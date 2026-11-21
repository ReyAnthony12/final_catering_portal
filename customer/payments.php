<?php
session_start();
require_once __DIR__ . '/../config.php';
/** @var mysqli $conn */
require_once __DIR__ . '/../includes/financial_helper.php';
require_once __DIR__ . '/../includes/booking_flow_helper.php';
require_once __DIR__ . '/../includes/order_reference_helper.php';

function ensureStoreTermsColumn($conn) {
    $check = mysqli_query($conn, "SHOW COLUMNS FROM store_details LIKE 'store_terms_conditions'");
    if ($check && mysqli_num_rows($check) === 0) {
        @mysqli_query($conn, "ALTER TABLE store_details ADD COLUMN store_terms_conditions TEXT NULL");
    }
}

function getPortalTermsContent($conn) {
    $default_terms = "By proceeding with payment, you agree to follow the portal's booking, payment verification, and dispute handling policies.";
    $query = mysqli_query($conn, "SELECT setting_value FROM system_settings WHERE setting_key = 'terms_content' LIMIT 1");
    if ($query && ($row = mysqli_fetch_assoc($query)) && !empty(trim($row['setting_value']))) {
        return $row['setting_value'];
    }
    return $default_terms;
}

function getNextUploadDetails($fin) {
    $balance = max(0, floatval($fin['totals']['balance'] ?? 0));
    $total_paid = max(0, floatval($fin['totals']['total_paid'] ?? 0));
    $down_payment_required = max(0, floatval($fin['down_payment_required'] ?? 0));

    if ($balance <= 0) {
        return [
            'amount' => 0,
            'kind' => 'none',
            'button_label' => 'No Payment Due'
        ];
    }

    if ($total_paid <= 0) {
        $downpayment_due = $down_payment_required > 0 ? min($down_payment_required, $balance) : $balance;
        return [
            'amount' => $downpayment_due,
            'kind' => ($down_payment_required > 0 && $downpayment_due < $balance) ? 'downpayment' : 'full_payment',
            'button_label' => ($down_payment_required > 0 && $downpayment_due < $balance) ? 'Pay Downpayment' : 'Pay Payment'
        ];
    }

    return [
        'amount' => $balance,
        'kind' => 'additional',
        'button_label' => 'Pay Balance'
    ];
}

function getDownpaymentStatusDetails($fin) {
    $required = max(0, floatval($fin['down_payment_required'] ?? 0));
    $verified_paid = max(0, floatval($fin['totals']['total_paid'] ?? 0));
    $paid_toward_downpayment = min($verified_paid, $required);
    $pending_initial_payment = false;

    foreach (($fin['history'] ?? []) as $payment) {
        $payment_type = strtolower((string) ($payment['payment_type'] ?? ''));
        $payment_status = strtolower((string) ($payment['status'] ?? ''));
        if ($payment_status === 'pending' && in_array($payment_type, ['downpayment', 'full_payment'], true)) {
            $pending_initial_payment = true;
            break;
        }
    }

    if ($required <= 0) {
        return [
            'required' => 0,
            'paid' => $paid_toward_downpayment,
            'remaining' => 0,
            'label' => 'Not Set',
            'note' => 'No downpayment amount has been configured for this booking yet.',
            'tone' => 'warning'
        ];
    }

    if ($paid_toward_downpayment >= $required) {
        return [
            'required' => $required,
            'paid' => $paid_toward_downpayment,
            'remaining' => 0,
            'label' => 'Paid',
            'note' => 'The required downpayment for this booking has already been covered.',
            'tone' => 'success'
        ];
    }

    if ($pending_initial_payment) {
        return [
            'required' => $required,
            'paid' => $paid_toward_downpayment,
            'remaining' => max(0, $required - $paid_toward_downpayment),
            'label' => 'Pending Verification',
            'note' => 'A downpayment proof was submitted and is waiting for caterer verification.',
            'tone' => 'warning'
        ];
    }

    return [
        'required' => $required,
        'paid' => $paid_toward_downpayment,
        'remaining' => max(0, $required - $paid_toward_downpayment),
        'label' => 'Unpaid',
        'note' => 'The required downpayment has not been verified yet.',
        'tone' => 'danger'
    ];
}

// Check if logged in
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'customer') {
    header("Location: ../login.php");
    exit();
}

$user_id = mysqli_real_escape_string($conn, $_SESSION['user_id']);
ensureStoreTermsColumn($conn);
$portal_terms_content = getPortalTermsContent($conn);

// Handle Upload (PHP Logic) - MOVED TO TOP to ensure it runs before page load
$is_payment_upload = $_SERVER['REQUEST_METHOD'] === 'POST'
    && (isset($_POST['upload_payment']) || isset($_FILES['payment_proof']));

if ($is_payment_upload) {
    $order_id = intval($_POST['order_id'] ?? 0);
    $accepted_store_terms = isset($_POST['accept_store_terms']) ? trim($_POST['accept_store_terms']) : '';
    $order_lookup = mysqli_query($conn, "SELECT id, order_number, customer_id FROM bookings WHERE id = '$order_id' AND customer_id = '$user_id' LIMIT 1");
    $order_meta = $order_lookup ? mysqli_fetch_assoc($order_lookup) : null;

    if (!$order_meta) {
        header("Location: payments.php?error=invalid_booking");
        exit();
    }

    $terms_lookup = mysqli_query($conn, "
        SELECT sd.store_terms_conditions
        FROM bookings o
        LEFT JOIN store_details sd ON o.store_id = sd.user_id
        WHERE o.id = '$order_id'
        LIMIT 1
    ");
    $terms_row = $terms_lookup ? mysqli_fetch_assoc($terms_lookup) : null;
    $store_terms_for_order = trim($terms_row['store_terms_conditions'] ?? '');

    if ($store_terms_for_order !== '' && $accepted_store_terms !== '1') {
        header("Location: payments.php?id=$order_id&error=terms_required");
        exit();
    }

    $fin = getOrderFinancials($order_id);
    $next_upload = getNextUploadDetails($fin);
    $amount_to_pay = max(0, floatval($next_upload['amount']));
    if ($amount_to_pay <= 0 || $next_upload['kind'] === 'none') {
        header("Location: payments.php?id=$order_id&error=no_amount_due");
        exit();
    }

    $file_error = isset($_FILES['payment_proof']) ? intval($_FILES['payment_proof']['error'] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE;
    if ($file_error !== UPLOAD_ERR_OK) {
        header("Location: payments.php?id=$order_id&error=upload_failed");
        exit();
    }

    $upload_dir = dirname(__DIR__) . '/uploads/payments/';
    if (!is_dir($upload_dir) && !mkdir($upload_dir, 0777, true) && !is_dir($upload_dir)) {
        header("Location: payments.php?id=$order_id&error=upload_failed");
        exit();
    }

    $file_ext = strtolower(pathinfo($_FILES['payment_proof']['name'], PATHINFO_EXTENSION));
    $allowed_ext = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    if (!in_array($file_ext, $allowed_ext, true)) {
        header("Location: payments.php?id=$order_id&error=upload_failed");
        exit();
    }

    $file_name = 'booking_' . $order_id . '_' . uniqid() . '.' . $file_ext;
    $target_file = $upload_dir . $file_name;

    if (!move_uploaded_file($_FILES['payment_proof']['tmp_name'], $target_file)) {
        header("Location: payments.php?id=$order_id&error=upload_failed");
        exit();
    }

            $proof_path = 'uploads/payments/' . $file_name;
            
            // Insert into payments table using the actual schema.
            $booking_reference_note = mysqli_real_escape_string($conn, formatOrderReference($order_id, $order_meta['order_number'] ?? ''));

            $amount_to_pay = max(0, floatval($amount_to_pay));
            if ($amount_to_pay <= 0) {
                header("Location: payments.php?id=$order_id&error=no_amount_due");
                exit();
            }
            $payment_type = $next_upload['kind'];

            $store_id_for_payment = 0;
            $store_lookup = mysqli_query($conn, "SELECT store_id FROM bookings WHERE id = '$order_id' LIMIT 1");
            if ($store_lookup && ($store_row = mysqli_fetch_assoc($store_lookup))) {
                $store_id_for_payment = intval($store_row['store_id']);
            }

            $existing_pending_q = mysqli_query($conn, "
                SELECT id
                FROM payments
                WHERE order_id = '$order_id'
                  AND status = 'pending'
                LIMIT 1
            ");

            if ($existing_pending_q && mysqli_num_rows($existing_pending_q) > 0) {
                header("Location: payments.php?id=$order_id&error=pending_review_exists");
                exit();
            }

            $allowed_types = ['downpayment', 'additional', 'full_payment', 'refund', 'damage_fee'];
            if (!in_array($payment_type, $allowed_types, true)) {
                $payment_type = 'downpayment';
            }

            $pay_columns = [];
            $cols_q = mysqli_query($conn, "SHOW COLUMNS FROM payments");
            if ($cols_q) {
                while ($col = mysqli_fetch_assoc($cols_q)) {
                    $pay_columns[] = $col['Field'];
                }
            }

            $insert_fields = ['order_id', 'amount', 'status'];
            $insert_values = ["'$order_id'", "'$amount_to_pay'", "'pending'"];

            if (in_array('customer_id', $pay_columns, true)) {
                $insert_fields[] = 'customer_id';
                $insert_values[] = "'$user_id'";
            }
            if (in_array('store_id', $pay_columns, true)) {
                $insert_fields[] = 'store_id';
                $insert_values[] = "'$store_id_for_payment'";
            }
            if (in_array('payment_type', $pay_columns, true)) {
                $insert_fields[] = 'payment_type';
                $insert_values[] = "'$payment_type'";
            }
            if (in_array('payment_method', $pay_columns, true)) {
                $insert_fields[] = 'payment_method';
                $insert_values[] = "'gcash'";
            }
            if (in_array('receipt_image', $pay_columns, true)) {
                $insert_fields[] = 'receipt_image';
                $insert_values[] = "'$proof_path'";
            }
            if (in_array('proof_image', $pay_columns, true)) {
                $insert_fields[] = 'proof_image';
                $insert_values[] = "'$proof_path'";
            }
            if (in_array('notes', $pay_columns, true)) {
                $insert_fields[] = 'notes';
                $insert_values[] = "'$booking_reference_note'";
            }
            if (in_array('created_at', $pay_columns, true)) {
                $insert_fields[] = 'created_at';
                $insert_values[] = "NOW()";
            }

            $insert_pay = "INSERT INTO payments (" . implode(', ', $insert_fields) . ") VALUES (" . implode(', ', $insert_values) . ")";
            $insert_result = mysqli_query($conn, $insert_pay);

            if (!$insert_result) {
                header("Location: payments.php?id=$order_id&error=upload_failed");
                exit();
            }
            
            // Also update legacy column for backward compatibility if needed
            mysqli_query($conn, "UPDATE bookings SET payment_screenshot = '$proof_path' WHERE id = '$order_id'");
            
                // NOTIFY CATERER
                $notif_q = mysqli_query($conn, "SELECT store_id, order_number FROM bookings WHERE id = '$order_id'");
                $notif_order = mysqli_fetch_assoc($notif_q);
                if ($notif_order) {
                    $store_id = $notif_order['store_id'];
                    $ord_num = $notif_order['order_number'];
                    // Use correct link relative to caterer dashboard (payments.php, not caterer/payments.php)
                    if (function_exists('createNotification')) {
                        createNotification($store_id, 'payment_received', "Payment Uploaded", "Customer uploaded payment proof for Booking #$ord_num", "payments.php?id=$order_id");
                    }
                    if (function_exists('notifyPortalAdmins')) {
                        notifyPortalAdmins(
                            'payment_received',
                            'Order Payment Uploaded',
                            "Customer uploaded payment proof for Booking #$ord_num.",
                            'reports.php'
                        );
                    }
                }
            
            header("Location: payments.php?id=$order_id&success=uploaded");
            exit();
}

$order_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// ==========================================
// MODE: SPECIFIC ORDER DETAILS (The Requested Page)
// ==========================================
if ($order_id > 0) {
    // 1. Fetch Order Basic Info
    $order_query = "SELECT o.*, sd.business_name, sd.gcash_qr_image as gcash_qr, sd.gcash_number, sd.store_terms_conditions
                    FROM bookings o
                    LEFT JOIN users u ON o.store_id = u.id
                    LEFT JOIN store_details sd ON u.id = sd.user_id
                    WHERE o.id = '$order_id' AND o.customer_id = '$user_id'";
    $order_res = mysqli_query($conn, $order_query);
    
    if (!$order_res || mysqli_num_rows($order_res) == 0) {
        header("Location: payments.php"); // Invalid ID
        exit();
    }
    
    $order = mysqli_fetch_assoc($order_res);
    // HELPER: Build Timeline & Charges Data
    function getFinancialDetails($conn, $order_id, $fin, $order) {
        $events = [];
        // order_date is the scheduled event date, not the transaction time.
        $bookingCreatedAt = $order['created_at'] ?? $order['order_date'];
        
        // 1. Payments
        foreach ($fin['history'] as $pay) {
            $events[] = [
                'date' => $pay['created_at'],
                'type' => 'payment',
                'title' => 'Payment Received',
                'desc' => 'Via ' . ucfirst($pay['payment_method']) . ($pay['status'] == 'pending' ? ' (Pending)' : ''),
                'amount' => -$pay['amount'],
                'status' => $pay['status']
            ];
        }

        // 2. Damages
        $damages = [];
        $dmg_query = mysqli_query($conn, "SELECT * FROM booking_damages WHERE order_id = '$order_id'");
        while ($dmg = mysqli_fetch_assoc($dmg_query)) {
            $damages[] = $dmg;
            $events[] = [
                'date' => $dmg['created_at'] ?? $bookingCreatedAt,
                'type' => 'damage',
                'title' => 'Damage Charge',
                'desc' => $dmg['item_name'] . ' (x' . $dmg['quantity'] . ')',
                'amount' => $dmg['total'],
                'status' => 'charged'
            ];
        }

        // 3. Extra Fees (Logs + Legacy)
        $extra_fee_logs = [];
        $log_query = mysqli_query($conn, "SELECT * FROM booking_logs WHERE order_id = '$order_id' AND action LIKE '%Extra Fee%'");
        $logged_fee_total = 0;
        while ($log = mysqli_fetch_assoc($log_query)) {
            $amount = 0;
            if (preg_match('/(?:\x{20B1}|&#8369;|₱)\s*([\d,.]+)/u', $log['details'], $matches)) {
                $amount = floatval(str_replace(',', '', $matches[1]));
            }
            $logged_fee_total += $amount;
            $extra_fee_logs[] = [
                'date' => $log['created_at'],
                'desc' => $log['details'],
                'amount' => $amount
            ];
            $events[] = [
                'date' => $log['created_at'],
                'type' => 'fee',
                'title' => 'Historical Adjustment',
                'desc' => $log['details'],
                'amount' => $amount,
                'status' => 'charged'
            ];
        }

        // Check for Legacy Extra Fee
        $total_extra = $fin['breakdown']['extra_fee'];
        if ($total_extra > $logged_fee_total + 0.01) { // Tolerance for float
            $diff = $total_extra - $logged_fee_total;
             $events[] = [
                'date' => $bookingCreatedAt,
                'type' => 'fee',
                'title' => 'Historical Adjustment',
                'desc' => 'Service/Delivery Adjustments',
                'amount' => $diff,
                'status' => 'charged'
            ];
             $extra_fee_logs[] = [
                'date' => $bookingCreatedAt,
                'desc' => 'Initial/Legacy Adjustment',
                'amount' => $diff
            ];
        }

        // 4. Order Created
        $events[] = [
            'date' => $bookingCreatedAt,
            'type' => 'order',
            'title' => 'Booking Created',
            'desc' => 'Base Package + Initial Add-ons',
            'amount' => $fin['breakdown']['base_price'] + $fin['breakdown']['addons'],
            'status' => 'billed'
        ];

        usort($events, function($a, $b) {
            return strtotime($b['date']) <=> strtotime($a['date']);
        });

        return ['events' => $events, 'damages' => $damages, 'fees' => $extra_fee_logs];
    }
    
    // 2. AJAX Handler for Real-Time Updates
    if (isset($_GET['fetch_details'])) {
        $fin = getOrderFinancials($order_id);
        $details = getFinancialDetails($conn, $order_id, $fin, $order);
        
        // Generate HTML for Charges Table
        ob_start();
        foreach ($details['fees'] as $fee) {
            echo '<tr>
                    <td>' . date('M d, Y', strtotime($fee['date'])) . '</td>
                    <td>Historical Adjustment</td>
                    <td>' . htmlspecialchars($fee['desc']) . '</td>
                    <td style="text-align: right;">&#8369;' . number_format($fee['amount'], 2) . '</td>
                  </tr>';
        }
        foreach ($details['damages'] as $dmg) {
            echo '<tr>
                    <td>' . date('M d, Y', strtotime($dmg['created_at'] ?? $order['order_date'])) . '</td>
                    <td>Damage</td>
                    <td>' . htmlspecialchars($dmg['item_name']) . ' (Qty: ' . $dmg['quantity'] . ')</td>
                    <td style="text-align: right;">&#8369;' . number_format($dmg['total'], 2) . '</td>
                  </tr>';
        }
        $charges_html = ob_get_clean();

        // Generate HTML for Timeline
        ob_start();
        foreach ($details['events'] as $event) {
            $color = $event['amount'] > 0 ? 'var(--error-color)' : 'var(--success-color)';
            echo '<div class="timeline-item">
                    <div class="timeline-dot" style="background: ' . $color . '"></div>
                    <div class="timeline-date">' . date('M d, Y h:i A', strtotime($event['date'])) . '</div>
                    <div style="display: flex; justify-content: space-between;">
                        <div>
                            <div class="timeline-title">' . htmlspecialchars($event['title']) . '</div>
                        </div>
                        <div class="timeline-amount" style="color: ' . $color . '">
                            ' . ($event['amount'] > 0 ? '+' : '') . '&#8369;' . number_format(abs($event['amount']), 2) . '
                        </div>
                    </div>
                  </div>';
        }
        $timeline_html = ob_get_clean();
        
        // Generate HTML for Payment History Table
        ob_start();
        if (empty($fin['history'])) {
            echo '<tr><td colspan="7" style="text-align: center; color: var(--text-muted);">No payments recorded yet.</td></tr>';
        } else {
            foreach ($fin['history'] as $pay) {
                $proof_html = '-';
                if (!empty($pay['proof_image'])) {
                    $proof_html = '<a href="javascript:void(0)" onclick="viewProof(\'../' . htmlspecialchars($pay['proof_image']) . '\')" style="color: var(--accent-gold); font-size: 12px; text-decoration: none;"><i class="fas fa-image"></i> View</a>';
                }
                
                echo '<tr>
                        <td style="color: var(--accent-gold); font-weight: 600;">' . htmlspecialchars(formatCatererBookingNumber($order_id, $order['order_number'], $order['store_id'])) . '</td>
                        <td>' . date('M d, Y', strtotime($pay['created_at'])) . '<br><span style="font-size: 11px; color: var(--text-muted);">' . date('h:i A', strtotime($pay['created_at'])) . '</span></td>
                        <td>' . ucfirst($pay['payment_type']) . '</td>
                        <td>' . ucfirst($pay['payment_method']) . '</td>
                        <td style="text-align: center;">' . $proof_html . '</td>
                        <td>';
                if($pay['status'] == 'verified') echo '<span style="color: var(--success-color);"><i class="fas fa-check"></i> Verified</span>';
                elseif($pay['status'] == 'pending') echo '<span style="color: var(--warning-text);"><i class="fas fa-clock"></i> Pending</span>';
                else {
                    echo '<span style="color: var(--error-color);"><i class="fas fa-times"></i> Rejected</span>';
                    if (!empty($pay['reject_reason'])) {
                        echo '<span class="reject-reason">Reason: ' . htmlspecialchars($pay['reject_reason']) . '</span>';
                    }
                }
                echo '  </td>
                        <td style="text-align: right;">&#8369;' . number_format($pay['amount'], 2) . '</td>
                      </tr>';
            }
        }
        $payment_table_html = ob_get_clean();

        $next_upload = getNextUploadDetails($fin);
        $downpayment_status = getDownpaymentStatusDetails($fin);

        echo json_encode([
            'grand_total' => number_format($fin['totals']['grand_total'], 2),
            'total_paid' => number_format($fin['totals']['total_paid'], 2),
            'balance' => number_format($fin['totals']['balance'], 2),
            'upload_amount' => number_format($next_upload['amount'], 2),
            'pay_button_label' => $next_upload['button_label'],
            'downpayment_required' => number_format($downpayment_status['required'], 2),
            'downpayment_paid' => number_format($downpayment_status['paid'], 2),
            'downpayment_remaining' => number_format($downpayment_status['remaining'], 2),
            'downpayment_status_label' => $downpayment_status['label'],
            'downpayment_status_note' => $downpayment_status['note'],
            'downpayment_status_tone' => $downpayment_status['tone'],
            'status' => $fin['status'] == 'paid' ? 'Fully paid' : ($fin['status'] == 'partial' ? 'Partially paid' : 'Pending'),
            'charges_html' => $charges_html,
            'timeline_html' => $timeline_html,
            'payment_table_html' => $payment_table_html
        ]);
        exit();
    }
    
    // 3. Initial Load
    $fin = getOrderFinancials($order_id);
    $details = getFinancialDetails($conn, $order_id, $fin, $order);
    $timeline_events = $details['events'];
    $damages = $details['damages'];
    $extra_fee_logs = $details['fees'];
    $next_upload = getNextUploadDetails($fin);
    $downpayment_status = getDownpaymentStatusDetails($fin);
    $amount_to_upload_now = $next_upload['amount'];
    $pay_button_label = $next_upload['button_label'];

    $pending_proof = null;
    foreach (($fin['history'] ?? []) as $pay) {
        if (strtolower((string) ($pay['status'] ?? '')) === 'pending') {
            $pending_proof = $pay;
            break;
        }
    }
    $pending_proof_path = '';
    if ($pending_proof) {
        $pending_proof_path = (string) ($pending_proof['proof_image'] ?? $pending_proof['receipt_image'] ?? '');
    } elseif (!empty($order['payment_screenshot']) && empty($fin['history'])) {
        $pending_proof_path = (string) $order['payment_screenshot'];
        $pending_proof = [
            'amount' => $amount_to_upload_now,
            'created_at' => $order['updated_at'] ?? $order['created_at'] ?? date('Y-m-d H:i:s'),
            'payment_type' => 'downpayment',
            'status' => 'pending',
        ];
    }
    $has_pending_proof = !empty($pending_proof);
    
    $page_title = 'Payment Details - Booking #' . formatCatererBookingNumber($order_id, $order['order_number'], $order['store_id']);
    require_once __DIR__ . '/includes/header.php';
echo '<script defer src="../assets/js/customer-payment-return.js?v=20261020-3"></script>';
    ?>
    
    <style>
        .payments-summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .payments-tabs {
            border-bottom: 1px solid var(--border-color);
            margin-bottom: 20px;
        }

        .payments-list-card {
            padding: 0;
            overflow: hidden;
        }

        .payments-desktop-table {
            display: none;
        }

        .payments-mobile-list {
            display: grid;
            gap: 12px;
            padding: 12px;
        }

        .payment-mobile-card {
            border: 1px solid var(--border-color);
            border-radius: 14px;
            padding: 14px;
            background: rgba(255,255,255,0.02);
        }

        .payment-mobile-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 12px;
            margin-bottom: 10px;
        }

        .payment-mobile-order {
            color: var(--accent-gold);
            font-weight: 700;
            line-height: 1.4;
        }

        .payment-mobile-date {
            color: var(--text-muted);
            font-size: 12px;
            margin-top: 2px;
        }

        .payment-mobile-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px 12px;
            margin-bottom: 12px;
        }

        .payment-mobile-label {
            display: block;
            margin-bottom: 4px;
            color: var(--text-muted);
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .payment-mobile-value {
            color: var(--text-main);
            font-weight: 600;
            word-break: break-word;
        }

        .payments-action-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 118px;
            padding: 10px 14px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 700;
            text-decoration: none !important;
            white-space: nowrap;
        }

        @media (min-width: 769px) {
            .payments-mobile-list {
                grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            }
        }

        @media (max-width: 768px) {
            .payments-summary-grid {
                gap: 14px;
                margin-bottom: 20px;
            }

            .payments-tabs {
                display: grid;
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 8px;
                border-bottom: none;
            }

            .tab-btn {
                width: 100%;
                padding: 12px 10px;
                border: 1px solid var(--border-color);
                border-radius: 14px;
                font-size: 13px;
                text-align: center;
            }

            .payments-list-card {
                overflow: visible;
            }

            .payments-mobile-list {
                padding: 8px;
            }

            .data-table thead {
                display: none;
            }

            .data-table,
            .data-table tbody,
            .data-table tr,
            .data-table td {
                display: block;
                width: 100%;
            }

            .data-table tbody {
                padding: 8px;
            }

            .data-table tr {
                margin-bottom: 14px;
                border: 1px solid var(--border-color);
                border-radius: 14px;
                padding: 14px;
                background: rgba(255,255,255,0.02);
            }

            .data-table td {
                border: none;
                padding: 8px 0;
                text-align: left !important;
                white-space: normal;
                word-break: break-word;
            }

            .data-table td::before {
                display: block;
                margin-bottom: 4px;
                color: var(--text-muted);
                font-size: 11px;
                font-weight: 700;
                text-transform: uppercase;
                letter-spacing: 0.05em;
            }

            #pending-tab .data-table td:nth-child(1)::before { content: 'Order'; }
            #pending-tab .data-table td:nth-child(2)::before { content: 'Details'; }
            #pending-tab .data-table td:nth-child(3)::before { content: 'Breakdown'; }
            #pending-tab .data-table td:nth-child(4)::before { content: 'Balance Due'; }
            #pending-tab .data-table td:nth-child(5)::before { content: 'Action'; }

            #history-tab .data-table td:nth-child(1)::before { content: 'Order'; }
            #history-tab .data-table td:nth-child(2)::before { content: 'Caterer'; }
            #history-tab .data-table td:nth-child(3)::before { content: 'Total Amount'; }
            #history-tab .data-table td:nth-child(4)::before { content: 'Status'; }
            #history-tab .data-table td:nth-child(5)::before { content: 'Action'; }

            .payments-list-card .btn {
                width: 100%;
                justify-content: center;
                display: inline-flex;
                padding: 12px 14px;
                font-size: 13px !important;
                border-radius: 12px;
            }

            .payments-action-link {
                width: 100%;
                min-width: 0;
            }

            .payments-list-card .status-badge {
                display: inline-flex;
                width: fit-content;
                max-width: 100%;
            }

            .payment-mobile-grid {
                grid-template-columns: 1fr;
            }
        }

        .finance-grid {
            display: grid;
            grid-template-columns: minmax(0, 3fr) minmax(280px, 1.2fr);
            gap: 24px;
            align-items: start;
        }
        @media(max-width: 1024px) { .finance-grid { grid-template-columns: minmax(0, 2fr) minmax(260px, 1fr); } }
        @media(max-width: 900px) { .finance-grid { grid-template-columns: 1fr; } }

        .finance-left-col {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }
        .finance-right-col {
            display: flex;
            flex-direction: column;
            gap: 20px;
            position: sticky;
            top: 20px;
        }
        @media(max-width: 900px) {
            .finance-right-col { position: static; }
        }

        .stat-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 14px;
            padding: 22px;
            margin-bottom: 0;
        }

        .stat-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 12px;
            font-size: 14px;
            color: var(--text-muted);
        }
        .stat-row.total {
            border-top: 1px dashed var(--border-color);
            padding-top: 15px;
            margin-top: 15px;
            font-size: 18px;
            font-weight: bold;
            color: var(--text-main);
        }
        .stat-row.paid { color: var(--success-color); }
        .stat-row.balance { color: var(--error-color); font-size: 20px; font-weight: bold; }

        .timeline-item {
            position: relative;
            padding-left: 30px;
            margin-bottom: 20px;
            border-left: 2px solid var(--border-color);
        }
        .timeline-dot {
            position: absolute;
            left: -6px;
            top: 0;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: var(--accent-gold);
        }
        .timeline-date { font-size: 11px; color: var(--text-muted); margin-bottom: 4px; }
        .timeline-title { font-weight: bold; color: var(--text-main); }
        .timeline-desc { font-size: 13px; color: var(--text-muted); }
        .timeline-amount { font-weight: bold; float: right; }
        
        .badge-status {
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
            text-transform: uppercase;
        }
        .bg-pending { background: rgba(243, 156, 18, 0.2); color: #f39c12; }
        .bg-paid { background: rgba(46, 204, 113, 0.2); color: #2ecc71; }
        .bg-partial { background: rgba(212, 175, 55, 0.2); color: #d4af37; }
        
        .table-custom {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }
        .table-custom th { text-align: left; padding: 12px; border-bottom: 1px solid var(--border-color); color: var(--text-muted); }
        .table-custom td { padding: 12px; border-bottom: 1px solid var(--border-color); color: var(--text-main); }
        .terms-card {
            margin-bottom: 16px;
            border-radius: 14px;
            border: 1px solid rgba(255, 255, 255, 0.08);
            background: linear-gradient(180deg, rgba(255,255,255,0.05), rgba(255,255,255,0.03));
            box-shadow: 0 12px 24px rgba(0, 0, 0, 0.16);
            overflow: hidden;
        }
        .terms-card h4 {
            margin: 0;
            color: var(--accent-gold);
            font-size: 15px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .terms-toggle {
            list-style: none;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            width: 100%;
            padding: 18px;
            cursor: pointer;
            user-select: none;
        }
        .terms-toggle::-webkit-details-marker {
            display: none;
        }
        .terms-toggle-text {
            display: flex;
            align-items: center;
            gap: 8px;
            min-width: 0;
        }
        .terms-toggle-arrow {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 28px;
            height: 28px;
            border-radius: 50%;
            background: rgba(212, 175, 55, 0.14);
            color: var(--accent-gold);
            transition: transform 0.2s ease, background 0.2s ease;
            flex-shrink: 0;
        }
        .terms-card[open] .terms-toggle-arrow {
            transform: rotate(180deg);
            background: rgba(212, 175, 55, 0.22);
        }
        .terms-card .terms-body {
            color: var(--text-main);
            line-height: 1.75;
            font-size: 14px;
            max-height: 340px;
            overflow-y: auto;
            padding: 0 18px 18px;
            margin-top: -2px;
            border-top: 1px solid rgba(255, 255, 255, 0.06);
        }
        .terms-toggle-hint {
            display: block;
            margin-top: 3px;
            color: var(--text-muted);
            font-size: 12px;
        }
        .terms-agreement {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            padding: 14px 16px;
            border-radius: 12px;
            border: 1px solid rgba(212, 175, 55, 0.18);
            background: rgba(212, 175, 55, 0.06);
            margin-bottom: 16px;
            color: var(--text-main);
        }
        .terms-agreement input {
            margin-top: 3px;
        }
        .instruction-card {
            margin-bottom: 16px;
            padding: 16px;
            border-radius: 10px;
            border: 1px solid rgba(212, 175, 55, 0.2);
            background: rgba(212, 175, 55, 0.08);
        }
        .instruction-card h4 {
            margin: 0 0 10px;
            color: var(--accent-gold);
            font-size: 15px;
        }
        .instruction-list {
            margin: 0;
            padding-left: 18px;
            color: var(--text-main);
            line-height: 1.7;
            font-size: 13px;
        }
        .proof-preview {
            margin-top: 12px;
            display: none;
            text-align: center;
            padding: 18px;
            border-radius: 14px;
            border: 1px dashed rgba(212, 175, 55, 0.25);
            background: rgba(255,255,255,0.03);
        }
        .proof-preview img {
            max-width: 100%;
            max-height: 320px;
            border-radius: 12px;
            margin-top: 12px;
            box-shadow: 0 16px 30px rgba(0, 0, 0, 0.28);
        }
        .reject-reason {
            display: block;
            margin-top: 6px;
            color: var(--error-color);
            font-size: 12px;
            line-height: 1.5;
        }

        .table-custom-wrap {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        .payment-modal {
            width: min(980px, calc(100vw - 32px));
            max-width: 980px !important;
            border-radius: 20px;
            overflow: hidden;
            border: 1px solid rgba(212, 175, 55, 0.45);
            box-shadow: 0 30px 80px rgba(0, 0, 0, 0.45);
            background: linear-gradient(180deg, #202020 0%, #181818 100%);
        }

        .payment-modal .modal-header {
            padding: 20px 24px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            background: rgba(255, 255, 255, 0.02);
        }

        .payment-modal .modal-header h3 {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 22px;
            margin: 0;
        }

        .payment-modal .modal-body {
            padding: 0;
        }

        .payment-modal-shell {
            display: grid;
            grid-template-columns: minmax(260px, 300px) minmax(0, 1fr);
            max-height: min(calc(88vh - 78px), 760px);
        }

        .payment-side-panel {
            padding: 24px;
            background: linear-gradient(180deg, rgba(212, 175, 55, 0.08), rgba(212, 175, 55, 0.03));
            border-right: 1px solid rgba(255, 255, 255, 0.08);
            display: grid;
            align-content: start;
            gap: 18px;
            overflow-y: auto;
        }

        .payment-main-panel {
            padding: 24px;
            overflow-y: auto;
        }

        .payment-side-card {
            padding: 18px;
            border-radius: 16px;
            border: 1px solid rgba(255, 255, 255, 0.09);
            background: rgba(15, 15, 15, 0.24);
        }

        .payment-side-card h4 {
            margin: 0 0 12px;
            color: var(--accent-gold);
            font-size: 15px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .payment-side-note {
            font-size: 13px;
            color: var(--text-muted);
            line-height: 1.7;
            margin: 0;
        }

        .payment-qr-wrap {
            text-align: center;
        }

        .payment-qr-wrap img {
            width: min(100%, 210px);
            border-radius: 16px;
            border: 1px solid rgba(212, 175, 55, 0.24);
            background: #111;
            padding: 8px;
            box-shadow: 0 14px 28px rgba(0, 0, 0, 0.28);
        }

        .payment-qr-number {
            margin-top: 14px;
            color: var(--accent-gold);
            font-weight: 700;
            font-size: 16px;
            letter-spacing: 0.02em;
        }

        .payment-qr-helper {
            margin-top: 6px;
            font-size: 12px;
            color: var(--text-muted);
        }

        .payment-amount-card {
            border-color: rgba(212, 175, 55, 0.22);
            background: rgba(212, 175, 55, 0.08);
        }

        .payment-amount-label {
            color: var(--text-muted);
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }

        .payment-amount-value {
            margin-top: 8px;
            color: var(--accent-gold);
            font-size: 30px;
            font-weight: 800;
            line-height: 1.1;
        }

        .payment-upload-form {
            display: grid;
            gap: 18px;
            padding-bottom: 8px;
        }

        .payment-upload-block {
            padding: 18px;
            border-radius: 16px;
            border: 1px solid rgba(255, 255, 255, 0.08);
            background: rgba(255, 255, 255, 0.03);
        }

        .payment-upload-block .form-label {
            display: block;
            margin-bottom: 10px;
            font-weight: 600;
            color: var(--text-main);
        }

        .payment-upload-helper {
            margin-top: 8px;
            font-size: 12px;
            color: var(--text-muted);
            line-height: 1.6;
        }

        .payment-submit-bar {
            position: sticky;
            bottom: 0;
            z-index: 5;
            margin: 0 -24px -24px;
            padding: 16px 24px 24px;
            background: var(--card-bg);
            border-top: 1px solid var(--border-color);
        }

        #payModal .payment-submit-btn {
            width: 100%;
            justify-content: center;
            min-height: 52px;
            background: linear-gradient(135deg, var(--primary-color), #2b5544);
            color: #f0f1e6;
            border: 1px solid #708b6b;
            border-radius: 12px;
            font-weight: 700;
            box-shadow: 0 4px 12px rgba(23, 61, 50, 0.18);
            transition: background 0.2s ease, box-shadow 0.2s ease;
        }

        #payModal .payment-submit-btn:hover {
            background: linear-gradient(135deg, #214c3e, #35634f);
            color: #fff;
            box-shadow: 0 6px 16px rgba(23, 61, 50, 0.25);
        }

        #payModal .payment-submit-btn:focus-visible {
            outline: 3px solid var(--accent-gold);
            outline-offset: 3px;
        }

        @media (max-width: 768px) {
            .container > div[style*="display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px;"] {
                flex-direction: column;
                align-items: flex-start !important;
                gap: 12px;
            }

            .stat-card {
                padding: 16px;
            }

            .table-custom-wrap {
                overflow: visible;
            }

            .table-custom {
                min-width: 0;
            }

            .table-custom thead {
                display: none;
            }

            .table-custom,
            .table-custom tbody,
            .table-custom tr,
            .table-custom td {
                display: block;
                width: 100%;
            }

            .table-custom tr {
                margin-bottom: 14px;
                padding: 14px;
                border: 1px solid var(--border-color);
                border-radius: 14px;
                background: rgba(255,255,255,0.02);
            }

            .table-custom td {
                border-bottom: none;
                padding: 8px 0;
                text-align: left !important;
            }

            .table-custom td::before {
                display: block;
                margin-bottom: 4px;
                color: var(--text-muted);
                font-size: 11px;
                font-weight: 700;
                text-transform: uppercase;
                letter-spacing: 0.05em;
            }

            #payment-table-body td:nth-child(1)::before { content: 'Booking Ref'; }
            #payment-table-body td:nth-child(2)::before { content: 'Date'; }
            #payment-table-body td:nth-child(3)::before { content: 'Type'; }
            #payment-table-body td:nth-child(4)::before { content: 'Method'; }
            #payment-table-body td:nth-child(5)::before { content: 'Receipt'; }
            #payment-table-body td:nth-child(6)::before { content: 'Status'; }
            #payment-table-body td:nth-child(7)::before { content: 'Amount'; }

            #charges-table-body td:nth-child(1)::before { content: 'Date'; }
            #charges-table-body td:nth-child(2)::before { content: 'Type'; }
            #charges-table-body td:nth-child(3)::before { content: 'Description'; }
            #charges-table-body td:nth-child(4)::before { content: 'Amount'; }

            .payment-modal {
                width: calc(100vw - 18px);
                max-width: calc(100vw - 18px) !important;
                border-radius: 16px;
            }

            .payment-modal .modal-header {
                padding: 16px 18px;
            }

            .payment-modal .modal-header h3 {
                font-size: 18px;
            }

            .payment-modal-shell {
                grid-template-columns: 1fr;
                max-height: calc(100vh - 120px);
            }

            .payment-side-panel,
            .payment-main-panel {
                padding: 16px;
            }

            .payment-side-panel {
                border-right: none;
                border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            }

            .payment-amount-value {
                font-size: 24px;
            }

            .payment-submit-bar {
                margin: 0 -16px -16px;
                padding: 14px 16px 16px;
            }

            .terms-card .terms-body {
                max-height: 260px;
                padding: 0 16px 16px;
            }

            .terms-toggle {
                padding: 16px;
            }
        }
        .payment-details-container {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 14px;
            overflow: hidden;
            box-shadow: var(--surface-shadow);
        }
        .payment-details-container .finance-grid { gap: 0; align-items: stretch; }
        .payment-details-heading { padding: 22px; border-bottom: 1px solid var(--border-color); }
        .payment-details-title { display: flex; justify-content: space-between; align-items: center; gap: 16px; flex-wrap: wrap; }
        .payment-details-container .finance-left-col,
        .payment-details-container .finance-right-col {
            min-width: 0;
            gap: 0;
            position: static;
        }
        .payment-details-container .finance-right-col {
            border-left: 1px solid var(--border-color);
        }
        .payment-details-container .stat-card {
            background: transparent !important;
            border: 0;
            border-radius: 0;
            box-shadow: none !important;
        }
        .payment-details-container .stat-card + .stat-card {
            border-top: 1px solid var(--border-color);
        }
        @media (max-width: 900px) {
            .payment-details-container .finance-right-col {
                border-left: 0;
                border-top: 1px solid var(--border-color);
            }
        }
        .swal2-popup.payment-submitted-modal {
            box-sizing: border-box;
            width: min(480px, calc(100vw - 32px));
            padding: 24px 20px;
            border: 1px solid #96a38e;
            border-radius: 18px;
            box-shadow: 0 16px 48px rgba(23, 61, 50, .22);
        }
        .payment-submitted-modal .swal2-title { color: #20372b; font-size: clamp(22px, 5vw, 28px); }
        .payment-submitted-modal .swal2-html-container { color: #425642; font-size: 15px; line-height: 1.6; margin: 16px 0 0; }
        .payment-submitted-modal .swal2-success-ring { border-color: #96a38e !important; }
        .payment-submitted-modal .swal2-confirm.payment-submitted-confirm {
            min-height: 44px;
            padding: 10px 32px;
            border-radius: 10px;
            color: #f0f1e6;
            font-weight: 600;
            box-shadow: none;
        }
        .payment-submitted-modal .swal2-confirm.payment-submitted-confirm:hover { background: #2b5544 !important; }
        .payment-submitted-modal .swal2-confirm.payment-submitted-confirm:focus-visible { outline: 3px solid #70501e; outline-offset: 3px; }
    </style>
    <link rel="stylesheet" href="../assets/css/customer-payment-details.css?v=<?php echo filemtime(__DIR__ . '/../assets/css/customer-payment-details.css'); ?>">
    <div class="payment-details-container">
        <div class="payment-details-heading">
        <a href="javascript:history.back()" style="color: var(--text-muted); text-decoration: none; display: inline-flex; align-items: center; gap: 8px; margin-bottom: 20px;">
            <i class="fas fa-arrow-left"></i> Back
        </a>
        
        <div class="payment-details-title">
            <div>
                <h1 style="margin: 0; color: var(--accent-gold); font-size: clamp(20px, 4vw, 28px);">Booking #<?php echo htmlspecialchars(formatCatererBookingNumber($order_id, $order['order_number'], $order['store_id'])); ?></h1>
            </div>
            <div class="badge-status <?php echo ($fin['status'] == 'paid' ? 'bg-paid' : ($fin['status'] == 'partial' ? 'bg-partial' : 'bg-pending')); ?>" style="align-self: center; white-space: nowrap;">
                <?php
                if ($fin['status'] == 'paid') {
                    echo 'Fully paid';
                } elseif ($fin['status'] == 'partial') {
                    echo 'Partially paid';
                } else {
                    echo 'Pending';
                }
                ?>
            </div>

        </div>
        
        </div>
        <?php if (($_GET['success'] ?? '') === 'uploaded'): ?>
            <div class="payment-proof-received" role="status">
                <strong>Payment proof submitted</strong>
                <span>The caterer will verify your proof. Check the transaction timeline for updates.</span>
            </div>
        <?php endif; ?>
        <div class="finance-grid">
            <!-- Left Column: Breakdown & Tables -->
            <div class="finance-left-col">
                <!-- 1. Financial Summary Breakdown -->
                <div class="stat-card balance-breakdown-card">
                    <h3 style="margin-top: 0; margin-bottom: 20px; border-bottom: 1px solid var(--border-color); padding-bottom: 10px;">
                        <i class="fas fa-file-invoice"></i> Cost Breakdown
                    </h3>
                    
                    <div class="stat-row">
                        <span>Base Package Price</span>
                        <span>&#8369;<?php echo number_format($fin['breakdown']['base_price'], 2); ?></span>
                    </div>
                    <?php
                        $cost_computation = bookingPricingCalculateStoredOrder($conn, $order);
                        $currency = static fn($value) => '₱' . number_format((float) $value, 2);
                    ?>
                    <?php if ($cost_computation && abs((float) $cost_computation['base_price'] - (float) $fin['breakdown']['base_price']) < 0.01): ?>
                        <div style="margin:0 0 16px;font-size:12px;line-height:1.7;overflow-wrap:anywhere;">
                        <?php if ($cost_computation['pricing_mode'] === 'package'): ?>
                            <div>Package: <?php echo htmlspecialchars($currency($cost_computation['selected_set_price'])); ?></div>
                            <?php if ($cost_computation['extra_guest_count'] > 0): ?>
                            <div>Extra guests: <?php echo (int) $cost_computation['extra_guest_count']; ?> × <?php echo $currency($cost_computation['extra_guest_rate']); ?> = <?php echo $currency($cost_computation['extra_guest_total']); ?></div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div><?php echo (int) $cost_computation['quantity']; ?> guests × <?php echo $currency($cost_computation['price_per_unit']); ?> per guest = <?php echo $currency($cost_computation['quantity'] * $cost_computation['price_per_unit']); ?></div>
                        <?php endif; ?>
                        <?php if ($cost_computation['swaps_per_unit'] > 0): ?><div>Menu swaps: +<?php echo $currency($cost_computation['swaps_per_unit']); ?></div><?php endif; ?>
                        </div>
                    <?php endif; ?>
                    <div class="stat-row">
                        <span>Add-ons Total</span>
                        <span>&#8369;<?php echo number_format($fin['breakdown']['addons'], 2); ?></span>
                    </div>
                    <?php if ($fin['breakdown']['damages'] > 0): ?>
                    <div class="stat-row" style="color: var(--error-color);">
                        <span>Damage Charges</span>
                        <span>&#8369;<?php echo number_format($fin['breakdown']['damages'], 2); ?></span>
                    </div>
                    <?php endif; ?>
                    
                    <div class="stat-row total">
                        <span>Grand Total</span>
                        <span id="grand-total-disp">&#8369;<?php echo number_format($fin['totals']['grand_total'], 2); ?></span>
                    </div>
                    <p style="margin:8px 0 0;font-size:12px;line-height:1.7;overflow-wrap:anywhere;">
                        <?php echo $currency($fin['breakdown']['base_price']); ?> + <?php echo $currency($fin['breakdown']['addons']); ?> add-ons<?php if ($fin['breakdown']['extra_fee'] != 0): ?> + <?php echo $currency($fin['breakdown']['extra_fee']); ?> historical adjustment<?php endif; ?><?php if ($fin['breakdown']['damages'] > 0): ?> + <?php echo $currency($fin['breakdown']['damages']); ?> damages<?php endif; ?> = <strong><?php echo $currency($fin['totals']['grand_total']); ?></strong>
                    </p>

                    <?php if ($fin['totals']['total_paid'] > 0): ?>
                    <div class="stat-row" style="margin-top: 12px; padding-top: 12px; border-top: 1px solid var(--border-color); color: var(--success-text);">
                        <span>Amount Paid (Verified)</span>
                        <span id="amount-paid-disp" style="font-weight: 600;">&#8369;<?php echo number_format($fin['totals']['total_paid'], 2); ?></span>
                    </div>
                    <div class="stat-row" style="color: <?php echo $fin['totals']['balance'] <= 0 ? 'var(--success-text)' : 'var(--warning-text)'; ?>; font-weight: 700;">
                        <span>Balance Due</span>
                        <span id="balance-due-disp">&#8369;<?php echo number_format($fin['totals']['balance'], 2); ?></span>
                    </div>
                    <?php endif; ?>
                </div>
                
                <!-- 3. Additional Charges Table (If any) -->
                <?php if ($fin['breakdown']['damages'] > 0 || $fin['breakdown']['extra_fee'] > 0): ?>
                <div class="stat-card">
                    <h3 style="margin-top: 0; margin-bottom: 20px;">
                        <i class="fas fa-exclamation-circle"></i> Additional Charges / Adjustments
                    </h3>
                    <div class="table-custom-wrap">
                    <table class="table-custom">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Type</th>
                                <th>Description</th>
                                <th style="text-align: right;">Amount</th>
                            </tr>
                        </thead>
                        <tbody id="charges-table-body">
                            <?php foreach ($extra_fee_logs as $fee): ?>
                            <tr>
                                <td><?php echo date('M d, Y', strtotime($fee['date'])); ?></td>
                                <td>Historical Adjustment</td>
                                <td><?php echo htmlspecialchars($fee['desc']); ?></td>
                                <td style="text-align: right;">&#8369;<?php echo number_format($fee['amount'], 2); ?></td>
                            </tr>
                            <?php endforeach; ?>
                            
                            <?php foreach ($damages as $dmg): ?>
                            <tr>
                                <td><?php echo date('M d, Y', strtotime($dmg['created_at'] ?? $order['order_date'])); ?></td>
                                <td>Damage</td>
                                <td><?php echo htmlspecialchars($dmg['item_name']); ?> (Qty: <?php echo $dmg['quantity']; ?>)</td>
                                <td style="text-align: right;">&#8369;<?php echo number_format($dmg['total'], 2); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Right Column: Balance & Timeline -->
            <div class="finance-right-col">
                <!-- Balance Card -->
                <div class="stat-card balance-summary-card" style="text-align: center; border-color: var(--accent-gold);">
                    <div style="font-size: 14px; color: var(--text-muted); margin-bottom: 5px;">Remaining Balance</div>
                    <div class="stat-row balance" style="justify-content: center; margin-bottom: 10px;">
                        <span id="balance-disp" style="color: var(--primary-color);">&#8369;<?php echo number_format($fin['totals']['balance'], 2); ?></span>
                    </div>
                    
                    <div class="stat-row" style="justify-content: center; font-size: 13px;">
                        <span style="margin-right: 5px;">Total Paid:</span>
                        <span style="color: var(--success-color); font-weight: bold;" id="paid-disp">&#8369;<?php echo number_format($fin['totals']['total_paid'], 2); ?></span>
                    </div>
                    
                    <?php
                        $grand_total_view = (float) ($fin['totals']['grand_total'] ?? 0);
                        $total_paid_view = (float) ($fin['totals']['total_paid'] ?? 0);
                        $is_amount_missing = ($grand_total_view <= 0 && $total_paid_view <= 0);
                        $bookingStatus = strtolower(trim((string) ($order['order_status'] ?? 'pending')));
                        $isBookingConfirmed = in_array($bookingStatus, ['confirmed', 'approved', 'preparing', 'ready', 'delivering', 'completed']);
                    ?>
                    <div class="balance-due" style="margin-top: 18px; padding: 18px 0 0; border-top: 1px solid var(--border-color);">
                        <?php if ($has_pending_proof): ?>
                            <div style="font-size: 12px; color: var(--text-muted); margin-bottom: 4px;">Amount Sent for Review</div>
                            <div style="font-size: 22px; font-weight: 700; color: var(--accent-gold);" id="amount-to-upload-disp">&#8369;<?php echo number_format((float) ($pending_proof['amount'] ?? $amount_to_upload_now), 2); ?></div>
                        <?php else: ?>
                            <div style="font-size: 12px; color: var(--text-muted); margin-bottom: 4px;"><?php echo $isBookingConfirmed && $next_upload['kind'] === 'downpayment' ? 'Ready for downpayment' : 'Amount Due for This Payment'; ?></div>
                            <div style="font-size: 22px; font-weight: 700; color: var(--accent-gold);" id="amount-to-upload-disp">&#8369;<?php echo number_format($amount_to_upload_now, 2); ?></div>
                        <?php endif; ?>
                    </div>



                    <?php if ($has_pending_proof): ?>
                    <div style="margin-top: 18px; padding-top: 18px; color: var(--accent-gold); border-top: 1px solid var(--border-color); text-align: left;">
                        <div style="font-weight: 700; margin-bottom: 6px;"><i class="fas fa-clock"></i> Payment proof sent</div>
                        <?php if ($pending_proof_path !== ''): ?>
                            <button type="button" onclick="viewProof('../<?php echo htmlspecialchars($pending_proof_path); ?>')" class="btn btn-outline" style="width: 100%; margin-top: 12px; justify-content: center;">
                                <i class="fas fa-image"></i> View submitted proof
                            </button>
                        <?php endif; ?>
                    </div>
                    <?php elseif (!$isBookingConfirmed): ?>
                    <div class="balance-locked" style="margin-top: 18px; padding-top: 18px; color: var(--primary-color); border-top: 1px solid var(--border-color); text-align: left;">
                        <div style="font-weight: 700; margin-bottom: 6px;"><i class="fas fa-lock"></i> Payment Locked</div>
                        <?php if ($bookingStatus === 'pending'): ?>
                        <div>Waiting for caterer confirmation. You can pay the downpayment once the caterer confirms your booking.</div>
                        <?php endif; ?>
                    </div>
                    <?php elseif ($fin['totals']['balance'] > 0): ?>
                    <button id="pay-action-btn" onclick="document.getElementById('payModal').style.display='flex'" class="btn btn-primary" style="width: 100%; margin-top: 15px; justify-content: center;">
                        <i class="fas fa-wallet"></i> <?php echo htmlspecialchars($pay_button_label); ?>
                    </button>
                    <?php elseif ($is_amount_missing): ?>
                    <div style="margin-top: 18px; padding-top: 18px; color: var(--accent-gold); border-top: 1px solid var(--border-color);">
                        <i class="fas fa-info-circle"></i> Pending Amount
                    </div>
                    <?php else: ?>
                    <div style="margin-top: 18px; padding-top: 18px; color: var(--success-color); border-top: 1px solid var(--border-color);">
                        <i class="fas fa-check-circle"></i> Fully paid
                    </div>
                    <?php endif; ?>
                </div>
                
                <!-- Timeline Widget -->
                <div class="stat-card balance-timeline-card">
                    <h3 style="margin-top: 0; margin-bottom: 20px;">
                        <i class="fas fa-stream"></i> Transaction Timeline
                    </h3>
                    <div style="max-height: 400px; overflow-y: auto;" id="timeline-container">
                        <?php foreach ($timeline_events as $event): ?>
                        <div class="timeline-item">
                            <div class="timeline-dot" style="background: <?php echo $event['amount'] > 0 ? 'var(--error-color)' : 'var(--success-color)'; ?>"></div>
                            <div class="timeline-date"><?php echo date('M d, Y h:i A', strtotime($event['date'])); ?></div>
                            <div style="display: flex; justify-content: space-between;">
                                <div>
                                    <div class="timeline-title"><?php echo htmlspecialchars($event['title']); ?></div>
                                </div>
                                <div class="timeline-amount" style="color: <?php echo $event['amount'] > 0 ? 'var(--error-color)' : 'var(--success-color)'; ?>">
                                    <?php echo ($event['amount'] > 0 ? '+' : '') . '&#8369;' . number_format(abs($event['amount']), 2); ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- REUSE SMART PAYMENT MODAL FROM LIST VIEW (Slightly modified ID handling) -->
    <div id="payModal" class="modal-overlay" style="display: none;">
        <div class="modal-content payment-modal">
            <div class="modal-header">
                <h3><i class="fas fa-wallet"></i> Settle Payment</h3>
                <button type="button" class="close-btn" aria-label="Close payment" onclick="document.getElementById('payModal').style.display='none'">&times;</button>
            </div>
            <div class="modal-body">
                <div class="payment-modal-shell">
                    <aside class="payment-side-panel">
                        <?php if ($order['gcash_qr']): ?>
                        <div class="payment-side-card payment-qr-wrap">
                            <button type="button" class="payment-qr-button" aria-label="View payment QR code" onclick="viewProof(this.querySelector('img').src, 'Payment QR code')">
                                <img src="../<?php echo htmlspecialchars($order['gcash_qr'], ENT_QUOTES, 'UTF-8'); ?>" alt="GCash QR">
                            </button>
                            <div class="payment-qr-number"><?php echo htmlspecialchars($order['gcash_number']); ?></div>
                            
                        </div>
                        <?php endif; ?>

                        <div class="payment-side-card payment-amount-card">
                            <div class="payment-amount-label">Amount To Upload Now</div>
                            <div class="payment-amount-value" id="modal-amount-to-upload">&#8369;<?php echo number_format($amount_to_upload_now, 2); ?></div>
                            
                        </div>


                    </aside>

                    <div class="payment-main-panel">
                    <form method="POST" enctype="multipart/form-data" class="payment-upload-form">
                        <input type="hidden" name="order_id" value="<?php echo $order_id; ?>">
                        <input type="hidden" name="upload_payment" value="1">

                        <div class="instruction-card">
                            <h4><i class="fas fa-receipt"></i> Payment Guide</h4>
                            <ul class="instruction-list">
                                <li>Send exactly <strong id="guide-amount-to-upload">&#8369;<?php echo number_format($amount_to_upload_now, 2); ?></strong> for this upload.</li>
                                <li>Make sure the screenshot clearly shows the amount, receiver, and successful transaction status.</li>
                                <li>Submit one proof per payment attempt only.</li>
                                <li>If your proof gets rejected, check the reject reason in the transactions table before uploading again.</li>
                            </ul>
                        </div>



                        <details class="terms-card">
                            <summary class="terms-toggle">
                                <span class="terms-toggle-text">
                                    <span>
                                        <h4><i class="fas fa-caterer"></i> <?php echo htmlspecialchars($order['business_name']); ?> Terms and Conditions</h4>
                                        
                                    </span>
                                </span>
                                <span class="terms-toggle-arrow"><i class="fas fa-chevron-down"></i></span>
                            </summary>
                            <div class="terms-body">
                                <?php
                                $store_terms_content = trim($order['store_terms_conditions'] ?? '');
                                echo $store_terms_content !== ''
                                    ? nl2br(htmlspecialchars($store_terms_content))
                                    : '<span style="color: var(--text-muted);">This caterer has not added custom payment terms yet.</span>';
                                ?>
                            </div>
                        </details>

                        <?php if (!empty(trim($order['store_terms_conditions'] ?? ''))): ?>
                        <label class="terms-agreement">
                            <input type="checkbox" name="accept_store_terms" value="1" required>
                            <span>I have read and agree to this caterer's Terms and Conditions before submitting my payment.</span>
                        </label>
                        <?php endif; ?>
                        
                        <div class="payment-upload-block">
                            <label class="form-label">Upload Proof (Screenshot)</label>
                            <input type="file" name="payment_proof" id="paymentProofInput" class="form-control" required accept="image/*">
                            
                            <div class="proof-preview" id="paymentProofPreview">
                                <div style="font-size: 12px; color: var(--text-muted);">Preview before submit</div>
                                <img id="paymentProofPreviewImg" src="" alt="Payment proof preview">
                            </div>
                        </div>
                        
                        <div class="payment-submit-bar">
                            <button type="submit" name="upload_payment" class="btn btn-primary payment-submit-btn">
                                Submit Payment Proof
                            </button>
                        </div>
                    </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
    // Success Notification Check
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('success') === 'uploaded' && window.self === window.top) {
        Swal.fire({
            icon: 'success',
            title: 'Payment Submitted!',
            text: 'Your payment proof has been successfully uploaded. It is now pending verification by the caterer.',
            confirmButtonColor: '#173d32',
            iconColor: '#245539',
            background: '#d4dbcd',
            color: '#20372b',
            customClass: {
                popup: 'payment-submitted-modal',
                confirmButton: 'payment-submitted-confirm'
            }
        });
        // Clean URL
        const newUrl = window.location.pathname + '?id=<?php echo $order_id; ?>';
        window.history.replaceState(null, null, newUrl);
    }
    if (urlParams.get('error') === 'terms_required') {
        Swal.fire({
            icon: 'warning',
            title: 'Terms Acceptance Required',
            text: 'Please review and accept the caterer terms and conditions before submitting your payment proof.',
            confirmButtonColor: '#d4af37',
            background: '#1e1e1e',
            color: '#ffffff'
        });
        const newUrl = window.location.pathname + '?id=<?php echo $order_id; ?>';
        window.history.replaceState(null, null, newUrl);
    }
    if (urlParams.get('error') === 'no_amount_due') {
        Swal.fire({
            icon: 'info',
            title: 'No Payment Due',
            text: 'There is no payment amount due for this booking right now.',
            confirmButtonColor: '#d4af37',
            background: '#1e1e1e',
            color: '#ffffff'
        });
        const newUrl = window.location.pathname + '?id=<?php echo $order_id; ?>';
        window.history.replaceState(null, null, newUrl);
    }
    if (urlParams.get('error') === 'upload_failed') {
        Swal.fire({
            icon: 'error',
            title: 'Upload Failed',
            text: 'The payment proof was not saved. Please try again with a clear JPG or PNG screenshot.',
            confirmButtonColor: '#d4af37',
            background: '#1e1e1e',
            color: '#ffffff'
        });
        const newUrl = window.location.pathname + '?id=<?php echo $order_id; ?>';
        window.history.replaceState(null, null, newUrl);
    }
    if (urlParams.get('error') === 'pending_review_exists') {
        Swal.fire({
            icon: 'info',
            title: 'Payment Already Submitted',
            text: 'This booking already has a payment proof pending verification. Please wait for the caterer to review it before uploading another one.',
            confirmButtonColor: '#d4af37',
            background: '#1e1e1e',
            color: '#ffffff'
        });
        const newUrl = window.location.pathname + '?id=<?php echo $order_id; ?>';
        window.history.replaceState(null, null, newUrl);
    }
    // View Proof Modal Logic
    function viewProof(imgSrc, title = 'Payment receipt') {
        document.getElementById('proofViewerTitle').textContent = title;
        document.getElementById('proofImg').alt = title;
        document.getElementById('proofImg').src = imgSrc;
        const viewer = document.getElementById('proofModal');
        viewer.proofReturnFocus = document.activeElement;
        viewer.style.display = 'flex';
        document.getElementById('closeProofButton').focus();
    }

    const paymentProofInput = document.getElementById('paymentProofInput');
    if (paymentProofInput) {
        paymentProofInput.addEventListener('change', function() {
            const preview = document.getElementById('paymentProofPreview');
            const previewImg = document.getElementById('paymentProofPreviewImg');
            const file = this.files && this.files[0] ? this.files[0] : null;

            if (!preview || !previewImg) return;

            if (!file) {
                preview.style.display = 'none';
                previewImg.src = '';
                return;
            }

            previewImg.src = URL.createObjectURL(file);
            preview.style.display = 'block';
        });
    }

    const paymentUploadForm = document.querySelector('.payment-upload-form');
    if (paymentUploadForm) {
        paymentUploadForm.addEventListener('submit', function() {
            const submitBtn = paymentUploadForm.querySelector('button[type="submit"]');
            if (submitBtn) {
                submitBtn.textContent = 'Submitting...';
            }
        });
    }

    // Real-Time Polling for Detail View
    setInterval(function() {
        fetch('payments.php?id=<?php echo $order_id; ?>&fetch_details=1')
            .then(res => res.json())
            .then(data => {
                document.getElementById('grand-total-disp').innerText = '\u20B1' + data.grand_total;
                document.getElementById('paid-disp').innerText = '\u20B1' + data.total_paid;
                document.getElementById('balance-disp').innerText = '\u20B1' + data.balance;
                const amountToUpload = document.getElementById('amount-to-upload-disp');
                if (amountToUpload) amountToUpload.innerText = '\u20B1' + data.upload_amount;
                const modalAmountToUpload = document.getElementById('modal-amount-to-upload');
                if (modalAmountToUpload) modalAmountToUpload.innerText = '\u20B1' + data.upload_amount;
                const guideAmountToUpload = document.getElementById('guide-amount-to-upload');
                if (guideAmountToUpload) guideAmountToUpload.innerText = '\u20B1' + data.upload_amount;
                const payActionBtn = document.getElementById('pay-action-btn');
                if (payActionBtn) {
                    payActionBtn.innerHTML = '<i class="fas fa-wallet"></i> ' + data.pay_button_label;
                }
                
                // Update Tables & Timeline
                if(document.getElementById('charges-table-body')) document.getElementById('charges-table-body').innerHTML = data.charges_html;
                if(document.getElementById('timeline-container')) document.getElementById('timeline-container').innerHTML = data.timeline_html;
                if(document.getElementById('payment-table-body')) document.getElementById('payment-table-body').innerHTML = data.payment_table_html;
            });
    }, 20000);

    const paymentParams = new URLSearchParams(window.location.search);
    if (paymentParams.get('open_pay') === '1') {
        const payModal = document.getElementById('payModal');
        if (payModal) {
            payModal.style.display = 'flex';
        }
        paymentParams.delete('open_pay');
        const cleanedUrl = window.location.pathname + '?' + paymentParams.toString();
        window.history.replaceState(null, null, cleanedUrl);
    }
    
    // Keep the payment form open until its X button is pressed.
    </script>

    <style>
    #payModal { --text-main: #000; --text-muted: #000; --accent-gold: #000; }
    #payModal :is(.payment-amount-label, .payment-amount-value, .payment-qr-number, .payment-qr-helper, .instruction-card, .instruction-card *, .terms-card, .terms-card *, .terms-agreement, .terms-agreement *, .payment-upload-block .form-label) { color: #000; }
    #payModal .payment-qr-button { display: block; max-width: 100%; margin: 0 auto; padding: 0; border: 0; border-radius: 8px; background: transparent; cursor: zoom-in; }
    #payModal .payment-qr-button img { display: block; max-width: 100%; height: auto; margin: 0 auto; }
    #payModal .payment-qr-button:focus-visible { outline: 2px solid #70501e; outline-offset: 4px; }
    #payModal .payment-qr-button:hover { box-shadow: 0 0 0 2px #708b6b; }
    </style>
    <!-- Receipt viewer: only the explicit close button dismisses it. -->
    <style>
    #proofModal { padding: 12px !important; background: rgba(12,32,25,.78) !important; box-sizing: border-box; }
    #proofModal .proof-viewer { width: min(400px,100%) !important; max-width: 400px !important; max-height: calc(100dvh - 24px) !important; margin: auto !important; padding: 0 !important; border: 1px solid #96a38e !important; border-radius: 10px !important; background: #d4dbcd !important; box-shadow: 0 12px 32px rgba(12,32,25,.25) !important; overflow: hidden; display: flex; flex-direction: column; }
    #proofModal .proof-viewer-header { display: flex; justify-content: space-between; align-items: center; gap: 12px; padding: 6px 10px; background: #c9d2c1; border-bottom: 1px solid #96a38e; color: #173d32; font-size: 12px; }
    #proofModal #closeProofButton { position: static !important; display: grid; place-items: center; flex: 0 0 40px; width: 40px; height: 40px; padding: 0 !important; border: 0; border-radius: 7px; background: #173d32 !important; color: #f0f1e6 !important; font-size: 24px !important; cursor: pointer; }
    #proofModal #closeProofButton:hover { background: #2b5544 !important; }
    #proofModal #closeProofButton:focus-visible { outline: 2px solid #70501e; outline-offset: 2px; }
    #proofModal .proof-image-wrap { padding: 10px; min-height: 0; overflow: auto; text-align: center; }
    #proofModal #proofImg { display: block; max-width: 100% !important; max-height: calc(100dvh - 110px) !important; width: auto; height: auto; margin: auto; object-fit: contain; border-radius: 6px; box-shadow: none !important; }
    </style>
    <div id="proofModal" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="proofViewerTitle" style="display: none; z-index: 3000;">
        <div class="modal-content proof-viewer">
            <div class="proof-viewer-header">
                <strong id="proofViewerTitle">Payment receipt</strong>
                <button id="closeProofButton" type="button" aria-label="Close receipt" onclick="closeProofViewer()">&times;</button>
            </div>
            <div class="proof-image-wrap"><img id="proofImg" src="" alt="Submitted payment receipt"></div>
        </div>
    </div>
    <script>
    function closeProofViewer() {
        const viewer = document.getElementById('proofModal');
        viewer.style.display = 'none';
        viewer.proofReturnFocus?.focus();
    }
    document.getElementById('proofModal').addEventListener('keydown', function(event) {
        if (event.key === 'Escape') { event.preventDefault(); event.stopPropagation(); }
        if (event.key === 'Tab') {
            event.preventDefault();
            document.getElementById('closeProofButton').focus();
        }
    });
    </script>

    <?php
    require_once __DIR__ . '/includes/footer.php';
    exit(); // Stop execution here so we don't render the list view below
}

// ==========================================
// MODE: LIST VIEW (Financial Center)
// ==========================================
// (Original code follows below)

// ==========================================
// RENDER GROUPED PAYMENTS HELPER
// ==========================================
if (!function_exists('renderGroupedPayments')) {
    function renderGroupedPayments($list, $is_pending) {
        if (empty($list)) {
            if ($is_pending) {
                return '<div class="empty-state">
                        <h3>All bills paid!</h3>
                        <p style="color: var(--text-muted);">You have no outstanding balances.</p>
                      </div>';
            } else {
                return '<div class="empty-state">
                        <p>No completed payments yet.</p>
                    </div>';
            }
        }
        
        $grouped = [];
        foreach ($list as $pay) {
            $store_name = $pay['caterer'] ?: 'Unknown Caterer';
            $grouped[$store_name][] = $pay;
        }

        ob_start();
        foreach ($grouped as $store_name => $store_orders) {
            $store_id_slug = preg_replace('/[^a-z0-9]/i', '_', strtolower($store_name));
            $unique_id = ($is_pending ? 'pend_' : 'hist_') . $store_id_slug;
            $profile_path = ltrim((string) ($store_orders[0]['profile_image'] ?? ''), '/');
            if ($profile_path === '' || !is_file(dirname(__DIR__) . '/' . $profile_path)) {
                $profile_path = 'uploads/logo.png';
            }
            $group_amount = array_sum(array_column($store_orders, $is_pending ? 'balance' : 'total_paid'));
            
            echo '<div class="card payments-list-card" style="margin-bottom: 20px; overflow: hidden; padding: 0;">';
            echo '<button type="button" class="payment-group-toggle" aria-expanded="false" aria-controls="'.$unique_id.'" onclick="togglePaymentGroup(this)">';
            echo '<img class="payment-group-avatar" src="../' . htmlspecialchars($profile_path, ENT_QUOTES, 'UTF-8') . '" alt="" width="56" height="56">';
            echo '<span class="payment-group-heading"><span class="payment-group-name">' . htmlspecialchars($store_name) . '</span><span class="payment-group-count">' . count($store_orders) . (count($store_orders) === 1 ? ' booking' : ' bookings') . '</span></span>';
            echo '<span class="payment-group-total"><span>' . ($is_pending ? 'Outstanding balance' : 'Total paid') . '</span><strong>&#8369;' . number_format($group_amount, 2) . '</strong></span>';
            echo '</button>';
            
            echo '<div id="'.$unique_id.'" class="caterer-accordion-content" style="display: none;">';
            
            echo '<div class="payments-desktop-table">
                    <table class="data-table" style="margin: 0;">
                        <thead>
                            <tr>
                                <th>Booking</th>
                                ' . ($is_pending ? '<th>Breakdown</th><th>Balance Due</th>' : '<th>Total Amount</th><th>Status</th>') . '
                                <th style="text-align: right;">Action</th>
                            </tr>
                        </thead>
                        <tbody>';
            $formatBookingDates = static function (array $pay): string {
                $bookedAt = strtotime((string) ($pay['created_at'] ?? ''));
                $eventAt = strtotime((string) ($pay['date'] ?? ''));
                return '<div>Booked on: ' . ($bookedAt ? date('M d, Y, g:i A', $bookedAt) : 'Unavailable') . '</div>'
                    . '<div>Event date: ' . ($eventAt ? date('M d, Y', $eventAt) : 'Unavailable') . '</div>';
            };
            foreach ($store_orders as $pay) {
                $bookingDates = $formatBookingDates($pay);
                echo '<tr>';
                echo '<td>
                        <span style="color: var(--accent-gold); font-weight: 600;">Booking #' . htmlspecialchars($pay['booking_number']) . '</span><br>
                        <div class="payment-mobile-date">' . $bookingDates . '</div>
                      </td>';
                      
                if ($is_pending) {
                    $is_amount_missing = (($pay['grand_total'] ?? 0) <= 0 && ($pay['total_paid'] ?? 0) <= 0);
                    $balance_cell = $is_amount_missing
                        ? '<span style="color: #f1c40f; font-weight: 700;">Pending Amount</span>'
                        : '&#8369;' . number_format($pay['balance'], 2);
                        
                    echo '<td>
                            <ul style="list-style: none; padding: 0; margin: 0; font-size: 12px; color: var(--text-muted);">
                                <li>Total: &#8369;' . number_format($pay['grand_total'], 2) . '</li>
                                <li>Paid: <span>&#8369;' . number_format($pay['total_paid'], 2) . '</span></li>';
                    if (($pay['pending_pay'] ?? 0) > 0) {
                        echo '<li style="color: var(--accent-gold);">Pending Verification: &#8369;' . number_format($pay['pending_pay'], 2) . '</li>';
                    }
                    if (($pay['breakdown']['damages'] ?? 0) > 0) {
                        echo '<li style="color: var(--error-color);">Damages: &#8369;' . number_format($pay['breakdown']['damages'], 2) . '</li>';
                    }
                    echo '      </ul>
                          </td>';
                    echo '<td style="font-weight: 700; font-size: 16px; color: ' . ($is_amount_missing ? '#f1c40f' : 'var(--error-color)') . ';">' . $balance_cell . '</td>';
                } else {
                    echo '<td>&#8369;' . number_format($pay['grand_total'], 2) . '</td>';
                    echo '<td><span class="status-badge status-completed">Fully paid</span></td>';
                }
                
                echo '<td style="text-align: right;">
                        <a href="payments.php?id=' . $pay['id'] . '" class="btn btn-primary payments-action-link">View Details</a>
                      </td>';
                echo '</tr>';
            }
            echo '</tbody></table></div>';
            
            echo '<div class="payments-mobile-list">';
            foreach ($store_orders as $pay) {
                $bookingDates = $formatBookingDates($pay);
                echo '<div class="payment-mobile-card">
                        <div class="payment-mobile-top">
                            <div>
                                <div class="payment-mobile-order">Booking #' . htmlspecialchars($pay['booking_number']) . '</div>
                                <div class="payment-mobile-date">' . $bookingDates . '</div>
                            </div>
                            <a href="payments.php?id=' . $pay['id'] . '" class="btn btn-primary payments-action-link">View Details</a>
                        </div>
                        <div class="payment-mobile-grid">';
                
                if ($is_pending) {
                    echo '  <div>
                                <span class="payment-mobile-label">Balance Due</span>
                                <div class="payment-mobile-value" style="color: var(--error-color);">&#8369;' . number_format($pay['balance'], 2) . '</div>
                            </div>
                            <div>
                                <span class="payment-mobile-label">Total</span>
                                <div class="payment-mobile-value">&#8369;' . number_format($pay['grand_total'], 2) . '</div>
                            </div>
                            <div>
                                <span class="payment-mobile-label">Paid</span>
                                <div class="payment-mobile-value">&#8369;' . number_format($pay['total_paid'], 2) . '</div>
                            </div>';
                    if (($pay['pending_pay'] ?? 0) > 0) {
                        echo '<div>
                                <span class="payment-mobile-label">Pending Verification</span>
                                <div class="payment-mobile-value" style="color: var(--accent-gold);">&#8369;' . number_format($pay['pending_pay'], 2) . '</div>
                              </div>';
                    }
                    if (($pay['breakdown']['damages'] ?? 0) > 0) {
                        echo '<div>
                                <span class="payment-mobile-label">Damages</span>
                                <div class="payment-mobile-value" style="color: var(--error-color);">&#8369;' . number_format($pay['breakdown']['damages'], 2) . '</div>
                              </div>';
                    }
                } else {
                    echo '  <div>
                                <span class="payment-mobile-label">Total Amount</span>
                                <div class="payment-mobile-value">&#8369;' . number_format($pay['grand_total'], 2) . '</div>
                            </div>
                            <div>
                                <span class="payment-mobile-label">Status</span>
                                <div class="payment-mobile-value"><span class="status-badge status-completed">Fully paid</span></div>
                            </div>';
                }
                
                echo '      </div>
                      </div>';
            }
            echo '</div></div></div>';
        }
        
        return ob_get_clean();
    }
}

// ==========================================
// HANDLE REAL-TIME DATA FETCH (AJAX) - LIST MODE
// ==========================================
if (isset($_GET['fetch_data'])) {
    $query = "
        SELECT 
            o.id AS order_id, o.store_id, o.order_number,
            o.order_date, o.created_at,
            o.order_status,
            u.profile_image,
            sd.business_name,
            sd.gcash_qr_image as gcash_qr,
            sd.gcash_number
        FROM bookings o
        LEFT JOIN users u ON o.store_id = u.id
        LEFT JOIN store_details sd ON u.id = sd.user_id
        WHERE o.customer_id = '$user_id' 
        AND (o.order_status != 'cancelled' OR o.order_status IS NULL)
        ORDER BY o.id DESC
    ";
    
    $result = mysqli_query($conn, $query);
    if (!$result) {
        appLogError('Customer payments fetch_data query failed', mysqli_error($conn));
        echo json_encode(['error' => 'Unable to load payment data right now. Please refresh and try again.']);
        exit;
    }
    
    $pending_list = [];
    $history_list = [];
    $total_due_summary = 0;
    $total_paid_summary = 0;
    
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $order_id = intval($row['order_id'] ?? 0);
            if ($order_id <= 0) {
                continue;
            }
            $fin = getOrderFinancials($order_id);
            
            // Correctly access data from nested structure
            $grand_total = $fin['totals']['grand_total'] ?? 0;
            $total_paid = $fin['totals']['total_paid'] ?? 0;
            $pending_pay = $fin['totals']['pending_payments'] ?? 0;
            $balance = $fin['totals']['balance'] ?? 0;
            
            // Check if user is trying to filter by status or something else? No, list all valid orders
            // Logic check: if balance > 0 OR pending > 0 -> Pending Bill
            // If balance <= 0 AND pending == 0 -> History
            
            $total_due_summary += $balance;
            $total_paid_summary += $total_paid;
            
            $item = [
                'id' => $order_id,
                'booking_number' => formatCatererBookingNumber($order_id, $row['order_number'], $row['store_id']),
                'caterer' => $row['business_name'],
                'profile_image' => $row['profile_image'] ?? '',
                'date' => $row['order_date'],
                'created_at' => $row['created_at'],
                'grand_total' => $grand_total,
                'total_paid' => $total_paid,
                'pending_pay' => $pending_pay,
                'balance' => $balance,
                'breakdown' => $fin['breakdown'],
                'history' => $fin['history'],
                'status' => $fin['status'],
                'qr' => $row['gcash_qr'],
                'gcash_num' => $row['gcash_number']
            ];
            
            $is_amount_missing = ($grand_total <= 0 && $total_paid <= 0);

            if ($balance > 0 || $is_amount_missing) {
                $pending_list[] = $item;
            } else {
                $history_list[] = $item;
            }
        }
    }
    
    // Generate HTML for Pending
    $pending_html = renderGroupedPayments($pending_list, true);
    
    // Generate HTML for History
    $history_html = renderGroupedPayments($history_list, false);
    
    header('Content-Type: application/json');
    echo json_encode([
        'total_due' => number_format($total_due_summary, 2),
        'total_paid' => number_format($total_paid_summary, 2),
        'pending_count' => count($pending_list),
        'pending_html' => $pending_html,
        'history_html' => $history_html
    ]);
    exit();
}

$page_title = 'Financial Center';
require_once __DIR__ . '/includes/header.php';
echo '<script defer src="../assets/js/customer-payment-return.js?v=20261020-3"></script>';

// ==========================================
// INITIAL DATA FETCH (For first load)
// ==========================================
// Note: We use the same logic as AJAX but executed inline to avoid layout shift
$query = "
        SELECT 
            o.id AS order_id, o.store_id, o.order_number,
            o.order_date, o.created_at,
            o.order_status,
            u.profile_image,
            sd.business_name,
            sd.gcash_qr_image as gcash_qr,
            sd.gcash_number
        FROM bookings o
    LEFT JOIN users u ON o.store_id = u.id
    LEFT JOIN store_details sd ON u.id = sd.user_id
    WHERE o.customer_id = '$user_id' 
    AND (o.order_status != 'cancelled' OR o.order_status IS NULL)
    ORDER BY o.id DESC
";

$result = mysqli_query($conn, $query);
if (!$result) {
    appLogError('Customer payments initial query failed', mysqli_error($conn));
    echo '<div class="alert alert-error" style="margin-bottom:20px;">Unable to load your payment list right now. Please refresh and try again.</div>';
}

$pending_list = [];
$history_list = [];
$total_due_summary = 0;
$total_paid_summary = 0;

if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $order_id = intval($row['order_id'] ?? 0);
            if ($order_id <= 0) {
                continue;
            }
            $fin = getOrderFinancials($order_id);
            
            // Correctly access data from nested structure
            $grand_total = $fin['totals']['grand_total'] ?? 0;
            $total_paid = $fin['totals']['total_paid'] ?? 0;
            $pending_pay = $fin['totals']['pending_payments'] ?? 0;
            $balance = $fin['totals']['balance'] ?? 0;
            
            $total_due_summary += $balance;
            $total_paid_summary += $total_paid;
            
            $item = [
                'id' => $order_id,
                'booking_number' => formatCatererBookingNumber($order_id, $row['order_number'], $row['store_id']),
                'caterer' => $row['business_name'],
                'profile_image' => $row['profile_image'] ?? '',
                'date' => $row['order_date'],
                'created_at' => $row['created_at'],
                'grand_total' => $grand_total,
                'total_paid' => $total_paid,
                'pending_pay' => $pending_pay,
                'balance' => $balance,
                'breakdown' => $fin['breakdown'],
                'history' => $fin['history'],
                'status' => $fin['status'],
                'qr' => $row['gcash_qr'],
                'gcash_num' => $row['gcash_number']
            ];
            
            if ($balance > 0) {
                $pending_list[] = $item;
            } else {
                $history_list[] = $item;
            }
        }
    }


?>
<link rel="stylesheet" href="../assets/css/customer-payments.css?v=<?php echo filemtime(__DIR__ . '/../assets/css/customer-payments.css'); ?>">
<div class="payments-center">
    <!-- Top Stats -->
    <div class="payments-summary-grid">
        <div class="finance-card finance-card-due">
            <div class="finance-label">Outstanding balance</div>
            <div id="total-due-display" class="finance-amount">&#8369;<?php echo number_format($total_due_summary, 2); ?></div>
            <div class="finance-note">Across your current bookings</div>
        </div>
        
        <div class="finance-card">
            <div class="finance-label">Total paid</div>
            <div id="total-paid-display" class="finance-amount">&#8369;<?php echo number_format($total_paid_summary, 2); ?></div>
            <div class="finance-note">Verified payments to date</div>
        </div>
    </div>

    <!-- Tabs -->
    <div class="payments-tabs">
        <button type="button" id="pending-tab-btn" class="tab-btn active" aria-pressed="true" aria-controls="pending-tab" onclick="switchTab('pending', this)">Pending Bills (<?php echo count($pending_list); ?>)</button>
        <button type="button" id="history-tab-btn" class="tab-btn" aria-pressed="false" aria-controls="history-tab" onclick="switchTab('history', this)">Payment History</button>
    </div>

    <!-- PENDING LIST -->
    <div id="pending-tab">
        <?php echo renderGroupedPayments($pending_list, true); ?>
    </div>

    <!-- HISTORY LIST -->
    <div id="history-tab" style="display: none;">
        <?php echo renderGroupedPayments($history_list, false); ?>
    </div>

</div>

<script>
function togglePaymentGroup(button) {
    const content = document.getElementById(button.getAttribute('aria-controls'));
    if (!content) return;
    const expanded = button.getAttribute('aria-expanded') !== 'true';
    button.setAttribute('aria-expanded', String(expanded));
    content.style.display = expanded ? 'block' : 'none';
}

function switchTab(tab, triggerButton) {
    const pendingTab = document.getElementById('pending-tab');
    const historyTab = document.getElementById('history-tab');
    if (!pendingTab || !historyTab) return;

    pendingTab.style.display = tab === 'pending' ? 'block' : 'none';
    historyTab.style.display = tab === 'history' ? 'block' : 'none';
    
    document.querySelectorAll('.payments-center .tab-btn').forEach(btn => {
        btn.classList.remove('active');
        btn.setAttribute('aria-pressed', 'false');
    });
    const activeButton = triggerButton || document.getElementById(tab + '-tab-btn');
    if (activeButton) {
        activeButton.classList.add('active');
        activeButton.setAttribute('aria-pressed', 'true');
    }
}

document.addEventListener('DOMContentLoaded', function() {
    const params = new URLSearchParams(window.location.search);
    const initialTab = params.get('tab');
    if (initialTab === 'history' || initialTab === 'pending') {
        switchTab(initialTab);
    }
});

// Real-time Updates (List Mode) — only updates counters, NOT the accordion HTML
// so that open accordion panels stay open.
function refreshFinancials() {
    fetch('payments.php?fetch_data=1')
        .then(response => response.json())
        .then(data => {
            const dueEl  = document.getElementById('total-due-display');
            const paidEl = document.getElementById('total-paid-display');
            const btnEl  = document.getElementById('pending-tab-btn');
            if (dueEl)  dueEl.innerText  = '\u20B1' + data.total_due;
            if (paidEl) paidEl.innerText = '\u20B1' + data.total_paid;
            if (btnEl)  btnEl.innerText  = 'Pending Bills (' + data.pending_count + ')';
            // NOTE: We intentionally do NOT replace pending-tab / history-tab innerHTML
            // here so that open accordion sections are not collapsed on every refresh.
        })
        .catch(err => console.error('Error fetching financial updates:', err));
}

// Refresh summary numbers every 30 seconds (non-disruptive)
setInterval(refreshFinancials, 30000);
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

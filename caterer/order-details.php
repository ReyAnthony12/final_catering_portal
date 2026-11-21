<?php
// Start session
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config.php';
/** @var mysqli $conn */
require_once __DIR__ . '/../includes/financial_helper.php';
require_once __DIR__ . '/../includes/booking_pricing_helper.php';
require_once __DIR__ . '/../includes/order_reference_helper.php';
require_once __DIR__ . '/../includes/menu_change_helper.php';
require_once __DIR__ . '/../includes/reschedule_helper.php';

if (!isLoggedIn() || !in_array(getUserRole(), ['store', 'caterer'])) {
    header('Location: ../login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$order_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Preserve the selected day only for bookings opened from capacity.
$capacity_return_date = (string) ($_GET['capacity_date'] ?? '');
$capacity_date_parts = explode('-', $capacity_return_date);
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $capacity_return_date)
    || !checkdate((int) ($capacity_date_parts[1] ?? 0), (int) ($capacity_date_parts[2] ?? 0), (int) ($capacity_date_parts[0] ?? 0))) {
    $capacity_return_date = '';
}
$capacity_return_query = $capacity_return_date !== '' ? '&capacity_date=' . rawurlencode($capacity_return_date) : '';
$order_back_href = $capacity_return_date !== ''
    ? 'booking-capacity.php?date=' . rawurlencode($capacity_return_date)
    : 'reservations.php';

// Dashboard links carry their own return context; other entry points keep their flow.
$dashboard_return_date = (string) ($_GET['dashboard_date'] ?? '');
$dashboard_parts = explode('-', $dashboard_return_date);
if ($capacity_return_date === ''
    && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $dashboard_return_date)
    && checkdate((int) ($dashboard_parts[1] ?? 0), (int) ($dashboard_parts[2] ?? 0), (int) ($dashboard_parts[0] ?? 0))) {
    $dashboard_page = max(1, (int) ($_GET['dashboard_page'] ?? 1));
    $capacity_return_query = '&dashboard_date=' . rawurlencode($dashboard_return_date) . '&dashboard_page=' . $dashboard_page;
    $order_back_href = 'dashboard.php?booking_date=' . rawurlencode($dashboard_return_date)
        . '&booking_page=' . $dashboard_page . '&booking_id=' . $order_id;
}
// Only explicit reservation entry links override the default reservation destination.
$reservation_return_tab = (string) ($_GET['reservation_tab'] ?? '');
if ($capacity_return_query === '' && in_array($reservation_return_tab, ['pending', 'completed', 'cancelled', 'trash'], true)) {
    $reservation_return_action = (string) ($_GET['reservation_action'] ?? 'all');
    if (!in_array($reservation_return_action, ['all', 'payments', 'reschedules', 'menu_changes', 'needs_attention'], true)) {
        $reservation_return_action = 'all';
    }
    $capacity_return_query = '&reservation_tab=' . rawurlencode($reservation_return_tab)
        . '&reservation_action=' . rawurlencode($reservation_return_action);
    $order_back_href = 'reservations.php?tab=' . rawurlencode($reservation_return_tab)
        . '&action=' . rawurlencode($reservation_return_action);
}

// Only links explicitly opened from Event Preparation use this return route.
if ($capacity_return_query === '' && ($_GET['from'] ?? '') === 'event_preparation') {
    $capacity_return_query = '&from=event_preparation';
    $order_back_href = 'event_preparation.php';
}

if ($order_id <= 0) {
    header('Location: reservations.php');
    exit();
}

// Fetch Order Details
$query = "
    SELECT o.*, u.first_name, u.last_name, u.phone_number, u.email,
           p.name as product_name, p.category, p.description as product_desc
    FROM bookings o 
    JOIN users u ON o.customer_id = u.id 
    LEFT JOIN products p ON o.product_id = p.id 
    WHERE o.id = '$order_id' AND o.store_id = '$user_id'
";
$result = mysqli_query($conn, $query);

if (mysqli_num_rows($result) === 0) {
    echo "Order not found.";
    exit();
}

$order = mysqli_fetch_assoc($result);
$csrf_token = generateCSRFToken();

$inventory_notice = '';
if (isset($_POST['replenish_booking_inventory'])) {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        exit('Invalid security token. Please reload the page.');
    }
    $item_id = (int) ($_POST['inventory_item_id'] ?? 0);
    $add_qty = filter_var($_POST['stock_quantity'] ?? null, FILTER_VALIDATE_INT);
    if ($item_id > 0 && $add_qty !== false && $add_qty > 0) {
        $stock_stmt = $conn->prepare('UPDATE inventory_items SET total_stock = total_stock + ?, available_stock = available_stock + ? WHERE id = ? AND store_id = ?');
        $stock_stmt->bind_param('iiii', $add_qty, $add_qty, $item_id, $user_id);
        $stock_stmt->execute();
        if ($stock_stmt->affected_rows > 0) {
            header('Location: order-details.php?id=' . $order_id . $capacity_return_query . '&inventory_updated=1');
            exit();
        }
    }
    $inventory_notice = 'Unable to add stock. Enter a positive whole number for an item in your inventory.';
}

// Stock preparation is internal and never changes the customer menu or bill.
$booking_inventory = [];
$inventory_product_id = (int) $order['product_id'];
$inventory_set_name = mysqli_real_escape_string($conn, trim((string) ($order['additional_menu'] ?? '')));
$inventory_store_id = (int) $user_id;
$inventory_rows = mysqli_query($conn, "SELECT si.inventory_item_id, si.quantity_required, si.is_pax_dependent, si.set_name,
    ii.item_name, ii.available_stock,
    (SELECT SUM(ei.quantity_required) FROM event_inventory ei
        JOIN events e ON e.id = ei.event_id
        WHERE e.order_id = '$order_id' AND e.store_id = '$inventory_store_id'
        AND ei.item_name = ii.item_name) AS preparation_required,
    COALESCE((SELECT SUM(iu.quantity_used) FROM inventory_usage iu WHERE iu.order_id = '$order_id'
        AND iu.inventory_item_id = ii.id AND iu.status IN ('reserved', 'active')), 0) AS reserved_for_booking
    FROM service_inventory si JOIN inventory_items ii ON ii.id = si.inventory_item_id
    WHERE si.service_id = '$inventory_product_id' AND ii.store_id = '$inventory_store_id'
    AND (si.set_name = '$inventory_set_name' OR si.set_name IS NULL OR si.set_name = '')");
if ($inventory_rows) {
    $all_inventory_rows = mysqli_fetch_all($inventory_rows, MYSQLI_ASSOC);
    $has_package_inventory = $inventory_set_name !== '' && count(array_filter($all_inventory_rows,
        fn($item) => (string) $item['set_name'] === trim((string) ($order['additional_menu'] ?? '')))) > 0;
    foreach ($all_inventory_rows as $item) {
        if ($has_package_inventory && (string) $item['set_name'] !== trim((string) ($order['additional_menu'] ?? ''))) continue;
        $id = (int) $item['inventory_item_id'];
        $required = max(0, (int) $item['quantity_required']);
        if (!empty($item['is_pax_dependent']) && $required > 0) $required = max(0, (int) $order['number_of_pax']);
        if (!isset($booking_inventory[$id])) $booking_inventory[$id] = $item + ['required' => 0];
        $booking_inventory[$id]['required'] += $required;
    }
    // The booking's saved preparation list takes precedence over package defaults.
    // Apply after aggregation so duplicate package mappings do not multiply it.
    foreach ($booking_inventory as &$item) {
        if ($item['preparation_required'] !== null) {
            $item['required'] = max(0, (int) $item['preparation_required']);
        }
    }
    unset($item);
}

function recalculateAndSaveOrderPricing(mysqli $conn, array $order, int $order_id, array $overrides = []): ?array
{
    $pricing = bookingPricingCalculateStoredOrder($conn, $order, $overrides);
    if (!$pricing) {
        return null;
    }

    $extra_updates = [];
    foreach (['additional_menu', 'number_of_pax'] as $column) {
        if (array_key_exists($column, $overrides)) {
            $extra_updates[$column] = $overrides[$column];
        }
    }

    if (!bookingPricingUpdateOrderFields($conn, $order_id, $pricing, $extra_updates)) {
        return null;
    }

    return $pricing;
}

// --- FINANCIALS (New Logic) ---
$financials = getOrderFinancials($order_id);
// ------------------------------

function parseStoreMenuChangeRequestDetails($message_text) {
    $details = [
        'current_set' => '',
        'requested_set' => '',
        'current_total' => 0,
        'new_total' => 0,
        'already_paid' => 0,
        'difference' => 0,
        'additional_needed' => 0
    ];

    $patterns = [
        'current_set' => '/Current Set:\s*(.+)/i',
        'requested_set' => '/Requested Set:\s*(.+)/i',
        'current_total' => '/Current Set Total:\s*PHP\s*([\d,]+(?:\.\d{1,2})?)/i',
        'new_total' => '/New Set Total:\s*PHP\s*([\d,]+(?:\.\d{1,2})?)/i',
        'already_paid' => '/Already Paid:\s*PHP\s*([\d,]+(?:\.\d{1,2})?)/i',
        'difference' => '/Price Difference:\s*([+-]?)PHP\s*([\d,]+(?:\.\d{1,2})?)/i',
        'additional_needed' => '/Additional Amount Needed:\s*PHP\s*([\d,]+(?:\.\d{1,2})?)/i'
    ];

    foreach ($patterns as $key => $pattern) {
        if (preg_match($pattern, $message_text, $matches)) {
            if (in_array($key, ['current_total', 'new_total', 'already_paid', 'additional_needed'], true)) {
                $details[$key] = floatval(str_replace(',', '', $matches[1]));
            } elseif ($key === 'difference') {
                $amount = floatval(str_replace(',', '', $matches[2]));
                $details[$key] = (($matches[1] ?? '') === '-') ? -$amount : $amount;
            } else {
                $details[$key] = trim($matches[1]);
            }
        }
    }

    return $details;
}

// Initialize variables
$setName = $order['product_name'] ?? '';

// ... (Rest of menu parsing logic remains same) ...
$sets_lookup = [];
$sets_query = "
    SELECT ps.* 
    FROM product_sets ps
    JOIN products p ON ps.product_id = p.id
    WHERE p.store_id = '$user_id'
";
$sets_result = mysqli_query($conn, $sets_query);
while ($set = mysqli_fetch_assoc($sets_result)) {
    $name = $set['set_name'] ?? $set['name'];
    $sets_lookup[$name] = $set;
}

// Parse Additional Menu
$raw_menu = $order['additional_menu'] ?? '';
// Robust splitting: split by pipe surrounded by optional whitespace
$parts = preg_split('/\s*\|\s*/', trim((string) $raw_menu), -1, PREG_SPLIT_NO_EMPTY);

$setName = '';
$swaps = [];
$addons = [];
$customRequest = '';
$addonLabels = ['Added Items:', 'Add-ons:', 'Add Ons:'];
$swapLabels = ['Menu Swaps:', 'Swaps:'];
$customLabels = ['Custom Add-on Request:', 'Custom Request:', 'Special Request:'];

if (!empty($parts)) {
    // The first part is usually the Set Name, unless it starts with "Added Items:" or similar
    $firstPart = trim((string) $parts[0]);
    $startsWithKnownLabel = false;
    foreach (array_merge($addonLabels, $swapLabels, $customLabels) as $label) {
        if (stripos($firstPart, $label) === 0) {
            $startsWithKnownLabel = true;
            break;
        }
    }

    if (!$startsWithKnownLabel) {
        $setName = $firstPart;
    }
    
    foreach ($parts as $part) {
        $part = trim((string) $part);
        if ($part === '' || $part === $setName) continue;

        $matched = false;

        foreach ($addonLabels as $label) {
            if (stripos($part, $label) === 0) {
                $itemsStr = trim(substr($part, strlen($label)));
                if (preg_match_all('/(?:^|,\s*)(.+?)\s*(\(\+[^\)]*\))(?=,|$)/', $itemsStr, $matches, PREG_SET_ORDER)) {
                    foreach ($matches as $match) {
                        $addons[] = trim($match[1]) . " " . $match[2];
                    }
                } elseif ($itemsStr !== '') {
                    $addons = array_map('trim', explode(',', $itemsStr));
                }
                $matched = true;
                break;
            }
        }
        if ($matched) continue;

        foreach ($swapLabels as $label) {
            if (stripos($part, $label) === 0) {
                $itemsStr = trim(substr($part, strlen($label)));
                if (preg_match_all('/(?:^|,\s*)(.+?)\s*(\(\+[^\)]*\))(?=,|$)/', $itemsStr, $matches, PREG_SET_ORDER)) {
                    foreach ($matches as $match) {
                        $swaps[] = trim($match[1]) . " " . $match[2];
                    }
                } elseif ($itemsStr !== '') {
                    $swaps = array_map('trim', explode(',', $itemsStr));
                }
                $matched = true;
                break;
            }
        }
        if ($matched) continue;

        foreach ($customLabels as $label) {
            if (stripos($part, $label) === 0) {
                $customRequest = trim(substr($part, strlen($label)));
                break;
            }
        }
    }
}

// Store acknowledgement against the current customization content.
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS booking_menu_reviews (
    booking_id INT NOT NULL PRIMARY KEY,
    content_hash CHAR(64) NOT NULL,
    reviewed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
)");
$menu_review_hash = hash('sha256', json_encode([$swaps, $addons]));
if (isset($_POST['complete_menu_review'])) {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        exit('Invalid security token. Please reload the page.');
    }
    $review_stmt = $conn->prepare('INSERT INTO booking_menu_reviews (booking_id, content_hash) VALUES (?, ?) ON DUPLICATE KEY UPDATE content_hash = VALUES(content_hash), reviewed_at = CURRENT_TIMESTAMP');
    $review_stmt->bind_param('is', $order_id, $menu_review_hash);
    $review_stmt->execute();
    header('Location: order-details.php?id=' . $order_id . $capacity_return_query);
    exit();
}
$menu_review_complete = false;
$review_result = mysqli_query($conn, "SELECT content_hash FROM booking_menu_reviews WHERE booking_id = '$order_id'");
if ($review_result && ($review_row = mysqli_fetch_assoc($review_result))) {
    $menu_review_complete = hash_equals($review_row['content_hash'], $menu_review_hash);
}
$pending_menu_tasks = 0;
if ((!empty($swaps) || !empty($addons)) && !$menu_review_complete) $pending_menu_tasks++;
if ($customRequest !== '' && !preg_match('/\(\+₱([\d,]+(?:\.\d+)?)\)\s*$/u', $customRequest)) $pending_menu_tasks++;
foreach ($booking_inventory as $inventory_item) {
    if ($inventory_item['required'] > max(0, (int) $inventory_item['available_stock'] + (int) $inventory_item['reserved_for_booking'])) $pending_menu_tasks++;
}

// Get Original Items if Set matches
$originalItems = [];

// Helper to extract items from a set description
function extractItemsFromSet($set, $sets_lookup) {
    $candidates = [];
    if (!empty($set['set_name'])) $candidates[] = $set['set_name'];
    if (!empty($set['name'])) $candidates[] = $set['name'];
    foreach ($candidates as $name) {
        if (isset($sets_lookup[$name])) {
            return $sets_lookup[$name];
        }
    }
    return null;
}

// Attempt 1: exact match from parsed setName (from additional_menu)
if ($setName && isset($sets_lookup[$setName])) {
    $setDesc = $sets_lookup[$setName]['description'];
    $originalItems = bookingPricingParseSetDescription($setDesc)['items'];
}

// Attempt 2: fallback to product_name directly
if (empty($originalItems)) {
    $productName = trim($order['product_name'] ?? '');
    if ($productName && isset($sets_lookup[$productName])) {
        $setDesc = $sets_lookup[$productName]['description'];
        $originalItems = bookingPricingParseSetDescription($setDesc)['items'];
    }
}

// Attempt 3: fuzzy/partial match in sets_lookup
if (empty($originalItems)) {
    $searchName = $setName ?: trim($order['product_name'] ?? '');
    foreach ($sets_lookup as $key => $set) {
        if ($searchName && (stripos($key, $searchName) !== false || stripos($searchName, $key) !== false)) {
            $setDesc = $set['description'];
            $originalItems = bookingPricingParseSetDescription($setDesc)['items'];
            if (!empty($originalItems)) break;
        }
    }
}

// Clean up items
if (!empty($originalItems)) {
    $originalItems = array_map(function($item) {
        return trim(preg_replace('/^[\d\.\-\•]+/', '', $item)); 
    }, $originalItems);
    $originalItems = array_filter($originalItems); 
}

$menu_change_request = null;
$menu_change_status = null;
$menu_change_message_id = 0;
$menu_change_history = [];
$reschedule_history = [];

$menu_request_q = mysqli_query($conn, "
    SELECT *
    FROM messages
    WHERE order_id = '$order_id'
      AND sender_id = '{$order['customer_id']}'
      AND (subject LIKE '%Menu Change Request%' OR message LIKE '%Requested Set:%')
    ORDER BY created_at DESC, id DESC
    LIMIT 1
");
if ($menu_request_q && $menu_request_row = mysqli_fetch_assoc($menu_request_q)) {
    $menu_change_request = parseStoreMenuChangeRequestDetails($menu_request_row['message'] ?? '');
    if (!empty($menu_change_request['requested_set'])) {
        $menu_change_message_id = intval($menu_request_row['id']);
        $status_log_q = mysqli_query($conn, "
            SELECT action, created_at
            FROM booking_logs
            WHERE order_id = '$order_id'
              AND action IN ('Menu Change Approved', 'Menu Change Rejected')
              AND created_at >= '{$menu_request_row['created_at']}'
            ORDER BY created_at DESC, id DESC
            LIMIT 1
        ");
        if ($status_log_q && $status_log = mysqli_fetch_assoc($status_log_q)) {
            $menu_change_status = $status_log['action'] === 'Menu Change Approved' ? 'approved' : 'rejected';
        } else {
            $menu_change_status = 'pending';
        }
    } else {
        $menu_change_request = null;
    }
}

$menu_history_q = mysqli_query($conn, "
    SELECT action, details, created_at
    FROM booking_logs
    WHERE order_id = '$order_id'
      AND action IN ('Menu Change Requested', 'Menu Change Approved', 'Menu Change Rejected')
    ORDER BY created_at DESC, id DESC
");
if ($menu_history_q) {
    while ($menu_history_row = mysqli_fetch_assoc($menu_history_q)) {
        $menu_change_history[] = $menu_history_row;
    }
}

$reschedule_status = strtolower(trim((string) ($order['reschedule_status'] ?? '')));
$reschedule_request = null;
if (!empty($order['requested_order_date']) && !empty($order['requested_order_time'])) {
    $reschedule_request = [
        'current_date' => $order['order_date'],
        'current_time' => $order['order_time'],
        'requested_date' => $order['requested_order_date'],
        'requested_time' => $order['requested_order_time'],
        'reason' => $order['reschedule_reason'] ?? '',
        'response' => $order['reschedule_response'] ?? '',
        'requested_at' => $order['reschedule_requested_at'] ?? '',
        'resolved_at' => $order['reschedule_resolved_at'] ?? ''
    ];
}

$reschedule_history_q = mysqli_query($conn, "
    SELECT action, details, created_at
    FROM booking_logs
    WHERE order_id = '$order_id'
      AND action IN ('Reschedule Requested', 'Reschedule Approved', 'Reschedule Rejected')
    ORDER BY created_at DESC, id DESC
");
if ($reschedule_history_q) {
    while ($reschedule_row = mysqli_fetch_assoc($reschedule_history_q)) {
        $reschedule_history[] = $reschedule_row;
    }
}

if (isset($_POST['menu_change_action'])) {
    if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        header("Location: order-details.php?id=$order_id$capacity_return_query&error=invalid_token");
        exit();
    }

    $action = $_POST['menu_change_action'];
    $message_id = intval($_POST['message_id'] ?? 0);
    $posted_order_id = intval($_POST['order_id'] ?? 0);

    if ($posted_order_id > 0 && $posted_order_id !== $order_id) {
        header("Location: order-details.php?id=$order_id$capacity_return_query&error=menu_request_not_found");
        exit();
    }

    $message_q = mysqli_query($conn, "
        SELECT m.*, o.customer_id, o.product_id, o.additional_menu, o.addons_total, o.extra_fee, o.order_number
        FROM messages m
        JOIN bookings o ON m.order_id = o.id
        WHERE m.id = '$message_id' AND m.order_id = '$order_id' AND o.store_id = '$user_id'
        LIMIT 1
    ");
    $message_row = $message_q ? mysqli_fetch_assoc($message_q) : null;

    if ($message_row) {
        $request_details = parseStoreMenuChangeRequestDetails($message_row['message'] ?? '');
        $requested_set = trim($request_details['requested_set'] ?? '');
        $current_set = trim($request_details['current_set'] ?? '');

        if ($requested_set !== '') {
            if ($action === 'approve') {
                $result = applyApprovedMenuChange($conn, [
                    'order_id' => $order_id,
                    'store_user_id' => $user_id,
                    'customer_id' => intval($message_row['customer_id']),
                    'product_id' => intval($message_row['product_id']),
                    'message_id' => $message_id,
                    'order_number' => $message_row['order_number'],
                    'requested_set' => $requested_set,
                    'current_set' => $current_set,
                    'additional_menu' => $message_row['additional_menu'] ?? '',
                    'number_of_pax' => intval($message_row['number_of_pax'] ?? ($message_row['quantity'] ?? 1)),
                    'addons_total' => floatval($message_row['addons_total'] ?? 0),
                    'extra_fee' => floatval($message_row['extra_fee'] ?? 0)
                ]);
                if (!$result['success']) {
                    $error = $result['error'] ?? 'menu_change_update_failed';
                    header("Location: order-details.php?id=$order_id$capacity_return_query&error=$error");
                    exit();
                }
                header("Location: order-details.php?id=$order_id$capacity_return_query&success=menu_change_approved");
                exit();
            } elseif ($action === 'reject') {
                $reject_reason = trim($_POST['reject_reason'] ?? '');
                if ($reject_reason === '') {
                    header("Location: order-details.php?id=$order_id$capacity_return_query&error=reject_reason_required");
                    exit();
                }
                $result = applyRejectedMenuChange($conn, [
                    'order_id' => $order_id,
                    'store_user_id' => $user_id,
                    'customer_id' => intval($message_row['customer_id']),
                    'message_id' => $message_id,
                    'order_number' => $message_row['order_number'],
                    'requested_set' => $requested_set,
                    'current_set' => $current_set,
                    'reject_reason' => $reject_reason
                ]);
                if (!$result['success']) {
                    $error = $result['error'] ?? 'menu_change_reply_failed';
                    header("Location: order-details.php?id=$order_id$capacity_return_query&error=$error");
                    exit();
                }
                header("Location: order-details.php?id=$order_id$capacity_return_query&success=menu_change_rejected");
                exit();
            }
        }
    }
}

if (isset($_POST['report_non_payment'])) {
    if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        header("Location: order-details.php?id=$order_id$capacity_return_query&error=invalid_token");
        exit();
    }
    
    $customer_id = intval($order['customer_id']);
    
    // Submit report request to portal admin instead of direct deactivation
    $reason = mysqli_real_escape_string($conn, "Non-payment of downpayment for booking.");
    mysqli_query($conn, "INSERT INTO user_reports (reporter_id, reported_user_id, order_id, reason, status, created_at) VALUES ('$user_id', '$customer_id', '$order_id', '$reason', 'pending', NOW())");
    
    // Cancel the order
    mysqli_query($conn, "UPDATE bookings SET order_status = 'cancelled' WHERE id = '$order_id'");
    
    // Log action
    $log_action = "Reported Non-Payment";
    $log_details = "Customer was reported for non-payment. Order cancelled and request sent to Portal Admin to deactivate account.";
    mysqli_query($conn, "INSERT INTO booking_logs (order_id, user_id, action, details, created_at) VALUES ('$order_id', '$user_id', '$log_action', '$log_details', NOW())");
    
    header("Location: order-details.php?id=$order_id$capacity_return_query&success=reported_non_payment");
    exit();
}

if (isset($_POST['reschedule_action'])) {
    if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        header("Location: order-details.php?id=$order_id$capacity_return_query&reschedule=invalid_token");
        exit();
    }

    if ($reschedule_status !== 'pending' || !$reschedule_request) {
        header("Location: order-details.php?id=$order_id$capacity_return_query&reschedule=no_pending_request");
        exit();
    }

    $action = $_POST['reschedule_action'];

    if ($action === 'approve') {
        $result = approveRescheduleRequest($conn, [
            'order_id' => $order_id,
            'store_user_id' => $user_id,
            'customer_id' => intval($order['customer_id']),
            'order_number' => $order['order_number'] ?? '',
            'old_date' => date('F d, Y', strtotime($order['order_date'])),
            'old_time' => !empty($order['order_time']) ? date('h:i A', strtotime($order['order_time'])) : 'N/A',
            'new_date' => $order['requested_order_date'],
            'new_time' => $order['requested_order_time']
        ]);
        header("Location: order-details.php?id=$order_id$capacity_return_query" . ($result['success'] ? '&reschedule=approved' : '&reschedule=failed'));
        exit();
    }

    if ($action === 'reject') {
        $reject_reason = trim($_POST['reject_reason'] ?? '');
        if ($reject_reason === '') {
            header("Location: order-details.php?id=$order_id$capacity_return_query&reschedule=reject_reason_required");
            exit();
        }

        $result = rejectRescheduleRequest($conn, [
            'order_id' => $order_id,
            'store_user_id' => $user_id,
            'customer_id' => intval($order['customer_id']),
            'order_number' => $order['order_number'] ?? '',
            'requested_date' => $order['requested_order_date'],
            'requested_time' => $order['requested_order_time'],
            'reject_reason' => $reject_reason
        ]);
        header("Location: order-details.php?id=$order_id$capacity_return_query" . ($result['success'] ? '&reschedule=rejected' : '&reschedule=failed'));
        exit();
    }
}

// Handle Damage Report Actions
if (isset($_POST['add_damage'])) {
    $item_name = mysqli_real_escape_string($conn, $_POST['item_name']);
    $quantity = intval($_POST['quantity']);
    $price = floatval($_POST['price']);
    $total = $quantity * $price;
    $notes = mysqli_real_escape_string($conn, $_POST['notes']);
    
    // Handle Photo Upload
    $damage_photo = null;
    if (isset($_FILES['damage_photo']) && $_FILES['damage_photo']['error'] == 0) {
        $upload_dir = '../uploads/damages/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
        
        $file_ext = strtolower(pathinfo($_FILES['damage_photo']['name'], PATHINFO_EXTENSION));
        $file_name = uniqid('damage_') . '.' . $file_ext;
        $target_file = $upload_dir . $file_name;
        
        if (move_uploaded_file($_FILES['damage_photo']['tmp_name'], $target_file)) {
            $damage_photo = 'uploads/damages/' . $file_name;
        }
    }
    
    $query = "INSERT INTO booking_damages (order_id, item_name, quantity, price, total, damage_photo, notes) 
              VALUES ('$order_id', '$item_name', '$quantity', '$price', '$total', " . ($damage_photo ? "'$damage_photo'" : "NULL") . ", '$notes')";
    
    if (mysqli_query($conn, $query)) {
        // Reset damage payment status to pending if new damage is added
        mysqli_query($conn, "UPDATE bookings SET damage_payment_status = 'pending' WHERE id = '$order_id'");
        
        header("Location: order-details.php?id=$order_id$capacity_return_query&success=damage_added");
        exit();
    } else {
        appLogError('Caterer order-details damage insert failed', mysqli_error($conn));
        header("Location: order-details.php?id=$order_id$capacity_return_query&error=damage_add_failed");
        exit();
    }
}

if (isset($_POST['delete_damage'])) {
    $damage_id = intval($_POST['damage_id']);
    mysqli_query($conn, "DELETE FROM booking_damages WHERE id = '$damage_id' AND order_id = '$order_id'");
    header("Location: order-details.php?id=$order_id$capacity_return_query&success=damage_deleted");
    exit();
}

if (isset($_POST['mark_damage_paid'])) {
    mysqli_query($conn, "UPDATE bookings SET damage_payment_status = 'paid' WHERE id = '$order_id'");
    mysqli_query($conn, "UPDATE booking_damages SET damage_status = 'paid' WHERE order_id = '$order_id'");
    header("Location: order-details.php?id=$order_id$capacity_return_query&success=damages_paid");
    exit();
}

// Fetch Damages
$damages_query = "SELECT * FROM booking_damages WHERE order_id = '$order_id'";
$damages_result = mysqli_query($conn, $damages_query);
$damages = [];
$total_damage_fee = 0;
if ($damages_result) {
    while ($row = mysqli_fetch_assoc($damages_result)) {
        $damages[] = $row;
        $total_damage_fee += $row['total'];
    }
}

// Handle New Payment Recording
if (isset($_POST['record_payment'])) {
    $amount = floatval($_POST['amount']);
    $type = $_POST['payment_type'];
    $method = $_POST['payment_method'];
    $raw_notes = trim($_POST['notes']);
    $reference_note = formatOrderReference($order_id, $order['order_number']);
    if ($raw_notes !== '') {
        $raw_notes .= ' | ' . $reference_note;
    } else {
        $raw_notes = $reference_note;
    }
    $notes = mysqli_real_escape_string($conn, $raw_notes);
    
    if ($amount > 0) {
        $ins = "INSERT INTO payments (order_id, amount, payment_type, payment_method, status, notes) 
                VALUES ('$order_id', '$amount', '$type', '$method', 'verified', '$notes')";
        if (mysqli_query($conn, $ins)) {
            if ($type === 'refund') {
                mysqli_query($conn, "UPDATE bookings SET order_status = 'cancelled' WHERE id = '$order_id'");
                $log_action = "Refund Issued";
                $log_details = "Refund of ₱" . number_format($amount, 2) . " processed. Order cancelled.";
                mysqli_query($conn, "INSERT INTO booking_logs (order_id, user_id, action, details, created_at) VALUES ('$order_id', '$user_id', '$log_action', '$log_details', NOW())");
            }
            // Update financials will happen on reload
            header("Location: order-details.php?id=$order_id$capacity_return_query&success=payment_recorded");
            exit();
        }
    }
}

// Price a special request and include its charge in the financial totals.
if (isset($_POST['update_custom_price'])) {
    if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        http_response_code(403);
        exit('Invalid request token.');
    }
    $request_price = filter_var($_POST['custom_price'] ?? '', FILTER_VALIDATE_FLOAT);
    if ($customRequest !== '' && $request_price !== false && is_finite($request_price) && $request_price >= 0) {
        $request_price = round($request_price, 2);
        $old_price = 0;
        if (preg_match('/\(\+₱([\d,]+(?:\.\d+)?)\)\s*$/u', $customRequest, $price_match)) {
            $old_price = (float) str_replace(',', '', $price_match[1]);
        }
        $request_name = trim(preg_replace('/\s*\((?:Price TBD|\+₱[\d,.]+)\)\s*$/iu', '', $customRequest));
        $priced_request = $request_name . ' (+₱' . number_format($request_price, 2) . ')';
        $menu_parts = explode('|', (string) $order['additional_menu']);
        foreach ($menu_parts as &$menu_part) {
            foreach ($customLabels as $label) {
                if (stripos(trim($menu_part), $label) === 0) {
                    $menu_part = $label . ' ' . $priced_request;
                    break;
                }
            }
        }
        unset($menu_part);
        $updated_menu = mysqli_real_escape_string($conn, implode(' | ', array_map('trim', $menu_parts)));
        if (recalculateAndSaveOrderPricing($conn, $order, $order_id, [
            'addons_total' => max(0, (float) $order['addons_total'] - $old_price + $request_price),
            'additional_menu' => $updated_menu
        ]) !== null) {
            header("Location: order-details.php?id=$order_id$capacity_return_query&success=custom_price_updated");
            exit();
        }
    }
}

// Handle Add Add-on
if (isset($_POST['add_addon'])) {
    $addon_name = mysqli_real_escape_string($conn, $_POST['addon_name']);
    $addon_price = floatval($_POST['addon_price']);
    
    if ($addon_price > 0 && !empty($addon_name)) {
        // Logic: new_addons = current_addons + price
        // We also need to append the name to the additional_menu string for display
        
        $current_addons_total = floatval($order['addons_total']);
        $new_addons_total = $current_addons_total + $addon_price;
        
        // Update menu text
        $current_menu = $order['additional_menu'];
        $new_item_str = "$addon_name (+₱" . number_format($addon_price, 2) . ")";
        
        if (strpos($current_menu, 'Added Items:') !== false) {
            // Append to existing Added Items section
            $new_menu = preg_replace('/(Added Items:)([^|]*)/', '$1$2, ' . $new_item_str, $current_menu);
        } else {
            // Create new Added Items section
            if (empty($current_menu)) {
                $new_menu = "Added Items: " . $new_item_str;
            } else {
                $new_menu = $current_menu . " | Added Items: " . $new_item_str;
            }
        }
        $new_menu = mysqli_real_escape_string($conn, $new_menu);
        
        if (recalculateAndSaveOrderPricing($conn, $order, $order_id, [
            'addons_total' => $new_addons_total,
            'additional_menu' => $new_menu
        ]) !== null) {
            $log_action = "Add-on Added";
            $log_details = "Added $addon_name (₱" . number_format($addon_price, 2) . ")";
            mysqli_query($conn, "INSERT INTO booking_logs (order_id, user_id, action, details, created_at) VALUES ('$order_id', '$user_id', '$log_action', '$log_details', NOW())");

            header("Location: order-details.php?id=$order_id$capacity_return_query&success=addon_added");
            exit();
        }
    }
}

// Handle Remove Add-on
if (isset($_POST['remove_addon'])) {
    $addon_name = mysqli_real_escape_string($conn, $_POST['addon_name']);
    $addon_price = floatval($_POST['addon_price']);
    
    // We need to parse the menu string, remove the item, and reconstruct the string
    $current_menu = $order['additional_menu'];
    $current_addons_total = floatval($order['addons_total']);
    
    // Parse current menu using robust splitting
    $parts = preg_split('/\s*\|\s*/', $current_menu, -1, PREG_SPLIT_NO_EMPTY);
    
    $parsed_setName = '';
    $parsed_addons = [];
    $parsed_swaps = [];
    $parsed_custom = '';
    
    if (!empty($parts)) {
        $firstPart = trim($parts[0]);
        if (strpos($firstPart, 'Added Items:') !== 0 && strpos($firstPart, 'Menu Swaps:') !== 0 && strpos($firstPart, 'Custom Add-on Request:') !== 0) {
            $parsed_setName = $firstPart;
        }
        
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === $parsed_setName) continue;
            
            if (strpos($part, 'Added Items:') !== false) {
                $itemsStr = str_replace('Added Items:', '', $part);
                // Robust parsing for deletion
                $parsed_addons = [];
                if (preg_match_all('/(?:^|,\s*)(.+?)\s*(\(\+?\s*[\d,.]+\))/', $itemsStr, $matches, PREG_SET_ORDER)) {
                    foreach ($matches as $m) {
                        $parsed_addons[] = trim($m[1]) . " " . $m[2];
                    }
                }
            } elseif (strpos($part, 'Menu Swaps:') !== false) {
                $parsed_swaps[] = $part; // Keep the whole string "Menu Swaps: ..."
            } elseif (strpos($part, 'Custom Add-on Request:') !== false) {
                $parsed_custom = $part; // Keep the whole string "Custom Add-on Request: ..."
            }
        }
    }
    
    // Find and remove the addon
    $found = false;
    $new_addons_list = [];
    
    // Normalize target for comparison
    $target_name = trim($addon_name);
    $target_price = round($addon_price, 2);
    
    foreach ($parsed_addons as $item) {
        $item = trim($item);
        
        // Extract name and price from the item string
        // Format is usually "Name (+?12.00)"
        $item_name = '';
        $item_price = 0.0;
        
        if (preg_match('/^(.*?)\s*\(\+?\s*([\d,.]+)\)$/', $item, $matches)) {
            $item_name = trim($matches[1]);
            $item_price = round(floatval(str_replace(',', '', $matches[2])), 2);
            
            // Check for match
            if (!$found && $item_name === $target_name && abs($item_price - $target_price) < 0.01) {
                $found = true; // Remove only first occurrence
                continue; 
            }
        } elseif (!$found) {
            // Fallback for items that might not match the regex (e.g. legacy format without price?)
            // Or if price is 0 and not shown?
            // If strict match fails, try relaxed match on name only if price is same
            // (But we really should rely on the regex)
            
            // Try to match reconstructed string as fallback
            $reconstructed = $addon_name . " (+₱" . number_format($addon_price, 2) . ")";
            if ($item === $reconstructed) {
                $found = true;
                continue;
            }
        }
        
        if (!empty($item)) {
            $new_addons_list[] = $item;
        }
    }
    
    if ($found) {
        // Reconstruct the menu string
        $new_menu_parts = [];
        if (!empty($parsed_setName)) {
            $new_menu_parts[] = $parsed_setName;
        }
        
        if (!empty($new_addons_list)) {
            $new_menu_parts[] = "Added Items: " . implode(', ', $new_addons_list);
        }
        
        // Add back swaps (which we kept as full strings, or array of strings?)
        // In remove_addon, $parsed_swaps stores raw "Menu Swaps: ..." string if it wasn't touched.
        // Wait, logic above: } elseif (strpos($part, 'Menu Swaps:') !== false) { $parsed_swaps[] = $part; }
        // So it is the FULL string "Menu Swaps: A, B".
        foreach ($parsed_swaps as $swap_part) {
            $new_menu_parts[] = $swap_part;
        }
        
        if (!empty($parsed_custom)) {
            $new_menu_parts[] = $parsed_custom;
        }
        
        $new_menu = implode(' | ', $new_menu_parts);
        $new_menu = mysqli_real_escape_string($conn, $new_menu);
        
        // Update totals
        $new_addons_total = max(0, $current_addons_total - $addon_price);
        
        if (recalculateAndSaveOrderPricing($conn, $order, $order_id, [
            'addons_total' => $new_addons_total,
            'additional_menu' => $new_menu
        ]) !== null) {
            $log_action = "Add-on Removed";
            $log_details = "Removed $addon_name (-₱" . number_format($addon_price, 2) . ")";
            mysqli_query($conn, "INSERT INTO booking_logs (order_id, user_id, action, details, created_at) VALUES ('$order_id', '$user_id', '$log_action', '$log_details', NOW())");

            header("Location: order-details.php?id=$order_id$capacity_return_query&success=addon_removed");
            exit();
        }
    }
}

// Handle Remove Swap
if (isset($_POST['remove_swap'])) {
    $swap_name = mysqli_real_escape_string($conn, $_POST['swap_name']);
    $swap_price = floatval($_POST['swap_price']);
    
    $current_menu = $order['additional_menu'];
    
    // Parse current menu
    $parts = preg_split('/\s*\|\s*/', $current_menu, -1, PREG_SPLIT_NO_EMPTY);
    
    $parsed_setName = '';
    $parsed_addons_str = ''; // Keep full string
    $parsed_swaps = [];
    $parsed_custom = '';
    
    if (!empty($parts)) {
        $firstPart = trim($parts[0]);
        if (strpos($firstPart, 'Added Items:') !== 0 && strpos($firstPart, 'Menu Swaps:') !== 0 && strpos($firstPart, 'Custom Add-on Request:') !== 0) {
            $parsed_setName = $firstPart;
        }
        
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === $parsed_setName) continue;
            
            if (strpos($part, 'Added Items:') !== false) {
                $parsed_addons_str = $part;
            } elseif (strpos($part, 'Menu Swaps:') !== false) {
                $itemsStr = str_replace('Menu Swaps:', '', $part);
                // Parse swaps into array
                if (preg_match_all('/(?:^|,\s*)(.+?)\s*(\(\+?\s*[\d,.]+\))/', $itemsStr, $matches, PREG_SET_ORDER)) {
                    foreach ($matches as $m) {
                        $parsed_swaps[] = trim($m[1]) . " " . $m[2];
                    }
                } else {
                    $parsed_swaps = array_map('trim', explode(',', $itemsStr));
                }
            } elseif (strpos($part, 'Custom Add-on Request:') !== false) {
                $parsed_custom = $part;
            }
        }
    }
    
    // Find and remove swap
    $found = false;
    $new_swaps_list = [];
    
    // Normalize target
    $target_name = trim($swap_name);
    $target_price = round($swap_price, 2);
    
    foreach ($parsed_swaps as $item) {
        $item = trim($item);
        
        $item_name = '';
        $item_price = 0.0;
        
        if (preg_match('/^(.*?)\s*\(\+?\s*([\d,.]+)\)$/', $item, $matches)) {
            $item_name = trim($matches[1]);
            $item_price = round(floatval(str_replace(',', '', $matches[2])), 2);
            
            if (!$found && $item_name === $target_name && abs($item_price - $target_price) < 0.01) {
                $found = true;
                continue;
            }
        } elseif (!$found) {
            // Fallback
            $reconstructed = $swap_name . " (+₱" . number_format($swap_price, 2) . ")";
            if ($item === $reconstructed) {
                $found = true;
                continue;
            }
        }
        
        if (!empty($item)) {
            $new_swaps_list[] = $item;
        }
    }
    
    if ($found) {
        $new_menu_parts = [];
        if (!empty($parsed_setName)) $new_menu_parts[] = $parsed_setName;
        if (!empty($parsed_addons_str)) $new_menu_parts[] = $parsed_addons_str;
        
        if (!empty($new_swaps_list)) {
            $new_menu_parts[] = "Menu Swaps: " . implode(', ', $new_swaps_list);
        }
        
        if (!empty($parsed_custom)) $new_menu_parts[] = $parsed_custom;
        
        $new_menu = implode(' | ', $new_menu_parts);
        $new_menu = mysqli_real_escape_string($conn, $new_menu);
        
        if (recalculateAndSaveOrderPricing($conn, $order, $order_id, ['additional_menu' => $new_menu]) !== null) {
            $log_action = "Swap Removed";
            $log_details = "Removed $swap_name (-₱" . number_format($swap_price, 2) . ")";
            mysqli_query($conn, "INSERT INTO booking_logs (order_id, user_id, action, details, created_at) VALUES ('$order_id', '$user_id', '$log_action', '$log_details', NOW())");

            header("Location: order-details.php?id=$order_id$capacity_return_query&success=swap_removed");
            exit();
        }
    }
}

// Handle Update Pax
if (isset($_POST['update_pax'])) {
    $new_pax = intval($_POST['new_pax']);

    if ($new_pax > 0) {
        $old_pax = intval($order['number_of_pax']);

        $pricing = recalculateAndSaveOrderPricing($conn, $order, $order_id, ['number_of_pax' => $new_pax]);
        if ($pricing !== null) {
            $log_action = "Pax Updated";
            $log_details = "Pax changed from $old_pax to $new_pax. Booking total recalculated to ₱" . number_format($pricing['total_amount'] ?? 0, 2);
            mysqli_query($conn, "INSERT INTO booking_logs (order_id, user_id, action, details, created_at) VALUES ('$order_id', '$user_id', '$log_action', '$log_details', NOW())");

            header("Location: order-details.php?id=$order_id$capacity_return_query&success=pax_updated");
            exit();
        }
    }
}

// Handle Update Date & Time
if (isset($_POST['update_date_time'])) {
    $new_date = mysqli_real_escape_string($conn, $_POST['new_date']);
    $new_time = mysqli_real_escape_string($conn, $_POST['new_time']);

    if (!empty($new_date) && !empty($new_time)) {
        $old_date = $order['order_date'];
        $old_time = $order['order_time'];

        // Update SQL
        $update_sql = "UPDATE bookings SET order_date = '$new_date', order_time = '$new_time' WHERE id = '$order_id'";
        
        if (mysqli_query($conn, $update_sql)) {
             // Log it
            $log_action = "Date/Time Updated";
            $log_details = "Changed from $old_date $old_time to $new_date $new_time";
            mysqli_query($conn, "INSERT INTO booking_logs (order_id, user_id, action, details, created_at) VALUES ('$order_id', '$user_id', '$log_action', '$log_details', NOW())");

            header("Location: order-details.php?id=$order_id$capacity_return_query&success=date_updated");
            exit();
        }
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<style>
    .menu-breakdown-trigger { position: relative; }
    .menu-attention-badge { position: absolute; top: -5px; right: -5px; min-width: 18px; height: 18px; padding: 0 4px; box-sizing: border-box; border-radius: 999px; background: #dc2626; color: #fff; font-size: 11px; font-weight: 700; display: grid; place-items: center; border: 2px solid var(--bg-card, #fff); }
    @media print { .menu-attention-badge, .menu-review-action { display: none !important; } }
    .internal-stock-preparation { margin-bottom: 24px; }
    .internal-stock-preparation h3 { margin: 0 0 16px; font-size: 16px; }
    .stock-preparation-row {
        display: grid;
        grid-template-columns: minmax(0, 1fr) minmax(200px, 260px);
        align-items: center;
        gap: 16px 24px;
        padding: 16px 0;
        border-bottom: 1px solid var(--border-color, #ccc);
    }
    .stock-preparation-details { min-width: 0; overflow-wrap: anywhere; }
    .stock-preparation-stats { display: flex; flex-wrap: wrap; gap: 8px 20px; margin: 10px 0 0; }
    .stock-preparation-stats div { display: grid; gap: 3px; }
    .stock-preparation-stats dt { font-size: 12px; color: var(--text-muted); }
    .stock-preparation-stats dd { margin: 0; font-size: 15px; font-weight: 600; }
    .stock-preparation-form { display: flex; align-items: flex-end; gap: 8px; min-width: 0; }
    .stock-preparation-form label { flex: 1; min-width: 0; font-size: 12px; }
    .stock-preparation-form input { width: 100%; min-width: 0; box-sizing: border-box; margin-top: 6px; min-height: 44px; }
    .stock-preparation-form .btn { flex: 0 0 auto; min-height: 44px; white-space: nowrap; }
    @media (max-width: 600px) {
        .stock-preparation-row { grid-template-columns: minmax(0, 1fr); gap: 12px; }
        .stock-preparation-form { width: 100%; }
        .stock-preparation-stats { justify-content: space-between; gap: 8px 12px; }
    }
    :root {
        --primary-green: #d4af37;
        --primary-dark: #8c7321;
        --accent-gold: #d4af37;
        --bg-dark: #0a0a0a;
        --card-bg: #1e1e1e;
        --card-hover: #2a2a2a;
        --text-main: #f9fafb;
        --text-secondary: #a0a0a0;
        --border-color: #333333;
        --input-bg: #141414;
        --success-bg: rgba(76, 175, 80, 0.12);
        --success-text: #4caf50;
        --warning-bg: rgba(212, 175, 55, 0.12);
        --warning-text: #d4af37;
        --danger-bg: rgba(244, 67, 54, 0.12);
        --danger-text: #f44336;
    }

    body {
        background-color: var(--bg-dark);
        color: var(--text-main);
        font-family: 'Inter', system-ui, -apple-system, sans-serif;
    }

    .dashboard-container {
        max-width: 1400px;
        margin: 0 auto;
        padding: 32px;
        display: grid;
        gap: 32px;
    }

    /* Header Section */
    .page-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding-bottom: 24px;
        border-bottom: 1px solid var(--border-color);
    }

    .page-title h1 {
        font-size: 28px;
        font-weight: 700;
        margin: 0;
        color: var(--text-main);
        letter-spacing: -0.5px;
    }

    .page-title span {
        font-size: 14px;
        color: var(--text-secondary);
        font-weight: 500;
        display: block;
        margin-bottom: 4px;
    }

    .header-actions {
        display: flex;
        gap: 12px;
    }

    .btn {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 10px 16px;
        border-radius: 8px;
        font-size: 14px;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.2s;
        text-decoration: none;
    }

    .btn-outline {
        background: transparent;
        border: 1px solid var(--border-color);
        color: var(--text-secondary);
    }

    .btn-outline:hover {
        border-color: var(--text-main);
        color: var(--text-main);
        background: var(--card-bg);
    }

    .btn-primary {
        background: var(--primary-green);
        color: white;
        border: 1px solid var(--primary-green);
    }

    .btn-primary:hover {
        background: var(--primary-dark);
        border-color: var(--primary-dark);
    }

    /* Grid Layout */
    .grid-layout {
        display: grid;
        grid-template-columns: 2fr 1fr;
        gap: 32px;
    }

    @media (max-width: 1024px) {
        .grid-layout {
            grid-template-columns: 1fr;
        }
    }

    /* Cards */
    .card {
        background: var(--card-bg);
        border: 1px solid var(--border-color);
        border-radius: 16px;
        padding: 24px;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
    }

    .card-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 24px;
        padding-bottom: 16px;
        border-bottom: 1px solid var(--border-color);
    }

    .card-title {
        font-size: 16px;
        font-weight: 600;
        color: var(--text-main);
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .card-title i {
        color: var(--primary-green);
    }

    /* Customer Info Grid */
    .info-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 24px;
    }

    .info-group {
        display: flex;
        flex-direction: column;
        gap: 4px;
    }

    .info-label {
        font-size: 11px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        color: var(--text-secondary);
        font-weight: 600;
        margin-bottom: 4px;
    }

    .info-value {
        font-size: 15px;
        color: var(--text-main);
        font-weight: 500;
    }
    
    .info-value.highlight {
        color: var(--accent-gold);
    }

    .info-icon {
        width: 20px;
        color: var(--text-secondary);
        margin-right: 8px;
    }

    /* Payment Section */
    .payment-summary {
        text-align: center;
        margin-bottom: 24px;
        padding: 20px;
        background: var(--input-bg);
        border-radius: 12px;
        border: 1px solid var(--border-color);
    }

    .total-amount {
        font-size: 36px;
        font-weight: 700;
        color: var(--text-main);
        line-height: 1.2;
        margin-bottom: 4px;
    }
    
    .total-label {
        font-size: 12px;
        color: var(--text-secondary);
        text-transform: uppercase;
        letter-spacing: 1px;
    }

    .payment-stats {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 12px;
        margin-top: 16px;
    }

    .stat-box {
        background: var(--card-bg);
        padding: 12px;
        border-radius: 8px;
        border: 1px solid var(--border-color);
    }

    .stat-label {
        font-size: 11px;
        color: var(--text-secondary);
        margin-bottom: 4px;
    }

    .stat-value {
        font-size: 16px;
        font-weight: 600;
    }

    .text-success { color: var(--success-text); }
    .text-danger { color: var(--danger-text); }

    .payment-form {
        margin-top: 24px;
    }

    .form-row {
        display: flex;
        gap: 8px;
        margin-bottom: 12px;
    }

    .input-group {
        position: relative;
        flex: 1;
    }

    .input-prefix {
        position: absolute;
        left: 12px;
        top: 50%;
        transform: translateY(-50%);
        color: var(--text-secondary);
        font-size: 14px;
    }

    .form-input {
        width: 100%;
        padding: 12px 12px 12px 28px;
        background: var(--input-bg);
        border: 1px solid var(--border-color);
        color: var(--text-main);
        border-radius: 8px;
        font-size: 14px;
        transition: border-color 0.2s;
    }

    .form-input:focus {
        outline: none;
        border-color: var(--primary-green);
    }

    .btn-block {
        width: 100%;
        justify-content: center;
    }

    .quick-actions {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 8px;
        margin-top: 12px;
    }

    .proof-container {
        margin-top: 24px;
        position: relative;
        border-radius: 12px;
        overflow: hidden;
        border: 1px solid var(--border-color);
        aspect-ratio: 16/9;
        background: var(--input-bg);
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
    }

    .proof-img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        transition: transform 0.3s;
    }

    .proof-img:hover {
        transform: scale(1.05);
    }

    .proof-overlay {
        position: absolute;
        bottom: 0;
        left: 0;
        right: 0;
        padding: 12px;
        background: linear-gradient(to top, rgba(0,0,0,0.8), transparent);
        color: white;
        font-size: 12px;
        display: flex;
        align-items: center;
        gap: 6px;
    }

    /* Menu Section */
    .menu-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
        gap: 40px;
    }

    .menu-list {
        list-style: none;
        padding: 0;
        margin: 0;
    }

    .menu-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 14px 0;
        border-bottom: 1px solid var(--border-color);
        font-size: 14px;
    }

    .menu-item:last-child {
        border-bottom: none;
    }

    .menu-item-content {
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .item-check {
        color: var(--primary-green);
        font-size: 12px;
        background: var(--success-bg);
        width: 20px;
        height: 20px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .badge {
        padding: 4px 10px;
        border-radius: 6px;
        font-size: 11px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    
    .badge-swap { background: var(--warning-bg); color: var(--warning-text); }
    .badge-addon { background: rgba(59, 130, 246, 0.15); color: #60a5fa; }

    .price-tag {
        font-family: 'JetBrains Mono', monospace;
        color: var(--text-secondary);
        font-size: 13px;
    }

    .special-request-box {
        margin-top: 16px;
        padding: 20px;
        background: var(--warning-bg);
        border: 1px dashed var(--warning-text);
        border-radius: 12px;
        color: var(--warning-text);
        font-size: 14px;
        line-height: 1.6;
    }

    /* Modal */
    .modal {
        display: none;
        position: fixed;
        z-index: 9999;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        background-color: rgba(0,0,0,0.9);
        backdrop-filter: blur(8px);
        justify-content: center;
        align-items: center;
        padding: 40px;
    }

    .modal-content {
        max-width: 600px;
        width: 90%;
        max-height: 90vh;
        border-radius: 12px;
        box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
    }

    .close-modal {
        position: absolute;
        top: 24px;
        right: 32px;
        color: white;
        font-size: 40px;
        cursor: pointer;
        transition: opacity 0.2s;
    }

    .close-modal:hover { opacity: 0.7; }
    
    .status-pill {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 6px 16px;
        border-radius: 999px;
        font-size: 12px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    
    .status-pending { background: var(--warning-bg); color: var(--warning-text); }
    .status-confirmed { background: var(--success-bg); color: var(--success-text); }
    .status-cancelled { background: var(--danger-bg); color: var(--danger-text); }
    
    .alert-success {
        background: var(--success-bg);
        color: var(--success-text);
        padding: 16px;
        border-radius: 12px;
        margin-bottom: 24px;
        display: flex;
        align-items: center;
        gap: 12px;
        border: 1px solid rgba(16, 185, 129, 0.2);
    }
</style>

<link rel="stylesheet" href="../assets/css/caterer-order-details.css?v=<?php echo filemtime(__DIR__ . '/../assets/css/caterer-order-details.css'); ?>">
<div class="dashboard-container order-details-page">
    
    <!-- Success Message -->
    <?php if (isset($_GET['success']) && !in_array($_GET['success'], ['addon_added', 'custom_price_updated'], true)): ?>
        <div class="alert-success" id="orderSuccessMessage" role="status">
            <i class="fas fa-check-circle"></i>
            <span>
                <?php
                if ($_GET['success'] === 'reported_non_payment') echo 'Report request sent to Portal Admin successfully. Order is now cancelled.';
                elseif ($_GET['success'] === 'addon_added') echo 'Add-on added successfully!';
                elseif ($_GET['success'] === 'addon_removed') echo 'Add-on removed successfully!';
                elseif ($_GET['success'] === 'damage_added') echo 'Damage reported successfully!';
                else echo 'Action completed successfully!';
                ?>
            </span>
        </div>
    <?php endif; ?>

    <script>
        const orderSuccessMessage = document.getElementById('orderSuccessMessage');
        if (orderSuccessMessage) {
            window.setTimeout(() => {
                orderSuccessMessage.remove();
                const cleanUrl = new URL(window.location.href);
                cleanUrl.searchParams.delete('success');
                window.history.replaceState(window.history.state, '', cleanUrl);
            }, 3000);
        }
    </script>



    <?php if (isset($_GET['reschedule'])): ?>
        <?php
            $reschedule_notice = '';
            $reschedule_notice_style = 'background: rgba(212, 175, 55, 0.08); border: 1px solid rgba(212, 175, 55, 0.25); color: var(--accent-gold);';
            switch ($_GET['reschedule']) {
                case 'approved':
                    $reschedule_notice = 'The reschedule request was approved and the booking schedule was updated.';
                    $reschedule_notice_style = 'background: rgba(46, 204, 113, 0.08); border: 1px solid rgba(46, 204, 113, 0.25); color: #8ff0b0;';
                    break;
                case 'rejected':
                    $reschedule_notice = 'The reschedule request was rejected and the original schedule stays active.';
                    $reschedule_notice_style = 'background: rgba(231, 76, 60, 0.08); border: 1px solid rgba(231, 76, 60, 0.25); color: #ff9b9b;';
                    break;
                case 'reject_reason_required':
                    $reschedule_notice = 'Please provide a reason before rejecting the reschedule request.';
                    $reschedule_notice_style = 'background: rgba(231, 76, 60, 0.08); border: 1px solid rgba(231, 76, 60, 0.25); color: #ff9b9b;';
                    break;
                case 'no_pending_request':
                case 'invalid_token':
                case 'failed':
                    $reschedule_notice = 'Unable to process the reschedule request right now. Please refresh and try again.';
                    $reschedule_notice_style = 'background: rgba(231, 76, 60, 0.08); border: 1px solid rgba(231, 76, 60, 0.25); color: #ff9b9b;';
                    break;
            }
        ?>
        <?php if ($reschedule_notice !== ''): ?>
            <dialog id="rescheduleOutcome" aria-labelledby="rescheduleOutcomeTitle">
                <h3 id="rescheduleOutcomeTitle"><?php echo ($_GET['reschedule'] ?? '') === 'approved' ? 'Reschedule approved' : 'Reschedule request'; ?></h3>
                <p><?php echo htmlspecialchars($reschedule_notice); ?></p>
                <button type="button" class="btn btn-primary" onclick="document.getElementById('rescheduleOutcome').close()">OK</button>
            </dialog>
            <style>
            #rescheduleOutcome{position:fixed;inset:0;margin:auto;width:min(380px,calc(100vw - 32px));height:fit-content;max-height:calc(100dvh - 32px);box-sizing:border-box;overflow:auto;padding:24px;border:1px solid #96a38e;border-radius:12px;background:#d4dbcd;color:#20372b;text-align:center;}
            #rescheduleOutcome::backdrop{background:rgb(12 32 25 / 55%);backdrop-filter:blur(4px);}
            #rescheduleOutcome h3{margin:0 0 12px;font-size:18px;line-height:1.4;}
            #rescheduleOutcome p{margin:0 0 20px;font-size:14px;line-height:1.6;overflow-wrap:anywhere;}
            #rescheduleOutcome .btn{min-width:100px;min-height:44px;justify-content:center;}
            </style>
            <script>
            window.addEventListener('load',function(){
                document.getElementById('rescheduleOutcome').showModal();
                const url=new URL(location.href);url.searchParams.delete('reschedule');history.replaceState(history.state,'',url);
            });
            </script>
        <?php endif; ?>
    <?php endif; ?>

    <!-- Main Content -->
    <div class="grid-layout">
        
        <div id="customerInfoModal" class="order-section-modal" hidden>
            <!-- Customer & Order Info -->
            <div class="card order-section-content" role="dialog" aria-modal="true" aria-label="Customer Information" tabindex="-1">
                <button type="button" class="section-modal-close" aria-label="Close Customer Information" onclick="closeOrderSection()"><i class="fas fa-times" aria-hidden="true"></i></button>
                <div class="card-header">
                    <div class="card-title">
                        <i class="fas fa-user-circle"></i>
                        Customer Information
                    </div>
                    <?php 
                        $statusClass = 'status-pending';
                        if (strtolower($order['order_status']) == 'confirmed') $statusClass = 'status-confirmed';
                        if (strtolower($order['order_status']) == 'cancelled') $statusClass = 'status-cancelled';
                    ?>
                    <div class="status-pill <?php echo $statusClass; ?>">
                        <i class="fas fa-circle" style="font-size: 8px;"></i>
                        <?php echo htmlspecialchars(ucfirst(strtolower($order['order_status']))); ?>
                    </div>
                </div>
                <div class="order-section-body">

                <div class="info-grid">
                    <div class="info-group">
                        <span class="info-label">Full Name</span>
                        <div class="info-value"><?php echo htmlspecialchars($order['first_name'] . ' ' . $order['last_name']); ?></div>
                    </div>
                    <div class="info-group">
                        <span class="info-label">Contact Number</span>
                        <div class="info-value">
                            <i class="fas fa-phone info-icon"></i><?php echo htmlspecialchars($order['phone_number']); ?>
                        </div>
                    </div>
                    <div class="info-group">
                        <span class="info-label">Email Address</span>
                        <div class="info-value">
                            <i class="fas fa-envelope info-icon"></i><?php echo htmlspecialchars($order['email']); ?>
                        </div>
                    </div>
                    <div class="info-group">
                        <span class="info-label">Event Address</span>
                        <div class="info-value">
                            <i class="fas fa-map-marker-alt info-icon"></i><?php echo htmlspecialchars($order['address']); ?>
                        </div>
                    </div>
                    <div class="info-group">
                        <span class="info-label">Event / Service Type</span>
                        <div class="info-value">
                            <i class="fas fa-glass-cheers info-icon"></i>
                            <?php echo htmlspecialchars($order['product_name']); ?>
                            <?php if (!empty($order['event_type'])): ?>
                                - <?php echo htmlspecialchars($order['event_type']); ?>
                            <?php endif; ?>
                            <?php if (!empty($order['category']) && $order['category'] != 'catering_service'): ?>
                                <span style="font-size: 12px; color: var(--accent-gold); margin-left: 4px;">
                                    • <?php echo ucwords(str_replace('_', ' ', $order['category'])); ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="info-group">
                        <span class="info-label">Event Date & Time</span>
                        <div class="info-value" style="display: flex; align-items: center; gap: 8px;">
                            <div>
                                <?php echo date('F d, Y', strtotime($order['order_date'])); ?> at <?php echo date('h:i A', strtotime($order['order_time'])); ?>
                            </div>
                            <button onclick="document.getElementById('updateDateModal').style.display='flex'" style="background: none; border: none; color: var(--accent-gold); cursor: pointer; padding: 0;">
                                <i class="fas fa-edit"></i>
                            </button>
                        </div>
                    </div>
                    <div class="info-group">
                        <span class="info-label">Date Booked</span>
                        <div class="info-value">
                            <i class="fas fa-calendar-check info-icon"></i>
                            <?php echo !empty($order['created_at']) ? date('F d, Y h:i A', strtotime($order['created_at'])) : 'N/A'; ?>
                        </div>
                    </div>
                    <div class="info-group">
                        <span class="info-label">Guest Count</span>
                        <div class="info-value" style="display: flex; align-items: center; gap: 8px;">
                            <?php echo intval($order['number_of_pax']); ?> Pax
                            <button onclick="document.getElementById('updatePaxModal').style.display='flex'" style="background: none; border: none; color: var(--accent-gold); cursor: pointer; padding: 0;">
                                <i class="fas fa-edit"></i>
                            </button>
                        </div>
                    </div>
                </div>
                            </div>
            </div>
        </div>
        <div id="rescheduleRequestModal" class="order-section-modal" hidden>
            <div class="card order-section-content" role="dialog" aria-modal="true" aria-label="Reschedule Request" tabindex="-1">
                <button type="button" class="section-modal-close" aria-label="Close Reschedule Request" onclick="closeOrderSection()"><i class="fas fa-times" aria-hidden="true"></i></button>
                <div class="card-header">
                    <div class="card-title">
                        <i class="fas fa-calendar-alt"></i>
                        Reschedule Request
                    </div>
                    <?php
                        $reschedule_badge_class = 'status-pending';
                        if ($reschedule_status === 'approved') $reschedule_badge_class = 'status-confirmed';
                        if ($reschedule_status === 'rejected') $reschedule_badge_class = 'status-cancelled';
                    ?>
                    <div class="status-pill <?php echo $reschedule_badge_class; ?>">
                        <?php echo htmlspecialchars(ucfirst(strtolower($reschedule_status !== '' ? $reschedule_status : 'none'))); ?>
                    </div>
                </div>
                <div class="order-section-body">

                <div style="display: grid; gap: 14px;">
                    <div style="padding: 14px; border-radius: 10px; border: 1px solid var(--border-color); background: rgba(255,255,255,0.02);">
                        <div style="font-size: 13px; color: var(--text-secondary); line-height: 1.7;">
                            Current Schedule:
                            <strong style="color: var(--text-main);"><?php echo date('F d, Y', strtotime($order['order_date'])); ?> at <?php echo date('h:i A', strtotime($order['order_time'])); ?></strong>
                        </div>
                        <?php if ($reschedule_request): ?>
                            <div style="font-size: 13px; color: var(--text-secondary); line-height: 1.7; margin-top: 6px;">
                                Requested Schedule:
                                <strong style="color: var(--text-main);"><?php echo date('F d, Y', strtotime($reschedule_request['requested_date'])); ?> at <?php echo date('h:i A', strtotime($reschedule_request['requested_time'])); ?></strong>
                            </div>
                            <div style="margin-top: 8px; font-size: 13px; color: var(--text-secondary);">
                                Reason: <span style="color: var(--text-main);"><?php echo htmlspecialchars($reschedule_request['reason']); ?></span>
                                <?php if (!empty($reschedule_request['requested_at'])): ?>
                                    <time datetime="<?php echo htmlspecialchars(date('c', strtotime($reschedule_request['requested_at']))); ?>" style="display:block;flex-basis:100%;margin-top:4px;font-size:12px;color:var(--text-secondary);line-height:1.5;">
                                        <?php echo date('M d, Y h:i A', strtotime($reschedule_request['requested_at'])); ?>
                                    </time>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($reschedule_request['response'])): ?>
                                <div style="margin-top: 8px; font-size: 13px; color: var(--text-secondary);">
                                    Response: <span style="color: var(--text-main);"><?php echo htmlspecialchars($reschedule_request['response']); ?></span>
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div style="margin-top: 8px; font-size: 13px; color: var(--text-secondary);">
                                No reschedule requests.
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php if ($reschedule_status === 'pending' && $reschedule_request): ?>
                        <div class="reschedule-review-actions">
                            <form method="POST" onsubmit="return confirmRescheduleApproval(event, this);">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                <input type="hidden" name="reschedule_action" value="approve">
                                <button type="submit" class="btn btn-primary" style="width: 100%; justify-content: center; background: #2ecc71; border-color: #2ecc71; color: #fff;">
                                    <i class="fas fa-check-circle"></i> Approve Reschedule
                                </button>
                            </form>
                            <form method="POST" onsubmit="return confirm('Reject this reschedule request?');">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                <input type="hidden" name="reschedule_action" value="reject">
                                <label for="rescheduleRejectReason">Reason for rejection</label>
                                <textarea id="rescheduleRejectReason" name="reject_reason" class="form-input" rows="3" required placeholder="Enter reason..."></textarea>
                                <button type="submit" class="btn btn-outline" style="width: 100%; justify-content: center; color: #ef4444; border-color: #ef4444;">
                                    <i class="fas fa-times-circle"></i> Reject Request
                                </button>
                            </form>
                        </div>
                    <?php endif; ?>

                </div>
                            </div>
            </div>
        </div>
        <div id="menuBreakdownModal" class="order-section-modal" hidden>
            <!-- Menu Breakdown -->
            <div class="card order-section-content" role="dialog" aria-modal="true" aria-label="Menu Breakdown" tabindex="-1">
                <button type="button" class="section-modal-close" aria-label="Close Menu Breakdown" onclick="closeOrderSection()"><i class="fas fa-times" aria-hidden="true"></i></button>
                <div class="card-header">
                    <div class="card-title">
                        <i class="fas fa-utensils"></i>
                        Menu Breakdown
                    </div>
                    <div style="font-size: 14px; color: var(--accent-gold); font-weight: 500;">
                        <?php echo htmlspecialchars($setName ?: 'Standard Order'); ?>
                    </div>
                </div>
                <div class="order-section-body">

                <section class="internal-stock-preparation">
                    <h3>Inventory for <?php echo (int) $order['number_of_pax']; ?> guests</h3>
                    <?php if ($inventory_notice !== ''): ?><p role="alert"><?php echo htmlspecialchars($inventory_notice); ?></p><?php endif; ?>
                    <?php if (isset($_GET['inventory_updated'])): ?><p role="status">Stock updated.</p><?php endif; ?>
                    <?php foreach ($booking_inventory as $item):
                        $available = max(0, (int) $item['available_stock'] + (int) $item['reserved_for_booking']);
                        $shortage = max(0, $item['required'] - $available);
                    ?>
                        <div class="stock-preparation-row">
                            <div class="stock-preparation-details">
                            <strong><?php echo htmlspecialchars($item['item_name']); ?></strong>
                            <dl class="stock-preparation-stats">
                                <div><dt>Needed</dt><dd><?php echo $item['required']; ?></dd></div>
                                <div><dt>Available</dt><dd><?php echo $available; ?></dd></div>
                                <div><dt>Shortage</dt><dd><?php echo $shortage; ?></dd></div>
                            </dl>
                            </div>
                            <?php if ($shortage > 0): ?>
                            <form method="post" class="stock-preparation-form">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                <input type="hidden" name="inventory_item_id" value="<?php echo (int) $item['inventory_item_id']; ?>">
                                <label for="stock-quantity-<?php echo (int) $item['inventory_item_id']; ?>">Add stock <input id="stock-quantity-<?php echo (int) $item['inventory_item_id']; ?>" class="form-control" type="number" inputmode="numeric" name="stock_quantity" min="1" step="1" value="<?php echo $shortage; ?>" required></label>
                                <button class="btn btn-primary" type="submit" name="replenish_booking_inventory" value="1">Add Stock</button>
                            </form>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </section>
                <div class="menu-grid">
                    
                    <?php
                        $event_timestamp = strtotime($order['order_date']);
                        $days_diff = ceil(($event_timestamp - time()) / (60 * 60 * 24));
                        $is_full_menu_change_allowed = $days_diff > 5;
                        $is_add_only_window = $days_diff > 0 && $days_diff <= 5;
                    ?>
                    <?php if ($days_diff <= 0): ?>
                        <p style="grid-column: 1 / -1; min-width: 0; margin: 0; color: var(--text-main); font-size: 13px; line-height: 1.5; overflow-wrap: anywhere;">Menu Finalized</p>
                    <?php elseif (!$is_add_only_window): ?>
                        <div style="grid-column: 1 / -1; background: rgba(16, 185, 129, 0.1); border: 1px solid rgba(16, 185, 129, 0.2); color: var(--success-text); padding: 12px; border-radius: 8px; margin-bottom: 0; font-size: 13px; display: flex; align-items: center; gap: 10px;">
                            <i class="fas fa-check-circle"></i>
                            <div>
                                <strong>Full Menu Changes Allowed</strong>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($menu_change_request): ?>
                    <section class="menu-change-review">
                        <div style="grid-column: 1 / -1; background: <?php echo $menu_change_status === 'approved' ? 'rgba(16, 185, 129, 0.1)' : ($menu_change_status === 'rejected' ? 'rgba(239, 68, 68, 0.1)' : 'rgba(212, 175, 55, 0.1)'); ?>; border: 1px solid <?php echo $menu_change_status === 'approved' ? 'rgba(16, 185, 129, 0.2)' : ($menu_change_status === 'rejected' ? 'rgba(239, 68, 68, 0.2)' : 'rgba(212, 175, 55, 0.2)'); ?>; color: <?php echo $menu_change_status === 'approved' ? 'var(--success-text)' : ($menu_change_status === 'rejected' ? 'var(--danger-text)' : 'var(--accent-gold)'); ?>; padding: 16px; border-radius: 10px; display: grid; gap: 12px;">
                            <div style="display: flex; justify-content: space-between; align-items: center; gap: 12px;">
                                <strong><i class="fas fa-random"></i> Menu Change Request</strong>
                                <span class="status-pill <?php echo $menu_change_status === 'approved' ? 'status-confirmed' : ($menu_change_status === 'rejected' ? 'status-cancelled' : 'status-pending'); ?>">
                                    <?php echo htmlspecialchars(ucfirst(strtolower($menu_change_status))); ?>
                                </span>
                            </div>
                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; color: #fff;">
                                <div>
                                    <div style="font-size: 12px; color: #aaa;">Current Set</div>
                                    <div style="font-weight: 600;"><?php echo htmlspecialchars($menu_change_request['current_set'] ?: 'Not specified'); ?></div>
                                </div>
                                <div>
                                    <div style="font-size: 12px; color: #aaa;">Requested Set</div>
                                    <div style="font-weight: 700; color: var(--accent-gold);"><?php echo htmlspecialchars($menu_change_request['requested_set']); ?></div>
                                </div>
                                <div>
                                    <div style="font-size: 12px; color: #aaa;">Already Paid</div>
                                    <div style="font-weight: 600;">PHP <?php echo number_format($menu_change_request['already_paid'], 2); ?></div>
                                </div>
                                <div>
                                    <div style="font-size: 12px; color: #aaa;">Additional Needed</div>
                                    <div style="font-weight: 700;">PHP <?php echo number_format($menu_change_request['additional_needed'], 2); ?></div>
                                </div>
                            </div>
                            <?php if ($menu_change_status === 'pending'): ?>
                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                                    <form method="POST" action="messages.php?view=<?php echo intval($menu_change_message_id); ?>" onsubmit="return confirmMenuChangeApproval(event, this);">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                        <input type="hidden" name="menu_change_action" value="approve">
                                        <input type="hidden" name="message_id" value="<?php echo $menu_change_message_id; ?>">
                                        <input type="hidden" name="order_id" value="<?php echo intval($order_id); ?>">
                                        <input type="hidden" name="return_to" value="order-details.php?id=<?php echo intval($order_id); ?>">
                                        <button type="submit" class="btn btn-primary" style="width: 100%; justify-content: center; background: #2ecc71; border-color: #2ecc71; color: #fff;">
                                            <i class="fas fa-check-circle"></i> Approve Request
                                        </button>
                                    </form>
                                    <form method="POST" action="messages.php?view=<?php echo intval($menu_change_message_id); ?>" onsubmit="return confirm('Reject this menu change request?');">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                        <input type="hidden" name="menu_change_action" value="reject">
                                        <input type="hidden" name="message_id" value="<?php echo $menu_change_message_id; ?>">
                                        <input type="hidden" name="order_id" value="<?php echo intval($order_id); ?>">
                                        <input type="hidden" name="return_to" value="order-details.php?id=<?php echo intval($order_id); ?>">
                                        <textarea name="reject_reason" class="form-input" rows="3" required style="margin-bottom: 10px; min-height: 86px;" placeholder="Reason for rejection..."></textarea>
                                        <button type="submit" class="btn btn-outline" style="width: 100%; justify-content: center; color: #ef4444; border-color: #ef4444;">
                                            <i class="fas fa-times-circle"></i> Reject Request
                                        </button>
                                    </form>
                                </div>
                            <?php endif; ?>
                        </div>
                    </section>
                    <?php endif; ?>

                    <?php if (!empty($menu_change_history)): ?>
                        <div style="grid-column: 1 / -1; background: rgba(255,255,255,0.02); border: 1px solid var(--border-color); border-radius: 10px; padding: 16px; display: grid; gap: 12px;">
                            <strong style="color: var(--accent-gold);"><i class="fas fa-history"></i> Menu Change History</strong>
                            <?php foreach ($menu_change_history as $entry): ?>
                                <?php
                                    $entryColor = 'var(--accent-gold)';
                                    if ($entry['action'] === 'Menu Change Approved') $entryColor = 'var(--success-text)';
                                    if ($entry['action'] === 'Menu Change Rejected') $entryColor = 'var(--danger-text)';
                                ?>
                                <div style="padding: 14px; border: 1px solid rgba(255,255,255,0.06); border-radius: 10px; background: #161616;">
                                    <div style="display: flex; justify-content: space-between; gap: 12px; align-items: center; margin-bottom: 6px;">
                                        <strong style="color: <?php echo $entryColor; ?>;"><?php echo htmlspecialchars($entry['action']); ?></strong>
                                        <span style="font-size: 12px; color: var(--text-secondary);"><?php echo date('M d, Y h:i A', strtotime($entry['created_at'])); ?></span>
                                    </div>
                                    <div style="font-size: 13px; color: var(--text-main); line-height: 1.6;">
                                        <?php echo nl2br(htmlspecialchars($entry['details'])); ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <!-- Standard Items -->
                    <div>
                        <span class="info-label" style="display: block; margin-bottom: 16px;">INCLUDED ITEMS</span>
                        <ul class="menu-list">
                            <?php if (!empty($originalItems)): ?>
                                <?php foreach ($originalItems as $item): ?>
                                    <?php 
                                        $isSwapped = false;
                                        $replacedBy = '';
                                        foreach ($swaps as $swapStr) {
                                            if (preg_match('/Swap\s+(.*?)\s+for\s+(.*?)\s+\(\+/i', $swapStr, $matches)) {
                                                $swapOrig = trim($matches[1]);
                                                $cleanItem = trim($item);
                                                $swapOrigBase = trim(preg_replace('/\s*\(.*?\)/', '', $swapOrig));
                                                $cleanItemBase = trim(preg_replace('/\s*\(.*?\)/', '', $cleanItem));
                                                if (strcasecmp($swapOrig, $cleanItem) === 0 || 
                                                    ($swapOrigBase !== '' && $cleanItemBase !== '' && strcasecmp($swapOrigBase, $cleanItemBase) === 0) ||
                                                    ($swapOrigBase !== '' && stripos($cleanItemBase, $swapOrigBase) !== false) ||
                                                    ($cleanItemBase !== '' && stripos($swapOrigBase, $cleanItemBase) !== false)) {
                                                    $isSwapped = true;
                                                    $replacedBy = trim($matches[2]);
                                                    break;
                                                }
                                            }
                                        }
                                    ?>
                                    <li class="menu-item">
                                        <?php if ($isSwapped): ?>
                                            <div style="width: 100%;">
                                                <span style="text-decoration: line-through; color: var(--text-secondary); opacity: 0.6;"><?php echo htmlspecialchars($item); ?></span>
                                                <div style="color: var(--accent-gold); font-size: 13px; margin-top: 4px; display: flex; align-items: center; gap: 6px;">
                                                    <i class="fas fa-exchange-alt"></i> Replaced by <strong><?php echo htmlspecialchars($replacedBy); ?></strong>
                                                </div>
                                            </div>
                                        <?php else: ?>
                                            <div class="menu-item-content">
                                                <div class="item-check"><i class="fas fa-check" style="font-size: 10px;"></i></div>
                                                <span><?php echo htmlspecialchars($item); ?></span>
                                            </div>
                                        <?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <li class="menu-item" style="color: var(--text-secondary); font-style: italic;">No specific items listed in this set.</li>
                            <?php endif; ?>
                        </ul>
                    </div>

                    <!-- Customizations -->
                    <div>
                        <span class="info-label" style="display: block; margin-bottom: 16px;">CUSTOMIZATIONS & ADD-ONS</span>
                        
                        <?php if (!empty($swaps) || !empty($addons) || !empty($customRequest)): ?>
                            <ul class="menu-list">
                                <?php foreach ($swaps as $swap): ?>
                                    <?php 
                                        $price = 0;
                                        if (preg_match('/\(\+[^0-9]*([\d,.]+)\)/', $swap, $matches)) { $price = floatval(str_replace(',', '', $matches[1])); }
                                        $display = preg_replace('/\(\+.*?\)/', '', $swap);
                                    ?>
                                    <li class="menu-item">
                                        <div class="menu-item-content">
                                            <span class="badge badge-swap">Swap</span>
                                            <span><?php echo htmlspecialchars($display); ?></span>
                                        </div>
                                        <div style="display: flex; align-items: center; gap: 8px;">
                                            <span class="price-tag">+&#8369;<?php echo number_format($price, 2); ?></span>
                                            <form method="POST" onsubmit="return confirm('Remove this swap?');" style="margin: 0;">
                                                <input type="hidden" name="swap_name" value="<?php echo htmlspecialchars($display); ?>">
                                                <input type="hidden" name="swap_price" value="<?php echo $price; ?>">
                                                <button type="submit" name="remove_swap" style="background: none; border: none; color: var(--danger-text); cursor: pointer; padding: 2px;">
                                                    <i class="fas fa-times"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </li>
                                <?php endforeach; ?>

                                <?php foreach ($addons as $addon): ?>
                                    <?php 
                                        $price = 0;
                                        if (preg_match('/\(\+[^0-9]*([\d,.]+)\)/', $addon, $matches)) { $price = floatval(str_replace(',', '', $matches[1])); }
                                        $display = preg_replace('/\(\+.*?\)/', '', $addon);
                                    ?>
                                    <li class="menu-item">
                                        <div class="menu-item-content">
                                            <span class="badge badge-addon">Add</span>
                                            <span><?php echo htmlspecialchars($display); ?></span>
                                        </div>
                                        <div style="display: flex; align-items: center; gap: 8px;">
                                            <span class="price-tag">+&#8369;<?php echo number_format($price, 2); ?></span>
                                            <form method="POST" onsubmit="return confirm('Remove this item?');" style="margin: 0;">
                                                <input type="hidden" name="addon_name" value="<?php echo htmlspecialchars($display); ?>">
                                                <input type="hidden" name="addon_price" value="<?php echo $price; ?>">
                                                <button type="submit" name="remove_addon" style="background: none; border: none; color: var(--danger-text); cursor: pointer; padding: 2px;">
                                                    <i class="fas fa-times"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </li>
                                <?php endforeach; ?>
                            </ul>

                            <?php if ((!empty($swaps) || !empty($addons)) && !$menu_review_complete): ?>
                                <form method="post" class="menu-review-action" style="margin:12px 0;">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                    <button type="submit" name="complete_menu_review" class="btn btn-primary">Mark customizations &amp; add-ons reviewed</button>
                                </form>
                            <?php endif; ?>
                            <?php if (!empty($customRequest)): ?>
                                <div class="special-request-box">
                                    <strong style="display: block; margin-bottom: 8px; font-size: 12px; text-transform: uppercase;">Special Request</strong>
                                    <i class="fas fa-quote-left" style="margin-right: 8px; opacity: 0.5;"></i>
                                    <?php echo htmlspecialchars($customRequest); ?>
                                    <form method="POST" class="custom-request-price-form" style="display:flex; flex-wrap:wrap; gap:10px; align-items:end; margin-top:14px;">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                        <label style="flex:1; min-width:140px;">Price (₱)
                                            <input type="number" name="custom_price" class="form-input" min="0" step="0.01" required aria-label="Special request price" value="<?php
                                                $saved_custom_price = '';
                                                if (preg_match('/\(\+₱([\d,]+(?:\.\d+)?)\)\s*$/u', $customRequest, $saved_match)) $saved_custom_price = str_replace(',', '', $saved_match[1]);
                                                echo htmlspecialchars($saved_custom_price);
                                            ?>" placeholder="0.00" style="width:100%; box-sizing:border-box;">
                                        </label>
                                        <button type="submit" name="update_custom_price" class="btn btn-primary">Save Price</button>
                                    </form>
                                </div>
                            <?php endif; ?>

                        <?php else: ?>
                            <div style="text-align: center; padding: 40px; color: var(--text-secondary); border: 1px dashed var(--border-color); border-radius: 12px;">
                                <i class="fas fa-clipboard-check" style="font-size: 24px; margin-bottom: 12px; opacity: 0.5;"></i>
                                <p>No customizations.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                            </div>
            </div>
        </div>
        <div id="damageReportModal" class="order-section-modal" hidden>
            <!-- Damage Report -->
            <div class="card order-section-content" role="dialog" aria-modal="true" aria-label="Damage / Incident Report" tabindex="-1">
                <button type="button" class="section-modal-close" aria-label="Close Damage / Incident Report" onclick="closeOrderSection()"><i class="fas fa-times" aria-hidden="true"></i></button>
                <div class="card-header">
                    <div class="card-title">
                        <i class="fas fa-exclamation-triangle" style="color: var(--danger-text);"></i>
                        Damage / Incident Report
                    </div>
                    <button type="button" class="btn btn-outline" onclick="document.getElementById('addDamageModal').style.display='flex'">
                        <i class="fas fa-plus"></i> Add Damage
                    </button>
                </div>
                <div class="order-section-body">

                <?php if (empty($damages)): ?>
                    <div style="text-align: center; padding: 30px; color: var(--text-secondary);">
                        <i class="fas fa-check-circle" style="font-size: 32px; margin-bottom: 12px; opacity: 0.3;"></i>
                        <p>No incidents.</p>
                    </div>
                <?php else: ?>
                    <table style="width: 100%; border-collapse: collapse; font-size: 14px;">
                        <thead>
                            <tr style="border-bottom: 1px solid var(--border-color); color: var(--text-secondary); text-align: left;">
                                <th style="padding: 12px; font-weight: 600;">Item</th>
                                <th style="padding: 12px; font-weight: 600;">Qty</th>
                                <th style="padding: 12px; font-weight: 600;">Price</th>
                                <th style="padding: 12px; font-weight: 600;">Total</th>
                                <th style="padding: 12px; font-weight: 600; text-align: right;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($damages as $damage): ?>
                                <tr style="border-bottom: 1px solid var(--border-color);">
                                    <td style="padding: 12px;">
                                        <div style="font-weight: 500;"><?php echo htmlspecialchars($damage['item_name']); ?></div>
                                        <?php if ($damage['notes']): ?>
                                            <div style="font-size: 12px; color: var(--text-secondary);"><?php echo htmlspecialchars($damage['notes']); ?></div>
                                        <?php endif; ?>
                                        <?php if ($damage['damage_photo']): ?>
                                            <a href="javascript:void(0)" onclick="openModal('../<?php echo $damage['damage_photo']; ?>')" style="font-size: 12px; color: var(--accent-gold); text-decoration: none;">
                                                <i class="fas fa-image"></i> View Photo
                                            </a>
                                        <?php endif; ?>
                                    </td>
                                    <td style="padding: 12px;"><?php echo $damage['quantity']; ?></td>
                                    <td style="padding: 12px;">&#8369;<?php echo number_format($damage['price'], 2); ?></td>
                                    <td style="padding: 12px; color: var(--danger-text); font-weight: 600;">&#8369;<?php echo number_format($damage['total'], 2); ?></td>
                                    <td style="padding: 12px; text-align: right;">
                                        <form method="POST" onsubmit="return confirm('Delete this damage record?');" style="display: inline;">
                                            <input type="hidden" name="damage_id" value="<?php echo $damage['id']; ?>">
                                            <button type="submit" name="delete_damage" class="btn-outline" style="padding: 4px 8px; font-size: 12px; color: var(--danger-text); border-color: var(--danger-text);">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        
                    </table>
                <?php endif; ?>
                            </div>
            <div class="damage-total-footer">
                    <span>Total Damage Fee:</span>
                    <strong>&#8369;<?php echo number_format($total_damage_fee, 2); ?></strong>
                </div>
            </div>
        </div>
        <!-- Right Column: Payment -->
        <div class="card financials-card">
    <!-- Header -->
    <header class="page-header">

        <div class="header-actions">
            
            <button onclick="window.print()" class="btn btn-outline">
                <i class="fas fa-print"></i> Print
            </button>
            <?php if (floatval($order['down_payment_paid']) <= 0 && $order['order_status'] !== 'cancelled'): ?>
                <button onclick="document.getElementById('reportNonPaymentModal').style.display='flex'" class="btn btn-outline" style="color: #e74c3c; border-color: #e74c3c;">
                    <i class="fas fa-exclamation-triangle"></i> Report
                </button>
            <?php endif; ?>
            <a href="<?php echo htmlspecialchars($order_back_href, ENT_QUOTES); ?>" class="btn btn-outline order-back-button" aria-label="Back" title="Back">
                <i class="fas fa-arrow-left" aria-hidden="true"></i>
            </a>
        </div>
    </header>

            <div class="card-header">
                <div class="card-title">
                    <i class="fas fa-wallet"></i>
                    Financials
                </div>
                <nav class="order-section-actions" aria-label="Order details">
                <button type="button" class="btn btn-outline" aria-label="Customer Information" title="Customer Information" aria-haspopup="dialog" aria-controls="customerInfoModal" onclick="openOrderSection('customerInfoModal', this)"><i class="fas fa-user-circle" aria-hidden="true"></i></button>
                <button type="button" class="btn btn-outline menu-breakdown-trigger" aria-label="Menu Breakdown<?php echo $pending_menu_tasks ? ', ' . $pending_menu_tasks . ' pending tasks' : ''; ?>" title="Menu Breakdown" aria-haspopup="dialog" aria-controls="menuBreakdownModal" onclick="openOrderSection('menuBreakdownModal', this)"><i class="fas fa-utensils" aria-hidden="true"></i><?php if ($pending_menu_tasks > 0): ?><span class="menu-attention-badge" aria-hidden="true"><?php echo $pending_menu_tasks; ?></span><?php endif; ?></button>
                <button type="button" class="btn btn-outline" aria-label="Damage / Incident Report" title="Damage / Incident Report" aria-haspopup="dialog" aria-controls="damageReportModal" onclick="openOrderSection('damageReportModal', this)"><i class="fas fa-exclamation-triangle" aria-hidden="true"></i></button>
                <button type="button" class="btn btn-outline menu-breakdown-trigger" id="rescheduleRequestTrigger" aria-label="Reschedule Request" title="Reschedule Request" aria-haspopup="dialog" aria-controls="rescheduleRequestModal" onclick="openOrderSection('rescheduleRequestModal', this)"><i class="fas fa-calendar-alt" aria-hidden="true"></i><span id="rescheduleRequestBadge" class="menu-attention-badge" <?php echo $reschedule_status !== 'pending' ? 'hidden style="display:none"' : ''; ?> aria-hidden="true">1</span></button>
                <a href="payments.php?id=<?php echo $order_id; ?><?php echo htmlspecialchars($capacity_return_query, ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-outline" aria-label="View payments" title="View payments" aria-haspopup="dialog" aria-controls="manageBookingDialog" onclick="openManageBooking(event, this.href)"><i class="fas fa-eye" aria-hidden="true"></i></a>
            </nav>
                <span class="financial-order-number" aria-label="Order number"><?php echo htmlspecialchars(formatOrderReference($order_id, $order['order_number'])); ?></span>
            </div>



            <div class="financials-scroll" role="region" aria-label="Financial details" tabindex="0">
            <!-- Financial Summary -->
            <div class="payment-summary" id="financial-summary">
                <div class="financial-balance"><div class="total-label">Balance Due</div>
                <div class="total-amount text-danger" id="balance-display">
                    &#8369;<?php echo number_format($financials['totals']['balance'], 2); ?>
                </div></div>
                
                <div class="payment-stats">
                    <div class="stat-box">
                        <div class="stat-label">Grand Total</div>
                        <div class="stat-value" id="total-display">&#8369;<?php echo number_format($financials['totals']['grand_total'], 2); ?></div>
                    </div>
                    <div class="stat-box">
                        <div class="stat-label">Total Paid</div>
                        <div class="stat-value text-success" id="paid-display">&#8369;<?php echo number_format($financials['totals']['total_paid'], 2); ?></div>
                    </div>
                </div>

                <!-- Breakdown Toggle -->
                <button type="button" class="financial-breakdown-toggle" aria-controls="breakdown-details" aria-expanded="false" onclick="toggleBreakdown()">View breakdown</button>
                <div id="breakdown-details" style="display: none; margin-top: 12px; text-align: left; font-size: 13px; border-top: 1px solid var(--border-color); padding-top: 12px;">
                    <div style="display: flex; justify-content: space-between; margin-bottom: 4px;">
                        <span style="color: var(--text-secondary);">Base Price:</span>
                        <span>&#8369;<?php echo number_format($financials['breakdown']['base_price'], 2); ?></span>
                    </div>
                    <div style="display: flex; justify-content: space-between; margin-bottom: 4px;">
                        <span style="color: var(--text-secondary);">Add-ons:</span>
                        <span>&#8369;<?php echo number_format($financials['breakdown']['addons'], 2); ?></span>
                    </div>
                    <div style="display: flex; justify-content: space-between; margin-bottom: 4px; color: var(--danger-text);">
                        <span>Damages:</span>
                        <span>&#8369;<?php echo number_format($financials['breakdown']['damages'], 2); ?></span>
                    </div>
                </div>
            </div>

            <!-- Actions -->
            <div class="financial-action-row">
                <button onclick="document.getElementById('recordPaymentModal').style.display='flex'" class="btn btn-primary" style="flex: 1; justify-content: center; min-width: 80px;">
                    <i class="fas fa-plus-circle"></i> Record payment
                </button>
                <button onclick="document.getElementById('addAddonModal').style.display='flex'" class="btn btn-outline" style="flex: 1; justify-content: center; min-width: 80px;">
                    <i class="fas fa-utensils"></i> Add Menu
                </button>
                
            </div>

            <!-- Payment History -->
            <div id="payment-history-container">
                <h4 style="font-size: 12px; margin-bottom: 12px; color: var(--text-secondary); text-transform: uppercase; font-weight: 600;">Payment History</h4>
                <?php if (empty($financials['history'])): ?>
                    <div id="no-payment-msg" style="text-align: center; padding: 20px; color: var(--text-secondary); font-size: 13px; border: 1px dashed var(--border-color); border-radius: 8px;">
                        No payments recorded yet.
                    </div>
                    <div id="payment-list" style="max-height: 300px; overflow-y: auto; display: none;">
                        <table style="width: 100%; font-size: 13px; border-collapse: collapse;">
                            <tbody id="payment-table-body"></tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div id="no-payment-msg" style="display: none; text-align: center; padding: 20px; color: var(--text-secondary); font-size: 13px; border: 1px dashed var(--border-color); border-radius: 8px;">
                        No payments recorded yet.
                    </div>
                    <div id="payment-list" style="max-height: 300px; overflow-y: auto;">
                        <table style="width: 100%; font-size: 13px; border-collapse: collapse;">
                            <tbody id="payment-table-body">
                            <?php foreach ($financials['history'] as $payment): ?>
                                <tr style="border-bottom: 1px solid var(--border-color);">
                                    <td style="padding: 12px 0;">
                                        <div style="font-weight: 600; color: var(--text-main);">
                                            <?php echo ucfirst($payment['payment_type']); ?>
                                            <?php if ($payment['status'] == 'pending'): ?>
                                                <span class="badge badge-warning" style="font-size: 10px; margin-left: 4px;">PENDING</span>
                                            <?php endif; ?>
                                        </div>
                                        <div style="font-size: 11px; color: var(--text-secondary);">
                                            <?php echo htmlspecialchars(formatOrderReference($order_id, $order['order_number'])); ?>
                                            <br>
                                            <?php echo date('M d, Y h:i A', strtotime($payment['created_at'])); ?>
                                            <br>
                                            Via <?php echo ucfirst($payment['payment_method']); ?>
                                        </div>

                                    </td>
                                    <td style="padding: 12px 0; text-align: right; vertical-align: top; font-weight: 600; color: <?php echo $payment['status']=='pending' ? 'var(--warning-text)' : 'var(--success-text)'; ?>;">
                                        +&#8369;<?php echo number_format($payment['amount'], 2); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
            
             <!-- Proof Display (Legacy Support) -->
            <?php if (!empty($order['payment_screenshot'])): ?>
                <div style="margin-top: 16px; padding-top: 0;">
                    <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:12px;">
                        <h4 style="font-size:12px;margin:0;color:var(--text-secondary);text-transform:uppercase;font-weight:600;">Uploaded Proof</h4>
                        <button type="button" class="btn btn-outline" style="display:inline-flex;align-items:center;justify-content:center;gap:8px;min-height:44px;padding:10px 16px;font-size:14px;line-height:1.4;" aria-label="Payment Transactions" title="Payment Transactions" onclick="openUploadedProofTransactions(event)"><span aria-hidden="true" style="font-size:22px;line-height:1;">⇄</span> Payment Transactions</button>
                    </div>
                    <div class="proof-container" onclick="openModal('../<?php echo htmlspecialchars($order['payment_screenshot']); ?>')">
                        <img src="../<?php echo htmlspecialchars($order['payment_screenshot']); ?>" alt="Payment Proof" class="proof-img">
                        <div class="proof-overlay">
                            <i class="fas fa-search-plus"></i> View Proof
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            </div> <!-- End financial details scroller -->
        </div>
    </div>
</div>

<!-- Add Damage Modal -->
<div id="addDamageModal" class="modal">
    <div class="modal-content" style="max-width: 500px; padding: 0; background: var(--card-bg); border: 1px solid var(--border-color);">
        <div style="padding: 24px; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center;">
            <h3 style="margin: 0; color: var(--text-main);">Report Damage / Incident</h3>
            <span style="cursor: pointer; font-size: 24px; color: var(--text-secondary);" onclick="document.getElementById('addDamageModal').style.display='none'">&times;</span>
        </div>
        <form method="POST" enctype="multipart/form-data" style="padding: 24px;">
            <div class="damage-form-fields" style="display: grid; gap: 16px;">
                <div>
                    <label style="display: block; margin-bottom: 8px; font-size: 14px; color: var(--text-secondary);">Item Name</label>
                    <input type="text" name="item_name" class="form-input" required placeholder="e.g. Broken Plate">
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                    <div>
                        <label style="display: block; margin-bottom: 8px; font-size: 14px; color: var(--text-secondary);">Quantity</label>
                        <input type="number" name="quantity" class="form-input" required value="1" min="1">
                    </div>
                    <div>
                        <label style="display: block; margin-bottom: 8px; font-size: 14px; color: var(--text-secondary);">Price per Item</label>
                        <div class="input-group">
                            <span class="input-prefix">&#8369;</span>
                            <input type="number" step="0.01" name="price" class="form-input" required placeholder="0.00">
                        </div>
                    </div>
                </div>
                <div>
                    <label style="display: block; margin-bottom: 8px; font-size: 14px; color: var(--text-secondary);">Notes (Optional)</label>
                    <textarea name="notes" class="form-input" rows="3" placeholder="Describe the damage..."></textarea>
                </div>
                <div>
                    <label style="display: block; margin-bottom: 8px; font-size: 14px; color: var(--text-secondary);">Photo Evidence (Optional)</label>
                    <input type="file" name="damage_photo" class="form-input" accept="image/*">
                </div>
            </div>
            <div class="damage-form-actions">
                <button type="submit" name="add_damage" class="btn btn-primary" style="width: 100%; justify-content: center; margin-top: 8px;">
                    Submit Report
                </button>
            </div>
        </form>
    </div>
</div>

<style>
    .prep-sheet-print {
        display: none;
    }

    @media print {
        .internal-stock-preparation { display: none !important; }
        @page {
            size: A4 portrait;
            margin: 15mm;
        }

        body {
            height: 100vh !important;
            overflow: hidden !important;
        }

        body * {
            visibility: hidden !important;
        }

        .prep-sheet-print,
        .prep-sheet-print * {
            visibility: visible !important;
        }

        .prep-sheet-print {
            display: block !important;
            position: absolute !important;
            left: 0;
            top: 0;
            width: 100% !important;
            background: #fff !important;
            color: #111 !important;
            padding: 0 !important;
            margin: 0 !important;
            font-family: Arial, Helvetica, sans-serif;
            font-size: 11pt;
            line-height: 1.4;
        }

        .prep-sheet-print h1,
        .prep-sheet-print h2,
        .prep-sheet-print h3 {
            color: #111 !important;
            margin-top: 0;
        }

        .prep-sheet-print .prep-card {
            border: 1px solid #999;
            border-radius: 6px;
            padding: 12px 14px;
            break-inside: avoid;
            margin-bottom: 12px;
        }

        .prep-sheet-print ul {
            margin: 8px 0 0;
            padding-left: 20px;
        }

        .prep-sheet-print li {
            margin-bottom: 5px;
            font-size: 11pt;
        }
    }
</style>

<div class="prep-sheet-print">
    <div style="margin-bottom: 20px;">
        <h2 style="margin: 0; font-size: 22px; text-transform: uppercase;">Booking Details</h2>
        <div style="margin-top: 5px; font-size: 14px; color: #555;"><strong>Ref:</strong> <?php echo htmlspecialchars(formatCatererBookingNumber(0, $order['order_number'])); ?></div>
        <div style="margin-top:10px;font-size:14px;line-height:1.6;">
            <div><strong>Event Date:</strong> <?php echo htmlspecialchars(date('F j, Y', strtotime($order['order_date']))); ?></div>
            <div><strong>Menu / Service:</strong> <?php echo htmlspecialchars($order['product_name'] ?? 'Not specified'); ?></div>
            <div><strong>Package:</strong> <?php echo htmlspecialchars($setName !== '' ? $setName : 'Not specified'); ?></div>
            <div><strong>Event Time:</strong> <?php echo !empty($order['order_time']) ? htmlspecialchars(date('g:i A', strtotime($order['order_time']))) : 'Not specified'; ?></div>
        </div>
    </div>

    <div class="prep-card">
        <h3 style="margin: 0 0 10px;">Included Items</h3>
        <ul>
            <?php if (!empty($originalItems)): ?>
                <?php foreach ($originalItems as $item): ?>
                    <li><?php echo htmlspecialchars($item); ?></li>
                <?php endforeach; ?>
            <?php else: ?>
                <li>No set items listed.</li>
            <?php endif; ?>
        </ul>
    </div>
    <div class="prep-card">
        <h3>Customizations &amp; Add-ons</h3>
        <?php if (!empty($swaps)): ?>
            <h4>Menu swaps</h4>
            <ul><?php foreach ($swaps as $swap): ?><li><?php echo htmlspecialchars($swap); ?></li><?php endforeach; ?></ul>
        <?php endif; ?>
        <?php if (!empty($addons)): ?>
            <h4>Add-ons</h4>
            <ul><?php foreach ($addons as $addon): ?><li><?php echo htmlspecialchars($addon); ?></li><?php endforeach; ?></ul>
        <?php endif; ?>
        <?php if (!empty($customRequest)): ?>
            <h4>Special request</h4>
            <p><?php echo nl2br(htmlspecialchars($customRequest)); ?></p>
        <?php endif; ?>
        <?php if (empty($swaps) && empty($addons) && empty($customRequest)): ?><p>No customizations or add-ons.</p><?php endif; ?>
    </div>
</div>

<!-- Report Non-Payment Modal -->
<div id="reportNonPaymentModal" class="modal">
    <div class="modal-content" style="max-width: 400px; padding: 0; background: var(--card-bg); border: 1px solid var(--border-color);">
        <div style="padding: 24px; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center;">
            <h3 style="margin: 0; color: #e74c3c;">Report Non-Payment</h3>
            <span style="cursor: pointer; font-size: 24px; color: var(--text-secondary);" onclick="document.getElementById('reportNonPaymentModal').style.display='none'">&times;</span>
        </div>
        <form method="POST" style="padding: 24px;">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <p style="color: var(--text-muted); font-size: 14px; margin-bottom: 20px;">
                Are you sure you want to report this customer for non-payment? This will <strong>cancel the order</strong> and <strong>send a request to the Portal Admin</strong> to deactivate the customer's account.
            </p>
            <div style="display: flex; gap: 12px; justify-content: flex-end;">
                <button type="button" class="btn btn-outline" onclick="document.getElementById('reportNonPaymentModal').style.display='none'">Cancel</button>
                <button type="submit" name="report_non_payment" class="btn btn-primary" style="background: #e74c3c; border-color: #e74c3c; color: white;">Yes, Report Customer</button>
            </div>
        </form>
    </div>
</div>

<!-- Image Modal -->
<div id="imageModal" class="modal" role="dialog" aria-modal="true" aria-label="Image preview">
    <button type="button" class="close-modal" aria-label="Close image preview" onclick="closeModal()">&times;</button>
    <img class="modal-content" id="modalImg" alt="Expanded image preview">
</div>

<!-- Record Payment Modal -->
<div id="recordPaymentModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="recordPaymentTitle">
    <div class="modal-content" style="padding: 0; background: var(--card-bg); border: 1px solid var(--border-color);">
        <div style="padding: 20px; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center;">
            <h3 id="recordPaymentTitle" style="margin: 0; color: var(--text-main); font-size: 18px;">Record Payment</h3>
            <button type="button" class="record-payment-close" aria-label="Close" onclick="document.getElementById('recordPaymentModal').style.display='none'">&times;</button>
        </div>
        <form method="POST" style="padding: 24px;">
            <div class="record-payment-fields">
                <div>
                    <label for="record-amount">Amount</label>
                    <div class="input-group">
                        <span class="input-prefix" style="font-size: 18px;">&#8369;</span>
                        <input type="number" step="0.01" id="record-amount" name="amount" class="form-input" required placeholder="0.00" style="font-size: 18px; padding-left: 32px; height: 50px;">
                    </div>
                </div>
                <div>
                    <label for="record-payment_type">Payment Type</label>
                    <select id="record-payment_type" name="payment_type" class="form-input" style="height: 50px; font-size: 16px;">
                        <option value="downpayment">Down Payment</option>
                        <option value="full_payment">Full Payment</option>
                        <option value="additional">Additional Payment</option>
                        <option value="refund">Refund</option>
                    </select>
                </div>
                <div>
                    <label for="record-payment_method">Payment Method</label>
                    <select id="record-payment_method" name="payment_method" class="form-input" style="height: 50px; font-size: 16px;">
                        <option value="cash">Cash</option>
                        <option value="gcash">GCash</option>
                    </select>
                </div>
                <div>
                    <label for="record-notes">Notes (Optional)</label>
                    <textarea id="record-notes" name="notes" class="form-input" rows="2" placeholder="Reference No, etc." style="font-size: 16px;"></textarea>
                </div>
            </div>
            <div class="record-payment-actions">
                <button type="submit" name="record_payment" class="btn btn-primary" style="width: 100%; justify-content: center; height: 50px; font-size: 16px;">
                    Save Payment
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Update Pax Modal -->
<div id="updatePaxModal" class="modal">
    <div class="modal-content" style="padding: 0; background: var(--card-bg); border: 1px solid var(--border-color);">
        <div style="padding: 20px; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center;">
            <h3 style="margin: 0; color: var(--text-main); font-size: 18px;">Update Guest Count</h3>
            <span style="cursor: pointer; font-size: 24px; color: var(--text-secondary);" onclick="document.getElementById('updatePaxModal').style.display='none'">&times;</span>
        </div>
        <form method="POST" style="padding: 24px;">
            <div style="display: grid; gap: 20px;">
                <div>
                    <label style="display: block; margin-bottom: 8px; font-size: 14px; color: var(--text-secondary);">New Pax Count</label>
                    <input type="number" name="new_pax" class="form-input" required value="<?php echo intval($order['number_of_pax']); ?>" min="1" style="height: 50px; font-size: 18px;">
                </div>
                <div>
                    <label style="display: block; margin-bottom: 8px; font-size: 14px; color: var(--text-secondary);">Additional Charge (Optional)</label>
                    
                    <div class="input-group">
                        <span class="input-prefix" style="font-size: 18px;">&#8369;</span>
                        <input type="number" step="0.01" name="pax_charge" class="form-input" placeholder="0.00" style="font-size: 18px; padding-left: 32px; height: 50px;">
                    </div>
                </div>
                <button type="submit" name="update_pax" class="btn btn-primary" style="width: 100%; justify-content: center; height: 50px; font-size: 16px;">
                    Update Pax
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Update Date Modal -->
<div id="updateDateModal" class="modal">
    <div class="modal-content" style="padding: 0; background: var(--card-bg); border: 1px solid var(--border-color);">
        <div style="padding: 20px; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center;">
            <h3 style="margin: 0; color: var(--text-main); font-size: 18px;">Update Event Date & Time</h3>
            <span style="cursor: pointer; font-size: 24px; color: var(--text-secondary);" onclick="document.getElementById('updateDateModal').style.display='none'">&times;</span>
        </div>
        <form method="POST" style="padding: 24px;">
            <div style="display: grid; gap: 20px;">
                <div>
                    <label style="display: block; margin-bottom: 8px; font-size: 14px; color: var(--text-secondary);">New Event Date</label>
                    <input type="date" name="new_date" class="form-input" required value="<?php echo $order['order_date']; ?>" style="height: 50px; font-size: 16px;">
                </div>
                <div>
                    <label style="display: block; margin-bottom: 8px; font-size: 14px; color: var(--text-secondary);">New Event Time</label>
                    <input type="time" name="new_time" class="form-input" required value="<?php echo date('H:i', strtotime($order['order_time'])); ?>" style="height: 50px; font-size: 16px;">
                </div>
                <button type="submit" name="update_date_time" class="btn btn-primary" style="width: 100%; justify-content: center; height: 50px; font-size: 16px;">
                    Update Schedule
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Add Add-on Modal -->
<div id="addAddonModal" class="modal">
    <div class="modal-content" style="padding: 0; background: var(--card-bg); border: 1px solid var(--border-color);">
        <div style="padding: 20px; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center;">
            <h3 style="margin: 0; color: var(--text-main); font-size: 18px;">Add Menu Item</h3>
            <span style="cursor: pointer; font-size: 24px; color: var(--text-secondary);" onclick="document.getElementById('addAddonModal').style.display='none'">&times;</span>
        </div>
        <form method="POST" style="padding: 24px;">
            <div style="display: grid; gap: 20px;">
                <div>
                    <label style="display: block; margin-bottom: 8px; font-size: 14px; color: var(--text-secondary);">Item Name</label>
                    <input type="text" name="addon_name" class="form-input" required placeholder="e.g. Extra Lechon, 50 Pax Rice" style="height: 50px; font-size: 16px;">
                </div>
                <div>
                    <label style="display: block; margin-bottom: 8px; font-size: 14px; color: var(--text-secondary);">Price</label>
                    <div class="input-group">
                        <span class="input-prefix" style="font-size: 18px;">&#8369;</span>
                        <input type="number" step="0.01" name="addon_price" class="form-input" required placeholder="0.00" style="font-size: 18px; padding-left: 32px; height: 50px;">
                    </div>
                </div>
                <button type="submit" name="add_addon" class="btn btn-primary" style="width: 100%; justify-content: center; height: 50px; font-size: 16px;">
                    Add Item
                </button>
            </div>
        </form>
    </div>
</div>

<script>

    const orderViewKey = 'order-view-<?php echo (int) $order_id; ?>';
    function getDamageDraft() {
        const modal = document.getElementById('addDamageModal');
        const fields = {};
        modal.querySelectorAll('input:not([type="file"]), textarea').forEach(field => {
            if (field.name) fields[field.name] = field.value;
        });
        return {open: modal.style.display === 'flex', fields, scroll: modal.querySelector('.damage-form-fields').scrollTop};
    }

    function saveOrderView() {
        const positions = {};
        document.querySelectorAll('.order-section-body').forEach(body => {
            positions[body.closest('.order-section-modal').id] = body.scrollTop;
        });
        try {
            sessionStorage.setItem(orderViewKey, JSON.stringify({
                section: activeOrderSection ? activeOrderSection.id : null,
                formModals: ['addAddonModal', 'recordPaymentModal', 'updatePaxModal', 'updateDateModal'].map(id => {
                    const modal = document.getElementById(id);
                    const fields = {};
                    modal.querySelectorAll('input:not([type="hidden"]):not([type="file"]), textarea, select').forEach(field => { if(field.name) fields[field.name] = field.value; });
                    return {id, open: modal.style.display === 'flex', fields};
                }),
                damageDraft: getDamageDraft(),
                image: document.getElementById('imageModal').style.display === 'flex' ? document.getElementById('modalImg').getAttribute('src') : null,
                financialScroll: document.querySelector('.financials-scroll').scrollTop,
                breakdown: document.getElementById('breakdown-details').style.display !== 'none',
                positions
            }));
        } catch (error) { /* Storage may be unavailable in private browsers. */ }
    }
    let activeOrderSection = null;
    let orderSectionTrigger = null;
    let orderSectionOverflow = '';
    function openOrderSection(id, trigger) {
        if (activeOrderSection) closeOrderSection();
        activeOrderSection = document.getElementById(id);
        orderSectionTrigger = trigger;
        orderSectionOverflow = document.body.style.overflow;
        activeOrderSection.hidden = false;
        document.body.style.overflow = 'hidden';
        // Focus the dialog itself on open/restore; keep the close icon unhighlighted.
        activeOrderSection.querySelector('[role="dialog"]').focus({ preventScroll: true });
        saveOrderView();
    }
    function closeOrderSection() {
        if (!activeOrderSection) return;
        activeOrderSection.hidden = true;
        activeOrderSection = null;
        document.body.style.overflow = orderSectionOverflow;
        if (orderSectionTrigger) orderSectionTrigger.focus();
        saveOrderView();
    }

    document.addEventListener('keydown', event => {
        if (!activeOrderSection) return;
        if (Array.from(document.querySelectorAll('.modal')).some(modal => getComputedStyle(modal).display !== 'none')) return;
        if (event.key === 'Escape') {
            event.preventDefault();
            event.stopImmediatePropagation();
        }
        if (event.key === 'Tab') {
            const controls = Array.from(activeOrderSection.querySelectorAll('button, a[href], input, select, textarea, [tabindex="0"]')).filter(el => !el.disabled && el.getClientRects().length);
            const first = controls[0], last = controls[controls.length - 1];
            if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
            else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
        }
    }, true);
    let imagePreviewTrigger = null;
    let imagePreviewOverflow = "";
    function openModal(src) {
        imagePreviewTrigger = document.activeElement;
        imagePreviewOverflow = document.body.style.overflow;
        document.getElementById('imageModal').style.display = "flex";
        document.getElementById('modalImg').src = src;
        document.body.style.overflow = "hidden";
        document.querySelector("#imageModal .close-modal").focus();
        saveOrderView();
    }

    function closeModal() {
        document.getElementById('imageModal').style.display = "none";
        document.body.style.overflow = imagePreviewOverflow;
        if (imagePreviewTrigger) imagePreviewTrigger.focus();
        saveOrderView();
    }

    // Close on outside click
    window.onclick = function(event) {

        if (event.target == document.getElementById('recordPaymentModal')) {
            document.getElementById('recordPaymentModal').style.display = "none";
        }
        if (event.target == document.getElementById('updatePaxModal')) {
            document.getElementById('updatePaxModal').style.display = "none";
        }
        if (event.target == document.getElementById('updateDateModal')) {
            document.getElementById('updateDateModal').style.display = "none";
        }
    }

    // Close on Escape key
    document.addEventListener('keydown', function(event) {
        if (document.getElementById("imageModal").style.display === "flex") {
            if (event.key === "Escape" || event.key === "Tab") {
                event.preventDefault();
                document.querySelector("#imageModal .close-modal").focus();
            }
            return;
        }
        if (document.getElementById('addAddonModal').style.display === 'flex' && event.key === 'Escape') {
            event.preventDefault();
            return;
        }
        if (event.key === "Escape") {
            document.getElementById('recordPaymentModal').style.display = "none";
            document.getElementById('addAddonModal').style.display = "none";
            document.getElementById('updatePaxModal').style.display = "none";
            document.getElementById('updateDateModal').style.display = "none";
        }
    });

    function toggleBreakdown() {
        var el = document.getElementById('breakdown-details');
        const button = document.querySelector('.financial-breakdown-toggle');
        if (el.style.display === 'none') {
            el.style.display = 'block';
            button.setAttribute('aria-expanded', 'true');
            button.textContent = 'Hide breakdown';
        } else {
            el.style.display = 'none';
            button.setAttribute('aria-expanded', 'false');
            button.textContent = 'View breakdown';
        }
    }

    // Preserve the active section and scroll position across reloads and form submissions.
    let savedOrderView = null;
    try { savedOrderView = JSON.parse(sessionStorage.getItem(orderViewKey)); } catch (error) {}
    if (savedOrderView) {
        const completedAction = new URLSearchParams(location.search).has('success');
        (savedOrderView.formModals || []).forEach(draft => {
            if (completedAction || !['addAddonModal','recordPaymentModal','updatePaxModal','updateDateModal'].includes(draft.id)) return;
            const modal = document.getElementById(draft.id);
            modal.querySelectorAll('input:not([type="hidden"]):not([type="file"]), textarea, select').forEach(field => {
                if (Object.prototype.hasOwnProperty.call(draft.fields || {}, field.name)) field.value = draft.fields[field.name];
            });
            if(draft.open) modal.style.display = 'flex';
        });
        if (savedOrderView.damageDraft) {
            const draft = savedOrderView.damageDraft;
            const damageModal = document.getElementById('addDamageModal');
            damageModal.querySelectorAll('input:not([type="file"]), textarea').forEach(field => {
                if (Object.prototype.hasOwnProperty.call(draft.fields || {}, field.name)) field.value = draft.fields[field.name];
            });
            if (draft.open) {
                damageModal.style.display = 'flex';
                requestAnimationFrame(() => { damageModal.querySelector('.damage-form-fields').scrollTop = draft.scroll || 0; });
            }
        }

        const section = document.getElementById(savedOrderView.section);
        if (section && section.classList.contains('order-section-modal')) {
            openOrderSection(section.id, document.querySelector('[aria-controls="' + section.id + '"]'));
        }
        document.getElementById('breakdown-details').style.display = savedOrderView.breakdown ? 'block' : 'none';
        if (typeof savedOrderView.image === 'string' && savedOrderView.image) {
            const savedImageUrl = new URL(savedOrderView.image, window.location.href);
            if (savedImageUrl.origin === window.location.origin) openModal(savedOrderView.image);
        }
        requestAnimationFrame(() => {
            document.querySelector('.financials-scroll').scrollTop = savedOrderView.financialScroll || 0;
            document.querySelectorAll('.order-section-body').forEach(body => {
                body.scrollTop = (savedOrderView.positions || {})[body.closest('.order-section-modal').id] || 0;
            });
        });
    }

    const damageModal = document.getElementById('addDamageModal');
    if (new URLSearchParams(window.location.search).get('success') === 'damage_added') {
        damageModal.querySelector('form').reset();
        damageModal.style.display = 'none';
        saveOrderView();
    }
    damageModal.addEventListener('input', saveOrderView);
    new MutationObserver(saveOrderView).observe(damageModal, {attributes: true, attributeFilter: ['style']});
    ['addAddonModal','recordPaymentModal','updatePaxModal','updateDateModal'].forEach(id => {
        const modal = document.getElementById(id);
        modal.addEventListener('input', saveOrderView);
        new MutationObserver(saveOrderView).observe(modal, {attributes:true, attributeFilter:['style']});
    });
    document.querySelector('.financials-scroll').addEventListener('scroll', saveOrderView, {passive:true});
    document.querySelectorAll('.order-section-body').forEach(body => body.addEventListener('scroll', saveOrderView, {passive:true}));
    window.addEventListener('pagehide', saveOrderView);
    document.addEventListener('submit', saveOrderView, true);

    // Polling for Balance Updates
    setInterval(function() {
        fetch('../api/get_order_balance.php?id=<?php echo $order_id; ?>', {cache: 'no-store'})
        .then(response => response.json())
        .then(data => {
            if (data.success && typeof data.reschedule_pending_count === 'number') {
                const badge = document.getElementById('rescheduleRequestBadge');
                const count = data.reschedule_pending_count;
                badge.hidden = count === 0;
                badge.style.display = count > 0 ? 'grid' : 'none';
                badge.textContent = count;
                document.getElementById('rescheduleRequestTrigger').setAttribute('aria-label', count > 0 ? 'Reschedule Request, 1 pending request' : 'Reschedule Request');
            }
            if (data.success && data.totals) {
                document.getElementById('balance-display').innerText = '\u20B1' + parseFloat(data.totals.balance).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                document.getElementById('total-display').innerText = '\u20B1' + parseFloat(data.totals.grand_total).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                document.getElementById('paid-display').innerText = '\u20B1' + parseFloat(data.totals.total_paid).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                
                // Update Payment History
                const historyBody = document.getElementById('payment-table-body');
                const noPaymentMsg = document.getElementById('no-payment-msg');
                const paymentList = document.getElementById('payment-list');
                
                if (data.history && data.history.length > 0) {
                    noPaymentMsg.style.display = 'none';
                    paymentList.style.display = 'block';
                    
                    let html = '';
                    data.history.forEach(payment => {
                        const amount = parseFloat(payment.amount).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                        const dateObj = new Date(payment.created_at);
                        const date = dateObj.toLocaleDateString('en-US', {month: 'short', day: '2-digit', year: 'numeric'}) + ' ' + dateObj.toLocaleTimeString('en-US', {hour: 'numeric', minute: '2-digit', hour12: true});
                        const statusBadge = payment.status === 'pending' ? '<span class="badge badge-warning" style="font-size: 10px; margin-left: 4px; background: var(--warning-bg); color: var(--warning-text); padding: 2px 6px; border-radius: 4px;">PENDING</span>' : '';
                        const color = payment.status === 'pending' ? 'var(--warning-text)' : 'var(--success-text)';
                        const bookingRef = <?php echo json_encode(formatOrderReference($order_id, $order['order_number'])); ?>;
                        
                        html += `
                            <tr style="border-bottom: 1px solid var(--border-color);">
                                <td style="padding: 12px 0;">
                                    <div style="font-weight: 600; color: var(--text-main);">
                                        ${payment.payment_type.charAt(0).toUpperCase() + payment.payment_type.slice(1)}
                                        ${statusBadge}
                                    </div>
                                    <div style="font-size: 11px; color: var(--text-secondary);">
                                        ${bookingRef}
                                        <br>
                                        ${date}
                                        <br>
                                        Via ${payment.payment_method.charAt(0).toUpperCase() + payment.payment_method.slice(1)}
                                    </div>
                                </td>
                                <td style="padding: 12px 0; text-align: right; vertical-align: top; font-weight: 600; color: ${color};">
                                    +&#8369;${amount}
                                </td>
                            </tr>
                        `;
                    });
                    historyBody.innerHTML = html;
                } else {
                    noPaymentMsg.style.display = 'block';
                    paymentList.style.display = 'none';
                    historyBody.innerHTML = '';
                }
            }
        }).catch(() => { /* Retain the current history when the connection drops. */ });
    }, 5000);
</script>

<?php if (in_array($_GET['success'] ?? '', ['addon_added', 'custom_price_updated'], true)): ?>
<dialog id="menuItemSavedNotice" aria-labelledby="menuItemSavedTitle">
    <h2 id="menuItemSavedTitle"><?php echo $_GET['success'] === 'custom_price_updated' ? 'Price saved successfully' : 'Menu item added'; ?></h2>
    <p><?php echo $_GET['success'] === 'custom_price_updated' ? 'The special request price has been updated.' : 'The item has been added successfully.'; ?></p>
    <button type="button" onclick="document.getElementById('menuItemSavedNotice').close()">OK</button>
</dialog>
<style>
#menuItemSavedNotice {width:min(340px,calc(100% - 32px));max-height:calc(100dvh - 32px);overflow:auto;box-sizing:border-box;padding:24px;border:1px solid #96a38e;border-radius:14px;background:#d4dbcd;color:#20372b;text-align:center;box-shadow:0 16px 48px #0c201933;}
#menuItemSavedNotice::backdrop {background:rgb(12 32 25 / 60%);}
#menuItemSavedNotice h2 {margin:0 0 10px;font-size:18px;color:#173d32;}
#menuItemSavedNotice p {margin:0 0 20px;font-size:14px;line-height:1.5;color:#425642;}
#menuItemSavedNotice button {min-width:96px;min-height:44px;padding:8px 20px;background:#173d32;color:#f0f1e6;border:1px solid #426052;border-radius:8px;font:inherit;cursor:pointer;}
</style>
<script>
(() => {
    <?php if ($_GET['success'] === 'addon_added'): ?>
    const formModal=document.getElementById('addAddonModal');
    if (formModal) {
        formModal.style.display='none';
        formModal.querySelector('form')?.reset();
    }
    <?php endif; ?>
    saveOrderView();
    const notice=document.getElementById('menuItemSavedNotice');
    notice.showModal();
    const cleanUrl=new URL(location.href);cleanUrl.searchParams.delete('success');history.replaceState(history.state,'',cleanUrl);
})();
</script>
<?php endif; ?>
<style>
@media print {
    html, body, body.bounded-caterer-page {height:auto!important;max-height:none!important;overflow:visible!important;}
    body.bounded-caterer-page > .main-content, .caterer-workspace {height:auto!important;max-height:none!important;overflow:visible!important;}
    .prep-sheet-print .prep-card {break-inside:auto;}
    .prep-sheet-print h3, .prep-sheet-print h4 {break-after:avoid;}
    .prep-sheet-print li {break-inside:avoid;}
}
</style>
<dialog id="approveMenuChangeDialog" aria-labelledby="approveMenuChangeTitle">
    <h3 id="approveMenuChangeTitle">Approve menu change?</h3>
    <p>Approve the customer’s requested package change?</p>
    <div class="reschedule-confirm-buttons">
        <button type="button" class="btn btn-outline" onclick="document.getElementById('approveMenuChangeDialog').close()">Cancel</button>
        <button type="button" class="btn btn-primary" id="confirmMenuChangeApprove">Approve</button>
    </div>
</dialog>
<style>
#approveMenuChangeDialog{position:fixed;inset:0;margin:auto;width:min(380px,calc(100vw - 32px));height:fit-content;max-height:calc(100dvh - 32px);overflow:auto;box-sizing:border-box;padding:24px;border:1px solid #96a38e;border-radius:12px;background:#d4dbcd;color:#20372b;text-align:center;}
#approveMenuChangeDialog::backdrop{background:rgb(12 32 25 / 55%);backdrop-filter:blur(4px);}
#approveMenuChangeDialog h3{margin:0 0 12px;font-size:18px;line-height:1.4;}
#approveMenuChangeDialog p{margin:0 0 22px;font-size:14px;line-height:1.6;overflow-wrap:anywhere;}
</style>
<script>
let menuChangeApprovalForm = null;
function confirmMenuChangeApproval(event, form) {
    event.preventDefault();
    menuChangeApprovalForm = form;
    document.getElementById('approveMenuChangeDialog').showModal();
    return false;
}
document.getElementById('confirmMenuChangeApprove').addEventListener('click', function() {
    if (!menuChangeApprovalForm) return;
    this.disabled = true;
    HTMLFormElement.prototype.submit.call(menuChangeApprovalForm);
});
</script>
<dialog id="approveRescheduleDialog" aria-labelledby="approveRescheduleTitle">
    <h3 id="approveRescheduleTitle">Approve reschedule?</h3>
    <p>This will update the event to the requested date and time.</p>
    <div class="reschedule-confirm-buttons">
        <button type="button" class="btn btn-outline" onclick="document.getElementById('approveRescheduleDialog').close()">Cancel</button>
        <button type="button" class="btn btn-primary" id="confirmRescheduleApprove">Approve</button>
    </div>
</dialog>
<style>
#approveRescheduleDialog{position:fixed;inset:0;margin:auto;width:min(380px,calc(100vw - 32px));height:fit-content;max-height:calc(100dvh - 32px);overflow:auto;box-sizing:border-box;padding:24px;border:1px solid #96a38e;border-radius:12px;background:#d4dbcd;color:#20372b;text-align:center;}
#approveRescheduleDialog::backdrop{background:rgb(12 32 25 / 55%);backdrop-filter:blur(4px);}
#approveRescheduleDialog h3{margin:0 0 12px;font-size:18px;line-height:1.4;}
#approveRescheduleDialog p{margin:0 0 22px;font-size:14px;line-height:1.6;overflow-wrap:anywhere;}
.reschedule-confirm-buttons{display:flex;justify-content:center;gap:10px;flex-wrap:wrap;}
.reschedule-confirm-buttons .btn{min-height:44px;flex:1;justify-content:center;}
</style>
<script>
let rescheduleApprovalForm = null;
function confirmRescheduleApproval(event, form) {
    event.preventDefault();
    rescheduleApprovalForm = form;
    document.getElementById('approveRescheduleDialog').showModal();
    return false;
}
document.getElementById('confirmRescheduleApprove').addEventListener('click', function() {
    if (!rescheduleApprovalForm) return;
    this.disabled = true;
    HTMLFormElement.prototype.submit.call(rescheduleApprovalForm);
});
</script>
<dialog id="manageBookingDialog" aria-labelledby="manageBookingTitle">
<header><h2 id="manageBookingTitle" tabindex="-1">Manage payments</h2><button type="button" aria-label="Close manage payments" onclick="closeManageBooking()">&times;</button></header>
<iframe title="Booking payment management" id="manageBookingFrame"></iframe>
</dialog>
<style>
#manageBookingDialog{width:min(900px,calc(100% - 32px));height:min(580px,calc(100dvh - 32px));box-sizing:border-box;max-width:none;max-height:none;padding:0;border:1px solid #96a38e;border-radius:14px;background:#d4dbcd;color:#20372b;overflow:hidden;}
#manageBookingDialog[open]{display:flex;flex-direction:column;}
#manageBookingDialog::backdrop{background:rgb(12 32 25 / 65%);backdrop-filter:blur(4px);}
#transactionsOnlyDialog::backdrop {
    background: rgb(12 32 25 / 55%);
    backdrop-filter: blur(5px);
    -webkit-backdrop-filter: blur(5px);
}
#manageBookingDialog header{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:10px 16px;border-bottom:1px solid #96a38e;flex-shrink:0;}
#manageBookingDialog h2{margin:0;font-size:17px;}
#manageBookingDialog h2:focus{outline:none;}
#manageBookingDialog header button{width:44px;height:44px;border:0;background:transparent;color:#20372b;font-size:26px;cursor:pointer;}
#manageBookingDialog header button,
#manageBookingDialog header button:hover,
#manageBookingDialog header button:focus,
#manageBookingDialog header button:focus-visible,
#manageBookingDialog header button:active {
    outline: none !important;
    border: none !important;
    box-shadow: none !important;
    background: transparent !important;
}
#manageBookingFrame{flex:1;min-height:0;width:100%;border:0;background:#d4dbcd;}
.order-section-modal [role="dialog"]:focus { outline: none; }
#manageBookingDialog header button,
.order-section-modal .section-modal-close,
button.close-modal {
    background: transparent !important;
    border: 0 !important;
    border-radius: 0 !important;
    box-shadow: none !important;
}
@media(max-width:600px){#manageBookingDialog{width:calc(100% - 16px);height:min(680px,calc(100dvh - 24px));}#manageBookingDialog header{padding:8px 12px;}}
</style>
<script>
const manageBookingStateKey = 'manage-payments-open-<?php echo (int) $user_id; ?>-<?php echo (int) $order_id; ?>';
function openUploadedProofTransactions(event) {
    if (event) event.preventDefault();
    const link = document.querySelector('[aria-controls="manageBookingDialog"]');
    if (!link) return;
    let dialog = document.getElementById('transactionsOnlyDialog');
    if (!dialog) {
        dialog = document.createElement('dialog');
        dialog.id = 'transactionsOnlyDialog';
        dialog.setAttribute('aria-label', 'Payment Transactions');
        dialog.style.cssText = 'width:min(920px,calc(100% - 16px));height:min(420px,calc(100dvh - 24px));max-width:none;padding:0;border:0;background:transparent;overflow:hidden;';
        const frame = document.createElement('iframe');
        frame.title = 'Payment Transactions';
        frame.style.cssText = 'width:100%;height:100%;border:0;display:block;';
        const url = new URL(link.href);
        url.searchParams.set('embed', '1');
        url.searchParams.set('transactions_only', '1');
        frame.src = url.href;
        dialog.append(frame);
        dialog.addEventListener('cancel', e => e.preventDefault());
        document.body.append(dialog);
    }
    if (!dialog.open) dialog.showModal();
    dialog.querySelector('iframe').contentWindow?.openPaymentTransactions?.();
    const pageUrl = new URL(location.href);
    pageUrl.searchParams.set('payment_transactions', '1');
    history.replaceState(history.state, '', pageUrl);
}
window.addEventListener('message', function(event) {
    const dialog = document.getElementById('transactionsOnlyDialog');
    if (event.origin === location.origin && event.source === dialog?.querySelector('iframe')?.contentWindow && event.data?.type === 'payment-transactions-size') {
        const height = Number(event.data.height);
        if (Number.isFinite(height) && height > 0) dialog.style.height = Math.min(height + 4, window.innerHeight - 24) + 'px';
    }
    if (event.origin === location.origin && event.source === dialog?.querySelector('iframe')?.contentWindow && event.data === 'close-payment-transactions') {
        const pageUrl = new URL(location.href);
        pageUrl.searchParams.delete('payment_transactions');
        history.replaceState(history.state, '', pageUrl);
        dialog.close();
    }
});
if (new URL(location.href).searchParams.get('payment_transactions') === '1') {
    openUploadedProofTransactions(null);
}
function openManageBooking(event, href){
    if(event) event.preventDefault();
    const url=new URL(href,location.href);url.searchParams.set('embed','1');
    const frame=document.getElementById('manageBookingFrame');
    const dialog = document.getElementById('manageBookingDialog');
    if (!dialog.open || frame.getAttribute('src') !== url.href) frame.src = url.href;
    if (!dialog.open) dialog.showModal();
    document.getElementById('manageBookingTitle').focus({preventScroll:true});
    const pageUrl = new URL(location.href);
    pageUrl.searchParams.set('manage_payments', '1');
    history.replaceState(history.state, '', pageUrl);
    try { sessionStorage.setItem(manageBookingStateKey, '1'); } catch (_) {}
}
function closeManageBooking(){
    const pageUrl = new URL(location.href);
    pageUrl.searchParams.delete('manage_payments');
    history.replaceState(history.state, '', pageUrl);
    try { sessionStorage.removeItem(manageBookingStateKey); } catch (_) {}
    document.getElementById('manageBookingDialog').close();
}
document.getElementById('manageBookingDialog').addEventListener('cancel',event=>event.preventDefault());
(function restoreManageBooking(){
    let shouldOpen = new URL(location.href).searchParams.get('manage_payments') === '1';
    try {
        shouldOpen = shouldOpen || sessionStorage.getItem(manageBookingStateKey) === '1';
    } catch (_) {}
    if (shouldOpen) {
        const link = document.querySelector('[aria-controls="manageBookingDialog"]');
        if (link) openManageBooking(null, link.href);
    }
})();
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>


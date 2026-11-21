<?php
require_once __DIR__ . '/../config.php';
/** @var mysqli $conn */
require_once __DIR__ . '/../includes/payment_functions.php';
require_once __DIR__ . '/../includes/booking_pricing_helper.php';
require_once __DIR__ . '/../includes/booking_guest_count.php';

function browseStoreGetLegacyCategoryLabel(string $key): string
{
    $labels = [
        'appetizers' => 'Appetizers',
        'soup' => 'Soup',
        'rice' => 'Rice',
        'main_dishes' => 'Main Dishes',
        'pasta_noodles' => 'Pasta/Noodles',
        'desserts' => 'Desserts',
        'drinks' => 'Drinks',
    ];
    return $labels[$key] ?? $key;
}

function browseStoreGetCategoryLines($raw_description): array
{
    $decoded = json_decode((string) $raw_description, true);
    $category_lines = [];

    if (is_array($decoded) && isset($decoded['categories']) && is_array($decoded['categories'])) {
        foreach ($decoded['categories'] as $key => $items) {
            $label = browseStoreGetLegacyCategoryLabel($key);
            $items_array = is_array($items)
                ? array_values(array_filter(array_map('trim', $items), 'strlen'))
                : array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n|,/', (string) $items)), 'strlen'));
            
            if (!empty($items_array)) {
                $category_lines[] = [
                    'key' => $key,
                    'label' => $label,
                    'items' => $items_array
                ];
            }
        }
        return $category_lines;
    }

    $plain = trim((string) $raw_description);
    if ($plain !== '') {
        $plain_items = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n|,/', $plain)), 'strlen'));
        if (!empty($plain_items)) {
            $category_lines[] = [
                'key' => 'main_dishes',
                'label' => 'Main Dishes',
                'items' => $plain_items
            ];
        }
    }

    return $category_lines;
}

if (isLoggedIn() && getUserRole() !== 'customer') {
    header('Location: ../index.php');
    exit();
}

$is_customer_logged_in = isLoggedIn() && getUserRole() === 'customer';
$user_id = $is_customer_logged_in ? intval($_SESSION['user_id']) : 0;

// ==============================================
// HANDLE TOGGLE FAVORITE (AJAX)
// ==============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_favorite') {
    header('Content-Type: application/json');
    if (!$is_customer_logged_in) {
        echo json_encode(['success' => false, 'message' => 'Please login first.']);
        exit();
    }
    
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid security token.']);
        exit();
    }
    
    $store_id = isset($_POST['store_id']) ? intval($_POST['store_id']) : 0;
    
    if ($store_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid caterer ID.']);
        exit();
    }
    
    // Check if already favorited
    $check_query = "SELECT id FROM customer_favorites WHERE customer_id = '$user_id' AND store_id = '$store_id'";
    $check_result = mysqli_query($conn, $check_query);
    
    if (mysqli_num_rows($check_result) > 0) {
        // Remove from favorites
        $delete_query = "DELETE FROM customer_favorites WHERE customer_id = '$user_id' AND store_id = '$store_id'";
        mysqli_query($conn, $delete_query);
        echo json_encode(['success' => true, 'is_favorite' => false]);
    } else {
        // Add to favorites
        $insert_query = "INSERT INTO customer_favorites (customer_id, store_id) VALUES ('$user_id', '$store_id')";
        mysqli_query($conn, $insert_query);
        echo json_encode(['success' => true, 'is_favorite' => true]);
    }
    exit();
}
$store_id = isset($_GET['store_id']) ? mysqli_real_escape_string($conn, $_GET['store_id']) : '';
$csrf_token = generateCSRFToken();
$current_booking_year = date('Y');
$current_year_start = $current_booking_year . '-01-01';
$current_year_end = $current_booking_year . '-12-31';

if (isset($_GET['booking']) && $_GET['booking'] === 'closed') {
    $error_message = 'This caterer is currently closed and is not accepting bookings right now.';
}

// Caterer availability follows the caterer's manual open/close toggle.
function isStoreOpen($is_open, $open_time, $close_time) {
    $auto_schedule_enabled = func_num_args() >= 4 ? func_get_arg(3) : 0;
    return isStoreAvailableNow($is_open, $open_time, $close_time, $auto_schedule_enabled);
}

function getMenuPreviewItemsFromCategoryLines(array $category_lines, int $limit = 5): array {
    $items = [];
    foreach ($category_lines as $line) {
        foreach ($line['items'] ?? [] as $item) {
            $item = trim((string) $item);
            if ($item !== '') {
                $items[] = $item;
            }
        }
    }
    return array_slice($items, 0, $limit);
}

function getTotalMenuItemCount(array $category_lines): int {
    $count = 0;
    foreach ($category_lines as $line) {
        $count += count($line['items'] ?? []);
    }
    return $count;
}

// Get customer details (for header consistency if needed, though header.php handles it)
// $customer_query = "SELECT * FROM users WHERE id = '$user_id'";
// $customer_result = mysqli_query($conn, $customer_query);
// $customer = mysqli_fetch_assoc($customer_result);

// ==============================================
// HANDLE ORDER FORM SUBMISSION
// ==============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['product_id'])) {
    requireLogin();
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        die('<div style="color: #f44336; padding: 20px;">Invalid security token. Please refresh the page and try again.</div>');
    }
    
    $product_id = mysqli_real_escape_string($conn, $_POST['product_id']);
    $store_id_post = mysqli_real_escape_string($conn, $_POST['store_id']);
    $quantity = parseBookingGuestCount($_POST['quantity'] ?? null);
    $delivery_date = mysqli_real_escape_string($conn, $_POST['delivery_date']);
    $delivery_time = mysqli_real_escape_string($conn, $_POST['delivery_time']);
    $delivery_address = mysqli_real_escape_string($conn, $_POST['delivery_address'] ?? '');
    $payment_method = mysqli_real_escape_string($conn, $_POST['payment_method']);
    $notes = mysqli_real_escape_string($conn, $_POST['notes'] ?? '');
    $booking_year = date('Y', strtotime($delivery_date));
    
    // ==============================================
    // SMART ORDER PROTECTION
    // ==============================================
    $errors = [];
    if ($quantity === null) {
        $errors[] = 'Enter a whole number of guests from 1 to 100,000 without leading zeros.';
    }
    $warnings = [];
    
    // 1. Check if event date is in the past
    $event_date = new DateTime($delivery_date);
    $today = new DateTime();
    $today->setTime(0, 0, 0);
    
    if ($event_date < $today) {
        $errors[] = 'Event date cannot be in the past.';
    }

    // Allow booking up to 1 year into the future
    $max_booking_date = clone $today;
    $max_booking_date->modify('+1 year');
    
    if ($event_date > $max_booking_date) {
        $errors[] = 'Booking dates are only allowed up to 1 year in advance.';
    }
    
    // 2. Check minimum order notice (3 days before event)
    $min_notice_date = clone $today;
    $min_notice_date->modify('+2 days');
    
    if ($event_date < $min_notice_date) {
        $errors[] = 'Please book at least 2 days in advance.';
    }
    
    // 3. Check if caterer is almost fully booked on selected date
    $availability = checkStoreAvailability($store_id_post, $delivery_date);
    if (!$availability['is_available']) {
        $errors[] = 'This caterer is fully booked on the selected date. Please choose another date.';
    } elseif ($availability['is_almost_full']) {
        $warnings[] = 'Warning: This caterer is almost fully booked on this date. Only ' . $availability['slots_remaining'] . ' slot(s) remaining.';
    }
    
    // Display errors if any
    if (!empty($errors) || !empty($warnings)) {
        $error_html = '<div style="background: rgba(244,67,54,0.1); border: 1px solid #f44336; border-radius: 8px; padding: 20px; margin-bottom: 20px;">';
        $error_html .= '<h4 style="color: #f44336; margin-bottom: 10px;"><i class="fas fa-exclamation-triangle"></i> Booking Availability Notice</h4>';
        $error_html .= '<ul style="color: #fff; margin: 0; padding-left: 20px;">';
        foreach ($errors as $error) {
            $error_html .= '<li style="margin-bottom: 5px;">' . $error . '</li>';
        }
        foreach ($warnings as $warning) {
            $error_html .= '<li style="margin-bottom: 5px; color: #ffd27f;">' . $warning . '</li>';
        }
        $error_html .= '</ul>';
        $error_html .= '<button onclick="history.back()" style="margin-top: 15px; padding: 10px 20px; background: var(--accent-gold); color: #000; border: none; border-radius: 6px; cursor: pointer;">Go Back</button>';
        $error_html .= '</div>';
        if (!empty($errors)) {
            die($error_html);
        }
    }

    // Get product details for price calculation
    $prod_query = "SELECT p.*, sd.minimum_booking_guests, sd.maximum_booking_guests FROM products p LEFT JOIN store_details sd ON sd.user_id = p.store_id WHERE p.id = '$product_id' AND p.store_id = '$store_id_post'";
    $prod_result = mysqli_query($conn, $prod_query);
    $product = mysqli_fetch_assoc($prod_result);

    if ($product) {
        $selected_set_name = isset($_POST['notes_package']) ? mysqli_real_escape_string($conn, $_POST['notes_package']) : '';
        $package_details = "Base Package: " . ($selected_set_name ? $selected_set_name : 'Standard');

        // 2. Handle Add-ons and Swaps (EXACTLY match frontend calculateTotal and book-service.php)
        $addons_json = isset($_POST['add_ons']) ? $_POST['add_ons'] : '[]';
        $swaps_json = isset($_POST['swaps']) ? $_POST['swaps'] : '[]';
        
        $addons = json_decode($addons_json, true);
        $swaps = json_decode($swaps_json, true);
        
        $pricing = bookingPricingCalculateRequest($conn, [
            'product_id' => intval($product_id),
            'quantity' => $quantity,
            'selected_set_name' => $selected_set_name,
            'addons' => $addons,
            'swaps' => array_map(function ($swap) {
                if (!isset($swap['replacement']) && isset($swap['new'])) {
                    $swap['replacement'] = $swap['new'];
                }
                return $swap;
            }, is_array($swaps) ? $swaps : [])
        ]);

        if (!$pricing) {
            $error_message = "Unable to place your booking right now. Please review your details and try again.";
            goto end_browse_store_order_submission;
        }

        if ($product['category'] === 'catering_service') {
            $required_guests = max((int) ($product['minimum_booking_guests'] ?? 1), (int) ($pricing['selected_set_pax'] ?? 0));
            $maximum_guests = (int) ($product['maximum_booking_guests'] ?? 100000);
            if ($quantity > $maximum_guests) {
                $error_message = "This caterer accepts at most $maximum_guests guests per booking.";
                goto end_browse_store_order_submission;
            }
            if ($quantity < $required_guests) {
                $error_message = "This booking requires at least $required_guests guests.";
                goto end_browse_store_order_submission;
            }
        }

        if ($product['category'] === 'catering_service' && !empty($pricing['selected_set_name'])) {
            if (!empty($pricing['selected_set_pax'])) {
                $package_details = "Package: " . $pricing['selected_set_name'] . " (?" . number_format($pricing['selected_set_price'], 2) . " Good for " . intval($pricing['selected_set_pax']) . " person)";
            } else {
                $package_details = "Package: " . $pricing['selected_set_name'] . " (?" . number_format($pricing['price_per_unit'], 2) . " per pax)";
            }
        }
        if ($product['category'] === 'catering_service') {
            $required_guests = max((int) ($product['minimum_booking_guests'] ?? 1), (int) ($pricing['selected_set_pax'] ?? 0));
            $maximum_guests = (int) ($product['maximum_booking_guests'] ?? 100000);
            if ($quantity > $maximum_guests) {
                $error_message = "This caterer accepts at most $maximum_guests guests per booking.";
                goto end_browse_store_order_submission;
            }
            if ($quantity < $required_guests) {
                $error_message = "This booking requires at least $required_guests guests.";
                goto end_browse_store_order_submission;
            }
        }

        if ($product['category'] === 'catering_service' && !empty($pricing['selected_set_name'])) {
            if (($pricing['pricing_mode'] ?? '') === 'by_pax') {
                $package_details = "Package: " . $pricing['selected_set_name'] . " (PHP " . number_format($pricing['price_per_unit'], 2) . " per pax)";
            } elseif (!empty($pricing['selected_set_pax'])) {
                $package_details = "Package: " . $pricing['selected_set_name'] . " (PHP " . number_format($pricing['selected_set_price'], 2) . " Package Set, good for " . intval($pricing['selected_set_pax']) . " person)";
            } else {
                $package_details = "Package: " . $pricing['selected_set_name'] . " (PHP " . number_format($pricing['selected_set_price'], 2) . " Package Set)";
            }
        }

        $addons_total = bookingPricingRound($pricing['addons_total'] ?? 0);
        $total_amount = bookingPricingRound($pricing['total_amount'] ?? 0);
        $base_price_for_order = bookingPricingRound($pricing['base_price'] ?? 0);
        $down_payment_required = bookingPricingRound($pricing['down_payment_required'] ?? 0);
        $package_id_for_order = isset($pricing['selected_set_id']) && intval($pricing['selected_set_id']) > 0 ? intval($pricing['selected_set_id']) : null;
        $package_name_for_order = trim((string) ($pricing['selected_set_name'] ?? $selected_set_name));
        $package_price_for_order = bookingPricingRound(($pricing['pricing_mode'] ?? '') === 'by_pax'
            ? ($pricing['price_per_unit'] ?? 0)
            : (($pricing['selected_set_price'] ?? 0) > 0 ? $pricing['selected_set_price'] : ($pricing['product_price'] ?? $product['price'])));

        // 5. Append details to Notes
        $notes = $package_details . "\n\nUser Notes: " . $notes;
        $notes = mysqli_real_escape_string($conn, $notes);

        $order_number = 'ORD-' . strtoupper(uniqid());
        $status = 'pending';

        $customer_query = mysqli_query($conn, "SELECT first_name, last_name, phone_number, address FROM users WHERE id = '$user_id' LIMIT 1");
        $customer_data = $customer_query ? mysqli_fetch_assoc($customer_query) : null;
        $full_name = trim(($customer_data['first_name'] ?? '') . ' ' . ($customer_data['last_name'] ?? ''));
        $contact_number = $customer_data['phone_number'] ?? '';
        $fallback_address = $customer_data['address'] ?? '';
        $final_address = $delivery_address !== '' ? $delivery_address : $fallback_address;
        $additional_menu = $selected_set_name !== '' ? $selected_set_name : null;
        
        // Handle Screenshot Upload
        $payment_screenshot = '';
        if (!empty($_FILES['payment_screenshot']['name'])) {
            $target_dir = "../uploads/receipts/";
            if (!file_exists($target_dir)) {
                mkdir($target_dir, 0777, true);
            }
            $file_extension = pathinfo($_FILES["payment_screenshot"]["name"], PATHINFO_EXTENSION);
            $new_filename = "receipt_" . $order_number . "." . $file_extension;
            $target_file = $target_dir . $new_filename;
            
            if (move_uploaded_file($_FILES["payment_screenshot"]["tmp_name"], $target_file)) {
                $payment_screenshot = "uploads/receipts/" . $new_filename;
            }
        }

        // Build insert query dynamically based on existing columns
        $columns = [];
        $values = [];
        
        // Get all columns from bookings table
        $table_cols = [];
        $col_result = mysqli_query($conn, "SHOW COLUMNS FROM bookings");
        while ($col = mysqli_fetch_assoc($col_result)) {
            $table_cols[] = $col['Field'];
        }
        
        // Build columns and values based on what exists in the table
        if (in_array('order_number', $table_cols)) { $columns[] = 'order_number'; $values[] = "'$order_number'"; }
        if (in_array('customer_id', $table_cols)) { $columns[] = 'customer_id'; $values[] = "'$user_id'"; }
        if (in_array('store_id', $table_cols)) { $columns[] = 'store_id'; $values[] = "'$store_id_post'"; }
        if (in_array('product_id', $table_cols)) { $columns[] = 'product_id'; $values[] = "'$product_id'"; }
        if (in_array('package_id', $table_cols)) { $columns[] = 'package_id'; $values[] = $package_id_for_order !== null ? "'$package_id_for_order'" : "NULL"; }
        if (in_array('package_name', $table_cols)) { $columns[] = 'package_name'; $values[] = $package_name_for_order !== '' ? "'" . mysqli_real_escape_string($conn, $package_name_for_order) . "'" : "NULL"; }
        if (in_array('package_price', $table_cols)) { $columns[] = 'package_price'; $values[] = "'$package_price_for_order'"; }
        if (in_array('quantity', $table_cols)) { $columns[] = 'quantity'; $values[] = "'$quantity'"; }
        if (in_array('number_of_pax', $table_cols)) { $columns[] = 'number_of_pax'; $values[] = "'$quantity'"; }
        if (in_array('total_amount', $table_cols)) { $columns[] = 'total_amount'; $values[] = "'$total_amount'"; }
        if (in_array('order_date', $table_cols)) { $columns[] = 'order_date'; $values[] = "'$delivery_date'"; }
        if (in_array('order_time', $table_cols)) { $columns[] = 'order_time'; $values[] = "'$delivery_time'"; }
        if (in_array('delivery_time', $table_cols)) { $columns[] = 'delivery_time'; $values[] = "'$delivery_time'"; }
        if (in_array('full_name', $table_cols)) { $columns[] = 'full_name'; $values[] = "'" . mysqli_real_escape_string($conn, $full_name) . "'"; }
        if (in_array('contact_number', $table_cols)) { $columns[] = 'contact_number'; $values[] = "'" . mysqli_real_escape_string($conn, $contact_number) . "'"; }
        if (in_array('delivery_type', $table_cols)) { $columns[] = 'delivery_type'; $values[] = "'delivery'"; }
        // Address column handling - check for both column names
        $address_saved = false;
        if (in_array('delivery_address', $table_cols)) { 
            $columns[] = 'delivery_address'; 
            $values[] = "'" . mysqli_real_escape_string($conn, $final_address) . "'"; 
            $address_saved = true;
        } 
        if (in_array('address', $table_cols)) { 
            $columns[] = 'address'; 
            $values[] = "'" . mysqli_real_escape_string($conn, $final_address) . "'"; 
            $address_saved = true;
        }
        if (in_array('base_price', $table_cols)) { $columns[] = 'base_price'; $values[] = "'$base_price_for_order'"; }
        if (in_array('down_payment_required', $table_cols)) { $columns[] = 'down_payment_required'; $values[] = "'$down_payment_required'"; }
        if (in_array('addons_total', $table_cols)) { $columns[] = 'addons_total'; $values[] = "'$addons_total'"; }
        if (in_array('extra_fee', $table_cols)) { $columns[] = 'extra_fee'; $values[] = "'0.00'"; }
        if (in_array('additional_menu', $table_cols)) { $columns[] = 'additional_menu'; $values[] = $additional_menu !== null ? "'" . mysqli_real_escape_string($conn, $additional_menu) . "'" : "NULL"; }
        if (in_array('payment_method', $table_cols)) { $columns[] = 'payment_method'; $values[] = "'$payment_method'"; }
        if (in_array('notes', $table_cols)) { $columns[] = 'notes'; $values[] = "'$notes'"; }
        if (in_array('order_status', $table_cols)) { $columns[] = 'order_status'; $values[] = "'$status'"; }
        if (in_array('payment_screenshot', $table_cols)) { $columns[] = 'payment_screenshot'; $values[] = "'$payment_screenshot'"; }
        
        $insert_query = "INSERT INTO bookings (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $values) . ")";

        $booking_claimed = claimBookingSubmission('order', (int) $user_id, [
            'store_id' => (int) $store_id_post,
            'product_id' => (int) $product_id,
            'delivery_date' => $delivery_date,
            'delivery_time' => $delivery_time,
            'quantity' => $quantity,
            'total' => $total_amount,
        ]);
        if (!$booking_claimed) {
            $error_message = 'This booking was already submitted. Please check your bookings before trying again.';
            goto end_browse_store_order_submission;
        }

        if (mysqli_query($conn, $insert_query)) {
            $success_message = "Booking placed successfully! Booking #$order_number";
            
            // Get customer name and product name for notification
            $customer_query = mysqli_query($conn, "SELECT first_name, last_name FROM users WHERE id = '$user_id'");
            $customer = mysqli_fetch_assoc($customer_query);
            $customer_name = $customer['first_name'] . ' ' . $customer['last_name'];
            
            $product_query = mysqli_query($conn, "SELECT name FROM products WHERE id = '$product_id'");
            $product = mysqli_fetch_assoc($product_query);
            $product_name = $product ? $product['name'] : 'Product';
            
            // Notify caterer about new order
            createNotification(
                $store_id_post,
                'new_order',
                'New Booking #' . $order_number,
                $customer_name . ' ordered ' . $product_name . ' (?' . number_format($total_amount, 2) . ')',
                'reservations.php'
            );

            if (function_exists('notifyPortalAdmins')) {
                notifyPortalAdmins(
                    'new_order',
                    'New Booking Placed',
                    $customer_name . ' placed Booking #' . $order_number . ' for ' . $product_name . '.',
                    'reports.php'
                );
            }
        } else {
            appLogError('Browse caterer order insert failed', mysqli_error($conn));
            $error_message = "Unable to place your booking right now. Please review your details and try again.";
        }
    }
}
end_browse_store_order_submission:
// ==============================================
// END OF ORDER FORM SUBMISSION HANDLING
// ==============================================

$page_title = empty($store_id) ? 'Browse Caterers' : 'Caterer Details';
require_once __DIR__ . '/includes/header.php';
?>

<style>
    .caterer-detail-card {
        margin-bottom: 18px;
        overflow: hidden;
    }

    .caterer-hero {
        position: relative;
        min-height: 180px;
        overflow: hidden;
        background: #151515;
    }

    .caterer-hero-bg,
    .caterer-hero-overlay {
        position: absolute;
        inset: 0;
    }

    .caterer-hero-badge {
        position: absolute;
        top: 9px;
        right: 9px;
        z-index: 2;
        background: linear-gradient(135deg, var(--accent-gold), #f4d03f);
        color: #000;
        padding: 4px 9px;
        border-radius: 20px;
        font-weight: 700;
        font-size: 10px;
        display: inline-flex;
        align-items: center;
        gap: 5px;
        box-shadow: 0 2px 10px rgba(212, 175, 55, 0.4);
    }

    .caterer-hero-content {
        position: absolute;
        left: 16px;
        right: 16px;
        bottom: 11px;
        z-index: 2;
        display: flex;
        align-items: flex-end;
        justify-content: space-between;
        gap: 12px;
    }

    .caterer-hero-main {
        display: flex;
        align-items: flex-end;
        gap: 10px;
        min-width: 0;
        flex: 1;
    }

    .caterer-profile-image {
        width: 58px;
        height: 58px;
        border-radius: 50%;
        border: 2px solid var(--accent-gold);
        object-fit: cover;
        background: #000;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.3);
        flex-shrink: 0;
    }

    .caterer-hero-info {
        min-width: 0;
    }

    .caterer-title-row {
        display: flex;
        align-items: center;
        gap: 5px;
        margin-bottom: 2px;
        flex-wrap: wrap;
    }

    .caterer-title {
        color: white;
        font-size: 18px;
        margin: 0;
        line-height: 1.1;
        word-break: break-word;
    }

    .caterer-meta-line {
        color: #fff;
        display: flex;
        align-items: center;
        gap: 5px;
        margin: 0 0 3px;
        font-weight: 600;
        font-size: 11px;
        text-shadow: 0 1px 3px rgba(0, 0, 0, 0.8);
        flex-wrap: wrap;
    }

    .caterer-meta-line.hours {
        font-size: 10.5px;
        margin-bottom: 5px;
    }

    .caterer-hours-status-open {
        color: #2ecc71;
        font-weight: 700;
    }

    .caterer-hours-status-closed {
        color: #e74c3c;
        font-weight: 700;
    }

    .caterer-hours-range {
        color: #ccc;
        font-weight: 500;
    }

    .caterer-stat-list {
        display: flex;
        gap: 5px;
        align-items: center;
        flex-wrap: wrap;
    }

    .caterer-stat-pill {
        background: rgba(0, 0, 0, 0.6);
        padding: 3px 7px;
        border-radius: 20px;
        display: inline-flex;
        align-items: center;
        gap: 5px;
        min-height: 23px;
    }

    .caterer-stat-pill span {
        font-size: 10px !important;
    }

    .caterer-title-row > i {
        font-size: 15px !important;
    }

    .caterer-stat-pill.rating {
        border: 1px solid rgba(212, 175, 55, 0.3);
    }

    .caterer-message-btn {
        background: var(--accent-gold);
        color: #000;
        padding: 7px 15px;
        border-radius: 25px;
        text-decoration: none;
        font-weight: 700;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        transition: all 0.3s;
        font-size: 11px;
        white-space: nowrap;
    }

    .caterer-message-btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(212, 175, 55, 0.4);
    }

    .caterer-detail-description {
        padding: 10px 16px;
    }

    .caterer-hero .favorite-btn {
        top: 9px !important;
        right: 9px !important;
        width: 32px !important;
        height: 32px !important;
    }

    .caterer-hero .favorite-btn i {
        font-size: 15px !important;
    }

    .caterer-hero:has(.caterer-hero-badge) .favorite-btn {
        top: 40px !important;
    }

    .caterer-section-nav {
        position: static;
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
        align-items: center;
        margin: 18px 0 0;
        padding: 12px 0 0;
        background: transparent;
        border: 0;
        border-top: 1px solid var(--border-color);
        border-radius: 0;
    }

    .soft-sage-theme .customer-page-shell .caterer-detail-card .caterer-section-nav {
        background: transparent !important;
    }

    .caterer-section-nav a {
        display: inline-flex;
        white-space: nowrap;
        flex-shrink: 0;
        align-items: center;
        gap: 8px;
        padding: 9px 14px;
        border-radius: 6px;
        color: var(--text-main);
        border: 1px solid transparent;
        background: rgba(255, 255, 255, 0.035);
        text-decoration: none;
        font-size: 13px;
        font-weight: 700;
    }

    .caterer-section-nav a:hover {
        color: var(--accent-gold);
        border-color: rgba(212, 179, 90, 0.32);
    }

    .caterer-section-nav a.active {
        color: #000;
        background: var(--accent-gold);
        border-color: var(--accent-gold);
    }

    .caterer-tab-panel {
        display: none;
    }

    .caterer-tab-panel.active {
        display: block;
    }

    .menu-category-shell {
        margin: 0 0 50px;
    }

    .menu-category-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
        margin-bottom: 18px;
        flex-wrap: wrap;
    }

    .menu-category-pills {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
    }

    .menu-category-pill {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 6px 10px;
        border: 1px solid var(--border-color);
        border-radius: 999px;
        color: var(--text-muted);
        text-decoration: none;
        font-size: 12px;
        background: rgba(255, 255, 255, 0.035);
    }

    .menu-category-pill:hover {
        border-color: var(--accent-gold);
        color: var(--accent-gold);
    }

    .menu-category-block {
        margin-top: 22px;
    }

    .menu-category-title {
        color: var(--text-main);
        font-size: 18px;
        margin-bottom: 12px;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .uploaded-menu-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
        gap: 12px;
    }

    .uploaded-menu-item {
        background: rgba(255, 255, 255, 0.04);
        border: 1px solid var(--border-color);
        border-radius: 8px;
        padding: 14px;
        display: flex;
        flex-direction: column;
        gap: 8px;
        min-height: 150px;
    }

    .uploaded-menu-name {
        color: var(--text-main);
        font-weight: 700;
        font-size: 15px;
        line-height: 1.3;
    }

    .uploaded-menu-meta {
        color: var(--text-muted);
        font-size: 12px;
        line-height: 1.45;
    }

    .uploaded-menu-price {
        color: var(--accent-gold);
        font-weight: 700;
        font-size: 13px;
        margin-top: auto;
    }

    .available-menu-container {
        background: rgba(255, 255, 255, 0.035);
        border: 1px solid var(--border-color);
        border-radius: 8px;
        padding: 14px;
    }

    .available-menu-list {
        display: flex;
        flex-direction: column;
        gap: 14px;
    }

    .available-menu-set {
        width: 100%;
        border: 0;
        border-left: 4px solid var(--accent-gold);
        border-radius: 8px;
        padding: 18px 18px 20px;
        background: var(--card-bg);
        color: var(--text-main);
        text-align: left;
        cursor: default;
        transition: background 0.2s ease, transform 0.2s ease;
    }

    .available-menu-set:hover {
        background: rgba(212, 179, 90, 0.08);
        transform: translateY(-1px);
    }

    .available-menu-set-head {
        display: flex;
        justify-content: space-between;
        gap: 18px;
        margin-bottom: 18px;
    }

    .available-menu-set-name {
        color: #fff;
        font-weight: 800;
        font-size: 15px;
    }

    .available-menu-set-price {
        color: var(--accent-gold);
        font-weight: 800;
        text-align: right;
        line-height: 1.5;
        white-space: nowrap;
    }

    .available-menu-lines {
        display: grid;
        gap: 9px;
        color: var(--text-main);
        font-size: 13px;
        line-height: 1.5;
    }

    .available-menu-lines strong {
        color: var(--accent-gold);
    }

    @media (max-width: 768px) {
        .caterer-hero {
            min-height: 0;
            padding-top: 58px;
        }

        .caterer-hero-badge {
            top: 12px;
            right: 12px;
            max-width: calc(100% - 24px);
        }

        .caterer-hero-content {
            position: relative;
            left: auto;
            right: auto;
            bottom: auto;
            padding: 0 16px 16px;
            flex-direction: column;
            align-items: stretch;
            gap: 12px;
        }

        .caterer-hero-main {
            flex-direction: column;
            align-items: flex-start;
            gap: 10px;
        }

        .caterer-profile-image {
            width: 58px;
            height: 58px;
        }

        .caterer-title {
            font-size: 18px;
        }

        .caterer-stat-list {
            gap: 8px;
        }


        .caterer-stat-pill {
            max-width: 100%;
        }

        .caterer-message-btn {
            width: 100%;
            white-space: normal;
        }

        .caterer-detail-description {
            padding: 10px 16px 12px;
        }
    }

    @media (max-width: 480px) {
        .caterer-hero {
            padding-top: 62px;
        }

        .caterer-hero-badge {
            font-size: 11px;
            padding: 6px 12px;
        }

        .caterer-title {
            font-size: 17px;
        }

        .caterer-meta-line,
        .caterer-meta-line.hours {
            font-size: 13px;
        }

        .caterer-stat-list {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            width: 100%;
        }

        .caterer-stat-pill {
            width: 100%;
            justify-content: center;
            padding: 8px 10px;
        }

    }

    .catering-service-list {
        display: flex;
        flex-direction: column;
        gap: 22px;
    }

    .catering-service-block {
        background: var(--card-bg);
        border: 1px solid var(--border-color);
        border-radius: 16px;
        overflow: hidden;
    }

    .catering-service-head {
        display: grid;
        grid-template-columns: 180px 1fr;
        gap: 18px;
        padding: 18px;
        align-items: center;
        border-bottom: 1px solid var(--border-color);
    }

    .catering-service-head img {
        width: 100%;
        height: 120px;
        object-fit: cover;
        border-radius: 12px;
        border: 1px solid var(--border-color);
    }

    .catering-service-head h3 {
        margin: 0 0 6px;
        font-size: 20px;
        color: var(--text-main);
    }

    .catering-service-head p {
        margin: 0 0 10px;
        color: var(--text-muted);
        font-size: 13px;
        line-height: 1.5;
    }

    .catering-service-from {
        color: var(--accent-gold);
        font-weight: 700;
        font-size: 15px;
    }

    .catering-service-inclusions {
        margin-top: 10px;
        padding: 10px 12px;
        background: rgba(212, 175, 55, 0.08);
        border: 1px solid rgba(212, 175, 55, 0.2);
        border-radius: 8px;
        font-size: 13px;
        color: var(--text-main);
        line-height: 1.5;
    }

    .catering-service-inclusions strong {
        color: var(--accent-gold);
        font-size: 12px;
        text-transform: uppercase;
        letter-spacing: 0.3px;
        display: block;
        margin-bottom: 4px;
    }

    .catering-package-inclusions,
    .catering-package-menu-preview {
        display: block;
        margin-top: 5px;
        font-size: 12px;
        color: var(--text-muted);
        line-height: 1.45;
    }

    .catering-package-menu-preview strong,
    .catering-package-inclusions strong {
        color: #c9b87a;
        font-weight: 600;
    }

    .catering-package-list {
        display: flex;
        flex-direction: column;
    }

    .catering-package-row {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 16px;
        padding: 14px 18px;
        border-top: 1px solid rgba(255,255,255,0.04);
    }

    .catering-package-row:first-child {
        border-top: 0;
    }

    .catering-package-info {
        min-width: 0;
        display: flex;
        align-items: baseline;
        gap: 12px;
    }

    .catering-package-info strong {
        display: block;
        font-size: 15px;
        color: var(--text-main);
        margin-bottom: 0;
    }

    .catering-package-info span {
        color: var(--accent-gold);
        font-size: 13px;
        font-weight: 600;
    }

    .catering-package-actions {
        display: flex;
        gap: 8px;
        flex-shrink: 0;
    }

    .catering-package-actions .service-set-toggle {
        margin-top: 0;
        min-height: 40px;
        padding: 8px 14px;
    }

    .catering-package-actions .btn-order-mini {
        min-height: 40px;
        min-width: 150px;
        background: var(--accent-gold);
        color: #111;
        border: none;
        padding: 8px 14px;
        border-radius: 8px;
        font-weight: 700;
        font-size: 13px;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
    }

    @media (max-width: 720px) {
        .catering-service-head {
            grid-template-columns: 1fr;
        }

        .catering-package-row {
            flex-direction: column;
            align-items: stretch;
        }

        .catering-package-actions {
            width: 100%;
        }

        .catering-package-actions .btn-order-mini,
        .catering-package-actions .service-set-toggle {
            flex: 1;
        }
    }

    /* Service card sets preview */
    .service-sets-preview {
        margin: 12px 0;
        padding-top: 12px;
        border-top: 1px dashed var(--border-color);
    }

    .service-sets-count {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        font-size: 12px;
        color: var(--text-muted);
        margin-bottom: 10px;
        background: rgba(212, 179, 90, 0.08);
        padding: 4px 10px;
        border-radius: 999px;
    }

    .service-sets-list {
        display: flex;
        flex-direction: column;
        gap: 10px;
    }

    .service-set-mini {
        background: rgba(255, 255, 255, 0.035);
        border: 1px solid var(--border-color);
        border-radius: 8px;
        padding: 12px;
    }

    .service-set-mini-head {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 10px;
        margin-bottom: 8px;
    }

    .service-set-mini-name {
        font-weight: 700;
        font-size: 14px;
        color: var(--text-main);
    }

    .service-set-mini-price {
        color: var(--accent-gold);
        font-weight: 700;
        font-size: 13px;
        text-align: right;
        white-space: nowrap;
        line-height: 1.4;
    }

    .service-set-mini-menu {
        display: grid;
        gap: 6px;
        font-size: 12px;
        color: var(--text-main);
        line-height: 1.45;
    }

    .service-set-mini-menu strong {
        color: var(--accent-gold);
        font-weight: 600;
    }

    .service-set-toggle {
        background: transparent;
        border: 1px solid var(--border-color);
        color: var(--text-muted);
        font-size: 12px;
        padding: 6px 10px;
        border-radius: 6px;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 5px;
        margin-top: 8px;
        transition: all 0.2s;
    }

    .service-set-toggle:hover {
        border-color: var(--accent-gold);
        color: var(--accent-gold);
    }

    .service-set-actions {
        display: flex;
        gap: 8px;
        margin-top: 10px;
        flex-wrap: wrap;
    }

    .service-set-actions .btn-order-mini {
        flex: 1;
        min-width: 100px;
        background: var(--accent-gold);
        color: #000;
        border: none;
        padding: 8px 10px;
        border-radius: 6px;
        font-weight: 600;
        font-size: 12px;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 4px;
        text-decoration: none;
        transition: all 0.2s;
    }

    .service-set-actions .btn-order-mini:hover {
        background: var(--accent-gold-hover);
    }

    /* View Menu Modal */
    .view-menu-modal {
        display: none;
        position: fixed;
        inset: 0;
        z-index: 9999;
        align-items: center;
        justify-content: center;
        padding: 20px;
        background: rgba(0, 0, 0, 0.78);
        backdrop-filter: blur(5px);
    }

    .view-menu-modal.active {
        display: flex;
    }

    .view-menu-dialog {
        background: var(--card-bg, #1a1f2c);
        border: 1px solid rgba(212, 175, 55, 0.22);
        border-radius: 16px;
        width: 100%;
        max-width: 840px;
        max-height: 90vh;
        overflow: hidden;
        box-shadow: 0 24px 60px rgba(0, 0, 0, 0.55);
        display: flex;
        flex-direction: column;
    }

    .view-menu-head {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 16px;
        padding: 20px 24px;
        border-bottom: 1px solid var(--border-color, rgba(255, 255, 255, 0.08));
        background: rgba(255, 255, 255, 0.015);
    }

    .view-menu-title {
        font-size: 22px;
        font-weight: 700;
        color: #fff;
        margin: 0;
        line-height: 1.3;
    }

    .view-menu-price-row {
        display: flex;
        align-items: center;
        flex-wrap: wrap;
        gap: 10px;
        margin-top: 8px;
    }

    .view-menu-price-badge {
        color: var(--accent-gold, #d4af37);
        font-size: 18px;
        font-weight: 800;
        letter-spacing: -0.2px;
    }

    .view-menu-pax-tag {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        background: rgba(255, 255, 255, 0.06);
        border: 1px solid rgba(255, 255, 255, 0.1);
        color: #e0e0e0;
        padding: 3px 10px;
        border-radius: 20px;
        font-size: 12.5px;
        font-weight: 600;
    }

    .view-menu-extra {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        margin-top: 6px;
        color: #e5c875;
        font-size: 12.5px;
        font-weight: 500;
        opacity: 0.95;
    }

    .view-menu-close {
        background: rgba(255, 255, 255, 0.06);
        border: 1px solid var(--border-color, rgba(255, 255, 255, 0.1));
        color: var(--text-muted, #a0aec0);
        width: 34px;
        height: 34px;
        border-radius: 8px;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        transition: all 0.2s ease;
        flex-shrink: 0;
    }

    .view-menu-close:hover {
        background: rgba(231, 76, 60, 0.15);
        border-color: rgba(231, 76, 60, 0.4);
        color: #e74c3c;
    }

    .view-menu-body {
        padding: 20px 24px;
        overflow-y: auto;
        display: flex;
        flex-direction: column;
        gap: 18px;
        max-height: calc(90vh - 150px);
    }

    .view-menu-body::-webkit-scrollbar {
        width: 6px;
    }

    .view-menu-body::-webkit-scrollbar-track {
        background: rgba(255, 255, 255, 0.02);
    }

    .view-menu-body::-webkit-scrollbar-thumb {
        background: rgba(255, 255, 255, 0.15);
        border-radius: 4px;
    }

    .view-menu-body::-webkit-scrollbar-thumb:hover {
        background: rgba(212, 175, 55, 0.4);
    }

    .view-menu-inclusions-card {
        background: rgba(255, 255, 255, 0.02);
        border: 1px solid rgba(255, 255, 255, 0.07);
        border-radius: 12px;
        padding: 14px 16px;
    }

    .view-menu-inclusions-header {
        color: var(--accent-gold, #d4af37);
        font-weight: 700;
        font-size: 13px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        display: flex;
        align-items: center;
        gap: 8px;
        margin-bottom: 10px;
    }

    .view-menu-inclusions-header i {
        color: #2ecc71;
    }

    .view-menu-inclusions-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
        gap: 8px 14px;
    }

    .view-menu-inclusion-item {
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 13px;
        color: var(--text-main, #e2e8f0);
        line-height: 1.4;
    }

    .view-menu-inclusion-item i {
        color: #2ecc71;
        font-size: 11px;
        flex-shrink: 0;
    }

    .view-menu-section-header {
        display: flex;
        align-items: center;
        gap: 8px;
        color: var(--accent-gold, #d4af37);
        font-weight: 700;
        font-size: 13px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        margin-top: 2px;
    }

    .view-menu-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 14px;
    }

    .view-menu-category-card {
        background: rgba(255, 255, 255, 0.025);
        border: 1px solid rgba(255, 255, 255, 0.07);
        border-radius: 12px;
        padding: 12px 14px;
        display: flex;
        flex-direction: column;
        transition: border-color 0.2s ease;
    }

    .view-menu-category-card:hover {
        border-color: rgba(212, 175, 55, 0.25);
    }

    .view-menu-category-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
        padding-bottom: 8px;
        margin-bottom: 8px;
        border-bottom: 1px solid rgba(255, 255, 255, 0.05);
    }

    .view-menu-category-title-wrap {
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .view-menu-category-icon {
        color: var(--accent-gold, #d4af37);
        font-size: 13px;
        opacity: 0.9;
    }

    .view-menu-category-title {
        color: #fff;
        font-size: 13px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.4px;
    }

    .view-menu-category-count {
        font-size: 11px;
        color: var(--text-muted, #a0aec0);
        background: rgba(255, 255, 255, 0.05);
        padding: 2px 7px;
        border-radius: 10px;
        font-weight: 500;
    }

    .view-menu-items-list {
        list-style: none;
        margin: 0;
        padding: 0;
        display: flex;
        flex-direction: column;
        gap: 6px;
    }

    .view-menu-item-row {
        display: flex;
        align-items: flex-start;
        gap: 8px;
        padding: 5px 6px;
        border-radius: 6px;
        transition: background 0.15s ease;
    }

    .view-menu-item-row:hover {
        background: rgba(255, 255, 255, 0.03);
    }

    .view-menu-item-dot {
        width: 5px;
        height: 5px;
        border-radius: 50%;
        background: var(--accent-gold, #d4af37);
        margin-top: 6px;
        flex-shrink: 0;
        opacity: 0.8;
    }

    .view-menu-item-content {
        display: flex;
        align-items: baseline;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 4px 8px;
        width: 100%;
    }

    .view-menu-dish-name {
        color: var(--text-main, #e2e8f0);
        font-size: 13.5px;
        font-weight: 500;
        line-height: 1.4;
    }

    .view-menu-dish-portion {
        font-size: 11.5px;
        color: #e5c875;
        background: rgba(212, 175, 55, 0.1);
        border: 1px solid rgba(212, 175, 55, 0.2);
        padding: 1px 6px;
        border-radius: 10px;
        font-weight: 500;
        white-space: nowrap;
    }

    .view-menu-empty {
        color: var(--text-muted, #a0aec0);
        font-size: 14px;
        text-align: center;
        padding: 24px 0;
    }

    .view-menu-foot {
        padding: 16px 24px 20px;
        border-top: 1px solid var(--border-color, rgba(255, 255, 255, 0.08));
        background: rgba(0, 0, 0, 0.15);
    }

    .view-menu-foot .btn-order-mini {
        width: 100%;
        padding: 12px 20px;
        font-size: 14.5px;
        font-weight: 700;
        border-radius: 10px;
        background: var(--accent-gold, #d4af37);
        color: #1a1500;
        border: none;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        transition: all 0.2s ease;
        box-shadow: 0 4px 14px rgba(212, 175, 55, 0.25);
    }

    .view-menu-foot .btn-order-mini:hover {
        background: #e5b93b;
        transform: translateY(-1px);
        box-shadow: 0 6px 18px rgba(212, 175, 55, 0.35);
    }

    @media (max-width: 760px) {
        .view-menu-dialog {
            max-height: 92vh;
        }

        .view-menu-grid {
            grid-template-columns: 1fr;
        }

        .view-menu-inclusions-grid {
            grid-template-columns: 1fr;
        }

        .view-menu-head,
        .view-menu-body,
        .view-menu-foot {
            padding-left: 16px;
            padding-right: 16px;
        }
    }

    @media (max-width: 480px) {
        .view-menu-modal {
            padding: 10px;
        }

        .view-menu-dialog {
            border-radius: 12px;
        }
    }

    /* Keep the browse controls inside the phone viewport. */
    @media (max-width: 768px) {
        .main-content > .section-header {
            align-items: stretch;
            flex-direction: column;
            gap: 14px;
        }

        .main-content > .section-header > div {
            display: flex !important;
            width: 100%;
            gap: 10px !important;
            align-items: stretch !important;
            flex-wrap: wrap;
        }

        .main-content > .section-header .search-box {
            flex: 1 1 220px;
            width: auto !important;
            min-width: 0;
        }

        .main-content > .section-header .search-box form,
        .main-content > .section-header .form-control {
            width: 100%;
        }

        .main-content > .section-header > div > form:not(:first-child) {
            flex: 1 1 145px;
        }

        .main-content > .section-header > div > form:not(:first-child) .btn {
            width: 100%;
            justify-content: center;
        }
    }

    @media (max-width: 420px) {
        .main-content > .section-header > div > form:not(:first-child) {
            flex-basis: 100%;
        }
    }
</style>

<!-- Content -->
<div class="main-content">
    <?php if (isset($success_message)): ?>
        <div class="alert alert-success" style="background: rgba(76, 175, 80, 0.1); color: #4caf50; padding: 15px; border-radius: 8px; margin-bottom: 20px; border: 1px solid #4caf50;">
            <i class="fas fa-check-circle"></i> <?php echo $success_message; ?>
        </div>
    <?php endif; ?>

    <?php if (isset($error_message) && $error_message === 'This caterer is currently closed and is not accepting bookings right now.'): ?>
        <?php require __DIR__ . '/includes/caterer-closed-modal.php'; ?>
    <?php elseif (isset($error_message)): ?>
        <div class="alert alert-error" style="background: rgba(244, 67, 54, 0.1); color: #f44336; padding: 15px; border-radius: 8px; margin-bottom: 20px; border: 1px solid #f44336;">
            <i class="fas fa-exclamation-circle"></i> <?php echo $error_message; ?>
        </div>
    <?php endif; ?>

    <?php if (empty($store_id)): ?>
        <!-- ==================== LIST VIEW ==================== -->
        <?php
        $search = isset($_GET['search']) ? mysqli_real_escape_string($conn, $_GET['search']) : '';
        $category = isset($_GET['category']) ? mysqli_real_escape_string($conn, $_GET['category']) : '';
        $event_name_suggestions = [];
        $event_suggestions_query = "
            SELECT DISTINCT p.name
            FROM products p
            INNER JOIN users u ON p.store_id = u.id
            INNER JOIN store_details sd ON u.id = sd.user_id
            WHERE p.is_available = 1
              AND p.category = 'catering_service'
              AND u.role IN ('caterer','store')
              AND u.status = 'active'
              AND sd.approval_status = 'approved'
            ORDER BY p.name ASC
            LIMIT 30
        ";
        $event_suggestions_result = mysqli_query($conn, $event_suggestions_query);
        if ($event_suggestions_result) {
            while ($event_row = mysqli_fetch_assoc($event_suggestions_result)) {
                if (!empty($event_row['name'])) {
                    $event_name_suggestions[] = $event_row['name'];
                }
            }
        }
        
        // Search only registered catering event/service names.
        $matching_store_ids = [];
        
        if (!empty($search)) {
            $product_ids_query = "
                SELECT DISTINCT p.store_id 
                FROM products p 
                INNER JOIN users u ON p.store_id = u.id 
                INNER JOIN store_details sd ON u.id = sd.user_id 
                WHERE p.is_available = 1 AND u.role IN ('caterer','store') AND u.status = 'active' AND sd.approval_status = 'approved'
                AND p.category = 'catering_service'
                AND p.name LIKE '%$search%'
            ";
            $product_ids_result = mysqli_query($conn, $product_ids_query);
            if ($product_ids_result) {
                while($row = mysqli_fetch_assoc($product_ids_result)) {
                    if (!in_array($row['store_id'], $matching_store_ids)) {
                        $matching_store_ids[] = $row['store_id'];
                    }
                }
            }
        }
        
        // Get user's favorite stores
        $user_favorites = [];
        if (isset($_SESSION['user_id'])) {
            $user_id = $_SESSION['user_id'];
            $favorites_query = "SELECT store_id FROM customer_favorites WHERE customer_id = '$user_id'";
            $favorites_result = mysqli_query($conn, $favorites_query);
            if ($favorites_result) {
                while ($row = mysqli_fetch_assoc($favorites_result)) {
                    $user_favorites[] = $row['store_id'];
                }
            }
        }
        
        // Build the main query
        $show_favorites = isset($_GET['favorites']) && $_GET['favorites'] == 1;
        
        if ($show_favorites) {
            // Only show favorite stores
            $where_clause = "u.id IN (SELECT store_id FROM customer_favorites WHERE customer_id = '$user_id')";
        } elseif (!empty($search) && !empty($matching_store_ids)) {
            $store_ids_list = implode(',', array_map('intval', $matching_store_ids));
            $where_clause = "u.id IN ($store_ids_list)";
        } elseif (!empty($search)) {
            $where_clause = "0 = 1";
        } else {
            $where_clause = "u.role IN ('caterer','store') AND u.status = 'active' AND sd.approval_status = 'approved'";
        }

        $stores_query = "
            SELECT u.id, u.profile_image, u.status, u.address,
                   sd.business_name, COALESCE(NULLIF(sd.location, ''), u.address) as location, sd.description, sd.is_open, 
                   sd.business_open_time, sd.business_close_time, sd.auto_schedule_enabled,
                   (SELECT COUNT(*) FROM products WHERE store_id = u.id AND is_available = 1) as product_count
            FROM users u 
            INNER JOIN store_details sd ON u.id = sd.user_id 
            WHERE $where_clause 
            AND sd.approval_status = 'approved'
            ORDER BY sd.business_name
        ";
        $stores_result = mysqli_query($conn, $stores_query);
        ?>

        <div class="section-header">
            <h1 class="section-title">Browse Caterers</h1>
            <div style="display: flex; gap: 15px; align-items: center;">
                <div class="search-box" style="position: relative; width: 300px;">
                    <form action="" method="GET">
                        <input type="text" name="search" class="form-control" placeholder="Search event type" value="<?php echo htmlspecialchars($search); ?>" list="browseEventNameSuggestions" style="padding-right: 40px;">
                        <datalist id="browseEventNameSuggestions">
                            <?php foreach ($event_name_suggestions as $event_name): ?>
                                <option value="<?php echo htmlspecialchars($event_name); ?>"></option>
                            <?php endforeach; ?>
                        </datalist>
                        <button type="submit" style="position: absolute; right: 10px; top: 50%; transform: translateY(-50%); background: none; border: none; color: var(--accent-gold); cursor: pointer;">
                            <i class="fas fa-search"></i>
                        </button>
                    </form>
                </div>
                <form action="" method="GET" style="margin: 0;">
                    <?php if (isset($_GET['favorites'])): ?>
                        <button type="submit" class="btn btn-outline" style="padding: 10px 20px; border-color: var(--accent-gold); color: var(--accent-gold); background: transparent;">
                            <i class="fas fa-arrow-left"></i> All Caterers
                        </button>
                    <?php else: ?>
                        <button type="submit" name="favorites" value="1" class="btn btn-outline" style="padding: 10px 20px; border-color: var(--accent-gold); color: var(--accent-gold); background: transparent;">
                            <i class="fas fa-star"></i> My Favorites
                        </button>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <div class="caterer-grid">
            <?php if ($stores_result && mysqli_num_rows($stores_result) > 0): ?>
                <?php while($caterer = mysqli_fetch_assoc($stores_result)): 
                    $is_favorite = in_array($caterer['id'], $user_favorites);
                ?>
                    <div class="caterer-card-wrapper" style="position: relative;">
                        <a href="browse-caterer.php?store_id=<?php echo $caterer['id']; ?>&source=browse" class="caterer-card" style="display: block; text-decoration: none;">
                            <img src="../<?php echo !empty($caterer['profile_image']) ? $caterer['profile_image'] : 'uploads/logo.png'; ?>" alt="Caterer" class="caterer-image">
                            <div class="caterer-content">
                                <h3 class="caterer-name"><?php echo htmlspecialchars($caterer['business_name']); ?></h3>
                                <div class="caterer-meta">
                                    <i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($caterer['location'] ?: 'Location N/A'); ?>
                                </div>

                                <div class="caterer-stats">
                                    <span><i class="fas fa-utensils"></i> <?php echo $caterer['product_count']; ?> Menu Items</span>
                                    <?php 
                                    // Initialize store_is_open
                                    $store_is_open = isStoreOpen($caterer['is_open'] ?? 0, $caterer['business_open_time'] ?? null, $caterer['business_close_time'] ?? null, $caterer['auto_schedule_enabled'] ?? 0);
                                    
                                    // Only show business hours if caterer has set them
                                    if (!empty($caterer['business_open_time']) && !empty($caterer['business_close_time'])):
                                        if($store_is_open): 
                                        ?>
                                        <span style="color: var(--success-color); white-space: nowrap;">Open (<?php echo date('g:i A', strtotime($caterer['business_open_time'])); ?> - <?php echo date('g:i A', strtotime($caterer['business_close_time'])); ?>)</span>
                                        <?php else: ?>
                                        <span style="color: var(--error-color); white-space: nowrap;">Closed (<?php echo date('g:i A', strtotime($caterer['business_open_time'])); ?> - <?php echo date('g:i A', strtotime($caterer['business_close_time'])); ?>)</span>
                                        <?php endif; 
                                    else:
                                        // No business hours set - just show Open/Closed status
                                        if($store_is_open): 
                                        ?>
                                        <span style="color: var(--success-color); white-space: nowrap;">Open</span>
                                        <?php else: ?>
                                        <span style="color: var(--error-color); white-space: nowrap;">Closed</span>
                                        <?php endif; 
                                    endif;
                                    ?>
                                </div>
                            </div>
                        </a>
                        <?php if ($is_customer_logged_in): ?>
                        <button 
                            class="favorite-btn" 
                            data-caterer-id="<?php echo $caterer['id']; ?>" 
                            style="
                                position: absolute;
                                top: 10px;
                                right: 10px;
                                background: rgba(0,0,0,0.6);
                                border: none;
                                border-radius: 50%;
                                width: 36px;
                                height: 36px;
                                display: flex;
                                align-items: center;
                                justify-content: center;
                                cursor: pointer;
                                z-index: 10;
                                transition: all 0.3s ease;
                            "
                            onclick="event.stopPropagation(); toggleFavorite(<?php echo $caterer['id']; ?>)"
                        >
                            <i class="<?php echo $is_favorite ? 'fas' : 'far'; ?> fa-star" style="color: <?php echo $is_favorite ? '#ffd700' : '#fff'; ?>; font-size: 18px;"></i>
                        </button>
                        <?php endif; ?>
                    </div>
                <?php endwhile; ?>
            <?php else: ?>
                <div class="empty-state" style="grid-column: 1/-1;">
                    <i class="fas fa-caterer-slash"></i>
                    <p>No caterers found matching your criteria.</p>
                    <a href="browse-caterer.php" class="btn btn-primary" style="margin-top: 15px;">View All Caterers</a>
                </div>
            <?php endif; ?>
        </div>

    <?php else: ?>
        <!-- ==================== CATERER DETAILS VIEW ==================== -->
        <?php
        $store_query = "
            SELECT u.*, sd.*, COALESCE(NULLIF(sd.location, ''), u.address) AS location 
            FROM users u 
            LEFT JOIN store_details sd ON u.id = sd.user_id 
            WHERE u.id = '$store_id' AND u.role IN ('caterer','store')
        ";
        $store_result = mysqli_query($conn, $store_query);
        
        if (mysqli_num_rows($store_result) == 0) {
            echo '<div class="alert alert-error">Caterer not found.</div>';
            require_once __DIR__ . '/includes/footer.php';
            exit();
        }
        
        $caterer = mysqli_fetch_assoc($store_result);
        $store_is_open = isStoreOpen($caterer['is_open'] ?? 0, $caterer['business_open_time'] ?? null, $caterer['business_close_time'] ?? null, $caterer['auto_schedule_enabled'] ?? 0);
        
        // Check if caterer is favorite
        $is_favorite = false;
        if (isset($_SESSION['user_id'])) {
            $check_fav_query = "SELECT id FROM customer_favorites WHERE customer_id = '$user_id' AND store_id = '$store_id'";
            $check_fav_result = mysqli_query($conn, $check_fav_query);
            if ($check_fav_result && mysqli_num_rows($check_fav_result) > 0) {
                $is_favorite = true;
            }
        }
        
        // Get caterer stats
        $stats_query = "SELECT 
            (SELECT COUNT(*) FROM products WHERE store_id = '$store_id' AND is_available = 1) as total_products,
            (SELECT COUNT(*) FROM products WHERE store_id = '$store_id' AND is_available = 1 AND category = 'catering_service') as catering_count,
            (SELECT COUNT(*) FROM bookings WHERE store_id = '$store_id' AND order_status = 'completed') as completed_orders";
        $stats_result = mysqli_query($conn, $stats_query);
        $store_stats = mysqli_fetch_assoc($stats_result);
        
        // Determine View (Landing, Food, or Catering)
        $view = isset($_GET['view']) ? $_GET['view'] : 'landing';
        $initial_tab = 'catering-section';
        if ($view === 'catering') {
            $initial_tab = 'catering-section';
        } elseif ($view === 'menu') {
            $initial_tab = 'menu-section';
        } elseif ($view === 'reviews') {
            $initial_tab = 'reviews-section';
        }
        ?>

        <?php
        // Booking returns may omit source; return to the caterer list by default.
        $back_link = 'browse-caterer.php';
        if (isset($_GET['source'])) {
            if ($_GET['source'] === 'featured') {
                $back_link = '../index.php#stores-section';
            } elseif ($_GET['source'] === 'browse') {
                $back_link = 'browse-caterer.php';
            } elseif ($_GET['source'] === 'search') {
                $search_query = isset($_GET['search_query']) ? $_GET['search_query'] : '';
                $back_link = '../index.php?search=' . urlencode($search_query);
                if (isset($_GET['service_id'])) {
                    $back_link .= '&open_modal=' . (int)$_GET['service_id'];
                }
            } elseif ($_GET['source'] === 'modal') {
                $search_query = isset($_GET['search_query']) ? $_GET['search_query'] : '';
                if ($search_query !== '') {
                    $back_link = '../index.php?search=' . urlencode($search_query) . '&show_caterers=1';
                } else {
                    $back_link = '../index.php?show_caterers=1';
                }
            } elseif ($_GET['source'] === 'dashboard') {
                $back_link = 'dashboard.php#caterer-' . (int)$store_id;
            }
        }
        ?>
        <div class="card caterer-detail-card">
            <div id="backButtonContainer" style="padding: 0 0 15px; margin-bottom: 15px; border-bottom: 1px solid var(--border-color);">
                <a href="<?php echo htmlspecialchars($back_link); ?>" style="display: inline-flex; align-items: center; gap: 8px; color: var(--accent-gold); text-decoration: none;">
                    <i class="fas fa-arrow-left"></i> Back to Caterers
                </a>
            </div>

            <div class="caterer-hero">
                <!-- Cover Image - Full width/height cover -->
                <?php if (!empty($caterer['cover_photo']) && file_exists('../' . $caterer['cover_photo'])): ?>
                    <?php 
                    $cover_pos = $caterer['cover_position'] ?? 'center';
                    // Map position names to CSS background-position values
                    $position_map = [
                        'center' => 'center center',
                        'top' => 'center top',
                        'bottom' => 'center bottom',
                        'left' => 'left center',
                        'right' => 'right center',
                        'top left' => 'left top',
                        'top right' => 'right top',
                        'bottom left' => 'left bottom',
                        'bottom right' => 'right bottom'
                    ];
                    $css_pos = $position_map[$cover_pos] ?? 'center center';
                    ?>
                    <div class="caterer-hero-bg" style="background: url('../<?php echo $caterer['cover_photo']; ?>') <?php echo $css_pos; ?>/cover no-repeat;"></div>
                    <div class="caterer-hero-overlay" style="background: linear-gradient(to bottom, rgba(0,0,0,0.4) 0%, rgba(0,0,0,0.7) 100%);"></div>
                <?php else: ?>
                    <!-- Default catering food background -->
                    <div class="caterer-hero-bg" style="background: url('../uploads/background/background.png') center center/cover no-repeat;"></div>
                    <!-- Dark overlay for text readability -->
                    <div class="caterer-hero-overlay" style="background: rgba(0,0,0,0.5);"></div>
                <?php endif; ?>
                
                <!-- Verified Badge -->
                <?php if (!empty($caterer['is_verified']) && $caterer['is_verified'] == 1): ?>
                <div class="caterer-hero-badge">
                    <i class="fas fa-check-circle"></i> Verified Caterer
                </div>
                <?php endif; ?>
                
                <!-- Favorite Button -->
                <?php if ($is_customer_logged_in): ?>
                <button 
                    class="favorite-btn" 
                    data-caterer-id="<?php echo $caterer['id']; ?>" 
                    style="
                        position: absolute;
                        top: 20px;
                        right: 20px;
                        background: rgba(0,0,0,0.6);
                        border: none;
                        border-radius: 50%;
                        width: 50px;
                        height: 50px;
                        display: flex;
                        align-items: center;
                        justify-content: center;
                        cursor: pointer;
                        z-index: 10;
                        transition: all 0.3s ease;
                    "
                    onclick="toggleFavorite(<?php echo $caterer['id']; ?>)"
                >
                    <i class="<?php echo $is_favorite ? 'fas' : 'far'; ?> fa-star" style="color: <?php echo $is_favorite ? '#ffd700' : '#fff'; ?>; font-size: 24px;"></i>
                </button>
                <?php endif; ?>
                
                <div class="caterer-hero-content">
                    <!-- Left: Caterer Info & Stats -->
                    <div class="caterer-hero-main">
                        <img src="../<?php echo !empty($caterer['profile_image']) ? $caterer['profile_image'] : 'uploads/logo.png'; ?>" class="caterer-profile-image">
                        <div class="caterer-hero-info">
                            <div class="caterer-title-row">
                                <h1 class="caterer-title"><?php echo htmlspecialchars($caterer['business_name']); ?></h1>
                                <?php if (!empty($caterer['is_verified']) && $caterer['is_verified'] == 1): ?>
                                    <i class="fas fa-check-circle" style="color: var(--accent-gold); font-size: 20px;" title="Verified Caterer"></i>
                                <?php endif; ?>
                            </div>
                            <p class="caterer-meta-line">
                                <i class="fas fa-map-marker-alt" style="color: var(--accent-gold);"></i> 
                                <?php echo htmlspecialchars($caterer['location'] ?: 'Location not set'); ?>
                            </p>
                            <p class="caterer-meta-line hours">
                                <i class="fas fa-clock" style="color: var(--accent-gold);"></i>
                                <?php if($store_is_open): ?>
                                    <span class="caterer-hours-status-open">OPEN</span>
                                    <span class="caterer-hours-range">(<?php echo date('g:i A', strtotime($caterer['business_open_time'] ?? '08:00:00')); ?> - <?php echo date('g:i A', strtotime($caterer['business_close_time'] ?? '17:00:00')); ?>)</span>
                                <?php else: ?>
                                    <span class="caterer-hours-status-closed">CLOSED</span>
                                    <span class="caterer-hours-range">(<?php echo date('g:i A', strtotime($caterer['business_open_time'] ?? '08:00:00')); ?> - <?php echo date('g:i A', strtotime($caterer['business_close_time'] ?? '17:00:00')); ?>)</span>
                                <?php endif; ?>
                            </p>
                            <!-- Stats Row - Below caterer name/location -->
                            <div class="caterer-stat-list">
                                <!-- Rating (from real reviews) -->
                                <?php
                                    $review_stats_q = mysqli_query($conn, "SELECT MAX(overall_rating) as highest_rating, COUNT(*) as total_reviews FROM reviews WHERE store_id = '$store_id'");
                                    $review_stats = mysqli_fetch_assoc($review_stats_q);
                                    $store_highest_rating = $review_stats['highest_rating'] ? number_format($review_stats['highest_rating'], 1) : '0.0';
                                    $store_total_reviews = (int)($review_stats['total_reviews'] ?? 0);
                                ?>
                                <div class="caterer-stat-pill rating">
                                    <i class="fas fa-star" style="color: var(--accent-gold); font-size: 12px;"></i>
                                    <span style="color: white; font-weight: 700; font-size: 13px;"><?php echo $store_highest_rating; ?></span>
                                    <span style="color: var(--text-muted); font-size: 11px;">(<?php echo $store_total_reviews; ?>)</span>
                                </div>
                                

                                <!-- Services -->
                                <div class="caterer-stat-pill">
                                    <i class="fas fa-utensils" style="color: var(--accent-gold); font-size: 11px;"></i>
                                    <span style="color: white; font-weight: 600; font-size: 12px;"><?php echo $store_stats['catering_count']; ?> Services</span>
                                </div>
                                
                                <!-- Orders -->
                                <div class="caterer-stat-pill">
                                    <i class="fas fa-check-circle" style="color: #2ecc71; font-size: 11px;"></i>
                                    <span style="color: white; font-weight: 600; font-size: 12px;"><?php echo $store_stats['completed_orders']; ?> Bookings</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Right: Message Button Only -->
                    <div>
                        <a href="messages.php?store_id=<?php echo $store_id; ?>" class="caterer-message-btn">
                            <i class="fas fa-comment-dots"></i> Message Caterer
                        </a>
                    </div>
                </div>
            </div>
            <?php if (trim((string)($caterer['description'] ?? '')) !== ''): ?>
                <div class="caterer-detail-description">
                    <p style="color: var(--text-muted); margin: 0;"><?php echo nl2br(htmlspecialchars($caterer['description'])); ?></p>
                </div>
            <?php endif; ?>
            <?php if ($view === 'landing'): ?>
            <nav class="caterer-section-nav" aria-label="Caterer sections">
                <a href="#catering-section" class="active" data-caterer-tab="catering-section"><i class="fas fa-utensils"></i> Catering Services</a>
                <a href="#menu-section" data-caterer-tab="menu-section"><i class="fas fa-book-open"></i> View Menu</a>
                <a href="#reviews-section" data-caterer-tab="reviews-section"><i class="fas fa-star"></i> Customer Reviews</a>
            </nav>
            <?php endif; ?>
        </div>

        <?php if ($view === 'landing'): ?>
            <!-- LANDING PAGE: Show All Sections -->
            
            <!-- CATERING SERVICES SECTION -->
            <div id="catering-section" class="caterer-tab-panel active" style="margin-bottom: 50px;">
                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px;">
                    <h2 class="section-title" style="margin-bottom: 0; border-bottom: 2px solid var(--accent-gold); padding-bottom: 10px; display: inline-block;">
                        <i class="fas fa-utensils"></i> Catering Services
                    </h2>
                </div>
                
                <?php
                // Get Catering Services
                $catering_query = "SELECT * FROM products WHERE store_id = '$store_id' AND is_available = TRUE AND category = 'catering_service' ORDER BY name";
                $catering_result = mysqli_query($conn, $catering_query);
                ?>
                
                <?php
                $set_menu_labels = [
                    'appetizers' => 'Appetizers',
                    'soup' => 'Soup',
                    'rice' => 'Rice',
                    'main_dishes' => 'Main Dishes',
                    'pasta_noodles' => 'Pasta/Noodles',
                    'desserts' => 'Desserts',
                    'drinks' => 'Drinks',
                ];
                ?>
                <?php if (mysqli_num_rows($catering_result) > 0): ?>
                    <div class="catering-service-list">
                        <?php while($service = mysqli_fetch_assoc($catering_result)):
                            $service_id = $service['id'];
                            $sets_res = mysqli_query($conn, "SELECT * FROM product_sets WHERE product_id = '$service_id' ORDER BY price ASC");
                            $service['sets'] = [];
                            while($s = mysqli_fetch_assoc($sets_res)) {
                                $s['addons'] = json_decode($s['addons'] ?? '[]', true) ?: [];
                                $s['swaps'] = json_decode($s['swaps'] ?? '[]', true) ?: [];
                                $s['category_lines'] = browseStoreGetCategoryLines($s['description'] ?? '');
                                $service['sets'][] = $s;
                            }
                            $display_prices = [];
                            foreach ($service['sets'] as $price_set) {
                                $display_prices[] = floatval($price_set['per_head_price'] ?? 0) > 0
                                    ? floatval($price_set['per_head_price'])
                                    : floatval($price_set['price'] ?? 0);
                            }
                            $display_prices = array_values(array_filter($display_prices));
                        ?>
                            <article class="catering-service-block" id="service-<?php echo $service['id']; ?>">
                                <div class="catering-service-head">
                                    <img src="../<?php echo !empty($service['image']) ? $service['image'] : 'uploads/products/default.png'; ?>" alt="<?php echo htmlspecialchars($service['name']); ?>">
                                    <div>
                                        <h3><?php echo htmlspecialchars($service['name']); ?></h3>
                                        <p><?php echo htmlspecialchars($service['description']); ?></p>
                                        <?php if (!empty($service['catering_details'])): ?>
                                            <div class="catering-service-inclusions">
                                                <strong>What's Included</strong>
                                                <?php echo nl2br(htmlspecialchars($service['catering_details'])); ?>
                                            </div>
                                        <?php endif; ?>

                                    </div>
                                </div>
                                <?php if (!empty($service['sets'])): ?>
                                    <div class="catering-package-list">
                                        <?php foreach ($service['sets'] as $set):
                                            $set_per_head = floatval($set['per_head_price'] ?? 0);
                                            $set_pax = (int) ($set['pax'] ?? 0);
                                            $set_price = (float) ($set['price'] ?? 0);
                                            if ($set_per_head > 0) {
                                                $set_price_label = '?' . number_format($set_per_head, 2) . ' per guest';
                                            } else {
                                                $set_price_label = '?' . number_format($set_price, 2);
                                                if ($set_pax > 0) {
                                                    $set_price_label .= ' � Good for ' . $set_pax . ' guests';
                                                }
                                            }
                                        ?>
                                            <div class="catering-package-row">
                                                <div class="catering-package-info">
                                                    <strong><?php echo htmlspecialchars($set['set_name'] ?? 'Package'); ?></strong>
                                                    <span><?php echo htmlspecialchars($set_price_label); ?></span>
                                                </div>
                                                <div class="catering-package-actions">
                                                    <?php if (!empty($set['category_lines'])): ?>
                                                        <button type="button" class="service-set-toggle" onclick="openViewMenuModal(<?php echo htmlspecialchars(json_encode($set['set_name'] ?? ''), ENT_QUOTES); ?>, <?php echo (float) ($set['price'] ?? 0); ?>, <?php echo (int) ($set['pax'] ?? 0); ?>, <?php echo htmlspecialchars(json_encode($set['category_lines']), ENT_QUOTES); ?>, <?php echo intval($service['id']); ?>, <?php echo intval($store_id); ?>, <?php echo (float) ($set['extra_guest_rate'] ?? 0); ?>, <?php echo htmlspecialchars(json_encode($service['catering_details'] ?? ''), ENT_QUOTES); ?>)">
                                                            <i class="fas fa-utensils"></i> View Menu
                                                        </button>
                                                    <?php endif; ?>
                                                    <?php if ($store_is_open): ?>
                                                        <button type="button" class="btn-order-mini" onclick="openBookThisServiceModal(<?php echo intval($service['id']); ?>, <?php echo intval($store_id); ?>, <?php echo htmlspecialchars(json_encode($set['set_name'] ?? ''), ENT_QUOTES); ?>)">
                                                            <i class="fas fa-calendar-check"></i> Book
                                                        </button>
                                                    <?php else: ?>
                                                        <span class="btn-order-mini" style="opacity: 0.65; pointer-events: none; cursor: not-allowed; background: #3b2a2a; color: #f3b0b0;">Closed</span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </article>
                        <?php endwhile; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state" style="padding: 30px; background: rgba(255,255,255,0.05); border-radius: 12px;">
                        <p>No catering services available at the moment.</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- VIEW MENU SECTION -->
            <div id="menu-section" class="menu-category-shell caterer-tab-panel">
                <?php
                $set_menu_labels = [
                    'appetizers' => 'Appetizers',
                    'soup' => 'Soup',
                    'rice' => 'Rice',
                    'main_dishes' => 'Main Dishes',
                    'pasta_noodles' => 'Pasta/Noodles',
                    'desserts' => 'Desserts',
                    'drinks' => 'Drinks',
                ];
                $available_menu_sets = [];
                $available_sets_query = "
                    SELECT ps.*, p.id AS product_id, p.name AS product_name, p.catering_details
                    FROM product_sets ps
                    INNER JOIN products p ON ps.product_id = p.id
                    WHERE p.store_id = '$store_id'
                      AND p.is_available = TRUE
                      AND p.category = 'catering_service'
                    ORDER BY p.name, ps.id
                ";
                $available_sets_result = mysqli_query($conn, $available_sets_query);
                if ($available_sets_result) {
                    while ($set_menu = mysqli_fetch_assoc($available_sets_result)) {
                        $decoded_description = json_decode((string) ($set_menu['description'] ?? ''), true);
                        $categories = [];

                        $category_lines = browseStoreGetCategoryLines($set_menu['description'] ?? '');

                        $available_menu_sets[] = [
                            'id' => $set_menu['id'],
                            'product_id' => $set_menu['product_id'],
                            'product_name' => $set_menu['product_name'],
                            'catering_details' => $set_menu['catering_details'] ?? '',
                            'set_name' => $set_menu['set_name'] ?? ($set_menu['name'] ?? 'Unnamed Set'),
                            'price' => (float) ($set_menu['price'] ?? 0),
                            'pax' => (int) ($set_menu['pax'] ?? 0),
                            'per_head_price' => (float) ($set_menu['per_head_price'] ?? 0),
                            'category_lines' => $category_lines,
                        ];
                    }
                }
                ?>

                <div class="menu-category-header">
                    <h2 class="section-title" style="margin-bottom: 0; border-bottom: 2px solid var(--accent-gold); padding-bottom: 10px; display: inline-block;">
                        <i class="fas fa-book-open"></i> View Menu
                    </h2>
                </div>

                <?php if (!empty($available_menu_sets)): ?>
                    <div class="available-menu-container">
                        <h3 class="menu-category-title" style="margin-bottom: 14px;"><i class="fas fa-utensils"></i> Available Menu Sets</h3>
                        <div class="available-menu-list">
                            <?php foreach ($available_menu_sets as $set): ?>
                                <div class="available-menu-set">
                                    <span class="available-menu-set-head">
                                        <span class="available-menu-set-name"><?php echo htmlspecialchars($set['set_name']); ?></span>
                                        <span class="available-menu-set-price">
                                            &#8369;<?php echo number_format($set['price'], 2); ?>
                                            <?php if ($set['pax'] > 0): ?>
                                                Good for <?php echo $set['pax']; ?> person
                                            <?php endif; ?>
                                            <?php if ($set['per_head_price'] > 0): ?>
                                                <br><small>&#8369;<?php echo number_format($set['per_head_price'], 2); ?> per pax</small>
                                            <?php endif; ?>
                                        </span>
                                    </span>
                                    <?php if (!empty($set['catering_details'])): ?>
                                        <span class="available-menu-lines" style="margin-top: 8px; display: block;">
                                            <span><strong>What's Included:</strong> <?php echo htmlspecialchars($set['catering_details']); ?></span>
                                        </span>
                                    <?php endif; ?>
                                    <span class="available-menu-lines">
                                        <?php if (!empty($set['category_lines'])): ?>
                                            <?php foreach ($set['category_lines'] as $line): ?>
                                                <span><strong><?php echo htmlspecialchars($line['label']); ?>:</strong> <?php echo htmlspecialchars(implode(', ', $line['items'])); ?></span>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <span style="color: var(--text-muted);">Menu details are available when booking this set.</span>
                                        <?php endif; ?>
                                    </span>
                                    <?php if ($store_is_open): ?>
                                    <button
                                        type="button"
                                        class="btn btn-primary"
                                        style="margin-top: 14px; padding: 9px 14px; border-radius: 8px;"
                                        onclick="openBookThisServiceModal(<?php echo intval($set['product_id']); ?>, <?php echo intval($store_id); ?>, <?php echo htmlspecialchars(json_encode($set['set_name']), ENT_QUOTES); ?>)"
                                    >
                                        <i class="fas fa-calendar-check"></i> Book This Package
                                    </button>
                                    <?php else: ?>
                                    <span class="btn btn-primary" style="margin-top: 14px; padding: 9px 14px; border-radius: 8px; opacity: 0.65; pointer-events: none; background: #3b2a2a; color: #f3b0b0; border: 1px solid rgba(231, 76, 60, 0.35);">
                                        <i class="fas fa-caterer-slash"></i> Closed � menu still viewable
                                    </span>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="empty-state" style="padding: 30px; background: rgba(255,255,255,0.05); border-radius: 12px;">
                        <p>No menu items available at the moment.</p>
                    </div>
                <?php endif; ?>
            </div>

        <?php elseif ($view === 'food'): ?>
            <!-- FOOD PACKAGES PAGE -->
            <div style="margin-bottom: 20px;">
                <a href="?store_id=<?php echo $store_id; ?>" class="btn btn-outline"><i class="fas fa-arrow-left"></i> Back to Categories</a>
            </div>
            
            <h2 class="section-title" style="margin-bottom: 30px;">Food Packages</h2>
            
            <?php
            // Get Products
            $products_query = "SELECT * FROM products WHERE store_id = '$store_id' AND is_available = TRUE AND category != 'catering_service' ORDER BY name";
            $products_result = mysqli_query($conn, $products_query);
            ?>
            
            <?php if (mysqli_num_rows($products_result) > 0): ?>
                <div class="products-grid">
                    <?php while($product = mysqli_fetch_assoc($products_result)): ?>
                        <div class="product-card">
                            <div class="product-image-container">
                                <img src="../<?php echo !empty($product['image']) ? $product['image'] : 'uploads/products/default.png'; ?>" class="product-image" alt="Product">
                            </div>
                            <div class="product-content">
                                <h3 class="product-title"><?php echo htmlspecialchars($product['name']); ?></h3>
                                <p class="product-description"><?php echo htmlspecialchars($product['description']); ?></p>
                                
                                <div class="product-price" style="font-size: 16px; color: var(--accent-gold); font-weight: 700;">&#8369;<?php echo number_format($product['price'], 2); ?></div>
                                
                                <?php if ($store_is_open): ?>
                                    <a href="<?php echo $is_customer_logged_in ? 'book-service.php?product_id=' . $product['id'] . '&store_id=' . $store_id : '../login.php?redirect=' . urlencode('customer/book-service.php?product_id=' . $product['id'] . '&store_id=' . $store_id); ?>" class="btn-order" style="text-decoration: none; text-align: center;">
                                        <i class="fas fa-shopping-cart"></i> Order Now
                                    </a>
                                <?php else: ?>
                                    <span class="btn-order" style="text-decoration: none; text-align: center; display: block; opacity: 0.65; pointer-events: none; cursor: not-allowed; background: #3b2a2a; color: #f3b0b0; border: 1px solid rgba(231, 76, 60, 0.35);">
                                        <i class="fas fa-caterer-slash"></i> Caterer Closed
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endwhile; ?>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-box-open"></i>
                    <p>No food packages available.</p>
                </div>
            <?php endif; ?>

        <?php elseif ($view === 'catering'): ?>
            <!-- CATERING SERVICES PAGE -->
            <div style="margin-bottom: 20px;">
                <a href="?store_id=<?php echo $store_id; ?>" class="btn btn-outline"><i class="fas fa-arrow-left"></i> Back to Categories</a>
            </div>
            
            <h2 class="section-title" style="margin-bottom: 30px;">Catering Services</h2>
            
            <?php
            // Get Catering Services
            $catering_query = "SELECT * FROM products WHERE store_id = '$store_id' AND is_available = TRUE AND category = 'catering_service' ORDER BY name";
            $catering_result = mysqli_query($conn, $catering_query);

            $set_menu_labels = [
                'appetizers' => 'Appetizers',
                'soup' => 'Soup',
                'rice' => 'Rice',
                'main_dishes' => 'Main Dishes',
                'pasta_noodles' => 'Pasta/Noodles',
                'desserts' => 'Desserts',
                'drinks' => 'Drinks',
            ];
            ?>

            <?php if (mysqli_num_rows($catering_result) > 0): ?>
                <div class="catering-service-list">
                    <?php while($service = mysqli_fetch_assoc($catering_result)):
                        $service_id = $service['id'];
                        $sets_res = mysqli_query($conn, "SELECT * FROM product_sets WHERE product_id = '$service_id' ORDER BY price ASC");
                        $service['sets'] = [];
                        while($s = mysqli_fetch_assoc($sets_res)) {
                            $s['addons'] = json_decode($s['addons'] ?? '[]', true) ?: [];
                            $s['swaps'] = json_decode($s['swaps'] ?? '[]', true) ?: [];
                            $s['category_lines'] = browseStoreGetCategoryLines($s['description'] ?? '');
                            $service['sets'][] = $s;
                        }
                        $display_prices = [];
                        foreach ($service['sets'] as $price_set) {
                            $display_prices[] = floatval($price_set['per_head_price'] ?? 0) > 0
                                ? floatval($price_set['per_head_price'])
                                : floatval($price_set['price'] ?? 0);
                        }
                        $display_prices = array_values(array_filter($display_prices));
                    ?>
                        <article class="catering-service-block">
                            <div class="catering-service-head">
                                <img src="../<?php echo !empty($service['image']) ? $service['image'] : 'uploads/products/default.png'; ?>" alt="<?php echo htmlspecialchars($service['name']); ?>">
                                <div>
                                    <h3><?php echo htmlspecialchars($service['name']); ?></h3>
                                    <p><?php echo htmlspecialchars($service['description']); ?></p>
                                    <?php if (!empty($service['catering_details'])): ?>
                                        <div class="catering-service-inclusions">
                                            <strong>What's Included</strong>
                                            <?php echo nl2br(htmlspecialchars($service['catering_details'])); ?>
                                        </div>
                                    <?php endif; ?>

                                </div>
                            </div>
                            <?php if (!empty($service['sets'])): ?>
                                <div class="catering-package-list">
                                    <?php foreach ($service['sets'] as $set):
                                        $set_per_head = floatval($set['per_head_price'] ?? 0);
                                        $set_pax = (int) ($set['pax'] ?? 0);
                                        $set_price = (float) ($set['price'] ?? 0);
                                        if ($set_per_head > 0) {
                                            $set_price_label = '?' . number_format($set_per_head, 2) . ' per guest';
                                        } else {
                                            $set_price_label = '?' . number_format($set_price, 2);
                                            if ($set_pax > 0) {
                                                $set_price_label .= ' � Good for ' . $set_pax . ' guests';
                                            }
                                        }
                                    ?>
                                        <div class="catering-package-row">
                                            <div class="catering-package-info">
                                                <strong><?php echo htmlspecialchars($set['set_name'] ?? 'Package'); ?></strong>
                                                <span><?php echo htmlspecialchars($set_price_label); ?></span>

                                            </div>
                                            <div class="catering-package-actions">
                                                <?php if (!empty($set['category_lines'])): ?>
                                                    <button type="button" class="service-set-toggle" onclick="openViewMenuModal(<?php echo htmlspecialchars(json_encode($set['set_name'] ?? ''), ENT_QUOTES); ?>, <?php echo (float) ($set['price'] ?? 0); ?>, <?php echo (int) ($set['pax'] ?? 0); ?>, <?php echo htmlspecialchars(json_encode($set['category_lines']), ENT_QUOTES); ?>, <?php echo intval($service['id']); ?>, <?php echo intval($store_id); ?>, <?php echo (float) ($set['extra_guest_rate'] ?? 0); ?>, <?php echo htmlspecialchars(json_encode($service['catering_details'] ?? ''), ENT_QUOTES); ?>)">
                                                        <i class="fas fa-utensils"></i> View Menu
                                                    </button>
                                                <?php endif; ?>
                                                <?php if ($store_is_open): ?>
                                                    <button type="button" class="btn-order-mini" onclick="openBookThisServiceModal(<?php echo intval($service['id']); ?>, <?php echo intval($store_id); ?>, <?php echo htmlspecialchars(json_encode($set['set_name'] ?? ''), ENT_QUOTES); ?>)">
                                                        <i class="fas fa-calendar-check"></i> Book
                                                    </button>
                                                <?php else: ?>
                                                    <span class="btn-order-mini" style="opacity: 0.65; pointer-events: none; cursor: not-allowed; background: #3b2a2a; color: #f3b0b0;">Closed</span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </article>
                    <?php endwhile; ?>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-utensils"></i>
                    <p>No catering services available.</p>
                </div>
            <?php endif; ?>

        <?php endif; ?>


        <!-- CUSTOMER REVIEWS SECTION -->
        <?php if ($view === 'landing'): ?>
        <div id="reviews-section" class="caterer-tab-panel" style="margin-top: 0; margin-bottom: 40px;">
            <h2 class="section-title" style="margin-bottom: 20px; border-bottom: 2px solid var(--accent-gold); padding-bottom: 10px; display: inline-block;">
                <i class="fas fa-star"></i> Customer Reviews
            </h2>
            <?php
            $customer_reviews_q = mysqli_query($conn, "SELECT r.*, u.first_name, u.last_name, u.profile_image, o.order_number FROM reviews r JOIN users u ON r.customer_id = u.id JOIN bookings o ON r.order_id = o.id WHERE r.store_id = '$store_id' ORDER BY r.created_at DESC LIMIT 6");
            ?>
            <?php if ($customer_reviews_q && mysqli_num_rows($customer_reviews_q) > 0): ?>
                <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(350px, 1fr)); gap: 20px;">
                    <?php while ($rev = mysqli_fetch_assoc($customer_reviews_q)): ?>
                        <div style="background: rgba(255,255,255,0.04); border: 1px solid var(--border-color); border-radius: 14px; padding: 20px;">
                            <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px;">
                                <div style="display: flex; align-items: center; gap: 12px;">
                                    <div style="width: 42px; height: 42px; background: var(--accent-gold); color: #000; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 16px;">
                                        <?php echo strtoupper(substr($rev['first_name'], 0, 1)); ?>
                                    </div>
                                    <div>
                                        <div style="font-weight: 600; color: var(--text-main);"><?php echo htmlspecialchars($rev['first_name'] . ' ' . substr($rev['last_name'], 0, 1) . '.'); ?></div>
                                        <div style="font-size: 12px; color: var(--text-muted);"><?php echo date('M d, Y', strtotime($rev['created_at'])); ?></div>
                                    </div>
                                </div>
                                <div style="color: #f1c40f; font-size: 14px;">
                                    <?php for ($i = 1; $i <= 5; $i++) echo $i <= round($rev['overall_rating']) ? '<i class="fas fa-star"></i>' : '<i class="far fa-star"></i>'; ?>
                                </div>
                            </div>
                            <?php if (!empty($rev['comment'])): ?>
                                <p style="color: var(--text-main); font-size: 14px; line-height: 1.6; margin: 0; font-style: italic;">"<?php echo htmlspecialchars(mb_strimwidth($rev['comment'], 0, 200, '...')); ?>"</p>
                            <?php endif; ?>

                        </div>
                    <?php endwhile; ?>
                </div>
            <?php else: ?>
                <div style="padding: 40px; text-align: center; background: rgba(255,255,255,0.03); border-radius: 14px; border: 1px solid var(--border-color);">
                    <i class="far fa-star fa-2x" style="color: var(--text-muted); margin-bottom: 12px;"></i>
                    <p style="color: var(--text-muted); margin: 0;">No customer reviews yet. Be the first to leave feedback!</p>
                </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/modalBookThisService.php'; ?>

<!-- View Menu Modal -->
<div id="viewMenuModal" class="view-menu-modal" onclick="closeViewMenuModal(event)">
    <div class="view-menu-dialog" role="dialog" aria-modal="true" aria-labelledby="viewMenuTitle">
        <div class="view-menu-head">
            <div>
                <h3 class="view-menu-title" id="viewMenuTitle">Menu</h3>
                <div class="view-menu-price" id="viewMenuPrice"></div>
            </div>
            <button type="button" class="view-menu-close" onclick="closeViewMenuModal()" aria-label="Close">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="view-menu-body" id="viewMenuBody">
            <div class="view-menu-empty">No menu details available.</div>
        </div>
        <div class="view-menu-foot" id="viewMenuFoot">
            <button type="button" class="btn-order-mini" id="viewMenuBookBtn">
                <i class="fas fa-calendar-check"></i> Book This Package
            </button>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const tabLinks = document.querySelectorAll('[data-caterer-tab]');
    const tabPanels = document.querySelectorAll('.caterer-tab-panel');

    tabLinks.forEach(function(link) {
        link.addEventListener('click', function(event) {
            event.preventDefault();
            const targetId = link.getAttribute('data-caterer-tab');

            tabLinks.forEach(function(item) {
                item.classList.toggle('active', item === link);
            });

            tabPanels.forEach(function(panel) {
                panel.classList.toggle('active', panel.id === targetId);
            });
        });
    });

    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') {
            closeViewMenuModal();
        }
    });
});

function getMenuCategoryIcon(label) {
    const l = (label || '').toLowerCase();
    if (l.includes('appetizer') || l.includes('starter') || l.includes('pica')) return 'fa-cookie-bite';
    if (l.includes('soup')) return 'fa-bowl-rice';
    if (l.includes('rice')) return 'fa-bowl-rice';
    if (l.includes('main') || l.includes('dish') || l.includes('meat') || l.includes('ulam') || l.includes('viand') || l.includes('beef') || l.includes('chicken') || l.includes('pork') || l.includes('fish') || l.includes('seafood')) return 'fa-drumstick-bite';
    if (l.includes('pasta') || l.includes('noodle') || l.includes('spaghetti') || l.includes('pancit') || l.includes('sotanghon')) return 'fa-plate-wheat';
    if (l.includes('dessert') || l.includes('cake') || l.includes('sweet')) return 'fa-cake-candles';
    if (l.includes('drink') || l.includes('beverage') || l.includes('juice') || l.includes('soda') || l.includes('coke')) return 'fa-glass-water';
    return 'fa-utensils';
}

function openViewMenuModal(setName, price, pax, categoryLines, productId, storeId, extraGuestRate, cateringDetails) {
    const modal = document.getElementById('viewMenuModal');
    const title = document.getElementById('viewMenuTitle');
    const priceEl = document.getElementById('viewMenuPrice');
    const body = document.getElementById('viewMenuBody');
    const bookBtn = document.getElementById('viewMenuBookBtn');

    if (!modal || !title || !priceEl || !body || !bookBtn) return;

    title.textContent = setName || 'Menu';

    const money = function(value) {
        return '&#8369;' + parseFloat(value || 0).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    };

    let priceHtml = '<div class="view-menu-price-row">';
    priceHtml += '<span class="view-menu-price-badge">' + money(price) + '</span>';
    if (parseInt(pax || 0) > 0) {
        priceHtml += '<span class="view-menu-pax-tag"><i class="fas fa-users"></i> Good for ' + parseInt(pax) + ' person' + (parseInt(pax) > 1 ? 's' : '') + '</span>';
    }
    priceHtml += '</div>';
    if (parseFloat(extraGuestRate || 0) > 0) {
        priceHtml += '<div class="view-menu-extra"><i class="fas fa-user-plus"></i> +' + money(extraGuestRate) + ' per extra guest</div>';
    }
    priceEl.innerHTML = priceHtml;

    let html = '';
    const details = typeof cateringDetails === 'string' ? cateringDetails.trim() : '';
    if (details !== '') {
        const inclusionLines = details.split(/\r\n|\r|\n/).map(function(s) { return s.trim(); }).filter(function(s) { return s.length > 0; });
        if (inclusionLines.length > 0) {
            html += '<div class="view-menu-inclusions-card">';
            html += '<div class="view-menu-inclusions-header">What\'s Included</div>';
            html += '<div class="view-menu-inclusions-grid">';
            inclusionLines.forEach(function(inc) {
                html += '<div class="view-menu-inclusion-item">';
                html += '<span>' + escapeHtml(inc) + '</span>';
                html += '</div>';
            });
            html += '</div></div>';
        }
    }

    let lines = [];
    try {
        lines = typeof categoryLines === 'string' ? JSON.parse(categoryLines) : (categoryLines || []);
    } catch(e) {
        lines = [];
    }

    if (Array.isArray(lines) && lines.length > 0) {
        html += '<div class="view-menu-section-header"><i class="fas fa-utensils"></i> <span>Menu Items</span></div>';
        html += '<div class="view-menu-grid">';
        lines.forEach(function(line) {
            let rawItems = line.items || [];
            if (typeof rawItems === 'string') {
                rawItems = rawItems.split(/,\s*|\r\n|\r|\n/);
            }
            const items = (Array.isArray(rawItems) ? rawItems : [rawItems])
                .map(function(i) { return typeof i === 'string' ? i.trim() : ''; })
                .filter(function(i) { return i.length > 0; });

            if (items.length === 0) return;

            const iconClass = getMenuCategoryIcon(line.label || '');
            const countLabel = items.length === 1 ? '1 item' : items.length + ' items';

            html += '<div class="view-menu-category-card">';
            html += '<div class="view-menu-category-head">';
            html += '<div class="view-menu-category-title-wrap">';
            html += '<span class="view-menu-category-icon"><i class="fas ' + iconClass + '"></i></span>';
            html += '<span class="view-menu-category-title">' + escapeHtml(line.label || 'Category') + '</span>';
            html += '</div>';
            html += '<span class="view-menu-category-count">' + countLabel + '</span>';
            html += '</div>';

            html += '<ul class="view-menu-items-list">';
            items.forEach(function(dish) {
                const match = dish.match(/^(.*?)\s*(\([^\)]+\))$/);
                html += '<li class="view-menu-item-row">';
                html += '<span class="view-menu-item-dot"></span>';
                html += '<div class="view-menu-item-content">';
                if (match && match[1].trim()) {
                    html += '<span class="view-menu-dish-name">' + escapeHtml(match[1].trim()) + '</span>';
                    html += '<span class="view-menu-dish-portion">' + escapeHtml(match[2].trim()) + '</span>';
                } else {
                    html += '<span class="view-menu-dish-name">' + escapeHtml(dish) + '</span>';
                }
                html += '</div>';
                html += '</li>';
            });
            html += '</ul>';
            html += '</div>';
        });
        html += '</div>';
    } else if (details === '') {
        html = '<div class="view-menu-empty">Menu details available when booking this set.</div>';
    }
    body.innerHTML = html;

    bookBtn.onclick = function() {
        closeViewMenuModal();
        openBookThisServiceModal(parseInt(productId), parseInt(storeId), setName || '');
    };

    modal.classList.add('active');
    document.body.style.overflow = 'hidden';
}

function closeViewMenuModal(event) {
    const modal = document.getElementById('viewMenuModal');
    if (!modal) return;
    if (event && !event.target.classList.contains('view-menu-modal') && !event.target.closest('.view-menu-close')) {
        return;
    }
    modal.classList.remove('active');
    document.body.style.overflow = '';
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

</script>

<!-- Order Modal -->
<div id="orderModal" class="modal-overlay" style="display: none;">
    <div class="modal-content" id="modalContentBox">
        <div class="modal-header">
            <h2 id="modalTitle">Place Order</h2>
            <button type="button" class="close-btn" onclick="closeOrderModal()">&times;</button>
        </div>
        <div class="modal-body">
            <!-- Normal Product View -->
            <div id="normalView">
                <form action="" method="POST" id="orderForm">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="store_id" value="<?php echo $store_id; ?>">
                    <input type="hidden" name="product_id" id="modal_product_id">
                    
                    <div style="display: flex; gap: 15px; margin-bottom: 20px; align-items: center;">
                        <img id="modal_product_image" src="" style="width: 80px; height: 80px; border-radius: 8px; object-fit: cover; border: 1px solid var(--border-color);">
                        <div>
                            <h3 id="modal_product_name" style="margin-bottom: 5px; color: var(--accent-gold);">Product Name</h3>
                            <div id="modal_product_price" style="font-weight: 700;">?0.00</div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Quantity</label>
                        <input type="number" name="quantity" class="form-control" value="1" min="1" required onchange="updateTotal()">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Booking Date</label>
                        <input type="date" name="delivery_date" class="form-control" required min="<?php echo max(date('Y-m-d'), $current_year_start); ?>" max="<?php echo $current_year_end; ?>">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Delivery Time</label>
                        <input type="time" name="delivery_time" class="form-control" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Delivery Address</label>
                        <input type="text" name="delivery_address" class="form-control" required placeholder="Full address">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Payment Method</label>
                        <select name="payment_method" class="form-control" required>
                            <option value="GCash" selected>GCash</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Notes (Optional)</label>
                        <textarea name="notes" class="form-control" rows="3" placeholder="Special instructions..."></textarea>
                    </div>

                    <button type="submit" class="btn btn-primary" style="width: 100%;">Confirm Order</button>
                </form>
            </div>

            <!-- Catering Split View -->
            <div id="cateringView" style="display: none;">
                <!-- Left: Details -->
                <div style="border-right: 1px solid var(--border-color); padding-right: 30px; overflow-y: auto; height: 100%;">
                    <img id="cat_product_image" src="" style="width: 100%; object-fit: cover; border-radius: 12px; margin-bottom: 25px; border: 1px solid var(--border-color);">
                    <h2 id="cat_product_name" style="color: var(--accent-gold); margin-bottom: 15px;">Wedding Package</h2>
                    <div id="cat_product_price" style="font-weight: 700; margin-bottom: 25px;">?150,000.00</div>
                    
                    <h4 style="color: var(--text-muted); margin-bottom: 15px; font-size: 18px;">Description & Menu Packages</h4>
                    <div id="cat_product_desc" style="white-space: pre-wrap; color: var(--text-main); margin-bottom: 30px;">
                        <!-- Description will be inserted here -->
                    </div>
                    
                    <!-- Service Details (Catering Details) -->
                    <div id="cat_service_details_container" style="border-top: 1px solid var(--border-color); padding-top: 30px; margin-top: 30px; display: none;">
                        <h4 style="color: var(--accent-gold); margin-bottom: 20px; font-size: 18px;"><i class="fas fa-concierge-bell"></i> Service Details</h4>
                        <div id="cat_service_details" style="white-space: pre-wrap; color: var(--text-main); line-height: 1.8;">
                            <!-- Service details will be inserted here -->
                        </div>
                    </div>

                    <!-- Sample Menus (Dynamic) -->
                    <div class="sample-menus" style="border-top: 1px solid var(--border-color); padding-top: 30px; margin-top: 30px;">
                        <h4 style="color: var(--accent-gold); margin-bottom: 20px; font-size: 18px;">Available Menu Sets</h4>
                        <!-- Sets will be populated via JS -->
                    </div>
                </div>

                <!-- Right: Booking Form -->
                <div style="overflow-y: auto; height: 100%; padding-left: 10px; padding-right: 10px;">
                    <h3 style="margin-bottom: 25px; color: var(--accent-gold); font-size: 22px;">Book This Service</h3>
                    <form action="" method="POST" id="catOrderForm" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <input type="hidden" name="store_id" value="<?php echo $store_id; ?>">
                        <input type="hidden" name="product_id" id="cat_product_id">
                        <input type="hidden" name="total_amount" id="cat_total_amount" value="0">
                        <input type="hidden" name="add_ons" id="cat_add_ons" value="[]">
                        <input type="hidden" name="swaps" id="cat_swaps" value="[]">
                        
                        <!-- Package Selection Logic (simulated) -->
                        <div class="form-group" style="margin-bottom: 25px;">
                            <label class="form-label" style="font-size: 16px; margin-bottom: 10px;">Select Package / Set</label>
                            <select name="notes_package" id="notes_package" class="form-control" required onchange="renderMenuCheckboxes(); calculateTotal()" style="padding: 15px; font-size: 15px;">
                                <option value="" data-price="0">-- Choose a Set --</option>
                            </select>
                            <small style="display: block; margin-top: 8px; font-size: 13px;">Refer to the description on the left for package details.</small>
                            
                            <!-- Dynamic Menu Selection Container -->
                            <div id="menu_customization_container"></div>
                        </div>

                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                            <div class="form-group" style="margin-bottom: 25px;">
                                <label class="form-label" style="font-size: 15px;">Event Date</label>
                                <input type="date" name="delivery_date" class="form-control" required min="<?php echo max(date('Y-m-d'), $current_year_start); ?>" max="<?php echo $current_year_end; ?>" style="padding: 12px;">
                            </div>

                            <div class="form-group" style="margin-bottom: 25px;">
                                <label class="form-label" style="font-size: 15px;">Event Time</label>
                                <input type="time" name="delivery_time" class="form-control" required style="padding: 12px;">
                            </div>
                        </div>
                        
                        <div class="form-group" style="margin-bottom: 25px;">
                            <label class="form-label" style="font-size: 15px;">Number of Person</label>
                            <input type="number" name="quantity" id="quantity" class="form-control" placeholder="Enter number of guests" min="<?php echo (int) ($caterer['minimum_booking_guests'] ?? 1); ?>" max="<?php echo (int) ($caterer['maximum_booking_guests'] ?? 100000); ?>" step="1" required onchange="calculateTotal()" onkeyup="calculateTotal()" style="padding: 12px;">
                        </div>

                        <!-- Total Calculation Display -->
                        <div style="background: rgba(212, 175, 55, 0.08); padding: 25px; border-radius: 12px; border: 1px solid var(--accent-gold); margin-bottom: 30px;">
                            <div style="display: flex; justify-content: space-between; margin-bottom: 10px;">
                                <span style="font-size: 16px;">Estimated Total:</span>
                                <strong id="display_total" style="color: var(--accent-gold); font-size: 24px;">?0.00</strong>
                            </div>
                            <div style="display: flex; justify-content: space-between; font-size: 15px; color: var(--text-muted); margin-bottom: 10px;">
                                <span>Required Downpayment (50%):</span>
                                <strong id="display_downpayment">?0.00</strong>
                            </div>
                            <p style="font-size: 12px; color: var(--text-muted); font-style: italic; margin: 0; border-top: 1px solid rgba(255,255,255,0.1); padding-top: 10px;">
                                * Note: This is an estimated price based on the selected set. The caterer may adjust the final price based on your specific requirements.
                            </p>
                        </div>

                        <div class="form-group" style="margin-bottom: 25px;">
                            <label class="form-label" style="font-size: 15px;">Event Venue / Address</label>
                            <input type="text" name="delivery_address" class="form-control" required placeholder="Full venue address" style="padding: 12px;">
                        </div>

                        <div class="form-group" style="margin-bottom: 25px;">
                            <label class="form-label" style="font-size: 15px;">Payment Method</label>
                            <select name="payment_method" id="payment_method" class="form-control" required onchange="toggleGCash()" style="padding: 12px;">
                                <option value="GCash" selected>GCash</option>
                            </select>
                        </div>

                        <!-- GCash Section -->
                        <div id="gcash_section" style="display: none; margin-top: 20px; border-top: 1px solid var(--border-color); padding-top: 25px; background: rgba(255,255,255,0.03); padding: 25px; border-radius: 12px;">
                            <h4 style="color: var(--accent-gold); margin-bottom: 15px; text-align: center; font-size: 18px;">Scan to Pay Downpayment</h4>
                            <div style="text-align: center; margin-bottom: 20px;">
                                <img src="../<?php echo !empty($caterer['gcash_qr_image']) ? $caterer['gcash_qr_image'] : 'uploads/qr/default_qr.png'; ?>" style="width: 220px; height: 220px; object-fit: cover; border-radius: 12px; border: 4px solid white; box-shadow: 0 5px 15px rgba(0,0,0,0.3);">
                                <p style="margin-top: 15px; color: #fff; font-weight: bold; font-size: 20px; letter-spacing: 1px;">
                                    <?php echo htmlspecialchars($caterer['gcash_number'] ?? '09XX-XXX-XXXX'); ?>
                                </p>
                                <p style="font-size: 14px; color: var(--text-muted); margin-top: 5px;">Please upload your payment screenshot below.</p>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Upload Payment Receipt <span style="color: var(--error-color);">*</span></label>
                                <input type="file" name="payment_screenshot" id="payment_screenshot" class="form-control" accept="image/*" style="padding: 10px;">
                            </div>
                        </div>

                        <div class="form-group" style="margin-bottom: 30px;">
                            <label class="form-label" style="font-size: 15px;">Custom Requests / Menu Swaps</label>
                            <textarea name="notes" class="form-control" rows="4" placeholder="Example: &quot;I want Set A, but please add the Leche Flan from Set B&quot; or &quot;Swap the Pork dish with the Beef dish from Set B&quot;. (Caterer will confirm final price)" style="padding: 12px;"></textarea>
                        </div>

                        <button type="submit" class="btn btn-primary" style="width: 100%; padding: 18px; font-size: 18px; text-transform: uppercase; letter-spacing: 1px; font-weight: 600;">Submit Booking Request</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="../assets/js/booking-pricing.js?v=<?php echo time(); ?>"></script>
<script>
    function switchTab(tabName) {
        // Hide all tabs
        document.querySelectorAll('.tab-content').forEach(tab => {
            tab.classList.remove('active');
        });
        document.querySelectorAll('.section-tab').forEach(btn => {
            btn.classList.remove('active');
        });

        // Show selected tab
        document.getElementById(tabName + '-tab').classList.add('active');
        
        // Activate button - find button with onclick containing tabName
        const buttons = document.querySelectorAll('.section-tab');
        buttons.forEach(btn => {
            if(btn.getAttribute('onclick').includes(tabName)) {
                btn.classList.add('active');
            }
        });
    }

    function openOrderModal(product) {
        const modalContentBox = document.getElementById('modalContentBox');
        const normalView = document.getElementById('normalView');
        const cateringView = document.getElementById('cateringView');
        const modalTitle = document.getElementById('modalTitle');

        // Fix image path - check 'image' property from DB
        let imgPath = '../uploads/products/default.png';
        
        if (product.image) {
            if (product.image.startsWith('../') || product.image.startsWith('http')) {
                imgPath = product.image;
            } else {
                imgPath = '../' + product.image;
            }
        } else if (product.image_path) {
            // Fallback for legacy support if any
            if (product.image_path.startsWith('../') || product.image_path.startsWith('http')) {
                imgPath = product.image_path;
            } else {
                imgPath = '../' + product.image_path;
            }
        }

        if (product.category === 'catering_service') {
            openBookThisServiceModal(product.id, <?php echo intval($store_id); ?>);
            return;
            // CATERING MODE (Split View)
            modalContentBox.style.maxWidth = '95%'; // Wider modal (was 1400px)
            modalContentBox.style.width = '95%'; // Ensure it takes up space
            modalContentBox.style.height = '90vh'; // Taller modal
            modalContentBox.style.maxHeight = '95vh'; 
            modalContentBox.style.display = 'flex';
            modalContentBox.style.flexDirection = 'column';
            
            // Adjust modal body for full height
            const modalBody = modalContentBox.querySelector('.modal-body');
            modalBody.style.flex = '1';
            modalBody.style.overflow = 'hidden'; // Handle scroll in columns
            modalBody.style.padding = '0'; // Remove default padding to handle in grid
            modalBody.style.display = 'grid';
            modalBody.style.gridTemplateColumns = '1fr 1fr';
            modalBody.style.gap = '60px';

            modalTitle.textContent = 'Service Details & Booking';
            normalView.style.display = 'none';
            cateringView.style.display = 'block';
            cateringView.style.height = '100%';
            cateringView.style.padding = '30px'; // Add padding to the grid container instead

            // Populate Details
            document.getElementById('cat_product_id').value = product.id;
            document.getElementById('cat_product_name').textContent = product.name;
            document.getElementById('cat_product_name').style.fontSize = '28px'; // Larger title
            
            // Format price range if sets exist
            if (product.sets && product.sets.length > 0) {
                let minPrice = Math.min(...product.sets.map(s => parseFloat(s.price)));
                document.getElementById('cat_product_price').textContent = 'Starts at ?' + minPrice.toFixed(2);
            } else {
                document.getElementById('cat_product_price').textContent = 'Price Varies';
            }
            document.getElementById('cat_product_price').style.fontSize = '22px'; // Larger price
            
            document.getElementById('cat_product_desc').textContent = product.description;
            document.getElementById('cat_product_desc').style.fontSize = '16px'; // Larger text
            document.getElementById('cat_product_desc').style.lineHeight = '1.8'; // Better spacing
            
            // Show Service Details if available
            const serviceDetailsContainer = document.getElementById('cat_service_details_container');
            const serviceDetailsDiv = document.getElementById('cat_service_details');
            if (product.catering_details && product.catering_details.trim() !== '') {
                serviceDetailsDiv.textContent = product.catering_details;
                serviceDetailsContainer.style.display = 'block';
            } else {
                serviceDetailsContainer.style.display = 'none';
            }
            
            document.getElementById('cat_product_image').src = imgPath;
            document.getElementById('cat_product_image').style.height = '350px'; // Taller image

            // DYNAMICALLY POPULATE SETS
            const setsContainer = document.querySelector('.sample-menus');
            const selectPackage = document.getElementById('notes_package');
            
            // Clear existing
            setsContainer.innerHTML = '<h4 style="color: var(--accent-gold); margin-bottom: 25px; font-size: 20px;">Available Menu Sets</h4>';
            selectPackage.innerHTML = '<option value="" data-price="0">-- Choose a Set --</option>';
            
            // NEW: Extract all unique items for customization
            let allItems = new Set();
            
            if (product.sets && product.sets.length > 0) {
                product.sets.forEach(set => {
                    // Split by comma, newline, bullet, dash
                    let items = set.description.split(/[,\\n�\-\r]+/);
                    items.forEach(i => {
                        let clean = i.trim();
                        // Filter out short noise and price info if attached
                        if(clean && clean.length > 2 && !clean.toLowerCase().includes('php') && !clean.includes('?')) {
                            allItems.add(clean);
                        }
                    });

                    // Add to Left Panel (Display)
                    const setPax = parseInt(set.pax || 0, 10);
                    const setPrice = parseFloat(set.price || 0);
                    const setPerHead = parseFloat(set.per_head_price || 0);
                    let setPriceLabel = setPax > 0
                        ? `?${setPrice.toFixed(2)} Good for ${setPax} person${setPerHead > 0 ? ` / ?${setPerHead.toFixed(2)} per pax` : ''}`
                        : `?${(setPerHead > 0 ? setPerHead : setPrice).toFixed(2)} per pax`;
                    setPriceLabel = setPerHead > 0
                        ? `\u20b1${setPerHead.toFixed(2)} per pax`
                        : `\u20b1${setPrice.toFixed(2)} Package Set${setPax > 0 ? ` / Good for ${setPax} person` : ''}`;

                    const setHtml = `
                        <div style="margin-bottom: 30px; background: rgba(255,255,255,0.03); padding: 25px; border-radius: 12px; border-left: 5px solid var(--accent-gold);">
                            <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 15px;">
                                <strong style="color: #fff; font-size: 18px;">${set.set_name}</strong>
                                <span style="color: var(--accent-gold); font-weight: bold; font-size: 16px;">${setPriceLabel}</span>
                            </div>
                            <div style="color: var(--text-muted); font-size: 16px; line-height: 1.8; white-space: pre-wrap;">${set.description}</div>
                        </div>
                    `;
                    setsContainer.insertAdjacentHTML('beforeend', setHtml);
                    
                    // Add to Dropdown
                    const option = document.createElement('option');
                    option.value = set.set_name;
                    option.setAttribute('data-price', set.price);
                    option.setAttribute('data-pax', setPax > 0 ? String(setPax) : '0');
                    option.setAttribute('data-per-head', setPerHead > 0 ? String(setPerHead) : '0');
                    option.textContent = `${set.set_name} - ${setPriceLabel}`;
                    selectPackage.appendChild(option);
                });
            } else {
                setsContainer.insertAdjacentHTML('beforeend', '<p style="color: var(--text-muted);">No specific sets defined. Please contact us for a custom package.</p>');
            }

            // Caterer data for checkboxes
            document.getElementById('catOrderForm').dataset.allItems = JSON.stringify(Array.from(allItems));
            document.getElementById('catOrderForm').dataset.sets = JSON.stringify(product.sets || []);
            
            // Reset customization container
            document.getElementById('menu_customization_container').innerHTML = '';

        } else {
            // NORMAL MODE (Food Items)
            modalContentBox.style.maxWidth = '600px'; // Slightly wider
            modalContentBox.style.width = 'auto';
            modalContentBox.style.height = 'auto';
            modalContentBox.style.display = 'block';
            
            // Reset modal body styles
            const modalBody = modalContentBox.querySelector('.modal-body');
            modalBody.style.flex = 'none';
            modalBody.style.overflow = 'visible';
            modalBody.style.padding = '20px'; // Restore padding
            modalBody.style.display = 'block';

            modalTitle.textContent = 'Place Order';
            normalView.style.display = 'block';
            cateringView.style.display = 'none';

            // Populate Details
            document.getElementById('modal_product_id').value = product.id;
            document.getElementById('modal_product_name').textContent = product.name;
            document.getElementById('modal_product_price').textContent = '?' + parseFloat(product.price).toFixed(2);
            document.getElementById('modal_product_image').src = imgPath;
        }
        
        document.getElementById('orderModal').style.display = 'flex';
    }

    function closeOrderModal() {
        document.getElementById('orderModal').style.display = 'none';
    }

    function updateIncludedVisuals() {
        // Reset all menu items to default state
        const menuItems = document.querySelectorAll('[id^="menu_item_"]');
        menuItems.forEach(item => {
            const original = item.getAttribute('data-original');
            item.style.background = 'rgba(46, 204, 113, 0.15)';
            item.style.borderColor = 'rgba(46, 204, 113, 0.3)';
            const icon = item.querySelector('i');
            const text = item.querySelector('.item-text');
            if (icon) {
                icon.className = 'fas fa-check-circle';
                icon.style.color = '#2ecc71';
            }
            if (text) {
                text.innerHTML = original;
                text.style.color = '#2ecc71';
            }
        });

        // Apply active swaps
        document.querySelectorAll('.swap-radio:checked').forEach(rb => {
            // Find target item based on original name
            const originalName = rb.getAttribute('data-original');
            const newItemName = rb.getAttribute('data-new');
            
            // Find the menu item that matches the original name
            const menuItems = document.querySelectorAll('[id^="menu_item_"]');
            menuItems.forEach(item => {
                const itemOriginal = item.getAttribute('data-original');
                const target = (originalName || '').toLowerCase().trim();
                const targetBase = target.replace(/\(.*?\)/g, '').trim();
                const current = (itemOriginal || '').toLowerCase().trim();
                const currentBase = current.replace(/\(.*?\)/g, '').trim();
                
                if (itemOriginal === originalName || (target && current === target) || (targetBase && currentBase && (currentBase === targetBase || currentBase.includes(targetBase) || targetBase.includes(currentBase)))) {
                    // Visual update for swap - change to purple style
                    item.style.background = 'rgba(155, 89, 182, 0.15)';
                    item.style.borderColor = 'rgba(155, 89, 182, 0.3)';
                    const icon = item.querySelector('i');
                    const text = item.querySelector('.item-text');
                    if (icon) {
                        icon.className = 'fas fa-exchange-alt';
                        icon.style.color = '#9b59b6';
                    }
                    if (text) {
                        text.innerHTML = `<span style="text-decoration: line-through; opacity: 0.6;">${itemOriginal}</span> <i class="fas fa-arrow-right" style="font-size: 11px; margin: 0 6px; color: #9b59b6;"></i> <strong style="color: #9b59b6;">${newItemName}</strong>`;
                        text.style.color = '#9b59b6';
                    }
                }
            });
        });
    }

    function renderMenuCheckboxes() {
        const packageSelect = document.getElementById('notes_package');
        const container = document.getElementById('menu_customization_container');
        
        if (!packageSelect || !container) return;

        const selectedSetName = packageSelect.value;
        const setsData = JSON.parse(document.getElementById('catOrderForm').dataset.sets || '[]');
        
        // Find selected set
        let selectedSet = null;
        if (selectedSetName) {
            selectedSet = setsData.find(s => s.set_name === selectedSetName);
        }
        
        // Parse explicit Add-ons and Swaps - Use per-set data
        let explicitAddons = [];
        let explicitSwaps = [];
        
        if (selectedSet) {
            explicitAddons = selectedSet.addons || [];
            explicitSwaps = selectedSet.swaps || [];
        }
        
        if (!selectedSetName) {
            container.innerHTML = '';
            return;
        }

        let includedItems = [];
        if (selectedSet && selectedSet.description) {
            // Split by newlines first, then by commas for better parsing
            includedItems = selectedSet.description
                .split(/\r?\n/)  // Split by newlines first
                .flatMap(line => line.split(/[,�]/))  // Then split each line by comma or bullet
                .map(i => i.trim())
                .filter(i => i.length > 1 && !i.toLowerCase().includes('php') && !i.includes('?'));
        }

        let html = '<div style="margin-top: 15px; margin-bottom: 25px; padding: 20px; background: rgba(255,255,255,0.03); border-radius: 12px; border: 1px dashed var(--border-color);">';
        
        // ==========================
        // 1. MENU ITEMS FROM SELECTED SET (DISPLAY ONLY - GREEN)
        // ==========================
        html += '<h5 style="color: var(--accent-gold); margin-bottom: 15px; font-size: 16px;"><i class="fas fa-utensils"></i> Menu Items in ' + selectedSetName + ':</h5>';
        if (includedItems.length > 0) {
            html += '<div style="margin-bottom: 25px; display: flex; flex-direction: column; gap: 10px;" id="menu_items_area">';
            includedItems.forEach((item, index) => {
                html += `
                    <div id="menu_item_${index}" data-original="${item}" style="display: flex; align-items: center; gap: 12px; padding: 12px 15px; border-radius: 8px; background: rgba(46, 204, 113, 0.15); border: 1px solid rgba(46, 204, 113, 0.3); transition: all 0.3s;">
                        <i class="fas fa-check-circle" style="color: #2ecc71; font-size: 16px;"></i>
                        <span class="item-text" style="font-size: 14px; color: #2ecc71; font-weight: 500;">${item}</span>
                    </div>
                `;
            });
            html += '</div>';
        } else {
            html += '<p style="color: var(--text-muted); font-size: 14px; margin-bottom: 20px;">No specific menu items listed for this set.</p>';
        }

        // ==========================
        // 2. ADD-ONS (CHECKBOXES - BLUE)
        // ==========================
        if (explicitAddons.length > 0) {
            html += '<h5 style="color: var(--accent-gold); margin-bottom: 15px; font-size: 16px;"><i class="fas fa-plus-circle"></i> Add-On Items:</h5>';
            html += '<div style="margin-bottom: 25px; display: flex; flex-direction: column; gap: 10px;" id="add_ons_area">';
            
            explicitAddons.forEach((addon, index) => {
                const addonId = 'addon_' + index;
                html += `
                    <div style="display: flex; align-items: center; gap: 12px; padding: 12px 15px; border-radius: 8px; background: rgba(52, 152, 219, 0.15); border: 1px solid rgba(52, 152, 219, 0.3); transition: all 0.3s;">
                        <input type="checkbox" id="${addonId}" value="${addon.name}" data-price="${addon.price}" class="addon-checkbox" style="width: 18px; height: 18px;" onchange="updateIncludedVisuals(); calculateTotal()">
                        <label for="${addonId}" style="flex: 1; font-size: 14px; color: #3498db; font-weight: 500; cursor: pointer;">
                            ${addon.name} <span style="color: var(--accent-gold); font-weight: normal;">(+?${parseFloat(addon.price).toFixed(2)})</span>
                        </label>
                    </div>
                `;
            });
            
            html += '</div>';
        }

        // ==========================
        // 3. SWAPS
        if (explicitSwaps.length > 0) {
            html += '<h5 style="color: var(--accent-gold); margin-bottom: 15px; font-size: 16px;"><i class="fas fa-exchange-alt"></i> Menu Swaps:</h5>';
            html += '<div style="margin-bottom: 25px; display: flex; flex-direction: column; gap: 12px;" id="swaps_area">';
            
            // Group swaps by category
            const categorizedSwaps = {};
            explicitSwaps.forEach((swap, index) => {
                const cat = swap.category || 'Other Swaps';
                if (!categorizedSwaps[cat]) categorizedSwaps[cat] = [];
                categorizedSwaps[cat].push({ swap, index });
            });

            Object.entries(categorizedSwaps).forEach(([category, swaps]) => {
                html += `<div style="margin-top: 10px; font-size: 14px; color: var(--accent-gold); font-weight: 600; border-bottom: 1px solid rgba(255,255,255,0.1); padding-bottom: 5px;">${escapeHtml(category)}</div>`;
                
                swaps.forEach(({swap, index}) => {
                    const swapId = 'swap_' + index;
                    const orig = swap.original_item || swap.original || '';
                    const replacement = swap.replacement || swap.new || '';
                    const price = parseFloat(swap.price || 0);

                    if (swap.options && Array.isArray(swap.options) && swap.options.length > 0) {
                        html += `
                            <div style="padding: 15px; border-radius: 8px; background: rgba(155, 89, 182, 0.15); border: 1px solid rgba(155, 89, 182, 0.3);">
                                <div style="font-size: 14px; color: #9b59b6; font-weight: 600; margin-bottom: 12px;">
                                    Swap ${escapeHtml(orig)} for:
                                </div>
                                <div style="display: flex; flex-direction: column; gap: 8px;">
                                    ${swap.options.map((opt, optIdx) => {
                                        const optId = swapId + '_opt_' + optIdx;
                                        return `
                                            <div style="display: flex; align-items: center; gap: 12px;">
                                                <input type="radio" name="${swapId}_options" id="${optId}" value="${escapeHtml(opt.name)}" data-original="${escapeHtml(orig)}" data-new="${escapeHtml(opt.name)}" data-price="${parseFloat(opt.price || 0)}" class="swap-radio" style="width: 16px; height: 16px;" onchange="updateIncludedVisuals(); calculateTotal()">
                                                <label for="${optId}" style="flex: 1; font-size: 13px; color: #9b59b6; cursor: pointer;">
                                                    ${escapeHtml(opt.name)} <span style="color: var(--accent-gold); font-weight: normal;">(+?${parseFloat(opt.price || 0).toFixed(2)})</span>
                                                </label>
                                            </div>
                                        `;
                                    }).join('')}
                                </div>
                            </div>
                        `;
                    } else if (orig || replacement) {
                        html += `
                            <div style="display: flex; align-items: center; gap: 12px; padding: 12px 15px; border-radius: 8px; background: rgba(155, 89, 182, 0.15); border: 1px solid rgba(155, 89, 182, 0.3); transition: all 0.3s;">
                                <input type="checkbox" id="${swapId}" value="${escapeHtml(replacement)}" data-original="${escapeHtml(orig)}" data-new="${escapeHtml(replacement)}" data-price="${price}" class="swap-checkbox swap-radio" style="width: 18px; height: 18px; accent-color: #9b59b6;" onchange="updateIncludedVisuals(); calculateTotal()">
                                <label for="${swapId}" style="flex: 1; font-size: 13px; color: #fff; font-weight: 500; cursor: pointer;">
                                    Swap <span style="color: #e74c3c; font-weight: 600;">${escapeHtml(orig)}</span> <i class="fas fa-arrow-right" style="color: var(--accent-gold); margin: 0 4px; font-size: 11px;"></i> <span style="color: #2ecc71; font-weight: 700;">${escapeHtml(replacement)}</span>
                                    <span style="color: var(--accent-gold); font-weight: normal; margin-left: 8px;">(${price > 0 ? '+?' + price.toFixed(2) : 'Free'})</span>
                                </label>
                            </div>
                        `;
                    }
                });
            });
            
            html += '</div>';
        }

        html += '</div>';
        container.innerHTML = html;
        
        // Add animation style if not exists
        if (!document.getElementById('fadeInStyle')) {
            const style = document.createElement('style');
            style.id = 'fadeInStyle';
            style.textContent = '@keyframes fadeIn { from { opacity: 0; transform: translateY(-5px); } to { opacity: 1; transform: translateY(0); } }';
            document.head.appendChild(style);
        }
    }


    function calculateTotal() {
        var packageSelect = document.getElementById('notes_package');
        var quantityInput = document.getElementById('quantity');
        var totalDisplay = document.getElementById('display_total');
        var downpaymentDisplay = document.getElementById('display_downpayment');
        var totalInput = document.getElementById('cat_total_amount');
        var addOnsInput = document.getElementById('cat_add_ons');
        var swapsInput = document.getElementById('cat_swaps');

        let addOns = [];
        document.querySelectorAll('.addon-checkbox:checked').forEach(cb => {
            const name = cb.value;
            const price = parseFloat(cb.getAttribute('data-price') || 0);
            addOns.push({ name: name, price: price });
        });
        
        let swaps = [];
        document.querySelectorAll('.swap-radio:checked').forEach(rb => {
            const original = rb.getAttribute('data-original');
            const newName = rb.getAttribute('data-new');
            const price = parseFloat(rb.getAttribute('data-price') || 0);
            swaps.push({ original: original, new: newName, price: price });
        });
        
        if (addOnsInput) {
            addOnsInput.value = JSON.stringify(addOns);
        }
        if (swapsInput) {
            swapsInput.value = JSON.stringify(swaps);
        }
        
        var selectedOption = packageSelect && packageSelect.selectedIndex >= 0
            ? packageSelect.options[packageSelect.selectedIndex]
            : null;
        var orderForm = document.getElementById('catOrderForm');
        var setsData = JSON.parse(orderForm && orderForm.dataset ? (orderForm.dataset.sets || '[]') : '[]');
        var selectedSet = selectedOption
            ? setsData.find(function (set) { return set && set.set_name === selectedOption.value; })
            : null;
        var quantity = quantityInput ? parseInt(quantityInput.value, 10) : 0;

        if (!selectedOption || isNaN(quantity) || quantity <= 0) {
            if(totalDisplay) totalDisplay.textContent = '?0.00';
            if(downpaymentDisplay) downpaymentDisplay.textContent = '?0.00';
            if(totalInput) totalInput.value = '0';
            return;
        }

        var calculated = window.BookingPricing.calculate({
            quantity: quantity,
            selectedSetPrice: selectedOption.getAttribute('data-price') || 0,
            selectedSetPax: selectedOption.getAttribute('data-pax') || (selectedSet ? selectedSet.pax : 0),
            selectedSetPerHeadPrice: selectedOption.getAttribute('data-per-head') || (selectedSet ? selectedSet.per_head_price : 0),
            addons: addOns,
            swaps: swaps
        });

        if(totalDisplay) totalDisplay.textContent = '?' + calculated.totalAmount.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        if(downpaymentDisplay) downpaymentDisplay.textContent = '?' + calculated.downPaymentRequired.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        if(totalInput) totalInput.value = calculated.totalAmount.toFixed(2);
    }

    function toggleGCash() {
        var paymentMethod = document.getElementById('payment_method'); // This targets the catering form select
        var gcashSection = document.getElementById('gcash_section');
        var paymentScreenshot = document.getElementById('payment_screenshot');
        
        if (paymentMethod && gcashSection) {
            if (paymentMethod.value === 'GCash') {
                gcashSection.style.display = 'block';
                if(paymentScreenshot) paymentScreenshot.required = true;
            } else {
                gcashSection.style.display = 'none';
                if(paymentScreenshot) paymentScreenshot.required = false;
            }
        }
    }

    function updateTotal() {
        // Simple client-side calc if needed
    }

    // Close modal when clicking outside
    window.onclick = function(event) {
        if (event.target == document.getElementById('orderModal')) {
            closeOrderModal();
        }
    }

    // Lock the regular order button immediately after the first valid submit.
    const orderForm = document.getElementById('orderForm');
    if (orderForm) {
    orderForm.addEventListener('submit', function(e) {
        if (this.dataset.submitting === 'true') {
            e.preventDefault();
            return;
        }
        this.dataset.submitting = 'true';
        const submitButton = this.querySelector('button[type="submit"]');
        if (submitButton) {
            submitButton.disabled = true;
            submitButton.textContent = 'Submitting...';
        }
    });
    }
    
    // Handle Cat Order Form Note Merging
    const catOrderForm = document.getElementById('catOrderForm');
    if (catOrderForm) {
    catOrderForm.addEventListener('submit', function(e) {
        const submitButton = this.querySelector('button[type="submit"]');
        if (this.dataset.submitting === 'true') {
            e.preventDefault();
            return;
        }
        this.dataset.submitting = 'true';
        if (submitButton) {
            submitButton.disabled = true;
            submitButton.textContent = 'Submitting...';
        }
        const packageVal = this.querySelector('[name="notes_package"]').value;
        const notesVal = this.querySelector('[name="notes"]').value;
        
        // Collect Add-ons
        const addOns = [];
        document.querySelectorAll('.addon-checkbox:checked').forEach(cb => {
            const price = cb.getAttribute('data-price');
            addOns.push(`${cb.value} (+?${price})`);
        });
        
        // Collect Swaps
        const swaps = [];
        document.querySelectorAll('.swap-radio:checked').forEach(rb => {
            const original = rb.getAttribute('data-original');
            const newName = rb.getAttribute('data-new');
            const price = rb.getAttribute('data-price');
            swaps.push(`Swap ${original} for ${newName} (+?${price})`);
        });
        
        let finalNotes = "Package: " + packageVal;
        
        if (addOns.length > 0) {
            finalNotes += "\n\nAdded Items:\n- " + addOns.join("\n- ");
        }
        
        if (swaps.length > 0) {
            finalNotes += "\n\nMenu Swaps:\n- " + swaps.join("\n- ");
        }
        
        finalNotes += "\n\nCustom Notes:\n" + notesVal;
        
        // Update notes field
        this.querySelector('[name="notes"]').value = finalNotes;
    });
    }

    // ==============================================
    // TOGGLE FAVORITE
    // ==============================================
    function toggleFavorite(storeId) {
        const btn = document.querySelector(`.favorite-btn[data-caterer-id="${storeId}"]`);
        if (!btn) {
            return;
        }
        const icon = btn.querySelector('i');
        
        // Disable button while loading
        btn.disabled = true;
        btn.style.opacity = '0.7';

        const formData = new FormData();
        formData.append('action', 'toggle_favorite');
        formData.append('store_id', storeId);
        formData.append('csrf_token', '<?php echo $csrf_token; ?>');

        fetch('browse-caterer.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                if (data.is_favorite) {
                    icon.classList.remove('far');
                    icon.classList.add('fas');
                    icon.style.color = '#ffd700';
                } else {
                    icon.classList.remove('fas');
                    icon.classList.add('far');
                    icon.style.color = '#fff';
                }
            } else {
                alert(data.message || 'Error updating favorite.');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error updating favorite.');
        })
        .finally(() => {
            btn.disabled = false;
            btn.style.opacity = '1';
        });
    }
    // Handle hash scroll on load (for specific services)
    window.addEventListener('load', function() {
        if (window.location.hash) {
            const target = document.querySelector(window.location.hash);
            if (target) {
                setTimeout(() => {
                    const headerOffset = 100; // Account for sticky header
                    const elementPosition = target.getBoundingClientRect().top;
                    const offsetPosition = elementPosition + window.pageYOffset - headerOffset;
                    
                    window.scrollTo({
                        top: offsetPosition,
                        behavior: 'smooth'
                    });
                    
                    // Highlight the specific service briefly
                    target.style.transition = 'background-color 1s ease';
                    target.style.backgroundColor = 'rgba(212, 175, 55, 0.15)';
                    setTimeout(() => {
                        target.style.backgroundColor = 'transparent';
                    }, 2000);
                }, 300);
            }
        }
    });
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

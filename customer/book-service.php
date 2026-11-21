<?php
require_once __DIR__ . '/../config.php';
/** @var mysqli $conn */
require_once __DIR__ . '/../includes/payment_functions.php';
require_once __DIR__ . '/../includes/booking_flow_helper.php';
require_once __DIR__ . '/../includes/booking_pricing_helper.php';
require_once __DIR__ . '/../includes/booking_guest_count.php';
requireLogin();

function ensureStoreTermsColumn($conn) {
    $check = mysqli_query($conn, "SHOW COLUMNS FROM store_details LIKE 'store_terms_conditions'");
    if ($check && mysqli_num_rows($check) === 0) {
        @mysqli_query($conn, "ALTER TABLE store_details ADD COLUMN store_terms_conditions TEXT NULL");
    }
}

function getPortalTermsContent($conn) {
    $default_terms = "By placing a booking, you agree to the portal's booking, payment, and dispute handling policies.";
    $query = mysqli_query($conn, "SELECT setting_value FROM system_settings WHERE setting_key = 'terms_content' LIMIT 1");
    if ($query && ($row = mysqli_fetch_assoc($query)) && !empty(trim($row['setting_value']))) {
        return $row['setting_value'];
    }
    return $default_terms;
}

if (getUserRole() !== 'customer') {
    header('Location: ../index.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$csrf_token = generateCSRFToken();
ensureStoreTermsColumn($conn);
$portal_terms_content = getPortalTermsContent($conn);
$current_booking_year = date('Y');
$current_year_start = $current_booking_year . '-01-01';
$current_year_end = $current_booking_year . '-12-31';

function customerMenuCategoryLabels(): array
{
    return [
        'appetizers' => 'Appetizers',
        'soup' => 'Soup',
        'rice' => 'Rice',
        'main_dishes' => 'Main Dishes',
        'pasta_noodles' => 'Pasta/Noodles',
        'desserts' => 'Desserts',
        'drinks' => 'Drinks',
    ];
}

function customerGetLegacyCategoryLabel(string $key): string
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

function customerNormalizeSetMenuCategories($raw_description): array
{
    $decoded = json_decode((string) $raw_description, true);
    $normalized = [];

    if (is_array($decoded) && isset($decoded['categories']) && is_array($decoded['categories'])) {
        foreach ($decoded['categories'] as $key => $items) {
            $label = customerGetLegacyCategoryLabel($key);
            $normalized[$label] = is_array($items)
                ? array_values(array_filter(array_map('trim', $items), 'strlen'))
                : array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n|,/', (string) $items)), 'strlen'));
        }
        return $normalized;
    }

    $plain = trim((string) $raw_description);
    if ($plain !== '') {
        $normalized['Main Dishes'] = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n|,/', $plain)), 'strlen'));
    }

    return $normalized;
}

function customerRenderSetMenuSummary($raw_description): string
{
    $categories = customerNormalizeSetMenuCategories($raw_description);
    $parts = [];

    foreach ($categories as $label => $items) {
        if (empty($items)) {
            continue;
        }
        $parts[] = '<div style="margin-bottom: 8px;"><strong style="color: var(--accent-gold);">' . htmlspecialchars($label) . ':</strong> ' . htmlspecialchars(implode(', ', $items)) . '</div>';
    }

    return $parts ? implode('', $parts) : '';
}

// Get product_id and store_id from URL
$product_id = isset($_GET['product_id']) ? intval($_GET['product_id']) : 0;
$store_id = isset($_GET['store_id']) ? intval($_GET['store_id']) : 0;
$preselected_set_name = isset($_GET['set']) ? trim((string) $_GET['set']) : '';
$is_embedded_booking_modal = false;

if ($product_id <= 0 || $store_id <= 0) {
    header('Location: browse-caterer.php');
    exit();
}

// Fetch product details
$product_query = "
    SELECT p.*, sd.business_name as store_name, sd.gcash_qr_image, sd.gcash_number, sd.store_terms_conditions, sd.is_open, sd.business_open_time, sd.business_close_time, sd.auto_schedule_enabled, sd.booking_gap_days, sd.minimum_booking_guests, sd.maximum_booking_guests
    FROM products p
    LEFT JOIN store_details sd ON p.store_id = sd.user_id
    WHERE p.id = '$product_id' AND p.store_id = '$store_id' AND p.is_available = 1
";
$product_result = mysqli_query($conn, $product_query);

if (!$product_result || mysqli_num_rows($product_result) === 0) {
    header('Location: browse-caterer.php');
    exit();
}

$product = mysqli_fetch_assoc($product_result);
$store_is_open = isStoreAvailableNow($product['is_open'] ?? 0, $product['business_open_time'] ?? null, $product['business_close_time'] ?? null, $product['auto_schedule_enabled'] ?? 0);

if (!$store_is_open) {
    header('Location: browse-caterer.php?store_id=' . $store_id . '&booking=closed');
    exit();
}

$store_capacity = getStoreBookingCapacity($store_id);
$minimum_guests = (int) ($product['minimum_booking_guests'] ?? 1);
$maximum_guests = (int) ($product['maximum_booking_guests'] ?? 100000);
$booking_gap_days = intval($product['booking_gap_days'] ?? 0);
$min_booking_date = date('Y-m-d', strtotime("+$booking_gap_days days"));

// ==========================================
// CHECK CATERER SUBSCRIPTION STATUS
// ==========================================
$store_sub_query = "SELECT subscription_status, subscription_expiry, free_trial_start, free_trial_used, free_trial_expiry FROM store_details WHERE user_id = '$store_id'";
$store_sub_result = mysqli_query($conn, $store_sub_query);
$store_sub = mysqli_fetch_assoc($store_sub_result);

$is_store_active = false;
$current_date = date('Y-m-d');

if ($store_sub) {
    // Check Active Subscription
    if ($store_sub['subscription_status'] === 'active' && $store_sub['subscription_expiry'] >= $current_date) {
        $is_store_active = true;
    }
    // Check Free Trial
    elseif ($store_sub['free_trial_start'] && !$store_sub['free_trial_used'] && $store_sub['free_trial_expiry'] >= $current_date) {
        $is_store_active = true;
    }
}
// ==========================================

// Check if it's a catering service
$is_catering = ($product['category'] === 'catering_service');

// Parse sets for catering
$sets = [];
if ($is_catering) {
    $sets_query = "SELECT * FROM product_sets WHERE product_id = '$product_id' ORDER BY id ASC";
    $sets_result = mysqli_query($conn, $sets_query);
    while ($set = mysqli_fetch_assoc($sets_result)) {
        // Decode addons - handle multiple encoding levels
        $addons = $set['addons'] ?? '[]';
        $decoded_addons = json_decode($addons, true);
        $depth = 0;
        while (is_string($decoded_addons) && $depth < 5) {
            $decoded_addons = json_decode($decoded_addons, true);
            $depth++;
        }
        $set['addons'] = is_array($decoded_addons) ? $decoded_addons : [];

        // Decode swaps - handle multiple encoding levels
        $swaps = $set['swaps'] ?? '[]';
        $decoded_swaps = json_decode($swaps, true);
        $depth = 0;
        while (is_string($decoded_swaps) && $depth < 5) {
            $decoded_swaps = json_decode($decoded_swaps, true);
            $depth++;
        }
        $set['swaps'] = is_array($decoded_swaps) ? $decoded_swaps : [];
        
        $sets[] = $set;
    }
}

$preselected_set = null;
if ($preselected_set_name !== '' && $is_catering && !empty($sets)) {
    foreach ($sets as $set) {
        $setName = $set['set_name'] ?? $set['name'] ?? '';
        if (strcasecmp(trim($setName), trim($preselected_set_name)) === 0) {
            $preselected_set = $set;
            break;
        }
    }
}
$has_preselected_set = ($preselected_set !== null);

function customerRenderOrderSidebarMenu($raw_description): string
{
    $categories = customerNormalizeSetMenuCategories($raw_description);
    $html = '';

    foreach ($categories as $label => $items) {
        if (empty($items)) {
            continue;
        }
        $html .= '<div class="order-menu-category">';
        $html .= '<div class="order-menu-category-label">' . htmlspecialchars($label) . '</div>';
        $html .= '<ul class="order-menu-items">';
        foreach ($items as $item) {
            $html .= '<li><i class="fas fa-check"></i> ' . htmlspecialchars($item) . '</li>';
        }
        $html .= '</ul></div>';
    }

    return $html !== '' ? $html : '<p class="order-menu-empty">No menu items listed for this package.</p>';
}

// Handle form submission
$success_message = '';
$error_message = '';
$duplicate_event_error = false;
$redirect_url = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        $error_message = 'Invalid security token. Please refresh the page.';
    } else {
        $quantity = parseBookingGuestCount($_POST['quantity'] ?? null);
        $contact_name_raw = trim((string) ($_POST['venue_contact_name'] ?? ''));
        $contact_phone_raw = trim((string) ($_POST['venue_contact_phone'] ?? ''));
        $delivery_date = mysqli_real_escape_string($conn, $_POST['delivery_date']);
        $delivery_time = mysqli_real_escape_string($conn, $_POST['delivery_time']);
        $delivery_address = mysqli_real_escape_string($conn, $_POST['delivery_address'] ?? '');
        $event_type = mysqli_real_escape_string($conn, trim((string) ($_POST['event_type'] ?? '')));
        $venue_contact_name = mysqli_real_escape_string($conn, trim((string) ($_POST['venue_contact_name'] ?? '')));
        $venue_contact_phone = mysqli_real_escape_string($conn, trim((string) ($_POST['venue_contact_phone'] ?? '')));
        $dietary_notes = mysqli_real_escape_string($conn, trim((string) ($_POST['dietary_notes'] ?? '')));
        $payment_method_raw = strtolower(trim((string) ($_POST['payment_method'] ?? '')));
        $payment_method = mysqli_real_escape_string($conn, $payment_method_raw);
        $booking_year = date('Y', strtotime($delivery_date));
        $delivery_time_minutes = preg_match('/^(?:[01]\\d|2[0-3]):(?:00|30)$/', $delivery_time)
            ? ((int) substr($delivery_time, 0, 2) * 60) + (int) substr($delivery_time, 3, 2)
            : -1;
        
        // SECURITY CHECK: Block booking payments if the caterer is inactive or manually closed
        if (!$store_is_open) {
            $error_message = 'This caterer is currently closed and is not accepting bookings right now.';
        } elseif (!$is_store_active) {
            $error_message = 'Payment submissions are currently disabled for this caterer right now. Please try another caterer or contact the admin.';
        } elseif ($quantity === null) {
            $error_message = 'Enter a whole number of guests from 1 to 100,000 without leading zeros.';
        } elseif ($quantity > $maximum_guests) {
            $error_message = "This caterer accepts at most $maximum_guests guests per booking.";
        } elseif ($contact_name_raw !== '' && !preg_match('/^[\p{L} ]{1,250}$/uD', $contact_name_raw)) {
            $error_message = 'On-site contact name must contain only letters and spaces, up to 250 characters.';
        } elseif ($contact_phone_raw !== '' && !preg_match('/^[0-9]{1,11}$/D', $contact_phone_raw)) {
            $error_message = 'On-site contact phone must contain only numbers, up to 11 digits.';
        } elseif ($delivery_time_minutes < 360 || $delivery_time_minutes > 1350) {
            $error_message = 'Please select an event time between 6:00 AM and 10:30 PM in 30-minute intervals.';
        } elseif (!in_array($payment_method_raw, ['cash', 'gcash'], true)) {
            $error_message = 'Please select a payment method.';
        } elseif ($event_type === '') {
            $error_message = 'Please select an event type.';
        } elseif (!isset($_POST['terms_agree'])) {
            $error_message = 'You must agree to the caterer terms to proceed.';
        } else {
            // Allow booking up to 1 year into the future; respect the caterer's booking_gap_days setting
            $event_date_obj = new DateTime($delivery_date);
            $today_obj = new DateTime();
            $today_obj->setTime(0, 0, 0);
            $min_booking_date_obj = clone $today_obj;
            $gap_advance = max(0, intval($booking_gap_days));
            if ($gap_advance > 0) {
                $min_booking_date_obj->modify("+{$gap_advance} days");
            } else {
                // Default: at least 1 day in advance if no gap set
                $min_booking_date_obj->modify('+1 day');
            }
            $max_booking_date = clone $today_obj;
            $max_booking_date->modify('+1 year');

            if ($event_date_obj > $max_booking_date) {
                $error_message = 'Booking dates are only allowed up to 1 year in advance.';
            } elseif ($event_date_obj < $min_booking_date_obj) {
                $error_message = 'Bookings must be made at least ' . ($gap_advance > 0 ? $gap_advance : 1) . ' day(s) in advance for this caterer.';
            } else {
                $availability = checkStoreAvailability($store_id, $delivery_date);
                if (!$availability['is_available']) {
                    if (!empty($availability['is_holiday'])) {
                        $error_message = 'This caterer is closed on the selected date (Holiday). Please choose another date.';
                    } else {
                        $slots_taken = intval($availability['active_orders'] ?? 0);
                        $slots_max   = intval($availability['max_orders_per_day'] ?? 1);
                        $error_message = "This caterer is fully booked on the selected date ({$slots_taken}/{$slots_max} bookings). Please choose another date.";
                    }
                } else {
                    // Check for duplicate event type on the same day for this customer
                    try {
                        if (customerHasSameDayEvent($conn, (int) $user_id, (string) $_POST['delivery_date'], trim((string) $_POST['event_type']))) {
                            $duplicate_event_error = true;
                            $error_message = "You already have a {$event_type} booked on this date. You can book a different event type, but not another {$event_type}.";
                        }
                    } catch (Throwable $exception) {
                        appLogError('Duplicate booking check failed', $exception->getMessage());
                        $error_message = 'Unable to check your existing bookings. Please try again.';
                    }
                }
            }
        }


        if (empty($error_message)) {
            $notes_package_raw = trim((string) ($_POST['notes_package'] ?? ''));
            $notes_package = mysqli_real_escape_string($conn, $notes_package_raw);

        
        // Handle add-ons and swaps from JSON data
        $addOnsJson = isset($_POST['add_ons_json']) ? json_decode($_POST['add_ons_json'], true) : [];
        $swapsJson = isset($_POST['swaps_json']) ? json_decode($_POST['swaps_json'], true) : [];
        $buildMenuJson = isset($_POST['build_menu_json']) ? json_decode($_POST['build_menu_json'], true) : [];
        
        // Combine customization details for notes
        $customization_details = [];
        
        if (!empty($addOnsJson)) {
            $addOnsList = array_map(function($addon) {
                $qty = max(1, intval($addon['qty'] ?? 1));
                $unitPrice = floatval($addon['price'] ?? 0);
                $totalPrice = $unitPrice * $qty;
                $label = $addon['name'];
                if ($qty > 1) {
                    $label .= ' x' . $qty;
                }
                return $label . ' (+₱' . number_format($totalPrice, 2) . ')';
            }, $addOnsJson);
            $customization_details[] = 'Added Items: ' . implode(', ', $addOnsList);
        }
        
        if (!empty($swapsJson)) {
            $swapsList = array_map(function($swap) {
                return 'Swap ' . $swap['original'] . ' for ' . $swap['replacement'] . ' (+₱' . number_format($swap['price'], 2) . ')';
            }, $swapsJson);
            $customization_details[] = 'Menu Swaps: ' . implode(', ', $swapsList);
        }

        if (is_array($buildMenuJson) && !empty($buildMenuJson)) {
            $buildMenuList = array_values(array_filter(array_map(function($item) {
                return trim((string) $item);
            }, $buildMenuJson), 'strlen'));
            if (!empty($buildMenuList)) {
                $customization_details[] = 'Build Your Own Menu: ' . implode(', ', $buildMenuList);
            }
        }
        
        // Handle Custom Add-on
        $custom_addon = mysqli_real_escape_string($conn, $_POST['custom_addon'] ?? '');
        if (!empty($custom_addon)) {
            $customization_details[] = 'Custom Add-on Request: ' . $custom_addon . ' (Price TBD)';
        }
        
        if (!empty($customization_details)) {
            $notes_package .= ' | ' . implode(' | ', $customization_details);
        }
        
        $selected_set_name = trim((string) explode(' | ', $notes_package_raw, 2)[0]);
        $pricing = bookingPricingCalculateRequest($conn, [
            'product_id' => $product_id,
            'quantity' => $quantity,
            'selected_set_name' => $selected_set_name,
            'addons' => $addOnsJson,
            'swaps' => array_map(function ($swap) {
                if (!isset($swap['replacement']) && isset($swap['new'])) {
                    $swap['replacement'] = $swap['new'];
                }
                return $swap;
            }, is_array($swapsJson) ? $swapsJson : [])
        ]);

        if (!$pricing) {
            $error_message = 'Unable to calculate the total amount. Please refresh the page and try again.';
            goto end_booking_post;
        }

        $required_guests = max($minimum_guests, (int) ($pricing['selected_set_pax'] ?? 0));
        if ($quantity < $required_guests) {
            $error_message = "This booking requires at least $required_guests guests.";
            goto end_booking_post;
        }

        $total_amount = bookingPricingRound($pricing['total_amount'] ?? 0);
        if ($total_amount <= 0) {
            $error_message = 'Unable to calculate the total amount. Please refresh the page and try again.';
            goto end_booking_post;
        }

        $customer_query = mysqli_query($conn, "SELECT first_name, last_name, email, phone_number, address FROM users WHERE id = '$user_id' LIMIT 1");
        $customer_info = $customer_query ? mysqli_fetch_assoc($customer_query) : null;
        $customer_name_raw = $customer_info ? trim(($customer_info['first_name'] ?? '') . ' ' . ($customer_info['last_name'] ?? '')) : '';
        if ($customer_name_raw === '') {
            $customer_name_raw = 'Customer #' . $user_id;
        }
        $customer_name = mysqli_real_escape_string($conn, $customer_name_raw);
        $customer_email = mysqli_real_escape_string($conn, (string) ($customer_info['email'] ?? ''));
        $customer_phone = mysqli_real_escape_string($conn, (string) ($customer_info['phone_number'] ?? ''));

        $package_id = isset($pricing['selected_set_id']) && intval($pricing['selected_set_id']) > 0 ? intval($pricing['selected_set_id']) : null;
        $package_name_raw = trim((string) ($pricing['selected_set_name'] ?? $selected_set_name));
        if ($package_name_raw === '') {
            $package_name_raw = (string) ($product['name'] ?? 'Standard Package');
        }
        $package_name = mysqli_real_escape_string($conn, $package_name_raw);
        $package_price = bookingPricingRound(($pricing['pricing_mode'] ?? '') === 'by_pax'
            ? ($pricing['price_per_unit'] ?? 0)
            : ((($pricing['selected_set_price'] ?? 0) > 0) ? $pricing['selected_set_price'] : ($pricing['product_price'] ?? $product['price'])));
        
        // Generate order number
        $order_number = 'ORD-' . strtoupper(uniqid());
        $normalized_payment_method = $payment_method_raw === 'cash' ? 'cash' : 'gcash';
        $status = 'pending';
        $payment_status_value = 'pending';
        $down_payment_required = bookingPricingRound($total_amount * 0.5);
        $payment_screenshot = '';
        
        // Build insert query dynamically
        $columns = [];
        $values = [];
        
        $col_result = mysqli_query($conn, "SHOW COLUMNS FROM bookings");
        $table_cols = [];
        while ($col = mysqli_fetch_assoc($col_result)) {
            $table_cols[] = $col['Field'];
        }
        
        if (in_array('order_number', $table_cols)) { $columns[] = 'order_number'; $values[] = "'$order_number'"; }
        if (in_array('customer_id', $table_cols)) { $columns[] = 'customer_id'; $values[] = "'$user_id'"; }
        if (in_array('store_id', $table_cols)) { $columns[] = 'store_id'; $values[] = "'$store_id'"; }
        if (in_array('product_id', $table_cols)) { $columns[] = 'product_id'; $values[] = "'$product_id'"; }
        if (in_array('package_id', $table_cols)) { $columns[] = 'package_id'; $values[] = $package_id !== null ? "'$package_id'" : "NULL"; }
        if (in_array('package_name', $table_cols)) { $columns[] = 'package_name'; $values[] = "'$package_name'"; }
        if (in_array('package_price', $table_cols)) { $columns[] = 'package_price'; $values[] = "'$package_price'"; }
        if (in_array('full_name', $table_cols)) { $columns[] = 'full_name'; $values[] = "'$customer_name'"; }
        if (in_array('contact_number', $table_cols)) { $columns[] = 'contact_number'; $values[] = "'$customer_phone'"; }
        if (in_array('email', $table_cols)) { $columns[] = 'email'; $values[] = "'$customer_email'"; }
        if (in_array('quantity', $table_cols)) { $columns[] = 'quantity'; $values[] = "'$quantity'"; }
        if (in_array('number_of_pax', $table_cols)) { $columns[] = 'number_of_pax'; $values[] = "'$quantity'"; }
        if (in_array('total_amount', $table_cols)) { $columns[] = 'total_amount'; $values[] = "'$total_amount'"; }
        if (in_array('order_date', $table_cols)) { $columns[] = 'order_date'; $values[] = "'$delivery_date'"; }
        if (in_array('order_time', $table_cols)) { $columns[] = 'order_time'; $values[] = "'$delivery_time'"; }
        if (in_array('delivery_time', $table_cols)) { $columns[] = 'delivery_time'; $values[] = "'$delivery_time'"; }
        if (in_array('delivery_address', $table_cols)) { $columns[] = 'delivery_address'; $values[] = "'$delivery_address'"; }
        if (in_array('address', $table_cols)) { $columns[] = 'address'; $values[] = "'$delivery_address'"; }
        if (in_array('event_type', $table_cols)) { $columns[] = 'event_type'; $values[] = $event_type !== '' ? "'$event_type'" : "NULL"; }
        if (in_array('venue_contact_name', $table_cols)) { $columns[] = 'venue_contact_name'; $values[] = $venue_contact_name !== '' ? "'$venue_contact_name'" : "NULL"; }
        if (in_array('venue_contact_phone', $table_cols)) { $columns[] = 'venue_contact_phone'; $values[] = $venue_contact_phone !== '' ? "'$venue_contact_phone'" : "NULL"; }
        if (in_array('dietary_notes', $table_cols)) { $columns[] = 'dietary_notes'; $values[] = $dietary_notes !== '' ? "'$dietary_notes'" : "NULL"; }
        if (in_array('delivery_type', $table_cols)) { $columns[] = 'delivery_type'; $values[] = "'delivery'"; }
        if (in_array('payment_method', $table_cols)) { $columns[] = 'payment_method'; $values[] = "'$normalized_payment_method'"; }
        if (in_array('payment_status', $table_cols)) { $columns[] = 'payment_status'; $values[] = "'$payment_status_value'"; }
        if (in_array('down_payment_required', $table_cols)) { $columns[] = 'down_payment_required'; $values[] = "'$down_payment_required'"; }
        if (in_array('down_payment_paid', $table_cols)) { $columns[] = 'down_payment_paid'; $values[] = "'0'"; }
        
        // Save base price, addons total, extra fee for financial helper
        $base_price_for_order = bookingPricingRound($pricing['base_price'] ?? 0);
        $addons_total_calc = bookingPricingRound($pricing['addons_total'] ?? 0);
        if (in_array('base_price', $table_cols)) { $columns[] = 'base_price'; $values[] = "'$base_price_for_order'"; }
        if (in_array('addons_total', $table_cols)) { $columns[] = 'addons_total'; $values[] = "'$addons_total_calc'"; }
        if (in_array('extra_fee', $table_cols)) { $columns[] = 'extra_fee'; $values[] = "'0.00'"; }
        
        // Fix: Save menu details to additional_menu column (not notes)
        if (in_array('additional_menu', $table_cols)) { 
            $columns[] = 'additional_menu'; 
            $values[] = "'$notes_package'"; 
        } elseif (in_array('notes', $table_cols)) { 
            // Fallback for older schema
            $columns[] = 'notes'; 
            $values[] = "'$notes_package'"; 
        }
        
        if (in_array('order_status', $table_cols)) { $columns[] = 'order_status'; $values[] = "'$status'"; }
        if (in_array('created_at', $table_cols)) { $columns[] = 'created_at'; $values[] = "NOW()"; }
        if (in_array('updated_at', $table_cols)) { $columns[] = 'updated_at'; $values[] = "NOW()"; }
        if ($payment_screenshot !== '' && in_array('payment_screenshot', $table_cols)) { $columns[] = 'payment_screenshot'; $values[] = "'$payment_screenshot'"; }
        
        $insert_query = "INSERT INTO bookings (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $values) . ")";

        $booking_claimed = claimBookingSubmission('order', (int) $user_id, [
            'store_id' => (int) $store_id,
            'product_id' => (int) $product_id,
            'delivery_date' => $delivery_date,
            'delivery_time' => $delivery_time,
            'quantity' => $quantity,
            'total' => $total_amount,
        ]);
        if (!$booking_claimed) {
            $error_message = 'This booking was already submitted. Please check your bookings before trying again.';
            goto end_booking_post;
        }

        if (mysqli_query($conn, $insert_query)) {
            $new_order_id = mysqli_insert_id($conn);
            $success_message = "Booking placed successfully! Booking #$order_number";
            
            // Notify caterer with customer name
            $store_notice = "New booking #$order_number from $customer_name_raw is awaiting your review.";
            createNotification($store_id, 'new_order', $store_notice, $order_number);

            if (function_exists('notifyPortalAdmins')) {
                notifyPortalAdmins(
                    'new_order',
                    'New Booking Placed',
                    $customer_name_raw . ' placed Booking #' . $order_number . '.',
                    'reports.php'
                );
            }
            
            // ==========================================
            // AUTOMATIC CONFIRMATION CHAT
            // ==========================================
            $chat_subject = "Order Confirmation - #$order_number";
            
            $chat_message = "Hello! Thank you for placing your order.\n\n";
            $chat_message .= "Here is your order summary:\n";
            $chat_message .= "--------------------------------\n";
            $chat_message .= "Booking #: $order_number\n";
            $chat_message .= "Service/Product: {$product['name']}\n";
            
            // Parse Package Name if available
            $pkg_name = 'Standard';
            if (!empty($notes_package)) {
                $parts = explode(' | ', $notes_package);
                $pkg_name = $parts[0];
            }
            $chat_message .= "Package: $pkg_name\n";
            $chat_message .= "Person/Quantity: $quantity\n";
            $chat_message .= "Total Amount: ₱" . number_format($total_amount, 2) . "\n";
            $chat_message .= "--------------------------------\n";
            
            // Add Customizations if any
            if (!empty($customization_details)) {
                $chat_message .= "Customizations:\n";
                foreach ($customization_details as $detail) {
                    $chat_message .= "• $detail\n";
                }
                $chat_message .= "--------------------------------\n";
            }
            
            $chat_message .= "\nThis is your order. Is everything correct?\n";
            $chat_message .= "Would you like to add anything else or have any special requests?\n";
            $chat_message .= "Please reply to this message to confirm.";
            
            $chat_message_safe = mysqli_real_escape_string($conn, $chat_message);
            $chat_subject_safe = mysqli_real_escape_string($conn, "New Order Confirmation");
            
            $msg_query = "INSERT INTO messages (sender_id, receiver_id, order_id, subject, message, created_at) 
                          VALUES ('$store_id', '$user_id', '$new_order_id', '$chat_subject_safe', '$chat_message_safe', NOW())";
            mysqli_query($conn, $msg_query);
            // ==========================================

            // Always redirect to booking list so the customer waits for caterer approval before paying.
            header("Location: booking.php?new_booking=1&booking_id=$new_order_id");
            exit();
        } else {
            $error_message = 'Error placing order. Please try again.';
        }
    }
    }
}

end_booking_post:
$page_title = 'Book Service - ' . htmlspecialchars($product['name']);
require_once __DIR__ . '/includes/header.php';
?>

<link rel="stylesheet" href="../assets/css/customer-book-service-theme.css?v=<?php echo filemtime(__DIR__ . '/../assets/css/customer-book-service-theme.css'); ?>">
<style>
    .booking-page-container {
        max-width: 900px;
        margin: 0 auto;
        padding: 24px 20px 40px;
    }
    
    .back-link {
        display: inline-flex;
        align-items: center;
        gap: 10px;
        color: var(--text-main);
        background: var(--card-bg);
        border: 1px solid var(--border-color);
        padding: 8px 18px;
        border-radius: 999px;
        text-decoration: none;
        margin-bottom: 20px;
        font-size: 14px;
        font-weight: 500;
        letter-spacing: 0.2px;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
        transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
    }
    
    .back-link i {
        color: var(--accent-gold);
        font-size: 13px;
        transition: transform 0.25s ease, color 0.25s ease;
    }
    
    .back-link:hover {
        background: rgba(214, 179, 90, 0.12);
        border-color: var(--accent-gold);
        color: #ffffff;
        transform: translateX(-4px);
        box-shadow: 0 6px 18px rgba(214, 179, 90, 0.18);
        text-decoration: none;
    }
    
    .back-link:hover i {
        transform: translateX(-3px);
        color: var(--accent-gold);
    }
    
    .booking-grid {
        display: block;
        background: var(--card-bg);
        border: 1px solid var(--border-color);
        border-radius: 16px;
        overflow: hidden;
        min-height: 0;
        max-width: 900px;
        margin: 0 auto;
        box-shadow: 0 18px 50px rgba(0, 0, 0, 0.28);
    }
    
    @media (max-width: 968px) {
        .booking-grid {
            display: block;
        }
    }
    
    .booking-form-container,
    .booking-form-container.form-focused {
        padding: 32px 36px 36px;
        overflow-y: visible;
        max-height: none;
        width: 100%;
    }

    .form-title {
        margin-bottom: 30px;
        color: var(--accent-gold);
        font-size: 24px;
    }

    .booking-step-indicator {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 8px;
        margin-bottom: 20px;
        flex-shrink: 0;
    }

    .booking-step-indicator.preselected-flow {
        grid-template-columns: repeat(3, 1fr);
    }

    .booking-step-dot {
        display: flex;
        flex-direction: column;
        gap: 6px;
        color: var(--text-muted);
        font-size: 12px;
        font-weight: 600;
        text-align: center;
    }

    .booking-step-dot span {
        height: 5px;
        border-radius: 99px;
        background: rgba(255, 255, 255, 0.1);
    }

    .booking-step-dot.active,
    .booking-step-dot.complete {
        color: var(--accent-gold);
    }

    .booking-step-dot.active span,
    .booking-step-dot.complete span {
        background: var(--accent-gold);
    }

    .booking-step {
        display: none;
    }

    .booking-step.active {
        display: block;
        position: relative;
        overflow: visible;
    }

    .booking-step-body {
        overflow: visible;
        padding-bottom: 8px;
    }

    .selected-set-banner {
        margin-bottom: 18px;
        padding: 14px 16px;
        border-radius: 12px;
        border: 1px solid rgba(212, 179, 90, 0.28);
        background: rgba(212, 179, 90, 0.08);
        color: var(--text-main);
        font-weight: 700;
    }

    .form-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 20px;
    }

    @media (max-width: 600px) {
        .form-row {
            grid-template-columns: 1fr;
        }
    }
    
    .product-name {
        color: var(--accent-gold);
        font-size: 28px;
        margin-bottom: 15px;
    }
    
    .product-price {
        font-weight: 700;
        font-size: 22px;
        margin-bottom: 25px;
        color: var(--text-main);
    }
    
    .product-description {
        white-space: pre-wrap;
        color: var(--text-main);
        margin-bottom: 30px;
        line-height: 1.8;
        font-size: 16px;
    }
    
    .section-title {
        color: var(--text-muted);
        margin-bottom: 15px;
        font-size: 18px;
    }
    
    .booking-form-container {
        padding: 32px 28px 24px;
        overflow: visible;
        min-width: 0;
        min-height: 0;
        display: block;
        background: var(--card-bg);
        border-left: 1px solid var(--border-color);
    }

    #bookingForm {
        display: block;
    }

    .booking-modal {
        position: fixed;
        inset: 0;
        z-index: 2200;
        display: none;
        align-items: flex-start;
        justify-content: center;
        padding: 32px 18px;
        background: rgba(0, 0, 0, 0.72);
        backdrop-filter: blur(8px);
        overflow-y: auto;
    }

    .booking-modal.active {
        display: flex;
    }

    .booking-modal-card {
        width: min(760px, 100%);
        max-height: min(90vh, 860px);
        background: var(--card-bg);
        border: 1px solid var(--border-color);
        border-radius: 16px;
        box-shadow: 0 24px 70px rgba(0, 0, 0, 0.55);
        position: relative;
        display: flex;
        flex-direction: column;
        overflow: hidden;
    }

    .booking-modal-close {
        position: absolute;
        top: 14px;
        right: 14px;
        width: 38px;
        height: 38px;
        border: 1px solid var(--border-color);
        border-radius: 10px;
        background: rgba(255, 255, 255, 0.05);
        color: var(--text-main);
        font-size: 22px;
        line-height: 1;
        cursor: pointer;
        z-index: 1;
    }

    .booking-modal-close:hover {
        border-color: var(--accent-gold);
        color: var(--accent-gold);
    }

    /* Embedded iframe mode (opened by modalBookThisService.php):
       show only the booking form, hide navigation/product page,
       and make the form scrollable within the iframe. */
    html:has(body.embedded-booking-modal),
    body.embedded-booking-modal {
        height: 100% !important;
        max-height: 100% !important;
        min-height: 0 !important;
        overflow: hidden !important;
        margin: 0 !important;
        padding: 0 !important;
    }

    body.embedded-booking-modal {
        display: flex !important;
        flex-direction: column;
    }

    body.embedded-booking-modal .navbar,
    body.embedded-booking-modal .impersonation-banner,
    body.embedded-booking-modal .back-link,
    body.embedded-booking-modal .main-footer,
    body.embedded-booking-modal .booking-modal-close {
        display: none !important;
    }

    body.embedded-booking-modal .container {
        margin: 0 !important;
        padding: 0 !important;
        max-width: none !important;
        width: 100% !important;
        height: 100% !important;
        min-height: 0 !important;
        overflow: hidden !important;
        display: flex;
        flex-direction: column;
    }

    body.embedded-booking-modal .booking-page-container {
        display: flex;
        flex-direction: column;
        flex: 1 1 auto;
        height: 100%;
        max-height: 100%;
        min-height: 0;
        padding: 0;
        max-width: none;
        overflow: hidden;
    }

    body.embedded-booking-modal .alert {
        flex-shrink: 0;
        margin: 16px 20px 0;
    }

    body.embedded-booking-modal .booking-grid {
        flex: 1 1 auto;
        display: flex;
        flex-direction: column;
        min-height: 0;
        background: transparent;
        border: 0;
        border-radius: 0;
        box-shadow: none;
        max-width: none;
        margin: 0;
    }

    body.embedded-booking-modal .booking-modal {
        flex: 1 1 auto;
        display: flex !important;
        flex-direction: column;
        min-height: 0;
        position: static;
        background: transparent;
        backdrop-filter: none;
        -webkit-backdrop-filter: none;
        padding: 0;
        overflow: visible;
    }

    body.embedded-booking-modal .booking-modal-card {
        flex: 1 1 auto;
        display: flex;
        flex-direction: column;
        min-height: 0;
        max-height: none;
        height: 100%;
        width: 100%;
        background: transparent;
        border: 0;
        border-radius: 0;
        box-shadow: none;
    }

    body.embedded-booking-modal .booking-form-container {
        flex: 1 1 auto;
        min-height: 0;
        position: relative;
        padding: 12px 20px 0;
        overflow: hidden;
        height: 100%;
    }

    body.embedded-booking-modal .booking-step-indicator {
        margin-top: 0;
        margin-bottom: 12px;
        flex-shrink: 0;
    }

    body.embedded-booking-modal .step-actions {
        display: none !important;
    }

    body.embedded-booking-modal .booking-step.active {
        padding-bottom: 0;
    }

    body.embedded-booking-modal .booking-step-body {
        overflow-y: auto !important;
        overflow-x: hidden;
        min-height: 0;
        flex: 1 1 auto;
        padding-bottom: 16px;
        -webkit-overflow-scrolling: touch;
    }
    
    .form-title {
        margin-bottom: 30px;
        color: var(--accent-gold);
        font-size: 24px;
    }

    .booking-step-indicator {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 8px;
        margin-bottom: 16px;
        flex-shrink: 0;
    }

    .booking-step-dot {
        display: flex;
        flex-direction: column;
        gap: 6px;
        color: var(--text-muted);
        font-size: 11px;
        font-weight: 600;
        text-align: center;
    }

    .booking-step-dot span {
        height: 5px;
        border-radius: 99px;
        background: rgba(255, 255, 255, 0.1);
    }

    .booking-step-dot.active,
    .booking-step-dot.complete {
        color: var(--accent-gold);
    }

    .booking-step-dot.active span,
    .booking-step-dot.complete span {
        background: var(--accent-gold);
    }

    .price-breakdown {
        margin: 0 0 16px 0;
        padding: 0;
        list-style: none;
        font-size: 13px;
        color: var(--text-muted);
    }

    .price-breakdown li {
        display: flex;
        justify-content: space-between;
        gap: 12px;
        margin-bottom: 6px;
    }

    .guest-hint {
        display: block;
        margin-top: 6px;
        font-size: 12px;
        color: var(--text-muted);
    }

    .booking-step {
        display: none;
    }

    .booking-step.active {
        display: block;
        position: relative;
        overflow: visible;
    }

    .booking-step-body {
        overflow: visible;
        padding-bottom: 8px;
    }

    .selected-set-banner {
        margin-bottom: 18px;
        padding: 14px 16px;
        border-radius: 12px;
        border: 1px solid rgba(212, 179, 90, 0.28);
        background: rgba(212, 179, 90, 0.08);
        color: var(--text-main);
        font-weight: 700;
    }

    .step-actions {
        display: flex;
        justify-content: flex-end;
        align-items: center;
        gap: 12px;
        margin: 20px 0 0;
        padding: 16px 0 0;
        border-top: 1px solid var(--border-color);
        background: transparent;
        position: sticky;
        bottom: 0;
    }

    .step-btn {
        min-height: 48px;
        padding: 12px 22px;
        border-radius: 10px;
        border: 1px solid var(--border-color);
        font-size: 15px;
        font-weight: 700;
        cursor: pointer;
        width: auto;
    }

    .step-btn.secondary {
        min-width: 120px;
        background: transparent;
        color: var(--text-main);
    }

    .step-btn.primary {
        min-width: 180px;
        flex: 1;
        max-width: 280px;
        background: var(--accent-gold);
        color: #111;
        border-color: var(--accent-gold);
        box-shadow: 0 8px 18px rgba(214, 179, 90, 0.28);
    }
    
    .form-group {
        margin-bottom: 25px;
    }
    
    .form-label {
        display: block;
        font-size: 15px;
        margin-bottom: 10px;
        color: var(--text-main);
    }
    
    .form-control {
        width: 100%;
        padding: 15px;
        border: 1px solid var(--border-color);
        border-radius: 8px;
        background: var(--secondary-bg);
        color: var(--text-main);
        font-size: 15px;
    }
    
    .form-control:focus {
        outline: none;
        border-color: var(--accent-gold);
    }

    .package-summary {
        display: none;
        margin-top: 12px;
        padding: 14px 16px;
        border-radius: 12px;
        border: 1px solid rgba(212, 175, 55, 0.24);
        background: rgba(212, 175, 55, 0.08);
    }

    .package-summary.active {
        display: block;
    }

    .package-summary-name {
        display: block;
        color: var(--text-main);
        font-weight: 700;
        margin-bottom: 4px;
        word-break: break-word;
    }

    .package-summary-meta {
        color: var(--accent-gold);
        font-size: 14px;
        line-height: 1.5;
        word-break: break-word;
    }

    .sets-list {
        display: flex;
        flex-direction: column;
        gap: 16px;
    }

    .set-card {
        background: rgba(255,255,255,0.03);
        padding: 20px;
        border-radius: 12px;
        border-left: 4px solid var(--accent-gold);
        border-top: 1px solid transparent;
        border-right: 1px solid transparent;
        border-bottom: 1px solid transparent;
        cursor: pointer;
        text-align: left;
        width: 100%;
        transition: border-color 0.2s ease, background 0.2s ease, transform 0.2s ease;
    }

    .set-card:hover,
    .set-card:focus {
        background: rgba(212, 179, 90, 0.08);
        border-color: rgba(212, 179, 90, 0.42);
        outline: none;
        transform: translateY(-1px);
    }

    .set-card-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 12px;
        margin-bottom: 10px;
    }

    .set-card-name {
        color: #fff;
        font-size: 16px;
        line-height: 1.4;
        word-break: break-word;
    }

    .set-card-price {
        color: var(--accent-gold);
        font-weight: bold;
        text-align: right;
        line-height: 1.5;
        word-break: break-word;
    }

    .set-card-description {
        color: var(--text-muted);
        font-size: 14px;
        line-height: 1.6;
        white-space: pre-wrap;
        word-break: break-word;
    }

    .terms-section {
        margin-top: 20px;
        padding: 18px;
        border-radius: 12px;
        border: 1px solid var(--border-color);
        background: rgba(255,255,255,0.04);
    }

    .terms-card {
        margin-bottom: 14px;
        border-radius: 10px;
        border: 1px solid rgba(212, 175, 55, 0.18);
        background: rgba(0, 0, 0, 0.15);
        overflow: hidden;
    }

    .terms-card summary {
        list-style: none;
        cursor: pointer;
        padding: 14px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
    }

    .terms-card summary::-webkit-details-marker {
        display: none;
    }

    .terms-card summary::after {
        content: '+';
        color: var(--accent-gold);
        font-size: 20px;
        font-weight: 700;
        line-height: 1;
    }

    .terms-card[open] summary::after {
        content: '-';
    }

    .terms-card h4 {
        margin: 0;
        color: var(--accent-gold);
        font-size: 15px;
    }

    .terms-body {
        padding: 0 14px 14px;
        color: var(--text-main);
        line-height: 1.7;
        font-size: 14px;
        max-height: 170px;
        overflow-y: auto;
    }
    
    .form-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 20px;
    }

    .schedule-group {
        margin-bottom: 24px;
        padding: 18px;
        border: 1px solid rgba(255,255,255,0.06);
        border-radius: 16px;
        background: rgba(255,255,255,0.025);
    }

    .schedule-group-header {
        margin-bottom: 14px;
    }

    .schedule-group-title {
        margin: 0 0 4px 0;
        color: var(--text-main);
        font-size: 16px;
        font-weight: 700;
    }

    .schedule-group-copy {
        margin: 0;
        color: var(--text-muted);
        font-size: 13px;
    }
    
    @media (max-width: 600px) {
        .booking-page-container {
            padding: 16px;
        }

        .booking-grid {
            gap: 0;
            border-radius: 14px;
            min-height: 0;
        }

        .product-details,
        .booking-form-container {
            padding: 20px 16px;
        }

        .booking-modal {
            padding: 12px;
        }

        .booking-modal-card {
            border-radius: 14px;
        }

        .product-image {
            height: 220px;
            margin-bottom: 18px;
        }

        .product-name {
            font-size: 22px;
            margin-bottom: 10px;
            line-height: 1.2;
        }

        .product-price {
            font-size: 18px;
            margin-bottom: 18px;
        }

        .product-description {
            font-size: 14px;
            line-height: 1.7;
            margin-bottom: 22px;
        }

        .section-title {
            font-size: 16px;
            margin-bottom: 12px;
        }

        .set-card {
            padding: 16px;
        }

        .set-card-header {
            flex-direction: column;
            align-items: flex-start;
            margin-bottom: 8px;
        }

        .set-card-price {
            text-align: left;
        }

        .form-row {
            grid-template-columns: 1fr;
        }

        .mini-date-picker {
            left: 0;
            right: 0;
            width: 100%;
            max-width: 100%;
            padding: 12px;
        }

        .mini-date-picker-header {
            gap: 8px;
        }

        .mini-date-picker-title {
            min-width: 0;
            text-align: left;
        }

        .mini-date-footer button {
            font-size: 12px;
        }
    }
    
    .total-box {
        background: rgba(212, 175, 55, 0.08);
        padding: 25px;
        border-radius: 12px;
        border: 1px solid var(--accent-gold);
        margin-bottom: 30px;
    }
    
    .total-row {
        display: flex;
        justify-content: space-between;
        margin-bottom: 10px;
    }
    
    .total-amount {
        color: var(--accent-gold);
        font-size: 24px;
        font-weight: bold;
    }
    
    .btn-submit {
        width: 100%;
        padding: 18px;
        background: var(--accent-gold);
        color: #000;
        border: none;
        border-radius: 8px;
        font-size: 16px;
        font-weight: bold;
        cursor: pointer;
        transition: all 0.3s ease;
    }
    
    .btn-submit:hover {
        background: #e6c260;
    }
    
    .alert {
        padding: 15px;
        border-radius: 8px;
        margin-bottom: 20px;
    }
    
    .alert-success {
        background: rgba(76, 175, 80, 0.2);
        border: 1px solid #4caf50;
        color: #4caf50;
    }
    
    .alert-error {
        background: rgba(244, 67, 54, 0.2);
        border: 1px solid #f44336;
        color: #f44336;
    }

    .availability-card {
        margin-top: 16px;
        padding: 16px;
        border: 1px solid var(--border-color);
        border-radius: 14px;
        background: rgba(255,255,255,0.02);
    }

    .availability-trigger {
        width: 100%;
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 12px;
        margin-top: 12px;
        padding: 13px 15px;
        border-radius: 12px;
        border: 1px solid rgba(212, 175, 55, 0.35);
        background: rgba(212, 175, 55, 0.08);
        color: var(--text-main);
        cursor: pointer;
        transition: all 0.2s ease;
    }

    .availability-trigger:hover {
        border-color: rgba(212, 175, 55, 0.65);
        background: rgba(212, 175, 55, 0.12);
    }

    .availability-trigger-label {
        display: flex;
        flex-direction: column;
        align-items: flex-start;
    }

    .availability-trigger-label strong {
        color: var(--accent-gold);
        font-size: 14px;
    }

    .availability-trigger-label span {
        color: var(--text-muted);
        font-size: 12px;
    }

    .date-picker-shell {
        position: relative;
        min-width: 0;
    }

    .mini-date-trigger {
        flex: 1;
        min-height: 52px;
        padding: 14px 16px;
        border: 1px solid var(--border-color);
        border-radius: 12px;
        background: var(--input-bg);
        color: var(--text-main);
        cursor: pointer;
        font-size: 15px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        text-align: left;
    }

    .mini-date-trigger:hover {
        border-color: rgba(212, 175, 55, 0.45);
    }

    .mini-date-trigger-value {
        color: var(--text-main);
        font-weight: 600;
    }

    .mini-date-trigger-placeholder {
        color: var(--text-muted);
    }

    .mini-date-picker {
        position: absolute;
        top: calc(100% + 10px);
        left: 0;
        right: auto;
        z-index: 1200;
        width: 100%;
        min-width: 300px;
        max-width: 350px;
        box-sizing: border-box;
        background: #1e1e24;
        border: 1px solid rgba(255,255,255,0.08);
        border-radius: 12px;
        box-shadow: 0 10px 40px rgba(0,0,0,0.5);
        padding: 16px;
        display: none;
    }

    .mini-date-picker.active {
        display: block;
    }

    .mini-date-picker-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 12px;
    }

    .mini-date-picker-title {
        color: var(--text-main);
        font-weight: 700;
        background: transparent;
        border: none;
        cursor: pointer;
    }

    .mini-date-picker-nav {
        display: flex;
        gap: 6px;
    }

    .mini-date-picker-nav button {
        width: 34px;
        height: 34px;
        border-radius: 10px;
        border: 1px solid rgba(255,255,255,0.1);
        background: rgba(255,255,255,0.03);
        color: var(--text-main);
        cursor: pointer;
    }

    .mini-date-grid,
    .mini-date-weekdays {
        display: grid;
        grid-template-columns: repeat(7, 1fr);
        gap: 4px;
    }

    .mini-date-weekdays {
        margin-bottom: 8px;
    }

    .mini-date-weekdays span {
        text-align: center;
        font-size: 12px;
        color: var(--accent-gold);
        font-weight: 700;
        padding: 6px 0;
    }

    .mini-date-cell {
        min-height: 38px;
        border: 1px solid transparent;
        border-radius: 10px;
        background: transparent;
        color: var(--text-main);
        cursor: pointer;
        font-size: 13px;
    }

    .mini-date-cell:hover {
        background: rgba(255,255,255,0.06);
    }

    .mini-date-cell.is-outside {
        color: rgba(255,255,255,0.28);
    }

    .mini-date-cell.is-disabled {
        color: rgba(255,255,255,0.2);
        cursor: not-allowed;
    }

    .mini-date-cell.is-selected {
        background: var(--accent-gold);
        color: #111;
        font-weight: 700;
    }

    .mini-date-footer {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-top: 12px;
        padding-top: 10px;
        border-top: 1px solid rgba(255,255,255,0.08);
    }

    .mini-date-footer button {
        border: none;
        background: transparent;
        color: var(--accent-gold);
        cursor: pointer;
        font-size: 13px;
        font-weight: 600;
    }

    .date-picker-hidden-input {
        position: absolute;
        opacity: 0;
        pointer-events: none;
        width: 1px;
        height: 1px;
    }

    .time-picker-shell {
        position: relative;
    }

    .mini-time-trigger {
        width: 100%;
        min-height: 52px;
        padding: 14px 16px;
        border: 1px solid var(--border-color);
        border-radius: 12px;
        background: var(--input-bg);
        color: var(--text-main);
        cursor: pointer;
        font-size: 15px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        text-align: left;
    }

    .mini-time-trigger:hover {
        border-color: rgba(212, 175, 55, 0.45);
    }

    .mini-time-trigger-value {
        color: var(--text-main);
        font-weight: 600;
    }

    .mini-time-trigger-placeholder {
        color: var(--text-muted);
    }

    .mini-time-picker {
        position: absolute;
        top: calc(100% + 10px);
        left: 0;
        z-index: 1200;
        width: 100%;
        max-width: 360px;
        background: #1f1f1f;
        border: 1px solid rgba(255,255,255,0.1);
        border-radius: 16px;
        box-shadow: 0 24px 60px rgba(0,0,0,0.4);
        padding: 12px;
        display: none;
    }

    .mini-time-picker.active {
        display: block;
    }

    .mini-time-column {
        background: rgba(255,255,255,0.03);
        border: 1px solid rgba(255,255,255,0.08);
        border-radius: 12px;
        overflow: hidden;
    }

    .mini-time-column-header {
        padding: 8px 10px;
        font-size: 11px;
        font-weight: 700;
        color: var(--accent-gold);
        text-transform: uppercase;
        letter-spacing: 0.08em;
        border-bottom: 1px solid rgba(255,255,255,0.08);
    }

    .mini-time-options {
        max-height: 260px;
        overflow-y: auto;
        padding: 6px;
    }

    .mini-time-options button {
        width: 100%;
        border: none;
        background: transparent;
        color: var(--text-main);
        border-radius: 8px;
        padding: 10px 8px;
        cursor: pointer;
        font-size: 14px;
        margin-bottom: 4px;
    }

    .mini-time-options button:hover {
        background: rgba(255,255,255,0.06);
    }

    .mini-time-options button.is-selected {
        background: #1d6fff;
        color: #ffffff;
        font-weight: 700;
    }

    .mini-time-footer {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-top: 12px;
        padding-top: 10px;
        border-top: 1px solid rgba(255,255,255,0.08);
    }

    .mini-time-footer button {
        border: none;
        background: transparent;
        color: var(--accent-gold);
        cursor: pointer;
        font-size: 13px;
        font-weight: 600;
    }

    .availability-legend {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
        margin-bottom: 16px;
    }

    .availability-pill {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 8px 12px;
        border-radius: 999px;
        font-size: 12px;
        font-weight: 600;
    }

    .availability-pill::before {
        content: '';
        width: 10px;
        height: 10px;
        border-radius: 50%;
        display: inline-block;
    }

    .availability-pill.available {
        background: rgba(46, 204, 113, 0.12);
        color: #7bed9f;
    }

    .availability-pill.available::before {
        background: #2ecc71;
    }

    .availability-pill.almost-full {
        background: rgba(243, 156, 18, 0.12);
        color: #f8c471;
    }

    .availability-pill.almost-full::before {
        background: #f39c12;
    }

    .availability-pill.fully-booked {
        background: rgba(231, 76, 60, 0.12);
        color: #f5b7b1;
    }

    .availability-pill.fully-booked::before {
        background: #e74c3c;
    }

    .availability-status-box {
        margin-top: 12px;
        padding: 12px 14px;
        border-radius: 12px;
        border: 1px solid var(--border-color);
        background: rgba(0,0,0,0.18);
        color: var(--text-muted);
        font-size: 13px;
        line-height: 1.6;
    }

    .availability-status-box strong {
        color: var(--text-main);
    }

    .availability-status-box.is-available {
        border-color: rgba(46, 204, 113, 0.35);
    }

    .availability-status-box.is-almost-full {
        border-color: rgba(243, 156, 18, 0.35);
    }

    .availability-status-box.is-fully-booked {
        border-color: rgba(231, 76, 60, 0.35);
    }

    #bookingAvailabilityCalendar {
        min-height: 380px;
    }

    #bookingAvailabilityCalendar .fc-daygrid-day.fc-day-past {
        opacity: 0.45;
    }

    #bookingAvailabilityCalendar .fc-daygrid-day.fc-day-today {
        background: rgba(212, 175, 55, 0.08);
    }

    .availability-modal {
        position: fixed;
        inset: 0;
        display: none;
        align-items: center;
        justify-content: center;
        padding: 24px;
        background: rgba(0, 0, 0, 0.7);
        backdrop-filter: blur(6px);
        z-index: 1300;
    }

    .availability-modal.active {
        display: flex;
    }

    .availability-modal-card {
        width: min(920px, 100%);
        max-height: 90vh;
        overflow: auto;
        background:
            radial-gradient(circle at top right, rgba(212, 175, 55, 0.08), transparent 28%),
            linear-gradient(180deg, #26231f 0%, #1c1b1a 100%);
        border: 1px solid rgba(255,255,255,0.08);
        border-radius: 22px;
        box-shadow: 0 30px 80px rgba(0,0,0,0.45);
        padding: 24px;
    }

    .availability-modal-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 12px;
        margin-bottom: 16px;
        padding-bottom: 16px;
        border-bottom: 1px solid rgba(255,255,255,0.08);
    }

    .availability-modal-header h3 {
        margin: 0 0 6px 0;
        color: var(--accent-gold);
        font-size: 24px;
        letter-spacing: -0.02em;
    }

    .availability-modal-header p {
        margin: 0;
        color: var(--text-muted);
        font-size: 14px;
        max-width: 620px;
        line-height: 1.55;
    }

    .availability-modal-close {
        width: 40px;
        height: 40px;
        border: none;
        border-radius: 12px;
        background: rgba(255,255,255,0.05);
        color: #fff;
        font-size: 20px;
        cursor: pointer;
        transition: background 0.2s ease, transform 0.2s ease;
    }

    .availability-modal-close:hover {
        background: rgba(255,255,255,0.12);
        transform: translateY(-1px);
    }

    .availability-modal .availability-legend {
        margin-bottom: 18px;
        padding: 12px 14px;
        border: 1px solid rgba(255,255,255,0.06);
        border-radius: 16px;
        background: rgba(255,255,255,0.03);
    }

    .availability-modal .fc {
        --fc-border-color: rgba(255,255,255,0.14);
        --fc-button-bg-color: #1a1a1a;
        --fc-button-border-color: rgba(255,255,255,0.14);
        --fc-button-hover-bg-color: #2a2a2a;
        --fc-button-hover-border-color: rgba(255,255,255,0.24);
        --fc-button-active-bg-color: #2f2f2f;
        --fc-button-active-border-color: rgba(255,255,255,0.24);
        --fc-page-bg-color: transparent;
        --fc-neutral-bg-color: rgba(255,255,255,0.02);
        --fc-list-event-hover-bg-color: rgba(255,255,255,0.04);
        color: #f5f5f5;
        padding: 16px;
        border: 1px solid rgba(255,255,255,0.06);
        border-radius: 18px;
        background: rgba(10, 10, 10, 0.18);
    }

    .availability-modal .fc-toolbar-title {
        font-size: 24px !important;
        font-weight: 700;
        letter-spacing: -0.02em;
    }

    .availability-modal .fc-button {
        text-transform: lowercase;
        border-radius: 10px !important;
        padding: 8px 12px !important;
        box-shadow: none !important;
    }

    .availability-modal .fc-col-header-cell {
        background: rgba(255,255,255,0.96);
    }

    .availability-modal .fc-col-header-cell-cushion {
        padding: 12px 4px;
        color: #a67c00;
        font-weight: 700;
        font-size: 12px;
        text-transform: uppercase;
        letter-spacing: 0.06em;
    }

    .availability-modal .fc-scrollgrid {
        border-radius: 16px;
        overflow: hidden;
    }

    .availability-modal .fc-daygrid-day-frame {
        min-height: 92px;
        padding: 8px;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
    }

    .availability-modal .fc-daygrid-day-number {
        color: rgba(255,255,255,0.78);
        font-weight: 600;
        font-size: 14px;
    }

    .availability-modal .fc-daygrid-day.availability-day-empty {
        background: rgba(255,255,255,0.02);
    }

    .availability-modal .fc-daygrid-day.availability-day-booked {
        background: rgba(46, 204, 113, 0.12);
    }

    .availability-modal .fc-daygrid-day.availability-day-almost-full {
        background: rgba(243, 156, 18, 0.14);
    }

    .availability-modal .fc-daygrid-day.availability-day-fully-booked {
        background: rgba(231, 76, 60, 0.15);
    }

    .availability-modal .fc-daygrid-day.availability-day-selected {
        outline: 2px solid rgba(212, 175, 55, 0.9);
        outline-offset: -2px;
    }

    .availability-modal .availability-count-badge {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        align-self: flex-start;
        min-width: 42px;
        padding: 5px 9px;
        border-radius: 999px;
        font-size: 11px;
        font-weight: 700;
        letter-spacing: 0.02em;
        color: #ffffff;
        background: rgba(46, 204, 113, 0.8);
    }

    .availability-modal .availability-count-badge.badge-empty {
        background: rgba(255,255,255,0.08);
        color: rgba(255,255,255,0.72);
    }

    .availability-modal .availability-count-badge.badge-almost-full {
        background: rgba(243, 156, 18, 0.88);
    }

    .availability-modal .availability-count-badge.badge-fully-booked {
        background: rgba(231, 76, 60, 0.88);
    }

    .availability-modal .fc-day-today .fc-daygrid-day-number {
        color: #fff;
        text-shadow: 0 0 10px rgba(212, 175, 55, 0.2);
    }

    .availability-modal .fc-event {
        border-radius: 6px;
        font-size: 12px;
        padding: 2px 4px;
    }

    @media (max-width: 600px) {
        .availability-modal {
            align-items: flex-end;
            padding: 10px;
        }

        .availability-modal-card {
            width: 100%;
            max-height: 92vh;
            border-radius: 18px;
            padding: 16px;
        }

        .availability-modal-header {
            align-items: flex-start;
            gap: 10px;
            margin-bottom: 12px;
            padding-bottom: 12px;
        }

        .availability-modal-header h3 {
            font-size: 18px;
            margin-bottom: 4px;
        }

        .availability-modal-header p {
            font-size: 13px;
            line-height: 1.45;
        }

        .availability-modal-close {
            width: 34px;
            height: 34px;
            border-radius: 10px;
            font-size: 18px;
            flex-shrink: 0;
        }

        .availability-modal .availability-legend {
            padding: 10px;
            gap: 8px;
            margin-bottom: 12px;
        }

        .availability-pill {
            font-size: 11px;
            padding: 7px 10px;
            gap: 6px;
        }

        .availability-modal .fc {
            padding: 10px;
            border-radius: 14px;
        }

        .availability-modal .fc-header-toolbar {
            margin-bottom: 10px !important;
            gap: 8px;
        }

        .availability-modal .fc-toolbar {
            flex-direction: row;
            align-items: center;
            justify-content: space-between;
        }

        .availability-modal .fc-toolbar-chunk {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .availability-modal .fc-toolbar-chunk:nth-child(2) {
            flex: 1;
            justify-content: center;
            min-width: 0;
        }

        .availability-modal .fc-toolbar-title {
            font-size: 16px !important;
            text-align: center;
            line-height: 1.2;
        }

        .availability-modal .fc-button {
            padding: 6px 10px !important;
            font-size: 12px !important;
        }

        .availability-modal .fc-col-header-cell-cushion {
            padding: 8px 2px;
            font-size: 10px;
            letter-spacing: 0.03em;
        }

        .availability-modal .fc-daygrid-day-frame {
            min-height: 72px;
            padding: 6px 4px;
        }

        .availability-modal .fc-daygrid-day-number {
            font-size: 12px;
        }

        .availability-modal .availability-count-badge {
            min-width: 34px;
            padding: 4px 7px;
            font-size: 10px;
        }
    }
    
    .gcash-section {
        display: none;
        margin-top: 20px;
        border-top: 1px solid var(--border-color);
        padding-top: 25px;
        background: rgba(255,255,255,0.03);
        padding: 25px;
        border-radius: 12px;
    }
    
    .gcash-qr {
        width: 220px;
        height: 220px;
        object-fit: cover;
        border-radius: 12px;
        border: 4px solid white;
        box-shadow: 0 5px 15px rgba(0,0,0,0.3);
    }
    
    /* Swap and Add-On Styles */
    .swap-container, .addon-container {
        padding: 0;
        border-radius: 8px;
        margin-bottom: 15px;
        border: none;
        transition: all 0.3s ease;
        background: transparent;
    }

    /* Redesigned Swap Card */
    .swap-card {
        background: rgba(30, 30, 30, 0.6);
        border: 1px solid rgba(255, 255, 255, 0.1);
        border-radius: 12px;
        margin-bottom: 15px;
        overflow: hidden;
        transition: all 0.3s ease;
        position: relative;
    }
    
    .swap-card:hover {
        border-color: rgba(155, 89, 182, 0.5);
        background: rgba(155, 89, 182, 0.05);
    }
    
    .swap-card.active-swap {
        border-color: #2ecc71;
        background: rgba(46, 204, 113, 0.05);
    }

    .swap-header-btn {
        width: 100%;
        padding: 15px 20px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        cursor: pointer;
        background: transparent;
        border: none;
        color: inherit;
        text-align: left;
    }
    
    .swap-title {
        font-weight: 600;
        font-size: 15px;
        color: #fff;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    
    .swap-price-badge {
        background: rgba(255, 255, 255, 0.1);
        padding: 4px 8px;
        border-radius: 6px;
        font-size: 12px;
        color: var(--accent-gold);
        font-weight: bold;
    }
    
    .swap-status-icon {
        width: 24px;
        height: 24px;
        border-radius: 50%;
        border: 2px solid rgba(255,255,255,0.2);
        display: flex;
        align-items: center;
        justify-content: center;
        transition: all 0.3s;
    }
    
    .active-swap .swap-status-icon {
        background: #2ecc71;
        border-color: #2ecc71;
        color: #fff;
    }

    .swap-expansion-panel {
        display: none;
        padding: 0 20px 20px 20px;
        border-top: 1px solid rgba(255, 255, 255, 0.05);
        animation: slideDown 0.3s ease-out;
    }
    
    @keyframes slideDown {
        from { opacity: 0; transform: translateY(-10px); }
        to { opacity: 1; transform: translateY(0); }
    }

    .step-label {
        font-size: 12px;
        text-transform: uppercase;
        letter-spacing: 1px;
        color: var(--text-muted);
        margin: 15px 0 10px 0;
        display: block;
    }

    .original-items-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
        gap: 10px;
    }

    .original-item-card {
        background: rgba(0, 0, 0, 0.2);
        border: 1px solid rgba(255, 255, 255, 0.1);
        border-radius: 8px;
        padding: 12px;
        font-size: 13px;
        text-align: center;
        cursor: pointer;
        transition: all 0.2s;
        color: var(--text-muted);
        position: relative;
        overflow: hidden;
    }

    .original-item-card:hover {
        border-color: #9b59b6;
        color: #fff;
        background: rgba(155, 89, 182, 0.1);
    }

    .original-item-card.selected-original {
        background: rgba(231, 76, 60, 0.15);
        border-color: #e74c3c;
        color: #e74c3c;
        text-decoration: line-through;
    }
    
    .original-item-card.selected-original::after {
        content: 'REPLACED';
        position: absolute;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%) rotate(-10deg);
        font-size: 10px;
        font-weight: bold;
        color: #e74c3c;
        border: 1px solid #e74c3c;
        padding: 2px 5px;
        border-radius: 4px;
        text-decoration: none;
        background: rgba(0,0,0,0.8);
    }

    /* Add-on Container (kept simple) */
    .addon-container {
        background: rgba(52, 152, 219, 0.15);
        border: 1px solid rgba(212, 175, 55, 0.3);
        padding: 15px;
    }
    
    .addon-option {
        display: flex;
        align-items: center;
        gap: 12px;
    }
    
    .addon-label {
        color: var(--accent-gold);
        display: flex;
        justify-content: space-between;
        width: 100%;
        cursor: pointer;
    }

    .menu-item {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 12px 15px;
        border-radius: 8px;
        margin-bottom: 10px;
        transition: all 0.3s ease;
    }

    .menu-category-block {
        margin-bottom: 18px;
    }

    .menu-category-block h5 {
        margin: 0 0 8px;
        color: var(--accent-gold);
        font-size: 14px;
    }
    
    .menu-item.included {
        background: rgba(255, 255, 255, 0.03);
        border: 1px solid rgba(255, 255, 255, 0.1);
    }
    
    .menu-item.included i { color: #2ecc71; }
    
    .menu-item.removed {
        opacity: 0.5;
        background: rgba(0, 0, 0, 0.2);
        border: 1px dashed rgba(255, 255, 255, 0.1);
    }
    .menu-item.removed .item-text { text-decoration: line-through; }
    .menu-item.removed i { color: #e74c3c; }

    .menu-item.added {
        background: rgba(46, 204, 113, 0.15);
        border: 1px solid rgba(46, 204, 113, 0.4);
        margin-left: 20px;
        animation: fadeIn 0.5s ease;
    }
    
    @keyframes fadeIn {
        from { opacity: 0; transform: translateX(-10px); }
        to { opacity: 1; transform: translateX(0); }
    }
    
    .menu-item.addon-item {
        background: rgba(52, 152, 219, 0.15);
        border: 1px solid rgba(52, 152, 219, 0.3);
    }
    
    .price-tag {
        color: var(--accent-gold);
        font-weight: normal;
        font-size: 12px;
    }
    
    .menu-item .item-text {
        font-size: 14px;
        font-weight: 500;
        color: var(--text-main);
    }

    /* Tabs System */
    .tabs-container {
        margin-bottom: 25px;
    }
    .tabs-header {
        display: flex;
        border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        margin-bottom: 20px;
        overflow-x: auto;
    }
    .tab-btn {
        padding: 15px 25px;
        background: transparent;
        border: none;
        color: var(--text-muted);
        font-weight: 600;
        cursor: pointer;
        border-bottom: 2px solid transparent;
        transition: all 0.3s;
        white-space: nowrap;
        font-size: 15px;
    }
    .tab-btn:hover {
        color: #fff;
    }
    .tab-btn.active {
        color: var(--accent-gold);
        border-bottom-color: var(--accent-gold);
    }

    @media (max-width: 600px) {
        .tabs-header {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 4px;
            overflow-x: visible;
            margin-bottom: 14px;
        }

        .tab-btn {
            min-width: 0;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 6px;
            min-height: 64px;
            padding: 10px 4px;
            font-size: 12px;
            line-height: 1.35;
            white-space: normal;
            overflow-wrap: anywhere;
            text-align: center;
        }

        .tab-btn i {
            margin-right: 0;
            font-size: 15px;
        }
    }

    .tab-content {
        display: none;
        animation: fadeIn 0.3s ease;
        max-height: 400px;
        overflow-y: auto;
        padding-right: 5px;
    }
    .tab-content.active {
        display: block;
    }
    /* Scrollbar styling for tab content */
    .tab-content::-webkit-scrollbar {
        width: 6px;
    }
    .tab-content::-webkit-scrollbar-track {
        background: rgba(255, 255, 255, 0.05);
        border-radius: 3px;
    }
    .tab-content::-webkit-scrollbar-thumb {
        background: rgba(255, 255, 255, 0.2);
        border-radius: 3px;
    }

    /* Swap UI - Custom Swap with Dropdown */
    .swap-row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 14px 18px;
        background: rgba(255, 255, 255, 0.03);
        border: 1px solid rgba(255, 255, 255, 0.08);
        border-radius: 10px;
        margin-bottom: 12px;
        transition: all 0.2s ease;
        flex-wrap: wrap;
        gap: 15px;
    }
    .swap-row:hover {
        background: rgba(255, 255, 255, 0.06);
        border-color: rgba(212, 175, 55, 0.4);
    }
    .swap-row.active {
        border-color: var(--accent-gold);
        background: rgba(212, 175, 55, 0.08);
    }
    .swap-header-bar {
        display: flex;
        align-items: center;
        justify-content: space-between;
        width: 100%;
        cursor: pointer;
        user-select: none;
        gap: 12px;
    }
    .swap-title-line {
        display: flex;
        align-items: center;
        flex-wrap: wrap;
        gap: 8px 12px;
    }
    .swap-badge-container {
        margin-top: 6px;
    }
    .swap-replaced-pill {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        background: rgba(46, 204, 113, 0.15);
        border: 1px solid rgba(46, 204, 113, 0.35);
        color: #2ecc71;
        font-size: 11.5px;
        padding: 2px 8px;
        border-radius: 12px;
        font-weight: 500;
    }
    .swap-replaced-pill b {
        color: #fff;
        font-weight: 600;
    }
    .swap-header-action {
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
    }
    .swap-chevron {
        color: var(--text-muted);
        font-size: 13px;
        transition: transform 0.2s ease, color 0.2s ease;
    }
    .swap-row.expanded .swap-chevron,
    .swap-row.active .swap-chevron {
        transform: rotate(180deg);
        color: var(--accent-gold);
    }
    .swap-left {
        display: flex;
        align-items: center;
        gap: 12px;
        cursor: pointer;
        min-width: 220px;
    }
    .swap-checkbox {
        width: 20px;
        height: 20px;
        accent-color: var(--accent-gold);
        cursor: pointer;
        flex-shrink: 0;
    }
    .swap-info {
        display: flex;
        flex-direction: column;
    }
    .swap-name {
        font-weight: 600;
        color: #fff;
        font-size: 15px;
    }
    .swap-dropdown-container {
        display: none;
        flex: 1 1 100%;
        flex-direction: column;
        gap: 10px;
        animation: fadeIn 0.2s ease-out;
        margin-top: 8px;
        padding-top: 14px;
        border-top: 1px dashed rgba(255,255,255,0.1);
    }
    .swap-dropdown-container.visible {
        display: flex;
    }
    .swap-dropdown-label {
        font-size: 12px;
        color: var(--accent-gold);
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        margin-bottom: 2px;
    }
    .swap-select {
        flex: 1;
        padding: 9px 12px;
        border-radius: 8px;
        background: rgba(0, 0, 0, 0.6);
        border: 1px solid var(--accent-gold);
        color: #fff;
        font-size: 13px;
        outline: none;
        cursor: pointer;
    }
    /* Card Grid Picker for menu items to replace */
    .swap-menu-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(130px, 1fr));
        gap: 8px;
        margin-top: 4px;
    }
    @media (max-width: 600px) {
        .swap-menu-grid { grid-template-columns: repeat(2, 1fr); }
    }
    .swap-menu-card {
        background: rgba(0,0,0,0.25);
        border: 1.5px solid rgba(255,255,255,0.1);
        border-radius: 8px;
        padding: 10px 8px;
        font-size: 12px;
        text-align: center;
        cursor: pointer;
        transition: all 0.18s ease;
        color: rgba(255,255,255,0.72);
        line-height: 1.35;
        user-select: none;
        word-break: break-word;
    }
    .swap-menu-card:hover {
        border-color: #9b59b6;
        color: #fff;
        background: rgba(155,89,182,0.15);
        transform: translateY(-1px);
    }
    .swap-menu-card.selected {
        background: rgba(231,76,60,0.18);
        border-color: #e74c3c;
        color: #e74c3c;
        font-weight: 600;
    }
    .swap-menu-card.selected::after {
        content: '✖ Replace';
        display: block;
        font-size: 10px;
        color: #e74c3c;
        margin-top: 3px;
        font-weight: 700;
    }
    .swap-menu-card.disabled-card {
        opacity: 0.35;
        cursor: not-allowed;
        pointer-events: none;
        text-decoration: line-through;
    }
    .swap-badge-free {
        display: inline-block;
        background: rgba(46, 204, 113, 0.15);
        color: #2ecc71;
        font-size: 11px;
        padding: 2px 8px;
        border-radius: 4px;
        font-weight: 600;
        border: 1px solid rgba(46, 204, 113, 0.3);
    }
    .swap-badge-price {
        display: inline-block;
        background: rgba(212, 175, 55, 0.15);
        color: var(--accent-gold);
        font-size: 11px;
        padding: 2px 8px;
        border-radius: 4px;
        font-weight: 600;
        border: 1px solid rgba(212, 175, 55, 0.3);
    }

    /* Sticky Summary */
    .sticky-summary {
        position: sticky;
        bottom: 20px;
        z-index: 99;
        box-shadow: 0 -5px 20px rgba(0,0,0,0.3);
        background: var(--card-bg); /* Ensure opacity */
    }

    /* Gold Input Style */
    .gold-input {
        border: 1px solid var(--accent-gold) !important;
        background: #000 !important;
        color: #fff !important;
        font-weight: bold;
        font-family: monospace;
        font-size: 16px;
        text-align: right;
    }
    
    .gold-input-label {
        color: #fff;
        font-weight: bold;
        font-size: 14px;
        margin-bottom: 5px;
        display: block;
    }

    .review-modal {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(0, 0, 0, 0.75);
        z-index: 3000;
        align-items: center;
        justify-content: center;
        padding: 20px;
    }

    .review-modal.active {
        display: flex;
    }

    .review-modal-card {
        width: 100%;
        max-width: 640px;
        max-height: 90vh;
        overflow-y: auto;
        background: #171717;
        border: 1px solid var(--border-color);
        border-radius: 16px;
        box-shadow: 0 20px 50px rgba(0, 0, 0, 0.4);
    }

    .review-modal-header {
        padding: 20px 22px;
        border-bottom: 1px solid var(--border-color);
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
    }

    .review-modal-header h3 {
        margin: 0;
        color: var(--accent-gold);
        font-size: 20px;
    }

    .review-close {
        background: transparent;
        border: none;
        color: var(--text-muted);
        font-size: 24px;
        cursor: pointer;
    }

    .review-modal-body {
        padding: 22px;
    }

    .review-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 14px;
        margin-bottom: 18px;
    }

    .review-item {
        padding: 4px 0;
        border-radius: 0;
        background: transparent;
        border: none;
    }

    .review-item.full {
        grid-column: 1 / -1;
    }

    .review-list {
        margin: 0;
        padding-left: 18px;
        color: var(--text-main);
        line-height: 1.7;
    }

    .review-list li + li {
        margin-top: 4px;
    }

    .review-label {
        display: block;
        margin-bottom: 6px;
        color: var(--text-muted);
        font-size: 12px;
        text-transform: uppercase;
        letter-spacing: 0.06em;
    }

    .review-value {
        color: var(--text-main);
        font-size: 15px;
        line-height: 1.6;
        word-break: break-word;
    }

    .review-note {
        margin: 0 0 18px;
        padding: 14px;
        border-radius: 10px;
        background: rgba(212, 175, 55, 0.08);
        border: 1px solid rgba(212, 175, 55, 0.18);
        color: var(--text-main);
        line-height: 1.6;
    }

    .policy-highlights {
        margin: 0 0 18px;
        padding: 16px;
        border-radius: 12px;
        background: rgba(231, 76, 60, 0.08);
        border: 1px solid rgba(231, 76, 60, 0.18);
    }

    .policy-highlights h4 {
        margin: 0 0 10px;
        color: #ffb4b4;
        font-size: 15px;
    }

    .review-actions {
        display: flex;
        justify-content: flex-end;
        gap: 12px;
    }

    .review-btn-secondary,
    .review-btn-primary {
        border-radius: 10px;
        padding: 12px 18px;
        font-weight: 700;
        cursor: pointer;
        border: 1px solid transparent;
    }

    .review-btn-secondary {
        background: transparent;
        border-color: var(--border-color);
        color: var(--text-main);
    }

    .review-btn-primary {
        background: var(--accent-gold);
        color: #000;
    }

    @media (max-width: 600px) {
        .review-grid {
            grid-template-columns: 1fr;
        }

        .review-actions {
            flex-direction: column;
        }
    }

    /* === Package Cards === */
    .pkg-card {
        background: rgba(255,255,255,0.03);
        border: 1px solid var(--border-color);
        border-left: 4px solid var(--border-color);
        border-radius: 10px;
        padding: 16px 18px;
        cursor: pointer;
        transition: border-color 0.2s, background 0.2s, transform 0.15s;
        user-select: none;
    }
    .pkg-card:hover {
        background: rgba(212,179,90,0.06);
        border-color: rgba(212,179,90,0.35);
        transform: translateY(-1px);
    }
    .pkg-card-selected {
        background: rgba(212,179,90,0.1) !important;
        border-color: var(--accent-gold) !important;
    }
    .pkg-card-selected .pkg-card-check { display: inline-flex !important; }

    /* === View Menu Button === */
    .btn-view-menu {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 5px 14px;
        font-size: 12px;
        font-weight: 600;
        color: var(--accent-gold);
        background: rgba(212,179,90,0.1);
        border: 1px solid rgba(212,179,90,0.35);
        border-radius: 20px;
        cursor: pointer;
        transition: background 0.2s, border-color 0.2s;
    }
    .btn-view-menu:hover {
        background: rgba(212,179,90,0.22);
        border-color: var(--accent-gold);
    }

    /* === Menu Preview Modal === */
    #menuPreviewModal {
        display: none;
        position: fixed;
        inset: 0;
        z-index: 9999;
        background: rgba(0,0,0,0.65);
        align-items: center;
        justify-content: center;
        padding: 16px;
    }
    #menuPreviewModal.open { display: flex; }
    .menu-preview-box {
        background: #1a1a2e;
        border: 1px solid var(--border-color);
        border-top: 3px solid var(--accent-gold);
        border-radius: 12px;
        width: 100%;
        max-width: 540px;
        max-height: 82vh;
        display: flex;
        flex-direction: column;
        overflow: hidden;
        box-shadow: 0 20px 60px rgba(0,0,0,0.6);
    }
    .menu-preview-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 16px 20px;
        border-bottom: 1px solid var(--border-color);
        flex-shrink: 0;
    }
    .menu-preview-title {
        color: #fff;
        font-size: 16px;
        font-weight: 700;
        margin: 0;
    }
    .menu-preview-subtitle {
        color: var(--text-muted);
        font-size: 12px;
        margin-top: 2px;
    }
    .menu-preview-close {
        background: transparent;
        border: none;
        color: var(--text-muted);
        font-size: 20px;
        cursor: pointer;
        padding: 4px 8px;
        border-radius: 6px;
        transition: color 0.2s, background 0.2s;
        flex-shrink: 0;
    }
    .menu-preview-close:hover { color: #fff; background: rgba(255,255,255,0.08); }
    .menu-preview-body {
        padding: 18px 20px;
        overflow-y: auto;
        flex: 1;
    }
    .menu-preview-category {
        margin-bottom: 18px;
    }
    .menu-preview-category-label {
        color: var(--accent-gold);
        font-size: 11px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.8px;
        margin-bottom: 8px;
        display: flex;
        align-items: center;
        gap: 6px;
    }
    .menu-preview-category-label::after {
        content: '';
        flex: 1;
        height: 1px;
        background: rgba(212,179,90,0.2);
    }
    .menu-preview-items {
        display: flex;
        flex-direction: column;
        gap: 5px;
    }
    .menu-preview-item {
        display: flex;
        align-items: flex-start;
        gap: 8px;
        color: var(--text-main);
        font-size: 13px;
        line-height: 1.5;
    }
    .menu-preview-item i {
        color: var(--accent-gold);
        font-size: 10px;
        margin-top: 4px;
        flex-shrink: 0;
    }
    .menu-preview-empty {
        color: var(--text-muted);
        font-size: 13px;
        text-align: center;
        padding: 32px 0;
    }
    /* Keep the mobile wizard in one scroll flow, with room for touch controls. */
    @media (max-width: 600px) {
        .booking-form-container,
        .booking-form-container.form-focused {
            padding: 20px 12px;
            box-sizing: border-box;
        }

        .tabs-container,
        .tab-content,
        .swap-left,
        .swap-info,
        .menu-item .item-text {
            min-width: 0;
        }

        .tab-content {
            max-height: none;
            overflow: visible;
            padding-right: 0;
        }

        .menu-item {
            gap: 8px;
            padding: 12px 10px;
            flex-wrap: wrap;
        }

        .menu-item > i {
            flex-shrink: 0;
        }

        .menu-item .item-text {
            flex: 1;
            overflow-wrap: anywhere;
            line-height: 1.5;
        }

        .swap-row {
            padding: 12px;
        }

        .swap-left {
            width: 100%;
        }

        .swap-name {
            overflow-wrap: anywhere;
        }

        .swap-dropdown-container {
            max-width: 100%;
            box-sizing: border-box;
        }

        .step-actions {
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(0, 2fr);
            gap: 10px;
            padding: 12px 0 max(12px, env(safe-area-inset-bottom));
            background: var(--card-bg);
            z-index: 2;
        }

        .step-actions .step-btn {
            min-width: 0;
            max-width: none;
            width: 100%;
            min-height: 48px;
            padding: 12px 8px;
            white-space: normal;
            overflow-wrap: anywhere;
        }
    }
</style>

<div class="booking-page-container<?php echo $has_preselected_set ? ' has-preselected-layout' : ''; ?>">
    <?php
    $cancel_url = 'browse-caterer.php?store_id=' . (int) $store_id;
    if (($_GET['cancel_from'] ?? '') === 'bookings') {
        $cancel_candidate = (string) ($_GET['cancel_return'] ?? 'booking.php');
        $cancel_parts = parse_url($cancel_candidate);
        if ($cancel_parts !== false && empty($cancel_parts['scheme']) && empty($cancel_parts['host'])
            && strpos($cancel_candidate, '//') !== 0 && strpos($cancel_candidate, '\\') === false
            && in_array($cancel_parts['path'] ?? '', ['booking.php', '/catering-portal/customer/booking.php'], true)) {
            $cancel_url = $cancel_candidate;
        }
    }
    // Separate cancel route for Book Again launched from a receipt.
    if (($_GET['cancel_from'] ?? '') === 'receipt') {
        $receipt_return_id = (int) ($_GET['receipt_id'] ?? 0);
        $receipt_owner_result = mysqli_query($conn, "SELECT id FROM bookings WHERE id = $receipt_return_id AND customer_id = '$user_id' LIMIT 1");
        if ($receipt_owner_result && mysqli_fetch_assoc($receipt_owner_result)) {
            $cancel_url = 'booking-receipt.php?id=' . $receipt_return_id;
            $receipt_return_query = [];
            parse_str((string) (parse_url((string) ($_GET['return_url'] ?? ''), PHP_URL_QUERY) ?? ''), $receipt_return_query);
            if (($receipt_return_query['return_to'] ?? '') === 'details') {
                $cancel_url .= '&return_to=details';
            }
        }
    }
    // The add-ons shortcut returns only to its originating booking details.
    if (($_GET['cancel_from'] ?? '') === 'booking_addons') {
        $addons_source_id = (int) ($_GET['source_booking_id'] ?? 0);
        $addons_source_result = mysqli_query($conn, "SELECT id FROM bookings WHERE id = $addons_source_id AND customer_id = '$user_id' LIMIT 1");
        if ($addons_source_result && mysqli_fetch_assoc($addons_source_result)) {
            $cancel_url = 'booking-details.php?id=' . $addons_source_id;
        }
    }
    $back_url = "browse-caterer.php?store_id=" . $store_id;
    if (isset($_GET['return_url']) && !empty($_GET['return_url'])) {
        $parsed = parse_url($_GET['return_url']);
        if (empty($parsed['host']) && !preg_match('#^//#', $_GET['return_url'])) {
            $back_url = htmlspecialchars($_GET['return_url']);
        }
    }
    // Book Again's arrow shares its validated list return destination.
    if (($_GET['cancel_from'] ?? '') === 'bookings' && isset($cancel_parts)
        && $cancel_url === $cancel_candidate) {
        $back_url = htmlspecialchars($cancel_url, ENT_QUOTES, 'UTF-8');
    }
    ?>
    
    <?php if ($success_message): ?>
        <div class="alert alert-success" style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px;">
            <div><i class="fas fa-check-circle"></i> <?php echo $success_message; ?></div>
            <?php if (!empty($redirect_url)): ?>
                <a href="<?php echo $redirect_url; ?>" style="display: inline-flex; align-items: center; gap: 6px; background: #4caf50; color: #fff; padding: 10px 20px; border-radius: 8px; text-decoration: none; font-weight: 600; font-size: 14px; white-space: nowrap;">
                    <i class="fas fa-eye"></i> View Your Booking
                </a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    
    <?php if ($error_message === 'This caterer is currently closed and is not accepting bookings right now.'): ?>
        <?php require __DIR__ . '/includes/caterer-closed-modal.php'; ?>
    <?php elseif ($duplicate_event_error): ?>
        <dialog class="sage-notice-dialog" id="duplicateBookingNotice" data-auto-notice aria-labelledby="duplicateBookingTitle">
            <h2 id="duplicateBookingTitle">Event already booked</h2>
            <p><?php echo htmlspecialchars($error_message, ENT_QUOTES, 'UTF-8'); ?></p>
            <div class="sage-notice-actions"><button type="button" data-notice-close>OK</button></div>
        </dialog>
    <?php elseif ($error_message): ?>
        <div class="alert alert-error">
            <i class="fas fa-exclamation-circle"></i> <?php echo $error_message; ?>
        </div>
    <?php endif; ?>
    
    <?php
    $sidebarSetName = $has_preselected_set ? ($preselected_set['set_name'] ?? $preselected_set['name'] ?? 'Selected Package') : '';
    $sidebarPerHead = $has_preselected_set ? floatval($preselected_set['per_head_price'] ?? 0) : 0;
    $sidebarPax = $has_preselected_set ? intval($preselected_set['pax'] ?? 0) : 0;
    $sidebarExtra = $has_preselected_set ? floatval($preselected_set['extra_guest_rate'] ?? 0) : 0;
    $sidebarPriceLabel = $has_preselected_set ? ($sidebarPerHead > 0
        ? '₱' . number_format($sidebarPerHead, 2) . ' per guest'
        : '₱' . number_format(floatval($preselected_set['price']), 2) . ($sidebarPax > 0 ? ' · Good for ' . $sidebarPax . ' guests' : '')) : '';
    
    $imgPath = '../uploads/products/default.png';
    if (!empty($product['image'])) {
        $imgPath = $product['image'][0] === '.' ? $product['image'] : '../' . $product['image'];
    }
    ?>
    <div class="booking-grid<?php echo $has_preselected_set ? ' has-preselected-set' : ''; ?>">
        <div class="booking-form-container<?php echo $has_preselected_set ? ' form-focused' : ''; ?>">
            <div class="booking-heading">
                <a href="<?php echo $back_url; ?>" class="back-link" aria-label="Back to previous page" title="Back to previous page">
                    <span aria-hidden="true">&#8592;</span>
                </a>
                <div>
                    <h2 class="form-title" style="margin: 0 0 2px;"><i class="fas fa-calendar-check" style="color: var(--accent-gold);"></i> Book This Service</h2>
                    <p style="margin: 0; color: var(--text-muted); font-size: 14px;">
                        <strong style="color: #fff; font-size: 15px;"><?php echo htmlspecialchars($product['name']); ?></strong> · <i class="fas fa-caterer" style="color: var(--accent-gold);"></i> <?php echo htmlspecialchars($product['store_name']); ?>
                        <?php if ($has_preselected_set): ?>
                            · <span style="color: var(--accent-gold); font-weight: 600;"><i class="fas fa-box-open"></i> <?php echo htmlspecialchars($sidebarSetName); ?></span>
                        <?php endif; ?>
                    </p>
                </div>

            </div>
            
            <form action="" method="POST" enctype="multipart/form-data" id="bookingForm">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <div class="booking-step-indicator<?php echo $has_preselected_set ? ' preselected-flow' : ''; ?>" aria-hidden="true">
                    <?php if ($has_preselected_set): ?>
                    <div class="booking-step-dot active" data-step-dot="2"><span></span>Customize</div>
                    <div class="booking-step-dot" data-step-dot="3"><span></span>Event</div>
                    <div class="booking-step-dot" data-step-dot="4"><span></span>Pay</div>
                    <?php else: ?>
                    <div class="booking-step-dot active" data-step-dot="1"><span></span>Package</div>
                    <div class="booking-step-dot" data-step-dot="2"><span></span>Customize</div>
                    <div class="booking-step-dot" data-step-dot="3"><span></span>Event</div>
                    <div class="booking-step-dot" data-step-dot="4"><span></span>Pay</div>
                    <?php endif; ?>
                </div>
                
                <div class="booking-step<?php echo $has_preselected_set ? ' preselected-package-step' : ' active'; ?>" data-step="1">
                <?php if ($is_catering && !empty($sets)): ?>
                <div class="booking-step-body">
                <div class="form-group">
                    <label class="form-label">Choose a package</label>
                    <!-- Hidden select kept for JS compatibility -->
                    <select name="notes_package" id="packageSelect" class="form-control" required style="display:none;" onchange="loadSet(this.value)">
                        <option value="" data-price="0">-- Choose a package --</option>
                        <?php foreach ($sets as $set):
                            $setId = $set['id'];
                            $setName = $set['set_name'] ?? $set['name'] ?? 'Unnamed Package';
                            $setPerHead = floatval($set['per_head_price'] ?? 0);
                            $setExtraGuest = floatval($set['extra_guest_rate'] ?? 0);
                            $paxDisplay = (!empty($set['pax']) && $set['pax'] > 0) ? "Good for " . intval($set['pax']) . " guests" : "per guest";
                            $isSelectedSet = $has_preselected_set && strcasecmp(trim($setName), trim($preselected_set_name)) === 0;
                        ?>
                            <option value="<?php echo htmlspecialchars($setName); ?>" data-price="<?php echo $set['price']; ?>" data-pax="<?php echo intval($set['pax'] ?? 0); ?>" data-per-head="<?php echo $setPerHead; ?>" data-extra-guest="<?php echo $setExtraGuest; ?>" data-set-id="<?php echo $setId; ?>"<?php echo $isSelectedSet ? ' selected' : ''; ?>>
                                <?php echo htmlspecialchars($setName); ?> - ₱<?php echo number_format($setPerHead > 0 ? $setPerHead : $set['price'], 2); ?> <?php echo $setPerHead > 0 ? 'per guest' : $paxDisplay; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <!-- Package Cards -->
                    <div id="packageCardsContainer" style="display: flex; flex-direction: column; gap: 12px; margin-top: 4px;">
                        <?php foreach ($sets as $set):
                            $setId = $set['id'];
                            $setName = $set['set_name'] ?? $set['name'] ?? 'Unnamed Package';
                            $setPerHead = floatval($set['per_head_price'] ?? 0);
                            $setExtraGuest = floatval($set['extra_guest_rate'] ?? 0);
                            $setPax = intval($set['pax'] ?? 0);
                            $setPrice = floatval($set['price'] ?? 0);
                            $isSelectedSet = $has_preselected_set && strcasecmp(trim($setName), trim($preselected_set_name)) === 0;
                            if ($setPerHead > 0) {
                                $priceLabel = '₱' . number_format($setPerHead, 2) . ' <span style="font-size:12px;font-weight:400;opacity:0.8">/ per guest</span>';
                                if ($setExtraGuest > 0) {
                                    $priceLabel .= '<br><span style="font-size:12px;font-weight:400;opacity:0.7">Extra guest: ₱' . number_format($setExtraGuest, 2) . '</span>';
                                }
                            } else {
                                $priceLabel = '₱' . number_format($setPrice, 2);
                                if ($setPax > 0) $priceLabel .= ' <span style="font-size:12px;font-weight:400;opacity:0.8">/ ' . $setPax . ' guests</span>';
                            }
                            $menuData = htmlspecialchars(json_encode($set['description'] ?? ''), ENT_QUOTES, 'UTF-8');
                        ?>
                        <div class="pkg-card<?php echo $isSelectedSet ? ' pkg-card-selected' : ''; ?>"
                             tabindex="0" onkeydown="if (event.target === this && (event.key === 'Enter' || event.key === ' ')) { event.preventDefault(); this.click(); }" data-set-name="<?php echo htmlspecialchars($setName); ?>"
                             onclick="selectPackageCard(this, '<?php echo htmlspecialchars(addslashes($setName)); ?>')">
                            <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:12px;">
                                <div style="flex:1; min-width:0;">
                                    <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap; margin-bottom:4px;">
                                        <span class="package-name"><?php echo htmlspecialchars($setName); ?></span>
                                        <?php if ($setPerHead > 0): ?>
                                            <span style="font-size:11px; background:rgba(212,179,90,0.15); color:var(--accent-gold); padding:2px 8px; border-radius:20px; font-weight:600;">Per Guest</span>
                                        <?php else: ?>
                                            <span style="font-size:11px; background:rgba(100,180,100,0.15); color:#6dbf6d; padding:2px 8px; border-radius:20px; font-weight:600;">Fixed Package</span>
                                        <?php endif; ?>
                                    </div>
                                    <div style="font-size:13px; color:var(--text-muted);">
                                        <?php if ($setPax > 0 && $setPerHead <= 0): ?>
                                            Good for <?php echo $setPax; ?> guests
                                        <?php elseif ($setPerHead > 0 && $setPax > 0): ?>
                                            Min <?php echo $setPax; ?> guests
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div style="text-align:right; flex-shrink:0;">
                                    <div style="color:var(--accent-gold); font-weight:700; font-size:15px; line-height:1.4;"><?php echo $priceLabel; ?></div>
                                </div>
                            </div>
                            <div style="margin-top:10px; display:flex; align-items:center; gap:10px;">
                                <button type="button"
                                    class="btn-view-menu"
                                    onclick="event.stopPropagation(); openMenuModal('<?php echo htmlspecialchars(addslashes($setName)); ?>')"
                                    title="View included menu">
                                    <i class="fas fa-utensils"></i> View Menu
                                </button>
                                <span class="pkg-card-check" style="margin-left:auto; color:var(--accent-gold); font-size:13px; display:none;">
                                    <i class="fas fa-check-circle"></i> Selected
                                </span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php if (!$has_preselected_set): ?>
                <div class="selected-set-banner" id="selectedSetBanner" style="display:none;">Selected package will appear here.</div>

                <?php endif; ?>
                </div>

                <div class="step-actions">
                    <button type="button" class="step-btn secondary" onclick="<?php echo htmlspecialchars('window.location.href=' . json_encode($cancel_url), ENT_QUOTES, 'UTF-8'); ?>">Cancel</button>
                    <button type="button" class="step-btn primary" onclick="goToBookingStep(2)">Continue</button>
                </div>
                <?php else: ?>
                <div class="booking-step-body">
                <div class="selected-set-banner">Standard Service</div>
                </div>
                <div class="step-actions">
                    <button type="button" class="step-btn secondary" onclick="<?php echo htmlspecialchars('window.location.href=' . json_encode($cancel_url), ENT_QUOTES, 'UTF-8'); ?>">Cancel</button>
                    <button type="button" class="step-btn primary" onclick="goToBookingStep(2)">Continue</button>
                </div>
                <?php endif; ?>
                </div>
                
                <div class="booking-step<?php echo $has_preselected_set ? ' active' : ''; ?>" data-step="2">
                <div class="booking-step-body">

                <!-- Live price summary for Step 2 -->
                <div id="step2PriceSummary" style="display:none; background: rgba(212,175,55,0.08); border: 1px solid rgba(212,175,55,0.25); border-radius: 10px; padding: 10px 16px; margin-bottom: 14px; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap;">
                    <div style="font-size: 13px; color: var(--text-muted);">Estimated Total</div>
                    <div style="font-size: 18px; font-weight: 700; color: var(--accent-gold);" id="step2TotalDisplay">₱0.00</div>
                </div>

                <div id="menuCustomization" class="form-group">
                    <!-- Will be populated by JavaScript -->
                </div>
                </div>
                <div class="step-actions">
                    <button type="button" class="step-btn secondary" onclick="<?php echo $has_preselected_set ? htmlspecialchars('window.location.href=' . json_encode(htmlspecialchars_decode($back_url, ENT_QUOTES)), ENT_QUOTES, 'UTF-8') : 'goToBookingStep(1)'; ?>">Back</button>
                    <button type="button" class="step-btn primary" onclick="goToBookingStep(3)">Continue</button>
                </div>
                </div>
                
                <div class="booking-step" data-step="3">
                <div class="booking-step-body">
                <div class="schedule-group">
                    <div class="schedule-group-header">
                        <h3 class="schedule-group-title">Schedule Details</h3>
                        <p class="schedule-group-copy">Choose your event date and time, then check availability if needed.</p>
                    </div>

                    <div class="form-row">
                        <div class="form-group" style="margin-bottom: 0;">
                            <label class="form-label">Event Date</label>
                            <div class="date-picker-shell">
                                <button type="button" id="miniDateTrigger" class="mini-date-trigger" onclick="toggleMiniDatePicker()">
                                    <span id="miniDateTriggerText" class="mini-date-trigger-placeholder">Select your event date</span>
                                    <i class="fas fa-chevron-down" style="color: var(--accent-gold);"></i>
                                </button>
                                <div id="miniDatePicker" class="mini-date-picker" aria-hidden="true">
                                    <div class="mini-date-picker-header">
                                        <button type="button" id="miniDatePickerTitle" class="mini-date-picker-title"></button>
                                        <div class="mini-date-picker-nav">
                                            <button type="button" onclick="changeMiniPickerMonth(-1)" aria-label="Previous month"><i class="fas fa-chevron-left"></i></button>
                                            <button type="button" onclick="changeMiniPickerMonth(1)" aria-label="Next month"><i class="fas fa-chevron-right"></i></button>
                                        </div>
                                    </div>
                                    <div class="mini-date-weekdays">
                                        <span>Su</span>
                                        <span>Mo</span>
                                        <span>Tu</span>
                                        <span>We</span>
                                        <span>Th</span>
                                        <span>Fr</span>
                                        <span>Sa</span>
                                    </div>
                                    <div id="miniDateGrid" class="mini-date-grid"></div>
                                    <div class="mini-date-footer">
                                        <button type="button" onclick="clearMiniDateSelection()">Clear</button>
                                        <button type="button" onclick="jumpMiniPickerToToday()">Today</button>
                                    </div>
                                </div>
                            </div>
                            <input type="date" name="delivery_date" id="deliveryDateInput" class="date-picker-hidden-input" required min="<?php echo max($min_booking_date, $current_year_start); ?>" max="<?php echo $current_year_end; ?>">
                        </div>
                        
                        <div class="form-group" style="margin-bottom: 0;">
                            <label class="form-label">Event Time</label>
                            <div class="time-picker-shell">
                                <button type="button" id="miniTimeTrigger" class="mini-time-trigger" onclick="toggleMiniTimePicker()">
                                    <span id="miniTimeTriggerText" class="mini-time-trigger-placeholder">Select event time</span>
                                    <i class="fas fa-clock" style="color: var(--accent-gold);"></i>
                                </button>
                                <div id="miniTimePicker" class="mini-time-picker" aria-hidden="true">
                                    <div class="mini-time-column">
                                        <div class="mini-time-column-header">Available event times</div>
                                        <div id="miniTimeSlots" class="mini-time-options"></div>
                                    </div>
                                </div>
                            </div>
                            <input type="time" name="delivery_time" id="deliveryTimeInput" class="date-picker-hidden-input" min="06:00" max="22:30" step="1800" required>
                        </div>
                    </div>


                </div>
                
                <div class="form-group">
                    <?php if (!empty($product['event_type'])): ?>
                        <label class="form-label">Event Type</label>
                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($product['event_type']); ?>" readonly style="background-color: #222;">
                        <input type="hidden" name="event_type" id="eventType" value="<?php echo htmlspecialchars($product['event_type']); ?>">
                        <small class="guest-hint">This service is specifically for a <?php echo htmlspecialchars($product['event_type']); ?> event.</small>
                    <?php else: ?>
                        <label class="form-label">Event Type</label>
                        <select name="event_type" id="eventType" class="form-control" required>
                            <option value="" disabled hidden selected>Select event type...</option>
                            <option value="Birthday">Birthday</option>
                            <option value="Wedding">Wedding</option>
                            <option value="Corporate Event">Corporate Event</option>
                            <option value="Anniversary">Anniversary</option>
                            <option value="Baptism">Baptism / Christening</option>
                            <option value="Family Gathering">Family Gathering</option>
                            <option value="Other">Other</option>
                        </select>
                    <?php endif; ?>
                </div>

                <div class="form-group" id="quantityGroup">
                    <label class="form-label">Number of guests</label>
                    <input type="text" aria-describedby="guestHint guestMaximumHint" name="quantity" id="quantity" class="form-control" inputmode="numeric" pattern="[1-9][0-9]{0,4}|100000" data-minimum="<?php echo $minimum_guests; ?>" required oninput="calculateTotal()">
                    <small id="guestMaximumHint" class="guest-hint">Maximum <?php echo number_format($maximum_guests); ?> guests per booking.</small>
                    <small id="guestHint" class="guest-hint">Minimum <?php echo $minimum_guests; ?> guests.</small>
                </div>
                <div class="form-group">
                    <label class="form-label">Event venue / address</label>
                    <input type="text" name="delivery_address" class="form-control" required placeholder="Full venue address">
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">On-site contact name</label>
                        <input type="text" name="venue_contact_name" id="venueContactName" class="form-control" maxlength="250" oninput="this.value = Array.from(this.value.replace(/[^\p{L} ]/gu, '')).slice(0, 250).join('')" placeholder="Person to look for at the venue">
                    </div>
                    <div class="form-group">
                        <label class="form-label">On-site contact phone</label>
                        <input type="text" name="venue_contact_phone" id="venueContactPhone" class="form-control" inputmode="numeric" maxlength="11" pattern="[0-9]{1,11}" oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 11)" placeholder="09XXXXXXXXX">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Allergies / dietary notes (optional)</label>
                    <textarea name="dietary_notes" id="dietaryNotes" class="form-control" rows="2" placeholder="No pork, halal, vegetarian guests, peanut allergy..."></textarea>
                </div>
                </div>
                <div class="step-actions">
                    <button type="button" class="step-btn secondary" onclick="goToBookingStep(2)">Back</button>
                    <button type="button" class="step-btn primary" onclick="goToBookingStep(4)">Continue</button>
                </div>
                </div>
                
                <div class="booking-step" data-step="4">
                <div class="booking-step-body">
                <div class="total-box sticky-summary">
                    <ul class="price-breakdown" id="priceBreakdownList"></ul>
                    <div class="total-row">
                        <span style="font-size: 16px;">Estimated Total:</span>
                        <strong id="displayTotal" class="total-amount">₱0.00</strong>
                    </div>
                    <div class="total-row" style="font-size: 15px; color: var(--text-muted);">
                        <span>Required Downpayment (50%):</span>
                        <strong id="displayDownpayment">₱0.00</strong>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Payment Method</label>
                    <select name="payment_method" id="paymentMethod" class="form-control" required onchange="toggleGCash()">
                        <option value="Cash">Cash</option>
                        <option value="GCash" selected>GCash</option>
                    </select>
                </div>
                
                <!-- Payment Section -->
                <div id="gcashSection" class="gcash-section">
                    <h4 style="color: var(--accent-gold); margin-bottom: 15px; text-align: center; font-size: 18px;"><i class="fas fa-wallet"></i> GCash Payment</h4>
                    <div style="text-align: center; margin-bottom: 20px;">
                        <p style="margin-top: 15px; color: #fff; font-weight: bold; font-size: 20px;">
                            Amount to Pay: <span id="gcashAmount" style="color: var(--accent-gold);">₱0.00</span>
                        </p>
                    </div>
                </div>
                
                <input type="hidden" name="total_amount" id="totalAmount" value="0">
                <input type="hidden" name="add_ons_json" id="addOnsJson" value="[]">
                <input type="hidden" name="swaps_json" id="swapsJson" value="[]">
                <input type="hidden" name="build_menu_json" id="buildMenuJson" value="[]">

                <div class="terms-section">

                    <details class="terms-card" style="margin-bottom: 0;">
                        <summary>
                            <h4><i class="fas fa-caterer"></i> <?php echo htmlspecialchars($product['store_name']); ?> Terms and Conditions</h4>
                        </summary>
                        <div class="terms-body">
                            <?php
                            $store_terms_content = trim($product['store_terms_conditions'] ?? '');
                            echo $store_terms_content !== ''
                                ? nl2br(htmlspecialchars($store_terms_content))
                                : '<span style="color: var(--text-muted);">This caterer has not added custom terms yet.</span>';
                            ?>
                        </div>
                    </details>
                </div>
                
                <div class="form-group" style="margin-top: 20px; background: rgba(255,255,255,0.05); padding: 15px; border-radius: 8px;">
                    <label style="display: flex; align-items: center; cursor: pointer; color: var(--text-main);">
                        <input type="checkbox" name="terms_agree" required style="width: 20px; height: 20px; accent-color: var(--accent-gold); margin-right: 10px;">
                        <span>
                            I agree to the Caterer Terms shown above.
                        </span>
                    </label>
                </div>
                </div>
                <div class="step-actions">
                    <button type="button" class="step-btn secondary" onclick="goToBookingStep(3)">Back</button>
                    <button type="submit" class="step-btn primary">
                        <i class="fas fa-clipboard-check"></i> Review Booking
                    </button>
                </div>
                </div>
            </form>
        </div>
    </div>
</div>

<div id="availabilityModal" class="availability-modal" aria-hidden="true">
    <div class="availability-modal-card">
        <div class="availability-modal-header">
            <div>
                <h3><i class="fas fa-calendar-days"></i> Date Availability</h3>
            </div>
            <button type="button" class="availability-modal-close" onclick="closeAvailabilityModal()" aria-label="Close availability modal">&times;</button>
        </div>
        <div class="availability-legend">
            <span class="availability-pill available">Available</span>
            <span class="availability-pill almost-full">Almost Full</span>
            <span class="availability-pill fully-booked">Fully Booked</span>
        </div>
        <div id="bookingAvailabilityCalendar"></div>
    </div>
</div>

<div id="reviewModal" class="review-modal" role="dialog" aria-modal="true" aria-labelledby="reviewModalTitle" aria-hidden="true">
    <div class="review-modal-card">
        <div class="review-modal-header">
            <h3 id="reviewModalTitle"><i class="fas fa-clipboard-check" aria-hidden="true"></i> Review Your Booking</h3>
            <button type="button" class="review-close" onclick="closeReviewModal()" aria-label="Close review modal">&times;</button>
        </div>
        <div class="review-modal-body">
            <section class="review-section">
                <h4>Package details</h4>
                <div class="review-grid">
                    <div class="review-item">
                        <span class="review-label">Caterer</span>
                        <div class="review-value" id="reviewStore"><?php echo htmlspecialchars($product['store_name']); ?></div>
                    </div>
                    <div class="review-item">
                        <span class="review-label">Service</span>
                        <div class="review-value" id="reviewService"><?php echo htmlspecialchars($product['name']); ?></div>
                    </div>
                    <div class="review-item">
                        <span class="review-label">Package</span>
                        <div class="review-value" id="reviewPackage">Not selected</div>
                    </div>
                    <div class="review-item">
                        <span class="review-label">Number of guests</span>
                        <div class="review-value" id="reviewQuantity">0</div>
                    </div>
                </div>
            </section>
            <section class="review-section">
                <h4>Event details</h4>
                <div class="review-grid">
                    <div class="review-item full">
                        <span class="review-label">Event Type</span>
                        <div class="review-value" id="reviewEventType">Not selected</div>
                    </div>
                    <div class="review-item">
                        <span class="review-label">Event Date</span>
                        <div class="review-value" id="reviewDate">Not set</div>
                    </div>
                    <div class="review-item">
                        <span class="review-label">Event Time</span>
                        <div class="review-value" id="reviewTime">Not set</div>
                    </div>
                    <div class="review-item full">
                        <span class="review-label">Venue / Address</span>
                        <div class="review-value" id="reviewAddress">Not set</div>
                    </div>
                    <div class="review-item full">
                        <span class="review-label">On-site contact</span>
                        <div class="review-value" id="reviewVenueContact">Not set</div>
                    </div>
                </div>
            </section>
            <section class="review-section">
                <h4>Menu and requests</h4>
                <div class="review-grid">
                    <div class="review-item full">
                        <span class="review-label">Add-ons</span>
                        <div class="review-value" id="reviewAddOns">No add-ons selected.</div>
                    </div>
                    <div class="review-item full">
                        <span class="review-label">Swaps</span>
                        <div class="review-value" id="reviewSwaps">No swaps selected.</div>
                    </div>
                    <div class="review-item full">
                        <span class="review-label">Allergies / dietary</span>
                        <div class="review-value" id="reviewDietary">None</div>
                    </div>
                    <div class="review-item full">
                        <span class="review-label">Custom Request</span>
                        <div class="review-value" id="reviewCustomRequest">No custom request added.</div>
                    </div>
                </div>
            </section>
            <section class="review-section">
                <h4>Payment summary</h4>
                <div class="review-grid">
                    <div class="review-item">
                        <span class="review-label">Payment Method</span>
                        <div class="review-value" id="reviewPaymentMethod">Not set</div>
                    </div>
                    <div class="review-item">
                        <span class="review-label">Payment Proof</span>
                        <div class="review-value" id="reviewPaymentProof">Not uploaded</div>
                    </div>
                    <div class="review-item full">
                        <span class="review-label">Price breakdown</span>
                        <div class="review-value" id="reviewBreakdown">-</div>
                    </div>
                    <div class="review-item">
                        <span class="review-label">Estimated Total</span>
                        <div class="review-value" id="reviewTotal">P0.00</div>
                    </div>
                    <div class="review-item">
                        <span class="review-label">Required Downpayment</span>
                        <div class="review-value" id="reviewDownpayment">P0.00</div>
                    </div>
                </div>
            </section>
        </div>
            <div class="review-actions">
                <button type="button" class="review-btn-secondary" onclick="closeReviewModal()">Back to Edit</button>
                <button type="button" class="review-btn-primary" id="finalConfirmBookingBtn">Submit Booking</button>
            </div>
    </div>
</div>

<!-- Menu Preview Modal -->
<div id="menuPreviewModal" role="dialog" aria-modal="true" aria-labelledby="menuPreviewTitle">
    <div class="menu-preview-box" onclick="event.stopPropagation()">
        <div class="menu-preview-header">
            <div>
                <h3 class="menu-preview-title" id="menuPreviewTitle">Menu Package</h3>
                <div class="menu-preview-subtitle">Included dishes</div>
            </div>
            <button type="button" class="menu-preview-close" aria-label="Close menu preview" onclick="closeMenuModal()"><i class="fas fa-times"></i></button>
        </div>
        <div class="menu-preview-body" id="menuPreviewContent">
            <!-- Content will be generated by JS -->
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.js"></script>
<script src="../assets/js/booking-pricing.js?v=<?php echo time(); ?>"></script>
<script src="../assets/js/booking-draft-state.js?v=20261027-1"></script>
<script>
// New navigation starts a new booking. Reload and Back keep the current draft.
const bookingNavigationType = performance.getEntriesByType('navigation')[0]?.type
    || (performance.navigation?.type === 1 ? 'reload' : performance.navigation?.type === 2 ? 'back_forward' : 'navigate');
BookingDraftState.resetForNewVisit(sessionStorage, <?php echo (int) $product_id; ?>, <?php echo (int) $user_id; ?>,
    <?php echo json_encode($_SERVER['REQUEST_METHOD']); ?>, bookingNavigationType, location.origin);
// Caterer sets data globally
const setsData = <?php echo json_encode($sets); ?>;
const menuCategoryLabels = <?php echo json_encode(customerMenuCategoryLabels()); ?>;
let activeAddOns = [];
let activeSwaps = [];
let activeBuildMenuItems = [];
let currentSetSwaps = [];
let bookingReviewConfirmed = false;
const storeAvailabilityEndpoint = 'get_store_availability.php?store_id=<?php echo intval($store_id); ?>';
const bookingCapacityPerDay = <?php echo intval($store_capacity); ?>;
const currentBookingYear = <?php echo intval($current_booking_year); ?>;
const currentYearStart = '<?php echo $min_booking_date; ?>';
const currentYearEnd = '<?php echo $current_year_end; ?>';

let storeHolidays = <?php
    $holidays = [];
    $hol_res = mysqli_query($conn, "SELECT holiday_date FROM store_holidays WHERE store_id = '$store_id'");
    if ($hol_res) { while($hr = mysqli_fetch_assoc($hol_res)) { $holidays[] = $hr['holiday_date']; } }
    echo json_encode($holidays);
?>;
let availabilityCalendar = null;
let availabilityCache = {};
let availabilityCalendarLoaded = false;
let currentCalendarRange = { start: null, end: null };
let miniPickerMonth = null;
const bookingTimeSlots = Array.from({ length: 34 }, (_, index) => {
    const totalMinutes = 360 + (index * 30);
    return `${String(Math.floor(totalMinutes / 60)).padStart(2, '0')}:${String(totalMinutes % 60).padStart(2, '0')}`;
});

function formatDateKey(date) {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
}

function enumerateDates(startStr, endStr) {
    const dates = [];
    const cursor = new Date(startStr + 'T00:00:00');
    const end = new Date(endStr + 'T00:00:00');

    while (cursor < end) {
        dates.push(formatDateKey(cursor));
        cursor.setDate(cursor.getDate() + 1);
    }

    return dates;
}
function getAvailabilityEntry(dateKey) {
    return availabilityCache[dateKey] || {
        date: dateKey,
        active_orders: 0,
        max_orders_per_day: bookingCapacityPerDay,
        slots_remaining: bookingCapacityPerDay,
        status: 'available'
    };
}

function formatMiniPickerDate(dateObj) {
    return dateObj.toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'long',
        day: 'numeric'
    });
}

function syncMiniDateTrigger() {
    const dateInput = document.getElementById('deliveryDateInput');
    const triggerText = document.getElementById('miniDateTriggerText');
    if (!dateInput || !triggerText) {
        return;
    }

    if (dateInput.value) {
        const selected = new Date(dateInput.value + 'T00:00:00');
        triggerText.textContent = formatMiniPickerDate(selected);
        triggerText.className = 'mini-date-trigger-value';
    } else {
        triggerText.textContent = 'Select your event date';
        triggerText.className = 'mini-date-trigger-placeholder';
    }
}

function formatMiniTimeDisplay(value) {
    if (!value) {
        return 'Select event time';
    }

    const parsed = new Date('1970-01-01T' + value);
    if (Number.isNaN(parsed.getTime())) {
        return value;
    }

    return parsed.toLocaleTimeString('en-US', {
        hour: 'numeric',
        minute: '2-digit'
    });
}

function syncMiniTimeTrigger() {
    const input = document.getElementById('deliveryTimeInput');
    const triggerText = document.getElementById('miniTimeTriggerText');
    if (!input || !triggerText) {
        return;
    }

    triggerText.textContent = formatMiniTimeDisplay(input.value);
    triggerText.className = input.value ? 'mini-time-trigger-value' : 'mini-time-trigger-placeholder';
}

function openMiniTimePicker() {
    const picker = document.getElementById('miniTimePicker');
    if (!picker) {
        return;
    }
    picker.classList.add('active');
    picker.setAttribute('aria-hidden', 'false');
    renderMiniTimePicker();
}

function closeMiniTimePicker() {
    const picker = document.getElementById('miniTimePicker');
    if (!picker) {
        return;
    }
    picker.classList.remove('active');
    picker.setAttribute('aria-hidden', 'true');
}

function toggleMiniTimePicker() {
    const picker = document.getElementById('miniTimePicker');
    if (!picker) {
        return;
    }
    if (picker.classList.contains('active')) {
        closeMiniTimePicker();
    } else {
        openMiniTimePicker();
    }
}

function selectMiniTimeSlot(value) {
    const input = document.getElementById('deliveryTimeInput');
    if (!input) {
        return;
    }
    input.value = value;
    syncMiniTimeTrigger();
    renderMiniTimePicker();
    closeMiniTimePicker();
}

function renderMiniTimePicker() {
    const slotWrap = document.getElementById('miniTimeSlots');
    const input = document.getElementById('deliveryTimeInput');
    if (!slotWrap || !input) {
        return;
    }

    slotWrap.innerHTML = '';
    bookingTimeSlots.forEach((time) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.textContent = formatMiniTimeDisplay(time);
        if (time === input.value) {
            button.classList.add('is-selected');
        }
        button.addEventListener('click', function() {
            selectMiniTimeSlot(time);
        });
        slotWrap.appendChild(button);
    });
}

function openMiniDatePicker() {
    const picker = document.getElementById('miniDatePicker');
    if (!picker) {
        return;
    }
    picker.classList.add('active');
    picker.setAttribute('aria-hidden', 'false');
    renderMiniDatePicker();
}

function closeMiniDatePicker() {
    const picker = document.getElementById('miniDatePicker');
    if (!picker) {
        return;
    }
    picker.classList.remove('active');
    picker.setAttribute('aria-hidden', 'true');
}

function toggleMiniDatePicker() {
    const picker = document.getElementById('miniDatePicker');
    if (!picker) {
        return;
    }
    if (picker.classList.contains('active')) {
        closeMiniDatePicker();
    } else {
        openMiniDatePicker();
    }
}

function renderMiniDatePicker() {
    const title = document.getElementById('miniDatePickerTitle');
    const grid = document.getElementById('miniDateGrid');
    const dateInput = document.getElementById('deliveryDateInput');
    if (!title || !grid || !dateInput) {
        return;
    }

    const selectedDate = dateInput.value ? new Date(dateInput.value + 'T00:00:00') : null;
    const minDate = new Date(dateInput.min + 'T00:00:00');
    const maxDate = new Date(dateInput.max + 'T00:00:00');

    if (!miniPickerMonth) {
        miniPickerMonth = selectedDate ? new Date(selectedDate) : new Date(minDate);
    }

    const viewDate = new Date(miniPickerMonth.getFullYear(), miniPickerMonth.getMonth(), 1);
    title.textContent = viewDate.toLocaleDateString('en-US', { month: 'long', year: 'numeric' });

    const firstDayIndex = viewDate.getDay();
    const gridStart = new Date(viewDate);
    gridStart.setDate(viewDate.getDate() - firstDayIndex);

    grid.innerHTML = '';

    for (let i = 0; i < 42; i++) {
        const cellDate = new Date(gridStart);
        cellDate.setDate(gridStart.getDate() + i);
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'mini-date-cell';
        button.textContent = cellDate.getDate();

        const dateKey = formatDateKey(cellDate);
        const isOutside = cellDate.getMonth() !== viewDate.getMonth();
        let isDisabled = cellDate < minDate || cellDate > maxDate;
        if (storeHolidays && storeHolidays.includes(dateKey)) {
            isDisabled = true;
        }
        const isSelected = selectedDate && formatDateKey(selectedDate) === dateKey;

        if (isOutside) {
            button.classList.add('is-outside');
        }
        if (isDisabled) {
            button.classList.add('is-disabled');
            button.disabled = true;
            if (storeHolidays && storeHolidays.includes(dateKey)) {
                button.title = "Caterer Holiday";
                button.style.color = "#8e44ad";
                button.style.backgroundColor = "rgba(142, 68, 173, 0.1)";
            }
        } else {
            button.addEventListener('click', function() {
                handleDateSelection(dateKey);
                closeMiniDatePicker();
            });
        }
        if (isSelected) {
            button.classList.add('is-selected');
        }

        grid.appendChild(button);
    }
}

function changeMiniPickerMonth(offset) {
    if (!miniPickerMonth) {
        miniPickerMonth = new Date();
    }
    miniPickerMonth = new Date(miniPickerMonth.getFullYear(), miniPickerMonth.getMonth() + offset, 1);

    const minDate = new Date(document.getElementById('deliveryDateInput').min + 'T00:00:00');
    const maxDate = new Date(document.getElementById('deliveryDateInput').max + 'T00:00:00');
    const minMonth = new Date(minDate.getFullYear(), minDate.getMonth(), 1);
    const maxMonth = new Date(maxDate.getFullYear(), maxDate.getMonth(), 1);

    if (miniPickerMonth < minMonth) {
        miniPickerMonth = minMonth;
    }
    if (miniPickerMonth > maxMonth) {
        miniPickerMonth = maxMonth;
    }

    renderMiniDatePicker();
}

function clearMiniDateSelection() {
    const dateInput = document.getElementById('deliveryDateInput');
    if (!dateInput) {
        return;
    }
    dateInput.value = '';
    syncMiniDateTrigger();
    closeMiniDatePicker();
}

function jumpMiniPickerToToday() {
    const dateInput = document.getElementById('deliveryDateInput');
    if (!dateInput) {
        return;
    }
    const today = new Date();
    const todayKey = formatDateKey(today);
    if (todayKey < dateInput.min || todayKey > dateInput.max) {
        miniPickerMonth = new Date(dateInput.min + 'T00:00:00');
        renderMiniDatePicker();
        return;
    }
    miniPickerMonth = new Date(today.getFullYear(), today.getMonth(), 1);
    renderMiniDatePicker();
}

function applyAvailabilityDayColors() {
    if (!availabilityCalendar) {
        return;
    }

    const calendarEl = document.getElementById('bookingAvailabilityCalendar');
    if (!calendarEl) {
        return;
    }

    const selectedDate = document.getElementById('deliveryDateInput')?.value || '';
    const cells = calendarEl.querySelectorAll('.fc-daygrid-day[data-date]');
    cells.forEach((cell) => {
        cell.classList.remove(
            'availability-day-empty',
            'availability-day-booked',
            'availability-day-almost-full',
            'availability-day-fully-booked',
            'availability-day-selected'
        );

        const oldBadge = cell.querySelector('.availability-count-badge');
        if (oldBadge) {
            oldBadge.remove();
        }

        const dateKey = cell.getAttribute('data-date');
        const entry = getAvailabilityEntry(dateKey);

        if (storeHolidays && storeHolidays.includes(dateKey)) {
            cell.classList.add('availability-day-fully-booked');
        } else if (entry.status === 'fully_booked') {
            cell.classList.add('availability-day-fully-booked');
        } else if (entry.status === 'almost_full') {
            cell.classList.add('availability-day-almost-full');
        } else if ((entry.active_orders || 0) > 0) {
            cell.classList.add('availability-day-booked');
        } else {
            cell.classList.add('availability-day-empty');
        }

        if (selectedDate === dateKey) {
            cell.classList.add('availability-day-selected');
        }

        const dayFrame = cell.querySelector('.fc-daygrid-day-frame');
        if (dayFrame) {
            const badge = document.createElement('div');
            badge.className = 'availability-count-badge';
            badge.textContent = `${entry.active_orders}/${entry.max_orders_per_day}`;

            if (entry.status === 'fully_booked') {
                badge.classList.add('badge-fully-booked');
            } else if (entry.status === 'almost_full') {
                badge.classList.add('badge-almost-full');
            } else if ((entry.active_orders || 0) === 0) {
                badge.classList.add('badge-empty');
            }

            dayFrame.appendChild(badge);
        }
    });
}

function getAvailabilityMessage(entry, dateStr) {
    if (!entry) {
        return {
            className: 'is-available',
            html: `Selected date: <strong>${dateStr}</strong><br>Available slots: <strong>${bookingCapacityPerDay}</strong> of <strong>${bookingCapacityPerDay}</strong>.`
        };
    }

    if (entry.status === 'fully_booked') {
        return {
            className: 'is-fully-booked',
            html: `Selected date: <strong>${dateStr}</strong><br><strong style="color:#f5b7b1;">Fully booked</strong>. This date already has <strong>${entry.active_orders}/${entry.max_orders_per_day}</strong> bookings.`
        };
    }

    if (entry.status === 'almost_full') {
        return {
            className: 'is-almost-full',
            html: `Selected date: <strong>${dateStr}</strong><br><strong style="color:#f8c471;">Almost full</strong>. Only <strong>${entry.slots_remaining}</strong> slot(s) left out of <strong>${entry.max_orders_per_day}</strong>.`
        };
    }

    return {
        className: 'is-available',
        html: `Selected date: <strong>${dateStr}</strong><br>Available slots: <strong>${entry.slots_remaining}</strong> of <strong>${entry.max_orders_per_day}</strong>. Current bookings: <strong>${entry.active_orders}</strong>.`
    };
}

function updateAvailabilityStatus(dateStr) {
    const statusBox = document.getElementById('availabilityStatusBox');
    if (!statusBox) {
        return true;
    }

    if (storeHolidays && storeHolidays.includes(dateStr)) {
        statusBox.className = 'availability-status-box is-fully-booked';
        statusBox.innerHTML = `Selected date: <strong>${dateStr}</strong><br>The caterer is on a <strong>Holiday</strong> on this date.`;
        return false;
    }

    const entry = availabilityCache[dateStr] || null;
    const state = getAvailabilityMessage(entry, dateStr);
    statusBox.className = 'availability-status-box ' + state.className;
    statusBox.innerHTML = state.html;
    return !entry || entry.status !== 'fully_booked';
}

async function loadAvailabilityRange(startStr, endStr) {
    const response = await fetch(`${storeAvailabilityEndpoint}&start=${encodeURIComponent(startStr)}&end=${encodeURIComponent(endStr)}`);
    const payload = await response.json();
    if (!payload.success) {
        throw new Error(payload.error || 'Unable to load caterer availability.');
    }

    availabilityCache = payload.dates || {};
    if (payload.holidays) {
        storeHolidays = payload.holidays;
    }
    return availabilityCache;
}

function syncCalendarSelection(dateStr) {
    if (!availabilityCalendar) {
        return;
    }
    availabilityCalendar.unselect();
    const existing = availabilityCalendar.getEventById('selected-booking-date');
    if (existing) {
        existing.remove();
    }
    availabilityCalendar.addEvent({
        id: 'selected-booking-date',
        start: dateStr,
        allDay: true,
        display: 'inverse-background',
        backgroundColor: 'rgba(212, 175, 55, 0.2)',
        borderColor: '#d4af37'
    });
}

function openAvailabilityModal() {
    const modal = document.getElementById('availabilityModal');
    if (!modal) {
        return;
    }
    modal.classList.add('active');
    modal.setAttribute('aria-hidden', 'false');
    if (availabilityCalendar && !availabilityCalendarLoaded) {
        availabilityCalendar.render();
        availabilityCalendarLoaded = true;
    } else if (availabilityCalendar) {
        availabilityCalendar.updateSize();
    }
}

function closeAvailabilityModal() {
    const modal = document.getElementById('availabilityModal');
    if (!modal) {
        return;
    }
    modal.classList.remove('active');
    modal.setAttribute('aria-hidden', 'true');
}

function handleDateSelection(dateStr) {
    const dateInput = document.getElementById('deliveryDateInput');
    const isAllowed = updateAvailabilityStatus(dateStr);
    const selectedYear = new Date(dateStr + 'T00:00:00').getFullYear();
    if (selectedYear !== currentBookingYear) {
        const statusBox = document.getElementById('availabilityStatusBox');
        if (statusBox) {
            statusBox.className = 'availability-status-box is-fully-booked';
            statusBox.innerHTML = `Selected date: <strong>${dateStr}</strong><br>Only booking dates within <strong>${currentBookingYear}</strong> are allowed.`;
        }
        if (dateInput) {
            dateInput.value = '';
        }
        return false;
    }
    if (!isAllowed) {
        if (dateInput) {
            dateInput.value = '';
        }
        return false;
    }

    if (dateInput) {
        dateInput.value = dateStr;
    }
    miniPickerMonth = new Date(dateStr + 'T00:00:00');
    syncMiniDateTrigger();
    renderMiniDatePicker();
    syncCalendarSelection(dateStr);
    applyAvailabilityDayColors();
    return true;
}

function initializeAvailabilityCalendar() {
    const calendarEl = document.getElementById('bookingAvailabilityCalendar');
    const dateInput = document.getElementById('deliveryDateInput');
    const timeInput = document.getElementById('deliveryTimeInput');

    if (!calendarEl || !dateInput || !timeInput || typeof FullCalendar === 'undefined') {
        return;
    }

    availabilityCalendar = new FullCalendar.Calendar(calendarEl, {
        initialView: 'dayGridMonth',
        initialDate: currentYearStart,
        height: 'auto',
        headerToolbar: {
            left: 'prev,next today',
            center: 'title',
            right: ''
        },
        validRange: {
            start: currentYearStart,
            end: new Date(new Date(currentYearEnd + 'T00:00:00').getTime() + 86400000).toISOString().slice(0, 10)
        },
        events: [],
        dateClick: function(info) {
            handleDateSelection(info.dateStr);
        },
        datesSet: async function(info) {
            try {
                currentCalendarRange = {
                    start: info.startStr,
                    end: info.endStr
                };
                await loadAvailabilityRange(info.startStr, info.endStr);
                applyAvailabilityDayColors();
                renderMiniDatePicker();
                if (dateInput.value) {
                    syncCalendarSelection(dateInput.value);
                    updateAvailabilityStatus(dateInput.value);
                    applyAvailabilityDayColors();
                }
            } catch (error) {
                const statusBox = document.getElementById('availabilityStatusBox');
                if (statusBox) {
                    statusBox.className = 'availability-status-box is-fully-booked';
                    statusBox.textContent = error.message;
                }
            }
        }
    });

    dateInput.addEventListener('change', function() {
        if (!this.value) {
            syncMiniDateTrigger();
            return;
        }
        handleDateSelection(this.value);
    });

    syncMiniDateTrigger();
    miniPickerMonth = dateInput.value ? new Date(dateInput.value + 'T00:00:00') : new Date(dateInput.min + 'T00:00:00');
    syncMiniTimeTrigger();

    timeInput.addEventListener('change', function() {
        syncMiniTimeTrigger();
    });

    document.addEventListener('click', function(e) {
        const shell = document.querySelector('.date-picker-shell');
        if (!shell) {
            return;
        }
        if (!shell.contains(e.target)) {
            closeMiniDatePicker();
        }
    });

    document.addEventListener('click', function(e) {
        const shell = document.querySelector('.time-picker-shell');
        if (!shell) {
            return;
        }
        if (!shell.contains(e.target)) {
            closeMiniTimePicker();
        }
    });
}

function selectPackageCard(setName, setId) {
    const packageSelect = document.getElementById('packageSelect');
    if (packageSelect && setName) {
        packageSelect.value = setName;
        loadSet(setName);
    }
    document.querySelectorAll('.pro-pkg-card').forEach(card => {
        card.classList.remove('selected');
        const btn = card.querySelector('.btn-outline');
        if (btn) btn.textContent = 'Select Package';
    });
    const activeCard = document.getElementById('pkg_card_' + setId);
    if (activeCard) {
        activeCard.classList.add('selected');
        const btn = activeCard.querySelector('.btn-outline');
        if (btn) btn.textContent = 'Selected Package';
    }
    const banner = document.getElementById('selectedSetBanner');
    const bannerName = document.getElementById('selectedSetBannerName');
    if (banner) banner.style.display = 'block';
    if (bannerName) bannerName.textContent = setName;
}

function selectPaymentCard(method) {
    const paymentSelect = document.getElementById('paymentMethod');
    if (paymentSelect) {
        paymentSelect.value = method;
        toggleGCash();
    }
    const gcashCard = document.getElementById('pay_card_gcash');
    const cashCard = document.getElementById('pay_card_cash');
    if (gcashCard && cashCard) {
        if (method === 'GCash') {
            gcashCard.classList.add('selected');
            cashCard.classList.remove('selected');
        } else {
            gcashCard.classList.remove('selected');
            cashCard.classList.add('selected');
        }
    }
}

function addDietaryChip(chipText) {
    const textarea = document.getElementById('dietaryNotes');
    if (!textarea) return;
    const current = textarea.value.trim();
    if (current.toLowerCase().includes(chipText.toLowerCase())) {
        return;
    }
    textarea.value = current ? current + ', ' + chipText : chipText;
}

function selectPackageFromCard(setName) {
    const packageSelect = document.getElementById('packageSelect');
    if (packageSelect && setName) {
        packageSelect.value = setName;
        loadSet(setName);
    }
    goToBookingStep(1, true);
    const form = document.getElementById('bookingForm');
    if (form) {
        form.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
}

function openBookingModal(setName) {
    selectPackageFromCard(setName);
}

function closeBookingModal() {
    window.location.href = 'browse-caterer.php?store_id=<?php echo (int) $store_id; ?>';
}

function setBookingStep(step) {
    document.querySelectorAll('.booking-step').forEach(function(el) {
        el.classList.toggle('active', el.dataset.step === String(step));
    });

    document.querySelectorAll('[data-step-dot]').forEach(function(dot) {
        const dotStep = parseInt(dot.dataset.stepDot, 10);
        dot.classList.toggle('active', dotStep === step);
        dot.classList.toggle('complete', dotStep < step);
    });
    sessionStorage.setItem('booking_step_<?php echo $product_id; ?>', step);
    notifyBookingHost();
}

function getActiveBookingStep() {
    const currentStep = document.querySelector('.booking-step.active');
    return currentStep ? parseInt(currentStep.dataset.step, 10) : 1;
}

function notifyBookingHost() {
    if (!window.parent || window.parent === window) {
        return;
    }
    const step = getActiveBookingStep();
    window.parent.postMessage({
        source: 'booking-wizard',
        type: 'state',
        step: step,
        primaryLabel: step >= 4 ? 'Review Booking' : 'Continue'
    }, '*');
}

function handleBookingHostCommand(type) {
    const step = getActiveBookingStep();
    if (type === 'back') {
        const minStep = hasPreselectedSet ? 2 : 1;
        if (step <= minStep) {
            if (window.parent && window.parent !== window) {
                window.parent.postMessage({ source: 'booking-wizard', type: 'close' }, '*');
            } else {
                closeBookingModal();
            }
            return;
        }
        goToBookingStep(step - 1, true);
        return;
    }

    if (type === 'next') {
        if (step >= 4) {
            const form = document.getElementById('bookingForm');
            if (form) {
                form.requestSubmit();
            }
            return;
        }
        goToBookingStep(step + 1);
    }
}

window.addEventListener('message', function(event) {
    const data = event.data || {};
    if (data.source !== 'booking-host') {
        return;
    }
    handleBookingHostCommand(data.type);
});

function validateBookingStep(step) {
    if (step === 1) {
        const packageSelect = document.getElementById('packageSelect');
        if (packageSelect && !packageSelect.value) {
            alert('Please select a package first.');
            return false;
        }
        return true;
    }

    if (step === 2) {
        return true;
    }

    if (step === 3) {
        const dateInput = document.getElementById('deliveryDateInput');
        const timeInput = document.getElementById('deliveryTimeInput');
        const quantityInput = document.getElementById('quantity');

        if (!dateInput.value) {
            alert('Please select your event date.');
            return false;
        }
        if (!timeInput.value) {
            alert('Please select your event time.');
            return false;
        }
        if (!/^(?:[1-9][0-9]{0,4}|100000)$/.test(quantityInput.value) || Number(quantityInput.value) < parseInt(quantityInput.dataset.minimum || '1', 10) || Number(quantityInput.value) > <?php echo $maximum_guests; ?>) {
            alert('Please enter a whole number of guests between ' + (quantityInput.dataset.minimum || '1') + ' and <?php echo number_format($maximum_guests); ?>.');
            return false;
        }
        const contactName = document.getElementById('venueContactName').value.trim();
        const contactPhone = document.getElementById('venueContactPhone').value.trim();
        if (contactName && !/^[\p{L} ]{1,250}$/u.test(contactName)) {
            alert('On-site contact name must contain only letters and spaces, up to 250 characters.');
            return false;
        }
        if (contactPhone && !/^[0-9]{1,11}$/.test(contactPhone)) {
            alert('On-site contact phone must contain only numbers, up to 11 digits.');
            return false;
        }
        if (!updateAvailabilityStatus(dateInput.value)) {
            alert('This caterer is already fully booked on the selected date. Please choose another date.');
            return false;
        }
        const addressInput = document.querySelector('input[name="delivery_address"]');
        if (!addressInput || !addressInput.value.trim()) {
            alert('Please enter the venue address.');
            return false;
        }
        return true;
    }

    return true;
}

function goToBookingStep(step, skipValidation) {
    const currentStep = document.querySelector('.booking-step.active');
    const currentStepNumber = currentStep ? parseInt(currentStep.dataset.step, 10) : 1;

    if (!skipValidation && step > currentStepNumber && !validateBookingStep(currentStepNumber)) {
        return;
    }

    setBookingStep(step);
    if (step === 4) {
        calculateTotal();
    }
    // Each navigation starts at the beginning of the newly visible step.
    requestAnimationFrame(function() {
        const activeStep = document.querySelector('.booking-step.active');
        if (!activeStep) return;
        activeStep.querySelectorAll('.booking-step-body').forEach(function(body) {
            body.scrollTo({ top: 0, left: 0, behavior: 'instant' });
        });
        for (let parent = activeStep.parentElement; parent; parent = parent.parentElement) {
            if (parent.scrollHeight > parent.clientHeight) {
                parent.scrollTo({ top: 0, left: 0, behavior: 'instant' });
            }
        }
        window.scrollTo({ top: 0, left: 0, behavior: 'instant' });
    });
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text == null ? '' : String(text);
    return div.innerHTML;
}

function getLegacyCategoryLabelJS(key) {
    const labels = {
        'appetizers': 'Appetizers',
        'soup': 'Soup',
        'rice': 'Rice',
        'main_dishes': 'Main Dishes',
        'pasta_noodles': 'Pasta/Noodles',
        'desserts': 'Desserts',
        'drinks': 'Drinks',
    };
    return labels[key] || key;
}

function parseSetMenuCategories(description) {
    const normalized = {};

    if (description) {
        try {
            const parsed = JSON.parse(description);
            if (parsed && parsed.categories) {
                Object.keys(parsed.categories).forEach(key => {
                    const label = getLegacyCategoryLabelJS(key);
                    const value = parsed.categories[key] || [];
                    normalized[label] = Array.isArray(value)
                        ? value.filter(Boolean)
                        : String(value).split(/\r?\n|,/).map(item => item.trim()).filter(Boolean);
                });
                return normalized;
            }
        } catch (e) {}

        normalized['Main Dishes'] = String(description)
            .split(/\r?\n|,|•/)
            .map(item => item.replace(/^[\d\.\-\•\s]+/, '').trim())
            .filter(item => item.length > 1 && !item.toLowerCase().includes('php') && !item.includes('₱'));
    }

    return normalized;
}

function flattenMenuCategories(categories) {
    return Object.values(categories).flat().filter(Boolean);
}

function renderCategorizedMenu(categories) {
    // Build a lookup: label -> key (for itemId generation)
    const labelToKey = {};
    Object.entries(menuCategoryLabels).forEach(([key, label]) => { labelToKey[label] = key; });

    // Iterate the actual categories object so all labels (legacy or structured) are shown
    const blocks = Object.entries(categories).map(([catLabel, items]) => {
        if (!Array.isArray(items) || !items.length) {
            return '';
        }
        const key = labelToKey[catLabel] || catLabel.toLowerCase().replace(/\s+/g, '_');

        return `
            <div class="menu-category-block">
                <h5>${escapeHtml(catLabel)}</h5>
                ${items.map((item, index) => {
                    const itemId = `${key}_${index}`;
                    const isSwapped = activeSwaps.some(s => {
                        const sOrig = (s.original || '').toLowerCase().trim();
                        const itm = item.toLowerCase().trim();
                        const sOrigBase = sOrig.replace(/\(.*?\)/g, '').trim();
                        const itmBase = itm.replace(/\(.*?\)/g, '').trim();
                        return itm === sOrig || (sOrigBase && itmBase && (itmBase === sOrigBase || itmBase.includes(sOrigBase) || sOrigBase.includes(itmBase)));
                    });
                    const itemClass = isSwapped ? 'menu-item swapped removed' : 'menu-item included';
                    const icon = isSwapped ? 'fa-times-circle' : 'fa-check-circle';
                    const safeItem = escapeHtml(item);
                    return `
                        <div id="menuItem_${itemId}" class="${itemClass}" data-original="${safeItem}">
                            <i class="fas ${icon}"></i>
                            <span class="item-text">${safeItem}</span>
                        </div>
                    `;
                }).join('')}
            </div>
        `;
    }).join('');

    return blocks || '<p style="color: var(--text-muted); font-size: 14px;">No specific menu items listed for this set.</p>';
}

function toggleBuildMenuItem(checkbox) {
    const item = checkbox.value;
    if (checkbox.checked) {
        if (!activeBuildMenuItems.includes(item)) {
            activeBuildMenuItems.push(item);
        }
    } else {
        activeBuildMenuItems = activeBuildMenuItems.filter(existing => existing !== item);
    }
    document.querySelectorAll('.build-menu-checkbox').forEach(otherCheckbox => {
        if (otherCheckbox.value === item) {
            otherCheckbox.checked = checkbox.checked;
        }
    });
    updateHiddenFields();
}

function renderOrderSidebarMenu(categories) {
    const blocks = Object.entries(categories).map(([catLabel, items]) => {
        if (!Array.isArray(items) || !items.length) {
            return '';
        }

        return `
            <div class="order-menu-category">
                <div class="order-menu-category-label">${escapeHtml(catLabel)}</div>
                <ul class="order-menu-items">
                    ${items.map(item => `<li><i class="fas fa-check"></i> ${escapeHtml(item)}</li>`).join('')}
                </ul>
            </div>
        `;
    }).filter(Boolean);

    return blocks.length
        ? blocks.join('')
        : '<p class="order-menu-empty">No menu items listed for this package.</p>';
}

function updateOrderSidebar(selectedSet, setName) {
    const sidebarMenu = document.getElementById('orderSidebarMenu');
    const sidebarSetName = document.getElementById('orderSidebarSetName');
    const sidebarSetPrice = document.getElementById('orderSidebarSetPrice');
    const sidebarSetMeta = document.getElementById('orderSidebarSetMeta');

    if (!selectedSet || !sidebarMenu) {
        return;
    }

    const menuCategories = parseSetMenuCategories(selectedSet.description || '');
    sidebarMenu.innerHTML = renderOrderSidebarMenu(menuCategories);

    if (sidebarSetName) {
        sidebarSetName.textContent = setName;
    }

    const setPrice = parseFloat(selectedSet.price || 0);
    const setPax = parseInt(selectedSet.pax || 0, 10);
    const setPerHead = parseFloat(selectedSet.per_head_price || 0);
    const setExtra = parseFloat(selectedSet.extra_guest_rate || 0);

    if (sidebarSetPrice) {
        sidebarSetPrice.textContent = setPerHead > 0
            ? '\u20b1' + setPerHead.toFixed(2) + ' per guest'
            : '\u20b1' + setPrice.toFixed(2) + (setPax > 0 ? ' \u00b7 Good for ' + setPax + ' guests' : '');
    }

    if (sidebarSetMeta) {
        sidebarSetMeta.textContent = (setPerHead <= 0 && setExtra > 0)
            ? '+\u20b1' + setExtra.toFixed(2) + ' per extra guest'
            : '';
    }
}

// Handle package card selection
function selectPackageCard(card, setName) {
    document.querySelectorAll('.pkg-card').forEach(c => c.classList.remove('pkg-card-selected'));
    card.classList.add('pkg-card-selected');
    
    // Update the hidden select field
    const select = document.getElementById('packageSelect');
    if (select) {
        select.value = setName;
        loadSet(setName);
    }
}

// Modal logic for View Menu
function openMenuModal(setName) {
    const selectedSet = setsData.find(s => s.set_name === setName || s.name === setName);
    if (!selectedSet) return;
    
    document.getElementById('menuPreviewTitle').textContent = setName;
    
    const menuCategories = parseSetMenuCategories(selectedSet.description || '');
    const content = document.getElementById('menuPreviewContent');
    
    let html = '';
    const entries = Object.entries(menuCategories);
    if (entries.length === 0) {
        html = '<div class="menu-preview-empty">No menu items listed.</div>';
    } else {
        entries.forEach(([catLabel, items]) => {
            if (Array.isArray(items) && items.length > 0) {
                html += `
                    <div class="menu-preview-category">
                        <div class="menu-preview-category-label">${escapeHtml(catLabel)}</div>
                        <div class="menu-preview-items">
                            ${items.map(item => `
                                <div class="menu-preview-item">
                                    <i class="fas fa-check"></i>
                                    <span>${escapeHtml(item)}</span>
                                </div>
                            `).join('')}
                        </div>
                    </div>
                `;
            }
        });
    }
    
    content.innerHTML = html || '<div class="menu-preview-empty">No menu items listed.</div>';
    document.getElementById('menuPreviewModal').classList.add('open');
    document.body.style.overflow = 'hidden';
}

function closeMenuModal() {
    document.getElementById('menuPreviewModal').classList.remove('open');
    document.body.style.overflow = '';
}

// Load set data when package is selected
function loadSet(setName) {
    const container = document.getElementById('menuCustomization');
    updatePackageSummary();
    const selectedSetBanner = document.getElementById('selectedSetBanner');
    
    if (!setName) {
        container.innerHTML = '';
        if (selectedSetBanner) {
            selectedSetBanner.textContent = 'Selected set will appear here.';
        }
        calculateTotal();
        return;
    }
    
    // Find selected set
    const selectedSet = setsData.find(s => 
        (s.set_name === setName || s.name === setName)
    );
    
    if (!selectedSet) {
        container.innerHTML = '<p style="color: #f44336;">Error loading set data.</p>';
        return;
    }

    if (selectedSetBanner) {
        const setPrice = parseFloat(selectedSet.price || 0);
        const setPax = parseInt(selectedSet.pax || 0, 10);
        const setPerHead = parseFloat(selectedSet.per_head_price || 0);
        const setExtra = parseFloat(selectedSet.extra_guest_rate || 0);
        selectedSetBanner.textContent = setName + ' - ' + (setPerHead > 0
            ? '\u20b1' + setPerHead.toFixed(2) + ' per guest'
            : '\u20b1' + setPrice.toFixed(2) + (setPax > 0 ? ' / Good for ' + setPax + ' guests' : ''));
        if (setPerHead <= 0 && setExtra > 0) {
            selectedSetBanner.textContent += ' · Extra guest \u20b1' + setExtra.toFixed(2);
        }
    }

    // Safely initialize arrays if undefined
    selectedSet.addons = selectedSet.addons || [];
    selectedSet.swaps = selectedSet.swaps || [];
    
    const menuCategories = parseSetMenuCategories(selectedSet.description || '');
    const includedItems = flattenMenuCategories(menuCategories);
    
    // Normalize addons and swaps to ensure they are arrays (handles cases where JSON might be an object)
    if (selectedSet.addons && typeof selectedSet.addons === 'object' && !Array.isArray(selectedSet.addons)) {
        selectedSet.addons = Object.values(selectedSet.addons);
    }
    if (selectedSet.swaps && typeof selectedSet.swaps === 'object' && !Array.isArray(selectedSet.swaps)) {
        selectedSet.swaps = Object.values(selectedSet.swaps);
    }
    
    // Caterer current swaps for easier access
    currentSetSwaps = selectedSet.swaps || [];
    
    // Reset or load active selections
    if (window.isInitialPageLoad) {
        try {
            activeAddOns = JSON.parse(sessionStorage.getItem('booking_addons_<?php echo $product_id; ?>') || '[]');
            activeSwaps = JSON.parse(sessionStorage.getItem('booking_swaps_<?php echo $product_id; ?>') || '[]');
            activeBuildMenuItems = JSON.parse(sessionStorage.getItem('booking_build_menu_<?php echo $product_id; ?>') || '[]');
            customAddOnRequest = sessionStorage.getItem('booking_custom_addon_<?php echo $product_id; ?>') || '';
        } catch(e) {
            activeAddOns = []; activeSwaps = []; activeBuildMenuItems = []; customAddOnRequest = '';
        }
    } else {
        activeAddOns = [];
        activeSwaps = [];
        activeBuildMenuItems = [];
        customAddOnRequest = '';
    }

    // Update Quantity/Pax based on Set configuration
    const qtyInput = document.getElementById('quantity');
    const qtyGroup = document.getElementById('quantityGroup');
    
    // Always ensure the group is visible
    if (qtyGroup) {
        qtyGroup.style.display = 'block';
    }

    if (selectedSet.pax && parseInt(selectedSet.pax) > 0) {
        qtyInput.dataset.minimum = Math.max(<?php echo $minimum_guests; ?>, Number(selectedSet.pax));
        qtyInput.value = qtyInput.dataset.minimum;
    } else {
        qtyInput.dataset.minimum = <?php echo $minimum_guests; ?>;
    }
    
    // Render the menu customization
    renderMenuCustomization(selectedSet, includedItems, menuCategories);
    updateOrderSidebar(selectedSet, setName);
    calculateTotal();
}

function simplifyPackageOptions() {
    const packageSelect = document.getElementById('packageSelect');
    if (!packageSelect) {
        return;
    }

    Array.from(packageSelect.options).forEach((option, index) => {
        if (index === 0) {
            return;
        }

        const fullText = option.dataset.summaryText || option.textContent.trim();
        if (!option.dataset.summaryText) {
            option.dataset.summaryText = fullText;
        }

        if (!option.dataset.summary) {
            const compactSummary = fullText.includes(' - ')
                ? fullText.split(' - ').slice(1).join(' - ')
                : fullText;
            option.dataset.summary = compactSummary;
        }

        option.textContent = option.value || fullText;
    });
}

function updatePackageSummary() {
    // Package details are already shown on the selected package card.
}

// Render menu customization with Tabs
function renderMenuCustomization(set, includedItems, menuCategories) {
    console.log('Rendering customization for set:', set);
    const container = document.getElementById('menuCustomization');
    
    // Tab Headers
    let html = `
    <div class="tabs-container">
        <div class="tabs-header">
            <button type="button" class="tab-btn active" onclick="openTab('tab-menu')">Included Menu</button>
            <button type="button" class="tab-btn" onclick="openTab('tab-addons')">Add-ons</button>
            <button type="button" class="tab-btn" onclick="openTab('tab-swaps')">Swap Options</button>
        </div>

        <!-- Tab 1: Set Menu -->
        <div id="tab-menu" class="tab-content active">
            <div id="menuItems" style="margin-bottom: 15px;">
    `;

    html += renderCategorizedMenu(menuCategories || {});
    html += `   </div>
        </div>`;

    // Tab 2: Add-ons
    html += `<div id="tab-addons" class="tab-content">`;
    if (set.addons && set.addons.length > 0) {
        set.addons.forEach((addon, index) => {
            const addonId = 'addon_' + index;
            // Check existing state in activeAddOns
            const existingAddon = activeAddOns.find(a => a.name === addon.name);
            const isChecked = !!existingAddon;
            const currentQty = existingAddon ? existingAddon.qty : 1; // Default to 1 for display
            const checkedAttr = isChecked ? 'checked' : '';
            const safeName = addon.name.replace(/'/g, "\\'");
            
            // UI: Row with Checkbox -> Hidden Quantity Input
            html += `
                <div id="addon_row_${index}" class="swap-row ${isChecked ? 'active' : ''}">
                    <div class="swap-left">
                        <input type="checkbox" id="${addonId}" 
                               class="swap-checkbox" 
                               ${checkedAttr}
                               onchange="toggleAddOnRow(${index}, '${safeName}', parseFloat(String('${addon.price}').replace(/,/g, '')) || 0)">
                        <div class="swap-info">
                            <span class="swap-name">${addon.name}</span>
                            <span class="swap-price">+₱${(parseFloat(String(addon.price).replace(/,/g, '')) || 0).toFixed(2)}</span>
                        </div>
                    </div>
                    
                    <div id="addon_qty_container_${index}" class="swap-dropdown-container ${isChecked ? 'visible' : ''}" style="width: 150px;">
                         <div style="display: flex; align-items: center; gap: 10px;">
                            <label style="color: var(--text-muted); font-size: 12px; white-space: nowrap;">Qty:</label>
                            <input type="number" id="addon_qty_${index}" 
                                   class="form-control" 
                                   style="padding: 8px; height: 36px; text-align: center;"
                                   min="1" 
                                   value="${currentQty}"
                                   onchange="updateAddOnQty(${index}, '${safeName}', parseFloat(String('${addon.price}').replace(/,/g, '')) || 0, this.value)"
                                   onkeyup="updateAddOnQty(${index}, '${safeName}', parseFloat(String('${addon.price}').replace(/,/g, '')) || 0, this.value)">
                         </div>
                    </div>
                </div>
            `;
        });
    } else {
        html += '<p style="color: var(--text-muted);">No add-ons available for this set.</p>';
    }

    // Custom Add-on Input
    html += `
        <div style="margin-top: 20px; padding-top: 15px; border-top: 1px dashed rgba(255,255,255,0.1);">
            <label style="display: block; color: var(--accent-gold); font-size: 14px; margin-bottom: 8px;">
                <i class="fas fa-edit"></i> Custom Add-on Request (Optional)
            </label>
            <input type="text" id="customAddOnInput" class="form-control" 
                   placeholder="Enter custom add-on item (e.g., Lechon Belly, Extra Tables)"
                   onchange="updateCustomAddOn(this.value)">
        </div>
    `;

    html += `</div>`;

    // Tab 3: Swaps (Custom Swap with Dropdown from Booked Package)
    html += `<div id="tab-swaps" class="tab-content">`;
    const swapsArray = (set.swaps && Array.isArray(set.swaps)) ? set.swaps : (set.swaps ? Object.values(set.swaps) : []);
    if (swapsArray.length > 0) {
        // Group swaps by category
        const categorizedSwaps = {};
        swapsArray.forEach((swap, index) => {
            const cat = (typeof swap !== 'string' && swap.category) ? swap.category : 'Other Swaps';
            if (!categorizedSwaps[cat]) categorizedSwaps[cat] = [];
            categorizedSwaps[cat].push({ swap, index });
        });

        Object.entries(categorizedSwaps).forEach(([category, items]) => {
            html += `<h4 style="margin: 15px 0 10px; padding-bottom: 5px; border-bottom: 1px solid var(--border-color); color: var(--accent-gold); font-size: 16px;">${escapeHtml(category)}</h4>`;
            
            items.forEach(({swap, index}) => {
                let swapName = '';
                let price = 0;

                if (typeof swap === 'string') {
                    swapName = swap;
                    price = 0;
                } else {
                    swapName = swap.name || swap.replacement || swap.original_item || swap.original || 'Swap Dish';
                    price = parseFloat(String(swap.price || '0').replace(/,/g, '')) || 0;
                }
                
                if (!swapName) return;
                
                const uniqueId = `swap_${index}`;
                const activeSwap = activeSwaps.find(s => s.id === uniqueId);
                const isActive = !!activeSwap;
                const currentSelectedTarget = activeSwap ? activeSwap.original : '';

                const safeSwapName = escapeHtml(swapName);
                const priceDisplay = price > 0 
                    ? `<span class="swap-badge-price">+₱${price.toFixed(2)}</span>` 
                    : `<span class="swap-badge-free"><i class="fas fa-gift" style="font-size:10px;margin-right:3px;"></i>Free Swap</span>`;

                // Build card grid — only show items from the matching category
                // e.g. a "Beef" swap should only show Beef dishes to replace
                const swapCategoryKey = category; // category from the outer forEach
                const categoryItems = menuCategories && menuCategories[swapCategoryKey]
                    ? menuCategories[swapCategoryKey]
                    : null;
                // Use category-filtered items if available, otherwise fall back to all items
                const gridSourceItems = (categoryItems && categoryItems.length > 0) ? categoryItems : includedItems;
                const gridCards = gridSourceItems.length > 0
                    ? gridSourceItems.map(item => {
                        const isUsedByOther = activeSwaps.some(s => s.original === item && s.id !== uniqueId);
                        const isSelected = currentSelectedTarget === item;
                        const safeItem = escapeHtml(item);
                        const cardClass = isUsedByOther ? 'swap-menu-card disabled-card' : (isSelected ? 'swap-menu-card selected' : 'swap-menu-card');
                        const clickHandler = isUsedByOther ? '' : `onclick="selectSwapCard('${uniqueId}', ${index}, '${safeItem.replace(/'/g,"\\'")}', this)"`;
                        return `<div class="${cardClass}" data-item="${safeItem}" ${clickHandler}>${safeItem}</div>`;
                    }).join('')
                    : `<p style="color:var(--text-muted);font-size:13px;">No menu items available to replace in this category.</p>`;

                html += `
                    <div id="row_${uniqueId}" class="swap-row ${isActive ? 'active' : ''} ${isActive ? 'expanded' : ''}">
                        <div class="swap-header-bar" onclick="toggleSwapAccordion('${uniqueId}')">
                            <div class="swap-info">
                                <div class="swap-title-line">
                                    <span class="swap-name"><i class="fas fa-exchange-alt" style="color:#9b59b6;font-size:13px;margin-right:6px;"></i>Replace with: <strong>${safeSwapName}</strong></span>
                                    ${priceDisplay}
                                </div>
                                <div id="badge_${uniqueId}" class="swap-badge-container" style="${isActive ? '' : 'display:none;'}">
                                    ${isActive ? `<span class="swap-replaced-pill"><i class="fas fa-check-circle"></i> Replacing: <b>${escapeHtml(currentSelectedTarget)}</b></span>` : ''}
                                </div>
                            </div>
                            <div class="swap-header-action">
                                <span class="swap-toggle-hint"><i class="fas fa-chevron-down swap-chevron"></i></span>
                            </div>
                        </div>
                        
                        <div id="dropdown_${uniqueId}" class="swap-dropdown-container ${isActive ? 'visible' : ''}">
                            <div class="swap-menu-grid" id="grid_${uniqueId}">
                                ${gridCards}
                            </div>
                        </div>
                    </div>
                `;
            });
        });
    } else {
        html += '<p style="color: var(--text-muted); padding: 10px 0;">No swap options available for this package.</p>';
    }
    html += `</div>
    </div>`; // End tabs-container

    container.innerHTML = html;
    
    updateMenuVisuals();
}

// Tab Switcher
function openTab(tabName) {
    // Hide all tabs
    document.querySelectorAll('.tab-content').forEach(tab => tab.classList.remove('active'));
    document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
    
    // Show current tab
    const targetTab = document.getElementById(tabName);
    if (targetTab) targetTab.classList.add('active');
    
    // Activate button
    const btn = document.querySelector(`button[onclick="openTab('${tabName}')"]`);
    if (btn) btn.classList.add('active');
}

// Toggle swap accordion panel
function toggleSwapAccordion(uniqueId) {
    const dropdownContainer = document.getElementById(`dropdown_${uniqueId}`);
    const row = document.getElementById(`row_${uniqueId}`);
    if (dropdownContainer) {
        dropdownContainer.classList.toggle('visible');
    }
    if (row) {
        row.classList.toggle('expanded');
    }
}

// Select a menu card in the card-grid swap picker
function selectSwapCard(uniqueId, index, selectedItem, cardEl) {
    const swap = currentSetSwaps[index];
    if (!swap) return;

    const swapName = (typeof swap === 'string') ? swap : (swap.name || swap.replacement || swap.original_item || swap.original || 'Swap Dish');
    const price = parseFloat(String(swap.price || '0').replace(/,/g, '')) || 0;

    const grid = document.getElementById(`grid_${uniqueId}`);
    if (!grid) return;

    const wasSelected = cardEl.classList.contains('selected');

    // Deselect all cards in this grid first
    grid.querySelectorAll('.swap-menu-card.selected').forEach(c => c.classList.remove('selected'));

    const row = document.getElementById(`row_${uniqueId}`);
    const badgeEl = document.getElementById(`badge_${uniqueId}`);

    if (wasSelected) {
        // Toggle off — deselect this swap
        activeSwaps = activeSwaps.filter(s => s.id !== uniqueId);
        if (row) row.classList.remove('active');
        if (badgeEl) {
            badgeEl.innerHTML = '';
            badgeEl.style.display = 'none';
        }
    } else {
        // Check if another swap already uses this item
        const conflict = activeSwaps.find(s => s.original === selectedItem && s.id !== uniqueId);
        if (conflict) {
            alert(`"${selectedItem}" is already being replaced by another swap. Please select a different item.`);
            return;
        }
        cardEl.classList.add('selected');
        activeSwaps = activeSwaps.filter(s => s.id !== uniqueId);
        activeSwaps.push({ id: uniqueId, original: selectedItem, replacement: swapName, price: price });
        if (row) row.classList.add('active');
        if (badgeEl) {
            badgeEl.innerHTML = `<span class="swap-replaced-pill"><i class="fas fa-check-circle"></i> Replacing: <b>${escapeHtml(selectedItem)}</b></span>`;
            badgeEl.style.display = 'inline-block';
        }
    }

    refreshAllSwapDropdowns();
    updateHiddenFields();
    calculateTotal();
    updateMenuVisuals();
}

// Fallbacks for any legacy calls
function toggleSwapCheckbox(uniqueId, index) {
    toggleSwapAccordion(uniqueId);
}

function handleSwapCheckboxChange(event, uniqueId, index) {
    if (event && event.stopPropagation) event.stopPropagation();
    toggleSwapAccordion(uniqueId);
}

function applySwapCheckboxState(uniqueId, index, isChecked) {
    toggleSwapAccordion(uniqueId);
}

function handleSwapTargetSelect(uniqueId, index, selectedOriginalItem) {
    const swap = currentSetSwaps[index];
    if (!swap) return;

    const swapName = (typeof swap === 'string') ? swap : (swap.name || swap.replacement || swap.original_item || swap.original || 'Swap Dish');
    const price = parseFloat(String(swap.price || '0').replace(/,/g, '')) || 0;

    if (!selectedOriginalItem) {
        activeSwaps = activeSwaps.filter(s => s.id !== uniqueId);
    } else {
        const existingSwap = activeSwaps.find(s => s.original === selectedOriginalItem && s.id !== uniqueId);
        if (existingSwap) {
            alert(`"${selectedOriginalItem}" is already being replaced. Please select another item.`);
            return;
        }

        activeSwaps = activeSwaps.filter(s => s.id !== uniqueId);
        activeSwaps.push({
            id: uniqueId,
            original: selectedOriginalItem,
            replacement: swapName,
            price: price
        });
    }

    refreshAllSwapDropdowns();
    updateHiddenFields();
    calculateTotal();
    updateMenuVisuals();
}

function refreshAllSwapDropdowns() {
    // For each swap-menu-grid, update card disabled/selected states based on activeSwaps
    document.querySelectorAll('[id^="grid_swap_"]').forEach(grid => {
        const uniqueId = grid.id.replace('grid_', '');
        const activeSwap = activeSwaps.find(s => s.id === uniqueId);
        const selectedItem = activeSwap ? activeSwap.original : '';
        const row = document.getElementById(`row_${uniqueId}`);
        const badgeEl = document.getElementById(`badge_${uniqueId}`);

        grid.querySelectorAll('.swap-menu-card').forEach(card => {
            const itemVal = card.getAttribute('data-item');
            const isUsedByOther = activeSwaps.some(s => s.original === itemVal && s.id !== uniqueId);

            // Reset
            card.classList.remove('disabled-card', 'selected');

            if (isUsedByOther) {
                card.classList.add('disabled-card');
            } else if (itemVal === selectedItem) {
                card.classList.add('selected');
            }
        });

        if (row) {
            if (activeSwap) {
                row.classList.add('active');
            } else {
                row.classList.remove('active');
            }
        }

        if (badgeEl) {
            if (activeSwap) {
                badgeEl.innerHTML = `<span class="swap-replaced-pill"><i class="fas fa-check-circle"></i> Replacing: <b>${escapeHtml(activeSwap.original)}</b></span>`;
                badgeEl.style.display = 'inline-block';
            } else {
                badgeEl.innerHTML = '';
                badgeEl.style.display = 'none';
            }
        }
    });
}

// Update menu item visuals based on swaps and add-ons
function updateMenuVisuals() {
    // 1. Reset original items to default state
    const originalItems = document.querySelectorAll('.menu-item[data-original]');
    originalItems.forEach(item => {
        item.className = 'menu-item included';
        item.style.textDecoration = 'none';
        
        const icon = item.querySelector('i');
        if (icon) {
            icon.className = 'fas fa-check-circle';
            icon.style.color = '#2ecc71';
        }
    });

    // 2. Remove previously added dynamic items (both swaps and addons)
    const dynamicItems = document.querySelectorAll('.menu-item.added, .menu-item.addon-item');
    dynamicItems.forEach(item => item.remove());

    // 3. Apply active swaps
    activeSwaps.forEach(swap => {
        let originalEl = null;
        let bestMatchScore = 0; 
        const target = (swap.original || '').toLowerCase().trim();
        const targetBase = target.replace(/\(.*?\)/g, '').trim();

        originalItems.forEach(item => {
            const itemOriginal = item.getAttribute('data-original') || '';
            const current = itemOriginal.toLowerCase().trim();
            const currentBase = current.replace(/\(.*?\)/g, '').trim();
            
            // Exact match
            if (itemOriginal === swap.original) {
                if (bestMatchScore < 4) {
                    originalEl = item;
                    bestMatchScore = 4;
                }
            }
            // Case-insensitive match
            else if (current === target) {
                if (bestMatchScore < 3) {
                    originalEl = item;
                    bestMatchScore = 3;
                }
            }
            // Base match without parentheses (e.g. "Chicken Sweet & Sour" matches "Chicken Sweet & Sour (Medium 30pcs)")
            else if (targetBase && currentBase && (currentBase === targetBase || currentBase.includes(targetBase) || targetBase.includes(currentBase))) {
                if (bestMatchScore < 2) {
                    originalEl = item;
                    bestMatchScore = 2;
                }
            }
            // Partial substring match
            else if (target && (current.includes(target) || target.includes(current))) {
                if (bestMatchScore < 1) {
                    originalEl = item;
                    bestMatchScore = 1;
                }
            }
        });
        
        if (originalEl) {
            // Mark original as removed
            originalEl.className = 'menu-item removed';
            const icon = originalEl.querySelector('i');
            if (icon) {
                icon.className = 'fas fa-times-circle';
                icon.style.color = '#e74c3c';
            }
            
            // Create new item element
            const newItem = document.createElement('div');
            newItem.className = 'menu-item added';
            const priceVal = parseFloat(swap.price || 0);
            const priceTag = priceVal > 0 ? `(+₱${priceVal.toFixed(2)})` : '(Free Swap)';
            newItem.innerHTML = `
                <i class="fas fa-plus-circle" style="color: #2ecc71;"></i>
                <span class="item-text">
                    ${escapeHtml(swap.replacement)} 
                    <span style="font-size: 10px; background: #2ecc71; color: #000; padding: 2px 6px; border-radius: 4px; margin-left: 5px; vertical-align: middle; font-weight: bold;">SWAPPED</span>
                </span>
                <span class="price-tag" style="margin-left: auto;">${priceTag}</span>
            `;
            
            // Insert after original item
            if (originalEl.nextSibling) {
                originalEl.parentNode.insertBefore(newItem, originalEl.nextSibling);
            } else {
                originalEl.parentNode.appendChild(newItem);
            }
        }
    });

    // 4. Apply active Add-ons
    const menuItemsContainer = document.getElementById('menuItems');
    if (menuItemsContainer && activeAddOns.length > 0) {
        activeAddOns.forEach(addon => {
            const newItem = document.createElement('div');
            newItem.className = 'menu-item addon-item';
            const qty = addon.qty || 1;
            
            newItem.innerHTML = `
                <i class="fas fa-plus-circle"></i>
                <span class="item-text">
                    ${escapeHtml(addon.name)}
                    <span style="font-size: 10px; background: #d4af37; color: #111; padding: 2px 6px; border-radius: 4px; margin-left: 5px; vertical-align: middle; font-weight: bold;">ADD-ON (x${qty})</span>
                </span>
                <span class="price-tag" style="margin-left: auto;">(+₱${parseFloat(addon.price * qty).toFixed(2)})</span>
            `;
            menuItemsContainer.appendChild(newItem);
        });
    }
}

// Toggle add-on row (Show/Hide Qty Input)
function toggleAddOnRow(index, name, price) {
    const checkboxEl = document.querySelector(`#addon_row_${index} input[type="checkbox"]`);
    const row = document.getElementById(`addon_row_${index}`);
    const qtyContainer = document.getElementById(`addon_qty_container_${index}`);
    const qtyInput = document.getElementById(`addon_qty_${index}`);
    
    if (checkboxEl.checked) {
        row.classList.add('active');
        qtyContainer.classList.add('visible');
        
        // Add to activeAddOns with default qty 1
        const qty = parseInt(qtyInput.value) || 1;
        updateAddOnState(name, price, qty);
    } else {
        row.classList.remove('active');
        qtyContainer.classList.remove('visible');
        
        // Remove from activeAddOns
        activeAddOns = activeAddOns.filter(a => a.name !== name);
        updateHiddenFields();
        calculateTotal();
        updateMenuVisuals();
    }
}

// Update AddOn Qty
function updateAddOnQty(index, name, price, qtyVal) {
    const qty = parseInt(qtyVal);
    if (qty > 0) {
        updateAddOnState(name, price, qty);
    }
}

// Helper to update state
function updateAddOnState(name, price, qty) {
    // Check if exists
    const existingIndex = activeAddOns.findIndex(a => a.name === name);
    
    if (existingIndex > -1) {
        activeAddOns[existingIndex].qty = qty;
        activeAddOns[existingIndex].totalPrice = price * qty;
    } else {
        activeAddOns.push({
            name: name,
            price: price, // Unit price
            qty: qty,
            totalPrice: price * qty
        });
    }
    
    updateHiddenFields();
    calculateTotal();
    updateMenuVisuals();
}

// Initialize on page load
let customAddOnRequest = '';

// Update custom add-on request
function updateCustomAddOn(value) {
    customAddOnRequest = value.trim();
    updateHiddenFields();
}

// Update hidden fields with JSON data
function updateHiddenFields() {
    document.getElementById('addOnsJson').value = JSON.stringify(activeAddOns);
    document.getElementById('swapsJson').value = JSON.stringify(activeSwaps);
    document.getElementById('buildMenuJson').value = JSON.stringify(activeBuildMenuItems);
    
    // Create or update hidden input for custom addon
    let customInput = document.getElementById('customAddOnHidden');
    if (!customInput) {
        customInput = document.createElement('input');
        customInput.type = 'hidden';
        customInput.id = 'customAddOnHidden';
        customInput.name = 'custom_addon';
        document.getElementById('bookingForm').appendChild(customInput);
    }
    customInput.value = customAddOnRequest;

    // Save to session storage
    sessionStorage.setItem('booking_addons_<?php echo $product_id; ?>', JSON.stringify(activeAddOns));
    sessionStorage.setItem('booking_swaps_<?php echo $product_id; ?>', JSON.stringify(activeSwaps));
    sessionStorage.setItem('booking_build_menu_<?php echo $product_id; ?>', JSON.stringify(activeBuildMenuItems));
    sessionStorage.setItem('booking_custom_addon_<?php echo $product_id; ?>', customAddOnRequest || '');
}

function formatBookingCurrency(amount) {
    return '₱' + (Number(amount) || 0).toLocaleString('en-PH', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
}

function saveFormInputsToSession() {
    const form = document.getElementById('bookingForm');
    if (!form) return;
    const inputsToSave = ['quantity', 'delivery_date', 'delivery_time', 'delivery_address', 'event_type', 'venue_contact_name', 'venue_contact_phone', 'dietary_notes', 'payment_method'];
    const formData = {};
    inputsToSave.forEach(name => {
        const el = form.querySelector(`[name="${name}"]`);
        if (el) formData[name] = el.value;
    });
    sessionStorage.setItem('booking_inputs_<?php echo $product_id; ?>', JSON.stringify(formData));
}

function loadFormInputsFromSession() {
    const savedInputs = sessionStorage.getItem('booking_inputs_<?php echo $product_id; ?>');
    if (!savedInputs) return;
    let needsCalc = false;
    try {
        const formData = JSON.parse(savedInputs);
        const form = document.getElementById('bookingForm');
        if (!form) return;
        Object.keys(formData).forEach(name => {
            const el = form.querySelector(`[name="${name}"]`);
            if (el && typeof formData[name] === 'string') {
                el.value = formData[name];
                if (name === 'quantity') needsCalc = true;
                if (name === 'delivery_date' && typeof handleDateSelection === 'function') handleDateSelection(formData[name]);
                if (name === 'payment_method' && typeof toggleGCash === 'function') toggleGCash();
            }
        });
        if (needsCalc) calculateTotal();
    } catch (e) { console.error('Error loading form inputs', e); }
}

// Attach listeners to save inputs on change
document.addEventListener('change', function(e) {
    if (e.target && e.target.closest('#bookingForm')) saveFormInputsToSession();
});
document.addEventListener('input', function(e) {
    if (e.target && e.target.closest('#bookingForm')) saveFormInputsToSession();
});

// Calculate total amount
function calculateTotal() {
    let quantity = /^(?:[1-9][0-9]{0,4}|100000)$/.test(document.getElementById('quantity').value) ? Number(document.getElementById('quantity').value) : 0;
    if (isNaN(quantity) || quantity <= 0) {
        const qtyInput = document.getElementById('quantity');
        quantity = parseInt(qtyInput.dataset.minimum) || 1;
    }
    const packageSelect = document.getElementById('packageSelect');
    const selectedOption = packageSelect && packageSelect.selectedOptions[0] ? packageSelect.selectedOptions[0] : null;
    const selectedSet = selectedOption
        ? setsData.find(set => (set.set_name === selectedOption.value || set.name === selectedOption.value))
        : null;
    const calculated = window.BookingPricing.calculate({
        quantity: quantity,
        selectedSetPrice: selectedOption ? selectedOption.dataset.price : 0,
        selectedSetPax: selectedOption ? (selectedOption.dataset.pax || (selectedSet ? selectedSet.pax : 0)) : 0,
        selectedSetPerHeadPrice: selectedOption ? (selectedOption.dataset.perHead || (selectedSet ? selectedSet.per_head_price : 0)) : 0,
        extraGuestRate: selectedOption ? (selectedOption.dataset.extraGuest || (selectedSet ? selectedSet.extra_guest_rate : 0)) : 0,
        productPrice: <?php echo floatval($product['price']); ?>,
        addons: activeAddOns,
        swaps: activeSwaps
    });

    const breakdown = [];
    if (calculated.pricingMode === 'package') {
        breakdown.push(['Package', calculated.packagePrice]);
        if (calculated.extraGuestCount > 0) {
            breakdown.push(['Extra guests (' + calculated.extraGuestCount + ' x ₱' + calculated.extraGuestRate.toFixed(2) + ')', calculated.extraGuestTotal]);
        }
    } else {
        // Swaps are flat - subtract flat swapsPerUnit (not * quantity) from basePrice to get pure guests cost
        breakdown.push([quantity + (quantity === 1 ? ' Guest' : ' Guests') + ' x rate', calculated.basePrice - calculated.swapsPerUnit]);
    }
    if (calculated.addonsTotal > 0) breakdown.push(['Add-ons', calculated.addonsTotal]);
    if (calculated.swapsPerUnit > 0) breakdown.push(['Swaps', calculated.swapsPerUnit]);

    const breakdownList = document.getElementById('priceBreakdownList');
    if (breakdownList) {
        breakdownList.innerHTML = breakdown.map(function(row) {
            return '<li><span>' + row[0] + '</span><strong>' + formatBookingCurrency(row[1]) + '</strong></li>';
        }).join('');
        window.latestPriceBreakdownHtml = breakdownList.innerHTML;
    }

    const guestHint = document.getElementById('guestHint');
    if (guestHint && selectedSet) {
        const minPax = Number(qtyInput.dataset.minimum);
        const extraRate = parseFloat(selectedSet.extra_guest_rate || 0);
        if (minPax > 0 && extraRate > 0) {
            guestHint.textContent = 'Minimum ' + minPax + ' guests. Extra guests are ' + formatBookingCurrency(extraRate) + ' each.';
        } else if (parseFloat(selectedSet.per_head_price || 0) > 0) {
            guestHint.textContent = 'Minimum ' + minPax + ' guests. This package is priced per guest.';
        } else {
            guestHint.textContent = 'Minimum ' + minPax + ' guests.';
        }
    }

    document.getElementById('displayTotal').textContent = formatBookingCurrency(calculated.totalAmount);
    document.getElementById('displayDownpayment').textContent = formatBookingCurrency(calculated.downPaymentRequired);
    document.getElementById('gcashAmount').textContent = formatBookingCurrency(calculated.downPaymentRequired);
    document.getElementById('totalAmount').value = calculated.totalAmount.toFixed(2);

    // Sync Step 2 live price summary bar
    const step2Summary = document.getElementById('step2PriceSummary');
    const step2TotalDisplay = document.getElementById('step2TotalDisplay');
    if (step2Summary && step2TotalDisplay) {
        const packageSelect = document.getElementById('packageSelect');
        const hasPackage = packageSelect && packageSelect.value;
        if (hasPackage || <?php echo $has_preselected_set ? 'true' : 'false'; ?>) {
            step2Summary.style.display = 'flex';
            let summaryParts = [formatBookingCurrency(calculated.totalAmount)];
            if (calculated.addonsTotal > 0) {
                summaryParts.push('<span style="font-size:12px;color:var(--text-muted);font-weight:400;">incl. ' + formatBookingCurrency(calculated.addonsTotal) + ' add-ons</span>');
            }
            if (calculated.swapsPerUnit > 0) {
                summaryParts.push('<span style="font-size:12px;color:var(--text-muted);font-weight:400;">+ ' + formatBookingCurrency(calculated.swapsPerUnit) + ' swaps</span>');
            }
            step2TotalDisplay.innerHTML = summaryParts.join(' ');
        } else {
            step2Summary.style.display = 'none';
        }
    }
}

// Toggle GCash section
function toggleGCash() {
    const paymentMethod = document.getElementById('paymentMethod').value;
    const gcashSection = document.getElementById('gcashSection');
    
    if (paymentMethod === 'GCash') {
        gcashSection.style.display = 'block';
    } else {
        gcashSection.style.display = 'none';
    }
}

function formatCurrencyForReview(amount) {
    const numericAmount = Number(amount) || 0;
    return 'P' + numericAmount.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function formatDateForReview(value) {
    if (!value) return 'Not set';
    const parsedDate = new Date(value + 'T00:00:00');
    if (Number.isNaN(parsedDate.getTime())) return value;
    return parsedDate.toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' });
}

function formatTimeForReview(value) {
    if (!value) return 'Not set';
    const parsedDate = new Date('1970-01-01T' + value);
    if (Number.isNaN(parsedDate.getTime())) return value;
    return parsedDate.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
}

function extractPolicyHighlights(storeTerms, hasCustomRequest) {
    const lowerTerms = (storeTerms || '').toLowerCase();
    const highlights = [];

    const policyChecks = [
        { keywords: ['no cancellation', 'cannot cancel', 'non-cancellable'], text: 'This booking may not be cancellable once submitted.' },
        { keywords: ['non-refundable', 'not refundable', 'no refund'], text: 'Payments or downpayments may be non-refundable.' },
        { keywords: ['downpayment'], text: 'Please review the caterer downpayment policy before submitting.' },
        { keywords: ['resched', 'reschedule'], text: 'Rescheduling may be limited or may require caterer approval.' },
        { keywords: ['late payment', 'payment deadline', 'pay on time'], text: 'Late payment rules may apply for this booking.' },
        { keywords: ['damage', 'damaged', 'breakage'], text: 'Damage-related charges or penalties may apply if items are returned damaged.' }
    ];

    policyChecks.forEach(check => {
        if (check.keywords.some(keyword => lowerTerms.includes(keyword)) && !highlights.includes(check.text)) {
            highlights.push(check.text);
        }
    });

    if (hasCustomRequest) {
        highlights.push('Custom add-on requests cannot be changed or deleted once the booking is submitted.');
    }

    if (highlights.length === 0) {
        highlights.push('Please review the Caterer Terms before final submission.');
    }

    return '<ul class="review-list">' + highlights.map(item => `<li>${item}</li>`).join('') + '</ul>';
}

function selectedEventTypeForReview() {
    const input = document.getElementById('eventType');
    if (!input || !input.value) return 'Not selected';
    return input.tagName === 'SELECT'
        ? (input.selectedOptions[0]?.textContent.trim() || input.value)
        : input.value;
}

function populateReviewModal() {
    document.getElementById('reviewEventType').textContent = selectedEventTypeForReview();
    const packageSelect = document.getElementById('packageSelect');
    const quantity = document.getElementById('quantity').value || '0';
    const contactName = document.getElementById('venueContactName') ? document.getElementById('venueContactName').value.trim() : '';
    const contactPhone = document.getElementById('venueContactPhone') ? document.getElementById('venueContactPhone').value.trim() : '';
    const dietary = document.getElementById('dietaryNotes') ? document.getElementById('dietaryNotes').value.trim() : '';
    const deliveryDate = document.querySelector('input[name="delivery_date"]').value;
    const deliveryTime = document.querySelector('input[name="delivery_time"]').value;
    const deliveryAddress = document.querySelector('input[name="delivery_address"]').value.trim();
    const paymentMethod = document.getElementById('paymentMethod').value || 'Not set';
    const totalAmount = document.getElementById('totalAmount').value;
    const downpaymentText = document.getElementById('displayDownpayment').textContent || 'P0.00';
    const addOnsSummary = activeAddOns.length > 0
        ? '<ul class="review-list">' + activeAddOns.map(addon =>
            `<li>${escapeHtml(addon.name)} ×${addon.qty} (${formatCurrencyForReview(addon.totalPrice)})</li>`
        ).join('') + '</ul>'
        : 'No add-ons selected.';
    const swapsSummary = activeSwaps.length > 0
        ? '<ul class="review-list">' + activeSwaps.map(swap =>
            `<li>Swap <strong>${escapeHtml(swap.original)}</strong> for <strong>${escapeHtml(swap.replacement)}</strong> (${formatCurrencyForReview(swap.price)})</li>`
        ).join('') + '</ul>'
        : 'No swaps selected.';
    const buildMenuSummary = activeBuildMenuItems.length > 0
        ? '<ul class="review-list">' + activeBuildMenuItems.map(item => `<li>${item}</li>`).join('') + '</ul>'
        : 'No custom menu items selected.';
    const storeTermsRaw = <?php echo json_encode($product['store_terms_conditions'] ?? ''); ?>;

    document.getElementById('reviewPackage').textContent = packageSelect
        ? (packageSelect.value || 'Not selected')
        : '<?php echo addslashes(htmlspecialchars($product['name'])); ?>';
    document.getElementById('reviewQuantity').textContent = quantity;
    document.getElementById('reviewDate').textContent = formatDateForReview(deliveryDate);
    document.getElementById('reviewTime').textContent = formatTimeForReview(deliveryTime);
    document.getElementById('reviewAddress').textContent = deliveryAddress || 'Not set';
    document.getElementById('reviewPaymentMethod').textContent = paymentMethod;
    document.getElementById('reviewPaymentProof').textContent = paymentMethod === 'GCash'
        ? 'GCash proof required after booking'
        : 'Cash payment selected';
    document.getElementById('reviewTotal').textContent = formatCurrencyForReview(totalAmount);
    document.getElementById('reviewDownpayment').textContent = downpaymentText.replace('₱', 'P');
    document.getElementById('reviewAddOns').innerHTML = addOnsSummary;
    document.getElementById('reviewSwaps').innerHTML = swapsSummary;
    const reviewBreakdown = document.getElementById('reviewBreakdown');
    if (reviewBreakdown) reviewBreakdown.innerHTML = window.latestPriceBreakdownHtml ? '<ul class="review-price-list">' + window.latestPriceBreakdownHtml + '</ul>' : '-';
    const reviewVenueContact = document.getElementById('reviewVenueContact');
    if (reviewVenueContact) reviewVenueContact.textContent = (contactName || contactPhone) ? (contactName + (contactPhone ? ' · ' + contactPhone : '')) : 'Not set';
    const reviewDietary = document.getElementById('reviewDietary');
    if (reviewDietary) reviewDietary.textContent = dietary || 'None';
    document.getElementById('reviewCustomRequest').textContent = customAddOnRequest || 'No custom request added.';
}

function openReviewModal() {
    populateReviewModal();
    const modal = document.getElementById('reviewModal');
    modal.classList.add('active');
    modal.setAttribute('aria-hidden', 'false');
    modal.querySelector('.review-modal-body').scrollTop = 0;
}

function closeReviewModal() {
    const modal = document.getElementById('reviewModal');
    modal.classList.remove('active');
    modal.setAttribute('aria-hidden', 'true');
}

// Form submit handler
document.getElementById('bookingForm').addEventListener('submit', function(e) {
    // Update hidden fields before submit
    updateHiddenFields();

    const dateInput = document.getElementById('deliveryDateInput');
    if (dateInput && dateInput.value && !updateAvailabilityStatus(dateInput.value)) {
        e.preventDefault();
        alert('This caterer is already fully booked on the selected date. Please choose another date.');
        return;
    }

    if (bookingReviewConfirmed) {
        if (this.dataset.submitting === 'true') {
            e.preventDefault();
            return;
        }
        this.dataset.submitting = 'true';
        this.querySelectorAll('button[type="submit"]').forEach(function(button) {
            button.disabled = true;
        });
        bookingReviewConfirmed = false;
        return;
    }

    e.preventDefault();
    openReviewModal();
});

document.getElementById('finalConfirmBookingBtn').addEventListener('click', function() {
    if (this.disabled) {
        return;
    }
    this.disabled = true;
    this.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';
    bookingReviewConfirmed = true;
    try { sessionStorage.removeItem(bookingViewKey); } catch (e) {}
    closeReviewModal();
    document.getElementById('bookingForm').requestSubmit();
});

document.getElementById('reviewModal').addEventListener('click', function(e) {
    // Keep backdrop clicks inside the review dialog without dismissing it.
    e.stopPropagation();
});

const preselectedSetName = <?php echo json_encode($preselected_set_name); ?>;
const hasPreselectedSet = <?php echo $has_preselected_set ? 'true' : 'false'; ?>;
const isEmbeddedBookingModal = <?php echo $is_embedded_booking_modal ? 'true' : 'false'; ?>;


// Keep the current booking workspace across refreshes in this browser tab.
const bookingViewKey = 'booking_view_<?php echo (int) $user_id; ?>_' + location.pathname + location.search;
const bookingScrollSelector = '.booking-page-container, .booking-modal-card, .booking-form-container, .booking-step-body, .review-modal-body';
let savedBookingView = null;
let bookingViewReady = false;
try { savedBookingView = JSON.parse(sessionStorage.getItem(bookingViewKey) || 'null'); } catch (e) {}

function saveBookingView() {
    if (!bookingViewReady || bookingReviewConfirmed) return;
    const packageSelect = document.getElementById('packageSelect');
    const activeTab = document.querySelector('.tab-content.active');
    const view = {
        step: getActiveBookingStep(),
        package: packageSelect ? packageSelect.value : '',
        tab: activeTab ? activeTab.id : '',
        review: document.getElementById('reviewModal').classList.contains('active'),
        x: window.scrollX, y: window.scrollY,
        scroll: Array.from(document.querySelectorAll(bookingScrollSelector), el => [el.scrollLeft, el.scrollTop]),
        details: Array.from(document.querySelectorAll('#bookingForm details'), el => el.open)
    };
    try {
        saveFormInputsToSession();
        sessionStorage.setItem(bookingViewKey, JSON.stringify(view));
    } catch (e) {}
}

// Restore the selected package before the wizard builds its menu and add-ons.
document.addEventListener('DOMContentLoaded', function() {
    if (!savedBookingView) return;
    const select = document.getElementById('packageSelect');
    if (select && Array.from(select.options).some(option => option.value === savedBookingView.package)) {
        select.value = savedBookingView.package;
    }
    if (savedBookingView.step >= 1 && savedBookingView.step <= 4) {
        sessionStorage.setItem('booking_step_<?php echo $product_id; ?>', savedBookingView.step);
    }
});

window.addEventListener('load', function() {
    if (savedBookingView) {
        if (savedBookingView.tab && document.getElementById(savedBookingView.tab)) openTab(savedBookingView.tab);
        document.querySelectorAll('#bookingForm details').forEach((el, i) => {
            // Terms start collapsed even when an earlier visit saved them open.
            if (el.classList.contains('terms-card')) { el.open = false; return; }
            if (typeof savedBookingView.details?.[i] === 'boolean') el.open = savedBookingView.details[i];
        });
        syncMiniDateTrigger();
        syncMiniTimeTrigger();
        if (savedBookingView.review) openReviewModal();
    }
    requestAnimationFrame(() => requestAnimationFrame(() => {
        if (savedBookingView) {
            document.querySelectorAll(bookingScrollSelector).forEach((el, i) => {
                const position = savedBookingView.scroll?.[i];
                if (position) el.scrollTo({ left: position[0], top: position[1], behavior: 'instant' });
            });
            window.scrollTo({ left: savedBookingView.x || 0, top: savedBookingView.y || 0, behavior: 'instant' });
        }
        bookingViewReady = true;
    }));
});
let bookingViewSaveTimer;
['input', 'change', 'click', 'scroll', 'toggle'].forEach(type => {
    document.addEventListener(type, function() {
        clearTimeout(bookingViewSaveTimer);
        bookingViewSaveTimer = setTimeout(saveBookingView, 120);
    }, true);
});
window.addEventListener('pagehide', saveBookingView);
document.addEventListener('visibilitychange', function() {
    if (document.visibilityState === 'hidden') saveBookingView();
});

// Initialize on page load
<?php if ($is_catering && !empty($sets)): ?>
document.addEventListener('DOMContentLoaded', function() {
    window.isInitialPageLoad = true;
    initializeAvailabilityCalendar();
    const packageSelect = document.getElementById('packageSelect');
    simplifyPackageOptions();
    updatePackageSummary();
    
    let savedStep = sessionStorage.getItem('booking_step_<?php echo $product_id; ?>');
    if (savedStep) savedStep = parseInt(savedStep, 10);

    if (savedStep) {
        if (packageSelect && packageSelect.value) {
            loadSet(packageSelect.value);
        }
        goToBookingStep(savedStep, true);
    } else if (preselectedSetName && packageSelect) {
        packageSelect.value = preselectedSetName;
        loadSet(preselectedSetName);
        goToBookingStep(2, true);
        // Auto-open Add-ons tab if coming from "Request Add-ons" button
        if (window.location.hash === '#addons-section') {
            setTimeout(function() {
                openTab('tab-addons');
                const addonsEl = document.getElementById('tab-addons');
                if (addonsEl) addonsEl.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }, 200);
        }
    } else if (packageSelect && packageSelect.value) {
        loadSet(packageSelect.value);
        goToBookingStep(1, true);
    } else {
        goToBookingStep(1, true);
    }
    
    window.isInitialPageLoad = false;
    loadFormInputsFromSession();
});
<?php else: ?>
document.addEventListener('DOMContentLoaded', function() {
    initializeAvailabilityCalendar();
    loadFormInputsFromSession();
    let savedStep = sessionStorage.getItem('booking_step_<?php echo $product_id; ?>');
    if (savedStep) {
        goToBookingStep(parseInt(savedStep, 10), true);
    } else {
        goToBookingStep(1, true);
    }
});
<?php endif; ?>
</script>

<?php if ($duplicate_event_error): ?>
<link rel="stylesheet" href="../assets/css/sage-notices.css?v=20261027-2">
<script src="../assets/js/sage-notices.js?v=20261027-2"></script>
<?php endif; ?>
<?php require_once __DIR__ . '/includes/footer.php'; ?>


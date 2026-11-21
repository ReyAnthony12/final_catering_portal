<?php
require_once __DIR__ . '/../config.php';
/** @var mysqli $conn */
require_once __DIR__ . '/../includes/financial_helper.php';
require_once __DIR__ . '/../includes/booking_flow_helper.php';

// Start session if not started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check login
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'customer') {
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$user_id = $_SESSION['user_id'];
$booking_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($booking_id <= 0) {
    echo json_encode(['error' => 'Invalid ID']);
    exit();
}

// Fetch complete booking details
$details_query = "
    SELECT 
        o.*,
        u.business_name as store_name,
        p.name as product_name
    FROM bookings o
    LEFT JOIN (
        SELECT u.id, sd.business_name 
        FROM users u 
        LEFT JOIN store_details sd ON u.id = sd.user_id 
        WHERE u.role IN ('caterer','store')
    ) u ON o.store_id = u.id
    LEFT JOIN products p ON o.product_id = p.id
    WHERE o.id = '$booking_id' AND o.customer_id = '$user_id'
";

$details_result = mysqli_query($conn, $details_query);

if (!$details_result || mysqli_num_rows($details_result) === 0) {
    echo json_encode(['error' => 'Booking not found']);
    exit();
}

$booking = mysqli_fetch_assoc($details_result);
$csrf_token = generateCSRFToken();

// ==========================================
// PARSE MENU DETAILS
// ==========================================
$sets_lookup = [];
$sets_query = "
    SELECT ps.* 
    FROM product_sets ps
    JOIN products p ON ps.product_id = p.id
    WHERE p.store_id = '{$booking['store_id']}'
";
$sets_result = mysqli_query($conn, $sets_query);
while ($set = mysqli_fetch_assoc($sets_result)) {
    $name = $set['set_name'] ?? $set['name'];
    $sets_lookup[$name] = $set;
}

$raw_menu = $booking['additional_menu'] ?? '';
$parts = explode(' | ', $raw_menu);

$setName = '';
$swaps = [];
$addons = [];
$customRequest = '';

if (!empty($parts)) {
    $setName = trim($parts[0]);
    
    foreach ($parts as $i => $part) {
        if ($i === 0) continue; 
        
        if (strpos($part, 'Added Items:') !== false) {
            $itemsStr = str_replace('Added Items:', '', $part);
            $addons = [];
            if (preg_match_all('/(?:^|,\s*)(.+?)\s*(\(\+?\s*[\d,.]+\))/', $itemsStr, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $m) {
                    $addons[] = trim($m[1]) . " " . $m[2];
                }
            }
        } elseif (strpos($part, 'Menu Swaps:') !== false) {
            $itemsStr = str_replace('Menu Swaps:', '', $part);
            $swaps = [];
            if (preg_match_all('/(?:^|,\s*)(.+?)\s*(\(\+?\s*[\d,.]+\))/', $itemsStr, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $m) {
                    $swaps[] = trim($m[1]) . " " . $m[2];
                }
            } else {
                $swaps = array_map('trim', explode(',', $itemsStr));
            }
        } elseif (strpos($part, 'Custom Add-on Request:') !== false) {
            $customRequest = trim(str_replace('Custom Add-on Request:', '', $part));
        }
    }
}

$originalItems = [];
$originalMenuGroups = [];
$menuLookupName = $setName;
if ((!isset($sets_lookup[$menuLookupName]) || $menuLookupName === '') && !empty($booking['package_name'])) {
    $menuLookupName = trim((string) $booking['package_name']);
}
if ($menuLookupName !== '' && isset($sets_lookup[$menuLookupName])) {
    $parsedMenu = bookingPricingParseSetDescription($sets_lookup[$menuLookupName]['description'] ?? '');
    $originalItems = $parsedMenu['items'];
    $originalMenuGroups = $parsedMenu['groups'];
}

// ==========================================
// FINANCIALS
// ==========================================
$fin = getOrderFinancials($booking_id);
$grand_total = $fin['totals']['grand_total'] ?? 0;
$total_paid = $fin['totals']['total_paid'] ?? 0;
$remaining = $fin['totals']['balance'] ?? 0;
$status_payment = $fin['status'] ?? 'pending';

// ==========================================
// TIMELINE
// ==========================================
$timeline = [
    'steps' => [
        'pending' => ['status' => 'current', 'label' => 'Pending', 'icon' => 'fa-hourglass-half', 'description' => 'Payment and booking review are still pending'],
        'confirmed' => ['status' => '', 'label' => 'Confirmed', 'icon' => 'fa-check', 'description' => 'Caterer accepted your booking'],
        'preparing' => ['status' => '', 'label' => 'Preparing', 'icon' => 'fa-utensils', 'description' => 'Your order is being prepared'],
        'delivering' => ['status' => '', 'label' => 'Ongoing', 'icon' => 'fa-calendar-day', 'description' => 'Your event is ongoing or the team is already on-site'],
        'completed' => ['status' => '', 'label' => 'Completed', 'icon' => 'fa-check-circle', 'description' => 'Booking finished successfully']
    ]
];

$current_status = strtolower(trim($booking['order_status']));

if ($current_status === 'approved') {
    $current_status = 'confirmed';
} elseif ($current_status === 'ready') {
    $current_status = 'delivering';
}

$found = false;
foreach ($timeline['steps'] as $key => &$step) {
    if ($current_status === 'cancelled') {
        $step['status'] = '';
        continue;
    }

    if ($key == $current_status) {
        $step['status'] = 'current';
        $found = true;
    } elseif (!$found) {
        $step['status'] = 'completed';
    } else {
        $step['status'] = '';
    }
}

unset($step); // Release the reference before rendering the steps by value.

$next_action_title = 'Next Action';
$next_action_text = 'Monitor your booking updates and follow any caterer instructions if needed.';

switch ($current_status) {
    case 'pending':
        $next_action_text = 'Wait for the caterer to review and confirm your booking request.';
        break;
    case 'confirmed':
        $next_action_text = $remaining > 0
            ? 'Prepare your remaining balance and monitor updates from the caterer.'
            : 'Your booking is confirmed. Just monitor caterer updates and prepare for your event.';
        break;
    case 'preparing':
        $next_action_text = 'Your order is being prepared. Keep your phone available in case the caterer contacts you.';
        break;
    case 'delivering':
        $next_action_text = 'Be ready to receive your order or coordinate with the caterer for handoff details.';
        break;
    case 'completed':
        $next_action_title = 'Booking Completed';
        $next_action_text = 'Your booking is complete. You may review the caterer or book again when needed.';
        break;
    case 'cancelled':
        $next_action_title = 'Booking Closed';
        $next_action_text = 'This booking has been cancelled. You may browse caterers again if you want to make a new booking.';
        break;
}

// Start Buffering HTML Responses

// 1. Timeline HTML
ob_start();
?>
<?php foreach ($timeline['steps'] as $key => $step): ?>
<div class="timeline-step <?php echo $step['status']; ?>">
    <div class="step-icon">
        <i class="fas <?php echo $step['icon']; ?>"></i>
    </div>
    <div class="step-label"><?php echo $step['label']; ?></div>
    <div class="step-description"><?php echo $step['description']; ?></div>
</div>
<?php endforeach; ?>
<?php
$timeline_html = ob_get_clean();

// 2. Menu HTML uses the initial page presentation.
ob_start();
require __DIR__ . '/includes/booking-menu-details.php';
$menu_html = ob_get_clean();

// 3. Financial HTML
ob_start();
?>
<div style="margin: 20px 0;">
    <div style="font-size: 14px; color: var(--text-muted); margin-bottom: 5px;">Payment Status</div>
    <div class="status-badge <?php echo $status_payment == 'paid' ? 'status-completed' : ($status_payment == 'partial' ? 'status-confirmed' : 'status-pending'); ?>" style="font-size: 16px; padding: 10px 20px;">
        <?php echo ucfirst($status_payment); ?>
    </div>
</div>

<div style="margin-bottom: 25px;">
    <div style="font-size: 14px; color: var(--text-muted);">Grand Total</div>
    <div style="font-size: 28px; font-weight: bold; color: var(--accent-gold);">?<?php echo number_format($grand_total, 2); ?></div>
</div>

<?php if (in_array(strtolower($current_status), ['confirmed', 'preparing', 'delivering', 'completed'])): ?>
<a href="payments.php?id=<?php echo $booking_id; ?>" class="btn btn-primary" style="justify-content: center;">
    <i class="fas fa-file-invoice-dollar"></i> View Payment Details & History
</a>
<p style="margin-top: 15px; font-size: 12px; color: var(--text-muted);">
    Click above to view your payment summary and booking payment history.
</p>
<?php else: ?>
<div style="background: rgba(255, 193, 7, 0.1); color: var(--warning-text); padding: 15px; border-radius: 8px; text-align: center; margin-top: 15px;">
    <i class="fas fa-clock" style="font-size: 20px; margin-bottom: 10px;"></i>
    <div style="font-weight: 600; margin-bottom: 5px;">Awaiting Approval</div>
    <div style="font-size: 12px;">Payment can be made once the caterer confirms your booking.</div>
</div>
<?php endif; ?>
<?php
$financial_html = ob_get_clean();

// 4. Status Badge HTML
ob_start();
?>
<i class="fas fa-circle" style="font-size: 8px;"></i>
<?php echo htmlspecialchars(getCustomerBookingStatusLabel($current_status)); ?>
<?php
$status_badge_html = ob_get_clean();
$status_badge_class = getCustomerBookingStatusClass($booking['order_status']);

// Return JSON
echo json_encode([
    'success' => true,
    'timeline_html' => $timeline_html,
    'menu_html' => $menu_html,
    'financial_html' => $financial_html,
    'status_badge_html' => $status_badge_html,
    'downpayment_open' => customerBookingDownpaymentIsOpen((string) $booking['order_status'], $fin ?: []),
    'awaiting_confirmation' => strtolower(trim((string) $booking['order_status'])) === 'pending',
    'status_badge_class' => $status_badge_class
]);
?>

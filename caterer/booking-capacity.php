<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config.php';
/** @var mysqli $conn */
require_once __DIR__ . '/../includes/payment_functions.php';

require_once __DIR__ . '/../includes/booking_guest_count.php';

if (!isLoggedIn() || !in_array(getUserRole(), ['store', 'caterer'])) {
    header('Location: ../login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$success = '';
$error = '';

$store_query = mysqli_query($conn, "
    SELECT u.first_name, u.last_name, u.status AS user_status, sd.business_name, sd.max_bookings_per_day, sd.booking_gap_days, sd.minimum_booking_guests, sd.maximum_booking_guests
    FROM users u
    LEFT JOIN store_details sd ON sd.user_id = u.id
    WHERE u.id = '$user_id'
    LIMIT 1
");
$caterer = $store_query ? mysqli_fetch_assoc($store_query) : null;
$is_store_closed_by_portal = (($caterer['user_status'] ?? '') === 'closed');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_holiday') {
    header('Content-Type: application/json');
    require_once __DIR__ . '/../includes/booking_holiday_helper.php';
    try {
        $result = $is_store_closed_by_portal
            ? ['success' => false, 'error' => 'This account cannot change holiday settings.']
            : toggleCatererHoliday($conn, (int)$user_id, trim($_POST['date'] ?? ''));
    } catch (Throwable $failure) {
        $result = ['success' => false, 'error' => 'Unable to update this date. Please try again.'];
    }
    echo json_encode($result);
    exit();
}

if (isset($_POST['save_booking_capacity']) && !$is_store_closed_by_portal) {
    $minimum_booking_guests = parseBookingGuestCount($_POST['minimum_booking_guests'] ?? null);
    $maximum_booking_guests = parseBookingGuestCount($_POST['maximum_booking_guests'] ?? null);
    if (!is_string($_POST['csrf_token'] ?? null) || !validateCSRFToken($_POST['csrf_token'])) {
        $error = 'Your session expired. Refresh the page and try again.';
    } elseif ($minimum_booking_guests === null || $maximum_booking_guests === null) {
        $error = 'Enter a whole number from 1 to 100,000 without leading zeros.';
    }
    if ($error === '' && $maximum_booking_guests < $minimum_booking_guests) {
        $error = 'Maximum guests must be at least the minimum guests.';
    }
    $max_bookings_per_day = max(1, min(100, intval($_POST['max_bookings_per_day'] ?? 5)));
    $booking_gap_days = max(0, min(365, intval($_POST['booking_gap_days'] ?? 0)));
    $existing_gap_days = max(0, intval($caterer['booking_gap_days'] ?? 0));
    if ($error === '' && $booking_gap_days !== $existing_gap_days && $booking_gap_days > 0) {
        $today = date('Y-m-d');
        $cutoff = date('Y-m-d', strtotime("+$booking_gap_days days", strtotime($today)));
        $conflict_query = mysqli_prepare($conn, "SELECT DATE(order_date) AS booking_date FROM bookings
            WHERE store_id = ? AND order_status != 'cancelled'
            AND order_date >= ? AND order_date < ? ORDER BY order_date LIMIT 1");
        if ($conflict_query) {
            mysqli_stmt_bind_param($conflict_query, 'iss', $user_id, $today, $cutoff);
            if (mysqli_stmt_execute($conflict_query)) {
                $conflict = mysqli_fetch_assoc(mysqli_stmt_get_result($conflict_query));
                if ($conflict) {
                    $conflict_date = date('M j, Y', strtotime($conflict['booking_date']));
                    $error = "Cannot change lead time: an existing booking on $conflict_date falls within the proposed $booking_gap_days-day lead time. Your settings have not changed.";
                }
            } else {
                $error = 'Unable to verify existing bookings. Your settings have not changed. Please try again.';
            }
            mysqli_stmt_close($conflict_query);
        } else {
            $error = 'Unable to verify existing bookings. Your settings have not changed. Please try again.';
        }
    }
    if ($error !== '') {
        // Keep both saved settings when the proposed lead time conflicts with a booking.
    } elseif (mysqli_query($conn, "UPDATE store_details SET max_bookings_per_day = '$max_bookings_per_day', booking_gap_days = '$booking_gap_days', minimum_booking_guests = '$minimum_booking_guests', maximum_booking_guests = '$maximum_booking_guests' WHERE user_id = '$user_id'")) {
        $caterer['minimum_booking_guests'] = $minimum_booking_guests;
        $caterer['maximum_booking_guests'] = $maximum_booking_guests;
        $success = 'Booking settings updated successfully.';
        $caterer['max_bookings_per_day'] = $max_bookings_per_day;
        $caterer['booking_gap_days'] = $booking_gap_days;
    } else {
        $error = 'Unable to update booking settings right now. Please try again.';
    }
}

$current_capacity = max(1, intval($caterer['max_bookings_per_day'] ?? getStoreBookingCapacity($user_id)));
$current_gap_days = intval($caterer['booking_gap_days'] ?? 0);
$range_start = date('Y-m-01');
$range_end = date('Y-m-t', strtotime('+12 months'));
$availability_range = getStoreAvailabilityRange($user_id, $range_start, $range_end);
$calendar_events = [];

foreach ($availability_range['dates'] as $date => $info) {
    $status = $info['status'] ?? 'available';
    $color = '#2ecc71';
    $title = $info['active_orders'] . '/' . $info['max_orders_per_day'] . ' booked';

    if ($status === 'almost_full') {
        $color = '#f39c12';
        $title = 'Almost full: ' . $title;
    } elseif ($status === 'fully_booked') {
        $color = '#e74c3c';
        $title = 'Fully booked: ' . $title;
    }

    $calendar_events[] = [
        'title' => $info['active_orders'] . '/' . $info['max_orders_per_day'],
        'start' => $date,
        'allDay' => true,
        'backgroundColor' => $color,
        'borderColor' => $color,
        'textColor' => '#ffffff',
        'extendedProps' => $info
    ];
}

foreach ($availability_range['holidays'] as $holiday_date) {
    $calendar_events[] = [
        'title' => 'Holiday',
        'start' => $holiday_date,
        'allDay' => true,
        'backgroundColor' => '#8e44ad',
        'borderColor' => '#8e44ad',
        'textColor' => '#ffffff',
        'extendedProps' => ['is_holiday' => true]
    ];
}

require_once __DIR__ . '/includes/header.php';
?>

<style>
    .capacity-page { max-width: 1440px; margin: 0 auto; }
    .capacity-page .content-header { padding: 28px; background: radial-gradient(ellipse at top right, rgba(212,175,55,.16), transparent 65%), var(--card-bg); border-color: rgba(212,175,55,.25); gap: 20px; }
    .capacity-page .page-title h1 { font-size: clamp(24px, 3vw, 32px); line-height: 1.2; letter-spacing: -.7px; }
    .capacity-page .page-title p { line-height: 1.6; margin-top: 10px; max-width: 520px; }
    .capacity-eyebrow { display: block; color: var(--accent-gold); font-size: 11px; font-weight: 700; letter-spacing: 2px; margin-bottom: 12px; }
    .capacity-hero-icon { display: grid; place-items: center; flex-shrink: 0; width: 64px; height: 64px; border-radius: 20px; background: rgba(212,175,55,.12); color: var(--accent-gold); font-size: 26px; border: 1px solid rgba(212,175,55,.25); }
    .capacity-stats { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; margin-bottom: 24px; }
    .capacity-stat { padding: 20px; border: 1px solid var(--border-color); border-radius: 16px; background: linear-gradient(135deg, rgba(212,175,55,.06), transparent), var(--card-bg); }
    .capacity-stat span { display: block; color: var(--text-muted); font-size: 13px; margin-bottom: 8px; }
    .capacity-stat strong { font-size: 30px; color: var(--text-main); letter-spacing: -1px; }
    .capacity-stat small { font-size: 13px; color: var(--text-muted); margin-left: 6px; }
    .capacity-layout { display: grid; grid-template-columns: minmax(240px, 280px) minmax(0, 1fr); gap: 24px; align-items: start; }
    .capacity-page .card { border-radius: 18px; min-width: 0; padding: 24px; }
    .capacity-page .card-header { gap: 12px; }
    .capacity-page .card-header h3 { font-size: 18px; line-height: 1.4; }
    .booking-capacity-form { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
    .booking-capacity-form > div { min-width: 0; }
    .capacity-page .form-control { min-height: 50px; font-size: 16px; transition: border-color .2s, box-shadow .2s; }
    .capacity-page .form-control:focus { outline: none; border-color: var(--accent-gold) !important; box-shadow: 0 0 0 3px rgba(212,175,55,.12); }
    .capacity-help { color: var(--text-muted); font-size: 12px; line-height: 1.6; margin: 8px 0 0; }
    .capacity-save { width: 100%; justify-content: center; min-height: 48px; }
    .capacity-legend { display: flex; gap: 8px 16px; flex-wrap: wrap; margin-bottom: 18px; }
    .capacity-legend span { display: inline-flex; align-items: center; gap: 6px; font-size: 12px; color: var(--text-muted); }
    .capacity-legend span::before { content: ''; width: 8px; height: 8px; border-radius: 50%; background: var(--status-color); }
    #booking-capacity-summary { font-size: 14px; line-height: 1.6; overflow-wrap: anywhere; }
    #booking-capacity-calendar { min-height: 0 !important; }
    .capacity-page .fc { font-size: 13px; --fc-border-color: var(--border-color); --fc-today-bg-color: rgba(212,175,55,.09); --fc-neutral-bg-color: rgba(255,255,255,.03); }
    .capacity-page .fc .fc-toolbar { flex-wrap: wrap; gap: 12px; }
    .capacity-page .fc .fc-toolbar-title { font-size: 20px; letter-spacing: -.4px; }
    .capacity-page .fc .fc-button-primary { background: var(--primary-bg); border-color: var(--border-color); color: var(--text-main); min-height: 40px; box-shadow: none; }
    .capacity-page .fc .fc-button-primary:not(:disabled):hover, .capacity-page .fc .fc-button-primary:not(:disabled).fc-button-active { background: var(--accent-gold); border-color: var(--accent-gold); color: #111; }
    .capacity-page .fc .fc-daygrid-day-number { padding: 8px; }
    .capacity-page .fc .fc-daygrid-event { border-radius: 5px; font-size: 11px; padding: 2px; }
    .capacity-page .fc .fc-daygrid-day { cursor: pointer; }
    .capacity-page .fc .capacity-selected { box-shadow: inset 0 0 0 2px var(--accent-gold); }
    @media (max-width: 1200px) {
        .capacity-layout { grid-template-columns: 1fr; gap: 0; }
        .booking-capacity-form { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .capacity-save { width: auto; min-width: 180px; }
    }
    @media (max-width: 600px) {
        .capacity-page .content-header { padding: 20px; margin-bottom: 16px; }
        .capacity-hero-icon { display: none; }
        .capacity-stats { gap: 10px; margin-bottom: 16px; }
        .capacity-stat { padding: 16px 14px; }
        .capacity-stat strong { font-size: 26px; }
        .capacity-stat small { display: block; margin: 4px 0 0; }
        .capacity-page .card { padding: 18px 14px; margin-bottom: 16px; }
        .booking-capacity-form { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
        .capacity-save { width: 100%; }
        .capacity-page .fc .fc-toolbar-title { font-size: 19px; }
        .capacity-page .fc .fc-toolbar-chunk:first-child { width: 100%; }
        .capacity-page .fc .fc-daygrid-day-number { padding: 6px; }
        .capacity-page .fc .fc-col-header-cell-cushion { padding: 9px 2px; font-size: 11px; }
        .capacity-page .fc .fc-daygrid-event { font-size: 10px; margin: 2px 1px; }
        #booking-capacity-summary { padding: 12px !important; }
    }
</style>

<style>
    .capacity-page .capacity-calendar-card { background: linear-gradient(155deg, rgba(212,175,55,.045), transparent 45%), var(--card-bg); }
    .capacity-calendar-card .capacity-legend { display: grid; grid-template-columns: repeat(4, auto); justify-content: start; gap: 10px 18px; }
    .capacity-calendar-card .fc .fc-toolbar { margin-bottom: 20px; align-items: center; }
    .capacity-calendar-card .fc .fc-toolbar-title { font-size: clamp(18px, 2vw, 24px); font-weight: 700; color: var(--text-main); }
    .capacity-calendar-card .fc .fc-button-primary { background: rgba(212,175,55,.08); border: 1px solid rgba(212,175,55,.2); color: var(--accent-gold); min-height: 44px; padding: 8px 12px; border-radius: 10px; text-transform: capitalize; }
    .capacity-calendar-card .fc .fc-button-primary:disabled { opacity: .4; }
    .capacity-calendar-card .fc .fc-button:focus-visible { outline: 2px solid var(--accent-gold); outline-offset: 3px; }
    .capacity-calendar-card .fc-theme-standard .fc-scrollgrid { border: 0; }
    .capacity-calendar-card .fc-theme-standard th,
    .capacity-calendar-card .fc-theme-standard td { border-color: rgba(128,128,128,.12); }
    .capacity-calendar-card .fc .fc-col-header-cell { background: transparent; border: 0; }
    .capacity-calendar-card .fc .fc-col-header-cell-cushion { color: var(--text-muted); font-size: 11px; font-weight: 600; padding: 12px 0; text-decoration: none; }
    .capacity-calendar-card .fc .fc-daygrid-day-frame { min-height: 90px; padding: 4px; transition: background .15s; }
    .capacity-calendar-card .fc .fc-daygrid-day:hover { background: rgba(212,175,55,.045); }
    .capacity-calendar-card .fc .fc-daygrid-day-top { justify-content: center; }
    .capacity-calendar-card .fc .fc-daygrid-day-number { color: var(--text-main); font-size: 13px; font-weight: 600; text-decoration: none; padding: 6px; }
    .capacity-calendar-card .fc .fc-day-today .fc-daygrid-day-number { background: var(--accent-gold); color: #15130b; border-radius: 50%; min-width: 28px; text-align: center; }
    .capacity-calendar-card .fc .fc-daygrid-event { background: transparent; border: 0; border-radius: 6px; margin: 3px 1px; padding: 3px 0; white-space: normal; }
    .capacity-event { display: flex; align-items: center; justify-content: center; gap: 5px; width: 100%; font-size: 11px; font-weight: 700; line-height: 1.5; }
    .capacity-event::before { content: ''; width: 5px; height: 5px; border-radius: 50%; flex-shrink: 0; background: currentColor; }
    .capacity-calendar-card .fc .capacity-selected { background: rgba(212,175,55,.09); box-shadow: inset 0 0 0 2px var(--accent-gold); }
    @media (max-width: 600px) {
        .capacity-page .capacity-calendar-card { padding: 18px 10px; }
        .capacity-calendar-card .card-header { margin-left: 4px; margin-right: 4px; }
        .capacity-calendar-card .card-header h3 { font-size: 16px; }
        .capacity-calendar-card .capacity-legend { display: flex; flex-wrap: nowrap; justify-content: space-between; gap: 4px; margin: 0 0 20px; }
        .capacity-calendar-card .capacity-legend span { font-size: clamp(8px, 2.5vw, 11px); gap: 3px; white-space: nowrap; }
        .capacity-calendar-card .capacity-legend span::before { width: 5px; height: 5px; flex-shrink: 0; }
        .capacity-calendar-card .fc .fc-toolbar { gap: 12px; }
        .capacity-calendar-card .fc .fc-toolbar-chunk:first-child { width: auto; }
        .capacity-calendar-card .fc .fc-toolbar-title { font-size: 18px; }
        .capacity-calendar-card .fc .fc-button-primary { padding: 8px; min-width: 36px; }
        .capacity-calendar-card .fc .fc-daygrid-day-frame { min-height: 66px; padding: 2px 0; }
        .capacity-calendar-card .fc .fc-daygrid-day-number { font-size: 12px; padding: 5px; }
        .capacity-calendar-card .fc .fc-daygrid-event { margin: 3px 0; padding: 2px 0; }
        .capacity-event { font-size: 10px; gap: 3px; }
        .capacity-event::before { width: 4px; height: 4px; }
    }
</style>

<style>
    .capacity-page .capacity-settings-card { position: relative; overflow: hidden; border: 1px solid rgba(212,175,55,.24); background: radial-gradient(ellipse at top right, rgba(212,175,55,.11), transparent 65%), var(--card-bg); box-shadow: 0 12px 32px rgba(0,0,0,.12); }
    .capacity-settings-card::before { content: ''; position: absolute; top: 0; left: 24px; right: 24px; height: 2px; background: linear-gradient(90deg, transparent, var(--accent-gold), transparent); }
    .capacity-page .capacity-settings-card { padding: 12px; }
    .capacity-settings-heading { display: flex; align-items: center; justify-content: flex-start; padding-bottom: 8px; margin-bottom: 8px; }
    .capacity-page .capacity-settings-heading h3 { font-size: 14px; }
    .capacity-settings-card .capacity-settings-heading p { display: none; }
    .capacity-settings-card form > div:last-child { margin-top: 8px !important; }
    .capacity-settings-heading h3 { margin: 0; font-weight: 600; letter-spacing: -.4px; }
    .capacity-settings-heading p { color: var(--text-muted); font-size: 12px; line-height: 1.5; margin: 5px 0 0; }
    .capacity-settings-card .booking-capacity-form > div { padding: 0; border: 0; background: transparent; }
    .capacity-settings-card label { display: block; margin-bottom: 4px; color: var(--text-muted); font-size: 11px; font-weight: 500; }
    .capacity-settings-card .form-control { box-sizing: border-box; font-size: 14px; font-weight: 500; letter-spacing: 0; min-height: 32px; height: 32px; padding: 5px 8px !important; border-radius: 8px; font-variant-numeric: tabular-nums; }
    .capacity-field-hint { margin: 3px 0 0; color: var(--text-muted); font-size: 10px; line-height: 1.3; }
    .capacity-settings-card .capacity-save { width: 100%; border-radius: 10px; min-height: 48px; font-weight: 600; letter-spacing: .2px; background: linear-gradient(110deg, #c5a134, #e2c565); color: #19160d; border: 1px solid rgba(235,210,130,.45); box-shadow: 0 4px 14px rgba(212,175,55,.1); transition: filter .2s, box-shadow .2s; }
    .capacity-settings-card .capacity-save:hover { filter: brightness(1.08); box-shadow: 0 6px 18px rgba(212,175,55,.24); }
    .capacity-settings-card .capacity-save:focus-visible { outline: 2px solid var(--accent-gold); outline-offset: 4px; }
    @media (max-width: 600px) {
        .capacity-page .capacity-settings-card { padding: 12px; }
        .capacity-settings-card .booking-capacity-form { gap: 10px; }
        .capacity-settings-card .booking-capacity-form > div { padding: 0; }
        .capacity-settings-heading { gap: 10px; }
        .capacity-page .capacity-settings-heading h3 { font-size: 14px; }
    }
    .capacity-settings-card .capacity-save { min-height: 32px; padding: 6px 10px; font-size: 11px; border-radius: 8px; }
</style>

<div class="capacity-page">

<?php if (isset($error) && $error): ?>
    <dialog id="capacity-error-modal" aria-labelledby="capacity-error-title" aria-describedby="capacity-error-description">
        <div class="capacity-error-heading">
            <h3 id="capacity-error-title"><i class="fas fa-exclamation-circle" aria-hidden="true"></i> Unable to save settings</h3>
            <button type="button" id="capacity-error-close" aria-label="Close message">&times;</button>
        </div>
        <p id="capacity-error-description"><?php echo htmlspecialchars($error); ?></p>
        <p id="capacity-error-countdown" role="status">Closing in 5 seconds.</p>
    </dialog>
    <style>
        #capacity-error-modal { box-sizing:border-box; width:min(440px, calc(100% - 32px)); margin:auto; padding:24px; border:1px solid var(--border-color); border-radius:18px; background:var(--card-bg); color:var(--text-main); box-shadow:0 20px 60px rgba(0,0,0,.25); max-height:calc(100dvh - 32px); overflow:auto; outline:none; }
        #capacity-error-modal::backdrop { background:rgba(16,30,23,.55); backdrop-filter:blur(3px); }
        .capacity-error-heading { display:flex; align-items:flex-start; justify-content:space-between; gap:12px; }
        .capacity-error-heading h3 { display:flex; align-items:center; gap:10px; margin:0; padding-top:10px; font-size:18px; line-height:1.4; }
        .capacity-error-heading h3 i { color:#a15c28; font-size:20px; flex-shrink:0; }
        #capacity-error-close { display:flex; align-items:center; justify-content:center; flex:0 0 44px; width:44px; height:44px; padding:0; border:0; border-radius:10px; background:transparent; color:inherit; font-size:26px; cursor:pointer; }
        #capacity-error-close:hover:enabled { background:rgba(23,61,50,.08); }
        #capacity-error-close:focus-visible { outline:2px solid var(--text-main); outline-offset:2px; }
        #capacity-error-close:disabled { opacity:.35; cursor:wait; }
        #capacity-error-description { font-size:14px; line-height:1.7; margin:14px 0 20px; overflow-wrap:anywhere; }
        #capacity-error-countdown { margin:0; padding-top:14px; border-top:1px solid var(--border-color); font-size:12px; line-height:1.5; color:var(--text-muted); }
        @media (max-width:480px) {
            #capacity-error-modal { width:calc(100% - 28px); padding:18px; border-radius:16px; }
            .capacity-error-heading { gap:6px; }
            .capacity-error-heading h3 { font-size:16px; gap:8px; }
            .capacity-error-heading h3 i { font-size:18px; }
            #capacity-error-description { margin:12px 0 16px; }
        }
    </style>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const modal = document.getElementById('capacity-error-modal');
            const close = document.getElementById('capacity-error-close');
            const countdown = document.getElementById('capacity-error-countdown');
            modal.addEventListener('cancel', event => event.preventDefault());
            close.addEventListener('click', () => modal.close());
            modal.showModal();
            const deadline = Date.now() + 5000;
            const timer = window.setInterval(function () {
                const remaining = Math.max(0, Math.ceil((deadline - Date.now()) / 1000));
                countdown.textContent = remaining ? `Closing in ${remaining} seconds.` : '';
                if (remaining === 0) {
                    window.clearInterval(timer);
                    modal.close();
                }
            }, 250);
            modal.addEventListener('close', () => window.clearInterval(timer), { once: true });
        });
    </script>
<?php endif; ?>

<div class="capacity-layout">
<div class="capacity-settings-card">
    <h2 class="capacity-settings-title">Booking Capacity</h2>

    <?php if ($is_store_closed_by_portal): ?>
        <p style="color: var(--text-muted); margin: 0;">Your account is currently locked by the portal, so booking settings cannot be changed right now.</p>
    <?php else: ?>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCSRFToken(), ENT_QUOTES, 'UTF-8'); ?>">
            <div class="booking-capacity-form">
                <div>
                    <label for="max_bookings_per_day">Daily capacity</label>
                    <input
                        type="number"
                        id="max_bookings_per_day"
                        name="max_bookings_per_day"
                        min="1"
                        max="100"
                        value="<?php echo $current_capacity; ?>"
                        class="form-control"
                        style="width: 100%; padding: 14px 16px; background: var(--primary-bg); color: var(--text-main); border: 1px solid var(--border-color); border-radius: 10px;"
                        required
                        title="Maximum number of bookings your team can handle per day."
                    >
                    <p class="capacity-field-hint">Bookings per day</p>
                </div>
                <div>
                    <label for="booking_gap_days">Lead time</label>
                    <input
                        type="number"
                        id="booking_gap_days"
                        name="booking_gap_days"
                        min="0"
                        max="365"
                        value="<?php echo $current_gap_days; ?>"
                        class="form-control"
                        style="width: 100%; padding: 14px 16px; background: var(--primary-bg); color: var(--text-main); border: 1px solid var(--border-color); border-radius: 10px;"
                        required
                        title="Number of days in advance a customer must book."
                    >
                    <p class="capacity-field-hint">Days before an event</p>
                </div>
                <div>
                    <label for="minimum_booking_guests">Minimum guests per booking</label>
                    <input type="text" id="minimum_booking_guests" name="minimum_booking_guests"
                        inputmode="numeric" pattern="[1-9][0-9]{0,4}|100000" maxlength="6" required
                        class="form-control" value="<?php echo (int) ($caterer['minimum_booking_guests'] ?? 1); ?>">
                    <p class="capacity-field-hint">Fewest guests you accept.</p>
                </div>
                <div>
                    <label for="maximum_booking_guests">Maximum guests per booking</label>
                    <input type="text" id="maximum_booking_guests" name="maximum_booking_guests"
                        inputmode="numeric" pattern="[1-9][0-9]{0,4}|100000" maxlength="6" required
                        class="form-control" value="<?php echo (int) ($caterer['maximum_booking_guests'] ?? 100000); ?>">
                    <p class="capacity-field-hint">Most guests you accept.</p>
                </div>
            </div>
            <div class="capacity-settings-actions">
                <button type="submit" name="save_booking_capacity" class="btn btn-primary capacity-save">
                    Save Settings
                </button>
            </div>
        </form>
    <?php endif; ?>
</div>

<link rel="stylesheet" href="../assets/css/caterer-dashboard-calendar.css">
<style>
    /* Desktop layout only; keep the existing mobile presentation. */
    @media (min-width: 993px) {
        .capacity-page .capacity-layout { grid-template-columns: minmax(0, 1fr); gap: 20px; }
        .capacity-page .capacity-settings-card { padding: 18px 24px; margin-bottom: 0; }
        .capacity-settings-card form { display: flex; align-items: center; gap: 24px; }
        .capacity-settings-card .booking-capacity-form { flex: 1; gap: 24px; }
        .capacity-settings-card label { font-size: 13px; margin-bottom: 6px; }
        .capacity-settings-card .form-control { min-height: 40px; height: 40px; font-size: 16px; }
        .capacity-settings-card .capacity-field-hint { font-size: 11px; margin-top: 5px; }
        .capacity-settings-card form > div:last-child { flex: 0 0 180px; margin-top: 0 !important; }
        .capacity-settings-card .capacity-save { min-height: 42px; font-size: 13px; }
        .capacity-page .capacity-calendar-card { margin-bottom: 0; }
        .capacity-page .capacity-calendar-card .calendar-heading-icon { display: none; }
        .capacity-page .capacity-calendar-card .calendar-heading { justify-content: flex-start; text-align: left; gap: 14px; padding-bottom: 16px; margin-bottom: 16px; }
        .capacity-page .capacity-calendar-card .calendar-heading > div:last-child { min-width: 0; }
        .capacity-page .capacity-calendar-card .capacity-legend { margin-bottom: 14px; }
        #booking-capacity-calendar .fc-toolbar.fc-header-toolbar { flex-wrap: wrap; margin-bottom: 16px; }
        .capacity-page .capacity-calendar-card .fc .fc-daygrid-day-frame { min-height: 76px; }
    }
</style>
<link rel="stylesheet" href="../assets/css/caterer-booking-capacity.css?v=<?php echo filemtime(__DIR__ . '/../assets/css/caterer-booking-capacity.css'); ?>">
<div class="capacity-calendar-card">
    <div class="card-header calendar-heading">
        <div><h3>Availability &amp; Holidays</h3></div>
    </div>
    <div class="capacity-legend">
        <span style="--status-color:#2ecc71">Available</span>
        <span style="--status-color:#f39c12">Almost full</span>
        <span style="--status-color:#e74c3c">Fully booked</span>
        <span style="--status-color:#b57ad3">Holiday</span>
    </div>
    <div id="booking-capacity-calendar"></div>
</div>
</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.js"></script>
<style>
    #capacity-day-modal { width: min(720px, calc(100% - 24px)); max-height: 85dvh; padding: 0; border: 1px solid var(--border-color); border-radius: 20px; background: var(--card-bg, #202020); color: var(--text-main, #eee); box-shadow: 0 24px 80px rgba(0,0,0,.5); }
    #capacity-day-modal::backdrop { background: rgba(0,0,0,.7); backdrop-filter: blur(5px); }
    .capacity-modal-heading { display: flex; align-items: center; justify-content: space-between; gap: 16px; padding: 18px 20px; border-bottom: 1px solid var(--border-color); }
    .capacity-modal-heading h3 { margin: 0; font-size: 18px; }
    .capacity-modal-close { display: grid; place-items: center; width: 44px; height: 44px; border: 1px solid var(--border-color); border-radius: 12px; background: transparent; color: inherit; font-size: 26px; cursor: pointer; }
    #capacity-day-modal #booking-capacity-summary { padding: 20px !important; }
    #capacity-day-modal { box-sizing: border-box; margin: auto; overflow-y: auto; overscroll-behavior: contain; }
    .capacity-modal-heading { position: sticky; top: 0; z-index: 1; background: var(--card-bg, #202020); }
    .capacity-modal-close { flex-shrink: 0; }
    .day-overview { padding: 18px; border: 1px solid rgba(212,175,55,.2); border-radius: 16px; background: linear-gradient(135deg, rgba(212,175,55,.1), transparent); }
    .day-eyebrow { color: var(--accent-gold); font-size: 11px; letter-spacing: 1.8px; text-transform: uppercase; }
    .day-overview h4 { margin: 7px 0 20px; font-size: clamp(21px, 5vw, 28px); line-height: 1.3; }
    .day-metrics { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); margin-bottom: 20px; }
    .day-metrics > div { padding: 0 8px; border-left: 1px solid var(--border-color); }
    .day-metrics > div:first-child { padding-left: 0; border: 0; }
    .day-metrics strong { display: block; font-size: 24px; line-height: 1.4; }
    .day-metrics small { font-size: 12px; color: var(--text-muted); }
    .day-metrics span { font-size: 11px; color: var(--text-muted); }
    .day-holiday { display: block; color: #d7a7eb; font-size: 12px; margin-bottom: 16px; }
    .day-holiday-action:disabled { opacity: .65; cursor: not-allowed; }
    .holiday-booking-note { color: #405747; font-size: 13px; line-height: 1.6; margin: 12px 0 0; }
    .day-holiday-action { width: 100%; min-height: 46px; justify-content: center; border-radius: 10px; }
    .day-list-heading { margin: 24px 0 12px; font-weight: 700; }
    .day-booking-list { display: grid; gap: 12px; }
    .day-booking-card { display: block; min-width: 0; padding: 16px; border: 1px solid var(--border-color); border-radius: 14px; background: rgba(128,128,128,.04); text-decoration: none; color: var(--text-main); }
    .day-booking-card:hover { border-color: var(--accent-gold); }
    .day-booking-card:focus-visible { outline: 2px solid var(--accent-gold); outline-offset: 3px; }
    .day-booking-top { display: flex; align-items: start; justify-content: space-between; flex-wrap: wrap; gap: 8px; }
    .day-order { font-size: 11px; color: var(--accent-gold); overflow-wrap: anywhere; }
    .day-status { font-size: 10px; padding: 3px 8px; border-radius: 6px; background: rgba(128,128,128,.12); text-transform: capitalize; }
    .day-customer { display: block; font-size: 17px; margin: 12px 0; overflow-wrap: anywhere; }
    .day-booking-meta { display: flex; flex-wrap: wrap; gap: 10px 20px; color: var(--text-muted); font-size: 12px; }
    .day-booking-meta i { color: var(--accent-gold); margin-right: 4px; }
    .day-view { display: flex; justify-content: space-between; border-top: 1px solid var(--border-color); padding-top: 12px; margin-top: 14px; font-size: 12px; color: var(--accent-gold); }
    .day-empty { padding: 24px 10px; text-align: center; color: var(--text-muted); }
    @media (max-width: 600px) {
        #capacity-day-modal { width: calc(100% - 20px); max-height: 90dvh; border-radius: 18px; }
        .capacity-modal-heading { padding: 12px 16px; }
        .capacity-modal-heading h3 { font-size: 16px; }
        #capacity-day-modal #booking-capacity-summary { padding: 16px !important; }
        .day-overview { padding: 14px; }
    }
</style>
<dialog id="holiday-confirm-modal" aria-labelledby="holiday-confirm-title" aria-describedby="holiday-confirm-description">
    <div class="holiday-confirm-heading">
        <h3 id="holiday-confirm-title">Mark as holiday?</h3>
        <button type="button" id="holiday-confirm-close" aria-label="Close confirmation">&times;</button>
    </div>
    <p id="holiday-confirm-description"></p>
    <p id="holiday-confirm-error" role="alert" hidden></p>
    <div class="holiday-confirm-actions">
        <button type="button" id="holiday-confirm-cancel">Cancel</button>
        <button type="button" id="holiday-confirm-save">Mark as holiday</button>
    </div>
</dialog>
<style>
    #holiday-confirm-modal { box-sizing:border-box; width:min(440px, calc(100% - 32px)); max-height:calc(100dvh - 32px); overflow:auto; margin:auto; padding:24px; border:1px solid var(--border-color); border-radius:18px; background:var(--card-bg); color:var(--text-main); box-shadow:0 24px 70px rgba(0,0,0,.25); outline:none; }
    #holiday-confirm-modal::backdrop { background:rgba(16,30,23,.55); backdrop-filter:blur(3px); }
    .holiday-confirm-heading { display:flex; align-items:center; justify-content:space-between; gap:12px; }
    .holiday-confirm-heading h3 { margin:0; font-size:20px; line-height:1.4; }
    #holiday-confirm-close { flex:0 0 44px; height:44px; border:0; background:transparent; color:inherit; font-size:28px; cursor:pointer; }
    #holiday-confirm-description { margin:12px 0 24px; font-size:14px; line-height:1.7; }
    .holiday-confirm-actions { display:flex; justify-content:flex-end; gap:10px; }
    .holiday-confirm-actions button { min-height:44px; padding:10px 18px; border:1px solid var(--border-color); border-radius:10px; background:transparent; color:inherit; font:inherit; font-size:14px; font-weight:600; cursor:pointer; }
    #holiday-confirm-save { background:#173d32; color:#f0f1e6; border-color:#173d32; }
    #holiday-confirm-modal button:disabled { opacity:.5; cursor:wait; }
    #holiday-confirm-modal button:focus-visible { outline:2px solid #496d53; outline-offset:3px; }
    #holiday-confirm-error { color:#a33426; font-size:13px; line-height:1.5; }
    @media (max-width:480px) { #holiday-confirm-modal { padding:18px; } .holiday-confirm-heading h3 { font-size:18px; } .holiday-confirm-actions button { flex:1; padding:10px; } }
</style>
<dialog id="capacity-day-modal" aria-labelledby="capacity-modal-title">
    <div class="capacity-modal-heading">
        <h3 id="capacity-modal-title">Booking details</h3>
        <button type="button" class="capacity-modal-close" aria-label="Close booking details">&times;</button>
    </div>
    <div id="booking-capacity-summary" aria-live="polite"></div>
</dialog>
<script>
const bookingCapacityEvents = <?php echo json_encode($calendar_events); ?>;
const bookingCapacitySummary = document.getElementById('booking-capacity-summary');
const capacityAccountLocked = <?php echo json_encode($is_store_closed_by_portal); ?>;
const capacityLeadDays = <?php echo max(0, (int) $current_gap_days); ?>;
function isCapacityLeadDate(dateStr) {
    const parts = new Intl.DateTimeFormat('en-US', {
        timeZone: 'Asia/Manila', year: 'numeric', month: '2-digit', day: '2-digit'
    }).formatToParts(new Date());
    const value = type => parts.find(part => part.type === type).value;
    const today = `${value('year')}-${value('month')}-${value('day')}`;
    const cutoff = new Date(today + 'T00:00:00Z');
    cutoff.setUTCDate(cutoff.getUTCDate() + capacityLeadDays);
    const day = String(dateStr).slice(0, 10);
    return day >= today && day < cutoff.toISOString().slice(0, 10);
}
const capacityDayModal = document.getElementById('capacity-day-modal');
let capacityModalPreviousOverflow = '';
capacityDayModal.querySelector('.capacity-modal-close').addEventListener('click', () => capacityDayModal.close());
capacityDayModal.addEventListener('click', function(event) {
    const bounds = capacityDayModal.getBoundingClientRect();
    if (event.target === capacityDayModal && (event.clientX < bounds.left || event.clientX > bounds.right || event.clientY < bounds.top || event.clientY > bounds.bottom)) capacityDayModal.close();
});
capacityDayModal.addEventListener('close', function() {
    document.body.style.overflow = capacityModalPreviousOverflow;
});
let capacityDayRequest;

function toggleHoliday(dateStr) {
    if (document.getElementById('day-holiday-action')?.disabled) return;
    if (capacityAccountLocked || isCapacityLeadDate(dateStr)) return;
    const modal = document.getElementById('holiday-confirm-modal');
    const removing = document.getElementById('day-holiday-action')?.textContent.includes('Remove');
    document.getElementById('holiday-confirm-title').textContent = removing ? 'Remove holiday?' : 'Mark as holiday?';
    const prettyDate = new Date(dateStr + 'T12:00:00').toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' });
    document.getElementById('holiday-confirm-description').textContent = removing
        ? `Allow bookings again on ${prettyDate}?`
        : `Mark ${prettyDate} as a holiday? Customers will be unable to book this date.`;
    const save = document.getElementById('holiday-confirm-save');
    const cancel = document.getElementById('holiday-confirm-cancel');
    const close = document.getElementById('holiday-confirm-close');
    const error = document.getElementById('holiday-confirm-error');
    error.hidden = true;
    save.textContent = removing ? 'Remove holiday' : 'Mark as holiday';
    save.disabled = cancel.disabled = close.disabled = false;
    cancel.onclick = close.onclick = () => modal.close();
    modal.oncancel = event => { if (save.disabled) event.preventDefault(); };
    save.onclick = async function () {
        save.disabled = cancel.disabled = close.disabled = true;
        save.textContent = 'Saving…';
        error.hidden = true;
        try {
            const response = await fetch(window.location.href, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=toggle_holiday&date=' + encodeURIComponent(dateStr)
            });
            const result = await response.json();
            if (!response.ok || !result.success) throw new Error(result.error || 'Unable to update this date. Please try again.');
            window.location.reload();
        } catch (failure) {
            error.textContent = failure.message || 'Unable to update this date. Please try again.';
            error.hidden = false;
            save.disabled = cancel.disabled = close.disabled = false;
            save.textContent = 'Try again';
        }
    };
    modal.showModal();
}

function loadDayBookings(dateStr) {
    if (isCapacityLeadDate(dateStr)) return;
    if (!capacityDayModal.open) {
        capacityModalPreviousOverflow = document.body.style.overflow;
        capacityDayModal.showModal();
        document.body.style.overflow = 'hidden';
    }
    if (capacityDayRequest) capacityDayRequest.abort();
    capacityDayRequest = new AbortController();
    bookingCapacitySummary.innerHTML = '<div style="padding:10px; color:var(--text-muted);">Loading bookings for ' + dateStr + '...</div>';
    
    fetch('get_day_bookings.php?date=' + encodeURIComponent(dateStr), { signal: capacityDayRequest.signal, cache: 'no-store' })
        .then(res => res.json())
        .then(data => {
            if (!data.success) {
                bookingCapacitySummary.innerHTML = '<div style="color:var(--danger);">' + (data.error || 'Failed to load.') + '</div>';
                return;
            }
            
            const escape = value => String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;'}[char]));
            const date = new Date(dateStr.slice(0, 10) + 'T12:00:00');
            const prettyDate = date.toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' });
            const weekday = date.toLocaleDateString('en-US', { weekday: 'long' });
            let html = '<div class="day-overview"><span class="day-eyebrow">' + escape(weekday) + '</span><h4>' + escape(prettyDate) + '</h4>';
            if (data.is_holiday) html += '<span class="day-holiday">Holiday · Bookings closed</span>';
            html += '<div class="day-metrics"><div><strong>' + escape(data.active_count) + '<small> / ' + escape(data.capacity) + '</small></strong><span>Booked</span></div>';
            html += '<div><strong>' + escape(data.slots_remaining) + '</strong><span>Slots left</span></div>';
            html += '<div><strong>' + escape(data.cancelled_count) + '</strong><span>Cancelled</span></div></div>';
            html += '<button type="button" id="day-holiday-action" class="btn btn-primary day-holiday-action" ' + ((capacityAccountLocked || (!data.is_holiday && Number(data.active_count) > 0)) ? 'disabled' : '') + '>' + (data.is_holiday ? 'Remove Holiday' : 'Mark as Holiday') + '</button>' + ((!data.is_holiday && Number(data.active_count) > 0) ? '<p class="holiday-booking-note">This date has bookings. Holidays can only be set on dates without active bookings.</p>' : '') + '</div>';
            html += '<div class="day-list-heading">Bookings · ' + data.bookings.length + '</div><div class="day-booking-list">';
            if (!data.bookings.length) html += '<div class="day-empty">No active bookings for this date.</div>';
            data.bookings.forEach(b => {
                html += '<a class="day-booking-card" href="order-details.php?id=' + encodeURIComponent(b.id) + '&capacity_date=' + encodeURIComponent(dateStr.slice(0, 10)) + '">';
                html += '<div class="day-booking-top"><span class="day-order">' + escape(b.order_number) + '</span><span class="day-status">' + escape(String(b.order_status ?? '').replace(/_/g, ' ')) + '</span></div>';
                html += '<strong class="day-customer">' + escape(b.customer_name) + '</strong>';
                html += '<div class="day-booking-meta"><span><i class="far fa-clock" aria-hidden="true"></i> ' + escape(b.time || 'Time pending') + '</span><span><i class="fas fa-users" aria-hidden="true"></i> ' + escape(b.pax) + ' guests</span></div>';
                html += '<div class="day-view">View booking <span aria-hidden="true">↗</span></div></a>';
            });
            html += '</div>';

            bookingCapacitySummary.innerHTML = html;
            document.getElementById('day-holiday-action').addEventListener('click', () => toggleHoliday(dateStr.slice(0, 10)));
        })
        .catch(err => {
            if (err.name === 'AbortError') return;
            bookingCapacitySummary.innerHTML = '<div style="color:var(--danger);">Error loading data.</div>';
        });
}

document.addEventListener('DOMContentLoaded', function() {
    const calendarEl = document.getElementById('booking-capacity-calendar');
    if (!calendarEl) {
        return;
    }

    const returnDate = new URLSearchParams(window.location.search).get('date');
    const validReturnDate = /^\d{4}-\d{2}-\d{2}$/.test(returnDate || '')
        && !Number.isNaN(new Date(returnDate + 'T12:00:00').getTime());
    const calendar = new FullCalendar.Calendar(calendarEl, {
        ...(validReturnDate ? { initialDate: returnDate } : {}),
        initialView: 'dayGridMonth',
        height: 'auto',
        fixedWeekCount: false,
        showNonCurrentDates: false,
        buttonText: { today: 'Today', month: 'Month', week: 'Week' },
        handleWindowResize: true,
        dayHeaderFormat: { weekday: 'short' },
        headerToolbar: {
            left: 'title',
            center: '',
            right: window.innerWidth < 600 ? 'prev,next today' : 'prev,next today dayGridMonth,timeGridWeek'
        },
        windowResize: function() {
            const compact = window.innerWidth < 600;
            if (compact && calendar.view.type !== 'dayGridMonth') calendar.changeView('dayGridMonth');
            calendar.setOption('headerToolbar', { left: 'title', center: '', right: compact ? 'prev,next today' : 'prev,next today dayGridMonth,timeGridWeek' });
        },
        events: bookingCapacityEvents,
        dayCellDidMount: function(info) {
            const day = info.el.getAttribute('data-date');
            if (day && isCapacityLeadDate(day)) {
                info.el.classList.add('capacity-lead-disabled');
                info.el.setAttribute('aria-disabled', 'true');
                info.el.title = 'Unavailable during the ' + capacityLeadDays + '-day lead time';
            }
        },
        eventContent: function(info) {
            const badge = document.createElement('span');
            badge.className = 'capacity-event';
            const status = info.event.extendedProps;
            badge.dataset.status = status.is_holiday ? 'holiday' : status.status;
            badge.textContent = info.event.extendedProps.is_holiday ? 'Holiday' : info.event.title;
            badge.title = info.event.extendedProps.is_holiday ? 'Holiday' : info.event.title + ' bookings';
            return { domNodes: [badge] };
        },
        dateClick: function(info) {
            if (isCapacityLeadDate(info.dateStr)) return;
            calendarEl.querySelectorAll('.capacity-selected').forEach(day => day.classList.remove('capacity-selected'));
            info.dayEl.classList.add('capacity-selected');
            loadDayBookings(info.dateStr);
        },
        eventClick: function(info) {
            if (isCapacityLeadDate(info.event.startStr)) return;
            loadDayBookings(info.event.startStr);
        }
    });

    calendar.render();
    if (validReturnDate && !isCapacityLeadDate(returnDate)) {
        const selectedDay = calendarEl.querySelector('[data-date="' + returnDate + '"]');
        if (selectedDay) selectedDay.classList.add('capacity-selected');
        loadDayBookings(returnDate);
    }
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

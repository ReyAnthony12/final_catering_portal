<?php
// dashboard.php - PORTAL ADMIN DASHBOARD
require_once __DIR__ . '/../config.php';
/** @var mysqli $conn */

requireLogin();

if (getUserRole() !== 'portal') {
    header('Location: ../login.php');
    exit();
}

$user_id = $_SESSION['user_id'];

// Handle AJAX request for getting registrations by date
if (isset($_GET['ajax_date'])) {
    $raw_date = trim((string) $_GET['ajax_date']);
    $date_obj = DateTime::createFromFormat('Y-m-d', $raw_date);
    if (!$date_obj || $date_obj->format('Y-m-d') !== $raw_date) {
        echo '<div class="portal-modal-header"><h2>Invalid Date</h2><button type="button" class="portal-close-btn" onclick="closePortalModal()">&times;</button></div><div class="portal-modal-body portal-modal-empty"><i class="fas fa-exclamation-triangle"></i><p>Invalid date format.</p></div>';
        exit();
    }
    $date = mysqli_real_escape_string($conn, $raw_date);

    function registrationAvatar(array $person, string $name): void {
        $path = str_replace('\\', '/', ltrim((string) ($person['profile_image'] ?? ''), '/'));
        $root = realpath(__DIR__ . '/..');
        $file = $path !== '' ? realpath($root . '/' . $path) : false;
        $has_image = $file && is_file($file) && strpos(str_replace('\\', '/', $file), str_replace('\\', '/', $root) . '/') === 0;
        echo '<span class="registration-avatar" aria-hidden="true">';
        echo htmlspecialchars(mb_strtoupper(mb_substr(trim($name), 0, 1)) ?: '?');
        if ($has_image) {
            echo '<img src="../' . htmlspecialchars($path, ENT_QUOTES, 'UTF-8') . '" alt="" onerror="this.remove()">';
        }
        echo '</span>';
    }

    // Get CATERER registrations for the selected date
    $store_registrations_query = "
        SELECT u.*, sd.business_name
        FROM users u 
        LEFT JOIN store_details sd ON u.id = sd.user_id 
        WHERE DATE(u.created_at) = '$date' 
        AND u.role = 'store'
        AND u.status = 'active'
        AND sd.approval_status = 'approved'
        ORDER BY u.created_at DESC
    ";
    $store_result = mysqli_query($conn, $store_registrations_query);

    // Get CUSTOMER registrations for the selected date
    $customer_registrations_query = "
        SELECT u.*
        FROM users u 
        WHERE DATE(u.created_at) = '$date' 
        AND u.role = 'customer'
        ORDER BY u.created_at DESC
    ";
    $customer_result = mysqli_query($conn, $customer_registrations_query);

    $total_stores = $store_result ? mysqli_num_rows($store_result) : 0;
    $total_customers = $customer_result ? mysqli_num_rows($customer_result) : 0;
    ?>

    <div class="portal-modal-header">
        <div class="registration-heading"><h2><?php echo date('F d, Y', strtotime($date)); ?></h2></div>
        <button type="button" class="portal-close-btn" onclick="closePortalModal()">&times;</button>
    </div>

    <div class="portal-modal-body">
        <?php if ($total_stores > 0): ?>
        <div class="table-container" style="margin-bottom: 30px;">
            <h3 style="margin-bottom: 15px; color: var(--accent-gold); display: flex; align-items: center; gap: 10px;">
                <i class="fas fa-utensils" aria-hidden="true"></i> Caterer Registrations <span class="registration-count"><?php echo $total_stores; ?></span>
            </h3>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Caterer Name</th>
                        <th>Contact Number</th>
                        <th>Registration Time</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($caterer = mysqli_fetch_assoc($store_result)): ?>
                        <tr>
                            <td data-label="Caterer" class="registration-name">
                                <div class="registration-identity">
                                    <?php registrationAvatar($caterer, $caterer['business_name'] ?? 'N/A'); ?>
                                    <strong><?php echo htmlspecialchars($caterer['business_name'] ?? 'N/A'); ?></strong>
                                </div>
                            </td>
                            <td data-label="Contact number"><?php echo htmlspecialchars($caterer['phone_number'] ?? 'N/A'); ?></td>
                            <td data-label="Registered">
                                <?php echo date('h:i A', strtotime($caterer['created_at'])); ?>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <?php if ($total_customers > 0): ?>
        <div class="table-container">
            <h3 style="margin-bottom: 15px; color: var(--success-color); display: flex; align-items: center; gap: 10px;">
                <i class="fas fa-users" aria-hidden="true"></i> Customer Registrations <span class="registration-count"><?php echo $total_customers; ?></span>
            </h3>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Customer Name</th>
                        <th>Contact Number</th>
                        <th>Registration Time</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($customer = mysqli_fetch_assoc($customer_result)): ?>
                        <tr>
                            <td data-label="Customer" class="registration-name">
                                <div class="registration-identity">
                                    <?php $customer_name = trim(($customer['first_name'] ?? '') . ' ' . ($customer['last_name'] ?? '')); ?>
                                    <?php registrationAvatar($customer, $customer_name); ?>
                                    <span><?php echo htmlspecialchars($customer_name); ?></span>
                                </div>
                            </td>
                            <td data-label="Contact number"><?php echo htmlspecialchars($customer['phone_number'] ?? 'N/A'); ?></td>
                            <td data-label="Registered">
                                <?php echo date('h:i A', strtotime($customer['created_at'])); ?>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <?php if ($total_stores == 0 && $total_customers == 0): ?>
            <div class="portal-modal-empty">
                <i class="fas fa-calendar-times"></i>
                <p>No registrations for this date.</p>
            </div>
        <?php endif; ?>
    </div>
    <?php
    exit();
}

// Get Dashboard Stats
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS portal_account_trash (
    user_id INT NOT NULL PRIMARY KEY,
    deleted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB");
// Count approved accounts only; pending registrations have their own counter.
$stats_query = "
    SELECT
        COALESCE(SUM(u.role IN ('store', 'caterer') AND u.status = 'active' AND EXISTS (
            SELECT 1 FROM store_details sd WHERE sd.user_id = u.id AND sd.approval_status = 'approved'
        )), 0) as total_stores,
        COALESCE(SUM(u.role IN ('store', 'caterer') AND u.status = 'pending'), 0) as pending_stores,
        COALESCE(SUM(u.role IN ('store', 'caterer') AND u.status = 'active' AND EXISTS (
            SELECT 1 FROM store_details sd WHERE sd.user_id = u.id AND sd.approval_status = 'approved'
        )), 0) as active_stores,
        COALESCE(SUM(u.role = 'customer' AND u.status = 'active'), 0) as total_customers,
        COALESCE(SUM(u.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) AND u.status = 'active'
            AND (u.role = 'customer' OR EXISTS (
                SELECT 1 FROM store_details sd WHERE sd.user_id = u.id AND sd.approval_status = 'approved'
            ))), 0) as new_users_last_7_days
    FROM users u
    WHERE u.role IN ('store', 'caterer', 'customer')
      AND u.id NOT IN (SELECT user_id FROM portal_account_trash)
";
$stats_result = mysqli_query($conn, $stats_query);
if (isset($_GET['ajax_stats'])) {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    if (!$stats_result) {
        http_response_code(500);
        echo json_encode(['error' => 'Unable to load totals.']);
    } else {
        echo json_encode(array_map('intval', mysqli_fetch_assoc($stats_result)));
    }
    exit();
}
$stats = $stats_result ? mysqli_fetch_assoc($stats_result) : [
    'total_stores' => 0,
    'pending_stores' => 0,
    'active_stores' => 0,
    'total_customers' => 0,
    'new_users_last_7_days' => 0,
];

// Approved caterers belong on their original registration date, including older months.
$calendar_events_query = "
    SELECT 
        u.id,
        u.first_name,
        u.last_name,
        u.role,
        u.created_at,
        u.status,
        sd.business_name
    FROM users u 
    LEFT JOIN store_details sd ON u.id = sd.user_id 
    WHERE u.role = 'customer'
       OR (u.role = 'store' AND u.status = 'active' AND sd.approval_status = 'approved')
    ORDER BY u.created_at DESC
";
$calendar_events_result = mysqli_query($conn, $calendar_events_query);

// Prepare events array for calendar
$events = [];
while ($event = mysqli_fetch_assoc($calendar_events_result)) {
    $event_date = date('Y-m-d', strtotime($event['created_at']));
    
    if ($event['role'] === 'store') {
        $title = "Caterer: " . (trim($event['business_name'] ?? '') ?: trim($event['first_name'] . ' ' . $event['last_name']));
        $color = '#d4af37'; // Gold for stores
    } else {
        $title = "Customer: " . $event['first_name'] . ' ' . $event['last_name'];
        $color = '#4caf50'; // Green for customers
    }
    
    // Add status indicator
    if ($event['status'] === 'pending') {
        $color = '#ff9800'; // Orange for pending
    } elseif ($event['status'] === 'closed') {
        $color = '#f44336'; // Red for closed
    }
    
    $events[] = [
        'id' => $event['id'],
        'title' => $title,
        'start' => $event_date,
        'color' => $color,
        'role' => $event['role']
    ];
}

if (isset($_GET['ajax_calendar'])) {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode($events, JSON_INVALID_UTF8_SUBSTITUTE);
    exit();
}

$events_json = json_encode($events, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE);

// Header Configuration
$page_title = 'Portal Dashboard - Catering Portal';
$extra_head = '
    <link rel="stylesheet" href="dashboard-premium.css?v=7">
    <link rel="stylesheet" href="registration-responsive.css?v=20261022-4">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.css">
    <style>
        /* FullCalendar Overrides for Dark Theme */
        .fc { color: var(--text-main); }
        .fc-theme-standard .fc-scrollgrid, .fc-theme-standard td, .fc-theme-standard th { border-color: var(--border-color); }
        .fc-daygrid-day-number { color: var(--text-muted); }
        .fc-col-header-cell-cushion { color: var(--text-main); }
        .fc-button-primary { background-color: var(--secondary-bg) !important; border-color: var(--border-color) !important; color: var(--text-main) !important; }
        .fc-button-primary:hover, .fc-button-primary.fc-button-active { background-color: var(--accent-gold) !important; border-color: var(--accent-gold) !important; color: #000 !important; }
        .fc-day-today { background-color: rgba(212, 175, 55, 0.05) !important; }
        @media (max-width: 992px) {
            .main-content.portal-dashboard {
                min-width: 0;
                width: 100%;
                max-width: 100%;
            }
            .portal-dashboard .fc .fc-toolbar {
                flex-wrap: wrap;
                justify-content: center;
                gap: 10px;
            }
        }
        @media (max-width: 576px) {
            .portal-dashboard .fc .fc-toolbar {
                flex-direction: column;
            }
            .portal-dashboard .fc .fc-toolbar-title {
                font-size: 20px;
            }
            .stats-grid.dashboard-stats {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 10px;
                margin-bottom: 20px;
            }
            .dashboard-stats .stat-card {
                min-width: 0;
                padding: 12px;
                border-radius: 10px;
            }
            .dashboard-stats .stat-header {
                gap: 6px;
                align-items: flex-start;
                margin-bottom: 8px;
            }
            .dashboard-stats .stat-header h3 {
                font-size: 11px;
                line-height: 1.4;
                letter-spacing: 0;
                overflow-wrap: anywhere;
            }
            .dashboard-stats .stat-icon {
                width: 24px;
                height: 24px;
                flex-shrink: 0;
                border-radius: 6px;
                font-size: 12px;
            }
            .dashboard-stats .stat-value {
                font-size: 24px;
                line-height: 1.2;
                margin-bottom: 4px;
                overflow-wrap: anywhere;
            }
            .dashboard-stats .stat-desc {
                font-size: 11px;
                line-height: 1.4;
                overflow-wrap: anywhere;
            }
        }
    </style>
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
?>

    <div class="main-content portal-dashboard">
        
        <!-- Stats Grid -->
        <div class="stats-grid dashboard-stats">
            <div class="stat-card gold">
                <div class="stat-header">
                    <h3>Total Caterers</h3>
                </div>
                <div class="stat-value" data-account-stat="total_stores"><?php echo $stats['total_stores']; ?></div>
            </div>
            
            <div class="stat-card orange">
                <div class="stat-header">
                    <h3>Pending Approvals</h3>
                </div>
                <div class="stat-value" data-account-stat="pending_stores"><?php echo $stats['pending_stores']; ?></div>
            </div>
            
            <div class="stat-card green">
                <div class="stat-header">
                    <h3>Total Customers</h3>
                </div>
                <div class="stat-value" data-account-stat="total_customers"><?php echo $stats['total_customers']; ?></div>
            </div>
            
            <div class="stat-card blue">
                <div class="stat-header">
                    <h3>New Users</h3>
                </div>
                <div class="stat-value" data-account-stat="new_users_last_7_days"><?php echo $stats['new_users_last_7_days']; ?></div>
            </div>
        </div>

        <!-- Calendar -->
        <div class="content-body dashboard-calendar">
            <div class="dashboard-calendar-heading">
                <div><h2>Registration Calendar</h2></div>
            </div>
            <div id="calendar"></div>
        </div>
    </div>
    
    <!-- Portal Modal Container for Calendar (Hidden by default) -->
    <div id="portalRegistrationsModal" class="portal-modal">
        <div class="portal-modal-content" id="portalModalContent">
            <!-- Portal modal content will be loaded here -->
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.js"></script>
    <script>
        // Dashboard initialization function
        function initDashboard() {
            var calendarEl = document.getElementById('calendar');
            var portalRegistrationsModal = document.getElementById('portalRegistrationsModal');
            var portalModalContent = document.getElementById('portalModalContent');
            
            // Parse PHP events data
            var eventsData = <?php echo $events_json; ?>;
            
            if (calendarEl) {
                var calendarStateKey = 'portal-calendar:<?php echo (int) $_SESSION['user_id']; ?>';
                var savedCalendarDate;
                try { savedCalendarDate = sessionStorage.getItem(calendarStateKey); } catch (_) {}
                var calendar = new FullCalendar.Calendar(calendarEl, {
                    initialView: 'dayGridMonth',
                    timeZone: 'local',
                    initialDate: savedCalendarDate || undefined,
                    datesSet: function(info) {
                        try { sessionStorage.setItem(calendarStateKey, info.view.currentStart.getFullYear() + '-' + String(info.view.currentStart.getMonth() + 1).padStart(2, '0') + '-01'); } catch (_) {}
                    },
                    height: '100%',
                    fixedWeekCount: false,
                    dayMaxEvents: 2,
                    eventDisplay: 'block',
                    moreLinkClick: function(info) {
                        info.jsEvent.preventDefault();
                        info.jsEvent.stopPropagation();
                        // Use the same readable dialog for crowded dates, on every screen size.
                        var date = info.date;
                        var dateKey = date.getFullYear() + '-' +
                            String(date.getMonth() + 1).padStart(2, '0') + '-' +
                            String(date.getDate()).padStart(2, '0');
                        loadRegistrationsForDate(dateKey);
                        // FullCalendar falls back to its popover on a falsy return value.
                        // A truthy non-view value suppresses that built-in popup.
                        return true;
                    },
                    buttonText: { today: 'Today', month: 'Month', week: 'Week', day: 'Day' },
                    headerToolbar: {
                        left: 'prev,next today',
                        center: 'title',
                        right: 'dayGridMonth,timeGridWeek,timeGridDay'
                    },
                    events: eventsData,
                    dateClick: function(info) {
                        // Load registrations for selected date via AJAX
                        loadRegistrationsForDate(info.dateStr.substring(0, 10));
                    },
                    eventClick: function(info) {
                        loadRegistrationsForDate(info.event.startStr.substring(0, 10));
                    },
                    eventDidMount: function(info) {
                        // Add tooltip with registration info
                        info.el.title = info.event.title;
                    }
                });
                
                calendar.render();
                let calendarSignature = JSON.stringify(eventsData), calendarLoading = false;
                async function refreshRegistrations() {
                    if (document.hidden || calendarLoading) return;
                    calendarLoading = true;
                    try {
                        const response = await fetch('dashboard.php?ajax_calendar=1', {cache:'no-store', credentials:'same-origin'});
                        if (!response.ok) return;
                        const events = await response.json();
                        if (!Array.isArray(events)) return;
                        const signature = JSON.stringify(events);
                        if (signature !== calendarSignature) {
                            calendar.batchRendering(() => { calendar.removeAllEvents(); calendar.addEventSource(events); });
                            calendarSignature = signature;
                        }
                    } catch (_) {} finally { calendarLoading = false; }
                }
                setInterval(refreshRegistrations, 10000);
                window.addEventListener('focus', refreshRegistrations);

                // Keep controls and event density usable when rotating or resizing a device.
                var mobileCalendar = window.matchMedia('(max-width: 640px)');
                function adaptCalendar() {
                    calendar.setOption('headerToolbar', mobileCalendar.matches
                        ? { left: 'prev,next', center: 'title', right: 'today' }
                        : { left: 'prev,next today', center: 'title', right: 'dayGridMonth,timeGridWeek,timeGridDay' });
                    calendar.setOption('dayMaxEvents', 1);
                    calendar.updateSize();
                }
                adaptCalendar();
                mobileCalendar.addEventListener('change', adaptCalendar);
                // Recalculate the grid when the available workspace changes size.
                new ResizeObserver(function() { calendar.updateSize(); }).observe(calendarEl);
            }
            
            function loadRegistrationsForDate(date) {
                // Show loading in portal modal
                portalModalContent.innerHTML = `
                    <div class="portal-modal-header">
                        <h2>Loading...</h2>
                        <button type="button" class="portal-close-btn" onclick="closePortalModal()">&times;</button>
                    </div>
                    <div class="portal-modal-body" style="text-align: center; padding: 60px 20px;">
                        <i class="fas fa-spinner fa-spin fa-3x" style="color: var(--accent-gold);"></i>
                        <p style="margin-top: 20px; color: var(--text-muted);">Loading registrations...</p>
                    </div>
                `;
                
                // Show portal modal with centered positioning
                portalRegistrationsModal.classList.add('active');
                
                // Create AJAX request
                var xhr = new XMLHttpRequest();
                xhr.open('GET', 'dashboard.php?ajax_date=' + date, true);
                
                xhr.onload = function() {
                    if (this.status === 200) {
                        portalModalContent.innerHTML = this.responseText;
                    } else {
                        portalModalContent.innerHTML = `
                            <div class="portal-modal-header">
                                <h2>Error</h2>
                                <button type="button" class="portal-close-btn" onclick="closePortalModal()">&times;</button>
                            </div>
                            <div class="portal-modal-body portal-modal-empty">
                                <i class="fas fa-exclamation-triangle"></i>
                                <p>Error loading registrations. Please try again.</p>
                            </div>
                        `;
                    }
                };
                
                xhr.onerror = function() {
                    portalModalContent.innerHTML = `
                        <div class="portal-modal-header">
                            <h2>Error</h2>
                            <button type="button" class="portal-close-btn" onclick="closePortalModal()">&times;</button>
                        </div>
                        <div class="portal-modal-body portal-modal-empty">
                            <i class="fas fa-wifi"></i>
                            <p>Network error. Please check your connection.</p>
                        </div>
                    `;
                };
                
                xhr.send();
            }
            
            // Dismiss registrations only through the explicit close button.
            window.closePortalModal = function() {
                portalRegistrationsModal.classList.remove('active');
            };
        }
        
        // Keep totals current without interrupting the calendar or open modal.
        let accountStatsLoading = false;
        async function refreshAccountStats() {
            if (document.hidden || accountStatsLoading) return;
            accountStatsLoading = true;
            try {
                const response = await fetch('dashboard.php?ajax_stats=1', {
                    credentials: 'same-origin', cache: 'no-store'
                });
                if (!response.ok) return;
                const stats = await response.json();
                document.querySelectorAll('[data-account-stat]').forEach(element => {
                    const value = stats[element.dataset.accountStat];
                    if (Number.isInteger(value) && value >= 0) element.textContent = value;
                });
            } catch (error) {
                // Retain the last successful values until the connection recovers.
            } finally {
                accountStatsLoading = false;
            }
        }
        setInterval(refreshAccountStats, 5000);
        window.addEventListener('focus', refreshAccountStats);
        document.addEventListener('visibilitychange', refreshAccountStats);

        // Initialize dashboard on page load
        document.addEventListener('DOMContentLoaded', function() {
            initDashboard();
        });
    </script>
<?php require __DIR__ . '/includes/footer-bar.php'; ?>
</body>
</html>

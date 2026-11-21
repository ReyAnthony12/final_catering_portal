<?php
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/inventory_return_helper.php';
require_once __DIR__ . '/../includes/order_reference_helper.php';
/** @var mysqli $conn */

// Check login
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['store', 'caterer'])) {
    header('Location: ../login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$page_title = 'Event Preparation';
$current_page = 'event_preparation.php';

function preparationUnlockDate(string $eventDate): DateTimeImmutable
{
    return (new DateTimeImmutable(substr($eventDate, 0, 10), new DateTimeZone('Asia/Manila')))->modify('-1 day')->setTime(0, 0);
}

// Track saved return checklists independently of the booking's completion status.
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS event_return_records (
    event_id INT NOT NULL PRIMARY KEY,
    saved_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB");

// ==========================================
// AUTO-SYNC: Create Events for Confirmed Orders if missing
// ==========================================
$sync_query = "
    SELECT o.id, o.store_id, o.product_id, o.number_of_pax, o.total_amount, o.order_date, o.order_status, o.additional_menu,
           u.first_name, u.last_name, p.name as service_name
    FROM bookings o
    JOIN users u ON o.customer_id = u.id
    JOIN products p ON o.product_id = p.id
    LEFT JOIN events e ON o.id = e.order_id
    WHERE o.store_id = '$user_id'
    AND o.order_status IN ('confirmed', 'preparing', 'ready', 'delivering')
    AND e.id IS NULL
";

$sync_res = mysqli_query($conn, $sync_query);
if ($sync_res && mysqli_num_rows($sync_res) > 0) {
    while ($row = mysqli_fetch_assoc($sync_res)) {
        $order_id = $row['id'];
        $cust_name = mysqli_real_escape_string($conn, $row['first_name'] . ' ' . $row['last_name']);
        $service = mysqli_real_escape_string($conn, $row['service_name']);
        $status = ($row['order_status'] == 'confirmed') ? 'Reserved' : 'Prepared';
        $pax = intval($row['number_of_pax']);
        $product_id = $row['product_id'];
        $selected_set_name = trim((string) ($row['additional_menu'] ?? ''));
        
        // 1. Create Event Record
        $ins = "INSERT INTO events (store_id, order_id, customer_name, service_type, pax, price, event_date, status)
                VALUES ('$user_id', '$order_id', '$cust_name', '$service', '$pax', '{$row['total_amount']}', '{$row['order_date']}', '$status')";
        
        if (mysqli_query($conn, $ins)) {
            $event_id = mysqli_insert_id($conn);
            
            // 2. Populate Event Inventory Checklist (based on Service Package)
            $mapping_filter = "AND (si.set_name IS NULL OR si.set_name = '')";
            if ($selected_set_name !== '') {
                $selected_set_name_esc = mysqli_real_escape_string($conn, $selected_set_name);
                $specific_map_check = mysqli_query($conn, "SELECT id FROM service_inventory WHERE service_id = '$product_id' AND set_name = '$selected_set_name_esc' LIMIT 1");
                if ($specific_map_check && mysqli_num_rows($specific_map_check) > 0) {
                    $mapping_filter = "AND si.set_name = '$selected_set_name_esc'";
                }
            }

            $mappings = mysqli_query($conn, "SELECT si.*, ii.item_name 
                                           FROM service_inventory si 
                                           JOIN inventory_items ii ON si.inventory_item_id = ii.id 
                                           WHERE si.service_id = '$product_id' $mapping_filter");
            
            while ($map = mysqli_fetch_assoc($mappings)) {
                $qty_needed = max(0, (int) $map['quantity_required']);
                if (!empty($map['is_pax_dependent']) && $qty_needed > 0) {
                    $qty_needed = max(0, $pax);
                }
                $item_name = mysqli_real_escape_string($conn, $map['item_name']);
                
                // Insert into event_inventory
                mysqli_query($conn, "INSERT INTO event_inventory (event_id, item_name, quantity_required) 
                                     VALUES ('$event_id', '$item_name', '$qty_needed')");
            }
        }
    }
}

// STAGE 5: EVENT DAY FLOW - ACTIONS
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $event_id = intval($_POST['event_id']);
    $event_reference = 'Unavailable';
    $reference_result = mysqli_query($conn, "SELECT order_id FROM events WHERE id = '$event_id' AND store_id = '$user_id'");
    if ($reference_result && ($reference_row = mysqli_fetch_assoc($reference_result))) {
        $event_reference = formatCatererBookingNumber($reference_row['order_id'], '', $user_id);
    }
    $action = $_POST['action'];
    $eventQuery = mysqli_query($conn, "SELECT status, event_date FROM events WHERE id = '$event_id' AND store_id = '$user_id'");
    $currentEvent = mysqli_fetch_assoc($eventQuery);
    if (!$currentEvent) {
        http_response_code(404);
        exit('Event not found.');
    }
    
    if ($action === 'prepare' && new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')) < preparationUnlockDate($currentEvent['event_date'])) {
        $error_msg = 'Stock preparation is available starting one day before the event.';
    } elseif ($action === 'prepare' && $currentEvent['status'] !== 'Reserved') {
        $error_msg = 'Only reserved events can be prepared.';
    } elseif ($action === 'add_item') {
        $event_id = intval($_POST['event_id']);
        $item_name = mysqli_real_escape_string($conn, $_POST['item_name']);
        $qty = intval($_POST['quantity']);
        
        if ($qty > 0) {
            // Check availability
            $check = mysqli_query($conn, "SELECT available_stock FROM inventory_items WHERE item_name = '$item_name' AND store_id = '$user_id'");
            $stock = mysqli_fetch_assoc($check);
            
            if ($stock && $stock['available_stock'] >= $qty) {
                // Deduct Stock
                mysqli_query($conn, "UPDATE inventory_items SET available_stock = available_stock - '$qty' WHERE item_name = '$item_name' AND store_id = '$user_id'");
                
                // Add to Event Inventory
                // Check if already exists in event
                $check_event = mysqli_query($conn, "SELECT id, quantity_required FROM event_inventory WHERE event_id = '$event_id' AND item_name = '$item_name'");
                if ($row = mysqli_fetch_assoc($check_event)) {
                    mysqli_query($conn, "UPDATE event_inventory SET quantity_required = quantity_required + '$qty' WHERE id = '{$row['id']}'");
                } else {
                    mysqli_query($conn, "INSERT INTO event_inventory (event_id, item_name, quantity_required) VALUES ('$event_id', '$item_name', '$qty')");
                }
                
                $success_msg = "Added $qty x $item_name to Event #$event_reference.";
            } else {
                $error_msg = "Insufficient stock for $item_name.";
            }
        }
    } elseif ($action === 'prepare') {
        // Mark as Prepared
        mysqli_query($conn, "UPDATE events SET status = 'Prepared' WHERE id = '$event_id'");
        
        // Mark inventory as Released
        mysqli_query($conn, "UPDATE event_inventory SET quantity_released = quantity_required WHERE event_id = '$event_id'");
        
        // (Optional: Deduct from main inventory if not done at booking. 
        // But Stage 3 says "When booking created... compute inventory". 
        // Usually 'Reserved' means stock is held. So we assume it's already deducted/reserved.)
        
        $success_msg = "Event #$event_reference marked as PREPARED. Items released.";
        
    } elseif ($action === 'complete_returns' || $action === 'update_returns') {
        mysqli_begin_transaction($conn);
        try {
        $lockedEvent = mysqli_query($conn, "SELECT status FROM events WHERE id = '$event_id' AND store_id = '$user_id' FOR UPDATE");
        $currentEvent = mysqli_fetch_assoc($lockedEvent);
        $is_update = ($currentEvent['status'] === 'Completed');
        
        // 1. Mark Event as Completed (if not already)
        if (!$is_update) {
            mysqli_query($conn, "UPDATE events SET status = 'Completed' WHERE id = '$event_id'");
        }
        
        $returns = $_POST['returns'] ?? []; // Array of [event_inventory_id => qty_returned]
        
        foreach ($returns as $ei_id => $qty_returned) {
            $ei_id = intval($ei_id);
            $qty_returned = intval($qty_returned);
            
            // Fetch current state
            $q = mysqli_query($conn, "SELECT ei.quantity_required, ei.quantity_returned, ei.item_name, ii.item_type, ii.id as inv_item_id 
                                      FROM event_inventory ei 
                                      LEFT JOIN inventory_items ii ON ii.id = (
                SELECT matched.id FROM inventory_items matched
                WHERE matched.item_name = ei.item_name AND matched.store_id = '$user_id'
                ORDER BY (matched.item_type = 'consumable') DESC, matched.id DESC LIMIT 1
            )
                                      WHERE ei.id = '$ei_id' AND ei.event_id = '$event_id'");
            $item = mysqli_fetch_assoc($q);
            
            if ($item && $item['item_type'] === 'equipment') {
                $borrowed = $item['quantity_required'];
                $current_returned = $item['quantity_returned'];
                $inv_item_id = $item['inv_item_id'];
                
                // Validation
                if ($qty_returned > $borrowed) $qty_returned = $borrowed;
                if ($qty_returned < 0) $qty_returned = 0;
                
                if ($is_update) {
                    // Calculate Delta
                    $delta = $qty_returned - $current_returned;
                    
                    // Update Inventory
                    if ($delta != 0) {
                        mysqli_query($conn, "UPDATE inventory_items SET available_stock = available_stock + '$delta', damaged_stock = damaged_stock - '$delta' 
                                             WHERE id = '$inv_item_id'");
                    }
                } else {
                    // First time completion
                    // Available increases by Returned
                    // Damaged increases by (Borrowed - Returned)
                    $missing = $borrowed - $qty_returned;
                    mysqli_query($conn, "UPDATE inventory_items SET available_stock = available_stock + '$qty_returned', damaged_stock = damaged_stock + '$missing' 
                                         WHERE id = '$inv_item_id'");
                }
                
                // Update Event Inventory Record
                mysqli_query($conn, "UPDATE event_inventory SET quantity_returned = '$qty_returned' WHERE id = '$ei_id'");
            }
        }
        // Consumables are processed automatically, without return inputs or stock credits.
        mysqli_query($conn, "UPDATE event_inventory ei
            JOIN inventory_items ii ON ii.id = (
                SELECT matched.id FROM inventory_items matched
                WHERE matched.item_name = ei.item_name AND matched.store_id = '$user_id'
                ORDER BY (matched.item_type = 'consumable') DESC, matched.id DESC LIMIT 1
            )
            SET ei.quantity_returned = ei.quantity_required
            WHERE ei.event_id = '$event_id' AND ii.item_type = 'consumable'");
        
        if (!mysqli_query($conn, "INSERT INTO event_return_records (event_id) VALUES ('$event_id')
            ON DUPLICATE KEY UPDATE saved_at = CURRENT_TIMESTAMP")) {
            throw new RuntimeException('Unable to record saved returns.');
        }
        mysqli_commit($conn);
        $returns_saved = true;
        $success_msg = "Returns saved for Event #$event_reference. Moved to Returned.";
        } catch (Throwable $error) {
            mysqli_rollback($conn);
            error_log('Save event returns: ' . $error->getMessage());
            $error_msg = 'Returns could not be saved. Please try again.';
        }
    } elseif ($action === 'complete_legacy' && $currentEvent['status'] !== 'Completed') {
        // Mark as Completed (Fallback if not using new flow)
        mysqli_query($conn, "UPDATE events SET status = 'Completed' WHERE id = '$event_id'");
        
        // Return Inventory (Assume FULL RETURN)
        $inv_q = mysqli_query($conn, "SELECT ei.*, ii.item_type 
                                      FROM event_inventory ei 
                                      LEFT JOIN inventory_items ii ON ii.id = (
                SELECT matched.id FROM inventory_items matched
                WHERE matched.item_name = ei.item_name AND matched.store_id = '$user_id'
                ORDER BY (matched.item_type = 'consumable') DESC, matched.id DESC LIMIT 1
            )
                                      WHERE ei.event_id = '$event_id'");
        
        while ($item = mysqli_fetch_assoc($inv_q)) {
            $qty = $item['quantity_required'];
            $name = mysqli_real_escape_string($conn, $item['item_name']);
            $type = $item['item_type'];
            
            // Update Event Inventory (Mark as processed/returned)
            mysqli_query($conn, "UPDATE event_inventory SET quantity_returned = '$qty' WHERE id = '{$item['id']}'");
            
            // Return to Main Stock ONLY if Equipment
            if ($type === 'equipment') {
                mysqli_query($conn, "UPDATE inventory_items SET available_stock = available_stock + '$qty' 
                                     WHERE item_name = '$name' AND store_id = '$user_id'");
            }
        }
        
        $success_msg = "Event #$event_reference marked as COMPLETED. Items returned to stock.";
    }
}

// Keep booking usage in step with the completed return checklist.
syncCompletedInventoryUsage($conn, (int) $user_id);

// Fetch Events
$where_clause = "WHERE events.store_id = '$user_id' AND EXISTS (SELECT 1 FROM event_inventory WHERE event_id = events.id)
    AND (events.order_id IS NULL OR NOT EXISTS (
        SELECT 1 FROM events newer
        WHERE newer.store_id = events.store_id AND newer.order_id = events.order_id
        AND newer.id > events.id
        AND EXISTS (SELECT 1 FROM event_inventory WHERE event_id = newer.id)
    ))";
if (isset($_GET['order_id'])) {
    $filter_order_id = intval($_GET['order_id']);
    $where_clause .= " AND events.order_id = '$filter_order_id'";
}

$events_query = "SELECT events.*, bookings.order_time, customer.profile_image AS customer_profile_image,
    EXISTS (SELECT 1 FROM event_return_records rr WHERE rr.event_id = events.id) AS returns_saved FROM events
    LEFT JOIN bookings ON bookings.id = events.order_id AND bookings.store_id = events.store_id
    LEFT JOIN users customer ON customer.id = bookings.customer_id
    $where_clause ORDER BY events.event_date ASC, bookings.order_time IS NULL ASC, bookings.order_time ASC, events.id ASC";
$events_result = mysqli_query($conn, $events_query);

// Fetch Inventory Items for Manual Addition
$inventory_items = [];
$inv_res = mysqli_query($conn, "SELECT item_name, available_stock FROM inventory_items WHERE store_id = '$user_id' ORDER BY item_name ASC");
while ($row = mysqli_fetch_assoc($inv_res)) {
    $inventory_items[] = $row;
}

require_once __DIR__ . '/includes/header.php';
?>

<style>
    /* Add Modal Styles */
    .modal-overlay {
        display: none;
        position: fixed;
        top: 0; left: 0;
        width: 100%; height: 100%;
        background: rgba(0,0,0,0.7);
        z-index: 1000;
        justify-content: center;
        align-items: center;
    }
    .modal-content {
        background: var(--card-bg);
        padding: 20px;
        border-radius: 8px;
        width: 400px;
        border: 1px solid var(--border-color);
    }

    .event-toolbar {
        display: flex;
        gap: 10px;
    }

    .event-card-body {
        padding: 20px;
        display: grid;
        grid-template-columns: 1fr 2fr;
        gap: 20px;
    }

    .event-table-wrap {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }

    @media (max-width: 768px) {
        .event-toolbar,
        .content-header {
            flex-direction: column;
            align-items: flex-start;
        }

        .event-toolbar {
            width: 100%;
        }

        .event-toolbar .btn {
            width: 100%;
        }

        .event-card-body {
            grid-template-columns: 1fr;
            padding: 16px;
        }

        .event-table-wrap table {
            min-width: 0;
        }

        .modal-content {
            width: min(100%, calc(100vw - 24px)) !important;
            max-height: calc(100vh - 24px);
            overflow-y: auto;
        }
    }
</style>

<link rel="stylesheet" href="../assets/css/caterer-event-preparation.css?v=<?php echo filemtime(__DIR__ . '/../assets/css/caterer-event-preparation.css'); ?>">
<section class="event-preparation-page">
<div class="content-header">
    <a href="reservations.php" class="prep-back-button" aria-label="Back to reservations" title="Back to reservations"><i class="fas fa-arrow-left" aria-hidden="true"></i></a>
    <div class="event-toolbar" role="group" aria-label="Filter events">
        <button type="button" onclick="filterEvents('active')" aria-pressed="true" id="btn-active" class="btn">
            Active events
        </button>
        <button type="button" onclick="filterEvents('completed')" aria-pressed="false" id="btn-completed" class="btn">
            Completed
        </button>
        <button type="button" onclick="filterEvents('returned')" aria-pressed="false" id="btn-returned" class="btn">
            Returned
        </button>
    </div>
</div>

    <script>
    const prepStateKey = 'prep-view:<?php echo (int) $user_id; ?>';
    function capturePrepView() {
        return {
            tab: document.querySelector('.event-toolbar .btn[aria-pressed="true"]').id.replace('btn-', ''),
            groups: [...document.querySelectorAll('.prep-customer-group')].filter(group => group.querySelector('.prep-customer-toggle').getAttribute('aria-expanded') === 'true').map(group => group.dataset.prepKey),
            checklists: [...document.querySelectorAll('.event-card')].filter(card => card.querySelector('details[open]')).map(card => card.id),
            scroll: document.querySelector('.event-preparation-page').scrollTop
        };
    }
    function savePrepView() {
        try { sessionStorage.setItem(prepStateKey, JSON.stringify(capturePrepView())); } catch (_) {}
    }
    function restorePrepView(state) {
        if (!state) return;
        document.querySelectorAll('.prep-customer-group').forEach(group => {
            const open = (state.groups || []).includes(group.dataset.prepKey);
            group.querySelector('.prep-customer-toggle').setAttribute('aria-expanded', String(open));
            group.querySelector('.prep-customer-bookings').hidden = !open;
        });
        (state.checklists || []).forEach(id => {
            const details = document.getElementById(id)?.querySelector('details');
            if (details) details.open = true;
        });
        filterEvents(['active', 'completed', 'returned'].includes(state.tab) ? state.tab : 'active');
        document.querySelector('.event-preparation-page').scrollTop = Number(state.scroll) || 0;
    }
    function filterEvents(type) {
        ['active', 'completed', 'returned'].forEach(category => {
            document.querySelectorAll('.event-' + category).forEach(el => el.style.display = category === type ? 'block' : 'none');
            document.getElementById('btn-' + category).setAttribute('aria-pressed', String(category === type));
        });
        document.getElementById('filter-empty').hidden = document.querySelectorAll('.event-' + type).length > 0;
        document.getElementById('filter-empty-title').textContent = { active: 'All caught up', completed: 'No completed events yet', returned: 'No saved returns yet' }[type];
    
    }
    
    // Initialize default view
    function initializeEventView() {
        // Show each customer once per tab, keeping all their bookings available.
        const container = document.querySelector('.events-container');
        const customerGroups = new Map();
        container.querySelectorAll('.event-card').forEach(function (card) {
            const nameButton = card.querySelector('.prep-event-name');
            const name = nameButton.lastElementChild.textContent.trim().replace(/\s+/g, ' ').toLocaleLowerCase();
            const category = card.classList.contains('event-returned') ? 'event-returned' : (card.classList.contains('event-completed') ? 'event-completed' : 'event-active');
            const key = category + ':' + name;
            let group = customerGroups.get(key);
            if (!group) {
                const wrapper = document.createElement('div');
                wrapper.className = 'prep-customer-group ' + category;
                wrapper.dataset.prepKey = key;
                const toggle = nameButton.cloneNode(true);
                toggle.classList.remove('prep-event-name');
                toggle.classList.add('prep-customer-toggle');
                const bookings = document.createElement('div');
                bookings.id = 'customer-events-' + customerGroups.size;
                bookings.className = 'prep-customer-bookings';
                bookings.hidden = true;
                toggle.setAttribute('aria-controls', bookings.id);
                toggle.setAttribute('aria-expanded', 'false');
                toggle.addEventListener('click', function () {
                    bookings.hidden = !bookings.hidden;
                    toggle.setAttribute('aria-expanded', String(!bookings.hidden));
                });
                wrapper.append(toggle, bookings);
                container.insertBefore(wrapper, card);
                group = { toggle, bookings };
                customerGroups.set(key, group);
            } else if (group.toggle.querySelector('img')?.dataset.uploaded !== 'true' && nameButton.querySelector('img')?.dataset.uploaded === 'true') {
                group.toggle.querySelector('.prep-customer-avatar').replaceWith(nameButton.querySelector('.prep-customer-avatar').cloneNode(true));
            }
            card.classList.remove('event-active', 'event-completed', 'event-returned');
            card.style.display = '';
            card.querySelector('.prep-event-details').hidden = false;
            group.bookings.appendChild(card);
        });
        customerGroups.forEach(function (group) {
            const count = group.bookings.querySelectorAll('.event-card').length;
            const total = document.createElement('span');
            total.className = 'prep-booking-total';
            total.textContent = count + (count === 1 ? ' booking' : ' bookings');
            group.toggle.append(total);
        });
        filterEvents('active');
        document.querySelectorAll('.prep-checklist-disclosure').forEach(function (details) {
            const count = details.querySelectorAll('tbody tr').length;
            details.querySelector('.prep-item-count').textContent = count;
            details.addEventListener('toggle', function () {
                details.querySelector('.prep-view-label').textContent = details.open ? 'Hide items' : 'View items';
            });
        });
    }
    document.addEventListener('DOMContentLoaded', function () {
        initializeEventView();
        try { restorePrepView(JSON.parse(sessionStorage.getItem(prepStateKey))); } catch (_) {}
        const workspace = document.querySelector('.event-preparation-page');
        workspace.addEventListener('click', () => queueMicrotask(savePrepView));
        workspace.addEventListener('toggle', savePrepView, true);
        workspace.addEventListener('scroll', savePrepView, { passive: true });
        window.addEventListener('pagehide', savePrepView);
        workspace.addEventListener('submit', savePrepView);
    });

    let refreshingEvents = false;
    let eventsSignature = null;
    async function refreshEvents() {
        const busy = () => document.querySelector('.modal-overlay[style*="flex"], form[data-saving="true"], .prep-quantity[data-saving="true"]') || document.activeElement?.matches('.prep-quantity');
        if (refreshingEvents || document.hidden || busy()) return;
        refreshingEvents = true;
        try {
            const response = await fetch(window.location.href, { cache: 'no-store', credentials: 'same-origin' });
            if (!response.ok || response.redirected) return;
            const page = new DOMParser().parseFromString(await response.text(), 'text/html');
            const incoming = page.querySelector('.events-container');
            if (!incoming || busy()) return;
            const signature = incoming.innerHTML;
            if (signature === eventsSignature) return;
            const container = document.querySelector('.events-container');
            const viewState = capturePrepView();
            container.replaceChildren(...incoming.childNodes);
            eventsSignature = signature;
            initializeEventView();
            restorePrepView(viewState);
            savePrepView();
        } catch (error) {
            // Preserve the current view during a temporary connection failure.
        } finally {
            refreshingEvents = false;
        }
    }
    window.setInterval(refreshEvents, 5000);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) refreshEvents(); });
    </script>

<?php if (isset($error_msg)): ?><div class="alert alert-danger" role="alert"><?php echo htmlspecialchars($error_msg); ?></div><?php endif; ?>
<?php if (isset($success_msg)): ?>
    <dialog id="event-success-message" class="prep-success-modal" aria-label="Success">
        <i class="fas fa-check-circle" aria-hidden="true"></i>
        <p role="status"><?php echo htmlspecialchars($success_msg, ENT_QUOTES, 'UTF-8'); ?></p>
    </dialog>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const modal = document.getElementById('event-success-message');
            modal.showModal();
            window.setTimeout(function () {
                modal.close();
                modal.remove();
            }, 1000);
        });
    </script>
<?php endif; ?>

<div id="filter-empty" class="prep-empty" hidden><i class="fas fa-clipboard-check" aria-hidden="true"></i><h3 id="filter-empty-title">All caught up</h3><p>Events will appear here as they move through preparation and completion.</p></div>
<div class="events-container" style="display: grid; gap: 20px;">
    <?php if (mysqli_num_rows($events_result) == 0): ?>
        <div style="text-align: center; padding: 40px; color: var(--text-muted); background: var(--card-bg); border-radius: 8px;">
            No events found.
        </div>
    <?php else: ?>
        <?php while ($event = mysqli_fetch_assoc($events_result)): ?>
            <?php 
                $status_color = '#f39c12'; // Reserved
                if ($event['status'] == 'Prepared') $status_color = '#3498db';
                if ($event['status'] == 'Completed') $status_color = '#27ae60';
                
                $is_completed = ($event['status'] == 'Completed');
                $card_class = !empty($event['returns_saved']) ? 'event-returned' : ($is_completed ? 'event-completed' : 'event-active');
                $display_style = $card_class !== 'event-active' ? 'display: none;' : '';
                $profile_path = preg_replace('~/+~', '/', str_replace('\\', '/', trim((string) ($event['customer_profile_image'] ?? ''))));
                $profile_path = ltrim($profile_path, '/');
                $profile_url = '../uploads/logo.png';
                $has_uploaded_profile = false;
                if (strpos($profile_path, 'uploads/') === 0 && strpos($profile_path, '..') === false
                    && $profile_path !== 'uploads/logo.png'
                    && is_file(__DIR__ . '/../' . $profile_path)) {
                    $profile_url = '../' . implode('/', array_map('rawurlencode', explode('/', $profile_path)));
                    $has_uploaded_profile = true;
                }
            ?>
            
            <div id="event-card-<?php echo $event['id']; ?>" class="event-card <?php echo $card_class; ?>" style="background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 8px; overflow: hidden; <?php echo $display_style; ?>">
                <button type="button" class="prep-event-name" aria-expanded="false" aria-controls="event-details-<?php echo $event['id']; ?>">
                    <span class="prep-customer-avatar" aria-hidden="true">
                        <img src="<?php echo htmlspecialchars($profile_url, ENT_QUOTES, 'UTF-8'); ?>" data-uploaded="<?php echo $has_uploaded_profile ? 'true' : 'false'; ?>" alt="" loading="lazy" onerror="this.onerror=null;this.dataset.uploaded='false';this.src='../uploads/logo.png';">
                    </span>
                    <span><?php echo htmlspecialchars($event['customer_name']); ?></span>
                </button>
                <div id="event-details-<?php echo $event['id']; ?>" class="prep-event-details" hidden>
                <!-- Event Header -->
                <div class="prep-event-heading" style="padding: 15px; background: rgba(0,0,0,0.2); border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center;">
                    <div>
                        <h3 style="margin: 0; color: var(--accent-gold);">EVENT #<?php echo htmlspecialchars(formatCatererBookingNumber($event['order_id'], '', $user_id)); ?></h3>
                        <div style="font-size: 13px; color: var(--text-muted); margin-top: 5px;">
                            <i class="fas fa-calendar"></i> <?php echo date('F j, Y', strtotime($event['event_date'])); ?>
                            <?php if ($event['order_time'] !== null): ?>
                                &middot; <?php echo date('g:i A', strtotime($event['order_time'])); ?>
                            <?php endif; ?>
                            <?php if (date('Y-m-d') == $event['event_date']): ?>
                                <span style="background: #e74c3c; color: white; padding: 2px 6px; border-radius: 4px; font-weight: bold; margin-left: 5px; font-size: 11px;">TODAY</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div style="text-align: right;">
                        <span style="background: <?php echo $status_color; ?>; color: white; padding: 5px 10px; border-radius: 4px; font-size: 12px; font-weight: bold; text-transform: uppercase;">
                            <?php echo $event['status']; ?>
                        </span>
                    </div>
                </div>
                
                <div class="event-card-body">
                    <!-- Customer Details -->
                    <div>
                        <h4 style="color: var(--text-main); margin-bottom: 10px; border-bottom: 1px solid var(--border-color); padding-bottom: 5px;">Event overview</h4>
                        <p style="margin: 5px 0; font-size: 14px;"><strong>Package</strong> <?php echo htmlspecialchars($event['service_type']); ?></p>
                        <p style="margin: 5px 0; font-size: 14px;"><strong>Guests</strong> <?php echo $event['pax']; ?></p>
                        <p style="margin: 5px 0; font-size: 14px;"><strong>Event total</strong> &#8369;<?php echo number_format($event['price'], 2); ?></p>
                        
                        <!-- Actions -->
                        <div class="prep-event-actions" style="margin-top: 20px;">
                            <?php if (!empty($event['order_id'])): ?>
                                <a href="order-details.php?id=<?php echo (int) $event['order_id']; ?>&amp;from=event_preparation" class="btn btn-outline-primary prep-view-details" onclick="savePrepView()" style="display:flex;align-items:center;justify-content:center;gap:8px;width:100%;box-sizing:border-box;margin-bottom:10px;min-height:44px;">
                                    <i class="fas fa-eye" aria-hidden="true"></i> View Details
                                </a>
                            <?php endif; ?>
                            <form method="POST">
                                <input type="hidden" name="event_id" value="<?php echo $event['id']; ?>">
                                
                                <?php if ($event['status'] == 'Reserved'): ?>
                                    <?php if (date('Y-m-d') == $event['event_date']): ?>
                                        <div style="margin-bottom: 10px; font-size: 12px; color: #e74c3c;">
                                            <i class="fas fa-exclamation-circle"></i> Event is TODAY!
                                        </div>
                                    <?php endif; ?>
                                    <?php
                                        $unlock_date = preparationUnlockDate($event['event_date']);
                                        $preparation_locked = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')) < $unlock_date;
                                    ?>
                                    <button type="submit" name="action" value="prepare" class="btn btn-primary" <?php echo $preparation_locked ? 'disabled aria-disabled="true"' : ''; ?> style="width: 100%; background: #3498db; border-color: #3498db;<?php echo $preparation_locked ? 'opacity:0.55;cursor:not-allowed;' : ''; ?>">
                                        <i class="fas fa-box-open"></i> Prepare &amp; release stock
                                    </button>
                                <?php elseif ($event['status'] == 'Prepared'): ?>
                                    <div style="margin-bottom: 10px; font-size: 12px; color: #3498db;">
                                        <i class="fas fa-truck"></i> Items Released / In Use
                                    </div>
                                    <button type="button" onclick="openReturnModal(<?php echo $event['id']; ?>, false)" class="btn btn-primary" style="width: 100%; background: #27ae60; border-color: #27ae60;">
                                        <i class="fas fa-check-circle"></i> Complete &amp; record returns
                                    </button>
                                <?php else: ?>
                                    <button type="button" onclick="openReturnModal(<?php echo $event['id']; ?>, true)" class="btn btn-secondary" style="width: 100%; opacity: 0.8; background: #27ae60; border-color: #27ae60; color: #fff;">
                                        <i class="fas fa-edit"></i> Edit Returns
                                    </button>
                                <?php endif; ?>
                            </form>
                        </div>
                    </div>
                    
                    <!-- Preparation checklist -->
                    <div>
                        <h4 style="color: var(--text-main); margin-bottom: 10px; border-bottom: 1px solid var(--border-color); padding-bottom: 5px; display: flex; justify-content: space-between; align-items: center;">
                            Preparation List
                        </h4>
                        <details class="prep-checklist-disclosure">
                            <summary class="prep-checklist-toggle">
                                <span><i class="fas fa-list-ul" aria-hidden="true"></i> <span class="prep-view-label">View items</span> <span class="prep-item-count"></span></span>
                                <i class="fas fa-chevron-down prep-disclosure-chevron" aria-hidden="true"></i>
                            </summary>
                        <div class="event-table-wrap">
                        <table style="width: 100%; font-size: 14px; border-collapse: collapse;">
                            <thead>
                                <tr style="text-align: left; color: var(--text-muted);">
                                    <th style="padding-bottom: 8px;">Item</th>
                                    <th style="padding-bottom: 8px;">Required</th>
                                    <th style="padding-bottom: 8px;">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $inv_json_array = [];
                                $inv_query = "SELECT ei.*, ii.item_type 
                                              FROM event_inventory ei 
                                              LEFT JOIN inventory_items ii ON ii.id = (
                SELECT matched.id FROM inventory_items matched
                WHERE matched.item_name = ei.item_name AND matched.store_id = '$user_id'
                ORDER BY (matched.item_type = 'consumable') DESC, matched.id DESC LIMIT 1
            )
                                              WHERE ei.event_id = '{$event['id']}'";
                                $inv_result = mysqli_query($conn, $inv_query);
                                while ($item = mysqli_fetch_assoc($inv_result)):
                                    $inv_json_array[] = $item;
                            ?>
                                    <tr style="border-bottom: 1px solid rgba(255,255,255,0.05);">
                                        <td style="padding: 8px 0;">
                                            <?php echo htmlspecialchars($item['item_name']); ?>
                                            <?php if ($item['item_type'] == 'consumable'): ?>
                                                <small class="prep-item-type">Consumable</small>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="Required" style="padding: 8px 0; font-weight: bold; color: var(--accent-gold);">
                                            <?php if ($event['status'] === 'Reserved' && !$item['quantity_released'] && !$item['quantity_returned']): ?>
                                                <input class="prep-quantity form-control" type="number" min="0" max="1000000" step="1" value="<?php echo (int) $item['quantity_required']; ?>" data-event="<?php echo (int) $event['id']; ?>" data-item="<?php echo (int) $item['id']; ?>" data-saved="<?php echo (int) $item['quantity_required']; ?>" aria-label="Quantity to release: <?php echo htmlspecialchars($item['item_name'], ENT_QUOTES); ?>">
                                                <small class="prep-quantity-message" role="status"></small>
                                            <?php else: echo (int) $item['quantity_required']; endif; ?>
                                        </td>
                                        <td data-label="Status" style="padding: 8px 0;">
                                            <?php if ($item['quantity_returned'] > 0): ?>
                                                <?php if ($item['item_type'] == 'consumable'): ?>
                                                    <span class="prep-stock-status prep-stock-consumed">Consumed</span>
                                                <?php else: ?>
                                                    <span class="prep-stock-status prep-stock-returned">Returned</span>
                                                <?php endif; ?>
                                            <?php elseif ($item['quantity_released'] > 0): ?>
                                                <span class="prep-stock-status prep-stock-released">Released</span>
                                            <?php else: ?>
                                                <span class="prep-stock-status prep-stock-pending">Pending</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                        </div>
                        
                        </details>
                        <!-- JSON Data for this event -->
                        <textarea id="event_data_<?php echo $event['id']; ?>" style="display:none;"><?php echo htmlspecialchars(json_encode($inv_json_array), ENT_QUOTES, 'UTF-8'); ?></textarea>
                    </div>
                </div>
                </div>
            </div>
        <?php endwhile; ?>
    <?php endif; ?>
</div>

</section>
<!-- Add Extra Item Modal -->
<div id="addExtraModal" class="modal-overlay">
    <div class="modal-content">
        <div style="display: flex; justify-content: space-between; margin-bottom: 15px;">
            <h3 style="margin: 0;">Add Extra Item</h3>
            <button aria-label="Close add item" onclick="closeAddModal()" style="background:none; border:none; color:var(--text-muted); cursor:pointer;">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="add_item">
            <input type="hidden" name="event_id" id="modal_event_id">
            
            <div style="margin-bottom: 15px;">
                <label style="display: block; margin-bottom: 5px; font-size: 13px;">Select Item</label>
                <select name="item_name" class="form-control" required>
                    <?php foreach ($inventory_items as $inv): ?>
                        <option value="<?php echo htmlspecialchars($inv['item_name']); ?>">
                            <?php echo htmlspecialchars($inv['item_name']); ?> (<?php echo number_format(floatval($inv['available_stock'])); ?> avail)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div style="margin-bottom: 15px;">
                <label style="display: block; margin-bottom: 5px; font-size: 13px;">Quantity</label>
                <input type="number" name="quantity" class="form-control" required min="1">
            </div>
            
            <button type="submit" class="btn btn-primary" style="width: 100%;">Add to Event</button>
        </form>
    </div>
</div>

<!-- Return Items Modal -->
<div id="returnModal" class="modal-overlay">
    <div class="modal-content" style="width: 500px;">
        <div style="display: flex; justify-content: space-between; margin-bottom: 15px;">
            <h3 style="margin: 0;" id="returnModalTitle">Complete Event & Return Items</h3>
            <button type="button" class="prep-modal-close" aria-label="Close returns" onclick="closeReturnModal()" style="background:none; border:none; color:var(--text-muted); cursor:pointer;">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" id="returnAction" value="complete_returns">
            <input type="hidden" name="event_id" id="return_event_id">
            
            <div style="max-height: 400px; overflow-y: auto; margin-bottom: 20px;">
                <table style="width: 100%; border-collapse: collapse; font-size: 13px;">
                    <thead>
                        <tr style="text-align: left; color: var(--text-muted); border-bottom: 1px solid var(--border-color);">
                            <th style="padding: 5px;">Item</th>
                            <th style="padding: 5px; text-align: center;">Quantity</th>
                            <th style="padding: 5px; width: 100px;">Returned</th>
                        </tr>
                    </thead>
                    <tbody id="returnItemsBody">
                        <!-- Populated via JS -->
                    </tbody>
                </table>
            </div>
            
            
            <button type="submit" class="btn btn-primary" style="width: 100%; background: #27ae60; border-color: #27ae60;">Save Returns</button>
        </form>
    </div>
</div>

<script>
    const preparationQuantityToken = <?php echo json_encode(generateCSRFToken()); ?>;
    document.addEventListener('change', async event => {
        const input = event.target.closest('.prep-quantity');
        if (!input || input.dataset.saving === 'true') return;
        const message = input.parentElement.querySelector('.prep-quantity-message');
        if (!input.checkValidity() || input.value === '') { input.reportValidity(); return; }
        const card = input.closest('.event-card');
        const release = card.querySelector('button[value="prepare"]');
        input.dataset.saving = 'true';
        input.disabled = true;
        if (release) {
            if (!release.hasAttribute('data-was-disabled')) release.dataset.wasDisabled = String(release.disabled);
            release.disabled = true;
        }
        message.textContent = 'Saving…';
        try {
            const response = await fetch('update_preparation_quantity.php', { method: 'POST', credentials: 'same-origin', body: new URLSearchParams({ csrf_token: preparationQuantityToken, event_id: input.dataset.event, item_id: input.dataset.item, quantity: input.value }) });
            const data = await response.json();
            if (!response.ok || !data.success) throw new Error(data.error || 'Unable to save quantity.');
            input.value = input.dataset.saved = String(data.quantity);
            const json = document.getElementById('event_data_' + input.dataset.event);
            const items = JSON.parse(json.value);
            items.forEach(item => { if (String(item.id) === input.dataset.item) item.quantity_required = data.quantity; });
            json.value = JSON.stringify(items);
            message.textContent = 'Saved';
        } catch (error) { input.value = input.dataset.saved; message.textContent = error.message; }
        finally {
            input.disabled = false;
            delete input.dataset.saving;
            if (release && !card.querySelector('.prep-quantity[data-saving="true"]')) {
                release.disabled = release.dataset.wasDisabled === 'true';
                delete release.dataset.wasDisabled;
            }
        }
    });
    // Save returns without navigating away from the current tab and scroll position.
    document.querySelector('#returnModal form').addEventListener('submit', async function (event) {
        event.preventDefault();
        const form = event.currentTarget;
        if (form.dataset.saving === 'true') return;
        const button = form.querySelector('button[type="submit"]');
        const view = capturePrepView();
        const workspace = document.querySelector('.event-preparation-page');
        const pageScroll = { x: window.scrollX, y: window.scrollY };
        form.dataset.saving = 'true';
        button.disabled = true;
        button.setAttribute('aria-busy', 'true');
        form.querySelector('.prep-action-message')?.remove();
        try {
            const response = await fetch(window.location.href, {
                method: 'POST', body: new FormData(form), credentials: 'same-origin'
            });
            if (!response.ok || response.redirected) throw new Error('Unable to save returns. Please try again.');
            const page = new DOMParser().parseFromString(await response.text(), 'text/html');
            const success = page.getElementById('event-success-message');
            const incoming = page.querySelector('.events-container');
            if (!success || !incoming) throw new Error(page.querySelector('.alert-danger')?.textContent || 'Unable to save returns. Please try again.');
            document.querySelector('.events-container').replaceChildren(...incoming.childNodes);
            initializeEventView();
            restorePrepView(view);
            savePrepView();
            document.getElementById('event-success-message')?.remove();
            closeReturnModal();
            const message = document.importNode(success, true);
            const dismiss = document.createElement('button');
            dismiss.type = 'button';
            dismiss.className = 'btn btn-primary';
            dismiss.textContent = 'OK';
            dismiss.style.cssText = 'min-height:44px;min-width:96px;margin-top:20px;';
            dismiss.addEventListener('click', () => { message.close(); message.remove(); });
            message.appendChild(dismiss);
            document.body.appendChild(message);
            message.showModal();
            workspace.scrollTop = Number(view.scroll) || 0;
            window.scrollTo(pageScroll.x, pageScroll.y);

        } catch (error) {
            const message = document.createElement('div');
            message.className = 'prep-action-message alert alert-danger';
            message.setAttribute('role', 'alert');
            message.textContent = error.message;
            form.appendChild(message);
        } finally {
            delete form.dataset.saving;
            button.disabled = false;
            button.removeAttribute('aria-busy');
        }
    });

    document.addEventListener('click', function (event) {
        const button = event.target.closest('.prep-event-name');
        if (!button) return;
        const panel = document.getElementById(button.getAttribute('aria-controls'));
        panel.hidden = !panel.hidden;
        button.setAttribute('aria-expanded', String(!panel.hidden));
    });

    // Update only this event, preserving the viewport and expanded checklist.
    document.addEventListener('submit', async function (event) {
        const form = event.target;
        const button = event.submitter;
        if (!button || button.name !== 'action' || button.value !== 'prepare') return;
        event.preventDefault();
        if (form.dataset.saving === 'true') return;
        const card = form.closest('.event-card');
        const body = new FormData(form);
        body.set('action', 'prepare');
        form.dataset.saving = 'true';
        button.disabled = true;
        button.setAttribute('aria-busy', 'true');
        card.querySelector('.prep-action-message')?.remove();
        try {
            // A control named "action" masks form.action in the browser.
            const targetUrl = new URL(form.getAttribute('action') || window.location.href, document.baseURI);
            const response = await fetch(targetUrl.href, { method: 'POST', body, credentials: 'same-origin' });
            if (!response.ok || response.redirected) throw new Error('Unable to confirm preparation. Please refresh to check the event status.');
            const page = new DOMParser().parseFromString(await response.text(), 'text/html');
            const updated = page.getElementById(card.id);
            if (!updated || !page.getElementById('event-success-message')) {
                throw new Error(page.querySelector('.alert-danger')?.textContent || 'Unable to confirm preparation. Please refresh to check the event status.');
            }
            const details = updated.querySelector('details');
            updated.querySelector('.prep-event-details').hidden = card.querySelector('.prep-event-details').hidden;
            updated.querySelector('.prep-event-name').setAttribute('aria-expanded', card.querySelector('.prep-event-name').getAttribute('aria-expanded'));
            details.open = card.querySelector('details').open;
            details.querySelector('.prep-item-count').textContent = details.querySelectorAll('tbody tr').length;
            const updateLabel = function () {
                details.querySelector('.prep-view-label').textContent = details.open ? 'Hide items' : 'View items';
            };
            updateLabel();
            details.addEventListener('toggle', updateLabel);
            card.replaceChildren(...updated.childNodes);
        } catch (error) {
            const message = document.createElement('div');
            message.className = 'prep-action-message alert alert-danger';
            message.setAttribute('role', 'alert');
            message.textContent = error.message;
            form.appendChild(message);
        } finally {
            delete form.dataset.saving;
            button.disabled = false;
            button.removeAttribute('aria-busy');
        }
    });

    function openAddModal(eventId) {
        document.getElementById('modal_event_id').value = eventId;
        document.getElementById('addExtraModal').style.display = 'flex';
    }
    
    function closeAddModal() {
        document.getElementById('addExtraModal').style.display = 'none';
    }
    function openReturnModal(eventId, isEdit) {
        document.getElementById('return_event_id').value = eventId;
        document.getElementById('returnAction').value = isEdit ? 'update_returns' : 'complete_returns';
        document.getElementById('returnModalTitle').textContent = isEdit ? 'Edit Returns' : 'Complete Event & Return Items';
        
        // Get Items Data
        const dataArea = document.getElementById('event_data_' + eventId);
        if (!dataArea) {
            alert('Error loading event data');
            return;
        }
        
        try {
            const items = JSON.parse(dataArea.value);
            const tbody = document.getElementById('returnItemsBody');
            tbody.innerHTML = '';
            
            items.forEach(item => {
                if (item.item_type === 'equipment') {
                    const tr = document.createElement('tr');
                    tr.style.borderBottom = '1px solid rgba(255,255,255,0.05)';
                    
                    const defaultVal = isEdit ? item.quantity_returned : item.quantity_required;
                    
                    tr.innerHTML = `
                        <td style="padding: 8px 5px;">${item.item_name}</td>
                        <td style="padding: 8px 5px; text-align: center; font-weight: bold;">${item.quantity_required}</td>
                        <td style="padding: 8px 5px;">
                            <input type="number" name="returns[${item.id}]" value="${defaultVal}" 
                                   min="0" max="${item.quantity_required}" 
                                   class="form-control" style="padding: 5px; text-align: center;">
                        </td>
                    `;
                    tbody.appendChild(tr);
                } else if (item.item_type === 'consumable') {
                    const tr = document.createElement('tr');
                    const name = document.createElement('td');
                    name.style.padding = '8px 5px';
                    name.textContent = item.item_name;
                    const quantity = document.createElement('td');
                    quantity.style.textAlign = 'center';
                    quantity.textContent = item.quantity_required;
                    const status = document.createElement('td');
                    status.textContent = 'Consumed';
                    status.style.color = '#245539';
                    tr.append(name, quantity, status);
                    tbody.appendChild(tr);
                }
            });
            
            if (!items.length) {
                tbody.innerHTML = '<tr><td colspan="3" style="text-align:center; padding: 20px; color: var(--text-muted);">No equipment to return (Consumables only).</td></tr>';
            }
            
            document.getElementById('returnModal').style.display = 'flex';
        } catch (e) {
            console.error(e);
            alert('Error parsing event data');
        }
    }
    
    function closeReturnModal() {
        document.getElementById('returnModal').style.display = 'none';
    }
    
    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') { closeAddModal(); }
    });

    // Close on outside click
    window.onclick = function(event) {
        if (event.target.id == 'addExtraModal') {
            closeAddModal();
        }
        // Returns stay open until the user presses the close button.
    }
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>


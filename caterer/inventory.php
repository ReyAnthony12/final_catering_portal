<?php
// Start session first as header.php checks it
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/inventory_return_helper.php';
/** @var mysqli $conn */

// Check login
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['store', 'caterer'])) {
    header('Location: ../login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
require_once __DIR__ . '/../includes/inventory_merge_helper.php';
mergeStoreInventoryDuplicates($conn, (int) $user_id);
$page_title = 'Inventory Management';
$current_page = 'inventory.php';
$requested_inventory_panel = isset($_GET['inventory_panel']) && in_array($_GET['inventory_panel'], ['0', '1'], true)
    ? (int) $_GET['inventory_panel'] : null;
header('Cache-Control: no-store');

function syncInventoryAvailability(mysqli $conn, int $storeId): array
{
    syncCompletedInventoryUsage($conn, $storeId);
    $updatedItems = 0;
    $itemsQuery = mysqli_query(
        $conn,
        "SELECT id, total_stock, available_stock, damaged_stock
         FROM inventory_items
         WHERE store_id = '" . intval($storeId) . "'"
    );

    if (!$itemsQuery) {
        return ['updated' => 0];
    }

    while ($item = mysqli_fetch_assoc($itemsQuery)) {
        $itemId = intval($item['id']);
        $activeUsageQuery = mysqli_query(
            $conn,
            "SELECT COALESCE(SUM(quantity_used), 0) AS total_active
             FROM inventory_usage
             WHERE inventory_item_id = '$itemId'
               AND status IN ('reserved', 'active')"
        );
        $activeUsageRow = $activeUsageQuery ? mysqli_fetch_assoc($activeUsageQuery) : null;
        $activeUsage = round((float) ($activeUsageRow['total_active'] ?? 0), 2);
        $damagedStock = round((float) ($item['damaged_stock'] ?? 0), 2);
        $currentTotal = round((float) ($item['total_stock'] ?? 0), 2);
        $currentAvailable = round((float) ($item['available_stock'] ?? 0), 2);

        $syncedTotal = max($currentTotal, $activeUsage + $damagedStock);
        $syncedAvailable = max(0, round($syncedTotal - $activeUsage - $damagedStock, 2));

        if (abs($syncedTotal - $currentTotal) > 0.009 || abs($syncedAvailable - $currentAvailable) > 0.009) {
            mysqli_query(
                $conn,
                "UPDATE inventory_items
                 SET total_stock = '$syncedTotal',
                     available_stock = '$syncedAvailable'
                 WHERE id = '$itemId'
                   AND store_id = '" . intval($storeId) . "'"
            );
            $updatedItems++;
        }
    }

    return ['updated' => $updatedItems];
}

// Handle POST requests
$success_msg = '';
$error_msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $action = $_POST['action'];
        
        if ($action === 'seed_missing_booking_items') {
            // Only checklist items belonging to this caterer's actual bookings qualify.
            // Package recommendations must never create unrelated inventory items.
            $bookingItemsQuery = mysqli_query($conn, "SELECT DISTINCT TRIM(ei.item_name) AS item_name
                FROM event_inventory ei
                JOIN events e ON e.id = ei.event_id
                JOIN bookings b ON b.id = e.order_id AND b.store_id = e.store_id
                WHERE e.store_id = '" . intval($user_id) . "'
                  AND ei.quantity_required > 0
                  AND TRIM(ei.item_name) <> ''");
            $bookingItems = [];
            if ($bookingItemsQuery) {
                while ($bookingItem = mysqli_fetch_assoc($bookingItemsQuery)) {
                    $bookingItems[] = $bookingItem['item_name'];
                }
            }

            $existingNames = [];
            $existingQuery = mysqli_query($conn, "SELECT item_name FROM inventory_items WHERE store_id = '$user_id'");
            while ($existingRow = mysqli_fetch_assoc($existingQuery)) {
                $existingNames[] = strtolower(trim((string) ($existingRow['item_name'] ?? '')));
            }

            $addedCount = 0;
            foreach ($bookingItems as $bookingItemName) {
                if (in_array(strtolower(trim($bookingItemName)), $existingNames, true)) {
                    continue;
                }

                $name = mysqli_real_escape_string($conn, $bookingItemName);
                $type = 'equipment';
                $unit = 'pcs';
                $total = 0;
                $threshold = 0;

                $insertQuery = "INSERT INTO inventory_items (store_id, item_name, item_type, unit, total_stock, available_stock, damaged_stock, low_stock_threshold)
                                VALUES ('$user_id', '$name', '$type', '$unit', '$total', '$total', 0, '$threshold')";

                if (mysqli_query($conn, $insertQuery)) {
                    $newItemId = mysqli_insert_id($conn);
                    mysqli_query($conn, "INSERT INTO inventory_logs (inventory_item_id, store_id, change_amount, change_type, reason) VALUES ('$newItemId', '$user_id', '$total', 'added', 'Missing item from actual booking checklist')");
                    $existingNames[] = strtolower(trim($bookingItemName));
                    $addedCount++;
                }
            }

            if (!$bookingItemsQuery) {
                $error_msg = 'Unable to load booking items right now. Please try again.';
            } elseif ($addedCount > 0) {
                $success_msg = $addedCount . ' missing booking item(s) added. Set their actual stock using Edit.';
            } else {
                $success_msg = 'No missing items found in your actual bookings.';
            }
        } elseif ($action === 'sync_inventory_counts') {
            $syncResult = syncInventoryAvailability($conn, (int) $user_id);
            if (($syncResult['updated'] ?? 0) > 0) {
                $success_msg = 'Inventory counts were organized and synced based on active bookings.';
            } else {
                $success_msg = 'Inventory counts are already organized and up to date.';
            }
        } elseif ($action === 'add') {
            $name = mysqli_real_escape_string($conn, trim(preg_replace('/\s+/u', ' ', (string) $_POST['item_name'])));
            $type = mysqli_real_escape_string($conn, $_POST['item_type']);
            $unit = mysqli_real_escape_string($conn, $_POST['unit']);
            $total = floatval($_POST['total_stock']);
            $low_threshold = floatval($_POST['low_stock_threshold']);
            
            // Available = Total initially
            $query = "INSERT INTO inventory_items (store_id, item_name, item_type, unit, total_stock, available_stock, damaged_stock, low_stock_threshold) 
                      VALUES ('$user_id', '$name', '$type', '$unit', '$total', '$total', 0, '$low_threshold')";
            
            $duplicate_item = mysqli_query($conn, "SELECT id FROM inventory_items WHERE store_id = '$user_id' AND LOWER(TRIM(item_name)) = LOWER('$name') AND item_type = '$type' LIMIT 1");
            if ($name === '') {
                $error_msg = 'Enter an item name.';
            } elseif ($duplicate_item && mysqli_num_rows($duplicate_item) > 0) {
                $existing_id = (int) mysqli_fetch_assoc($duplicate_item)['id'];
                if (!is_finite($total) || $total < 0) {
                    $error_msg = 'Enter a valid stock quantity.';
                } else {
                    mysqli_begin_transaction($conn);
                    try {
                        if (!mysqli_query($conn, "UPDATE inventory_items SET total_stock = total_stock + '$total', available_stock = available_stock + '$total' WHERE id = $existing_id AND store_id = '$user_id'")) throw new RuntimeException('Stock update failed.');
                        if (!mysqli_query($conn, "INSERT INTO inventory_logs (inventory_item_id, store_id, change_amount, change_type, reason) VALUES ($existing_id, '$user_id', '$total', 'added', 'Stock added to existing item')")) throw new RuntimeException('Stock log failed.');
                        mysqli_commit($conn);
                        $success_msg = 'Stock added to the existing item successfully.';
                    } catch (Throwable $error) {
                        mysqli_rollback($conn);
                        $error_msg = 'Unable to add stock. Please try again.';
                    }
                }
            } elseif (mysqli_query($conn, $query)) {
                $new_item_id = mysqli_insert_id($conn);
                // Log the addition
                mysqli_query($conn, "INSERT INTO inventory_logs (inventory_item_id, store_id, change_amount, change_type, reason) VALUES ('$new_item_id', '$user_id', '$total', 'added', 'Initial stock')");
                $success_msg = "Item added successfully.";
            } else {
                appLogError('Caterer inventory add item failed', mysqli_error($conn));
                $error_msg = "Unable to add the inventory item right now. Please try again.";
            }
        } elseif ($action === 'edit') {
            $id = intval($_POST['item_id']);
            $name = mysqli_real_escape_string($conn, $_POST['item_name']);
            $type = mysqli_real_escape_string($conn, $_POST['item_type']);
            $unit = mysqli_real_escape_string($conn, $_POST['unit']);
            $total = floatval($_POST['total_stock']);
            $low_threshold = floatval($_POST['low_stock_threshold']);
            
            // Get current state
            $current_q = mysqli_query($conn, "SELECT total_stock, available_stock, damaged_stock FROM inventory_items WHERE id = '$id' AND store_id = '$user_id'");
            $current = mysqli_fetch_assoc($current_q);
            
            if ($current) {
                $diff = $total - $current['total_stock'];
                
                // Calculate new available stock
                // New Available = Old Available + Difference
                // (If we increase total by 10, available increases by 10)
                $new_available = $current['available_stock'] + $diff;
                
                if ($new_available < 0) {
                    $error_msg = "Cannot reduce total stock below usage amount.";
                } else {
                    $query = "UPDATE inventory_items SET item_name = '$name', item_type = '$type', unit = '$unit', total_stock = '$total', available_stock = '$new_available', low_stock_threshold = '$low_threshold' WHERE id = '$id' AND store_id = '$user_id'";
                    
                    if (mysqli_query($conn, $query)) {
                        if ($diff != 0) {
                            $type = $diff > 0 ? 'added' : 'adjustment';
                            mysqli_query($conn, "INSERT INTO inventory_logs (inventory_item_id, store_id, change_amount, change_type, reason) VALUES ('$id', '$user_id', '$diff', '$type', 'Stock update')");
                        }
                        $success_msg = "Item updated successfully.";
                    } else {
                        $error_msg = "Error updating item.";
                    }
                }
            }
        } elseif ($action === 'return') {
            $id = intval($_POST['item_id']);
            $returned = floatval($_POST['returned_qty']); // Good condition
            $damaged = floatval($_POST['damaged_qty']); // Damaged
            
            // Logic:
            // Items are usually "in use" (reserved/active) when they are out.
            // But this simple "Return" action implies we are just adjusting stocks manually or returning from a generic "out" state?
            // Actually, the "Return" button in the UI was likely for "Returning items from an event".
            // However, without linking to a specific event, we are just increasing Available Stock and (optionally) increasing Damaged Stock?
            // NO. If we just increase Available, we are creating stock out of thin air if it wasn't deducted first.
            
            // If the user is manually returning items, they might be returning "Damaged" items to "Available" (repair) or moving "Available" to "Damaged".
            // Let's assume this action is for "Reporting Damages" or "Restocking".
            
            // Let's change the UI to "Adjust Stock" or "Report Damage".
            // If reporting damage: Available -> Damaged.
            // If repairing: Damaged -> Available.
            
            // For now, let's support "Report Damage" (Move Available -> Damaged)
            if ($damaged > 0) {
                $query = "UPDATE inventory_items SET available_stock = available_stock - $damaged, damaged_stock = damaged_stock + $damaged WHERE id = '$id' AND store_id = '$user_id' AND available_stock >= $damaged";
                if (mysqli_query($conn, $query) && mysqli_affected_rows($conn) > 0) {
                     mysqli_query($conn, "INSERT INTO inventory_logs (inventory_item_id, store_id, change_amount, change_type, reason) VALUES ('$id', '$user_id', '$damaged', 'damaged', 'Manual damage report')");
                    $success_msg = "Damaged items reported.";
                } else {
                    $error_msg = "Insufficient available stock to report as damaged.";
                }
            }
            
            // If adding stock (Restock)
            if ($returned > 0) {
                 // This is basically "Add Stock" but maybe they mean "Return from Damaged"?
                 // Let's keep it simple: "Add Stock" is done via Edit. 
                 // Let's allow "Repair" (Damaged -> Available)
                 $query = "UPDATE inventory_items SET damaged_stock = damaged_stock - $returned, available_stock = available_stock + $returned WHERE id = '$id' AND store_id = '$user_id' AND damaged_stock >= $returned";
                 if (mysqli_query($conn, $query) && mysqli_affected_rows($conn) > 0) {
                     mysqli_query($conn, "INSERT INTO inventory_logs (inventory_item_id, store_id, change_amount, change_type, reason) VALUES ('$id', '$user_id', '$returned', 'restocked', 'Repaired/Returned from damage')");
                    $success_msg .= ($success_msg ? " " : "") . "Items repaired successfully and returned to available stock.";

                 } else {
                     $error_msg = "Unable to repair items. Check that enough damaged stock is available.";
                 }
            }
            if ($damaged == 0 && $returned == 0) {
                $success_msg = "No stock changes were needed.";
            } elseif ($damaged < 0 || $returned < 0) {
                $error_msg = "Stock quantities cannot be negative.";
            }
        }
    }
}

syncInventoryAvailability($conn, (int) $user_id);

// Fetch Inventory
$inventory = [];
$result = mysqli_query(
    $conn,
    "SELECT ii.*,
            COALESCE(SUM(CASE WHEN iu.status IN ('reserved', 'active') THEN iu.quantity_used ELSE 0 END), 0) AS usage_in_progress
     FROM inventory_items ii
     LEFT JOIN inventory_usage iu ON iu.inventory_item_id = ii.id
     WHERE ii.store_id = '$user_id'
     GROUP BY ii.id
     ORDER BY ii.item_name ASC"
);
while ($row = mysqli_fetch_assoc($result)) {
    $row['in_use'] = max(0, round((float) ($row['usage_in_progress'] ?? 0), 2));
    $row['available_stock'] = max(0, round((float) ($row['available_stock'] ?? 0), 2));
    
    $inventory[] = $row;
}

$inventory_summary = [
    'total_items' => count($inventory),
    'consumables' => 0,
    'equipment' => 0,
    'low_stock' => 0,
    'damaged_items' => 0,
    'available_total' => 0,
    'in_use_total' => 0
];

foreach ($inventory as $inventory_item) {
    $item_type = strtolower((string) ($inventory_item['item_type'] ?? ''));
    $available_qty = (float) ($inventory_item['available_stock'] ?? 0);
    $damaged_qty = (float) ($inventory_item['damaged_stock'] ?? 0);
    $in_use_qty = (float) ($inventory_item['in_use'] ?? 0);
    $total_qty = (float) ($inventory_item['total_stock'] ?? 0);
    $threshold_qty = (float) ($inventory_item['low_stock_threshold'] ?? 0);

    if ($item_type === 'consumable') {
        $inventory_summary['consumables']++;
    } else {
        $inventory_summary['equipment']++;
    }

    if ($available_qty <= $threshold_qty) {
        $inventory_summary['low_stock']++;
    }

    if ($damaged_qty > 0) {
        $inventory_summary['damaged_items']++;
    }

    $inventory_summary['available_total'] += max(0, $available_qty);
    $inventory_summary['in_use_total'] += max(0, $in_use_qty);
}

$equipment_inventory = [];
$consumable_inventory = [];
$inventory_type_summary = [
    'equipment' => [
        'label' => 'Equipment',
        'count' => 0,
        'available' => 0,
        'in_use' => 0,
        'low_stock' => 0
    ],
    'consumable' => [
        'label' => 'Consumables',
        'count' => 0,
        'available' => 0,
        'in_use' => 0,
        'low_stock' => 0
    ]
];

foreach ($inventory as $inventory_item) {
    $type_key = strtolower((string) ($inventory_item['item_type'] ?? 'equipment')) === 'consumable' ? 'consumable' : 'equipment';
    if ($type_key === 'consumable') {
        $consumable_inventory[] = $inventory_item;
    } else {
        $equipment_inventory[] = $inventory_item;
    }

    $inventory_type_summary[$type_key]['count']++;
    $inventory_type_summary[$type_key]['available'] += max(0, (float) ($inventory_item['available_stock'] ?? 0));
    $inventory_type_summary[$type_key]['in_use'] += max(0, (float) ($inventory_item['in_use'] ?? 0));
    if ((float) ($inventory_item['available_stock'] ?? 0) <= (float) ($inventory_item['low_stock_threshold'] ?? 0)) {
        $inventory_type_summary[$type_key]['low_stock']++;
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<style>
    .inventory-container {
        padding: 0;
    }
    
    .page-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 18px;
        margin-bottom: 22px;
        padding: 22px 24px;
        background: linear-gradient(135deg, rgba(212, 175, 55, 0.12), rgba(255,255,255,0.02));
        border: 1px solid rgba(212, 175, 55, 0.16);
        border-radius: 18px;
        box-shadow: 0 20px 40px rgba(0, 0, 0, 0.18);
    }

    .page-header-copy h1 {
        color: var(--accent-gold);
        margin: 0;
        font-size: 30px;
        line-height: 1.1;
    }

    .page-header-copy p {
        color: var(--text-muted);
        margin: 8px 0 0;
        max-width: 620px;
        line-height: 1.5;
    }

    .page-header-badges {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        margin-top: 14px;
    }

    .header-badge {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 8px 12px;
        border-radius: 999px;
        background: rgba(255,255,255,0.04);
        border: 1px solid rgba(255,255,255,0.08);
        color: var(--text-main);
        font-size: 12px;
        font-weight: 600;
    }

    .header-badge i {
        color: var(--accent-gold);
    }

    .page-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        justify-content: flex-end;
    }

    .page-action-form {
        display: inline-flex;
    }
    
    .btn-add {
        background: var(--accent-gold);
        color: #000;
        border: none;
        padding: 10px 20px;
        border-radius: 6px;
        font-weight: 600;
        cursor: pointer;
        display: flex;
        align-items: center;
        gap: 8px;
        transition: transform 0.2s;
        text-decoration: none;
    }
    
    .btn-add:hover {
        transform: translateY(-2px);
        box-shadow: 0 12px 24px rgba(212, 175, 55, 0.22);
    }
    
    .btn-outline {
        background: rgba(255,255,255,0.02);
        border: 1px solid var(--border-color);
        color: var(--text-main);
        padding: 10px 20px;
        border-radius: 10px;
        font-weight: 600;
        cursor: pointer;
        display: flex;
        align-items: center;
        gap: 8px;
        transition: all 0.2s;
        text-decoration: none;
    }
    
    .btn-outline:hover {
        background: rgba(255,255,255,0.05);
        border-color: rgba(212, 175, 55, 0.35);
        color: var(--accent-gold);
    }

    .inventory-overview {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 14px;
        margin-bottom: 18px;
    }

    .overview-card {
        min-width: 0;
        background: linear-gradient(180deg, rgba(255,255,255,0.03), rgba(255,255,255,0.01));
        border: 1px solid var(--border-color);
        border-radius: 16px;
        padding: 18px;
        position: relative;
        overflow: hidden;
    }

    .overview-card::before {
        content: "";
        position: absolute;
        inset: 0 auto 0 0;
        width: 4px;
        background: var(--accent-gold);
        opacity: 0.9;
    }

    .overview-label {
        color: var(--text-muted);
        font-size: 12px;
        text-transform: uppercase;
        letter-spacing: 0.08em;
        margin-bottom: 8px;
    }

    .overview-value {
        color: var(--text-main);
        font-size: 30px;
        font-weight: 800;
        line-height: 1;
        margin-bottom: 6px;
    }

    .overview-note {
        color: var(--text-muted);
        font-size: 12px;
        line-height: 1.4;
    }

    .inventory-alert-strip {
        display: flex;
        flex-wrap: wrap;
        gap: 12px;
        margin-bottom: 18px;
    }

    .inventory-alert {
        flex: 1 1 240px;
        display: flex;
        align-items: flex-start;
        gap: 12px;
        padding: 14px 16px;
        border-radius: 14px;
        border: 1px solid var(--border-color);
        background: rgba(255,255,255,0.02);
    }

    .inventory-alert i {
        margin-top: 2px;
    }

    .inventory-alert strong {
        display: block;
        margin-bottom: 4px;
        color: var(--text-main);
    }

    .inventory-alert span {
        color: var(--text-muted);
        font-size: 12px;
        line-height: 1.45;
    }

    .inventory-alert.warning {
        border-color: rgba(255, 193, 7, 0.28);
        background: rgba(255, 193, 7, 0.08);
    }

    .inventory-alert.warning i {
        color: #ffc107;
    }

    .inventory-alert.danger {
        border-color: rgba(244, 67, 54, 0.28);
        background: rgba(244, 67, 54, 0.08);
    }

    .inventory-alert.danger i {
        color: #f44336;
    }

    .inventory-alert.info {
        border-color: rgba(52, 152, 219, 0.24);
        background: rgba(52, 152, 219, 0.08);
    }

    .inventory-alert.info i {
        color: #3498db;
    }

    .inventory-table-container {
        background: linear-gradient(180deg, rgba(255,255,255,0.02), rgba(255,255,255,0.01));
        border: 1px solid var(--border-color);
        border-radius: 18px;
        overflow: hidden;
        box-shadow: 0 18px 40px rgba(0, 0, 0, 0.16);
    }

    .inventory-sections {
        display: grid;
        grid-template-columns: 1fr;
        gap: 18px;
    }

    .inventory-section-card {
        background: linear-gradient(180deg, rgba(255,255,255,0.02), rgba(255,255,255,0.01));
        border: 1px solid var(--border-color);
        border-radius: 18px;
        overflow: hidden;
        box-shadow: 0 18px 40px rgba(0, 0, 0, 0.16);
    }

    .inventory-section-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 16px;
        padding: 18px 20px;
        border-bottom: 1px solid rgba(255,255,255,0.06);
        background: rgba(255,255,255,0.015);
    }

    .inventory-section-title {
        margin: 0;
        color: var(--text-main);
        font-size: 18px;
    }

    .inventory-section-copy {
        margin: 5px 0 0;
        color: var(--text-muted);
        font-size: 12px;
        line-height: 1.45;
    }

    .inventory-section-meta {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        justify-content: flex-end;
    }

    .inventory-empty-state {
        text-align: center;
        padding: 32px 20px;
        color: var(--text-muted);
    }

    .inventory-table-toolbar {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 14px;
        padding: 16px 20px;
        border-bottom: 1px solid rgba(255,255,255,0.06);
        background: rgba(255,255,255,0.015);
    }

    .inventory-table-toolbar h3 {
        margin: 0;
        color: var(--text-main);
        font-size: 16px;
    }

    .inventory-table-toolbar p {
        margin: 4px 0 0;
        color: var(--text-muted);
        font-size: 12px;
    }

    .table-toolbar-meta {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
    }

    .toolbar-chip {
        padding: 7px 11px;
        border-radius: 999px;
        font-size: 11px;
        font-weight: 700;
        letter-spacing: 0.04em;
        text-transform: uppercase;
        background: rgba(255,255,255,0.04);
        border: 1px solid rgba(255,255,255,0.07);
        color: var(--text-muted);
    }
    
    .inventory-table {
        width: 100%;
        border-collapse: collapse;
    }
    
    .inventory-table th {
        text-align: left;
        padding: 16px 20px;
        border-bottom: 1px solid var(--border-color);
        color: var(--text-muted);
        font-weight: 600;
        font-size: 12px;
        text-transform: uppercase;
        letter-spacing: 0.08em;
        white-space: nowrap;
        background: rgba(255,255,255,0.02);
    }
    
    .inventory-table td {
        padding: 16px 20px;
        border-bottom: 1px solid rgba(255,255,255,0.05);
        color: var(--text-main);
        font-size: 14px;
        vertical-align: middle;
    }
    
    .inventory-table tr:last-child td {
        border-bottom: none;
    }
    
    .inventory-table tr:hover {
        background: rgba(255,255,255,0.025);
    }

    .item-cell {
        min-width: 220px;
    }

    .item-title {
        font-weight: 700;
        color: var(--text-main);
        margin-bottom: 4px;
    }

    .item-subtitle {
        color: var(--text-muted);
        font-size: 12px;
    }

    .type-pill {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 6px 10px;
        border-radius: 999px;
        font-size: 11px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.04em;
    }

    .type-pill.consumable {
        color: #4db6ff;
        background: rgba(52, 152, 219, 0.12);
        border: 1px solid rgba(52, 152, 219, 0.22);
    }

    .type-pill.equipment {
        color: #c889ff;
        background: rgba(155, 89, 182, 0.12);
        border: 1px solid rgba(155, 89, 182, 0.22);
    }

    .metric-stack {
        display: inline-flex;
        flex-direction: column;
        gap: 4px;
    }

    .metric-value {
        font-weight: 700;
        color: var(--text-main);
    }

    .metric-caption {
        color: var(--text-muted);
        font-size: 11px;
    }

    .stock-meta {
        margin-top: 6px;
        font-size: 11px;
        color: var(--text-muted);
    }
    
    .stock-badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 6px 10px;
        border-radius: 999px;
        font-size: 12px;
        font-weight: 700;
    }
    
    .stock-good { background: rgba(76, 175, 80, 0.1); color: #4caf50; }
    .stock-low { background: rgba(255, 193, 7, 0.1); color: #ffc107; }
    .stock-out { background: rgba(244, 67, 54, 0.1); color: #f44336; }

    .health-pill {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 6px 10px;
        border-radius: 999px;
        font-size: 11px;
        font-weight: 700;
    }

    .health-pill.healthy {
        background: rgba(76, 175, 80, 0.1);
        color: #4caf50;
    }

    .health-pill.warning {
        background: rgba(255, 193, 7, 0.1);
        color: #ffc107;
    }

    .health-pill.danger {
        background: rgba(244, 67, 54, 0.1);
        color: #f44336;
    }

    .action-group {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        justify-content: flex-end;
        flex-wrap: wrap;
    }
    
    .action-btn {
        background: none;
        border: 1px solid var(--border-color);
        color: var(--text-muted);
        width: 34px;
        height: 34px;
        border-radius: 9px;
        cursor: pointer;
        font-size: 12px;
        transition: all 0.2s;
        display: inline-flex;
        align-items: center;
        justify-content: center;
    }
    
    .action-btn:hover {
        border-color: var(--accent-gold);
        color: var(--accent-gold);
        background: rgba(212, 175, 55, 0.08);
    }
    
    .action-btn.damage:hover {
        border-color: #f44336;
        color: #f44336;
        background: rgba(244, 67, 54, 0.08);
    }

    /* Modal Styles */
    .form-group {
        margin-bottom: 15px;
    }
    
    .form-label {
        display: block;
        margin-bottom: 8px;
        color: var(--text-muted);
        font-size: 14px;
    }
    
    .form-control {
        width: 100%;
        padding: 10px;
        background: rgba(255,255,255,0.05);
        border: 1px solid var(--border-color);
        border-radius: 6px;
        color: var(--text-main);
        font-size: 14px;
    }
    
    .form-control:focus {
        border-color: var(--accent-gold);
        outline: none;
    }

    @media (max-width: 992px) {
        .page-header {
            flex-direction: column;
        }

        .page-actions {
            width: 100%;
            justify-content: flex-start;
        }

        .inventory-table-toolbar {
            flex-direction: column;
            align-items: flex-start;
        }

        .inventory-section-header {
            flex-direction: column;
        }

        .inventory-section-meta {
            justify-content: flex-start;
        }

    }
    @media (max-width: 576px) {
        .inventory-container .page-header {
            padding: 16px;
            gap: 16px;
            border-radius: 14px;
        }

        .inventory-container .page-header-copy h1 {
            font-size: 26px;
        }

        .inventory-container .page-header-copy p {
            font-size: 13px;
        }

        .inventory-container .page-actions {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 8px;
        }

        .inventory-container .page-action-form {
            min-width: 0;
        }

        .inventory-container .page-actions .btn-outline,
        .inventory-container .page-actions .btn-add {
            width: 100%;
            box-sizing: border-box;
            justify-content: center;
            padding: 10px 8px;
            font-size: 12px;
            line-height: 1.4;
            text-align: center;
            border-radius: 10px;
        }

        .inventory-container .inventory-overview {
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
        }

        .inventory-container .overview-card {
            padding: 14px 12px;
            border-radius: 12px;
        }

        .inventory-container .overview-label {
            font-size: 11px;
            letter-spacing: 0.04em;
            line-height: 1.4;
            min-height: 2.8em;
            margin-bottom: 6px;
        }

        .inventory-container .overview-value {
            font-size: 26px;
            line-height: 1.15;
            overflow-wrap: anywhere;
        }

        .inventory-container .overview-note {
            font-size: 11px;
            overflow-wrap: anywhere;
        }

        .inventory-container .inventory-section-header {
            padding: 16px;
        }
    }
</style>
<link rel="stylesheet" href="../assets/css/caterer-inventory.css?v=<?php echo filemtime(__DIR__ . '/../assets/css/caterer-inventory.css'); ?>">

<div class="inventory-container">
    <div class="page-header">
        <div class="page-actions">
            <form method="POST" class="page-action-form">
                <input type="hidden" name="action" value="seed_missing_booking_items">
                <button type="submit" class="btn-outline">
                    Add Missing Booking Items
                </button>
            </form>
            <form method="POST" class="page-action-form">
                <input type="hidden" name="action" value="sync_inventory_counts">
                <button type="submit" class="btn-outline">
                    Sync Counts
                </button>
            </form>
            <a href="service_mapping.php" class="btn-outline">
                Service Packages
            </a>
            <button class="btn-outline" onclick="openCompletedEventsModal()" style="border-color: #27ae60; color: #27ae60;">
                Returned Items
            </button>
            <button class="btn-add" onclick="openModal('addModal')">
                Add Item
            </button>
        </div>
    </div>

    <div class="inventory-overview">
        <div class="overview-card">
            <div class="overview-label">Total Items</div>
            <div class="overview-value"><?php echo $inventory_summary['total_items']; ?></div>
        </div>
        <div class="overview-card">
            <div class="overview-label">Low Stock Alerts</div>
            <div class="overview-value"><?php echo $inventory_summary['low_stock']; ?></div>
        </div>
        <div class="overview-card">
            <div class="overview-label">Damaged Items</div>
            <div class="overview-value"><?php echo $inventory_summary['damaged_items']; ?></div>
        </div>
        <div class="overview-card">
            <div class="overview-label">In Use</div>
            <div class="overview-value"><?php echo number_format($inventory_summary['in_use_total'], 0); ?></div>
        </div>
        <div class="overview-card">
            <div class="overview-label">Equipment</div>
            <div class="overview-value"><?php echo $inventory_type_summary['equipment']['count']; ?></div>
        </div>
        <div class="overview-card">
            <div class="overview-label">Consumables</div>
            <div class="overview-value"><?php echo $inventory_type_summary['consumable']['count']; ?></div>
        </div>
    </div>
    
    <?php if ($success_msg): ?>
        <dialog id="inventory-success-message" aria-labelledby="inventory-success-title" aria-describedby="inventory-success-description">
            <span class="inventory-success-icon" aria-hidden="true"><i class="fas fa-check"></i></span>
            <h2 id="inventory-success-title">Success!</h2>
            <p id="inventory-success-description"><?php echo htmlspecialchars($success_msg, ENT_QUOTES, 'UTF-8'); ?></p>
            <button type="button" class="inventory-success-ok" onclick="document.getElementById('inventory-success-message').close()">OK</button>
        </dialog>
    <?php endif; ?>
    
    <?php if ($error_msg): ?>
        <div class="alert alert-error"><?php echo $error_msg; ?></div>
    <?php endif; ?>

    <?php if ($inventory_summary['low_stock'] > 0): ?>
    <dialog id="inventory-low-stock-notice" aria-labelledby="inventory-low-stock-title" aria-describedby="inventory-low-stock-description">
        <span class="inventory-warning-icon" aria-hidden="true"><i class="fas fa-exclamation-triangle"></i></span>
        <h2 id="inventory-low-stock-title"><?php echo (int) $inventory_summary['low_stock']; ?> low-stock item<?php echo $inventory_summary['low_stock'] === 1 ? '' : 's'; ?></h2>
        <p id="inventory-low-stock-description">Review items marked with warning badges so you can restock before upcoming events.</p>
        <form method="dialog"><button type="submit" autofocus>Got it</button></form>
    </dialog>
    <?php endif; ?>
    
    <div class="inventory-sections">
        <?php
        $inventory_sections = [
            [
                'title' => 'Equipment Inventory',
                'description' => '',
                'items' => $equipment_inventory,
                'summary' => $inventory_type_summary['equipment']
            ],
            [
                'title' => 'Consumables Inventory',
                'description' => '',
                'items' => $consumable_inventory,
                'summary' => $inventory_type_summary['consumable']
            ]
        ];
        ?>
        <div class="inventory-type-buttons" aria-label="Inventory type">
            <?php foreach ($inventory_sections as $index => $section): ?>
                <button type="button" class="inventory-type-button" id="inventory-button-<?php echo $index; ?>" aria-controls="inventory-panel-<?php echo $index; ?>" aria-pressed="false" onclick="selectInventoryType(<?php echo $index; ?>)">
                    <?php echo htmlspecialchars($section['title']); ?>
                    <span class="section-item-count"><?php echo $section['summary']['count']; ?> items</span>
                </button>
            <?php endforeach; ?>
        </div>
        <?php foreach ($inventory_sections as $index => $section): ?>
            <div class="inventory-section-card inventory-type-panel" id="inventory-panel-<?php echo $index; ?>" role="region" aria-live="polite" aria-labelledby="inventory-button-<?php echo $index; ?>" hidden>
                <?php if ($requested_inventory_panel === $index): ?>
                <div class="inventory-section-header">
                    <div class="inventory-section-meta">
                        <div class="section-stat"><span>Available</span><strong><?php echo number_format($section['summary']['available'], 0); ?></strong></div>
                        <div class="section-stat"><span>In use</span><strong><?php echo number_format($section['summary']['in_use'], 0); ?></strong></div>
                        <div class="section-stat<?php echo $section['summary']['low_stock'] > 0 ? ' needs-attention' : ''; ?>"><span>Low stock</span><strong><?php echo $section['summary']['low_stock']; ?></strong></div>
                    </div>
                </div>
                <?php if (empty($section['items'])): ?>
                    <div class="inventory-empty-state">
                        No <?php echo strtolower($section['summary']['label']); ?> items yet. Click Add Item to start.
                    </div>
                <?php else: ?>
                    <div class="inventory-list-controls">
                        <label>Search items
                            <input type="search" class="inventory-search" placeholder="Search by item name" autocomplete="off">
                        </label>
                    </div>
                    <div class="inventory-table-scroll">
                        <table class="inventory-table">
                            <thead>
                                <tr>
                                    <th>Item Name</th>
                                    <th>Type</th>
                                    <th>Total Stock</th>
                                    <th>Available</th>
                                    <th>In Use</th>
                                    <th>Damaged</th>
                                    <th>Status</th>
                                    <th style="text-align: right;">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($section['items'] as $item): ?>
                            <tr>
                                <td data-label="Item" class="item-cell">
                                    <div class="item-title"><span class="inventory-desktop-name"><?php echo htmlspecialchars($item['item_name']); ?></span><button type="button" class="inventory-item-toggle" data-item-id="<?php echo (int) $item['id']; ?>" aria-expanded="false"><?php echo htmlspecialchars($item['item_name']); ?></button></div>
                                    <div class="item-subtitle">Low stock alert at <?php echo floatval($item['low_stock_threshold']); ?></div>
                                </td>
                                <td data-label="Type">
                                    <?php if ($item['item_type'] == 'consumable'): ?>
                                        <span class="type-pill consumable">Consumable</span>
                                    <?php else: ?>
                                        <span class="type-pill equipment">Equipment</span>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Total stock">
                                    <div class="metric-stack">
                                        <span class="metric-value"><?php echo floatval($item['total_stock']); ?></span>
                                        <span class="metric-caption">Total recorded</span>
                                    </div>
                                </td>
                                <td data-label="Available">
                                    <?php 
                                        $class = 'stock-good';
                                        if ($item['available_stock'] <= 0) $class = 'stock-out';
                                        elseif ($item['available_stock'] <= $item['low_stock_threshold']) $class = 'stock-low';

                                        $status_class = 'healthy';
                                        $status_icon = 'fa-circle-check';
                                        $status_label = 'Ready';
                                        $stock_meta = 'Ready for booking';

                                        if ($item['available_stock'] <= 0 && $item['in_use'] > 0) {
                                            $status_class = 'danger';
                                            $status_icon = 'fa-box-open';
                                            $status_label = 'Fully Assigned';
                                            $stock_meta = 'All stock is already assigned to bookings';
                                        } elseif ($item['available_stock'] <= 0) {
                                            $status_class = 'danger';
                                            $status_icon = 'fa-circle-xmark';
                                            $status_label = 'Out of Stock';
                                            $stock_meta = 'No free stock left';
                                        } elseif ($item['available_stock'] <= $item['low_stock_threshold']) {
                                            $status_class = 'warning';
                                            $status_icon = 'fa-triangle-exclamation';
                                            $status_label = 'Low Stock';
                                            $stock_meta = 'Restock soon for upcoming bookings';
                                        } elseif ($item['damaged_stock'] > 0) {
                                            $status_class = 'warning';
                                            $status_icon = 'fa-wrench';
                                            $status_label = 'Needs Check';
                                            $stock_meta = 'Some units are marked as damaged';
                                        }
                                    ?>
                                    <span class="stock-badge <?php echo $class; ?>">
                                        <?php echo floatval($item['available_stock']); ?>
                                    </span>
                                    <div class="stock-meta"><?php echo htmlspecialchars($stock_meta); ?></div>
                                </td>
                                <td data-label="In use">
                                    <?php if ($item['in_use'] > 0): ?>
                                        <div class="metric-stack">
                                            <span class="metric-value" style="color: #4db6ff;"><?php echo floatval($item['in_use']); ?></span>
                                            <span class="metric-caption">Currently assigned</span>
                                        </div>
                                    <?php else: ?>
                                        <span style="color: var(--text-muted);">None</span>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Damaged">
                                    <span style="color: <?php echo $item['damaged_stock'] > 0 ? '#f44336' : 'var(--text-main)'; ?>; font-weight: 700;">
                                        <?php echo floatval($item['damaged_stock']); ?>
                                    </span>
                                </td>
                                <td data-label="Status">
                                    <span class="health-pill <?php echo $status_class; ?>">
                                        <?php echo htmlspecialchars($status_label); ?>
                                    </span>
                                </td>
                                <td data-label="Actions" style="text-align: right;">
                                    <div class="action-group">
                                        <button class="action-btn inventory-history-action" onclick='viewUsage(<?php echo $item['id']; ?>, "<?php echo htmlspecialchars($item['item_name']); ?>")' title="View History">
                                            History
                                        </button>
                                        <button class="action-btn" onclick='openEditModal(<?php echo json_encode($item); ?>)' title="Edit Item">
                                            Edit
                                        </button>
                                        <button class="action-btn damage" onclick='openReturnModal(<?php echo json_encode($item); ?>)' title="Report Damage / Repair">
                                            Repair
                                        </button>
                                    </div>
                                </td>
                            </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="inventory-pagination" aria-label="Inventory pages">
                        <span class="inventory-page-status" role="status"></span>
                        <div class="inventory-page-actions">
                            <button type="button" class="btn-outline inventory-prev">Previous</button>
                            <button type="button" class="btn-outline inventory-next">Next</button>
                        </div>
                    </div>
                <?php endif; ?>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Usage History Modal -->
<div id="usageModal" class="caterer-modal">
    <div class="modal-dialog">
        <div class="modal-header">
            <h2>History: <span id="usageItemName" style="color: var(--text-main);"></span></h2>
            <button type="button" class="close-btn" aria-label="Close inventory history" onclick="closeModal('usageModal')">&times;</button>
        </div>
        <div class="modal-body">
            <div id="usageContent">Loading...</div>
        </div>
    </div>
</div>

<!-- Completed Events Modal -->
<div id="completedEventsModal" class="caterer-modal">
    <div class="modal-dialog" style="max-width: 800px;">
        <div class="modal-header">
            <h2 style="color: #27ae60;">Completed Events & Returns</h2>
            <button class="close-btn" onclick="closeModal('completedEventsModal')">&times;</button>
        </div>
        <div class="modal-body">
            <div id="completedEventsContent" style="max-height: 600px; overflow-y: auto;">Loading...</div>
        </div>
    </div>
</div>

<script>
    let inventoryLoadController;
    const inventoryViewKey = 'inventory-view-' + <?php echo json_encode((string) $user_id); ?>;
    function readInventoryView() {
        try { return JSON.parse(sessionStorage.getItem(inventoryViewKey)) || {}; } catch { return {}; }
    }
    function saveInventoryView(changes) {
        try { sessionStorage.setItem(inventoryViewKey, JSON.stringify({...readInventoryView(), ...changes})); } catch {}
    }
    function initializeInventoryList(panel) {
        const search = panel.querySelector('.inventory-search');
        if (!search) return;
        const rows = Array.from(panel.querySelectorAll('.inventory-table tbody tr'));
        const previous = panel.querySelector('.inventory-prev');
        const next = panel.querySelector('.inventory-next');
        const status = panel.querySelector('.inventory-page-status');
        const pageSize = 8;
        let currentPage = 0;
        function render() {
            const query = search.value.trim().toLocaleLowerCase();
            const matches = rows.filter(row => row.querySelector('.item-title').textContent.toLocaleLowerCase().includes(query));
            const pageCount = Math.ceil(matches.length / pageSize);
            currentPage = Math.max(0, Math.min(currentPage, pageCount - 1));
            rows.forEach(row => { row.hidden = true; });
            const start = currentPage * pageSize;
            panel.dataset.listPage = currentPage;
            matches.slice(start, start + pageSize).forEach(row => { row.hidden = false; });
            status.textContent = matches.length
                ? `${start + 1}–${Math.min(start + pageSize, matches.length)} of ${matches.length} items · Page ${currentPage + 1} of ${pageCount}`
                : 'No matching items.';
            previous.disabled = currentPage === 0;
            next.disabled = currentPage + 1 >= pageCount;
        }
        search.addEventListener('input', () => { currentPage = 0; render(); });
        previous.addEventListener('click', () => { currentPage--; render(); });
        next.addEventListener('click', () => { currentPage++; render(); });
        panel.restoreInventoryList = (query, page) => { search.value = query; currentPage = page; render(); };
        render();
    }
    async function selectInventoryType(index, refresh = false) {
        if (refresh && inventoryLoadController) return;
        if (inventoryLoadController) inventoryLoadController.abort();
        const shouldClose = !refresh && document.getElementById(`inventory-button-${index}`).getAttribute('aria-pressed') === 'true';
        if (shouldClose) {
            document.querySelectorAll('.inventory-type-button').forEach(button => {
                button.setAttribute('aria-pressed', 'false');
            });
            document.querySelectorAll('.inventory-type-panel').forEach(panel => {
                panel.hidden = true;
                panel.replaceChildren();
                panel.removeAttribute('aria-busy');
            });
            inventoryLoadController = null;
            saveInventoryView({panel: null});
            return;
        }
        inventoryLoadController = new AbortController();
        const controller = inventoryLoadController;
        saveInventoryView({panel: index});
        const existingPanel = document.getElementById(`inventory-panel-${index}`);
        const previousQuery = existingPanel.querySelector('.inventory-search')?.value || '';
        const previousPage = Number(existingPanel.dataset.listPage || 0);
        document.querySelectorAll('.inventory-type-button').forEach((button, buttonIndex) => {
            button.setAttribute('aria-pressed', buttonIndex === index ? 'true' : 'false');
        });
        document.querySelectorAll('.inventory-type-panel').forEach((panel, panelIndex) => {
            panel.hidden = panelIndex !== index;
            if (!refresh) panel.replaceChildren();
            panel.removeAttribute('aria-busy');
        });
        const panel = document.getElementById(`inventory-panel-${index}`);
        panel.setAttribute('aria-busy', 'true');
        if (!refresh) panel.innerHTML = '<div class="inventory-empty-state">Loading latest inventory...</div>';
        try {
            const url = new URL('inventory.php', window.location.href);
            url.searchParams.set('inventory_panel', index);
            const response = await fetch(url, { cache: 'no-store', signal: controller.signal });
            if (!response.ok || response.redirected) throw new Error('Inventory unavailable');
            const page = new DOMParser().parseFromString(await response.text(), 'text/html');
            const freshPanel = page.getElementById(`inventory-panel-${index}`);
            if (!freshPanel || !freshPanel.children.length) throw new Error('Inventory unavailable');
            if (controller.signal.aborted) return;
            if (refresh && panel.contains(document.activeElement)) return;
            panel.replaceChildren(...freshPanel.childNodes);
            restoreExpandedInventoryItems(panel);
            initializeInventoryList(panel);
            if (refresh && panel.restoreInventoryList) panel.restoreInventoryList(previousQuery, previousPage);
            page.querySelectorAll('.inventory-type-button .section-item-count').forEach((count, countIndex) => {
                document.querySelectorAll('.inventory-type-button .section-item-count')[countIndex].textContent = count.textContent;
            });
            const overview = page.querySelector('.inventory-overview');
            if (overview) document.querySelector('.inventory-overview').replaceChildren(...overview.childNodes);
        } catch (error) {
            if (error.name !== 'AbortError' && !refresh) {
                panel.innerHTML = '<div class="inventory-empty-state">Unable to load inventory. Close and reopen this inventory to try again.</div>';
            }
        } finally {
            if (!controller.signal.aborted) panel.removeAttribute('aria-busy');
            if (inventoryLoadController === controller) inventoryLoadController = null;
        }
    }

    let usageLoadController;
    function viewUsage(itemId, itemName, refresh = false) {
        if (usageLoadController) usageLoadController.abort();
        usageLoadController = new AbortController();
        const controller = usageLoadController;
        saveInventoryView({history: {id: itemId, name: itemName}});
        document.getElementById('usageItemName').textContent = itemName;
        if (!refresh) document.getElementById('usageContent').innerHTML = '<div style="text-align:center; padding:20px;">Loading usage data...</div>';
        openModal('usageModal');
        
        // Fetch usage data via AJAX
        fetch(`get_inventory_usage.php?id=${encodeURIComponent(itemId)}`, {cache: 'no-store', signal: controller.signal})
            .then(response => { if (!response.ok || response.redirected) throw new Error('History unavailable'); return response.text(); })
            .then(html => {
                const scrollTop = document.querySelector('#usageModal .modal-body').scrollTop;
                const expandedCustomers = Array.from(document.querySelectorAll('#usageContent .usage-customer[open]'), group => group.dataset.customerId);
                document.getElementById('usageContent').innerHTML = html;
                if (refresh) document.querySelectorAll('#usageContent .usage-customer').forEach(group => { group.open = expandedCustomers.includes(group.dataset.customerId); });
                if (refresh) document.querySelector('#usageModal .modal-body').scrollTop = scrollTop;
            })
            .catch(err => {
                if (err.name !== 'AbortError' && !refresh) document.getElementById('usageContent').innerHTML = '<div style="color:red; text-align:center;">Error loading data.</div>';
            });
    }

    function openCompletedEventsModal() {
        document.getElementById('completedEventsContent').innerHTML = '<div style="text-align:center; padding:20px;">Loading completed events...</div>';
        openModal('completedEventsModal');
        
        // Fetch completed events data via AJAX
        fetch(`get_completed_events_inventory.php`)
            .then(response => response.text())
            .then(html => {
                document.getElementById('completedEventsContent').innerHTML = html;
            })
            .catch(err => {
                document.getElementById('completedEventsContent').innerHTML = '<div style="color:red; text-align:center;">Error loading data.</div>';
            });
    }
</script>

<!-- Add Item Modal -->
<div id="addModal" class="caterer-modal">
    <div class="modal-dialog">
        <div class="modal-header">
            <h2>Add Inventory Item</h2>
            <button type="button" class="close-btn" aria-label="Close add inventory item" onclick="closeModal('addModal')">&times;</button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" value="add">
                
                <div class="form-group">
                    <label class="form-label">Item Name</label>
                    <input type="text" name="item_name" class="form-control" required placeholder="e.g. Plates, Chicken, Gas Stove">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Type</label>
                    <select name="item_type" class="form-control" required>
                        <option value="consumable">Consumable</option>
                        <option value="equipment">Equipment</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Total Stock</label>
                    <input type="number" step="0.01" name="total_stock" class="form-control" required min="0" placeholder="0.00">
                </div>
                <input type="hidden" name="unit" value="pcs">

                <div class="form-group">
                    <label class="form-label">Low Stock Warning Threshold</label>
                    <input type="number" step="0.01" name="low_stock_threshold" class="form-control" value="10" min="0">

                </div>
            </div>
            <div class="modal-footer">
                <button type="submit" class="btn btn-primary">Save Item</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Item Modal -->
<div id="editModal" class="caterer-modal">
    <div class="modal-dialog">
        <div class="modal-header">
            <h2>Edit Inventory Item</h2>
            <button type="button" class="close-btn" aria-label="Close edit inventory" onclick="closeModal('editModal')">&times;</button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="item_id" id="edit_item_id">
                
                <div class="form-group">
                    <label class="form-label" for="edit_item_name">Item Name</label>
                    <input type="text" name="item_name" id="edit_item_name" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label" for="edit_item_type">Type</label>
                    <select name="item_type" id="edit_item_type" class="form-control" required>
                        <option value="consumable">Consumable</option>
                        <option value="equipment">Equipment</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label" for="edit_total_stock">Total Stock</label>
                    <input type="number" step="0.01" name="total_stock" id="edit_total_stock" class="form-control" required min="0">
                </div>
                <input type="hidden" name="unit" id="edit_unit">

                <div class="form-group">
                    <label class="form-label" for="edit_low_threshold">Low Stock Alert</label>
                    <input type="number" step="0.01" name="low_stock_threshold" id="edit_low_threshold" class="form-control" min="0">
                </div>
                
            </div>
            <div class="modal-footer">
                <button type="submit" class="btn btn-primary">Update Item</button>
            </div>
        </form>
    </div>
</div>

<!-- Return/Damage Modal -->
<div id="returnModal" class="caterer-modal">
    <div class="modal-dialog">
        <div class="modal-header">
            <h2>Stock Adjustment / Damage Report</h2>
            <button type="button" class="close-btn" aria-label="Close stock adjustment" onclick="closeModal('returnModal')">&times;</button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" value="return">
                <input type="hidden" name="item_id" id="return_item_id">
                
                <div class="form-group">
                    <label class="form-label" for="return_item_name">Item Name</label>
                    <input type="text" id="return_item_name" class="form-control" readonly>
                </div>
                
                <div class="form-group">
                    <label class="form-label" for="return_damaged_qty">Report Damage</label>
                    <input type="number" step="0.01" name="damaged_qty" id="return_damaged_qty" class="form-control" min="0" value="0" aria-describedby="damage_hint">
                    <small class="adjustment-hint" id="damage_hint">Available to damaged stock.</small>
                </div>
                
                <div class="form-group">
                    <label class="form-label" for="return_repaired_qty">Repair / Restock</label>
                    <input type="number" step="0.01" name="returned_qty" id="return_repaired_qty" class="form-control" min="0" value="0" aria-describedby="repair_hint">
                    <small class="adjustment-hint" id="repair_hint">Damaged to available stock.</small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="submit" class="btn btn-primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<script>
    const expandedInventoryItems = new Set();
    function restoreExpandedInventoryItems(root) {
        root.querySelectorAll('.inventory-item-toggle').forEach(button => {
            const expanded = expandedInventoryItems.has(button.dataset.itemId);
            button.setAttribute('aria-expanded', String(expanded));
            button.closest('tr').classList.toggle('inventory-item-expanded', expanded);
        });
    }
    document.addEventListener('click', event => {
        if (!window.matchMedia('(max-width: 767px)').matches) return;
        const row = event.target.closest('.inventory-table tbody tr');
        if (!row || event.target.closest('.action-group, a, input, select, form')) return;
        const toggle = row.querySelector('.inventory-item-toggle');
        if (!toggle) return;
        if (expandedInventoryItems.has(toggle.dataset.itemId)) expandedInventoryItems.delete(toggle.dataset.itemId);
        else expandedInventoryItems.add(toggle.dataset.itemId);
        restoreExpandedInventoryItems(row);
    });
    const inventorySuccessMessage = document.getElementById('inventory-success-message');
    const lowStockNotice = document.getElementById('inventory-low-stock-notice');
    if (lowStockNotice) {
        const showLowStockNotice = () => lowStockNotice.showModal();
        if (inventorySuccessMessage) inventorySuccessMessage.addEventListener('close', showLowStockNotice, { once: true });
        else showLowStockNotice();
    }
    if (inventorySuccessMessage) {
        inventorySuccessMessage.showModal();
        document.body.classList.add('inventory-success-open');
        inventorySuccessMessage.addEventListener('close', () => {
            document.body.classList.remove('inventory-success-open');
        });
    }

    function openModal(modalId) {
        document.getElementById(modalId).style.display = 'flex';
        if (modalId === 'addModal') saveInventoryView({addOpen: true});
    }
    
    function closeModal(modalId) {
        document.getElementById(modalId).style.display = 'none';
        if (modalId === 'addModal') saveInventoryView({addOpen: false});
        if (modalId === 'usageModal') {
            if (usageLoadController) usageLoadController.abort();
            saveInventoryView({history: null});
        }
    }

    document.addEventListener('DOMContentLoaded', () => {
        const saved = readInventoryView();
        const addForm = document.querySelector('#addModal form');
        const draftFields = ['item_name', 'item_type', 'total_stock', 'low_stock_threshold'];
        const addSucceeded = <?php echo json_encode($success_msg === 'Item added successfully.'); ?>;
        if (addSucceeded) {
            saveInventoryView({addDraft: null, addOpen: false});
            addForm.reset();
            document.getElementById('addModal').style.display = 'none';
        }
        if (!addSucceeded && saved.addDraft) {
            draftFields.forEach(name => {
                if (typeof saved.addDraft[name] === 'string') addForm.elements.namedItem(name).value = saved.addDraft[name];
            });
        }
        const rememberDraft = () => {
            const draft = {};
            draftFields.forEach(name => { draft[name] = addForm.elements.namedItem(name).value; });
            saveInventoryView({addDraft: draft});
        };
        addForm.addEventListener('input', rememberDraft);
        addForm.addEventListener('change', rememberDraft);
        addForm.addEventListener('submit', rememberDraft);
        window.addEventListener('pagehide', rememberDraft);
        if (saved.addOpen && !addSucceeded && !inventorySuccessMessage) openModal('addModal');
        if (saved.panel === 0 || saved.panel === 1) selectInventoryType(saved.panel);
        if (saved.history && Number.isInteger(Number(saved.history.id)) && Number(saved.history.id) > 0) viewUsage(saved.history.id, saved.history.name);
    });
    window.setInterval(() => {
        if (document.hidden) return;
        const saved = readInventoryView();
        if (saved.panel === 0 || saved.panel === 1) selectInventoryType(saved.panel, true);
        if (saved.history && document.getElementById('usageModal').style.display === 'flex') viewUsage(saved.history.id, saved.history.name, true);
    }, 15000);
    
    function openEditModal(item) {
        document.getElementById('edit_item_id').value = item.id;
        document.getElementById('edit_item_name').value = item.item_name;
        document.getElementById('edit_item_type').value = item.item_type || 'consumable';
        document.getElementById('edit_total_stock').value = item.total_stock;
        document.getElementById('edit_unit').value = item.unit;
        document.getElementById('edit_low_threshold').value = item.low_stock_threshold;
        openModal('editModal');
    }
    
    function openReturnModal(item) {
        document.getElementById('return_item_id').value = item.id;
        document.getElementById('return_item_name').value = item.item_name;
        openModal('returnModal');
    }
    
    // History, edit, and repair are dismissed explicitly with their close buttons.
    window.onclick = function(event) {
        if (event.target.classList.contains('caterer-modal') &&
            !['addModal', 'usageModal', 'editModal', 'returnModal'].includes(event.target.id)) {
            event.target.style.display = 'none';
        }
    }
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>


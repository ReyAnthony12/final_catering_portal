<?php
session_start();
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
function quantityReply(int $status, array $data): void {
    http_response_code($status);
    echo json_encode($data);
    exit;
}
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['store', 'caterer'], true)) quantityReply(401, ['error' => 'Please sign in again.']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validateCSRFToken($_POST['csrf_token'] ?? '')) quantityReply(403, ['error' => 'Please refresh and try again.']);
$store = (int) $_SESSION['user_id'];
$event = (int) ($_POST['event_id'] ?? 0);
$itemId = (int) ($_POST['item_id'] ?? 0);
$raw = (string) ($_POST['quantity'] ?? '');
if (!preg_match('/^\d{1,7}$/D', $raw) || (int) $raw > 1000000) quantityReply(422, ['error' => 'Enter a whole number from 0 to 1,000,000.']);
$quantity = (int) $raw;
mysqli_begin_transaction($conn);
try {
    $eventRow = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM events WHERE id=$event AND store_id=$store FOR UPDATE"));
    if (!$eventRow || $eventRow['status'] !== 'Reserved') throw new RuntimeException('Only items awaiting preparation can be edited.');
    $row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM event_inventory WHERE id=$itemId AND event_id=$event FOR UPDATE"));
    if (!$row || $row['quantity_released'] > 0 || $row['quantity_returned'] > 0) throw new RuntimeException('This item has already been released.');
    $name = mysqli_real_escape_string($conn, $row['item_name']);
    $stock = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM inventory_items WHERE store_id=$store AND item_name='$name' ORDER BY (item_type='consumable') DESC, id DESC LIMIT 1 FOR UPDATE"));
    if (!$stock) throw new RuntimeException('This item was not found in inventory.');
    $inventoryId = (int) $stock['id'];
    $order = (int) $eventRow['order_id'];
    $usage = mysqli_query($conn, "SELECT id, quantity_used FROM inventory_usage WHERE inventory_item_id=$inventoryId AND order_id=$order AND status IN ('reserved','active') FOR UPDATE");
    $usageIds = []; $previous = 0;
    while ($allocation = mysqli_fetch_assoc($usage)) { $usageIds[] = (int) $allocation['id']; $previous += (float) $allocation['quantity_used']; }
    $difference = $quantity - $previous;
    if ($difference > (float) $stock['available_stock']) throw new RuntimeException('Not enough available stock for that quantity.');
    if (!mysqli_query($conn, "UPDATE inventory_items SET available_stock=available_stock-($difference) WHERE id=$inventoryId")) throw new RuntimeException('Unable to update stock.');
    if ($usageIds) {
        $first = array_shift($usageIds);
        if (!mysqli_query($conn, "UPDATE inventory_usage SET quantity_used=$quantity WHERE id=$first")) throw new RuntimeException('Unable to update allocation.');
        if ($usageIds && !mysqli_query($conn, 'DELETE FROM inventory_usage WHERE id IN (' . implode(',', $usageIds) . ')')) throw new RuntimeException('Unable to update allocation.');
    } else {
        $date = mysqli_real_escape_string($conn, $eventRow['event_date']);
        if (!mysqli_query($conn, "INSERT INTO inventory_usage (inventory_item_id,order_id,event_date,quantity_used,status) VALUES ($inventoryId,$order,'$date',$quantity,'active')")) throw new RuntimeException('Unable to save allocation.');
    }
    if (!mysqli_query($conn, "UPDATE event_inventory SET quantity_required=$quantity WHERE id=$itemId")) throw new RuntimeException('Unable to save quantity.');
    mysqli_commit($conn);
    quantityReply(200, ['success' => true, 'quantity' => $quantity]);
} catch (Throwable $error) {
    mysqli_rollback($conn);
    quantityReply(422, ['error' => $error instanceof RuntimeException ? $error->getMessage() : 'Unable to save quantity. Please try again.']);
}

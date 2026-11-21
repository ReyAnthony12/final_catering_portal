<?php

/** Consume released allocations once; reservations have already reduced availability. */
function syncReleasedConsumableStock(mysqli $conn, int $storeId): void
{
    mysqli_begin_transaction($conn);
    try {
        $rows = mysqli_query($conn, "SELECT iu.id, iu.inventory_item_id, iu.quantity_used
            FROM inventory_usage iu JOIN inventory_items ii ON ii.id = iu.inventory_item_id
            WHERE ii.store_id = $storeId AND ii.item_type = 'consumable'
              AND iu.status IN ('reserved', 'active')
              AND EXISTS (SELECT 1 FROM events e WHERE e.order_id = iu.order_id
                  AND e.store_id = ii.store_id AND e.status IN ('Prepared', 'Completed')
                  AND NOT EXISTS (SELECT 1 FROM events newer
                      WHERE newer.store_id = e.store_id AND newer.order_id = e.order_id
                        AND newer.id > e.id
                        AND EXISTS (SELECT 1 FROM event_inventory nei WHERE nei.event_id = newer.id)))
            ORDER BY ii.id, iu.id FOR UPDATE");
        if (!$rows) throw new RuntimeException('Unable to read released consumables.');
        while ($row = mysqli_fetch_assoc($rows)) {
            $id = (int) $row['id'];
            $itemId = (int) $row['inventory_item_id'];
            $quantity = max(0, (float) $row['quantity_used']);
            if (!mysqli_query($conn, "UPDATE inventory_items
                SET total_stock = GREATEST(0, total_stock - $quantity) WHERE id = $itemId")
                || !mysqli_query($conn, "UPDATE inventory_usage SET status = 'consumed' WHERE id = $id")) {
                throw new RuntimeException('Unable to deduct released consumables.');
            }
        }
        mysqli_commit($conn);
    } catch (Throwable $error) {
        mysqli_rollback($conn);
        throw $error;
    }
}

/** Close booking allocations once the current event's return checklist is completed. */
function syncCompletedInventoryUsage(mysqli $conn, int $storeId): void
{
    $storeId = intval($storeId);
    syncReleasedConsumableStock($conn, $storeId);
    mysqli_query($conn, "UPDATE inventory_usage iu
        JOIN inventory_items ii ON ii.id = iu.inventory_item_id
        JOIN events e ON e.order_id = iu.order_id AND e.store_id = ii.store_id
        SET iu.status = CASE WHEN ii.item_type = 'equipment' THEN 'returned' ELSE 'consumed' END
        WHERE ii.store_id = '$storeId'
          AND e.status = 'Completed'
          AND iu.status IN ('reserved', 'active')
          AND EXISTS (SELECT 1 FROM event_inventory ei WHERE ei.event_id = e.id)
          AND NOT EXISTS (
              SELECT 1 FROM events newer
              WHERE newer.store_id = e.store_id AND newer.order_id = e.order_id
                AND newer.id > e.id
                AND EXISTS (SELECT 1 FROM event_inventory nei WHERE nei.event_id = newer.id)
          )");
}

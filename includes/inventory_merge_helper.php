<?php

// Keep category distinctions while combining duplicate names inside each category.
function mergeStoreInventoryDuplicates(mysqli $conn, int $storeId): int
{
    $conn->begin_transaction();
    try {
        $rows = $conn->query("SELECT * FROM inventory_items WHERE store_id = $storeId ORDER BY id FOR UPDATE");
        $groups = [];
        $merged = 0;
        while ($row = $rows->fetch_assoc()) {
            $key = strtolower(trim(preg_replace('/\s+/u', ' ', $row['item_name']))) . ':' . $row['item_type'];
            if (!isset($groups[$key])) { $groups[$key] = (int) $row['id']; continue; }
            $keep = $groups[$key];
            $duplicate = (int) $row['id'];
            if (!$conn->query("UPDATE inventory_items target JOIN inventory_items source ON source.id = $duplicate
                SET target.total_stock = target.total_stock + source.total_stock,
                    target.available_stock = target.available_stock + source.available_stock,
                    target.damaged_stock = target.damaged_stock + source.damaged_stock,
                    target.low_stock_threshold = GREATEST(target.low_stock_threshold, source.low_stock_threshold)
                WHERE target.id = $keep AND target.store_id = $storeId")) throw new RuntimeException('Unable to combine stock.');
            foreach (['service_inventory', 'inventory_usage', 'inventory_logs'] as $table) {
                if (!$conn->query("UPDATE $table SET inventory_item_id = $keep WHERE inventory_item_id = $duplicate")) throw new RuntimeException('Unable to preserve inventory references.');
            }
            if (!$conn->query("DELETE FROM inventory_items WHERE id = $duplicate AND store_id = $storeId")) throw new RuntimeException('Unable to remove duplicate.');
            $merged++;
        }
        $conn->commit();
        return $merged;
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }
}

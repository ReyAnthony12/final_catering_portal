<?php
require_once __DIR__ . '/../config.php';
/** @var mysqli $conn */

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['store', 'caterer'])) {
    echo "Unauthorized";
    exit();
}

$user_id = $_SESSION['user_id'];
$service_id = isset($_GET['service_id']) ? intval($_GET['service_id']) : 0;

if ($service_id <= 0) {
    echo "Invalid service ID";
    exit();
}

// Verify service belongs to caterer
$check = mysqli_query($conn, "SELECT id FROM products WHERE id = '$service_id' AND store_id = '$user_id'");
if (mysqli_num_rows($check) == 0) {
    echo "Service not found or permission denied.";
    exit();
}

$query = "
    SELECT si.*, ii.item_name, ii.unit, COALESCE(ps.set_name, si.set_name) as actual_set_name
    FROM service_inventory si 
    JOIN inventory_items ii ON si.inventory_item_id = ii.id 
    LEFT JOIN product_sets ps ON si.set_id = ps.id
    WHERE si.service_id = '$service_id'
    ORDER BY actual_set_name ASC, ii.item_name ASC
";
$result = mysqli_query($conn, $query);

if (!$result || mysqli_num_rows($result) == 0) {
    echo '<div style="text-align: center; padding: 40px; color: var(--text-muted);">
            No inventory items linked to this service yet.<br>
            Click "Add Item" to start mapping ingredients or equipment.
          </div>';
    exit();
}

// Group items by set
$grouped_items = [];
while ($row = mysqli_fetch_assoc($result)) {
    $set_name = !empty($row['actual_set_name']) ? $row['actual_set_name'] : 'General / All Sets';
    if (!isset($grouped_items[$set_name])) {
        $grouped_items[$set_name] = [];
    }
    $grouped_items[$set_name][] = $row;
}
?>

<?php foreach ($grouped_items as $set_name => $items): ?>
    <div style="margin-bottom: 30px;">
        <h3 style="color: var(--accent-gold); border-bottom: 1px solid var(--border-color); padding-bottom: 10px; margin-bottom: 15px; font-size: 16px;">
            <?php echo htmlspecialchars($set_name); ?>
        </h3>
        <table class="table mapping-table" style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr style="border-bottom: 1px solid var(--border-color); color: var(--text-muted); text-align: left;">
                    <th style="padding: 10px;">Item Name</th>

                    <th style="padding: 10px;">Quantity</th>
                    <th style="padding: 10px;">Logic</th>
                    <th style="padding: 10px; text-align: right;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $row): ?>
                    <tr style="border-bottom: 1px solid rgba(255,255,255,0.05);">
                        <td data-label="Item Name" style="padding: 10px; color: var(--text-main); font-weight: 500;">
                            <span class="mapping-desktop-item-name"><?php echo htmlspecialchars($row['item_name']); ?></span>
                            <button type="button" class="mapping-item-toggle" aria-expanded="false"><?php echo htmlspecialchars($row['item_name']); ?></button>
                        </td>
                        <td data-label="Quantity" style="padding: 10px; color: var(--text-muted);">
                            <?php echo (int) $row['quantity_required']; ?>
                        </td>
                        <td data-label="Logic" style="padding: 10px;">
                            <?php if ($row['is_pax_dependent']): ?>
                                <span style="font-size: 11px; background: transparent !important; color: #3498db; padding: 2px 6px; border-radius: 4px;">Per Guest (Pax)</span>
                            <?php else: ?>
                                <span style="font-size: 11px; background: transparent !important; color: var(--text-muted); padding: 2px 6px; border-radius: 4px;">Fixed / Per Order</span>
                            <?php endif; ?>
                        </td>
                        <td data-label="Action" style="padding: 10px; text-align: right;">
                            <form method="POST" onsubmit="return confirm('Remove this mapping?');" style="display: inline;">
                                <input type="hidden" name="action" value="remove_mapping">
                                <input type="hidden" name="mapping_id" value="<?php echo $row['id']; ?>">
                                <button type="submit" class="btn-sm btn-danger" aria-label="Remove <?php echo htmlspecialchars($row['item_name'], ENT_QUOTES, 'UTF-8'); ?> mapping" style="border: none; background: none; color: #e74c3c; cursor: pointer;">
                                    Remove
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endforeach; ?>


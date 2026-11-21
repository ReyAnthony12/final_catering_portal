<?php
require_once __DIR__ . '/config.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }
$is_customer_logged_in = isset($_SESSION['user_id']) && isset($_SESSION['role']) && $_SESSION['role'] === 'customer';

if (!isset($_GET['store_id']) && !isset($internal_store_id)) {
    echo "Invalid request"; return;
}

$store_id = isset($internal_store_id) ? $internal_store_id : intval($_GET['store_id']);

$store_query = "SELECT u.*, sd.* FROM users u INNER JOIN store_details sd ON u.id = sd.user_id WHERE u.id = '$store_id' AND u.role IN ('caterer', 'store')";
$store_res = mysqli_query($conn, $store_query);
$store = mysqli_fetch_assoc($store_res);

if (!$store) {
    echo "Caterer not found."; return;
}

// Function definitions for this API file
if (!function_exists('browseStoreGetLegacyCategoryLabel')) {
function browseStoreGetLegacyCategoryLabel(string $key): string
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
}

if (!function_exists('browseStoreGetCategoryLines')) {
function browseStoreGetCategoryLines($raw_description): array
{
    $decoded = json_decode((string) $raw_description, true);
    $category_lines = [];

    if (is_array($decoded) && isset($decoded['categories']) && is_array($decoded['categories'])) {
        foreach ($decoded['categories'] as $key => $items) {
            $label = browseStoreGetLegacyCategoryLabel($key);
            $items_array = is_array($items)
                ? array_values(array_filter(array_map('trim', $items), 'strlen'))
                : array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n|,/', (string) $items)), 'strlen'));
            
            if (!empty($items_array)) {
                $category_lines[] = [
                    'key' => $key,
                    'label' => $label,
                    'items' => $items_array
                ];
            }
        }
        return $category_lines;
    }

    $plain = trim((string) $raw_description);
    if ($plain !== '') {
        $plain_items = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n|,/', $plain)), 'strlen'));
        if (!empty($plain_items)) {
            $category_lines[] = [
                'key' => 'main_dishes',
                'label' => 'Main Dishes',
                'items' => $plain_items
            ];
        }
    }
    return $category_lines;
}
}

if (!function_exists('isStoreOpenLocal')) {
function isStoreOpenLocal($is_open, $open_time, $close_time, $auto_schedule_enabled = 0) {
    return isStoreAvailableNow($is_open, $open_time, $close_time, $auto_schedule_enabled);
}
}

$store_is_open = isStoreOpenLocal($store['is_open'] ?? 0, $store['business_open_time'] ?? null, $store['business_close_time'] ?? null, $store['auto_schedule_enabled'] ?? 0);

$available_menu_sets = [];
$available_sets_query = "
    SELECT ps.*, p.id AS product_id, p.name AS product_name, p.catering_details
    FROM product_sets ps
    INNER JOIN products p ON ps.product_id = p.id
    WHERE p.store_id = '$store_id'
      AND p.is_available = TRUE
      AND p.category = 'catering_service'
    ORDER BY p.name, ps.id
";
$available_sets_result = mysqli_query($conn, $available_sets_query);

if ($available_sets_result) {
    while ($set_menu = mysqli_fetch_assoc($available_sets_result)) {
        // Need to parse category lines
        $category_lines = browseStoreGetCategoryLines($set_menu['description'] ?? '');

        $available_menu_sets[] = [
            'id' => $set_menu['id'],
            'product_id' => $set_menu['product_id'],
            'product_name' => $set_menu['product_name'],
            'catering_details' => $set_menu['catering_details'] ?? '',
            'set_name' => $set_menu['set_name'] ?? ($set_menu['name'] ?? 'Unnamed Set'),
            'price' => (float) ($set_menu['price'] ?? 0),
            'pax' => (int) ($set_menu['pax'] ?? 0),
            'per_head_price' => (float) ($set_menu['per_head_price'] ?? 0),
            'category_lines' => $category_lines,
        ];
    }
}

$fixed_packages = [];
$per_guest_packages = [];
foreach ($available_menu_sets as $set) {
    if ($set['per_head_price'] > 0) {
        $per_guest_packages[] = $set;
    } else {
        $fixed_packages[] = $set;
    }
}
?>

<div class="modal-header menu-dialog-header">
    <h2>Menu</h2>
    <button type="button" class="close-caterer-menu" aria-label="Close menu" onclick="closeCatererMenuModal()">&times;</button>
</div>
<div class="modal-body menu-dialog-body">
    <?php foreach (['Fixed Packages' => $fixed_packages, 'Per Guest Packages' => $per_guest_packages] as $title => $packages): ?>
        <?php if (!$packages) continue; ?>
        <section class="menu-package-group">
            <h3><?php echo htmlspecialchars($title); ?></h3>
            <?php foreach ($packages as $set): ?>
                <?php
                $booking_path = 'customer/book-service.php?product_id=' . $set['product_id'] . '&store_id=' . $store_id;
                $booking_url = $is_customer_logged_in ? $booking_path : 'login.php?redirect=' . urlencode($booking_path);
                ?>
                <article class="menu-package-card">
                    <header class="menu-package-header">
                        <h4><?php echo htmlspecialchars($set['set_name']); ?></h4>
                        <div class="menu-package-price">
                            <strong>&#8369;<?php echo number_format($set['price'], 2); ?></strong>
                            <?php if ($set['per_head_price'] > 0): ?>
                                <span>&#8369;<?php echo number_format($set['per_head_price'], 2); ?> per guest</span>
                            <?php elseif ($set['pax'] > 0): ?>
                                <span>Good for <?php echo $set['pax']; ?> <?php echo $set['pax'] === 1 ? 'guest' : 'guests'; ?></span>
                            <?php endif; ?>
                        </div>
                    </header>
                    <div class="menu-package-content">
                        <?php if (!empty($set['catering_details'])): ?>
                            <div class="menu-package-inclusions">
                                <h5>What's Included</h5>
                                <p><?php echo htmlspecialchars($set['catering_details']); ?></p>
                            </div>
                        <?php endif; ?>
                        <?php if ($set['category_lines']): ?>
                            <div class="menu-food-grid">
                                <?php foreach ($set['category_lines'] as $line): ?>
                                    <div class="menu-food-category">
                                        <h5><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $line['label']))); ?></h5>
                                        <ul>
                                            <?php foreach ($line['items'] as $item): ?>
                                                <li><?php echo htmlspecialchars($item); ?></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <p>Menu details are available when booking this set.</p>
                        <?php endif; ?>
                    </div>
                    <footer class="menu-package-footer">
                        <a class="menu-book-link" href="<?php echo htmlspecialchars($booking_url); ?>">Book Now</a>
                    </footer>
                </article>
            <?php endforeach; ?>
        </section>
    <?php endforeach; ?>
    <?php if (!$available_menu_sets): ?>
        <p>No menu items available at the moment.</p>
    <?php endif; ?>
</div>

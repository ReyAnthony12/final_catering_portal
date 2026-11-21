<?php
// Start session first as header.php checks it, but we need it for logic before header
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Logic that must run before HTML output
require_once __DIR__ . '/../config.php';
/** @var mysqli $conn */

// Auto-Migration: Ensure 'pax' column exists in 'product_sets'
$check_pax = mysqli_query($conn, "SHOW COLUMNS FROM product_sets LIKE 'pax'");
if (mysqli_num_rows($check_pax) == 0) {
    mysqli_query($conn, "ALTER TABLE product_sets ADD COLUMN pax INT DEFAULT 0 AFTER price");
}

$check_extra_guest = mysqli_query($conn, "SHOW COLUMNS FROM product_sets LIKE 'extra_guest_rate'");
if ($check_extra_guest && mysqli_num_rows($check_extra_guest) == 0) {
    mysqli_query($conn, "ALTER TABLE product_sets ADD COLUMN extra_guest_rate DECIMAL(10,2) DEFAULT 0.00 AFTER per_head_price");
}

$check_event_type = mysqli_query($conn, "SHOW COLUMNS FROM products LIKE 'event_type'");
if ($check_event_type && mysqli_num_rows($check_event_type) == 0) {
    mysqli_query($conn, "ALTER TABLE products ADD COLUMN event_type VARCHAR(100) NULL AFTER category");
}

if (!isLoggedIn() || !in_array(getUserRole(), ['store', 'caterer'])) {
    header('Location: ../login.php');
    exit();
}

$user_id = $_SESSION['user_id'];

// Check if caterer is closed by portal
$store_status_query = "SELECT u.status as user_status FROM users u WHERE u.id = '$user_id'";
$store_status_result = mysqli_query($conn, $store_status_query);
$store_status = mysqli_fetch_assoc($store_status_result);
$is_store_closed_by_portal = ($store_status['user_status'] === 'closed');

// Keep deleted services and their packages available for restoration.
$trash_column = mysqli_query($conn, "SHOW COLUMNS FROM products LIKE 'deleted_at'");
if ($trash_column && mysqli_num_rows($trash_column) === 0) {
    mysqli_query($conn, "ALTER TABLE products ADD COLUMN deleted_at DATETIME NULL DEFAULT NULL");
}
if (empty($_SESSION['service_trash_token'])) {
    $_SESSION['service_trash_token'] = bin2hex(random_bytes(32));
}
$success = '';
$error = '';
$service_action = $_POST['service_action'] ?? '';
$is_collection_request = ($_SERVER['HTTP_X_SERVICE_COLLECTION'] ?? '') === '1';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($service_action, ['trash', 'restore'], true)) {
    if ($is_store_closed_by_portal) {
        $error = 'Your account is closed. Services cannot be changed.';
    } elseif (!hash_equals($_SESSION['service_trash_token'], (string) ($_POST['trash_token'] ?? ''))) {
        $error = 'Your session changed. Refresh the page and try again.';
    } else {
        $service_id = (int) ($_POST['service_id'] ?? 0);
        $sql = $service_action === 'trash'
            ? "UPDATE products SET deleted_at = NOW(), is_available = 0 WHERE id = ? AND store_id = ? AND deleted_at IS NULL"
            : "UPDATE products SET deleted_at = NULL, is_available = 1 WHERE id = ? AND store_id = ? AND deleted_at IS NOT NULL";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('ii', $service_id, $user_id);
        if ($stmt->execute() && $stmt->affected_rows > 0) {
            if ($is_collection_request) {
                header('Content-Type: application/json');
                echo json_encode(['ok' => true, 'message' => $service_action === 'trash' ? 'Service moved to Trash.' : 'Service restored.']);
                exit();
            }
            header('Location: add-menu.php?msg=' . ($service_action === 'trash' ? 'trashed&view=trash' : 'restored'));
            exit();
        }
        $error = 'Service could not be changed. Refresh and try again.';
    }
}
if ($is_collection_request && $service_action !== '') {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'message' => $error ?: 'Unable to change service.']);
    exit();
}
$messages = [
    'trashed' => 'Service moved to Trash. You can restore it anytime.',
    'restored' => 'Service restored successfully.',
    'added' => 'Item added successfully!',
    'updated' => 'Product updated successfully!',
];
$success = $messages[$_GET['msg'] ?? ''] ?? '';
$show_trash = ($_GET['view'] ?? '') === 'trash';
$is_edit_mode = false;
$current_product = null;
$current_sets = [];
$current_mappings = [];
$current_set_mappings = [];

// Check if editing existing product
if (isset($_GET['edit']) && !$is_store_closed_by_portal) {
    $product_id = mysqli_real_escape_string($conn, $_GET['edit']);
    
    $check_query = "SELECT * FROM products WHERE id = '$product_id' AND store_id = '$user_id' AND deleted_at IS NULL";
    $check_result = mysqli_query($conn, $check_query);
    
    if (mysqli_num_rows($check_result) > 0) {
        $is_edit_mode = true;
        $current_product = mysqli_fetch_assoc($check_result);
        
        // Fetch Sets
        if ($current_product['category'] === 'catering_service') {
            $sets_query = "SELECT * FROM product_sets WHERE product_id = '$product_id' ORDER BY id ASC";
            $sets_result = mysqli_query($conn, $sets_query);
            while ($set = mysqli_fetch_assoc($sets_result)) {
                $current_sets[] = $set;
            }
        }
        
        // Fetch Inventory Mappings
        $mappings_query = "SELECT * FROM service_inventory WHERE service_id = '$product_id' ORDER BY id ASC";
        $mappings_result = mysqli_query($conn, $mappings_query);
        while ($map = mysqli_fetch_assoc($mappings_result)) {
            $map_set_name = trim((string) ($map['set_name'] ?? ''));
            if ($map_set_name !== '') {
                if (!isset($current_set_mappings[$map_set_name])) {
                    $current_set_mappings[$map_set_name] = [];
                }
                $current_set_mappings[$map_set_name][] = $map;
            } else {
                $current_mappings[] = $map;
            }
        }
    } else {
        $error = 'Product not found or you do not have permission to edit it.';
    }
}

// Fetch Inventory Items for Dropdown
$editing_service_id = $is_edit_mode ? (int) $product_id : 0;
$inventory_query = "SELECT ii.*, GREATEST(0, ii.total_stock - COALESCE((
    SELECT SUM(si.quantity_required) FROM service_inventory si
    JOIN products p ON p.id = si.service_id
    WHERE si.inventory_item_id = ii.id AND p.store_id = ii.store_id
      AND si.service_id <> $editing_service_id
), 0)) AS allocation_capacity
FROM inventory_items ii WHERE ii.store_id = '$user_id' ORDER BY ii.item_name ASC";
$inventory_result = mysqli_query($conn, $inventory_query);
$inventory_items = [];
$service_allocations = [];
$allocation_result = mysqli_query($conn, "SELECT si.service_id, si.inventory_item_id, SUM(si.quantity_required) AS qty FROM service_inventory si JOIN products p ON p.id = si.service_id WHERE p.store_id = '$user_id' GROUP BY si.service_id, si.inventory_item_id");
while ($allocation_result && ($allocation = mysqli_fetch_assoc($allocation_result))) {
    $service_allocations[(int) $allocation['inventory_item_id']][(int) $allocation['service_id']] = (float) $allocation['qty'];
}
while ($row = mysqli_fetch_assoc($inventory_result)) {
    $row['physical_stock'] = $row['total_stock'];
    $row['service_allocations'] = $service_allocations[(int) $row['id']] ?? [];
    $inventory_items[] = $row;
}
if (($_GET['stock_snapshot'] ?? '') === '1') {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode($inventory_items);
    exit();
}

function saveInventoryMappings(mysqli $conn, int $product_id, array $mappings, ?string $set_name = null): void
{
    global $inventory_items;

    $escaped_set_name = ($set_name !== null && $set_name !== '')
        ? "'" . mysqli_real_escape_string($conn, $set_name) . "'"
        : "NULL";

    foreach ($mappings as $map) {
        if (empty($map['item_id'])) {
            continue;
        }

        $inv_id = intval($map['item_id']);
        $raw_qty = (string) ($map['qty'] ?? '0');
        $qty = preg_match('/^[0-9]+$/D', $raw_qty) ? (float) $raw_qty : 0;
        if ($inv_id <= 0) {
            continue;
        }
        $stock = null;
        foreach ($inventory_items as $inventory_item) {
            if (intval($inventory_item['id']) === $inv_id) {
                $stock = floor(floatval($inventory_item['physical_stock']));
                break;
            }
        }
        if ($stock === null || $stock < 0) {
            continue;
        }
        $used_result = mysqli_query($conn, "SELECT COALESCE(SUM(si.quantity_required), 0) AS allocated
            FROM service_inventory si JOIN products p ON p.id = si.service_id
            JOIN inventory_items ii ON ii.id = si.inventory_item_id AND ii.store_id = p.store_id
            WHERE si.inventory_item_id = '$inv_id'");
        $used = $used_result ? (float) mysqli_fetch_assoc($used_result)['allocated'] : $stock;
        $qty = min($qty, max(0, $stock - $used));

        $is_pax = isset($map['is_pax']) ? 1 : 0;
        mysqli_query(
            $conn,
            "INSERT INTO service_inventory (service_id, set_name, inventory_item_id, quantity_required, is_pax_dependent)
             VALUES ('$product_id', $escaped_set_name, '$inv_id', '$qty', '$is_pax')"
        );
    }
}

function getLegacyCategoryLabel(string $key): string
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

function menuCategoryLabels(): array
{
    return [
        'appetizers' => 'Appetizers',
        'soup' => 'Soup',
        'rice' => 'Rice',
        'main_dishes' => 'Main Dishes',
        'pasta_noodles' => 'Pasta/Noodles',
        'desserts' => 'Desserts',
        'drinks' => 'Drinks',
    ];
}

function normalizeSetMenuCategories($raw_description): array
{
    $decoded = json_decode((string) $raw_description, true);
    $normalized = [];

    if (is_array($decoded) && isset($decoded['categories']) && is_array($decoded['categories'])) {
        foreach ($decoded['categories'] as $key => $items) {
            $label = getLegacyCategoryLabel($key);
            if (is_string($items)) {
                $items = preg_split('/\r\n|\r|\n|,/', $items);
            }
            if (is_array($items)) {
                $normalized[$label] = array_values(array_filter(array_map('trim', $items), 'strlen'));
            }
        }
        return $normalized;
    }

    $plain = trim((string) $raw_description);
    if ($plain !== '') {
        $normalized['Main Dishes'] = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n|,/', $plain)), 'strlen'));
    }

    return $normalized;
}

function syncCateringProductPrice(mysqli $conn, int $product_id): void
{
    $row = mysqli_fetch_assoc(mysqli_query($conn, "
        SELECT MIN(CASE WHEN COALESCE(per_head_price, 0) > 0 THEN per_head_price ELSE price END) AS starting_price
        FROM product_sets
        WHERE product_id = '$product_id'
    "));
    $starting = floatval($row['starting_price'] ?? 0);
    mysqli_query($conn, "UPDATE products SET price = '$starting' WHERE id = '$product_id'");
}

function reloadProductSets(mysqli $conn, int $product_id): array
{
    $sets = [];
    $sets_result = mysqli_query($conn, "SELECT * FROM product_sets WHERE product_id = '$product_id' ORDER BY id ASC");
    if ($sets_result) {
        while ($set = mysqli_fetch_assoc($sets_result)) {
            $sets[] = $set;
        }
    }
    return $sets;
}

function buildSetDescriptionFromPost(array $set): string
{
    $categories = [];

    if (isset($set['menu_category_names']) && is_array($set['menu_category_names']) && isset($set['menu_categories']) && is_array($set['menu_categories'])) {
        foreach ($set['menu_categories'] as $idx => $raw) {
            $label = trim((string) ($set['menu_category_names'][$idx] ?? ''));
            if ($label === '') continue;

            $items = trim((string) $raw) === ''
                ? []
                : array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n|,/', (string)$raw)), 'strlen'));
            $categories[$label] = $items;
        }
    }

    $has_items = false;
    foreach ($categories as $items) {
        if (!empty($items)) {
            $has_items = true;
            break;
        }
    }

    if (!$has_items && !empty($set['desc'])) {
        $categories = normalizeSetMenuCategories($set['desc']);
    }

    return json_encode([
        'format' => 'categorized_menu_v2',
        'categories' => $categories,
    ], JSON_UNESCAPED_UNICODE);
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $service_action === '' && !$is_store_closed_by_portal) {
    if (!empty($_POST['product_id'])) {
        $posted_product_id = (int) $_POST['product_id'];
        $editable = mysqli_query($conn, "SELECT id FROM products WHERE id = '$posted_product_id' AND store_id = '$user_id' AND deleted_at IS NULL");
        if (!$editable || mysqli_num_rows($editable) === 0) {
            header('Location: add-menu.php?view=trash');
            exit();
        }
    }
    $name = mysqli_real_escape_string($conn, $_POST['name']);
    $event_type = isset($_POST['event_type']) ? mysqli_real_escape_string($conn, $_POST['event_type']) : '';
    
    if (empty($name) && !empty($event_type)) {
        $name = $event_type . ' Package';
    }

    $description = mysqli_real_escape_string($conn, $_POST['description']);
    $price = isset($_POST['price']) ? floatval($_POST['price']) : 0;
    $category = isset($_POST['category']) ? mysqli_real_escape_string($conn, $_POST['category']) : 'catering_service';
    $catering_details = isset($_POST['catering_details']) ? mysqli_real_escape_string($conn, $_POST['catering_details']) : '';
    $agreement = isset($_POST['agreement']) ? mysqli_real_escape_string($conn, $_POST['agreement']) : '';
    $pax = isset($_POST['pax']) ? mysqli_real_escape_string($conn, $_POST['pax']) : 0;
    
    // Kept for backward compatibility or global options if needed, but primary focus is now per-set
    $catering_addons = isset($_POST['catering_addons_json']) ? mysqli_real_escape_string($conn, $_POST['catering_addons_json']) : '[]';
    $catering_swaps = isset($_POST['catering_swaps_json']) ? mysqli_real_escape_string($conn, $_POST['catering_swaps_json']) : '[]';
    
    $is_available = 1;
    
    // Image Upload
    $image = $is_edit_mode && isset($current_product['image']) ? $current_product['image'] : '';
    
    if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        $unique_id = uniqid('', true);
        $original_name = basename($_FILES['image']['name']);
        $file_ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
        $clean_name = preg_replace('/[^A-Za-z0-9]/', '_', pathinfo($original_name, PATHINFO_FILENAME));
        $clean_name = substr($clean_name, 0, 50);
        $file_name = 'prod_' . $unique_id . '_' . $clean_name . '.' . $file_ext;
        
        $target_file = '../' . UPLOAD_DIR . 'products/' . $file_name;
        
        if (!is_dir('../' . UPLOAD_DIR . 'products/')) {
            mkdir('../' . UPLOAD_DIR . 'products/', 0777, true);
        }
        
        $allowed_types = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        if (in_array($file_ext, $allowed_types)) {
            if (move_uploaded_file($_FILES['image']['tmp_name'], $target_file)) {
                chmod($target_file, 0644);
                $image = UPLOAD_DIR . 'products/' . $file_name;
            }
        }
    }
    
    if (isset($_POST['product_id']) && !empty($_POST['product_id'])) {
        // UPDATE
        $product_id = mysqli_real_escape_string($conn, $_POST['product_id']);
        
        $update_query = "UPDATE products SET 
                         name = '$name', description = '$description', price = '$price', category = '$category', event_type = '$event_type',
                         catering_details = '$catering_details', agreement = '$agreement', pax = '$pax',
                         is_available = '$is_available', catering_addons = '$catering_addons', catering_swaps = '$catering_swaps'";
        
        if (!empty($image)) {
            $update_query .= ", image = '$image'";
        }
        
        $update_query .= " WHERE id = '$product_id' AND store_id = '$user_id' AND deleted_at IS NULL";
        
        if (mysqli_query($conn, $update_query)) {
            $success = 'Product updated successfully!';
            mysqli_query($conn, "DELETE FROM service_inventory WHERE service_id = '$product_id'");
            
            // Handle Sets (New Structured Format)
            if ($category === 'catering_service') {
                mysqli_query($conn, "DELETE FROM product_sets WHERE product_id = '$product_id'");
                
                if (isset($_POST['sets']) && is_array($_POST['sets'])) {
                    foreach ($_POST['sets'] as $set) {
                        if (!empty($set['name'])) {
                            $s_name = mysqli_real_escape_string($conn, $set['name']);
                            $s_desc = mysqli_real_escape_string($conn, buildSetDescriptionFromPost($set));
                            $s_pricing_mode = ($set['pricing_mode'] ?? 'package') === 'by_pax' ? 'by_pax' : 'package';
                            $s_price = isset($set['price']) ? floatval($set['price']) : 0;
                            $s_pax = isset($set['pax']) ? intval($set['pax']) : 0;
                            $s_per_head = isset($set['per_head_price']) ? floatval($set['per_head_price']) : 0;
                            $s_extra_guest = isset($set['extra_guest_rate']) ? floatval($set['extra_guest_rate']) : 0;
                            if ($s_pricing_mode === 'by_pax') {
                                $s_price = $s_per_head;
                                $s_pax = 0;
                                $s_extra_guest = 0;
                            } else {
                                $s_per_head = 0;
                            }
                            if ($s_price <= 0 && $s_per_head <= 0) {
                                continue;
                            }
                            
                            // Handle per-set addons
                            $s_addons = '[]';
                            if (isset($set['addons']) && is_array($set['addons'])) {
                                $clean_addons = [];
                                foreach ($set['addons'] as $addon) {
                                    if (!empty($addon['name'])) {
                                        $clean_addons[] = [
                                            'name' => $addon['name'],
                                            'price' => floatval($addon['price'])
                                        ];
                                    }
                                }
                                $s_addons = mysqli_real_escape_string($conn, json_encode($clean_addons));
                            }
                            
                            // Handle per-set swaps
                            $s_swaps = '[]';
                            if (isset($set['swaps']) && is_array($set['swaps'])) {
                                $clean_swaps = [];
                                foreach ($set['swaps'] as $swap_rule) {
                                    $swap_name = trim((string) ($swap_rule['name'] ?? $swap_rule['replacement'] ?? $swap_rule['original_item'] ?? $swap_rule['original'] ?? ''));
                                    $swap_category = trim((string) ($swap_rule['category'] ?? ''));
                                    if ($swap_name !== '') {
                                        $clean_swaps[] = [
                                            'category' => $swap_category,
                                            'name' => $swap_name,
                                            'replacement' => $swap_name,
                                            'original_item' => $swap_name,
                                            'original' => $swap_name,
                                            'price' => floatval($swap_rule['price'] ?? 0)
                                        ];
                                    }
                                }
                                $s_swaps = mysqli_real_escape_string($conn, json_encode($clean_swaps));
                            }
                            
                            $insert_set = "INSERT INTO product_sets (product_id, set_name, description, price, addons, swaps, pax, per_head_price, extra_guest_rate) 
                                           VALUES ('$product_id', '$s_name', '$s_desc', '$s_price', '$s_addons', '$s_swaps', '$s_pax', '$s_per_head', '$s_extra_guest')";
                            mysqli_query($conn, $insert_set);

                            if (isset($set['mappings']) && is_array($set['mappings'])) {
                                saveInventoryMappings($conn, (int) $product_id, $set['mappings'], $set['name']);
                            }
                        }
                    }
                }
                syncCateringProductPrice($conn, (int) $product_id);
            }
            // Refresh data
            $current_product = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM products WHERE id = '$product_id'"));
            $current_sets = reloadProductSets($conn, (int) $product_id);
            
            // Handle General Inventory Mappings
            if (isset($_POST['mappings']) && is_array($_POST['mappings'])) {
                saveInventoryMappings($conn, (int) $product_id, $_POST['mappings']);
            }
            
            header("Location: add-menu.php?msg=updated&step=1");
            exit();
        } else {
            appLogError('Caterer add-menu update product failed', mysqli_error($conn));
            $error = 'Failed to update the menu item. Please try again.';
        }
    } else {
        // INSERT
        $query = "INSERT INTO products (store_id, name, description, price, image, category, event_type, catering_details, agreement, pax, catering_addons, catering_swaps, is_available) 
                  VALUES ('$user_id', '$name', '$description', '$price', '$image', '$category', '$event_type', '$catering_details', '$agreement', '$pax', '$catering_addons', '$catering_swaps', '$is_available')";
        
        if (mysqli_query($conn, $query)) {
            $product_id = mysqli_insert_id($conn);
            $success = 'Item added successfully!';
            
            if ($category === 'catering_service' && isset($_POST['sets']) && is_array($_POST['sets'])) {
                foreach ($_POST['sets'] as $set) {
                    if (!empty($set['name'])) {
                        $s_name = mysqli_real_escape_string($conn, $set['name']);
                        $s_desc = mysqli_real_escape_string($conn, buildSetDescriptionFromPost($set));
                        $s_pricing_mode = ($set['pricing_mode'] ?? 'package') === 'by_pax' ? 'by_pax' : 'package';
                        $s_price = isset($set['price']) ? floatval($set['price']) : 0;
                        $s_pax = isset($set['pax']) ? intval($set['pax']) : 0;
                        $s_per_head = isset($set['per_head_price']) ? floatval($set['per_head_price']) : 0;
                        $s_extra_guest = isset($set['extra_guest_rate']) ? floatval($set['extra_guest_rate']) : 0;
                        if ($s_pricing_mode === 'by_pax') {
                            $s_price = $s_per_head;
                            $s_pax = 0;
                            $s_extra_guest = 0;
                        } else {
                            $s_per_head = 0;
                        }
                        if ($s_price <= 0 && $s_per_head <= 0) {
                            continue;
                        }
                        
                        // Handle per-set addons
                        $s_addons = '[]';
                        if (isset($set['addons']) && is_array($set['addons'])) {
                            $clean_addons = [];
                            foreach ($set['addons'] as $addon) {
                                if (!empty($addon['name'])) {
                                    $clean_addons[] = [
                                        'name' => $addon['name'],
                                        'price' => floatval($addon['price'])
                                    ];
                                }
                            }
                            $s_addons = mysqli_real_escape_string($conn, json_encode($clean_addons));
                        }
                        
                        $s_swaps = '[]';
                        if (isset($set['swaps']) && is_array($set['swaps'])) {
                            $clean_swaps = [];
                            foreach ($set['swaps'] as $swap_rule) {
                                $swap_name = trim((string) ($swap_rule['name'] ?? $swap_rule['replacement'] ?? $swap_rule['original_item'] ?? $swap_rule['original'] ?? ''));
                                $swap_category = trim((string) ($swap_rule['category'] ?? ''));
                                if ($swap_name !== '') {
                                    $clean_swaps[] = [
                                        'category' => $swap_category,
                                        'name' => $swap_name,
                                        'replacement' => $swap_name,
                                        'original_item' => $swap_name,
                                        'original' => $swap_name,
                                        'price' => floatval($swap_rule['price'] ?? 0)
                                    ];
                                }
                            }
                            $s_swaps = mysqli_real_escape_string($conn, json_encode($clean_swaps));
                        }
                        
                        $insert_set = "INSERT INTO product_sets (product_id, set_name, description, price, addons, swaps, pax, per_head_price, extra_guest_rate) 
                                       VALUES ('$product_id', '$s_name', '$s_desc', '$s_price', '$s_addons', '$s_swaps', '$s_pax', '$s_per_head', '$s_extra_guest')";
                        mysqli_query($conn, $insert_set);

                        if (isset($set['mappings']) && is_array($set['mappings'])) {
                            saveInventoryMappings($conn, (int) $product_id, $set['mappings'], $set['name']);
                        }
                    }
                }
                syncCateringProductPrice($conn, (int) $product_id);
            }
            
            // Handle General Inventory Mappings (Insert)
            if (isset($_POST['mappings']) && is_array($_POST['mappings'])) {
                saveInventoryMappings($conn, (int) $product_id, $_POST['mappings']);
            }
            
            header("Location: add-menu.php?msg=added");
            exit();
        } else {
            appLogError('Caterer add-menu insert product failed', mysqli_error($conn));
            $error = 'Failed to add the menu item. Please try again.';
        }
    }
}

$trash_count_row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS total FROM products WHERE store_id = '$user_id' AND deleted_at IS NOT NULL"));
$trash_count = (int) $trash_count_row['total'];
$service_filter = $show_trash ? 'IS NOT NULL' : 'IS NULL';
// Fetch services for the selected collection.
$menu_query = "SELECT p.*,
                      COALESCE(ps.min_set_price, 0) AS min_set_price,
                      COALESCE(ps.max_set_price, 0) AS max_set_price
               FROM products p
               LEFT JOIN (
                   SELECT product_id,
                          MIN(CASE WHEN COALESCE(per_head_price, 0) > 0 THEN per_head_price ELSE price END) AS min_set_price,
                          MAX(CASE WHEN COALESCE(per_head_price, 0) > 0 THEN per_head_price ELSE price END) AS max_set_price
                   FROM product_sets
                   GROUP BY product_id
               ) ps ON ps.product_id = p.id
               WHERE p.store_id = '$user_id' AND p.deleted_at $service_filter
               ORDER BY p.created_at DESC";
$menu_result = mysqli_query($conn, $menu_query);

// INCLUDE HEADER (This starts HTML, Body, Sidebar)
require_once __DIR__ . '/includes/header.php';
?>

<script>document.body.classList.add('menu-editor-page');</script><link rel="stylesheet" href="../assets/css/menu-editor.css?v=<?php echo filemtime(__DIR__ . '/../assets/css/menu-editor.css'); ?>"><style>
    .layout-grid {
        display: grid;
        grid-template-columns: 2fr 1fr;
        gap: 30px;
    }

    .page-header {
        margin-bottom: 30px;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .page-header h1 {
        margin: 0;
        font-size: 24px;
        color: var(--accent-gold);
    }

    .catering-panel {
        background: rgba(255, 255, 255, 0.02);
        border: 1px dashed var(--border-color);
        padding: 20px;
        border-radius: 8px;
        margin-top: 15px;
    }

    .catering-panel h4 {
        margin-top: 0;
        color: var(--accent-gold);
        font-size: 16px;
    }

    .dynamic-list {
        display: flex;
        flex-direction: column;
        gap: 15px;
    }

    .dynamic-item {
        background: var(--card-bg);
        border: 1px solid var(--border-color);
        padding: 15px;
        border-radius: 8px;
        position: relative;
    }
    
    .nested-panel {
        background: rgba(255, 255, 255, 0.03);
        border: 1px solid var(--border-color);
        padding: 15px;
        border-radius: 8px;
        margin-top: 15px;
    }
    
    .nested-panel h5 {
        margin-top: 0;
        margin-bottom: 10px;
        color: var(--text-main);
        font-size: 14px;
        border-bottom: 1px solid var(--border-color);
        padding-bottom: 5px;
    }

    .remove-btn {
        position: absolute;
        top: 10px;
        right: 10px;
        background: none;
        border: none;
        color: var(--danger);
        cursor: pointer;
    }

    .menu-list-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 15px;
        border-bottom: 1px solid var(--border-color);
    }
    .menu-list-item:last-child { border-bottom: none; }
    
    .menu-item-info { display: flex; align-items: center; gap: 15px; }
    .menu-thumb { width: 50px; height: 50px; border-radius: 6px; object-fit: cover; background: rgba(255, 255, 255, 0.05); }

    /* Responsive */
    .gold-input {
        border: 1px solid var(--accent-gold) !important;
        background: #000 !important;
        color: #fff !important;
        font-weight: bold;
        font-family: monospace;
        font-size: 16px;
        text-align: right;
        padding-left: 25px; /* Space for Peso sign */
    }
    
    .gold-input-wrapper {
        position: relative;
    }
    
    .caterer-step-btn {
        background: rgba(255, 255, 255, 0.05);
        color: var(--text-muted);
        border: 1px solid rgba(255, 255, 255, 0.1);
        cursor: pointer;
        transition: all 0.2s ease;
    }
    
    .caterer-step-btn:hover {
        background: rgba(255, 255, 255, 0.1);
        color: #fff;
    }

    .caterer-step-btn.active {
        background: var(--accent-gold);
        color: #111;
        border-color: var(--accent-gold);
    }

    .caterer-step-2-only.hidden-step,
    .caterer-step-nested.hidden-step {
        display: none !important;
    }

    .package-price-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
        gap: 16px;
        margin: 16px 0 20px;
    }

    /* Form Control Override for Dark Mode */
    .form-control {
        background-color: #111827;
        color: var(--text-main);
        border: 1px solid var(--border-color);
        border-radius: 6px;
        padding: 10px;
        width: 100%;
        box-sizing: border-box;
    }
    
    .form-control:focus {
        border-color: var(--accent-gold);
        outline: none;
    }
    
    label.form-label {
        color: var(--text-main);
        font-weight: 500;
        margin-bottom: 5px;
        display: block;
    }
    
    .text-muted {
        color: var(--text-muted) !important;
    }

    @media (max-width: 992px) {
        .layout-grid {
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 768px) {
        .page-header,
        .content-header {
            flex-direction: column;
            align-items: flex-start;
            gap: 12px;
        }

        .menu-list-item,
        .menu-item-info {
            flex-direction: column;
            align-items: flex-start;
        }

        .dynamic-item > div[style*="grid-template-columns: 1fr 1fr"],
        .dynamic-item > div[style*="display: flex; gap: 10px; align-items: flex-end"] {
            display: grid !important;
            grid-template-columns: 1fr !important;
        }
    }
    .dynamic-item:has(> .inventory-mapping-fields) {
        position: relative;
        padding: 18px !important;
        border: 1px solid #a4b19b !important;
        border-radius: 12px;
        background: #d4dbcd;
        margin-bottom: 14px;
    }
    .inventory-mapping-fields {
        display: grid !important;
        grid-template-columns: minmax(0, 2fr) minmax(120px, 1fr) minmax(180px, 1fr);
        align-items: start !important;
        gap: 16px !important;
        padding-top: 30px;
    }
    .inventory-mapping-fields > div { min-width: 0; }
    .inventory-mapping-fields > div:first-child > div:first-child { margin-bottom: 8px !important; }
    .inventory-mapping-fields > div:first-child button { position: absolute; top: 10px; right: 52px; min-height: 36px; padding: 6px 12px !important; }
    .inventory-mapping-fields > div > label { display: block; margin-bottom: 8px; line-height: 1.5; }
    .inventory-mapping-fields .form-control { width: 100%; min-width: 0; min-height: 44px; box-sizing: border-box; }
    .inventory-mapping-fields > div:last-child > label:last-child { min-height: 44px; margin: 0; flex-wrap: wrap; }
    .inventory-mapping-fields input[type="checkbox"] { width: 18px; height: 18px; flex-shrink: 0; }
    .dynamic-item:has(> .inventory-mapping-fields) > .remove-btn { top: 8px; right: 8px; width: 36px; height: 36px; border: 0; background: transparent; }
    @media(max-width:700px) {
        .inventory-mapping-fields { grid-template-columns: minmax(0,1fr); gap: 14px !important; }
        .dynamic-item:has(> .inventory-mapping-fields) { padding: 14px !important; }
        .inventory-mapping-fields > div:last-child > label:last-child { min-height: 36px; }
    }
    /* Keep one package boundary instead of several nested inventory boxes. */
    .package-inventory-panel {
        border: 0 !important;
        background: transparent !important;
        box-shadow: none !important;
        padding: 14px 0 0 !important;
        margin: 14px 0 0 !important;
        border-radius: 0 !important;
    }
    .package-inventory-panel > h5 { margin: 0 0 12px; padding: 0 !important; border: 0 !important; font-size: 13px; }
    .package-inventory-panel .dynamic-item:has(> .inventory-mapping-fields) {
        border: 0 !important;
        border-bottom: 1px solid #a9b6a1 !important;
        border-radius: 0 !important;
        background: transparent !important;
        box-shadow: none !important;
        padding: 10px 0 16px !important;
        margin: 0 0 14px;
    }
    .package-inventory-panel .dynamic-list { border: 0 !important; padding: 0 !important; background: transparent !important; }
    @media(max-width:700px) {
        .dynamic-item:has(> .package-inventory-panel) { padding: 12px !important; }
        .package-inventory-panel .inventory-mapping-fields { padding-top: 42px; gap: 10px !important; }
    }
    .mapping-row-toolbar { display:flex;justify-content:flex-end;align-items:center;gap:8px;margin-bottom:10px; }
    .mapping-row-toolbar .btn { min-height:36px;padding:6px 12px;font-size:12px; }
    .mapping-row-remove { width:36px;height:36px;border:0;background:transparent;color:var(--text-main);font-size:22px;cursor:pointer; }
    .package-inventory-panel .inventory-mapping-fields { padding-top:0 !important; }
    .inventory-mapping-fields > div > label:first-child { display:block;height:auto;min-height:18px;margin:0 0 8px !important;line-height:18px;font-size:12px; }
    .inventory-mapping-fields > div:last-child > label:last-child { display:flex;align-items:center;min-height:44px;margin:0 !important; }
</style>

<?php if ($is_edit_mode): ?>
    <div style="margin-bottom: 16px;">
        <a href="add-menu.php" class="btn btn-secondary"><i class="fas fa-plus"></i> Add New Instead</a>
    </div>
<?php endif; ?>

<?php if ($success && !$is_edit_mode): ?>
    <dialog id="successAlert" class="service-success-modal" aria-labelledby="serviceSuccessTitle" aria-describedby="serviceSuccessMessage">
        <span class="service-success-icon" aria-hidden="true"><i class="fas fa-check"></i></span>
        <h2 id="serviceSuccessTitle">Success!</h2>
        <p id="serviceSuccessMessage"><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></p>
        <button type="button" class="service-success-dismiss" onclick="document.getElementById('successAlert').close()">OK</button>
    </dialog>
    <script>
        // Clear form fields after successful creation
        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('mainForm').reset();
            // Clear dynamic sets
            document.getElementById('setsContainer').innerHTML = '';
            // Reset catering fields visibility
            toggleCateringFields();
        });
    </script>
<?php elseif ($success && $is_edit_mode): ?>
    <dialog id="successAlert" class="service-success-modal" aria-labelledby="serviceSuccessTitle" aria-describedby="serviceSuccessMessage">
        <span class="service-success-icon" aria-hidden="true"><i class="fas fa-check"></i></span>
        <h2 id="serviceSuccessTitle">Success!</h2>
        <p id="serviceSuccessMessage"><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></p>
        <button type="button" class="service-success-dismiss" onclick="document.getElementById('successAlert').close()">OK</button>
    </dialog>
<?php endif; ?>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const successAlert = document.getElementById('successAlert');
        if (successAlert) {
            successAlert.showModal();
            document.body.classList.add('service-success-open');
            successAlert.addEventListener('close', function() {
                document.body.classList.remove('service-success-open');
                const url = new URL(window.location.href);
                url.searchParams.delete('msg');
                history.replaceState(history.state, '', url);
            });
        }
    });
</script>
<?php if ($error): ?>
    <div class="alert alert-error">
        <i class="fas fa-exclamation-triangle"></i> <?php echo $error; ?>
    </div>
<?php endif; ?>

<?php if ($is_store_closed_by_portal): ?>
    <div class="card" style="text-align: center; padding: 60px 20px;">
        <i class="fas fa-lock fa-5x" style="color: var(--danger); margin-bottom: 30px;"></i>
        <h2 style="color: var(--text-main);">Feature Locked</h2>
        <p style="color: var(--text-muted); font-size: 18px; max-width: 600px; margin: 20px auto;">
            Your caterer account has been closed by the Portal Administrator. 
            You cannot manage menu items at this time.
        </p>
        <a href="messages.php" class="btn btn-primary" style="margin-top: 20px;">
            <i class="fas fa-envelope"></i> Contact Portal Admin
        </a>
    </div>
<?php else: ?>
    <div class="layout-grid">
            <!-- Left: Form -->
            <div>
                <form method="POST" action="" enctype="multipart/form-data" id="mainForm" data-step="1" novalidate>
                    <?php if ($is_edit_mode): ?>
                        <input type="hidden" name="product_id" value="<?php echo $current_product['id']; ?>">
                    <?php endif; ?>

                    <input type="hidden" name="category" id="category" value="catering_service">
                    <input type="hidden" name="price" id="price" value="0">

                    <div class="service-editor-header">
                    <div class="service-library-toolbar"><button type="button" class="btn btn-secondary" id="viewServicesButton" aria-haspopup="dialog" aria-controls="servicesModal">View Services</button></div>
                    <div class="card" id="storeMenuStepper" style="margin-bottom: 16px;">
                        
                        <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                            <button type="button" class="btn btn-sm caterer-step-btn active" data-caterer-step="1" onclick="showStoreMenuStep(1)"><span class="step-number">01</span><span>Service</span></button>
                            <button type="button" class="btn btn-sm caterer-step-btn" data-caterer-step="2" onclick="showStoreMenuStep(2)"><span class="step-number">02</span><span>Packages &amp; Menu</span></button>
                            <button type="button" class="btn btn-sm caterer-step-btn" data-caterer-step="3" onclick="showStoreMenuStep(3)"><span class="step-number">03</span><span>Add-ons &amp; Swaps</span></button>
                        </div>
                    </div>

                    </div>
                    <div class="card caterer-step-panel" data-caterer-panel="1">
                        

                        <div class="form-group">
                            <div class="event-type-label-row">
                                <label class="form-label" for="eventTypeSelect">Event type (optional)</label>
                                <button type="button" class="event-type-undo btn btn-secondary" id="undoEventType" onclick="resetEventType()" hidden><i class="fas fa-undo" aria-hidden="true"></i> Undo</button>
                            </div>
                            <select name="event_type" id="eventTypeSelect" class="form-control" onchange="toggleServiceName()">
                                <option value="" <?php echo (!$is_edit_mode || empty($current_product['event_type'])) ? 'selected' : ''; ?>>Select event type</option>
                                <option value="Wedding" <?php echo ($is_edit_mode && ($current_product['event_type'] ?? '') == 'Wedding') ? 'selected' : ''; ?>>Wedding</option>
                                <option value="Birthday" <?php echo ($is_edit_mode && ($current_product['event_type'] ?? '') == 'Birthday') ? 'selected' : ''; ?>>Birthday</option>
                                <option value="Corporate Event" <?php echo ($is_edit_mode && ($current_product['event_type'] ?? '') == 'Corporate Event') ? 'selected' : ''; ?>>Corporate Event</option>
                                <option value="Anniversary" <?php echo ($is_edit_mode && ($current_product['event_type'] ?? '') == 'Anniversary') ? 'selected' : ''; ?>>Anniversary</option>
                                <option value="Baptism" <?php echo ($is_edit_mode && ($current_product['event_type'] ?? '') == 'Baptism') ? 'selected' : ''; ?>>Baptism / Christening</option>
                                <option value="Family Gathering" <?php echo ($is_edit_mode && ($current_product['event_type'] ?? '') == 'Family Gathering') ? 'selected' : ''; ?>>Family Gathering</option>
                                <option value="Other" <?php echo ($is_edit_mode && ($current_product['event_type'] ?? '') == 'Other') ? 'selected' : ''; ?>>Other</option>
                            </select>
                        </div>

                        <div class="form-group" id="serviceNameGroup">
                            <label class="form-label">Service name</label>
                            <input type="text" name="name" id="serviceNameInput" class="form-control" placeholder="e.g. Grand Buffet Package" value="<?php echo $is_edit_mode ? htmlspecialchars($current_product['name']) : ''; ?>">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Short description</label>
                            <textarea name="description" class="form-control" rows="3" placeholder="What this service is for..." required><?php echo $is_edit_mode ? htmlspecialchars($current_product['description']) : ''; ?></textarea>
                        </div>

                        <div class="form-group">
                            <label class="form-label">What's included</label>
                            <textarea name="catering_details" class="form-control" rows="3" placeholder="Waiters, buffet tables, utensils, setup time..."><?php echo $is_edit_mode ? htmlspecialchars($current_product['catering_details']) : ''; ?></textarea>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Photo</label>
                            <?php if ($is_edit_mode && $current_product['image']): ?>
                                <img src="../<?php echo $current_product['image']; ?>" style="width: 100px; height: 100px; object-fit: cover; border-radius: 8px; margin-bottom: 10px; display: block;">
                            <?php endif; ?>
                            <div class="service-photo-control">
                                <input type="file" name="image" id="servicePhotoInput" class="form-control" accept="image/*">
                                <button type="button" class="service-photo-remove" aria-label="Remove selected photo" title="Remove selected photo" hidden>&times;</button>
                            </div>
                        </div>
                    </div>

                    <div id="cateringFields" style="display: none;">
                        <div class="card">



                            <!-- Sets -->
                            <div class="catering-panel" id="packagesPanel">
                                <div id="setsContainer" class="dynamic-list"></div>
                                <button type="button" class="btn btn-secondary" id="addPackageButton" onclick="addSet()" style="margin-top: 15px;">
                                    <i class="fas fa-plus"></i> Add Package
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="service-editor-actions">
                    <div id="storeStepNav" style="display: flex; gap: 10px; margin-bottom: 12px;">
                        <button type="button" class="btn btn-secondary" id="storeStepBack" onclick="showStoreMenuStep(currentStoreMenuStep - 1)">Back</button>
                        <button type="button" class="btn btn-primary" id="storeStepNext" onclick="showStoreMenuStep(currentStoreMenuStep + 1)" style="flex: 1;">Continue</button>
                    </div>
                    <button type="submit" class="btn btn-primary" id="storePublishBtn" style="width: 100%; padding: 15px; font-size: 16px;">
                        <i class="fas fa-save"></i> <?php echo $is_edit_mode ? 'Save Changes' : 'Publish Service'; ?>
                    </button>
                    </div>
                </form>
            </div>

            <!-- Service library: opened only on request. -->
            <dialog id="servicesModal" aria-labelledby="servicesModalTitle" closedby="none">
                <div class="services-modal-heading"><div><span class="library-eyebrow">SERVICE LIBRARY</span><h2 id="servicesModalTitle">Your Services</h2></div><button type="button" class="services-close" id="closeServicesButton" aria-label="Close services">&times;</button></div>
                <div class="card" id="serviceCollection">
                    <div class="card-header">
                        <nav class="services-tabs" aria-label="Service collections">
                            <a href="add-menu.php" class="<?php echo !$show_trash ? 'selected' : ''; ?>">Services</a>
                            <a href="?view=trash" class="<?php echo $show_trash ? 'selected' : ''; ?>"> Trash (<?php echo $trash_count; ?>)</a>
                        </nav>
                    </div>
                    <div class="collection-status" role="status" aria-live="polite"></div>
                    <?php if (mysqli_num_rows($menu_result) > 0): ?>
                        <div class="services-list">
                            <?php 
                            mysqli_data_seek($menu_result, 0);
                            while ($item = mysqli_fetch_assoc($menu_result)): 
                            ?>
                                <div class="menu-list-item">
                                    <div class="menu-item-info">
                                        <?php if ($item['image']): ?>
                                            <img src="../<?php echo $item['image']; ?>" class="menu-thumb" alt="Service photo" loading="lazy">
                                        <?php else: ?>
                                            <div class="menu-thumb" style="display: flex; align-items: center; justify-content: center; color: var(--text-muted);"></div>
                                        <?php endif; ?>
                                        <div>
                                            <div class="service-name"><?php echo htmlspecialchars($item['name']); ?></div>
                                            <div class="service-price">
                                                <?php if (floatval($item['min_set_price']) > 0): ?>
                                                    <?php if (floatval($item['min_set_price']) == floatval($item['max_set_price'])): ?>
                                                        &#8369;<?php echo number_format($item['min_set_price'], 2); ?>
                                                    <?php else: ?>
                                                        From &#8369;<?php echo number_format($item['min_set_price'], 2); ?>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    &#8369;<?php echo number_format($item['price'], 2); ?>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="service-actions">
                                        <?php if (!$show_trash): ?>
                                        <a href="?edit=<?php echo (int) $item['id']; ?>" class="btn service-edit"> Edit service</a>
                                        <?php endif; ?>
                                        <form method="post" action="add-menu.php<?php echo $show_trash ? '?view=trash' : ''; ?>" class="service-action-form">
                                            <input type="hidden" name="trash_token" value="<?php echo htmlspecialchars($_SESSION['service_trash_token'], ENT_QUOTES, 'UTF-8'); ?>">
                                            <input type="hidden" name="service_id" value="<?php echo (int) $item['id']; ?>">
                                            <input type="hidden" name="service_action" value="<?php echo $show_trash ? 'restore' : 'trash'; ?>">
                                            <button type="submit" class="btn <?php echo $show_trash ? 'service-edit' : 'service-delete'; ?>" <?php echo $is_store_closed_by_portal ? 'disabled' : ''; ?>>
                                                 <?php echo $show_trash ? 'Restore service' : 'Delete'; ?>
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            <?php endwhile; ?>
                        </div>
                    <?php else: ?>
                        <div class="collection-empty">
                            <span class="collection-empty-icon" aria-hidden="true"><i class="fas <?php echo $show_trash ? 'fa-trash-alt' : 'fa-utensils'; ?>"></i></span>
                            <h3><?php echo $show_trash ? 'Your trash is empty' : 'Your next great service starts here'; ?></h3>
                            <p><?php echo $show_trash ? 'Deleted services will appear here. You can restore them whenever you need.' : 'Add your first service to start building your collection.'; ?></p>
                        </div>
                    <?php endif; ?>
                </div>
            </dialog>
        </div>
    </div>
<?php endif; ?>

    <script>
        // --- Data Logic ---
        function toggleServiceName() {
            var eventTypeSelect = document.getElementById('eventTypeSelect');
            var serviceNameGroup = document.getElementById('serviceNameGroup');
            var serviceNameInput = document.getElementById('serviceNameInput');
            var undoButton = document.getElementById('undoEventType');
            if (undoButton && eventTypeSelect) undoButton.hidden = eventTypeSelect.value === '';
            
            if (eventTypeSelect && serviceNameGroup && serviceNameInput) {
                if (eventTypeSelect.value !== "") {
                    serviceNameGroup.style.display = 'none';
                    serviceNameInput.required = false;
                } else {
                    serviceNameGroup.style.display = 'block';
                    serviceNameInput.required = true;
                }
            }
        }
        function resetEventType() {
            var select = document.getElementById('eventTypeSelect');
            if (!select) return;
            select.value = '';
            select.dispatchEvent(new Event('change', { bubbles: true }));
            document.getElementById('serviceNameInput')?.focus();
        }
        // Initialize on load
        document.addEventListener('DOMContentLoaded', toggleServiceName);

        function escapeHtml(text) {
            if (!text) return '';
            return String(text)
                .replace(/&/g, "&amp;")
                .replace(/</g, "&lt;")
                .replace(/>/g, "&gt;")
                .replace(/"/g, "&quot;")
                .replace(/'/g, "&#039;");
        }
        
        // Helper to generate random IDs
        function generateId() {
            return Math.random().toString(36).substr(2, 9);
        }

        let setCounter = 0;
        let mapCounter = 0;
        
        // Pass PHP inventory items to JS
        const inventoryItems = <?php echo json_encode($inventory_items); ?>;
        document.addEventListener('keydown', event => {
            if (!event.target.matches('input[id^="mapping_qty_"]')) return;
            if (!event.ctrlKey && !event.metaKey && event.key.length === 1 && !/^[0-9]$/.test(event.key)) event.preventDefault();
        });
        document.addEventListener('beforeinput', event => {
            if (event.target.matches('input[id^="mapping_qty_"]') && event.data && /[^0-9]/.test(event.data)) event.preventDefault();
        });
        document.addEventListener('paste', event => {
            if (event.target.matches('input[id^="mapping_qty_"]') && !/^[0-9]+$/.test(event.clipboardData.getData('text'))) event.preventDefault();
        });
        const sharedDraftPrefix = 'inventory-allocation-<?php echo (int) $user_id; ?>-';
        const editingServiceId = <?php echo $editing_service_id; ?>;
        function applySharedAllocationDrafts() {
            const drafts = {};
            try {
                for (let i = 0; i < localStorage.length; i++) {
                    const key = localStorage.key(i);
                    if (key.startsWith(sharedDraftPrefix)) drafts[key.slice(sharedDraftPrefix.length)] = JSON.parse(localStorage.getItem(key));
                }
            } catch (_) {}
            inventoryItems.forEach(item => {
                const allocations = {...item.service_allocations};
                Object.entries(drafts).forEach(([service, quantities]) => {
                    // An unsaved draft can reserve more stock, but cannot release a saved allocation.
                    allocations[service] = Math.max(Number(allocations[service]) || 0, Number(quantities[item.id]) || 0);
                });
                const used = Object.entries(allocations).reduce((sum, [service, qty]) => sum + (Number(service) === editingServiceId ? 0 : Number(qty) || 0), 0);
                item.allocation_capacity = Math.max(0, Number(item.physical_stock) - used);
            });
        }
        applySharedAllocationDrafts();
        window.addEventListener('storage', event => {
            if (event.key?.startsWith(sharedDraftPrefix)) { applySharedAllocationDrafts(); updateStockInfo(); }
        });
        document.addEventListener('input', event => {
            if (!event.target.closest('.inventory-mapping-fields')) return;
            saveSharedAllocationDraft();
        });
        document.addEventListener('change', event => {
            if (event.target.closest('.inventory-mapping-fields')) saveSharedAllocationDraft();
        });
        function saveSharedAllocationDraft() {
            if (!editingServiceId) return;
            const quantities = {};
            document.querySelectorAll('.inventory-mapping-fields').forEach(fields => {
                const id = fields.querySelector('select')?.value;
                if (id) quantities[id] = (quantities[id] || 0) + Math.max(0, Math.floor(Number(fields.querySelector('input[type="number"]')?.value) || 0));
            });
            try { localStorage.setItem(sharedDraftPrefix + editingServiceId, JSON.stringify(quantities)); } catch (_) {}
        }
        window.addEventListener('pagehide', saveSharedAllocationDraft);
        let refreshingSharedStock = false;
        setInterval(async () => {
            if (document.hidden || refreshingSharedStock) return;
            refreshingSharedStock = true;
            try {
                const url = new URL(location.href);
                url.searchParams.set('stock_snapshot', '1');
                const response = await fetch(url, {cache: 'no-store'});
                if (!response.ok) return;
                const items = await response.json();
                if (!Array.isArray(items)) return;
                inventoryItems.splice(0, inventoryItems.length, ...items);
                applySharedAllocationDrafts();
                updateStockInfo();
            } catch (_) {} finally { refreshingSharedStock = false; }
        }, 5000);
        const menuCategoryLabels = <?php echo json_encode(menuCategoryLabels()); ?>;

        function buildInventoryOptions(selectedItemId = '') {
            let options = `<option value="" disabled hidden ${selectedItemId ? '' : 'selected'}>-- Select Item --</option>`;
            [['consumable', 'Consumables'], ['equipment', 'Equipment']].forEach(([type, label]) => {
                const items = inventoryItems.filter(item =>
                    (String(item.item_type || 'equipment').toLowerCase() === 'consumable' ? 'consumable' : 'equipment') === type);
                if (!items.length) return;
                options += `<optgroup label="${label}">`;
                items.forEach(item => {
                    const selected = item.id == selectedItemId ? 'selected' : '';
                    options += `<option value="${item.id}" ${selected}>${escapeHtml(item.item_name)} (Available: ${Math.max(0, Math.floor(parseFloat(item.allocation_capacity) || 0))})</option>`;
                });
                options += '</optgroup>';
            });
            return options;
        }

        function updateStockInfo(selectEl = null, mapId = null, refreshAll = true) {
            const rows = Array.from(document.querySelectorAll('.inventory-mapping-fields'));
            if (refreshAll) {
                rows.forEach(fields => {
                    const select = fields.querySelector('select');
                    const input = fields.querySelector('input[type="number"]');
                    if (select && input) updateStockInfo(select, input.id.replace('mapping_qty_', ''), false);
                });
                return;
            }
            const itemId = selectEl.value;
            const infoSpan = document.getElementById(`stock_info_${mapId}`);
            if (!infoSpan) return;
            const qtyInput = document.getElementById(`mapping_qty_${mapId}`);
            const item = inventoryItems.find(i => i.id == itemId);
            const totalStock = item ? Math.max(0, Math.floor(parseFloat(item.allocation_capacity) || 0)) : null;
            const usedElsewhere = id => rows.reduce((sum, fields) => {
                const select = fields.querySelector('select');
                const input = fields.querySelector('input[type="number"]');
                return sum + (select !== selectEl && select?.value == id
                    ? Math.max(0, Math.floor(parseFloat(input?.value) || 0)) : 0);
            }, 0);
            let requestedQty = Math.max(0, Math.floor(parseFloat(qtyInput?.value) || 0));
            if (qtyInput) {
                qtyInput.setCustomValidity('');
                if (totalStock !== null) {
                    let allocatedBefore = 0;
                    for (const fields of rows) {
                        const select = fields.querySelector('select');
                        if (select === selectEl) break;
                        if (select?.value == itemId) {
                            allocatedBefore += Math.max(0, Math.floor(parseFloat(fields.querySelector('input[type="number"]')?.value) || 0));
                        }
                    }
                    const available = Math.max(0, totalStock - allocatedBefore);
                    qtyInput.max = String(available);
                    requestedQty = Math.min(requestedQty, available);
                } else {
                    qtyInput.removeAttribute('max');
                }
                if (qtyInput.value !== '' && Number(qtyInput.value) !== requestedQty) {
                    qtyInput.value = String(requestedQty);
                }
            }
            selectEl.querySelectorAll('option').forEach(option => {
                const inventoryItem = inventoryItems.find(i => i.id == option.value);
                if (!inventoryItem) return;
                const stock = parseFloat(inventoryItem.allocation_capacity) || 0;
                const remaining = Math.max(0, stock - usedElsewhere(option.value) - (option.value === itemId ? requestedQty : 0));
                option.textContent = `${inventoryItem.item_name} (Available: ${Number(remaining.toFixed(2))})`;
            });
            if (!itemId) {
                infoSpan.textContent = 'Remaining Stock: --';
                return;
            }
            if (item) {
                const remaining = Math.max(0, totalStock - usedElsewhere(itemId) - requestedQty);
                const inventoryTotal = Math.max(0, Math.floor(parseFloat(item.total_stock) || 0));
                const otherServices = Math.max(0, inventoryTotal - totalStock);
                const thisService = usedElsewhere(itemId) + requestedQty;
                infoSpan.textContent = `Remaining Stock: ${remaining} / ${inventoryTotal}`;
                infoSpan.title = `Inventory: ${inventoryTotal}; allocated to other services: ${otherServices}; allocated to this service: ${thisService}; remaining: ${remaining}`;
            } else {
                infoSpan.textContent = 'Remaining Stock: --';
            }
        }

        function renderMappingRow(containerId, fieldPrefix, data = null) {
            const container = document.getElementById(containerId);
            if (!container) return;

            const mapId = mapCounter++;
            const itemId = data ? data.inventory_item_id : '';
            const qty = Math.max(0, Math.floor(parseFloat(data?.quantity_required) || 0));
            const isPax = data ? (data.is_pax_dependent == 1) : true;
            const options = buildInventoryOptions(itemId);

            const html = `
                <div id="map_${mapId}" class="dynamic-item" style="border-left: 3px solid var(--accent-blue);">
                    <div class="mapping-row-toolbar">
                        <button type="button" class="btn btn-outline" onclick="openServiceInventoryItem()">+ Add Item</button>
                        <button type="button" class="mapping-row-remove" aria-label="Remove item" title="Remove item" onclick="document.getElementById('map_${mapId}').remove(); updateStockInfo(); saveSharedAllocationDraft();">&times;</button>
                    </div>
                    <div class="inventory-mapping-fields" style="display: flex; gap: 10px; align-items: flex-end;">
                        <div style="flex: 2;">
                            <label style="font-size:12px;font-weight:600;">Inventory Item</label>
                            <select name="${fieldPrefix}[${mapId}][item_id]" class="form-control" onchange="updateStockInfo(this, ${mapId})" required>
                                ${options}
                            </select>
                            <span id="stock_info_${mapId}" style="display:block; margin-top:5px; font-size:11px; color:var(--accent-gold); font-weight:600;">Total Stock: --</span>

                        </div>
                        <div style="flex: 1;">
                            <label style="font-size: 12px; font-weight: 600;">Qty Needed</label>
                            <input type="number" step="1" min="0" id="mapping_qty_${mapId}" name="${fieldPrefix}[${mapId}][qty]" class="form-control" value="${qty}" oninput="updateStockInfo(document.getElementById('map_${mapId}').querySelector('select'), ${mapId})" onchange="if (!this.value) this.value = '0'; updateStockInfo(document.getElementById('map_${mapId}').querySelector('select'), ${mapId})" required placeholder="0">
                        </div>
                        <div style="flex: 1;">
                            <label style="font-size: 12px; font-weight: 600; display: block; margin-bottom: 5px;">Calculation</label>
                            <label style="font-size: 12px; display: flex; align-items: center; gap: 5px; cursor: pointer;">
                                <input type="checkbox" name="${fieldPrefix}[${mapId}][is_pax]" value="1" ${isPax ? 'checked' : ''}>
                                Multiply by guest count
                            </label>
                        </div>
                    </div>
                </div>
            `;
            container.insertAdjacentHTML('beforeend', html);

            const selectEl = document.querySelector(`select[name="${fieldPrefix}[${mapId}][item_id]"]`);
            if (selectEl) {
                updateStockInfo(selectEl, mapId);
            }
        }

        function addSetMapping(setId, data = null) {
            renderMappingRow(`set_${setId}_mappings`, `sets[${setId}][mappings]`, data);
        }

        function updateSetPricingMode(setId) {
            const modeSelect = document.getElementById(`set_${setId}_pricing_mode`);
            const mode = modeSelect ? modeSelect.value : 'package';
            const packageFields = document.querySelectorAll(`.set_${setId}_package_pricing`);
            const byPaxFields = document.querySelectorAll(`.set_${setId}_by_pax_pricing`);
            const priceInput = document.getElementById(`set_${setId}_price`);
            const paxInput = document.getElementById(`set_${setId}_pax`);
            const perHeadInput = document.getElementById(`set_${setId}_per_head_price`);
            const extraGuestFields = document.querySelectorAll(`.set_${setId}_extra_guest`);

            packageFields.forEach(field => field.style.display = mode === 'package' ? 'block' : 'none');
            byPaxFields.forEach(field => field.style.display = mode === 'by_pax' ? 'block' : 'none');
            extraGuestFields.forEach(field => field.style.display = mode === 'package' ? 'block' : 'none');

            if (priceInput) priceInput.required = mode === 'package';
            if (paxInput) paxInput.required = mode === 'package';
            if (perHeadInput) perHeadInput.required = mode === 'by_pax';
        }

        let categoryCounter = 0;

        function getLegacyCategoryLabel(key) {
            const labels = {
                'appetizers': 'Appetizers',
                'soup': 'Soup',
                'rice': 'Rice',
                'main_dishes': 'Main Dishes',
                'pasta_noodles': 'Pasta/Noodles',
                'desserts': 'Desserts',
                'drinks': 'Drinks',
            };
            return labels[key] || key;
        }

        function normalizeMenuCategories(desc = '', categories = null) {
            let normalized = {};

            if (categories && typeof categories === 'object') {
                Object.keys(categories).forEach(key => {
                    const label = getLegacyCategoryLabel(key);
                    const value = categories[key] || [];
                    normalized[label] = Array.isArray(value)
                        ? value.filter(Boolean)
                        : String(value).split(/\r?\n|,/).map(item => item.trim()).filter(Boolean);
                });
                return normalized;
            }

            if (desc) {
                try {
                    const parsed = JSON.parse(desc);
                    if (parsed && parsed.categories) {
                        return normalizeMenuCategories('', parsed.categories);
                    }
                } catch (e) {}

                normalized['Main Dishes'] = String(desc).split(/\r?\n|,/).map(item => item.trim()).filter(Boolean);
            }

            return normalized;
        }

        function addMenuCategory(setId) {
            const container = document.getElementById(`set_${setId}_menu_categories`);
            const catId = categoryCounter++;
            const html = `
                <div id="set_${setId}_cat_${catId}" class="menu-category-card" style="position: relative; border: 1px dashed var(--border-color); padding: 8px; border-radius: 4px; background: rgba(0,0,0,0.2);">
                    <button type="button" class="category-remove" aria-label="Remove menu category" style="position: absolute; top: -10px; right: -10px; background: #ef4444; color: white; border: none; border-radius: 50%; width: 24px; height: 24px; font-size: 12px; cursor: pointer; display: flex; align-items: center; justify-content: center; box-shadow: 0 2px 4px rgba(0,0,0,0.3);" onclick="document.getElementById('set_${setId}_cat_${catId}').remove();">&times;</button>
                    <input type="text" name="sets[${setId}][menu_category_names][]" class="form-control mb-1" placeholder="Category Name" style="font-size: 13px; font-weight: 600; padding: 6px; height: auto;" required>
                    <textarea name="sets[${setId}][menu_categories][]" class="form-control" rows="2" placeholder="One item per line"></textarea>
                </div>
            `;
            container.insertAdjacentHTML('beforeend', html);
        }

        function renderMenuCategoryInputs(setId, categories) {
            let html = '';
            
            Object.entries(categories).forEach(([label, items]) => {
                const catId = categoryCounter++;
                html += `
                    <div id="set_${setId}_cat_${catId}" class="menu-category-card" style="position: relative; border: 1px dashed var(--border-color); padding: 8px; border-radius: 4px; background: rgba(0,0,0,0.2);">
                        <button type="button" class="category-remove" aria-label="Remove menu category" style="position: absolute; top: -10px; right: -10px; background: #ef4444; color: white; border: none; border-radius: 50%; width: 24px; height: 24px; font-size: 12px; cursor: pointer; display: flex; align-items: center; justify-content: center; box-shadow: 0 2px 4px rgba(0,0,0,0.3);" onclick="document.getElementById('set_${setId}_cat_${catId}').remove();">&times;</button>
                        <input type="text" name="sets[${setId}][menu_category_names][]" class="form-control mb-1" value="${escapeHtml(label)}" placeholder="Category Name" style="font-size: 13px; font-weight: 600; padding: 6px; height: auto;" required>
                        <textarea name="sets[${setId}][menu_categories][]" class="form-control" rows="2" placeholder="One item per line">${escapeHtml(items.join('\n'))}</textarea>
                    </div>
                `;
            });
            return html;
        }

        // --- UI Builders ---
        function addSet(data = null) {
            const container = document.getElementById('setsContainer');
            const setId = setCounter++; // Unique index for form submission
            
            // Defaults
            const name = data ? data.name : `Set ${String.fromCharCode(65 + (setId % 26))}`;
            const desc = data ? data.desc : '';
            const price = data ? data.price : '';
            const pax = data ? (data.pax || '') : '';
            const perHeadPrice = data ? (data.per_head_price || '') : '';
            const extraGuestRate = data ? (data.extra_guest_rate || '') : '';
            const pricingMode = parseFloat(perHeadPrice || 0) > 0 ? 'by_pax' : 'package';
            const categories = normalizeMenuCategories(desc, data ? data.categories : null);
            
            const setHtml = `
                <div id="set_${setId}" class="dynamic-item">
                    <button type="button" class="remove-btn" aria-label="Remove item" title="Remove item" onclick="document.getElementById('set_${setId}').remove();">&times;</button>
                    <div>
                        <label style="font-size: 14px; font-weight: 600; color: #fff;">Package name</label>
                        <input type="text" name="sets[${setId}][name]" class="form-control" placeholder="e.g. Intimate Birthday, Classic Buffet" value="${escapeHtml(name)}" required>
                    </div>
                    <div class="caterer-step-2-only package-price-grid">
                        <div>
                            <label style="font-size: 14px; font-weight: 600; color: #fff;">How to price</label>
                            <select id="set_${setId}_pricing_mode" name="sets[${setId}][pricing_mode]" class="form-control" onchange="updateSetPricingMode(${setId})">
                                <option value="package" ${pricingMode === 'package' ? 'selected' : ''}>Fixed package</option>
                                <option value="by_pax" ${pricingMode === 'by_pax' ? 'selected' : ''}>Per guest</option>
                            </select>
                        </div>
                        <div class="set_${setId}_package_pricing">
                            <label style="font-size: 14px; font-weight: 600; color: #fff;">Package price</label>
                            <div class="gold-input-wrapper">
                                <span class="gold-input-icon">&#8369;</span>
                                <input type="number" id="set_${setId}_price" name="sets[${setId}][price]" class="form-control gold-input" placeholder="0.00" value="${escapeHtml(price)}" step="0.01" min="0">
                            </div>
                        </div>
                        <div class="set_${setId}_package_pricing">
                            <label style="font-size: 14px; font-weight: 600; color: #fff;">Good for (guests)</label>
                            <input type="number" id="set_${setId}_pax" name="sets[${setId}][pax]" class="form-control gold-input" placeholder="e.g. 50" value="${escapeHtml(pax)}" min="1">
                        </div>
                        <div class="set_${setId}_package_pricing set_${setId}_extra_guest">
                            <label style="font-size: 14px; font-weight: 600; color: #fff;">Extra guest</label>
                            <div class="gold-input-wrapper">
                                <span class="gold-input-icon">&#8369;</span>
                                <input type="number" id="set_${setId}_extra_guest_rate" name="sets[${setId}][extra_guest_rate]" class="form-control gold-input" placeholder="0.00" value="${escapeHtml(extraGuestRate)}" step="0.01" min="0">
                            </div>
                        </div>
                        <div class="set_${setId}_by_pax_pricing">
                            <label style="font-size: 14px; font-weight: 600; color: #fff;">Price per guest</label>
                            <div class="gold-input-wrapper">
                                <span class="gold-input-icon">&#8369;</span>
                                <input type="number" id="set_${setId}_per_head_price" name="sets[${setId}][per_head_price]" class="form-control gold-input" placeholder="0.00" value="${escapeHtml(perHeadPrice)}" step="0.01" min="0">
                            </div>
                        </div>
                    </div>
                    
                    <div class="caterer-step-2-only">
                        <label style="font-size: 12px; font-weight: 600;">Menu (one dish per line)</label>
                        <input type="hidden" name="sets[${setId}][desc]" value="${escapeHtml(desc)}">
                        <div id="set_${setId}_menu_categories" class="menu-category-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; margin-bottom: 12px;">
                            ${renderMenuCategoryInputs(setId, categories)}
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-blue" onclick="addMenuCategory(${setId})" style="width: 100%;">
                            <i class="fas fa-plus"></i> Add Category
                        </button>
                    </div>

                    <div class="nested-panel caterer-step-nested package-inventory-panel">
                        <h5><i class="fas fa-boxes"></i> Inventory for this package</h5>
                        <div id="set_${setId}_mappings" class="dynamic-list"></div>
                        <button type="button" class="btn btn-sm btn-outline-blue" onclick="addSetMapping(${setId})" style="margin-top: 10px; width: 100%;">
                            <i class="fas fa-plus"></i> Add Inventory
                        </button>
                    </div>
                    
                    <div class="nested-panel caterer-step-nested">
                        <h5><i class="fas fa-plus-circle"></i> Add-ons</h5>
                        <div id="set_${setId}_addons" class="dynamic-list"></div>
                        <button type="button" class="btn btn-sm btn-secondary" onclick="addNestedAddon(${setId})" style="margin-top: 10px; width: 100%;">
                            <i class="fas fa-plus"></i> Add Add-on
                        </button>
                    </div>

                    <div class="nested-panel caterer-step-nested">
                        <h5><i class="fas fa-exchange-alt"></i> Dish swaps</h5>
                        <div id="set_${setId}_swaps" class="dynamic-list"></div>
                        <button type="button" class="btn btn-sm btn-secondary" onclick="addNestedSwap(${setId})" style="margin-top: 10px; width: 100%;">
                            <i class="fas fa-plus"></i> Add Swap
                        </button>
                    </div>
                </div>
            `;
            container.insertAdjacentHTML('beforeend', setHtml);
            updateSetPricingMode(setId);

            if (data) {
                if (data.addons && Array.isArray(data.addons)) {
                    data.addons.forEach(addon => addNestedAddon(setId, addon.name, addon.price));
                }
                if (data.swaps && Array.isArray(data.swaps)) {
                    data.swaps.forEach(swap => addNestedSwap(setId, swap));
                }
                if (data.mappings && Array.isArray(data.mappings)) {
                    data.mappings.forEach(mapping => addSetMapping(setId, mapping));
                }
            }
            showStoreMenuStep(currentStoreMenuStep);
        }

        function addNestedAddon(setId, name = '', price = '') {
            const container = document.getElementById(`set_${setId}_addons`);
            const uniqueId = generateId();
            const html = `
                <div id="${uniqueId}" style="display: flex; gap: 5px; margin-bottom: 5px;">
                    <input type="text" name="sets[${setId}][addons][${uniqueId}][name]" class="form-control" placeholder="Item Name" value="${escapeHtml(name)}" style="flex: 2;">
                    <input type="number" name="sets[${setId}][addons][${uniqueId}][price]" class="form-control" placeholder="Price" value="${escapeHtml(price)}" style="flex: 1;">
                    <button type="button" class="btn btn-sm btn-danger remove-btn" aria-label="Remove add-on" title="Remove add-on" onclick="document.getElementById('${uniqueId}').remove();">&times;</button>
                </div>
            `;
            container.insertAdjacentHTML('beforeend', html);
        }

        /**
         * Returns <option> HTML for menu items that belong to a specific category
         * across OTHER packages/sets only — grouped by package name using <optgroup>.
         * The current package's own items are excluded (swaps should come from other packages).
         * Each package's own name is also excluded from its items list.
         *
         * @param {string|number} currentSetId  - The set this swap belongs to (its items are excluded)
         * @param {string}        categoryName  - The category name to match across other sets
         * @param {string}        selectedName  - Currently-selected item value (for edit mode)
         */
        function getMenuOptionsForCategory(currentSetId, categoryName, selectedName) {
            let optionsHtml = '<option value="">Select Menu Item</option>';

            if (!categoryName) return optionsHtml;

            const currentContainer = document.getElementById(`set_${currentSetId}`);
            if (!currentContainer) return optionsHtml;

            // Only include OTHER packages — exclude the current package entirely
            const ordered = Array.from(document.querySelectorAll('#setsContainer > .dynamic-item'))
                                 .filter(c => c.id !== `set_${currentSetId}`);

            let foundSelected = false;

            ordered.forEach(container => {
                const rawId = container.id.replace('set_', '');

                // Get the package name so we can exclude it from its own item list
                const setNameInput = container.querySelector(`input[name="sets[${rawId}][name]"]`);
                const pkgName = setNameInput ? setNameInput.value.trim() : `Package ${rawId}`;

                const catNameInputs = container.querySelectorAll(`input[name="sets[${rawId}][menu_category_names][]"]`);
                const catTextareas  = container.querySelectorAll(`textarea[name="sets[${rawId}][menu_categories][]"]`);

                let itemsForThisSet = [];

                catNameInputs.forEach((input, idx) => {
                    if (input.value.trim() !== categoryName) return;
                    const textarea = catTextareas[idx];
                    if (!textarea) return;

                    const lines = textarea.value.split('\n').map(l => l.trim()).filter(l => l && l !== pkgName);
                    itemsForThisSet.push(...lines);
                });

                if (itemsForThisSet.length === 0) return;

                // Use optgroup to label which package each item comes from
                optionsHtml += `<optgroup label="${escapeHtml(pkgName)}">`;
                itemsForThisSet.forEach(line => {
                    const sel = selectedName === line ? 'selected' : '';
                    if (sel) foundSelected = true;
                    optionsHtml += `<option value="${escapeHtml(line)}" ${sel}>${escapeHtml(line)}</option>`;
                });
                optionsHtml += `</optgroup>`;
            });

            // If the saved value is not found (e.g. item was renamed) keep it selectable
            if (selectedName && !foundSelected) {
                optionsHtml += `<option value="${escapeHtml(selectedName)}" selected>${escapeHtml(selectedName)} (Saved)</option>`;
            }

            return optionsHtml;
        }

        /**
         * Called when the Category dropdown changes on a swap row.
         * Re-populates the sibling "Available Swap Menu Item" dropdown
         * with items from the newly selected category.
         */
        function onSwapCategoryChange(categorySelect, setId) {
            const swapRow   = categorySelect.closest('.dynamic-item');
            const menuSelect = swapRow ? swapRow.querySelector(`select[name*="[name]"]`) : null;
            if (!menuSelect) return;

            const chosenCategory = categorySelect.value;
            menuSelect.innerHTML = getMenuOptionsForCategory(setId, chosenCategory, '');
        }

        function addNestedSwap(setId, swapData = null) {
            const container = document.getElementById(`set_${setId}_swaps`);
            
            // Get available categories for this set
            const categoryInputs = document.querySelectorAll(`input[name="sets[${setId}][menu_category_names][]"]`);
            const categories = Array.from(categoryInputs).map(input => input.value.trim()).filter(v => v);

            const swapId = generateId();
            const swapName     = swapData ? (swapData.name || swapData.replacement || swapData.original_item || swapData.original || '') : '';
            const swapCategory = swapData ? (swapData.category || '') : '';
            const price        = swapData ? (swapData.price || '') : '';
            
            let categoryOptions = '<option value="">Select Category</option>';
            categories.forEach(cat => {
                const selected = cat === swapCategory ? 'selected' : '';
                categoryOptions += `<option value="${escapeHtml(cat)}" ${selected}>${escapeHtml(cat)}</option>`;
            });
            // Also include the saved category even if it's not currently in the inputs (e.g. if they renamed it)
            if (swapCategory && !categories.includes(swapCategory)) {
                categoryOptions += `<option value="${escapeHtml(swapCategory)}" selected>${escapeHtml(swapCategory)} (Missing)</option>`;
            }

            // Pre-populate menu items based on the already-selected category (edit mode)
            const menuOptionsHtml = getMenuOptionsForCategory(setId, swapCategory, swapName);

            const html = `
                <div id="swap_rule_${swapId}" class="dynamic-item" style="background: rgba(0,0,0,0.1);">
                    <button type="button" class="remove-btn" aria-label="Remove item" title="Remove item" onclick="document.getElementById('swap_rule_${swapId}').remove();">&times;</button>
                    <div class="swap-rule-fields" style="display: grid; grid-template-columns: 1.5fr 2fr 1fr; gap: 10px; align-items: flex-end;">
                        <div>
                            <label style="font-size: 12px; font-weight: 600; color: #fff;">Category</label>
                            <select name="sets[${setId}][swaps][${swapId}][category]" class="form-control" required style="padding: 8px;"
                                    onchange="onSwapCategoryChange(this, '${setId}')">
                                ${categoryOptions}
                            </select>
                        </div>
                        <div>
                            <label style="font-size: 12px; font-weight: 600; color: #fff;">Available Swap Menu Item</label>
                            <select name="sets[${setId}][swaps][${swapId}][name]" class="form-control" required style="padding: 8px;">
                                ${menuOptionsHtml}
                            </select>
                        </div>
                        <div>
                            <label style="font-size: 12px; font-weight: 600; color: #fff;">Extra ? (0 for Free)</label>
                            <input type="number" name="sets[${setId}][swaps][${swapId}][price]" class="form-control" placeholder="0.00" step="0.01" min="0" value="${escapeHtml(price)}">
                        </div>
                    </div>
                </div>
            `;
            container.insertAdjacentHTML('beforeend', html);
        }

        let currentStoreMenuStep = Math.min(3, Math.max(1, parseInt(new URL(window.location.href).searchParams.get('step'), 10) || 1));

        function showStoreMenuStep(step) {
            currentStoreMenuStep = Math.min(3, Math.max(1, parseInt(step, 10) || 1));
            document.getElementById('mainForm').dataset.step = String(currentStoreMenuStep);
            const packageButton = document.getElementById('addPackageButton');
            if (packageButton) {
                packageButton.hidden = false;
                packageButton.textContent = currentStoreMenuStep === 3 ? 'Add-ons and Swapping' : 'Add Package';
            }
            const stepUrl = new URL(window.location.href);
            stepUrl.searchParams.set('step', currentStoreMenuStep);
            history.replaceState(history.state, '', stepUrl);
            document.querySelectorAll('[data-caterer-panel]').forEach(function(panel) {
                panel.style.display = String(panel.getAttribute('data-caterer-panel')) === String(currentStoreMenuStep) ? '' : 'none';
            });
            document.querySelectorAll('.caterer-step-nested').forEach(function(panel) {
                panel.classList.toggle('hidden-step', currentStoreMenuStep !== 3);
                panel.style.display = currentStoreMenuStep === 3 ? '' : 'none';
            });
            document.querySelectorAll('.caterer-step-2-only').forEach(function(panel) {
                panel.classList.toggle('hidden-step', currentStoreMenuStep !== 2);
            });
            const cateringFields = document.getElementById('cateringFields');
            if (cateringFields) cateringFields.style.display = currentStoreMenuStep === 1 ? 'none' : '';
            const packagesPanel = document.getElementById('packagesPanel');
            if (packagesPanel) {
                packagesPanel.style.display = (currentStoreMenuStep === 2 || currentStoreMenuStep === 3) ? '' : 'none';
            }
            document.querySelectorAll('.caterer-step-btn').forEach(function(btn) {
                const isCurrent = parseInt(btn.getAttribute('data-caterer-step'), 10) === currentStoreMenuStep;
                btn.classList.toggle('active', isCurrent);
                if (isCurrent) btn.setAttribute('aria-current', 'step');
                else btn.removeAttribute('aria-current');
            });
            const backBtn = document.getElementById('storeStepBack');
            const nextBtn = document.getElementById('storeStepNext');
            const publishBtn = document.getElementById('storePublishBtn');
            if (backBtn) backBtn.style.display = currentStoreMenuStep === 1 ? 'none' : '';
            if (nextBtn) {
                nextBtn.style.display = currentStoreMenuStep === 3 ? 'none' : '';
                nextBtn.textContent = currentStoreMenuStep === 1 ? 'Continue to Packages' : 'Continue to Add-ons';
            }
            if (publishBtn) publishBtn.style.display = currentStoreMenuStep === 3 ? '' : 'none';
        }

    function toggleCateringFields() { showStoreMenuStep(currentStoreMenuStep);
    }

        // --- Initialization ---
        document.addEventListener('DOMContentLoaded', function() {
            // Custom validation: only enforce Step 1 required fields before submit
            document.getElementById('mainForm').addEventListener('submit', function(e) {
                var nameField = this.querySelector('input[name="name"]');
                var descField = this.querySelector('textarea[name="description"]');
                var hasError = false;

                var eventTypeSelect = document.getElementById('eventTypeSelect');
                var isEventSelected = eventTypeSelect && eventTypeSelect.value !== '';

                if (!isEventSelected && nameField && nameField.value.trim() === '') {
                    nameField.style.borderColor = '#ef4444';
                    hasError = true;
                } else if (nameField) {
                    nameField.style.borderColor = '';
                }

                if (descField && descField.value.trim() === '') {
                    descField.style.borderColor = '#ef4444';
                    hasError = true;
                } else if (descField) {
                    descField.style.borderColor = '';
                }

                if (hasError) {
                    e.preventDefault();
                    showStoreMenuStep(1);
                    if (!isEventSelected && nameField && nameField.value.trim() === '') {
                        nameField.focus();
                    } else if (descField && descField.value.trim() === '') {
                        descField.focus();
                    }
                    alert('Please fill in all required fields before saving.');
                }
            });

            toggleCateringFields();

            <?php if ($is_edit_mode && !empty($current_sets)): ?>
                // Load Sets with nested data
                <?php 
                $sets_js_array = [];
                foreach ($current_sets as $s) {
                    // Handle addons decoding (robust)
                    $addons_decoded = json_decode($s['addons'] ?? '[]', true);
                    while (is_string($addons_decoded)) {
                        $addons_decoded = json_decode($addons_decoded, true);
                    }
                    if (!is_array($addons_decoded)) $addons_decoded = [];

                    // Handle swaps decoding (robust)
                    $swaps_decoded = json_decode($s['swaps'] ?? '[]', true);
                    while (is_string($swaps_decoded)) {
                        $swaps_decoded = json_decode($swaps_decoded, true);
                    }
                    if (!is_array($swaps_decoded)) $swaps_decoded = [];

                    $sets_js_array[] = [
                        'name' => $s['set_name'],
                        'desc' => $s['description'],
                        'categories' => normalizeSetMenuCategories($s['description'] ?? ''),
                        'price' => $s['price'],
                        'pax' => isset($s['pax']) ? $s['pax'] : '',
                        'per_head_price' => isset($s['per_head_price']) ? $s['per_head_price'] : '',
                        'extra_guest_rate' => isset($s['extra_guest_rate']) ? $s['extra_guest_rate'] : '',
                        'addons' => $addons_decoded,
                        'swaps' => $swaps_decoded,
                        'mappings' => $current_set_mappings[$s['set_name']] ?? []
                    ];
                }
                echo "const savedSets = " . json_encode($sets_js_array) . ";";
                ?>
                
                if (Array.isArray(savedSets)) {
                    savedSets.forEach(item => addSet(item));
                }
            <?php endif; ?>
            showStoreMenuStep(currentStoreMenuStep);
            initializeServiceDraft();
        });
    </script>

<script>
function initializeServiceDraft() {
    const form = document.getElementById('mainForm');
    if (!form) return;
    const key = 'service-draft:v1:<?php echo (int) $user_id; ?>:<?php echo $is_edit_mode ? (int) $current_product['id'] : 'new'; ?>';
    const completed = <?php echo json_encode(in_array($_GET['msg'] ?? '', ['added', 'updated'], true)); ?>;
    const pendingKey = 'service-draft-pending:<?php echo (int) $user_id; ?>';
    let completedKey = key;
    if (completed) {
        try { completedKey = sessionStorage.getItem(pendingKey) || key; localStorage.removeItem(completedKey); sessionStorage.removeItem(pendingKey); } catch (_) {}
    }
    const status = document.createElement('span');
    status.setAttribute('role', 'status');
    status.style.cssText = 'margin-right:auto;color:var(--text-muted);font-size:11px;align-self:center';
    const controls = () => [...form.querySelectorAll('input[name],select[name],textarea[name]')].filter(el => !['file','submit','button'].includes(el.type));
    const capture = () => {
        const fields = controls().filter(el => !el.name.startsWith('sets[')).map(el => ({name:el.name,value:el.value,checked:el.checked}));
        const sets = [...form.querySelectorAll('#setsContainer > .dynamic-item')].map(set => {
            const id = set.id.replace('set_', '');
            const read = name => set.querySelector(`[name="sets[${id}][${name}]"]`)?.value || '';
            const nested = type => [...set.querySelectorAll(`[id="set_${id}_${type}"] > div`)].map(row => {
                const value = suffix => row.querySelector(`[name$="[${suffix}]"]`);
                return type === 'mappings' ? {inventory_item_id:value('item_id')?.value || '',quantity_required:value('qty')?.value || '',is_pax_dependent:value('is_pax')?.checked ? 1 : 0} : {name:value('name')?.value || '',price:value('price')?.value || '',category:value('category')?.value || ''};
            });
            const categories = [...set.querySelectorAll('.menu-category-card')].map(row => ({name:row.querySelector('input').value,items:row.querySelector('textarea').value}));
            return {name:read('name'),desc:read('desc'),price:read('price'),pax:read('pax'),per_head_price:read('per_head_price'),extra_guest_rate:read('extra_guest_rate'),pricing_mode:read('pricing_mode'),categories,addons:nested('addons'),swaps:nested('swaps'),mappings:nested('mappings')};
        });
        return {fields,sets,step:currentStoreMenuStep,scroll:document.querySelector('.caterer-workspace').scrollTop};
    };
    const save = () => {
        try { localStorage.setItem(key, JSON.stringify(capture())); status.textContent = 'Draft saved'; }
        catch (_) { status.textContent = 'Draft could not be saved on this browser'; }
    };
    try {
        if (completed) localStorage.removeItem(key);
        const draft = completed ? null : JSON.parse(localStorage.getItem(key));
        if (draft && Array.isArray(draft.fields) && Array.isArray(draft.sets)) {
            draft.fields.forEach(field => controls().filter(el => el.name === field.name).forEach(el => { el.value = field.value; if (['checkbox','radio'].includes(el.type)) el.checked = field.checked; }));
            document.getElementById('setsContainer').replaceChildren();
            setCounter = 0;
            // Build all packages first so swaps can resolve items from other packages.
            draft.sets.forEach(data => {
                addSet({...data,categories:{},addons:[],swaps:[],mappings:[]});
                const id = setCounter - 1;
                const container = document.getElementById(`set_${id}_menu_categories`);
                container.replaceChildren();
                data.categories.forEach(category => {
                    addMenuCategory(id);
                    const row = container.lastElementChild;
                    row.querySelector('input').value = category.name;
                    row.querySelector('textarea').value = category.items;
                });
                document.getElementById(`set_${id}_pricing_mode`).value = data.pricing_mode;
                updateSetPricingMode(id);
            });
            draft.sets.forEach((data,id) => {
                data.addons.forEach(item => addNestedAddon(id,item.name,item.price));
                data.swaps.forEach(item => addNestedSwap(id,item));
                data.mappings.forEach(item => addSetMapping(id,item));
            });
            toggleServiceName();
            showStoreMenuStep(draft.step || 1);
            document.querySelector('.caterer-workspace').scrollTop = draft.scroll || 0;
            status.textContent = 'Draft restored';
        }
    } catch (_) { status.textContent = 'Unable to restore the saved draft'; }
    form.addEventListener('input',save);
    form.addEventListener('submit',() => { save(); try { sessionStorage.setItem(pendingKey,key); } catch (_) {} });
    form.addEventListener('change',save);
    form.addEventListener('click',() => queueMicrotask(save));
    window.addEventListener('pagehide',save);
    document.addEventListener('visibilitychange',() => { if (document.hidden) save(); });
    // Store the selected photo separately so large files do not fill localStorage.
    const photo = form.querySelector('input[type="file"][name="image"]');
    const removePhoto = form.querySelector('.service-photo-remove');
    const updatePhotoControl = () => { if (removePhoto) removePhoto.hidden = !photo?.files.length; };
    if (photo && removePhoto) {
        photo.addEventListener('change',updatePhotoControl);
        removePhoto.addEventListener('click',() => {
            photo.value = '';
            photo.dispatchEvent(new Event('change',{bubbles:true}));
            photo.focus({preventScroll:true});
        });
        updatePhotoControl();
    }
    if (photo && window.indexedDB) {
        const request = indexedDB.open('catering-service-drafts',1);
        request.onupgradeneeded = () => request.result.createObjectStore('photos');
        request.onsuccess = () => {
            const db = request.result;
            if (completed) db.transaction('photos','readwrite').objectStore('photos').delete(completedKey);
            else {
                const get = db.transaction('photos').objectStore('photos').get(key);
                get.onsuccess = () => {
                    if (get.result && !photo.files.length) {
                        try { const transfer = new DataTransfer(); transfer.items.add(get.result); photo.files = transfer.files; updatePhotoControl(); }
                        catch (_) { status.textContent = 'Draft restored. Please select the photo again.'; }
                    }
                };
            }
            photo.addEventListener('change',() => {
                const store = db.transaction('photos','readwrite').objectStore('photos');
                if (photo.files[0]) store.put(photo.files[0],key); else store.delete(key);
            });
        };
        request.onerror = () => { status.textContent = 'Text draft saved; select photo again after refresh'; };
    }
}
</script>

<script src="../assets/js/service-collection.js?v=<?= filemtime(__DIR__ . '/../assets/js/service-collection.js') ?>" defer></script>
<dialog id="serviceInventoryItemSuccess" aria-labelledby="serviceInventoryItemSuccessTitle" style="position:fixed;inset:0;margin:auto;width:min(360px,calc(100vw - 32px));height:fit-content;max-height:calc(100dvh - 32px);overflow:auto;box-sizing:border-box;padding:24px;border:1px solid #96a38e;border-radius:12px;background:#d4dbcd;color:#20372b;text-align:center;">
    <h3 id="serviceInventoryItemSuccessTitle" style="margin:0 0 12px;font-size:18px;line-height:1.4;">Item added successfully</h3>
    <p style="margin:0 0 20px;font-size:14px;line-height:1.6;">Item saved successfully. You can add another item.</p>
    <button type="button" class="btn btn-primary" style="min-width:100px;min-height:44px;" onclick="document.getElementById('serviceInventoryItemSuccess').close()">OK</button>
</dialog>
<style>#serviceInventoryItemSuccess::backdrop{background:#10251c99;backdrop-filter:blur(4px);}</style>
<dialog id="serviceInventoryItemDialog" aria-label="Add Inventory Item" style="position:fixed;inset:0;margin:auto;width:min(440px,calc(100vw - 24px));max-height:calc(100dvh - 24px);overflow:auto;box-sizing:border-box;padding:20px;border:1px solid #96a38e;border-radius:12px;background:#d4dbcd;color:#20372b;">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;"><h3 style="margin:0;">Add Inventory Item</h3><button type="button" aria-label="Close add item" onclick="document.getElementById('serviceInventoryItemDialog').close()" style="border:0;background:transparent;font-size:24px;min-width:44px;min-height:44px;">×</button></div>
    <div id="serviceInventoryItemBody"></div>
</dialog>
<style>#serviceInventoryItemDialog::backdrop{background:#10251c99;backdrop-filter:blur(4px);}#serviceInventoryItemDialog input,#serviceInventoryItemDialog select{width:100%;box-sizing:border-box;min-height:44px;}#serviceInventoryItemDialog .form-group{margin:12px 0;}</style>
<script>
const inventoryModalStateKey = 'serviceInventoryModal:' + <?php echo json_encode((string) $user_id); ?> + ':' + location.pathname + ':' + (new URLSearchParams(location.search).get('edit') || 'new');
const inventoryDraftFields = ['item_name', 'item_type', 'total_stock', 'low_stock_threshold'];
function readInventoryModalState() {
    try { return JSON.parse(sessionStorage.getItem(inventoryModalStateKey) || 'null'); } catch (_) { return null; }
}
function saveInventoryModalState() {
    const dialog = document.getElementById('serviceInventoryItemDialog');
    const form = dialog.querySelector('form');
    const fields = {};
    inventoryDraftFields.forEach(name => {
        const input = form?.elements.namedItem(name);
        if (input) fields[name] = input.value;
    });
    try { sessionStorage.setItem(inventoryModalStateKey, JSON.stringify({ open: dialog.open, fields })); } catch (_) {}
}
document.getElementById('serviceInventoryItemDialog').addEventListener('close', saveInventoryModalState);
document.getElementById('serviceInventoryItemSuccess').addEventListener('close', () => {
    document.querySelector('#serviceInventoryItemDialog [name="item_name"]')?.focus();
});
window.addEventListener('pagehide', saveInventoryModalState);
async function openServiceInventoryItem() {
    const dialog = document.getElementById('serviceInventoryItemDialog');
    const body = document.getElementById('serviceInventoryItemBody');
    const draft = readInventoryModalState();
    body.textContent = 'Loading…';
    if (!dialog.open) dialog.showModal();
    try { sessionStorage.setItem(inventoryModalStateKey, JSON.stringify({ open: true, fields: draft?.fields || {} })); } catch (_) {}
    try {
        const response = await fetch('inventory.php', {cache:'no-store'});
        const page = new DOMParser().parseFromString(await response.text(),'text/html');
        const form = page.querySelector('#addModal form');
        if (!response.ok || !form) throw new Error('Unable to load the inventory form.');
        body.replaceChildren(document.importNode(form,true));
        const loadedForm = body.querySelector('form');
        inventoryDraftFields.forEach(name => {
            if (draft?.fields && Object.prototype.hasOwnProperty.call(draft.fields, name)) {
                loadedForm.elements.namedItem(name).value = draft.fields[name];
            }
        });
        loadedForm.addEventListener('input', saveInventoryModalState);
        loadedForm.addEventListener('change', saveInventoryModalState);
        saveInventoryModalState();
        body.querySelector('form').addEventListener('submit', async event => {
            event.preventDefault();
            const activeForm = event.currentTarget;
            const button = activeForm.querySelector('[type="submit"]');
            button.disabled = true;
            let message = body.querySelector('[role="status"]');
            if (!message) { message = document.createElement('p'); message.setAttribute('role','status'); body.append(message); }
            message.textContent = '';
            try {
                const saved = await fetch('inventory.php', {method:'POST',body:new FormData(activeForm)});
                const result = new DOMParser().parseFromString(await saved.text(),'text/html');
                if (!saved.ok || !result.getElementById('inventory-success-description')) throw new Error(result.querySelector('.alert-error')?.textContent || 'Unable to save item.');
                activeForm.reset();
                saveInventoryModalState();
                const url = new URL(location.href); url.searchParams.set('stock_snapshot','1');
                const snapshot = await fetch(url,{cache:'no-store'});
                const items = await snapshot.json();
                inventoryItems.splice(0,inventoryItems.length,...items);
                applySharedAllocationDrafts();
                document.querySelectorAll('.inventory-mapping-fields select').forEach(select => {
                    const value=select.value;select.innerHTML=buildInventoryOptions(value);select.value=value;
                });
                updateStockInfo();
                activeForm.reset();
                message.textContent = '';
                document.getElementById('serviceInventoryItemSuccess').showModal();
                dialog.scrollTop = 0;
            } catch(error) { message.textContent=error.message; message.style.color='#913b34'; }
            finally { button.disabled=false; }
        });
    } catch(error) { body.textContent=error.message; }
}
document.addEventListener('DOMContentLoaded', () => {
    if (readInventoryModalState()?.open) openServiceInventoryItem();
});
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>

<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config.php';
/** @var mysqli $conn */

// Check login
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['store', 'caterer'])) {
    header('Location: ../login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$page_title = 'Service Inventory Mapping';
$current_page = 'inventory.php'; // Keep inventory active in sidebar

// Handle POST requests
$success_msg = '';
$error_msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $action = $_POST['action'];
        
        if ($action === 'add_mapping') {
            $service_id = intval($_POST['service_id']);
            $inventory_item_id = intval($_POST['inventory_item_id']);
            $quantity = floatval($_POST['quantity_required']);
            $is_pax = isset($_POST['is_pax_dependent']) ? 1 : 0;
            $set_id = !empty($_POST['set_id']) ? intval($_POST['set_id']) : 'NULL';
            
            // Check if mapping exists
            $set_condition = $set_id === 'NULL' ? "set_id IS NULL" : "set_id = '$set_id'";
            $check = mysqli_query($conn, "SELECT si.id FROM service_inventory si
                JOIN inventory_items existing_item ON existing_item.id = si.inventory_item_id
                JOIN inventory_items selected_item ON selected_item.id = '$inventory_item_id'
                WHERE si.service_id = '$service_id'
                AND (si.inventory_item_id = '$inventory_item_id'
                     OR LOWER(TRIM(existing_item.item_name)) = LOWER(TRIM(selected_item.item_name)))");
            if (!is_finite($quantity) || $quantity < 1 || floor($quantity) != $quantity) {
                $error_msg = 'Enter a whole number of units, at least 1.';
            } elseif (mysqli_num_rows($check) > 0) {
                $error_msg = "This inventory item is already added to this service.";
            } else {
                $query = "INSERT INTO service_inventory (service_id, inventory_item_id, quantity_required, is_pax_dependent, set_id) 
                          VALUES ('$service_id', '$inventory_item_id', '$quantity', '$is_pax', $set_id)";
                if (mysqli_query($conn, $query)) {
                    $success_msg = "Item added successfully.";
                } else {
                    appLogError('Caterer service mapping insert failed', mysqli_error($conn));
                    $error_msg = "Unable to map the inventory item right now. Please try again.";
                }
            }
        } elseif ($action === 'remove_mapping') {
            $mapping_id = intval($_POST['mapping_id']);
            // Verify ownership via service -> caterer
            $check = mysqli_query($conn, "SELECT si.id FROM service_inventory si 
                                          JOIN products p ON si.service_id = p.id 
                                          WHERE si.id = '$mapping_id' AND p.store_id = '$user_id'");
            
            if (mysqli_num_rows($check) > 0) {
                mysqli_query($conn, "DELETE FROM service_inventory WHERE id = '$mapping_id'");
                $success_msg = "Mapping removed.";
            } else {
                $error_msg = "Invalid permission or mapping not found.";
            }
        } elseif ($action === 'update_mapping') {
             $mapping_id = intval($_POST['mapping_id']);
             $quantity = floatval($_POST['quantity_required']);
             $is_pax = isset($_POST['is_pax_dependent']) ? 1 : 0;
             
             // Verify ownership
            $check = mysqli_query($conn, "SELECT si.id FROM service_inventory si 
                                          JOIN products p ON si.service_id = p.id 
                                          WHERE si.id = '$mapping_id' AND p.store_id = '$user_id'");
            
            if (mysqli_num_rows($check) > 0) {
                mysqli_query($conn, "UPDATE service_inventory SET quantity_required = '$quantity', is_pax_dependent = '$is_pax' WHERE id = '$mapping_id'");
                $success_msg = "Mapping updated.";
            } else {
                $error_msg = "Invalid permission or mapping not found.";
            }
        }
    }
}

// Fetch services/products, including set-based pricing for catering services
$services_query = "SELECT p.*,
                          COALESCE(ps.min_set_price, 0) AS min_set_price,
                          COALESCE(ps.max_set_price, 0) AS max_set_price
                   FROM products p
                   LEFT JOIN (
                       SELECT product_id, MIN(price) AS min_set_price, MAX(price) AS max_set_price
                       FROM product_sets
                       GROUP BY product_id
                   ) ps ON ps.product_id = p.id
                   WHERE p.store_id = '$user_id'
                   ORDER BY p.name ASC";
$services_result = mysqli_query($conn, $services_query);
$services = [];
while ($row = mysqli_fetch_assoc($services_result)) {
    $services[] = $row;
}

// Fetch Inventory Items for Dropdown
$inventory_query = "SELECT * FROM inventory_items WHERE store_id = '$user_id' ORDER BY item_name ASC";
$inventory_result = mysqli_query($conn, $inventory_query);
$inventory_items = [];
while ($row = mysqli_fetch_assoc($inventory_result)) {
$inventory_items[] = $row;
}

$linked_inventory = [];
$linked_result = mysqli_query($conn, "SELECT si.service_id, si.inventory_item_id, si.set_id, ii.item_name FROM service_inventory si JOIN inventory_items ii ON ii.id = si.inventory_item_id JOIN products p ON p.id = si.service_id WHERE p.store_id = '$user_id'");
while ($linked = mysqli_fetch_assoc($linked_result)) {
    $linked_inventory[] = $linked;
}
require_once __DIR__ . '/includes/header.php';
?>

<style>
    /* Keep this page and its shared navigation free of decorative icons. */
    body .fas, body .far, body .fab, body .fa { display: none !important; }

    .mapping-container {
        display: grid;
        grid-template-columns: minmax(240px, 1fr) minmax(0, 2fr);
        align-items: start;
        gap: 20px;
    }
    
    @media (max-width: 768px) {
        .mapping-container {
            grid-template-columns: 1fr;
        }
    }
    
    .service-list {
        background: var(--card-bg);
        border: 1px solid var(--border-color);
        border-radius: 8px;
        overflow: hidden;
    }
    
    .service-item {
        padding: 15px;
        border-bottom: 1px solid var(--border-color);
        cursor: pointer;
        transition: background 0.2s;
    }
    
    .service-item:hover, .service-item.active {
        background: rgba(212, 175, 55, 0.1);
    }
    
    .service-item:last-child {
        border-bottom: none;
    }
    
    .mapping-details {
        background: var(--card-bg);
        border: 1px solid var(--border-color);
        border-radius: 8px;
        padding: 20px;
        min-height: 400px;
    }
    
    .badge {
        font-size: 10px;
        padding: 2px 6px;
        border-radius: 4px;
        background: var(--accent-blue);
        color: white;
    }
    
    .table-mapping th, .table-mapping td {
        padding: 10px;
        border-bottom: 1px solid rgba(255,255,255,0.05);
        font-size: 14px;
    }
    
    .btn-sm {
        padding: 4px 8px;
        font-size: 12px;
    }
    .mapping-toolbar { display: flex; margin-bottom: 18px; }
    .mapping-toolbar .btn { min-height: 44px; }
    .mapping-container > * { min-width: 0; }
    .mapping-container .service-list, .mapping-container .mapping-details { border-radius: 16px; }
    .mapping-container .service-item {
        display: block; width: 100%; text-align: left; background: transparent;
        border: 0; border-bottom: 1px solid var(--border-color); font: inherit;
        min-height: 76px; color: var(--text-main); overflow-wrap: anywhere;
    }
    .mapping-container .service-item:hover, .mapping-container .service-item.active {
        background: rgba(212,175,55,.12); box-shadow: inset 3px 0 var(--accent-gold);
    }
    .mapping-container .service-item:focus-visible { outline: 2px solid var(--accent-gold); outline-offset: -3px; }
    .mapping-container .service-item > div:last-child { flex-wrap: wrap; gap: 6px 12px; }
    #mapping-content > div:first-child { gap: 12px; }
    #selected-service-name { overflow-wrap: anywhere; min-width: 0; }
    #mapping-content .btn { min-height: 44px; flex-shrink: 0; padding: 10px 14px; }
    .mapping-details .mapping-table { width: 100%; table-layout: fixed; }
    .mapping-table th, .mapping-table td { overflow-wrap: anywhere; }
    .mapping-table th:first-child { width: 26%; }
    .mapping-table th:nth-child(3) { width: 24%; }
    .mapping-table button { min-width: 44px; min-height: 44px; }
    .mapping-table td:nth-child(3) span { display: inline-block; line-height: 1.5; }
    @media (max-width: 768px) {
        .mapping-container { gap: 16px; }
        .mapping-container .service-list > div:last-child { max-height: 240px !important; overflow-y: auto; }
        .mapping-container .mapping-details { padding: 16px; min-height: 0; }
        #mapping-placeholder { padding: 28px 8px !important; line-height: 1.6; }
        #selected-service-name { font-size: 18px !important; }
        .mapping-details .mapping-table, .mapping-table tbody { display: block; }
        .mapping-table thead { display: none; }
        .mapping-table tbody { display: grid; gap: 8px; }
        .mapping-table tbody tr {
            display: grid; grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px 12px; border: 1px solid var(--border-color) !important; border-radius: 10px;
            padding: 16px; background: rgba(255,255,255,.025); align-items: start;
        }
        .mapping-table tbody td { display: block; padding: 0 !important; border: 0; min-width: 0; font-size: 14px; line-height: 1.5; white-space: normal; overflow-wrap: anywhere; }
        .mapping-table tbody td::before { content: attr(data-label); display: block; margin-bottom: 5px; font-size: 11px; font-weight: 600; letter-spacing: .5px; text-transform: uppercase; color: var(--text-muted); }
        .mapping-table tbody td:first-child { grid-column: 1 / -1; font-size: 15px; font-weight: 600 !important; }
        .mapping-table tbody td:nth-child(2), .mapping-table tbody td:nth-child(3) { text-align: left; }
        .mapping-table tbody td:nth-child(3) span { white-space: normal; font-size: 12px !important; padding: 4px 7px !important; }
        .mapping-table tbody td:last-child { text-align: right; }
        .mapping-table button { min-width: 64px; min-height: 44px; padding: 6px 8px; font-size: 13px; }
        #addMappingModal { padding: 12px; }
        #addMappingModal .modal-dialog { width: 100%; max-height: 90dvh; overflow-y: auto; min-width: 0; }
        #addMappingModal .modal-footer { flex-wrap: wrap; gap: 8px; }
        #addMappingModal .form-control { font-size: 16px; min-width: 0; width: 100%; }
    }
    @media (max-width: 360px) {
        #mapping-content > div:first-child { flex-wrap: wrap; }
        #mapping-content .btn { width: 100%; justify-content: center; }
    }
    @media (min-width: 769px) {
        .mapping-toolbar { margin-bottom: 24px; }
        .mapping-toolbar .btn {
            border-radius: 10px; padding: 11px 18px;
            background: #191919; border-color: #34322c; font-size: 13px;
        }
        .mapping-container {
            grid-template-columns: minmax(230px, .85fr) minmax(0, 2fr);
            gap: 24px;
        }
        .mapping-container .service-list {
            background: #181818; border-color: #34312a;
            box-shadow: 0 8px 28px rgba(0,0,0,.12);
        }
        .mapping-container .service-list > div:first-child {
            padding: 20px !important; background: #1d1c19 !important;
            font-size: 13px; letter-spacing: .2px; color: #d7d3c8;
        }
        .mapping-container .service-list > div:last-child { padding: 10px; }
        .mapping-container .service-item {
            border: 1px solid transparent; border-radius: 10px;
            padding: 17px 14px; margin-bottom: 8px; min-height: 94px;
            transition: background .18s, border-color .18s;
        }
        .mapping-container .service-item:last-child { margin-bottom: 0; }
        .mapping-container .service-item:hover {
            background: #23221e; border-color: #454033; box-shadow: none;
        }
        .mapping-container .service-item.active {
            background: linear-gradient(110deg, #37301d, #27241b);
            border-color: #786329; box-shadow: inset 3px 0 var(--accent-gold);
        }
        .mapping-container .service-item > div:first-child {
            font-size: 15px; line-height: 1.5;
        }
        .mapping-container .service-item > div:last-child {
            margin-top: 10px !important; font-size: 11px !important;
        }
        .mapping-container .mapping-details {
            padding: 26px; background: #1b1b1b; border-color: #34312a;
            box-shadow: 0 8px 28px rgba(0,0,0,.12);
        }
        #mapping-content > div:first-child {
            padding-bottom: 22px !important; margin-bottom: 24px !important;
            border-bottom-color: #373329 !important;
        }
        #selected-service-name { font-size: 23px !important; letter-spacing: -.4px; }
        #mapping-content .btn-primary {
            border-radius: 10px; padding: 12px 20px; font-weight: 700;
            box-shadow: 0 4px 14px rgba(212,175,55,.12);
        }
        #mapping-list > div {
            border: 1px solid #35322b; border-radius: 12px;
            overflow: hidden; margin-bottom: 20px !important;
            background: #1e1e1d;
        }
        #mapping-list h3 {
            padding: 16px 18px !important; margin: 0 !important;
            border-bottom-color: #35322b !important;
            background: #24221c; font-size: 14px !important;
            letter-spacing: .1px;
        }
        .mapping-details .mapping-table { margin: 0; }
        .mapping-details .mapping-table th {
            padding: 13px 12px !important; background: #191a19;
            color: #a9a598; font-size: 10px; letter-spacing: .7px;
            font-weight: 600; border-bottom: 1px solid #34322b;
        }
        .mapping-details .mapping-table td {
            padding: 10px 12px !important; font-size: 13px; height: 58px;
        }
        .mapping-details .mapping-table th:first-child,
        .mapping-details .mapping-table td:first-child { padding-left: 18px !important; }
        .mapping-table tbody tr:last-child { border-bottom: 0 !important; }
        .mapping-table tbody tr:hover td { background: rgba(212,175,55,.035); }
        .mapping-table td:nth-child(3) span {
            border: 1px solid rgba(52,152,219,.18); border-radius: 6px;
            padding: 4px 7px !important; font-size: 10px !important;
        }
        .mapping-table .btn-danger {
            min-height: 34px; padding: 6px 9px; border-radius: 7px;
            font-size: 11px; color: #ee9188 !important;
            background: rgba(231,76,60,.07) !important;
            border: 1px solid rgba(231,76,60,.16) !important;
        }
        .mapping-table .btn-danger:hover { background: rgba(231,76,60,.17) !important; }
        .mapping-table .btn-danger:focus-visible {
            outline: 2px solid #ee9188; outline-offset: 2px;
        }
        #mapping-placeholder { padding: 90px 24px !important; font-size: 14px; }
    }
/* One continuous workspace, with a divider instead of separate panels. */
.mapping-workspace { border: 1px solid #96a38e; border-radius: 16px; overflow: hidden; background: var(--card-bg); }
.mapping-workspace .mapping-toolbar { margin: 0; padding: 12px 16px; border-bottom: 1px solid #96a38e66; }
.mapping-workspace .mapping-back { display: inline-flex; align-items: center; justify-content: center; width: 40px; height: 40px; color: var(--text-main); text-decoration: none; font-size: 26px; line-height: 1; border-radius: 8px; }
.mapping-workspace .mapping-back:hover { background: #173d3210; }
.mapping-workspace .mapping-back:focus-visible { outline: 2px solid #173d32; outline-offset: 2px; }
.mapping-workspace .mapping-container { gap: 0; align-items: stretch; grid-template-columns: minmax(210px, .8fr) minmax(0, 2fr); }
.mapping-workspace .mapping-container .service-list,
.mapping-workspace .mapping-container .mapping-details { border: 0 !important; border-radius: 0; box-shadow: none; background: transparent !important; }
.mapping-workspace .mapping-container .service-list { border-right: 1px solid #96a38e !important; }
.mapping-workspace .mapping-container .service-list > div:first-child { padding: 14px !important; font-size: 12px; background: transparent !important; }
.mapping-workspace .mapping-container .service-list > div:last-child { padding: 8px; }
.mapping-workspace .mapping-container .service-item { min-height: 0; padding: 12px; margin-bottom: 6px; border-radius: 9px; }
.mapping-workspace .mapping-container .service-item > div:first-child { font-size: 13px; }
.mapping-workspace .mapping-container .service-item > div:last-child { margin-top: 5px !important; font-size: 11px !important; }
.mapping-workspace .mapping-container .mapping-details { padding: 16px; min-height: 320px; }
.mapping-workspace #mapping-content > div:first-child { padding-bottom: 14px !important; margin-bottom: 14px !important; }
.mapping-workspace #selected-service-name { font-size: 20px !important; }
.mapping-workspace #mapping-list > div { border: 0; border-radius: 0; box-shadow: none; margin-bottom: 14px !important; }
@media (max-width: 768px) {
 .mapping-workspace .mapping-container { grid-template-columns: minmax(0, 1fr); }
 .mapping-workspace .mapping-container .service-list { border-right: 0 !important; border-bottom: 1px solid #96a38e !important; }
 .mapping-workspace .mapping-container .service-list > div:last-child { display: flex; gap: 8px; overflow-x: auto; max-height: none !important; }
 .mapping-workspace .mapping-container .service-item { flex: 0 0 190px; width: 190px; margin: 0; }
 .mapping-workspace .mapping-container .mapping-details { padding: 12px; min-height: 0; }
 .mapping-workspace .mapping-toolbar { padding: 8px 10px; }
 .mapping-workspace #selected-service-name { font-size: 17px !important; }
 .mapping-workspace #mapping-content > div:first-child { flex-wrap: wrap; }
}
/* Scroll within each list while keeping service controls in view. */
.mapping-workspace .mapping-container { height: clamp(340px, 65dvh, 680px); min-height: 0; }
.mapping-workspace .mapping-container .service-list { display: flex; flex-direction: column; min-height: 0; overflow: hidden; }
.mapping-workspace .mapping-container .service-list > div:first-child { flex-shrink: 0; }
.mapping-workspace .mapping-container .service-list > div:last-child { min-height: 0; max-height: none !important; overflow-y: auto; scrollbar-width: thin; scrollbar-gutter: stable; overscroll-behavior: contain; }
.mapping-workspace .mapping-container .mapping-details { display: flex; flex-direction: column; min-height: 0; overflow: hidden; }
.mapping-workspace #mapping-content { flex: 1; flex-direction: column; min-height: 0; overflow: hidden; }
.mapping-workspace #mapping-content > div:first-child { flex-shrink: 0; }
.mapping-workspace #mapping-list { flex: 1; min-height: 0; overflow-y: scroll; overflow-x: hidden; scrollbar-width: thin; scrollbar-gutter: stable; overscroll-behavior: contain; padding-right: 6px; }
.mapping-workspace #mapping-list::-webkit-scrollbar { width: 7px; }
.mapping-workspace #mapping-list::-webkit-scrollbar-thumb { background: #8a9d82; border-radius: 8px; }
.mapping-workspace #mapping-list::-webkit-scrollbar-track { background: #173d320a; }
@media (max-width: 768px) {
 .mapping-workspace .mapping-container { height: auto; }
 .mapping-workspace .mapping-container .service-list > div:last-child { max-height: 160px !important; }
 .mapping-workspace .mapping-container .mapping-details { height: clamp(260px, 55dvh, 520px); }
}
.mapping-item-toggle { display: none; }
@media (max-width: 768px) {
 .mapping-workspace .mapping-table tbody { gap: 5px; }
 .mapping-workspace .mapping-table tbody tr { padding: 3px 9px; gap: 8px 12px; border-radius: 8px; }
 .mapping-workspace .mapping-table tbody tr:not(.mapping-item-expanded) td:not(:first-child) { display: none; }
 .mapping-workspace .mapping-table tbody td:first-child::before { display: none; }
 .mapping-workspace .mapping-desktop-item-name { display: none; }
 .mapping-workspace .mapping-table .mapping-item-toggle {
  display: block; width: 100%; min-width: 0; min-height: 30px;
  padding: 0; border: 0; border-radius: 0; background: transparent;
  color: inherit; font: inherit; font-size: 12px; font-weight: 650;
  text-align: left; overflow-wrap: anywhere; cursor: pointer;
 }
 .mapping-workspace .mapping-item-toggle:focus-visible { outline: 2px solid #173d32; outline-offset: 2px; }
 .mapping-workspace .mapping-table tbody tr.mapping-item-expanded { padding: 3px 9px 10px; }
}
/* Plain, legible mapping logic and removal action. */
.mapping-workspace .mapping-table td:nth-child(3) span {
 background: transparent !important; border: 0 !important; border-radius: 0 !important;
 padding: 0 !important; box-shadow: none !important;
 color: #203f32 !important; font-size: 12px !important; font-weight: 600; line-height: 1.5;
}
.mapping-workspace .mapping-table .btn-danger,
.mapping-workspace .mapping-table .btn-danger:hover {
 background: transparent !important; border: 0 !important; border-radius: 0;
 box-shadow: none !important; color: #923e32 !important;
 font-size: 12px; font-weight: 650; padding: 4px 0; min-height: 36px;
}
.mapping-workspace .mapping-table .btn-danger:hover { text-decoration: underline; text-underline-offset: 3px; }
.mapping-workspace .mapping-table .btn-danger:focus-visible { outline: 2px solid #923e32; outline-offset: 2px; }
/* Service name and starting price share one compact row. */
.mapping-workspace .mapping-container .service-item {
 display: flex; align-items: center; justify-content: space-between;
 gap: 10px; min-width: 0;
}
.mapping-workspace .mapping-container .service-item > div:first-child {
 flex: 1 1 auto; min-width: 0; white-space: nowrap;
 overflow: hidden; text-overflow: ellipsis;
}
.mapping-workspace .mapping-container .service-item > div:last-child {
 flex: 0 0 auto; margin-top: 0 !important; white-space: nowrap; flex-wrap: nowrap;
}
@media (max-width: 768px) {
 .mapping-workspace .mapping-container .service-list > div:last-child { display: block; }
 .mapping-workspace .mapping-container .service-item { width: 100%; margin: 0 0 6px; box-sizing: border-box; }
 .mapping-workspace .mapping-container .service-item:last-child { margin-bottom: 0; }
 .mapping-workspace .mapping-container .service-item > div:first-child { font-size: 12px; }
 .mapping-workspace .mapping-container .service-item > div:last-child { font-size: 11px !important; }
}
/* Compact back navigation strip. */
.mapping-workspace .mapping-toolbar { padding: 2px 10px; margin: 0; }
.mapping-workspace .mapping-back { width: 34px; height: 34px; font-size: 23px; }
@media (pointer: coarse) {
 .mapping-workspace .mapping-back { width: 44px; height: 44px; }
}
/* Override shared theme badge fills for mapping logic labels. */
body .main-content .mapping-workspace .mapping-table td[data-label="Logic"] span {
 background: transparent !important; background-color: transparent !important;
 background-image: none !important; border: 0 !important; box-shadow: none !important;
 padding: 0 !important;
}
/* Remove inherited spacing around the back arrow. */
body .main-content .mapping-workspace .mapping-toolbar {
 box-sizing: border-box; display: flex; align-items: center;
 height: 42px !important; min-height: 0 !important; gap: 0 !important;
 padding: 6px 12px !important; margin: 0 !important;
}
body .main-content .mapping-workspace .mapping-back {
 box-sizing: border-box; height: 28px !important; min-height: 0 !important;
 width: 32px; padding: 0 !important; margin: 0 !important; line-height: 1;
}
/* Extend the shared workspace and divider to its bottom edge. */
.mapping-workspace { display: flex; flex-direction: column; min-height: 320px; }
.mapping-workspace .mapping-toolbar { flex: 0 0 42px; }
.mapping-workspace .mapping-container { flex: 1 1 auto; height: auto; min-height: 0; }
@media (max-width: 768px) {
 .mapping-workspace .mapping-container { grid-template-rows: auto minmax(0, 1fr); }
 .mapping-workspace .mapping-container .service-list { max-height: 180px; }
 .mapping-workspace .mapping-container .mapping-details { height: auto; min-height: 0; }
}
#addMappingModal { padding: 16px; box-sizing: border-box; }
#addMappingModal .modal-dialog { box-sizing: border-box; width: 100%; max-width: 460px; max-height: calc(100dvh - 32px); margin: 0; overflow-y: auto; }
#addMappingModal .modal-header { gap: 12px; }
#addMappingModal .modal-header h2 { font-size: 20px; overflow-wrap: anywhere; }
#addMappingModal .close-btn { min-height: 44px; min-width: 44px; flex-shrink: 0; }
#addMappingModal .form-control { box-sizing: border-box; width: 100%; min-width: 0; min-height: 44px; }
#addMappingModal .modal-footer { display: flex; justify-content: flex-end; gap: 8px; padding: 14px 20px; }
#addMappingModal .modal-footer .btn { min-height: 44px; }
#addMappingModal #is_pax { width: 20px; height: 20px; flex-shrink: 0; }
body.mapping-success-open { overflow: hidden; }
dialog#mapping-success-message {
 box-sizing: border-box; position: fixed; inset: 0; margin: auto;
 width: min(340px, calc(100% - 32px)); max-width: none; max-height: calc(100dvh - 32px);
 padding: 28px 24px; border: 1px solid #96a38e; border-radius: 18px;
 background: #e4eadf; color: #20372b; text-align: center;
 box-shadow: 0 20px 60px #0004; overflow-y: auto;
}
dialog#mapping-success-message::backdrop { background: #10251ccc; backdrop-filter: blur(4px); }
#mapping-success-message .mapping-success-icon { display: grid; place-items: center; width: 48px; height: 48px; margin: 0 auto 14px; border-radius: 50%; background: #c5d8c5; color: #173d32; font-size: 22px; }
#mapping-success-title { margin: 0 0 8px; font-size: 21px; line-height: 1.3; color: #20372b; }
#mapping-success-description { margin: 0 0 20px; font-size: 14px; line-height: 1.6; overflow-wrap: anywhere; color: #425642; }
#mapping-success-message .btn { min-height: 44px; min-width: 100px; padding: 10px 24px; border: 0; border-radius: 10px; background: #173d32; color: #f0f1e6; font-size: 14px; cursor: pointer; }
@media (max-width: 480px) {
 #addMappingModal { padding: 12px; }
 #addMappingModal .modal-dialog { max-height: calc(100dvh - 24px); }
 #addMappingModal .modal-header, #addMappingModal .modal-body, #addMappingModal .modal-footer { padding: 16px; }
 #addMappingModal .modal-header h2 { font-size: 18px; }
 #addMappingModal .modal-footer .btn { flex: 0 0 auto; }
 #addMappingModal .form-control { font-size: 16px; }
}
#mapping-success-message { padding-top: 60px; }
#mapping-success-message .mapping-success-close { position: absolute; top: 10px; right: 10px; display: grid; place-items: center; width: 44px; height: 44px; padding: 0; border: 0; border-radius: 8px; background: #173d3210; color: #173d32; font-size: 24px; line-height: 1; cursor: pointer; }
#mapping-success-message .mapping-success-close:focus-visible { outline: 2px solid #173d32; outline-offset: 2px; }
#addMappingModal .modal-header { padding: 16px 20px; }
#addMappingModal .modal-body { padding: 18px 20px; }
#addMappingModal .form-group { margin-bottom: 14px; }
#addMappingModal .modal-body > .form-group:last-child { margin-bottom: 0; margin-top: 8px !important; }
@media (max-width: 480px) {
 #addMappingModal .modal-header, #addMappingModal .modal-body, #addMappingModal .modal-footer { padding: 14px 16px; }
}
#mapping-content .mapping-add-icon { display: inline-flex; align-items: center; justify-content: center; width: 34px; min-width: 34px; height: 34px; min-height: 34px; padding: 0; border-radius: 8px; font-size: 21px; line-height: 1; }
#mapping-content .mapping-add-icon,
#mapping-content .mapping-add-icon:hover,
#mapping-content .mapping-add-icon:active { background: transparent !important; border: 0 !important; box-shadow: none !important; color: #173d32 !important; }
#mapping-content .mapping-add-icon:focus-visible { outline: 2px solid #173d32; outline-offset: 2px; }
#addMappingModal .form-label { display: block; margin-bottom: 8px; font-size: 13px; font-weight: 600; line-height: 1.4; }
#addMappingModal select.form-control { display: block; max-width: 100%; padding: 11px 34px 11px 12px; border-radius: 9px; line-height: 1.5; text-overflow: ellipsis; color: #20372b; background-color: #cbd5c2; border: 1px solid #96a38e; cursor: pointer; }
#addMappingModal select.form-control:focus-visible { outline: 2px solid #173d32; outline-offset: 2px; }
#addMappingModal select.form-control option { background: #e4eadf; color: #20372b; }
#addMappingModal select.form-control option[hidden] { display: none; }
@media (max-width: 480px) {
 #addMappingModal select.form-control { font-size: 16px; min-height: 46px; }
}
/* Contained dropdown menus with readable, wrapping options. */
.mapping-picker { position: relative; width: 100%; min-width: 0; }
.mapping-picker-trigger { display: flex; align-items: center; justify-content: space-between; gap: 12px; width: 100%; min-height: 46px; padding: 11px 12px; border: 1px solid #96a38e; border-radius: 9px; background: #cbd5c2; color: #20372b; font: inherit; font-size: 14px; text-align: left; cursor: pointer; }
.mapping-picker-trigger span:first-child { min-width: 0; overflow-wrap: anywhere; }
.mapping-picker-menu { position: absolute; z-index: 20; top: calc(100% + 6px); left: 0; right: 0; box-sizing: border-box; margin-top: 0; box-shadow: 0 8px 20px #173d3226; padding: 5px; max-height: min(200px, 30dvh); overflow-y: auto; overflow-x: hidden; overscroll-behavior: contain; scrollbar-width: thin; border: 1px solid #96a38e; border-radius: 9px; background: #e4eadf; }
.mapping-picker-menu[hidden] { display: none; }
.mapping-picker-group { padding: 8px 9px 5px; font-size: 11px; font-weight: 700; color: #52654c; text-transform: uppercase; letter-spacing: .4px; }
.mapping-picker-option { display: block; width: 100%; min-height: 44px; padding: 10px; border: 0; border-radius: 6px; background: transparent; color: #20372b; text-align: left; font: inherit; font-size: 14px; line-height: 1.5; white-space: normal; overflow-wrap: anywhere; cursor: pointer; }
.mapping-picker-option:hover, .mapping-picker-option[aria-pressed="true"] { background: #c5d8c5; }
.mapping-picker-trigger:focus-visible, .mapping-picker-option:focus-visible { outline: 2px solid #173d32; outline-offset: -2px; }
@media (max-width: 480px) {
 .mapping-picker-trigger, .mapping-picker-option { font-size: 16px; }
 #addMappingModal .modal-dialog { border-radius: 14px; }
}
</style>

<section class="mapping-workspace" aria-label="Service inventory mapping"><div class="mapping-toolbar">
    <a href="inventory.php" class="mapping-back" aria-label="Back to Inventory" title="Back to Inventory"><span aria-hidden="true">&#8592;</span></a>
</div>

<?php if ($success_msg): ?>
    <dialog id="mapping-success-message" aria-labelledby="mapping-success-title" aria-describedby="mapping-success-description">
    <button type="button" class="mapping-success-close" aria-label="Close" onclick="document.getElementById('mapping-success-message').close()">&times;</button><span class="mapping-success-icon" aria-hidden="true">&#10003;</span>
    <h2 id="mapping-success-title">Success!</h2>
    <p id="mapping-success-description"><?php echo htmlspecialchars($success_msg, ENT_QUOTES, 'UTF-8'); ?></p>

</dialog>
<?php endif; ?>

<?php if ($error_msg): ?>
    <div class="alert alert-error"><?php echo $error_msg; ?></div>
<?php endif; ?>

<div class="mapping-container">
    <!-- Left: Service List -->
    <div class="service-list">
        <div style="padding: 15px; background: rgba(0,0,0,0.2); border-bottom: 1px solid var(--border-color); font-weight: bold;">
            Select a Service / Product
        </div>
        <div style="max-height: 600px; overflow-y: auto;">
            <?php foreach ($services as $service): ?>
                <button type="button" class="service-item" data-service-id="<?php echo (int) $service['id']; ?>" onclick="loadMappings(<?php echo (int) $service['id']; ?>, <?php echo htmlspecialchars(json_encode($service['name']), ENT_QUOTES, 'UTF-8'); ?>, this)" aria-pressed="false">
                    <div style="font-weight: 600; color: var(--text-main);"><?php echo htmlspecialchars($service['name']); ?></div>
                    <div style="font-size: 12px; color: var(--text-muted); display: flex; justify-content: space-between; margin-top: 4px;">
                        
                        <span style="color: var(--accent-gold);">
                            <?php if ($service['category'] === 'catering_service' && floatval($service['min_set_price']) > 0): ?>
                                <?php if (floatval($service['min_set_price']) == floatval($service['max_set_price'])): ?>
                                    &#8369;<?php echo number_format($service['min_set_price'], 2); ?>
                                <?php else: ?>
                                    From &#8369;<?php echo number_format($service['min_set_price'], 2); ?>
                                <?php endif; ?>
                            <?php else: ?>
                                &#8369;<?php echo number_format($service['price'], 2); ?>
                            <?php endif; ?>
                        </span>
                    </div>
                </button>
            <?php endforeach; ?>
        </div>
    </div>
    
    <!-- Right: Mapping Details -->
    <div class="mapping-details">
        <div id="mapping-placeholder" style="text-align: center; color: var(--text-muted); padding-top: 100px;">
            <p>Select a service to manage its inventory requirements.</p>
        </div>
        
        <div id="mapping-content" style="display: none;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; border-bottom: 1px solid var(--border-color); padding-bottom: 15px;">
                <h2 id="selected-service-name" style="margin: 0; color: var(--accent-gold); font-size: 20px;"></h2>
                <button type="button" class="btn btn-primary btn-sm mapping-add-icon" onclick="openAddModal()" aria-label="Add Item" title="Add Item"><span aria-hidden="true">+</span></button>
            </div>
            
            <div id="mapping-list">
                <!-- Loaded via AJAX -->
                <div style="text-align: center; padding: 20px;">Loading...</div>
            </div>
        </div>
    </div>
</div>

<!-- Add Mapping Modal -->
</section>
<div id="addMappingModal" class="caterer-modal">
    <div class="modal-dialog" role="dialog" aria-modal="true" aria-labelledby="mapping-modal-title">
        <div class="modal-header">
            <h2 id="mapping-modal-title">Link Inventory Item</h2>
            <button type="button" class="close-btn" style="font-size: 24px; line-height: 1;" aria-label="Close" onclick="closeModal('addMappingModal')">&times;</button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" value="add_mapping">
                <input type="hidden" name="service_id" id="modal_service_id">
                
                <div class="form-group" id="set_selection_group" style="display:none;">
                    <label for="modal_set_id" class="form-label">Service Set <span style="font-size: 12px; color: var(--text-muted);">(Optional)</span></label>
                    <select name="set_id" id="modal_set_id" class="form-control">
                        <option value="" hidden>General / All Sets</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="modal_inventory_item" class="form-label">Inventory Item</label>
                    <select name="inventory_item_id" id="modal_inventory_item" class="form-control" required>
                        <option value="" disabled hidden>Select Item</option>
                        <?php foreach (['consumable' => 'Consumables', 'equipment' => 'Equipment'] as $type => $type_label): ?>
                            <optgroup label="<?php echo $type_label; ?>">
                                <?php foreach ($inventory_items as $item): ?>
                                    <?php $item_type = strtolower(trim((string) ($item['item_type'] ?? 'equipment'))) === 'consumable' ? 'consumable' : 'equipment'; ?>
                                    <?php if ($item_type !== $type) continue; ?>
                                    <option value="<?php echo (int) $item['id']; ?>" data-item-name="<?php echo htmlspecialchars($item['item_name'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <?php echo htmlspecialchars($item['item_name'], ENT_QUOTES, 'UTF-8'); ?> (Available: <?php echo floatval($item['available_stock']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endforeach; ?>
                    </select>
                    <p id="mapping-items-empty" hidden role="status" style="font-size:13px;line-height:1.5;margin:8px 0 0;">No unadded inventory items available for this service.</p>
                </div>
                <div class="form-group">
                    <label for="mapping_units" class="form-label">Units to Add</label>
                    <input type="number" id="mapping_units" step="1" name="quantity_required" class="form-control" required min="1" inputmode="numeric" placeholder="Enter number of units">
                </div>
                
                <div class="form-group" style="display: flex; gap: 10px; align-items: center; margin-top: 15px; background: rgba(255,255,255,0.05); padding: 10px; border-radius: 6px;">
                    <input type="checkbox" name="is_pax_dependent" id="is_pax" value="1" checked>
                    <div>
                        <label for="is_pax" style="font-weight: 600; cursor: pointer;">Multiply by Pax Count?</label>
                        
                    </div>
                </div>
            </div>
            <div class="modal-footer">

                <button type="submit" class="btn btn-primary">Save Item</button>
            </div>
        </form>
    </div>
</div>

<script>


    document.getElementById('mapping-list').addEventListener('click', event => {
        if (!window.matchMedia('(max-width: 768px)').matches) return;
        const row = event.target.closest('.mapping-table tbody tr');
        if (!row || event.target.closest('form, a, input, select')) return;
        const button = row.querySelector('.mapping-item-toggle');
        if (!button) return;
        const expanded = row.classList.toggle('mapping-item-expanded');
        button.setAttribute('aria-expanded', String(expanded));
    });
    let currentServiceId = null;
    const linkedInventory = <?php echo json_encode($linked_inventory, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    function filterAvailableInventory() {
        const select = document.getElementById('modal_inventory_item');
        const normalizeItemName = name => String(name || '').trim().toLowerCase();
        let count = 0;
        Array.from(select.options).forEach(option => {
            if (!option.value) return;
            const linked = linkedInventory.some(row => String(row.service_id) === String(currentServiceId)
                && (String(row.inventory_item_id) === option.value
                    || normalizeItemName(row.item_name) === normalizeItemName(option.dataset.itemName)));
            option.hidden = linked;
            option.disabled = linked;
            if (linked && option.selected) select.value = '';
            if (!linked) count++;
        });
        select.querySelectorAll('optgroup').forEach(group => {
            group.hidden = !Array.from(group.querySelectorAll('option')).some(option => !option.disabled);
        });
        document.getElementById('mapping-items-empty').hidden = count !== 0;
        mappingForm.querySelector('button[type="submit"]').disabled = count === 0;
    }
    const mappingDraftKey = 'mapping-draft-<?php echo (int) $user_id; ?>';
    const mappingForm = document.querySelector('#addMappingModal form');
    function readMappingDraft() {
        try { return JSON.parse(sessionStorage.getItem(mappingDraftKey)) || {}; }
        catch (_) { return {}; }
    }
    function rememberMappingDraft() {
        if (!currentServiceId) return;
        const draft = {
            service: String(currentServiceId),
            open: document.getElementById('addMappingModal').style.display === 'flex',
            item: mappingForm.elements.inventory_item_id.value,
            quantity: mappingForm.elements.quantity_required.value,
            set: mappingForm.elements.set_id.value,
            pax: mappingForm.elements.is_pax_dependent.checked
        };
        try { sessionStorage.setItem(mappingDraftKey, JSON.stringify(draft)); } catch (_) {}
    }
    function syncMappingSetOption() {
        filterAvailableInventory();
        const select = mappingForm.elements.set_id;
        // Keep General available when a specific set is selected.
        select.options[0].hidden = select.value === '';
    }
    mappingForm.elements.set_id.addEventListener('change', syncMappingSetOption);
    function restoreMappingDraft() {
        syncMappingSetOption();
        const draft = readMappingDraft();
        if (draft.service !== String(currentServiceId)) return;
        mappingForm.elements.inventory_item_id.value = draft.item || '';
        mappingForm.elements.quantity_required.value = draft.quantity || '';
        mappingForm.elements.set_id.value = draft.set || '';
        syncMappingSetOption();
        mappingForm.elements.is_pax_dependent.checked = draft.pax !== false;
        filterAvailableInventory();
        if (draft.open) document.getElementById('addMappingModal').style.display = 'flex';
    }
    const mappingAdded = <?php echo json_encode($success_msg === 'Item added successfully.'); ?>;
    if (mappingAdded) {
        try { sessionStorage.removeItem(mappingDraftKey); } catch (_) {}
    }
    mappingForm.addEventListener('input', rememberMappingDraft);
    mappingForm.addEventListener('change', rememberMappingDraft);
    mappingForm.addEventListener('submit', rememberMappingDraft);
    window.addEventListener('pagehide', rememberMappingDraft);

    function loadMappings(serviceId, serviceName, trigger) {
        if (currentServiceId === serviceId) {
            currentServiceId = null;
            const viewUrl = new URL(window.location.href);
            viewUrl.searchParams.delete('service');
            history.replaceState(history.state, '', viewUrl);
            document.getElementById('mapping-content').style.display = 'none';
            document.getElementById('mapping-placeholder').style.display = 'block';
            document.getElementById('mapping-list').replaceChildren();
            document.getElementById('modal_service_id').value = '';
            document.querySelectorAll('.service-item').forEach(button => {
                button.classList.remove('active');
                button.setAttribute('aria-pressed', 'false');
            });
            return;
        }
        currentServiceId = serviceId;
        mappingForm.reset();
        restoreMappingDraft();
        const viewUrl = new URL(window.location.href);
        viewUrl.searchParams.set('service', serviceId);
        history.replaceState(history.state, '', viewUrl);
        document.getElementById('selected-service-name').textContent = serviceName;
        document.getElementById('modal_service_id').value = serviceId;
        
        document.getElementById('mapping-placeholder').style.display = 'none';
        document.getElementById('mapping-content').style.display = 'flex';
        
        // Highlight active item
        document.querySelectorAll('.service-item').forEach(el => {
            el.classList.toggle('active', el === trigger);
            el.setAttribute('aria-pressed', String(el === trigger));
        });
        document.getElementById('mapping-list').innerHTML = '<div role="status" style="padding: 24px; text-align: center;">Loading inventory items...</div>';
        
        // Fetch mappings
        fetch(`get_service_mappings.php?service_id=${serviceId}`)
            .then(response => { if (!response.ok) throw new Error('Unable to load'); return response.text(); })
            .then(html => {
                if (currentServiceId === serviceId) document.getElementById('mapping-list').innerHTML = html;
            }).catch(() => {
                if (currentServiceId === serviceId) document.getElementById('mapping-list').textContent = 'Unable to load inventory items. Select the service again to retry.';
            });
            
        // Fetch sets for dropdown
        fetch(`get_service_sets.php?service_id=${serviceId}`)
            .then(response => response.json())
            .then(data => {
                if (currentServiceId !== serviceId) return;
                const setSelect = document.getElementById('modal_set_id');
                const setGroup = document.getElementById('set_selection_group');
                setSelect.innerHTML = '<option value="" hidden>General / All Sets</option>';
                if (data && data.length > 0) {
                    data.forEach(set => {
                        const option = document.createElement('option');
                        option.value = set.id;
                        option.textContent = set.set_name;
                        setSelect.appendChild(option);
                    });
                    setGroup.style.display = 'block';
                } else {
                    setGroup.style.display = 'none';
                }
                restoreMappingDraft();
            }).catch(() => {
                if (currentServiceId === serviceId) document.getElementById('set_selection_group').style.display = 'none';
            });
    }
    
    function openAddModal() {
        if (!currentServiceId) return;
        filterAvailableInventory();
        document.getElementById('addMappingModal').style.display = 'flex';
        rememberMappingDraft();
    }
    
    function closeModal(id) {
        document.getElementById(id).style.display = 'none';
        rememberMappingDraft();
    }
    
    // Dismiss mapping dialogs only through their explicit close buttons.
    const mappingSuccessMessage = document.getElementById('mapping-success-message');
    if (mappingSuccessMessage) {
        mappingSuccessMessage.addEventListener('cancel', event => event.preventDefault());
        mappingSuccessMessage.showModal();
        document.body.classList.add('mapping-success-open');
        mappingSuccessMessage.addEventListener('close', () => {
            document.body.classList.remove('mapping-success-open');
        });
    }
    // Keep both menus inside the modal rather than using a wide native popup.
    function enhanceMappingSelect(select) {
        const picker = document.createElement('div');
        picker.className = 'mapping-picker';
        const trigger = document.createElement('button');
        trigger.type = 'button';
        trigger.className = 'mapping-picker-trigger';
        trigger.setAttribute('aria-expanded', 'false');
        const label = document.querySelector(`label[for="${select.id}"]`);
        if (label) {
            label.id = `${select.id}-label`;
            trigger.setAttribute('aria-labelledby', `${label.id} ${select.id}-value`);
        }
        const value = document.createElement('span');
        value.id = `${select.id}-value`;
        trigger.append(value);
        const menu = document.createElement('div');
        menu.className = 'mapping-picker-menu';
        menu.id = `${select.id}-menu`;
        menu.hidden = true;
        trigger.setAttribute('aria-controls', menu.id);
        picker.append(trigger, menu);
        select.after(picker);
        select.style.display = 'none';
        function positionPicker() {
            const bounds = select.closest('.modal-dialog').getBoundingClientRect();
            const field = trigger.getBoundingClientRect();
            const below = Math.max(0, Math.min(bounds.bottom, window.innerHeight) - field.bottom - 12);
            const above = Math.max(0, field.top - Math.max(bounds.top, 0) - 12);
            const openAbove = below < 150 && above > below;
            menu.style.top = openAbove ? 'auto' : 'calc(100% + 6px)';
            menu.style.bottom = openAbove ? 'calc(100% + 6px)' : 'auto';
            menu.style.maxHeight = `${Math.min(200, openAbove ? above : below)}px`;
        }
        window.addEventListener('resize', () => { if (!menu.hidden) positionPicker(); });
        function closePicker() {
            menu.hidden = true;
            trigger.setAttribute('aria-expanded', 'false');
        }
        function renderPicker() {
            value.textContent = select.selectedOptions[0]?.textContent.trim() || 'Select Item';
            menu.replaceChildren();
            let lastGroup = null;
            Array.from(select.options).forEach(option => {
                if (option.hidden || option.disabled) return;
                const group = option.parentElement.tagName === 'OPTGROUP' ? option.parentElement : null;
                if (group && group !== lastGroup) {
                    const heading = document.createElement('div');
                    heading.className = 'mapping-picker-group';
                    heading.textContent = group.label;
                    menu.append(heading);
                }
                lastGroup = group;
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'mapping-picker-option';
                button.textContent = option.textContent.trim();
                button.setAttribute('aria-pressed', String(option.selected));
                button.addEventListener('click', () => {
                    select.value = option.value;
                    select.dispatchEvent(new Event('change', {bubbles: true}));
                    closePicker();
                    trigger.focus();
                });
                menu.append(button);
            });
        }
        trigger.addEventListener('click', () => {
            const opening = menu.hidden;
            document.querySelectorAll('.mapping-picker-menu').forEach(other => { other.hidden = true; });
            document.querySelectorAll('.mapping-picker-trigger').forEach(other => other.setAttribute('aria-expanded', 'false'));
            renderPicker();
            positionPicker();
            menu.hidden = !opening;
            trigger.setAttribute('aria-expanded', String(opening));
        });
        picker.addEventListener('keydown', event => {
            if (event.key === 'Escape') { event.preventDefault(); closePicker(); trigger.focus(); }
            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                event.preventDefault();
                if (menu.hidden) { renderPicker(); positionPicker(); menu.hidden = false; trigger.setAttribute('aria-expanded', 'true'); }
                const buttons = Array.from(menu.querySelectorAll('button'));
                const index = buttons.indexOf(document.activeElement);
                const next = event.key === 'ArrowDown' ? index + 1 : (index < 0 ? buttons.length - 1 : index - 1);
                buttons[(next + buttons.length) % buttons.length]?.focus();
            }
        });
        select.addEventListener('change', renderPicker);
        select.addEventListener('invalid', event => { event.preventDefault(); trigger.focus(); trigger.click(); });
        new MutationObserver(renderPicker).observe(select, {childList: true, subtree: true, attributes: true, attributeFilter: ['hidden', 'disabled']});
        mappingForm.addEventListener('reset', () => queueMicrotask(renderPicker));
        renderPicker();
    }
    enhanceMappingSelect(document.getElementById('modal_set_id'));
    enhanceMappingSelect(document.getElementById('modal_inventory_item'));
    // The shared workspace bounds the panel to the available viewport height.
    const savedService = new URL(window.location.href).searchParams.get('service');
    const savedTrigger = Array.from(document.querySelectorAll('.service-item')).find(button => button.dataset.serviceId === savedService);
    if (savedTrigger) savedTrigger.click();
    let mappingRefreshBusy = false;
    async function refreshOpenMappings() {
        const list = document.getElementById('mapping-list');
        if (!currentServiceId || mappingRefreshBusy || document.hidden || list.contains(document.activeElement) || document.getElementById('addMappingModal').style.display === 'flex') return;
        const serviceId = currentServiceId;
        mappingRefreshBusy = true;
        try {
            const response = await fetch(`get_service_mappings.php?service_id=${serviceId}`, {cache: 'no-store'});
            if (!response.ok) return;
            const html = await response.text();
            if (currentServiceId !== serviceId || list.contains(document.activeElement)) return;
            const expanded = new Set(Array.from(list.querySelectorAll('.mapping-item-expanded input[name="mapping_id"]')).map(input => input.value));
            const scroll = list.scrollTop;
            list.innerHTML = html;
            list.querySelectorAll('input[name="mapping_id"]').forEach(input => {
                if (!expanded.has(input.value)) return;
                const row = input.closest('tr');
                row.classList.add('mapping-item-expanded');
                row.querySelector('.mapping-item-toggle')?.setAttribute('aria-expanded', 'true');
            });
            list.scrollTop = scroll;
        } catch (_) { /* Retain the current list while offline. */ }
        finally { mappingRefreshBusy = false; }
    }
    window.setInterval(refreshOpenMappings, 15000);
    window.addEventListener('focus', refreshOpenMappings);
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>


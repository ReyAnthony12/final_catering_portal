<?php
require_once '../config.php';

// Uncomment the next line for debugging purposes only
// error_log('get_menu_data.php accessed with POST data: ' . print_r($_POST, true));

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

try {
    // Validate input
    $set_id = isset($_POST['set_id']) ? intval($_POST['set_id']) : 0;
    
    if ($set_id <= 0) {
        throw new Exception('Invalid set ID');
    }

    // Query the specific set
    $query = "
        SELECT ps.*, p.name as product_name, sd.business_name as store_name
        FROM product_sets ps
        LEFT JOIN products p ON ps.product_id = p.id
        LEFT JOIN store_details sd ON p.store_id = sd.user_id
        WHERE ps.id = ?
    ";
    
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "i", $set_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    if (!$result || mysqli_num_rows($result) === 0) {
        throw new Exception('Set not found');
    }
    
    $set_data = mysqli_fetch_assoc($result);
    
    // Decode JSON fields
    $set_data['addons'] = json_decode($set_data['addons'] ?? '[]', true) ?: [];
    $set_data['swaps'] = json_decode($set_data['swaps'] ?? '[]', true) ?: [];
    
    // Parse menu items from description
    $included_items = [];
    if (!empty($set_data['description'])) {
        $included_items = array_filter(
            array_map('trim', 
                array_merge(
                    explode("\n", $set_data['description']),
                    explode(",", str_replace(["\n", "\r"], ",", $set_data['description']))
                )
            ),
            function($item) {
                return strlen($item) > 1 && 
                       !preg_match('/^\d+\.?\s*/', $item) && 
                       !preg_match('/php/i', $item) && 
                       !strpos($item, '₱');
            }
        );
    }
    
    // Return success response
    echo json_encode([
        'success' => true,
        'data' => [
            'set_id' => $set_data['id'],
            'set_name' => $set_data['set_name'],
            'description' => $set_data['description'],
            'price' => floatval($set_data['price']),
            'included_items' => array_values($included_items),
            'addons' => $set_data['addons'],
            'swaps' => $set_data['swaps']
        ]
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

mysqli_close($conn);
?>
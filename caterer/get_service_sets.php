<?php
require_once __DIR__ . '/../config.php';
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['store', 'caterer'])) {
    echo json_encode([]);
    exit();
}

$user_id = $_SESSION['user_id'];
$service_id = isset($_GET['service_id']) ? intval($_GET['service_id']) : 0;

$query = "SELECT id, set_name FROM product_sets WHERE product_id = '$service_id' ORDER BY set_name ASC";
$result = mysqli_query($conn, $query);

$sets = [];
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $sets[] = $row;
    }
}
header('Content-Type: application/json');
echo json_encode($sets);
?>


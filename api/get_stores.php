<?php
require_once __DIR__ . '/../config.php';
/** @var mysqli $conn */

header('Content-Type: application/json');

$store_available_sql = getStoreAvailabilitySql('sd.is_open', 'sd.business_open_time', 'sd.business_close_time', 'sd.auto_schedule_enabled');

$query = "SELECT u.*, sd.business_name, sd.location, sd.description, sd.is_open, sd.business_open_time, sd.business_close_time, sd.auto_schedule_enabled,
                 $store_available_sql AS is_available_now
          FROM users u
          LEFT JOIN store_details sd ON u.id = sd.user_id
          WHERE u.role = 'caterer' AND u.status = 'active'
          ORDER BY is_available_now DESC, u.created_at DESC";

$result = mysqli_query($conn, $query);

$stores = [];
while ($row = mysqli_fetch_assoc($result)) {
    $row['status'] = !empty($row['is_available_now']) ? 'open' : 'closed';
    $stores[] = $row;
}

echo json_encode($stores);
?>

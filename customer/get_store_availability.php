<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/payment_functions.php';
requireLogin();

if (getUserRole() !== 'customer') {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

header('Content-Type: application/json');

$store_id = intval($_GET['store_id'] ?? 0);
$start = trim($_GET['start'] ?? '');
$end = trim($_GET['end'] ?? '');

if ($store_id <= 0 || !$start || !$end) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Missing required parameters.']);
    exit();
}

$start_date = date('Y-m-d', strtotime($start));
$end_date = date('Y-m-d', strtotime($end));

if (!$start_date || !$end_date) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Invalid date range.']);
    exit();
}

$range = getStoreAvailabilityRange($store_id, $start_date, $end_date);

echo json_encode([
    'success' => true,
    'store_id' => $store_id,
    'max_bookings_per_day' => $range['max_bookings_per_day'],
    'booking_gap_days' => $range['booking_gap_days'],
    'holidays' => $range['holidays'],
    'dates' => $range['dates']
]);

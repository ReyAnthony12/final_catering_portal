<?php
require_once __DIR__ . '/../config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$impersonator = $_SESSION['admin_impersonator'] ?? null;

if (!$impersonator || ($impersonator['role'] ?? '') !== 'portal') {
    header('Location: ../login.php');
    exit();
}

$_SESSION['user_id'] = $impersonator['user_id'];
$_SESSION['username'] = $impersonator['username'];
$_SESSION['role'] = $impersonator['role'];
$_SESSION['first_name'] = $impersonator['first_name'];
$_SESSION['last_name'] = $impersonator['last_name'];
$_SESSION['profile_image'] = $impersonator['profile_image'];

unset($_SESSION['admin_impersonator']);

header('Location: dashboard.php');
exit();
?>

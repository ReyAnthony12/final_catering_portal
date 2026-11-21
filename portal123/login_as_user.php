<?php
require_once __DIR__ . '/../config.php';
/** @var mysqli $conn */
requireLogin();

// Only portal admin can do this
if (getUserRole() !== 'portal') {
    header('Location: ../index.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        header('Location: users.php?error=invalid_token');
        exit();
    }

    $target_user_id = mysqli_real_escape_string($conn, $_POST['id'] ?? '');

    // Get target user details
    $query = "SELECT * FROM users WHERE id = '$target_user_id'";
    $result = mysqli_query($conn, $query);

    if ($result && mysqli_num_rows($result) > 0) {
        $user = mysqli_fetch_assoc($result);

        $_SESSION['admin_impersonator'] = [
            'user_id' => $_SESSION['user_id'],
            'username' => $_SESSION['username'] ?? '',
            'role' => $_SESSION['role'] ?? 'portal',
            'first_name' => $_SESSION['first_name'] ?? '',
            'last_name' => $_SESSION['last_name'] ?? '',
            'profile_image' => $_SESSION['profile_image'] ?? ''
        ];

        // Overwrite session with target user
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['first_name'] = $user['first_name'];
        $_SESSION['last_name'] = $user['last_name'];
        $_SESSION['profile_image'] = $user['profile_image'];

        // Redirect based on role
        if ($user['role'] === 'caterer') {
            header('Location: ../caterer/dashboard.php');
        } elseif ($user['role'] === 'customer') {
            header('Location: ../customer/dashboard.php');
        } else {
            header('Location: dashboard.php');
        }
        exit();
    }
}

// Fallback
header('Location: users.php');
exit();
?>

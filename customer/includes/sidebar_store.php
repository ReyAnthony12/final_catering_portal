<style>
    .sidebar {
        width: var(--sidebar-width);
        background: linear-gradient(180deg, #1a1c23 0%, #13151a 100%);
        color: white;
        position: fixed;
        height: 100vh;
        left: 0;
        top: 0;
        z-index: 1000;
        transition: all 0.3s ease;
        display: flex;
        flex-direction: column;
    }

    .sidebar-header {
        padding: 25px 20px;
        text-align: center;
        border-bottom: 1px solid rgba(255,255,255,0.05);
    }

    .logo-img {
        width: 70px;
        height: 70px;
        border-radius: 50%;
        object-fit: cover;
        border: 2px solid var(--accent-gold);
        margin-bottom: 15px;
    }

    .sidebar-menu {
        list-style: none;
        padding: 20px 0;
        margin: 0;
        flex-grow: 1;
    }

    .sidebar-menu li a {
        display: flex;
        align-items: center;
        padding: 14px 25px;
        color: rgba(255,255,255,0.7);
        text-decoration: none;
        transition: all 0.2s;
        border-left: 3px solid transparent;
    }

    .sidebar-menu li a:hover, .sidebar-menu li a.active {
        background: rgba(255,255,255,0.05);
        color: white;
        border-left-color: var(--accent-gold);
    }

    .sidebar-menu li a i {
        width: 25px;
        margin-right: 10px;
        text-align: center;
    }

    @media (max-width: 768px) {
        .sidebar {
            transform: translateX(-100%);
        }
        .sidebar.active {
            transform: translateX(0);
        }
    }
</style>

<div class="sidebar">
    <div class="sidebar-header">
        <?php
        // Ensure $user_id is available
        if(!isset($user_id) && isset($_SESSION['user_id'])) $user_id = $_SESSION['user_id'];
        
        // Fetch caterer info if variables are not set
        if(!isset($profile_image) || !isset($business_name)) {
            // Check if we already have the connection
            if(isset($conn)) {
                $sidebar_query = "SELECT u.profile_image, sd.business_name FROM users u LEFT JOIN store_details sd ON u.id = sd.user_id WHERE u.id = '$user_id'";
                $sidebar_result = mysqli_query($conn, $sidebar_query);
                if($sidebar_result && mysqli_num_rows($sidebar_result) > 0) {
                    $sidebar_row = mysqli_fetch_assoc($sidebar_result);
                    $profile_image = !empty($sidebar_row['profile_image']) ? $sidebar_row['profile_image'] : 'uploads/logo.png';
                    $business_name = !empty($sidebar_row['business_name']) ? $sidebar_row['business_name'] : 'My Caterer';
                } else {
                    $profile_image = 'uploads/logo.png';
                    $business_name = 'My Caterer';
                }
            }
        }
        ?>
        <img src="../<?php echo htmlspecialchars($profile_image ?? 'uploads/logo.png'); ?>" alt="Logo" class="logo-img">
        <h3 style="margin: 0; font-size: 16px;"><?php echo htmlspecialchars($business_name ?? 'My Caterer'); ?></h3>
    </div>
    
    <ul class="sidebar-menu">
        <?php 
        $current_page = basename($_SERVER['PHP_SELF']);
        
        // Check status if not set
        if(!isset($is_store_closed_by_portal) && isset($conn)) {
            $status_q = "SELECT status FROM users WHERE id = '$user_id'";
            $status_r = mysqli_query($conn, $status_q);
            if($status_r && mysqli_num_rows($status_r) > 0) {
                $status_row = mysqli_fetch_assoc($status_r);
                $is_store_closed_by_portal = ($status_row['status'] === 'closed');
            } else {
                $is_store_closed_by_portal = false;
            }
        }
        ?>
        
        <?php if (isset($is_store_closed_by_portal) && $is_store_closed_by_portal): ?>
            <li><a href="dashboard.php" class="<?php echo $current_page == 'dashboard.php' ? 'active' : ''; ?>"><i class="fas fa-tachometer-alt"></i> Dashboard <span class="badge">Locked</span></a></li>
            <li><a href="messages.php" class="<?php echo $current_page == 'messages.php' ? 'active' : ''; ?>"><i class="fas fa-envelope"></i> Messages</a></li>
            <li><a href="add-menu.php" class="<?php echo $current_page == 'add-menu.php' ? 'active' : ''; ?>"><i class="fas fa-utensils"></i> Menu <span class="badge">Locked</span></a></li>
        <?php else: ?>
            <li><a href="dashboard.php" class="<?php echo $current_page == 'dashboard.php' ? 'active' : ''; ?>"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
            <li><a href="reservations.php" class="<?php echo $current_page == 'reservations.php' ? 'active' : ''; ?>"><i class="fas fa-calendar-check"></i> Reservations</a></li>
            <li><a href="messages.php" class="<?php echo $current_page == 'messages.php' ? 'active' : ''; ?>"><i class="fas fa-envelope"></i> Messages</a></li>
            <li><a href="add-menu.php" class="<?php echo $current_page == 'add-menu.php' ? 'active' : ''; ?>"><i class="fas fa-utensils"></i> Menu Management</a></li>
            <li><a href="reports.php" class="<?php echo $current_page == 'reports.php' ? 'active' : ''; ?>"><i class="fas fa-chart-bar"></i> Reports</a></li>
            <li><a href="subscription.php" class="<?php echo $current_page == 'subscription.php' ? 'active' : ''; ?>"><i class="fas fa-credit-card"></i> Subscription</a></li>
            <li><a href="profile.php" class="<?php echo $current_page == 'profile.php' ? 'active' : ''; ?>"><i class="fas fa-user"></i> Profile</a></li>
        <?php endif; ?>
        <li><a href="../logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
    </ul>
</div>

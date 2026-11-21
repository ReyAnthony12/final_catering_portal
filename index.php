<?php
// Start session only if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'config.php';

// Get system settings
$global_settings_query = "SELECT * FROM system_settings";
$global_settings_result = mysqli_query($conn, $global_settings_query);
$global_settings = [];
if ($global_settings_result) {
    while ($row = mysqli_fetch_assoc($global_settings_result)) {
        $global_settings[$row['setting_key']] = $row['setting_value'];
    }
}
$site_logo = !empty($global_settings['site_logo']) ? $global_settings['site_logo'] : 'uploads/logo.png';

// Give uploaded images a file-based version so browsers and hosting proxies do
// not keep a stale 404 after an image has been uploaded or replaced.
if (!function_exists('homepageAssetUrl')) {
    function homepageAssetUrl($path): string {
        $path = trim((string) $path);
        if ($path === '') {
            return '';
        }

        $isAbsolute = (bool) preg_match('~^(?:https?:)?//~i', $path);
        $url = $isAbsolute ? $path : BASE_URL . ltrim($path, '/');
        $localPath = __DIR__ . '/' . ltrim($path, '/');
        $version = is_file($localPath) ? (string) filemtime($localPath) : '20261122';
        $separator = strpos($url, '?') === false ? '?' : '&';
        return $url . $separator . 'v=' . rawurlencode($version);
    }
}

// Functions
if (!function_exists('isLoggedIn')) {
    function isLoggedIn() {
        return isset($_SESSION['user_id']) && isset($_SESSION['role']);
    }
}

if (!function_exists('getUserRole')) {
    function getUserRole() {
        return $_SESSION['role'] ?? null;
    }
}

// Handle search functionality
$search_results = [];
$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';
$search_query_safe = mysqli_real_escape_string($conn, $search_query);

if ($search_query !== '') {
    $products_query = "SELECT p.*, u.first_name, u.last_name, sd.business_name, sd.subscription_status,
                         (SELECT MIN(ps.price) FROM product_sets ps WHERE ps.product_id = p.id AND ps.price > 0) AS min_set_price
                  FROM products p 
                  JOIN users u ON p.store_id = u.id 
                  INNER JOIN store_details sd ON u.id = sd.user_id 
                  WHERE p.is_available = 1 
                  AND u.status = 'active' 
                  AND u.role = 'store'
                  AND sd.approval_status = 'approved'
                  AND p.name LIKE '%$search_query_safe%'";
    
    $products_query .= " ORDER BY p.created_at DESC LIMIT 20";
    $search_results_result = mysqli_query($conn, $products_query);

    if ($search_results_result) {
        while ($search_item = mysqli_fetch_assoc($search_results_result)) {
            $search_results[] = $search_item;
        }
    }
}

// Get all active approved stores/caterers
$stores_query = "SELECT u.*, sd.business_name, sd.is_open, sd.business_open_time, sd.business_close_time, sd.auto_schedule_enabled, sd.subscription_status 
                 FROM users u 
                 INNER JOIN store_details sd ON u.id = sd.user_id 
                 WHERE u.role = 'store' 
                   AND u.status = 'active' 
                   AND sd.approval_status = 'approved'
                 ORDER BY u.created_at DESC";
$stores_result = mysqli_query($conn, $stores_query);

$stores_array = [];
while ($caterer = mysqli_fetch_assoc($stores_result)) {
    $stores_array[] = $caterer;
}

$event_name_suggestions = [];
$event_suggestions_query = "
    SELECT DISTINCT p.name
    FROM products p
    JOIN users u ON p.store_id = u.id
    INNER JOIN store_details sd ON u.id = sd.user_id
    WHERE p.is_available = 1
      AND p.category = 'catering_service'
      AND u.role = 'store'
      AND u.status = 'active'
      AND sd.approval_status = 'approved'
    ORDER BY p.name ASC
    LIMIT 30
";
$event_suggestions_result = mysqli_query($conn, $event_suggestions_query);
if ($event_suggestions_result) {
    while ($event_row = mysqli_fetch_assoc($event_suggestions_result)) {
        if (!empty($event_row['name'])) {
            $event_name_suggestions[] = $event_row['name'];
        }
    }
}

// ==============================================
// GET RECOMMENDED SECTIONS
// ==============================================

// 1. Get Popular Stores
$popular_stores_query = "
    SELECT 
        u.id, 
        u.first_name, 
        u.last_name, 
        u.profile_image,
        sd.business_name, 
        sd.is_open,
        sd.business_open_time,
        sd.business_close_time,
        sd.auto_schedule_enabled,
        COUNT(DISTINCT o.id) as order_count,
        COUNT(DISTINCT p.id) as menu_count,
        COUNT(DISTINCT CASE WHEN p.category = 'catering_service' THEN p.id END) as service_count
    FROM users u
    INNER JOIN store_details sd ON u.id = sd.user_id
    LEFT JOIN bookings o ON u.id = o.store_id
    LEFT JOIN products p ON p.store_id = u.id AND p.is_available = 1
    WHERE u.role = 'store' 
    AND u.status = 'active'
    AND sd.approval_status = 'approved'
    GROUP BY u.id
    ORDER BY order_count DESC, u.created_at DESC
    LIMIT 5
";

$popular_stores_result = mysqli_query($conn, $popular_stores_query);
$popular_stores = [];
while ($caterer = mysqli_fetch_assoc($popular_stores_result)) {
    $popular_stores[] = $caterer;
}

// 2. Get Popular Products
$popular_products_query = "
    SELECT 
        p.*,
        u.first_name,
        u.last_name,
        u.profile_image,
        sd.business_name,
        sd.subscription_status,
        sd.is_open,
        sd.business_open_time,
        sd.business_close_time,
        sd.auto_schedule_enabled,
        COUNT(o.id) as order_count
    FROM products p
    JOIN users u ON p.store_id = u.id
    INNER JOIN store_details sd ON u.id = sd.user_id
    LEFT JOIN bookings o ON p.id = o.product_id
    WHERE p.is_available = 1
    AND u.status = 'active'
    AND u.role = 'store'
    AND sd.approval_status = 'approved'
    AND p.category = 'food'
    GROUP BY p.id
    ORDER BY order_count DESC, p.created_at DESC
    LIMIT 5
";
$popular_products_result = mysqli_query($conn, $popular_products_query);
$popular_products = [];
while ($product = mysqli_fetch_assoc($popular_products_result)) {
    $popular_products[] = $product;
}

// 3. Get Popular Catering Services
$popular_catering_query = "
    SELECT 
        p.*,
        u.first_name,
        u.last_name,
        u.profile_image,
        sd.business_name,
        sd.subscription_status,
        sd.is_open,
        sd.business_open_time,
        sd.business_close_time,
        sd.auto_schedule_enabled,
        (SELECT MIN(ps.price) FROM product_sets ps WHERE ps.product_id = p.id AND ps.price > 0) AS min_set_price,
        COUNT(o.id) as booking_count
    FROM products p
    JOIN users u ON p.store_id = u.id
    INNER JOIN store_details sd ON u.id = sd.user_id
    LEFT JOIN bookings o ON p.id = o.product_id
    WHERE p.is_available = 1
    AND u.status = 'active'
    AND u.role = 'store'
    AND sd.approval_status = 'approved'
    AND p.category = 'catering_service'
    GROUP BY p.id
    ORDER BY booking_count DESC, p.created_at DESC
    LIMIT 5
";
$popular_catering_result = mysqli_query($conn, $popular_catering_query);
$popular_catering = [];
while ($catering = mysqli_fetch_assoc($popular_catering_result)) {
    $popular_catering[] = $catering;
}

function getPackagePreviewItems($description, $limit = 4) {
    $items = [];
    $description = trim((string) $description);
    $decoded = json_decode($description, true);

    // Product sets can use categorized_menu_v1 or categorized_menu_v2.
    // Read their values recursively so JSON is never displayed as raw text.
    if (is_array($decoded)) {
        $menuData = isset($decoded['categories']) && is_array($decoded['categories']) ? $decoded['categories'] : $decoded;
        $collectItems = function ($value) use (&$collectItems, &$items, $limit) {
            if (count($items) >= $limit) {
                return;
            }
            if (is_array($value)) {
                foreach ($value as $child) {
                    $collectItems($child);
                    if (count($items) >= $limit) {
                        return;
                    }
                }
                return;
            }
            $value = trim((string) $value);
            if ($value !== '' && strlen($value) >= 2) {
                $items[] = $value;
            }
        };
        $collectItems($menuData);
        return $items;
    }

    foreach (preg_split('/[\r\n,]+/', $description) as $part) {
        $item = trim($part);
        if ($item !== '' && strlen($item) >= 2) {
            $items[] = $item;
            if (count($items) >= $limit) {
                break;
            }
        }
    }

    return $items;
}

$package_previews_query = "
    SELECT
        ps.id,
        ps.product_id,
        ps.set_name,
        ps.description,
        ps.price,
        ps.pax,
        p.name AS service_name,
        p.description AS service_description,
        p.image,
        p.store_id,
        u.first_name,
        u.last_name,
        sd.business_name
    FROM product_sets ps
    JOIN products p ON ps.product_id = p.id
    JOIN users u ON p.store_id = u.id
    INNER JOIN store_details sd ON u.id = sd.user_id
    WHERE p.is_available = 1
      AND p.category = 'catering_service'
      AND u.status = 'active'
      AND u.role = 'store'
      AND sd.approval_status = 'approved'
    ORDER BY ps.created_at DESC
";
$package_previews_result = mysqli_query($conn, $package_previews_query);
$catering_service_groups = [];
if ($package_previews_result) {
    while ($package_preview = mysqli_fetch_assoc($package_previews_result)) {
        $service_id = intval($package_preview['product_id']);
        if (!isset($catering_service_groups[$service_id])) {
            if (count($catering_service_groups) >= 4) {
                continue;
            }
            $catering_service_groups[$service_id] = ['service' => $package_preview, 'packages' => []];
        }
        if (count($catering_service_groups[$service_id]['packages']) < 4) {
            $catering_service_groups[$service_id]['packages'][] = $package_preview;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en" class="soft-sage-theme">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Catering Services Portal System</title>
    <link rel="icon" type="image/png" href="<?php echo htmlspecialchars(homepageAssetUrl($site_logo), ENT_QUOTES, 'UTF-8'); ?>">
    <link rel="shortcut icon" href="<?php echo htmlspecialchars(homepageAssetUrl($site_logo), ENT_QUOTES, 'UTF-8'); ?>">
    <link rel="apple-touch-icon" href="<?php echo htmlspecialchars(homepageAssetUrl($site_logo), ENT_QUOTES, 'UTF-8'); ?>">
    <!-- Preconnect to external CDNs for faster font/icon loading -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="preconnect" href="https://cdnjs.cloudflare.com">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        /* ==================== BASIC RESET & BODY STYLES ==================== */
        :root {
            --primary-bg: #101113;
            --secondary-bg: #17191d;
            --card-bg: #1f2328;
            --text-main: #f5f1e8;
            --text-muted: #c1b8aa;
            --accent-gold: #d6b35a;
            --accent-gold-hover: #bf983f;
            --border-color: rgba(255, 255, 255, 0.1);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        html {
            scroll-behavior: smooth;
            scrollbar-gutter: stable;
        }

        body {
            font-family: 'Inter', 'Segoe UI', sans-serif;
            line-height: 1.6;
            color: var(--text-main);
            background:
                radial-gradient(circle at top center, rgba(214, 179, 90, 0.08), transparent 22%),
                linear-gradient(180deg, #121315 0%, var(--primary-bg) 45%, #0d0e10 100%);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        .container {
            width: 100%;
            max-width: 1280px;
            margin: 0 auto;
            padding: 0 24px;
        }

        /* ==================== HEADER STYLES ==================== */
        .main-header {
            position: sticky;
            top: 0;
            z-index: 1000;
            background: rgba(16, 17, 19, 0.9);
            backdrop-filter: blur(14px);
            border-bottom: 1px solid var(--border-color);
            padding: 16px 0;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.18);
        }

        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 24px;
        }

        /* LOGO */
        .logo-section {
            display: flex;
            align-items: center;
            flex-shrink: 0;
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
            position: relative;
        }

        .logo-mark {
            position: relative;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            animation: logoEntrance 0.85s ease-out both;
            isolation: isolate;
        }

        .logo-mark::before {
            content: '';
            position: absolute;
            inset: -7px;
            border-radius: 14px;
            background: radial-gradient(circle, rgba(214, 179, 90, 0.22) 0%, rgba(214, 179, 90, 0.1) 42%, rgba(214, 179, 90, 0) 72%);
            opacity: 0.5;
            transform: scale(0.96);
            animation: logoHaloPulse 4.8s ease-in-out infinite;
            z-index: -1;
            pointer-events: none;
        }

        .logo-mark::after {
            content: '';
            position: absolute;
            top: -12%;
            left: -40%;
            width: 38%;
            height: 124%;
            background: linear-gradient(115deg, transparent 0%, rgba(255, 255, 255, 0.1) 45%, rgba(255, 255, 255, 0.65) 50%, rgba(255, 255, 255, 0.1) 55%, transparent 100%);
            transform: skewX(-20deg);
            opacity: 0;
            pointer-events: none;
        }

        .logo:hover .logo-mark::after {
            animation: logoShine 1.1s ease;
        }

        .logo-img {
            width: 48px;
            height: 48px;
            border-radius: 8px;
            object-fit: cover;
            border: 1px solid rgba(214, 179, 90, 0.65);
            box-shadow: 0 0 18px rgba(214, 179, 90, 0.2);
            animation: logoFloat 4.8s ease-in-out infinite, logoGlow 3.6s ease-in-out infinite;
            transform-origin: center;
            will-change: transform, box-shadow;
            transition: transform 0.28s ease, box-shadow 0.28s ease, filter 0.28s ease;
            display: block;
        }

        .logo h1 {
            font-size: 13px;
            color: var(--text-main);
            font-weight: 700;
            letter-spacing: -0.5px;
            background: linear-gradient(135deg, #fff 0%, #f3e5bf 42%, var(--accent-gold) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            text-shadow: 0 0 18px rgba(214, 179, 90, 0.08);
            transition: filter 0.28s ease;
        }

        .logo:hover .logo-img {
            transform: translateY(-2px) scale(1.04);
            box-shadow:
                0 0 22px rgba(214, 179, 90, 0.42),
                0 0 34px rgba(214, 179, 90, 0.18);
            filter: brightness(1.06);
        }

        .logo:hover h1 {
            filter: brightness(1.06);
        }

        @keyframes logoFloat {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-4px); }
        }

        @keyframes logoEntrance {
            0% {
                opacity: 0;
                transform: translateY(8px) scale(0.94);
            }
            100% {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        @keyframes logoGlow {
            0%, 100% {
                box-shadow:
                    0 0 12px rgba(214, 179, 90, 0.2),
                    0 0 20px rgba(214, 179, 90, 0.12);
            }
            50% {
                box-shadow:
                    0 0 18px rgba(214, 179, 90, 0.38),
                    0 0 30px rgba(214, 179, 90, 0.18);
            }
        }

        @keyframes logoHaloPulse {
            0%, 100% {
                opacity: 0.38;
                transform: scale(0.96);
            }
            50% {
                opacity: 0.66;
                transform: scale(1.02);
            }
        }

        @keyframes logoShine {
            0% {
                left: -40%;
                opacity: 0;
            }
            15% {
                opacity: 1;
            }
            100% {
                left: 118%;
                opacity: 0;
            }
        }

        /* SEARCH BAR */
        .header-search-container {
            flex: 1;
            max-width: 600px;
            position: relative;
            margin: 0 20px;
        }

        .search-form {
            position: relative;
            width: 100%;
        }

        .search-container {
            display: flex;
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 10px;
            transition: border-color 0.2s, box-shadow 0.2s;
            overflow: hidden;
        }

        .search-container:focus-within {
            border-color: var(--accent-gold);
            box-shadow: 0 0 0 4px rgba(214, 179, 90, 0.08);
        }

        .search-container input {
            flex: 1;
            padding: 10px 16px;
            border: none;
            font-size: 15px;
            background: transparent;
            color: var(--text-main);
            outline: none;
        }

        .search-btn {
            background: transparent;
            color: var(--accent-gold);
            border: none;
            padding: 0 20px;
            cursor: pointer;
            font-weight: 600;
            font-size: 14px;
            transition: color 0.2s;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .search-btn:hover {
            color: var(--text-main);
        }

        /* NAV BUTTONS */
        .nav-section {
            display: flex;
            align-items: center;
            gap: 16px;
            flex-shrink: 0;
        }

        .stores-btn {
            background: rgba(255, 255, 255, 0.02);
            color: var(--text-main);
            border: 1px solid var(--border-color);
            padding: 8px 16px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .stores-btn:hover {
            border-color: var(--accent-gold);
            color: var(--accent-gold);
            background: rgba(214, 179, 90, 0.07);
        }

        .user-info, .auth-buttons {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .welcome {
            color: var(--text-muted);
            font-size: 14px;
            margin-right: 8px;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 8px 16px;
            border-radius: 6px;
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.2s;
            gap: 8px;
            cursor: pointer;
        }

        .btn-login {
            color: var(--text-main);
            border: 1px solid transparent;
        }
        
        .btn-login:hover {
            color: var(--accent-gold);
            background: rgba(255, 255, 255, 0.03);
        }

        .btn-register, .btn-dashboard {
            background: var(--accent-gold);
            color: #000;
            border: none;
            box-shadow: 0 12px 24px rgba(214, 179, 90, 0.18);
        }

        .btn-register:hover, .btn-dashboard:hover {
            background: var(--accent-gold-hover);
            transform: translateY(-1px);
        }

        .btn-logout {
            color: #ff4444;
            border: 1px solid rgba(255, 68, 68, 0.2);
            padding: 7px 12px;
        }

        .btn-logout:hover {
            background: rgba(255, 68, 68, 0.1);
        }

        /* ==================== HERO SECTION ==================== */
        .hero-section {
            position: relative;
            padding: 112px 0 72px;
            background: linear-gradient(rgba(9, 10, 12, 0.58), rgba(9, 10, 12, 0.84)), url('uploads/background/background.png') no-repeat center/cover;
            text-align: center;
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
            overflow: hidden;
        }

        .hero-section::before {
            content: '';
            position: absolute;
            inset: auto 50% -160px auto;
            width: 420px;
            height: 420px;
            transform: translateX(50%);
            background: radial-gradient(circle, rgba(214, 179, 90, 0.16) 0%, rgba(214, 179, 90, 0.05) 38%, rgba(214, 179, 90, 0) 72%);
            filter: blur(8px);
            pointer-events: none;
        }

        .hero-overlay {
            display: none; /* Handled by linear-gradient above */
        }

        .hero-content {
            max-width: 940px;
            margin: 0 auto;
            position: relative;
            z-index: 2;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 20px;
        }

        .hero-title {
            font-size: 3.5rem;
            font-weight: 800;
            color: #ffffff;
            margin-bottom: 0;
            line-height: 1.1;
            letter-spacing: -1px;
            opacity: 0;
            animation: premiumReveal 1.4s cubic-bezier(0.16, 1, 0.3, 1) forwards;
        }

        .hero-title .highlight-text {
            background: linear-gradient(135deg, #f7ebc6 0%, #d6b35a 50%, #b89742 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            text-shadow: 0 4px 25px rgba(214, 179, 90, 0.3);
            display: inline-block;
        }

        @keyframes premiumReveal {
            0% {
                opacity: 0;
                transform: translateY(35px);
            }
            100% {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .hero-subtitle {
            font-size: 1.25rem;
            color: #e0d8cb;
            margin: 0;
            font-weight: 400;
            max-width: 720px;
            margin-left: auto;
            margin-right: auto;
            opacity: 0;
            animation: premiumReveal 1.4s cubic-bezier(0.16, 1, 0.3, 1) forwards 0.25s;
        }

        .hero-highlights {
            width: 100%;
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 16px;
            margin-top: 8px;
        }

        .hero-highlight {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(214, 179, 90, 0.18);
            border-radius: 14px;
            padding: 18px 16px;
            text-align: center;
            backdrop-filter: blur(10px);
            box-shadow: 0 12px 32px rgba(0, 0, 0, 0.18);
            transition: transform 0.28s ease, border-color 0.28s ease, box-shadow 0.28s ease, background 0.28s ease;
        }

        .hero-highlight:hover {
            transform: translateY(-4px);
            border-color: rgba(214, 179, 90, 0.34);
            background: rgba(255, 255, 255, 0.07);
            box-shadow: 0 18px 34px rgba(0, 0, 0, 0.22);
        }

        .hero-highlight strong {
            display: block;
            font-size: 1.4rem;
            color: var(--accent-gold);
            line-height: 1.1;
            margin-bottom: 6px;
        }

        .hero-highlight span {
            color: #e7dfd2;
            font-size: 0.95rem;
        }

        .hero-btn {
            display: inline-block;
            padding: 16px 32px;
            font-size: 16px;
            font-weight: 600;
            color: #000;
            background: var(--accent-gold);
            border-radius: 8px;
            text-decoration: none;
            transition: background 0.2s, transform 0.2s, box-shadow 0.2s;
            box-shadow: 0 16px 30px rgba(214, 179, 90, 0.22);
        }

        .hero-btn:hover {
            background: var(--accent-gold-hover);
            transform: translateY(-1px);
        }

        .hero-actions {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            flex-wrap: wrap;
            opacity: 0;
            animation: premiumReveal 1.4s cubic-bezier(0.16, 1, 0.3, 1) forwards 0.45s;
        }

        .hero-btn-secondary {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 15px 24px;
            border: 1px solid rgba(214, 179, 90, 0.46);
            border-radius: 8px;
            color: var(--text-main);
            background: rgba(16, 17, 19, 0.42);
            text-decoration: none;
            font-weight: 600;
            transition: all 0.2s ease;
        }

        .hero-btn-secondary:hover {
            color: var(--accent-gold);
            border-color: var(--accent-gold);
            background: rgba(214, 179, 90, 0.1);
        }

        .browse-intro {
            padding: 34px 0;
            background: #141619;
            border-top: 1px solid rgba(214, 179, 90, 0.12);
            border-bottom: 1px solid rgba(214, 179, 90, 0.12);
        }

        .browse-steps {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 18px;
        }

        .browse-step {
            display: flex;
            align-items: center;
            gap: 14px;
            color: var(--text-muted);
        }

        .browse-step-icon {
            width: 42px;
            height: 42px;
            flex: 0 0 42px;
            display: grid;
            place-items: center;
            border: 1px solid rgba(214, 179, 90, 0.3);
            border-radius: 50%;
            color: var(--accent-gold);
            background: rgba(214, 179, 90, 0.07);
        }

        .browse-step strong {
            display: block;
            color: var(--text-main);
            margin-bottom: 2px;
        }

        @keyframes heroReveal {
            0% {
                opacity: 0;
                transform: translateY(20px);
            }
            100% {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .search-results-section {
            padding: 48px 0 28px;
            background: var(--secondary-bg);
            border-bottom: 1px solid var(--border-color);
        }

        .search-results-head {
            display: flex;
            flex-direction: row;
            justify-content: space-between;
            align-items: flex-start;
            gap: 16px;
            margin-bottom: 36px;
            width: 100%;
        }

        .search-results-meta {
            color: var(--text-muted);
            font-size: 15px;
        }

        .search-results-meta strong {
            color: var(--text-main);
        }

        .clear-search-link {
            color: var(--accent-gold);
            text-decoration: none;
            font-weight: 600;
            align-self: flex-start;
            display: inline-block;
        }

        .clear-search-link:hover {
            opacity: 0.85;
        }

        /* ==================== CONTENT SECTIONS ==================== */
        .content-section {
            padding: 80px 0;
            background: var(--primary-bg);
        }
        
        .content-section:nth-child(even) {
            background: var(--secondary-bg);
        }

        .section-header {
            text-align: center;
            margin-bottom: 60px;
        }

        .section-kicker {
            display: inline-block;
            color: var(--accent-gold);
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 1.8px;
            text-transform: uppercase;
            margin-bottom: 14px;
        }

        .section-title {
            font-size: 2rem;
            color: var(--text-main);
            margin-bottom: 16px;
            font-weight: 700;
            position: relative;
            display: inline-block;
        }

        .section-desc {
            color: var(--text-muted);
            font-size: 1.1rem;
            max-width: 720px;
            margin: 0 auto;
        }

        .items-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
            gap: 20px;
        }

        /* CARD STYLES */
        .item-card {
            background: linear-gradient(180deg, rgba(255,255,255,0.03), rgba(255,255,255,0.015));
            border-radius: 8px;
            overflow: hidden;
            transition: transform 0.2s, box-shadow 0.2s;
            display: flex;
            flex-direction: column;
            border: 1px solid var(--border-color);
            height: 100%;
        }

        .item-card:hover {
            transform: translateY(-4px);
            border-color: rgba(214, 179, 90, 0.28);
            box-shadow: 0 18px 40px rgba(0,0,0,0.24);
        }

        .caterer-card {
            border-radius: 14px;
        }

        .caterer-card .item-image {
            height: 210px;
        }

        .caterer-card .item-content {
            padding: 20px;
        }

        .caterer-meta {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin: 2px 0 16px;
        }

        .caterer-meta span {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 9px;
            border-radius: 999px;
            background: rgba(214, 179, 90, 0.08);
            border: 1px solid rgba(214, 179, 90, 0.15);
            color: #e6dcc6;
            font-size: 12px;
        }

        .caterer-card .item-footer {
            align-items: center;
        }

        .caterer-card .item-link {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 9px 12px;
            border-radius: 8px;
            color: #16130b;
            background: var(--accent-gold);
        }

        .caterer-card .item-link:hover {
            color: #16130b;
            opacity: 1;
            background: var(--accent-gold-hover);
        }

        .featured-caterers-section {
            position: relative;
            background:
                radial-gradient(circle at 50% 0%, rgba(214, 179, 90, 0.07), transparent 34%),
                var(--primary-bg);
        }

        /* â”€â”€ Caterer Overlay Card â”€â”€ */
        .caterer-overlay-card {
            display: block;
            position: relative;
            border-radius: 14px;
            overflow: hidden;
            text-decoration: none;
            border: 1px solid rgba(255,255,255,0.08);
            transition: transform 0.28s ease, box-shadow 0.28s ease, border-color 0.28s ease;
            aspect-ratio: 3/4;
            background: #1a1c1f;
        }

        .caterer-no-img {
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #1f2128, #2a2d36);
            position: absolute;
            inset: 0;
        }

        .caterer-no-img span {
            font-size: 3rem;
            font-weight: 800;
            color: rgba(214, 179, 90, 0.5);
            line-height: 1;
        }

        .caterer-overlay-card:hover {
            transform: translateY(-5px);
            border-color: rgba(214, 179, 90, 0.4);
            box-shadow: 0 20px 44px rgba(0,0,0,0.32);
        }

        .caterer-overlay-img {
            width: 100%;
            height: 100%;
            position: absolute;
            inset: 0;
        }

        .caterer-overlay-img img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.5s ease;
        }

        .caterer-overlay-card:hover .caterer-overlay-img img {
            transform: scale(1.05);
        }

        .caterer-overlay-info {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            padding: 16px 18px;
            background: linear-gradient(to top, #142d25 0%, rgba(20,45,37,0.98) 65%, rgba(20,45,37,0.94) 100%);
            border-top: 1px solid rgba(255,255,255,0.18);
        }

        .caterer-overlay-name {
            font-size: 1.05rem;
            font-weight: 700;
            color: #fff;
            margin-bottom: 8px;
            line-height: 1.3;
            text-shadow: 0 1px 2px rgba(0,0,0,0.35);
        }

        .caterer-overlay-info .status-open {
            color: #b9f6ca;
        }

        .caterer-overlay-info .status-closed {
            color: #ffc5bf;
        }

        .caterer-overlay-footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            flex-wrap: wrap;
        }

        .caterer-overlay-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 13px;
            font-weight: 600;
            color: #000;
            background: var(--accent-gold);
            padding: 6px 14px;
            border-radius: 6px;
            transition: background 0.2s;
            white-space: nowrap;
        }

        .caterer-overlay-card:hover .caterer-overlay-btn {
            background: var(--accent-gold-hover);
        }

        .featured-caterers-grid {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 16px;
            justify-items: stretch;
        }

        @media (max-width: 1100px) {
            .featured-caterers-grid {
                grid-template-columns: repeat(3, 1fr);
            }
        }

        @media (max-width: 768px) {
            .featured-caterers-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 480px) {
            .featured-caterers-grid {
                grid-template-columns: 1fr;
            }
        }

        /* A single caterer should feel like a featured showcase, not a small lonely card. */
        .featured-caterers-grid .single-featured-caterer {
            grid-column: 1 / -1;
            max-width: 760px;
            width: 100%;
            margin: 0 auto;
            display: grid;
            grid-template-columns: minmax(260px, 310px) minmax(0, 1fr);
        }

        .featured-caterers-grid .single-featured-caterer .item-image {
            height: 100%;
            min-height: 260px;
        }

        .featured-caterers-grid .single-featured-caterer .item-content {
            justify-content: center;
            padding: 28px;
        }

        .featured-caterers-grid .single-featured-caterer .item-title {
            font-size: 1.35rem;
        }

        .featured-caterers-grid .single-featured-caterer .caterer-meta {
            margin: 8px 0 24px;
        }

        .item-image {
            height: 160px;
            width: 100%;
            overflow: hidden;
            position: relative;
        }

        .item-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.5s;
        }

        .item-card:hover .item-image img {
            transform: scale(1.05);
        }

        .rank-badge {
            position: absolute;
            top: 12px;
            left: 12px;
            background: rgba(12, 13, 15, 0.78);
            color: var(--accent-gold);
            padding: 4px 12px;
            border-radius: 4px;
            font-weight: 700;
            font-size: 14px;
            backdrop-filter: blur(4px);
            z-index: 2;
        }

        .item-content {
            padding: 16px;
            flex: 1;
            display: flex;
            flex-direction: column;
        }

        .item-category {
            font-size: 12px;
            color: var(--accent-gold);
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 8px;
            font-weight: 600;
        }

        .item-title {
            font-size: 1rem;
            color: var(--text-main);
            margin-bottom: 8px;
            font-weight: 600;
            line-height: 1.4;
        }

        .item-details {
            color: var(--text-muted);
            font-size: 14px;
            margin-bottom: 14px;
            flex: 1;
        }

        .item-details p,
        .item-details span {
            display: -webkit-box;
            -webkit-line-clamp: 3;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .item-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-top: 12px;
            border-top: 1px solid var(--border-color);
            margin-top: auto;
        }

        .item-price {
            font-size: 0.95rem;
            color: var(--text-main);
            font-weight: 700;
        }

        .item-caterer {
            font-size: 13px;
            color: var(--text-muted);
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .status-text {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-weight: 600;
        }

        .status-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            display: inline-block;
        }

        .status-open {
            color: #7bd88f;
        }

        .status-open .status-dot {
            background: #4caf50;
            box-shadow: 0 0 10px rgba(76, 175, 80, 0.45);
        }

        .status-closed {
            color: #ff8a80;
        }

        .status-closed .status-dot {
            background: #f44336;
            box-shadow: 0 0 10px rgba(244, 67, 54, 0.4);
        }

        .item-link {
            color: var(--accent-gold);
            text-decoration: none;
            font-weight: 600;
            transition: opacity 0.2s;
        }

        .item-link:hover {
            opacity: 0.85;
        }

        .package-preview-list {
            display: grid;
            gap: 8px;
            margin: 12px 0 14px;
            padding: 12px;
            border: 1px solid rgba(214, 179, 90, 0.14);
            border-radius: 12px;
            background: rgba(214, 179, 90, 0.05);
        }

        .package-preview-list strong {
            color: var(--accent-gold);
            font-size: 12px;
            letter-spacing: 0.5px;
            text-transform: uppercase;
        }

        .package-preview-list ul {
            margin: 0;
            padding-left: 18px;
            color: var(--text-muted);
            font-size: 13px;
        }

        .package-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            padding-top: 12px;
            border-top: 1px solid var(--border-color);
            margin-top: auto;
        }

        .package-actions a {
            flex: 1;
            min-width: 130px;
            text-align: center;
            text-decoration: none;
            border-radius: 10px;
            padding: 10px 12px;
            font-size: 13px;
            font-weight: 700;
        }

        .package-card .item-image {
            height: 175px;
        }

        .package-card .item-content {
            padding: 20px;
        }

        .service-package-list {
            display: grid;
            gap: 24px;
        }

        .service-package-card {
            display: grid;
            grid-template-columns: 88px minmax(0, 1fr);
            gap: 14px;
            padding: 18px;
            border: 1px solid rgba(255, 255, 255, 0.12);
            border-radius: 14px;
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.045), rgba(255, 255, 255, 0.018));
            box-shadow: 0 14px 32px rgba(0, 0, 0, 0.18);
        }

        .service-cover {
            width: 88px;
            height: 88px;
            border-radius: 10px;
            overflow: hidden;
            border: 1px solid rgba(214, 179, 90, 0.28);
        }

        .service-cover img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .service-heading {
            min-width: 0;
        }

        .service-heading h3 {
            color: var(--text-main);
            font-size: 1.16rem;
            margin-bottom: 4px;
        }

        .service-heading p {
            color: var(--text-muted);
            font-size: 13px;
            line-height: 1.5;
        }

        .service-inclusions {
            grid-column: 2;
            padding: 10px 12px;
            border: 1px solid rgba(214, 179, 90, 0.28);
            border-radius: 7px;
            color: #dfd3b7;
            background: rgba(214, 179, 90, 0.055);
            font-size: 12px;
        }

        .service-inclusions strong {
            color: var(--accent-gold);
            margin-right: 6px;
        }

        .package-row {
            grid-column: 1 / -1;
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 18px;
            align-items: center;
            padding-top: 15px;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
        }

        .package-row:first-of-type {
            margin-top: 2px;
        }

        .package-row h4 {
            color: var(--text-main);
            font-size: 14px;
            margin-bottom: 3px;
        }

        .package-row p {
            color: #e1c976;
            font-size: 12px;
            font-weight: 700;
            margin-bottom: 5px;
        }

        .package-row .menu-preview {
            color: var(--text-muted);
            font-size: 12px;
            line-height: 1.45;
        }

        .package-row-actions {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
            justify-content: flex-end;
        }

        .package-row-actions a {
            padding: 8px 12px;
            border-radius: 6px;
            text-decoration: none;
            font-size: 12px;
            font-weight: 700;
            white-space: nowrap;
        }

        .package-row-actions .view-package {
            color: var(--text-main);
            border: 1px solid rgba(255, 255, 255, 0.18);
        }

        .package-row-actions .book-package {
            color: #17140b;
            background: var(--accent-gold);
        }

        .package-price {
            color: var(--accent-gold);
            font-size: 1.12rem;
            font-weight: 800;
        }

        .btn-preview-menu {
            color: var(--accent-gold);
            border: 1px solid rgba(214, 179, 90, 0.35);
            background: rgba(214, 179, 90, 0.06);
        }

        .btn-book-login {
            color: #17140b;
            background: var(--accent-gold);
            border: 1px solid var(--accent-gold);
        }

        .view-all-container {
            text-align: center;
            margin-top: 48px;
        }

        .view-all-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: var(--text-main);
            text-decoration: none;
            font-weight: 600;
            border: 1px solid rgba(214, 179, 90, 0.45);
            padding: 10px 24px;
            border-radius: 8px;
            background: rgba(214, 179, 90, 0.07);
            transition: all 0.2s;
            font-size: 15px;
        }

        .view-all-link:hover {
            background: rgba(214, 179, 90, 0.15);
            border-color: var(--accent-gold);
            color: var(--accent-gold);
        }

        .final-cta {
            padding: 72px 0 90px;
            background: var(--secondary-bg);
        }

        .final-cta-card {
            max-width: 1000px;
            margin: 0 auto;
            padding: 60px 48px;
            border-radius: 24px;
            border: 1px solid rgba(214, 179, 90, 0.16);
            background:
                radial-gradient(circle at top left, rgba(214, 179, 90, 0.12), transparent 35%),
                linear-gradient(135deg, rgba(255,255,255,0.045), rgba(255,255,255,0.02));
            text-align: center;
            box-shadow: 0 22px 52px rgba(0,0,0,0.2);
        }

        .final-cta-card h2 {
            font-size: 2rem;
            margin-bottom: 14px;
            color: var(--text-main);
        }

        .final-cta-card p {
            max-width: 700px;
            margin: 0 auto;
            color: var(--text-muted);
            font-size: 1.05rem;
        }

        .final-cta-actions {
            margin-top: 28px;
            display: flex;
            justify-content: center;
            gap: 14px;
            flex-wrap: wrap;
        }

        .secondary-cta {
            display: inline-block;
            padding: 14px 28px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            border: 1px solid rgba(255, 255, 255, 0.35);
            color: var(--text-main);
            transition: all 0.2s;
            background: rgba(255,255,255,0.08);
        }

        .secondary-cta:hover {
            border-color: var(--accent-gold);
            color: var(--accent-gold);
            background: rgba(214, 179, 90, 0.1);
        }

        /* ==================== FOOTER ==================== */
        .main-footer {
            background: #0c0d0f;
            padding: 20px 0 14px;
            border-top: 1px solid rgba(214, 179, 90, .25);
            margin-top: auto;
        }

        .footer-content {
            display: grid;
            grid-template-columns: minmax(0, 1.35fr) minmax(0, 1fr);
            column-gap: 20px;
            row-gap: 4px;
            text-align: left;
            max-width: 760px;
            margin-inline: auto;
        }

        .footer-section a {
            display: flex;
            align-items: center;
            min-height: 44px;
            min-width: 0;
            max-width: 100%;
            color: inherit;
            text-decoration: none;
            transition: color .2s;
        }

        .footer-section a:hover {
            color: var(--accent-gold);
        }

        .footer-section a:focus-visible {
            outline: 2px solid var(--accent-gold);
            outline-offset: 3px;
            border-radius: 4px;
        }

        .footer-section a span {
            min-width: 0;
            overflow-wrap: anywhere;
        }

        .footer-section {
            min-width: 0;
            display: flex;
            align-items: center;
            min-height: 44px;
            container-type: inline-size;
        }

        .footer-section:nth-child(1) p,
        .footer-section:nth-child(2) p {
            white-space: nowrap;
            overflow-wrap: normal;
        }

        .footer-section:nth-child(1) p {
            font-size: min(13px, 5.5cqw);
        }

        .footer-section:nth-child(2) p {
            font-size: min(10px, 8.5cqw);
        }

        .footer-section:nth-child(1) a span,
        .footer-section:nth-child(2) p span {
            overflow-wrap: normal;
        }

        .footer-section:nth-child(even) {
            padding-left: 16px;
            border-left: 1px solid rgba(255, 255, 255, .08);
        }

        .footer-section p {
            color: #c7c2b8;
            margin: 0;
            display: flex;
            align-items: baseline;
            justify-content: flex-start;
            gap: 8px;
            font-size: 13px;
            line-height: 1.6;
            overflow-wrap: anywhere;
            width: 100%;
        }

        .footer-section p + p {
            margin-top: 4px;
        }

        .copyright {
            grid-column: 1 / -1;
            margin: 0;
            margin-top: 12px;
            padding-top: 12px;
            border-top: 1px solid var(--border-color);
            color: #99968f;
            font-size: 11px;
            line-height: 1.6;
            width: 100%;
            text-align: center;
        }
        
        /* No Items */
        .no-items {
            grid-column: 1 / -1;
            text-align: center;
            padding: 40px;
            color: var(--text-muted);
        }
        
        .no-items i {
            font-size: 48px;
            margin-bottom: 16px;
            color: var(--border-color);
        }

        /* ==================== MODAL STYLES ==================== */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.8);
            z-index: 2000;
            overflow-y: auto;
            backdrop-filter: blur(4px);
            animation: modalFadeIn 0.3s ease-out forwards;
        }

        .modal-content {
            background: var(--secondary-bg);
            margin: 60px auto;
            border-radius: 12px;
            width: 90%;
            max-width: 1100px;
            border: 1px solid var(--border-color);
            box-shadow: 0 20px 60px rgba(0,0,0,0.5);
            animation: modalSlideUp 0.3s ease-out forwards;
        }

        @keyframes modalFadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes modalSlideUp {
            from { opacity: 0; transform: translateY(20px) scale(0.98); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }

        .modal-header {
            padding: 24px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h2 {
            font-size: 20px;
            color: var(--text-main);
            margin: 0;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .modal-header h2 i {
            color: var(--accent-gold);
        }

        .close-modal {
            background: none;
            border: none;
            color: var(--text-muted);
            font-size: 24px;
            cursor: pointer;
        }
        
        .close-modal:hover {
            color: var(--text-main);
        }

        .modal-body {
            padding: 24px;
        }
        
        /* Stores Modal Grid */
        .stores-modal-body {
            padding: 24px;
        }
        
        .stores-grid-modal {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
            gap: 16px;
        }
        
        .caterer-card-modal {
            background: var(--card-bg);
            border-radius: 8px;
            overflow: hidden;
            border: 1px solid var(--border-color);
            position: relative;
        }
        
        .caterer-image-modal {
            height: 130px;
            overflow: hidden;
            position: relative;
        }
        
        .caterer-image-modal img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .caterer-info-modal {
            padding: 14px;
        }
        
        .caterer-info-modal h3 {
            font-size: 15px;
            color: var(--text-main);
            margin-bottom: 8px;
        }
        
        .caterer-info-modal p {
            color: var(--text-muted);
            font-size: 12px;
            margin-bottom: 8px;
        }

        .caterer-status-modal {
            display: none;
        }
        
        .menu-count-modal {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 4px 10px;
            background: rgba(212, 175, 55, 0.1);
            border-radius: 4px;
            color: var(--accent-gold);
            font-size: 12px;
            margin: 8px 0;
        }
        
        .btn-view-modal {
            display: block;
            width: 100%;
            padding: 10px;
            background: transparent;
            color: var(--text-main);
            border: 1px solid var(--border-color);
            border-radius: 6px;
            text-align: center;
            text-decoration: none;
            font-size: 14px;
            margin-top: 16px;
            transition: all 0.2s;
        }
        
        .btn-view-modal:hover {
            border-color: var(--accent-gold);
            color: var(--accent-gold);
        }
        
        .no-stores-modal {
            text-align: center;
            padding: 40px;
            color: var(--text-muted);
        }

        /* Search Loading Animation */
        .search-loading {
            display: inline-block;
            width: 16px;
            height: 16px;
            border: 2px solid rgba(255,255,255,0.1);
            border-top: 2px solid var(--accent-gold);
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin-right: 8px;
            vertical-align: middle;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* RESPONSIVE */
        @media (max-width: 900px) {
            .hero-title {
                font-size: 3rem;
            }
            .header-search-container {
                max-width: 400px;
            }
        }

        @media (max-width: 768px) {
            .hero-title {
                font-size: 2.5rem;
            }

            .hero-highlights {
                grid-template-columns: 1fr;
                max-width: 420px;
            }

            .browse-steps {
                grid-template-columns: 1fr;
                max-width: 420px;
                margin: 0 auto;
            }

            .package-row {
                grid-template-columns: 1fr;
                gap: 12px;
            }

            .package-row-actions {
                justify-content: flex-start;
            }

            .featured-caterers-grid .single-featured-caterer {
                grid-template-columns: 1fr;
                width: min(100%, 420px);
            }

            .featured-caterers-grid .single-featured-caterer .item-image {
                height: 220px;
                min-height: 0;
            }

            .item-footer {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }

            .final-cta-card {
                padding: 32px 22px;
            }
            
            .header-content {
                flex-direction: column;
                gap: 16px;
                height: auto;
            }
            
            .header-search-container {
                width: 100%;
                max-width: none;
                order: 2;
                margin: 0;
            }
            
            .nav-section {
                width: 100%;
                justify-content: space-between;
                order: 3;
            }

            .footer-content {
                grid-template-columns: minmax(0, 1.35fr) minmax(0, 1fr);
                gap: 4px 12px;
            }

            .main-footer {
                padding: 16px 0 12px;
            }

            .footer-section:nth-child(even) {
                padding-left: 12px;
            }

            .footer-section p {
                font-size: 12px;
            }

            .footer-content .copyright {
                padding-top: 10px;
            }

            .service-package-card {
                grid-template-columns: 64px minmax(0, 1fr);
                padding: 14px;
            }

            .service-cover {
                width: 64px;
                height: 64px;
            }

            .service-inclusions {
                grid-column: 1 / -1;
            }
            
            .logo-section {
                width: 100%;
                justify-content: center;
                order: 1;
            }
            
            .auth-buttons {
                flex-wrap: wrap;
            }
            
            .stores-grid-modal {
                grid-template-columns: 1fr;
            }
        }
        
        @media (max-width: 480px) {
            .hero-title {
                font-size: 2rem;
            }

            .hero-content {
                gap: 16px;
            }

            .hero-subtitle {
                font-size: 1rem;
            }
            
            .container {
                padding: 0 16px;
            }

            .section-title {
                font-size: 1.65rem;
            }
            
            .nav-section {
                flex-direction: column;
                align-items: stretch;
            }
            
            .stores-btn {
                justify-content: center;
            }
            
            .user-info {
                flex-direction: column;
                align-items: stretch;
            }
            
            .auth-buttons {
                justify-content: center;
            }
        }
        
        /* Smooth scrolling */
        html {
            scroll-behavior: smooth;
        }
        
        /* Scrollbar */
        ::-webkit-scrollbar {
            width: 8px;
        }

        ::-webkit-scrollbar-track {
            background: var(--primary-bg);
        }

        ::-webkit-scrollbar-thumb {
            background: #333;
            border-radius: 4px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: #555;
        }
        .mobile-menu-toggle { display: none; }

        /* Keep the landing page compact on desktop without changing mobile spacing. */
        @media (min-width: 769px) {
            .featured-caterers-section { padding: 20px 0 18px; }
            .featured-caterers-section .section-header { margin-bottom: 14px; }
            .featured-caterers-section .section-title { margin-bottom: 0; }
            .featured-caterers-section .section-kicker { margin-bottom: 0; }
            .featured-caterers-section .view-all-container { margin-top: 14px; }
            .final-cta { padding: 18px 0 22px; }
            .final-cta-card { padding: 22px 40px; }
            .final-cta-actions { margin-top: 12px; }
            .main-footer { padding: 6px 0 5px; }
            .footer-content { row-gap: 0; }
            .footer-section, .footer-section a { min-height: 25px; }
            .footer-section p { line-height: 1.3; }
            .footer-content .copyright { margin-top: 3px; padding-top: 3px; line-height: 1.3; }
        }

        @media (min-width: 769px) and (max-width: 1100px) {
            .header-content { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 12px; }
            .header-search-container { grid-column: 1 / -1; grid-row: 2; width: 100%; max-width: none; margin: 0; }
            .search-container input { min-width: 0; }
        }

        /* Compact mobile header, with navigation available on demand. */
        @media (max-width: 768px) {
            html { overflow-y: auto !important; scroll-padding-top: 150px; }
            body { overflow-x: clip; overflow-y: visible !important; }
            .main-header { padding: 12px 0; }
            .header-content { display: grid; grid-template-columns: minmax(0, 1fr) 44px; gap: 12px; }
            .logo-section { width: auto; min-width: 0; justify-content: flex-start; order: 0; }
            .logo { gap: 10px; min-width: 0; }
            .logo img { width: 38px; height: 38px; object-fit: contain; }
            .logo h1 { font-size: 14px; line-height: 1.25; max-width: 180px; }
            .mobile-menu-toggle {
                display: inline-flex; align-items: center; justify-content: center;
                width: 44px; height: 44px; padding: 0; border-radius: 10px;
                border: 1px solid var(--border-color); background: var(--card-bg);
                color: var(--text-main); font-size: 20px; cursor: pointer;
            }
            .header-search-container { grid-column: 1 / -1; order: 1; min-width: 0; }
            .search-container { min-height: 48px; border-radius: 12px; }
            .search-container input { min-width: 0; width: 100%; font-size: 16px; padding: 12px; }
            .search-btn { min-width: 48px; padding: 0 14px; flex-shrink: 0; }
            .search-btn .search-label { display: none; }
            .nav-section { display: none; grid-column: 1 / -1; order: 2; gap: 12px; }
            .nav-section.is-open { display: flex; flex-direction: column; align-items: stretch; padding-top: 12px; border-top: 1px solid var(--border-color); }
            .nav-section .stores-btn { width: 100%; justify-content: center; min-height: 44px; }
            .auth-buttons { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
            .auth-buttons .btn, .user-info .btn { min-height: 44px; justify-content: center; }
            .user-info { flex-wrap: wrap; gap: 10px; }
            .hero-section { padding: 40px 20px 44px; text-align: left; }
            .hero-content { align-items: flex-start; gap: 18px; max-width: 560px; }
            .hero-title { font-size: clamp(30px, 7.8vw, 42px); line-height: 1.12; letter-spacing: -0.8px; }
            .hero-subtitle { font-size: 16px; line-height: 1.65; margin: 0; }
            .hero-actions { width: 100%; justify-content: flex-start; margin-top: 6px; }
            .hero-actions .hero-btn { width: 100%; min-height: 50px; text-align: center; padding: 14px 20px; }
            .content-section { padding: 36px 0; }
            .section-header { margin-bottom: 22px; }
            .section-title { font-size: 26px; line-height: 1.2; }
            .items-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; }
            .caterer-overlay-card { aspect-ratio: 4 / 3; }
            .caterer-overlay-info { padding: 24px 16px 16px; }
            .caterer-overlay-name { font-size: 19px; overflow-wrap: anywhere; }
            .caterer-overlay-btn { min-height: 44px; white-space: normal; }
            .view-all-link { display: inline-flex; align-items: center; min-height: 44px; }
            .search-results-head { align-items: flex-start; flex-wrap: wrap; gap: 12px; }
            .search-results-head .section-title { overflow-wrap: anywhere; }
            .final-cta { padding: 24px 0 36px; }
            .final-cta-card h2 { font-size: 26px; line-height: 1.25; }
            .final-cta-actions, .final-cta-actions a { width: 100%; }
            .final-cta-actions a { text-align: center; }
            .footer-content, .footer-section { min-width: 0; max-width: 100%; }
            .footer-section p, .footer-section a { overflow-wrap: anywhere; }
            .modal-content { width: calc(100% - 24px); max-height: 88dvh; }
            .modal-header { padding: 16px; gap: 12px; }
            .modal-header h2 { font-size: 20px; }
            .close-modal { min-width: 44px; min-height: 44px; }
        }
        @media (max-width: 480px) {
            .items-grid { grid-template-columns: minmax(0, 1fr); }
        }
        /* Keep the featured cards and contact area compact on phones. */
        @media (max-width: 768px) {
            .final-cta { padding: 12px 0 16px; }
            .final-cta-card { padding: 18px 16px; border-radius: 14px; }
            .final-cta-card h2 { font-size: 19px; line-height: 1.25; margin-bottom: 8px; }
            .final-cta-card p { font-size: 12px; line-height: 1.5; max-width: 420px; }
            .final-cta-actions { margin-top: 12px; gap: 8px; }
            .final-cta-actions a { padding: 9px 12px; font-size: 12px; line-height: 1.35; min-height: 36px; border-radius: 6px; }
            .featured-caterers-section { padding: 14px 0; }
            .featured-caterers-section { padding-bottom: 6px; }
            .featured-caterers-section .view-all-container { margin-top: 8px; }
            .featured-caterers-section .view-all-link { min-height: 28px; padding: 5px 12px; font-size: 11px; line-height: 1.4; border-radius: 5px; }
            .featured-caterers-section ~ .final-cta { padding-top: 6px; }
            .featured-caterers-section .section-header { margin-bottom: 10px; }
            .featured-caterers-section .section-title { font-size: 18px; }
            .featured-caterers-section > .container { padding-inline: 10px; }
            .featured-caterers-grid { grid-template-columns: minmax(0, 1fr); gap: 8px; width: 100%; max-width: none; margin-inline: auto; }
            .featured-caterers-grid .caterer-overlay-card { aspect-ratio: 16 / 9; border-radius: 8px; min-width: 0; }
            .featured-caterers-grid .caterer-overlay-info { padding: 16px 7px 7px; }
            .featured-caterers-grid .caterer-overlay-name { font-size: 12px; margin-bottom: 4px; line-height: 1.2; }
            .featured-caterers-grid .caterer-overlay-footer { gap: 3px 5px; }
            .featured-caterers-grid .status-text { font-size: 10px; }
            .featured-caterers-grid .status-dot { width: 5px; height: 5px; }
            .featured-caterers-grid .caterer-overlay-btn { font-size: 9px; min-height: 24px; padding: 4px 5px; gap: 3px; }
            .main-footer { padding: 4px 0; }
            .footer-content { gap: 0 10px; }
            .footer-section, .footer-section a { min-height: 22px; }
            .footer-section p { font-size: 10px; line-height: 1.4; }
            .footer-section:nth-child(even) { padding-left: 8px; }
            .footer-content .copyright { font-size: 9px; line-height: 1.3; margin-top: 2px; padding-top: 3px; }
        }
        @media (prefers-reduced-motion: reduce) {
            .hero-title, .hero-subtitle, .hero-actions { animation: none; opacity: 1; }
        }
        .mobile-sidebar-heading, .mobile-sidebar-close, .mobile-nav-backdrop { display: none; }
        @media (max-width: 768px) {
            .main-header { backdrop-filter: none; }
            body.mobile-navigation-open { overflow: hidden !important; }
            #homepageNavigation.is-open {
                position: fixed; inset: 12px 12px auto auto; width: min(240px, calc(100vw - 24px));
                height: auto; max-height: calc(100dvh - 24px); box-sizing: border-box; z-index: 1002;
                display: flex; flex-direction: column; align-items: stretch;
                justify-content: flex-start; gap: 10px; padding: 12px;
                background: #173d32; border: 1px solid #577362; border-radius: 12px;
                overflow-y: auto; box-shadow: -12px 0 32px rgba(0,0,0,.25);
            }
            #homepageNavigation .auth-buttons { display: flex; flex-direction: column; width: 100%; }
            #homepageNavigation .user-info { flex-direction: column; align-items: stretch; }
            #homepageNavigation .stores-btn, #homepageNavigation .btn { width: 100%; min-height: 44px; justify-content: flex-start; box-sizing: border-box; }
            #homepageNavigation .mobile-sidebar-close {
                display: flex; align-items: center; justify-content: center; align-self: flex-end;
                width: 44px; height: 44px; flex-shrink: 0; border: 1px solid #577362;
                border-radius: 8px; background: transparent; color: #f0f1e6; cursor: pointer;
            }
            .mobile-nav-backdrop.is-open { display: block; position: fixed; inset: 0; z-index: 1001; background: rgba(0,0,0,.5); }
        }
    </style>
    <link rel="stylesheet" href="assets/css/homepage-modals.css?v=20261015">
    <?php require_once __DIR__ . '/includes/auth_popups.php'; ?>
    <link rel="stylesheet" href="assets/css/soft-sage-theme.css?v=20261022-2">
    <style>
        @media (max-width: 768px) {
            #homepageNavigation.is-open {
                width: min(280px, calc(100vw - 24px)); padding: 18px; gap: 12px;
                border-color: #426052; border-radius: 20px;
                background: #173d32;
                box-shadow: 0 24px 64px rgba(0,0,0,.35), inset 0 1px 0 rgba(255,255,255,.06);
            }
            #homepageNavigation .mobile-sidebar-close { position: static; width: 36px; height: 36px; border-color: #426052; border-radius: 11px; background: #20483b; }
            #homepageNavigation .auth-buttons { gap: 10px; }
            #homepageNavigation .stores-btn, #homepageNavigation .btn {
                display: flex; align-items: center; justify-content: flex-start; gap: 12px;
                min-height: 52px; padding: 10px 14px; border-radius: 12px;
                border: 1px solid #426052 !important;
                background: #20483b !important; color: #f0f1e6 !important;
                font-size: 14px; font-weight: 600; line-height: 1.3; box-shadow: none;
                transition: background .18s ease, border-color .18s ease;
            }
            #homepageNavigation .stores-btn i, #homepageNavigation .btn i {
                display: inline-flex; align-items: center; justify-content: center;
                width: 30px; height: 30px; flex: 0 0 30px; border-radius: 9px;
                background: #365849; color: #f0f1e6 !important; font-size: 13px;
            }
            #homepageNavigation .btn-register { background: #20483b !important; color: #f0f1e6 !important; border-color: #426052 !important; }
            #homepageNavigation .btn-register i { background: #365849; color: #f0f1e6 !important; }
            #homepageNavigation .stores-btn:hover, #homepageNavigation .btn:hover { background: #365849 !important; border-color: #708b6b !important; }
            #homepageNavigation .btn-register:hover { background: #365849 !important; }
        }
        #catererMenuModal { backdrop-filter: none; }
        #catererMenuModal > .modal-content { padding: 10px clamp(12px, 3vw, 30px) 16px; max-height: calc(100dvh - 40px); background: #c5cfbf !important; border-color: #8c9e86 !important; color: #233a30; }
        #catererMenuModal .menu-dialog-header { display: flex; flex: 0 0 auto; justify-content: space-between; align-items: center; padding: 4px 10px 8px; }
        #catererMenuModal .menu-dialog-header h2 { margin: 0; color: #203b30; font-size: 24px; }
        #catererMenuModalContent { display: flex; flex-direction: column; min-height: 0; }
        #catererMenuModal .menu-dialog-body { overflow-y: auto; overscroll-behavior-y: contain; scroll-behavior: auto; min-height: 0; padding: 12px 8px 0 0; }
        #catererMenuModal .menu-package-group + .menu-package-group { margin-top: 24px; }
        #catererMenuModal .menu-package-group > h3 { margin: 0 0 12px; padding-left: 10px; border-left: 4px solid #907044; color: #203b30; font-size: 18px; }
        #catererMenuModal .menu-package-card { overflow: hidden; margin-bottom: 14px; border: 1px solid #a0af9c; border-radius: 12px; background: #bdc9b8; color: #233a30; }
        #catererMenuModal .menu-package-header { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 8px 16px; padding: 14px 16px; background: #a6b79f; }
        #catererMenuModal .menu-package-header h4 { flex: 1 1 190px; margin: 0; font-size: 18px; color: #203b30; overflow-wrap: anywhere; }
        #catererMenuModal .menu-package-price { display: grid; gap: 3px; font-size: 18px; font-variant-numeric: tabular-nums; }
        #catererMenuModal .menu-package-price span { font-size: 13px; }
        #catererMenuModal .menu-package-content { display: grid; gap: 12px; padding: 14px 16px; font-size: 14px; }
        #catererMenuModal .menu-package-content p { margin: 0; line-height: 1.5; }
        #catererMenuModal .menu-package-content h5 { margin: 0 0 5px; color: #203b30; font-size: 14px; }
        #catererMenuModal .menu-package-inclusions { padding: 10px 12px; background: #d0c1a4; border-radius: 8px; }
        #catererMenuModal .menu-food-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 175px), 1fr)); gap: 8px; }
        #catererMenuModal .menu-food-category { padding: 9px 11px; border: 1px solid #8c9e86; border-radius: 8px; background: #b1bfaa; overflow-wrap: anywhere; }
        #catererMenuModal .menu-food-category ul { margin: 0; padding-left: 17px; list-style: disc; line-height: 1.45; }
        #catererMenuModal .menu-package-footer { display: flex; justify-content: flex-end; padding: 10px 16px; border-top: 1px solid #8c9e86; }
        #catererMenuModal .menu-book-link { padding: 9px 16px; border-radius: 7px; background: #173c32; color: #d6dfc9; font-size: 14px; font-weight: 700; text-decoration: none; }
        #catererMenuModal .menu-book-link:hover { background: #285849; }

    </style>
    <link rel="stylesheet" href="assets/css/compact-footer.css?v=20261021-1">
</head>
<body>
    <!-- Fixed Header - EXACT AS PICTURE -->
    <header class="main-header">
        <div class="container">
            <div class="header-content">
                <!-- LOGO SECTION - LEFT CORNER -->
                <div class="logo-section">
                    <a href="./" class="logo">
                        <span class="logo-mark">
                            <img src="<?php echo htmlspecialchars(homepageAssetUrl($site_logo), ENT_QUOTES, 'UTF-8'); ?>" alt="Logo" class="logo-img">
                        </span>
                        <h1>Catering Services Portal</h1>
                    </a>
                </div>
                
                <button type="button" class="mobile-menu-toggle" id="homepageMenuToggle" aria-label="Open navigation" aria-expanded="false" aria-controls="homepageNavigation">
                    <i class="fas fa-bars" aria-hidden="true"></i>
                </button>

                <!-- SEARCH BAR SECTION - CENTER (WIDE) - FIXED -->
                <div class="header-search-container">
                    <form method="GET" action="" class="search-form" id="searchForm">
                        <div class="search-container">
                            <input type="text" name="search" id="searchInput" 
                                   placeholder="Search event type" aria-label="Search event type"
                                   value="<?php echo isset($_GET['search']) ? htmlspecialchars($_GET['search']) : ''; ?>"
                                   list="eventNameSuggestions"
                                   autocomplete="off">
                            <datalist id="eventNameSuggestions">
                                <?php foreach ($event_name_suggestions as $event_name): ?>
                                    <option value="<?php echo htmlspecialchars($event_name); ?>"></option>
                                <?php endforeach; ?>
                            </datalist>
                            <button type="submit" class="search-btn" aria-label="Search caterers">
                                <i class="fas fa-search" aria-hidden="true"></i> <span class="search-label">Search</span>
                            </button>
                        </div>
                    </form>
                </div>
                
                <!-- BUTTONS SECTION - RIGHT CORNER -->
                <div class="mobile-nav-backdrop" id="homepageNavBackdrop" aria-hidden="true"></div>
                <div class="nav-section" id="homepageNavigation" role="navigation" aria-label="Main navigation">
                    <button type="button" class="mobile-sidebar-close" aria-label="Close navigation"><i class="fas fa-times" aria-hidden="true"></i></button>
                    <!-- Caterers Button -->
                    <button onclick="openStoresModal()" class="stores-btn">
                        <i class="fas fa-utensils"></i> Caterers
                    </button>
                    
                    <?php if (isLoggedIn()): ?>
                        <?php
                        $user_id = intval($_SESSION['user_id']);
                        $user_query = "SELECT first_name, role, profile_image FROM users WHERE id = $user_id";
                        $user_result = mysqli_query($conn, $user_query);
                        $user = ($user_result && mysqli_num_rows($user_result) > 0) ? mysqli_fetch_assoc($user_result) : null;
                        $dashboard_paths = [
                            'portal' => 'portal123/dashboard.php',
                            'store' => 'caterer/dashboard.php',
                            'customer' => 'customer/dashboard.php',
                        ];
                        $dashboard_path = $dashboard_paths[$user['role'] ?? ''] ?? 'login.php';
                        ?>
                        <div class="user-info">
                            <?php if ($user): ?>
                                <span class="welcome">Welcome, <?php echo htmlspecialchars($user['first_name']); ?>!</span>
                                <a href="<?php echo htmlspecialchars($dashboard_path, ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-dashboard">
                                    <i class="fas fa-tachometer-alt"></i> Dashboard
                                </a>
                            <?php endif; ?>
                            <a href="logout.php" class="btn btn-logout">
                                <i class="fas fa-sign-out-alt"></i> Logout
                            </a>
                        </div>
                    <?php else: ?>
                        <div class="auth-buttons">
                            <a href="login.php" class="btn btn-login">
                                <i class="fas fa-sign-in-alt"></i> Login
                            </a>
                            <a href="register.php" class="btn btn-register">
                                <i class="fas fa-user-plus"></i> Register
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </header>

    <!-- Caterers Modal -->
    <div id="storesModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="storesModalTitle">
        <div class="modal-content">
            <div class="modal-header">
                <div class="caterer-dialog-heading"><h2 id="storesModalTitle">Find your caterer</h2></div>
                <button class="close-modal" aria-label="Close caterers" onclick="closeStoresModal()">&times;</button>
            </div>
            <div class="stores-modal-body">
                <?php if (count($stores_array) > 0): ?>
                    <div class="stores-grid-modal">
                        <?php foreach ($stores_array as $caterer): ?>
                            <?php $index_store_is_available = isStoreAvailableNow($caterer['is_open'] ?? 0, $caterer['business_open_time'] ?? null, $caterer['business_close_time'] ?? null, $caterer['auto_schedule_enabled'] ?? 0); ?>
                            <div class="caterer-card-modal">
                                <div class="caterer-status-modal <?php echo $index_store_is_available ? 'open' : 'closed'; ?>">
                                    <?php echo $index_store_is_available ? 'ðŸŸ¢ Open' : 'ðŸ”´ Closed'; ?>
                                </div>
                                <div class="caterer-image-modal">
                                    <?php if (!empty($caterer['profile_image']) && file_exists($caterer['profile_image'])): ?>
                                        <img src="<?php echo htmlspecialchars(homepageAssetUrl($caterer['profile_image']), ENT_QUOTES, 'UTF-8'); ?>" 
                                             alt="<?php echo $caterer['business_name'] ?? 'Caterer'; ?>"
                                             onerror="this.onerror=null; this.src='https://via.placeholder.com/400x200/3498db/ffffff?text=<?php echo urlencode($caterer['business_name'] ?? 'Caterer'); ?>'">
                                    <?php else: ?>
                                        <img src="https://via.placeholder.com/400x200/3498db/ffffff?text=<?php echo urlencode($caterer['business_name'] ?? 'Caterer'); ?>" 
                                             alt="<?php echo $caterer['business_name'] ?? 'Caterer'; ?>">
                                    <?php endif; ?>
                                </div>
                                <div class="caterer-info-modal">
                                    <h3><?php echo $caterer['business_name'] ?? $caterer['first_name'] . ' ' . $caterer['last_name']; ?></h3>
                                    
                                    <?php 
                                    $store_id = $caterer['id'];
                                    $sub_query = "SELECT subscription_status FROM store_details WHERE user_id = '$store_id'";
                                    $sub_result = mysqli_query($conn, $sub_query);
                                    $sub_data = mysqli_fetch_assoc($sub_result);
                                    $subscription_active = ($sub_data['subscription_status'] == 'active');
                                    
                                    $menu_query = "SELECT COUNT(*) as menu_count FROM products WHERE store_id = '$store_id' AND is_available = 1";
                                    $menu_result = mysqli_query($conn, $menu_query);
                                    $menu_data = mysqli_fetch_assoc($menu_result);
                                    $menu_count = $menu_data['menu_count'];
                                    ?>
                                    
                                    
                                    <?php 
                                    $modal_search_param = isset($_GET['search']) ? '&search_query=' . urlencode($_GET['search']) : '';
                                    ?>
                                    <button type="button" class="btn-view-modal" onclick="openCatererMenuModal(<?php echo $caterer['id']; ?>)">
                                        View Menu
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="no-stores-modal">
                        <h3>No caterers available at the moment</h3>
                        <p>Please check back later or register as a caterer to start offering catering services.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <main>
        <?php if ($search_query !== ''): ?>
        <section class="search-results-section">
            <div class="container">
                <div class="search-results-head">
                    <div style="display: flex; flex-direction: column; gap: 8px;">
                        <span class="section-kicker" style="display: block; margin-bottom: 4px;">Search Results</span>
                    </div>
                    <a href="index.php" class="clear-search-link">Clear Search</a>
                </div>

                <?php if (!empty($search_results)): ?>
                    <div class="items-grid">
                        <?php foreach ($search_results as $product): ?>
                            <?php
                            $store_name = !empty($product['business_name']) ? $product['business_name'] : $product['first_name'] . ' ' . $product['last_name'];
                            $display_price = (float) ($product['price'] ?? 0);
                            if (($product['category'] ?? '') === 'catering_service' && (float) ($product['min_set_price'] ?? 0) > 0) {
                                $display_price = (float) $product['min_set_price'];
                            }
                            $price = number_format($display_price, 2);
                            $search_description = trim($product['description'] ?? '') !== ''
                                ? $product['description']
                                : ($product['catering_details'] ?? '');
                            ?>
                            <div class="item-card">
                                <div class="item-image">
                                    <?php if (!empty($product['image'])): ?>
                                        <img src="<?php echo BASE_URL . $product['image']; ?>" alt="<?php echo htmlspecialchars($product['name']); ?>">
                                    <?php else: ?>
                                        <img src="https://via.placeholder.com/800x600/333/d4af37?text=<?php echo urlencode($product['name']); ?>" alt="<?php echo htmlspecialchars($product['name']); ?>">
                                    <?php endif; ?>
                                </div>
                                <div class="item-content">
                                    <div class="item-category"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $product['category'] ?? 'menu item'))); ?></div>
                                    <h3 class="item-title"><?php echo htmlspecialchars($product['name']); ?></h3>
                                    <div class="item-details">
                                        <span><?php echo $search_description !== '' ? htmlspecialchars(substr($search_description, 0, 110)) . '...' : 'Matching menu item available for viewing and booking.'; ?></span>
                                    </div>
                                    <div class="item-footer">
                                        <span class="item-price">&#8369;<?php echo $price; ?></span>
                                        <span class="item-caterer"><i class="fas fa-caterer"></i> <?php echo htmlspecialchars($store_name); ?></span>
                                    </div>
                                    <div class="view-all-container" style="text-align: left; margin-top: 18px;">
                                        <?php if (($product['category'] ?? '') === 'catering_service'): ?>
                                            <button type="button" class="item-link" onclick="openSearchMenuModal(<?php echo $product['id']; ?>)" style="background: none; border: none; padding: 0; cursor: pointer; font: inherit;">View Menu <i class="fas fa-arrow-right"></i></button>
                                        <?php else: ?>
                                            <a href="customer/browse-caterer.php?store_id=<?php echo $product['store_id']; ?>&source=search&search_query=<?php echo urlencode($search_query); ?>" class="item-link">View Menu <i class="fas fa-arrow-right"></i></a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <?php if (($product['category'] ?? '') === 'catering_service'): ?>
                                <?php
                                $prod_id = $product['id'];
                                $sets_query = "SELECT * FROM product_sets WHERE product_id = '$prod_id' ORDER BY price ASC";
                                $sets_res = mysqli_query($conn, $sets_query);
                                $sets = [];
                                while ($s = mysqli_fetch_assoc($sets_res)) {
                                    $sets[] = $s;
                                }
                                ?>
                                <div class="modal" id="searchMenuModal_<?php echo $prod_id; ?>" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); z-index: 9999; align-items: center; justify-content: center; padding: 20px; box-sizing: border-box;">
                                    <div class="modal-content" style="max-width: 900px; width: 100%; padding: 30px; background: #1a1a1a; border: 1px solid #333; border-radius: 12px; margin: auto; position: relative; display: flex; flex-direction: column;">
                                        <div class="modal-header" style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #333; padding-bottom: 16px; margin-bottom: 20px; flex-shrink: 0;">
                                            <h3 style="margin: 0; color: #fff; font-size: 20px;"><i class="fas fa-utensils" style="color: var(--accent-gold); margin-right: 8px;"></i> <?php echo htmlspecialchars($product['name']); ?> - Menu Packages</h3>
                                            <span class="close" onclick="closeSearchMenuModal(<?php echo $prod_id; ?>)" style="color: #aaa; font-size: 28px; font-weight: bold; cursor: pointer; line-height: 1;">&times;</span>
                                        </div>
                                        <div class="modal-body" style="overflow-y: auto; padding-right: 10px; flex: 1;">
                                            <?php if (count($sets) > 0): ?>
                                                <div style="display: flex; flex-direction: column; gap: 0;">
                                                    <?php foreach ($sets as $set): 
                                                        $set_per_head = floatval($set['per_head_price'] ?? 0);
                                                        $set_pax = (int) ($set['pax'] ?? 0);
                                                        $set_price = (float) ($set['price'] ?? 0);
                                                        if ($set_per_head > 0) {
                                                            $price_label = '₱' . number_format($set_per_head, 2) . ' per guest';
                                                        } else {
                                                            $price_label = '₱' . number_format($set_price, 2);
                                                            if ($set_pax > 0) {
                                                                $price_label .= ' · Good for ' . $set_pax . ' guests';
                                                            }
                                                        }
                                                        
                                                        $menu_preview = getPackagePreviewItems($set['description'], 10);
                                                        $menu_preview_str = !empty($menu_preview) ? implode(', ', $menu_preview) : '';
                                                    ?>
                                                        <div class="catering-package-row" style="display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; padding: 20px 0; border-bottom: 1px solid rgba(255,255,255,0.05);">
                                                            <div style="flex: 1; min-width: 300px; padding-right: 20px;">
                                                                <h4 style="color: #fff; font-size: 16px; margin: 0 0 6px 0; font-weight: 600;"><?php echo htmlspecialchars($set['set_name'] ?? 'Package'); ?></h4>
                                                                <p style="color: var(--accent-gold); font-size: 13px; margin: 0 0 10px 0; font-weight: 500;"><?php echo $price_label; ?></p>
                                                                <?php if ($menu_preview_str): ?>
                                                                    <div style="color: #aaa; font-size: 13px; line-height: 1.5;">
                                                                        <strong style="color: #ddd;">Menu:</strong><br>
                                                                        <?php echo htmlspecialchars($menu_preview_str); ?>
                                                                    </div>
                                                                <?php endif; ?>
                                                            </div>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php else: ?>
                                                <p style="color: #aaa; text-align: center; padding: 30px;">No menu packages available for this catering service.</p>
                                            <?php endif; ?>
                                        </div>
                                        <div class="modal-footer" style="padding-top: 20px; text-align: right; border-top: 1px solid rgba(255,255,255,0.05); margin-top: 10px; flex-shrink: 0;">
                                            <a href="customer/browse-caterer.php?store_id=<?php echo $product['store_id']; ?>&source=search&search_query=<?php echo urlencode($search_query); ?>&service_id=<?php echo $product['id']; ?>#service-<?php echo $product['id']; ?>" style="display: inline-block; padding: 12px 24px; background: var(--accent-gold); color: #000; text-decoration: none; border-radius: 8px; font-size: 14px; font-weight: 600; transition: opacity 0.2s;"><i class="fas fa-arrow-right"></i> View More</a>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="no-items">
                        <i class="fas fa-search"></i>
                        <h3>No results found</h3>
                        <p>Try a different keyword for food, catering service, or caterer-related items.</p>
                    </div>
                <?php endif; ?>
            </div>
        </section>
        <?php endif; ?>

        <!-- Hero Section - REDESIGNED -->
        <section class="hero-section">
            <div class="hero-overlay"></div>
            <div class="hero-content">
                <h1 class="hero-title">Your Gateway to <span class="highlight-text">Trusted Caterers</span></h1>
                <p class="hero-subtitle">Discover trusted caterers, preview their menus and packages, then book the perfect spread for your celebration.</p>
                <div class="hero-actions">
                    <a href="#stores-section" class="hero-btn">Browse Caterers <i class="fas fa-arrow-down"></i></a>
                </div>
            </div>
        </section>


        <!-- Section 1: Popular Caterers -->
        <section id="stores-section" class="content-section featured-caterers-section">
            <div class="container">
                <div class="section-header">
                    <span class="section-kicker">Trusted Partners</span>
                    <h2 class="section-title">Featured Caterers</h2>
                </div>
                
                <div class="items-grid featured-caterers-grid">
                    <?php if (!empty($popular_stores)): ?>
                        <?php foreach ($popular_stores as $caterer): ?>
                            <?php
                            $store_id = $caterer['id'];
                            $menu_count = intval($caterer['menu_count'] ?? 0);
                            $service_count = intval($caterer['service_count'] ?? 0);
                            $store_name = $caterer['business_name'] ?? $caterer['first_name'] . ' ' . $caterer['last_name'];
                            ?>
                            <a href="customer/browse-caterer.php?store_id=<?php echo $caterer['id']; ?>&source=featured" class="caterer-overlay-card">
                                <div class="caterer-overlay-img">
                                    <?php if (!empty($caterer['profile_image']) && file_exists($caterer['profile_image'])): ?>
                                        <img src="<?php echo htmlspecialchars(homepageAssetUrl($caterer['profile_image']), ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($store_name); ?>">
                                    <?php else: ?>
                                        <div class="caterer-no-img">
                                            <span><?php echo strtoupper(mb_substr($store_name, 0, 1)); ?></span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="caterer-overlay-info">
                                    <h3 class="caterer-overlay-name"><?php echo htmlspecialchars($store_name); ?></h3>
                                    <div class="caterer-overlay-footer">
                                        <?php if (isStoreAvailableNow($caterer['is_open'] ?? 0, $caterer['business_open_time'] ?? null, $caterer['business_close_time'] ?? null, $caterer['auto_schedule_enabled'] ?? 0)): ?>
                                            <span class="status-text status-open"><span class="status-dot"></span>Open Now</span>
                                        <?php else: ?>
                                            <span class="status-text status-closed"><span class="status-dot"></span>Closed</span>
                                        <?php endif; ?>
                                        <span class="caterer-overlay-btn">See what's inside <i class="fas fa-arrow-right"></i></span>
                                    </div>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="no-items" style="grid-column: 1/-1; background: transparent;">
                            <i class="fas fa-caterer-slash" style="color: #555;"></i>
                            <h3 style="color: #999;">No Caterers Yet</h3>
                        </div>
                    <?php endif; ?>
                </div>
                
            </div>
        </section>

        <!-- Section 2: Popular Products -->
        <?php if (!empty($popular_products)): ?>
        <section class="content-section">
            <div class="container">
                <div class="section-header">
                    <span class="section-kicker">Customer Favorites</span>
                    <h2 class="section-title">Trending Dishes</h2>
                    <p class="section-desc">See the dishes getting the most attention from customers, from everyday trays to crowd-pleasing specialties for bigger occasions.</p>
                </div>
                
                <div class="items-grid">
                    <?php foreach ($popular_products as $product): ?>
                        <?php
                        $store_name = !empty($product['business_name']) ? $product['business_name'] : $product['first_name'] . ' ' . $product['last_name'];
                        $price = number_format($product['price'], 2);
                        ?>
                        <div class="item-card">
                            <div class="item-image">
                                <?php if ($product['image']): ?>
                                    <img src="<?php echo BASE_URL . $product['image']; ?>" alt="<?php echo $product['name']; ?>">
                                <?php else: ?>
                                    <img src="https://via.placeholder.com/800x600/333/d4af37?text=<?php echo urlencode($product['name']); ?>" alt="<?php echo $product['name']; ?>">
                                <?php endif; ?>
                            </div>
                            <div class="item-content">
                                <div class="item-category">Food Product</div>
                                <h3 class="item-title"><?php echo htmlspecialchars($product['name']); ?></h3>
                                <div class="item-details">
                                    <span><?php echo !empty($product['description']) ? htmlspecialchars(substr($product['description'], 0, 100)) . '...' : 'Freshly prepared menu option available for booking and inquiries.'; ?></span>
                                </div>
                                <div class="item-footer">
                                    <span class="item-price">&#8369;<?php echo $price; ?></span>
                                    <span class="item-caterer"><i class="fas fa-caterer"></i> <?php echo htmlspecialchars($store_name); ?></span>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <div class="view-all-container">
                    <a href="customer/browse-caterer.php" class="view-all-link">View All Menu Items</a>
                </div>
            </div>
        </section>
        <?php endif; ?>



        <section class="final-cta">
            <div class="container">
                <div class="final-cta-card">
                    <h2>Plan your next event with more confidence</h2>
                    <p>Explore trusted caterers, compare dishes and packages, then move straight into booking with one clear portal built for catering decisions.</p>
                    <div class="final-cta-actions">
                        <a href="customer/browse-caterer.php" class="hero-btn">Start Browsing Caterers</a>
                        <a href="register.php" class="secondary-cta">Create an Account</a>
                    </div>
                </div>
            </div>
        </section>
    </main>

    <?php include __DIR__ . '/includes/public-footer.php'; ?>

        <div style="display: none;" id="preloaded-menus">
    <?php
    $preloaded_ids = [];
    if (isset($stores_array) && is_array($stores_array)) {
        foreach ($stores_array as $caterer) {
            $internal_store_id = $caterer['id'];
            if (!in_array($internal_store_id, $preloaded_ids)) {
                echo '<div id="caterer-menu-html-' . $internal_store_id . '">';
                include 'api_get_caterer_menu.php';
                echo '</div>';
                $preloaded_ids[] = $internal_store_id;
            }
        }
    }
    if (isset($popular_stores) && is_array($popular_stores)) {
        foreach ($popular_stores as $caterer) {
            $internal_store_id = $caterer['id'];
            if (!in_array($internal_store_id, $preloaded_ids)) {
                echo '<div id="caterer-menu-html-' . $internal_store_id . '">';
                include 'api_get_caterer_menu.php';
                echo '</div>';
                $preloaded_ids[] = $internal_store_id;
            }
        }
    }
    // Clean up to not interfere with other parts
    unset($internal_store_id);
    ?>
</div>
    <!-- Caterer Specific Menu Modal -->
    <div class="modal" id="catererMenuModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); z-index: 10000; align-items: center; justify-content: center; padding: 20px; box-sizing: border-box;">
        <div class="modal-content" style="max-width: 800px; width: 100%; background: #1a1a1a; border: 1px solid #333; border-radius: 12px; margin: auto; position: relative; display: flex; flex-direction: column;">
            <div id="catererMenuModalContent">
                <!-- Content will be loaded here via AJAX -->
                <div style="text-align: center; padding: 40px; color: #888;">
                    <i class="fas fa-spinner fa-spin" style="font-size: 30px; margin-bottom: 15px;"></i>
                    <p>Loading menu...</p>
                </div>
            </div>
        </div>
    </div>

    <script>

            

            

            

            

        
        document.addEventListener('DOMContentLoaded', function() {

            
            // Search functionality
            const searchInput = document.getElementById('searchInput');
            const searchForm = document.getElementById('searchForm');
            const storesModal = document.getElementById('storesModal');
            
            // Leave focus with the user so phones do not open the keyboard on arrival.
            const menuToggle = document.getElementById('homepageMenuToggle');
            const navigation = document.getElementById('homepageNavigation');
            function setNavigationOpen(open) {
                navigation.classList.toggle('is-open', open);
                document.getElementById('homepageNavBackdrop').classList.toggle('is-open', open);
                document.body.classList.toggle('mobile-navigation-open', open);
                menuToggle.setAttribute('aria-expanded', String(open));
                menuToggle.setAttribute('aria-label', open ? 'Close navigation' : 'Open navigation');
                menuToggle.innerHTML = open ? '<i class="fas fa-times" aria-hidden="true"></i>' : '<i class="fas fa-bars" aria-hidden="true"></i>';
            }
            menuToggle.addEventListener('click', () => {
                const open = !navigation.classList.contains('is-open');
                setNavigationOpen(open);
                if (open) navigation.querySelector('.mobile-sidebar-close').focus();
            });
            navigation.querySelector('.mobile-sidebar-close').addEventListener('click', () => { setNavigationOpen(false); menuToggle.focus(); });
            document.getElementById('homepageNavBackdrop').addEventListener('click', () => { setNavigationOpen(false); menuToggle.focus(); });
            navigation.addEventListener('keydown', event => {
                if (event.key !== 'Tab' || !navigation.classList.contains('is-open')) return;
                const items = [...navigation.querySelectorAll('a[href], button')].filter(item => item.getClientRects().length);
                const first = items[0], last = items[items.length - 1];
                if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
                else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
            });
            document.addEventListener('keydown', (event) => {
                if (event.key === 'Escape' && navigation.classList.contains('is-open')) {
                    setNavigationOpen(false);
                    menuToggle.focus();
                }
            });
            document.addEventListener('click', (event) => {
                if (event.composedPath().includes(menuToggle)) return;
                if (!event.target.closest('.main-header') || event.target.closest('#homepageNavigation a, #homepageNavigation button')) {
                    setNavigationOpen(false);
                }
            });
            window.matchMedia('(max-width: 768px)').addEventListener('change', () => setNavigationOpen(false));
            
            // Form submission
            searchForm.addEventListener('submit', function(e) {
                const searchTerm = searchInput.value.trim();
                if (!searchTerm) {
                    e.preventDefault(); // Prevent empty search
                }
            });
            
            /* click outside disabled for storesModal */
            
                        // Caterer Menu Modal FAST PRELOADED
            window.openCatererMenuModal = function(storeId) {
                const modal = document.getElementById('catererMenuModal');
                const contentDiv = document.getElementById('catererMenuModalContent');
                
                if (modal && contentDiv) {
                    modal.style.display = 'flex';
                    const preloaded = document.getElementById('caterer-menu-html-' + storeId);
                    if (preloaded) {
                        contentDiv.innerHTML = preloaded.innerHTML;
                    } else {
                        // Fallback if not preloaded
                        contentDiv.innerHTML = '<div style="text-align: center; padding: 40px; color: #888;"><i class="fas fa-spinner fa-spin" style="font-size: 30px; margin-bottom: 15px;"></i><p>Loading menu...</p></div>';
                        fetch('api_get_caterer_menu.php?store_id=' + storeId)
                            .then(response => response.text())
                            .then(html => {
                                contentDiv.innerHTML = html;
                            })
                            .catch(err => {
                                contentDiv.innerHTML = '<div style="text-align: center; padding: 20px; color: #e74c3c;"><p>Error loading menu.</p></div>';
                            });
                    }
                }
            };
            
            window.closeCatererMenuModal = function() {
                const modal = document.getElementById('catererMenuModal');
                if (modal) {
                    modal.style.display = 'none';
                }
            };
            
            // Allow closing by clicking outside the modal content
            /* click outside disabled for catererMenuModal */

            // Search Menu Modal Functions
            window.openSearchMenuModal = function(productId) {
                const modal = document.getElementById('searchMenuModal_' + productId);
                if (modal) {
                    modal.style.display = 'flex';
                    document.body.style.overflow = 'hidden';
                }
            };
            
            window.closeSearchMenuModal = function(productId) {
                const modal = document.getElementById('searchMenuModal_' + productId);
                if (modal) {
                    modal.style.display = 'none';
                    document.body.style.overflow = '';
                }
            };

            // Global window click for search menu modals
            /* click outside disabled for searchMenuModal */

            // Caterer Modal Functions
            window.openStoresModal = function() {
                document.getElementById('storesModal').style.display = 'flex';
                // Removing body overflow hidden so the scrollbar stays visible and prevents shifting
            };
            
            window.closeStoresModal = function() {
                document.getElementById('storesModal').style.display = 'none';
            };

            // Open modal if URL has show_caterers=1
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.get('show_caterers') === '1') {
                window.openStoresModal();
            }
            
            // Open search menu modal if open_modal is present
            if (urlParams.has('open_modal')) {
                const modalId = urlParams.get('open_modal');
                if (typeof window.openSearchMenuModal === 'function') {
                    window.openSearchMenuModal(modalId);
                }
            }
        });
    </script>
</body>
</html>




<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config.php';
/** @var mysqli $conn */

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['store', 'caterer'])) {
    header('Location: ../login.php');
    exit();
}

$store_id = $_SESSION['user_id'];

// --- Handle Filters ---
$where_clauses = ["r.store_id = '$store_id'"];
if (in_array($_GET['rating'] ?? '', ['1', '2', '3', '4', '5'], true)) {
    $rating = (int) $_GET['rating'];
    $where_clauses[] = "r.overall_rating = $rating";
}
if (preg_match('/^[0-9]{4}-(0[1-9]|1[0-2])$/', $_GET['date_from'] ?? '')) {
    $date_from = $_GET['date_from'] . '-01';
    $where_clauses[] = "r.created_at >= '$date_from'";
}
if (preg_match('/^[0-9]{4}-(0[1-9]|1[0-2])$/', $_GET['date_to'] ?? '')) {
    $date_to = (new DateTimeImmutable($_GET['date_to'] . '-01'))->modify('+1 month')->format('Y-m-d');
    $where_clauses[] = "r.created_at < '$date_to'";
}

$where_sql = implode(' AND ', $where_clauses);

// --- Fetch Averages ---
$avg_query = "SELECT 
    AVG(overall_rating) as avg_overall,
    AVG(service_rating) as avg_service,
    AVG(food_rating) as avg_food,
    AVG(delivery_rating) as avg_delivery,
    COUNT(*) as total_reviews
    FROM reviews r 
    JOIN bookings o ON r.order_id = o.id
    WHERE r.store_id = '$store_id'";
$avg_result = mysqli_query($conn, $avg_query);
$avgs = mysqli_fetch_assoc($avg_result);

// --- Fetch Reviews ---
$reviews_query = "SELECT r.*, u.first_name, u.last_name, u.profile_image, o.order_number 
                  FROM reviews r 
                  JOIN users u ON r.customer_id = u.id 
                  JOIN bookings o ON r.order_id = o.id
                  WHERE $where_sql 
                  ORDER BY r.created_at DESC";
$reviews_result = mysqli_query($conn, $reviews_query);

require_once __DIR__ . '/includes/header.php';
?>

<link rel="stylesheet" href="../assets/css/caterer-reviews.css?v=<?php echo filemtime(__DIR__ . '/../assets/css/caterer-reviews.css'); ?>">
<div class="reviews-page">
<div class="review-controls">
<div class="review-stats" aria-label="Average ratings out of five">
<?php foreach (['avg_overall' => 'Overall', 'avg_service' => 'Service', 'avg_food' => 'Food Quality', 'avg_delivery' => 'Delivery'] as $key => $label): ?>
<div class="review-stat">
<span class="review-stat-label"><?php echo $label; ?></span>
<span class="review-stat-value"><?php echo number_format($avgs[$key] ?? 0, 1); ?><small>/5</small></span>
</div>
<?php endforeach; ?>
</div>
<!-- Filters -->
<div class="card review-filters">
    <form method="GET" class="review-filter-form">
        <div>
            <label class="form-label" for="review-rating">Rating</label>
            <select id="review-rating" name="rating" class="form-control">
                <option value="">All Ratings</option>
                <option value="5" <?php echo (isset($_GET['rating']) && $_GET['rating'] == 5) ? 'selected' : ''; ?>>5 Stars</option>
                <option value="4" <?php echo (isset($_GET['rating']) && $_GET['rating'] == 4) ? 'selected' : ''; ?>>4 Stars</option>
                <option value="3" <?php echo (isset($_GET['rating']) && $_GET['rating'] == 3) ? 'selected' : ''; ?>>3 Stars</option>
                <option value="2" <?php echo (isset($_GET['rating']) && $_GET['rating'] == 2) ? 'selected' : ''; ?>>2 Stars</option>
                <option value="1" <?php echo (isset($_GET['rating']) && $_GET['rating'] == 1) ? 'selected' : ''; ?>>1 Star</option>
            </select>
        </div>
        <div>
            <label class="form-label" for="review-date_from">From Month</label>
            <input type="month" id="review-date_from" name="date_from" class="form-control" value="<?php echo htmlspecialchars($_GET['date_from'] ?? ''); ?>">
        </div>
        <div>
            <label class="form-label" for="review-date_to">To Month</label>
            <input type="month" id="review-date_to" name="date_to" class="form-control" value="<?php echo htmlspecialchars($_GET['date_to'] ?? ''); ?>">
        </div>
        <div class="review-filter-actions"><button type="submit" class="btn btn-primary">Filter</button>
            <a href="reviews.php" class="btn btn-secondary" style="margin-left: 5px;">Reset</a>
        </div>
    </form>
</div>

</div> <!-- End review controls -->

<!-- Reviews List -->
<div class="review-list-heading">
    <h1>Reviews</h1>
    <span class="reviews-count"><?php echo (int) mysqli_num_rows($reviews_result); ?> reviews</span>
</div>
<div class="reviews-list">
    <?php if (mysqli_num_rows($reviews_result) > 0): ?>
        <?php while ($review = mysqli_fetch_assoc($reviews_result)): ?>
            <div class="card review-entry">
                <div class="review-entry-header">
                    <div class="review-author">
                        <div class="review-avatar">
                            <span><?php echo htmlspecialchars(strtoupper(substr($review['first_name'], 0, 1) . substr($review['last_name'], 0, 1))); ?></span>
                            <?php
                            $profile_path = str_replace('\\', '/', trim((string) ($review['profile_image'] ?? '')));
                            if (strpos($profile_path, 'uploads/profiles/') === 0 && strpos($profile_path, '..') === false && is_file(__DIR__ . '/../' . $profile_path)):
                            ?>
                                <img src="<?php echo htmlspecialchars('../' . $profile_path, ENT_QUOTES); ?>" alt="<?php echo htmlspecialchars($review['first_name'] . ' ' . $review['last_name'], ENT_QUOTES); ?>" loading="lazy" onerror="this.remove()">
                            <?php endif; ?>
                        </div>
                        <div class="review-author-details">
                            <h4 style="margin: 0; color: var(--text-main);"><?php echo htmlspecialchars($review['first_name'] . ' ' . $review['last_name']); ?></h4>
                            <div class="review-meta">
                                <span><?php echo date('M d, Y', strtotime($review['created_at'])); ?></span>
                            </div>
                        </div>
                    </div>
                    <div class="review-score">
                        <?php echo number_format($review['overall_rating'], 1); ?><small>/ 5</small>
                    </div>
                </div>
                
                <blockquote class="review-comment"><?php echo nl2br(htmlspecialchars($review['comment'])); ?></blockquote>
                

            </div>
        <?php endwhile; ?>
    <?php else: ?>
        <div class="card" style="text-align: center; padding: 50px;">
            
            <p style="color: var(--text-muted);">No reviews found matching your criteria.</p>
        </div>
    <?php endif; ?>
</div>

</div>
<script src="../assets/js/review-month-picker.js?v=<?php echo filemtime(__DIR__ . '/../assets/js/review-month-picker.js'); ?>"></script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>


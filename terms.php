<?php
require_once 'config.php';
$page_title = 'Terms and Conditions';
?>
<!DOCTYPE html>
<html lang="en" class="soft-sage-theme terms-sage-theme">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - Catering Portal</title>
    <link rel="icon" type="image/png" href="uploads/logo.png">
    <link rel="shortcut icon" href="uploads/logo.png">
    <link rel="apple-touch-icon" href="uploads/logo.png">
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --primary-bg: #1a1a1a;
            --secondary-bg: #2d2d2d;
            --accent-gold: #d4af37;
            --text-main: #ffffff;
            --text-muted: #b0b0b0;
        }
        
        body {
            background-color: var(--primary-bg);
            color: var(--text-main);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            padding: 0;
            line-height: 1.6;
        }

        .container {
            max-width: 1000px;
            margin: 40px auto;
            padding: 40px;
            background: var(--secondary-bg);
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.3);
            border: 1px solid rgba(255,255,255,0.05);
        }

        h1 {
            color: var(--accent-gold);
            text-align: center;
            margin-bottom: 40px;
            font-size: 2.5rem;
            border-bottom: 2px solid var(--accent-gold);
            padding-bottom: 20px;
        }

        h2 {
            color: var(--accent-gold);
            margin-top: 30px;
            font-size: 1.5rem;
        }

        p, ul {
            margin-bottom: 20px;
            color: #ddd;
        }

        ul {
            padding-left: 20px;
        }

        li {
            margin-bottom: 10px;
        }

        .back-btn {
            display: inline-block;
            margin-top: 30px;
            padding: 12px 25px;
            background: var(--accent-gold);
            color: #000;
            text-decoration: none;
            font-weight: bold;
            border-radius: 6px;
            transition: all 0.3s ease;
        }

        .back-btn:hover {
            background: #e6c260;
            transform: translateY(-2px);
        }
        
        .footer-note {
            margin-top: 50px;
            text-align: center;
            color: var(--text-muted);
            font-size: 0.9rem;
            border-top: 1px solid rgba(255,255,255,0.1);
            padding-top: 20px;
        }
    </style>
    <link rel="stylesheet" href="assets/css/soft-sage-theme.css?v=20261019-8">
    <link rel="stylesheet" href="assets/css/auth-sage-theme.css?v=20261018-1">
    <link rel="stylesheet" href="assets/css/terms-page.css?v=20261027-2">
</head>
<body>

<main class="container terms-page">
    <header class="terms-header">
        <button type="button" class="terms-back" onclick="goBack()"><i class="fas fa-arrow-left" aria-hidden="true"></i> Go Back</button>
        <p class="terms-brand">Catering Portal</p>
        <h1>Terms and Conditions</h1>
    </header>
    <article class="terms-content" aria-label="Terms and Conditions">

    <?php
    // Fetch terms from system_settings
    $terms_content = '';
    $terms_query = "SELECT setting_value FROM system_settings WHERE setting_key = 'terms_content'";
    if ($result = mysqli_query($conn, $terms_query)) {
        if (mysqli_num_rows($result) > 0) {
            $row = mysqli_fetch_assoc($result);
            $terms_content = $row['setting_value'];
        }
    }

    // Default content if database is empty
    if (empty($terms_content)) {
        $terms_content = '
    <p>Welcome to our Catering Portal. By booking our services, you agree to comply with and be bound by the following terms and conditions. Please read them carefully.</p>

    <h2>1. Booking and Reservations</h2>
    <ul>
        <li>A non-refundable down payment of <strong>50%</strong> of the total estimated cost is required to secure your reservation date.</li>
        <li>The remaining balance must be paid in full on or before the day of the event, or as per the specific caterer\'s policy.</li>
        <li>Bookings are only confirmed upon receipt of the down payment and verification by the catering provider.</li>
        <li>We recommend booking at least 2 weeks in advance to ensure availability.</li>
    </ul>

    <h2>2. Cancellations and Refunds</h2>
    <ul>
        <li>Cancellations made <strong>7 days or more</strong> before the event date may be eligible for a refund of payments made, excluding the non-refundable down payment (subject to caterer discretion).</li>
        <li>Cancellations made <strong>less than 7 days</strong> before the event are non-refundable.</li>
        <li>In the event of a cancellation by the catering provider due to unforeseen circumstances, a full refund (including down payment) will be issued.</li>
    </ul>

    <h2>3. Menu Changes and Customizations</h2>
    <ul>
        <li>Final menu choices and guest counts must be confirmed at least <strong>5 days</strong> prior to the event.</li>
        <li>Any custom requests or add-ons submitted during booking cannot be removed once the order is confirmed, as preparations may have already begun.</li>
        <li>Additional charges may apply for last-minute changes or increases in guest count.</li>
    </ul>

    <h2>4. Event Duration and Overtime</h2>
    <ul>
        <li>The standard service duration is <strong>4 hours</strong> from the agreed start time.</li>
        <li>Overtime fees may apply for service exceeding the standard duration.</li>
        <li>Clients must ensure the venue allows ample time for setup (usually 2-3 hours before the event) and breakdown.</li>
    </ul>

    <h2>5. Liability and Damages</h2>
    <ul>
        <li>The client is responsible for any loss or damage to catering equipment (plates, utensils, warmers, tables, etc.) caused by guests during the event.</li>
        <li>Damaged or lost items will be charged to the client at replacement cost.</li>
        <li>The catering provider is not liable for food quality issues resulting from the client\'s failure to consume food within the recommended safe timeframe (usually 4 hours after serving).</li>
    </ul>

    <h2>6. Force Majeure</h2>
    <ul>
        <li>The catering provider shall not be liable for failure to perform obligations due to events beyond reasonable control, such as acts of God, extreme weather, strikes, or government restrictions.</li>
        <li>In such cases, both parties will work together to reschedule the event or negotiate a fair resolution.</li>
    </ul>

    <h2>7. Privacy Policy</h2>
    <ul>
        <li>We value your privacy. Your personal information (contact details, address) is collected solely for the purpose of fulfilling your order and will not be shared with third parties without consent.</li>
    </ul>';
    }

    echo $terms_content;
    ?>
    </article>

    <div class="footer-note">
        &copy; <?php echo date('Y'); ?> Catering Portal. All rights reserved.
    </div>
</main>

<script>
    function goBack() {
        // If there is history, go back
        if (window.history.length > 1) {
            window.history.back();
        } else {
            // If opened in a new tab/window without history, try to close it
            // This works if opened via script, but browsers might block it otherwise
            try {
                window.close();
            } catch (e) {
                console.log('Cannot close window');
            }
            
            // Fallback: Redirect to homepage if close fails or wasn't allowed
            // We set a small timeout to let window.close() attempt first
            setTimeout(function() {
                window.location.href = 'index.php';
            }, 100);
        }
    }
</script>

</body>
</html>

<?php

require_once __DIR__ . '/notification_helper.php';

if (!function_exists('getSubscriptionFee')) {
    function getSubscriptionFee(mysqli $conn): float
    {
        $defaultAmount = 500.00;
        $query = mysqli_query($conn, "SELECT setting_value FROM system_settings WHERE setting_key = 'subscription_monthly_fee' LIMIT 1");
        if ($query && ($row = mysqli_fetch_assoc($query))) {
            $amount = (float) ($row['setting_value'] ?? 0);
            if ($amount > 0) {
                return round($amount, 2);
            }
        }

        return $defaultAmount;
    }
}

if (!function_exists('getSubscriptionWarningDays')) {
    function getSubscriptionWarningDays(): int
    {
        return 10;
    }
}

if (!function_exists('syncStoreSubscriptions')) {
    function syncStoreSubscriptions(mysqli $conn): void
    {
        static $alreadySynced = false;

        if ($alreadySynced) {
            return;
        }

        // Throttle: run at most once per hour per session to avoid
        // running expensive UPDATE queries on every single page load.
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $lastSync = $_SESSION['subscription_sync_at'] ?? 0;
        if ((time() - $lastSync) < 3600) {
            $alreadySynced = true;
            return;
        }

        $alreadySynced = true;
        $_SESSION['subscription_sync_at'] = time();
        $currentDate = date('Y-m-d');
        $warningDays = getSubscriptionWarningDays();
        $warningDate = date('Y-m-d', strtotime('+' . $warningDays . ' days'));

        mysqli_query(
            $conn,
            "UPDATE store_details
             SET subscription_status = 'expired'
             WHERE subscription_status = 'active'
               AND subscription_expiry IS NOT NULL
               AND subscription_expiry < '$currentDate'"
        );

        $expiringResult = mysqli_query(
            $conn,
            "SELECT user_id, business_name, subscription_expiry
             FROM store_details
             WHERE subscription_status = 'active'
               AND subscription_expiry IS NOT NULL
               AND subscription_expiry <= '$warningDate'
               AND subscription_expiry >= '$currentDate'"
        );

        if (!$expiringResult || !function_exists('createNotification')) {
            return;
        }

        while ($caterer = mysqli_fetch_assoc($expiringResult)) {
            $storeId = (int) ($caterer['user_id'] ?? 0);
            if ($storeId <= 0) {
                continue;
            }

            $notificationCheck = mysqli_query(
                $conn,
                "SELECT id
                 FROM notifications
                 WHERE user_id = '$storeId'
                   AND type = 'subscription_expiring'
                   AND DATE(created_at) = '$currentDate'
                 LIMIT 1"
            );

            if ($notificationCheck && mysqli_num_rows($notificationCheck) > 0) {
                continue;
            }

            $daysLeft = (int) ceil((strtotime((string) $caterer['subscription_expiry']) - strtotime($currentDate)) / 86400);
            if ($daysLeft <= 0) {
                $title = 'Subscription Expires Today';
                $message = 'Your caterer subscription expires today. Please renew now to avoid interruption.';
            } elseif ($daysLeft === 1) {
                $title = 'Subscription Expires Tomorrow';
                $message = 'Your caterer subscription will expire tomorrow. Please renew now to avoid interruption.';
            } else {
                $title = 'Subscription Expiring Soon';
                $message = 'Your caterer subscription will expire in ' . $daysLeft . ' days on ' . $caterer['subscription_expiry'] . '. Please renew soon.';
            }

            createNotification($storeId, 'subscription_expiring', $title, $message, 'subscription.php');
        }
    }
}

if (!function_exists('activateStoreSubscription')) {
    function activateStoreSubscription(mysqli $conn, int $storeId, ?string $paidAt = null): ?string
    {
        $storeId = (int) $storeId;
        if ($storeId <= 0) {
            return null;
        }

        $currentDate = date('Y-m-d');
        $paidAtValue = $paidAt && strtotime($paidAt) ? date('Y-m-d H:i:s', strtotime($paidAt)) : date('Y-m-d H:i:s');

        $storeQuery = mysqli_query($conn, "SELECT subscription_expiry, subscription_status FROM store_details WHERE user_id = '$storeId' LIMIT 1");
        $storeData = $storeQuery ? mysqli_fetch_assoc($storeQuery) : null;
        if (!$storeData) {
            return null;
        }

        if (($storeData['subscription_status'] ?? '') === 'active' && !empty($storeData['subscription_expiry']) && $storeData['subscription_expiry'] > $currentDate) {
            $newExpiry = date('Y-m-d', strtotime($storeData['subscription_expiry'] . ' +30 days'));
        } else {
            $newExpiry = date('Y-m-d', strtotime('+30 days'));
        }

        $safeExpiry = mysqli_real_escape_string($conn, $newExpiry);

        $updated = mysqli_query(
            $conn,
            "UPDATE store_details
             SET subscription_status = 'active',
                 subscription_expiry = '$safeExpiry',
                 free_trial_used = 1
             WHERE user_id = '$storeId'"
        );

        if (!$updated) {
            return null;
        }

        $safePaidAt = mysqli_real_escape_string($conn, $paidAtValue);
        mysqli_query(
            $conn,
            "UPDATE subscriptions
             SET start_date = DATE('$safePaidAt'),
                 end_date = '$safeExpiry'
             WHERE store_id = '$storeId'
               AND payment_status = 'paid'
               AND (end_date IS NULL OR end_date = '0000-00-00')
             ORDER BY id DESC
             LIMIT 1"
        );

        return $newExpiry;
    }
}

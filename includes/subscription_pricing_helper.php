<?php
function normalizeSubscriptionMonthlyFee(string $value): ?string {
    $value = trim($value);
    if (!preg_match('/^\d{1,7}(?:\.\d{1,2})?$/D', $value) || (float)$value < 0.01) return null;
    return number_format((float)$value, 2, '.', '');
}

// One database-scoped lock shared by price edits and subscription submissions.
function acquireSubscriptionPricingLock(mysqli $conn, int $seconds = 10): bool {
    $seconds = max(0, min(10, $seconds));
    $result = $conn->query("SELECT GET_LOCK(CONCAT('cp_subscription_price_', MD5(DATABASE())), $seconds) AS acquired");
    return $result && (int)$result->fetch_assoc()['acquired'] === 1;
}
function releaseSubscriptionPricingLock(mysqli $conn): void {
    $conn->query("SELECT RELEASE_LOCK(CONCAT('cp_subscription_price_', MD5(DATABASE())))");
}

function saveSubscriptionMonthlyFee(mysqli $conn, string $amount, ?string &$error = null): bool {
    $error = null;
    if (normalizeSubscriptionMonthlyFee($amount) !== $amount) {
        $error = 'Enter a positive amount with up to two decimal places.';
        return false;
    }
    $locked = false;
    try {
        $locked = acquireSubscriptionPricingLock($conn);
        if (!$locked) {
            $error = 'A subscription transaction is in progress. Please try again after it finishes.';
            return false;
        }
        $conn->begin_transaction();
        $existing = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'subscription_monthly_fee' FOR UPDATE");
        $values = $existing->fetch_all(MYSQLI_ASSOC);
        $changing = !$values;
        foreach ($values as $value) {
            if (normalizeSubscriptionMonthlyFee((string)$value['setting_value']) !== $amount) $changing = true;
        }
        $pending = $conn->query("SELECT COUNT(*) AS pending FROM subscriptions WHERE payment_status = 'pending'")->fetch_assoc();
        if ($changing && (int)$pending['pending'] > 0) {
            $conn->rollback();
            $error = 'Cannot change the subscription price while a subscription payment is pending verification. Approve or reject pending payments first.';
            return false;
        }
        // Older databases do not have a unique index on setting_key.
        // Update every existing copy so all readers receive the same amount.
        $sql = $values
            ? "UPDATE system_settings SET setting_value = ? WHERE setting_key = 'subscription_monthly_fee'"
            : "INSERT INTO system_settings (setting_key, setting_value) VALUES ('subscription_monthly_fee', ?)";
        $save = $conn->prepare($sql);
        $save->bind_param('s', $amount);
        $save->execute();
        $save->close();
        $result = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'subscription_monthly_fee'");
        if (!$result->num_rows) throw new RuntimeException('Saved setting missing.');
        while ($row = $result->fetch_assoc()) {
            if (normalizeSubscriptionMonthlyFee((string)$row['setting_value']) !== $amount) throw new RuntimeException('Saved price did not match.');
        }
        $conn->commit();
        return true;
    } catch (Throwable $failure) {
        $conn->rollback();
        $error = 'Unable to save the subscription price. Please try again.';
        error_log('Subscription price save failed: ' . $failure->getMessage());
        return false;
    } finally {
        if ($locked) releaseSubscriptionPricingLock($conn);
    }
}

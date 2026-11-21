<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/booking_flow_helper.php';
require_once __DIR__ . '/booking_pricing_helper.php';

if (!function_exists('getOrderStoredPricingSnapshot')) {
    function getOrderStoredPricingSnapshot(array $order): array
    {
        $base_price = bookingPricingRound($order['base_price'] ?? 0);
        $addons_total = bookingPricingRound($order['addons_total'] ?? 0);
        $extra_fee = bookingPricingRound($order['extra_fee'] ?? 0);
        $package_total = bookingPricingRound($base_price + $addons_total + $extra_fee);
        $stored_total_amount = bookingPricingRound($order['total_amount'] ?? 0);

        if ($stored_total_amount > 0) {
            $unaccounted_amount = bookingPricingRound($stored_total_amount - $package_total);
            if ($unaccounted_amount > 0) {
                $addons_total = bookingPricingRound($addons_total + $unaccounted_amount);
            }
            $package_total = $stored_total_amount;
        }

        return [
            'base_price' => $base_price,
            'addons_total' => $addons_total,
            'extra_fee' => $extra_fee,
            'package_total' => $package_total,
            'stored_total_amount' => $stored_total_amount
        ];
    }
}

if (!function_exists('syncOrderPricingSnapshotForFinancials')) {
    function syncOrderPricingSnapshotForFinancials(mysqli $conn, int $order_id, array $order, array $snapshot): array
    {
        $stored_base_price = bookingPricingRound($order['base_price'] ?? 0);
        $stored_package_total = bookingPricingRound($snapshot['package_total'] ?? 0);

        if ($stored_base_price > 0 || intval($order['product_id'] ?? 0) <= 0) {
            return $snapshot;
        }

        $pricing = bookingPricingCalculateStoredOrder($conn, $order);
        if (!$pricing || bookingPricingRound($pricing['total_amount'] ?? 0) <= 0) {
            return $snapshot;
        }

        $recalculated_total = bookingPricingRound($pricing['total_amount'] ?? 0);
        if ($stored_package_total <= 0 || abs($stored_package_total - $recalculated_total) > 0.01) {
            bookingPricingUpdateOrderFields($conn, $order_id, $pricing);
        }

        return [
            'base_price' => bookingPricingRound($pricing['base_price'] ?? 0),
            'addons_total' => bookingPricingRound($pricing['addons_total'] ?? 0),
            'extra_fee' => bookingPricingRound($pricing['extra_fee'] ?? 0),
            'package_total' => $recalculated_total,
            'stored_total_amount' => bookingPricingRound($order['total_amount'] ?? 0)
        ];
    }
}

if (!function_exists('getOrderFinancials')) {
    function getOrderFinancials($order_id)
    {
        global $conn;
        $order_id = intval($order_id);

        $order_result = mysqli_query($conn, "SELECT * FROM bookings WHERE id = $order_id LIMIT 1");
        if (!$order_result || mysqli_num_rows($order_result) === 0) {
            return null;
        }

        $order = mysqli_fetch_assoc($order_result);
        $snapshot = getOrderStoredPricingSnapshot($order);
        $snapshot = syncOrderPricingSnapshotForFinancials($conn, $order_id, $order, $snapshot);

        $damage_result = mysqli_query($conn, "SELECT COALESCE(SUM(total), 0) AS total FROM booking_damages WHERE order_id = $order_id");
        $damage_row = $damage_result ? mysqli_fetch_assoc($damage_result) : ['total' => 0];
        $damage_total = bookingPricingRound($damage_row['total'] ?? 0);

        $payments = [];
        $total_paid = 0.0;
        $pending_payments = 0.0;

        $payment_result = mysqli_query($conn, "SELECT * FROM payments WHERE order_id = $order_id ORDER BY created_at DESC, id DESC");
        if ($payment_result) {
            while ($payment = mysqli_fetch_assoc($payment_result)) {
                if (!isset($payment['proof_image']) && isset($payment['receipt_image'])) {
                    $payment['proof_image'] = $payment['receipt_image'];
                }

                $payments[] = $payment;

                $payment_amount = bookingPricingRound($payment['amount'] ?? 0);
                $payment_status = strtolower((string) ($payment['status'] ?? ''));
                $payment_type = strtolower((string) ($payment['payment_type'] ?? ''));
                
                if ($payment_type === 'refund') {
                    $payment_amount = -$payment_amount;
                }

                if (in_array($payment_status, ['verified', 'confirmed'], true)) {
                    $total_paid += $payment_amount;
                } elseif ($payment_status === 'pending') {
                    $pending_payments += $payment_amount;
                }
            }
        }

        $package_total = bookingPricingRound($snapshot['package_total']);
        $grand_total = bookingPricingRound($package_total + $damage_total);
        $balance = bookingPricingRound(max(0, $grand_total - $total_paid));

        $down_payment_required = $package_total > 0 ? bookingPricingRound($package_total * 0.5) : 0.0;

        $status = 'unpaid';
        if ($grand_total > 0) {
            if ($total_paid >= $grand_total) {
                $status = 'paid';
            } elseif ($total_paid > 0) {
                $status = 'partial';
            }
        } elseif ($total_paid > 0) {
            $status = 'partial';
        }

        if (($order['payment_status'] ?? '') !== $status) {
            mysqli_query($conn, "UPDATE bookings SET payment_status = '" . mysqli_real_escape_string($conn, $status) . "' WHERE id = $order_id");
        }

        if (bookingPricingRound($order['down_payment_required'] ?? 0) !== $down_payment_required) {
            mysqli_query($conn, "UPDATE bookings SET down_payment_required = '" . mysqli_real_escape_string($conn, (string) $down_payment_required) . "' WHERE id = $order_id");
        }

        if (bookingPricingRound($order['total_amount'] ?? 0) !== $package_total) {
            mysqli_query($conn, "UPDATE bookings SET total_amount = '" . mysqli_real_escape_string($conn, (string) $package_total) . "' WHERE id = $order_id");
        }

        if (bookingPricingRound($order['down_payment_paid'] ?? 0) !== bookingPricingRound($total_paid)) {
            mysqli_query($conn, "UPDATE bookings SET down_payment_paid = '" . mysqli_real_escape_string($conn, (string) bookingPricingRound($total_paid)) . "' WHERE id = $order_id");
        }

        return [
            'order_id' => $order_id,
            'currency' => '₱',
            'payment_channel' => normalizePaymentChannel($order['payment_method'] ?? '', $order),
            'down_payment_required' => $down_payment_required,
            'breakdown' => [
                'base_price' => $snapshot['base_price'],
                'addons' => $snapshot['addons_total'],
                'extra_fee' => $snapshot['extra_fee'],
                'package_total' => $package_total,
                'damages' => $damage_total
            ],
            'totals' => [
                'grand_total' => $grand_total,
                'total_paid' => bookingPricingRound($total_paid),
                'pending_payments' => bookingPricingRound($pending_payments),
                'balance' => $balance
            ],
            'status' => $status,
            'history' => $payments
        ];
    }
}
?>

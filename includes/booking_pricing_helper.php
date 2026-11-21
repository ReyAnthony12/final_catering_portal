<?php

if (!function_exists('bookingPricingRound')) {
    function bookingPricingRound($amount): float
    {
        if (is_string($amount)) {
            $amount = preg_replace('/[^\d.-]/', '', $amount);
        }
        return round((float) $amount, 2);
    }
}

if (!function_exists('bookingPricingParseCurrencyAmount')) {
    function bookingPricingParseCurrencyAmount($value): float
    {
        $cleaned = preg_replace('/[^\d.-]/', '', (string) $value);
        return bookingPricingRound($cleaned);
    }
}

if (!function_exists('bookingPricingNormalizeAddonItems')) {
    function bookingPricingNormalizeAddonItems($addons): array
    {
        if (!is_array($addons)) {
            return [];
        }

        $normalized = [];
        foreach ($addons as $addon) {
            if (!is_array($addon)) {
                continue;
            }

            $name = trim((string) ($addon['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $qty = intval($addon['qty'] ?? 1);
            if ($qty < 1) {
                $qty = 1;
            }

            $price = bookingPricingRound($addon['price'] ?? 0);
            $normalized[] = [
                'name' => $name,
                'qty' => $qty,
                'price' => $price,
                'total_price' => bookingPricingRound($price * $qty)
            ];
        }

        return $normalized;
    }
}

if (!function_exists('bookingPricingNormalizeSwapItems')) {
    function bookingPricingNormalizeSwapItems($swaps): array
    {
        if (!is_array($swaps)) {
            return [];
        }

        $normalized = [];
        foreach ($swaps as $swap) {
            if (!is_array($swap)) {
                continue;
            }

            $original = trim((string) ($swap['original'] ?? ''));
            $replacement = trim((string) ($swap['replacement'] ?? ($swap['new'] ?? '')));
            $price = bookingPricingRound($swap['price'] ?? 0);

            $normalized[] = [
                'original' => $original,
                'replacement' => $replacement,
                'price' => $price
            ];
        }

        return $normalized;
    }
}

if (!function_exists('bookingPricingMenuCategoryLabels')) {
    function bookingPricingMenuCategoryLabels(): array
    {
        return [
            'appetizers' => 'Appetizers',
            'soup' => 'Soup',
            'rice' => 'Rice',
            'main_dishes' => 'Main Dishes',
            'pasta_noodles' => 'Pasta/Noodles',
            'desserts' => 'Desserts',
            'drinks' => 'Drinks',
        ];
    }
}

if (!function_exists('bookingPricingGetLegacyCategoryLabel')) {
    function bookingPricingGetLegacyCategoryLabel(string $key): string
    {
        $labels = [
            'appetizers' => 'Appetizers',
            'soup' => 'Soup',
            'rice' => 'Rice',
            'main_dishes' => 'Main Dishes',
            'pasta_noodles' => 'Pasta/Noodles',
            'desserts' => 'Desserts',
            'drinks' => 'Drinks',
        ];
        return $labels[$key] ?? $key;
    }
}

if (!function_exists('bookingPricingParseSetDescription')) {
    function bookingPricingParseSetDescription($description): array
    {
        $groups = [];
        $items = [];
        $raw = trim((string) $description);
        if ($raw === '') {
            return ['groups' => [], 'items' => []];
        }

        $decoded = json_decode($raw, true);
        if (is_array($decoded) && isset($decoded['categories']) && is_array($decoded['categories'])) {
            foreach ($decoded['categories'] as $key => $categoryItems) {
                if (is_string($categoryItems)) {
                    $categoryItems = preg_split('/\r\n|\r|\n|,/', $categoryItems);
                }
                if (!is_array($categoryItems)) {
                    continue;
                }

                $clean = [];
                foreach ($categoryItems as $item) {
                    $item = trim((string) $item);
                    $item = trim(preg_replace('/^[\d\.\-\x{2022}\x{25E6}]+/u', '', $item));
                    if ($item === '') {
                        continue;
                    }
                    $clean[] = $item;
                    $items[] = $item;
                }

                $label = bookingPricingGetLegacyCategoryLabel($key);
                if (!empty($clean)) {
                    $groups[] = ['label' => $label, 'items' => $clean];
                }
            }
            return ['groups' => $groups, 'items' => $items];
        }

        $lines = preg_split('/\r\n|\r|\n|,/', $raw);
        $clean = [];
        foreach ($lines as $item) {
            $item = trim((string) $item);
            $item = trim(preg_replace('/^[\d\.\-\x{2022}\x{25E6}]+/u', '', $item));
            if ($item === '') {
                continue;
            }
            $clean[] = $item;
            $items[] = $item;
        }

        if (!empty($clean)) {
            $groups[] = ['label' => 'Main Dishes', 'items' => $clean];
        }

        return ['groups' => $groups, 'items' => $items];
    }
}

if (!function_exists('bookingPricingExtractSetName')) {
    function bookingPricingExtractSetName($additional_menu): string
    {
        $parts = preg_split('/\s*\|\s*/', trim((string) $additional_menu), -1, PREG_SPLIT_NO_EMPTY);
        if (empty($parts)) {
            return '';
        }

        $firstPart = trim((string) $parts[0]);
        if (
            stripos($firstPart, 'Added Items:') === 0 ||
            stripos($firstPart, 'Menu Swaps:') === 0 ||
            stripos($firstPart, 'Custom Add-on Request:') === 0
        ) {
            return '';
        }

        return $firstPart;
    }
}

if (!function_exists('bookingPricingParseStoredMenu')) {
    function bookingPricingParseStoredMenu($additional_menu): array
    {
        $parts = preg_split('/\s*\|\s*/', trim((string) $additional_menu), -1, PREG_SPLIT_NO_EMPTY);
        $parsed = [
            'selected_set_id' => null,
            'selected_set_name' => '',
            'addons' => [],
            'swaps' => [],
            'custom_request' => ''
        ];

        if (empty($parts)) {
            return $parsed;
        }

        $parsed['selected_set_name'] = bookingPricingExtractSetName($additional_menu);

        foreach ($parts as $index => $part) {
            $part = trim((string) $part);
            if ($part === '') {
                continue;
            }

            if ($index === 0 && $parsed['selected_set_name'] !== '' && $part === $parsed['selected_set_name']) {
                continue;
            }

            if (stripos($part, 'Added Items:') === 0) {
                $itemsText = trim(substr($part, strlen('Added Items:')));
                if (preg_match_all('/(.+?)\s*\(\+\s*(?:₱|P|₱)\s*([\d,.]+)\)/u', $itemsText, $matches, PREG_SET_ORDER)) {
                    foreach ($matches as $match) {
                        $parsed['addons'][] = [
                            'name' => trim((string) ($match[1] ?? '')),
                            'qty' => 1,
                            'price' => bookingPricingParseCurrencyAmount($match[2] ?? 0)
                        ];
                    }
                }
                continue;
            }

            if (stripos($part, 'Menu Swaps:') === 0) {
                $itemsText = trim(substr($part, strlen('Menu Swaps:')));
                if (preg_match_all('/Swap\s+(.+?)\s+for\s+(.+?)\s*\(\+\s*(?:₱|P|₱)\s*([\d,.]+)\)/iu', $itemsText, $matches, PREG_SET_ORDER)) {
                    foreach ($matches as $match) {
                        $parsed['swaps'][] = [
                            'original' => trim((string) ($match[1] ?? '')),
                            'replacement' => trim((string) ($match[2] ?? '')),
                            'price' => bookingPricingParseCurrencyAmount($match[3] ?? 0)
                        ];
                    }
                }
                continue;
            }

            if (stripos($part, 'Custom Add-on Request:') === 0) {
                $parsed['custom_request'] = trim(substr($part, strlen('Custom Add-on Request:')));
            }
        }

        return [
            'selected_set_name' => $parsed['selected_set_name'],
            'addons' => bookingPricingNormalizeAddonItems($parsed['addons']),
            'swaps' => bookingPricingNormalizeSwapItems($parsed['swaps']),
            'custom_request' => $parsed['custom_request']
        ];
    }
}

if (!function_exists('bookingPricingResolveProductRate')) {
    function bookingPricingResolveProductRate(mysqli $conn, int $product_id, string $selected_set_name = ''): ?array
    {
        if ($product_id <= 0) {
            return null;
        }

        $product_stmt = $conn->prepare("SELECT id, category, price FROM products WHERE id = ? LIMIT 1");
        if (!$product_stmt) {
            return null;
        }

        $product_stmt->bind_param("i", $product_id);
        if (!$product_stmt->execute()) {
            $product_stmt->close();
            return null;
        }

        $product = $product_stmt->get_result()->fetch_assoc();
        $product_stmt->close();

        if (!$product) {
            return null;
        }

        $resolved = [
            'product_id' => $product_id,
            'category' => (string) ($product['category'] ?? ''),
            'product_price' => bookingPricingRound($product['price'] ?? 0),
            'selected_set_name' => '',
            'selected_set_price' => 0.0,
            'selected_set_pax' => 0,
            'selected_set_per_head_price' => 0.0,
            'extra_guest_rate' => 0.0,
            'pricing_mode' => 'unit',
            'price_per_unit' => bookingPricingRound($product['price'] ?? 0),
            'is_catering' => (($product['category'] ?? '') === 'catering_service')
        ];

        $selected_set_name = trim($selected_set_name);
        if (!$resolved['is_catering'] || $selected_set_name === '') {
            return $resolved;
        }

        $set_stmt = $conn->prepare("
            SELECT id, set_name, price, pax, per_head_price, extra_guest_rate
            FROM product_sets
            WHERE product_id = ?
              AND set_name = ?
            LIMIT 1
        ");

        if (!$set_stmt) {
            return $resolved;
        }

        $set_stmt->bind_param("is", $product_id, $selected_set_name);
        if (!$set_stmt->execute()) {
            $set_stmt->close();
            return $resolved;
        }

        $set_row = $set_stmt->get_result()->fetch_assoc();
        $set_stmt->close();

        if (!$set_row) {
            return $resolved;
        }

        $set_price = bookingPricingRound($set_row['price'] ?? 0);
        $set_pax = max(0, intval($set_row['pax'] ?? 0));
        $set_per_head_price = bookingPricingRound($set_row['per_head_price'] ?? 0);
        $extra_guest_rate = bookingPricingRound($set_row['extra_guest_rate'] ?? 0);
        $pricing_mode = $set_per_head_price > 0 ? 'by_pax' : 'package';
        $price_per_unit = $pricing_mode === 'by_pax' ? $set_per_head_price : 0.0;

        return [
            'product_id' => $product_id,
            'category' => (string) ($product['category'] ?? ''),
            'product_price' => bookingPricingRound($product['price'] ?? 0),
            'selected_set_id' => intval($set_row['id'] ?? 0),
            'selected_set_name' => trim((string) ($set_row['set_name'] ?? $selected_set_name)),
            'selected_set_price' => $set_price,
            'selected_set_pax' => $set_pax,
            'selected_set_per_head_price' => $set_per_head_price,
            'extra_guest_rate' => $extra_guest_rate,
            'pricing_mode' => $pricing_mode,
            'price_per_unit' => $price_per_unit,
            'is_catering' => (($product['category'] ?? '') === 'catering_service')
        ];
    }
}

if (!function_exists('bookingPricingCalculateBreakdown')) {
    function bookingPricingCalculateBreakdown(array $resolved_rate, int $quantity, array $addons = [], array $swaps = [], float $extra_fee = 0.0): array
    {
        $quantity = max(1, intval($quantity));
        $addons = bookingPricingNormalizeAddonItems($addons);
        $swaps = bookingPricingNormalizeSwapItems($swaps);
        $extra_fee = bookingPricingRound($extra_fee);

        $addons_total = 0.0;
        foreach ($addons as $addon) {
            $addons_total += bookingPricingRound($addon['total_price'] ?? (($addon['price'] ?? 0) * ($addon['qty'] ?? 1)));
        }
        $addons_total = bookingPricingRound($addons_total);

        $swaps_per_unit = 0.0;
        foreach ($swaps as $swap) {
            $swaps_per_unit += bookingPricingRound($swap['price'] ?? 0);
        }
        $swaps_per_unit = bookingPricingRound($swaps_per_unit);

        $pricing_mode = (string) ($resolved_rate['pricing_mode'] ?? 'unit');
        $selected_set_price = bookingPricingRound($resolved_rate['selected_set_price'] ?? 0);
        $selected_set_pax = max(0, intval($resolved_rate['selected_set_pax'] ?? 0));
        $extra_guest_rate = bookingPricingRound($resolved_rate['extra_guest_rate'] ?? 0);
        $price_per_unit = bookingPricingRound($resolved_rate['price_per_unit'] ?? 0);
        $extra_guest_count = 0;
        $extra_guest_total = 0.0;

        if ($pricing_mode === 'package' && $selected_set_price > 0) {
            if ($selected_set_pax > 0 && $quantity > $selected_set_pax) {
                $extra_guest_count = $quantity - $selected_set_pax;
                $extra_guest_total = bookingPricingRound($extra_guest_count * $extra_guest_rate);
            }
            $base_total = bookingPricingRound($selected_set_price + $extra_guest_total + $swaps_per_unit);
        } else {
            // Swaps are a flat fee — only the per-head rate is multiplied by quantity
            $base_total = bookingPricingRound($price_per_unit * $quantity + $swaps_per_unit);
        }
        $total_amount = bookingPricingRound($base_total + $addons_total + $extra_fee);

        return [
            'product_id' => intval($resolved_rate['product_id'] ?? 0),
            'category' => (string) ($resolved_rate['category'] ?? ''),
            'quantity' => $quantity,
            'selected_set_id' => isset($resolved_rate['selected_set_id']) ? intval($resolved_rate['selected_set_id']) : null,
            'selected_set_name' => (string) ($resolved_rate['selected_set_name'] ?? ''),
            'selected_set_price' => $selected_set_price,
            'selected_set_pax' => intval($resolved_rate['selected_set_pax'] ?? 0),
            'selected_set_per_head_price' => bookingPricingRound($resolved_rate['selected_set_per_head_price'] ?? 0),
            'extra_guest_rate' => $extra_guest_rate,
            'extra_guest_count' => $extra_guest_count,
            'extra_guest_total' => $extra_guest_total,
            'pricing_mode' => $pricing_mode,
            'product_price' => bookingPricingRound($resolved_rate['product_price'] ?? 0),
            'price_per_unit' => $price_per_unit,
            'swaps_per_unit' => $swaps_per_unit,
            'base_price' => $base_total,
            'addons_total' => $addons_total,
            'extra_fee' => $extra_fee,
            'total_amount' => $total_amount,
            'down_payment_required' => bookingPricingRound($total_amount * 0.5),
            'addons' => $addons,
            'swaps' => $swaps
        ];
    }
}

if (!function_exists('bookingPricingCalculateRequest')) {
    function bookingPricingCalculateRequest(mysqli $conn, array $params): ?array
    {
        $product_id = intval($params['product_id'] ?? 0);
        $quantity = max(1, intval($params['quantity'] ?? 1));
        $selected_set_name = trim((string) ($params['selected_set_name'] ?? ''));
        $extra_fee = bookingPricingRound($params['extra_fee'] ?? 0);

        $resolved_rate = bookingPricingResolveProductRate($conn, $product_id, $selected_set_name);
        if (!$resolved_rate) {
            return null;
        }

        return bookingPricingCalculateBreakdown(
            $resolved_rate,
            $quantity,
            $params['addons'] ?? [],
            $params['swaps'] ?? [],
            $extra_fee
        );
    }
}

if (!function_exists('bookingPricingCalculateStoredOrder')) {
    function bookingPricingCalculateStoredOrder(mysqli $conn, array $order, array $overrides = []): ?array
    {
        $merged_order = array_merge($order, $overrides);
        $product_id = intval($merged_order['product_id'] ?? 0);
        $quantity = max(1, intval($merged_order['number_of_pax'] ?? ($merged_order['quantity'] ?? 1)));
        $extra_fee = bookingPricingRound($merged_order['extra_fee'] ?? 0);
        $additional_menu = (string) ($merged_order['additional_menu'] ?? '');
        $parsed_menu = bookingPricingParseStoredMenu($additional_menu);

        $resolved_rate = bookingPricingResolveProductRate($conn, $product_id, $parsed_menu['selected_set_name'] ?? '');
        if (!$resolved_rate) {
            return null;
        }

        $addons_total = bookingPricingRound($merged_order['addons_total'] ?? 0);
        $addons = $parsed_menu['addons'] ?? [];
        if ($addons_total <= 0 && !empty($addons)) {
            foreach ($addons as $addon) {
                $addons_total += bookingPricingRound($addon['total_price'] ?? 0);
            }
            $addons_total = bookingPricingRound($addons_total);
        }

        $breakdown = bookingPricingCalculateBreakdown(
            $resolved_rate,
            $quantity,
            [],
            $parsed_menu['swaps'] ?? [],
            $extra_fee
        );

        $breakdown['addons_total'] = $addons_total;
        $breakdown['addons'] = $addons;
        $breakdown['additional_menu'] = $additional_menu;
        $breakdown['selected_set_name'] = $parsed_menu['selected_set_name'] ?? ($breakdown['selected_set_name'] ?? '');
        $breakdown['total_amount'] = bookingPricingRound($breakdown['base_price'] + $addons_total + $extra_fee);
        $breakdown['down_payment_required'] = bookingPricingRound($breakdown['total_amount'] * 0.5);

        return $breakdown;
    }
}

if (!function_exists('bookingPricingBuildOrderUpdateMap')) {
    function bookingPricingBuildOrderUpdateMap(array $pricing): array
    {
        return [
            'base_price' => bookingPricingRound($pricing['base_price'] ?? 0),
            'addons_total' => bookingPricingRound($pricing['addons_total'] ?? 0),
            'extra_fee' => bookingPricingRound($pricing['extra_fee'] ?? 0),
            'total_amount' => bookingPricingRound($pricing['total_amount'] ?? 0),
            'down_payment_required' => bookingPricingRound($pricing['down_payment_required'] ?? 0)
        ];
    }
}

if (!function_exists('bookingPricingUpdateOrderFields')) {
    function bookingPricingUpdateOrderFields(mysqli $conn, int $order_id, array $pricing, array $extra_updates = []): bool
    {
        if ($order_id <= 0) {
            return false;
        }

        $updates = array_merge(bookingPricingBuildOrderUpdateMap($pricing), $extra_updates);
        if (empty($updates)) {
            return false;
        }

        $assignments = [];
        foreach ($updates as $column => $value) {
            if (is_null($value)) {
                $assignments[] = "`$column` = NULL";
                continue;
            }

            if (is_int($value) || is_float($value)) {
                $assignments[] = "`$column` = '" . mysqli_real_escape_string($conn, (string) $value) . "'";
                continue;
            }

            $assignments[] = "`$column` = '" . mysqli_real_escape_string($conn, (string) $value) . "'";
        }

        $sql = "UPDATE bookings SET " . implode(', ', $assignments) . " WHERE id = " . intval($order_id);
        return (bool) mysqli_query($conn, $sql);
    }
}
?>

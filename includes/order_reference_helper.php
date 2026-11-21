<?php
// Normalize notification copy without changing stored references or target URLs.
function formatNotificationBookingReferences($text): string {
    global $conn;
    $role = $_SESSION['role'] ?? '';
    if ($role === 'customer') return formatCustomerMessageBookingReferences($text);
    if (in_array($role, ['store', 'caterer'], true)) {
        $text = formatCatererMessageBookingReferences($text);
    } elseif ($role === 'portal') {
        static $references = null;
        if ($references === null) {
            $references = [];
            $sequences = [];
            $result = mysqli_query($conn, "SELECT id, store_id, customer_id, order_number FROM bookings ORDER BY COALESCE(created_at, '1000-01-01 00:00:00'), id");
            if (!$result) throw new RuntimeException('Unable to load notification booking references.');
            while ($row = mysqli_fetch_assoc($result)) {
                $key = $row['store_id'] . ':' . $row['customer_id'];
                $sequences[$key] = ($sequences[$key] ?? 0) + 1;
                $references[strtoupper($row['order_number'])] = $sequences[$key];
            }
        }
        $text = preg_replace_callback('/(?:(?:Order|Booking)\s*#?\s*:?\s*)?\bORD-[A-Z0-9]+\b/i', static function ($match) use ($references) {
            preg_match('/ORD-[A-Z0-9]+/i', $match[0], $reference);
            $number = $references[strtoupper($reference[0])] ?? null;
            return $number === null ? $match[0] : 'Booking #' . $number;
        }, (string) $text);
    }
    $parts = preg_split('~(https?://[^\s<>]+|\[attachment:[^\]]*\])~i', (string) $text, -1, PREG_SPLIT_DELIM_CAPTURE);
    foreach ($parts as $index => $part) {
        if ($index % 2 !== 0) continue;
        $parts[$index] = preg_replace_callback('/\borders?\b/i', static function ($match) {
            $word = strtolower($match[0]) === 'orders' ? 'bookings' : 'booking';
            return $match[0] === strtoupper($match[0]) ? strtoupper($word) : (ctype_upper($match[0][0]) ? ucfirst($word) : $word);
        }, $part);
    }
    return implode('', $parts);
}

function formatOrderReference($order_id, $order_number = '') {
    if (($_SESSION['role'] ?? '') === 'customer') {
        return formatCustomerBookingNumber($order_id, $order_number);
    }
    return formatCatererBookingNumber($order_id, $order_number);
}

// Number a customer's own bookings from one, independent of other accounts.
function formatCustomerBookingNumber($booking_id, $reference = '') {
    global $conn;
    static $cache = [];
    $customer_id = (int) ($_SESSION['user_id'] ?? 0);
    if (!isset($cache[$customer_id])) {
        $result = mysqli_query($conn, "SELECT id, order_number FROM bookings WHERE customer_id = $customer_id ORDER BY COALESCE(created_at, '1000-01-01 00:00:00') ASC, id ASC");
        if (!$result) { throw new RuntimeException('Unable to load customer booking numbers.'); }
        $cache[$customer_id] = ['ids' => [], 'references' => []];
        $sequence = 0;
        while ($row = mysqli_fetch_assoc($result)) {
            $sequence++;
            $cache[$customer_id]['ids'][(int) $row['id']] = $sequence;
            $cache[$customer_id]['references'][(string) $row['order_number']] = $sequence;
        }
    }
    $number = $cache[$customer_id]['ids'][(int) $booking_id] ?? $cache[$customer_id]['references'][(string) $reference] ?? null;
    return $number === null ? 'Unavailable' : (string) $number;
}
// Use the same per-customer, per-caterer creation sequence on every page.
// Archived and cancelled bookings remain included so filters never renumber bookings.
function getCatererBookingNumbers($store_id) {
    global $conn;
    static $cache = [];
    $store_id = (int) $store_id;
    if (!isset($cache[$store_id])) {
        $numbers = [];
        $references = [];
        $sequences = [];
        $result = mysqli_query($conn, "SELECT id, customer_id, order_number FROM bookings WHERE store_id = $store_id ORDER BY COALESCE(created_at, '1000-01-01 00:00:00') ASC, id ASC");
        if (!$result) { throw new RuntimeException('Unable to load booking numbers.'); }
        while ($row = mysqli_fetch_assoc($result)) {
            $customer = (int) $row['customer_id'];
            $sequences[$customer] = ($sequences[$customer] ?? 0) + 1;
            $numbers[(int) $row['id']] = $sequences[$customer];
            $references[$row['order_number']] = $sequences[$customer];
        }
        $cache[$store_id] = ['ids' => $numbers, 'references' => $references];
    }
    return $cache[$store_id];
}

function formatCatererBookingNumber($booking_id = 0, $order_number = '', $store_id = null) {
    if (($_SESSION['role'] ?? '') === 'customer') {
        return formatCustomerBookingNumber($booking_id, $order_number);
    }
    $numbers = getCatererBookingNumbers((int) ($store_id ?? ($_SESSION['user_id'] ?? 0)));
    $number = $numbers['ids'][(int) $booking_id] ?? $numbers['references'][(string) $order_number] ?? null;
    return $number === null ? 'Unavailable' : (string) $number;
}


// Translate references only for display; retain stored message text for booking lookups.
function formatCatererMessageBookingReferences($text) {
    $references = getCatererBookingNumbers((int) ($_SESSION['user_id'] ?? 0))['references'];
    // Leave attachment paths and URLs intact.
    $parts = preg_split('~(\[attachment:[^\]]*\]|https?://[^\s<>]+)~i', (string) $text, -1, PREG_SPLIT_DELIM_CAPTURE);
    foreach ($parts as $index => $part) {
        if ($index % 2 !== 0) { continue; }
        $parts[$index] = preg_replace_callback('/(?:(?:Order|Booking)\s*#\s*:?\s*)?\bORD-[A-Z0-9]+\b/i', function ($match) use ($references) {
            preg_match('/ORD-[A-Z0-9]+/i', $match[0], $reference);
            $key = strtoupper($reference[0]);
            if (!isset($references[$key])) { return $match[0]; }
            return 'Booking #' . $references[$key];
        }, $part);
    }
    return implode('', $parts);
}


// Render customer message references using the same numbering as My Bookings.
function formatCustomerMessageBookingReferences($text): string {
    global $conn;
    static $references = [];
    $customerId = (int) ($_SESSION['user_id'] ?? 0);
    if (!isset($references[$customerId])) {
        $references[$customerId] = [];
        $result = mysqli_query($conn, "SELECT id, order_number FROM bookings WHERE customer_id = $customerId ORDER BY COALESCE(created_at, '1000-01-01 00:00:00'), id");
        if (!$result) throw new RuntimeException('Unable to load customer booking references.');
        $number = 0;
        while ($row = mysqli_fetch_assoc($result)) {
            $number++;
            $references[$customerId][strtoupper($row['order_number'])] = $number;
        }
    }
    $parts = preg_split('~(\[attachment:[^\]]*\]|https?://[^\s<>]+)~i', (string) $text, -1, PREG_SPLIT_DELIM_CAPTURE);
    foreach ($parts as $index => $part) {
        if ($index % 2 !== 0) continue;
        $parts[$index] = preg_replace_callback('/(?:(?:Order|Booking)\s*#?\s*:?\s*)?\bORD-[A-Z0-9]+\b/i', function ($match) use ($references, $customerId) {
            preg_match('/ORD-[A-Z0-9]+/i', $match[0], $reference);
            $number = $references[$customerId][strtoupper($reference[0])] ?? null;
            return $number === null ? $match[0] : 'Booking #' . $number;
        }, $part);
        $parts[$index] = preg_replace('/\bOrder\s*#\s*:?\s*/i', 'Booking #', $parts[$index]);
        $parts[$index] = preg_replace_callback('/\borders?\b/i', function ($match) {
            $word = strtolower($match[0]) === 'orders' ? 'bookings' : 'booking';
            if ($match[0] === strtoupper($match[0])) return strtoupper($word);
            return ctype_upper($match[0][0]) ? ucfirst($word) : $word;
        }, $parts[$index]);
    }
    return implode('', $parts);
}

<?php

function parseBookingGuestCount($value): ?int
{
    if (!is_string($value) || !preg_match('/\A(?:[1-9][0-9]{0,4}|100000)\z/', $value)) {
        return null;
    }

    return (int) $value;
}

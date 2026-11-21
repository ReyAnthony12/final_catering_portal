<?php

if (!function_exists('appLogError')) {
    function appLogError($context, $details = '') {
        $message = trim($context . ($details !== '' ? ': ' . $details : ''));
        error_log($message);
    }
}

if (!function_exists('appSafeMessage')) {
    function appSafeMessage($message) {
        return $message;
    }
}

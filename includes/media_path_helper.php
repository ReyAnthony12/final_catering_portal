<?php
/**
 * Normalize database media paths and generate URLs that refresh when a file is replaced.
 * Only local uploads/ paths are accepted so database values cannot become arbitrary URLs.
 */
if (!function_exists('mediaUploadPath')) {
    function mediaUploadPath($path, string $fallback = 'uploads/logo.png'): string
    {
        $path = preg_replace('~/+~', '/', str_replace('\\', '/', trim((string) $path)));
        $fallback = preg_replace('~/+~', '/', str_replace('\\', '/', trim($fallback)));
        $valid = static function (string $value): bool {
            return strpos($value, 'uploads/') === 0
                && strpos($value, '..') === false
                && !preg_match('~[\x00-\x1F]~', $value);
        };

        if (!$valid($path)) {
            $path = $fallback;
        }
        $root = dirname(__DIR__);
        if (!$valid($path) || !is_file($root . '/' . $path)) {
            $path = $fallback;
        }
        return $path;
    }
}

if (!function_exists('mediaUploadUrl')) {
    function mediaUploadUrl($path, string $prefix = '', string $fallback = 'uploads/logo.png'): string
    {
        $path = mediaUploadPath($path, $fallback);
        $file = dirname(__DIR__) . '/' . $path;
        $version = is_file($file) ? (string) filemtime($file) : (string) time();
        return $prefix . $path . '?v=' . rawurlencode($version);
    }
}

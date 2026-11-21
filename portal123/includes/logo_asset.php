<?php

if (!function_exists('portalLogoUrl')) {
    function portalLogoUrl($relativePath): string
    {
        $relativePath = trim(str_replace('\\', '/', (string) $relativePath));
        $relativePath = ltrim($relativePath, '/');

        if ($relativePath === '' || in_array('..', explode('/', $relativePath), true)) {
            $relativePath = 'uploads/logo.png';
        }

        $url = '../' . $relativePath;
        $diskPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);

        if (is_file($diskPath)) {
            $modifiedAt = @filemtime($diskPath);
            if ($modifiedAt !== false) {
                $url .= '?v=' . rawurlencode((string) $modifiedAt);
            }
        }

        return $url;
    }
}

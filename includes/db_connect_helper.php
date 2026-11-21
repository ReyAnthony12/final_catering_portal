<?php

if (!function_exists('columnExists')) {
    // Shared by login and password recovery; match column names exactly.
    function columnExists($conn, $table, $column) {
        $statement = mysqli_prepare($conn,
            'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1'
        );
        if (!$statement) {
            return false;
        }
        mysqli_stmt_bind_param($statement, 'ss', $table, $column);
        mysqli_stmt_execute($statement);
        mysqli_stmt_store_result($statement);
        $exists = mysqli_stmt_num_rows($statement) > 0;
        mysqli_stmt_close($statement);
        return $exists;
    }
}

if (!function_exists('cateringPortalMysqlHosts')) {
    function cateringPortalMysqlHosts(string $host): array
    {
        $host = trim($host);
        if ($host === '') {
            $host = '127.0.0.1';
        }

        // On Windows, 'localhost' triggers a slow DNS/pipe lookup.
        // When 127.0.0.1 is configured, skip adding 'localhost' as fallback.
        // Only add a fallback when 'localhost' was explicitly configured.
        $normalized = strtolower($host);
        if ($normalized === 'localhost') {
            return ['localhost', '127.0.0.1'];
        }

        // Already an IP address — no DNS fallback needed.
        return [$host];
    }
}

if (!function_exists('cateringPortalMysqlPorts')) {
    function cateringPortalMysqlPorts(int $configuredPort = 0): array
    {
        if ($configuredPort > 0) {
            // Configured port takes priority; only fall back to common ports
            // if the configured one fails.
            $fallbacks = [];
            foreach ([3306, 3307, 3308, 33060] as $port) {
                if ($port !== $configuredPort) {
                    $fallbacks[] = $port;
                }
            }
            return array_merge([$configuredPort], $fallbacks);
        }

        return [3306, 3307, 3308, 33060];
    }
}

if (!function_exists('cateringPortalConnectServer')) {
    /**
     * Connect to MySQL/MariaDB.
     * When DB_HOST=127.0.0.1 and DB_PORT are set in .env, connects instantly
     * on the first try without DNS resolution or port scanning overhead.
     */
    function cateringPortalConnectServer(
        string $host,
        string $user,
        string $pass,
        int $configuredPort = 0,
        ?int &$usedPort = null,
        ?string &$usedHost = null
    ): mysqli {
        $lastError = 'Could not connect to MySQL.';
        $attempts = [];

        $previousReport = mysqli_report(MYSQLI_REPORT_OFF);
        try {
            foreach (cateringPortalMysqlHosts($host) as $tryHost) {
                foreach (cateringPortalMysqlPorts($configuredPort) as $port) {
                    $attempts[] = $tryHost . ':' . $port;
                    try {
                        $link = @mysqli_connect($tryHost, $user, $pass, '', $port);
                    } catch (Throwable $e) {
                        $lastError = $e->getMessage();
                        continue;
                    }

                    if ($link instanceof mysqli) {
                        $usedPort = $port;
                        $usedHost = $tryHost;
                        return $link;
                    }
                    $lastError = mysqli_connect_error() ?: $lastError;
                }
            }
        } finally {
            mysqli_report($previousReport);
        }

        throw new Exception(
            'Could not connect to MySQL. Tried ' . implode(', ', $attempts) . '. Last error: ' . $lastError
        );
    }
}

if (!function_exists('cateringPortalSelectDatabase')) {
    function cateringPortalSelectDatabase(mysqli $conn, string $dbName, bool $createIfMissing = false): void
    {
        $dbName = trim($dbName);
        if ($dbName === '') {
            throw new Exception('Database name is empty.');
        }

        if (@mysqli_select_db($conn, $dbName)) {
            return;
        }

        if ($createIfMissing) {
            $safeName = str_replace('`', '``', $dbName);
            @mysqli_query($conn, "CREATE DATABASE IF NOT EXISTS `$safeName` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
            if (@mysqli_select_db($conn, $dbName)) {
                return;
            }
        }

        throw new Exception(
            "Connected to MySQL, but database `$dbName` was not found. Import it in phpMyAdmin, then refresh this page."
        );
    }
}

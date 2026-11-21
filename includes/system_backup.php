<?php
// Private signed backups: SQL is accepted only when produced by this installation.
function portalBackupDirectory(): string {
    $root = dirname(__DIR__);
    $directory = dirname($root, 2) . '/private-portal-backups/' . substr(hash('sha256', $root), 0, 16);
    if (!is_dir($directory) && !mkdir($directory, 0700, true)) throw new RuntimeException('Cannot create private backup storage.');
    return $directory;
}
function portalBackupKey(): string {
    $path = portalBackupDirectory() . '/recovery.key';
    if (!is_file($path)) {
        $handle = @fopen($path, 'x');
        if ($handle) { fwrite($handle, random_bytes(32)); fclose($handle); chmod($path, 0600); }
    }
    $key = file_get_contents($path);
    if ($key === false || strlen($key) !== 32) throw new RuntimeException('Recovery key unavailable.');
    return $key;
}
function portalBackupCommand(bool $dump, string $sql, ?string $database = null): void {
    $binary = dirname(dirname(__DIR__), 2) . '/mysql/bin/' . ($dump ? 'mysqldump.exe' : 'mysql.exe');
    if (!is_file($binary)) throw new RuntimeException('MySQL backup tools are unavailable on this server.');
    $command = [$binary, '--host=' . DB_HOST, '--port=' . (DB_PORT ?: 3306), '--user=' . DB_USER, '--default-character-set=utf8mb4'];
    if ($dump) $command = array_merge($command, ['--single-transaction', '--quick', '--hex-blob', '--routines', '--triggers', '--events', '--add-drop-table']);
    $command[] = $database ?? DB_NAME;
    $error = $sql . '.error';
    $descriptors = $dump
        ? [0 => ['pipe', 'r'], 1 => ['file', $sql, 'w'], 2 => ['file', $error, 'w']]
        : [0 => ['file', $sql, 'r'], 1 => ['pipe', 'w'], 2 => ['file', $error, 'w']];
    $environment = getenv();
    $environment['MYSQL_PWD'] = DB_PASS;
    $process = proc_open($command, $descriptors, $pipes, null, $environment, ['bypass_shell' => true]);
    if (!is_resource($process)) throw new RuntimeException('Unable to start the database backup tool.');
    foreach ($pipes as $pipe) { if (!$dump) stream_get_contents($pipe); fclose($pipe); }
    $status = proc_close($process);
    @unlink($error);
    if ($status !== 0) throw new RuntimeException($dump ? 'Database backup failed.' : 'Database restore failed.');
}
function portalCreateBackup(): string {
    $directory = portalBackupDirectory();
    $base = $directory . '/backup-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(6));
    $sql = $base . '.sql';
    $archive = $base . '.zip';
    $zip = new ZipArchive();
    try {
        portalBackupCommand(true, $sql);
        if ($zip->open($archive, ZipArchive::CREATE | ZipArchive::EXCL) !== true) throw new RuntimeException('Cannot create backup archive.');
        $files = ['database.sql' => hash_file('sha256', $sql)];
        $zip->addFile($sql, 'database.sql');
        $uploads = dirname(__DIR__) . '/uploads';
        if (is_dir($uploads)) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($uploads, FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if (!$file->isFile() || $file->isLink()) continue;
                $name = 'uploads/' . str_replace('\\', '/', substr($file->getPathname(), strlen($uploads) + 1));
                $files[$name] = hash_file('sha256', $file->getPathname());
                $zip->addFile($file->getPathname(), $name);
            }
        }
        $root = dirname(__DIR__);
        $sourceRoots = ['api', 'assets', 'caterer', 'customer', 'includes', 'portal123'];
        $sources = [];
        foreach (new DirectoryIterator($root) as $file) {
            if ($file->isFile() && !$file->isLink() && (in_array(strtolower($file->getExtension()), ['php', 'html', 'css', 'js'], true) || $file->getFilename() === '.htaccess')) $sources[] = $file->getPathname();
        }
        foreach ($sourceRoots as $folder) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $folder, FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file->isFile() && !$file->isLink() && !str_ends_with($file->getFilename(), '.restoring')) $sources[] = $file->getPathname();
            }
        }
        foreach ($sources as $source) {
            $name = 'application/' . str_replace('\\', '/', substr($source, strlen($root) + 1));
            $files[$name] = hash_file('sha256', $source);
            $zip->addFile($source, $name);
        }
        $manifest = json_encode(['format' => 'catering-portal-backup-v2', 'created_at' => gmdate('c'), 'files' => $files], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $zip->addFromString('manifest.json', $manifest);
        $zip->addFromString('signature.txt', hash_hmac('sha256', $manifest, portalBackupKey()));
        if (!$zip->close()) throw new RuntimeException('Cannot finish backup archive.');
        return $archive;
    } catch (Throwable $error) {
        @$zip->close(); @unlink($archive); throw $error;
    } finally { @unlink($sql); }
}
function portalValidateBackup(string $archive): array {
    $zip = new ZipArchive();
    if ($zip->open($archive) !== true) throw new RuntimeException('Choose a valid system backup ZIP.');
    try {
        $manifest = $zip->getFromName('manifest.json');
        $signature = $zip->getFromName('signature.txt');
        if (!is_string($manifest) || !is_string($signature) || !hash_equals(hash_hmac('sha256', $manifest, portalBackupKey()), $signature)) throw new RuntimeException('This backup is damaged or belongs to another installation.');
        $data = json_decode($manifest, true, 512, JSON_THROW_ON_ERROR);
        if (!in_array($data['format'] ?? '', ['catering-portal-backup-v1', 'catering-portal-backup-v2'], true) || empty($data['files']['database.sql'])) throw new RuntimeException('Unsupported backup format.');
        $total = 0;
        foreach ($data['files'] as $name => $hash) {
            if ($name !== 'database.sql' && ((!str_starts_with($name, 'uploads/') && !str_starts_with($name, 'application/')) || preg_match('~(^|/)(\.|\.\.)(/|$)|[\\\\:\x00]~', $name))) throw new RuntimeException('Unsafe backup path.');
            if (str_starts_with($name, 'application/')) {
                $relative = substr($name, strlen('application/'));
                $folder = explode('/', $relative)[0];
                if (str_contains($relative, '/') ? !in_array($folder, ['api', 'assets', 'caterer', 'customer', 'includes', 'portal123'], true) : (!in_array(strtolower(pathinfo($relative, PATHINFO_EXTENSION)), ['php', 'html', 'css', 'js'], true) && $relative !== '.htaccess')) throw new RuntimeException('Unsupported application file.');
            }
            $stat = $zip->statName($name);
            if (!$stat || ($total += $stat['size']) > 8 * 1024 * 1024 * 1024) throw new RuntimeException('Backup is missing files or exceeds the 8 GB restore limit.');
            $stream = $zip->getStream($name);
            if (!$stream) throw new RuntimeException('Cannot read backup file.');
            $context = hash_init('sha256'); hash_update_stream($context, $stream); fclose($stream);
            if (!hash_equals($hash, hash_final($context))) throw new RuntimeException('Backup file verification failed.');
        }
        return $data;
    } finally { $zip->close(); }
}
function portalApplyBackup(string $archive, array $manifest, ?string $targetRoot = null, ?string $database = null): void {
    $zip = new ZipArchive();
    if ($zip->open($archive) !== true) throw new RuntimeException('Cannot open backup.');
    $sql = portalBackupDirectory() . '/restore-' . bin2hex(random_bytes(8)) . '.sql';
    try {
        // Never extract an archive directly into the application directory.
        foreach ($manifest['files'] as $name => $hash) {
            $root = $targetRoot ?? dirname(__DIR__);
            $relative = str_starts_with($name, 'application/') ? substr($name, strlen('application/')) : $name;
            $destination = $name === 'database.sql' ? $sql : $root . '/' . $relative;
            if (!is_dir(dirname($destination)) && !mkdir(dirname($destination), 0750, true)) throw new RuntimeException('Cannot restore upload directory.');
            $parent = realpath(dirname($destination));
            $allowedRoot = realpath(str_starts_with($name, 'application/') ? $root : $root . '/uploads');
            if ($name !== 'database.sql' && (!$allowedRoot || ($parent !== $allowedRoot && !str_starts_with($parent, $allowedRoot . DIRECTORY_SEPARATOR)) || is_link($destination))) throw new RuntimeException('Unsafe restore destination.');
            $input = $zip->getStream($name);
            $output = fopen($destination . '.restoring', 'wb');
            if (!$input || !$output) throw new RuntimeException('Cannot write restored file.');
            $written = stream_copy_to_stream($input, $output); fclose($input); fclose($output);
            if ($written === false || !hash_equals($hash, hash_file('sha256', $destination . '.restoring')) || !rename($destination . '.restoring', $destination)) throw new RuntimeException('Cannot finish restoring file.');
            if (str_starts_with($name, 'application/') && function_exists('opcache_invalidate')) opcache_invalidate($destination, true);
        }
        portalBackupCommand(false, $sql, $database);
    } finally { $zip->close(); @unlink($sql); }
}

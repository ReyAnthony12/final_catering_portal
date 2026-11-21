<?php
require_once __DIR__ . '/../config.php';
requireLogin();
if (getUserRole() !== 'portal') { http_response_code(403); exit('Access denied.'); }
require_once __DIR__ . '/../includes/system_backup.php';
set_time_limit(0);
$error = '';
$success = '';
$preview = null;
$csrf_token = generateCSRFToken();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!validateCSRFToken($_POST['csrf_token'] ?? '')) throw new RuntimeException('Your session expired. Refresh and try again.');
        $action = $_POST['action'] ?? '';
        if (in_array($action, ['upload_start', 'upload_chunk', 'upload_finish'], true)) {
            header('Content-Type: application/json');
            header('Cache-Control: no-store');
            if ($action === 'upload_start') {
                $size = (int) ($_POST['size'] ?? 0);
                if ($size < 1 || $size > 2 * 1024 * 1024 * 1024) throw new RuntimeException('Choose a backup smaller than 2 GB.');
                $previous = $_SESSION['portal_chunk_upload']['path'] ?? '';
                if ($previous && dirname($previous) === portalBackupDirectory()) @unlink($previous);
                $path = portalBackupDirectory() . '/upload-' . bin2hex(random_bytes(16)) . '.zip';
                if (file_put_contents($path, '') === false) throw new RuntimeException('Cannot prepare upload.');
                $_SESSION['portal_chunk_upload'] = ['path' => $path, 'size' => $size, 'offset' => 0, 'expires' => time() + 3600];
            } else {
                $upload = $_SESSION['portal_chunk_upload'] ?? [];
                if (empty($upload['path']) || ($upload['expires'] ?? 0) < time()) throw new RuntimeException('Upload expired. Choose the file again.');
                if ($action === 'upload_chunk') {
                    $part = $_FILES['chunk'] ?? [];
                    if (($part['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($part['tmp_name'])) throw new RuntimeException('Upload interrupted. Please try again.');
                    $length = filesize($part['tmp_name']);
                    if ((int) ($_POST['offset'] ?? -1) !== $upload['offset'] || $length < 1 || $length > 4 * 1024 * 1024 || $upload['offset'] + $length > $upload['size']) throw new RuntimeException('Invalid upload part. Start the upload again.');
                    $input = fopen($part['tmp_name'], 'rb'); $output = fopen($upload['path'], 'ab');
                    if (!$input || !$output) throw new RuntimeException('Cannot save upload.');
                    $written = stream_copy_to_stream($input, $output); fclose($input); fclose($output);
                    if ($written !== $length) throw new RuntimeException('Incomplete upload. Try again.');
                    $_SESSION['portal_chunk_upload']['offset'] += $length;
                } else {
                    if ($upload['offset'] !== $upload['size']) throw new RuntimeException('The backup upload is incomplete.');
                    portalValidateBackup($upload['path']);
                    $previous = $_SESSION['portal_restore']['path'] ?? '';
                    if ($previous && dirname($previous) === portalBackupDirectory()) @unlink($previous);
                    $_SESSION['portal_restore'] = ['path' => $upload['path'], 'expires' => time() + 1800];
                    unset($_SESSION['portal_chunk_upload']);
                }
            }
            echo json_encode(['success' => true]); exit;
        }
        if ($action === 'download' || $action === 'safety') {
            $path = $action === 'download' ? portalCreateBackup() : ($_SESSION['portal_safety_backup'] ?? '');
            if (!$path || !is_file($path) || dirname($path) !== portalBackupDirectory()) throw new RuntimeException('No safety backup is available in this session.');
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="catering-portal-' . basename($path) . '"');
            header('Cache-Control: no-store, private');
            header('Content-Length: ' . filesize($path));
            readfile($path); exit;
        }
        if ($action === 'validate') {
            $upload = $_FILES['backup'] ?? [];
            if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new RuntimeException('Upload failed. Choose a ZIP within the server upload limit (' . ini_get('upload_max_filesize') . ').');
            $manifest = portalValidateBackup($upload['tmp_name']);
            $path = portalBackupDirectory() . '/pending-' . bin2hex(random_bytes(16)) . '.zip';
            if (!move_uploaded_file($upload['tmp_name'], $path)) throw new RuntimeException('Cannot stage the backup.');
            $old = $_SESSION['portal_restore']['path'] ?? '';
            if ($old && dirname($old) === portalBackupDirectory()) @unlink($old);
            $_SESSION['portal_restore'] = ['path' => $path, 'expires' => time() + 1800];
            $preview = $manifest;
        } elseif ($action === 'restore') {
            $pending = $_SESSION['portal_restore'] ?? [];
            if (empty($pending['path']) || ($pending['expires'] ?? 0) < time() || ($_POST['confirm_restore'] ?? '') !== 'yes') throw new RuntimeException('Validate the backup and confirm restore first.');
            $manifest = portalValidateBackup($pending['path']);
            // Keep a verified, private recovery point before changing any data.
            $safety = portalCreateBackup();
            $safetyManifest = portalValidateBackup($safety);
            $_SESSION['portal_safety_backup'] = $safety;
            try { portalApplyBackup($pending['path'], $manifest); }
            catch (Throwable $restoreError) {
                try { portalApplyBackup($safety, $safetyManifest); }
                catch (Throwable $rollbackError) { throw new RuntimeException('Restore and automatic recovery failed. Keep the safety backup and contact the administrator.'); }
                throw new RuntimeException('Restore failed. The previous database and files were recovered from the safety backup.');
            }
            @unlink($pending['path']); unset($_SESSION['portal_restore']);
            $success = 'Backup restored successfully. Sign out and sign in again if the restored accounts differ.';
        }
    } catch (Throwable $failure) {
        if (in_array($_POST['action'] ?? '', ['upload_start', 'upload_chunk', 'upload_finish'], true)) {
            http_response_code(400); header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => $failure->getMessage()]); exit;
        }
        $error = $failure->getMessage();
    }
}
if (isset($_GET['review']) && !$preview && !$error) {
    try {
        $pending = $_SESSION['portal_restore'] ?? [];
        if (empty($pending['path']) || ($pending['expires'] ?? 0) < time()) throw new RuntimeException('Upload expired. Choose the backup again.');
        $preview = portalValidateBackup($pending['path']);
    } catch (Throwable $failure) { $error = $failure->getMessage(); }
}
$page_title = 'System Backup';
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/sidebar.php';
?>
<main class="main-content backup-page">
    <a class="btn btn-outline" href="reports.php">&larr; Back to Reports</a>
    <h1 style="margin:16px 0 8px">System Backup &amp; Restore</h1>
    <?php if ($error): ?><div class="alert alert-error" role="alert" style="padding:12px;margin-bottom:12px"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success" role="status" style="padding:12px;margin-bottom:12px"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>
    <div class="grid-2" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,300px),1fr));gap:12px">
        <section class="content-body">
            <h2>Create backup</h2>
            <form method="post"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>"><button class="btn btn-primary" name="action" value="download">Download System Backup</button></form>
        </section>
        <section class="content-body">
            <h2>Import backup</h2>
            <form method="post" enctype="multipart/form-data" id="backupImportForm">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <input class="form-control" type="file" name="backup" accept=".zip,application/zip" required aria-label="System backup ZIP">
                <button class="btn btn-primary" name="action" value="validate" style="margin-top:12px">Check Backup</button>
            </form>
            <p id="backupUploadStatus" role="status" hidden style="margin-top:10px;font-size:12px"></p>
        </section>
    </div>
    <?php if ($preview): ?>
    <section class="content-body" style="margin-top:12px">
        <h2>Backup verified</h2>
        <p style="margin:10px 0">Created <?php echo htmlspecialchars($preview['created_at']); ?> &middot; <?php echo count($preview['files']) - 1; ?> website files, plus the complete database.</p>
        <p style="margin-bottom:12px">Restore replaces current website files and database records. A safety backup is created first.</p>
        <form method="post"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>"><label style="display:flex;gap:8px;align-items:start;margin-bottom:12px"><input type="checkbox" name="confirm_restore" value="yes" required> I understand that current website files and records will be replaced by this backup.</label><button class="btn btn-danger" name="action" value="restore">Restore This Backup</button></form>
    </section>
    <?php endif; ?>
    <?php if (!empty($_SESSION['portal_safety_backup'])): ?><form method="post" style="margin-top:12px"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>"><button class="btn btn-outline" name="action" value="safety">Download Pre-restore Safety Backup</button></form><?php endif; ?>
</main>
<script src="backup-upload.js?v=<?php echo filemtime(__DIR__ . '/backup-upload.js'); ?>"></script>
<?php require __DIR__ . '/includes/footer.php'; ?>

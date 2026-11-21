<?php
require_once __DIR__ . '/../config.php';
/** @var mysqli $conn */
requireLogin();

if (getUserRole() !== 'portal') {
    header('Location: ../login.php');
    exit();
}

$page_title = 'User Appeals - Portal Dashboard';
$current_page = 'user-reports.php';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        $error = "Invalid security token.";
    } else {
        $report_id = intval($_POST['report_id']);
        $action = $_POST['action'];
        
        $report_query = mysqli_query($conn, "SELECT * FROM user_reports WHERE id = $report_id AND status = 'pending'");
        if ($report_query && mysqli_num_rows($report_query) > 0) {
            $report = mysqli_fetch_assoc($report_query);
            
            if ($action === 'approve') {
                // Deactivate the reported user
                $reported_user_id = $report['reported_user_id'];
                mysqli_query($conn, "UPDATE users SET status = 'inactive' WHERE id = '$reported_user_id'");
                
                // Mark report as resolved
                mysqli_query($conn, "UPDATE user_reports SET status = 'resolved', resolved_at = NOW() WHERE id = $report_id");
                
                $success = "User has been deactivated and report resolved.";
            } elseif ($action === 'reject') {
                // Dismiss report
                mysqli_query($conn, "UPDATE user_reports SET status = 'rejected', resolved_at = NOW() WHERE id = $report_id");
                
                $success = "Report request has been dismissed.";
            }
        } else {
            $error = "Report not found or already resolved.";
        }
    }
}

// Fetch pending reports
$reports_query = "
    SELECT r.*, 
           reporter.business_name as store_name,
           reported.first_name, reported.last_name, reported.email, reported.role, reported.profile_image, reported.status as user_status,
           o.order_number
    FROM user_reports r
    LEFT JOIN users reported ON r.reported_user_id = reported.id
    LEFT JOIN store_details reporter ON r.reporter_id = reporter.user_id
    LEFT JOIN bookings o ON r.order_id = o.id
    WHERE r.status = 'pending'
    ORDER BY r.created_at DESC
";
$reports_result = mysqli_query($conn, $reports_query);

$extra_head = '
    <style>
        /* ── Summary Bar ── */
        .ur-summary-bar {
            display: flex;
            align-items: center;
            gap: 16px;
            margin-bottom: 24px;
            flex-wrap: wrap;
        }
        .ur-summary-pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 18px;
            border-radius: 999px;
            font-size: 13px;
            font-weight: 600;
            border: 1px solid;
        }
        .ur-pill-pending {
            background: rgba(255, 152, 0, 0.12);
            color: #ff9800;
            border-color: rgba(255, 152, 0, 0.3);
        }

        /* ── Report Card ── */
        .report-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 14px;
            margin-bottom: 12px;
            overflow: hidden;
            transition: box-shadow 0.25s ease, border-color 0.25s ease;
        }
        .report-card:hover {
            box-shadow: 0 6px 24px rgba(0,0,0,0.3);
        }
        .report-card.is-open {
            border-color: rgba(244, 67, 54, 0.35);
            box-shadow: 0 8px 28px rgba(0,0,0,0.35);
        }

        /* Card top accent strip */
        .report-card-accent {
            height: 4px;
            background: linear-gradient(90deg, #f44336, #ff6b35);
        }

        /* ── Clickable Header (always visible) ── */
        .report-card-header {
            padding: 16px 22px;
            cursor: pointer;
            user-select: none;
            display: flex;
            align-items: center;
            gap: 14px;
            transition: background 0.2s ease;
        }
        .report-card-header:hover {
            background: rgba(255,255,255,0.025);
        }

        /* Avatar initials */
        .report-avatar {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            background: linear-gradient(135deg, #f44336, #c62828);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            font-weight: 700;
            color: #fff;
            flex-shrink: 0;
            letter-spacing: 0.5px;
        }
        .report-header-info {
            flex: 1;
            min-width: 0;
        }
        .report-name {
            font-size: 16px;
            font-weight: 700;
            color: var(--text-main);
            margin: 0 0 5px;
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        .report-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 3px 10px;
            border-radius: 999px;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.4px;
        }
        .badge-pending {
            background: rgba(255, 152, 0, 0.15);
            color: #ff9800;
            border: 1px solid rgba(255, 152, 0, 0.3);
        }
        .report-meta-row {
            display: flex;
            flex-wrap: wrap;
            gap: 4px 18px;
        }
        .report-meta-item {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 13px;
            color: var(--text-muted);
        }
        .report-meta-item i { font-size: 11px; opacity: 0.7; }
        .report-meta-item strong { color: var(--text-main); font-weight: 600; }

        /* Chevron toggle indicator */
        .report-chevron {
            flex-shrink: 0;
            width: 28px;
            height: 28px;
            border-radius: 8px;
            background: rgba(255,255,255,0.04);
            border: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--text-muted);
            font-size: 13px;
            transition: transform 0.3s ease, background 0.2s ease, color 0.2s ease, border-color 0.2s ease;
        }
        .report-card.is-open .report-chevron {
            transform: rotate(180deg);
            background: rgba(244, 67, 54, 0.1);
            border-color: rgba(244, 67, 54, 0.3);
            color: #f44336;
        }

        /* ── Collapsible Body ── */
        .report-card-body {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.38s ease;
            padding: 0 22px;
        }
        .report-card.is-open .report-card-body {
            max-height: 900px;
            padding: 0 22px 22px;
        }

        /* Divider */
        .report-divider {
            border: none;
            border-top: 1px solid var(--border-color);
            margin: 0 0 16px;
        }

        /* ── Info Grid ── */
        .report-info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(175px, 1fr));
            gap: 10px;
            margin-bottom: 16px;
        }
        .report-info-cell {
            background: rgba(255,255,255,0.03);
            border: 1px solid var(--border-color);
            border-radius: 10px;
            padding: 11px 14px;
        }
        .report-info-cell-label {
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            color: var(--text-muted);
            font-weight: 600;
            margin-bottom: 5px;
        }
        .report-info-cell-value {
            font-size: 14px;
            font-weight: 600;
            color: var(--text-main);
        }

        /* ── Reason Box ── */
        .report-reason-box {
            background: rgba(244, 67, 54, 0.06);
            border: 1px solid rgba(244, 67, 54, 0.22);
            border-left: 4px solid #f44336;
            border-radius: 0 10px 10px 0;
            padding: 14px 18px;
            margin-bottom: 20px;
        }
        .report-reason-label {
            display: flex;
            align-items: center;
            gap: 7px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #f44336;
            margin-bottom: 8px;
        }
        .report-reason-text {
            font-size: 14px;
            line-height: 1.65;
            color: var(--text-main);
        }

        /* ── Action Row ── */
        .report-action-row {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }
        .btn-deactivate {
            background: linear-gradient(135deg, #f44336, #c62828);
            color: #fff;
            border: none;
            padding: 10px 22px;
            border-radius: 9px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: filter 0.2s ease, transform 0.15s ease, box-shadow 0.2s ease;
            box-shadow: 0 4px 14px rgba(244, 67, 54, 0.3);
        }
        .btn-deactivate:hover {
            filter: brightness(1.08);
            transform: translateY(-1px);
            box-shadow: 0 6px 20px rgba(244, 67, 54, 0.4);
        }
        .btn-dismiss {
            background: transparent;
            color: var(--text-muted);
            border: 1px solid var(--border-color);
            padding: 10px 22px;
            border-radius: 9px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s ease;
        }
        .btn-dismiss:hover {
            background: rgba(255,255,255,0.05);
            border-color: var(--text-muted);
            color: var(--text-main);
        }
        .report-action-note {
            margin-left: auto;
            font-size: 11px;
            color: var(--text-muted);
            display: flex;
            align-items: center;
            gap: 5px;
        }

        /* ── Empty State ── */
        .ur-empty-state {
            text-align: center;
            padding: 70px 20px;
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 14px;
        }
        .ur-empty-icon {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: rgba(76, 175, 80, 0.1);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
        }
        .ur-empty-icon i { font-size: 36px; color: var(--success-color); }
        .ur-empty-state h3 { font-size: 20px; color: var(--text-main); margin-bottom: 8px; }
        .ur-empty-state p  { font-size: 14px; color: var(--text-muted); }

        /* ── Alert Banners ── */
        .ur-alert {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px 20px;
            border-radius: 12px;
            border: 1px solid;
            margin-bottom: 22px;
            font-size: 14px;
            font-weight: 500;
        }
        .ur-alert-success { background: rgba(76, 175, 80, 0.08); border-color: rgba(76, 175, 80, 0.3); color: #4caf50; }
        .ur-alert-error   { background: rgba(244, 67, 54, 0.08); border-color: rgba(244, 67, 54, 0.3); color: #f44336; }
        .ur-alert i { font-size: 18px; flex-shrink: 0; }
        .ur-alert span { color: var(--text-main); }

        .admin-workspace .user-reports-page { min-width: 0; }
        .admin-workspace .user-reports-page .ur-summary-bar { margin-bottom: 20px; }
        .admin-workspace .user-reports-page .report-card { margin-bottom: 20px; background: #171b20; box-shadow: 0 8px 24px #00000018; }
        .admin-workspace .user-reports-page .report-card.is-open { border-color: #424851; box-shadow: 0 8px 24px #00000020; }
        .admin-workspace .user-reports-page .report-card-accent { display: none; }
        .admin-workspace .user-reports-page .report-card.is-open .report-card-header { background: #1c2128; border-bottom: 1px solid var(--border-color); }
        .admin-workspace .user-reports-page .report-divider { display: none; }
        .admin-workspace .user-reports-page .report-info-grid { margin: 20px 0; gap: 12px; }
        .admin-workspace .user-reports-page .report-info-cell { padding: 14px 16px; background: #1e232a; border-radius: 10px; }
        .admin-workspace .user-reports-page .report-info-cell-label { font-size: 11px; line-height: 1.5; letter-spacing: .35px; margin-bottom: 6px; }
        .admin-workspace .user-reports-page .report-info-cell-value { font-size: 14px; line-height: 1.6; }
        .admin-workspace .user-reports-page .report-reason-box { padding: 18px; border: 1px solid #f4433626; border-left: 3px solid #e86b62; border-radius: 10px; background: #e86b6208; margin-bottom: 20px; }
        .admin-workspace .user-reports-page .report-reason-label { color: #ef9a93; font-size: 12px; text-transform: none; letter-spacing: 0; line-height: 1.5; }
        .admin-workspace .user-reports-page .report-reason-text { font-size: 14px; line-height: 1.8; }
        .admin-workspace .user-reports-page .report-action-row { gap: 10px; padding-top: 18px; border-top: 1px solid var(--border-color); }
        .admin-workspace .user-reports-page .btn-deactivate { background: #c93632; box-shadow: none; padding: 12px 18px; line-height: 1.5; }
        .admin-workspace .user-reports-page .btn-deactivate:hover { background: #db423d; box-shadow: none; transform: none; }
        .admin-workspace .user-reports-page .btn-dismiss { background: #20252c; padding: 12px 18px; color: #d1d5dc; line-height: 1.5; }
        .admin-workspace .user-reports-page .report-card.is-open .report-chevron { color: #e2bf63; background: #e2bf630c; border-color: #e2bf632e; }
        .admin-workspace .user-reports-page .report-card-header { padding: 22px 24px; gap: 16px; min-height: 104px; }
        .admin-workspace .user-reports-page .report-card-header:focus-visible { outline: 2px solid #e2bf63; outline-offset: -4px; border-radius: 14px; }
        .admin-workspace .user-reports-page .report-avatar { position: relative; overflow: hidden; width: 52px; height: 52px; border-radius: 50%; }
        .admin-workspace .user-reports-page .report-avatar img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; }
        .admin-workspace .user-reports-page .report-name { display: block; font-size: 17px; line-height: 1.4; margin: 0; }
        .admin-workspace .user-reports-page .report-badge { display: table; margin-top: 7px; font-size: 10px; line-height: 1.4; }
        .admin-workspace .user-reports-page .report-chevron { width: 40px; height: 40px; }
        .admin-workspace .user-reports-page .report-card-body { padding-left: 24px; padding-right: 24px; }
        .admin-workspace .user-reports-page .report-card.is-open .report-card-body { padding: 0 24px 24px; }
        .admin-workspace .user-reports-page .report-info-grid { grid-template-columns: repeat(auto-fit, minmax(min(100%, 200px), 1fr)); }
        .admin-workspace .user-reports-page .report-info-cell { min-width: 0; }
        .admin-workspace .user-reports-page .report-reason-text { overflow-wrap: anywhere; }
        .admin-workspace .user-reports-page .report-action-row button { min-height: 46px; white-space: normal; justify-content: center; }
        @media (max-width: 600px) {
            .admin-workspace .user-reports-page .report-card-header { padding: 18px 14px; gap: 12px; min-height: 96px; }
            .admin-workspace .user-reports-page .report-avatar { width: 46px; height: 46px; }
            .admin-workspace .user-reports-page .report-name { font-size: 15px; }
            .admin-workspace .user-reports-page .report-chevron { width: 32px; height: 32px; }
            .admin-workspace .user-reports-page .report-card-body,
            .admin-workspace .user-reports-page .report-card.is-open .report-card-body { padding-left: 14px; padding-right: 14px; }
            .admin-workspace .user-reports-page .report-info-grid { grid-template-columns: minmax(0, 1fr); }
            .admin-workspace .user-reports-page .report-reason-box { padding: 14px; }
            .report-card-header { padding: 14px 16px; gap: 12px; }
            .report-card.is-open .report-card-body { padding: 0 16px 18px; }
            .report-info-grid { grid-template-columns: 1fr 1fr; }
            .report-action-note { display: none; }
            .report-avatar { width: 42px; height: 42px; font-size: 16px; border-radius: 10px; }
        }
        /* Compact report layout across desktop and mobile. */
        .admin-workspace .user-reports-page .report-card { width: 100%; max-width: 560px; border-radius: 10px; margin-bottom: 10px; }
        .admin-workspace .user-reports-page .report-card-header { min-height: 60px; padding: 10px 12px; gap: 8px; }
        .admin-workspace .user-reports-page .report-avatar { width: 32px; height: 32px; font-size: 12px; }
        .admin-workspace .user-reports-page .report-name { font-size: 14px; }
        .admin-workspace .user-reports-page .report-badge { font-size: 9px; padding: 2px 8px; margin-top: 4px; }
        .admin-workspace .user-reports-page .report-chevron { width: 26px; height: 26px; font-size: 10px; }
        .admin-workspace .user-reports-page .report-card-body,
        .admin-workspace .user-reports-page .report-card.is-open .report-card-body { padding-left: 14px; padding-right: 14px; }
        .admin-workspace .user-reports-page .report-card.is-open .report-card-body { padding-bottom: 14px; }
        .admin-workspace .user-reports-page .report-info-grid { margin: 12px 0; gap: 8px; }
        .admin-workspace .user-reports-page .report-info-cell { padding: 9px 12px; border-radius: 8px; }
        .admin-workspace .user-reports-page .report-info-cell-label { font-size: 10px; margin-bottom: 3px; }
        .admin-workspace .user-reports-page .report-info-cell-value { font-size: 13px; line-height: 1.5; }
        .admin-workspace .user-reports-page .report-reason-box { padding: 11px 12px; margin-bottom: 12px; border-radius: 8px; }
        .admin-workspace .user-reports-page .report-reason-label { font-size: 11px; margin-bottom: 4px; }
        .admin-workspace .user-reports-page .report-reason-text { font-size: 13px; line-height: 1.6; }
        .admin-workspace .user-reports-page .report-action-row { padding-top: 12px; gap: 8px; }
        .admin-workspace .user-reports-page .report-action-row button { font-size: 12px; padding: 9px 14px; min-height: 40px; border-radius: 8px; }
        @media (min-width: 993px) {
            .admin-workspace .user-reports-page { padding-top: 36px; }
            .admin-workspace .user-reports-page > .ur-summary-bar,
            .admin-workspace .user-reports-page > .report-card,
            .admin-workspace .user-reports-page > .ur-alert,
            .admin-workspace .user-reports-page > .ur-empty-state { width: 100%; max-width: 1040px; margin-left: auto; margin-right: auto; }
            .admin-workspace .user-reports-page .ur-summary-bar { margin-bottom: 16px; }
            .admin-workspace .user-reports-page .ur-summary-pill { font-size: 12px; padding: 7px 14px; }
            .admin-workspace .user-reports-page .report-card { border-radius: 14px; margin-bottom: 16px; }
            .admin-workspace .user-reports-page .report-card-header { min-height: 86px; padding: 18px 22px; gap: 14px; }
            .admin-workspace .user-reports-page .report-avatar { width: 44px; height: 44px; font-size: 15px; }
            .admin-workspace .user-reports-page .report-name { display: flex; align-items: center; gap: 14px; font-size: 16px; }
            .admin-workspace .user-reports-page .report-badge { display: inline-flex; margin-top: 0; font-size: 10px; padding: 4px 10px; }
            .admin-workspace .user-reports-page .report-chevron { width: 34px; height: 34px; font-size: 12px; }
            .admin-workspace .user-reports-page .report-card-body,
            .admin-workspace .user-reports-page .report-card.is-open .report-card-body { padding-left: 22px; padding-right: 22px; }
            .admin-workspace .user-reports-page .report-card.is-open .report-card-body { padding-bottom: 22px; }
            .admin-workspace .user-reports-page .report-info-grid { margin: 18px 0; gap: 12px; }
            .admin-workspace .user-reports-page .report-info-cell { padding: 12px 16px; }
            .admin-workspace .user-reports-page .report-info-cell-value { font-size: 14px; }
            .admin-workspace .user-reports-page .report-reason-box { padding: 16px; margin-bottom: 18px; }
            .admin-workspace .user-reports-page .report-reason-text { font-size: 14px; }
            .admin-workspace .user-reports-page .report-action-row { padding-top: 16px; gap: 12px; }
            .admin-workspace .user-reports-page .report-action-row button { font-size: 13px; padding: 10px 18px; min-height: 42px; }
        }
        .reports-table-wrap { width: 100%; overflow-x: auto; border: 1px solid var(--border-color); border-radius: 14px; background: var(--card-bg); }
        .reports-table { width: 100%; min-width: 760px; border-collapse: collapse; text-align: left; }
        .reports-table th { padding: 15px 20px; background: rgba(255,255,255,.025); color: var(--text-muted); font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; }
        .reports-table td { padding: 18px 20px; border-top: 1px solid var(--border-color); color: var(--text-main); font-size: 13px; vertical-align: middle; }
        .reports-table .report-user { display: flex; align-items: center; gap: 12px; }
        .admin-workspace .user-reports-page .reports-table .report-avatar { width: 40px; height: 40px; }
        .admin-workspace .user-reports-page .reports-table .report-name { font-size: 14px; }
        .admin-workspace .user-reports-page .reports-table .report-badge { display: inline-flex; margin: 0; white-space: nowrap; }
        .report-view-button { display: inline-flex; align-items: center; gap: 8px; padding: 9px 12px; background: #20252c; border: 1px solid var(--border-color); border-radius: 8px; color: var(--text-main); cursor: pointer; white-space: nowrap; font: inherit; }
        .report-view-button:hover { background: #2a3039; }
        .report-view-button:focus-visible { outline: 2px solid #e2bf63; outline-offset: 3px; }
        .report-view-button[aria-expanded="true"] i { transform: rotate(180deg); }
        .reports-table .report-details-row[hidden] { display: none; }
        .reports-table .report-details-row > td { padding: 0 20px 20px; }
        .admin-workspace .user-reports-page .reports-table .report-card-body { max-height: none; overflow: visible; padding: 0; }
        .admin-workspace .main-content.user-reports-page {
            display: flex;
            flex-direction: column;
            box-sizing: border-box;
            min-height: 100vh;
            min-height: 100dvh;
            padding: 24px;
        }
        .admin-workspace .user-reports-page > .reports-table-wrap {
            flex: 1;
            width: 100%;
            max-width: none;
            margin: 0;
        }
        .admin-workspace .user-reports-page .reports-table-wrap > .ur-summary-bar {
            padding: 18px 20px;
            margin: 0;
            border-bottom: 1px solid var(--border-color);
        }
        @media (max-width: 992px) {
            .admin-workspace .main-content.user-reports-page { padding: 84px 16px 16px; }
        }
        @media (max-width: 600px) {
            .admin-workspace .main-content.user-reports-page { padding: 78px 12px 12px; }
        }
        .report-mobile-toggle { display: none; }
        /* Phone layout only; desktop table stays unchanged. */
        @media (max-width: 600px) {
            .admin-workspace .user-reports-page .reports-table .report-mobile-toggle { display: flex; align-items: center; justify-content: space-between; gap: 12px; width: 100%; min-height: 44px; padding: 0; border: 0; background: transparent; color: var(--text-main); font: inherit; font-weight: 700; text-align: left; cursor: pointer; overflow-wrap: anywhere; }
            .admin-workspace .user-reports-page .report-mobile-toggle > .report-user { min-width: 0; }
            .report-mobile-toggle:focus-visible { outline: 2px solid #e2bf63; outline-offset: 4px; }
            .report-mobile-toggle[aria-expanded="true"] i { transform: rotate(180deg); }
            .admin-workspace .user-reports-page .reports-table td:first-child > .report-user,
            .admin-workspace .user-reports-page .reports-table td:first-child::before,
            .admin-workspace .user-reports-page .reports-table tbody > tr:not(.report-details-row) > td:last-child,
            .admin-workspace .user-reports-page .reports-table tbody > tr:not(.report-details-row):not(.is-expanded) > td:not(:first-child) { display: none; }
            .admin-workspace .user-reports-page .reports-table-wrap { overflow: hidden; }
            .admin-workspace .user-reports-page .reports-table,
            .admin-workspace .user-reports-page .reports-table tbody { display: block; width: 100%; min-width: 0; }
            .admin-workspace .user-reports-page .reports-table thead { position: absolute; width: 1px; height: 1px; overflow: hidden; clip-path: inset(50%); }
            .admin-workspace .user-reports-page .reports-table tbody > tr:not(.report-details-row) { display: grid; grid-template-columns: minmax(0, 1fr); gap: 16px; padding: 18px 16px; border-top: 1px solid var(--border-color); }
            .admin-workspace .user-reports-page .reports-table td { display: block; min-width: 0; padding: 0; border: 0; white-space: normal; overflow-wrap: anywhere; }
            .admin-workspace .user-reports-page .reports-table td[data-label]::before { content: attr(data-label); display: block; margin-bottom: 7px; color: var(--text-muted); font-size: 10px; font-weight: 700; text-transform: uppercase; }
            .admin-workspace .user-reports-page .reports-table .report-avatar { flex-shrink: 0; }
            .admin-workspace .user-reports-page .report-view-button { width: 100%; min-height: 44px; justify-content: center; white-space: normal; }
            .admin-workspace .user-reports-page .reports-table .report-details-row:not([hidden]) { display: block; padding: 0 16px 18px; }
            .admin-workspace .user-reports-page .reports-table .report-info-grid { grid-template-columns: minmax(0, 1fr); }
            .admin-workspace .user-reports-page .report-info-cell,
            .admin-workspace .user-reports-page .report-reason-box { min-width: 0; overflow-wrap: anywhere; }
            .admin-workspace .user-reports-page .report-action-row { flex-direction: column; align-items: stretch; }
            .admin-workspace .user-reports-page .report-action-row button { width: 100%; min-height: 44px; white-space: normal; }
        }
    </style>
';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
?>

    <div class="main-content user-reports-page">
        <?php if (isset($success)): ?>
            <div class="ur-alert ur-alert-success">
                <i class="fas fa-check-circle"></i>
                <span><?php echo htmlspecialchars($success); ?></span>
            </div>
        <?php endif; ?>

        <?php if (isset($error)): ?>
            <div class="ur-alert ur-alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <span><?php echo htmlspecialchars($error); ?></span>
            </div>
        <?php endif; ?>

        <?php if ($reports_result && mysqli_num_rows($reports_result) > 0):
            $total_pending = mysqli_num_rows($reports_result);
            mysqli_data_seek($reports_result, 0);
        ?>
            <div class="reports-table-wrap">
            <div class="ur-summary-bar">
                <div class="ur-summary-pill ur-pill-pending">
                    <i class="fas fa-clock"></i>
                    <?php echo $total_pending; ?> Pending Request<?php echo $total_pending !== 1 ? 's' : ''; ?>
                </div>
            </div>

                <table class="reports-table" aria-label="Pending user reports">
                    <thead><tr>
                        <th scope="col">Reported User</th>
                        <th scope="col">Reported By</th>
                        <th scope="col">Report Date</th>
                        <th scope="col">Status</th>
                        <th scope="col">Action</th>
                    </tr></thead>
                    <tbody>
            <?php while ($report = mysqli_fetch_assoc($reports_result)): ?>
                <?php
                    $full_name  = htmlspecialchars($report['first_name'] . ' ' . $report['last_name']);
                    $initials   = strtoupper(substr($report['first_name'], 0, 1) . substr($report['last_name'], 0, 1));
                    $store_name = htmlspecialchars($report['store_name'] ?? 'Unknown Caterer');
                    $date_str   = date('M j, Y \a\t g:i A', strtotime($report['created_at']));
                    $report_id  = (int)$report['id'];
                    $profile_path = ltrim(str_replace('\\', '/', (string) ($report['profile_image'] ?? '')), '/');
                    $has_profile = $profile_path !== '' && is_file(dirname(__DIR__) . '/' . $profile_path);
                ?>
                <tr>
                    <td data-label="Reported User">
                        <button type="button" class="report-mobile-toggle" aria-expanded="false" aria-controls="report-details-<?php echo $report_id; ?>" onclick="toggleReportCard(<?php echo $report_id; ?>, this)"><span class="report-user">
                            <span class="report-avatar" aria-hidden="true">
                                <span><?php echo htmlspecialchars($initials); ?></span>
                                <?php if ($has_profile): ?>
                                    <img src="../<?php echo htmlspecialchars($profile_path, ENT_QUOTES, 'UTF-8'); ?>" alt="" width="40" height="40" loading="lazy" onerror="this.remove()">
                                <?php endif; ?>
                            </span>
                            <span><?php echo $full_name; ?></span>
                        </span><i class="fas fa-chevron-down" aria-hidden="true"></i></button>
                        <div class="report-user">
                        <div class="report-avatar" aria-hidden="true">
                            <span><?php echo htmlspecialchars($initials); ?></span>
                            <?php if ($has_profile): ?>
                                <img src="../<?php echo htmlspecialchars($profile_path, ENT_QUOTES, 'UTF-8'); ?>" alt="" width="40" height="40" loading="lazy" onerror="this.remove()">
                            <?php endif; ?>
                        </div>
                        <span class="report-name"><?php echo $full_name; ?></span>
                    </div></td>
                    <td data-label="Reported By"><?php echo $store_name; ?></td>
                    <td data-label="Report Date"><?php echo date('M d, Y', strtotime($report['created_at'])); ?></td>
                    <td data-label="Status"><span class="report-badge badge-pending">Pending Review</span></td>
                    <td><button type="button" class="report-view-button" aria-expanded="false" aria-controls="report-details-<?php echo $report_id; ?>" onclick="toggleReportCard(<?php echo $report_id; ?>, this)">View Details <i class="fas fa-chevron-down" aria-hidden="true"></i></button></td>
                </tr>
                <tr class="report-details-row" id="report-details-<?php echo $report_id; ?>" hidden>
                    <td colspan="5">
                    <div class="report-card-body">
                        <hr class="report-divider">

                        <!-- Info Grid — "Reported User" removed to avoid duplicate name -->
                        <div class="report-info-grid">
                            <div class="report-info-cell">
                                <div class="report-info-cell-label">
                                    <i class="fas fa-store" style="margin-right:4px;"></i> Reported By
                                </div>
                                <div class="report-info-cell-value"><?php echo $store_name; ?></div>
                            </div>
                            <div class="report-info-cell">
                                <div class="report-info-cell-label">
                                    <i class="fas fa-clock" style="margin-right:4px;"></i> Report Date
                                </div>
                                <div class="report-info-cell-value"><?php echo date('M d, Y', strtotime($report['created_at'])); ?></div>
                            </div>
                            <?php if (!empty($report['order_number'])): ?>
                            <div class="report-info-cell">
                                <div class="report-info-cell-label">
                                    <i class="fas fa-receipt" style="margin-right:4px;"></i> Related Order
                                </div>
                                <div class="report-info-cell-value"><?php echo htmlspecialchars($report['order_number']); ?></div>
                            </div>
                            <?php endif; ?>
                        </div>

                        <!-- Reason Box -->
                        <div class="report-reason-box">
                            <div class="report-reason-label">
                                    <i class="fas fa-exclamation-triangle"></i> Reason for report
                            </div>
                            <div class="report-reason-text">
                                <?php echo nl2br(htmlspecialchars($report['reason'])); ?>
                            </div>
                        </div>

                        <!-- Action Buttons -->
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCSRFToken()); ?>">
                            <input type="hidden" name="report_id" value="<?php echo $report_id; ?>">
                            <div class="report-action-row">
                                <button type="submit" name="action" value="approve" class="btn-deactivate"
                                    onclick="return portalConfirmButton(event, this, 'Are you sure you want to deactivate <?php echo addslashes($full_name); ?>? This action cannot be undone.');">
                                    <i class="fas fa-user-slash"></i> Approve &amp; Deactivate User
                                </button>
                                <button type="submit" name="action" value="reject" class="btn-dismiss">
                                    <i class="fas fa-ban"></i> Dismiss Request
                                </button>
                                <span class="report-action-note">
                                    <i class="fas fa-shield-alt"></i> Report #<?php echo $report_id; ?>
                                </span>
                            </div>
                        </form>
                    </div>

                    </td>
                </tr>
            <?php endwhile; ?>
                    </tbody>
                </table>
            </div>

        <?php else: ?>
            <div class="ur-empty-state">
                <div class="ur-empty-icon">
                    <i class="fas fa-check-circle"></i>
                </div>
                <h3>All Clear!</h3>
                <p>There are no pending user deactivation requests at this time.</p>
            </div>
        <?php endif; ?>
    </div>

    <script>
        function toggleReportCard(id, button) {
            const details = document.getElementById('report-details-' + id);
            if (!details) return;
            details.hidden = !details.hidden;
            const summary = details.previousElementSibling;
            summary.classList.toggle('is-expanded', !details.hidden);
            summary.querySelectorAll('button[aria-expanded]').forEach(function (toggle) {
                toggle.setAttribute('aria-expanded', String(!details.hidden));
            });
        }
    </script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

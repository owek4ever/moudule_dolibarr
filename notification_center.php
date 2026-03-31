<?php
/**
 * notification_center.php
 *
 * Notification Center for Flotte module.
 * Shows all notification history, status, and quick-send tools.
 */

// Load Dolibarr environment
$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
    $res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"] . "/main.inc.php";
}
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1; $j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) { $i--; $j--; }
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i+1))."/main.inc.php")) { $res = @include substr($tmp, 0, ($i+1))."/main.inc.php"; }
if (!$res && file_exists("../main.inc.php"))      { $res = @include "../main.inc.php"; }
if (!$res && file_exists("../../main.inc.php"))   { $res = @include "../../main.inc.php"; }
if (!$res && file_exists("../../../main.inc.php")){ $res = @include "../../../main.inc.php"; }
if (!$res) { die("Include of main fails"); }

require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';
dol_include_once('/flotte/class/FirebaseNotificationService.class.php');

$langs->loadLangs(array("flotte@flotte", "other"));
restrictedArea($user, 'flotte');

$svc    = new FirebaseNotificationService($db);
$action = GETPOST('action', 'aZ09');

// ── Handle AJAX token registration ──────────────────────────────
if ($action === 'register_token' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $token       = GETPOST('token', 'alpha');
    $device_type = GETPOST('device_type', 'alpha') ?: 'web';
    $label       = GETPOST('label', 'alpha') ?: 'Browser';
    if ($token && $svc->registerToken($user->id, $token, $device_type, $label)) {
        echo json_encode(array('success' => true));
    } else {
        echo json_encode(array('success' => false, 'error' => 'Registration failed'));
    }
    exit;
}

// ── Handle test notification send ───────────────────────────────
if ($action === 'send_test' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $title  = GETPOST('title', 'alphanohtml') ?: 'Test Notification';
    $body   = GETPOST('body', 'alphanohtml')  ?: 'This is a test from Flotte.';
    $target = GETPOST('target', 'aZ09'); // 'me' or 'broadcast'

    if ($target === 'broadcast') {
        $result = $svc->broadcast($title, $body, array(), 'custom', 2);
    } else {
        $result = $svc->sendToUser($user->id, $title, $body, array(), 'custom', 2);
    }
    setEventMessages($result['success'] ? $langs->trans('NotificationSent', $result['sent']) : $langs->trans('SendFailed') . ': ' . ($result['error'] ?? $langs->trans('NoDevicesRegistered')), null, $result['success'] ? 'mesgs' : 'errors');
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// ── Handle alert scan trigger ────────────────────────────────────
if ($action === 'run_scan') {
    $summary = $svc->runAlertScan();
    setEventMessages($langs->trans('AlertScanComplete', $summary['sent']), null, 'mesgs');
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// ── Filters & pagination ────────────────────────────────────────
$filter_type   = GETPOST('filter_type',   'alpha');
$filter_status = GETPOST('filter_status', 'alpha');
$page          = max(0, (int) GETPOST('page', 'int'));
$limit         = 25;
$offset        = $page * $limit;

$filters = array(
    'type'   => $filter_type,
    'status' => $filter_status,
    'limit'  => $limit,
    'offset' => $offset,
);

$logs  = $svc->getNotificationLog($filters);
$total = $svc->countNotificationLog($filters);
$pages = (int) ceil($total / $limit);

// ── Stats ────────────────────────────────────────────────────────
$statSent   = $svc->countNotificationLog(array('status' => 'sent'));
$statFailed = $svc->countNotificationLog(array('status' => 'failed'));
$statPending= $svc->countNotificationLog(array('status' => 'pending'));

llxHeader('', $langs->trans('NotificationCenter'));
?>
<style>
@import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&display=swap');
*{box-sizing:border-box;}
.nc-page{font-family:'DM Sans',sans-serif;max-width:1200px;margin:0 auto;padding:0 4px 60px;color:#1a1f2e;}

/* ── Header ── */
.nc-header{display:flex;align-items:center;justify-content:space-between;padding:26px 0 22px;border-bottom:1px solid #e8eaf0;margin-bottom:28px;gap:12px;flex-wrap:wrap;}
.nc-header-left{display:flex;align-items:center;gap:14px;}
.nc-header-icon{width:46px;height:46px;border-radius:12px;background:rgba(249,115,22,.12);display:flex;align-items:center;justify-content:center;color:#f97316;font-size:20px;}
.nc-header-title{font-size:22px;font-weight:700;color:#1a1f2e;margin:0 0 3px;letter-spacing:-0.3px;}
.nc-header-sub{font-size:12.5px;color:#8b92a9;}
.nc-header-actions{display:flex;gap:8px;flex-wrap:wrap;}

/* ── Stat cards ── */
.nc-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:14px;margin-bottom:24px;}
.nc-stat{background:#fff;border:1px solid #e8eaf0;border-radius:12px;padding:18px 20px;display:flex;flex-direction:column;gap:4px;box-shadow:0 1px 4px rgba(0,0,0,.04);}
.nc-stat-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:#8b92a9;}
.nc-stat-value{font-size:28px;font-weight:700;letter-spacing:-1px;}
.nc-stat.green .nc-stat-value{color:#16a34a;}
.nc-stat.red   .nc-stat-value{color:#dc2626;}
.nc-stat.amber .nc-stat-value{color:#d97706;}
.nc-stat.blue  .nc-stat-value{color:#2563eb;}

/* ── Buttons ── */
.nc-btn{display:inline-flex;align-items:center;gap:7px;padding:8px 16px;border-radius:6px;font-size:13px;font-weight:600;text-decoration:none!important;cursor:pointer;font-family:'DM Sans',sans-serif;border:none;white-space:nowrap;transition:all .15s;}
.nc-btn-primary{background:#f97316;color:#fff;}
.nc-btn-primary:hover{background:#ea6c0b;color:#fff;}
.nc-btn-ghost{background:#fff;color:#5a6482;border:1.5px solid #d1d5e0!important;}
.nc-btn-ghost:hover{background:#f5f6fa;color:#2d3748;}
.nc-btn-danger{background:#fef2f2;color:#dc2626;border:1.5px solid #fecaca!important;}
.nc-btn-sm{padding:6px 12px;font-size:12px;}

/* ── Card ── */
.nc-card{background:#fff;border:1px solid #e8eaf0;border-radius:12px;overflow:hidden;box-shadow:0 1px 6px rgba(0,0,0,.04);margin-bottom:20px;}
.nc-card-header{display:flex;align-items:center;justify-content:space-between;padding:14px 20px;border-bottom:1px solid #f0f2f8;background:#f7f8fc;gap:10px;flex-wrap:wrap;}
.nc-card-header-left{display:flex;align-items:center;gap:10px;}
.nc-card-title{font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.7px;color:#8b92a9;}
.nc-card-body{padding:20px;}

/* ── Table ── */
.nc-table{width:100%;border-collapse:collapse;}
.nc-table th{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:#8b92a9;padding:10px 16px;border-bottom:2px solid #f0f2f8;text-align:left;background:#fafbfe;}
.nc-table td{padding:12px 16px;border-bottom:1px solid #f5f6fb;font-size:13px;color:#2d3748;vertical-align:middle;}
.nc-table tr:last-child td{border-bottom:none;}
.nc-table tr:hover td{background:#fafbfe;}

/* ── Status badges ── */
.nc-badge{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:12px;font-size:11.5px;font-weight:600;}
.nc-badge::before{content:'';width:5px;height:5px;border-radius:50%;flex-shrink:0;}
.nb-sent{background:#edfaf3;color:#166534;} .nb-sent::before{background:#22c55e;}
.nb-failed{background:#fef2f2;color:#991b1b;} .nb-failed::before{background:#ef4444;}
.nb-pending{background:#fffbeb;color:#92400e;} .nb-pending::before{background:#f59e0b;}
.nb-cancelled{background:#f3f4f6;color:#4b5563;} .nb-cancelled::before{background:#9ca3af;}

/* ── Priority ── */
.nc-priority{font-size:11px;font-weight:700;padding:2px 8px;border-radius:4px;}
.np-critical{background:#fef2f2;color:#dc2626;}
.np-high{background:#fff7ed;color:#c2410c;}
.np-normal{background:#f0f9ff;color:#0369a1;}
.np-low{background:#f9fafb;color:#6b7280;}

/* ── Type icon ── */
.nc-type-icon{display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:7px;font-size:13px;margin-right:6px;}

/* ── Firebase setup banner ── */
.nc-setup-banner{background:linear-gradient(135deg,#fff7ed,#fef3c7);border:1px solid #fed7aa;border-radius:12px;padding:20px 24px;margin-bottom:20px;display:flex;align-items:center;gap:16px;}
.nc-setup-banner-icon{font-size:32px;flex-shrink:0;}
.nc-setup-banner h3{margin:0 0 4px;font-size:15px;color:#92400e;}
.nc-setup-banner p{margin:0;font-size:13px;color:#b45309;}

/* ── Filter bar ── */
.nc-filter-bar{display:flex;gap:10px;align-items:center;flex-wrap:wrap;}
.nc-filter-bar select,.nc-filter-bar input{padding:7px 12px;border:1.5px solid #e2e5f0;border-radius:8px;font-size:13px;font-family:'DM Sans',sans-serif;color:#2d3748;background:#fafbfe;outline:none;}
.nc-filter-bar select:focus,.nc-filter-bar input:focus{border-color:#f97316;box-shadow:0 0 0 3px rgba(249,115,22,.1);}

/* ── Modal ── */
.nc-modal-backdrop{display:none;position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:9000;align-items:center;justify-content:center;}
.nc-modal-backdrop.open{display:flex;}
.nc-modal{background:#fff;border-radius:16px;padding:28px;width:100%;max-width:500px;box-shadow:0 20px 60px rgba(0,0,0,.2);}
.nc-modal h2{margin:0 0 16px;font-size:18px;}
.nc-modal label{display:block;font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:#8b92a9;margin-bottom:4px;}
.nc-modal input,.nc-modal select,.nc-modal textarea{width:100%;padding:9px 13px;border:1.5px solid #e2e5f0;border-radius:8px;font-size:13px;font-family:'DM Sans',sans-serif;margin-bottom:14px;outline:none;box-sizing:border-box;}
.nc-modal input:focus,.nc-modal select:focus,.nc-modal textarea:focus{border-color:#f97316;box-shadow:0 0 0 3px rgba(249,115,22,.1);}
.nc-modal-footer{display:flex;gap:8px;justify-content:flex-end;margin-top:4px;}

/* ── Pagination ── */
.nc-pagination{display:flex;gap:6px;align-items:center;justify-content:center;padding-top:16px;}
.nc-page-btn{padding:6px 14px;border-radius:6px;font-size:13px;font-weight:600;border:1.5px solid #e2e5f0;background:#fff;color:#5a6482;cursor:pointer;text-decoration:none;}
.nc-page-btn.active{background:#f97316;color:#fff;border-color:#f97316;}
.nc-page-btn:hover:not(.active){background:#f5f6fa;}

/* ── FCM status dot ── */
#fcm-status{width:8px;height:8px;border-radius:50%;background:#d1d5e0;display:inline-block;margin-right:5px;}
#fcm-status.connected{background:#22c55e;}
#fcm-status.error{background:#ef4444;}
</style>

<div class="nc-page">

<?php
// Show Firebase not configured warning
if (!$svc->isConfigured()) :
?>
<div class="nc-setup-banner">
    <div class="nc-setup-banner-icon">🔥</div>
    <div>
        <h3><?php echo $langs->trans('FirebaseNotConfigured'); ?></h3>
        <p><?php echo $langs->trans('FirebaseNotConfiguredDesc'); ?>
           <a href="<?php echo dol_buildpath('/flotte/notification_settings.php', 1); ?>" style="color:#c2410c;font-weight:600;">→ <?php echo $langs->trans('GoToSettings'); ?></a>
        </p>
    </div>
</div>
<?php endif; ?>

<!-- ── Header ── -->
<div class="nc-header">
    <div class="nc-header-left">
        <div class="nc-header-icon"><i class="fa fa-bell"></i></div>
        <div>
            <div class="nc-header-title"><?php echo $langs->trans('NotificationCenter'); ?></div>
            <div class="nc-header-sub">
                <span id="fcm-status"></span>
                <span id="fcm-status-text">Checking browser push status…</span>
            </div>
        </div>
    </div>
    <div class="nc-header-actions">
        <a href="<?php echo dol_buildpath('/flotte/notification_settings.php', 1); ?>" class="nc-btn nc-btn-ghost"><i class="fa fa-cog"></i> <?php echo $langs->trans('Settings'); ?></a>
        <button class="nc-btn nc-btn-ghost" onclick="document.getElementById('modal-register').classList.add('open')"><i class="fa fa-mobile-alt"></i> <?php echo $langs->trans('RegisterDevice'); ?></button>
        <button class="nc-btn nc-btn-ghost" onclick="openTestModal()"><i class="fa fa-paper-plane"></i> <?php echo $langs->trans('SendTest'); ?></button>
        <a href="?action=run_scan" class="nc-btn nc-btn-primary" onclick="return confirm('<?php echo dol_escape_js($langs->trans('ConfirmRunAlertScan')); ?>')"><i class="fa fa-sync"></i> <?php echo $langs->trans('RunAlertScan'); ?></a>
    </div>
</div>

<!-- ── Stat cards ── -->
<div class="nc-stats">
    <div class="nc-stat blue">
        <div class="nc-stat-label"><?php echo $langs->trans('TotalNotifications'); ?></div>
        <div class="nc-stat-value"><?php echo number_format($svc->countNotificationLog()); ?></div>
    </div>
    <div class="nc-stat green">
        <div class="nc-stat-label"><?php echo $langs->trans('Sent'); ?></div>
        <div class="nc-stat-value"><?php echo number_format($statSent); ?></div>
    </div>
    <div class="nc-stat red">
        <div class="nc-stat-label"><?php echo $langs->trans('Failed'); ?></div>
        <div class="nc-stat-value"><?php echo number_format($statFailed); ?></div>
    </div>
    <div class="nc-stat amber">
        <div class="nc-stat-label"><?php echo $langs->trans('Pending'); ?></div>
        <div class="nc-stat-value"><?php echo number_format($statPending); ?></div>
    </div>
    <div class="nc-stat blue">
        <div class="nc-stat-label"><?php echo $langs->trans('FirebaseStatus'); ?></div>
        <div class="nc-stat-value" style="font-size:16px;padding-top:6px;">
            <?php if ($svc->isConfigured()): ?>
                <span style="color:#16a34a;"><i class="fa fa-check-circle"></i> <?php echo $langs->trans('Connected'); ?></span>
            <?php else: ?>
                <span style="color:#dc2626;"><i class="fa fa-exclamation-circle"></i> <?php echo $langs->trans('NotSet'); ?></span>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ── Notification log ── -->
<div class="nc-card">
    <div class="nc-card-header">
        <div class="nc-card-header-left">
            <span class="nc-card-title"><?php echo $langs->trans('NotificationHistory'); ?></span>
            <small style="color:#8b92a9;font-size:12px;">(<?php echo number_format($total); ?> total)</small>
        </div>
        <form method="GET" class="nc-filter-bar" style="margin:0;">
            <select name="filter_type" onchange="this.form.submit()">
                <option value=""><?php echo $langs->trans('AllTypes'); ?></option>
                <option value="registration_expiry" <?php echo $filter_type == 'registration_expiry' ? 'selected' : ''; ?>><?php echo $langs->trans('RegistrationExpiry'); ?></option>
                <option value="license_expiry"      <?php echo $filter_type == 'license_expiry'      ? 'selected' : ''; ?>><?php echo $langs->trans('LicenseExpiry'); ?></option>
                <option value="insurance_expiry"    <?php echo $filter_type == 'insurance_expiry'    ? 'selected' : ''; ?>><?php echo $langs->trans('InsuranceExpiry'); ?></option>
                <option value="inspection_due"      <?php echo $filter_type == 'inspection_due'      ? 'selected' : ''; ?>><?php echo $langs->trans('InspectionDue'); ?></option>
                <option value="workorder_created"   <?php echo $filter_type == 'workorder_created'   ? 'selected' : ''; ?>><?php echo $langs->trans('WorkOrder'); ?></option>
                <option value="booking_created"     <?php echo $filter_type == 'booking_created'     ? 'selected' : ''; ?>><?php echo $langs->trans('Booking'); ?></option>
                <option value="custom"              <?php echo $filter_type == 'custom'              ? 'selected' : ''; ?>><?php echo $langs->trans('CustomTest'); ?></option>
            </select>
            <select name="filter_status" onchange="this.form.submit()">
                <option value=""><?php echo $langs->trans('AllStatuses'); ?></option>
                <option value="sent"      <?php echo $filter_status == 'sent'      ? 'selected' : ''; ?>><?php echo $langs->trans('Sent'); ?></option>
                <option value="failed"    <?php echo $filter_status == 'failed'    ? 'selected' : ''; ?>><?php echo $langs->trans('Failed'); ?></option>
                <option value="pending"   <?php echo $filter_status == 'pending'   ? 'selected' : ''; ?>><?php echo $langs->trans('Pending'); ?></option>
                <option value="cancelled" <?php echo $filter_status == 'cancelled' ? 'selected' : ''; ?>><?php echo $langs->trans('Cancelled'); ?></option>
            </select>
            <?php if ($filter_type || $filter_status): ?>
                <a href="<?php echo $_SERVER['PHP_SELF']; ?>" class="nc-btn nc-btn-ghost nc-btn-sm"><i class="fa fa-times"></i> <?php echo $langs->trans('Clear'); ?></a>
            <?php endif; ?>
        </form>
    </div>
    <div style="overflow-x:auto;">
        <table class="nc-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th><?php echo $langs->trans('Type'); ?></th>
                    <th><?php echo $langs->trans('Title'); ?></th>
                    <th><?php echo $langs->trans('Vehicle'); ?></th>
                    <th><?php echo $langs->trans('Priority'); ?></th>
                    <th><?php echo $langs->trans('Status'); ?></th>
                    <th><?php echo $langs->trans('SentAt'); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($logs)): ?>
                <tr><td colspan="7" style="text-align:center;color:#8b92a9;padding:40px;"><?php echo $langs->trans('NoNotificationsFound'); ?></td></tr>
            <?php else: ?>
                <?php foreach ($logs as $log):
                    $typeIcons = array(
                        'registration_expiry' => array('🪪', '#dbeafe'),
                        'license_expiry'      => array('📋', '#ede9fe'),
                        'insurance_expiry'    => array('🛡️', '#fef9c3'),
                        'inspection_due'      => array('🔧', '#dcfce7'),
                        'workorder_created'   => array('⚙️', '#f3e8ff'),
                        'booking_created'     => array('📅', '#e0f2fe'),
                        'fuel_added'          => array('⛽', '#fff7ed'),
                        'custom'              => array('📣', '#f1f5f9'),
                    );
                    $typeInfo = $typeIcons[$log->type] ?? array('🔔', '#f3f4f6');

                    $priorityMap = array(1=>'low', 2=>'normal', 3=>'high', 4=>'critical');
                    $priorityLabel = $priorityMap[$log->priority] ?? 'normal';
                ?>
                <tr>
                    <td style="color:#8b92a9;font-size:12px;">#<?php echo $log->rowid; ?></td>
                    <td>
                        <span class="nc-type-icon" style="background:<?php echo $typeInfo[1]; ?>"><?php echo $typeInfo[0]; ?></span>
                        <span style="font-size:12px;"><?php echo dol_escape_htmltag(ucwords(str_replace('_', ' ', $log->type))); ?></span>
                    </td>
                    <td>
                        <div style="font-weight:600;"><?php echo dol_escape_htmltag($log->title); ?></div>
                        <div style="font-size:12px;color:#6b7280;margin-top:2px;"><?php echo dol_escape_htmltag(substr($log->body, 0, 80)); ?><?php echo strlen($log->body) > 80 ? '…' : ''; ?></div>
                    </td>
                    <td>
                        <?php if ($log->fk_vehicle): ?>
                            <a href="<?php echo dol_buildpath('/flotte/vehicle_card.php', 1); ?>?id=<?php echo $log->fk_vehicle; ?>" style="font-size:12.5px;font-weight:600;color:#3c4758;">
                                <?php echo dol_escape_htmltag($log->vehicle_ref); ?>
                            </a>
                            <div style="font-size:11.5px;color:#8b92a9;"><?php echo dol_escape_htmltag($log->maker . ' ' . $log->model); ?></div>
                        <?php else: ?>
                            <span style="color:#c4c9d8;">—</span>
                        <?php endif; ?>
                    </td>
                    <td><span class="nc-priority np-<?php echo $priorityLabel; ?>"><?php echo strtoupper($priorityLabel); ?></span></td>
                    <td>
                        <span class="nc-badge nb-<?php echo dol_escape_htmltag($log->status); ?>">
                            <?php echo ucfirst(dol_escape_htmltag($log->status)); ?>
                        </span>
                        <?php if ($log->status === 'failed' && $log->error_message): ?>
                            <div style="font-size:11px;color:#ef4444;margin-top:2px;"><?php echo dol_escape_htmltag(substr($log->error_message, 0, 60)); ?></div>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:12px;color:#6b7280;white-space:nowrap;">
                        <?php echo $log->date_sent ? dol_print_date($log->date_sent, 'dayhour') : dol_print_date($log->date_creation, 'dayhour'); ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($pages > 1): ?>
    <div class="nc-pagination">
        <?php if ($page > 0): ?>
            <a href="?page=<?php echo $page-1; ?>&filter_type=<?php echo urlencode($filter_type); ?>&filter_status=<?php echo urlencode($filter_status); ?>" class="nc-page-btn"><?php echo $langs->trans('Prev'); ?></a>
        <?php endif; ?>
        <?php for ($p = max(0, $page-2); $p <= min($pages-1, $page+2); $p++): ?>
            <a href="?page=<?php echo $p; ?>&filter_type=<?php echo urlencode($filter_type); ?>&filter_status=<?php echo urlencode($filter_status); ?>" class="nc-page-btn <?php echo $p === $page ? 'active' : ''; ?>"><?php echo $p+1; ?></a>
        <?php endfor; ?>
        <?php if ($page < $pages-1): ?>
            <a href="?page=<?php echo $page+1; ?>&filter_type=<?php echo urlencode($filter_type); ?>&filter_status=<?php echo urlencode($filter_status); ?>" class="nc-page-btn"><?php echo $langs->trans('Next'); ?></a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

</div><!-- nc-page -->

<!-- ── Modal: Register Device ── -->
<div class="nc-modal-backdrop" id="modal-register">
    <div class="nc-modal">
        <h2>🔔 <?php echo $langs->trans('RegisterThisDevice'); ?></h2>
        <p style="font-size:13px;color:#6b7280;margin:0 0 16px;"><?php echo $langs->trans('AllowBrowserPush'); ?></p>
        <label><?php echo $langs->trans('DeviceLabelOptional'); ?></label>
        <input type="text" id="device-label" placeholder="<?php echo $langs->trans('DeviceLabelPlaceholder'); ?>" />
        <div class="nc-modal-footer">
            <button class="nc-btn nc-btn-ghost" onclick="document.getElementById('modal-register').classList.remove('open')"><?php echo $langs->trans('Cancel'); ?></button>
            <button class="nc-btn nc-btn-primary" onclick="requestFcmPermission()"><i class="fa fa-bell"></i> <?php echo $langs->trans('EnableNotifications'); ?></button>
        </div>
        <div id="register-result" style="margin-top:12px;font-size:13px;"></div>
    </div>
</div>

<!-- ── Modal: Send Test ── -->
<div class="nc-modal-backdrop" id="modal-test">
    <div class="nc-modal">
        <form method="POST" action="<?php echo $_SERVER['PHP_SELF']; ?>">
            <input type="hidden" name="action" value="send_test">
            <input type="hidden" name="token" value="<?php echo newToken(); ?>">
            <h2>📣 <?php echo $langs->trans('SendTestNotification'); ?></h2>
            <label><?php echo $langs->trans('Title'); ?></label>
            <input type="text" name="title" value="<?php echo $langs->trans('TestFromFlotte'); ?>" required />
            <label><?php echo $langs->trans('Message'); ?></label>
            <textarea name="body" rows="3" style="resize:vertical;">This is a test push notification from your Fleet Management System.</textarea>
            <label><?php echo $langs->trans('Target'); ?></label>
            <select name="target">
                <option value="me"><?php echo $langs->trans('OnlyMe'); ?></option>
                <option value="broadcast"><?php echo $langs->trans('AllUsersBroadcast'); ?></option>
            </select>
            <div class="nc-modal-footer">
                <button type="button" class="nc-btn nc-btn-ghost" onclick="document.getElementById('modal-test').classList.remove('open')"><?php echo $langs->trans('Cancel'); ?></button>
                <button type="submit" class="nc-btn nc-btn-primary"><i class="fa fa-paper-plane"></i> <?php echo $langs->trans('Send'); ?></button>
            </div>
        </form>
    </div>
</div>

<script>
// ── Firebase initialization ───────────────────────────────────────────
// Replace with your Firebase config values from notification_settings.php
const FIREBASE_CONFIG = <?php
    $cfg = $svc->getConfig();
    // Full Firebase app config — hardcoded to match the service worker.
    // Only vapidKey comes from the DB (it's the Web Push certificate key).
    echo json_encode(array(
        'apiKey'            => 'AIzaSyCxUJHdA0_jMxlut9lQxE69Nit91lwwJDw',
        'authDomain'        => 'dolibarr-flotte.firebaseapp.com',
        'projectId'         => 'dolibarr-flotte',
        'storageBucket'     => 'dolibarr-flotte.firebasestorage.app',
        'messagingSenderId' => '262203283893',
        'appId'             => '1:262203283893:web:11766cd9f79e099edca1fc',
        'vapidKey'          => $cfg['vapid_key'] ?? '',
    ));
?>;

const REGISTER_URL = '<?php echo dol_buildpath('/flotte/notification_center.php', 1); ?>?action=register_token';

// ── FCM Service Worker Registration ──────────────────────────────────
function checkFcmStatus() {
    const dot  = document.getElementById('fcm-status');
    const text = document.getElementById('fcm-status-text');

    if (!('Notification' in window)) {
        dot.className = 'error';
        text.textContent = 'Browser does not support notifications';
        return;
    }

    const perm = Notification.permission;
    if (perm === 'granted') {
        dot.className = 'connected';
        text.textContent = 'Push notifications enabled';
    } else if (perm === 'denied') {
        dot.className = 'error';
        text.textContent = 'Notifications blocked — please update browser settings';
    } else {
        dot.className = '';
        text.textContent = 'Notifications not yet enabled — click Register Device';
    }
}
checkFcmStatus();

// ── Request FCM Permission + get token ───────────────────────────────
async function requestFcmPermission() {
    const resultEl = document.getElementById('register-result');
    const label    = document.getElementById('device-label').value || 'Browser';
    let lastStep   = 'init';

    function step(msg) {
        lastStep = msg;
        resultEl.innerHTML = '⏳ ' + msg;
    }

    resultEl.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Requesting permission…';

    try {
        const perm = await Notification.requestPermission();
        if (perm !== 'granted') {
            resultEl.innerHTML = '❌ Permission denied. Please allow notifications in browser settings.';
            return;
        }

        // ── Step 1: VAPID key check ──
        if (!FIREBASE_CONFIG.vapidKey) {
            resultEl.innerHTML = '⚠️ VAPID key not configured — go to Settings and paste the Web Push key pair from Firebase Console.';
            return;
        }
        if (FIREBASE_CONFIG.vapidKey.length < 80) {
            resultEl.innerHTML = '⚠️ VAPID key looks wrong (length: ' + FIREBASE_CONFIG.vapidKey.length + ', expected ~88). Check notification settings.';
            return;
        }
        step('[1/4] VAPID key OK (' + FIREBASE_CONFIG.vapidKey.length + ' chars)…');

        // ── Step 2: Load Firebase SDK ──
        await loadScript('https://www.gstatic.com/firebasejs/9.23.0/firebase-app-compat.js');
        await loadScript('https://www.gstatic.com/firebasejs/9.23.0/firebase-messaging-compat.js');
        step('[2/4] Firebase SDK loaded…');

        // ── Step 3: Init Firebase app ──
        if (!firebase.apps.length) {
            firebase.initializeApp({
                apiKey:            FIREBASE_CONFIG.apiKey,
                authDomain:        FIREBASE_CONFIG.authDomain,
                projectId:         FIREBASE_CONFIG.projectId,
                storageBucket:     FIREBASE_CONFIG.storageBucket,
                messagingSenderId: FIREBASE_CONFIG.messagingSenderId,
                appId:             FIREBASE_CONFIG.appId,
            });
        }

        // ── Step 4: Register service worker ──
        const swPath = '<?php echo dol_buildpath('/flotte/firebase-sw.js', 1); ?>';
        step('[3/4] Registering SW at: ' + swPath);
        const swReg = await navigator.serviceWorker.register(swPath);
        await navigator.serviceWorker.ready;

        // ── Step 5: Get FCM token ──
        step('[4/4] Contacting push service…');
        const messaging = firebase.messaging();
        const token = await messaging.getToken({
            vapidKey: FIREBASE_CONFIG.vapidKey,
            serviceWorkerRegistration: swReg
        });

        if (!token) {
            resultEl.innerHTML = '❌ Could not get FCM token. Try again.';
            return;
        }

        // ── Step 6: Send token to server ──
        step('[5/5] Saving token to server…');
        const res  = await fetch(REGISTER_URL, {
            method:  'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body:    new URLSearchParams({ token, device_type: 'web', label })
        });
        const data = await res.json();

        if (data.success) {
            resultEl.innerHTML = '✅ Device registered successfully!';
            checkFcmStatus();
            setTimeout(() => document.getElementById('modal-register').classList.remove('open'), 1500);
        } else {
            resultEl.innerHTML = '❌ Server registration failed: ' + (data.error || 'Unknown error');
        }
    } catch (e) {
        const detail = e.code ? ' [' + e.code + ']' : '';
        resultEl.innerHTML = '❌ Failed at step: <b>' + lastStep + '</b><br>'
            + 'Error: ' + e.message + detail;
        console.error('[FCM] Failed at step:', lastStep, e);
    }
}

// Helper to load a script tag dynamically
function loadScript(src) {
    return new Promise(function(resolve, reject) {
        if (document.querySelector('script[src="' + src + '"]')) { resolve(); return; }
        const s = document.createElement('script');
        s.src = src;
        s.onload  = resolve;
        s.onerror = reject;
        document.head.appendChild(s);
    });
}

function openTestModal() {
    document.getElementById('modal-test').classList.add('open');
}

// Close modals on backdrop click
document.querySelectorAll('.nc-modal-backdrop').forEach(function(el) {
    el.addEventListener('click', function(e) {
        if (e.target === el) el.classList.remove('open');
    });
});

function urlB64ToUint8Array(base64String) {
    const padding = '='.repeat((4 - base64String.length % 4) % 4);
    const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    const rawData = window.atob(base64);
    const outputArray = new Uint8Array(rawData.length);
    for (let i = 0; i < rawData.length; ++i) outputArray[i] = rawData.charCodeAt(i);
    return outputArray;
}
</script>

<?php
llxFooter();
$db->close();
?>
<?php
/**
 * notification_settings.php
 *
 * Firebase & Alert Rules configuration page for the Flotte module.
 */

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

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
dol_include_once('/flotte/class/FirebaseNotificationService.class.php');

$langs->loadLangs(array("flotte@flotte", "admin", "other"));

// Admin only
if (!$user->admin) {
    accessforbidden();
}

$svc    = new FirebaseNotificationService($db);
$action = GETPOST('action', 'aZ09');

// ── Save Firebase config ──────────────────────────────────────────────
if ($action === 'save_firebase' && $_POST) {
    $data = array(
        'server_key'           => GETPOST('server_key',           'alphanohtml'),
        'project_id'           => GETPOST('project_id',           'alphanohtml'),
        'service_account_json' => GETPOST('service_account_json', 'none'),
        'vapid_key'            => GETPOST('vapid_key',            'alphanohtml'),
        'use_v1_api'           => GETPOST('use_v1_api',           'int'),
    );
    if ($svc->saveConfig($data)) {
        setEventMessages($langs->trans('FirebaseConfigSaved'), null, 'mesgs');
    } else {
        setEventMessages($langs->trans('FailedToSaveConfig').': '.$db->lasterror(), null, 'errors');
    }
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// ── Save / update alert rule ──────────────────────────────────────────
if ($action === 'save_rule' && $_POST) {
    $rowid       = (int) GETPOST('rowid', 'int');
    $rule_name   = GETPOST('rule_name',   'alphanohtml');
    $alert_type  = GETPOST('alert_type',  'alpha');
    $days_before = (int) GETPOST('days_before', 'int') ?: 30;
    $days2       = (int) GETPOST('days_before_second', 'int') ?: 7;
    $is_active   = GETPOST('is_active', 'int') ? 1 : 0;
    $channel     = GETPOST('notify_channel', 'alpha') ?: 'firebase';
    $priority    = (int) GETPOST('priority', 'int') ?: 2;
    $notify_users= GETPOST('notify_users', 'alphanohtml');
    $now         = $db->idate(dol_now());

    if ($rowid > 0) {
        $sql  = "UPDATE " . MAIN_DB_PREFIX . "flotte_alert_rules SET";
        $sql .= " rule_name = '"       . $db->escape($rule_name)  . "'";
        $sql .= ", alert_type = '"     . $db->escape($alert_type) . "'";
        $sql .= ", days_before = "     . $days_before;
        $sql .= ", days_before_second = " . $days2;
        $sql .= ", is_active = "       . $is_active;
        $sql .= ", notify_channel = '" . $db->escape($channel)    . "'";
        $sql .= ", priority = "        . $priority;
        $sql .= ", notify_users = "    . (!empty($notify_users) ? "'" . $db->escape($notify_users) . "'" : "NULL");
        $sql .= " WHERE rowid = "      . $rowid . " AND entity = " . (int) $conf->entity;
    } else {
        $sql  = "INSERT INTO " . MAIN_DB_PREFIX . "flotte_alert_rules";
        $sql .= " (entity, rule_name, alert_type, days_before, days_before_second, is_active, notify_channel, priority, notify_users, date_creation, fk_user_author)";
        $sql .= " VALUES (";
        $sql .= (int) $conf->entity . ", '" . $db->escape($rule_name)  . "', '" . $db->escape($alert_type) . "', ";
        $sql .= $days_before . ", " . $days2 . ", " . $is_active . ", '" . $db->escape($channel) . "', ";
        $sql .= $priority . ", " . (!empty($notify_users) ? "'" . $db->escape($notify_users) . "'" : "NULL") . ", ";
        $sql .= "'" . $now . "', " . (int) $user->id . ")";
    }

    if ($db->query($sql)) {
        setEventMessages($langs->trans('AlertRuleSaved'), null, 'mesgs');
    } else {
        setEventMessages($langs->trans('Error').': '.$db->lasterror(), null, 'errors');
    }
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// ── Delete alert rule ─────────────────────────────────────────────────
if ($action === 'delete_rule') {
    $rowid = (int) GETPOST('rowid', 'int');
    $db->query("DELETE FROM " . MAIN_DB_PREFIX . "flotte_alert_rules WHERE rowid = " . $rowid . " AND entity = " . (int) $conf->entity);
    setEventMessages($langs->trans('RuleDeleted'), null, 'mesgs');
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// ── Load data ─────────────────────────────────────────────────────────
$cfg   = $svc->getConfig();
$rules = array();
$res2  = $db->query("SELECT * FROM " . MAIN_DB_PREFIX . "flotte_alert_rules WHERE entity = " . (int) $conf->entity . " ORDER BY rowid ASC");
if ($res2) {
    while ($row = $db->fetch_object($res2)) {
        $rules[] = $row;
    }
}

// Edit rule?
$editRule = null;
if ($action === 'edit_rule') {
    $rowid = (int) GETPOST('rowid', 'int');
    foreach ($rules as $r) {
        if ($r->rowid == $rowid) { $editRule = $r; break; }
    }
}

llxHeader('', $langs->trans('NotificationSettings'));
?>
<style>
@import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&display=swap');
*{box-sizing:border-box;}
.ns-page{font-family:'DM Sans',sans-serif;max-width:1100px;margin:0 auto;padding:0 4px 60px;color:#1a1f2e;}
.ns-header{display:flex;align-items:center;justify-content:space-between;padding:26px 0 22px;border-bottom:1px solid #e8eaf0;margin-bottom:28px;gap:12px;flex-wrap:wrap;}
.ns-header-left{display:flex;align-items:center;gap:14px;}
.ns-header-icon{width:46px;height:46px;border-radius:12px;background:rgba(249,115,22,.12);display:flex;align-items:center;justify-content:center;color:#f97316;font-size:20px;}
.ns-header-title{font-size:22px;font-weight:700;margin:0 0 3px;letter-spacing:-0.3px;}
.ns-header-sub{font-size:12.5px;color:#8b92a9;}

/* Card */
.ns-card{background:#fff;border:1px solid #e8eaf0;border-radius:12px;overflow:hidden;box-shadow:0 1px 6px rgba(0,0,0,.04);margin-bottom:24px;}
.ns-card-header{display:flex;align-items:center;gap:10px;padding:14px 20px;border-bottom:1px solid #f0f2f8;background:#f7f8fc;}
.ns-card-icon{width:28px;height:28px;border-radius:7px;display:flex;align-items:center;justify-content:center;font-size:13px;}
.ns-card-icon.orange{background:rgba(249,115,22,.12);color:#f97316;}
.ns-card-icon.blue  {background:rgba(37,99,235,.1);color:#2563eb;}
.ns-card-icon.green {background:rgba(22,163,74,.1);color:#16a34a;}
.ns-card-title{font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.7px;color:#8b92a9;}
.ns-card-body{padding:24px;}

/* Form */
.ns-form-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;}
@media(max-width:700px){.ns-form-grid{grid-template-columns:1fr;}}
.ns-form-group{display:flex;flex-direction:column;gap:4px;margin-bottom:2px;}
.ns-form-group.full{grid-column:1/-1;}
.ns-label{font-size:11.5px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#6b7280;}
.ns-input{padding:9px 13px;border:1.5px solid #e2e5f0;border-radius:8px;font-size:13px;font-family:'DM Sans',sans-serif;color:#2d3748;background:#fafbfe;outline:none;width:100%;}
.ns-input:focus{border-color:#f97316;box-shadow:0 0 0 3px rgba(249,115,22,.1);background:#fff;}
textarea.ns-input{resize:vertical;min-height:90px;font-family:'DM Mono',monospace;font-size:12px;}

/* Buttons */
.ns-btn{display:inline-flex;align-items:center;gap:7px;padding:9px 18px;border-radius:7px;font-size:13px;font-weight:600;text-decoration:none!important;cursor:pointer;font-family:'DM Sans',sans-serif;border:none;transition:all .15s;}
.ns-btn-primary{background:#f97316;color:#fff;}
.ns-btn-primary:hover{background:#ea6c0b;}
.ns-btn-ghost{background:#fff;color:#5a6482;border:1.5px solid #d1d5e0!important;}
.ns-btn-ghost:hover{background:#f5f6fa;color:#2d3748;}
.ns-btn-danger{background:#fef2f2;color:#dc2626;border:1.5px solid #fecaca!important;}
.ns-btn-sm{padding:6px 12px;font-size:12px;}

/* Table */
.ns-table{width:100%;border-collapse:collapse;}
.ns-table th{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:#8b92a9;padding:10px 14px;border-bottom:2px solid #f0f2f8;text-align:left;background:#fafbfe;}
.ns-table td{padding:12px 14px;border-bottom:1px solid #f5f6fb;font-size:13px;color:#2d3748;vertical-align:middle;}
.ns-table tr:last-child td{border-bottom:none;}
.ns-table tr:hover td{background:#fafbfe;}

/* Toggle */
.ns-toggle{display:inline-flex;align-items:center;gap:8px;cursor:pointer;}
.ns-toggle input[type="checkbox"]{width:36px;height:20px;appearance:none;background:#d1d5e0;border-radius:20px;position:relative;cursor:pointer;transition:background .2s;}
.ns-toggle input[type="checkbox"]:checked{background:#f97316;}
.ns-toggle input[type="checkbox"]::after{content:'';width:16px;height:16px;background:#fff;border-radius:50%;position:absolute;top:2px;left:2px;transition:left .2s;box-shadow:0 1px 3px rgba(0,0,0,.2);}
.ns-toggle input[type="checkbox"]:checked::after{left:18px;}

/* Info box */
.ns-info{background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:14px 18px;font-size:13px;color:#1d4ed8;margin-bottom:0;}
.ns-info ol{margin:8px 0 0 16px;padding:0;}
.ns-info li{margin-bottom:4px;}
.ns-info code{background:#dbeafe;padding:1px 5px;border-radius:3px;font-family:'DM Mono',monospace;font-size:11.5px;}

/* Badge */
.ns-badge{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:10px;font-size:11px;font-weight:600;}
.nb-active{background:#dcfce7;color:#166534;}
.nb-inactive{background:#f3f4f6;color:#6b7280;}

.ns-action-bar{display:flex;gap:8px;justify-content:flex-end;margin-top:20px;}
</style>

<div class="ns-page">

<!-- ── Header ── -->
<div class="ns-header">
    <div class="ns-header-left">
        <div class="ns-header-icon"><i class="fa fa-cog"></i></div>
        <div>
            <div class="ns-header-title"><?php echo $langs->trans('NotificationSettings'); ?></div>
            <div class="ns-header-sub"><?php echo $langs->trans('NotificationSettingsDesc'); ?></div>
        </div>
    </div>
    <div>
        <a href="<?php echo dol_buildpath('/flotte/notification_center.php', 1); ?>" class="ns-btn ns-btn-ghost"><i class="fa fa-arrow-left"></i> <?php echo $langs->trans('NotificationCenter'); ?></a>
    </div>
</div>

<!-- ── Section 1: Firebase Setup ── -->
<div class="ns-card">
    <div class="ns-card-header">
        <div class="ns-card-icon orange">🔥</div>
        <span class="ns-card-title"><?php echo $langs->trans('FirebaseCloudMessaging'); ?></span>
    </div>
    <div class="ns-card-body">

        <div class="ns-info" style="margin-bottom:20px;">
            <strong><?php echo $langs->trans('HowToSetupFirebase'); ?></strong>
            <ol>
                <li>Go to <a href="https://console.firebase.google.com/" target="_blank">console.firebase.google.com</a> → Create/select your project</li>
                <li>Go to <strong>Project Settings → Service Accounts</strong> → Generate new private key → download the JSON file</li>
                <li>Paste the full JSON content into <em>Service Account JSON</em> below</li>
                <li>Copy your <strong>Project ID</strong> from Project Settings → General</li>
                <li>For Web Push: go to <strong>Project Settings → Cloud Messaging → Web configuration</strong> → copy the <em>VAPID key</em></li>
            </ol>
        </div>

        <form method="POST" action="<?php echo $_SERVER['PHP_SELF']; ?>">
            <input type="hidden" name="action" value="save_firebase">
            <input type="hidden" name="token"  value="<?php echo newToken(); ?>">

            <div class="ns-form-grid">
                <div class="ns-form-group">
                    <label class="ns-label"><?php echo $langs->trans('APIVersion'); ?></label>
                    <select name="use_v1_api" class="ns-input">
                        <option value="1" <?php echo ($cfg['use_v1_api'] ?? 1) == 1 ? 'selected' : ''; ?>><?php echo $langs->trans('FCMv1Recommended'); ?></option>
                        <option value="0" <?php echo ($cfg['use_v1_api'] ?? 1) == 0 ? 'selected' : ''; ?>><?php echo $langs->trans('LegacyHTTPAPI'); ?></option>
                    </select>
                </div>
                <div class="ns-form-group">
                    <label class="ns-label"><?php echo $langs->trans('FirebaseProjectID'); ?></label>
                    <input type="text" name="project_id" class="ns-input" placeholder="my-fleet-app-12345" value="<?php echo dol_escape_htmltag($cfg['project_id'] ?? ''); ?>">
                </div>

                <!-- FCM v1 fields -->
                <div class="ns-form-group full">
                    <label class="ns-label"><?php echo $langs->trans('ServiceAccountJSON'); ?> <small style="text-transform:none;font-weight:400;color:#9ca3af;">(for FCM v1 — paste the full JSON from Firebase)</small></label>
                    <textarea name="service_account_json" class="ns-input" placeholder='{"type":"service_account","project_id":"...","private_key":"-----BEGIN PRIVATE KEY-----\n...","client_email":"...",...}'><?php echo dol_escape_htmltag($cfg['service_account_json'] ?? ''); ?></textarea>
                </div>

                <!-- Legacy fields -->
                <div class="ns-form-group full">
                    <label class="ns-label"><?php echo $langs->trans('ServerKey'); ?> <small style="text-transform:none;font-weight:400;color:#9ca3af;">(Legacy API only — from Firebase → Project Settings → Cloud Messaging)</small></label>
                    <input type="text" name="server_key" class="ns-input" placeholder="AAAA..." value="<?php echo dol_escape_htmltag($cfg['server_key'] ?? ''); ?>">
                </div>

                <div class="ns-form-group full">
                    <label class="ns-label"><?php echo $langs->trans('VAPIDPublicKey'); ?> <small style="text-transform:none;font-weight:400;color:#9ca3af;">(for Web Push — from Cloud Messaging → Web configuration)</small></label>
                    <input type="text" name="vapid_key" class="ns-input" placeholder="BK..." value="<?php echo dol_escape_htmltag($cfg['vapid_key'] ?? ''); ?>">
                </div>
            </div>

            <div class="ns-action-bar">
                <?php if ($svc->isConfigured()): ?>
                    <span style="color:#16a34a;font-size:13px;font-weight:600;align-self:center;"><i class="fa fa-check-circle"></i> <?php echo $langs->trans('FirebaseIsConfigured'); ?></span>
                <?php else: ?>
                    <span style="color:#dc2626;font-size:13px;font-weight:600;align-self:center;"><i class="fa fa-exclamation-circle"></i> <?php echo $langs->trans('NotConfiguredYet'); ?></span>
                <?php endif; ?>
                <button type="submit" class="ns-btn ns-btn-primary"><i class="fa fa-save"></i> <?php echo $langs->trans('SaveFirebaseConfig'); ?></button>
            </div>
        </form>
    </div>
</div>

<!-- ── Section 2: Alert Rules ── -->
<div class="ns-card">
    <div class="ns-card-header">
        <div class="ns-card-icon blue">⚡</div>
        <span class="ns-card-title"><?php echo $langs->trans('AlertRules'); ?></span>
    </div>
    <div class="ns-card-body">

        <!-- Add / Edit Rule Form -->
        <details <?php echo ($action === 'edit_rule' || $action === 'add_rule') ? 'open' : ''; ?> style="margin-bottom:24px;">
            <summary style="cursor:pointer;font-weight:700;font-size:14px;color:#f97316;padding:10px 0;">
                <i class="fa fa-plus-circle"></i> <?php echo $editRule ? $langs->trans('EditRule') : $langs->trans('AddNewAlertRule'); ?>
            </summary>
            <form method="POST" action="<?php echo $_SERVER['PHP_SELF']; ?>" style="margin-top:16px;">
                <input type="hidden" name="action" value="save_rule">
                <input type="hidden" name="token"  value="<?php echo newToken(); ?>">
                <?php if ($editRule): ?>
                    <input type="hidden" name="rowid" value="<?php echo $editRule->rowid; ?>">
                <?php endif; ?>
                <div class="ns-form-grid">
                    <div class="ns-form-group">
                        <label class="ns-label"><?php echo $langs->trans('RuleName'); ?></label>
                        <input type="text" name="rule_name" class="ns-input" required placeholder="e.g. Registration 30-day Warning" value="<?php echo dol_escape_htmltag($editRule->rule_name ?? ''); ?>">
                    </div>
                    <div class="ns-form-group">
                        <label class="ns-label"><?php echo $langs->trans('AlertType'); ?></label>
                        <select name="alert_type" class="ns-input">
                            <?php
                            $typeOpts = array(
                                'registration_expiry' => $langs->trans('VehicleRegistrationExpiry'),
                                'license_expiry'      => $langs->trans('DriverLicenseExpiry'),
                                'insurance_expiry'    => $langs->trans('InsuranceExpiry'),
                                'inspection_due'      => $langs->trans('ScheduledInspectionDue'),
                            );
                            foreach ($typeOpts as $v => $l): ?>
                                <option value="<?php echo $v; ?>" <?php echo ($editRule->alert_type ?? '') == $v ? 'selected' : ''; ?>><?php echo $l; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="ns-form-group">
                        <label class="ns-label"><?php echo $langs->trans('FirstAlertDaysBefore'); ?></label>
                        <input type="number" name="days_before" class="ns-input" min="1" max="365" placeholder="30" value="<?php echo dol_escape_htmltag($editRule->days_before ?? 30); ?>">
                    </div>
                    <div class="ns-form-group">
                        <label class="ns-label"><?php echo $langs->trans('SecondReminderDaysBefore'); ?></label>
                        <input type="number" name="days_before_second" class="ns-input" min="1" max="365" placeholder="7" value="<?php echo dol_escape_htmltag($editRule->days_before_second ?? 7); ?>">
                    </div>
                    <div class="ns-form-group">
                        <label class="ns-label"><?php echo $langs->trans('NotifyChannel'); ?></label>
                        <select name="notify_channel" class="ns-input">
                            <option value="firebase" <?php echo ($editRule->notify_channel ?? '') == 'firebase' ? 'selected' : ''; ?>><?php echo $langs->trans('FirebasePushOnly'); ?></option>
                            <option value="email"    <?php echo ($editRule->notify_channel ?? '') == 'email'    ? 'selected' : ''; ?>><?php echo $langs->trans('EmailOnly'); ?></option>
                            <option value="both"     <?php echo ($editRule->notify_channel ?? 'both') == 'both' ? 'selected' : ''; ?>><?php echo $langs->trans('BothPushAndEmail'); ?></option>
                        </select>
                    </div>
                    <div class="ns-form-group">
                        <label class="ns-label"><?php echo $langs->trans('Priority'); ?></label>
                        <select name="priority" class="ns-input">
                            <option value="1" <?php echo ($editRule->priority ?? 2) == 1 ? 'selected' : ''; ?>><?php echo $langs->trans('Low'); ?></option>
                            <option value="2" <?php echo ($editRule->priority ?? 2) == 2 ? 'selected' : ''; ?>><?php echo $langs->trans('Normal'); ?></option>
                            <option value="3" <?php echo ($editRule->priority ?? 2) == 3 ? 'selected' : ''; ?>><?php echo $langs->trans('High'); ?></option>
                            <option value="4" <?php echo ($editRule->priority ?? 2) == 4 ? 'selected' : ''; ?>><?php echo $langs->trans('Critical'); ?></option>
                        </select>
                    </div>
                    <div class="ns-form-group full">
                        <label class="ns-label"><?php echo $langs->trans('NotifyUsers'); ?> <small style="text-transform:none;font-weight:400;color:#9ca3af;">(comma-separated user IDs, leave blank for all admins)</small></label>
                        <input type="text" name="notify_users" class="ns-input" placeholder="1,2,5" value="<?php echo dol_escape_htmltag($editRule->notify_users ?? ''); ?>">
                    </div>
                    <div class="ns-form-group">
                        <label class="ns-label"><?php echo $langs->trans('Active'); ?></label>
                        <label class="ns-toggle" style="padding-top:8px;">
                            <input type="checkbox" name="is_active" value="1" <?php echo ($editRule->is_active ?? 1) ? 'checked' : ''; ?>>
                            <span style="font-size:13px;color:#374151;"><?php echo $langs->trans('EnableThisRule'); ?></span>
                        </label>
                    </div>
                </div>
                <div class="ns-action-bar">
                    <?php if ($editRule): ?>
                        <a href="<?php echo $_SERVER['PHP_SELF']; ?>" class="ns-btn ns-btn-ghost"><?php echo $langs->trans('Cancel'); ?></a>
                    <?php endif; ?>
                    <button type="submit" class="ns-btn ns-btn-primary"><i class="fa fa-save"></i> <?php echo $editRule ? $langs->trans('UpdateRule') : $langs->trans('AddRule'); ?></button>
                </div>
            </form>
        </details>

        <!-- Rules Table -->
        <?php if (empty($rules)): ?>
            <div style="text-align:center;padding:30px;color:#8b92a9;font-size:13px;">
                <?php echo $langs->trans('NoAlertRulesConfigured'); ?>
            </div>
        <?php else: ?>
        <div style="overflow-x:auto;">
            <table class="ns-table">
                <thead>
                    <tr>
                        <th><?php echo $langs->trans('RuleName'); ?></th>
                        <th><?php echo $langs->trans('Type'); ?></th>
                        <th><?php echo $langs->trans('AlertsDays'); ?></th>
                        <th><?php echo $langs->trans('Channel'); ?></th>
                        <th><?php echo $langs->trans('Priority'); ?></th>
                        <th><?php echo $langs->trans('Status'); ?></th>
                        <th><?php echo $langs->trans('Action'); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rules as $rule): ?>
                <tr>
                    <td style="font-weight:600;"><?php echo dol_escape_htmltag($rule->rule_name); ?></td>
                    <td style="font-size:12px;"><?php echo dol_escape_htmltag(ucwords(str_replace('_', ' ', $rule->alert_type))); ?></td>
                    <td>
                        <span style="background:#eff6ff;color:#1d4ed8;padding:2px 8px;border-radius:5px;font-size:12px;font-weight:600;"><?php echo $rule->days_before; ?>d</span>
                        &nbsp;+&nbsp;
                        <span style="background:#f0fdf4;color:#166534;padding:2px 8px;border-radius:5px;font-size:12px;font-weight:600;"><?php echo $rule->days_before_second; ?>d</span>
                    </td>
                    <td style="font-size:12px;"><?php echo dol_escape_htmltag(ucfirst($rule->notify_channel)); ?></td>
                    <td>
                        <?php
                        $pm = array(1=>$langs->trans('Low'),2=>$langs->trans('Normal'),3=>$langs->trans('High'),4=>$langs->trans('Critical'));
                        $pc = array(1=>'#6b7280',2=>'#2563eb',3=>'#d97706',4=>'#dc2626');
                        $pb = array(1=>'#f3f4f6',2=>'#eff6ff',3=>'#fff7ed',4=>'#fef2f2');
                        $p  = (int) $rule->priority;
                        ?>
                        <span style="background:<?php echo $pb[$p]??'#f3f4f6'; ?>;color:<?php echo $pc[$p]??'#6b7280'; ?>;padding:2px 8px;border-radius:5px;font-size:11px;font-weight:700;"><?php echo $pm[$p]??'Normal'; ?></span>
                    </td>
                    <td><span class="ns-badge <?php echo $rule->is_active ? 'nb-active' : 'nb-inactive'; ?>"><?php echo $rule->is_active ? $langs->trans('Active') : $langs->trans('Inactive'); ?></span></td>
                    <td>
                        <a href="?action=edit_rule&rowid=<?php echo $rule->rowid; ?>" class="ns-btn ns-btn-ghost ns-btn-sm"><i class="fa fa-pen"></i></a>
                        <a href="?action=delete_rule&rowid=<?php echo $rule->rowid; ?>" class="ns-btn ns-btn-danger ns-btn-sm" onclick="return confirm('<?php echo dol_escape_js($langs->trans('ConfirmDeleteRule')); ?>')"><i class="fa fa-trash"></i></a>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ── Section 3: Cron Setup ── -->
<div class="ns-card">
    <div class="ns-card-header">
        <div class="ns-card-icon green">⏰</div>
        <span class="ns-card-title"><?php echo $langs->trans('AutomatedCronJob'); ?></span>
    </div>
    <div class="ns-card-body">
        <p style="font-size:13px;color:#374151;margin:0 0 12px;"><?php echo $langs->trans('CronJobDesc'); ?></p>
        <pre style="background:#1e293b;color:#e2e8f0;padding:16px 20px;border-radius:10px;font-family:'DM Mono',monospace;font-size:13px;overflow-x:auto;margin:0;">
# Run every day at 7:00 AM
0 7 * * * php <?php echo DOL_DOCUMENT_ROOT; ?>/modules/flotte/cron_alerts.php >> /var/log/flotte_alerts.log 2>&1</pre>
        <p style="font-size:12px;color:#8b92a9;margin:10px 0 0;"><?php echo $langs->trans('CronJobDolibarrTip'); ?></p>
    </div>
</div>

</div><!-- ns-page -->

<?php
llxFooter();
$db->close();
?>
<?php
/* Copyright (C) 2024 Your Company */

// Load Dolibarr environment – same as booking_card.php
$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
    $res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1;
$j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) { $i--; $j--; }
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1))."/main.inc.php")) {
    $res = @include substr($tmp, 0, ($i + 1))."/main.inc.php";
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php")) {
    $res = @include dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php";
}
if (!$res && file_exists("../main.inc.php"))      { $res = @include "../main.inc.php"; }
if (!$res && file_exists("../../main.inc.php"))   { $res = @include "../../main.inc.php"; }
if (!$res && file_exists("../../../main.inc.php")){ $res = @include "../../../main.inc.php"; }
if (!$res) { die("Include of main fails"); }

require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';

// Now GETPOST() is defined
if (!function_exists('GETPOST')) {
    function GETPOST($param, $type = 'alpha') {
        return isset($_POST[$param]) ? $_POST[$param] : (isset($_GET[$param]) ? $_GET[$param] : '');
    }
}

// Security check
restrictedArea($user, 'flotte');

$langs->loadLangs(array("flotte@flotte", "other"));
$form = new Form($db);

// Global defaults defined in admin/setup.php — used as fallback when no
// per-vehicle interval has been configured yet for a given service type.
$service_defaults = array(
    'oil_change' => array(
        'km'   => getDolGlobalInt('FLOTTE_SERVICE_OIL_INTERVAL_KM',     10000),
        'days' => getDolGlobalInt('FLOTTE_SERVICE_OIL_INTERVAL_DAYS',     180),
    ),
    'filter_change' => array(
        'km'   => getDolGlobalInt('FLOTTE_SERVICE_FILTER_INTERVAL_KM',  15000),
        'days' => getDolGlobalInt('FLOTTE_SERVICE_FILTER_INTERVAL_DAYS',  365),
    ),
    'general' => array(
        'km'   => getDolGlobalInt('FLOTTE_SERVICE_GENERAL_INTERVAL_KM', 20000),
        'days' => getDolGlobalInt('FLOTTE_SERVICE_GENERAL_INTERVAL_DAYS', 365),
    ),
);

// ---------------------------------------------------------------------
// CREATE TABLES IF NOT EXIST (safe on every page load)
// ---------------------------------------------------------------------
$db->query("CREATE TABLE IF NOT EXISTS ".MAIN_DB_PREFIX."flotte_service_interval (
    rowid INT AUTO_INCREMENT PRIMARY KEY,
    fk_vehicle INT NOT NULL,
    service_type VARCHAR(50) NOT NULL,
    interval_km INT DEFAULT NULL,
    interval_days INT DEFAULT NULL,
    last_service_date DATE DEFAULT NULL,
    last_service_mileage INT DEFAULT NULL,
    enabled TINYINT(1) DEFAULT 1,
    entity INT DEFAULT 1,
    date_creation DATETIME DEFAULT NULL,
    fk_user_creat INT DEFAULT NULL,
    tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_vehicle_type (fk_vehicle, service_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$db->query("CREATE TABLE IF NOT EXISTS ".MAIN_DB_PREFIX."flotte_service_log (
    rowid INT AUTO_INCREMENT PRIMARY KEY,
    fk_vehicle INT NOT NULL,
    service_type VARCHAR(50) NOT NULL,
    performed_date DATE NOT NULL,
    mileage_at_service INT NOT NULL,
    notes TEXT,
    entity INT DEFAULT 1,
    date_creation DATETIME DEFAULT NULL,
    fk_user_creat INT DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Add current_mileage column to vehicle table if not exists
$chk = $db->query("SHOW COLUMNS FROM ".MAIN_DB_PREFIX."flotte_vehicle LIKE 'current_mileage'");
if ($chk && $db->num_rows($chk) == 0) {
    $db->query("ALTER TABLE ".MAIN_DB_PREFIX."flotte_vehicle ADD COLUMN current_mileage INT DEFAULT NULL AFTER initial_mileage");
}

// ---------------------------------------------------------------------
// ACTIONS
// ---------------------------------------------------------------------
$action = GETPOST('action', 'aZ09');
$vehicle_id = GETPOST('vehicle_id', 'int');
$service_type = GETPOST('service_type', 'aZ09');

// If no vehicle selected via POST, check GET
if (empty($vehicle_id)) {
    $vehicle_id = GETPOST('vehicle_selector', 'int');
}

// Save interval settings
if ($action == 'save_interval' && $vehicle_id && $service_type && !empty($user->rights->flotte->write)) {
    $interval_km = GETPOST('interval_km', 'int');
    $interval_days = GETPOST('interval_days', 'int');
    $enabled = GETPOST('enabled', 'int') ? 1 : 0;

    $sql = "INSERT INTO ".MAIN_DB_PREFIX."flotte_service_interval 
            (fk_vehicle, service_type, interval_km, interval_days, enabled, entity, date_creation, fk_user_creat)
            VALUES ($vehicle_id, '$service_type', ".($interval_km ?: "NULL").", ".($interval_days ?: "NULL").", $enabled, ".$conf->entity.", NOW(), ".$user->id.")
            ON DUPLICATE KEY UPDATE
            interval_km = VALUES(interval_km),
            interval_days = VALUES(interval_days),
            enabled = VALUES(enabled)";
    $db->query($sql);
    setEventMessages($langs->trans("SettingsSaved"), null, 'mesgs');
    header("Location: ".$_SERVER['PHP_SELF']."?vehicle_id=".$vehicle_id);
    exit;
}

// Log a performed service
if ($action == 'log_service' && $vehicle_id && $service_type && !empty($user->rights->flotte->write)) {
    $performed_date = GETPOST('performed_date', 'alpha');
    $mileage = GETPOST('mileage', 'int');
    $notes = GETPOST('notes', 'restricthtml');

    if (empty($performed_date)) $performed_date = date('Y-m-d');
    if (empty($mileage)) {
        $sql_m = "SELECT current_mileage FROM ".MAIN_DB_PREFIX."flotte_vehicle WHERE rowid = $vehicle_id";
        $res_m = $db->query($sql_m);
        if ($res_m && $obj_m = $db->fetch_object($res_m)) {
            $mileage = (int)$obj_m->current_mileage;
        }
    }

    $sql = "INSERT INTO ".MAIN_DB_PREFIX."flotte_service_log 
            (fk_vehicle, service_type, performed_date, mileage_at_service, notes, entity, date_creation, fk_user_creat)
            VALUES ($vehicle_id, '$service_type', '$performed_date', ".($mileage ?: 0).", '".$db->escape($notes)."', ".$conf->entity.", NOW(), ".$user->id.")";
    if ($db->query($sql)) {
        $db->query("UPDATE ".MAIN_DB_PREFIX."flotte_service_interval 
                    SET last_service_date = '$performed_date', last_service_mileage = $mileage
                    WHERE fk_vehicle = $vehicle_id AND service_type = '$service_type'");
        setEventMessages($langs->trans("ServiceLogged"), null, 'mesgs');
    } else {
        setEventMessages($langs->trans("Error").": ".$db->lasterror(), null, 'errors');
    }
    header("Location: ".$_SERVER['PHP_SELF']."?vehicle_id=".$vehicle_id);
    exit;
}

// Delete a service log entry
if ($action == 'delete_log' && ($log_id = GETPOST('log_id', 'int')) && !empty($user->rights->flotte->delete)) {
    $db->query("DELETE FROM ".MAIN_DB_PREFIX."flotte_service_log WHERE rowid = $log_id");
    header("Location: ".$_SERVER['PHP_SELF']."?vehicle_id=".$vehicle_id);
    exit;
}

// ---------------------------------------------------------------------
// LOAD DATA FOR SELECTED VEHICLE
// ---------------------------------------------------------------------

// Fetch all vehicles for dropdown
$sql_all_vehicles = "SELECT rowid, ref, maker, model FROM ".MAIN_DB_PREFIX."flotte_vehicle
                     WHERE entity = ".$conf->entity."
                     ORDER BY ref ASC";
$res_all = $db->query($sql_all_vehicles);
$all_vehicles = [];
if ($res_all) {
    while ($obj = $db->fetch_object($res_all)) {
        $all_vehicles[$obj->rowid] = $obj->ref.' - '.$obj->maker.' '.$obj->model;
    }
}

// If no vehicle selected but there is at least one, pick the first
if (empty($vehicle_id) && !empty($all_vehicles)) {
    $vehicle_id = array_key_first($all_vehicles);
}

$vehicle = null;
$intervals = [];
$last_logs = [];

if ($vehicle_id) {
    // Fetch selected vehicle details
    $sql_veh = "SELECT v.rowid, v.ref, v.maker, v.model, v.current_mileage,
                       COALESCE(v.current_mileage, v.initial_mileage, 0) as effective_mileage
                FROM ".MAIN_DB_PREFIX."flotte_vehicle v
                WHERE v.rowid = ".((int)$vehicle_id)." AND v.entity = ".$conf->entity;
    $res_veh = $db->query($sql_veh);
    if ($res_veh && $obj = $db->fetch_object($res_veh)) {
        $vehicle = $obj;
    }
    
    // Fetch intervals for this vehicle
    $sql_int = "SELECT * FROM ".MAIN_DB_PREFIX."flotte_service_interval
                WHERE fk_vehicle = $vehicle_id AND entity = ".$conf->entity;
    $res_int = $db->query($sql_int);
    if ($res_int) {
        while ($obj = $db->fetch_object($res_int)) {
            $intervals[$obj->service_type] = $obj;
        }
    }
    
    // Fetch last service log per type for this vehicle
    $sql_log = "SELECT l1.* FROM ".MAIN_DB_PREFIX."flotte_service_log l1
                INNER JOIN (
                    SELECT service_type, MAX(performed_date) as max_date
                    FROM ".MAIN_DB_PREFIX."flotte_service_log
                    WHERE fk_vehicle = $vehicle_id
                    GROUP BY service_type
                ) l2 ON l1.service_type = l2.service_type AND l1.performed_date = l2.max_date
                WHERE l1.fk_vehicle = $vehicle_id";
    $res_log = $db->query($sql_log);
    if ($res_log) {
        while ($obj = $db->fetch_object($res_log)) {
            $last_logs[$obj->service_type] = $obj;
        }
    }
}

// ---------------------------------------------------------------------
// VIEW
// ---------------------------------------------------------------------
llxHeader('', $langs->trans("ServiceReminder"));
?>
<script>
// Global service-reminder defaults from admin/setup.php – used to pre-fill
// the settings modal when a vehicle has no interval configured yet.
var FLOTTE_SERVICE_DEFAULTS = <?php echo json_encode($service_defaults, JSON_PRETTY_PRINT); ?>;
</script>
<style>
/* Copy the complete CSS from the previous answer (the same as in booking_card but condensed) */
@import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=DM+Mono:wght@400;500&display=swap');
.dc-page * { box-sizing: border-box; }
.dc-page {
    font-family: 'DM Sans', sans-serif;
    max-width: 1400px;
    margin: 0 auto;
    padding: 0 2px 48px;
    color: #1a1f2e;
}
.dc-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 26px 0 22px;
    border-bottom: 1px solid #e8eaf0;
    margin-bottom: 28px;
    flex-wrap: wrap;
}
.dc-header-left { display: flex; align-items: center; gap: 14px; }
.dc-header-icon {
    width: 46px; height: 46px; border-radius: 12px;
    background: rgba(60,71,88,0.1);
    display: flex; align-items: center; justify-content: center;
    font-size: 20px;
}
.dc-header-title { font-size: 21px; font-weight: 700; margin: 0 0 3px; }
.dc-btn {
    display: inline-flex; align-items: center; gap: 7px;
    padding: 8px 16px; border-radius: 6px;
    font-size: 13px; font-weight: 600;
    text-decoration: none; cursor: pointer;
    transition: all 0.15s; border: none;
}
.dc-btn-primary  { background: #3c4758 !important; color: #fff !important; }
.dc-btn-primary:hover  { background: #2a3346 !important; }
.dc-btn-ghost {
    background: #fff !important; color: #5a6482 !important;
    border: 1.5px solid #d1d5e0 !important;
}
.dc-btn-ghost:hover { background: #f5f6fa !important; }
.dc-btn-success { background: #10b981 !important; color: #fff !important; }
.dc-card {
    background: #fff;
    border: 1px solid #e8eaf0;
    border-radius: 12px;
    margin-bottom: 24px;
    overflow: hidden;
}
.dc-card-header {
    display: flex; align-items: center; gap: 10px;
    padding: 14px 20px;
    border-bottom: 1px solid #f0f2f8;
    background: #f7f8fc;
}
.dc-card-header-icon {
    width: 28px; height: 28px; border-radius: 7px;
    display: flex; align-items: center; justify-content: center;
    font-size: 13px;
}
.dc-card-title { font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.7px; color: #8b92a9; }
.dc-table {
    width: 100%;
    border-collapse: collapse;
}
.dc-table th, .dc-table td {
    text-align: left;
    padding: 12px 16px;
    border-bottom: 1px solid #f0f2f8;
    font-size: 13px;
}
.dc-table th {
    font-weight: 600;
    color: #8b92a9;
    text-transform: uppercase;
    font-size: 11px;
    background: #fafbfe;
}
.dc-badge {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 4px 10px; border-radius: 20px;
    font-size: 11.5px; font-weight: 600;
}
.dc-badge.due { background: #fee2e2; color: #b91c1c; }
.dc-badge.warning { background: #fff8ec; color: #b45309; }
.dc-badge.ok { background: #edfaf3; color: #1a7d4a; }
.dc-field-row {
    display: flex;
    flex-wrap: wrap;
    gap: 16px;
    align-items: flex-end;
    margin-bottom: 16px;
}
.dc-field-group {
    flex: 1;
    min-width: 140px;
}
.dc-field-group label {
    display: block;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    color: #8b92a9;
    margin-bottom: 4px;
}
.dc-field-group input, .dc-field-group select {
    width: 100%;
    padding: 8px 12px;
    border: 1.5px solid #e2e5f0;
    border-radius: 8px;
    font-size: 13px;
}
.modal-overlay {
    position: fixed;
    top: 0; left: 0;
    width: 100%; height: 100%;
    background: rgba(0,0,0,0.5);
    display: none;
    justify-content: center;
    align-items: center;
    z-index: 1000;
}
.modal-content {
    background: #fff;
    border-radius: 16px;
    padding: 24px;
    max-width: 500px;
    width: 90%;
}
@media (max-width: 780px) {
    .dc-table th, .dc-table td { padding: 8px 12px; }
    .dc-field-row { flex-direction: column; align-items: stretch; }
}
</style>

<div class="dc-page">

<div class="dc-header">
    <div class="dc-header-left">
        <div class="dc-header-icon"><i class="fa fa-wrench"></i></div>
        <div>
            <div class="dc-header-title"><?php echo $langs->trans("ServiceReminder"); ?></div>
            <div class="dc-header-sub"><?php echo $langs->trans("TrackOilAndFilterChanges"); ?></div>
        </div>
    </div>
    <div>
        <a href="<?php echo dol_buildpath('/flotte/vehicle_list.php', 1); ?>" class="dc-btn dc-btn-ghost"><i class="fa fa-arrow-left"></i> <?php echo $langs->trans("BackToVehicles"); ?></a>
    </div>
</div>

<!-- Vehicle selector -->
<div class="dc-card" style="margin-bottom:20px;">
    <div class="dc-card-header">
        <div class="dc-card-header-icon blue"><i class="fa fa-car"></i></div>
        <span class="dc-card-title"><?php echo $langs->trans("SelectVehicle"); ?></span>
    </div>
    <div class="dc-card-body" style="padding: 16px;">
        <form method="GET" action="<?php echo $_SERVER['PHP_SELF']; ?>" style="margin:0;">
            <div style="display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap;">
                <div style="flex:2; min-width:200px;">
                    <label style="display:block; font-size:11px; font-weight:600; text-transform:uppercase; color:#8b92a9; margin-bottom:4px;"><?php echo $langs->trans("Vehicle"); ?></label>
                    <select name="vehicle_id" id="vehicle_selector" class="flat" style="width:100%;">
                        <option value="">-- <?php echo $langs->trans("SelectAVehicle"); ?> --</option>
                        <?php foreach ($all_vehicles as $vid => $label): ?>
                        <option value="<?php echo $vid; ?>" <?php echo ($vehicle_id == $vid) ? 'selected' : ''; ?>><?php echo dol_escape_htmltag($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <button type="submit" class="dc-btn dc-btn-primary"><i class="fa fa-search"></i> <?php echo $langs->trans("Show"); ?></button>
                </div>
            </div>
        </form>
    </div>
</div>

<?php if ($vehicle_id && !$vehicle): ?>
<div class="dc-card">
    <div class="dc-card-body" style="padding: 40px; text-align: center; color: #8b92a9;">
        <i class="fa fa-exclamation-triangle" style="font-size: 48px; opacity: 0.4;"></i>
        <p><?php echo $langs->trans("VehicleNotFound"); ?></p>
    </div>
</div>
<?php elseif ($vehicle_id && $vehicle): ?>
<!-- Service reminders for selected vehicle -->
<div class="dc-card">
    <div class="dc-card-header">
        <div class="dc-card-header-icon green"><i class="fa fa-car"></i></div>
        <span class="dc-card-title"><?php echo dol_escape_htmltag($vehicle->ref.' - '.$vehicle->maker.' '.$vehicle->model); ?></span>
        <span style="margin-left:auto; font-size:12px; background:#f0f2fa; padding:4px 10px; border-radius:20px;">
            <i class="fa fa-tachometer-alt"></i> <?php echo number_format($vehicle->effective_mileage); ?> km
        </span>
    </div>
    <div style="padding: 0;">
        <table class="dc-table">
            <thead>
                <tr><th><?php echo $langs->trans("ServiceType"); ?></th>
                <th><?php echo $langs->trans("IntervalKm"); ?></th>
                <th><?php echo $langs->trans("IntervalDays"); ?></th>
                <th><?php echo $langs->trans("LastService"); ?></th>
                <th><?php echo $langs->trans("Status"); ?></th>
                <th style="width: 100px;"></th>
            </tr>
            </thead>
            <tbody>
            <?php
            $service_types = [
                'oil_change' => $langs->trans("OilChange"),
                'filter_change' => $langs->trans("FilterChange"),
                'general' => $langs->trans("GeneralService")
            ];
            foreach ($service_types as $type_code => $type_label):
                $interval = isset($intervals[$type_code]) ? $intervals[$type_code] : null;
                $last = isset($last_logs[$type_code]) ? $last_logs[$type_code] : null;
                $last_date = $last ? $last->performed_date : ($interval ? $interval->last_service_date : null);
                $last_mileage = $last ? $last->mileage_at_service : ($interval ? $interval->last_service_mileage : null);
                
                $due_km = false;
                $due_days = false;
                $status_class = "ok";
                $status_text = $langs->trans("UpToDate");
                
                if ($interval && $interval->enabled) {
                    $now = time();
                    $last_ts = $last_date ? strtotime($last_date) : null;
                    $current_mileage = $vehicle->effective_mileage;
                    
                    if ($interval->interval_km && $last_mileage !== null) {
                        $km_since = $current_mileage - $last_mileage;
                        if ($km_since >= $interval->interval_km) {
                            $due_km = true;
                        }
                    }
                    if ($interval->interval_days && $last_ts) {
                        $days_since = floor(($now - $last_ts) / 86400);
                        if ($days_since >= $interval->interval_days) {
                            $due_days = true;
                        }
                    }
                    
                    if ($due_km || $due_days) {
                        $status_class = "due";
                        $status_text = $langs->trans("DueNow");
                    } elseif (($interval->interval_km && $km_since > $interval->interval_km * 0.9) || 
                              ($interval->interval_days && $days_since > $interval->interval_days * 0.9)) {
                        $status_class = "warning";
                        $status_text = $langs->trans("DueSoon");
                    }
                } elseif (!$interval || !$interval->enabled) {
                    $status_class = "warning";
                    $status_text = $langs->trans("NotConfigured");
                }
                
                $interval_km_display = $interval && $interval->interval_km ? number_format($interval->interval_km).' km' : '—';
                $interval_days_display = $interval && $interval->interval_days ? $interval->interval_days.' '.$langs->trans("Days") : '—';
                $last_info = $last_date ? dol_print_date($last_date, 'day').' / '.number_format($last_mileage).' km' : ($last_mileage ? number_format($last_mileage).' km' : $langs->trans("Never"));
            ?>
            <tr>
                <td><strong><?php echo $type_label; ?></strong></td>
                <td><?php echo $interval_km_display; ?></td>
                <td><?php echo $interval_days_display; ?></td>
                <td><?php echo $last_info; ?></td>
                <td><span class="dc-badge <?php echo $status_class; ?>"><?php echo $status_text; ?></span></td>
                <td>
                    <button class="dc-btn dc-btn-ghost" style="padding:4px 10px;" onclick="openSettings(<?php echo $vehicle_id; ?>,'<?php echo $type_code; ?>')"><i class="fa fa-cog"></i></button>
                    <button class="dc-btn dc-btn-success" style="padding:4px 10px;" onclick="openLogForm(<?php echo $vehicle_id; ?>,'<?php echo $type_code; ?>')"><i class="fa fa-check"></i> <?php echo $langs->trans("Log"); ?></button>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <div style="padding: 12px 16px; border-top: 1px solid #f0f2f8; font-size: 12px; color: #6c757d;">
            <i class="fa fa-info-circle"></i> <?php echo $langs->trans("MileageUpdatedFromCompletedBookings"); ?>
        </div>
    </div>
</div>

<!-- Service history log -->
<?php
$sql_history = "SELECT * FROM ".MAIN_DB_PREFIX."flotte_service_log
                WHERE fk_vehicle = $vehicle_id
                ORDER BY performed_date DESC LIMIT 20";
$res_history = $db->query($sql_history);
$history = [];
if ($res_history) {
    while ($h = $db->fetch_object($res_history)) {
        $history[] = $h;
    }
}
if (!empty($history)):
?>
<div class="dc-card">
    <div class="dc-card-header">
        <div class="dc-card-header-icon purple"><i class="fa fa-history"></i></div>
        <span class="dc-card-title"><?php echo $langs->trans("ServiceHistory"); ?></span>
    </div>
    <div style="padding: 0;">
        <table class="dc-table">
            <thead>
                <tr><th><?php echo $langs->trans("Date"); ?></th>
                <th><?php echo $langs->trans("ServiceType"); ?></th>
                <th><?php echo $langs->trans("Mileage"); ?> (km)</th>
                <th><?php echo $langs->trans("Notes"); ?></th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($history as $h): ?>
            <tr>
                <td><?php echo dol_print_date($h->performed_date, 'day'); ?></td>
                <td><?php echo $langs->trans(ucfirst(str_replace('_', ' ', $h->service_type))); ?></td>
                <td><?php echo number_format($h->mileage_at_service); ?></td>
                <td><?php echo dol_escape_htmltag($h->notes); ?></td>
                <td>
                    <?php if (!empty($user->rights->flotte->delete)): ?>
                    <a href="<?php echo $_SERVER['PHP_SELF'].'?action=delete_log&log_id='.$h->rowid.'&vehicle_id='.$vehicle_id; ?>" onclick="return confirm('<?php echo $langs->trans("ConfirmDelete"); ?>');" class="dc-btn dc-btn-ghost" style="padding:2px 8px;"><i class="fa fa-trash"></i></a>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php elseif (empty($vehicle_id) && !empty($all_vehicles)): ?>
<div class="dc-card">
    <div class="dc-card-body" style="padding: 40px; text-align: center; color: #8b92a9;">
        <i class="fa fa-hand-point-left" style="font-size: 48px; opacity: 0.4;"></i>
        <p><?php echo $langs->trans("SelectAVehicleFromAbove"); ?></p>
    </div>
</div>
<?php else: ?>
<div class="dc-card">
    <div class="dc-card-body" style="padding: 40px; text-align: center; color: #8b92a9;">
        <i class="fa fa-truck" style="font-size: 48px; opacity: 0.4;"></i>
        <p><?php echo $langs->trans("NoVehiclesFound"); ?></p>
        <a href="<?php echo dol_buildpath('/flotte/vehicle_card.php?action=create', 1); ?>" class="dc-btn dc-btn-primary"><?php echo $langs->trans("AddVehicle"); ?></a>
    </div>
</div>
<?php endif; ?>

</div> <!-- .dc-page -->

<!-- Modals -->
<div id="settingsModal" class="modal-overlay">
    <div class="modal-content">
        <h3 style="margin-top:0;"><?php echo $langs->trans("ServiceIntervalSettings"); ?></h3>
        <form method="POST" action="">
            <input type="hidden" name="action" value="save_interval">
            <input type="hidden" name="vehicle_id" id="settings_vehicle_id">
            <input type="hidden" name="service_type" id="settings_service_type">
            <div class="dc-field-row">
                <div class="dc-field-group">
                    <label><?php echo $langs->trans("IntervalKilometers"); ?></label>
                    <input type="number" name="interval_km" id="settings_km" step="100" placeholder="e.g., 10000">
                </div>
                <div class="dc-field-group">
                    <label><?php echo $langs->trans("IntervalDays"); ?></label>
                    <input type="number" name="interval_days" id="settings_days" placeholder="e.g., 180">
                </div>
                <div class="dc-field-group">
                    <label><?php echo $langs->trans("Enabled"); ?></label>
                    <select name="enabled" id="settings_enabled">
                        <option value="1"><?php echo $langs->trans("Yes"); ?></option>
                        <option value="0"><?php echo $langs->trans("No"); ?></option>
                    </select>
                </div>
            </div>
            <div style="text-align: right; margin-top: 20px;">
                <button type="button" class="dc-btn dc-btn-ghost" onclick="closeModal('settingsModal')"><?php echo $langs->trans("Cancel"); ?></button>
                <button type="submit" class="dc-btn dc-btn-primary"><?php echo $langs->trans("Save"); ?></button>
            </div>
        </form>
    </div>
</div>

<div id="logModal" class="modal-overlay">
    <div class="modal-content">
        <h3 style="margin-top:0;"><?php echo $langs->trans("LogServicePerformed"); ?></h3>
        <form method="POST" action="">
            <input type="hidden" name="action" value="log_service">
            <input type="hidden" name="vehicle_id" id="log_vehicle_id">
            <input type="hidden" name="service_type" id="log_service_type">
            <div class="dc-field-row">
                <div class="dc-field-group">
                    <label><?php echo $langs->trans("DatePerformed"); ?></label>
                    <?php echo $form->selectDate('', 'performed_date', 0, 0, 1, '', 1, 0); ?>
                </div>
                <div class="dc-field-group">
                    <label><?php echo $langs->trans("MileageAtService"); ?> (km)</label>
                    <input type="number" name="mileage" id="log_mileage" step="1" placeholder="<?php echo $langs->trans("AutoFromVehicle"); ?>">
                </div>
            </div>
            <div class="dc-field-group">
                <label><?php echo $langs->trans("Notes"); ?></label>
                <textarea name="notes" rows="3" style="width:100%; border:1.5px solid #e2e5f0; border-radius:8px; padding:8px;"></textarea>
            </div>
            <div style="text-align: right; margin-top: 20px;">
                <button type="button" class="dc-btn dc-btn-ghost" onclick="closeModal('logModal')"><?php echo $langs->trans("Cancel"); ?></button>
                <button type="submit" class="dc-btn dc-btn-primary"><?php echo $langs->trans("LogService"); ?></button>
            </div>
        </form>
    </div>
</div>

<script>
function openSettings(vehicleId, serviceType) {
    document.getElementById('settings_vehicle_id').value = vehicleId;
    document.getElementById('settings_service_type').value = serviceType;

    // Defaults defined in admin/setup.php for this service type (fallback when
    // no per-vehicle record exists yet).
    var defaults = (FLOTTE_SERVICE_DEFAULTS && FLOTTE_SERVICE_DEFAULTS[serviceType]) || {};

    fetch(window.location.href + '?get_interval=1&vehicle_id='+vehicleId+'&service_type='+serviceType)
        .then(r => r.json())
        .then(data => {
            // Use the per-vehicle value when present; fall back to the global
            // admin default so the modal is never shown with empty fields.
            document.getElementById('settings_km').value =
                (data.interval_km !== null && data.interval_km !== undefined)
                    ? data.interval_km
                    : (defaults.km || '');
            document.getElementById('settings_days').value =
                (data.interval_days !== null && data.interval_days !== undefined)
                    ? data.interval_days
                    : (defaults.days || '');
            document.getElementById('settings_enabled').value = data.enabled ? 1 : 0;
        })
        .catch(() => {
            // Fetch failed – still pre-fill with admin defaults so the modal
            // is usable offline or on first use.
            document.getElementById('settings_km').value = defaults.km || '';
            document.getElementById('settings_days').value = defaults.days || '';
        });

    document.getElementById('settingsModal').style.display = 'flex';
}
function openLogForm(vehicleId, serviceType) {
    document.getElementById('log_vehicle_id').value = vehicleId;
    document.getElementById('log_service_type').value = serviceType;
    document.getElementById('log_mileage').value = '';
    document.getElementById('logModal').style.display = 'flex';
}
function closeModal(id) {
    document.getElementById(id).style.display = 'none';
}
window.onclick = function(e) {
    if (e.target.classList.contains('modal-overlay')) {
        e.target.style.display = 'none';
    }
}
</script>

<?php
// AJAX handler for interval data
if (GETPOST('get_interval', 'int') == 1) {
    $vid = GETPOST('vehicle_id', 'int');
    $stype = GETPOST('service_type', 'aZ09');
    $sql = "SELECT interval_km, interval_days, enabled FROM ".MAIN_DB_PREFIX."flotte_service_interval 
            WHERE fk_vehicle = $vid AND service_type = '$stype' AND entity = ".$conf->entity;
    $res = $db->query($sql);
    $data = ['interval_km'=>null, 'interval_days'=>null, 'enabled'=>1];
    if ($res && $obj = $db->fetch_object($res)) {
        $data['interval_km'] = $obj->interval_km;
        $data['interval_days'] = $obj->interval_days;
        $data['enabled'] = $obj->enabled;
    }
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

llxFooter();
$db->close();
?>
<?php
/* Copyright (C) 2024 Your Company
 * Vehicle Maintenance Alert Card
 */

$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
    $res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1; $j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) { $i--; $j--; }
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i+1))."/main.inc.php")) { $res = @include substr($tmp, 0, ($i+1))."/main.inc.php"; }
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i+1)))."/main.inc.php")) { $res = @include dirname(substr($tmp, 0, ($i+1)))."/main.inc.php"; }
if (!$res && file_exists("../main.inc.php"))       { $res = @include "../main.inc.php"; }
if (!$res && file_exists("../../main.inc.php"))    { $res = @include "../../main.inc.php"; }
if (!$res && file_exists("../../../main.inc.php")) { $res = @include "../../../main.inc.php"; }
if (!$res) { die("Include of main fails"); }

require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';
$langs->loadLangs(array("flotte@flotte", "other"));

restrictedArea($user, 'flotte');

$action     = GETPOST('action', 'aZ09') ?: 'view';
$fk_vehicle = GETPOST('fk_vehicle', 'int');
$cancel     = GETPOST('cancel', 'alpha');
if ($cancel) { $action = 'view'; }

/* ── Maintenance Interval Definitions ───────────────────────────────────── */
$maintenanceItems = array(
    'oil_change'      => array('label'=>'Oil Change',          'icon'=>'fa-oil-can',         'interval_km'=>30000, 'interval_days'=>180,  'warning_km'=>500,  'warning_days'=>14,  'critical'=>true),
    'oil_filter'      => array('label'=>'Oil Filter',          'icon'=>'fa-filter',          'interval_km'=>5000,  'interval_days'=>180,  'warning_km'=>500,  'warning_days'=>14,  'critical'=>true),
    'air_filter'      => array('label'=>'Air Filter',          'icon'=>'fa-wind',            'interval_km'=>15000, 'interval_days'=>365,  'warning_km'=>1000, 'warning_days'=>21,  'critical'=>false),
    'cabin_filter'    => array('label'=>'Cabin Air Filter',    'icon'=>'fa-snowflake',       'interval_km'=>15000, 'interval_days'=>365,  'warning_km'=>1000, 'warning_days'=>21,  'critical'=>false),
    'tyre_rotation'   => array('label'=>'Tyre Rotation',       'icon'=>'fa-circle-notch',    'interval_km'=>10000, 'interval_days'=>180,  'warning_km'=>800,  'warning_days'=>14,  'critical'=>false),
    'brake_fluid'     => array('label'=>'Brake Fluid',         'icon'=>'fa-tint',            'interval_km'=>40000, 'interval_days'=>730,  'warning_km'=>2000, 'warning_days'=>30,  'critical'=>true),
    'brake_pads'      => array('label'=>'Brake Pads',          'icon'=>'fa-stop-circle',     'interval_km'=>30000, 'interval_days'=>730,  'warning_km'=>2000, 'warning_days'=>30,  'critical'=>true),
    'coolant'         => array('label'=>'Coolant Flush',       'icon'=>'fa-thermometer-half','interval_km'=>50000, 'interval_days'=>730,  'warning_km'=>3000, 'warning_days'=>30,  'critical'=>false),
    'transmission'    => array('label'=>'Transmission Fluid',  'icon'=>'fa-cogs',            'interval_km'=>60000, 'interval_days'=>1095, 'warning_km'=>3000, 'warning_days'=>30,  'critical'=>false),
    'spark_plugs'     => array('label'=>'Spark Plugs',         'icon'=>'fa-bolt',            'interval_km'=>30000, 'interval_days'=>730,  'warning_km'=>2000, 'warning_days'=>30,  'critical'=>false),
    'timing_belt'     => array('label'=>'Timing Belt',         'icon'=>'fa-clock',           'interval_km'=>80000, 'interval_days'=>1825, 'warning_km'=>5000, 'warning_days'=>60,  'critical'=>true),
    'battery'         => array('label'=>'Battery Check',       'icon'=>'fa-battery-half',    'interval_km'=>0,     'interval_days'=>365,  'warning_km'=>0,    'warning_days'=>30,  'critical'=>false),
    'wheel_alignment' => array('label'=>'Wheel Alignment',     'icon'=>'fa-arrows-alt-h',    'interval_km'=>20000, 'interval_days'=>365,  'warning_km'=>1500, 'warning_days'=>14,  'critical'=>false),
    'ac_service'      => array('label'=>'A/C Service',         'icon'=>'fa-fan',             'interval_km'=>0,     'interval_days'=>730,  'warning_km'=>0,    'warning_days'=>30,  'critical'=>false),
);

/* ── Load Vehicles ──────────────────────────────────────────────────────── */
$vehicles = array();
$sql = "SELECT rowid, ref, CONCAT(maker, ' ', model) AS label FROM ".MAIN_DB_PREFIX."flotte_vehicle WHERE entity = ".((int)$conf->entity)." ORDER BY maker, model";
$resql = $db->query($sql);
if ($resql) {
    while ($obj = $db->fetch_object($resql)) {
        $vehicles[$obj->rowid] = array('ref' => $obj->ref, 'label' => $obj->label);
    }
}

/* ── Load Selected Vehicle ─────────────────────────────────────────────── */
$vehicle = null; $maintenanceRecords = array(); $currentKm = 0;

if ($fk_vehicle > 0) {
    $sql = "SELECT rowid, ref, CONCAT(maker, ' ', model) AS label FROM ".MAIN_DB_PREFIX."flotte_vehicle WHERE rowid=".((int)$fk_vehicle)." AND entity=".((int)$conf->entity);
    $resql = $db->query($sql);
    if ($resql && $db->num_rows($resql) > 0) { $vehicle = $db->fetch_object($resql); }

    $sql = "SELECT MAX(meter_in) as last_km FROM ".MAIN_DB_PREFIX."flotte_inspection WHERE fk_vehicle=".((int)$fk_vehicle)." AND entity=".((int)$conf->entity);
    $resql = $db->query($sql);
    if ($resql) { $obj = $db->fetch_object($resql); if ($obj && $obj->last_km) $currentKm = (int)$obj->last_km; }

    $sql = "SELECT service_type, meter_in AS last_km, DATE(datetime_in) AS last_date, next_km, next_date, technician, service_notes AS notes FROM ".MAIN_DB_PREFIX."flotte_inspection WHERE fk_vehicle=".((int)$fk_vehicle)." AND entity=".((int)$conf->entity)." AND service_type IS NOT NULL ORDER BY datetime_in DESC";
    $resql = $db->query($sql);
    if ($resql) {
        while ($obj = $db->fetch_object($resql)) {
            if (!isset($maintenanceRecords[$obj->service_type])) $maintenanceRecords[$obj->service_type] = $obj;
        }
    }
}

/* ── Save Maintenance ───────────────────────────────────────────────────── */
if ($action === 'save_maintenance' && $fk_vehicle > 0) {
    // CSRF check — version-safe: checkToken() only exists in Dolibarr 16+
    $postedToken  = GETPOST('token', 'alpha');
    $sessionToken = isset($_SESSION['newtoken']) ? $_SESSION['newtoken'] : (isset($_SESSION['token']) ? $_SESSION['token'] : '');
    if (function_exists('checkToken')) {
        if (!checkToken()) {
            setEventMessages($langs->trans("InvalidToken"), null, 'errors');
            header("Location: ".$_SERVER['PHP_SELF']."?fk_vehicle=".$fk_vehicle);
            exit;
        }
    } elseif (empty($postedToken) || empty($sessionToken) || $postedToken !== $sessionToken) {
        setEventMessages($langs->trans("InvalidToken"), null, 'errors');
        header("Location: ".$_SERVER['PHP_SELF']."?fk_vehicle=".$fk_vehicle);
        exit;
    }

    $service_type = GETPOST('service_type', 'alpha');
    $last_km      = (int) GETPOST('last_km', 'int');
    $last_date    = GETPOST('last_date', 'alpha');
    $technician   = GETPOST('technician', 'alpha');
    $notes        = GETPOST('notes', 'restricthtml');

    // Validate required fields before touching the DB
    if (empty($service_type) || !isset($maintenanceItems[$service_type])) {
        setEventMessages('Invalid service type.', null, 'errors');
        header("Location: ".$_SERVER['PHP_SELF']."?fk_vehicle=".$fk_vehicle);
        exit;
    }
    if (empty($last_date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $last_date)) {
        setEventMessages('Invalid or missing service date.', null, 'errors');
        header("Location: ".$_SERVER['PHP_SELF']."?fk_vehicle=".$fk_vehicle);
        exit;
    }

    $item      = $maintenanceItems[$service_type];
    $next_km   = ($item['interval_km'] > 0) ? $last_km + $item['interval_km'] : 0;
    $next_date = '';
    if ($item['interval_days'] > 0) {
        $ts = strtotime($last_date);
        if ($ts !== false) $next_date = date('Y-m-d', strtotime("+{$item['interval_days']} days", $ts));
    }

    $db->begin();

    // Check if a record already exists for this vehicle + service type
    $sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."flotte_inspection"
         ." WHERE fk_vehicle=".((int)$fk_vehicle)
         ." AND service_type='".$db->escape($service_type)."'"
         ." AND entity=".((int)$conf->entity);
    $resql = $db->query($sql);

    if (!$resql) {
        $db->rollback();
        setEventMessages('DB lookup failed: '.$db->lasterror(), null, 'errors');
        header("Location: ".$_SERVER['PHP_SELF']."?fk_vehicle=".$fk_vehicle);
        exit;
    }

    if ($db->num_rows($resql) > 0) {
        $obj = $db->fetch_object($resql);
        $sql = "UPDATE ".MAIN_DB_PREFIX."flotte_inspection SET"
             ." meter_in=".((int)$last_km).","
             ." datetime_in='".$db->escape($last_date)."',"
             ." next_km=".((int)$next_km).","
             ." next_date='".$db->escape($next_date)."',"
             ." technician='".$db->escape($technician)."',"
             ." service_notes='".$db->escape($notes)."',"
             ." tms=NOW()"
             ." WHERE rowid=".((int)$obj->rowid);
    } else {
        $sql = "INSERT INTO ".MAIN_DB_PREFIX."flotte_inspection"
             ." (ref,entity,fk_vehicle,service_type,meter_in,datetime_in,next_km,next_date,technician,service_notes)"
             ." VALUES ("
             ."'".$db->escape('MAINT-'.$fk_vehicle.'-'.strtoupper($service_type).'-'.date('YmdHis'))."',"
             .((int)$conf->entity).","
             .((int)$fk_vehicle).","
             ."'".$db->escape($service_type)."',"
             .((int)$last_km).","
             ."'".$db->escape($last_date)."',"
             .((int)$next_km).","
             ."'".$db->escape($next_date)."',"
             ."'".$db->escape($technician)."',"
             ."'".$db->escape($notes)."')";
    }

    if ($db->query($sql)) {
        $db->commit();
        setEventMessages($langs->trans("MaintenanceSaved"), null, 'mesgs');
    } else {
        $db->rollback();
        // Show the real DB error so it's actionable
        setEventMessages('Save failed: '.$db->lasterror(), null, 'errors');
    }

    header("Location: ".$_SERVER['PHP_SELF']."?fk_vehicle=".$fk_vehicle);
    exit;
}

/* ── Alert Status ───────────────────────────────────────────────────────── */
function getAlertStatus($item, $record, $currentKm) {
    if (!$record) return array('status'=>'unknown','km_remaining'=>null,'days_remaining'=>null,'progress'=>0);
    $status = 'ok'; $kmRemaining = null; $daysRemaining = null; $progress = 0;
    if ($item['interval_km'] > 0 && $record->next_km > 0) {
        $kmRemaining = (int)$record->next_km - $currentKm;
        $progress    = min(100, max(0, (($currentKm - (int)$record->last_km) / $item['interval_km']) * 100));
        if ($kmRemaining <= 0)                       $status = 'critical';
        elseif ($kmRemaining <= $item['warning_km']) $status = 'warning';
    }
    if ($item['interval_days'] > 0 && !empty($record->next_date)) {
        $daysRemaining = (int)((strtotime($record->next_date) - time()) / 86400);
        if ($daysRemaining <= 0 && $status !== 'critical')               $status = 'critical';
        elseif ($daysRemaining <= $item['warning_days'] && $status==='ok') $status = 'warning';
    }
    return array('status'=>$status,'km_remaining'=>$kmRemaining,'days_remaining'=>$daysRemaining,'progress'=>$progress);
}

$criticalCount=0; $warningCount=0; $okCount=0; $unknownCount=0; $alertData=array();
foreach ($maintenanceItems as $key => $item) {
    $record = isset($maintenanceRecords[$key]) ? $maintenanceRecords[$key] : null;
    $alert  = getAlertStatus($item, $record, $currentKm);
    $alertData[$key] = $alert;
    if      ($alert['status']==='critical') $criticalCount++;
    elseif  ($alert['status']==='warning')  $warningCount++;
    elseif  ($alert['status']==='ok')       $okCount++;
    else                                    $unknownCount++;
}

if ($criticalCount>0)    { $overallStatus='critical'; }
elseif ($warningCount>0) { $overallStatus='warning'; }
else                     { $overallStatus='ok'; }

// Map overall status to a page subtitle indicator used in the header
$overallStatusLabel = array(
    'critical' => '🔴 '.$langs->trans('ServiceOverdue'),
    'warning'  => '🟡 '.$langs->trans('ServiceDueSoon'),
    'ok'       => '🟢 '.$langs->trans('AllServicesUpToDate'),
);
$pageSubTitle = $vehicle ? ($overallStatusLabel[$overallStatus] ?? '') : '';

llxHeader('', $langs->trans('MaintenanceAlerts').($pageSubTitle ? ' — '.$pageSubTitle : ''), '');
?>
<style>
@import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=DM+Mono:wght@400;500&display=swap');

.mc-page * { box-sizing: border-box; }
.mc-page {
    font-family: 'DM Sans', sans-serif;
    max-width: 1160px;
    margin: 0 auto;
    padding: 0 2px 48px;
    color: #1a1f2e;
}

/* ── Page header ── */
.dc-header {
    display: flex; align-items: center; justify-content: space-between;
    padding: 26px 0 22px;
    border-bottom: 1px solid #e8eaf0;
    margin-bottom: 24px;
    gap: 16px; flex-wrap: wrap;
}
.dc-header-left  { display: flex; align-items: center; gap: 14px; }
.dc-header-icon  {
    width: 46px; height: 46px; border-radius: 12px;
    background: rgba(60,71,88,0.1);
    display: flex; align-items: center; justify-content: center;
    color: #3c4758; font-size: 20px; flex-shrink: 0;
}
.dc-header-title { font-size: 21px; font-weight: 700; color: #1a1f2e; margin: 0 0 3px; letter-spacing: -0.3px; }
.dc-header-sub   { font-size: 12.5px; color: #8b92a9; font-weight: 400; }
.dc-header-actions { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }

/* ── Buttons ── */
.dc-btn {
    display: inline-flex; align-items: center; gap: 7px;
    padding: 8px 16px; border-radius: 6px;
    font-size: 13px; font-weight: 600;
    text-decoration: none !important; cursor: pointer;
    font-family: 'DM Sans', sans-serif; white-space: nowrap;
    transition: all 0.15s ease; border: none;
}
.dc-btn-primary { background: #3c4758 !important; color: #fff !important; }
.dc-btn-primary:hover { background: #2a3346 !important; color: #fff !important; }
.dc-btn-ghost {
    background: #fff !important; color: #5a6482 !important;
    border: 1.5px solid #d1d5e0 !important;
}
.dc-btn-ghost:hover { background: #f5f6fa !important; color: #2d3748 !important; }
button.dc-btn-primary { background: #3c4758 !important; color: #fff !important; border: none !important; }
button.dc-btn-primary:hover { background: #2a3346 !important; }
button.dc-btn-ghost { background: #fff !important; color: #5a6482 !important; border: 1.5px solid #d1d5e0 !important; }

/* ── Cards ── */
.dc-card {
    background: #fff;
    border: 1px solid #e8eaf0;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 1px 6px rgba(0,0,0,0.04);
    margin-bottom: 16px;
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
    font-size: 13px; flex-shrink: 0;
}
.dc-card-header-icon.blue  { background: rgba(60,71,88,0.1);  color: #3c4758; }
.dc-card-header-icon.green { background: rgba(22,163,74,0.1);  color: #16a34a; }
.dc-card-header-icon.amber { background: rgba(217,119,6,0.1);  color: #d97706; }
.dc-card-header-icon.red   { background: rgba(220,38,38,0.1);  color: #dc2626; }
.dc-card-title { font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.7px; color: #8b92a9; }

/* ── Vehicle selector ── */
.mc-selector-row {
    display: flex; align-items: center; gap: 16px; flex-wrap: wrap;
    padding: 16px 20px;
}
.mc-selector-row label {
    font-size: 12px; font-weight: 600; color: #8b92a9;
    text-transform: uppercase; letter-spacing: 0.5px; white-space: nowrap;
}
.mc-selector-row select {
    padding: 8px 12px !important;
    border: 1.5px solid #e2e5f0 !important;
    border-radius: 8px !important;
    font-size: 13px !important;
    font-family: 'DM Sans', sans-serif !important;
    color: #2d3748 !important;
    background: #fafbfe !important;
    outline: none !important;
    transition: border-color 0.15s !important;
    min-width: 260px; flex: 1; cursor: pointer;
}
.mc-selector-row select:focus { border-color: #3c4758 !important; box-shadow: 0 0 0 3px rgba(60,71,88,0.1) !important; }
.mc-odometer {
    display: flex; align-items: center; gap: 10px;
    background: rgba(60,71,88,0.06); border: 1px solid #e2e5f0;
    border-radius: 8px; padding: 8px 16px; margin-left: auto;
}
.mc-odometer-icon { color: #3c4758; font-size: 15px; }
.mc-odometer-val  { font-family: 'DM Mono', monospace; font-size: 17px; font-weight: 600; color: #3c4758; line-height: 1; }
.mc-odometer-lbl  { font-size: 10px; letter-spacing: 1px; text-transform: uppercase; color: #8b92a9; font-weight: 500; }

/* ── Alert banners ── */
.mc-alert {
    display: flex; align-items: flex-start; gap: 14px;
    padding: 14px 20px; border-left-width: 3px; border-left-style: solid;
}
.mc-alert + .mc-alert { border-top: 1px solid #f0f2f8; }
.mc-alert.critical { background: #fff5f5; border-left-color: #dc2626; }
.mc-alert.warning  { background: #fffbeb; border-left-color: #d97706; }
.mc-alert.ok       { background: #f0fdf4; border-left-color: #16a34a; }
.mc-alert-icon {
    width: 30px; height: 30px; border-radius: 7px;
    display: flex; align-items: center; justify-content: center;
    font-size: 13px; flex-shrink: 0; margin-top: 1px;
}
.mc-alert.critical .mc-alert-icon { background: rgba(220,38,38,0.1);  color: #dc2626; }
.mc-alert.warning  .mc-alert-icon { background: rgba(217,119,6,0.1);  color: #d97706; }
.mc-alert.ok       .mc-alert-icon { background: rgba(22,163,74,0.1);  color: #16a34a; }
.mc-alert-body { flex: 1; }
.mc-alert-title {
    font-size: 12.5px; font-weight: 700; text-transform: uppercase;
    letter-spacing: 0.4px; margin-bottom: 6px;
}
.mc-alert.critical .mc-alert-title { color: #dc2626; }
.mc-alert.warning  .mc-alert-title { color: #d97706; }
.mc-alert.ok       .mc-alert-title { color: #16a34a; }
.mc-alert-tags { display: flex; flex-wrap: wrap; gap: 5px; }
.mc-alert-tag {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 2px 8px; border-radius: 4px;
    font-size: 11px; font-weight: 600;
}
.mc-alert.critical .mc-alert-tag { background: rgba(220,38,38,0.08); color: #b91c1c; border: 1px solid rgba(220,38,38,0.15); }
.mc-alert.warning  .mc-alert-tag { background: rgba(217,119,6,0.08);  color: #b45309; border: 1px solid rgba(217,119,6,0.15); }

/* ── Stats row ── */
.mc-stats-grid {
    display: grid; grid-template-columns: repeat(4,1fr);
    gap: 0; border-bottom: 1px solid #f0f2f8;
}
@media(max-width:640px) { .mc-stats-grid { grid-template-columns: repeat(2,1fr); } }
.mc-stat-tile {
    display: flex; align-items: center; gap: 12px;
    padding: 16px 20px; border-right: 1px solid #f0f2f8;
}
.mc-stat-tile:last-child { border-right: none; }
.mc-stat-bar { width: 4px; height: 32px; border-radius: 2px; flex-shrink: 0; }
.mc-stat-bar.red   { background: #dc2626; }
.mc-stat-bar.amber { background: #d97706; }
.mc-stat-bar.green { background: #16a34a; }
.mc-stat-bar.muted { background: #d1d5e0; }
.mc-stat-num { font-family: 'DM Mono', monospace; font-size: 26px; font-weight: 600; line-height: 1; }
.mc-stat-num.red   { color: #dc2626; }
.mc-stat-num.amber { color: #d97706; }
.mc-stat-num.green { color: #16a34a; }
.mc-stat-num.muted { color: #c4c9d8; }
.mc-stat-lbl { font-size: 10px; letter-spacing: 1px; text-transform: uppercase; color: #8b92a9; font-weight: 600; margin-top: 2px; }

/* ── Section separator ── */
.mc-section-sep {
    display: flex; align-items: center; gap: 10px;
    margin: 24px 0 10px;
}
.mc-section-sep-line { flex: 1; height: 1px; background: #e8eaf0; }
.mc-section-sep-label {
    font-size: 10.5px; font-weight: 700; letter-spacing: 1.2px;
    text-transform: uppercase; white-space: nowrap;
    display: flex; align-items: center; gap: 7px;
    padding: 3px 10px; border-radius: 20px;
}
.mc-section-sep-label.critical { background: #fff5f5; color: #dc2626; border: 1px solid rgba(220,38,38,0.2); }
.mc-section-sep-label.warning  { background: #fffbeb; color: #d97706; border: 1px solid rgba(217,119,6,0.2); }
.mc-section-sep-label.ok       { background: #f0fdf4; color: #16a34a; border: 1px solid rgba(22,163,74,0.2); }
.mc-section-sep-label.unknown  { background: #f7f8fc; color: #8b92a9; border: 1px solid #e8eaf0; }
.mc-section-dot { width: 6px; height: 6px; border-radius: 50%; flex-shrink: 0; }
.mc-section-sep-label.critical .mc-section-dot { background: #dc2626; }
.mc-section-sep-label.warning  .mc-section-dot { background: #d97706; }
.mc-section-sep-label.ok       .mc-section-dot { background: #16a34a; }
.mc-section-sep-label.unknown  .mc-section-dot { background: #c4c9d8; }

/* ── Maintenance table ── */
.mc-table { width: 100%; border-collapse: collapse; }
.mc-table thead tr { background: #f7f8fc; border-bottom: 1px solid #e8eaf0; }
.mc-table thead th {
    padding: 10px 16px; text-align: left;
    font-size: 10px; font-weight: 700; letter-spacing: 0.8px;
    text-transform: uppercase; color: #8b92a9; white-space: nowrap;
}
.mc-table tbody tr {
    border-bottom: 1px solid #f5f6fb;
    transition: background 0.12s; cursor: pointer;
}
.mc-table tbody tr:last-child { border-bottom: none; }
.mc-table tbody tr:hover { background: #f7f8fc; }
.mc-table td { padding: 12px 16px; vertical-align: middle; }

.mc-stripe { width: 4px; padding: 0 !important; }
.mc-stripe.critical { background: #dc2626; }
.mc-stripe.warning  { background: #d97706; }
.mc-stripe.ok       { background: #16a34a; }
.mc-stripe.unknown  { background: #e8eaf0; }

.mc-row-icon {
    width: 34px; height: 34px; border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    font-size: 14px; flex-shrink: 0;
}
.mc-row-icon.critical { background: rgba(220,38,38,0.08);  color: #dc2626; }
.mc-row-icon.warning  { background: rgba(217,119,6,0.08);  color: #d97706; }
.mc-row-icon.ok       { background: rgba(22,163,74,0.08);  color: #16a34a; }
.mc-row-icon.unknown  { background: rgba(60,71,88,0.06);   color: #8b92a9; }

.mc-row-name   { font-size: 13.5px; font-weight: 600; color: #2d3748; }
.mc-row-badges { display: flex; gap: 4px; margin-top: 4px; flex-wrap: wrap; }
.mc-row-badge {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 1px 7px; border-radius: 4px;
    font-size: 10px; font-weight: 700; letter-spacing: 0.4px; text-transform: uppercase;
}
.mc-row-badge.critical { background: rgba(220,38,38,0.08);  color: #b91c1c; border: 1px solid rgba(220,38,38,0.15); }
.mc-row-badge.warning  { background: rgba(217,119,6,0.08);  color: #b45309; border: 1px solid rgba(217,119,6,0.15); }
.mc-row-badge.ok       { background: rgba(22,163,74,0.08);  color: #15803d; border: 1px solid rgba(22,163,74,0.15); }
.mc-row-badge.unknown  { background: #f7f8fc; color: #8b92a9; border: 1px solid #e8eaf0; }
.mc-row-badge.safety   { background: rgba(109,40,217,0.07); color: #6d28d9; border: 1px solid rgba(109,40,217,0.15); }

.mc-prog-wrap { min-width: 160px; }
.mc-prog-meta { display: flex; justify-content: space-between; font-size: 10px; color: #8b92a9; margin-bottom: 5px; font-weight: 500; }
.mc-prog-pct  { font-family: 'DM Mono', monospace; font-size: 10px; font-weight: 600; }
.mc-prog-pct.critical { color: #dc2626; }
.mc-prog-pct.warning  { color: #d97706; }
.mc-prog-pct.ok       { color: #16a34a; }
.mc-prog-track { height: 5px; background: #f0f2f8; border-radius: 99px; overflow: hidden; }
.mc-prog-fill  { height: 100%; border-radius: 99px; transition: width 0.8s ease; }
.mc-prog-fill.critical { background: #dc2626; }
.mc-prog-fill.warning  { background: #d97706; }
.mc-prog-fill.ok       { background: #16a34a; }
.mc-prog-fill.unknown  { background: #e8eaf0; }

.mc-num-val  { font-family: 'DM Mono', monospace; font-size: 14px; font-weight: 600; line-height: 1; }
.mc-num-val.critical { color: #dc2626; }
.mc-num-val.warning  { color: #d97706; }
.mc-num-val.ok       { color: #16a34a; }
.mc-num-val.muted    { color: #c4c9d8; }
.mc-num-unit { font-size: 9px; letter-spacing: 0.8px; text-transform: uppercase; color: #c4c9d8; margin-top: 2px; font-weight: 600; }

.mc-interval  { font-family: 'DM Mono', monospace; font-size: 11px; color: #8b92a9; line-height: 1.5; }
.mc-last-date { font-family: 'DM Mono', monospace; font-size: 11px; font-weight: 500; color: #5a6482; }
.mc-last-tech { font-size: 11px; color: #8b92a9; margin-top: 2px; }

.mc-log-btn {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 6px 12px; border-radius: 6px;
    background: #fff; border: 1.5px solid #d1d5e0;
    font-family: 'DM Sans', sans-serif; font-size: 11px; font-weight: 600;
    color: #5a6482; cursor: pointer; transition: all 0.15s;
    text-transform: uppercase; letter-spacing: 0.4px;
}
.mc-log-btn:hover { background: #3c4758; border-color: #3c4758; color: #fff; }

/* ── Empty state ── */
.mc-empty { padding: 64px 24px; text-align: center; }
.mc-empty-icon {
    width: 64px; height: 64px; border-radius: 50%;
    background: #f7f8fc; border: 2px dashed #d1d5e0;
    display: flex; align-items: center; justify-content: center;
    font-size: 26px; color: #c4c9d8; margin: 0 auto 20px;
}
.mc-empty h3 { font-size: 17px; font-weight: 700; color: #8b92a9; margin: 0 0 8px; }
.mc-empty p  { font-size: 13px; color: #c4c9d8; margin: 0; }

/* ── Modal ── */
.mc-modal-overlay {
    display: none; position: fixed; inset: 0;
    background: rgba(15,20,35,0.55); z-index: 9000;
    align-items: center; justify-content: center;
    backdrop-filter: blur(3px);
}
.mc-modal-overlay.open { display: flex; }
.mc-modal {
    background: #fff; border: 1px solid #e8eaf0;
    border-radius: 14px; padding: 28px; width: 480px; max-width: 95vw;
    box-shadow: 0 24px 60px rgba(0,0,0,0.12);
    animation: modalIn .2s cubic-bezier(.16,1,.3,1);
}
@keyframes modalIn { from{opacity:0;transform:scale(.96) translateY(10px)} to{opacity:1;transform:none} }
.mc-modal-head {
    display: flex; align-items: center; gap: 12px;
    margin-bottom: 22px; padding-bottom: 18px;
    border-bottom: 1px solid #f0f2f8;
}
.mc-modal-head-icon {
    width: 38px; height: 38px; border-radius: 10px;
    background: rgba(60,71,88,0.08); color: #3c4758;
    display: flex; align-items: center; justify-content: center;
    font-size: 15px; flex-shrink: 0;
}
.mc-modal-title { font-size: 16px; font-weight: 700; color: #1a1f2e; }
.mc-modal-sub   { font-size: 11px; color: #8b92a9; margin-top: 2px; font-family: 'DM Mono', monospace; }
.mc-modal-field { margin-bottom: 14px; }
.mc-modal-field label {
    display: block; font-size: 10px; font-weight: 700;
    letter-spacing: 0.8px; text-transform: uppercase; color: #8b92a9; margin-bottom: 6px;
}
.mc-modal-field input,
.mc-modal-field textarea {
    width: 100%; padding: 9px 12px;
    border: 1.5px solid #e2e5f0; border-radius: 8px;
    font-size: 13px; font-family: 'DM Sans', sans-serif;
    color: #2d3748; background: #fafbfe; outline: none;
    transition: border-color 0.15s, box-shadow 0.15s;
    box-sizing: border-box;
}
.mc-modal-field input:focus,
.mc-modal-field textarea:focus {
    border-color: #3c4758; box-shadow: 0 0 0 3px rgba(60,71,88,0.1); background: #fff;
}
.mc-modal-field textarea { resize: vertical; min-height: 72px; }
.mc-modal-actions {
    display: flex; gap: 8px; justify-content: flex-end;
    margin-top: 22px; padding-top: 18px; border-top: 1px solid #f0f2f8;
}

@keyframes blink { 0%,100%{opacity:1} 50%{opacity:.3} }

/* ── Responsive ── */
@media(max-width:1024px) { .mc-col-interval, .mc-col-last { display: none; } }
@media(max-width:900px)  { .mc-col-nextdue { display: none; } }
@media(max-width:768px)  { .mc-col-days { display: none; } }
@media(max-width:580px)  { .mc-col-km, .mc-col-progress { display: none; } }

/* ── Print styles ── */
@media print {
    .dc-header-actions, .mc-selector-row button, .mc-log-btn,
    .mc-modal-overlay, #mc-search { display: none !important; }
    .mc-page { max-width: 100%; padding: 0; }
    .dc-card { box-shadow: none; border: 1px solid #ccc; page-break-inside: avoid; }
    .mc-col-interval, .mc-col-last, .mc-col-nextdue,
    .mc-col-days, .mc-col-km, .mc-col-progress { display: table-cell !important; }
    body { font-size: 11px; }
}
</style>

<div class="mc-page">

<!-- ══ HEADER ════════════════════════════════════════════════════════════════ -->
<div class="dc-header">
    <div class="dc-header-left">
        <div class="dc-header-icon"><i class="fa fa-tools"></i></div>
        <div>
            <div class="dc-header-title"><?= $langs->trans('MaintenanceAlerts') ?></div>
            <div class="dc-header-sub">
                <?php if ($vehicle): ?>
                    [<?= dol_escape_htmltag($vehicle->ref) ?>] <?= dol_escape_htmltag($vehicle->label) ?> &nbsp;&bull;&nbsp;
                <?php endif; ?>
                <?= $langs->trans('FleetManagement') ?>
            </div>
        </div>
    </div>
    <div class="dc-header-actions">
        <?php if ($vehicle): ?>
            <?php if ($criticalCount > 0): ?>
            <span class="dc-btn" style="background:#fff5f5;color:#dc2626;border:1.5px solid rgba(220,38,38,0.25);cursor:default;">
                <span style="width:7px;height:7px;border-radius:50%;background:#dc2626;animation:blink 1s infinite;display:inline-block;"></span>
                <?= $criticalCount ?> <?= $langs->trans('Overdue') ?>
            </span>
            <?php elseif ($warningCount > 0): ?>
            <span class="dc-btn" style="background:#fffbeb;color:#d97706;border:1.5px solid rgba(217,119,6,0.25);cursor:default;">
                <span style="width:7px;height:7px;border-radius:50%;background:#d97706;display:inline-block;"></span>
                <?= $warningCount ?> <?= $langs->trans('DueSoon') ?>
            </span>
            <?php else: ?>
            <span class="dc-btn" style="background:#f0fdf4;color:#16a34a;border:1.5px solid rgba(22,163,74,0.25);cursor:default;">
                <span style="width:7px;height:7px;border-radius:50%;background:#16a34a;display:inline-block;"></span>
                <?= $langs->trans('AllClear') ?>
            </span>
            <?php endif; ?>
        <?php endif; ?>
        <?php if ($vehicle): ?>
        <button class="dc-btn dc-btn-ghost" onclick="window.print()" title="<?= $langs->trans('Print') ?>">
            <i class="fa fa-print"></i> <?= $langs->trans('Print') ?>
        </button>
        <?php endif; ?>
        <a class="dc-btn dc-btn-ghost" href="<?= dol_buildpath('/flotte/inspection_list.php', 1) ?>">
            <i class="fa fa-arrow-left"></i> <?= $langs->trans('BackToList') ?>
        </a>
    </div>
</div>

<!-- ══ VEHICLE SELECTOR ═══════════════════════════════════════════════════════ -->
<div class="dc-card">
    <div class="dc-card-header">
        <div class="dc-card-header-icon blue"><i class="fa fa-car"></i></div>
        <span class="dc-card-title"><?= $langs->trans('Vehicle') ?></span>
    </div>
    <form method="GET" action="<?= $_SERVER['PHP_SELF'] ?>">
    <div class="mc-selector-row">
        <label><i class="fa fa-car" style="margin-right:5px;"></i><?= $langs->trans('SelectVehicle') ?></label>
        <select name="fk_vehicle" onchange="this.form.submit()">
            <option value=""><?= $langs->trans('SelectAVehicle') ?>…</option>
            <?php foreach ($vehicles as $vid => $v): ?>
            <option value="<?= (int)$vid ?>" <?= ($fk_vehicle == $vid ? 'selected' : '') ?>>
                [<?= dol_escape_htmltag($v['ref']) ?>] <?= dol_escape_htmltag($v['label']) ?>
            </option>
            <?php endforeach; ?>
        </select>
        <?php if ($vehicle && $currentKm > 0): ?>
        <div class="mc-odometer">
            <i class="fa fa-tachometer-alt mc-odometer-icon"></i>
            <div>
                <div class="mc-odometer-lbl"><?= $langs->trans('Odometer') ?></div>
                <div class="mc-odometer-val"><?= number_format($currentKm) ?> <span style="font-size:11px;opacity:.6;">km</span></div>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php if ($vehicle): ?>
    <div class="mc-selector-row" style="border-top:1px solid #f0f2f8;padding-top:12px;">
        <label><i class="fa fa-search" style="margin-right:5px;"></i><?= $langs->trans('Filter') ?></label>
        <input type="text" id="mc-search" placeholder="<?= dol_escape_htmltag($langs->trans('SearchService')) ?>…"
               oninput="filterRows(this.value)"
               style="padding:7px 12px;border:1.5px solid #e2e5f0;border-radius:8px;font-size:13px;font-family:'DM Sans',sans-serif;color:#2d3748;background:#fafbfe;outline:none;min-width:220px;flex:1;">
        <noscript><span style="font-size:11px;color:#c4c9d8;"><?= $langs->trans('JavaScriptRequired') ?></span></noscript>
    </div>
    <?php endif; ?>
    </form>
</div>

<?php if (!$vehicle): ?>
<!-- ══ EMPTY STATE ════════════════════════════════════════════════════════════ -->
<div class="dc-card">
    <div class="mc-empty">
        <div class="mc-empty-icon"><i class="fa fa-car"></i></div>
        <h3><?= $langs->trans('SelectVehicleToBegin') ?></h3>
        <p><?= $langs->trans('ChooseVehicleFromDropdown') ?></p>
    </div>
</div>

<?php else: ?>

<!-- ══ ALERT BANNERS ══════════════════════════════════════════════════════════ -->
<?php
$critItems = array_filter($alertData, function($a){ return $a['status']==='critical'; });
$warnItems = array_filter($alertData, function($a){ return $a['status']==='warning'; });
?>
<?php if (!empty($critItems) || !empty($warnItems) || $okCount > 0): ?>
<div class="dc-card" style="overflow:hidden;">
    <?php if (!empty($critItems)): ?>
    <div class="mc-alert critical">
        <div class="mc-alert-icon"><i class="fa fa-exclamation-triangle"></i></div>
        <div class="mc-alert-body">
            <div class="mc-alert-title"><i class="fa fa-shield-alt" style="margin-right:5px;"></i><?= $langs->trans('ServiceOverdue') ?> — <?= $langs->trans('ImmediateAttentionRequired') ?></div>
            <div class="mc-alert-tags">
                <?php foreach ($critItems as $key => $a): ?>
                <span class="mc-alert-tag"><i class="fa <?= $maintenanceItems[$key]['icon'] ?>"></i><?= dol_escape_htmltag($maintenanceItems[$key]['label']) ?></span>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
    <?php if (!empty($warnItems)): ?>
    <div class="mc-alert warning">
        <div class="mc-alert-icon"><i class="fa fa-bell"></i></div>
        <div class="mc-alert-body">
            <div class="mc-alert-title"><?= $langs->trans('ServiceDueSoon') ?> — <?= $langs->trans('ScheduleServiceSoon') ?></div>
            <div class="mc-alert-tags">
                <?php foreach ($warnItems as $key => $a): ?>
                <span class="mc-alert-tag"><i class="fa <?= $maintenanceItems[$key]['icon'] ?>"></i><?= dol_escape_htmltag($maintenanceItems[$key]['label']) ?></span>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
    <?php if (empty($critItems) && empty($warnItems) && $okCount > 0): ?>
    <div class="mc-alert ok">
        <div class="mc-alert-icon"><i class="fa fa-check-circle"></i></div>
        <div class="mc-alert-body">
            <div class="mc-alert-title"><?= $langs->trans('AllServicesUpToDate') ?></div>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- ══ STATS ══════════════════════════════════════════════════════════════════ -->
<div class="dc-card" style="overflow:hidden;">
    <div class="mc-stats-grid">
        <div class="mc-stat-tile">
            <div class="mc-stat-bar red"></div>
            <div>
                <div class="mc-stat-num red"><?= $criticalCount ?></div>
                <div class="mc-stat-lbl"><?= $langs->trans('Overdue') ?></div>
            </div>
        </div>
        <div class="mc-stat-tile">
            <div class="mc-stat-bar amber"></div>
            <div>
                <div class="mc-stat-num amber"><?= $warningCount ?></div>
                <div class="mc-stat-lbl"><?= $langs->trans('DueSoon') ?></div>
            </div>
        </div>
        <div class="mc-stat-tile">
            <div class="mc-stat-bar green"></div>
            <div>
                <div class="mc-stat-num green"><?= $okCount ?></div>
                <div class="mc-stat-lbl"><?= $langs->trans('UpToDate') ?></div>
            </div>
        </div>
        <div class="mc-stat-tile">
            <div class="mc-stat-bar muted"></div>
            <div>
                <div class="mc-stat-num muted"><?= $unknownCount ?></div>
                <div class="mc-stat-lbl"><?= $langs->trans('NoRecord') ?></div>
            </div>
        </div>
    </div>
</div>

<!-- ══ MAINTENANCE TABLES ═════════════════════════════════════════════════════ -->
<?php
$sectionMeta = array(
    'critical' => array('label' => $langs->trans('Overdue').' — '.$langs->trans('ImmediateAttentionRequired'), 'cls' => 'critical'),
    'warning'  => array('label' => $langs->trans('DueSoon'),  'cls' => 'warning'),
    'ok'       => array('label' => $langs->trans('UpToDate'), 'cls' => 'ok'),
    'unknown'  => array('label' => $langs->trans('NoRecord'), 'cls' => 'unknown'),
);

foreach (array('critical','warning','ok','unknown') as $sectionStatus):
    $keys = array();
    foreach ($maintenanceItems as $k => $item) {
        if ($alertData[$k]['status'] === $sectionStatus) $keys[] = $k;
    }
    if (empty($keys)) continue;
    $sec = $sectionMeta[$sectionStatus];
?>

<div class="mc-section-sep">
    <div class="mc-section-sep-line"></div>
    <div class="mc-section-sep-label <?= $sec['cls'] ?>">
        <span class="mc-section-dot"></span>
        <?= $sec['label'] ?>
    </div>
    <div class="mc-section-sep-line"></div>
</div>

<div class="dc-card" style="overflow:hidden;">
<table class="mc-table">
<thead>
<tr>
    <th style="width:4px;padding:0;"></th>
    <th style="width:42px;"></th>
    <th><?= $langs->trans('Service') ?></th>
    <th class="mc-col-progress"><?= $langs->trans('Progress') ?></th>
    <th class="mc-col-km" style="text-align:center;"><?= $langs->trans('KmLeft') ?></th>
    <th class="mc-col-days" style="text-align:center;"><?= $langs->trans('DaysLeft') ?></th>
    <th class="mc-col-nextdue" style="text-align:center;"><?= $langs->trans('NextDue') ?></th>
    <th class="mc-col-interval" style="text-align:center;"><?= $langs->trans('Interval') ?></th>
    <th class="mc-col-last"><?= $langs->trans('LastService') ?></th>
    <th></th>
</tr>
</thead>
<tbody>
<?php foreach ($keys as $key):
    $item  = $maintenanceItems[$key];
    $rec   = isset($maintenanceRecords[$key]) ? $maintenanceRecords[$key] : null;
    $a     = $alertData[$key];
    $pct   = $sectionStatus === 'critical' ? 100 : ($sectionStatus === 'unknown' ? 0 : min(100, (int)$a['progress']));
    $kmVal = $a['km_remaining']   !== null ? (int)$a['km_remaining']   : null;
    $dVal  = $a['days_remaining'] !== null ? (int)$a['days_remaining'] : null;
    $kmClass = ($kmVal !== null) ? ($kmVal <= 0 ? 'critical' : ($kmVal <= $item['warning_km'] ? 'warning' : 'ok')) : 'muted';
    $dClass  = ($dVal  !== null) ? ($dVal  <= 0 ? 'critical' : ($dVal  <= $item['warning_days'] ? 'warning' : 'ok')) : 'muted';
    // Next-due values for the new column
    $nextKmVal   = ($rec && $rec->next_km > 0 && $item['interval_km'] > 0) ? (int)$rec->next_km : null;
    $nextDateVal = ($rec && !empty($rec->next_date) && $item['interval_days'] > 0) ? $rec->next_date : null;
?>
<tr onclick="openModal('<?= $key ?>')" data-label="<?= dol_escape_htmltag(strtolower($item['label'])) ?>" class="mc-data-row">>
    <td class="mc-stripe <?= $sectionStatus ?>"></td>
    <td style="padding:12px 8px 12px 16px;">
        <div class="mc-row-icon <?= $sectionStatus ?>"><i class="fa <?= $item['icon'] ?>"></i></div>
    </td>
    <td>
        <div class="mc-row-name"><?= dol_escape_htmltag($item['label']) ?></div>
        <div class="mc-row-badges">
            <span class="mc-row-badge <?= $sectionStatus ?>">
                <?= array('critical'=>$langs->trans('Overdue'),'warning'=>$langs->trans('DueSoon'),'ok'=>'OK','unknown'=>$langs->trans('NoRecord'))[$sectionStatus] ?>
            </span>
            <?php if (!empty($item['critical'])): ?>
            <span class="mc-row-badge safety"><i class="fa fa-bolt" style="font-size:9px;"></i> <?= $langs->trans('Safety') ?></span>
            <?php endif; ?>
        </div>
    </td>
    <td class="mc-col-progress">
        <div class="mc-prog-wrap">
            <div class="mc-prog-meta">
                <span><?= $sectionStatus === 'critical' ? $langs->trans('Overdue') : $langs->trans('IntervalUsed') ?></span>
                <span class="mc-prog-pct <?= $sectionStatus !== 'unknown' ? $sectionStatus : '' ?>"><?= $pct ?>%</span>
            </div>
            <div class="mc-prog-track">
                <div class="mc-prog-fill <?= $sectionStatus ?>" style="width:<?= $pct ?>%"></div>
            </div>
        </div>
    </td>
    <td class="mc-col-km" style="text-align:center;">
        <?php if ($kmVal !== null && $item['interval_km'] > 0): ?>
        <div class="mc-num-val <?= $kmClass ?>"><?= $kmVal <= 0 ? '+'.number_format(abs($kmVal)) : number_format($kmVal) ?></div>
        <div class="mc-num-unit"><?= $kmVal <= 0 ? $langs->trans('KmOverdue') : 'km' ?></div>
        <?php else: ?><div class="mc-num-val muted">—</div><?php endif; ?>
    </td>
    <td class="mc-col-days" style="text-align:center;">
        <?php if ($dVal !== null): ?>
        <div class="mc-num-val <?= $dClass ?>"><?= abs($dVal) ?></div>
        <div class="mc-num-unit"><?= $dVal <= 0 ? $langs->trans('DaysLate') : $langs->trans('days') ?></div>
        <?php else: ?><div class="mc-num-val muted">—</div><?php endif; ?>
    </td>
    <td class="mc-col-nextdue" style="text-align:center;">
        <?php if ($nextDateVal || $nextKmVal): ?>
        <div class="mc-interval" style="text-align:center;">
            <?php if ($nextDateVal): ?>
            <div style="font-family:'DM Mono',monospace;font-size:11px;color:#5a6482;font-weight:500;"><?= dol_escape_htmltag($nextDateVal) ?></div>
            <?php endif; ?>
            <?php if ($nextKmVal): ?>
            <div style="font-family:'DM Mono',monospace;font-size:11px;color:#8b92a9;"><?= number_format($nextKmVal) ?> km</div>
            <?php endif; ?>
        </div>
        <?php else: ?><div class="mc-num-val muted">—</div><?php endif; ?>
    </td>
    <td class="mc-col-interval" style="text-align:center;">
        <div class="mc-interval">
            <?php if ($item['interval_km'] > 0): ?><?= $langs->trans('Every') ?> <?= number_format($item['interval_km']) ?> km<?php endif; ?>
            <?php if ($item['interval_km'] > 0 && $item['interval_days'] > 0): ?><br><?php endif; ?>
            <?php if ($item['interval_days'] > 0): ?><?= $item['interval_days'] ?> <?= $langs->trans('days') ?><?php endif; ?>
        </div>
    </td>
    <td class="mc-col-last">
        <?php if ($rec): ?>
        <div class="mc-last-date"><?= dol_escape_htmltag($rec->last_date) ?> · <?= number_format((int)$rec->last_km) ?> km</div>
        <?php if (!empty($rec->technician)): ?>
        <div class="mc-last-tech"><i class="fa fa-user-cog" style="margin-right:4px;color:#c4c9d8;"></i><?= dol_escape_htmltag($rec->technician) ?></div>
        <?php endif; ?>
        <?php else: ?>
        <div class="mc-last-date" style="color:#c4c9d8;"><?= $langs->trans('NoRecord') ?></div>
        <?php endif; ?>
    </td>
    <td style="text-align:right;padding-right:16px;">
        <button class="mc-log-btn" onclick="event.stopPropagation();openModal('<?= $key ?>')">
            <i class="fa fa-plus"></i> <?= $langs->trans('Log') ?>
        </button>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<?php endforeach; ?>

<?php endif; ?>
</div><!-- /mc-page -->

<!-- ══ MODAL ══════════════════════════════════════════════════════════════════ -->
<div class="mc-modal-overlay" id="serviceModal">
    <div class="mc-modal">
        <div class="mc-modal-head">
            <div class="mc-modal-head-icon"><i class="fa fa-wrench"></i></div>
            <div>
                <div class="mc-modal-title" id="modal-title-text"><?= $langs->trans('LogService') ?></div>
                <div class="mc-modal-sub" id="modal-interval-text"></div>
            </div>
        </div>
        <form method="POST" action="<?= $_SERVER['PHP_SELF'] ?>?fk_vehicle=<?= (int)$fk_vehicle ?>">
            <input type="hidden" name="action" value="save_maintenance">
            <input type="hidden" name="token" value="<?= newToken() ?>">
            <input type="hidden" name="service_type" id="modal-service-type">
            <div class="mc-modal-field">
                <label><i class="fa fa-calendar-alt" style="margin-right:5px;"></i><?= $langs->trans('ServiceDate') ?></label>
                <input type="date" name="last_date" value="<?= date('Y-m-d') ?>" required>
            </div>
            <div class="mc-modal-field">
                <label><i class="fa fa-tachometer-alt" style="margin-right:5px;"></i><?= $langs->trans('OdometerAtService') ?> (km)</label>
                <input type="number" name="last_km" id="modal-km" value="<?= $currentKm ?>" min="0" required>
            </div>
            <div class="mc-modal-field">
                <label><i class="fa fa-user-cog" style="margin-right:5px;"></i><?= $langs->trans('TechnicianGarage') ?></label>
                <input type="text" name="technician" placeholder="<?= $langs->trans('ExAutoProGarage') ?>">
            </div>
            <div class="mc-modal-field">
                <label><i class="fa fa-sticky-note" style="margin-right:5px;"></i><?= $langs->trans('Notes') ?></label>
                <textarea name="notes" placeholder="<?= $langs->trans('PartsReplacedObservations') ?>"></textarea>
            </div>
            <div class="mc-modal-actions">
                <button type="button" class="dc-btn dc-btn-ghost" onclick="closeModal()"><i class="fa fa-times"></i> <?= $langs->trans('Cancel') ?></button>
                <button type="submit" class="dc-btn dc-btn-primary"><i class="fa fa-save"></i> <?= $langs->trans('SaveRecord') ?></button>
            </div>
        </form>
    </div>
</div>

<script>
var maintenanceItems = <?php
    $out = array();
    foreach ($maintenanceItems as $k => $v) {
        $out[$k] = array('label'=>$v['label'],'interval_km'=>$v['interval_km'],'interval_days'=>$v['interval_days']);
    }
    echo json_encode($out);
?>;

// Translated strings for use in JS (avoids hardcoding English)
var i18n = {
    every: <?= json_encode($langs->trans('Every')) ?>,
    km:    'km',
    days:  <?= json_encode($langs->trans('days')) ?>
};

function openModal(serviceType) {
    var item = maintenanceItems[serviceType];
    if (!item) return;
    document.getElementById('modal-service-type').value = serviceType;
    document.getElementById('modal-title-text').textContent = item.label;
    var parts = [];
    if (item.interval_km > 0)   parts.push(i18n.every + ' ' + item.interval_km.toLocaleString() + ' ' + i18n.km);
    if (item.interval_days > 0) parts.push(i18n.every + ' ' + item.interval_days + ' ' + i18n.days);
    document.getElementById('modal-interval-text').textContent = parts.join(' · ');
    document.getElementById('serviceModal').classList.add('open');
}
function closeModal() {
    document.getElementById('serviceModal').classList.remove('open');
}
document.getElementById('serviceModal').addEventListener('click', function(e){ if(e.target===this) closeModal(); });
document.addEventListener('keydown', function(e){ if(e.key==='Escape') closeModal(); });

// Search / filter rows
function filterRows(query) {
    var q = query.toLowerCase().trim();
    var rows = document.querySelectorAll('tr.mc-data-row');
    rows.forEach(function(row) {
        var label = (row.getAttribute('data-label') || '').toLowerCase();
        row.style.display = (!q || label.indexOf(q) !== -1) ? '' : 'none';
    });
    // Hide section separators and cards that have no visible rows
    document.querySelectorAll('.dc-card').forEach(function(card) {
        var visible = card.querySelectorAll('tr.mc-data-row:not([style*="display: none"])');
        card.style.display = (visible.length === 0 && card.querySelector('tr.mc-data-row')) ? 'none' : '';
    });
    document.querySelectorAll('.mc-section-sep').forEach(function(sep, i) {
        // Show separator only if next sibling card is visible
        var next = sep.nextElementSibling;
        sep.style.display = (next && next.style.display === 'none') ? 'none' : '';
    });
}
</script>

<?php
llxFooter();
$db->close();
?>
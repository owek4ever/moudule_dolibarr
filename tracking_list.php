<?php
/* Copyright (C) 2025 - Fleet Vehicle Tracking Map */

// Load Dolibarr environment
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
if (!$res && file_exists("../main.inc.php"))       { $res = @include "../main.inc.php"; }
if (!$res && file_exists("../../main.inc.php"))    { $res = @include "../../main.inc.php"; }
if (!$res && file_exists("../../../main.inc.php")) { $res = @include "../../../main.inc.php"; }
if (!$res) { die("Include of main fails"); }

require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';

$langs->loadLangs(array("flotte@flotte", "other"));
restrictedArea($user, 'flotte');

$selected_id = GETPOST('id', 'int');

// ── Fetch all vehicles with booking data ──────────────────────────────────
function mapTableExists($db, $table) {
    $r = $db->query("SHOW TABLES LIKE '".MAIN_DB_PREFIX.$table."'");
    return ($r && $db->num_rows($r) > 0);
}

function getMapVehicles($db, $conf) {
    $vehicles = array();
    if (!mapTableExists($db, 'flotte_vehicle')) return $vehicles;

    $sql  = "SELECT v.rowid, v.ref, v.maker, v.model, v.license_plate, v.in_service,";
    $sql .= "       b.rowid AS booking_id, b.ref AS booking_ref, b.status AS booking_status,";
    $sql .= "       b.departure_address, b.arriving_address, b.eta, b.booking_date,";
    $sql .= "       b.selling_amount, IFNULL(b.selling_amount_ttc, b.selling_amount) AS selling_amount_ttc,";
    $sql .= "       d.firstname AS driver_firstname, d.lastname AS driver_lastname, d.phone AS driver_phone,";
    $sql .= "       c.firstname AS customer_firstname, c.lastname AS customer_lastname, c.phone AS customer_phone";
    $sql .= " FROM ".MAIN_DB_PREFIX."flotte_vehicle v";

    if (mapTableExists($db, 'flotte_booking')) {
        $sql .= " LEFT JOIN ".MAIN_DB_PREFIX."flotte_booking b";
        $sql .= "   ON b.fk_vehicle = v.rowid";
        $sql .= "   AND b.status IN ('pending','confirmed','in_progress')";
        $sql .= "   AND b.entity = ".(int)$conf->entity;
        $sql .= "   AND b.rowid = (SELECT MAX(b2.rowid) FROM ".MAIN_DB_PREFIX."flotte_booking b2";
        $sql .= "     WHERE b2.fk_vehicle = v.rowid AND b2.status IN ('pending','confirmed','in_progress')";
        $sql .= "     AND b2.entity = ".(int)$conf->entity.")";
    } else {
        $sql .= " LEFT JOIN (SELECT NULL AS rowid, NULL AS ref, NULL AS status, NULL AS departure_address,";
        $sql .= "  NULL AS arriving_address, NULL AS eta, NULL AS booking_date, NULL AS selling_amount,";
        $sql .= "  NULL AS selling_amount_ttc, NULL AS fk_vehicle, NULL AS fk_driver, NULL AS fk_customer, NULL AS entity) b ON 0=1";
    }

    if (mapTableExists($db, 'flotte_driver')) {
        $sql .= " LEFT JOIN ".MAIN_DB_PREFIX."flotte_driver d ON d.rowid = b.fk_driver AND d.entity = ".(int)$conf->entity;
    } else {
        $sql .= " LEFT JOIN (SELECT NULL AS rowid, NULL AS firstname, NULL AS lastname, NULL AS phone) d ON 0=1";
    }
    if (mapTableExists($db, 'flotte_customer')) {
        $sql .= " LEFT JOIN ".MAIN_DB_PREFIX."flotte_customer c ON c.rowid = b.fk_customer AND c.entity = ".(int)$conf->entity;
    } else {
        $sql .= " LEFT JOIN (SELECT NULL AS rowid, NULL AS firstname, NULL AS lastname, NULL AS phone) c ON 0=1";
    }

    $sql .= " WHERE v.entity = ".(int)$conf->entity;
    $sql .= " ORDER BY v.in_service DESC, b.rowid DESC, v.ref ASC";

    $resql = $db->query($sql);
    if (!$resql) { dol_print_error($db); return $vehicles; }
    while ($obj = $db->fetch_object($resql)) {
        if (!$obj->in_service) {
            $obj->computed_status = 'outofservice';
        } elseif (!empty($obj->booking_id)) {
            $obj->computed_status = 'inuse';
        } else {
            $obj->computed_status = 'available';
        }
        $vehicles[] = $obj;
    }
    return $vehicles;
}

$all_vehicles = getMapVehicles($db, $conf);

// Auto-select first in-use vehicle if none specified
if (!$selected_id) {
    foreach ($all_vehicles as $v) {
        if ($v->computed_status === 'inuse') { $selected_id = $v->rowid; break; }
    }
    if (!$selected_id && !empty($all_vehicles)) {
        $selected_id = $all_vehicles[0]->rowid;
    }
}

// Find selected vehicle
$selected_vehicle = null;
foreach ($all_vehicles as $v) {
    if ($v->rowid == $selected_id) { $selected_vehicle = $v; break; }
}

// Page output — no llxHeader wrapper, we need full viewport
llxHeader('', $langs->trans('VehicleTrackingMap'), '');
print '<div class="fichecenter">';
?>
<style>
@import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=DM+Mono:wght@400;500&display=swap');

/* ── Reset & layout ── */
.vtm-wrap * { box-sizing: border-box; }
.vtm-wrap {
    font-family: 'DM Sans', sans-serif;
    display: flex;
    flex-direction: column;
    height: calc(100vh - 120px);
    min-height: 600px;
    color: #1a1f2e;
}

/* ── Top bar ── */
.vtm-topbar {
    display: flex; align-items: center; justify-content: space-between;
    padding: 12px 20px; background: #fff;
    border-bottom: 1px solid #e8eaf0;
    gap: 12px; flex-wrap: wrap; flex-shrink: 0;
}
.vtm-topbar-left { display: flex; align-items: center; gap: 12px; }
.vtm-topbar-title { font-size: 17px; font-weight: 700; color: #1a1f2e; display: flex; align-items: center; gap: 8px; }
.vtm-topbar-title i { color: #0f766e; }
.vtm-btn {
    display: inline-flex; align-items: center; gap: 7px; padding: 7px 14px;
    border-radius: 7px; font-size: 13px; font-weight: 600; text-decoration: none !important;
    border: 1.5px solid #e2e5f0; background: #f8f9fc; color: #3c4758; cursor: pointer;
    transition: all 0.15s; font-family: 'DM Sans', sans-serif;
}
.vtm-btn:hover { background: #3c4758; color: #fff !important; border-color: #3c4758; }
.vtm-btn-fs { background: #0f766e; color: #fff !important; border-color: #0f766e; }
.vtm-btn-fs:hover { background: #0d6460 !important; border-color: #0d6460 !important; }

/* ── Main content area ── */
.vtm-body {
    display: flex;
    flex: 1;
    overflow: hidden;
}

/* ── Sidebar ── */
.vtm-sidebar {
    width: 300px; flex-shrink: 0;
    background: #fff; border-right: 1px solid #e8eaf0;
    display: flex; flex-direction: column;
    overflow: hidden;
}
.vtm-sidebar-header {
    padding: 14px 16px 10px;
    border-bottom: 1px solid #f0f2f8;
    flex-shrink: 0;
}
.vtm-sidebar-title {
    font-size: 12px; font-weight: 700; text-transform: uppercase;
    letter-spacing: 0.7px; color: #9aa0b4; margin-bottom: 10px;
}
.vtm-search {
    width: 100%; padding: 7px 12px; border: 1.5px solid #e2e5f0;
    border-radius: 8px; font-size: 13px; font-family: 'DM Sans', sans-serif;
    outline: none; background: #fafbfe; color: #2d3748; transition: border-color 0.15s;
}
.vtm-search:focus { border-color: #0f766e; background: #fff; }

.vtm-vehicle-list {
    flex: 1; overflow-y: auto;
    padding: 8px 0;
}
.vtm-vehicle-list::-webkit-scrollbar { width: 4px; }
.vtm-vehicle-list::-webkit-scrollbar-track { background: transparent; }
.vtm-vehicle-list::-webkit-scrollbar-thumb { background: #e2e5f0; border-radius: 4px; }

.vtm-vehicle-item {
    display: flex; align-items: center; gap: 10px;
    padding: 10px 16px; cursor: pointer;
    transition: background 0.12s; border-left: 3px solid transparent;
    text-decoration: none !important;
}
.vtm-vehicle-item:hover { background: #f8f9fc; }
.vtm-vehicle-item.active {
    background: #f0fdf9; border-left-color: #0f766e;
}
.vtm-vi-dot {
    width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0;
}
.vtm-vi-dot.available    { background: #16a34a; }
.vtm-vi-dot.inuse        { background: #2563eb; animation: pulse-b 1.4s infinite; }
.vtm-vi-dot.outofservice { background: #dc2626; }
@keyframes pulse-b {
    0%,100% { box-shadow: 0 0 0 0 rgba(37,99,235,0.5); }
    50%      { box-shadow: 0 0 0 5px rgba(37,99,235,0); }
}
.vtm-vi-info { flex: 1; min-width: 0; }
.vtm-vi-name { font-size: 13px; font-weight: 600; color: #1e293b; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.vtm-vi-ref  { font-size: 11px; color: #9aa0b4; font-family: 'DM Mono', monospace; }
.vtm-vi-badge {
    font-size: 10px; font-weight: 700; text-transform: uppercase;
    padding: 2px 8px; border-radius: 10px; flex-shrink: 0; letter-spacing: 0.4px;
}
.vtm-vi-badge.available    { background: #d1fae5; color: #065f46; }
.vtm-vi-badge.inuse        { background: #dbeafe; color: #1e40af; }
.vtm-vi-badge.outofservice { background: #fee2e2; color: #991b1b; }

/* ── Map panel ── */
.vtm-map-panel {
    flex: 1; position: relative; display: flex; flex-direction: column;
    overflow: hidden;
}

/* Vehicle info strip above map */
.vtm-info-strip {
    display: flex; align-items: center; gap: 16px;
    padding: 10px 18px; background: #1e293b; color: #e2e8f0;
    font-size: 12.5px; flex-wrap: wrap; flex-shrink: 0;
}
.vtm-info-strip .vtm-is-name { font-size: 14px; font-weight: 700; color: #fff; }
.vtm-info-strip .vtm-is-sep  { color: #475569; }
.vtm-info-strip .vtm-is-item { display: flex; align-items: center; gap: 5px; color: #94a3b8; }
.vtm-info-strip .vtm-is-item i { color: #64748b; }
.vtm-info-strip .vtm-is-item strong { color: #e2e8f0; }
.vtm-is-badge {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 3px 10px; border-radius: 12px; font-size: 11px; font-weight: 700;
}
.vtm-is-badge.available    { background: rgba(22,163,74,0.2);  color: #4ade80; }
.vtm-is-badge.inuse        { background: rgba(37,99,235,0.2);  color: #93c5fd; }
.vtm-is-badge.outofservice { background: rgba(220,38,38,0.2);  color: #fca5a5; }

/* Route summary bar */
.vtm-route-bar {
    display: flex; align-items: stretch; gap: 0;
    background: #f8f9fc; border-bottom: 1px solid #e8eaf0;
    font-size: 12.5px; flex-shrink: 0; flex-wrap: wrap;
}
.vtm-route-point {
    display: flex; align-items: center; gap: 8px;
    padding: 10px 16px; flex: 1; min-width: 0;
    border-right: 1px solid #e8eaf0;
}
.vtm-route-point:last-child { border-right: none; }
.vtm-route-point .vtm-rp-icon {
    width: 28px; height: 28px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 12px; flex-shrink: 0;
}
.vtm-rp-icon.dep  { background: #dcfce7; color: #16a34a; }
.vtm-rp-icon.arr  { background: #fee2e2; color: #dc2626; }
.vtm-rp-icon.curr { background: #dbeafe; color: #2563eb; }
.vtm-route-point .vtm-rp-label { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: #9aa0b4; }
.vtm-route-point .vtm-rp-addr  { font-size: 12.5px; font-weight: 600; color: #1e293b; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.vtm-rp-none { color: #9aa0b4; font-style: italic; font-weight: 400; }

/* Map container */
#vtm-map {
    flex: 1;
    z-index: 1;
}

/* Fullscreen button overlay */
.vtm-fs-btn-overlay {
    position: absolute; bottom: 24px; right: 16px; z-index: 1000;
}

/* No-address banner (shown over map) */
.vtm-no-addr-banner {
    position: absolute; bottom: 70px; left: 50%; transform: translateX(-50%);
    z-index: 1000; background: rgba(30,41,59,0.92); color: #e2e8f0;
    padding: 10px 18px; border-radius: 10px; font-size: 12.5px;
    display: flex; align-items: center; gap: 8px; white-space: nowrap;
    box-shadow: 0 4px 16px rgba(0,0,0,0.25);
    max-width: 90%;
}
.vtm-no-addr-banner i { color: #60a5fa; flex-shrink: 0; }
.vtm-no-addr-banner a { color: #4ade80; font-weight: 600; margin-left: 4px; }

/* No address overlay */
.vtm-no-addr {
    flex: 1; display: flex; flex-direction: column;
    align-items: center; justify-content: center;
    background: #f8f9fc; color: #94a3b8; gap: 12px; padding: 40px;
}
.vtm-no-addr i { font-size: 56px; opacity: 0.25; }
.vtm-no-addr h3 { font-size: 16px; font-weight: 600; color: #64748b; margin: 0; }
.vtm-no-addr p  { font-size: 13px; color: #9aa0b4; margin: 0; text-align: center; max-width: 320px; }

/* Leaflet custom markers */
.vtm-marker-dep  { background: #16a34a; border: 3px solid #fff; border-radius: 50%; box-shadow: 0 2px 8px rgba(0,0,0,0.3); }
.vtm-marker-arr  { background: #dc2626; border: 3px solid #fff; border-radius: 50%; box-shadow: 0 2px 8px rgba(0,0,0,0.3); }
.vtm-marker-curr {
    background: #2563eb; border: 3px solid #fff; border-radius: 50%;
    box-shadow: 0 0 0 6px rgba(37,99,235,0.25);
    animation: pulse-curr 1.8s infinite;
}
@keyframes pulse-curr {
    0%,100% { box-shadow: 0 0 0 0 rgba(37,99,235,0.4); }
    50%      { box-shadow: 0 0 0 10px rgba(37,99,235,0); }
}

/* Sidebar toggle for mobile */
.vtm-sidebar-toggle {
    display: none; background: #0f766e; color: #fff; border: none;
    padding: 7px 12px; border-radius: 7px; cursor: pointer; font-size: 13px; font-weight: 600;
}

/* Fullscreen mode */
.vtm-wrap.fullscreen {
    position: fixed; inset: 0; z-index: 9998; height: 100vh !important;
    background: #fff;
}
.vtm-wrap.fullscreen .vtm-topbar { display: none; }

/* Leaflet popup */
.vtm-popup { font-family: 'DM Sans', sans-serif; font-size: 13px; min-width: 180px; }
.vtm-popup strong { display: block; font-size: 14px; margin-bottom: 4px; color: #1e293b; }
.vtm-popup span   { color: #64748b; font-size: 12px; }

/* Responsive */
@media (max-width: 768px) {
    .vtm-sidebar { position: absolute; left: 0; top: 0; bottom: 0; z-index: 500; transform: translateX(-100%); transition: transform 0.25s; }
    .vtm-sidebar.open { transform: translateX(0); }
    .vtm-sidebar-toggle { display: inline-flex; align-items: center; gap: 6px; }
    .vtm-map-panel { width: 100%; }
}
</style>

<div class="vtm-wrap" id="vtmWrap">

    <!-- ── Top bar ── -->
    <div class="vtm-topbar">
        <div class="vtm-topbar-left">
            <button class="vtm-btn vtm-sidebar-toggle" onclick="toggleSidebar()">
                <i class="fa fa-bars"></i> <?php echo $langs->trans('Vehicles'); ?>
            </button>
            <div class="vtm-topbar-title">
                <i class="fa fa-map-marker-alt"></i> <?php echo $langs->trans('VehicleTrackingMap'); ?>
            </div>
        </div>
        <div style="display:flex;gap:8px;align-items:center;">
            <button class="vtm-btn vtm-btn-fs" onclick="toggleFullscreen()" id="fsBtn">
                <i class="fa fa-expand" id="fsIcon"></i> <?php echo $langs->trans('FullScreen'); ?>
            </button>
            <a href="<?php echo dol_buildpath('/flotte/tracking_list.php', 1); ?>" class="vtm-btn">
                <i class="fa fa-th-large"></i> <?php echo $langs->trans('CardView'); ?>
            </a>
            <a href="<?php echo dol_buildpath('/flotte/flotteindex.php', 1); ?>" class="vtm-btn">
                <i class="fa fa-arrow-left"></i> <?php echo $langs->trans('Dashboard'); ?>
            </a>
        </div>
    </div>

    <!-- ── Body ── -->
    <div class="vtm-body">

        <!-- ── Sidebar ── -->
        <div class="vtm-sidebar" id="vtmSidebar">
            <div class="vtm-sidebar-header">
                <div class="vtm-sidebar-title"><?php echo $langs->trans('Vehicles'); ?> (<?php echo count($all_vehicles); ?>)</div>
                <input type="text" class="vtm-search" id="vtmSearch" placeholder="<?php echo $langs->trans('SearchVehicle'); ?>..." oninput="filterVehicles(this.value)">
            </div>
            <div class="vtm-vehicle-list" id="vtmVehicleList">
                <?php foreach ($all_vehicles as $v):
                    $vname = trim($v->maker.' '.$v->model);
                    if (empty($vname)) $vname = $v->ref;
                    $st = $v->computed_status;
                    $st_label = $st === 'available' ? $langs->trans('Available') : ($st === 'inuse' ? $langs->trans('InUse') : $langs->trans('Off'));
                    $is_active = ($v->rowid == $selected_id);
                ?>
                <a href="<?php echo dol_buildpath('/flotte/vehicle_tracking_map.php', 1).'?id='.(int)$v->rowid; ?>"
                   class="vtm-vehicle-item <?php echo $is_active ? 'active' : ''; ?>"
                   data-name="<?php echo strtolower(dol_escape_htmltag($vname)); ?>"
                   data-ref="<?php echo strtolower(dol_escape_htmltag($v->ref)); ?>"
                   data-plate="<?php echo strtolower(dol_escape_htmltag($v->license_plate ?? '')); ?>">
                    <span class="vtm-vi-dot <?php echo $st; ?>"></span>
                    <div class="vtm-vi-info">
                        <div class="vtm-vi-name"><?php echo dol_escape_htmltag($vname); ?></div>
                        <div class="vtm-vi-ref"><?php echo dol_escape_htmltag($v->ref); ?><?php if (!empty($v->license_plate)) echo ' · '.dol_escape_htmltag($v->license_plate); ?></div>
                    </div>
                    <span class="vtm-vi-badge <?php echo $st; ?>"><?php echo $st_label; ?></span>
                </a>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- ── Map panel ── -->
        <div class="vtm-map-panel">

            <?php if ($selected_vehicle): 
                $sv = $selected_vehicle;
                $sv_name = trim($sv->maker.' '.$sv->model);
                if (empty($sv_name)) $sv_name = $sv->ref;
                $sv_st = $sv->computed_status;
                $sv_st_label = $sv_st === 'available' ? $langs->trans('Available') : ($sv_st === 'inuse' ? $langs->trans('InUse') : $langs->trans('OutOfService'));
                $sv_driver   = trim(($sv->driver_firstname ?? '').' '.($sv->driver_lastname ?? ''));
                $sv_customer = trim(($sv->customer_firstname ?? '').' '.($sv->customer_lastname ?? ''));
            ?>

            <!-- Info strip -->
            <div class="vtm-info-strip">
                <span class="vtm-is-name"><?php echo dol_escape_htmltag($sv_name); ?></span>
                <span class="vtm-is-sep">·</span>
                <span class="vtm-is-badge <?php echo $sv_st; ?>"><?php echo $sv_st_label; ?></span>
                <?php if (!empty($sv->license_plate)): ?>
                <span class="vtm-is-sep">·</span>
                <span class="vtm-is-item"><i class="fa fa-id-card"></i> <strong><?php echo dol_escape_htmltag($sv->license_plate); ?></strong></span>
                <?php endif; ?>
                <?php if (!empty($sv_driver)): ?>
                <span class="vtm-is-sep">·</span>
                <span class="vtm-is-item"><i class="fa fa-user"></i> <strong><?php echo dol_escape_htmltag($sv_driver); ?></strong></span>
                <?php endif; ?>
                <?php if (!empty($sv_customer)): ?>
                <span class="vtm-is-sep">·</span>
                <span class="vtm-is-item"><i class="fa fa-user-tie"></i> <strong><?php echo dol_escape_htmltag($sv_customer); ?></strong></span>
                <?php endif; ?>
                <?php if (!empty($sv->eta)): ?>
                <span class="vtm-is-sep">·</span>
                <span class="vtm-is-item"><i class="fa fa-clock"></i> ETA: <strong><?php echo dol_escape_htmltag($sv->eta); ?></strong></span>
                <?php endif; ?>
                <?php if (!empty($sv->selling_amount_ttc) && $sv->selling_amount_ttc > 0): ?>
                <span class="vtm-is-sep">·</span>
                <span class="vtm-is-item"><i class="fa fa-money-bill-wave"></i> <strong style="color:#4ade80;"><?php echo price($sv->selling_amount_ttc, 0, '', 1, -1, -1, $conf->currency); ?></strong></span>
                <?php endif; ?>
            </div>

            <!-- Route bar -->
            <div class="vtm-route-bar">
                <div class="vtm-route-point">
                    <div class="vtm-rp-icon dep"><i class="fa fa-map-pin"></i></div>
                    <div style="min-width:0;">
                        <div class="vtm-rp-label"><?php echo $langs->trans('From'); ?></div>
                        <div class="vtm-rp-addr">
                            <?php if (!empty($sv->departure_address)): ?>
                                <?php echo dol_escape_htmltag($sv->departure_address); ?>
                            <?php else: ?>
                                <span class="vtm-rp-none"><?php echo $langs->trans('NoDepartureSet'); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php if ($sv_st === 'inuse'): ?>
                <div class="vtm-route-point">
                    <div class="vtm-rp-icon curr"><i class="fa fa-truck"></i></div>
                    <div style="min-width:0;">
                        <div class="vtm-rp-label"><?php echo $langs->trans('Status'); ?></div>
                        <div class="vtm-rp-addr"><?php echo dol_escape_htmltag(ucwords(str_replace('_', ' ', $sv->booking_status ?? 'Active'))); ?></div>
                    </div>
                </div>
                <?php endif; ?>
                <div class="vtm-route-point">
                    <div class="vtm-rp-icon arr"><i class="fa fa-flag-checkered"></i></div>
                    <div style="min-width:0;">
                        <div class="vtm-rp-label"><?php echo $langs->trans('To'); ?></div>
                        <div class="vtm-rp-addr">
                            <?php if (!empty($sv->arriving_address)): ?>
                                <?php echo dol_escape_htmltag($sv->arriving_address); ?>
                            <?php else: ?>
                                <span class="vtm-rp-none"><?php echo $langs->trans('NoDestinationSet'); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Map always shown -->
            <div id="vtm-map"></div>
            <?php if (empty($sv->departure_address) && empty($sv->arriving_address)): ?>
            <div class="vtm-no-addr-banner">
                <i class="fa fa-info-circle"></i>
                <?php echo $langs->trans('NoAddressesRecorded'); ?>
                <a href="<?php echo dol_buildpath('/flotte/booking_card.php', 1).'?id='.(int)$sv->booking_id; ?>"><?php echo $langs->trans('AddAddresses'); ?></a>
            </div>
            <?php endif; ?>
            <div class="vtm-fs-btn-overlay">
                <button class="vtm-btn vtm-btn-fs" onclick="toggleFullscreen()" style="box-shadow:0 4px 16px rgba(0,0,0,0.2);">
                    <i class="fa fa-expand" id="fsIcon2"></i>
                </button>
            </div>

            <?php else: ?>
            <div class="vtm-no-addr">
                <i class="fa fa-car"></i>
                <h3><?php echo $langs->trans('NoVehicleSelected'); ?></h3>
                <p><?php echo $langs->trans('SelectVehicleFromSidebar'); ?></p>
            </div>
            <?php endif; ?>

        </div><!-- /vtm-map-panel -->
    </div><!-- /vtm-body -->
</div><!-- /vtm-wrap -->

<?php if ($selected_vehicle): ?>
<!-- ── Leaflet ── -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<!-- Leaflet Routing Machine -->
<link rel="stylesheet" href="https://unpkg.com/leaflet-routing-machine@3.2.12/dist/leaflet-routing-machine.css"/>
<script src="https://unpkg.com/leaflet-routing-machine@3.2.12/dist/leaflet-routing-machine.js"></script>

<script>
var DEP_ADDR  = <?php echo json_encode($selected_vehicle->departure_address ?? ''); ?>;
var ARR_ADDR  = <?php echo json_encode($selected_vehicle->arriving_address  ?? ''); ?>;
var V_NAME    = <?php echo json_encode(trim(($selected_vehicle->maker ?? '').' '.($selected_vehicle->model ?? '')) ?: $selected_vehicle->ref); ?>;
var V_STATUS  = <?php echo json_encode($selected_vehicle->computed_status ?? 'available'); ?>;
var V_BOOKING_STATUS = <?php echo json_encode($selected_vehicle->booking_status ?? ''); ?>;

// ── Init map ──
var map = L.map('vtm-map', { zoomControl: true }).setView([0, 0], 2);

L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
    maxZoom: 19
}).addTo(map);

// ── Custom marker icons ──
function makeIcon(cls, size) {
    size = size || 16;
    return L.divIcon({
        className: '',
        html: '<div style="width:'+size+'px;height:'+size+'px;border-radius:50%;border:3px solid #fff;box-shadow:0 2px 8px rgba(0,0,0,0.3);" class="'+cls+'"></div>',
        iconSize: [size, size],
        iconAnchor: [size/2, size/2],
        popupAnchor: [0, -(size/2 + 4)]
    });
}

var iconDep  = makeIcon('vtm-marker-dep', 18);
var iconArr  = makeIcon('vtm-marker-arr', 18);
var iconCurr = makeIcon('vtm-marker-curr', 20);

// ── Geocode using Nominatim ──
function geocode(address, callback) {
    if (!address) { callback(null); return; }
    var url = 'https://nominatim.openstreetmap.org/search?format=json&limit=1&q=' + encodeURIComponent(address);
    fetch(url, { headers: { 'Accept-Language': 'en' } })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data && data.length > 0) {
                callback({ lat: parseFloat(data[0].lat), lng: parseFloat(data[0].lon), display: data[0].display_name });
            } else {
                callback(null);
            }
        })
        .catch(function() { callback(null); });
}

var depLatLng  = null;
var arrLatLng  = null;
var routeControl = null;

function buildMap() {
    var geocoded = 0;
    var needed   = (DEP_ADDR ? 1 : 0) + (ARR_ADDR ? 1 : 0);
    if (needed === 0) {
        // No addresses — show a default world view with a vehicle marker
        map.setView([20, 10], 2);
        L.marker([20, 10], { icon: iconCurr })
            .bindPopup('<div class="vtm-popup"><strong>🔵 ' + V_NAME + '</strong><span>No address data available</span></div>')
            .addTo(map);
        return;
    }

    function onGeocoded() {
        geocoded++;
        if (geocoded < needed) return;

        var bounds = [];

        // Add departure marker
        if (depLatLng) {
            var depMarker = L.marker([depLatLng.lat, depLatLng.lng], { icon: iconDep })
                .bindPopup('<div class="vtm-popup"><strong>🟢 Departure</strong><span>' + (DEP_ADDR || '') + '</span></div>')
                .addTo(map);
            bounds.push([depLatLng.lat, depLatLng.lng]);
        }

        // Add arrival marker
        if (arrLatLng) {
            var arrMarker = L.marker([arrLatLng.lat, arrLatLng.lng], { icon: iconArr })
                .bindPopup('<div class="vtm-popup"><strong>🔴 Destination</strong><span>' + (ARR_ADDR || '') + '</span></div>')
                .addTo(map);
            bounds.push([arrLatLng.lat, arrLatLng.lng]);
        }

        // Draw route if both points exist
        if (depLatLng && arrLatLng) {
            routeControl = L.Routing.control({
                waypoints: [
                    L.latLng(depLatLng.lat, depLatLng.lng),
                    L.latLng(arrLatLng.lat, arrLatLng.lng)
                ],
                routeWhileDragging: false,
                addWaypoints: false,
                draggableWaypoints: false,
                fitSelectedRoutes: true,
                show: false, // hide the turn-by-turn panel
                createMarker: function() { return null; }, // use our custom markers
                lineOptions: {
                    styles: [
                        { color: '#0f766e', opacity: 0.15, weight: 9 },
                        { color: '#0f766e', opacity: 0.8,  weight: 4 }
                    ]
                },
                router: L.Routing.osrmv1({
                    serviceUrl: 'https://router.project-osrm.org/route/v1'
                })
            }).addTo(map);

            // Add "current position" marker at midpoint of route once loaded
            routeControl.on('routesfound', function(e) {
                var coords = e.routes[0].coordinates;
                if (coords && coords.length > 0 && V_STATUS === 'inuse') {
                    var mid = coords[Math.floor(coords.length * 0.45)];
                    L.marker([mid.lat, mid.lng], { icon: iconCurr })
                        .bindPopup('<div class="vtm-popup"><strong>🔵 ' + V_NAME + '</strong><span>Estimated current position along route</span></div>')
                        .addTo(map)
                        .openPopup();
                }
            });

        } else if (bounds.length > 0) {
            // Only one point — center on it
            map.setView([bounds[0][0], bounds[0][1]], 13);
        }

        // Fit bounds with padding
        if (bounds.length > 1) {
            map.fitBounds(bounds, { padding: [40, 40] });
        }
    }

    if (DEP_ADDR) {
        geocode(DEP_ADDR, function(result) {
            depLatLng = result;
            onGeocoded();
        });
    }
    if (ARR_ADDR) {
        geocode(ARR_ADDR, function(result) {
            arrLatLng = result;
            onGeocoded();
        });
    }
}

buildMap();

// ── Sidebar search ──
function filterVehicles(q) {
    q = q.toLowerCase().trim();
    document.querySelectorAll('.vtm-vehicle-item').forEach(function(el) {
        var name  = el.dataset.name  || '';
        var ref   = el.dataset.ref   || '';
        var plate = el.dataset.plate || '';
        el.style.display = (!q || name.includes(q) || ref.includes(q) || plate.includes(q)) ? '' : 'none';
    });
}

// ── Mobile sidebar toggle ──
function toggleSidebar() {
    document.getElementById('vtmSidebar').classList.toggle('open');
}

// ── Fullscreen ──
function toggleFullscreen() {
    var wrap = document.getElementById('vtmWrap');
    var icon1 = document.getElementById('fsIcon');
    var icon2 = document.getElementById('fsIcon2');
    var btn   = document.getElementById('fsBtn');

    if (wrap.classList.contains('fullscreen')) {
        wrap.classList.remove('fullscreen');
        if (icon1) { icon1.className = 'fa fa-expand'; }
        if (icon2) { icon2.className = 'fa fa-expand'; }
        if (btn)   { btn.innerHTML = '<i class="fa fa-expand"></i> <?php echo $langs->trans("FullScreen"); ?>'; }
        document.body.style.overflow = '';
    } else {
        wrap.classList.add('fullscreen');
        if (icon1) { icon1.className = 'fa fa-compress'; }
        if (icon2) { icon2.className = 'fa fa-compress'; }
        if (btn)   { btn.innerHTML = '<i class="fa fa-compress"></i> <?php echo $langs->trans("ExitFullScreen"); ?>'; }
        document.body.style.overflow = 'hidden';
    }
    // Invalidate map size after layout change
    setTimeout(function() { map.invalidateSize(); }, 300);
}

// Escape key exits fullscreen
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && document.getElementById('vtmWrap').classList.contains('fullscreen')) {
        toggleFullscreen();
    }
});
</script>
<?php else: ?>
<script>
function filterVehicles(q) {
    q = q.toLowerCase().trim();
    document.querySelectorAll('.vtm-vehicle-item').forEach(function(el) {
        var name  = el.dataset.name  || '';
        var ref   = el.dataset.ref   || '';
        var plate = el.dataset.plate || '';
        el.style.display = (!q || name.includes(q) || ref.includes(q) || plate.includes(q)) ? '' : 'none';
    });
}
function toggleSidebar() {
    document.getElementById('vtmSidebar').classList.toggle('open');
}
function toggleFullscreen() {
    var wrap = document.getElementById('vtmWrap');
    wrap.classList.toggle('fullscreen');
    document.body.style.overflow = wrap.classList.contains('fullscreen') ? 'hidden' : '';
}
</script>
<?php endif; ?>

<?php
print '</div>'; // fichecenter
print dol_get_fiche_end();
llxFooter();
$db->close();
?>
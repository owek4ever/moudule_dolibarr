<?php
/* Copyright (C) 2024 Your Company
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

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
if (!$res && file_exists("../main.inc.php"))      { $res = @include "../main.inc.php"; }
if (!$res && file_exists("../../main.inc.php"))   { $res = @include "../../main.inc.php"; }
if (!$res && file_exists("../../../main.inc.php")){ $res = @include "../../../main.inc.php"; }
if (!$res) { die("Include of main fails"); }

require_once DOL_DOCUMENT_ROOT.'/core/class/html.formcompany.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';

// Load translation files
$langs->loadLangs(array("flotte@flotte", "other"));

// Get parameters
$action = GETPOST('action', 'aZ09') ? GETPOST('action', 'aZ09') : 'view';
$massaction = GETPOST('massaction', 'alpha');
$confirm = GETPOST('confirm', 'alpha');
$toselect = GETPOST('toselect', 'array');

$search_ref = GETPOST('search_ref', 'alpha');
$search_maker = GETPOST('search_maker', 'alpha');
$search_model = GETPOST('search_model', 'alpha');
$search_license_plate = GETPOST('search_license_plate', 'alpha');
$search_status = GETPOST('search_status', 'alpha');

$limit = GETPOST('limit', 'int') ? GETPOST('limit', 'int') : $conf->liste_limit;
$sortfield = GETPOST('sortfield', 'aZ09comma');
$sortorder = GETPOST('sortorder', 'aZ09comma');
$page = GETPOSTISSET('pageplusone') ? (GETPOST('pageplusone') - 1) : GETPOST("page", 'int');

if (empty($page) || $page == -1) {
    $page = 0;
}

$offset = $limit * $page;

if (!$sortfield) {
    $sortfield = "t.ref";
}
if (!$sortorder) {
    $sortorder = "ASC";
}

// Security check
restrictedArea($user, 'flotte');

// Initialize form object
$form = new Form($db);

/*
 * Actions
 */
if (GETPOST('cancel', 'alpha')) {
    $action = 'list';
    $massaction = '';
}

if (GETPOST('button_removefilter_x', 'alpha') || GETPOST('button_removefilter.x', 'alpha') || GETPOST('button_removefilter', 'alpha')) {
    $search_ref = '';
    $search_maker = '';
    $search_model = '';
    $search_license_plate = '';
    $search_status = '';
}

// Handle confirmed delete from list page
if ($action == 'confirm_delete' && GETPOST('confirm', 'alpha') == 'yes') {
    $id_to_delete = GETPOST('id', 'int');
    if ($id_to_delete > 0) {
        $db->begin();
        $sql_del = "DELETE FROM ".MAIN_DB_PREFIX."flotte_vehicle WHERE rowid = ".(int)$id_to_delete." AND entity IN (".getEntity('flotte').")";
        $resql_del = $db->query($sql_del);
        if ($resql_del) {
            $db->commit();
            setEventMessages($langs->trans("VehicleDeletedSuccessfully"), null, 'mesgs');
        } else {
            $db->rollback();
            setEventMessages("Error in SQL: ".$db->lasterror(), null, 'errors');
        }
    }
    $action = 'list';
}

// Build and execute select
$sql = 'SELECT t.rowid, t.ref, t.maker, t.model, t.type, t.year, t.license_plate, t.color, t.vin, t.in_service, t.initial_mileage, t.registration_expiry, t.license_expiry';
$sql .= ' FROM '.MAIN_DB_PREFIX.'flotte_vehicle as t';
$sql .= ' WHERE 1 = 1';
$sql .= ' AND t.entity IN ('.getEntity('flotte').')';

if ($search_ref) {
    $sql .= " AND t.ref LIKE '%".$db->escape($search_ref)."%'";
}
if ($search_maker) {
    $sql .= " AND t.maker LIKE '%".$db->escape($search_maker)."%'";
}
if ($search_model) {
    $sql .= " AND t.model LIKE '%".$db->escape($search_model)."%'";
}
if ($search_license_plate) {
    $sql .= " AND t.license_plate LIKE '%".$db->escape($search_license_plate)."%'";
}
if ($search_status !== '') {
    if ($search_status == '1') {
        $sql .= " AND t.in_service = 1";
    } elseif ($search_status == '0') {
        $sql .= " AND t.in_service = 0";
    }
}

$sql .= $db->order($sortfield, $sortorder);

// Count total nb of records
$sqlcount = str_replace('SELECT t.rowid, t.ref, t.maker, t.model, t.type, t.year, t.license_plate, t.color, t.vin, t.in_service, t.initial_mileage, t.registration_expiry, t.license_expiry', 'SELECT COUNT(*) as nb', $sql);
$resql = $db->query($sqlcount);
$nbtotalofrecords = 0;
if ($resql) {
    $obj = $db->fetch_object($resql);
    $nbtotalofrecords = $obj->nb;
}

$sql .= $db->plimit($limit + 1, $offset);
$resql = $db->query($sql);

$num = 0;
if ($resql) {
    $num = $db->num_rows($resql);
}

// Build param string for URL
$param = '';
if (!empty($search_ref))           $param .= '&search_ref='.urlencode($search_ref);
if (!empty($search_maker))         $param .= '&search_maker='.urlencode($search_maker);
if (!empty($search_model))         $param .= '&search_model='.urlencode($search_model);
if (!empty($search_license_plate)) $param .= '&search_license_plate='.urlencode($search_license_plate);
if (!empty($search_status))        $param .= '&search_status='.urlencode($search_status);

// Page header
llxHeader('', $langs->trans("Vehicles List"), '');

// Show delete confirmation dialog if requested
if ($action == 'delete') {
    $id_to_delete = GETPOST('id', 'int');
    if ($id_to_delete > 0) {
        $formconfirm = $form->formconfirm($_SERVER["PHP_SELF"].'?id='.$id_to_delete, $langs->trans('DeleteVehicle'), $langs->trans('ConfirmDeleteVehicle'), 'confirm_delete', '', 0, 1);
        print $formconfirm;
    }
}

// Collect rows into array for display
$rows = array();
if ($resql && $num > 0) {
    $i = 0;
    while ($i < min($num, $limit)) {
        $obj = $db->fetch_object($resql);
        if (empty($obj)) break;
        $rows[] = $obj;
        $i++;
    }
}

// Sort helpers
function sortArrow($field, $sortfield, $sortorder) {
    if ($sortfield == $field) {
        return $sortorder == 'ASC' ? ' <span class="vl-sort-arrow">↑</span>' : ' <span class="vl-sort-arrow">↓</span>';
    }
    return ' <span class="vl-sort-arrow muted">↕</span>';
}
function sortHref($field, $sortfield, $sortorder, $self, $param) {
    $dir = ($sortfield == $field && $sortorder == 'ASC') ? 'DESC' : 'ASC';
    return $self.'?sortfield='.$field.'&sortorder='.$dir.'&'.$param;
}

$self = $_SERVER["PHP_SELF"];
?>

<style>
/* ── Google Font ── */
@import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=DM+Mono:wght@400;500&display=swap');

/* ── Reset scoped to our container ── */
.vl-wrap * { box-sizing: border-box; }

.vl-wrap {
    font-family: 'DM Sans', sans-serif;
    max-width: 1480px;
    margin: 0 auto;
    padding: 0 4px 40px;
    color: #1a1f2e;
}

/* ── Page Header ── */
.vl-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 28px 0 24px;
    border-bottom: 1px solid #e8eaf0;
    margin-bottom: 24px;
    gap: 16px;
    flex-wrap: wrap;
}

.vl-header-left h1 {
    font-size: 22px;
    font-weight: 700;
    color: #1a1f2e;
    margin: 0 0 4px;
    letter-spacing: -0.3px;
}

.vl-header-left .vl-subtitle {
    font-size: 13px;
    color: #7c859c;
    font-weight: 400;
}

.vl-header-actions {
    display: flex;
    gap: 10px;
    align-items: center;
    flex-wrap: wrap;
}

.vl-btn {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 8px 16px;
    border-radius: 6px;
    font-size: 13px;
    font-weight: 600;
    text-decoration: none !important;
    transition: all 0.15s ease;
    border: none;
    cursor: pointer;
    font-family: 'DM Sans', sans-serif;
    white-space: nowrap;
}

.vl-btn-primary {
    background: #3c4758 !important;
    color: #fff !important;
}
.vl-btn-primary:hover {
    background: #2a3346 !important;
    color: #fff !important;
    text-decoration: none !important;
}

.vl-btn-secondary {
    background: #3c4758 !important;
    color: #fff !important;
    border: none !important;
}
.vl-btn-secondary:hover {
    background: #2a3346 !important;
    color: #fff !important;
    text-decoration: none !important;
}

/* ── Filter Bar ── */
.vl-filters {
    background: #fff;
    border: 1px solid #e8eaf0;
    border-radius: 12px;
    padding: 18px 20px;
    margin-bottom: 20px;
    display: flex;
    gap: 12px;
    align-items: flex-end;
    flex-wrap: wrap;
    box-shadow: 0 1px 4px rgba(0,0,0,0.04);
}

.vl-filter-group {
    display: flex;
    flex-direction: column;
    gap: 5px;
    flex: 1;
    min-width: 130px;
}

.vl-filter-group label {
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.6px;
    color: #9aa0b4;
}

.vl-filter-group input,
.vl-filter-group select {
    padding: 8px 12px;
    border: 1.5px solid #e2e5f0;
    border-radius: 8px;
    font-size: 13px;
    font-family: 'DM Sans', sans-serif;
    color: #2d3748;
    background: #fafbfe;
    outline: none;
    transition: border-color 0.15s, box-shadow 0.15s;
    width: 100%;
}

.vl-filter-group input:focus,
.vl-filter-group select:focus {
    border-color: #3c4758;
    box-shadow: 0 0 0 3px rgba(60,71,88,0.1);
    background: #fff;
}

.vl-filter-actions {
    display: flex;
    gap: 8px;
    align-items: flex-end;
    padding-bottom: 1px;
}

.vl-btn-filter {
    padding: 8px 16px;
    font-size: 13px;
    border-radius: 8px;
    font-weight: 600;
    border: none;
    cursor: pointer;
    font-family: 'DM Sans', sans-serif;
    transition: all 0.15s;
    white-space: nowrap;
}
.vl-btn-filter.apply {
    background: #3c4758 !important;
    color: #fff !important;
}
.vl-btn-filter.apply:hover { opacity: 0.9; }
.vl-btn-filter.reset {
    background: #3c4758 !important;
    color: #fff !important;
}
.vl-btn-filter.reset:hover { background: #2a3346 !important; }

/* ── Stats Row ── */
.vl-stats {
    display: flex;
    gap: 10px;
    margin-bottom: 16px;
    flex-wrap: wrap;
}

.vl-stat-chip {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 14px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    background: #f0f2fa;
    color: #5a6482;
}
.vl-stat-chip .vl-stat-num {
    font-size: 14px;
    font-weight: 700;
    color: #1a1f2e;
}
.vl-stat-chip.active { background: #edfaf3; color: #1a7d4a; }
.vl-stat-chip.active .vl-stat-num { color: #1a7d4a; }
.vl-stat-chip.inactive { background: #fef2f2; color: #b91c1c; }
.vl-stat-chip.inactive .vl-stat-num { color: #b91c1c; }

/* ── Table Card ── */
.vl-table-card {
    background: #fff;
    border: 1px solid #e8eaf0;
    border-radius: 14px;
    overflow: hidden;
    box-shadow: 0 2px 12px rgba(0,0,0,0.05);
}

.vl-table-wrap {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}

table.vl-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13.5px;
}

table.vl-table thead tr {
    background: #f7f8fc;
    border-bottom: 2px solid #e8eaf0;
}

table.vl-table thead th {
    padding: 13px 16px;
    text-align: left;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.7px;
    color: #8b92a9;
    white-space: nowrap;
}

table.vl-table thead th a {
    color: #8b92a9;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    transition: color 0.15s;
}
table.vl-table thead th a:hover { color: #3c4758; }

.vl-sort-arrow { font-size: 10px; opacity: 0.6; }
.vl-sort-arrow.muted { opacity: 0.25; }

table.vl-table thead th.center { text-align: center; }
table.vl-table thead th.right  { text-align: right; }

table.vl-table tbody tr {
    border-bottom: 1px solid #f0f2f8;
    transition: background 0.12s;
}
table.vl-table tbody tr:last-child { border-bottom: none; }
table.vl-table tbody tr:hover { background: #fafbff; }

table.vl-table tbody td {
    padding: 14px 16px;
    color: #2d3748;
    vertical-align: middle;
}

table.vl-table tbody td.center { text-align: center; }
table.vl-table tbody td.right  { text-align: right; }

/* ── Cell Styles ── */
.vl-ref-link {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    text-decoration: none;
    color: #3c4758;
    font-weight: 600;
    font-family: 'DM Mono', monospace;
    font-size: 13px;
    transition: color 0.15s;
}
.vl-ref-link:hover { color: #2a3346; text-decoration: none; }

.vl-ref-icon {
    width: 30px;
    height: 30px;
    background: rgba(60,71,88,0.08);
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #3c4758;
    font-size: 14px;
    flex-shrink: 0;
}

.vl-vehicle-name {
    font-weight: 600;
    color: #1a1f2e;
    font-size: 13.5px;
}
.vl-vehicle-sub {
    font-size: 11.5px;
    color: #9aa0b4;
    margin-top: 2px;
}

.vl-plate {
    display: inline-block;
    font-family: 'DM Mono', monospace;
    font-size: 12px;
    font-weight: 500;
    padding: 4px 10px;
    background: #f0f2fa;
    border-radius: 6px;
    border: 1px solid #dde1ec;
    color: #2d3748;
    letter-spacing: 1px;
}

.vl-color-swatch {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    font-size: 12.5px;
    color: #4a5568;
    font-weight: 500;
}
.vl-color-dot {
    width: 14px;
    height: 14px;
    border-radius: 50%;
    border: 2px solid rgba(0,0,0,0.1);
    flex-shrink: 0;
}

.vl-vin {
    font-family: 'DM Mono', monospace;
    font-size: 11.5px;
    color: #7c859c;
    max-width: 140px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    display: block;
}

.vl-mileage {
    font-weight: 600;
    color: #2d3748;
    font-size: 13px;
}
.vl-mileage-unit {
    font-size: 11px;
    color: #9aa0b4;
    font-weight: 400;
    margin-left: 2px;
}

/* ── Status Badge ── */
.vl-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 11.5px;
    font-weight: 600;
    white-space: nowrap;
}
.vl-badge::before {
    content: '';
    width: 6px;
    height: 6px;
    border-radius: 50%;
    flex-shrink: 0;
}
.vl-badge.in-service {
    background: #edfaf3;
    color: #1a7d4a;
}
.vl-badge.in-service::before { background: #22c55e; }

.vl-badge.out-of-service {
    background: #fef2f2;
    color: #b91c1c;
}
.vl-badge.out-of-service::before { background: #ef4444; }

/* ── Action Buttons ── */
.vl-actions {
    display: flex;
    gap: 4px;
    justify-content: center;
}

.vl-action-btn {
    width: 32px;
    height: 32px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    text-decoration: none;
    transition: all 0.15s;
    font-size: 13px;
    border: 1.5px solid transparent;
}
.vl-action-btn.view  { color: #3c4758; background: #eaecf0; border-color: #c4c9d4; }
.vl-action-btn.edit  { color: #d97706; background: #fef9ec; border-color: #fde9a2; }
.vl-action-btn.del   { color: #dc2626; background: #fef2f2; border-color: #fecaca; }
.vl-action-btn:hover { transform: translateY(-1px); box-shadow: 0 3px 8px rgba(0,0,0,0.1); text-decoration: none; }

/* ── Empty State ── */
.vl-empty {
    padding: 70px 20px;
    text-align: center;
    color: #9aa0b4;
}
.vl-empty-icon {
    font-size: 52px;
    opacity: 0.3;
    margin-bottom: 16px;
}
.vl-empty p {
    font-size: 15px;
    font-weight: 500;
    margin: 0 0 20px;
    color: #7c859c;
}

/* ── Pagination ── */
.vl-pagination {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 16px 20px;
    border-top: 1px solid #f0f2f8;
    flex-wrap: wrap;
    gap: 12px;
}
.vl-pagination-info {
    font-size: 12.5px;
    color: #9aa0b4;
}
.vl-page-btns {
    display: flex;
    gap: 4px;
}
.vl-page-btn {
    min-width: 34px;
    height: 34px;
    padding: 0 10px;
    border-radius: 8px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 13px;
    font-weight: 600;
    text-decoration: none;
    transition: all 0.15s;
    border: 1.5px solid #e2e5f0;
    color: #5a6482;
    background: #fff;
}
.vl-page-btn:hover { background: #f0f2fa; border-color: #c4c9d8; text-decoration: none; color: #2d3748; }
.vl-page-btn.active { background: #3c4758; color: #fff; border-color: transparent; }
.vl-page-btn.disabled { opacity: 0.35; pointer-events: none; }

/* ── Responsive ── */
@media (max-width: 900px) {
    .vl-filters { flex-direction: column; }
    .vl-filter-group { min-width: 100%; }
}
@media (max-width: 600px) {
    .vl-header { flex-direction: column; align-items: flex-start; }
}
</style>

<div class="vl-wrap">

<!-- Header -->
<div class="vl-header">
    <div class="vl-header-left">
        <h1><i class="fa fa-car" style="color:#3c4758;margin-right:10px;"></i><?php echo $langs->trans("Vehicles List"); ?></h1>
        <div class="vl-subtitle"><?php echo $nbtotalofrecords; ?> vehicle<?php echo $nbtotalofrecords != 1 ? 's' : ''; ?> found</div>
    </div>
    <div class="vl-header-actions">
        <?php if ($user->rights->flotte->read) { ?>
        <a class="vl-btn vl-btn-secondary" href="<?php echo dol_buildpath('/flotte/vehicle_list.php', 1); ?>?action=export">
            <i class="fa fa-download"></i> Export
        </a>
        <?php } ?>
        <?php if ($user->rights->flotte->write) { ?>
        <a class="vl-btn vl-btn-primary" href="<?php echo dol_buildpath('/flotte/vehicle_card.php', 1); ?>?action=create">
            <i class="fa fa-plus"></i> New Vehicle
        </a>
        <?php } ?>
    </div>
</div>

<!-- Filter Form -->
<form method="POST" action="<?php echo $self; ?>">
<input type="hidden" name="token" value="<?php echo newToken(); ?>">
<input type="hidden" name="formfilteraction" value="list">
<input type="hidden" name="action" value="list">
<input type="hidden" name="sortfield" value="<?php echo $sortfield; ?>">
<input type="hidden" name="sortorder" value="<?php echo $sortorder; ?>">
<input type="hidden" name="page" value="<?php echo $page; ?>">

<div class="vl-filters">
    <div class="vl-filter-group">
        <label>Reference</label>
        <input type="text" name="search_ref" placeholder="Search ref…" value="<?php echo dol_escape_htmltag($search_ref); ?>">
    </div>
    <div class="vl-filter-group">
        <label>Maker</label>
        <input type="text" name="search_maker" placeholder="e.g. Toyota…" value="<?php echo dol_escape_htmltag($search_maker); ?>">
    </div>
    <div class="vl-filter-group">
        <label>Model</label>
        <input type="text" name="search_model" placeholder="e.g. Corolla…" value="<?php echo dol_escape_htmltag($search_model); ?>">
    </div>
    <div class="vl-filter-group">
        <label>License Plate</label>
        <input type="text" name="search_license_plate" placeholder="Search plate…" value="<?php echo dol_escape_htmltag($search_license_plate); ?>">
    </div>
    <div class="vl-filter-group" style="max-width:160px;">
        <label>Status</label>
        <select name="search_status">
            <option value="">All statuses</option>
            <option value="1" <?php echo $search_status === '1' ? 'selected' : ''; ?>>In Service</option>
            <option value="0" <?php echo $search_status === '0' ? 'selected' : ''; ?>>Out of Service</option>
        </select>
    </div>
    <div class="vl-filter-actions">
        <button type="submit" class="vl-btn-filter apply"><i class="fa fa-search"></i> Search</button>
        <button type="submit" name="button_removefilter" value="1" class="vl-btn-filter reset"><i class="fa fa-times"></i> Reset</button>
    </div>
</div>

<!-- Stats chips -->
<?php
$total_in    = 0; $total_out = 0;
foreach ($rows as $r) { if ($r->in_service) $total_in++; else $total_out++; }
?>
<div class="vl-stats">
    <div class="vl-stat-chip">
        <span class="vl-stat-num"><?php echo $nbtotalofrecords; ?></span> Total
    </div>
    <div class="vl-stat-chip active">
        <span class="vl-stat-num"><?php echo $total_in; ?></span> In Service
    </div>
    <?php if ($total_out > 0) { ?>
    <div class="vl-stat-chip inactive">
        <span class="vl-stat-num"><?php echo $total_out; ?></span> Out of Service
    </div>
    <?php } ?>
</div>

<!-- Table -->
<div class="vl-table-card">
    <div class="vl-table-wrap">
    <table class="vl-table">
        <thead>
            <tr>
                <th><a href="<?php echo sortHref('t.ref', $sortfield, $sortorder, $self, $param); ?>">Ref <?php echo sortArrow('t.ref', $sortfield, $sortorder); ?></a></th>
                <th><a href="<?php echo sortHref('t.maker', $sortfield, $sortorder, $self, $param); ?>">Vehicle <?php echo sortArrow('t.maker', $sortfield, $sortorder); ?></a></th>
                <th><a href="<?php echo sortHref('t.type', $sortfield, $sortorder, $self, $param); ?>">Type <?php echo sortArrow('t.type', $sortfield, $sortorder); ?></a></th>
                <th><a href="<?php echo sortHref('t.year', $sortfield, $sortorder, $self, $param); ?>">Year <?php echo sortArrow('t.year', $sortfield, $sortorder); ?></a></th>
                <th><a href="<?php echo sortHref('t.license_plate', $sortfield, $sortorder, $self, $param); ?>">Plate <?php echo sortArrow('t.license_plate', $sortfield, $sortorder); ?></a></th>
                <th><a href="<?php echo sortHref('t.color', $sortfield, $sortorder, $self, $param); ?>">Color <?php echo sortArrow('t.color', $sortfield, $sortorder); ?></a></th>
                <th><a href="<?php echo sortHref('t.vin', $sortfield, $sortorder, $self, $param); ?>">VIN <?php echo sortArrow('t.vin', $sortfield, $sortorder); ?></a></th>
                <th class="right"><a href="<?php echo sortHref('t.initial_mileage', $sortfield, $sortorder, $self, $param); ?>">Mileage <?php echo sortArrow('t.initial_mileage', $sortfield, $sortorder); ?></a></th>
                <th class="center"><a href="<?php echo sortHref('t.in_service', $sortfield, $sortorder, $self, $param); ?>">Status <?php echo sortArrow('t.in_service', $sortfield, $sortorder); ?></a></th>
                <th class="center">Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php if (!empty($rows)) {
            foreach ($rows as $obj) {
                $cardUrl = dol_buildpath('/flotte/vehicle_card.php', 1).'?id='.$obj->rowid;
                // Color: try to map common color names to CSS
                $colorName = strtolower(trim($obj->color ?? ''));
                $cssSafeColors = ['white','black','red','blue','green','gray','grey','silver','gold','orange','yellow','brown','beige','purple','pink','navy','cyan','maroon','olive','teal'];
                $colorCss = in_array($colorName, $cssSafeColors) ? $colorName : '#9aa0b4';
        ?>
            <tr>
                <!-- Ref -->
                <td>
                    <a href="<?php echo $cardUrl; ?>" class="vl-ref-link">
                        <span class="vl-ref-icon"><i class="fa fa-car"></i></span>
                        <?php echo dol_escape_htmltag($obj->ref); ?>
                    </a>
                </td>

                <!-- Vehicle (Maker + Model) -->
                <td>
                    <div class="vl-vehicle-name"><?php echo dol_escape_htmltag($obj->maker); ?></div>
                    <div class="vl-vehicle-sub"><?php echo dol_escape_htmltag($obj->model); ?></div>
                </td>

                <!-- Type -->
                <td><?php echo dol_escape_htmltag($obj->type); ?></td>

                <!-- Year -->
                <td><?php echo dol_escape_htmltag($obj->year); ?></td>

                <!-- License Plate -->
                <td>
                    <?php if (!empty($obj->license_plate)) { ?>
                    <span class="vl-plate"><?php echo dol_escape_htmltag($obj->license_plate); ?></span>
                    <?php } ?>
                </td>

                <!-- Color -->
                <td>
                    <?php if (!empty($obj->color)) { ?>
                    <div class="vl-color-swatch">
                        <span class="vl-color-dot" style="background:<?php echo $colorCss; ?>;"></span>
                        <?php echo dol_escape_htmltag($obj->color); ?>
                    </div>
                    <?php } ?>
                </td>

                <!-- VIN -->
                <td><span class="vl-vin" title="<?php echo dol_escape_htmltag($obj->vin); ?>"><?php echo dol_escape_htmltag($obj->vin); ?></span></td>

                <!-- Mileage -->
                <td class="right">
                    <?php if (!empty($obj->initial_mileage)) { ?>
                    <span class="vl-mileage"><?php echo number_format((int)$obj->initial_mileage, 0, '.', ' '); ?><span class="vl-mileage-unit">km</span></span>
                    <?php } ?>
                </td>

                <!-- Status -->
                <td class="center">
                    <?php if ($obj->in_service == 1) { ?>
                    <span class="vl-badge in-service">In Service</span>
                    <?php } else { ?>
                    <span class="vl-badge out-of-service">Out of Service</span>
                    <?php } ?>
                </td>

                <!-- Actions -->
                <td>
                    <div class="vl-actions">
                        <a href="<?php echo $cardUrl; ?>" class="vl-action-btn view" title="View"><i class="fa fa-eye"></i></a>
                        <?php if ($user->rights->flotte->write) { ?>
                        <a href="<?php echo $cardUrl; ?>&action=edit" class="vl-action-btn edit" title="Edit"><i class="fa fa-pen"></i></a>
                        <?php } ?>
                        <?php if ($user->rights->flotte->delete) { ?>
                        <a href="<?php echo dol_buildpath('/flotte/vehicle_list.php', 1); ?>?action=delete&id=<?php echo $obj->rowid; ?>&token=<?php echo newToken(); ?>" class="vl-action-btn del" title="Delete"><i class="fa fa-trash"></i></a>
                        <?php } ?>
                    </div>
                </td>
            </tr>
        <?php }} else { ?>
            <tr>
                <td colspan="10">
                    <div class="vl-empty">
                        <div class="vl-empty-icon"><i class="fa fa-car"></i></div>
                        <p>No vehicles found</p>
                        <?php if ($user->rights->flotte->write) { ?>
                        <a class="vl-btn vl-btn-primary" href="<?php echo dol_buildpath('/flotte/vehicle_card.php', 1); ?>?action=create">
                            <i class="fa fa-plus"></i> Add First Vehicle
                        </a>
                        <?php } ?>
                    </div>
                </td>
            </tr>
        <?php } ?>
        </tbody>
    </table>
    </div>

    <!-- Pagination -->
    <?php if ($nbtotalofrecords > $limit) {
        $totalpages = ceil($nbtotalofrecords / $limit);
        $prevpage   = max(0, $page - 1);
        $nextpage   = min($totalpages - 1, $page + 1);
        $showing_from = $offset + 1;
        $showing_to   = min($offset + $limit, $nbtotalofrecords);
    ?>
    <div class="vl-pagination">
        <div class="vl-pagination-info">
            Showing <strong><?php echo $showing_from; ?></strong>–<strong><?php echo $showing_to; ?></strong> of <strong><?php echo $nbtotalofrecords; ?></strong> vehicles
        </div>
        <div class="vl-page-btns">
            <a class="vl-page-btn <?php echo $page == 0 ? 'disabled' : ''; ?>" href="<?php echo $self; ?>?page=0&sortfield=<?php echo $sortfield; ?>&sortorder=<?php echo $sortorder; ?>&<?php echo $param; ?>">«</a>
            <a class="vl-page-btn <?php echo $page == 0 ? 'disabled' : ''; ?>" href="<?php echo $self; ?>?page=<?php echo $prevpage; ?>&sortfield=<?php echo $sortfield; ?>&sortorder=<?php echo $sortorder; ?>&<?php echo $param; ?>">‹</a>
            <?php
            $start = max(0, $page - 2);
            $end   = min($totalpages - 1, $page + 2);
            for ($p = $start; $p <= $end; $p++) {
                $active = $p == $page ? 'active' : '';
                echo '<a class="vl-page-btn '.$active.'" href="'.$self.'?page='.$p.'&sortfield='.$sortfield.'&sortorder='.$sortorder.'&'.$param.'">'.($p + 1).'</a>';
            }
            ?>
            <a class="vl-page-btn <?php echo $page >= $totalpages - 1 ? 'disabled' : ''; ?>" href="<?php echo $self; ?>?page=<?php echo $nextpage; ?>&sortfield=<?php echo $sortfield; ?>&sortorder=<?php echo $sortorder; ?>&<?php echo $param; ?>">›</a>
            <a class="vl-page-btn <?php echo $page >= $totalpages - 1 ? 'disabled' : ''; ?>" href="<?php echo $self; ?>?page=<?php echo $totalpages - 1; ?>&sortfield=<?php echo $sortfield; ?>&sortorder=<?php echo $sortorder; ?>&<?php echo $param; ?>">»</a>
        </div>
    </div>
    <?php } ?>
</div>

</form>
</div><!-- .vl-wrap -->

<?php
if ($resql) { $db->free($resql); }
llxFooter();
$db->close();
?>
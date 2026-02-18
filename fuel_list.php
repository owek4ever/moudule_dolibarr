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
$search_vehicle = GETPOST('search_vehicle', 'alpha');
$search_date = GETPOST('search_date', 'alpha');
$search_reference = GETPOST('search_reference', 'alpha');
$search_state = GETPOST('search_state', 'alpha');
$search_fuel_source = GETPOST('search_fuel_source', 'alpha');

$limit = GETPOST('limit', 'int') ? GETPOST('limit', 'int') : $conf->liste_limit;
$sortfield = GETPOST('sortfield', 'aZ09comma');
$sortorder = GETPOST('sortorder', 'aZ09comma');
$page = GETPOSTISSET('pageplusone') ? (GETPOST('pageplusone') - 1) : GETPOST("page", 'int');

if (empty($page) || $page == -1) {
    $page = 0;
}

$offset = $limit * $page;

if (!$sortfield) {
    $sortfield = "t.date";
}
if (!$sortorder) {
    $sortorder = "DESC";
}

// Initialize technical objects
$form = new Form($db);
$formcompany = new FormCompany($db);
$hookmanager->initHooks(array('fuellist', 'globalcard'));

// Security check
restrictedArea($user, 'flotte');

/*
 * Actions
 */
if (GETPOST('cancel', 'alpha')) {
    $action = 'list';
    $massaction = '';
}

if (GETPOST('button_removefilter_x', 'alpha') || GETPOST('button_removefilter.x', 'alpha') || GETPOST('button_removefilter', 'alpha')) {
    $search_ref = '';
    $search_vehicle = '';
    $search_date = '';
    $search_reference = '';
    $search_state = '';
    $search_fuel_source = '';
}

// Handle confirmed delete from list page
if ($action == 'confirm_delete' && GETPOST('confirm', 'alpha') == 'yes') {
    $id_to_delete = GETPOST('id', 'int');
    if ($id_to_delete > 0) {
        $db->begin();
        $sql_del = "DELETE FROM ".MAIN_DB_PREFIX."flotte_fuel WHERE rowid = ".(int)$id_to_delete." AND entity IN (".getEntity('flotte').")";
        $resql_del = $db->query($sql_del);
        if ($resql_del) {
            // Delete associated files if any
            $uploadDir = $conf->flotte->dir_output . '/fuel/' . $id_to_delete . '/';
            if (is_dir($uploadDir)) {
                dol_delete_dir_recursive($uploadDir);
            }
            $db->commit();
            setEventMessages($langs->trans("FuelRecordDeletedSuccessfully"), null, 'mesgs');
        } else {
            $db->rollback();
            setEventMessages("Error in SQL: ".$db->lasterror(), null, 'errors');
        }
    }
    $action = 'list';
}

// Build and execute select
$sql = 'SELECT t.rowid, t.ref, t.fk_vehicle, t.date, t.start_meter, t.reference, t.state, t.note, t.complete_fillup, t.fuel_source, t.qty, t.cost_unit, (t.qty * t.cost_unit) as total_cost, v.maker, v.model, v.license_plate';
$sql .= ' FROM '.MAIN_DB_PREFIX.'flotte_fuel as t';
$sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'flotte_vehicle as v ON t.fk_vehicle = v.rowid';
$sql .= ' WHERE 1 = 1';
$sql .= ' AND t.entity IN ('.getEntity('flotte').')';

if ($search_ref) {
    $sql .= " AND t.ref LIKE '%".$db->escape($search_ref)."%'";
}
if ($search_vehicle) {
    $sql .= " AND (v.maker LIKE '%".$db->escape($search_vehicle)."%' OR v.model LIKE '%".$db->escape($search_vehicle)."%' OR v.license_plate LIKE '%".$db->escape($search_vehicle)."%')";
}
if ($search_date) {
    $sql .= " AND DATE(t.date) = '".$db->escape($search_date)."'";
}
if ($search_reference) {
    $sql .= " AND t.reference LIKE '%".$db->escape($search_reference)."%'";
}
if ($search_state) {
    $sql .= " AND t.state = '".$db->escape($search_state)."'";
}
if ($search_fuel_source) {
    $sql .= " AND t.fuel_source = '".$db->escape($search_fuel_source)."'";
}

$sql .= $db->order($sortfield, $sortorder);

// Count total nb of records - Updated to match driver_list.php
$sqlcount = preg_replace('/^SELECT[^,]+(,\s*[^,]+)*\s+FROM/', 'SELECT COUNT(*) as nb FROM', $sql);
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
if (!empty($search_vehicle))       $param .= '&search_vehicle='.urlencode($search_vehicle);
if (!empty($search_date))          $param .= '&search_date='.urlencode($search_date);
if (!empty($search_reference))     $param .= '&search_reference='.urlencode($search_reference);
if (!empty($search_state))         $param .= '&search_state='.urlencode($search_state);
if (!empty($search_fuel_source))   $param .= '&search_fuel_source='.urlencode($search_fuel_source);

// Page header
llxHeader('', $langs->trans("Fuel Records List"), '');

// Show delete confirmation dialog if requested
if ($action == 'delete') {
    $id_to_delete = GETPOST('id', 'int');
    if ($id_to_delete > 0) {
        $formconfirm = $form->formconfirm($_SERVER["PHP_SELF"].'?id='.$id_to_delete, $langs->trans('DeleteFuelRecord'), $langs->trans('ConfirmDeleteFuelRecord'), 'confirm_delete', '', 0, 1);
        print $formconfirm;
    }
}

// Collect rows
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
function fl_sortArrow($field, $sortfield, $sortorder) {
    if ($sortfield == $field) return $sortorder == 'ASC' ? ' <span class="vl-sort-arrow">↑</span>' : ' <span class="vl-sort-arrow">↓</span>';
    return ' <span class="vl-sort-arrow muted">↕</span>';
}
function fl_sortHref($field, $sortfield, $sortorder, $self, $param) {
    $dir = ($sortfield == $field && $sortorder == 'ASC') ? 'DESC' : 'ASC';
    return $self.'?sortfield='.$field.'&sortorder='.$dir.'&'.$param;
}

$self = $_SERVER["PHP_SELF"];

// Count by state
$cnt_pending = 0; $cnt_approved = 0; $cnt_completed = 0; $cnt_rejected = 0;
foreach ($rows as $r) {
    if ($r->state == 'pending') $cnt_pending++;
    elseif ($r->state == 'approved') $cnt_approved++;
    elseif ($r->state == 'completed') $cnt_completed++;
    elseif ($r->state == 'rejected') $cnt_rejected++;
}
?>

<style>
@import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=DM+Mono:wght@400;500&display=swap');

.vl-wrap * { box-sizing: border-box; }

.vl-wrap {
    font-family: 'DM Sans', sans-serif;
    max-width: 1480px;
    margin: 0 auto;
    padding: 0 4px 40px;
    color: #1a1f2e;
}

/* Header */
.vl-header {
    display: flex; align-items: center; justify-content: space-between;
    padding: 28px 0 24px; border-bottom: 1px solid #e8eaf0;
    margin-bottom: 24px; gap: 16px; flex-wrap: wrap;
}
.vl-header-left h1 {
    font-size: 22px; font-weight: 700; color: #1a1f2e;
    margin: 0 0 4px; letter-spacing: -0.3px;
}
.vl-header-left .vl-subtitle { font-size: 13px; color: #7c859c; font-weight: 400; }
.vl-header-actions { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }

/* Buttons */
.vl-btn {
    display: inline-flex; align-items: center; gap: 7px;
    padding: 8px 16px; border-radius: 6px; font-size: 13px; font-weight: 600;
    text-decoration: none !important; transition: all 0.15s ease;
    border: none; cursor: pointer; font-family: 'DM Sans', sans-serif; white-space: nowrap;
}
.vl-btn-primary   { background: #3c4758 !important; color: #fff !important; }
.vl-btn-primary:hover  { background: #2a3346 !important; color: #fff !important; }
.vl-btn-secondary { background: #3c4758 !important; color: #fff !important; border: none !important; }
.vl-btn-secondary:hover { background: #2a3346 !important; color: #fff !important; }

/* Filters */
.vl-filters {
    background: #fff; border: 1px solid #e8eaf0; border-radius: 12px;
    padding: 18px 20px; margin-bottom: 20px; display: flex;
    gap: 12px; align-items: flex-end; flex-wrap: wrap;
    box-shadow: 0 1px 4px rgba(0,0,0,0.04);
}
.vl-filter-group { display: flex; flex-direction: column; gap: 5px; flex: 1; min-width: 130px; }
.vl-filter-group label { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.6px; color: #9aa0b4; }
.vl-filter-group input,
.vl-filter-group select {
    padding: 8px 12px; border: 1.5px solid #e2e5f0; border-radius: 8px;
    font-size: 13px; font-family: 'DM Sans', sans-serif; color: #2d3748;
    background: #fafbfe; outline: none; transition: border-color 0.15s, box-shadow 0.15s; width: 100%;
}
.vl-filter-group input:focus,
.vl-filter-group select:focus {
    border-color: #3c4758; box-shadow: 0 0 0 3px rgba(60,71,88,0.1); background: #fff;
}
.vl-filter-actions { display: flex; gap: 8px; align-items: flex-end; padding-bottom: 1px; }
.vl-btn-filter {
    padding: 8px 16px; font-size: 13px; border-radius: 6px; font-weight: 600;
    border: none; cursor: pointer; font-family: 'DM Sans', sans-serif;
    transition: all 0.15s; white-space: nowrap;
}
.vl-btn-filter.apply { background: #3c4758 !important; color: #fff !important; }
.vl-btn-filter.apply:hover { background: #2a3346 !important; }
.vl-btn-filter.reset  { background: #3c4758 !important; color: #fff !important; }
.vl-btn-filter.reset:hover  { background: #2a3346 !important; }

/* Stats chips */
.vl-stats { display: flex; gap: 10px; margin-bottom: 16px; flex-wrap: wrap; }
.vl-stat-chip {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 6px 14px; border-radius: 20px; font-size: 12px;
    font-weight: 600; background: #f0f2fa; color: #5a6482;
}
.vl-stat-chip .vl-stat-num { font-size: 14px; font-weight: 700; color: #1a1f2e; }
.vl-stat-chip.approved { background: #f0fdf4; color: #166534; }
.vl-stat-chip.approved .vl-stat-num { color: #166534; }
.vl-stat-chip.rejected { background: #fef2f2; color: #991b1b; }
.vl-stat-chip.rejected .vl-stat-num { color: #991b1b; }
.vl-stat-chip.completed { background: #eff6ff; color: #1d4ed8; }
.vl-stat-chip.completed .vl-stat-num { color: #1d4ed8; }

/* Table */
.vl-table-card {
    background: #fff; border: 1px solid #e8eaf0; border-radius: 14px;
    overflow: hidden; box-shadow: 0 2px 12px rgba(0,0,0,0.05);
}
.vl-table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }

table.vl-table { width: 100%; border-collapse: collapse; font-size: 13.5px; }
table.vl-table thead tr { background: #f7f8fc; border-bottom: 2px solid #e8eaf0; }
table.vl-table thead th {
    padding: 13px 16px; text-align: left; font-size: 11px; font-weight: 700;
    text-transform: uppercase; letter-spacing: 0.7px; color: #8b92a9; white-space: nowrap;
}
table.vl-table thead th a { color: #8b92a9; text-decoration: none; display: inline-flex; align-items: center; gap: 4px; transition: color 0.15s; }
table.vl-table thead th a:hover { color: #3c4758; }
table.vl-table thead th.center { text-align: center; }

.vl-sort-arrow { font-size: 10px; opacity: 0.6; }
.vl-sort-arrow.muted { opacity: 0.25; }

table.vl-table tbody tr { border-bottom: 1px solid #f0f2f8; transition: background 0.12s; }
table.vl-table tbody tr:last-child { border-bottom: none; }
table.vl-table tbody tr:hover { background: #fafbff; }
table.vl-table tbody td { padding: 14px 16px; color: #2d3748; vertical-align: middle; }
table.vl-table tbody td.center { text-align: center; }

/* Ref link */
.vl-ref-link {
    display: inline-flex; align-items: center; gap: 8px;
    text-decoration: none; color: #3c4758; font-weight: 600;
    font-family: 'DM Mono', monospace; font-size: 13px; transition: color 0.15s;
}
.vl-ref-link:hover { color: #2a3346; text-decoration: none; }
.vl-ref-icon {
    width: 30px; height: 30px; background: rgba(60,71,88,0.08);
    border-radius: 8px; display: flex; align-items: center;
    justify-content: center; color: #3c4758; font-size: 14px; flex-shrink: 0;
}

/* Vehicle name */
.vl-vehicle-name { font-weight: 600; color: #1a1f2e; font-size: 13.5px; }
.vl-vehicle-sub  { font-size: 11.5px; color: #9aa0b4; margin-top: 2px; }

/* License plate chip */
.vl-plate-chip {
    display: inline-flex; align-items: center; gap: 6px;
    font-size: 12.5px; font-weight: 600; color: #1d4ed8;
    background: #eff6ff; padding: 4px 10px; border-radius: 6px;
}

/* Mono */
.vl-mono {
    font-family: 'DM Mono', monospace; font-size: 12px; color: #4a5568;
    background: #f0f2fa; padding: 3px 8px; border-radius: 5px; display: inline-block;
}

/* State badge */
.vl-badge {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 4px 10px; border-radius: 20px;
    font-size: 11.5px; font-weight: 600; white-space: nowrap;
}
.vl-badge::before { content: ''; width: 6px; height: 6px; border-radius: 50%; flex-shrink: 0; }
.vl-badge.pending   { background: #fffbeb; color: #92400e; }
.vl-badge.pending::before { background: #f59e0b; }
.vl-badge.approved  { background: #f0fdf4; color: #166534; }
.vl-badge.approved::before { background: #22c55e; }
.vl-badge.completed { background: #eff6ff; color: #1d4ed8; }
.vl-badge.completed::before { background: #3b82f6; }
.vl-badge.rejected  { background: #fef2f2; color: #991b1b; }
.vl-badge.rejected::before { background: #ef4444; }

/* Fuel source badge */
.vl-badge.station { background: #f5f3ff; color: #5b21b6; }
.vl-badge.station::before { background: #8b5cf6; }
.vl-badge.tank    { background: #ecfdf5; color: #065f46; }
.vl-badge.tank::before    { background: #10b981; }
.vl-badge.other   { background: #f0f2fa; color: #5a6482; }
.vl-badge.other::before   { background: #8b92a9; }

/* Action buttons */
.vl-actions { display: flex; gap: 4px; justify-content: center; }
.vl-action-btn {
    width: 32px; height: 32px; border-radius: 8px; display: flex;
    align-items: center; justify-content: center; text-decoration: none;
    transition: all 0.15s; font-size: 13px; border: 1.5px solid transparent;
}
.vl-action-btn.view { color: #3c4758; background: #eaecf0; border-color: #c4c9d4; }
.vl-action-btn.edit { color: #d97706; background: #fef9ec; border-color: #fde9a2; }
.vl-action-btn.del  { color: #dc2626; background: #fef2f2; border-color: #fecaca; }
.vl-action-btn:hover { transform: translateY(-1px); box-shadow: 0 3px 8px rgba(0,0,0,0.1); text-decoration: none; }

/* Empty state */
.vl-empty { padding: 70px 20px; text-align: center; color: #9aa0b4; }
.vl-empty-icon { font-size: 52px; opacity: 0.3; margin-bottom: 16px; }
.vl-empty p { font-size: 15px; font-weight: 500; margin: 0 0 20px; color: #7c859c; }

/* Pagination */
.vl-pagination {
    display: flex; align-items: center; justify-content: space-between;
    padding: 16px 20px; border-top: 1px solid #f0f2f8; flex-wrap: wrap; gap: 12px;
}
.vl-pagination-info { font-size: 12.5px; color: #9aa0b4; }
.vl-page-btns { display: flex; gap: 4px; }
.vl-page-btn {
    min-width: 34px; height: 34px; padding: 0 10px; border-radius: 8px;
    display: inline-flex; align-items: center; justify-content: center;
    font-size: 13px; font-weight: 600; text-decoration: none; transition: all 0.15s;
    border: 1.5px solid #e2e5f0; color: #5a6482; background: #fff;
}
.vl-page-btn:hover { background: #f0f2fa; border-color: #c4c9d8; text-decoration: none; color: #2d3748; }
.vl-page-btn.active { background: #3c4758; color: #fff; border-color: transparent; }
.vl-page-btn.disabled { opacity: 0.35; pointer-events: none; }

@media (max-width: 900px) { .vl-filters { flex-direction: column; } .vl-filter-group { min-width: 100%; } }
@media (max-width: 600px) { .vl-header { flex-direction: column; align-items: flex-start; } }
</style>

<div class="vl-wrap">

<!-- Header -->
<div class="vl-header">
    <div class="vl-header-left">
        <h1><i class="fa fa-gas-pump" style="color:#3c4758;margin-right:10px;"></i><?php echo $langs->trans("Fuel Records List"); ?></h1>
        <div class="vl-subtitle"><?php echo $nbtotalofrecords; ?> record<?php echo $nbtotalofrecords != 1 ? 's' : ''; ?> found</div>
    </div>
    <div class="vl-header-actions">
        <?php if ($user->rights->flotte->read) { ?>
        <a class="vl-btn vl-btn-secondary" href="<?php echo dol_buildpath('/flotte/fuel_list.php', 1); ?>?action=export">
            <i class="fa fa-download"></i> Export
        </a>
        <?php } ?>
        <?php if ($user->rights->flotte->write) { ?>
        <a class="vl-btn vl-btn-primary" href="<?php echo dol_buildpath('/flotte/fuel_card.php', 1); ?>?action=create">
            <i class="fa fa-plus"></i> New Fuel Record
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
        <label>Vehicle</label>
        <input type="text" name="search_vehicle" placeholder="Maker, model, plate…" value="<?php echo dol_escape_htmltag($search_vehicle); ?>">
    </div>
    <div class="vl-filter-group">
        <label>Date</label>
        <input type="date" name="search_date" value="<?php echo dol_escape_htmltag($search_date); ?>">
    </div>
    <div class="vl-filter-group">
        <label>Receipt Ref.</label>
        <input type="text" name="search_reference" placeholder="Receipt ref…" value="<?php echo dol_escape_htmltag($search_reference); ?>">
    </div>
    <div class="vl-filter-group" style="max-width:160px;">
        <label>Fuel Source</label>
        <select name="search_fuel_source">
            <option value="">All</option>
            <option value="Station" <?php echo $search_fuel_source === 'Station' ? 'selected' : ''; ?>>Station</option>
            <option value="Tank"    <?php echo $search_fuel_source === 'Tank'    ? 'selected' : ''; ?>>Tank</option>
            <option value="Other"   <?php echo $search_fuel_source === 'Other'   ? 'selected' : ''; ?>>Other</option>
        </select>
    </div>
    <div class="vl-filter-group" style="max-width:160px;">
        <label>State</label>
        <select name="search_state">
            <option value="">All</option>
            <option value="pending"   <?php echo $search_state === 'pending'   ? 'selected' : ''; ?>>Pending</option>
            <option value="approved"  <?php echo $search_state === 'approved'  ? 'selected' : ''; ?>>Approved</option>
            <option value="completed" <?php echo $search_state === 'completed' ? 'selected' : ''; ?>>Completed</option>
            <option value="rejected"  <?php echo $search_state === 'rejected'  ? 'selected' : ''; ?>>Rejected</option>
        </select>
    </div>
    <div class="vl-filter-actions">
        <button type="submit" class="vl-btn-filter apply"><i class="fa fa-search"></i> Search</button>
        <button type="submit" name="button_removefilter" value="1" class="vl-btn-filter reset"><i class="fa fa-times"></i> Reset</button>
    </div>
</div>

<!-- Stats chips -->
<div class="vl-stats">
    <div class="vl-stat-chip">
        <span class="vl-stat-num"><?php echo $nbtotalofrecords; ?></span> Total
    </div>
    <?php if ($cnt_pending > 0) { ?>
    <div class="vl-stat-chip" style="background:#fffbeb;color:#92400e;">
        <span class="vl-stat-num" style="color:#92400e;"><?php echo $cnt_pending; ?></span> Pending
    </div>
    <?php } ?>
    <?php if ($cnt_approved > 0) { ?>
    <div class="vl-stat-chip approved">
        <span class="vl-stat-num"><?php echo $cnt_approved; ?></span> Approved
    </div>
    <?php } ?>
    <?php if ($cnt_completed > 0) { ?>
    <div class="vl-stat-chip completed">
        <span class="vl-stat-num"><?php echo $cnt_completed; ?></span> Completed
    </div>
    <?php } ?>
    <?php if ($cnt_rejected > 0) { ?>
    <div class="vl-stat-chip rejected">
        <span class="vl-stat-num"><?php echo $cnt_rejected; ?></span> Rejected
    </div>
    <?php } ?>
</div>

<!-- Table -->
<div class="vl-table-card">
    <div class="vl-table-wrap">
    <table class="vl-table">
        <thead>
            <tr>
                <th><a href="<?php echo fl_sortHref('t.ref', $sortfield, $sortorder, $self, $param); ?>">Ref <?php echo fl_sortArrow('t.ref', $sortfield, $sortorder); ?></a></th>
                <th><a href="<?php echo fl_sortHref('v.license_plate', $sortfield, $sortorder, $self, $param); ?>">Vehicle <?php echo fl_sortArrow('v.license_plate', $sortfield, $sortorder); ?></a></th>
                <th class="center"><a href="<?php echo fl_sortHref('t.date', $sortfield, $sortorder, $self, $param); ?>">Date <?php echo fl_sortArrow('t.date', $sortfield, $sortorder); ?></a></th>
                <th class="center"><a href="<?php echo fl_sortHref('t.start_meter', $sortfield, $sortorder, $self, $param); ?>">Meter Reading <?php echo fl_sortArrow('t.start_meter', $sortfield, $sortorder); ?></a></th>
                <th><a href="<?php echo fl_sortHref('t.reference', $sortfield, $sortorder, $self, $param); ?>">Receipt Ref. <?php echo fl_sortArrow('t.reference', $sortfield, $sortorder); ?></a></th>
                <th class="center"><a href="<?php echo fl_sortHref('t.qty', $sortfield, $sortorder, $self, $param); ?>">Quantity <?php echo fl_sortArrow('t.qty', $sortfield, $sortorder); ?></a></th>
                <th class="center"><a href="<?php echo fl_sortHref('t.cost_unit', $sortfield, $sortorder, $self, $param); ?>">Cost/Unit <?php echo fl_sortArrow('t.cost_unit', $sortfield, $sortorder); ?></a></th>
                <th class="center"><a href="<?php echo fl_sortHref('total_cost', $sortfield, $sortorder, $self, $param); ?>">Total Cost <?php echo fl_sortArrow('total_cost', $sortfield, $sortorder); ?></a></th>
                <th class="center"><a href="<?php echo fl_sortHref('t.fuel_source', $sortfield, $sortorder, $self, $param); ?>">Fuel Source <?php echo fl_sortArrow('t.fuel_source', $sortfield, $sortorder); ?></a></th>
                <th class="center"><a href="<?php echo fl_sortHref('t.state', $sortfield, $sortorder, $self, $param); ?>">State <?php echo fl_sortArrow('t.state', $sortfield, $sortorder); ?></a></th>
                <th class="center">Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php if (!empty($rows)) {
            foreach ($rows as $obj) {
                $cardUrl = dol_buildpath('/flotte/fuel_card.php', 1).'?id='.$obj->rowid;
                $vehicle_parts = array();
                if ($obj->maker) $vehicle_parts[] = $obj->maker;
                if ($obj->model) $vehicle_parts[] = $obj->model;
                $vehicleName = implode(' ', $vehicle_parts);
        ?>
            <tr>
                <!-- Ref -->
                <td>
                    <a href="<?php echo $cardUrl; ?>" class="vl-ref-link">
                        <span class="vl-ref-icon"><i class="fa fa-gas-pump"></i></span>
                        <?php echo dol_escape_htmltag($obj->ref); ?>
                    </a>
                </td>

                <!-- Vehicle -->
                <td>
                    <div class="vl-vehicle-name"><?php echo dol_escape_htmltag($vehicleName ?: '—'); ?></div>
                    <?php if (!empty($obj->license_plate)) { ?>
                    <div class="vl-vehicle-sub"><?php echo dol_escape_htmltag($obj->license_plate); ?></div>
                    <?php } ?>
                </td>

                <!-- Date -->
                <td class="center"><?php echo dol_print_date($db->jdate($obj->date), 'day') ?: '—'; ?></td>

                <!-- Meter Reading -->
                <td class="center">
                    <?php if (!empty($obj->start_meter)) { ?>
                    <span class="vl-mono"><?php echo number_format($obj->start_meter); ?> km</span>
                    <?php } else { echo '<span style="color:#c4c9d8;">—</span>'; } ?>
                </td>

                <!-- Receipt Ref -->
                <td>
                    <?php if (!empty($obj->reference)) { ?>
                    <span class="vl-mono"><?php echo dol_escape_htmltag($obj->reference); ?></span>
                    <?php } else { echo '<span style="color:#c4c9d8;">—</span>'; } ?>
                </td>

                <!-- Quantity -->
                <td class="center">
                    <?php if (!empty($obj->qty)) { ?>
                    <span style="font-weight:600;"><?php echo number_format($obj->qty, 2); ?></span><span style="font-size:11px;color:#9aa0b4;margin-left:3px;">L</span>
                    <?php } else { echo '<span style="color:#c4c9d8;">—</span>'; } ?>
                </td>

                <!-- Cost/Unit -->
                <td class="center"><?php echo !empty($obj->cost_unit) ? price($obj->cost_unit) : '<span style="color:#c4c9d8;">—</span>'; ?></td>

                <!-- Total Cost -->
                <td class="center">
                    <?php if (!empty($obj->total_cost)) { ?>
                    <span style="font-weight:700;"><?php echo price($obj->total_cost); ?></span>
                    <?php } else { echo '<span style="color:#c4c9d8;">—</span>'; } ?>
                </td>

                <!-- Fuel Source -->
                <td class="center">
                    <?php
                    $fs = $obj->fuel_source;
                    if ($fs == 'Station')     echo '<span class="vl-badge station">Station</span>';
                    elseif ($fs == 'Tank')    echo '<span class="vl-badge tank">Tank</span>';
                    elseif ($fs == 'Other')   echo '<span class="vl-badge other">Other</span>';
                    else                      echo '<span style="color:#c4c9d8;font-size:13px;">—</span>';
                    ?>
                </td>

                <!-- State -->
                <td class="center">
                    <?php
                    $st = $obj->state;
                    if ($st == 'pending')        echo '<span class="vl-badge pending">Pending</span>';
                    elseif ($st == 'approved')   echo '<span class="vl-badge approved">Approved</span>';
                    elseif ($st == 'completed')  echo '<span class="vl-badge completed">Completed</span>';
                    elseif ($st == 'rejected')   echo '<span class="vl-badge rejected">Rejected</span>';
                    else                         echo '<span style="color:#c4c9d8;font-size:13px;">—</span>';
                    ?>
                </td>

                <!-- Actions -->
                <td>
                    <div class="vl-actions">
                        <a href="<?php echo $cardUrl; ?>" class="vl-action-btn view" title="View"><i class="fa fa-eye"></i></a>
                        <?php if ($user->rights->flotte->write) { ?>
                        <a href="<?php echo $cardUrl; ?>&action=edit" class="vl-action-btn edit" title="Edit"><i class="fa fa-pen"></i></a>
                        <?php } ?>
                        <?php if ($user->rights->flotte->delete) { ?>
                        <a href="<?php echo dol_buildpath('/flotte/fuel_list.php', 1); ?>?action=delete&id=<?php echo $obj->rowid; ?>&token=<?php echo newToken(); ?>" class="vl-action-btn del" title="Delete"><i class="fa fa-trash"></i></a>
                        <?php } ?>
                    </div>
                </td>
            </tr>
        <?php }} else { ?>
            <tr>
                <td colspan="11">
                    <div class="vl-empty">
                        <div class="vl-empty-icon"><i class="fa fa-gas-pump"></i></div>
                        <p>No fuel records found</p>
                        <?php if ($user->rights->flotte->write) { ?>
                        <a class="vl-btn vl-btn-primary" href="<?php echo dol_buildpath('/flotte/fuel_card.php', 1); ?>?action=create">
                            <i class="fa fa-plus"></i> Add First Record
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
        $totalpages   = ceil($nbtotalofrecords / $limit);
        $prevpage     = max(0, $page - 1);
        $nextpage     = min($totalpages - 1, $page + 1);
        $showing_from = $offset + 1;
        $showing_to   = min($offset + $limit, $nbtotalofrecords);
    ?>
    <div class="vl-pagination">
        <div class="vl-pagination-info">
            Showing <strong><?php echo $showing_from; ?></strong>–<strong><?php echo $showing_to; ?></strong> of <strong><?php echo $nbtotalofrecords; ?></strong> records
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
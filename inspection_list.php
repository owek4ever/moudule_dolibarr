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
$search_registration = GETPOST('search_registration', 'alpha');
$search_date_out = GETPOST('search_date_out', 'alpha');
$search_date_in = GETPOST('search_date_in', 'alpha');

$limit = GETPOST('limit', 'int') ? GETPOST('limit', 'int') : $conf->liste_limit;
$sortfield = GETPOST('sortfield', 'aZ09comma');
$sortorder = GETPOST('sortorder', 'aZ09comma');
$page = GETPOSTISSET('pageplusone') ? (GETPOST('pageplusone') - 1) : GETPOST("page", 'int');

if (empty($page) || $page == -1) {
    $page = 0;
}

$offset = $limit * $page;

if (!$sortfield) {
    $sortfield = "i.tms";
}
if (!$sortorder) {
    $sortorder = "DESC";
}

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
    $search_registration = '';
    $search_date_out = '';
    $search_date_in = '';
}

// Delete action
if ($action == 'confirm_delete' && $confirm == 'yes' && $user->rights->flotte->write) {
    $id = GETPOST('id', 'int');
    if ($id > 0) {
        $sql = "DELETE FROM ".MAIN_DB_PREFIX."flotte_inspection WHERE rowid = ".(int)$id;
        $result = $db->query($sql);
        if ($result) {
            setEventMessages($langs->trans("RecordDeleted"), null, 'mesgs');
            header("Location: ".$_SERVER['PHP_SELF']);
            exit;
        } else {
            setEventMessages($langs->trans("Error"), null, 'errors');
        }
    }
}

// Build and execute select
$sql = 'SELECT i.rowid, i.ref, i.registration_number, i.meter_out, i.meter_in, i.fuel_out, i.fuel_in,';
$sql .= ' i.datetime_out, i.datetime_in, i.petrol_card, i.lights_indicators, i.inverter_cigarette,';
$sql .= ' i.mats_seats, i.interior_damage, i.interior_lights, i.exterior_damage, i.tyres_condition,';
$sql .= ' i.ladders, i.extension_leeds, i.power_tools, i.ac_working, i.headlights_working,';
$sql .= ' i.locks_alarms, i.windows_condition, i.seats_condition, i.oil_check, i.suspension,';
$sql .= ' i.toolboxes_condition, i.tms,';
$sql .= ' v.ref as vehicle_ref, v.maker, v.model, v.license_plate';
$sql .= ' FROM '.MAIN_DB_PREFIX.'flotte_inspection as i';
$sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'flotte_vehicle as v ON i.fk_vehicle = v.rowid';
$sql .= ' WHERE 1 = 1';
$sql .= ' AND i.entity IN ('.getEntity('flotte').')';

// Add search filters
if ($search_ref) {
    $sql .= " AND i.ref LIKE '%".$db->escape($search_ref)."%'";
}
if ($search_vehicle) {
    $sql .= " AND (v.ref LIKE '%".$db->escape($search_vehicle)."%' OR v.maker LIKE '%".$db->escape($search_vehicle)."%' OR v.model LIKE '%".$db->escape($search_vehicle)."%')";
}
if ($search_registration) {
    $sql .= " AND i.registration_number LIKE '%".$db->escape($search_registration)."%'";
}
if ($search_date_out) {
    $sql .= " AND DATE(i.datetime_out) = '".$db->escape($search_date_out)."'";
}
if ($search_date_in) {
    $sql .= " AND DATE(i.datetime_in) = '".$db->escape($search_date_in)."'";
}

$sql .= $db->order($sortfield, $sortorder);

// Count total nb of records
$sqlcount = preg_replace('/SELECT.*FROM/', 'SELECT COUNT(*) as nb FROM', $sql);
$resql = $db->query($sqlcount);
$nbtotalofrecords = 0;
if ($resql) {
    $obj = $db->fetch_object($resql);
    $nbtotalofrecords = $obj->nb;
}

$sql .= $db->plimit($limit + 1, $offset);
$resql = $db->query($sql);

$form = new Form($db);
$num = 0;
if ($resql) {
    $num = $db->num_rows($resql);
}

// Page header
llxHeader('', $langs->trans("Inspections List"), '');

// Build param string for URL
$param = '';
if (!empty($search_ref))           $param .= '&search_ref='.urlencode($search_ref);
if (!empty($search_vehicle))       $param .= '&search_vehicle='.urlencode($search_vehicle);
if (!empty($search_registration))  $param .= '&search_registration='.urlencode($search_registration);
if (!empty($search_date_out))      $param .= '&search_date_out='.urlencode($search_date_out);
if (!empty($search_date_in))       $param .= '&search_date_in='.urlencode($search_date_in);

// Confirmation to delete
if ($action == 'delete') {
    $id = GETPOST('id', 'int');
    $formconfirm = $form->formconfirm($_SERVER["PHP_SELF"].'?id='.$id.$param, $langs->trans('DeleteInspection'), $langs->trans('ConfirmDeleteInspection'), 'confirm_delete', '', 0, 1);
    print $formconfirm;
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
function il_sortArrow($field, $sortfield, $sortorder) {
    if ($sortfield == $field) return $sortorder == 'ASC' ? ' <span class="vl-sort-arrow">↑</span>' : ' <span class="vl-sort-arrow">↓</span>';
    return ' <span class="vl-sort-arrow muted">↕</span>';
}
function il_sortHref($field, $sortfield, $sortorder, $self, $param) {
    $dir = ($sortfield == $field && $sortorder == 'ASC') ? 'DESC' : 'ASC';
    return $self.'?sortfield='.$field.'&sortorder='.$dir.'&'.$param;
}

$self = $_SERVER["PHP_SELF"];

// Helper: compute condition score for a row
function il_conditionScore($obj) {
    $checks = array(
        'petrol_card'        => $obj->petrol_card,
        'lights_indicators'  => $obj->lights_indicators,
        'inverter_cigarette' => $obj->inverter_cigarette,
        'mats_seats'         => $obj->mats_seats,
        'interior_damage'    => !$obj->interior_damage,
        'interior_lights'    => $obj->interior_lights,
        'exterior_damage'    => !$obj->exterior_damage,
        'tyres_condition'    => $obj->tyres_condition,
        'ladders'            => $obj->ladders,
        'extension_leeds'    => $obj->extension_leeds,
        'power_tools'        => $obj->power_tools,
        'ac_working'         => $obj->ac_working,
        'headlights_working' => $obj->headlights_working,
        'locks_alarms'       => $obj->locks_alarms,
        'windows_condition'  => $obj->windows_condition,
        'seats_condition'    => $obj->seats_condition,
        'oil_check'          => $obj->oil_check,
        'suspension'         => $obj->suspension,
        'toolboxes_condition'=> $obj->toolboxes_condition,
    );
    $score = 0; $total = 0;
    foreach ($checks as $value) {
        if ($value !== null && $value !== '') { $total++; if ($value) $score++; }
    }
    return $total > 0 ? round(($score / $total) * 100) : 0;
}

// Count open (out only) vs completed (both in and out)
$cnt_open = 0; $cnt_completed = 0;
foreach ($rows as $r) {
    if (!empty($r->datetime_out) && empty($r->datetime_in)) $cnt_open++;
    elseif (!empty($r->datetime_out) && !empty($r->datetime_in)) $cnt_completed++;
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
table.vl-table thead th.right  { text-align: right; }

.vl-sort-arrow { font-size: 10px; opacity: 0.6; }
.vl-sort-arrow.muted { opacity: 0.25; }

table.vl-table tbody tr { border-bottom: 1px solid #f0f2f8; transition: background 0.12s; }
table.vl-table tbody tr:last-child { border-bottom: none; }
table.vl-table tbody tr:hover { background: #fafbff; }
table.vl-table tbody td { padding: 14px 16px; color: #2d3748; vertical-align: middle; }
table.vl-table tbody td.center { text-align: center; }
table.vl-table tbody td.right  { text-align: right; }

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

/* Mono */
.vl-mono {
    font-family: 'DM Mono', monospace; font-size: 12px; color: #4a5568;
    background: #f0f2fa; padding: 3px 8px; border-radius: 5px; display: inline-block;
}

/* Datetime */
.vl-datetime { font-size: 12.5px; color: #2d3748; font-weight: 500; }
.vl-datetime-sub { font-size: 11px; color: #9aa0b4; margin-top: 2px; }

/* Condition score bar */
.vl-score-wrap { display: flex; align-items: center; gap: 8px; justify-content: center; }
.vl-score-bar {
    width: 60px; height: 6px; background: #e8eaf0; border-radius: 3px; overflow: hidden; flex-shrink: 0;
}
.vl-score-fill { height: 100%; border-radius: 3px; }
.vl-score-label { font-size: 12px; font-weight: 700; min-width: 32px; }

/* Status badge */
.vl-badge {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 4px 10px; border-radius: 20px;
    font-size: 11.5px; font-weight: 600; white-space: nowrap;
}
.vl-badge::before { content: ''; width: 6px; height: 6px; border-radius: 50%; flex-shrink: 0; }
.vl-badge.open      { background: #fffbeb; color: #92400e; }
.vl-badge.open::before { background: #f59e0b; }
.vl-badge.completed { background: #f0fdf4; color: #166534; }
.vl-badge.completed::before { background: #22c55e; }

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
        <h1><i class="fa fa-clipboard-list" style="color:#3c4758;margin-right:10px;"></i><?php echo $langs->trans("Inspections List"); ?></h1>
        <div class="vl-subtitle"><?php echo $nbtotalofrecords; ?> inspection<?php echo $nbtotalofrecords != 1 ? 's' : ''; ?> found</div>
    </div>
    <div class="vl-header-actions">
        <?php if ($user->rights->flotte->read) { ?>
        <a class="vl-btn vl-btn-secondary" href="<?php echo dol_buildpath('/flotte/inspection_list.php', 1); ?>?action=export">
            <i class="fa fa-download"></i> Export
        </a>
        <?php } ?>
        <?php if ($user->rights->flotte->write) { ?>
        <a class="vl-btn vl-btn-primary" href="<?php echo dol_buildpath('/flotte/inspection_card.php', 1); ?>?action=create">
            <i class="fa fa-plus"></i> New Inspection
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
        <input type="text" name="search_vehicle" placeholder="Ref, maker, model…" value="<?php echo dol_escape_htmltag($search_vehicle); ?>">
    </div>
    <div class="vl-filter-group">
        <label>Registration</label>
        <input type="text" name="search_registration" placeholder="Reg. number…" value="<?php echo dol_escape_htmltag($search_registration); ?>">
    </div>
    <div class="vl-filter-group">
        <label>Date Out</label>
        <input type="date" name="search_date_out" value="<?php echo dol_escape_htmltag($search_date_out); ?>">
    </div>
    <div class="vl-filter-group">
        <label>Date In</label>
        <input type="date" name="search_date_in" value="<?php echo dol_escape_htmltag($search_date_in); ?>">
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
    <?php if ($cnt_open > 0) { ?>
    <div class="vl-stat-chip" style="background:#fffbeb;color:#92400e;">
        <span class="vl-stat-num" style="color:#92400e;"><?php echo $cnt_open; ?></span> In Progress
    </div>
    <?php } ?>
    <?php if ($cnt_completed > 0) { ?>
    <div class="vl-stat-chip" style="background:#f0fdf4;color:#166534;">
        <span class="vl-stat-num" style="color:#166534;"><?php echo $cnt_completed; ?></span> Completed
    </div>
    <?php } ?>
</div>

<!-- Table -->
<div class="vl-table-card">
    <div class="vl-table-wrap">
    <table class="vl-table">
        <thead>
            <tr>
                <th><a href="<?php echo il_sortHref('i.ref', $sortfield, $sortorder, $self, $param); ?>">Ref <?php echo il_sortArrow('i.ref', $sortfield, $sortorder); ?></a></th>
                <th><a href="<?php echo il_sortHref('v.ref', $sortfield, $sortorder, $self, $param); ?>">Vehicle <?php echo il_sortArrow('v.ref', $sortfield, $sortorder); ?></a></th>
                <th><a href="<?php echo il_sortHref('i.registration_number', $sortfield, $sortorder, $self, $param); ?>">Registration <?php echo il_sortArrow('i.registration_number', $sortfield, $sortorder); ?></a></th>
                <th class="center"><a href="<?php echo il_sortHref('i.datetime_out', $sortfield, $sortorder, $self, $param); ?>">Date Out <?php echo il_sortArrow('i.datetime_out', $sortfield, $sortorder); ?></a></th>
                <th class="center"><a href="<?php echo il_sortHref('i.datetime_in', $sortfield, $sortorder, $self, $param); ?>">Date In <?php echo il_sortArrow('i.datetime_in', $sortfield, $sortorder); ?></a></th>
                <th class="center">Condition</th>
                <th class="center">Status</th>
                <th class="center">Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php if (!empty($rows)) {
            foreach ($rows as $obj) {
                $cardUrl = dol_buildpath('/flotte/inspection_card.php', 1).'?id='.$obj->rowid;

                // Condition score
                $condition_percentage = il_conditionScore($obj);
                if ($condition_percentage >= 80)     { $score_color = '#22c55e'; $score_text_color = '#166534'; }
                elseif ($condition_percentage >= 50) { $score_color = '#f59e0b'; $score_text_color = '#92400e'; }
                else                                  { $score_color = '#ef4444'; $score_text_color = '#991b1b'; }

                // Distance
                $distance = '';
                if ($obj->meter_out && $obj->meter_in && $obj->meter_in > $obj->meter_out) {
                    $distance = number_format($obj->meter_in - $obj->meter_out).' km';
                }

                // Status
                $is_completed = !empty($obj->datetime_out) && !empty($obj->datetime_in);
                $is_open      = !empty($obj->datetime_out) && empty($obj->datetime_in);
        ?>
            <tr>
                <!-- Ref -->
                <td>
                    <a href="<?php echo $cardUrl; ?>" class="vl-ref-link">
                        <span class="vl-ref-icon"><i class="fa fa-clipboard-list"></i></span>
                        <?php echo dol_escape_htmltag($obj->ref); ?>
                    </a>
                </td>

                <!-- Vehicle -->
                <td>
                    <?php if (!empty($obj->vehicle_ref)) { ?>
                    <div class="vl-vehicle-name"><?php echo dol_escape_htmltag(trim(($obj->maker ?? '').' '.($obj->model ?? ''))); ?></div>
                    <div class="vl-vehicle-sub"><?php echo dol_escape_htmltag($obj->vehicle_ref); ?><?php if (!empty($obj->license_plate)) echo ' · '.dol_escape_htmltag($obj->license_plate); ?></div>
                    <?php } else { echo '<span style="color:#c4c9d8;">—</span>'; } ?>
                </td>

                <!-- Registration -->
                <td>
                    <?php if (!empty($obj->registration_number)) { ?>
                    <span class="vl-mono"><?php echo dol_escape_htmltag($obj->registration_number); ?></span>
                    <?php } else { echo '<span style="color:#c4c9d8;">—</span>'; } ?>
                </td>

                <!-- Date Out -->
                <td class="center">
                    <?php if ($obj->datetime_out) { ?>
                    <div class="vl-datetime"><?php echo dol_print_date($obj->datetime_out, 'dayhour'); ?></div>
                    <?php if ($obj->meter_out) { ?><div class="vl-datetime-sub"><?php echo number_format($obj->meter_out); ?> km</div><?php } ?>
                    <?php } else { echo '<span style="color:#c4c9d8;">—</span>'; } ?>
                </td>

                <!-- Date In -->
                <td class="center">
                    <?php if ($obj->datetime_in) { ?>
                    <div class="vl-datetime"><?php echo dol_print_date($obj->datetime_in, 'dayhour'); ?></div>
                    <?php if ($distance) { ?><div class="vl-datetime-sub"><?php echo $distance; ?></div><?php } ?>
                    <?php } else { echo '<span style="color:#c4c9d8;">—</span>'; } ?>
                </td>

                <!-- Condition score -->
                <td class="center">
                    <div class="vl-score-wrap">
                        <div class="vl-score-bar">
                            <div class="vl-score-fill" style="width:<?php echo $condition_percentage; ?>%;background:<?php echo $score_color; ?>;"></div>
                        </div>
                        <span class="vl-score-label" style="color:<?php echo $score_text_color; ?>;"><?php echo $condition_percentage; ?>%</span>
                    </div>
                </td>

                <!-- Status -->
                <td class="center">
                    <?php if ($is_completed)  echo '<span class="vl-badge completed">Completed</span>';
                    elseif ($is_open)         echo '<span class="vl-badge open">In Progress</span>';
                    else                      echo '<span style="color:#c4c9d8;font-size:13px;">—</span>';
                    ?>
                </td>

                <!-- Actions -->
                <td>
                    <div class="vl-actions">
                        <a href="<?php echo $cardUrl; ?>" class="vl-action-btn view" title="View"><i class="fa fa-eye"></i></a>
                        <?php if ($user->rights->flotte->write) { ?>
                        <a href="<?php echo $cardUrl; ?>&action=edit" class="vl-action-btn edit" title="Edit"><i class="fa fa-pen"></i></a>
                        <?php } ?>
                        <?php if ($user->rights->flotte->write) { ?>
                        <a href="<?php echo $self; ?>?id=<?php echo $obj->rowid; ?>&action=delete&token=<?php echo newToken(); ?>" class="vl-action-btn del" title="Delete"><i class="fa fa-trash"></i></a>
                        <?php } ?>
                    </div>
                </td>
            </tr>
        <?php }} else { ?>
            <tr>
                <td colspan="8">
                    <div class="vl-empty">
                        <div class="vl-empty-icon"><i class="fa fa-clipboard-list"></i></div>
                        <p>No inspections found</p>
                        <?php if ($user->rights->flotte->write) { ?>
                        <a class="vl-btn vl-btn-primary" href="<?php echo dol_buildpath('/flotte/inspection_card.php', 1); ?>?action=create">
                            <i class="fa fa-plus"></i> Add First Inspection
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
            Showing <strong><?php echo $showing_from; ?></strong>–<strong><?php echo $showing_to; ?></strong> of <strong><?php echo $nbtotalofrecords; ?></strong> inspections
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
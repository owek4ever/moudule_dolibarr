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
$search_firstname = GETPOST('search_firstname', 'alpha');
$search_lastname = GETPOST('search_lastname', 'alpha');
$search_phone = GETPOST('search_phone', 'alpha');
$search_status = GETPOST('search_status', 'alpha');
$search_employee_id = GETPOST('search_employee_id', 'alpha');
$search_license_number = GETPOST('search_license_number', 'alpha');

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

// Initialize technical objects
$form = new Form($db);
$formcompany = new FormCompany($db);
$hookmanager->initHooks(array('driverlist', 'globalcard'));

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
    $search_firstname = '';
    $search_lastname = '';
    $search_phone = '';
    $search_status = '';
    $search_employee_id = '';
    $search_license_number = '';
}

// Handle confirmed delete from list page
if ($action == 'confirm_delete' && GETPOST('confirm', 'alpha') == 'yes') {
    $id_to_delete = GETPOST('id', 'int');
    if ($id_to_delete > 0) {
        $db->begin();
        $sql_del = "DELETE FROM ".MAIN_DB_PREFIX."flotte_driver WHERE rowid = ".(int)$id_to_delete." AND entity IN (".getEntity('flotte').")";
        $resql_del = $db->query($sql_del);
        if ($resql_del) {
            // Delete associated files
            $uploadDir = $conf->flotte->dir_output . '/drivers/' . $id_to_delete . '/';
            if (is_dir($uploadDir)) {
                dol_delete_dir_recursive($uploadDir);
            }
            $db->commit();
            setEventMessages($langs->trans("DriverDeletedSuccessfully"), null, 'mesgs');
        } else {
            $db->rollback();
            setEventMessages("Error in SQL: ".$db->lasterror(), null, 'errors');
        }
    }
    $action = 'list';
}

// Build and execute select
$sql = 'SELECT t.rowid, t.ref, t.firstname, t.middlename, t.lastname, t.phone, t.email, t.status, t.license_number, t.employee_id, t.department, t.gender, t.join_date, t.fk_vehicle';
$sql .= ', v.ref as vehicle_ref, v.maker as vehicle_maker, v.model as vehicle_model';
$sql .= ' FROM '.MAIN_DB_PREFIX.'flotte_driver as t';
$sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'flotte_vehicle as v ON t.fk_vehicle = v.rowid';
$sql .= ' WHERE 1 = 1';
$sql .= ' AND t.entity IN ('.getEntity('flotte').')';

if ($search_ref) {
    $sql .= " AND t.ref LIKE '%".$db->escape($search_ref)."%'";
}
if ($search_firstname) {
    $sql .= " AND t.firstname LIKE '%".$db->escape($search_firstname)."%'";
}
if ($search_lastname) {
    $sql .= " AND t.lastname LIKE '%".$db->escape($search_lastname)."%'";
}
if ($search_phone) {
    $sql .= " AND t.phone LIKE '%".$db->escape($search_phone)."%'";
}
if ($search_status) {
    $sql .= " AND t.status = '".$db->escape($search_status)."'";
}
if ($search_employee_id) {
    $sql .= " AND t.employee_id LIKE '%".$db->escape($search_employee_id)."%'";
}
if ($search_license_number) {
    $sql .= " AND t.license_number LIKE '%".$db->escape($search_license_number)."%'";
}

$sql .= $db->order($sortfield, $sortorder);

// Count total nb of records
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
if (!empty($search_ref))            $param .= '&search_ref='.urlencode($search_ref);
if (!empty($search_firstname))      $param .= '&search_firstname='.urlencode($search_firstname);
if (!empty($search_lastname))       $param .= '&search_lastname='.urlencode($search_lastname);
if (!empty($search_phone))          $param .= '&search_phone='.urlencode($search_phone);
if (!empty($search_status))         $param .= '&search_status='.urlencode($search_status);
if (!empty($search_employee_id))    $param .= '&search_employee_id='.urlencode($search_employee_id);
if (!empty($search_license_number)) $param .= '&search_license_number='.urlencode($search_license_number);

// Page header
llxHeader('', $langs->trans("Drivers List"), '');

// Show delete confirmation dialog if requested
if ($action == 'delete') {
    $id_to_delete = GETPOST('id', 'int');
    if ($id_to_delete > 0) {
        $formconfirm = $form->formconfirm($_SERVER["PHP_SELF"].'?id='.$id_to_delete, $langs->trans('DeleteDriver'), $langs->trans('ConfirmDeleteDriver'), 'confirm_delete', '', 0, 1);
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
function dl_sortArrow($field, $sortfield, $sortorder) {
    if ($sortfield == $field) return $sortorder == 'ASC' ? ' <span class="vl-sort-arrow">↑</span>' : ' <span class="vl-sort-arrow">↓</span>';
    return ' <span class="vl-sort-arrow muted">↕</span>';
}
function dl_sortHref($field, $sortfield, $sortorder, $self, $param) {
    $dir = ($sortfield == $field && $sortorder == 'ASC') ? 'DESC' : 'ASC';
    return $self.'?sortfield='.$field.'&sortorder='.$dir.'&'.$param;
}

$self = $_SERVER["PHP_SELF"];

// Count by status
$cnt_active = 0; $cnt_inactive = 0; $cnt_suspended = 0;
foreach ($rows as $r) {
    if ($r->status == 'active') $cnt_active++;
    elseif ($r->status == 'suspended') $cnt_suspended++;
    else $cnt_inactive++;
}
?>

<meta name="viewport" content="width=device-width, initial-scale=1.0">

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
.vl-header-left .vl-subtitle { font-size: 13px; color: #7c859c; font-weight: 400; }
.vl-header-actions { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }

/* Buttons */
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
.vl-btn-primary  { background: #3c4758 !important; color: #fff !important; }
.vl-btn-primary:hover  { background: #2a3346 !important; color: #fff !important; }
.vl-btn-secondary { background: #3c4758 !important; color: #fff !important; border: none !important; }
.vl-btn-secondary:hover { background: #2a3346 !important; color: #fff !important; }

/* Filters */
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
.vl-filter-group { display: flex; flex-direction: column; gap: 5px; flex: 1; min-width: 130px; }
.vl-filter-group label { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.6px; color: #9aa0b4; }
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
.vl-filter-actions { display: flex; gap: 8px; align-items: flex-end; padding-bottom: 1px; }
.vl-btn-filter {
    padding: 8px 16px;
    font-size: 13px;
    border-radius: 6px;
    font-weight: 600;
    border: none;
    cursor: pointer;
    font-family: 'DM Sans', sans-serif;
    transition: all 0.15s;
    white-space: nowrap;
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
.vl-stat-chip.active   { background: #edfaf3; color: #1a7d4a; }
.vl-stat-chip.active .vl-stat-num { color: #1a7d4a; }
.vl-stat-chip.suspended { background: #fff8ec; color: #b45309; }
.vl-stat-chip.suspended .vl-stat-num { color: #b45309; }
.vl-stat-chip.inactive { background: #fef2f2; color: #b91c1c; }
.vl-stat-chip.inactive .vl-stat-num { color: #b91c1c; }

/* Table */
.vl-table-card {
    background: #fff;
    border: 1px solid #e8eaf0;
    border-radius: 14px;
    overflow: hidden;
    box-shadow: 0 2px 12px rgba(0,0,0,0.05);
}
.vl-table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }

table.vl-table { width: 100%; border-collapse: collapse; font-size: 13.5px; }
table.vl-table thead tr { background: #f7f8fc; border-bottom: 2px solid #e8eaf0; }
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
    width: 30px; height: 30px;
    background: rgba(60,71,88,0.08);
    border-radius: 8px; display: flex; align-items: center;
    justify-content: center; color: #3c4758; font-size: 14px; flex-shrink: 0;
}

/* Driver name */
.vl-driver-name { font-weight: 600; color: #1a1f2e; font-size: 13.5px; }
.vl-driver-sub  { font-size: 11.5px; color: #9aa0b4; margin-top: 2px; }

/* Mono fields */
.vl-mono {
    font-family: 'DM Mono', monospace;
    font-size: 12px; color: #4a5568;
    background: #f0f2fa; padding: 3px 8px;
    border-radius: 5px; display: inline-block;
}

/* Vehicle chip */
.vl-vehicle-chip {
    display: inline-flex; align-items: center; gap: 6px;
    font-size: 12.5px; font-weight: 600;
    color: #3c4758; background: rgba(60,71,88,0.07);
    padding: 4px 10px; border-radius: 6px;
}
.vl-vehicle-sub { font-size: 11px; color: #9aa0b4; margin-top: 2px; }

/* Status badge */
.vl-badge {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 4px 10px; border-radius: 20px;
    font-size: 11.5px; font-weight: 600; white-space: nowrap;
}
.vl-badge::before { content: ''; width: 6px; height: 6px; border-radius: 50%; flex-shrink: 0; }
.vl-badge.active    { background: #edfaf3; color: #1a7d4a; }
.vl-badge.active::before { background: #22c55e; }
.vl-badge.suspended { background: #fff8ec; color: #b45309; }
.vl-badge.suspended::before { background: #f59e0b; }
.vl-badge.inactive  { background: #fef2f2; color: #b91c1c; }
.vl-badge.inactive::before  { background: #ef4444; }

/* Action buttons */
.vl-actions { display: flex; gap: 4px; justify-content: center; }
.vl-action-btn {
    width: 32px; height: 32px; border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    text-decoration: none; transition: all 0.15s;
    font-size: 13px; border: 1.5px solid transparent;
}
.vl-action-btn.view  { color: #3c4758; background: #eaecf0; border-color: #c4c9d4; }
.vl-action-btn.edit  { color: #d97706; background: #fef9ec; border-color: #fde9a2; }
.vl-action-btn.del   { color: #dc2626; background: #fef2f2; border-color: #fecaca; }
.vl-action-btn:hover { transform: translateY(-1px); box-shadow: 0 3px 8px rgba(0,0,0,0.1); text-decoration: none; }

/* Empty state */
.vl-empty { padding: 70px 20px; text-align: center; color: #9aa0b4; }
.vl-empty-icon { font-size: 52px; opacity: 0.3; margin-bottom: 16px; }
.vl-empty p { font-size: 15px; font-weight: 500; margin: 0 0 20px; color: #7c859c; }

/* Pagination */
.vl-pagination {
    display: flex; align-items: center; justify-content: space-between;
    padding: 16px 20px; border-top: 1px solid #f0f2f8;
    flex-wrap: wrap; gap: 12px;
}
.vl-pagination-info { font-size: 12.5px; color: #9aa0b4; }
.vl-page-btns { display: flex; gap: 4px; }
.vl-page-btn {
    min-width: 34px; height: 34px; padding: 0 10px;
    border-radius: 8px; display: inline-flex; align-items: center;
    justify-content: center; font-size: 13px; font-weight: 600;
    text-decoration: none; transition: all 0.15s;
    border: 1.5px solid #e2e5f0; color: #5a6482; background: #fff;
}
.vl-page-btn:hover { background: #f0f2fa; border-color: #c4c9d8; text-decoration: none; color: #2d3748; }
.vl-page-btn.active { background: #3c4758; color: #fff; border-color: transparent; }
.vl-page-btn.disabled { opacity: 0.35; pointer-events: none; }

/* ══════════════════════════════════════
   RESPONSIVE BREAKPOINTS
══════════════════════════════════════ */

/* Tablet (≤ 1024px) */
@media (max-width: 1024px) {
    .vl-wrap { padding: 0 12px 40px; }
    table.vl-table thead th,
    table.vl-table tbody td { padding: 11px 12px; }
}

/* Small tablet (≤ 900px) */
@media (max-width: 900px) {
    .vl-filters { flex-direction: column; }
    .vl-filter-group { min-width: 100% !important; max-width: 100% !important; }
    .vl-filter-actions { width: 100%; justify-content: flex-end; }

    /* Hide less critical columns: Employee ID, Department */
    table.vl-table th:nth-child(4),
    table.vl-table td:nth-child(4),
    table.vl-table th:nth-child(6),
    table.vl-table td:nth-child(6) { display: none; }
}

/* Mobile (≤ 600px) */
@media (max-width: 600px) {
    .vl-wrap { padding: 0 8px 32px; }

    /* Header */
    .vl-header {
        flex-direction: column;
        align-items: flex-start;
        padding: 18px 0 16px;
        gap: 12px;
    }
    .vl-header-left h1 { font-size: 18px; }
    .vl-header-actions { width: 100%; justify-content: flex-start; }
    .vl-btn { padding: 8px 12px; font-size: 12px; }

    /* Stats */
    .vl-stats { gap: 6px; }
    .vl-stat-chip { padding: 5px 10px; font-size: 11px; }

    /* Convert table to stacked card layout */
    .vl-table-wrap { overflow-x: unset; }

    table.vl-table,
    table.vl-table thead,
    table.vl-table tbody,
    table.vl-table th,
    table.vl-table td,
    table.vl-table tr { display: block; }

    table.vl-table thead { display: none; }

    table.vl-table tbody tr {
        border: 1px solid #e8eaf0;
        border-radius: 10px;
        margin-bottom: 12px;
        padding: 8px 4px;
        background: #fff;
        box-shadow: 0 1px 6px rgba(0,0,0,0.05);
    }
    table.vl-table tbody tr:hover { background: #fafbff; }

    table.vl-table tbody td {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 8px 14px;
        border-bottom: 1px solid #f4f5fb;
        font-size: 13px;
        text-align: right !important;
    }
    table.vl-table tbody td:last-child { border-bottom: none; }

    /* data-label pseudo headers */
    table.vl-table tbody td::before {
        content: attr(data-label);
        font-size: 11px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        color: #9aa0b4;
        text-align: left;
        flex-shrink: 0;
        margin-right: 10px;
    }

    /* Ref cell — no label, left-aligned */
    table.vl-table tbody td:first-child {
        justify-content: flex-start;
        text-align: left !important;
        padding-top: 12px;
        padding-bottom: 12px;
    }
    table.vl-table tbody td:first-child::before { display: none; }

    .vl-actions { justify-content: flex-end; }

    /* Pagination */
    .vl-pagination {
        flex-direction: column;
        align-items: center;
        text-align: center;
        gap: 10px;
        padding: 14px 12px;
    }
    .vl-page-btns { flex-wrap: wrap; justify-content: center; }
    .vl-page-btn { min-width: 30px; height: 30px; font-size: 12px; }
}
</style>

<div class="vl-wrap">

<!-- Header -->
<div class="vl-header">
    <div class="vl-header-left">
        <h1><i class="fa fa-users" style="color:#3c4758;margin-right:10px;"></i><?php echo $langs->trans("Drivers List"); ?></h1>
        <div class="vl-subtitle"><?php echo $nbtotalofrecords; ?> driver<?php echo $nbtotalofrecords != 1 ? 's' : ''; ?> found</div>
    </div>
    <div class="vl-header-actions">
        <?php if ($user->rights->flotte->read) { ?>
        <a class="vl-btn vl-btn-secondary" href="<?php echo dol_buildpath('/flotte/driver_list.php', 1); ?>?action=export">
            <i class="fa fa-download"></i> Export
        </a>
        <?php } ?>
        <?php if ($user->rights->flotte->write) { ?>
        <a class="vl-btn vl-btn-primary" href="<?php echo dol_buildpath('/flotte/driver_card.php', 1); ?>?action=create">
            <i class="fa fa-plus"></i> New Driver
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
        <label>First Name</label>
        <input type="text" name="search_firstname" placeholder="First name…" value="<?php echo dol_escape_htmltag($search_firstname); ?>">
    </div>
    <div class="vl-filter-group">
        <label>Last Name</label>
        <input type="text" name="search_lastname" placeholder="Last name…" value="<?php echo dol_escape_htmltag($search_lastname); ?>">
    </div>
    <div class="vl-filter-group">
        <label>Phone</label>
        <input type="text" name="search_phone" placeholder="Phone…" value="<?php echo dol_escape_htmltag($search_phone); ?>">
    </div>
    <div class="vl-filter-group">
        <label>Employee ID</label>
        <input type="text" name="search_employee_id" placeholder="Employee ID…" value="<?php echo dol_escape_htmltag($search_employee_id); ?>">
    </div>
    <div class="vl-filter-group">
        <label>License No.</label>
        <input type="text" name="search_license_number" placeholder="License…" value="<?php echo dol_escape_htmltag($search_license_number); ?>">
    </div>
    <div class="vl-filter-group" style="max-width:150px;">
        <label>Status</label>
        <select name="search_status">
            <option value="">All statuses</option>
            <option value="active"    <?php echo $search_status === 'active'    ? 'selected' : ''; ?>>Active</option>
            <option value="inactive"  <?php echo $search_status === 'inactive'  ? 'selected' : ''; ?>>Inactive</option>
            <option value="suspended" <?php echo $search_status === 'suspended' ? 'selected' : ''; ?>>Suspended</option>
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
    <?php if ($cnt_active > 0) { ?>
    <div class="vl-stat-chip active">
        <span class="vl-stat-num"><?php echo $cnt_active; ?></span> Active
    </div>
    <?php } ?>
    <?php if ($cnt_suspended > 0) { ?>
    <div class="vl-stat-chip suspended">
        <span class="vl-stat-num"><?php echo $cnt_suspended; ?></span> Suspended
    </div>
    <?php } ?>
    <?php if ($cnt_inactive > 0) { ?>
    <div class="vl-stat-chip inactive">
        <span class="vl-stat-num"><?php echo $cnt_inactive; ?></span> Inactive
    </div>
    <?php } ?>
</div>

<!-- Table -->
<div class="vl-table-card">
    <div class="vl-table-wrap">
    <table class="vl-table">
        <thead>
            <tr>
                <th><a href="<?php echo dl_sortHref('t.ref', $sortfield, $sortorder, $self, $param); ?>">Ref <?php echo dl_sortArrow('t.ref', $sortfield, $sortorder); ?></a></th>
                <th><a href="<?php echo dl_sortHref('t.firstname', $sortfield, $sortorder, $self, $param); ?>">Driver <?php echo dl_sortArrow('t.firstname', $sortfield, $sortorder); ?></a></th>
                <th><a href="<?php echo dl_sortHref('t.phone', $sortfield, $sortorder, $self, $param); ?>">Phone <?php echo dl_sortArrow('t.phone', $sortfield, $sortorder); ?></a></th>
                <th><a href="<?php echo dl_sortHref('t.employee_id', $sortfield, $sortorder, $self, $param); ?>">Employee ID <?php echo dl_sortArrow('t.employee_id', $sortfield, $sortorder); ?></a></th>
                <th><a href="<?php echo dl_sortHref('t.license_number', $sortfield, $sortorder, $self, $param); ?>">License No. <?php echo dl_sortArrow('t.license_number', $sortfield, $sortorder); ?></a></th>
                <th><a href="<?php echo dl_sortHref('t.department', $sortfield, $sortorder, $self, $param); ?>">Department <?php echo dl_sortArrow('t.department', $sortfield, $sortorder); ?></a></th>
                <th><a href="<?php echo dl_sortHref('v.ref', $sortfield, $sortorder, $self, $param); ?>">Assigned Vehicle <?php echo dl_sortArrow('v.ref', $sortfield, $sortorder); ?></a></th>
                <th class="center"><a href="<?php echo dl_sortHref('t.status', $sortfield, $sortorder, $self, $param); ?>">Status <?php echo dl_sortArrow('t.status', $sortfield, $sortorder); ?></a></th>
                <th class="center">Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php if (!empty($rows)) {
            foreach ($rows as $obj) {
                $cardUrl = dol_buildpath('/flotte/driver_card.php', 1).'?id='.$obj->rowid;
                $fullName = trim($obj->firstname.' '.($obj->middlename ? $obj->middlename.' ' : '').$obj->lastname);
        ?>
            <tr>
                <!-- Ref -->
                <td data-label="Ref">
                    <a href="<?php echo $cardUrl; ?>" class="vl-ref-link">
                        <span class="vl-ref-icon"><i class="fa fa-user"></i></span>
                        <?php echo dol_escape_htmltag($obj->ref); ?>
                    </a>
                </td>

                <!-- Driver name -->
                <td data-label="Driver">
                    <div class="vl-driver-name"><?php echo dol_escape_htmltag($fullName); ?></div>
                    <?php if (!empty($obj->email)) { ?>
                    <div class="vl-driver-sub"><?php echo dol_escape_htmltag($obj->email); ?></div>
                    <?php } ?>
                </td>

                <!-- Phone -->
                <td data-label="Phone"><?php echo dol_escape_htmltag($obj->phone ?: '—'); ?></td>

                <!-- Employee ID -->
                <td data-label="Employee ID">
                    <?php if (!empty($obj->employee_id)) { ?>
                    <span class="vl-mono"><?php echo dol_escape_htmltag($obj->employee_id); ?></span>
                    <?php } else { echo '—'; } ?>
                </td>

                <!-- License Number -->
                <td data-label="License No.">
                    <?php if (!empty($obj->license_number)) { ?>
                    <span class="vl-mono"><?php echo dol_escape_htmltag($obj->license_number); ?></span>
                    <?php } else { echo '—'; } ?>
                </td>

                <!-- Department -->
                <td data-label="Department"><?php echo dol_escape_htmltag($obj->department ?: '—'); ?></td>

                <!-- Assigned Vehicle -->
                <td data-label="Vehicle">
                    <?php if ($obj->vehicle_ref) { ?>
                    <div class="vl-vehicle-chip">
                        <i class="fa fa-car" style="font-size:11px;opacity:0.6;"></i>
                        <?php echo dol_escape_htmltag($obj->vehicle_ref); ?>
                    </div>
                    <?php if ($obj->vehicle_maker || $obj->vehicle_model) { ?>
                    <div class="vl-vehicle-sub"><?php echo dol_escape_htmltag(trim($obj->vehicle_maker.' '.$obj->vehicle_model)); ?></div>
                    <?php } ?>
                    <?php } else { ?>
                    <span style="color:#c4c9d8;font-size:13px;">Not assigned</span>
                    <?php } ?>
                </td>

                <!-- Status -->
                <td class="center" data-label="Status">
                    <?php
                    $s = $obj->status;
                    if ($s == 'active') echo '<span class="vl-badge active">Active</span>';
                    elseif ($s == 'suspended') echo '<span class="vl-badge suspended">Suspended</span>';
                    else echo '<span class="vl-badge inactive">Inactive</span>';
                    ?>
                </td>

                <!-- Actions -->
                <td data-label="Actions">
                    <div class="vl-actions">
                        <a href="<?php echo $cardUrl; ?>" class="vl-action-btn view" title="View"><i class="fa fa-eye"></i></a>
                        <?php if ($user->rights->flotte->write) { ?>
                        <a href="<?php echo $cardUrl; ?>&action=edit" class="vl-action-btn edit" title="Edit"><i class="fa fa-pen"></i></a>
                        <?php } ?>
                        <?php if ($user->rights->flotte->delete) { ?>
                        <a href="<?php echo dol_buildpath('/flotte/driver_list.php', 1); ?>?action=delete&id=<?php echo $obj->rowid; ?>&token=<?php echo newToken(); ?>" class="vl-action-btn del" title="Delete"><i class="fa fa-trash"></i></a>
                        <?php } ?>
                    </div>
                </td>
            </tr>
        <?php }} else { ?>
            <tr>
                <td colspan="9">
                    <div class="vl-empty">
                        <div class="vl-empty-icon"><i class="fa fa-users"></i></div>
                        <p>No drivers found</p>
                        <?php if ($user->rights->flotte->write) { ?>
                        <a class="vl-btn vl-btn-primary" href="<?php echo dol_buildpath('/flotte/driver_card.php', 1); ?>?action=create">
                            <i class="fa fa-plus"></i> Add First Driver
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
            Showing <strong><?php echo $showing_from; ?></strong>–<strong><?php echo $showing_to; ?></strong> of <strong><?php echo $nbtotalofrecords; ?></strong> drivers
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
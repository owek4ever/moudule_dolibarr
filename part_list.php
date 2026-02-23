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
$search_title = GETPOST('search_title', 'alpha');
$search_number = GETPOST('search_number', 'alpha');
$search_barcode = GETPOST('search_barcode', 'alpha');
$search_manufacturer = GETPOST('search_manufacturer', 'alpha');
$search_model = GETPOST('search_model', 'alpha');
$search_status = GETPOST('search_status', 'alpha');
$search_availability = GETPOST('search_availability', 'alpha');
$search_vendor = GETPOST('search_vendor', 'int');

$limit = GETPOST('limit', 'int') ? GETPOST('limit', 'int') : $conf->liste_limit;
$sortfield = GETPOST('sortfield', 'aZ09comma');
$sortorder = GETPOST('sortorder', 'aZ09comma');
$page = GETPOSTISSET('pageplusone') ? (GETPOST('pageplusone') - 1) : GETPOST("page", 'int');

if (empty($page) || $page == -1) {
    $page = 0;
}

$offset = $limit * $page;

if (!$sortfield) {
    $sortfield = "t.title";
}
if (!$sortorder) {
    $sortorder = "ASC";
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
    $search_title = '';
    $search_number = '';
    $search_barcode = '';
    $search_manufacturer = '';
    $search_model = '';
    $search_status = '';
    $search_availability = '';
    $search_vendor = '';
}

// Delete action
if ($action == 'confirm_delete' && $confirm == 'yes' && $user->rights->flotte->write) {
    $id = GETPOST('id', 'int');
    if ($id > 0) {
        $sql = "DELETE FROM ".MAIN_DB_PREFIX."flotte_part WHERE rowid = ".(int)$id;
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
$sql = 'SELECT t.rowid, t.ref, t.barcode, t.title, t.number, t.description, t.status, t.availability,';
$sql .= ' t.fk_vendor, t.manufacturer, t.year, t.model, t.qty_on_hand, t.unit_cost, t.note, t.picture,';
$sql .= ' v.name as vendor_name';
$sql .= ' FROM '.MAIN_DB_PREFIX.'flotte_part as t';
$sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'flotte_vendor as v ON t.fk_vendor = v.rowid';
$sql .= ' WHERE 1 = 1';
$sql .= ' AND t.entity IN ('.getEntity('flotte').')';

if ($search_ref) {
    $sql .= " AND t.ref LIKE '%".$db->escape($search_ref)."%'";
}
if ($search_title) {
    $sql .= " AND t.title LIKE '%".$db->escape($search_title)."%'";
}
if ($search_number) {
    $sql .= " AND t.number LIKE '%".$db->escape($search_number)."%'";
}
if ($search_barcode) {
    $sql .= " AND t.barcode LIKE '%".$db->escape($search_barcode)."%'";
}
if ($search_manufacturer) {
    $sql .= " AND t.manufacturer LIKE '%".$db->escape($search_manufacturer)."%'";
}
if ($search_model) {
    $sql .= " AND t.model LIKE '%".$db->escape($search_model)."%'";
}
if ($search_status) {
    $sql .= " AND t.status = '".$db->escape($search_status)."'";
}
if ($search_availability) {
    $sql .= " AND t.availability = ".((int) $search_availability);
}
if ($search_vendor) {
    $sql .= " AND t.fk_vendor = ".((int) $search_vendor);
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

// Get vendors and categories for filters
$vendors = array();
$sql_vendors = "SELECT rowid, name FROM ".MAIN_DB_PREFIX."flotte_vendor WHERE entity = ".$conf->entity." ORDER BY name";
$resql_vendors = $db->query($sql_vendors);
if ($resql_vendors) {
    while ($obj = $db->fetch_object($resql_vendors)) {
        $vendors[$obj->rowid] = $obj->name;
    }
}

$form = new Form($db);
$num = 0;
if ($resql) {
    $num = $db->num_rows($resql);
}

// Page header
llxHeader('', $langs->trans("Parts List"), '');

// Build param string for URL
$param = '';
if (!empty($search_ref))           $param .= '&search_ref='.urlencode($search_ref);
if (!empty($search_title))         $param .= '&search_title='.urlencode($search_title);
if (!empty($search_number))        $param .= '&search_number='.urlencode($search_number);
if (!empty($search_barcode))       $param .= '&search_barcode='.urlencode($search_barcode);
if (!empty($search_manufacturer))  $param .= '&search_manufacturer='.urlencode($search_manufacturer);
if (!empty($search_model))         $param .= '&search_model='.urlencode($search_model);
if (!empty($search_status))        $param .= '&search_status='.urlencode($search_status);
if (!empty($search_availability))  $param .= '&search_availability='.urlencode($search_availability);
if (!empty($search_vendor))        $param .= '&search_vendor='.urlencode($search_vendor);

// Confirmation to delete
if ($action == 'delete') {
    $id = GETPOST('id', 'int');
    $formconfirm = $form->formconfirm($_SERVER["PHP_SELF"].'?id='.$id.$param, $langs->trans('DeletePart'), $langs->trans('ConfirmDeletePart'), 'confirm_delete', '', 0, 1);
    print $formconfirm;

    // Clear the action from URL after showing confirmation to prevent reappearing on refresh
    if (!empty($formconfirm)) {
        echo '<script type="text/javascript">
        if (window.history.replaceState) {
            window.history.replaceState(null, null, "'.$_SERVER["PHP_SELF"].'?'.$param.'");
        }
        </script>';
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
function pl_sortArrow($field, $sortfield, $sortorder) {
    if ($sortfield == $field) return $sortorder == 'ASC' ? ' <span class="vl-sort-arrow">↑</span>' : ' <span class="vl-sort-arrow">↓</span>';
    return ' <span class="vl-sort-arrow muted">↕</span>';
}
function pl_sortHref($field, $sortfield, $sortorder, $self, $param) {
    $dir = ($sortfield == $field && $sortorder == 'ASC') ? 'DESC' : 'ASC';
    return $self.'?sortfield='.$field.'&sortorder='.$dir.'&'.$param;
}

$self = $_SERVER["PHP_SELF"];

// Count by status
$cnt_active = 0; $cnt_inactive = 0; $cnt_maintenance = 0; $cnt_discontinued = 0;
$cnt_available = 0; $cnt_out_of_stock = 0;
foreach ($rows as $r) {
    if ($r->status == 'Active')        $cnt_active++;
    elseif ($r->status == 'Inactive')  $cnt_inactive++;
    elseif ($r->status == 'Maintenance') $cnt_maintenance++;
    elseif ($r->status == 'Discontinued') $cnt_discontinued++;
    if ($r->availability == 1) $cnt_available++;
    if ($r->qty_on_hand <= 0)  $cnt_out_of_stock++;
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

/* Part title */
.vl-part-name { font-weight: 600; color: #1a1f2e; font-size: 13.5px; }
.vl-part-sub  { font-size: 11.5px; color: #9aa0b4; margin-top: 2px; }

/* Mono */
.vl-mono {
    font-family: 'DM Mono', monospace; font-size: 12px; color: #4a5568;
    background: #f0f2fa; padding: 3px 8px; border-radius: 5px; display: inline-block;
}

/* Category chip */
.vl-category-chip {
    display: inline-flex; align-items: center; gap: 6px;
    font-size: 12.5px; font-weight: 600; color: #1d4ed8;
    background: #eff6ff; padding: 4px 10px; border-radius: 6px;
}

/* Stock indicator */
.vl-stock {
    display: inline-flex; align-items: baseline; gap: 4px;
    font-weight: 700; font-size: 13px;
}
.vl-stock.ok      { color: #166534; }
.vl-stock.low     { color: #92400e; }
.vl-stock.empty   { color: #991b1b; }

/* Status / availability badge */
.vl-badge {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 4px 10px; border-radius: 20px;
    font-size: 11.5px; font-weight: 600; white-space: nowrap;
}
.vl-badge::before { content: ''; width: 6px; height: 6px; border-radius: 50%; flex-shrink: 0; }
.vl-badge.active        { background: #f0fdf4; color: #166534; }
.vl-badge.active::before { background: #22c55e; }
.vl-badge.inactive      { background: #fef2f2; color: #991b1b; }
.vl-badge.inactive::before { background: #ef4444; }
.vl-badge.maintenance   { background: #fffbeb; color: #92400e; }
.vl-badge.maintenance::before { background: #f59e0b; }
.vl-badge.discontinued  { background: #f0f2fa; color: #5a6482; }
.vl-badge.discontinued::before { background: #8b92a9; }
.vl-badge.available     { background: #f0fdf4; color: #166534; }
.vl-badge.available::before { background: #22c55e; }
.vl-badge.unavailable   { background: #fef2f2; color: #991b1b; }
.vl-badge.unavailable::before { background: #ef4444; }

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

/* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
   RESPONSIVE STYLES
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */

/* ── 1100px: tighten table cell padding ── */
@media (max-width: 1100px) {
    .vl-wrap { padding: 0 10px 40px; }
    table.vl-table thead th,
    table.vl-table tbody td { padding: 11px 10px; }
}

/* ── 900px: stack filters ── */
@media (max-width: 900px) {
    .vl-filters { flex-direction: column; gap: 10px; }
    .vl-filter-group { min-width: 100%; max-width: 100% !important; }
    .vl-filter-actions { width: 100%; }
    .vl-btn-filter { flex: 1; justify-content: center; }
    .vl-stats { gap: 8px; }
}

/* ── 760px: hide lower-priority columns, stack header ── */
@media (max-width: 760px) {
    .vl-wrap { padding: 0 8px 32px; }

    .vl-header { flex-direction: column; align-items: flex-start; gap: 12px; padding: 18px 0 16px; }
    .vl-header-actions { width: 100%; }
    .vl-btn { flex: 1; justify-content: center; }

    /* Hide: Barcode (4), Manufacturer (5), Available (10) */
    table.vl-table thead th:nth-child(4),
    table.vl-table tbody td:nth-child(4),
    table.vl-table thead th:nth-child(5),
    table.vl-table tbody td:nth-child(5),
    table.vl-table thead th:nth-child(10),
    table.vl-table tbody td:nth-child(10) { display: none; }

    table.vl-table thead th,
    table.vl-table tbody td { padding: 10px 8px; font-size: 12.5px; }

    .vl-pagination { flex-direction: column; align-items: center; gap: 10px; padding: 14px 12px; }
    .vl-page-btn { min-width: 30px; height: 30px; font-size: 12px; }
}

/* ── 540px: card-style rows for mobile ── */
@media (max-width: 540px) {
    .vl-wrap { padding: 0 6px 24px; }
    .vl-header-left h1 { font-size: 18px; }
    .vl-header-left .vl-subtitle { font-size: 12px; }

    /* Switch table to card layout */
    .vl-table-wrap { overflow-x: unset; }
    table.vl-table { display: block; }
    table.vl-table thead { display: none; }
    table.vl-table tbody { display: flex; flex-direction: column; gap: 10px; padding: 10px; }
    table.vl-table tbody tr {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 6px 12px;
        border: 1px solid #e8eaf0;
        border-radius: 10px;
        padding: 12px 14px;
        background: #fff;
        box-shadow: 0 1px 4px rgba(0,0,0,0.04);
    }
    table.vl-table tbody tr:hover { background: #fafbff; }
    table.vl-table tbody td {
        display: flex;
        flex-direction: column;
        padding: 0;
        border: none;
        font-size: 13px;
        text-align: left !important;
    }

    /* Restore hidden columns */
    table.vl-table tbody td:nth-child(4),
    table.vl-table tbody td:nth-child(5),
    table.vl-table tbody td:nth-child(10) { display: flex; }

    /* Column labels via pseudo-elements */
    table.vl-table tbody td::before {
        content: attr(data-label);
        font-size: 10px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        color: #9aa0b4;
        margin-bottom: 2px;
    }

    /* Ref spans full width at top */
    table.vl-table tbody td:nth-child(1) {
        grid-column: 1 / -1;
        border-bottom: 1px solid #f0f2f8;
        padding-bottom: 8px;
        margin-bottom: 2px;
    }
    /* Actions span full width at bottom */
    table.vl-table tbody td:last-child {
        grid-column: 1 / -1;
        border-top: 1px solid #f0f2f8;
        padding-top: 8px;
        margin-top: 2px;
    }
    .vl-actions { justify-content: flex-start; }

    /* Empty state row: undo grid */
    table.vl-table tbody tr:has(.vl-empty) {
        display: block;
        border: none;
        box-shadow: none;
        padding: 0;
    }

    /* Filters & stats */
    .vl-filters { padding: 14px 12px; border-radius: 10px; }
    .vl-filter-actions { flex-direction: row; }
    .vl-stats { gap: 6px; }
    .vl-stat-chip { font-size: 11.5px; padding: 5px 10px; }

    /* Pagination */
    .vl-page-btns { flex-wrap: wrap; justify-content: center; }
}
</style>

<div class="vl-wrap">

<!-- Header -->
<div class="vl-header">
    <div class="vl-header-left">
        <h1><i class="fa fa-cogs" style="color:#3c4758;margin-right:10px;"></i><?php echo $langs->trans("Parts List"); ?></h1>
        <div class="vl-subtitle"><?php echo $nbtotalofrecords; ?> part<?php echo $nbtotalofrecords != 1 ? 's' : ''; ?> found</div>
    </div>
    <div class="vl-header-actions">
        <?php if ($user->rights->flotte->read) { ?>
        <a class="vl-btn vl-btn-secondary" href="<?php echo dol_buildpath('/flotte/part_list.php', 1); ?>?action=export">
            <i class="fa fa-download"></i> Export
        </a>
        <?php } ?>
        <?php if ($user->rights->flotte->write) { ?>
        <a class="vl-btn vl-btn-primary" href="<?php echo dol_buildpath('/flotte/part_card.php', 1); ?>?action=create">
            <i class="fa fa-plus"></i> New Part
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
        <label>Title</label>
        <input type="text" name="search_title" placeholder="Part title…" value="<?php echo dol_escape_htmltag($search_title); ?>">
    </div>
    <div class="vl-filter-group">
        <label>Part No.</label>
        <input type="text" name="search_number" placeholder="Part number…" value="<?php echo dol_escape_htmltag($search_number); ?>">
    </div>
    <div class="vl-filter-group">
        <label>Barcode</label>
        <input type="text" name="search_barcode" placeholder="Barcode…" value="<?php echo dol_escape_htmltag($search_barcode); ?>">
    </div>
    <div class="vl-filter-group">
        <label>Manufacturer</label>
        <input type="text" name="search_manufacturer" placeholder="Manufacturer…" value="<?php echo dol_escape_htmltag($search_manufacturer); ?>">
    </div>
    <div class="vl-filter-group" style="max-width:170px;">
        <label>Vendor</label>
        <select name="search_vendor">
            <option value="">All</option>
            <?php foreach ($vendors as $v_id => $v_name) { ?>
            <option value="<?php echo (int)$v_id; ?>" <?php echo (int)$search_vendor === (int)$v_id ? 'selected' : ''; ?>><?php echo dol_escape_htmltag($v_name); ?></option>
            <?php } ?>
        </select>
    </div>
    <div class="vl-filter-group" style="max-width:160px;">
        <label>Status</label>
        <select name="search_status">
            <option value="">All</option>
            <option value="Active"       <?php echo $search_status === 'Active'       ? 'selected' : ''; ?>>Active</option>
            <option value="Inactive"     <?php echo $search_status === 'Inactive'     ? 'selected' : ''; ?>>Inactive</option>
            <option value="Maintenance"  <?php echo $search_status === 'Maintenance'  ? 'selected' : ''; ?>>Maintenance</option>
            <option value="Discontinued" <?php echo $search_status === 'Discontinued' ? 'selected' : ''; ?>>Discontinued</option>
        </select>
    </div>
    <div class="vl-filter-group" style="max-width:160px;">
        <label>Availability</label>
        <select name="search_availability">
            <option value="">All</option>
            <option value="1" <?php echo $search_availability === '1' ? 'selected' : ''; ?>>Available</option>
            <option value="0" <?php echo $search_availability === '0' ? 'selected' : ''; ?>>Not Available</option>
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
    <div class="vl-stat-chip" style="background:#f0fdf4;color:#166534;">
        <span class="vl-stat-num" style="color:#166534;"><?php echo $cnt_active; ?></span> Active
    </div>
    <?php } ?>
    <?php if ($cnt_maintenance > 0) { ?>
    <div class="vl-stat-chip" style="background:#fffbeb;color:#92400e;">
        <span class="vl-stat-num" style="color:#92400e;"><?php echo $cnt_maintenance; ?></span> Maintenance
    </div>
    <?php } ?>
    <?php if ($cnt_inactive > 0) { ?>
    <div class="vl-stat-chip" style="background:#fef2f2;color:#991b1b;">
        <span class="vl-stat-num" style="color:#991b1b;"><?php echo $cnt_inactive; ?></span> Inactive
    </div>
    <?php } ?>
    <?php if ($cnt_discontinued > 0) { ?>
    <div class="vl-stat-chip" style="background:#f0f2fa;color:#5a6482;">
        <span class="vl-stat-num" style="color:#5a6482;"><?php echo $cnt_discontinued; ?></span> Discontinued
    </div>
    <?php } ?>
    <?php if ($cnt_out_of_stock > 0) { ?>
    <div class="vl-stat-chip" style="background:#fef2f2;color:#991b1b;">
        <span class="vl-stat-num" style="color:#991b1b;"><?php echo $cnt_out_of_stock; ?></span> Out of Stock
    </div>
    <?php } ?>
</div>

<!-- Table -->
<div class="vl-table-card">
    <div class="vl-table-wrap">
    <table class="vl-table">
        <thead>
            <tr>
                <th><a href="<?php echo pl_sortHref('t.ref', $sortfield, $sortorder, $self, $param); ?>">Ref <?php echo pl_sortArrow('t.ref', $sortfield, $sortorder); ?></a></th>
                <th><a href="<?php echo pl_sortHref('t.title', $sortfield, $sortorder, $self, $param); ?>">Title <?php echo pl_sortArrow('t.title', $sortfield, $sortorder); ?></a></th>
                <th><a href="<?php echo pl_sortHref('t.number', $sortfield, $sortorder, $self, $param); ?>">Part No. <?php echo pl_sortArrow('t.number', $sortfield, $sortorder); ?></a></th>
                <th><a href="<?php echo pl_sortHref('t.barcode', $sortfield, $sortorder, $self, $param); ?>">Barcode <?php echo pl_sortArrow('t.barcode', $sortfield, $sortorder); ?></a></th>
                <th><a href="<?php echo pl_sortHref('t.manufacturer', $sortfield, $sortorder, $self, $param); ?>">Manufacturer <?php echo pl_sortArrow('t.manufacturer', $sortfield, $sortorder); ?></a></th>
                <th><a href="<?php echo pl_sortHref('v.name', $sortfield, $sortorder, $self, $param); ?>">Vendor <?php echo pl_sortArrow('v.name', $sortfield, $sortorder); ?></a></th>
                <th class="right"><a href="<?php echo pl_sortHref('t.qty_on_hand', $sortfield, $sortorder, $self, $param); ?>">Stock <?php echo pl_sortArrow('t.qty_on_hand', $sortfield, $sortorder); ?></a></th>
                <th class="right"><a href="<?php echo pl_sortHref('t.unit_cost', $sortfield, $sortorder, $self, $param); ?>">Unit Cost <?php echo pl_sortArrow('t.unit_cost', $sortfield, $sortorder); ?></a></th>
                <th class="center"><a href="<?php echo pl_sortHref('t.status', $sortfield, $sortorder, $self, $param); ?>">Status <?php echo pl_sortArrow('t.status', $sortfield, $sortorder); ?></a></th>
                <th class="center"><a href="<?php echo pl_sortHref('t.availability', $sortfield, $sortorder, $self, $param); ?>">Available <?php echo pl_sortArrow('t.availability', $sortfield, $sortorder); ?></a></th>
                <th class="center">Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php if (!empty($rows)) {
            foreach ($rows as $obj) {
                $cardUrl = dol_buildpath('/flotte/part_card.php', 1).'?id='.$obj->rowid;
        ?>
            <tr>
                <!-- Ref -->
                <td data-label="Ref">
                    <a href="<?php echo $cardUrl; ?>" class="vl-ref-link">
                        <span class="vl-ref-icon"><i class="fa fa-cog"></i></span>
                        <?php echo dol_escape_htmltag($obj->ref); ?>
                    </a>
                </td>

                <!-- Title -->
                <td data-label="Title">
                    <div class="vl-part-name"><?php echo dol_escape_htmltag($obj->title ?: '—'); ?></div>
                    <?php if (!empty($obj->model) || !empty($obj->year)) { ?>
                    <div class="vl-part-sub"><?php echo dol_escape_htmltag(trim(($obj->model ?? '').' '.($obj->year ?? ''))); ?></div>
                    <?php } ?>
                </td>

                <!-- Part Number -->
                <td data-label="Part No.">
                    <?php if (!empty($obj->number)) { ?>
                    <span class="vl-mono"><?php echo dol_escape_htmltag($obj->number); ?></span>
                    <?php } else { echo '<span style="color:#c4c9d8;">—</span>'; } ?>
                </td>

                <!-- Barcode -->
                <td data-label="Barcode">
                    <?php if (!empty($obj->barcode)) { ?>
                    <span class="vl-mono"><?php echo dol_escape_htmltag($obj->barcode); ?></span>
                    <?php } else { echo '<span style="color:#c4c9d8;">—</span>'; } ?>
                </td>

                <!-- Manufacturer -->
                <td data-label="Manufacturer"><?php echo dol_escape_htmltag($obj->manufacturer ?: '—'); ?></td>

                <!-- Vendor -->
                <td data-label="Vendor"><?php echo dol_escape_htmltag($obj->vendor_name ?: '—'); ?></td>

                <!-- Stock -->
                <td class="right" data-label="Stock">
                    <?php
                    $qty = (int)$obj->qty_on_hand;
                    if ($qty <= 0)     $stock_cls = 'empty';
                    elseif ($qty <= 5) $stock_cls = 'low';
                    else               $stock_cls = 'ok';
                    ?>
                    <span class="vl-stock <?php echo $stock_cls; ?>"><?php echo $qty; ?></span>
                </td>

                <!-- Unit Cost -->
                <td class="right" data-label="Unit Cost">
                    <?php echo !empty($obj->unit_cost) ? price($obj->unit_cost) : '<span style="color:#c4c9d8;">—</span>'; ?>
                </td>

                <!-- Status -->
                <td class="center" data-label="Status">
                    <?php
                    $st = $obj->status;
                    if ($st == 'Active')        echo '<span class="vl-badge active">Active</span>';
                    elseif ($st == 'Inactive')  echo '<span class="vl-badge inactive">Inactive</span>';
                    elseif ($st == 'Maintenance') echo '<span class="vl-badge maintenance">Maintenance</span>';
                    elseif ($st == 'Discontinued') echo '<span class="vl-badge discontinued">Discontinued</span>';
                    else echo '<span style="color:#c4c9d8;font-size:13px;">—</span>';
                    ?>
                </td>

                <!-- Availability -->
                <td class="center" data-label="Available">
                    <?php
                    if ($obj->availability == 1) echo '<span class="vl-badge available">Available</span>';
                    else                         echo '<span class="vl-badge unavailable">Not Available</span>';
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
                        <a href="<?php echo $self; ?>?id=<?php echo $obj->rowid; ?>&action=delete&token=<?php echo newToken(); ?><?php echo $param; ?>" class="vl-action-btn del" title="Delete"><i class="fa fa-trash"></i></a>
                        <?php } ?>
                    </div>
                </td>
            </tr>
        <?php }} else { ?>
            <tr>
                <td colspan="11">
                    <div class="vl-empty">
                        <div class="vl-empty-icon"><i class="fa fa-cogs"></i></div>
                        <p>No parts found</p>
                        <?php if ($user->rights->flotte->write) { ?>
                        <a class="vl-btn vl-btn-primary" href="<?php echo dol_buildpath('/flotte/part_card.php', 1); ?>?action=create">
                            <i class="fa fa-plus"></i> Add First Part
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
            Showing <strong><?php echo $showing_from; ?></strong>–<strong><?php echo $showing_to; ?></strong> of <strong><?php echo $nbtotalofrecords; ?></strong> parts
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
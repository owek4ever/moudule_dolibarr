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
$search_name = GETPOST('search_name', 'alpha');
$search_phone = GETPOST('search_phone', 'alpha');
$search_email = GETPOST('search_email', 'alpha');
$search_type = GETPOST('search_type', 'alpha');
$search_city = GETPOST('search_city', 'alpha');
$search_state = GETPOST('search_state', 'alpha');

$limit = GETPOST('limit', 'int') ? GETPOST('limit', 'int') : $conf->liste_limit;
$sortfield = GETPOST('sortfield', 'aZ09comma');
$sortorder = GETPOST('sortorder', 'aZ09comma');
$page = GETPOSTISSET('pageplusone') ? (GETPOST('pageplusone') - 1) : GETPOST("page", 'int');

if (empty($page) || $page == -1) {
    $page = 0;
}

$offset = $limit * $page;

if (!$sortfield) {
    $sortfield = "t.name";
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
    $search_name = '';
    $search_phone = '';
    $search_email = '';
    $search_type = '';
    $search_city = '';
    $search_state = '';
}

// Delete action
if ($action == 'confirm_delete' && $confirm == 'yes' && $user->rights->flotte->write) {
    $id = GETPOST('id', 'int');
    if ($id > 0) {
        $sql = "DELETE FROM ".MAIN_DB_PREFIX."flotte_vendor WHERE rowid = ".(int)$id;
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
$sql = 'SELECT t.rowid, t.ref, t.name, t.phone, t.email, t.type, t.website, t.note, t.address1, t.address2, t.city, t.state, t.picture';
$sql .= ' FROM '.MAIN_DB_PREFIX.'flotte_vendor as t';
$sql .= ' WHERE 1 = 1';
$sql .= ' AND t.entity IN ('.getEntity('flotte').')';

if ($search_ref) {
    $sql .= " AND t.ref LIKE '%".$db->escape($search_ref)."%'";
}
if ($search_name) {
    $sql .= " AND t.name LIKE '%".$db->escape($search_name)."%'";
}
if ($search_phone) {
    $sql .= " AND t.phone LIKE '%".$db->escape($search_phone)."%'";
}
if ($search_email) {
    $sql .= " AND t.email LIKE '%".$db->escape($search_email)."%'";
}
if ($search_type) {
    $sql .= " AND t.type = '".$db->escape($search_type)."'";
}
if ($search_city) {
    $sql .= " AND t.city LIKE '%".$db->escape($search_city)."%'";
}
if ($search_state) {
    $sql .= " AND t.state LIKE '%".$db->escape($search_state)."%'";
}

$sql .= $db->order($sortfield, $sortorder);

// Count total nb of records
$sqlcount = str_replace('SELECT t.rowid, t.ref, t.name, t.phone, t.email, t.type, t.website, t.note, t.address1, t.address2, t.city, t.state, t.picture', 'SELECT COUNT(*) as nb', $sql);
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
llxHeader('', $langs->trans("Vendors List"), '');

// Show delete confirmation dialog if requested
if ($action == 'delete') {
    $id = GETPOST('id', 'int');
    $formconfirm = $form->formconfirm($_SERVER["PHP_SELF"].'?id='.$id.$param, $langs->trans('DeleteVendor'), $langs->trans('ConfirmDeleteVendor'), 'confirm_delete', '', 0, 1);
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
function vl_sortArrow($field, $sortfield, $sortorder) {
    if ($sortfield == $field) return $sortorder == 'ASC' ? ' <span class="vl-sort-arrow">↑</span>' : ' <span class="vl-sort-arrow">↓</span>';
    return ' <span class="vl-sort-arrow muted">↕</span>';
}
function vl_sortHref($field, $sortfield, $sortorder, $self, $param) {
    $dir = ($sortfield == $field && $sortorder == 'ASC') ? 'DESC' : 'ASC';
    return $self.'?sortfield='.$field.'&sortorder='.$dir.'&'.$param;
}

$self = $_SERVER["PHP_SELF"];

// Build param string for URL
$param = '';
if (!empty($search_ref))           $param .= '&search_ref='.urlencode($search_ref);
if (!empty($search_name))          $param .= '&search_name='.urlencode($search_name);
if (!empty($search_phone))         $param .= '&search_phone='.urlencode($search_phone);
if (!empty($search_email))         $param .= '&search_email='.urlencode($search_email);
if (!empty($search_type))          $param .= '&search_type='.urlencode($search_type);
if (!empty($search_city))          $param .= '&search_city='.urlencode($search_city);
if (!empty($search_state))         $param .= '&search_state='.urlencode($search_state);

// Count by type
$type_counts = array();
foreach ($rows as $r) {
    if (!empty($r->type)) {
        $type_counts[$r->type] = ($type_counts[$r->type] ?? 0) + 1;
    }
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

/* Vendor name */
.vl-vendor-name { font-weight: 600; color: #1a1f2e; font-size: 13.5px; }
.vl-vendor-sub  { font-size: 11.5px; color: #9aa0b4; margin-top: 2px; }

/* Mono */
.vl-mono {
    font-family: 'DM Mono', monospace; font-size: 12px; color: #4a5568;
    background: #f0f2fa; padding: 3px 8px; border-radius: 5px; display: inline-block;
}

/* Type badge */
.vl-badge {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 4px 10px; border-radius: 20px;
    font-size: 11.5px; font-weight: 600; white-space: nowrap;
}
.vl-badge::before { content: ''; width: 6px; height: 6px; border-radius: 50%; flex-shrink: 0; }
.vl-badge.parts       { background: #f0fdf4; color: #166534; }
.vl-badge.parts::before { background: #22c55e; }
.vl-badge.fuel        { background: #eff6ff; color: #1d4ed8; }
.vl-badge.fuel::before { background: #3b82f6; }
.vl-badge.maintenance { background: #fffbeb; color: #92400e; }
.vl-badge.maintenance::before { background: #f59e0b; }
.vl-badge.insurance   { background: #fef2f2; color: #991b1b; }
.vl-badge.insurance::before { background: #ef4444; }
.vl-badge.service     { background: #f5f3ff; color: #5b21b6; }
.vl-badge.service::before { background: #8b5cf6; }
.vl-badge.other       { background: #f0f2fa; color: #5a6482; }
.vl-badge.other::before { background: #8b92a9; }

/* Website link */
.vl-website-link {
    display: inline-flex; align-items: center; gap: 5px;
    color: #3c4758; font-size: 12.5px; text-decoration: none;
    font-weight: 500;
}
.vl-website-link:hover { color: #2a3346; text-decoration: underline; }

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

/* ── 1100px: tighten table columns ── */
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

    /* Hide lower-priority columns */
    table.vl-table thead th:nth-child(4),
    table.vl-table tbody td:nth-child(4),
    table.vl-table thead th:nth-child(7),
    table.vl-table tbody td:nth-child(7),
    table.vl-table thead th:nth-child(8),
    table.vl-table tbody td:nth-child(8) { display: none; }

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
    }

    /* Restore hidden columns */
    table.vl-table tbody td:nth-child(4),
    table.vl-table tbody td:nth-child(7),
    table.vl-table tbody td:nth-child(8) { display: flex; }

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
        <h1><i class="fa fa-store" style="color:#3c4758;margin-right:10px;"></i><?php echo $langs->trans("Vendors List"); ?></h1>
        <div class="vl-subtitle"><?php echo $nbtotalofrecords; ?> vendor<?php echo $nbtotalofrecords != 1 ? 's' : ''; ?> found</div>
    </div>
    <div class="vl-header-actions">
        <?php if ($user->rights->flotte->read) { ?>
        <a class="vl-btn vl-btn-secondary" href="<?php echo dol_buildpath('/flotte/vendor_list.php', 1); ?>?action=export">
            <i class="fa fa-download"></i> Export
        </a>
        <?php } ?>
        <?php if ($user->rights->flotte->write) { ?>
        <a class="vl-btn vl-btn-primary" href="<?php echo dol_buildpath('/flotte/vendor_card.php', 1); ?>?action=create">
            <i class="fa fa-plus"></i> New Vendor
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
        <label>Name</label>
        <input type="text" name="search_name" placeholder="Vendor name…" value="<?php echo dol_escape_htmltag($search_name); ?>">
    </div>
    <div class="vl-filter-group">
        <label>Phone</label>
        <input type="text" name="search_phone" placeholder="Phone…" value="<?php echo dol_escape_htmltag($search_phone); ?>">
    </div>
    <div class="vl-filter-group">
        <label>Email</label>
        <input type="text" name="search_email" placeholder="Email…" value="<?php echo dol_escape_htmltag($search_email); ?>">
    </div>
    <div class="vl-filter-group" style="max-width:160px;">
        <label>Type</label>
        <select name="search_type">
            <option value="">All</option>
            <option value="Parts"       <?php echo $search_type === 'Parts'       ? 'selected' : ''; ?>>Parts</option>
            <option value="Fuel"        <?php echo $search_type === 'Fuel'        ? 'selected' : ''; ?>>Fuel</option>
            <option value="Maintenance" <?php echo $search_type === 'Maintenance' ? 'selected' : ''; ?>>Maintenance</option>
            <option value="Insurance"   <?php echo $search_type === 'Insurance'   ? 'selected' : ''; ?>>Insurance</option>
            <option value="Service"     <?php echo $search_type === 'Service'     ? 'selected' : ''; ?>>Service</option>
            <option value="Other"       <?php echo $search_type === 'Other'       ? 'selected' : ''; ?>>Other</option>
        </select>
    </div>
    <div class="vl-filter-group">
        <label>City</label>
        <input type="text" name="search_city" placeholder="City…" value="<?php echo dol_escape_htmltag($search_city); ?>">
    </div>
    <div class="vl-filter-group">
        <label>State</label>
        <input type="text" name="search_state" placeholder="State…" value="<?php echo dol_escape_htmltag($search_state); ?>">
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
    <?php
    $type_styles = array(
        'Parts'       => 'background:#f0fdf4;color:#166534;',
        'Fuel'        => 'background:#eff6ff;color:#1d4ed8;',
        'Maintenance' => 'background:#fffbeb;color:#92400e;',
        'Insurance'   => 'background:#fef2f2;color:#991b1b;',
        'Service'     => 'background:#f5f3ff;color:#5b21b6;',
        'Other'       => 'background:#f0f2fa;color:#5a6482;',
    );
    $type_num_styles = array(
        'Parts'       => 'color:#166534;',
        'Fuel'        => 'color:#1d4ed8;',
        'Maintenance' => 'color:#92400e;',
        'Insurance'   => 'color:#991b1b;',
        'Service'     => 'color:#5b21b6;',
        'Other'       => 'color:#5a6482;',
    );
    foreach ($type_counts as $type => $cnt) {
        $chip_style = $type_styles[$type] ?? '';
        $num_style  = $type_num_styles[$type] ?? '';
        echo '<div class="vl-stat-chip" style="'.$chip_style.'">';
        echo '<span class="vl-stat-num" style="'.$num_style.'">'.$cnt.'</span> '.dol_escape_htmltag($type);
        echo '</div>';
    }
    ?>
</div>

<!-- Table -->
<div class="vl-table-card">
    <div class="vl-table-wrap">
    <table class="vl-table">
        <thead>
            <tr>
                <th><a href="<?php echo vl_sortHref('t.ref', $sortfield, $sortorder, $self, $param); ?>">Ref <?php echo vl_sortArrow('t.ref', $sortfield, $sortorder); ?></a></th>
                <th><a href="<?php echo vl_sortHref('t.name', $sortfield, $sortorder, $self, $param); ?>">Name <?php echo vl_sortArrow('t.name', $sortfield, $sortorder); ?></a></th>
                <th><a href="<?php echo vl_sortHref('t.phone', $sortfield, $sortorder, $self, $param); ?>">Phone <?php echo vl_sortArrow('t.phone', $sortfield, $sortorder); ?></a></th>
                <th><a href="<?php echo vl_sortHref('t.email', $sortfield, $sortorder, $self, $param); ?>">Email <?php echo vl_sortArrow('t.email', $sortfield, $sortorder); ?></a></th>
                <th><a href="<?php echo vl_sortHref('t.type', $sortfield, $sortorder, $self, $param); ?>">Type <?php echo vl_sortArrow('t.type', $sortfield, $sortorder); ?></a></th>
                <th><a href="<?php echo vl_sortHref('t.city', $sortfield, $sortorder, $self, $param); ?>">City <?php echo vl_sortArrow('t.city', $sortfield, $sortorder); ?></a></th>
                <th><a href="<?php echo vl_sortHref('t.state', $sortfield, $sortorder, $self, $param); ?>">State <?php echo vl_sortArrow('t.state', $sortfield, $sortorder); ?></a></th>
                <th><a href="<?php echo vl_sortHref('t.website', $sortfield, $sortorder, $self, $param); ?>">Website <?php echo vl_sortArrow('t.website', $sortfield, $sortorder); ?></a></th>
                <th class="center">Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php if (!empty($rows)) {
            foreach ($rows as $obj) {
                $cardUrl = dol_buildpath('/flotte/vendor_card.php', 1).'?id='.$obj->rowid;
        ?>
            <tr>
                <!-- Ref -->
                <td data-label="Ref">
                    <a href="<?php echo $cardUrl; ?>" class="vl-ref-link">
                        <span class="vl-ref-icon"><i class="fa fa-store"></i></span>
                        <?php echo dol_escape_htmltag($obj->ref); ?>
                    </a>
                </td>

                <!-- Name + email -->
                <td data-label="Name">
                    <div class="vl-vendor-name"><?php echo dol_escape_htmltag($obj->name ?: '—'); ?></div>
                    <?php if (!empty($obj->email)) { ?>
                    <div class="vl-vendor-sub"><?php echo dol_escape_htmltag($obj->email); ?></div>
                    <?php } ?>
                </td>

                <!-- Phone -->
                <td data-label="Phone"><?php echo dol_escape_htmltag($obj->phone ?: '—'); ?></td>

                <!-- Email -->
                <td data-label="Email"><?php echo dol_escape_htmltag($obj->email ?: '—'); ?></td>

                <!-- Type -->
                <td data-label="Type">
                    <?php
                    $t = $obj->type;
                    $badge_class = strtolower($t);
                    if ($t) echo '<span class="vl-badge '.dol_escape_htmltag($badge_class).'">'.dol_escape_htmltag($t).'</span>';
                    else echo '<span style="color:#c4c9d8;">—</span>';
                    ?>
                </td>

                <!-- City -->
                <td data-label="City"><?php echo dol_escape_htmltag($obj->city ?: '—'); ?></td>

                <!-- State -->
                <td data-label="State"><?php echo dol_escape_htmltag($obj->state ?: '—'); ?></td>

                <!-- Website -->
                <td data-label="Website">
                    <?php if (!empty($obj->website)) {
                        $website_url = strpos($obj->website, 'http') === 0 ? $obj->website : 'http://' . $obj->website;
                    ?>
                    <a href="<?php echo $website_url; ?>" target="_blank" class="vl-website-link">
                        <i class="fa fa-external-link-alt" style="font-size:10px;opacity:0.6;"></i>
                        <?php echo dol_escape_htmltag(dol_trunc($obj->website, 24)); ?>
                    </a>
                    <?php } else { echo '<span style="color:#c4c9d8;">—</span>'; } ?>
                </td>

                <!-- Actions -->
                <td data-label="Actions">
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
                <td colspan="9">
                    <div class="vl-empty">
                        <div class="vl-empty-icon"><i class="fa fa-store"></i></div>
                        <p>No vendors found</p>
                        <?php if ($user->rights->flotte->write) { ?>
                        <a class="vl-btn vl-btn-primary" href="<?php echo dol_buildpath('/flotte/vendor_card.php', 1); ?>?action=create">
                            <i class="fa fa-plus"></i> Add First Vendor
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
            Showing <strong><?php echo $showing_from; ?></strong>–<strong><?php echo $showing_to; ?></strong> of <strong><?php echo $nbtotalofrecords; ?></strong> vendors
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
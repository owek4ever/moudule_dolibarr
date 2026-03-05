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
$search_driver = GETPOST('search_driver', 'alpha');
$search_customer = GETPOST('search_customer', 'alpha');
$search_status = GETPOST('search_status', 'alpha');
$search_date_from = GETPOST('search_date_from', 'alpha');
$search_date_to = GETPOST('search_date_to', 'alpha');
$search_vendor = GETPOST('search_vendor', 'alpha');
$search_invoiced_customer = GETPOST('search_invoiced_customer', 'alpha');
$search_invoiced_vendor   = GETPOST('search_invoiced_vendor', 'alpha');

$limit = GETPOST('limit', 'int') ? GETPOST('limit', 'int') : $conf->liste_limit;
$sortfield = GETPOST('sortfield', 'aZ09comma');
$sortorder = GETPOST('sortorder', 'aZ09comma');
$page = GETPOSTISSET('pageplusone') ? (GETPOST('pageplusone') - 1) : GETPOST("page", 'int');

if (empty($page) || $page == -1) {
    $page = 0;
}

$offset = $limit * $page;

if (!$sortfield) {
    $sortfield = "t.booking_date";
}
if (!$sortorder) {
    $sortorder = "DESC";
}

// Initialize technical objects
$form = new Form($db);
$formcompany = new FormCompany($db);
$hookmanager->initHooks(array('bookinglist', 'globalcard'));

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
    $search_driver = '';
    $search_customer = '';
    $search_status = '';
    $search_date_from = '';
    $search_date_to = '';
    $search_vendor = '';
    $search_invoiced_customer = '';
    $search_invoiced_vendor   = '';
}

// Handle confirmed delete from list page
if ($action == 'confirm_delete' && GETPOST('confirm', 'alpha') == 'yes') {
    $id_to_delete = GETPOST('id', 'int');
    if ($id_to_delete > 0) {
        $db->begin();
        $sql_del = "DELETE FROM ".MAIN_DB_PREFIX."flotte_booking WHERE rowid = ".(int)$id_to_delete." AND entity IN (".getEntity('flotte').")";
        $resql_del = $db->query($sql_del);
        if ($resql_del) {
            // Delete associated files if any
            $uploadDir = $conf->flotte->dir_output . '/bookings/' . $id_to_delete . '/';
            if (is_dir($uploadDir)) {
                dol_delete_dir_recursive($uploadDir);
            }
            $db->commit();
            setEventMessages($langs->trans("BookingDeletedSuccessfully"), null, 'mesgs');
        } else {
            $db->rollback();
            setEventMessages("Error in SQL: ".$db->lasterror(), null, 'errors');
        }
    }
    $action = 'list';
}

// ── Invoiced lookup (must run before main SQL for filter support) ────────
$invoiced_customer_ids = array();
$invoiced_vendor_ids   = array();

$_ri = $db->query("SELECT note_private FROM ".MAIN_DB_PREFIX."facture WHERE note_private LIKE '%FLOTTE_BOOKING_IDS:%' AND fk_statut >= 1 AND entity IN (".getEntity('facture').")");
if ($_ri) { while ($_inv = $db->fetch_object($_ri)) { if (preg_match('/FLOTTE_BOOKING_IDS:([\d,]+)/', $_inv->note_private, $_m)) { foreach (explode(',', $_m[1]) as $_bid) { $_bid = (int)trim($_bid); if ($_bid > 0) $invoiced_customer_ids[$_bid] = true; } } } }

$_ri2 = $db->query("SELECT note_private FROM ".MAIN_DB_PREFIX."facture_fourn WHERE note_private LIKE '%FLOTTE_VENDOR_BOOKING_IDS:%' AND fk_statut >= 1 AND entity IN (".getEntity('facture_fourn').")");
if ($_ri2) { while ($_inv = $db->fetch_object($_ri2)) { if (preg_match('/FLOTTE_VENDOR_BOOKING_IDS:([\d,]+)/', $_inv->note_private, $_m)) { foreach (explode(',', $_m[1]) as $_bid) { $_bid = (int)trim($_bid); if ($_bid > 0) $invoiced_vendor_ids[$_bid] = true; } } } }
// ─────────────────────────────────────────────────────────────────────────────

// Build and execute select
$sql = 'SELECT t.rowid, t.ref, t.booking_date, t.status, t.distance, t.arriving_address, t.departure_address, t.buying_amount, t.selling_amount, IFNULL(t.selling_amount_ttc, t.selling_amount) as selling_amount_ttc, IFNULL(t.buying_amount_ttc, t.buying_amount) as buying_amount_ttc,';
$sql .= ' t.fk_customer, t.fk_vendor,';
$sql .= ' v.ref as vehicle_ref, v.maker, v.model, v.license_plate,';
$sql .= ' d.firstname as driver_firstname, d.lastname as driver_lastname,';
$sql .= ' c.firstname as customer_firstname, c.lastname as customer_lastname, c.company_name,';
$sql .= ' vn.name as vendor_name';
$sql .= ' FROM '.MAIN_DB_PREFIX.'flotte_booking as t';
$sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'flotte_vehicle as v ON t.fk_vehicle = v.rowid';
$sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'flotte_driver as d ON t.fk_driver = d.rowid';
$sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'flotte_customer as c ON t.fk_customer = c.rowid';
$sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'flotte_vendor as vn ON t.fk_vendor = vn.rowid';
$sql .= ' WHERE 1 = 1';
$sql .= ' AND t.entity IN ('.getEntity('flotte').')';

if ($search_ref) {
    $sql .= " AND t.ref LIKE '%".$db->escape($search_ref)."%'";
}
if ($search_vehicle) {
    $sql .= " AND (v.ref LIKE '%".$db->escape($search_vehicle)."%' OR v.maker LIKE '%".$db->escape($search_vehicle)."%' OR v.model LIKE '%".$db->escape($search_vehicle)."%')";
}
if ($search_driver) {
    $sql .= " AND (d.firstname LIKE '%".$db->escape($search_driver)."%' OR d.lastname LIKE '%".$db->escape($search_driver)."%')";
}
if ($search_customer) {
    $sql .= " AND (c.firstname LIKE '%".$db->escape($search_customer)."%' OR c.lastname LIKE '%".$db->escape($search_customer)."%' OR c.company_name LIKE '%".$db->escape($search_customer)."%')";
}
if ($search_status) {
    $sql .= " AND t.status = '".$db->escape($search_status)."'";
}
if ($search_date_from) {
    $sql .= " AND t.booking_date >= '".$db->escape($search_date_from)."'";
}
if ($search_vendor) {
    $sql .= " AND vn.name LIKE '%".$db->escape($search_vendor)."%'";
}
if ($search_date_to) {
    $sql .= " AND t.booking_date <= '".$db->escape($search_date_to)."'";
}
if ($search_invoiced_customer === '1') {
    $ids = !empty($invoiced_customer_ids) ? implode(',', array_keys($invoiced_customer_ids)) : '0';
    $sql .= " AND t.rowid IN (".$ids.")";
} elseif ($search_invoiced_customer === '0') {
    $ids = !empty($invoiced_customer_ids) ? implode(',', array_keys($invoiced_customer_ids)) : '0';
    $sql .= " AND t.rowid NOT IN (".$ids.")";
}
if ($search_invoiced_vendor === '1') {
    $ids = !empty($invoiced_vendor_ids) ? implode(',', array_keys($invoiced_vendor_ids)) : '0';
    $sql .= " AND t.rowid IN (".$ids.")";
} elseif ($search_invoiced_vendor === '0') {
    $ids = !empty($invoiced_vendor_ids) ? implode(',', array_keys($invoiced_vendor_ids)) : '0';
    $sql .= " AND t.rowid NOT IN (".$ids.")";
}

$sql .= $db->order($sortfield, $sortorder);

// Count total nb of records
$sqlcount = preg_replace('/SELECT .+ FROM /s', 'SELECT COUNT(*) as nb FROM ', $sql);
$sqlcount = preg_replace('/ ORDER BY.+$/s', '', $sqlcount);
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
if (!empty($search_ref))        $param .= '&search_ref='.urlencode($search_ref);
if (!empty($search_vehicle))    $param .= '&search_vehicle='.urlencode($search_vehicle);
if (!empty($search_driver))     $param .= '&search_driver='.urlencode($search_driver);
if (!empty($search_customer))   $param .= '&search_customer='.urlencode($search_customer);
if (!empty($search_status))     $param .= '&search_status='.urlencode($search_status);
if (!empty($search_vendor))    $param .= '&search_vendor='.urlencode($search_vendor);
if (!empty($search_date_from))  $param .= '&search_date_from='.urlencode($search_date_from);
if (!empty($search_date_to))    $param .= '&search_date_to='.urlencode($search_date_to);
if ($search_invoiced_customer !== '') $param .= '&search_invoiced_customer='.urlencode($search_invoiced_customer);
if ($search_invoiced_vendor   !== '') $param .= '&search_invoiced_vendor='.urlencode($search_invoiced_vendor);

// Page header
llxHeader('', $langs->trans("Bookings List"), '');

// Show delete confirmation dialog if requested
if ($action == 'delete') {
    $id_to_delete = GETPOST('id', 'int');
    if ($id_to_delete > 0) {
        $formconfirm = $form->formconfirm($_SERVER["PHP_SELF"].'?id='.$id_to_delete, $langs->trans('DeleteBooking'), $langs->trans('ConfirmDeleteBooking'), 'confirm_delete', '', 0, 1);
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
function bl_sortArrow($field, $sortfield, $sortorder) {
    if ($sortfield == $field) return $sortorder == 'ASC' ? ' <span class="vl-sort-arrow">↑</span>' : ' <span class="vl-sort-arrow">↓</span>';
    return ' <span class="vl-sort-arrow muted">↕</span>';
}
function bl_sortHref($field, $sortfield, $sortorder, $self, $param) {
    $dir = ($sortfield == $field && $sortorder == 'ASC') ? 'DESC' : 'ASC';
    return $self.'?sortfield='.$field.'&sortorder='.$dir.'&'.$param;
}

$self = $_SERVER["PHP_SELF"];

// Count by status
$cnt_pending = 0; $cnt_confirmed = 0; $cnt_inprogress = 0; $cnt_completed = 0; $cnt_cancelled = 0;
foreach ($rows as $r) {
    if ($r->status == 'pending')      $cnt_pending++;
    elseif ($r->status == 'confirmed')   $cnt_confirmed++;
    elseif ($r->status == 'in_progress') $cnt_inprogress++;
    elseif ($r->status == 'completed')   $cnt_completed++;
    elseif ($r->status == 'cancelled')   $cnt_cancelled++;
}
?>

<style>
@import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=DM+Mono:wght@400;500&display=swap');

html, body { overflow-x: hidden; max-width: 100%; }
.vl-wrap * { box-sizing: border-box; }
.vl-wrap {
    font-family: 'DM Sans', sans-serif;
    max-width: 100%; margin: 0 auto; padding: 0 4px 40px; color: #1a1f2e;
}

/* Header */
.vl-header {
    display: flex; align-items: center; justify-content: space-between;
    padding: 28px 0 24px; border-bottom: 1px solid #e8eaf0;
    margin-bottom: 24px; gap: 16px; flex-wrap: wrap;
}
.vl-header-left h1 { font-size: 22px; font-weight: 700; color: #1a1f2e; margin: 0 0 4px; letter-spacing: -0.3px; }
.vl-header-left .vl-subtitle { font-size: 13px; color: #7c859c; font-weight: 400; }
.vl-header-actions { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }

/* Buttons */
.vl-btn {
    display: inline-flex; align-items: center; gap: 7px; padding: 8px 16px;
    border-radius: 6px; font-size: 13px; font-weight: 600; text-decoration: none !important;
    transition: all 0.15s ease; border: none; cursor: pointer;
    font-family: 'DM Sans', sans-serif;
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
.vl-filter-group select:focus { border-color: #3c4758; box-shadow: 0 0 0 3px rgba(60,71,88,0.1); background: #fff; }
.vl-filter-actions { display: flex; gap: 8px; align-items: flex-end; padding-bottom: 1px; }
.vl-btn-filter {
    padding: 8px 16px; font-size: 13px; border-radius: 6px; font-weight: 600;
    border: none; cursor: pointer; font-family: 'DM Sans', sans-serif; transition: all 0.15s;
}
.vl-btn-filter.apply { background: #3c4758 !important; color: #fff !important; }
.vl-btn-filter.apply:hover { background: #2a3346 !important; }
.vl-btn-filter.reset { background: #3c4758 !important; color: #fff !important; }
.vl-btn-filter.reset:hover { background: #2a3346 !important; }

/* Stats chips */
.vl-stats { display: flex; gap: 10px; margin-bottom: 16px; flex-wrap: wrap; }
.vl-stat-chip {
    display: inline-flex; align-items: center; gap: 6px; padding: 6px 14px;
    border-radius: 20px; font-size: 12px; font-weight: 600; background: #f0f2fa; color: #5a6482;
}
.vl-stat-chip .vl-stat-num { font-size: 14px; font-weight: 700; color: #1a1f2e; }
.vl-stat-chip.confirmed  { background: #eff6ff; color: #1d4ed8; }
.vl-stat-chip.confirmed .vl-stat-num { color: #1d4ed8; }
.vl-stat-chip.inprogress { background: #edfaf3; color: #1a7d4a; }
.vl-stat-chip.inprogress .vl-stat-num { color: #1a7d4a; }
.vl-stat-chip.completed  { background: #f0fdf4; color: #15803d; }
.vl-stat-chip.completed .vl-stat-num { color: #15803d; }
.vl-stat-chip.pending    { background: #fff8ec; color: #b45309; }
.vl-stat-chip.pending .vl-stat-num { color: #b45309; }
.vl-stat-chip.cancelled  { background: #fef2f2; color: #b91c1c; }
.vl-stat-chip.cancelled .vl-stat-num { color: #b91c1c; }

/* Table */
.vl-table-card {
    background: #fff; border: 1px solid #e8eaf0; border-radius: 14px;
    box-shadow: 0 2px 12px rgba(0,0,0,0.05);
}
.vl-table-wrap { overflow-x: visible; }
table.vl-table { width: 100%; border-collapse: collapse; font-size: 12.5px; table-layout: auto; }
table.vl-table thead tr { background: #f7f8fc; border-bottom: 2px solid #e8eaf0; }
table.vl-table thead th {
    padding: 10px 10px; text-align: left; font-size: 11px; font-weight: 700;
    text-transform: uppercase; letter-spacing: 0.7px; color: #8b92a9;
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
table.vl-table tbody td { padding: 10px 10px; color: #2d3748; vertical-align: middle; }
table.vl-table tbody td.center { text-align: center; }
table.vl-table tbody td.right  { text-align: right; }

/* Ref link */
.vl-ref-link {
    display: inline-flex; align-items: center; gap: 8px; text-decoration: none;
    color: #3c4758; font-weight: 600; font-family: 'DM Mono', monospace; font-size: 13px; transition: color 0.15s;
}
.vl-ref-link:hover { color: #2a3346; text-decoration: none; }
.vl-ref-icon {
    width: 30px; height: 30px; background: rgba(60,71,88,0.08); border-radius: 8px;
    display: flex; align-items: center; justify-content: center; color: #3c4758; font-size: 14px; flex-shrink: 0;
}

/* Vehicle chip */
.vl-vehicle-chip {
    display: inline-flex; align-items: center; gap: 6px; font-size: 12.5px;
    font-weight: 600; color: #3c4758; background: rgba(60,71,88,0.07);
    padding: 4px 10px; border-radius: 6px;
}
/* Vendor chip */
.vl-vendor-chip {
    display: inline-flex; align-items: center; gap: 6px; font-size: 12.5px;
    font-weight: 600; color: #0f766e; background: rgba(15,118,110,0.07);
    padding: 4px 10px; border-radius: 6px;
}
.vl-sub { font-size: 11px; color: #9aa0b4; margin-top: 2px; }

/* Amount */
.vl-amount { font-weight: 700; color: #1a7d4a; font-size: 13.5px; }

/* Distance */
.vl-distance { font-weight: 600; color: #2d3748; font-size: 13px; }
.vl-distance-unit { font-size: 11px; color: #9aa0b4; font-weight: 400; margin-left: 2px; }

/* Date chip */
.vl-date-chip {
    display: inline-flex; align-items: center; gap: 5px; font-size: 12.5px;
    color: #4a5568; background: #f0f2fa; padding: 4px 10px; border-radius: 6px;
}

/* Invoiced badges */
.vl-invoiced-wrap { display: flex; flex-direction: column; gap: 4px; }
.vl-inv-badge {
    display: inline-flex; align-items: center; gap: 4px; padding: 3px 8px;
    border-radius: 20px; font-size: 11px; font-weight: 600;
}
.vl-inv-badge.customer { background: #ede9fe; color: #6d28d9; }
.vl-inv-badge.vendor   { background: #ccfbf1; color: #0f766e; }

/* Status badge */
.vl-badge {
    display: inline-flex; align-items: center; gap: 5px; padding: 4px 10px;
    border-radius: 20px; font-size: 11.5px; font-weight: 600;
}
.vl-badge::before { content: ''; width: 6px; height: 6px; border-radius: 50%; flex-shrink: 0; }
.vl-badge.pending     { background: #fff8ec; color: #b45309; }
.vl-badge.pending::before     { background: #f59e0b; }
.vl-badge.confirmed   { background: #eff6ff; color: #1d4ed8; }
.vl-badge.confirmed::before   { background: #3b82f6; }
.vl-badge.in-progress { background: #edfaf3; color: #1a7d4a; }
.vl-badge.in-progress::before { background: #22c55e; }
.vl-badge.completed   { background: #f0fdf4; color: #15803d; }
.vl-badge.completed::before   { background: #16a34a; }
.vl-badge.cancelled   { background: #fef2f2; color: #b91c1c; }
.vl-badge.cancelled::before   { background: #ef4444; }

/* Action buttons */
.vl-actions { display: flex; gap: 4px; justify-content: center; }
.vl-action-btn {
    width: 32px; height: 32px; border-radius: 8px; display: flex; align-items: center;
    justify-content: center; text-decoration: none; transition: all 0.15s; font-size: 13px; border: 1.5px solid transparent;
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
    display: inline-flex; align-items: center; justify-content: center; font-size: 13px;
    font-weight: 600; text-decoration: none; transition: all 0.15s;
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

    /* Hide Vendor, Distance and Amounts on tablet */
    table.vl-table th:nth-child(6),
    table.vl-table td:nth-child(6),
    table.vl-table th:nth-child(7),
    table.vl-table td:nth-child(7),
    table.vl-table th:nth-child(8),
    table.vl-table td:nth-child(8) { display: none; }
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

    /* Stats chips */
    .vl-stats { gap: 6px; }
    .vl-stat-chip { padding: 5px 10px; font-size: 11px; }

    /* Convert table to stacked card layout */
    

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

    /* Ref cell — no label, full left-align */
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

/* ══════════════════════════════════════
   INVOICE SELECTION MODE
══════════════════════════════════════ */
.vl-btn-invoice { background: #7c3aed !important; color: #fff !important; }
.vl-btn-invoice:hover { background: #6d28d9 !important; color: #fff !important; }
.vl-btn-invoice.active { background: #6d28d9 !important; box-shadow: 0 0 0 3px rgba(124,58,237,0.25) !important; }

/* Checkbox column */
.vl-check-col { width: 44px; text-align: center !important; padding: 0 8px !important; }
.vl-row-check {
    width: 18px; height: 18px; cursor: pointer; accent-color: #7c3aed;
    border-radius: 4px; border: 2px solid #c4c9d8;
}
.vl-select-all { width: 18px; height: 18px; cursor: pointer; accent-color: #7c3aed; }
table.vl-table tbody tr.vl-selected { background: #faf5ff !important; }
table.vl-table tbody tr.vl-selected td { border-bottom-color: #e9d5ff !important; }

/* Sticky invoice action bar */
.vl-invoice-bar {
    position: fixed; bottom: -100px; left: 0; right: 0; z-index: 9999;
    background: #1a1f2e; color: #fff;
    padding: 14px 28px; display: flex; align-items: center; gap: 16px;
    box-shadow: 0 -4px 24px rgba(0,0,0,0.18); flex-wrap: wrap;
    transition: bottom 0.3s cubic-bezier(0.34,1.56,0.64,1);
}
.vl-invoice-bar.visible { bottom: 0; }
.vl-invoice-bar-info { font-size: 13.5px; font-weight: 600; color: #a5b4fc; margin-right: auto; }
.vl-invoice-bar-info strong { color: #fff; font-size: 15px; }
.vl-inv-btn {
    display: inline-flex; align-items: center; gap: 8px; padding: 10px 20px;
    border-radius: 8px; font-size: 13.5px; font-weight: 700; cursor: pointer;
    border: none; font-family: 'DM Sans', sans-serif; transition: all 0.15s;
}
.vl-inv-btn-customer { background: #7c3aed; color: #fff; }
.vl-inv-btn-customer:hover { background: #6d28d9; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(124,58,237,0.4); }
.vl-inv-btn-vendor { background: #0f766e; color: #fff; }
.vl-inv-btn-vendor:hover { background: #0d6b63; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(15,118,110,0.4); }
.vl-inv-btn-cancel { background: rgba(255,255,255,0.08); color: #e2e8f0; border: 1.5px solid rgba(255,255,255,0.15); }
.vl-inv-btn-cancel:hover { background: rgba(255,255,255,0.15); }
</style>

<meta name="viewport" content="width=device-width, initial-scale=1.0">

<div class="vl-wrap">

<!-- Header -->
<div class="vl-header">
    <div class="vl-header-left">
        <h1><i class="fa fa-calendar-check" style="color:#3c4758;margin-right:10px;"></i><?php echo $langs->trans("Bookings List"); ?></h1>
        <div class="vl-subtitle"><?php echo $nbtotalofrecords; ?> booking<?php echo $nbtotalofrecords != 1 ? 's' : ''; ?> found</div>
    </div>
    <div class="vl-header-actions">
        <?php if ($user->rights->flotte->read) { ?>
        <button type="button" class="vl-btn vl-btn-invoice" id="btn-invoice-mode" onclick="toggleInvoiceMode()">
            <i class="fa fa-file-invoice"></i> Invoice
        </button>
        <a class="vl-btn vl-btn-secondary" href="<?php echo dol_buildpath('/flotte/booking_list.php', 1); ?>?action=export">
            <i class="fa fa-download"></i> Export
        </a>
        <?php } ?>
        <?php if ($user->rights->flotte->write) { ?>
        <a class="vl-btn vl-btn-primary" href="<?php echo dol_buildpath('/flotte/booking_card.php', 1); ?>?action=create">
            <i class="fa fa-plus"></i> New Booking
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
        <label>Driver</label>
        <input type="text" name="search_driver" placeholder="First or last name…" value="<?php echo dol_escape_htmltag($search_driver); ?>">
    </div>
    <div class="vl-filter-group">
        <label>Customer</label>
        <input type="text" name="search_customer" placeholder="Name or company…" value="<?php echo dol_escape_htmltag($search_customer); ?>">
    </div>
    <div class="vl-filter-group">
        <label>Vendor</label>
        <input type="text" name="search_vendor" placeholder="Vendor name…" value="<?php echo dol_escape_htmltag($search_vendor); ?>">
    </div>
    <div class="vl-filter-group" style="max-width:120px;">
        <label>Date From</label>
        <input type="date" name="search_date_from" value="<?php echo dol_escape_htmltag($search_date_from); ?>">
    </div>
    <div class="vl-filter-group" style="max-width:120px;">
        <label>Date To</label>
        <input type="date" name="search_date_to" value="<?php echo dol_escape_htmltag($search_date_to); ?>">
    </div>
    <div class="vl-filter-group" style="max-width:150px;">
        <label>Status</label>
        <select name="search_status">
            <option value="">All statuses</option>
            <option value="pending"     <?php echo $search_status === 'pending'     ? 'selected' : ''; ?>>Pending</option>
            <option value="confirmed"   <?php echo $search_status === 'confirmed'   ? 'selected' : ''; ?>>Confirmed</option>
            <option value="in_progress" <?php echo $search_status === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
            <option value="completed"   <?php echo $search_status === 'completed'   ? 'selected' : ''; ?>>Completed</option>
            <option value="cancelled"   <?php echo $search_status === 'cancelled'   ? 'selected' : ''; ?>>Cancelled</option>
        </select>
    </div>
    <div class="vl-filter-group" style="max-width:155px;">
        <label>Customer Invoiced</label>
        <select name="search_invoiced_customer">
            <option value="">All</option>
            <option value="1" <?php echo $search_invoiced_customer === '1' ? 'selected' : ''; ?>>Invoiced</option>
            <option value="0" <?php echo $search_invoiced_customer === '0' ? 'selected' : ''; ?>>Not invoiced</option>
        </select>
    </div>
    <div class="vl-filter-group" style="max-width:155px;">
        <label>Vendor Invoiced</label>
        <select name="search_invoiced_vendor">
            <option value="">All</option>
            <option value="1" <?php echo $search_invoiced_vendor === '1' ? 'selected' : ''; ?>>Invoiced</option>
            <option value="0" <?php echo $search_invoiced_vendor === '0' ? 'selected' : ''; ?>>Not invoiced</option>
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
    <?php if ($cnt_pending > 0) { ?><div class="vl-stat-chip pending"><span class="vl-stat-num"><?php echo $cnt_pending; ?></span> Pending</div><?php } ?>
    <?php if ($cnt_confirmed > 0) { ?><div class="vl-stat-chip confirmed"><span class="vl-stat-num"><?php echo $cnt_confirmed; ?></span> Confirmed</div><?php } ?>
    <?php if ($cnt_inprogress > 0) { ?><div class="vl-stat-chip inprogress"><span class="vl-stat-num"><?php echo $cnt_inprogress; ?></span> In Progress</div><?php } ?>
    <?php if ($cnt_completed > 0) { ?><div class="vl-stat-chip completed"><span class="vl-stat-num"><?php echo $cnt_completed; ?></span> Completed</div><?php } ?>
    <?php if ($cnt_cancelled > 0) { ?><div class="vl-stat-chip cancelled"><span class="vl-stat-num"><?php echo $cnt_cancelled; ?></span> Cancelled</div><?php } ?>
</div>

<!-- Table -->
<div class="vl-table-card">
    <div class="vl-table-wrap">
    <table class="vl-table">
        <thead>
            <tr>
                <th class="vl-check-col" id="check-col-header" style="display:none;">
                    <input type="checkbox" class="vl-select-all" id="select-all" title="Select all">
                </th>
                <th><a href="<?php echo bl_sortHref('t.ref', $sortfield, $sortorder, $self, $param); ?>">Ref <?php echo bl_sortArrow('t.ref', $sortfield, $sortorder); ?></a></th>
                <th><a href="<?php echo bl_sortHref('v.ref', $sortfield, $sortorder, $self, $param); ?>">Vehicle <?php echo bl_sortArrow('v.ref', $sortfield, $sortorder); ?></a></th>
                <th><a href="<?php echo bl_sortHref('d.lastname', $sortfield, $sortorder, $self, $param); ?>">Driver <?php echo bl_sortArrow('d.lastname', $sortfield, $sortorder); ?></a></th>
                <th><a href="<?php echo bl_sortHref('c.lastname', $sortfield, $sortorder, $self, $param); ?>">Customer <?php echo bl_sortArrow('c.lastname', $sortfield, $sortorder); ?></a></th>
                <th><a href="<?php echo bl_sortHref('vn.name', $sortfield, $sortorder, $self, $param); ?>">Vendor <?php echo bl_sortArrow('vn.name', $sortfield, $sortorder); ?></a></th>
                <th><a href="<?php echo bl_sortHref('t.booking_date', $sortfield, $sortorder, $self, $param); ?>">Date <?php echo bl_sortArrow('t.booking_date', $sortfield, $sortorder); ?></a></th>
                <th class="right"><a href="<?php echo bl_sortHref('t.distance', $sortfield, $sortorder, $self, $param); ?>">Distance <?php echo bl_sortArrow('t.distance', $sortfield, $sortorder); ?></a></th>
                <th class="right"><a href="<?php echo bl_sortHref('t.selling_amount', $sortfield, $sortorder, $self, $param); ?>">Selling <?php echo bl_sortArrow('t.selling_amount', $sortfield, $sortorder); ?></a></th>
                <th class="right"><a href="<?php echo bl_sortHref('t.buying_amount', $sortfield, $sortorder, $self, $param); ?>">Buying <?php echo bl_sortArrow('t.buying_amount', $sortfield, $sortorder); ?></a></th>
                <th class="center">Invoiced</th>
                <th class="center"><a href="<?php echo bl_sortHref('t.status', $sortfield, $sortorder, $self, $param); ?>">Status <?php echo bl_sortArrow('t.status', $sortfield, $sortorder); ?></a></th>
                <th class="center">Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php if (!empty($rows)) {
            foreach ($rows as $obj) {
                $cardUrl = dol_buildpath('/flotte/booking_card.php', 1).'?id='.$obj->rowid;
                // Build customer display
                $customerName = trim($obj->customer_firstname.' '.$obj->customer_lastname);
                $driverName   = trim($obj->driver_firstname.' '.$obj->driver_lastname);
                // Status css class
                $statusClass = str_replace('_', '-', $obj->status ?: 'pending');
        ?>
            <tr data-id="<?php echo $obj->rowid; ?>"
                data-ref="<?php echo dol_escape_htmltag($obj->ref); ?>"
                data-customer-id="<?php echo (int)$obj->fk_customer; ?>"
                data-vendor-id="<?php echo (int)$obj->fk_vendor; ?>"
                data-customer-name="<?php echo dol_escape_htmltag(trim($customerName.' '.($obj->company_name ? '('.$obj->company_name.')' : ''))); ?>">

                <!-- Checkbox (invoice mode) -->
                <td class="vl-check-col" style="display:none;" data-label="">
                    <input type="checkbox" class="vl-row-check" value="<?php echo $obj->rowid; ?>">
                </td>
                <!-- Ref -->
                <td data-label="Ref">
                    <a href="<?php echo $cardUrl; ?>" class="vl-ref-link">
                        <span class="vl-ref-icon"><i class="fa fa-calendar"></i></span>
                        <?php echo dol_escape_htmltag($obj->ref); ?>
                    </a>
                </td>

                <!-- Vehicle -->
                <td data-label="Vehicle">
                    <?php if (!empty($obj->vehicle_ref)) { ?>
                    <div class="vl-vehicle-chip">
                        <i class="fa fa-car" style="font-size:11px;opacity:0.6;"></i>
                        <?php echo dol_escape_htmltag($obj->vehicle_ref); ?>
                    </div>
                    <?php if ($obj->maker || $obj->model) { ?>
                    <div class="vl-sub"><?php echo dol_escape_htmltag(trim($obj->maker.' '.$obj->model)); ?></div>
                    <?php } ?>
                    <?php } else { echo '<span style="color:#c4c9d8;">—</span>'; } ?>
                </td>

                <!-- Driver -->
                <td data-label="Driver">
                    <?php if (!empty($driverName)) { ?>
                    <div style="font-weight:500;"><?php echo dol_escape_htmltag($driverName); ?></div>
                    <?php } else { echo '<span style="color:#c4c9d8;">—</span>'; } ?>
                </td>

                <!-- Customer -->
                <td data-label="Customer">
                    <?php if (!empty($customerName) || !empty($obj->company_name)) { ?>
                    <?php if (!empty($customerName)) { ?>
                    <div style="font-weight:500;"><?php echo dol_escape_htmltag($customerName); ?></div>
                    <?php } ?>
                    <?php if (!empty($obj->company_name)) { ?>
                    <div class="vl-sub"><?php echo dol_escape_htmltag($obj->company_name); ?></div>
                    <?php } ?>
                    <?php } else { echo '<span style="color:#c4c9d8;">—</span>'; } ?>
                </td>

                <!-- Vendor -->
                <td data-label="Vendor">
                    <?php if (!empty($obj->vendor_name)) { ?>
                    <div class="vl-vendor-chip">
                        <i class="fa fa-store" style="font-size:11px;opacity:0.6;"></i>
                        <?php echo dol_escape_htmltag($obj->vendor_name); ?>
                    </div>
                    <?php } else { echo '<span style="color:#c4c9d8;">—</span>'; } ?>
                </td>

                <!-- Date -->
                <td data-label="Date">
                    <span class="vl-date-chip">
                        <i class="fa fa-calendar-day" style="font-size:11px;opacity:0.6;"></i>
                        <?php echo dol_print_date($db->jdate($obj->booking_date), 'day'); ?>
                    </span>
                </td>

                <!-- Distance -->
                <td class="right" data-label="Distance">
                    <?php if ($obj->distance) { ?>
                    <span class="vl-distance"><?php echo number_format((int)$obj->distance, 0, '.', ' '); ?><span class="vl-distance-unit">km</span></span>
                    <?php } else { echo '<span style="color:#c4c9d8;">—</span>'; } ?>
                </td>

                <!-- Selling Amount -->
                <td class="right" data-label="Selling">
                    <?php if ($obj->selling_amount_ttc) { ?>
                    <span class="vl-amount"><?php echo price($obj->selling_amount_ttc); ?></span>
                    <?php } else { echo '<span style="color:#c4c9d8;">—</span>'; } ?>
                </td>

                <!-- Buying Amount -->
                <td class="right" data-label="Buying">
                    <?php if ($obj->buying_amount_ttc) { ?>
                    <span class="vl-amount" style="color:#0f766e;"><?php echo price($obj->buying_amount_ttc); ?></span>
                    <?php } else { echo '<span style="color:#c4c9d8;">—</span>'; } ?>
                </td>

                <!-- Invoiced -->
                <td class="center" data-label="Invoiced">
                    <?php
                    $is_inv_cust = !empty($invoiced_customer_ids[$obj->rowid]);
                    $is_inv_vend = !empty($invoiced_vendor_ids[$obj->rowid]);
                    if ($is_inv_cust || $is_inv_vend) { ?>
                    <div class="vl-invoiced-wrap">
                        <?php if ($is_inv_cust) { ?><span class="vl-inv-badge customer"><i class="fa fa-check" style="font-size:9px;"></i> Customer</span><?php } ?>
                        <?php if ($is_inv_vend) { ?><span class="vl-inv-badge vendor"><i class="fa fa-check" style="font-size:9px;"></i> Vendor</span><?php } ?>
                    </div>
                    <?php } else { echo '<span style="color:#c4c9d8;">—</span>'; } ?>
                </td>

                <!-- Status -->
                <td class="center" data-label="Status">
                    <?php
                    $labels = array(
                        'pending'     => 'Pending',
                        'confirmed'   => 'Confirmed',
                        'in_progress' => 'In Progress',
                        'completed'   => 'Completed',
                        'cancelled'   => 'Cancelled',
                    );
                    $label = isset($labels[$obj->status]) ? $labels[$obj->status] : ucfirst($obj->status ?: 'Unknown');
                    echo '<span class="vl-badge '.$statusClass.'">'.$label.'</span>';
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
                        <a href="<?php echo dol_buildpath('/flotte/booking_list.php', 1); ?>?action=delete&id=<?php echo $obj->rowid; ?>&token=<?php echo newToken(); ?>" class="vl-action-btn del" title="Delete"><i class="fa fa-trash"></i></a>
                        <?php } ?>
                    </div>
                </td>
            </tr>
        <?php }} else { ?>
            <tr>
                <td colspan="10">
                    <div class="vl-empty">
                        <div class="vl-empty-icon"><i class="fa fa-calendar-check"></i></div>
                        <p>No bookings found</p>
                        <?php if ($user->rights->flotte->write) { ?>
                        <a class="vl-btn vl-btn-primary" href="<?php echo dol_buildpath('/flotte/booking_card.php', 1); ?>?action=create">
                            <i class="fa fa-plus"></i> Add First Booking
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
            Showing <strong><?php echo $showing_from; ?></strong>–<strong><?php echo $showing_to; ?></strong> of <strong><?php echo $nbtotalofrecords; ?></strong> bookings
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

<!-- Sticky Invoice Action Bar -->
<div class="vl-invoice-bar" id="invoice-bar">
    <div class="vl-invoice-bar-info">
        <strong id="inv-count">0</strong> booking<span id="inv-plural">s</span> selected
    </div>
    <button type="button" class="vl-inv-btn vl-inv-btn-customer" onclick="createInvoice('customer')">
        <i class="fa fa-user-tie"></i> Customer Invoice
    </button>
    <button type="button" class="vl-inv-btn vl-inv-btn-vendor" onclick="createInvoice('vendor')">
        <i class="fa fa-store"></i> Vendor Invoice
    </button>
    <button type="button" class="vl-inv-btn vl-inv-btn-cancel" onclick="toggleInvoiceMode()">
        <i class="fa fa-times"></i> Cancel
    </button>
</div>

<!-- Hidden form for invoice submission -->
<form id="invoice-form" method="POST" action="<?php echo dol_buildpath('/flotte/booking_invoice.php', 1); ?>">
    <input type="hidden" name="token" value="<?php echo newToken(); ?>">
    <input type="hidden" name="invoice_type" id="invoice-type-input" value="">
    <input type="hidden" name="booking_ids" id="invoice-booking-ids" value="">
</form>

<script>
var invoiceMode = false;

function toggleInvoiceMode() {
    invoiceMode = !invoiceMode;
    var btn     = document.getElementById('btn-invoice-mode');
    var bar     = document.getElementById('invoice-bar');
    var hdrCol  = document.getElementById('check-col-header');
    var checkCells = document.querySelectorAll('.vl-check-col');
    var checks  = document.querySelectorAll('.vl-row-check');

    if (invoiceMode) {
        btn.classList.add('active');
        bar.classList.add('visible');
        hdrCol.style.display = '';
        checkCells.forEach(function(el) { el.style.display = ''; });
    } else {
        btn.classList.remove('active');
        bar.classList.remove('visible');
        hdrCol.style.display = 'none';
        checkCells.forEach(function(el) { el.style.display = 'none'; });
        checks.forEach(function(cb) {
            cb.checked = false;
            cb.closest('tr').classList.remove('vl-selected');
        });
        document.getElementById('select-all').checked = false;
        updateCount();
    }
}

function updateCount() {
    var n = document.querySelectorAll('.vl-row-check:checked').length;
    document.getElementById('inv-count').textContent = n;
    document.getElementById('inv-plural').textContent = n === 1 ? '' : 's';
}

// Row checkbox toggle
document.querySelectorAll('.vl-row-check').forEach(function(cb) {
    cb.addEventListener('change', function() {
        this.closest('tr').classList.toggle('vl-selected', this.checked);
        updateCount();
        // update select-all indeterminate state
        var all = document.querySelectorAll('.vl-row-check');
        var checked = document.querySelectorAll('.vl-row-check:checked');
        var sa = document.getElementById('select-all');
        sa.checked = checked.length === all.length;
        sa.indeterminate = checked.length > 0 && checked.length < all.length;
    });
});

// Clicking a row in invoice mode selects it
document.querySelectorAll('table.vl-table tbody tr').forEach(function(tr) {
    tr.addEventListener('click', function(e) {
        if (!invoiceMode) return;
        if (e.target.tagName === 'A' || e.target.tagName === 'INPUT') return;
        var cb = tr.querySelector('.vl-row-check');
        if (cb) { cb.checked = !cb.checked; cb.dispatchEvent(new Event('change')); }
    });
});

// Select all
document.getElementById('select-all').addEventListener('change', function() {
    document.querySelectorAll('.vl-row-check').forEach(function(cb) {
        cb.checked = document.getElementById('select-all').checked;
        cb.closest('tr').classList.toggle('vl-selected', cb.checked);
    });
    updateCount();
});

function createInvoice(type) {
    var checked = document.querySelectorAll('.vl-row-check:checked');
    if (checked.length === 0) {
        alert('Please select at least one booking.');
        return;
    }
    var ids = [];
    checked.forEach(function(cb) {
        ids.push(cb.closest('tr').dataset.id);
    });
    document.getElementById('invoice-type-input').value = type;
    document.getElementById('invoice-booking-ids').value = ids.join(',');
    document.getElementById('invoice-form').submit();
}
</script>

<?php
if ($resql) { $db->free($resql); }
llxFooter();
$db->close();
?>
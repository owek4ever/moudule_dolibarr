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

$search_ref    = GETPOST('search_ref',    'alpha');
$search_name   = GETPOST('search_name',   'alpha');
$search_phone  = GETPOST('search_phone',  'alpha');
$search_email  = GETPOST('search_email',  'alpha');
$search_siren  = GETPOST('search_siren',  'alpha');
$search_town   = GETPOST('search_town',   'alpha');
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
    $sortfield = "t.nom";
}
if (!$sortorder) {
    $sortorder = "ASC";
}

// Initialize technical objects
$form = new Form($db);
$formcompany = new FormCompany($db);
$hookmanager->initHooks(array('customerlist', 'globalcard'));

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
    $search_ref    = '';
    $search_name   = '';
    $search_phone  = '';
    $search_email  = '';
    $search_siren  = '';
    $search_town   = '';
    $search_status = '';
}

// ── (Import / Template actions removed — customers sourced from third-party module) ──

// Build and execute select

// Build and execute select — customers from the third-party (societe) module
$sql  = 'SELECT t.rowid, t.code_client AS ref, t.nom, t.phone, t.email, t.siren, t.town, t.zip, t.status';
$sql .= ' FROM '.MAIN_DB_PREFIX.'societe AS t';
$sql .= ' WHERE t.client IN (1, 2)';
$sql .= ' AND t.entity IN ('.getEntity('societe').')';

if ($search_ref) {
    $sql .= " AND t.code_client LIKE '%".$db->escape($search_ref)."%'";
}
if ($search_name) {
    $sql .= " AND t.nom LIKE '%".$db->escape($search_name)."%'";
}
if ($search_phone) {
    $sql .= " AND t.phone LIKE '%".$db->escape($search_phone)."%'";
}
if ($search_email) {
    $sql .= " AND t.email LIKE '%".$db->escape($search_email)."%'";
}
if ($search_siren) {
    $sql .= " AND t.siren LIKE '%".$db->escape($search_siren)."%'";
}
if ($search_town) {
    $sql .= " AND t.town LIKE '%".$db->escape($search_town)."%'";
}
if ($search_status !== '') {
    $sql .= " AND t.status = ".(int)$search_status;
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
if (!empty($search_ref))    $param .= '&search_ref='.urlencode($search_ref);
if (!empty($search_name))   $param .= '&search_name='.urlencode($search_name);
if (!empty($search_phone))  $param .= '&search_phone='.urlencode($search_phone);
if (!empty($search_email))  $param .= '&search_email='.urlencode($search_email);
if (!empty($search_siren))  $param .= '&search_siren='.urlencode($search_siren);
if (!empty($search_town))   $param .= '&search_town='.urlencode($search_town);
if ($search_status !== '')  $param .= '&search_status='.urlencode($search_status);

// Page header
llxHeader('', $langs->trans("Customers List"), '');

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

// Count active / inactive
$cnt_active   = 0;
$cnt_inactive = 0;
foreach ($rows as $r) {
    if ($r->status == 1) $cnt_active++;
    else $cnt_inactive++;
}

// Sort helpers
function cl_sortArrow($field, $sortfield, $sortorder) {
    if ($sortfield == $field) return $sortorder == 'ASC' ? ' <span class="vl-sort-arrow">↑</span>' : ' <span class="vl-sort-arrow">↓</span>';
    return ' <span class="vl-sort-arrow muted">↕</span>';
}
function cl_sortHref($field, $sortfield, $sortorder, $self, $param) {
    $dir = ($sortfield == $field && $sortorder == 'ASC') ? 'DESC' : 'ASC';
    return $self.'?sortfield='.$field.'&sortorder='.$dir.'&'.$param;
}

$self = $_SERVER["PHP_SELF"];
?>

<style>
@import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=DM+Mono:wght@400;500&display=swap');

.vl-wrap * { box-sizing: border-box; }

.vl-wrap {
    font-family: 'DM Sans', sans-serif;
    max-width: 100%;
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
.vl-stat-chip.company { background: #eff6ff; color: #1d4ed8; }
.vl-stat-chip.company .vl-stat-num { color: #1d4ed8; }

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

/* Customer name */
.vl-customer-name { font-weight: 600; color: #1a1f2e; font-size: 13.5px; }
.vl-customer-sub  { font-size: 11.5px; color: #9aa0b4; margin-top: 2px; }

/* Company chip */
.vl-company-chip {
    display: inline-flex; align-items: center; gap: 6px;
    font-size: 12.5px; font-weight: 600; color: #1d4ed8;
    background: #eff6ff; padding: 4px 10px; border-radius: 6px;
}

/* Mono */
.vl-mono {
    font-family: 'DM Mono', monospace; font-size: 12px; color: #4a5568;
    background: #f0f2fa; padding: 3px 8px; border-radius: 5px; display: inline-block;
}

/* Payment delay */
.vl-delay {
    display: inline-flex; align-items: baseline; gap: 4px;
    font-weight: 600; color: #2d3748; font-size: 13px;
}
.vl-delay-unit { font-size: 11px; color: #9aa0b4; font-weight: 400; }

/* Gender badge */
.vl-badge {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 4px 10px; border-radius: 20px;
    font-size: 11.5px; font-weight: 600; white-space: nowrap;
}
.vl-badge::before { content: ''; width: 6px; height: 6px; border-radius: 50%; flex-shrink: 0; }
.vl-badge.male   { background: #eff6ff; color: #1d4ed8; }
.vl-badge.male::before { background: #3b82f6; }
.vl-badge.female { background: #fdf2f8; color: #9d174d; }
.vl-badge.female::before { background: #ec4899; }
.vl-badge.other  { background: #f5f3ff; color: #5b21b6; }
.vl-badge.other::before  { background: #8b5cf6; }

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

    /* Hide Tax No. and Payment Delay columns */
    table.vl-table th:nth-child(5),
    table.vl-table td:nth-child(5),
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

/* ── Import button ── */
.vl-btn-import {
    background: #3c4758 !important;
    color: #fff !important;
    border: none !important;
}
.vl-btn-import:hover {
    background: #2a3346 !important;
    color: #fff !important;
    text-decoration: none !important;
}

/* ── Import Modal ── */
.vl-modal-overlay {
    display: none; position: fixed; inset: 0;
    background: rgba(15,20,35,0.45); backdrop-filter: blur(3px);
    z-index: 9999; align-items: center; justify-content: center; padding: 16px;
}
.vl-modal-overlay.open { display: flex; }
.vl-modal {
    background: #fff; border-radius: 14px; width: 100%; max-width: 560px;
    box-shadow: 0 20px 60px rgba(0,0,0,0.18);
    font-family: 'DM Sans', sans-serif; overflow: hidden;
    animation: clModalIn 0.18s ease;
}
@keyframes clModalIn {
    from { opacity:0; transform: translateY(-14px) scale(0.97); }
    to   { opacity:1; transform: translateY(0) scale(1); }
}
.vl-modal-header {
    display: flex; align-items: center; justify-content: space-between;
    padding: 18px 22px 16px; border-bottom: 1px solid #eaecf5; background: #f7f8fc;
}
.vl-modal-header-left { display: flex; align-items: center; gap: 11px; }
.vl-modal-icon {
    width: 38px; height: 38px; border-radius: 10px;
    background: rgba(60,71,88,0.1); display: flex; align-items: center;
    justify-content: center; color: #3c4758; font-size: 16px; flex-shrink: 0;
}
.vl-modal-title { font-size: 15px; font-weight: 700; color: #1a1f2e; margin: 0; }
.vl-modal-sub   { font-size: 12px; color: #9aa0b4; margin: 2px 0 0; }
.vl-modal-close {
    background: none; border: none; cursor: pointer; color: #9aa0b4;
    font-size: 18px; padding: 4px; border-radius: 6px; line-height: 1;
    transition: color 0.15s, background 0.15s;
}
.vl-modal-close:hover { color: #1a1f2e; background: #e8eaf0; }
.vl-modal-body { padding: 22px; max-height: 65vh; overflow-y: auto; }

.vl-import-notice {
    background: #f0f4ff; border: 1px solid #c7d4fb; border-radius: 8px;
    padding: 12px 14px; font-size: 12.5px; color: #3c4758;
    margin-bottom: 18px; display: flex; align-items: flex-start; gap: 10px;
}
.vl-import-notice i { flex-shrink: 0; margin-top: 2px; color: #4a6cf7; }
.vl-import-notice a { color: #4a6cf7; font-weight: 600; text-decoration: underline; }

.vl-fields-table {
    width: 100%; border-collapse: collapse; font-size: 12px;
    margin-bottom: 18px; border-radius: 8px; overflow: hidden; border: 1px solid #e8eaf0;
}
.vl-fields-table thead tr { background: #f7f8fc; }
.vl-fields-table th {
    padding: 8px 12px; text-align: left; font-size: 11px; font-weight: 700;
    text-transform: uppercase; letter-spacing: 0.5px; color: #8b92a9; border-bottom: 1px solid #e8eaf0;
}
.vl-fields-table td { padding: 7px 12px; border-bottom: 1px solid #f2f3f8; color: #2d3748; vertical-align: top; }
.vl-fields-table tr:last-child td { border-bottom: none; }
.vl-fields-table tbody tr:nth-child(even) { background: #fafbfe; }
.vl-col-name { font-family: 'DM Mono', monospace; font-size: 11px; color: #3c4758; font-weight: 500; }
.vl-col-req  { color: #ef4444; font-weight: 700; font-size: 11px; text-align: center; }
.vl-col-opt  { color: #9aa0b4; font-size: 11px; text-align: center; }

.vl-fields-toggle {
    font-size: 12px; color: #4a6cf7; font-weight: 600; cursor: pointer;
    background: none; border: none; padding: 0; margin-bottom: 12px;
    display: inline-flex; align-items: center; gap: 5px; font-family: 'DM Sans', sans-serif;
}
.vl-fields-toggle:hover { text-decoration: underline; }

.vl-dropzone {
    border: 2px dashed #c8cddf; border-radius: 10px; padding: 28px 20px;
    text-align: center; cursor: pointer; transition: border-color 0.15s, background 0.15s;
    background: #fafbfe; position: relative;
}
.vl-dropzone:hover, .vl-dropzone.drag-over { border-color: #3c4758; background: #f2f4fa; }
.vl-dropzone input[type=file] {
    position: absolute; inset: 0; opacity: 0; cursor: pointer; width: 100%; height: 100%;
}
.vl-dropzone-icon  { font-size: 28px; color: #9aa0b4; margin-bottom: 10px; }
.vl-dropzone-text  { font-size: 13px; font-weight: 600; color: #3c4758; margin-bottom: 4px; }
.vl-dropzone-sub   { font-size: 11.5px; color: #9aa0b4; }
.vl-dropzone-file  { font-size: 12.5px; color: #1a7d4a; font-weight: 600; margin-top: 8px; display: none; }
.vl-dropzone.has-file .vl-dropzone-icon { color: #22c55e; }
.vl-dropzone.has-file .vl-dropzone-file { display: block; }
.vl-dropzone.has-file .vl-dropzone-sub  { display: none; }
</style>

<meta name="viewport" content="width=device-width, initial-scale=1.0">

<div class="vl-wrap">

<!-- Header -->
<div class="vl-header">
    <div class="vl-header-left">
        <h1><i class="fa fa-user-tie" style="color:#3c4758;margin-right:10px;"></i><?php echo $langs->trans("Customers List"); ?></h1>
        <div class="vl-subtitle"><?php echo $nbtotalofrecords; ?> <?php echo $langs->trans("CustomersFound"); ?></div>
    </div>
    <div class="vl-header-actions">
        <?php if ($user->rights->societe->creer) { ?>
        <a class="vl-btn vl-btn-primary" href="<?php echo dol_buildpath('/societe/card.php', 1); ?>?action=create">
            <i class="fa fa-plus"></i> <?php echo $langs->trans("NewCustomer"); ?>
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
        <label><?php echo $langs->trans("Ref"); ?></label>
        <input type="text" name="search_ref" placeholder="<?php echo $langs->trans('SearchRef'); ?>" value="<?php echo dol_escape_htmltag($search_ref); ?>">
    </div>
    <div class="vl-filter-group">
        <label><?php echo $langs->trans("Name"); ?></label>
        <input type="text" name="search_name" placeholder="<?php echo $langs->trans('SearchName'); ?>" value="<?php echo dol_escape_htmltag($search_name); ?>">
    </div>
    <div class="vl-filter-group">
        <label><?php echo $langs->trans("Phone"); ?></label>
        <input type="text" name="search_phone" placeholder="<?php echo $langs->trans('SearchPhone'); ?>" value="<?php echo dol_escape_htmltag($search_phone); ?>">
    </div>
    <div class="vl-filter-group">
        <label><?php echo $langs->trans("Email"); ?></label>
        <input type="text" name="search_email" placeholder="<?php echo $langs->trans('SearchEmail'); ?>" value="<?php echo dol_escape_htmltag($search_email); ?>">
    </div>
    <div class="vl-filter-group">
        <label><?php echo $langs->trans("SIREN"); ?></label>
        <input type="text" name="search_siren" placeholder="<?php echo $langs->trans('SearchSIREN'); ?>" value="<?php echo dol_escape_htmltag($search_siren); ?>">
    </div>
    <div class="vl-filter-group">
        <label><?php echo $langs->trans("Town"); ?></label>
        <input type="text" name="search_town" placeholder="<?php echo $langs->trans('SearchTown'); ?>" value="<?php echo dol_escape_htmltag($search_town); ?>">
    </div>
    <div class="vl-filter-group" style="max-width:150px;">
        <label><?php echo $langs->trans("Status"); ?></label>
        <select name="search_status">
            <option value=""><?php echo $langs->trans("All"); ?></option>
            <option value="1" <?php echo $search_status === '1' ? 'selected' : ''; ?>><?php echo $langs->trans("Active"); ?></option>
            <option value="0" <?php echo $search_status === '0' ? 'selected' : ''; ?>><?php echo $langs->trans("Inactive"); ?></option>
        </select>
    </div>
    <div class="vl-filter-actions">
        <button type="submit" class="vl-btn-filter apply"><i class="fa fa-search"></i> <?php echo $langs->trans("Search"); ?></button>
        <button type="submit" name="button_removefilter" value="1" class="vl-btn-filter reset"><i class="fa fa-times"></i> <?php echo $langs->trans("Reset"); ?></button>
    </div>
</div>

<!-- Stats chips -->
<div class="vl-stats">
    <div class="vl-stat-chip">
        <span class="vl-stat-num"><?php echo $nbtotalofrecords; ?></span> <?php echo $langs->trans("Total"); ?>
    </div>
    <?php if ($cnt_active > 0) { ?>
    <div class="vl-stat-chip" style="background:#ecfdf5;color:#065f46;">
        <span class="vl-stat-num" style="color:#065f46;"><?php echo $cnt_active; ?></span> <?php echo $langs->trans("Active"); ?>
    </div>
    <?php } ?>
    <?php if ($cnt_inactive > 0) { ?>
    <div class="vl-stat-chip" style="background:#fef2f2;color:#991b1b;">
        <span class="vl-stat-num" style="color:#991b1b;"><?php echo $cnt_inactive; ?></span> <?php echo $langs->trans("Inactive"); ?>
    </div>
    <?php } ?>
</div>

<!-- Table -->
<div class="vl-table-card">
    <div class="vl-table-wrap">
    <table class="vl-table">
        <thead>
            <tr>
                <th><a href="<?php echo cl_sortHref('t.code_client', $sortfield, $sortorder, $self, $param); ?>"><?php echo $langs->trans("Ref"); ?> <?php echo cl_sortArrow('t.code_client', $sortfield, $sortorder); ?></a></th>
                <th><a href="<?php echo cl_sortHref('t.nom', $sortfield, $sortorder, $self, $param); ?>"><?php echo $langs->trans("Name"); ?> <?php echo cl_sortArrow('t.nom', $sortfield, $sortorder); ?></a></th>
                <th><a href="<?php echo cl_sortHref('t.phone', $sortfield, $sortorder, $self, $param); ?>"><?php echo $langs->trans("Phone"); ?> <?php echo cl_sortArrow('t.phone', $sortfield, $sortorder); ?></a></th>
                <th><a href="<?php echo cl_sortHref('t.email', $sortfield, $sortorder, $self, $param); ?>"><?php echo $langs->trans("Email"); ?> <?php echo cl_sortArrow('t.email', $sortfield, $sortorder); ?></a></th>
                <th><a href="<?php echo cl_sortHref('t.siren', $sortfield, $sortorder, $self, $param); ?>"><?php echo $langs->trans("SIREN"); ?> <?php echo cl_sortArrow('t.siren', $sortfield, $sortorder); ?></a></th>
                <th><a href="<?php echo cl_sortHref('t.town', $sortfield, $sortorder, $self, $param); ?>"><?php echo $langs->trans("Town"); ?> <?php echo cl_sortArrow('t.town', $sortfield, $sortorder); ?></a></th>
                <th class="center"><a href="<?php echo cl_sortHref('t.status', $sortfield, $sortorder, $self, $param); ?>"><?php echo $langs->trans("Status"); ?> <?php echo cl_sortArrow('t.status', $sortfield, $sortorder); ?></a></th>
                <th class="center"><?php echo $langs->trans("Action"); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php if (!empty($rows)) {
            foreach ($rows as $obj) {
                $cardUrl  = dol_buildpath('/societe/card.php', 1).'?socid='.$obj->rowid;
                $editUrl  = $cardUrl.'&action=edit';
        ?>
            <tr>
                <!-- Ref -->
                <td data-label="<?php echo $langs->trans('Ref'); ?>">
                    <a href="<?php echo $cardUrl; ?>" class="vl-ref-link">
                        <span class="vl-ref-icon"><i class="fa fa-building"></i></span>
                        <?php echo dol_escape_htmltag($obj->ref ?: '—'); ?>
                    </a>
                </td>

                <!-- Name + email -->
                <td data-label="<?php echo $langs->trans('Name'); ?>">
                    <div class="vl-customer-name"><?php echo dol_escape_htmltag($obj->nom ?: '—'); ?></div>
                    <?php if (!empty($obj->email)) { ?>
                    <div class="vl-customer-sub"><?php echo dol_escape_htmltag($obj->email); ?></div>
                    <?php } ?>
                </td>

                <!-- Phone -->
                <td data-label="<?php echo $langs->trans('Phone'); ?>"><?php echo dol_escape_htmltag($obj->phone ?: '—'); ?></td>

                <!-- Email -->
                <td data-label="<?php echo $langs->trans('Email'); ?>"><?php echo dol_escape_htmltag($obj->email ?: '—'); ?></td>

                <!-- SIREN -->
                <td data-label="<?php echo $langs->trans('SIREN'); ?>">
                    <?php if (!empty($obj->siren)) { ?>
                    <span class="vl-mono"><?php echo dol_escape_htmltag($obj->siren); ?></span>
                    <?php } else { echo '<span style="color:#c4c9d8;">—</span>'; } ?>
                </td>

                <!-- Town -->
                <td data-label="<?php echo $langs->trans('Town'); ?>">
                    <?php if (!empty($obj->town)) { ?>
                    <span style="font-size:13px;color:#4a5568;">
                        <?php if (!empty($obj->zip)) echo dol_escape_htmltag($obj->zip).' '; ?>
                        <?php echo dol_escape_htmltag($obj->town); ?>
                    </span>
                    <?php } else { echo '<span style="color:#c4c9d8;">—</span>'; } ?>
                </td>

                <!-- Status -->
                <td class="center" data-label="<?php echo $langs->trans('Status'); ?>">
                    <?php if ($obj->status == 1) { ?>
                    <span class="vl-badge" style="background:#ecfdf5;color:#065f46;">
                        <i class="fa fa-circle" style="font-size:7px;color:#10b981;"></i>
                        <?php echo $langs->trans('Active'); ?>
                    </span>
                    <?php } else { ?>
                    <span class="vl-badge" style="background:#fef2f2;color:#991b1b;">
                        <i class="fa fa-circle" style="font-size:7px;color:#ef4444;"></i>
                        <?php echo $langs->trans('Inactive'); ?>
                    </span>
                    <?php } ?>
                </td>

                <!-- Actions -->
                <td data-label="<?php echo $langs->trans('Action'); ?>">
                    <div class="vl-actions">
                        <a href="<?php echo $cardUrl; ?>" class="vl-action-btn view" title="<?php echo $langs->trans('View'); ?>"><i class="fa fa-eye"></i></a>
                        <?php if ($user->rights->societe->creer) { ?>
                        <a href="<?php echo $editUrl; ?>" class="vl-action-btn edit" title="<?php echo $langs->trans('Edit'); ?>"><i class="fa fa-pen"></i></a>
                        <?php } ?>
                    </div>
                </td>
            </tr>
        <?php }} else { ?>
            <tr>
                <td colspan="8">
                    <div class="vl-empty">
                        <div class="vl-empty-icon"><i class="fa fa-building"></i></div>
                        <p><?php echo $langs->trans("NoCustomersFound"); ?></p>
                        <?php if ($user->rights->societe->creer) { ?>
                        <a class="vl-btn vl-btn-primary" href="<?php echo dol_buildpath('/societe/card.php', 1); ?>?action=create">
                            <i class="fa fa-plus"></i> <?php echo $langs->trans("AddFirstCustomer"); ?>
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
            <?php echo sprintf($langs->trans("ShowingCustomers"), $showing_from, $showing_to, $nbtotalofrecords); ?>
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
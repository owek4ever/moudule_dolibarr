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

$langs->loadLangs(array("flotte@flotte", "other"));

// ─── Parameters ───────────────────────────────────────────────────
$action  = GETPOST('action',  'aZ09') ?: 'view';
$confirm = GETPOST('confirm', 'alpha');

$search_ref          = GETPOST('search_ref',          'alpha');
$search_requested_by = GETPOST('search_requested_by', 'alpha');
$search_assigned_to  = GETPOST('search_assigned_to',  'alpha');
$search_priority     = GETPOST('search_priority',     'alpha');
$search_status       = GETPOST('search_status',       'alpha');
$search_due_date     = GETPOST('search_due_date',     'alpha');

$limit     = GETPOST('limit', 'int') ?: $conf->liste_limit;
$sortfield = GETPOST('sortfield', 'aZ09comma');
$sortorder = GETPOST('sortorder', 'aZ09comma');
$page      = GETPOSTISSET('pageplusone') ? (GETPOST('pageplusone') - 1) : GETPOST("page", 'int');
if (empty($page) || $page == -1) $page = 0;
$offset = $limit * $page;

if (!$sortfield) $sortfield = "t.tms";
if (!$sortorder) $sortorder = "DESC";

restrictedArea($user, 'flotte');

// ─── Actions ──────────────────────────────────────────────────────
if (GETPOST('cancel', 'alpha')) $action = 'list';

if (GETPOST('button_removefilter_x','alpha') || GETPOST('button_removefilter.x','alpha') || GETPOST('button_removefilter','alpha')) {
    $search_ref = $search_requested_by = $search_assigned_to = $search_priority = $search_status = $search_due_date = '';
}

// Delete
if ($action == 'confirm_delete' && $confirm == 'yes' && $user->rights->flotte->write) {
    $id = GETPOST('id', 'int');
    if ($id > 0) {
        $result = $db->query("DELETE FROM ".MAIN_DB_PREFIX."flotte_workorder WHERE rowid = ".(int)$id);
        if ($result) {
            setEventMessages('Work order deleted.', null, 'mesgs');
            header("Location: ".$_SERVER['PHP_SELF']); exit;
        } else {
            setEventMessages('Error: '.$db->lasterror(), null, 'errors');
        }
    }
}

// ─── Main query ───────────────────────────────────────────────────
$sql  = "SELECT t.rowid, t.ref, t.status, t.tms, t.priority,";
$sql .= " COALESCE(t.requested_by, '')                       AS requested_by,";
$sql .= " COALESCE(t.task_to_perform, t.description, '')     AS task_display,";
$sql .= " COALESCE(t.due_date, t.required_by)               AS due_display,";
$sql .= " TRIM(CONCAT(COALESCE(d.firstname,''), ' ', COALESCE(d.lastname,''))) AS driver_fullname";
$sql .= " FROM ".MAIN_DB_PREFIX."flotte_workorder AS t";
$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."flotte_driver AS d ON t.fk_driver = d.rowid";
$sql .= " WHERE 1=1 AND t.entity IN (".getEntity('flotte').")";

if ($search_ref)          $sql .= " AND t.ref LIKE '%".$db->escape($search_ref)."%'";
if ($search_requested_by) $sql .= " AND t.requested_by LIKE '%".$db->escape($search_requested_by)."%'";
if ($search_assigned_to)  $sql .= " AND (d.firstname LIKE '%".$db->escape($search_assigned_to)."%' OR d.lastname LIKE '%".$db->escape($search_assigned_to)."%')";
if ($search_priority)     $sql .= " AND t.priority = '".$db->escape($search_priority)."'";
if ($search_status)       $sql .= " AND t.status = '".$db->escape($search_status)."'";
if ($search_due_date)     $sql .= " AND COALESCE(t.due_date, t.required_by) = '".$db->escape($search_due_date)."'";

// Count
$sqlcount = "SELECT COUNT(*) as nb FROM ".MAIN_DB_PREFIX."flotte_workorder AS t"
          . " LEFT JOIN ".MAIN_DB_PREFIX."flotte_driver AS d ON t.fk_driver = d.rowid"
          . " WHERE 1=1 AND t.entity IN (".getEntity('flotte').")";
if ($search_ref)          $sqlcount .= " AND t.ref LIKE '%".$db->escape($search_ref)."%'";
if ($search_requested_by) $sqlcount .= " AND t.requested_by LIKE '%".$db->escape($search_requested_by)."%'";
if ($search_assigned_to)  $sqlcount .= " AND (d.firstname LIKE '%".$db->escape($search_assigned_to)."%' OR d.lastname LIKE '%".$db->escape($search_assigned_to)."%')";
if ($search_priority)     $sqlcount .= " AND t.priority = '".$db->escape($search_priority)."'";
if ($search_status)       $sqlcount .= " AND t.status = '".$db->escape($search_status)."'";
if ($search_due_date)     $sqlcount .= " AND COALESCE(t.due_date, t.required_by) = '".$db->escape($search_due_date)."'";

$nbtotalofrecords = 0;
$resql_count = $db->query($sqlcount);
if ($resql_count) { $obj = $db->fetch_object($resql_count); $nbtotalofrecords = $obj->nb; }

$sql .= $db->order($sortfield, $sortorder);
$sql .= $db->plimit($limit + 1, $offset);
$resql = $db->query($sql);
$num   = $resql ? $db->num_rows($resql) : 0;

$form = new Form($db);

// ─── Collect rows ─────────────────────────────────────────────────
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

// ─── Sort helpers ─────────────────────────────────────────────────
function wo_sortArrow($field, $sortfield, $sortorder) {
    if ($sortfield == $field) return $sortorder == 'ASC' ? ' <span class="vl-sort-arrow">↑</span>' : ' <span class="vl-sort-arrow">↓</span>';
    return ' <span class="vl-sort-arrow muted">↕</span>';
}
function wo_sortHref($field, $sortfield, $sortorder, $self, $param) {
    $dir = ($sortfield == $field && $sortorder == 'ASC') ? 'DESC' : 'ASC';
    return $self.'?sortfield='.$field.'&sortorder='.$dir.'&'.$param;
}

$self = $_SERVER["PHP_SELF"];

// Count by status
$cnt_pending = 0; $cnt_inprogress = 0; $cnt_completed = 0; $cnt_cancelled = 0;
foreach ($rows as $r) {
    if ($r->status == 'Pending')        $cnt_pending++;
    elseif ($r->status == 'In Progress') $cnt_inprogress++;
    elseif ($r->status == 'Completed')   $cnt_completed++;
    elseif ($r->status == 'Cancelled')   $cnt_cancelled++;
}

// ─── URL param string ─────────────────────────────────────────────
$param = '';
if (!empty($search_ref))          $param .= '&search_ref='.urlencode($search_ref);
if (!empty($search_requested_by)) $param .= '&search_requested_by='.urlencode($search_requested_by);
if (!empty($search_assigned_to))  $param .= '&search_assigned_to='.urlencode($search_assigned_to);
if (!empty($search_priority))     $param .= '&search_priority='.urlencode($search_priority);
if (!empty($search_status))       $param .= '&search_status='.urlencode($search_status);
if (!empty($search_due_date))     $param .= '&search_due_date='.urlencode($search_due_date);

llxHeader('', 'Work Orders', '');

// Delete confirmation
if ($action == 'delete') {
    $id = GETPOST('id', 'int');
    print $form->formconfirm(
        $_SERVER["PHP_SELF"].'?id='.$id.$param,
        'Delete Work Order',
        'Are you sure you want to delete this work order?',
        'confirm_delete', '', 0, 1
    );
}
?>

<style>
@import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=DM+Mono:wght@400;500&display=swap');

.vl-wrap * { box-sizing: border-box; }
.vl-wrap {
    font-family: 'DM Sans', sans-serif;
    max-width: 1480px; margin: 0 auto;
    padding: 0 4px 40px; color: #1a1f2e;
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
.vl-btn-filter.reset { background: #3c4758 !important; color: #fff !important; }
.vl-btn-filter.reset:hover { background: #2a3346 !important; }

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

/* Task preview */
.vl-task-main { font-weight: 600; color: #1a1f2e; font-size: 13.5px; }
.vl-task-sub  { font-size: 11.5px; color: #9aa0b4; margin-top: 2px; }

/* Mono */
.vl-mono {
    font-family: 'DM Mono', monospace; font-size: 12px; color: #4a5568;
    background: #f0f2fa; padding: 3px 8px; border-radius: 5px; display: inline-block;
}

/* Priority badge */
.vl-priority {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 3px 10px; border-radius: 20px;
    font-size: 11.5px; font-weight: 600; white-space: nowrap;
}
.vl-priority.low      { background: #f0fdf4; color: #166534; }
.vl-priority.medium   { background: #fefce8; color: #854d0e; }
.vl-priority.high     { background: #fef2f2; color: #991b1b; }
.vl-priority.urgent   { background: #7f1d1d; color: #fff; }

/* Status badge */
.vl-badge {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 4px 10px; border-radius: 20px;
    font-size: 11.5px; font-weight: 600; white-space: nowrap;
}
.vl-badge::before { content: ''; width: 6px; height: 6px; border-radius: 50%; flex-shrink: 0; }
.vl-badge.pending     { background: #f0f2fa; color: #5a6482; }
.vl-badge.pending::before { background: #8b92a9; }
.vl-badge.inprogress  { background: #f0fdf4; color: #166534; }
.vl-badge.inprogress::before { background: #22c55e; }
.vl-badge.completed   { background: #eff6ff; color: #1d4ed8; }
.vl-badge.completed::before { background: #3b82f6; }
.vl-badge.cancelled   { background: #fef2f2; color: #991b1b; }
.vl-badge.cancelled::before { background: #ef4444; }

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
        <h1><i class="fa fa-tools" style="color:#3c4758;margin-right:10px;"></i>Work Orders</h1>
        <div class="vl-subtitle"><?php echo $nbtotalofrecords; ?> work order<?php echo $nbtotalofrecords != 1 ? 's' : ''; ?> found</div>
    </div>
    <div class="vl-header-actions">
        <?php if ($user->rights->flotte->read) { ?>
        <a class="vl-btn vl-btn-secondary" href="<?php echo dol_buildpath('/flotte/workorder_list.php', 1); ?>?action=export">
            <i class="fa fa-download"></i> Export
        </a>
        <?php } ?>
        <?php if ($user->rights->flotte->write) { ?>
        <a class="vl-btn vl-btn-primary" href="<?php echo dol_buildpath('/flotte/workorder_card.php', 1); ?>?action=create">
            <i class="fa fa-plus"></i> New Work Order
        </a>
        <?php } ?>
    </div>
</div>

<!-- Filter Form -->
<form method="POST" action="<?php echo $self; ?>">
<input type="hidden" name="token"            value="<?php echo newToken(); ?>">
<input type="hidden" name="formfilteraction" value="list">
<input type="hidden" name="action"           value="list">
<input type="hidden" name="sortfield"        value="<?php echo $sortfield; ?>">
<input type="hidden" name="sortorder"        value="<?php echo $sortorder; ?>">
<input type="hidden" name="page"             value="<?php echo $page; ?>">

<div class="vl-filters">
    <div class="vl-filter-group">
        <label>Reference</label>
        <input type="text" name="search_ref" placeholder="Search ref…" value="<?php echo dol_escape_htmltag($search_ref); ?>">
    </div>
    <div class="vl-filter-group">
        <label>Requested By</label>
        <input type="text" name="search_requested_by" placeholder="Name or department…" value="<?php echo dol_escape_htmltag($search_requested_by); ?>">
    </div>
    <div class="vl-filter-group">
        <label>Assigned To</label>
        <input type="text" name="search_assigned_to" placeholder="Driver name…" value="<?php echo dol_escape_htmltag($search_assigned_to); ?>">
    </div>
    <div class="vl-filter-group">
        <label>Due Date</label>
        <input type="date" name="search_due_date" value="<?php echo dol_escape_htmltag($search_due_date); ?>">
    </div>
    <div class="vl-filter-group" style="max-width:150px;">
        <label>Priority</label>
        <select name="search_priority">
            <option value="">All</option>
            <option value="Low"    <?php echo $search_priority === 'Low'    ? 'selected' : ''; ?>>Low</option>
            <option value="Medium" <?php echo $search_priority === 'Medium' ? 'selected' : ''; ?>>Medium</option>
            <option value="High"   <?php echo $search_priority === 'High'   ? 'selected' : ''; ?>>High</option>
            <option value="Urgent" <?php echo $search_priority === 'Urgent' ? 'selected' : ''; ?>>Urgent</option>
        </select>
    </div>
    <div class="vl-filter-group" style="max-width:150px;">
        <label>Status</label>
        <select name="search_status">
            <option value="">All</option>
            <option value="Pending"     <?php echo $search_status === 'Pending'     ? 'selected' : ''; ?>>Pending</option>
            <option value="In Progress" <?php echo $search_status === 'In Progress' ? 'selected' : ''; ?>>In Progress</option>
            <option value="Completed"   <?php echo $search_status === 'Completed'   ? 'selected' : ''; ?>>Completed</option>
            <option value="Cancelled"   <?php echo $search_status === 'Cancelled'   ? 'selected' : ''; ?>>Cancelled</option>
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
    <div class="vl-stat-chip" style="background:#f0f2fa;color:#5a6482;">
        <span class="vl-stat-num" style="color:#5a6482;"><?php echo $cnt_pending; ?></span> Pending
    </div>
    <?php } ?>
    <?php if ($cnt_inprogress > 0) { ?>
    <div class="vl-stat-chip" style="background:#f0fdf4;color:#166534;">
        <span class="vl-stat-num" style="color:#166534;"><?php echo $cnt_inprogress; ?></span> In Progress
    </div>
    <?php } ?>
    <?php if ($cnt_completed > 0) { ?>
    <div class="vl-stat-chip" style="background:#eff6ff;color:#1d4ed8;">
        <span class="vl-stat-num" style="color:#1d4ed8;"><?php echo $cnt_completed; ?></span> Completed
    </div>
    <?php } ?>
    <?php if ($cnt_cancelled > 0) { ?>
    <div class="vl-stat-chip" style="background:#fef2f2;color:#991b1b;">
        <span class="vl-stat-num" style="color:#991b1b;"><?php echo $cnt_cancelled; ?></span> Cancelled
    </div>
    <?php } ?>
</div>

<!-- Table -->
<div class="vl-table-card">
    <div class="vl-table-wrap">
    <table class="vl-table">
        <thead>
            <tr>
                <th><a href="<?php echo wo_sortHref('t.ref', $sortfield, $sortorder, $self, $param); ?>">Ref <?php echo wo_sortArrow('t.ref', $sortfield, $sortorder); ?></a></th>
                <th><a href="<?php echo wo_sortHref('t.requested_by', $sortfield, $sortorder, $self, $param); ?>">Requested By <?php echo wo_sortArrow('t.requested_by', $sortfield, $sortorder); ?></a></th>
                <th>Task</th>
                <th><a href="<?php echo wo_sortHref('d.lastname', $sortfield, $sortorder, $self, $param); ?>">Assigned To <?php echo wo_sortArrow('d.lastname', $sortfield, $sortorder); ?></a></th>
                <th class="center"><a href="<?php echo wo_sortHref('t.due_date', $sortfield, $sortorder, $self, $param); ?>">Due Date <?php echo wo_sortArrow('t.due_date', $sortfield, $sortorder); ?></a></th>
                <th class="center"><a href="<?php echo wo_sortHref('t.priority', $sortfield, $sortorder, $self, $param); ?>">Priority <?php echo wo_sortArrow('t.priority', $sortfield, $sortorder); ?></a></th>
                <th class="center"><a href="<?php echo wo_sortHref('t.status', $sortfield, $sortorder, $self, $param); ?>">Status <?php echo wo_sortArrow('t.status', $sortfield, $sortorder); ?></a></th>
                <th class="center">Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php if (!empty($rows)) {
            foreach ($rows as $obj) {
                $cardUrl  = dol_buildpath('/flotte/workorder_card.php', 1).'?id='.$obj->rowid;
                $priority = $obj->priority ?: 'Medium';
                $dname    = trim($obj->driver_fullname ?? '');
        ?>
            <tr>
                <!-- Ref -->
                <td>
                    <a href="<?php echo $cardUrl; ?>" class="vl-ref-link">
                        <span class="vl-ref-icon"><i class="fa fa-tools"></i></span>
                        <?php echo dol_escape_htmltag($obj->ref); ?>
                    </a>
                </td>

                <!-- Requested By -->
                <td><?php echo dol_escape_htmltag($obj->requested_by ?: '—'); ?></td>

                <!-- Task preview -->
                <td>
                    <?php if (!empty($obj->task_display)) {
                        echo '<div class="vl-task-main">'.dol_escape_htmltag(dol_trunc($obj->task_display, 45)).'</div>';
                    } else {
                        echo '<span style="color:#c4c9d8;">—</span>';
                    } ?>
                </td>

                <!-- Assigned To -->
                <td><?php echo $dname ? dol_escape_htmltag($dname) : '<span style="color:#c4c9d8;">—</span>'; ?></td>

                <!-- Due Date -->
                <td class="center">
                    <?php
                    $due = $obj->due_display ? dol_print_date($db->jdate($obj->due_display), 'day') : '';
                    echo $due ?: '<span style="color:#c4c9d8;">—</span>';
                    ?>
                </td>

                <!-- Priority -->
                <td class="center">
                    <?php
                    $pcss = strtolower($priority);
                    echo '<span class="vl-priority '.$pcss.'">'.dol_escape_htmltag($priority).'</span>';
                    ?>
                </td>

                <!-- Status -->
                <td class="center">
                    <?php
                    $st = $obj->status;
                    if ($st == 'Pending')        echo '<span class="vl-badge pending">Pending</span>';
                    elseif ($st == 'In Progress') echo '<span class="vl-badge inprogress">In Progress</span>';
                    elseif ($st == 'Completed')   echo '<span class="vl-badge completed">Completed</span>';
                    elseif ($st == 'Cancelled')   echo '<span class="vl-badge cancelled">Cancelled</span>';
                    else                          echo '<span style="color:#c4c9d8;font-size:13px;">—</span>';
                    ?>
                </td>

                <!-- Actions -->
                <td>
                    <div class="vl-actions">
                        <a href="<?php echo $cardUrl; ?>" class="vl-action-btn view" title="View"><i class="fa fa-eye"></i></a>
                        <?php if ($user->rights->flotte->write) { ?>
                        <a href="<?php echo $cardUrl; ?>&action=edit" class="vl-action-btn edit" title="Edit"><i class="fa fa-pen"></i></a>
                        <a href="<?php echo $self; ?>?id=<?php echo $obj->rowid; ?>&action=delete&token=<?php echo newToken(); ?>" class="vl-action-btn del" title="Delete"><i class="fa fa-trash"></i></a>
                        <?php } ?>
                    </div>
                </td>
            </tr>
        <?php }} else { ?>
            <tr>
                <td colspan="8">
                    <div class="vl-empty">
                        <div class="vl-empty-icon"><i class="fa fa-tools"></i></div>
                        <p>No work orders found</p>
                        <?php if ($user->rights->flotte->write) { ?>
                        <a class="vl-btn vl-btn-primary" href="<?php echo dol_buildpath('/flotte/workorder_card.php', 1); ?>?action=create">
                            <i class="fa fa-plus"></i> Add First Work Order
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
            Showing <strong><?php echo $showing_from; ?></strong>–<strong><?php echo $showing_to; ?></strong> of <strong><?php echo $nbtotalofrecords; ?></strong> work orders
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
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
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';

$langs->loadLangs(array("flotte@flotte", "other"));

// ─── Helper: auto-generate WO reference ──────────────────────────
function getNextWorkOrderRef($db, $entity) {
    $prefix = "WO-";
    $sql = "SELECT ref FROM ".MAIN_DB_PREFIX."flotte_workorder"
         . " WHERE entity = ".(int)$entity
         . " AND ref LIKE '".$prefix."%'"
         . " ORDER BY ref DESC LIMIT 1";
    $resql = $db->query($sql);
    if ($resql && $db->num_rows($resql) > 0) {
        $obj  = $db->fetch_object($resql);
        $next = (int)str_replace($prefix, '', $obj->ref) + 1;
    } else {
        $next = 1;
    }
    return $prefix.str_pad($next, 4, '0', STR_PAD_LEFT);
}

// ─── Helper: parse Dolibarr date widget fields ────────────────────
function getDateFromPost($field) {
    $day   = GETPOST($field.'day',   'int');
    $month = GETPOST($field.'month', 'int');
    $year  = GETPOST($field.'year',  'int');
    if ($day > 0 && $month > 0 && $year > 0) {
        $hour = (int) GETPOST($field.'hour', 'int');
        $min  = (int) GETPOST($field.'min',  'int');
        return sprintf('%04d-%02d-%02d %02d:%02d:00', $year, $month, $day, $hour, $min);
    }
    $raw = GETPOST($field, 'alpha');
    if (empty($raw)) return '';
    $ts = dol_stringtotime($raw);
    if ($ts) return dol_print_date($ts, '%Y-%m-%d %H:%M:%S', 'tzserver');
    foreach (['Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d', 'd/m/Y', 'm/d/Y'] as $fmt) {
        $d = DateTime::createFromFormat($fmt, $raw);
        if ($d) return $d->format('Y-m-d H:i:s');
    }
    return '';
}

// ─── Parameters ──────────────────────────────────────────────────
$id      = GETPOST('id',      'int');
$action  = GETPOST('action',  'aZ09') ?: 'view';
$confirm = GETPOST('confirm', 'alpha');
$cancel  = GETPOST('cancel',  'alpha');

restrictedArea($user, 'flotte');

// ─── Object defaults ─────────────────────────────────────────────
$object                      = new stdClass();
$object->id                  = 0;
$object->ref                 = '';
$object->tms                 = null;
$object->status              = 'Pending';
$object->priority            = 'Medium';
$object->requested_by        = '';
$object->task_to_perform     = '';
$object->problem_description = '';
$object->fk_driver           = 0;
$object->responsible_person  = '';
$object->start_date          = '';
$object->due_date            = '';
$object->technician_notes    = '';
$object->driver_fullname     = '';
// legacy columns kept in sync
$object->description         = '';
$object->note                = '';
$object->required_by         = '';

$error  = 0;
$errors = array();

if ($action == 'create') {
    $object->ref = getNextWorkOrderRef($db, $conf->entity);
}

// ─── Cancel ──────────────────────────────────────────────────────
if ($cancel) $action = ($id > 0) ? 'view' : 'list';

// ─── Delete ──────────────────────────────────────────────────────
if ($action == 'confirm_delete' && $confirm == 'yes' && $user->rights->flotte->delete) {
    $db->begin();
    $result = $db->query("DELETE FROM ".MAIN_DB_PREFIX."flotte_workorder WHERE rowid = ".((int)$id));
    if ($result) {
        $db->commit();
        header("Location: ".dol_buildpath('/flotte/workorder_list.php', 1));
        exit;
    } else {
        $db->rollback();
        $errors[] = 'Error deleting work order: '.$db->lasterror();
    }
}

// ─── Insert ──────────────────────────────────────────────────────
if ($action == 'add' && $user->rights->flotte->write) {
    $db->begin();

    $ref                 = GETPOST('ref',                 'alpha');
    $requested_by        = GETPOST('requested_by',        'alpha');
    $task_to_perform     = GETPOST('task_to_perform',     'alpha');
    $problem_description = GETPOST('problem_description', 'alpha');
    $priority            = GETPOST('priority',            'alpha');
    $fk_driver           = GETPOST('fk_driver',           'int');
    $responsible_person  = GETPOST('responsible_person',  'alpha');
    $start_date          = getDateFromPost('start_date');
    $due_date            = getDateFromPost('due_date');
    $status              = GETPOST('status',              'alpha');
    $technician_notes    = GETPOST('technician_notes',    'alpha');

    if (empty($ref)) $ref = getNextWorkOrderRef($db, $conf->entity);
    if (empty($ref)) { $error++; $errors[] = 'Reference is required.'; }

    if (!$error) {
        $sql  = "INSERT INTO ".MAIN_DB_PREFIX."flotte_workorder (";
        $sql .= " ref, entity,";
        $sql .= " requested_by, task_to_perform, problem_description, priority,";
        $sql .= " fk_driver, responsible_person, start_date, due_date,";
        $sql .= " status, technician_notes,";
        $sql .= " description, note, required_by,";
        $sql .= " fk_user_author";
        $sql .= ") VALUES (";
        $sql .= " '".$db->escape($ref)."', ".(int)$conf->entity.",";
        $sql .= " '".$db->escape($requested_by)."',";
        $sql .= " '".$db->escape($task_to_perform)."',";
        $sql .= " '".$db->escape($problem_description)."',";
        $sql .= " '".$db->escape($priority)."',";
        $sql .= " ".($fk_driver > 0 ? (int)$fk_driver : "NULL").",";
        $sql .= " '".$db->escape($responsible_person)."',";
        $sql .= " ".($start_date ? "'".$db->escape($start_date)."'" : "NULL").",";
        $sql .= " ".($due_date   ? "'".$db->escape($due_date)."'"   : "NULL").",";
        $sql .= " '".$db->escape($status)."',";
        $sql .= " '".$db->escape($technician_notes)."',";
        $sql .= " '".$db->escape($task_to_perform)."',";
        $sql .= " '".$db->escape($technician_notes)."',";
        $sql .= " ".($due_date ? "'".$db->escape($due_date)."'" : "NULL").",";
        $sql .= " ".(int)$user->id;
        $sql .= ")";

        $result = $db->query($sql);
        if ($result) {
            $id = $db->last_insert_id(MAIN_DB_PREFIX."flotte_workorder");
            $db->commit();
            $action = 'view';
            setEventMessages($langs->trans('WorkOrderCreatedSuccessfully'), null, 'mesgs');
        } else {
            $db->rollback();
            $error++;
            $errors[] = 'ErrorCreatingWorkOrder: '.$db->lasterror();
        }
    } else {
        $db->rollback();
    }
}

// ─── Update ──────────────────────────────────────────────────────
if ($action == 'update' && $user->rights->flotte->write) {
    $db->begin();

    $ref                 = GETPOST('ref',                 'alpha');
    $requested_by        = GETPOST('requested_by',        'alpha');
    $task_to_perform     = GETPOST('task_to_perform',     'alpha');
    $problem_description = GETPOST('problem_description', 'alpha');
    $priority            = GETPOST('priority',            'alpha');
    $fk_driver           = GETPOST('fk_driver',           'int');
    $responsible_person  = GETPOST('responsible_person',  'alpha');
    $start_date          = getDateFromPost('start_date');
    $due_date            = getDateFromPost('due_date');
    $status              = GETPOST('status',              'alpha');
    $technician_notes    = GETPOST('technician_notes',    'alpha');

    if (empty($ref)) { $error++; $errors[] = 'Reference is required.'; }

    if (!$error) {
        $sql  = "UPDATE ".MAIN_DB_PREFIX."flotte_workorder SET";
        $sql .= "  ref                 = '".$db->escape($ref)."',";
        $sql .= "  requested_by        = '".$db->escape($requested_by)."',";
        $sql .= "  task_to_perform     = '".$db->escape($task_to_perform)."',";
        $sql .= "  problem_description = '".$db->escape($problem_description)."',";
        $sql .= "  priority            = '".$db->escape($priority)."',";
        $sql .= "  fk_driver           = ".($fk_driver > 0 ? (int)$fk_driver : "NULL").",";
        $sql .= "  responsible_person  = '".$db->escape($responsible_person)."',";
        $sql .= "  start_date          = ".($start_date ? "'".$db->escape($start_date)."'" : "NULL").",";
        $sql .= "  due_date            = ".($due_date   ? "'".$db->escape($due_date)."'"   : "NULL").",";
        $sql .= "  status              = '".$db->escape($status)."',";
        $sql .= "  technician_notes    = '".$db->escape($technician_notes)."',";
        $sql .= "  description         = '".$db->escape($task_to_perform)."',";
        $sql .= "  note                = '".$db->escape($technician_notes)."',";
        $sql .= "  required_by         = ".($due_date ? "'".$db->escape($due_date)."'" : "NULL").",";
        $sql .= "  fk_user_modif       = ".(int)$user->id;
        $sql .= " WHERE rowid = ".((int)$id);

        $result = $db->query($sql);
        if ($result) {
            $db->commit();
            $action = 'view';
            setEventMessages($langs->trans('WorkOrderUpdatedSuccessfully'), null, 'mesgs');
        } else {
            $db->rollback();
            $error++;
            $errors[] = 'ErrorUpdatingWorkOrder: '.$db->lasterror();
        }
    } else {
        $db->rollback();
    }
}

// ─── Load record ─────────────────────────────────────────────────
if ($id > 0) {
    $sql = "SELECT w.*,
                TRIM(CONCAT(COALESCE(d.firstname,''), ' ', COALESCE(d.lastname,''))) AS driver_fullname
            FROM ".MAIN_DB_PREFIX."flotte_workorder AS w
            LEFT JOIN ".MAIN_DB_PREFIX."flotte_driver AS d ON w.fk_driver = d.rowid
            WHERE w.rowid = ".((int)$id);
    $resql = $db->query($sql);
    if ($resql) {
        $object = $db->fetch_object($resql);
        if (!$object) { header("HTTP/1.0 404 Not Found"); print 'Work order not found.'; exit; }
        if (empty($object->task_to_perform))  $object->task_to_perform  = $object->description ?? '';
        if (empty($object->technician_notes)) $object->technician_notes = $object->note        ?? '';
        if (empty($object->due_date))         $object->due_date         = $object->required_by ?? '';
        if (empty($object->priority))         $object->priority         = 'Medium';
        if (empty($object->status))           $object->status           = 'Pending';
    } else {
        header("HTTP/1.0 500 Internal Server Error");
        print 'Error loading work order: '.$db->lasterror();
        exit;
    }
}

// ─── Drivers dropdown ────────────────────────────────────────────
$drivers = array();
$resql_d = $db->query(
    "SELECT rowid, TRIM(CONCAT(COALESCE(firstname,''), ' ', COALESCE(lastname,''))) AS fullname, employee_id"
    . " FROM ".MAIN_DB_PREFIX."flotte_driver"
    . " WHERE entity = ".(int)$conf->entity
    . " AND (status IS NULL OR status != 'Inactive')"
    . " ORDER BY lastname, firstname"
);
if ($resql_d) {
    while ($obj = $db->fetch_object($resql_d)) {
        $label = trim($obj->fullname);
        if (!empty($obj->employee_id)) $label .= ' ['.$obj->employee_id.']';
        if (empty($label)) $label = 'Driver #'.$obj->rowid;
        $drivers[$obj->rowid] = $label;
    }
}

$form = new Form($db);
$hookmanager->initHooks(array('workordercard'));

/*
 * View
 */

if ($action == 'create')   $title = $langs->trans('NewWorkOrder');
elseif ($action == 'edit') $title = $langs->trans('EditWorkOrder');
elseif ($id > 0)           $title = $langs->trans('WorkOrder').' '.$object->ref;
else                       $title = $langs->trans('WorkOrder');

llxHeader('', $title);

?>
<style>
@import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=DM+Mono:wght@400;500&display=swap');

.dc-page * { box-sizing: border-box; }
.dc-page {
    font-family: 'DM Sans', sans-serif;
    max-width: 1160px;
    margin: 0 auto;
    padding: 0 2px 48px;
    color: #1a1f2e;
}

/* ── Page header ── */
.dc-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 26px 0 22px;
    border-bottom: 1px solid #e8eaf0;
    margin-bottom: 28px;
    gap: 16px;
    flex-wrap: wrap;
}
.dc-header-left { display: flex; align-items: center; gap: 14px; }
.dc-header-icon {
    width: 46px; height: 46px; border-radius: 12px;
    background: rgba(60,71,88,0.1);
    display: flex; align-items: center; justify-content: center;
    color: #3c4758; font-size: 20px; flex-shrink: 0;
}
.dc-header-title { font-size: 21px; font-weight: 700; color: #1a1f2e; margin: 0 0 3px; letter-spacing: -0.3px; }
.dc-header-sub { font-size: 12.5px; color: #8b92a9; font-weight: 400; }
.dc-header-actions { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }

/* ── Status / priority badges ── */
.dc-badge {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 4px 11px; border-radius: 20px;
    font-size: 11.5px; font-weight: 600; white-space: nowrap;
}
.dc-badge::before { content: ''; width: 6px; height: 6px; border-radius: 50%; flex-shrink: 0; }
.dc-badge.status-pending    { background: #fffbeb; color: #b45309; }
.dc-badge.status-pending::before    { background: #f59e0b; }
.dc-badge.status-inprogress { background: #eff6ff; color: #1d4ed8; }
.dc-badge.status-inprogress::before { background: #3b82f6; }
.dc-badge.status-completed  { background: #edfaf3; color: #1a7d4a; }
.dc-badge.status-completed::before  { background: #22c55e; }
.dc-badge.status-cancelled  { background: #fef2f2; color: #b91c1c; }
.dc-badge.status-cancelled::before  { background: #ef4444; }
.dc-badge.priority-low      { background: #edfaf3; color: #1a7d4a; }
.dc-badge.priority-low::before      { background: #22c55e; }
.dc-badge.priority-medium   { background: #fffbeb; color: #b45309; }
.dc-badge.priority-medium::before   { background: #f59e0b; }
.dc-badge.priority-high     { background: #fef2f2; color: #b91c1c; }
.dc-badge.priority-high::before     { background: #ef4444; }
.dc-badge.priority-urgent   { background: #4c0519; color: #fecdd3; }
.dc-badge.priority-urgent::before   { background: #f43f5e; }

/* ── Buttons ── */
.dc-btn {
    display: inline-flex; align-items: center; gap: 7px;
    padding: 8px 16px; border-radius: 6px;
    font-size: 13px; font-weight: 600;
    text-decoration: none !important; cursor: pointer;
    font-family: 'DM Sans', sans-serif; white-space: nowrap;
    transition: all 0.15s ease; border: none;
}
.dc-btn-primary  { background: #3c4758 !important; color: #fff !important; }
.dc-btn-primary:hover  { background: #2a3346 !important; color: #fff !important; }
.dc-btn-ghost {
    background: #fff !important; color: #5a6482 !important;
    border: 1.5px solid #d1d5e0 !important;
}
.dc-btn-ghost:hover { background: #f5f6fa !important; color: #2d3748 !important; }
.dc-btn-danger {
    background: #fef2f2 !important; color: #dc2626 !important;
    border: 1.5px solid #fecaca !important;
}
.dc-btn-danger:hover { background: #fee2e2 !important; color: #b91c1c !important; }
input.dc-btn-primary,
button.dc-btn-primary {
    background: #3c4758 !important; color: #fff !important; border: none !important;
}
input.dc-btn-primary:hover,
button.dc-btn-primary:hover { background: #2a3346 !important; }

/* ── Two-column grid ── */
.dc-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
    margin-bottom: 20px;
}
@media (max-width: 780px) { .dc-grid { grid-template-columns: 1fr; } }

/* ── Section card ── */
.dc-card {
    background: #fff;
    border: 1px solid #e8eaf0;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 1px 6px rgba(0,0,0,0.04);
}
.dc-card-header {
    display: flex; align-items: center; gap: 10px;
    padding: 14px 20px;
    border-bottom: 1px solid #f0f2f8;
    background: #f7f8fc;
}
.dc-card-header-icon {
    width: 28px; height: 28px; border-radius: 7px;
    display: flex; align-items: center; justify-content: center;
    font-size: 13px; flex-shrink: 0;
}
.dc-card-header-icon.blue   { background: rgba(60,71,88,0.1);  color: #3c4758; }
.dc-card-header-icon.green  { background: rgba(22,163,74,0.1);  color: #16a34a; }
.dc-card-header-icon.amber  { background: rgba(217,119,6,0.1);  color: #d97706; }
.dc-card-header-icon.purple { background: rgba(109,40,217,0.1); color: #6d28d9; }
.dc-card-header-icon.red    { background: rgba(220,38,38,0.1);  color: #dc2626; }
.dc-card-header-icon.teal   { background: rgba(13,148,136,0.1); color: #0d9488; }
.dc-card-title { font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.7px; color: #8b92a9; }
.dc-card-body { padding: 0; }

/* ── Field rows ── */
.dc-field {
    display: flex; align-items: flex-start;
    padding: 12px 20px;
    border-bottom: 1px solid #f5f6fb;
    gap: 12px;
}
.dc-field:last-child { border-bottom: none; }
.dc-field-label {
    flex: 0 0 160px; font-size: 12px; font-weight: 600;
    color: #8b92a9; text-transform: uppercase; letter-spacing: 0.5px;
    padding-top: 2px; line-height: 1.4;
}
.dc-field-label.required::after { content: ' *'; color: #ef4444; }
.dc-field-value { flex: 1; font-size: 13.5px; color: #2d3748; line-height: 1.5; min-width: 0; }
.dc-field-value a { color: #3c4758; }

/* ── Mono chip ── */
.dc-mono {
    font-family: 'DM Mono', monospace; font-size: 12px;
    background: #f0f2fa; color: #4a5568;
    padding: 3px 9px; border-radius: 5px; display: inline-block;
}

/* ── Form inputs ── */
.dc-page input[type="text"],
.dc-page input[type="email"],
.dc-page input[type="number"],
.dc-page select,
.dc-page textarea {
    padding: 8px 12px !important;
    border: 1.5px solid #e2e5f0 !important;
    border-radius: 8px !important;
    font-size: 13px !important;
    font-family: 'DM Sans', sans-serif !important;
    color: #2d3748 !important;
    background: #fafbfe !important;
    outline: none !important;
    transition: border-color 0.15s, box-shadow 0.15s !important;
    width: 100% !important;
    max-width: 100% !important;
    box-sizing: border-box !important;
}
.dc-page input[type="text"]:focus,
.dc-page input[type="email"]:focus,
.dc-page input[type="number"]:focus,
.dc-page select:focus,
.dc-page textarea:focus {
    border-color: #3c4758 !important;
    box-shadow: 0 0 0 3px rgba(60,71,88,0.1) !important;
    background: #fff !important;
}
.dc-page textarea { resize: vertical !important; }

/* ── Radio group ── */
.dc-radio-group { display: flex; flex-wrap: wrap; gap: 6px; }
.dc-radio-group label {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 6px 14px; border-radius: 20px; cursor: pointer;
    font-size: 13px; font-weight: 500; border: 1.5px solid #e2e5f0;
    background: #fafbfe; color: #5a6482; transition: all 0.15s;
    white-space: nowrap;
}
.dc-radio-group label:hover { border-color: #3c4758; background: #f5f6fa; color: #1a1f2e; }
.dc-radio-group input[type="radio"] { display: none; }
.dc-radio-group input[type="radio"]:checked + span { font-weight: 700; }
.dc-radio-group label:has(input:checked) { border-color: #3c4758; background: rgba(60,71,88,0.08); color: #3c4758; }

/* ── Bottom action bar ── */
.dc-action-bar {
    display: flex; align-items: center; justify-content: flex-end;
    gap: 8px; padding: 18px 0 4px;
    flex-wrap: wrap;
}
.dc-action-bar-left { margin-right: auto; }

/* ── Fix Dolibarr date/time widget styling ── */
.dc-page .tcms { display:inline-flex !important; align-items:center !important; gap:4px !important; flex-wrap:wrap !important; }
.dc-page input.hasDatepicker,
.dc-page input[id^="start_date"],
.dc-page input[id^="due_date"] {
    padding: 7px 10px !important;
    border: 1.5px solid #e2e5f0 !important;
    border-radius: 8px !important;
    font-size: 13px !important;
    font-family: 'DM Sans', sans-serif !important;
    color: #2d3748 !important;
    background: #fafbfe !important;
    width: 120px !important;
    box-sizing: border-box !important;
}
.dc-page select[id^="start_datehour"],
.dc-page select[id^="start_datemin"],
.dc-page select[id^="due_datehour"],
.dc-page select[id^="due_datemin"] {
    padding: 7px 6px !important;
    border: 1.5px solid #e2e5f0 !important;
    border-radius: 8px !important;
    font-size: 13px !important;
    font-family: 'DM Sans', sans-serif !important;
    color: #2d3748 !important;
    background: #fafbfe !important;
    width: auto !important;
}
.dc-page img[id$="DatePicker"] { display:none !important; }

/* ── Ref tag ── */
.dc-ref-tag {
    font-family: 'DM Mono', monospace; font-size: 13px;
    background: rgba(60,71,88,0.08); color: #3c4758;
    padding: 4px 10px; border-radius: 6px; font-weight: 500;
}
</style>
<?php

// Confirmation to delete
if ($action == 'delete') {
    $formconfirm = $form->formconfirm(
        $_SERVER["PHP_SELF"].'?id='.$id,
        $langs->trans('DeleteWorkOrder'),
        $langs->trans('ConfirmDeleteWorkOrder'),
        'confirm_delete',
        '',
        0,
        1
    );
    print $formconfirm;
}

// Show error messages
if (!empty($errors)) {
    foreach ($errors as $err) setEventMessage($err, 'errors');
}

$isEdit   = ($action == 'edit');
$isCreate = ($action == 'create');
$isView   = (!$isEdit && !$isCreate);

$pageTitle = $isCreate ? $langs->trans('NewWorkOrder') : ($isEdit ? $langs->trans('EditWorkOrder') : $langs->trans('WorkOrder'));
$pageSub   = $isCreate ? $langs->trans('FillInWorkOrderDetails') : (isset($object->ref) ? $object->ref : '');

// Helper: status => badge css class
$statusBadgeClass = array(
    'Pending'     => 'status-pending',
    'In Progress' => 'status-inprogress',
    'Completed'   => 'status-completed',
    'Cancelled'   => 'status-cancelled',
);
// Helper: priority => badge css class
$priorityBadgeClass = array(
    'Low'    => 'priority-low',
    'Medium' => 'priority-medium',
    'High'   => 'priority-high',
    'Urgent' => 'priority-urgent',
);

// Open form
if ($isCreate || $isEdit) {
    print '<form method="POST" action="'.$_SERVER['PHP_SELF'].($id > 0 ? '?id='.$id : '').'" enctype="multipart/form-data">';
    print '<input type="hidden" name="action" value="'.($isCreate ? 'add' : 'update').'">';
    print '<input type="hidden" name="token"  value="'.newToken().'">';
    if ($id > 0) print '<input type="hidden" name="id" value="'.$id.'">';
}

print '<div class="dc-page">';

/* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
   PAGE HEADER
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */
print '<div class="dc-header">';
print '  <div class="dc-header-left">';
print '    <div class="dc-header-icon"><i class="fa fa-wrench"></i></div>';
print '    <div>';
print '      <div class="dc-header-title">'.dol_escape_htmltag($pageTitle).'</div>';
if ($pageSub) print '      <div class="dc-header-sub">'.dol_escape_htmltag($pageSub).'</div>';
print '    </div>';
print '  </div>';
print '  <div class="dc-header-actions">';
if ($isView && $id > 0) {
    $curStatus   = !empty($object->status)   ? $object->status   : 'Pending';
    $curPriority = !empty($object->priority) ? $object->priority : 'Medium';
    $statusCss   = isset($statusBadgeClass[$curStatus])     ? $statusBadgeClass[$curStatus]     : 'status-pending';
    $priorityCss = isset($priorityBadgeClass[$curPriority]) ? $priorityBadgeClass[$curPriority] : 'priority-medium';
    print '<span class="dc-badge '.$statusCss.'">'.dol_escape_htmltag($curStatus).'</span>';
    print '<span class="dc-badge '.$priorityCss.'">'.dol_escape_htmltag($curPriority).'</span>';
    print '<a class="dc-btn dc-btn-ghost" href="'.dol_buildpath('/flotte/workorder_list.php', 1).'"><i class="fa fa-arrow-left"></i> '.$langs->trans('BackToList').'</a>';
    if ($user->rights->flotte->write)  print '<a class="dc-btn dc-btn-ghost" href="'.$_SERVER['PHP_SELF'].'?id='.$id.'&action=edit"><i class="fa fa-pen"></i> '.$langs->trans('Modify').'</a>';
    if ($user->rights->flotte->delete) print '<a class="dc-btn dc-btn-danger" href="'.$_SERVER['PHP_SELF'].'?id='.$id.'&action=delete"><i class="fa fa-trash"></i> '.$langs->trans('Delete').'</a>';
}
print '  </div>';
print '</div>';

/* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
   ROW 1 — Work Order Info + Assignment
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */
print '<div class="dc-grid">';

/* ── Card: Work Order Information ── */
print '<div class="dc-card">';
print '  <div class="dc-card-header">';
print '    <div class="dc-card-header-icon blue"><i class="fa fa-clipboard-list"></i></div>';
print '    <span class="dc-card-title">'.$langs->trans('WorkOrderInformation').'</span>';
print '  </div>';
print '  <div class="dc-card-body">';

// Reference
print '  <div class="dc-field">';
print '    <div class="dc-field-label">'.$langs->trans('Reference').'</div>';
print '    <div class="dc-field-value">';
if ($isCreate) {
    print '<em style="color:#9aa0b4;font-size:12.5px;">'.$langs->trans('AutoGenerated').'</em>';
    print '<input type="hidden" name="ref" value="'.dol_escape_htmltag($object->ref).'">';
} elseif ($isEdit) {
    print '<input type="text" name="ref" value="'.dol_escape_htmltag($object->ref).'" readonly style="background:#f5f6fa!important;color:#9aa0b4!important;">';
} else {
    print '<span class="dc-ref-tag">'.dol_escape_htmltag($object->ref).'</span>';
}
print '    </div></div>';

// Date Created
print '  <div class="dc-field">';
print '    <div class="dc-field-label">'.$langs->trans('DateCreation').'</div>';
print '    <div class="dc-field-value">';
if ($isCreate) {
    print '<em style="color:#9aa0b4;font-size:12.5px;">'.dol_print_date(dol_now(), 'dayhour').'</em>';
} else {
    print dol_print_date($db->jdate($object->tms), 'dayhour');
}
print '    </div></div>';

// Requested By
print '  <div class="dc-field">';
print '    <div class="dc-field-label">'.$langs->trans('RequestedBy').'</div>';
print '    <div class="dc-field-value">';
if ($isCreate || $isEdit) print '<input type="text" name="requested_by" value="'.dol_escape_htmltag($object->requested_by).'" placeholder="'.$langs->trans('NameOrDepartment').'">';
else print dol_escape_htmltag($object->requested_by ?: '&mdash;');
print '    </div></div>';

// Priority
print '  <div class="dc-field">';
print '    <div class="dc-field-label">'.$langs->trans('Priority').'</div>';
print '    <div class="dc-field-value">';
if ($isCreate || $isEdit) {
    print '<div class="dc-radio-group">';
    foreach (['Low', 'Medium', 'High', 'Urgent'] as $p) {
        $chk = ($object->priority == $p) ? ' checked' : '';
        print '<label><input type="radio" name="priority" value="'.$p.'"'.$chk.'><span>'.dol_escape_htmltag($langs->trans($p)).'</span></label>';
    }
    print '</div>';
} else {
    $p = !empty($object->priority) ? $object->priority : 'Medium';
    $pCss = isset($priorityBadgeClass[$p]) ? $priorityBadgeClass[$p] : 'priority-medium';
    print '<span class="dc-badge '.$pCss.'">'.dol_escape_htmltag($langs->trans($p)).'</span>';
}
print '    </div></div>';

print '  </div>';
print '</div>';

/* ── Card: Assignment & Status ── */
print '<div class="dc-card">';
print '  <div class="dc-card-header">';
print '    <div class="dc-card-header-icon green"><i class="fa fa-user-cog"></i></div>';
print '    <span class="dc-card-title">'.$langs->trans('AssignmentAndStatus').'</span>';
print '  </div>';
print '  <div class="dc-card-body">';

// Assigned Driver
print '  <div class="dc-field">';
print '    <div class="dc-field-label">'.$langs->trans('AssignedTo').'</div>';
print '    <div class="dc-field-value">';
if ($isCreate || $isEdit) {
    if (!empty($drivers)) {
        print $form->selectarray('fk_driver', $drivers, (int)$object->fk_driver, 1, 0, 0, '', 0, 0, 0, '', '');
    } else {
        print '<em style="color:#9aa0b4;font-size:12.5px;">'.$langs->trans('NoActiveDriversFound').'</em>';
        print '<input type="hidden" name="fk_driver" value="0">';
    }
} else {
    print dol_escape_htmltag(trim($object->driver_fullname) ?: '&mdash;');
}
print '    </div></div>';

// Responsible Person
print '  <div class="dc-field">';
print '    <div class="dc-field-label">'.$langs->trans('ResponsiblePerson').'</div>';
print '    <div class="dc-field-value">';
if ($isCreate || $isEdit) print '<input type="text" name="responsible_person" value="'.dol_escape_htmltag($object->responsible_person).'" placeholder="'.$langs->trans('SupervisorOrApprover').'">';
else print dol_escape_htmltag($object->responsible_person ?: '&mdash;');
print '    </div></div>';

// Status
print '  <div class="dc-field">';
print '    <div class="dc-field-label">'.$langs->trans('Status').'</div>';
print '    <div class="dc-field-value">';
if ($isCreate || $isEdit) {
    print '<div class="dc-radio-group">';
    foreach (['Pending', 'In Progress', 'Completed', 'Cancelled'] as $s) {
        $chk = ($object->status == $s) ? ' checked' : '';
        print '<label><input type="radio" name="status" value="'.dol_escape_htmltag($s).'"'.$chk.'><span>'.dol_escape_htmltag($s).'</span></label>';
    }
    print '</div>';
} else {
    $curStatus = !empty($object->status) ? $object->status : 'Pending';
    $sCss = isset($statusBadgeClass[$curStatus]) ? $statusBadgeClass[$curStatus] : 'status-pending';
    print '<span class="dc-badge '.$sCss.'">'.dol_escape_htmltag($curStatus).'</span>';
}
print '    </div></div>';

print '  </div>';
print '</div>';

print '</div>';// dc-grid row1

/* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
   ROW 2 — Schedule (+ spacer)
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */
print '<div class="dc-grid">';

/* ── Card: Schedule ── */
print '<div class="dc-card">';
print '  <div class="dc-card-header">';
print '    <div class="dc-card-header-icon amber"><i class="fa fa-calendar-alt"></i></div>';
print '    <span class="dc-card-title">'.$langs->trans('Schedule').'</span>';
print '  </div>';
print '  <div class="dc-card-body">';

// Start Date
print '  <div class="dc-field">';
print '    <div class="dc-field-label">'.$langs->trans('StartDate').'</div>';
print '    <div class="dc-field-value">';
if ($isCreate || $isEdit) {
    $sdVal = !empty($object->start_date) ? htmlspecialchars($object->start_date) : '';
    print '<div style="display:flex;gap:8px;flex-wrap:wrap;">';
    print $form->selectDate($object->start_date ?: -1, 'start_date', 1, 1, 1, '', 1, 1);
    print '</div>';
} else {
    print $object->start_date ? dol_print_date($db->jdate($object->start_date), 'dayhour') : '&mdash;';
}
print '    </div></div>';

// Due Date
print '  <div class="dc-field">';
print '    <div class="dc-field-label">'.$langs->trans('DueDate').'</div>';
print '    <div class="dc-field-value">';
if ($isCreate || $isEdit) {
    $ddVal = !empty($object->due_date) ? htmlspecialchars($object->due_date) : '';
    print '<div style="display:flex;gap:8px;flex-wrap:wrap;">';
    print $form->selectDate($object->due_date ?: -1, 'due_date', 1, 1, 1, '', 1, 1);
    print '</div>';
} else {
    print $object->due_date ? dol_print_date($db->jdate($object->due_date), 'dayhour') : '&mdash;';
}
print '    </div></div>';

print '  </div>';
print '</div>';

/* ── Spacer (empty cell keeps 2-col grid balanced) ── */
print '<div></div>';

print '</div>';// dc-grid row2

/* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
   ROW 3 — Task Details (full width)
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */
print '<div class="dc-card" style="margin-bottom:20px;">';
print '  <div class="dc-card-header">';
print '    <div class="dc-card-header-icon teal"><i class="fa fa-tasks"></i></div>';
print '    <span class="dc-card-title">'.$langs->trans('TaskDetails').'</span>';
print '  </div>';
print '  <div class="dc-card-body">';

// Task to Perform
print '  <div class="dc-field" style="flex-direction:column;gap:8px;">';
print '    <div class="dc-field-label" style="flex:none;">'.$langs->trans('TaskToPerform').'</div>';
print '    <div class="dc-field-value" style="width:100%;">';
if ($isCreate || $isEdit) print '<textarea name="task_to_perform" rows="3" placeholder="'.dol_escape_htmltag($langs->trans('DescribeTasksToBePerformed')).'">'.dol_escape_htmltag($object->task_to_perform).'</textarea>';
else print '<div style="white-space:pre-wrap;">'.nl2br(dol_escape_htmltag($object->task_to_perform ?: '&mdash;')).'</div>';
print '    </div></div>';

// Problem Description
print '  <div class="dc-field" style="flex-direction:column;gap:8px;">';
print '    <div class="dc-field-label" style="flex:none;">'.$langs->trans('ProblemDescription').'</div>';
print '    <div class="dc-field-value" style="width:100%;">';
if ($isCreate || $isEdit) print '<textarea name="problem_description" rows="3" placeholder="'.dol_escape_htmltag($langs->trans('DescribeProblemOrSymptoms')).'">'.dol_escape_htmltag($object->problem_description).'</textarea>';
else print '<div style="white-space:pre-wrap;">'.nl2br(dol_escape_htmltag($object->problem_description ?: '&mdash;')).'</div>';
print '    </div></div>';

print '  </div>';
print '</div>';

/* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
   ROW 4 — Technician Notes (full width)
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */
print '<div class="dc-card" style="margin-bottom:20px;">';
print '  <div class="dc-card-header">';
print '    <div class="dc-card-header-icon purple"><i class="fa fa-sticky-note"></i></div>';
print '    <span class="dc-card-title">'.$langs->trans('NotesAndApproval').'</span>';
print '  </div>';
print '  <div class="dc-card-body">';

// Technician Notes
print '  <div class="dc-field" style="flex-direction:column;gap:8px;">';
print '    <div class="dc-field-label" style="flex:none;">'.$langs->trans('TechnicianNotes').'</div>';
print '    <div class="dc-field-value" style="width:100%;">';
if ($isCreate || $isEdit) print '<textarea name="technician_notes" rows="5" placeholder="'.dol_escape_htmltag($langs->trans('FindingsObservationsRecommendations')).'">'.dol_escape_htmltag($object->technician_notes).'</textarea>';
else print '<div style="white-space:pre-wrap;">'.nl2br(dol_escape_htmltag($object->technician_notes ?: '&mdash;')).'</div>';
print '    </div></div>';

print '  </div>';
print '</div>';

/* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
   BOTTOM ACTION BAR
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */
if ($isCreate || $isEdit) {
    print '<div class="dc-action-bar">';
    print '<a class="dc-btn dc-btn-ghost dc-action-bar-left" href="'.dol_buildpath('/flotte/workorder_list.php', 1).'"><i class="fa fa-arrow-left"></i> '.$langs->trans('BackToList').'</a>';
    print '<a class="dc-btn dc-btn-ghost" href="'.($id > 0 ? $_SERVER['PHP_SELF'].'?id='.$id : dol_buildpath('/flotte/workorder_list.php', 1)).'"><i class="fa fa-times"></i> '.$langs->trans('Cancel').'</a>';
    print '<button type="submit" class="dc-btn dc-btn-primary"><i class="fa fa-check"></i> '.($isCreate ? $langs->trans('Create') : $langs->trans('Save')).'</button>';
    print '</div>';
    print '</form>';
} elseif ($id > 0) {
    print '<div class="dc-action-bar">';
    print '<a class="dc-btn dc-btn-ghost dc-action-bar-left" href="'.dol_buildpath('/flotte/workorder_list.php', 1).'"><i class="fa fa-arrow-left"></i> '.$langs->trans('BackToList').'</a>';
    if ($user->rights->flotte->write)  print '<a class="dc-btn dc-btn-ghost" href="'.$_SERVER['PHP_SELF'].'?id='.$id.'&action=edit"><i class="fa fa-pen"></i> '.$langs->trans('Modify').'</a>';
    if ($user->rights->flotte->delete) print '<a class="dc-btn dc-btn-danger" href="'.$_SERVER['PHP_SELF'].'?id='.$id.'&action=delete"><i class="fa fa-trash"></i> '.$langs->trans('Delete').'</a>';
    print '</div>';
}

print '</div>';// dc-page

llxFooter();
$db->close();
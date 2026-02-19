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

// Function to generate next work order reference
function getNextWorkOrderRef($db, $entity) {
    $prefix = "WO-";
    
    // Get the last reference from database
    $sql = "SELECT ref FROM ".MAIN_DB_PREFIX."flotte_workorder";
    $sql .= " WHERE entity = ".$entity;
    $sql .= " AND ref LIKE '".$prefix."%'";
    $sql .= " ORDER BY ref DESC LIMIT 1";
    
    $resql = $db->query($sql);
    if ($resql && $db->num_rows($resql) > 0) {
        $obj = $db->fetch_object($resql);
        $last_ref = $obj->ref;
        
        // Extract the numeric part and increment
        $numeric_part = (int)str_replace($prefix, '', $last_ref);
        $next_number = $numeric_part + 1;
    } else {
        // No existing references, start from 1
        $next_number = 1;
    }
    
    // Format with leading zeros (e.g., WO-0001)
    return $prefix.str_pad($next_number, 4, '0', STR_PAD_LEFT);
}

// Function to convert date to MySQL format
function convertDateToMysql($datestring) {
    if (empty($datestring)) {
        return '';
    }
    
    // Handle different date formats
    $timestamp = dol_stringtotime($datestring);
    if ($timestamp === false) {
        // Try parsing common formats
        $formats = ['m/d/Y', 'd/m/Y', 'Y-m-d', 'Y/m/d'];
        foreach ($formats as $format) {
            $date = DateTime::createFromFormat($format, $datestring);
            if ($date !== false) {
                return $date->format('Y-m-d');
            }
        }
        return '';
    }
    
    return dol_print_date($timestamp, '%Y-%m-%d', 'tzserver');
}

// Get parameters
$id = GETPOST('id', 'int');
$action = GETPOST('action', 'aZ09') ? GETPOST('action', 'aZ09') : 'view';
$confirm = GETPOST('confirm', 'alpha');
$cancel = GETPOST('cancel', 'alpha');
$contextpage = GETPOST('contextpage','aZ')?GETPOST('contextpage','aZ'):'workordercard';

// Security check
restrictedArea($user, 'flotte');

// Initialize variables
$object = new stdClass();
$object->id = 0;
$object->ref = '';
$object->fk_vehicle = 0;
$object->fk_vendor = 0;
$object->required_by = '';
$object->reading = 0;
$object->note = '';
$object->status = '';
$object->price = 0;
$object->description = '';

$error = 0;
$errors = array();

// Generate reference for new work order
if ($action == 'create' && empty($object->ref)) {
    $object->ref = getNextWorkOrderRef($db, $conf->entity);
}

/*
 * Actions
 */

if ($cancel) {
    $action = 'view';
    if (!empty($backtopage)) {
        header("Location: ".$backtopage);
        exit;
    }
}

if ($action == 'confirm_delete' && $confirm == 'yes' && $user->rights->flotte->delete) {
    $db->begin();
    
    $sql = "DELETE FROM ".MAIN_DB_PREFIX."flotte_workorder WHERE rowid = ".((int) $id);
    $result = $db->query($sql);
    
    if ($result) {
        $db->commit();
        header("Location: " . dol_buildpath('/flotte/workorder_list.php', 1));
        exit;
    } else {
        $db->rollback();
        $error++;
        setEventMessages($langs->trans("ErrorDeletingWorkOrder"), null, 'errors');
    }
}

if ($action == 'add') {
    $db->begin();
    
    // Get form data
    $ref = GETPOST('ref', 'alpha');
    $fk_vehicle = GETPOST('fk_vehicle', 'int');
    $fk_vendor = GETPOST('fk_vendor', 'int');
    
    // Fix: Convert date to MySQL format
    $required_by_raw = GETPOST('required_by', 'alpha');
    $required_by = '';
    if (!empty($required_by_raw)) {
        // Handle Dolibarr date format
        $day = GETPOST('required_byday', 'int');
        $month = GETPOST('required_bymonth', 'int');
        $year = GETPOST('required_byyear', 'int');
        
        if ($day > 0 && $month > 0 && $year > 0) {
            $required_by = sprintf('%04d-%02d-%02d', $year, $month, $day);
        } else {
            $required_by = convertDateToMysql($required_by_raw);
        }
    }
    
    $reading = GETPOST('reading', 'int');
    $note = GETPOST('note', 'alpha');
    $status = GETPOST('status', 'alpha');
    $price = GETPOST('price', 'alpha');
    $description = GETPOST('description', 'alpha');
    
    // Auto-generate reference if empty
    if (empty($ref)) {
        $ref = getNextWorkOrderRef($db, $conf->entity);
    }
    
    if (empty($ref)) {
        $error++;
        $errors[] = $langs->trans("ErrorFieldRequired", $langs->trans("Reference"));
    }
    if (empty($fk_vehicle)) {
        $error++;
        $errors[] = $langs->trans("ErrorFieldRequired", $langs->trans("Vehicle"));
    }
    
    if (!$error) {
        $sql = "INSERT INTO ".MAIN_DB_PREFIX."flotte_workorder (";
        $sql .= "ref, entity, fk_vehicle, fk_vendor, required_by, reading, note, status, price, description, fk_user_author";
        $sql .= ") VALUES (";
        $sql .= "'".$db->escape($ref)."', ".$conf->entity.", ";
        $sql .= $fk_vehicle.", ";
        $sql .= ($fk_vendor > 0 ? $fk_vendor : "NULL").", ";
        $sql .= "'".$db->escape($required_by)."', ";
        $sql .= $reading.", ";
        $sql .= "'".$db->escape($note)."', ";
        $sql .= "'".$db->escape($status)."', ";
        $sql .= ($price ? $price : "0").", ";
        $sql .= "'".$db->escape($description)."', ";
        $sql .= $user->id;
        $sql .= ")";
        
        $result = $db->query($sql);
        if ($result) {
            $id = $db->last_insert_id(MAIN_DB_PREFIX."flotte_workorder");
            $db->commit();
            $action = 'view';
            setEventMessages($langs->trans("WorkOrderCreatedSuccessfully"), null, 'mesgs');
        } else {
            $db->rollback();
            $error++;
            $errors[] = $langs->trans("ErrorCreatingWorkOrder") . ": " . $db->lasterror();
        }
    } else {
        $db->rollback();
    }
}

if ($action == 'update') {
    $db->begin();
    
    // Get form data
    $ref = GETPOST('ref', 'alpha');
    $fk_vehicle = GETPOST('fk_vehicle', 'int');
    $fk_vendor = GETPOST('fk_vendor', 'int');
    
    // Fix: Convert date to MySQL format
    $required_by_raw = GETPOST('required_by', 'alpha');
    $required_by = '';
    if (!empty($required_by_raw)) {
        // Handle Dolibarr date format
        $day = GETPOST('required_byday', 'int');
        $month = GETPOST('required_bymonth', 'int');
        $year = GETPOST('required_byyear', 'int');
        
        if ($day > 0 && $month > 0 && $year > 0) {
            $required_by = sprintf('%04d-%02d-%02d', $year, $month, $day);
        } else {
            $required_by = convertDateToMysql($required_by_raw);
        }
    }
    
    $reading = GETPOST('reading', 'int');
    $note = GETPOST('note', 'alpha');
    $status = GETPOST('status', 'alpha');
    $price = GETPOST('price', 'alpha');
    $description = GETPOST('description', 'alpha');
    
    if (empty($ref)) {
        $error++;
        $errors[] = $langs->trans("ErrorFieldRequired", $langs->trans("Reference"));
    }
    if (empty($fk_vehicle)) {
        $error++;
        $errors[] = $langs->trans("ErrorFieldRequired", $langs->trans("Vehicle"));
    }
    
    if (!$error) {
        $sql = "UPDATE ".MAIN_DB_PREFIX."flotte_workorder SET ";
        $sql .= "ref = '".$db->escape($ref)."', ";
        $sql .= "fk_vehicle = ".$fk_vehicle.", ";
        $sql .= "fk_vendor = ".($fk_vendor > 0 ? $fk_vendor : "NULL").", ";
        $sql .= "required_by = '".$db->escape($required_by)."', ";
        $sql .= "reading = ".$reading.", ";
        $sql .= "note = '".$db->escape($note)."', ";
        $sql .= "status = '".$db->escape($status)."', ";
        $sql .= "price = ".($price ? $price : "0").", ";
        $sql .= "description = '".$db->escape($description)."', ";
        $sql .= "fk_user_modif = ".$user->id." ";
        $sql .= "WHERE rowid = ".((int) $id);
        
        $result = $db->query($sql);
        if ($result) {
            $db->commit();
            $action = 'view';
            setEventMessages($langs->trans("WorkOrderUpdatedSuccessfully"), null, 'mesgs');
        } else {
            $db->rollback();
            $error++;
            $errors[] = $langs->trans("ErrorUpdatingWorkOrder") . ": " . $db->lasterror();
        }
    } else {
        $db->rollback();
    }
}

// Load object data
if ($id > 0) {
    $sql = "SELECT w.*, v.ref as vehicle_ref, v.maker, v.model, ven.name as vendor_name 
            FROM ".MAIN_DB_PREFIX."flotte_workorder as w 
            LEFT JOIN ".MAIN_DB_PREFIX."flotte_vehicle as v ON w.fk_vehicle = v.rowid 
            LEFT JOIN ".MAIN_DB_PREFIX."flotte_vendor as ven ON w.fk_vendor = ven.rowid 
            WHERE w.rowid = ".((int) $id);
    $resql = $db->query($sql);
    if ($resql) {
        $object = $db->fetch_object($resql);
        if (!$object) {
            header("HTTP/1.0 404 Not Found");
            print $langs->trans("WorkOrderNotFound");
            exit;
        }
    } else {
        header("HTTP/1.0 500 Internal Server Error");
        print $langs->trans("ErrorLoadingWorkOrder") . ": " . $db->lasterror();
        exit;
    }
}

// Get vehicles and vendors for dropdowns
$vehicles = array();
$sql_vehicles = "SELECT rowid, ref, maker, model FROM ".MAIN_DB_PREFIX."flotte_vehicle WHERE entity = ".$conf->entity." ORDER BY ref";
$resql_vehicles = $db->query($sql_vehicles);
if ($resql_vehicles) {
    while ($obj = $db->fetch_object($resql_vehicles)) {
        $vehicles[$obj->rowid] = $obj->ref . ' - ' . $obj->maker . ' ' . $obj->model;
    }
}

$vendors = array();
$sql_vendors = "SELECT rowid, name FROM ".MAIN_DB_PREFIX."flotte_vendor WHERE entity = ".$conf->entity." ORDER BY name";
$resql_vendors = $db->query($sql_vendors);
if ($resql_vendors) {
    while ($obj = $db->fetch_object($resql_vendors)) {
        $vendors[$obj->rowid] = $obj->name;
    }
}

$form = new Form($db);

// Initialize technical object to manage hooks
$hookmanager->initHooks(array('workordercard'));

/*
 * View
 */

$title = $langs->trans('WorkOrder');
if ($action == 'create') {
    $title = $langs->trans('NewWorkOrder');
} elseif ($action == 'edit') {
    $title = $langs->trans('EditWorkOrder');
} elseif ($id > 0) {
    $title = $langs->trans('WorkOrder') . " " . $object->ref;
}

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

/* ── Status badges ── */
.dc-badge {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 4px 11px; border-radius: 20px;
    font-size: 11.5px; font-weight: 600; white-space: nowrap;
}
.dc-badge::before { content: ''; width: 6px; height: 6px; border-radius: 50%; flex-shrink: 0; }
.dc-badge.pending     { background: #fff8ec; color: #b45309; }
.dc-badge.pending::before     { background: #f59e0b; }
.dc-badge.in-progress { background: #eff6ff; color: #1d4ed8; }
.dc-badge.in-progress::before { background: #3b82f6; }
.dc-badge.completed   { background: #edfaf3; color: #1a7d4a; }
.dc-badge.completed::before   { background: #22c55e; }
.dc-badge.cancelled   { background: #fef2f2; color: #b91c1c; }
.dc-badge.cancelled::before   { background: #ef4444; }

/* ── Buttons ── */
.dc-btn {
    display: inline-flex; align-items: center; gap: 7px;
    padding: 8px 16px; border-radius: 6px;
    font-size: 13px; font-weight: 600;
    text-decoration: none !important; cursor: pointer;
    font-family: 'DM Sans', sans-serif; white-space: nowrap;
    transition: all 0.15s ease; border: none;
}
.dc-btn-primary { background: #3c4758 !important; color: #fff !important; }
.dc-btn-primary:hover { background: #2a3346 !important; color: #fff !important; }
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
button.dc-btn-primary { background: #3c4758 !important; color: #fff !important; border: none !important; }
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

/* ── Mono / chip ── */
.dc-mono {
    font-family: 'DM Mono', monospace; font-size: 12px;
    background: #f0f2fa; color: #4a5568;
    padding: 3px 9px; border-radius: 5px; display: inline-block;
}
.dc-chip {
    display: inline-flex; align-items: center; gap: 6px;
    font-size: 12.5px; font-weight: 600; color: #3c4758;
    background: rgba(60,71,88,0.07); padding: 4px 10px; border-radius: 6px;
}

/* ── Form inputs ── */
.dc-page input[type="text"],
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
.dc-page input[type="number"]:focus,
.dc-page select:focus,
.dc-page textarea:focus {
    border-color: #3c4758 !important;
    box-shadow: 0 0 0 3px rgba(60,71,88,0.1) !important;
    background: #fff !important;
}
.dc-page input[type="checkbox"] { width: auto !important; cursor: pointer; }
.dc-page textarea { resize: vertical !important; }

/* ── Bottom action bar ── */
.dc-action-bar {
    display: flex; align-items: center; justify-content: flex-end;
    gap: 8px; padding: 18px 0 4px;
    flex-wrap: wrap;
}
.dc-action-bar-left { margin-right: auto; }

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
        $_SERVER["PHP_SELF"] . '?id=' . $id,
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
    foreach ($errors as $error_msg) {
        setEventMessage($error_msg, 'errors');
    }
}

$isEdit   = ($action == 'edit');
$isCreate = ($action == 'create');
$isView   = (!$isEdit && !$isCreate);

$pageTitle = $isCreate ? $langs->trans('NewWorkOrder') : ($isEdit ? $langs->trans('EditWorkOrder') : $langs->trans('WorkOrder'));
$pageSub   = $isCreate ? $langs->trans('FillInWorkOrderDetails') : (isset($object->ref) ? $object->ref : '');

// Form start
if ($isCreate || $isEdit) {
    print '<form method="POST" action="'.$_SERVER['PHP_SELF'].($id > 0 ? '?id='.$id : '').'">';
    print '<input type="hidden" name="action" value="'.($isCreate ? 'add' : 'update').'">';
    print '<input type="hidden" name="token" value="'.newToken().'">';
    if ($id > 0) {
        print '<input type="hidden" name="id" value="'.$id.'">';
    }
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
    if (!empty($object->status)) {
        $stClass = strtolower(str_replace(' ', '-', $object->status));
        $stLabel = ($object->status == 'In Progress') ? $langs->trans('InProgress') : $langs->trans(ucfirst(strtolower($object->status)));
        print '<span class="dc-badge '.$stClass.'">'.dol_escape_htmltag($stLabel).'</span>';
    }
    print '<a class="dc-btn dc-btn-ghost" href="'.dol_buildpath('/flotte/workorder_list.php', 1).'"><i class="fa fa-arrow-left"></i> '.$langs->trans('BackToList').'</a>';
    print '<a class="dc-btn dc-btn-ghost" href="'.$_SERVER['PHP_SELF'].'?id='.$id.'&action=edit"><i class="fa fa-pen"></i> '.$langs->trans('Modify').'</a>';
    print '<a class="dc-btn dc-btn-danger" href="'.$_SERVER['PHP_SELF'].'?id='.$id.'&action=delete"><i class="fa fa-trash"></i> '.$langs->trans('Delete').'</a>';
}
print '  </div>';
print '</div>';

/* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
   ROW 1 — Work Order Info + Additional Details
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */
print '<div class="dc-grid">';

/* ── Card: Work Order Information ── */
print '<div class="dc-card">';
print '  <div class="dc-card-header">';
print '    <div class="dc-card-header-icon blue"><i class="fa fa-wrench"></i></div>';
print '    <span class="dc-card-title">'.$langs->trans('WorkOrderInformation').'</span>';
print '  </div>';
print '  <div class="dc-card-body">';

// Reference
print '  <div class="dc-field">';
print '    <div class="dc-field-label">'.$langs->trans('Reference').'</div>';
print '    <div class="dc-field-value">';
if ($isCreate) {
    print '<em style="color:#9aa0b4;font-size:12.5px;">'.$langs->trans('AutoGenerated').'</em>';
    print '<input type="hidden" name="ref" value="'.(isset($object->ref) ? dol_escape_htmltag($object->ref) : '').'">';
} elseif ($isEdit) {
    print '<input type="text" name="ref" value="'.(isset($object->ref) ? dol_escape_htmltag($object->ref) : '').'" readonly style="background:#f5f6fa!important;color:#9aa0b4!important;">';
} else {
    print '<span class="dc-ref-tag">'.dol_escape_htmltag($object->ref).'</span>';
}
print '    </div></div>';

// Vehicle
print '  <div class="dc-field">';
print '    <div class="dc-field-label required">'.$langs->trans('Vehicle').'</div>';
print '    <div class="dc-field-value">';
if ($isCreate || $isEdit) {
    print $form->selectarray('fk_vehicle', $vehicles, (isset($object->fk_vehicle) ? $object->fk_vehicle : ''), 1);
} else {
    if (!empty($object->vehicle_ref)) {
        $vehicle_info = $object->vehicle_ref;
        if (!empty($object->maker) && !empty($object->model)) {
            $vehicle_info .= ' - ' . $object->maker . ' ' . $object->model;
        }
        print '<span class="dc-chip"><i class="fa fa-car" style="font-size:11px;opacity:0.6;"></i>'.dol_escape_htmltag($vehicle_info).'</span>';
    } else {
        print '<span style="color:#c4c9d8;">&mdash;</span>';
    }
}
print '    </div></div>';

// Vendor
print '  <div class="dc-field">';
print '    <div class="dc-field-label">'.$langs->trans('Vendor').'</div>';
print '    <div class="dc-field-value">';
if ($isCreate || $isEdit) {
    print $form->selectarray('fk_vendor', $vendors, (isset($object->fk_vendor) ? $object->fk_vendor : ''), 1);
} else {
    if (!empty($object->vendor_name)) {
        print '<span class="dc-chip"><i class="fa fa-building" style="font-size:11px;opacity:0.6;"></i>'.dol_escape_htmltag($object->vendor_name).'</span>';
    } else {
        print '<span style="color:#c4c9d8;">&mdash;</span>';
    }
}
print '    </div></div>';

// Required By
print '  <div class="dc-field">';
print '    <div class="dc-field-label">'.$langs->trans('RequiredBy').'</div>';
print '    <div class="dc-field-value">';
if ($isCreate || $isEdit) {
    print $form->selectDate((isset($object->required_by) && $object->required_by ? $db->jdate($object->required_by) : ''), 'required_by', 0, 0, 1, '', 1, 1);
} else {
    print (!empty($object->required_by) ? dol_print_date($db->jdate($object->required_by), 'day') : '<span style="color:#c4c9d8;">&mdash;</span>');
}
print '    </div></div>';

// Reading (Meter)
print '  <div class="dc-field">';
print '    <div class="dc-field-label">'.$langs->trans('Reading').'</div>';
print '    <div class="dc-field-value">';
if ($isCreate || $isEdit) {
    print '<input type="number" name="reading" value="'.(isset($object->reading) ? (int)$object->reading : 0).'" min="0" step="1">';
} else {
    print (!empty($object->reading) ? '<span class="dc-mono">'.number_format((int)$object->reading).' km</span>' : '&mdash;');
}
print '    </div></div>';

print '  </div>';// card-body
print '</div>';  // dc-card

/* ── Card: Additional Details ── */
print '<div class="dc-card">';
print '  <div class="dc-card-header">';
print '    <div class="dc-card-header-icon amber"><i class="fa fa-info-circle"></i></div>';
print '    <span class="dc-card-title">'.$langs->trans('AdditionalInformation').'</span>';
print '  </div>';
print '  <div class="dc-card-body">';

// Status
print '  <div class="dc-field">';
print '    <div class="dc-field-label">'.$langs->trans('Status').'</div>';
print '    <div class="dc-field-value">';
if ($isCreate || $isEdit) {
    $status_options = array(
        'Pending'     => $langs->trans('Pending'),
        'In Progress' => $langs->trans('InProgress'),
        'Completed'   => $langs->trans('Completed'),
        'Cancelled'   => $langs->trans('Cancelled'),
    );
    print $form->selectarray('status', $status_options, (isset($object->status) ? $object->status : 'Pending'), 1);
} else {
    if (!empty($object->status)) {
        $stClass = strtolower(str_replace(' ', '-', $object->status));
        $stLabel = ($object->status == 'In Progress') ? $langs->trans('InProgress') : $langs->trans(ucfirst(strtolower($object->status)));
        print '<span class="dc-badge '.$stClass.'">'.dol_escape_htmltag($stLabel).'</span>';
    } else {
        print '<span style="color:#c4c9d8;">&mdash;</span>';
    }
}
print '    </div></div>';

// Price
print '  <div class="dc-field">';
print '    <div class="dc-field-label">'.$langs->trans('Price').'</div>';
print '    <div class="dc-field-value">';
if ($isCreate || $isEdit) {
    print '<input type="number" name="price" value="'.(isset($object->price) ? (float)$object->price : 0).'" step="0.01" min="0">';
} else {
    print '<span class="dc-mono">'.(isset($object->price) && $object->price ? price($object->price) : price(0)).'</span>';
}
print '    </div></div>';

print '  </div>';// card-body
print '</div>';  // dc-card

print '</div>';// dc-grid row1

/* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
   ROW 2 — Description
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */
if ($isCreate || $isEdit || !empty($object->description)) {
    print '<div class="dc-card" style="margin-bottom:20px;">';
    print '  <div class="dc-card-header">';
    print '    <div class="dc-card-header-icon blue"><i class="fa fa-align-left"></i></div>';
    print '    <span class="dc-card-title">'.$langs->trans('Description').'</span>';
    print '  </div>';
    print '  <div class="dc-card-body">';
    print '  <div class="dc-field" style="flex-direction:column;gap:8px;">';
    print '    <div class="dc-field-value" style="width:100%;">';
    if ($isCreate || $isEdit) {
        print '<textarea name="description" rows="3" style="min-height:70px;">'.dol_escape_htmltag(isset($object->description) ? $object->description : '').'</textarea>';
    } else {
        print '<div style="font-size:13.5px;color:#2d3748;line-height:1.7;">'.nl2br(dol_escape_htmltag($object->description)).'</div>';
    }
    print '    </div>';
    print '  </div>';
    print '  </div>';
    print '</div>';
}

/* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
   ROW 3 — Notes
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */
if ($isCreate || $isEdit || !empty($object->note)) {
    print '<div class="dc-card" style="margin-bottom:20px;">';
    print '  <div class="dc-card-header">';
    print '    <div class="dc-card-header-icon purple"><i class="fa fa-sticky-note"></i></div>';
    print '    <span class="dc-card-title">'.$langs->trans('Notes').'</span>';
    print '  </div>';
    print '  <div class="dc-card-body">';
    print '  <div class="dc-field" style="flex-direction:column;gap:8px;">';
    print '    <div class="dc-field-value" style="width:100%;">';
    if ($isCreate || $isEdit) {
        print '<textarea name="note" rows="4" style="min-height:90px;">'.dol_escape_htmltag(isset($object->note) ? $object->note : '').'</textarea>';
    } else {
        print '<div style="font-size:13.5px;color:#2d3748;line-height:1.7;">'.nl2br(dol_escape_htmltag($object->note)).'</div>';
    }
    print '    </div>';
    print '  </div>';
    print '  </div>';
    print '</div>';
}

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
    print '<a class="dc-btn dc-btn-ghost" href="'.$_SERVER['PHP_SELF'].'?id='.$id.'&action=edit"><i class="fa fa-pen"></i> '.$langs->trans('Modify').'</a>';
    print '<a class="dc-btn dc-btn-danger" href="'.$_SERVER['PHP_SELF'].'?id='.$id.'&action=delete"><i class="fa fa-trash"></i> '.$langs->trans('Delete').'</a>';
    print '</div>';
}

print '</div>';// dc-page

// End of page
llxFooter();
$db->close();
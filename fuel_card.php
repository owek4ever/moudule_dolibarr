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

// Function to generate next fuel record reference
function getNextFuelRef($db, $entity) {
    $prefix = "FUEL-";
    
    // Get the last reference from database
    $sql = "SELECT ref FROM ".MAIN_DB_PREFIX."flotte_fuel";
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
    
    // Format with leading zeros (e.g., FUEL-0001)
    return $prefix.str_pad($next_number, 4, '0', STR_PAD_LEFT);
}

// Get parameters
$id = GETPOST('id', 'int');
$action = GETPOST('action', 'aZ09') ? GETPOST('action', 'aZ09') : 'view';
$confirm = GETPOST('confirm', 'alpha');
$cancel = GETPOST('cancel', 'alpha');

// Security check
restrictedArea($user, 'flotte');

// Initialize variables
$object = new stdClass();
$object->id = 0;
$object->ref = '';
$object->fk_vehicle = '';
$object->date = '';
$object->start_meter = '';
$object->reference = '';
$object->state = '';
$object->note = '';
$object->complete_fillup = 0;
$object->fuel_source = '';
$object->qty = '';
$object->cost_unit = '';

$error = 0;
$errors = array();

// Generate reference for new fuel record
if ($action == 'create' && empty($object->ref)) {
    $object->ref = getNextFuelRef($db, $conf->entity);
}

// Get vehicle list for dropdown
$vehicles = array();
$sql = "SELECT rowid, maker, model, license_plate FROM ".MAIN_DB_PREFIX."flotte_vehicle WHERE entity IN (".getEntity('flotte').") ORDER BY maker, model";
$resql = $db->query($sql);
if ($resql) {
    while ($obj = $db->fetch_object($resql)) {
        $vehicles[$obj->rowid] = $obj->maker . ' ' . $obj->model . ' (' . $obj->license_plate . ')';
    }
}

/*
 * Actions
 */

if ($cancel) {
    $action = 'view';
}

if ($action == 'confirm_delete' && $confirm == 'yes' && $user->rights->flotte->delete) {
    $db->begin();
    
    $sql = "DELETE FROM ".MAIN_DB_PREFIX."flotte_fuel WHERE rowid = ".((int) $id);
    $result = $db->query($sql);
    
    if ($result) {
        $db->commit();
        header("Location: " . dol_buildpath('/flotte/fuel_list.php', 1));
        exit;
    } else {
        $db->rollback();
        $error++;
        setEventMessages($langs->trans("ErrorDeletingFuelRecord"), null, 'errors');
    }
}

if ($action == 'add') {
    $db->begin();
    
    // Get form data
    $ref = GETPOST('ref', 'alpha');
    $fk_vehicle = GETPOST('fk_vehicle', 'int');
    
    // Fix: Convert date to MySQL format
    $date_raw = GETPOST('date', 'alpha');
    $date = '';
    if (!empty($date_raw)) {
        // Handle Dolibarr date format
        $day = GETPOST('dateday', 'int');
        $month = GETPOST('datemonth', 'int');
        $year = GETPOST('dateyear', 'int');
        
        if ($day > 0 && $month > 0 && $year > 0) {
            $date = sprintf('%04d-%02d-%02d', $year, $month, $day);
        } else {
            $date = convertDateToMysql($date_raw);
        }
    }
    
    $start_meter = GETPOST('start_meter', 'int');
    $reference = GETPOST('reference', 'alpha');
    $state = GETPOST('state', 'alpha');
    $note = GETPOST('note', 'alpha');
    $complete_fillup = GETPOST('complete_fillup', 'int') ? 1 : 0;
    $fuel_source = GETPOST('fuel_source', 'alpha');
    $qty = GETPOST('qty', 'alpha');
    $cost_unit = GETPOST('cost_unit', 'alpha');
    
    // Auto-generate reference if empty
    if (empty($ref)) {
        $ref = getNextFuelRef($db, $conf->entity);
    }
    
    // Convert to numbers for database
    $qty_numeric = (float) str_replace(',', '.', $qty);
    $cost_unit_numeric = (float) str_replace(',', '.', $cost_unit);
    
    if (empty($fk_vehicle)) {
        $error++;
        $errors[] = $langs->trans("ErrorFieldRequired", $langs->trans("Vehicle"));
    }
    if (empty($date)) {
        $error++;
        $errors[] = $langs->trans("ErrorFieldRequired", $langs->trans("Date"));
    }
    if (empty($qty) || $qty_numeric <= 0) {
        $error++;
        $errors[] = $langs->trans("ErrorFieldRequired", $langs->trans("Quantity"));
    }
    if (empty($cost_unit) || $cost_unit_numeric <= 0) {
        $error++;
        $errors[] = $langs->trans("ErrorFieldRequired", $langs->trans("CostUnit"));
    }
    
    if (!$error) {
        $sql = "INSERT INTO ".MAIN_DB_PREFIX."flotte_fuel (";
        $sql .= "ref, entity, fk_vehicle, date, start_meter, reference, state, note, complete_fillup, fuel_source, qty, cost_unit, fk_user_author";
        $sql .= ") VALUES (";
        $sql .= "'".$db->escape($ref)."', ".$conf->entity.", ";
        $sql .= "'".$db->escape($fk_vehicle)."', ";
        $sql .= "'".$db->escape($date)."', ";
        $sql .= ($start_meter ? $start_meter : 'NULL').", ";
        $sql .= "'".$db->escape($reference)."', ";
        $sql .= "'".$db->escape($state)."', ";
        $sql .= "'".$db->escape($note)."', ";
        $sql .= "'".$db->escape($complete_fillup)."', ";
        $sql .= "'".$db->escape($fuel_source)."', ";
        $sql .= "'".$db->escape($qty_numeric)."', ";
        $sql .= "'".$db->escape($cost_unit_numeric)."', ";
        $sql .= $user->id;
        $sql .= ")";
        
        $result = $db->query($sql);
        if ($result) {
            $id = $db->last_insert_id(MAIN_DB_PREFIX."flotte_fuel");
            $db->commit();
            $action = 'view';
            setEventMessages($langs->trans("FuelRecordCreatedSuccessfully"), null, 'mesgs');
        } else {
            $db->rollback();
            $error++;
            $errors[] = $langs->trans("ErrorCreatingFuelRecord") . ": " . $db->lasterror();
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
    
    // Fix: Convert date to MySQL format
    $date_raw = GETPOST('date', 'alpha');
    $date = '';
    if (!empty($date_raw)) {
        // Handle Dolibarr date format
        $day = GETPOST('dateday', 'int');
        $month = GETPOST('datemonth', 'int');
        $year = GETPOST('dateyear', 'int');
        
        if ($day > 0 && $month > 0 && $year > 0) {
            $date = sprintf('%04d-%02d-%02d', $year, $month, $day);
        } else {
            $date = convertDateToMysql($date_raw);
        }
    }
    
    $start_meter = GETPOST('start_meter', 'int');
    $reference = GETPOST('reference', 'alpha');
    $state = GETPOST('state', 'alpha');
    $note = GETPOST('note', 'alpha');
    $complete_fillup = GETPOST('complete_fillup', 'int') ? 1 : 0;
    $fuel_source = GETPOST('fuel_source', 'alpha');
    $qty = GETPOST('qty', 'alpha');
    $cost_unit = GETPOST('cost_unit', 'alpha');
    
    // Convert to numbers for database
    $qty_numeric = (float) str_replace(',', '.', $qty);
    $cost_unit_numeric = (float) str_replace(',', '.', $cost_unit);
    
    if (empty($fk_vehicle)) {
        $error++;
        $errors[] = $langs->trans("ErrorFieldRequired", $langs->trans("Vehicle"));
    }
    if (empty($date)) {
        $error++;
        $errors[] = $langs->trans("ErrorFieldRequired", $langs->trans("Date"));
    }
    if (empty($qty) || $qty_numeric <= 0) {
        $error++;
        $errors[] = $langs->trans("ErrorFieldRequired", $langs->trans("Quantity"));
    }
    if (empty($cost_unit) || $cost_unit_numeric <= 0) {
        $error++;
        $errors[] = $langs->trans("ErrorFieldRequired", $langs->trans("CostUnit"));
    }
    
    if (!$error) {
        $sql = "UPDATE ".MAIN_DB_PREFIX."flotte_fuel SET ";
        $sql .= "ref = '".$db->escape($ref)."', ";
        $sql .= "fk_vehicle = '".$db->escape($fk_vehicle)."', ";
        $sql .= "date = '".$db->escape($date)."', ";
        $sql .= "start_meter = ".($start_meter ? $start_meter : 'NULL').", ";
        $sql .= "reference = '".$db->escape($reference)."', ";
        $sql .= "state = '".$db->escape($state)."', ";
        $sql .= "note = '".$db->escape($note)."', ";
        $sql .= "complete_fillup = '".$db->escape($complete_fillup)."', ";
        $sql .= "fuel_source = '".$db->escape($fuel_source)."', ";
        $sql .= "qty = '".$db->escape($qty_numeric)."', ";
        $sql .= "cost_unit = '".$db->escape($cost_unit_numeric)."', ";
        $sql .= "fk_user_modif = ".$user->id." ";
        $sql .= "WHERE rowid = ".((int) $id);
        
        $result = $db->query($sql);
        if ($result) {
            $db->commit();
            $action = 'view';
            setEventMessages($langs->trans("FuelRecordUpdatedSuccessfully"), null, 'mesgs');
        } else {
            $db->rollback();
            $error++;
            $errors[] = $langs->trans("ErrorUpdatingFuelRecord") . ": " . $db->lasterror();
        }
    } else {
        $db->rollback();
    }
}

// Load object data
if ($id > 0) {
    $sql = "SELECT f.*, v.maker, v.model, v.license_plate 
            FROM ".MAIN_DB_PREFIX."flotte_fuel as f 
            LEFT JOIN ".MAIN_DB_PREFIX."flotte_vehicle as v ON f.fk_vehicle = v.rowid 
            WHERE f.rowid = ".((int) $id);
    $resql = $db->query($sql);
    if ($resql) {
        $object = $db->fetch_object($resql);
        if (!$object) {
            print $langs->trans("FuelRecordNotFound");
            exit;
        }
        // Ensure numeric values are properly formatted
        $object->qty = (float) $object->qty;
        $object->cost_unit = (float) $object->cost_unit;
    } else {
        print $langs->trans("ErrorLoadingFuelRecord");
        exit;
    }
} else {
    // Set default values for new record
    $object->date = date('Y-m-d');
    $object->state = 'pending';
    $object->fuel_source = 'Station';
    $object->qty = 0;
    $object->cost_unit = 0;
    $object->ref = getNextFuelRef($db, $conf->entity);
}

$form = new Form($db);

// Initialize technical object to manage hooks
$hookmanager->initHooks(array('fuelcard'));

/*
 * View
 */

$title = $langs->trans('FuelRecord');
if ($action == 'create') {
    $title = $langs->trans('NewFuelRecord');
} elseif ($action == 'edit') {
    $title = $langs->trans('EditFuelRecord');
} elseif ($id > 0) {
    $title = $langs->trans('FuelRecord') . " " . $object->ref;
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
.dc-badge.pending   { background: #fff8ec; color: #b45309; }
.dc-badge.pending::before   { background: #f59e0b; }
.dc-badge.approved  { background: #edfaf3; color: #1a7d4a; }
.dc-badge.approved::before  { background: #22c55e; }
.dc-badge.rejected  { background: #fef2f2; color: #b91c1c; }
.dc-badge.rejected::before  { background: #ef4444; }
.dc-badge.completed { background: #eff6ff; color: #1d4ed8; }
.dc-badge.completed::before { background: #3b82f6; }

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

/* ── Total cost highlight ── */
.dc-total {
    font-family: 'DM Mono', monospace; font-size: 15px;
    font-weight: 600; color: #1a1f2e;
    background: #f0f2fa; padding: 6px 12px;
    border-radius: 8px; display: inline-block;
}

/* ── Fillup pill ── */
.dc-pill-yes { background: #edfaf3; color: #1a7d4a; padding: 3px 10px; border-radius: 20px; font-size: 12px; font-weight: 600; display: inline-block; }
.dc-pill-no  { background: #f5f6fb; color: #8b92a9; padding: 3px 10px; border-radius: 20px; font-size: 12px; font-weight: 600; display: inline-block; }

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

/* ── Live total row ── */
.dc-live-total {
    display: flex; align-items: center; gap: 10px;
    padding: 14px 20px;
    background: #f7f8fc;
    border-top: 1px solid #e8eaf0;
}
.dc-live-total-label { font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: #8b92a9; }
.dc-live-total-value { font-family: 'DM Mono', monospace; font-size: 15px; font-weight: 600; color: #1a1f2e; }

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
        $langs->trans('DeleteFuelRecord'),
        $langs->trans('ConfirmDeleteFuelRecord'),
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

$pageTitle = $isCreate ? $langs->trans('NewFuelRecord') : ($isEdit ? $langs->trans('EditFuelRecord') : $langs->trans('FuelRecord'));
$pageSub   = $isCreate ? $langs->trans('FillInFuelDetails') : (isset($object->ref) ? $object->ref : '');

// Precompute totals
$total_cost = 0;
if (is_numeric($object->qty) && is_numeric($object->cost_unit)) {
    $total_cost = (float)$object->qty * (float)$object->cost_unit;
}

// Form start
if ($isCreate || $isEdit) {
    print '<form method="POST" action="'.$_SERVER['PHP_SELF'].($id > 0 ? '?id='.$id : '').'">';
    print '<input type="hidden" name="action" value="'.($isCreate ? 'add' : 'update').'">';
    print '<input type="hidden" name="token" value="'.newToken().'">';
}

print '<div class="dc-page">';

/* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
   PAGE HEADER
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */
print '<div class="dc-header">';
print '  <div class="dc-header-left">';
print '    <div class="dc-header-icon"><i class="fa fa-gas-pump"></i></div>';
print '    <div>';
print '      <div class="dc-header-title">'.dol_escape_htmltag($pageTitle).'</div>';
if ($pageSub) print '      <div class="dc-header-sub">'.dol_escape_htmltag($pageSub).'</div>';
print '    </div>';
print '  </div>';
print '  <div class="dc-header-actions">';
if ($isView && $id > 0) {
    if (!empty($object->state)) {
        $stClass = strtolower($object->state);
        $stLabel = $langs->trans(ucfirst($object->state));
        print '<span class="dc-badge '.$stClass.'">'.dol_escape_htmltag($stLabel).'</span>';
    }
    print '<a class="dc-btn dc-btn-ghost" href="'.dol_buildpath('/flotte/fuel_list.php', 1).'"><i class="fa fa-arrow-left"></i> '.$langs->trans('BackToList').'</a>';
    print '<a class="dc-btn dc-btn-ghost" href="'.$_SERVER['PHP_SELF'].'?id='.$id.'&action=edit"><i class="fa fa-pen"></i> '.$langs->trans('Modify').'</a>';
    print '<a class="dc-btn dc-btn-danger" href="'.$_SERVER['PHP_SELF'].'?id='.$id.'&action=delete"><i class="fa fa-trash"></i> '.$langs->trans('Delete').'</a>';
}
print '  </div>';
print '</div>';

/* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
   ROW 1 — Fuel Record Info + Fuel Details
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */
print '<div class="dc-grid">';

/* ── Card: Fuel Record Information ── */
print '<div class="dc-card">';
print '  <div class="dc-card-header">';
print '    <div class="dc-card-header-icon blue"><i class="fa fa-gas-pump"></i></div>';
print '    <span class="dc-card-title">'.$langs->trans('FuelRecordInformation').'</span>';
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
    if (!empty($object->fk_vehicle)) {
        print '<span class="dc-chip"><i class="fa fa-car" style="font-size:11px;opacity:0.6;"></i>'.dol_escape_htmltag($object->maker.' '.$object->model.' ('.$object->license_plate.')').'</span>';
    } else {
        print '<span style="color:#c4c9d8;">&mdash;</span>';
    }
}
print '    </div></div>';

// Date
print '  <div class="dc-field">';
print '    <div class="dc-field-label required">'.$langs->trans('Date').'</div>';
print '    <div class="dc-field-value">';
if ($isCreate || $isEdit) {
    print $form->selectDate((isset($object->date) ? $db->jdate($object->date) : ''), 'date', 0, 0, 0, '', 1, 1);
} else {
    print dol_print_date($db->jdate($object->date), 'day');
}
print '    </div></div>';

// Meter Reading
print '  <div class="dc-field">';
print '    <div class="dc-field-label">'.$langs->trans('MeterReading').'</div>';
print '    <div class="dc-field-value">';
if ($isCreate || $isEdit) {
    print '<input type="number" name="start_meter" value="'.(isset($object->start_meter) ? dol_escape_htmltag($object->start_meter) : '').'" min="0" step="1">';
} else {
    print (!empty($object->start_meter) ? '<span class="dc-mono">'.number_format((float)$object->start_meter).' km</span>' : '&mdash;');
}
print '    </div></div>';

// Reference Number
print '  <div class="dc-field">';
print '    <div class="dc-field-label">'.$langs->trans('ReferenceNumber').'</div>';
print '    <div class="dc-field-value">';
if ($isCreate || $isEdit) {
    print '<input type="text" name="reference" value="'.(isset($object->reference) ? dol_escape_htmltag($object->reference) : '').'">';
} else {
    print (!empty($object->reference) ? '<span class="dc-mono">'.dol_escape_htmltag($object->reference).'</span>' : '&mdash;');
}
print '    </div></div>';

// State
print '  <div class="dc-field">';
print '    <div class="dc-field-label">'.$langs->trans('State').'</div>';
print '    <div class="dc-field-value">';
if ($isCreate || $isEdit) {
    $state_options = array(
        'pending'   => $langs->trans('Pending'),
        'approved'  => $langs->trans('Approved'),
        'rejected'  => $langs->trans('Rejected'),
        'completed' => $langs->trans('Completed'),
    );
    print $form->selectarray('state', $state_options, (isset($object->state) ? $object->state : 'pending'), 0);
} else {
    if (!empty($object->state)) {
        $stClass = strtolower($object->state);
        $stLabel = $langs->trans(ucfirst($object->state));
        print '<span class="dc-badge '.$stClass.'">'.dol_escape_htmltag($stLabel).'</span>';
    }
}
print '    </div></div>';

print '  </div>';// card-body
print '</div>';  // dc-card

/* ── Card: Fuel Details ── */
print '<div class="dc-card">';
print '  <div class="dc-card-header">';
print '    <div class="dc-card-header-icon amber"><i class="fa fa-tint"></i></div>';
print '    <span class="dc-card-title">'.$langs->trans('FuelDetails').'</span>';
print '  </div>';
print '  <div class="dc-card-body">';

// Fuel Source
print '  <div class="dc-field">';
print '    <div class="dc-field-label">'.$langs->trans('FuelSource').'</div>';
print '    <div class="dc-field-value">';
if ($isCreate || $isEdit) {
    $source_options = array(
        'Station' => $langs->trans('Station'),
        'Tank'    => $langs->trans('Tank'),
        'Other'   => $langs->trans('Other'),
    );
    print $form->selectarray('fuel_source', $source_options, (isset($object->fuel_source) ? $object->fuel_source : 'Station'), 0);
} else {
    if (!empty($object->fuel_source)) {
        $srcColors = array('Station' => 'green', 'Tank' => 'amber', 'Other' => 'purple');
        $srcClass  = isset($srcColors[$object->fuel_source]) ? $srcColors[$object->fuel_source] : 'blue';
        $srcIcons  = array('Station' => 'fa-gas-pump', 'Tank' => 'fa-database', 'Other' => 'fa-ellipsis-h');
        $srcIcon   = isset($srcIcons[$object->fuel_source]) ? $srcIcons[$object->fuel_source] : 'fa-tint';
        print '<span class="dc-chip" style="background:rgba(60,71,88,0.07);">';
        print '<i class="fa '.$srcIcon.'" style="font-size:11px;opacity:0.6;"></i>';
        print dol_escape_htmltag($langs->trans($object->fuel_source));
        print '</span>';
    } else {
        print '<span style="color:#c4c9d8;">&mdash;</span>';
    }
}
print '    </div></div>';

// Quantity
print '  <div class="dc-field">';
print '    <div class="dc-field-label required">'.$langs->trans('Quantity').'</div>';
print '    <div class="dc-field-value">';
if ($isCreate || $isEdit) {
    print '<input type="number" name="qty" id="dc_qty" value="'.(isset($object->qty) ? dol_escape_htmltag($object->qty) : '').'" min="0.01" step="0.01" required>';
} else {
    print '<span class="dc-mono">'.(is_numeric($object->qty) ? number_format((float)$object->qty, 2) : '0.00').' L</span>';
}
print '    </div></div>';

// Cost per Unit
print '  <div class="dc-field">';
print '    <div class="dc-field-label required">'.$langs->trans('CostUnit').'</div>';
print '    <div class="dc-field-value">';
if ($isCreate || $isEdit) {
    print '<input type="number" name="cost_unit" id="dc_cost" value="'.(isset($object->cost_unit) ? dol_escape_htmltag($object->cost_unit) : '').'" min="0.01" step="0.01" required>';
} else {
    print '<span class="dc-mono">'.(is_numeric($object->cost_unit) ? price($object->cost_unit) : price(0)).' / L</span>';
}
print '    </div></div>';

// Complete Fill-up
print '  <div class="dc-field">';
print '    <div class="dc-field-label">'.$langs->trans('CompleteFillup').'</div>';
print '    <div class="dc-field-value">';
if ($isCreate || $isEdit) {
    print '<input type="checkbox" name="complete_fillup" value="1"'.(isset($object->complete_fillup) && $object->complete_fillup ? ' checked' : '').'>';
} else {
    print ($object->complete_fillup
        ? '<span class="dc-pill-yes"><i class="fa fa-check" style="font-size:10px;"></i> '.$langs->trans('Yes').'</span>'
        : '<span class="dc-pill-no">'.$langs->trans('No').'</span>');
}
print '    </div></div>';

// Total Cost — live in edit, static in view
print '  <div class="dc-live-total">';
print '    <span class="dc-live-total-label">'.$langs->trans('TotalCost').'</span>';
if ($isCreate || $isEdit) {
    print '    <span class="dc-live-total-value" id="dc_total">'.price($total_cost).'</span>';
} else {
    print '    <span class="dc-total">'.price($total_cost).'</span>';
}
print '  </div>';

print '  </div>';// card-body
print '</div>';  // dc-card

print '</div>';// dc-grid row1

/* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
   ROW 2 — Notes (always shown in edit; only when filled in view)
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
    print '<a class="dc-btn dc-btn-ghost dc-action-bar-left" href="'.dol_buildpath('/flotte/fuel_list.php', 1).'"><i class="fa fa-arrow-left"></i> '.$langs->trans('BackToList').'</a>';
    print '<a class="dc-btn dc-btn-ghost" href="'.($id > 0 ? $_SERVER['PHP_SELF'].'?id='.$id : dol_buildpath('/flotte/fuel_list.php', 1)).'"><i class="fa fa-times"></i> '.$langs->trans('Cancel').'</a>';
    print '<button type="submit" class="dc-btn dc-btn-primary"><i class="fa fa-check"></i> '.($isCreate ? $langs->trans('Create') : $langs->trans('Save')).'</button>';
    print '</div>';
    print '</form>';
} elseif ($id > 0) {
    print '<div class="dc-action-bar">';
    print '<a class="dc-btn dc-btn-ghost dc-action-bar-left" href="'.dol_buildpath('/flotte/fuel_list.php', 1).'"><i class="fa fa-arrow-left"></i> '.$langs->trans('BackToList').'</a>';
    print '<a class="dc-btn dc-btn-ghost" href="'.$_SERVER['PHP_SELF'].'?id='.$id.'&action=edit"><i class="fa fa-pen"></i> '.$langs->trans('Modify').'</a>';
    print '<a class="dc-btn dc-btn-danger" href="'.$_SERVER['PHP_SELF'].'?id='.$id.'&action=delete"><i class="fa fa-trash"></i> '.$langs->trans('Delete').'</a>';
    print '</div>';
}

print '</div>';// dc-page

// JavaScript: live total calculation in create/edit
if ($isCreate || $isEdit) {
    print '<script type="text/javascript">
    (function() {
        function formatNum(n) {
            return n.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ",");
        }
        function calcTotal() {
            var qty  = parseFloat(document.getElementById("dc_qty").value)  || 0;
            var cost = parseFloat(document.getElementById("dc_cost").value) || 0;
            var el   = document.getElementById("dc_total");
            if (el) el.textContent = formatNum(qty * cost);
        }
        document.addEventListener("DOMContentLoaded", function() {
            var q = document.getElementById("dc_qty");
            var c = document.getElementById("dc_cost");
            if (q) q.addEventListener("input", calcTotal);
            if (c) c.addEventListener("input", calcTotal);
            calcTotal();
        });
    })();
    </script>';
}

// End of page
llxFooter();
$db->close();
?>
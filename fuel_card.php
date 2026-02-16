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

// Subheader
$linkback = '<a href="' . dol_buildpath('/flotte/fuel_list.php', 1) . '">' . $langs->trans('BackToList') . '</a>';

$h = 0;
$head = array();
$head[$h][0] = 'javascript:void(0);'; // Non-clickable link
$head[$h][1] = $langs->trans('Card');
$head[$h][2] = 'card';
$h++;

dol_fiche_head($head, 'card', $langs->trans('FuelRecord'), -1, 'fuel');

// Add CSS to make the Card tab non-clickable
print '<style>
    .tabsAction a[href*="javascript:void(0)"],
    .tabs a[href*="javascript:void(0)"],
    a.tabactive[href*="javascript:void(0)"] {
        pointer-events: none !important;
        cursor: default !important;
        text-decoration: none !important;
    }
</style>';

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

if ($action == 'create' || $action == 'edit') {
    print '<form method="POST" action="' . $_SERVER['PHP_SELF'] . ($id > 0 ? '?id=' . $id : '') . '">';
    print '<input type="hidden" name="action" value="' . ($action == 'create' ? 'add' : 'update') . '">';
    print '<input type="hidden" name="token" value="' . newToken() . '">';
}

print '<div class="fichecenter">';
print '<div class="fichehalfleft">';

// Basic Information
print load_fiche_titre($langs->trans('Fuel Record Information'), '', '');
print '<table class="border tableforfield" width="100%">';

// Reference
print '<tr><td class="titlefield">' . $langs->trans('Reference') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    print $form->textwithpicto('<input type="text" class="flat" name="ref" value="' . (isset($object->ref) ? $object->ref : '') . '" size="20" readonly>', $langs->trans('AutoGenerated'));
} else {
    print $object->ref;
}
print '</td></tr>';

// Vehicle
print '<tr><td>' . $langs->trans('Vehicle') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    print $form->selectarray('fk_vehicle', $vehicles, (isset($object->fk_vehicle) ? $object->fk_vehicle : ''), 1, 0, 0, '', 0, 0, 0, '', 'minwidth200', 1);
} else {
    if ($object->fk_vehicle) {
        print $object->maker . ' ' . $object->model . ' (' . $object->license_plate . ')';
    }
}
print '</td></tr>';

// Date
print '<tr><td>' . $langs->trans('Date') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    print $form->selectDate((isset($object->date) ? $db->jdate($object->date) : ''), 'date', 0, 0, 0, '', 1, 1);
} else {
    print dol_print_date($db->jdate($object->date), 'day');
}
print '</td></tr>';

// Meter Reading
print '<tr><td>' . $langs->trans('Meter Reading') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    print '<input type="number" class="flat" name="start_meter" value="' . (isset($object->start_meter) ? $object->start_meter : '') . '" size="10" min="0" step="1"> km';
} else {
    print ($object->start_meter ? number_format((float)$object->start_meter) . ' km' : '');
}
print '</td></tr>';

// Reference Number
print '<tr><td>' . $langs->trans('Reference Number') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    print '<input type="text" class="flat" name="reference" value="' . (isset($object->reference) ? $object->reference : '') . '" size="20">';
} else {
    print $object->reference;
}
print '</td></tr>';

// State
print '<tr><td>' . $langs->trans('State') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    $state_options = array(
        'pending' => $langs->trans('Pending'),
        'approved' => $langs->trans('Approved'),
        'rejected' => $langs->trans('Rejected'),
        'completed' => $langs->trans('Completed')
    );
    print $form->selectarray('state', $state_options, (isset($object->state) ? $object->state : ''), 0);
} else {
    if ($object->state) {
        $state_label = $langs->trans(ucfirst($object->state));
        $status_color = 'status1';
        if ($object->state == 'approved') $status_color = 'status4';
        elseif ($object->state == 'completed') $status_color = 'status6';
        elseif ($object->state == 'rejected') $status_color = 'status8';
        print dolGetStatus($state_label, '', '', $status_color, 1);
    }
}
print '</td></tr>';

print '</table>';

print '</div>';
print '<div class="fichehalfright">';

// Fuel Details
print load_fiche_titre($langs->trans('Fuel Details'), '', '');
print '<table class="border tableforfield" width="100%">';

// Fuel Source
print '<tr><td class="titlefield">' . $langs->trans('Fuel Source') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    $source_options = array(
        'Station' => $langs->trans('Station'),
        'Tank' => $langs->trans('Tank'),
        'Other' => $langs->trans('Other')
    );
    print $form->selectarray('fuel_source', $source_options, (isset($object->fuel_source) ? $object->fuel_source : ''), 0);
} else {
    if ($object->fuel_source) {
        $source_label = $langs->trans($object->fuel_source);
        $status_color = 'status4';
        if ($object->fuel_source == 'Tank') $status_color = 'status8';
        elseif ($object->fuel_source == 'Other') $status_color = 'status9';
        print dolGetStatus($source_label, '', '', $status_color, 1);
    }
}
print '</td></tr>';

// Quantity
print '<tr><td>' . $langs->trans('Quantity') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    print '<input type="number" class="flat" name="qty" value="' . (isset($object->qty) ? $object->qty : '') . '" size="10" min="0.01" step="0.01" required> L';
} else {
    print (is_numeric($object->qty) ? number_format((float)$object->qty, 2) : '0.00') . ' L';
}
print '</td></tr>';

// Cost per Unit
print '<tr><td>' . $langs->trans('Cost Unit') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    print '<input type="number" class="flat" name="cost_unit" value="' . (isset($object->cost_unit) ? $object->cost_unit : '') . '" size="10" min="0.01" step="0.01" required> ' . $conf->currency;
} else {
    print (is_numeric($object->cost_unit) ? price($object->cost_unit) : price(0));
}
print '</td></tr>';

// Total Cost
print '<tr><td>' . $langs->trans('Total Cost') . '</td><td>';
$total_cost = 0;
if (is_numeric($object->qty) && is_numeric($object->cost_unit)) {
    $total_cost = (float)$object->qty * (float)$object->cost_unit;
}
print '<strong>' . price($total_cost) . '</strong>';
print '</td></tr>';

// Complete Fill-up
print '<tr><td>' . $langs->trans('Complete Fill up') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    print '<input type="checkbox" name="complete_fillup" value="1"' . (isset($object->complete_fillup) && $object->complete_fillup ? ' checked' : '') . '>';
} else {
    print ($object->complete_fillup ? $langs->trans('Yes') : $langs->trans('No'));
}
print '</td></tr>';

print '</table>';

print '</div>';
print '</div>';

print '<div class="clearboth"></div>';

// Notes
if ($action == 'create' || $action == 'edit') {
    print '<br>';
    print load_fiche_titre($langs->trans('Notes'), '', '');
    print '<table class="border tableforfield" width="100%">';
    print '<tr><td class="tdtop">' . $langs->trans('Notes') . '</td><td>';
    print '<textarea name="note" class="flat" rows="4" cols="80">' . (isset($object->note) ? $object->note : '') . '</textarea>';
    print '</td></tr>';
    print '</table>';
} elseif (!empty($object->note)) {
    print '<br>';
    print load_fiche_titre($langs->trans('Notes'), '', '');
    print '<table class="border tableforfield" width="100%">';
    print '<tr><td>' . $langs->trans('Notes') . '</td><td>';
    print nl2br($object->note);
    print '</td></tr>';
    print '</table>';
}

// Add button styling CSS
print '<style>
    .flotte-btn {
        display: inline-block;
        min-width: 120px;
        height: 34px;
        line-height: 34px;
        padding: 0 20px;
        text-align: center;
        box-sizing: border-box;
        font-size: 13px;
        border-radius: 3px;
        cursor: pointer;
        text-decoration: none;
        vertical-align: middle;
        margin: 0 4px;
    }
    /* Submit / Create / Save — solid blue fill */
    input.flotte-btn {
        background: #3c6d9f;
        border: 1px solid #2e5a85;
        color: #fff;
    }
    input.flotte-btn:hover {
        background: #2e5a85;
    }
    /* Modify — solid blue fill (same weight as submit) */
    a.flotte-btn-primary {
        background: #3c6d9f;
        border: 1px solid #2e5a85;
        color: #fff;
    }
    a.flotte-btn-primary:hover {
        background: #2e5a85;
        color: #fff;
    }
    /* Cancel — blue outline, white fill */
    a.flotte-btn-cancel {
        background: #fff;
        border: 1px solid #3c6d9f;
        color: #3c6d9f;
    }
    a.flotte-btn-cancel:hover {
        background: #eef3f8;
        color: #2e5a85;
    }
    /* Back to List — blue outline, white fill */
    a.flotte-btn-back {
        background: #fff;
        border: 1px solid #3c6d9f;
        color: #3c6d9f;
    }
    a.flotte-btn-back:hover {
        background: #eef3f8;
        color: #2e5a85;
    }
    /* Delete — red fill */
    a.flotte-btn-delete {
        background: #c9302c;
        border: 1px solid #ac2925;
        color: #fff;
    }
    a.flotte-btn-delete:hover {
        background: #ac2925;
        color: #fff;
    }
</style>'."\n";

// Form buttons
if ($action == 'create' || $action == 'edit') {
    print '<div class="center" style="margin-top: 20px; margin-bottom: 10px;">';
    print '<input type="submit" class="flotte-btn" value="' . ($action == 'create' ? $langs->trans('Create') : $langs->trans('Save')) . '">';
    print '<a class="flotte-btn flotte-btn-cancel" href="' . ($id > 0 ? $_SERVER['PHP_SELF'] . '?id=' . $id : dol_buildpath('/flotte/fuel_list.php', 1)) . '">' . $langs->trans('Cancel') . '</a>';
    print '<a class="flotte-btn flotte-btn-back" href="' . dol_buildpath('/flotte/fuel_list.php', 1) . '">' . $langs->trans('BackToList') . '</a>';
    print '</div>';
    print '</form>';
} elseif ($id > 0) {
    // Action buttons
    print '<div class="center" style="margin-top: 20px; margin-bottom: 10px;">';
    print '<a class="flotte-btn flotte-btn-primary" href="' . $_SERVER['PHP_SELF'] . '?id=' . $id . '&action=edit">' . $langs->trans('Modify') . '</a>';
    print '<a class="flotte-btn flotte-btn-delete" href="' . $_SERVER['PHP_SELF'] . '?id=' . $id . '&action=delete">' . $langs->trans('Delete') . '</a>';
    print '<a class="flotte-btn flotte-btn-back" href="' . dol_buildpath('/flotte/fuel_list.php', 1) . '">' . $langs->trans('BackToList') . '</a>';
    print '</div>';
}

dol_fiche_end();

// Add JavaScript for dynamic total calculation in edit mode
if ($action == 'create' || $action == 'edit') {
    print '<script type="text/javascript">
    function calculateTotal() {
        var qty = parseFloat(document.getElementsByName("qty")[0].value) || 0;
        var cost = parseFloat(document.getElementsByName("cost_unit")[0].value) || 0;
        var total = qty * cost;
        
        // Update any total display elements if they exist
        var totalElements = document.querySelectorAll(".total-cost");
        totalElements.forEach(function(element) {
            element.textContent = total.toFixed(2);
        });
    }
    
    document.addEventListener("DOMContentLoaded", function() {
        var qtyInput = document.getElementsByName("qty")[0];
        var costInput = document.getElementsByName("cost_unit")[0];
        
        if (qtyInput) qtyInput.addEventListener("input", calculateTotal);
        if (costInput) costInput.addEventListener("input", calculateTotal);
        
        calculateTotal(); // Initial calculation
    });
    </script>';
}

// End of page
llxFooter();
$db->close();
?>
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

// Function to generate next inspection reference
function getNextInspectionRef($db, $entity) {
    $prefix = "INSP-";
    
    // Get the last reference from database
    $sql = "SELECT ref FROM ".MAIN_DB_PREFIX."flotte_inspection";
    $sql .= " WHERE entity = ".((int) $entity);
    $sql .= " AND ref LIKE '".$db->escape($prefix)."%'";
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
    
    // Format with leading zeros (e.g., INSP-0001)
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
$object->registration_number = '';
$object->meter_out = '';
$object->meter_in = '';
$object->fuel_out = '';
$object->fuel_in = '';
$object->datetime_out = '';
$object->datetime_in = '';
$object->petrol_card = 0;
$object->lights_indicators = 0;
$object->inverter_cigarette = 0;
$object->mats_seats = 0;
$object->interior_damage = 0;
$object->interior_lights = 0;
$object->exterior_damage = 0;
$object->tyres_condition = 0;
$object->ladders = 0;
$object->extension_leeds = 0;
$object->power_tools = 0;
$object->ac_working = 0;
$object->headlights_working = 0;
$object->locks_alarms = 0;
$object->windows_condition = 0;
$object->seats_condition = 0;
$object->oil_check = 0;
$object->suspension = 0;
$object->toolboxes_condition = 0;

$error = 0;
$errors = array();

// Generate reference for new inspection
if ($action == 'create' && empty($object->ref)) {
    $object->ref = getNextInspectionRef($db, $conf->entity);
}

/*
 * Actions
 */

if ($cancel) {
    $action = 'view';
}

if ($action == 'confirm_delete' && $confirm == 'yes' && !empty($user->rights->flotte->delete)) {
    $db->begin();
    
    $sql = "DELETE FROM ".MAIN_DB_PREFIX."flotte_inspection WHERE rowid = ".((int) $id);
    $result = $db->query($sql);
    
    if ($result) {
        $db->commit();
        header("Location: " . dol_buildpath('/flotte/inspection_list.php', 1));
        exit;
    } else {
        $db->rollback();
        $error++;
        setEventMessages($langs->trans("ErrorDeletingInspection"), null, 'errors');
    }
}

if ($action == 'add') {
    $db->begin();
    
    // Get form data with proper validation
    $ref = GETPOST('ref', 'alpha');
    $fk_vehicle = GETPOST('fk_vehicle', 'int');
    $registration_number = GETPOST('registration_number', 'alpha');
    $meter_out = GETPOST('meter_out', 'int');
    $meter_in = GETPOST('meter_in', 'int');
    $fuel_out = GETPOST('fuel_out', 'alpha');
    $fuel_in = GETPOST('fuel_in', 'alpha');
    
    // Fix: Convert datetime_out to MySQL format
    $datetime_out_raw = GETPOST('datetime_out', 'alpha');
    $datetime_out = '';
    if (!empty($datetime_out_raw)) {
        // Handle Dolibarr date format
        $day = GETPOST('dateday', 'int');
        $month = GETPOST('datemonth', 'int');
        $year = GETPOST('dateyear', 'int');
        
        if ($day > 0 && $month > 0 && $year > 0) {
            $datetime_out = sprintf('%04d-%02d-%02d', $year, $month, $day);
        } else {
            $datetime_out = convertDateToMysql($datetime_out_raw);
        }
    }
    
    // Fix: Convert datetime_in to MySQL format
    $datetime_in_raw = GETPOST('datetime_in', 'alpha');
    $datetime_in = '';
    if (!empty($datetime_in_raw)) {
        // Handle Dolibarr date format
        $day_in = GETPOST('datetime_inday', 'int');
        $month_in = GETPOST('datetime_inmonth', 'int');
        $year_in = GETPOST('datetime_inyear', 'int');
        
        if ($day_in > 0 && $month_in > 0 && $year_in > 0) {
            $datetime_in = sprintf('%04d-%02d-%02d', $year_in, $month_in, $day_in);
        } else {
            $datetime_in = convertDateToMysql($datetime_in_raw);
        }
    }
    
    // Checkbox fields
    $petrol_card = GETPOST('petrol_card', 'int') ? 1 : 0;
    $lights_indicators = GETPOST('lights_indicators', 'int') ? 1 : 0;
    $inverter_cigarette = GETPOST('inverter_cigarette', 'int') ? 1 : 0;
    $mats_seats = GETPOST('mats_seats', 'int') ? 1 : 0;
    $interior_damage = GETPOST('interior_damage', 'int') ? 1 : 0;
    $interior_lights = GETPOST('interior_lights', 'int') ? 1 : 0;
    $exterior_damage = GETPOST('exterior_damage', 'int') ? 1 : 0;
    $tyres_condition = GETPOST('tyres_condition', 'int') ? 1 : 0;
    $ladders = GETPOST('ladders', 'int') ? 1 : 0;
    $extension_leeds = GETPOST('extension_leeds', 'int') ? 1 : 0;
    $power_tools = GETPOST('power_tools', 'int') ? 1 : 0;
    $ac_working = GETPOST('ac_working', 'int') ? 1 : 0;
    $headlights_working = GETPOST('headlights_working', 'int') ? 1 : 0;
    $locks_alarms = GETPOST('locks_alarms', 'int') ? 1 : 0;
    $windows_condition = GETPOST('windows_condition', 'int') ? 1 : 0;
    $seats_condition = GETPOST('seats_condition', 'int') ? 1 : 0;
    $oil_check = GETPOST('oil_check', 'int') ? 1 : 0;
    $suspension = GETPOST('suspension', 'int') ? 1 : 0;
    $toolboxes_condition = GETPOST('toolboxes_condition', 'int') ? 1 : 0;
    
    // Auto-generate reference if empty
    if (empty($ref)) {
        $ref = getNextInspectionRef($db, $conf->entity);
    }
    
    // Validation
    if (empty($fk_vehicle)) {
        $error++;
        $errors[] = $langs->trans("ErrorFieldRequired", $langs->trans("Vehicle"));
    }
    
    if (!$error) {
        $now = dol_now();
        
        $sql = "INSERT INTO ".MAIN_DB_PREFIX."flotte_inspection (";
        $sql .= "ref, entity, fk_vehicle, registration_number, meter_out, meter_in, fuel_out, fuel_in, ";
        $sql .= "datetime_out, datetime_in, petrol_card, lights_indicators, inverter_cigarette, ";
        $sql .= "mats_seats, interior_damage, interior_lights, exterior_damage, tyres_condition, ";
        $sql .= "ladders, extension_leeds, power_tools, ac_working, headlights_working, ";
        $sql .= "locks_alarms, windows_condition, seats_condition, oil_check, suspension, toolboxes_condition, ";
        $sql .= "fk_user_author";
        $sql .= ") VALUES (";
        $sql .= "'".$db->escape($ref)."', ".((int) $conf->entity).", ";
        $sql .= "".((int) $fk_vehicle).", ";
        $sql .= "'".$db->escape($registration_number)."', ";
        $sql .= ($meter_out > 0 ? ((int) $meter_out) : "NULL").", ";
        $sql .= ($meter_in > 0 ? ((int) $meter_in) : "NULL").", ";
        $sql .= "'".$db->escape($fuel_out)."', ";
        $sql .= "'".$db->escape($fuel_in)."', ";
        $sql .= (!empty($datetime_out) ? "'".$db->escape($datetime_out)."'" : "NULL").", ";
        $sql .= (!empty($datetime_in) ? "'".$db->escape($datetime_in)."'" : "NULL").", ";
        $sql .= "".((int) $petrol_card).", ";
        $sql .= "".((int) $lights_indicators).", ";
        $sql .= "".((int) $inverter_cigarette).", ";
        $sql .= "".((int) $mats_seats).", ";
        $sql .= "".((int) $interior_damage).", ";
        $sql .= "".((int) $interior_lights).", ";
        $sql .= "".((int) $exterior_damage).", ";
        $sql .= "".((int) $tyres_condition).", ";
        $sql .= "".((int) $ladders).", ";
        $sql .= "".((int) $extension_leeds).", ";
        $sql .= "".((int) $power_tools).", ";
        $sql .= "".((int) $ac_working).", ";
        $sql .= "".((int) $headlights_working).", ";
        $sql .= "".((int) $locks_alarms).", ";
        $sql .= "".((int) $windows_condition).", ";
        $sql .= "".((int) $seats_condition).", ";
        $sql .= "".((int) $oil_check).", ";
        $sql .= "".((int) $suspension).", ";
        $sql .= "".((int) $toolboxes_condition).", ";
        $sql .= ((int) $user->id);
        $sql .= ")";
        
        $result = $db->query($sql);
        if ($result) {
            $id = $db->last_insert_id(MAIN_DB_PREFIX."flotte_inspection");
            $db->commit();
            $action = 'view';
            setEventMessages($langs->trans("InspectionCreatedSuccessfully"), null, 'mesgs');
        } else {
            $db->rollback();
            $error++;
            $errors[] = $langs->trans("ErrorCreatingInspection") . ": " . $db->lasterror();
        }
    }
    
    if ($error) {
        $db->rollback();
    }
}

if ($action == 'update' && $id > 0) {
    $db->begin();
    
    // Get form data with proper validation
    $ref = GETPOST('ref', 'alpha');
    $fk_vehicle = GETPOST('fk_vehicle', 'int');
    $registration_number = GETPOST('registration_number', 'alpha');
    $meter_out = GETPOST('meter_out', 'int');
    $meter_in = GETPOST('meter_in', 'int');
    $fuel_out = GETPOST('fuel_out', 'alpha');
    $fuel_in = GETPOST('fuel_in', 'alpha');
    
    // Fix: Convert datetime_out to MySQL format
    $datetime_out_raw = GETPOST('datetime_out', 'alpha');
    $datetime_out = '';
    if (!empty($datetime_out_raw)) {
        // Handle Dolibarr date format
        $day = GETPOST('datetime_outday', 'int');
        $month = GETPOST('datetime_outmonth', 'int');
        $year = GETPOST('datetime_outyear', 'int');
        
        if ($day > 0 && $month > 0 && $year > 0) {
            $datetime_out = sprintf('%04d-%02d-%02d', $year, $month, $day);
        } else {
            $datetime_out = convertDateToMysql($datetime_out_raw);
        }
    }
    
    // Fix: Convert datetime_in to MySQL format
    $datetime_in_raw = GETPOST('datetime_in', 'alpha');
    $datetime_in = '';
    if (!empty($datetime_in_raw)) {
        // Handle Dolibarr date format
        $day_in = GETPOST('datetime_inday', 'int');
        $month_in = GETPOST('datetime_inmonth', 'int');
        $year_in = GETPOST('datetime_inyear', 'int');
        
        if ($day_in > 0 && $month_in > 0 && $year_in > 0) {
            $datetime_in = sprintf('%04d-%02d-%02d', $year_in, $month_in, $day_in);
        } else {
            $datetime_in = convertDateToMysql($datetime_in_raw);
        }
    }
    
    // Checkbox fields
    $petrol_card = GETPOST('petrol_card', 'int') ? 1 : 0;
    $lights_indicators = GETPOST('lights_indicators', 'int') ? 1 : 0;
    $inverter_cigarette = GETPOST('inverter_cigarette', 'int') ? 1 : 0;
    $mats_seats = GETPOST('mats_seats', 'int') ? 1 : 0;
    $interior_damage = GETPOST('interior_damage', 'int') ? 1 : 0;
    $interior_lights = GETPOST('interior_lights', 'int') ? 1 : 0;
    $exterior_damage = GETPOST('exterior_damage', 'int') ? 1 : 0;
    $tyres_condition = GETPOST('tyres_condition', 'int') ? 1 : 0;
    $ladders = GETPOST('ladders', 'int') ? 1 : 0;
    $extension_leeds = GETPOST('extension_leeds', 'int') ? 1 : 0;
    $power_tools = GETPOST('power_tools', 'int') ? 1 : 0;
    $ac_working = GETPOST('ac_working', 'int') ? 1 : 0;
    $headlights_working = GETPOST('headlights_working', 'int') ? 1 : 0;
    $locks_alarms = GETPOST('locks_alarms', 'int') ? 1 : 0;
    $windows_condition = GETPOST('windows_condition', 'int') ? 1 : 0;
    $seats_condition = GETPOST('seats_condition', 'int') ? 1 : 0;
    $oil_check = GETPOST('oil_check', 'int') ? 1 : 0;
    $suspension = GETPOST('suspension', 'int') ? 1 : 0;
    $toolboxes_condition = GETPOST('toolboxes_condition', 'int') ? 1 : 0;
    
    // Validation
    if (empty($fk_vehicle)) {
        $error++;
        $errors[] = $langs->trans("ErrorFieldRequired", $langs->trans("Vehicle"));
    }
    
    if (!$error) {
        $now = dol_now();
        
        $sql = "UPDATE ".MAIN_DB_PREFIX."flotte_inspection SET ";
        $sql .= "ref = '".$db->escape($ref)."', ";
        $sql .= "fk_vehicle = ".((int) $fk_vehicle).", ";
        $sql .= "registration_number = '".$db->escape($registration_number)."', ";
        $sql .= "meter_out = ".($meter_out > 0 ? ((int) $meter_out) : "NULL").", ";
        $sql .= "meter_in = ".($meter_in > 0 ? ((int) $meter_in) : "NULL").", ";
        $sql .= "fuel_out = '".$db->escape($fuel_out)."', ";
        $sql .= "fuel_in = '".$db->escape($fuel_in)."', ";
        $sql .= "datetime_out = ".(!empty($datetime_out) ? "'".$db->escape($datetime_out)."'" : "NULL").", ";
        $sql .= "datetime_in = ".(!empty($datetime_in) ? "'".$db->escape($datetime_in)."'" : "NULL").", ";
        $sql .= "petrol_card = ".((int) $petrol_card).", ";
        $sql .= "lights_indicators = ".((int) $lights_indicators).", ";
        $sql .= "inverter_cigarette = ".((int) $inverter_cigarette).", ";
        $sql .= "mats_seats = ".((int) $mats_seats).", ";
        $sql .= "interior_damage = ".((int) $interior_damage).", ";
        $sql .= "interior_lights = ".((int) $interior_lights).", ";
        $sql .= "exterior_damage = ".((int) $exterior_damage).", ";
        $sql .= "tyres_condition = ".((int) $tyres_condition).", ";
        $sql .= "ladders = ".((int) $ladders).", ";
        $sql .= "extension_leeds = ".((int) $extension_leeds).", ";
        $sql .= "power_tools = ".((int) $power_tools).", ";
        $sql .= "ac_working = ".((int) $ac_working).", ";
        $sql .= "headlights_working = ".((int) $headlights_working).", ";
        $sql .= "locks_alarms = ".((int) $locks_alarms).", ";
        $sql .= "windows_condition = ".((int) $windows_condition).", ";
        $sql .= "seats_condition = ".((int) $seats_condition).", ";
        $sql .= "oil_check = ".((int) $oil_check).", ";
        $sql .= "suspension = ".((int) $suspension).", ";
        $sql .= "toolboxes_condition = ".((int) $toolboxes_condition).", ";
        $sql .= "fk_user_modif = ".((int) $user->id).", ";
        $sql .= "tms = '".$db->idate($now)."' ";
        $sql .= "WHERE rowid = ".((int) $id);
        
        $result = $db->query($sql);
        if ($result) {
            $db->commit();
            $action = 'view';
            setEventMessages($langs->trans("InspectionUpdatedSuccessfully"), null, 'mesgs');
        } else {
            $db->rollback();
            $error++;
            $errors[] = $langs->trans("ErrorUpdatingInspection") . ": " . $db->lasterror();
        }
    }
    
    if ($error) {
        $db->rollback();
    }
}

// Load object data
if ($id > 0) {
    $sql = "SELECT * FROM ".MAIN_DB_PREFIX."flotte_inspection WHERE rowid = ".((int) $id);
    $resql = $db->query($sql);
    if ($resql) {
        $object = $db->fetch_object($resql);
        if (!$object) {
            header("HTTP/1.0 404 Not Found");
            print $langs->trans("InspectionNotFound");
            exit;
        }
    } else {
        header("HTTP/1.0 500 Internal Server Error");
        print $langs->trans("ErrorLoadingInspection") . ": " . $db->lasterror();
        exit;
    }
}

$form = new Form($db);

// Initialize technical object to manage hooks
$hookmanager->initHooks(array('inspectioncard'));

/*
 * View
 */

$title = $langs->trans('Inspection');
if ($action == 'create') {
    $title = $langs->trans('NewInspection');
} elseif ($action == 'edit') {
    $title = $langs->trans('EditInspection');
} elseif ($id > 0) {
    $title = $langs->trans('Inspection') . " " . $object->ref;
}

llxHeader('', $title);

// Subheader
$linkback = '<a href="' . DOL_URL_ROOT . '/flotte/inspection_list.php">' . $langs->trans('BackToList') . '</a>';

$h = 0;
$head = array();
$head[$h][0] = $_SERVER["PHP_SELF"] . '?id=' . $id;
$head[$h][1] = $langs->trans('Card');
$head[$h][2] = 'card';
$h++;

dol_fiche_head($head, 'card', $langs->trans('Inspection'), -1, 'clipboard-list');

// Confirmation to delete
if ($action == 'delete') {
    $formconfirm = $form->formconfirm(
        $_SERVER["PHP_SELF"] . '?id=' . $id,
        $langs->trans('DeleteInspection'),
        $langs->trans('ConfirmDeleteInspection'),
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
    if ($id > 0) {
        print '<input type="hidden" name="id" value="' . $id . '">';
    }
}

print '<div class="fichecenter">';
print '<div class="fichehalfleft">';

// Basic Information
print load_fiche_titre($langs->trans('InspectionInformation'), '', '');
print '<table class="border tableforfield" width="100%">';

// Reference
print '<tr><td class="titlefield">' . $langs->trans('Reference') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    print $form->textwithpicto('<input type="text" class="flat" name="ref" value="' . dol_escape_htmltag(isset($object->ref) ? $object->ref : '') . '" size="20" readonly>', $langs->trans('AutoGenerated'));
} else {
    print dol_escape_htmltag($object->ref);
}
print '</td></tr>';

// Vehicle
print '<tr><td>' . $langs->trans('Vehicle') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    // Get available vehicles
    $vehicles = array();
    $sql = "SELECT rowid, ref, maker, model FROM ".MAIN_DB_PREFIX."flotte_vehicle WHERE entity IN (".getEntity('flotte').")";
    $resql = $db->query($sql);
    if ($resql) {
        while ($obj = $db->fetch_object($resql)) {
            $vehicles[$obj->rowid] = dol_escape_htmltag($obj->ref . ' - ' . $obj->maker . ' ' . $obj->model);
        }
    }
    print $form->selectarray('fk_vehicle', $vehicles, (isset($object->fk_vehicle) ? $object->fk_vehicle : ''), 1, 0, 0, '', 0, 0, 0, '', 'minwidth300');
} else {
    if (!empty($object->fk_vehicle)) {
        $sql = "SELECT ref, maker, model FROM ".MAIN_DB_PREFIX."flotte_vehicle WHERE rowid = " . ((int) $object->fk_vehicle);
        $resql = $db->query($sql);
        if ($resql && $db->num_rows($resql) > 0) {
            $obj = $db->fetch_object($resql);
            print dol_escape_htmltag($obj->ref . ' - ' . $obj->maker . ' ' . $obj->model);
        } else {
            print '<span class="opacitymedium">' . $langs->trans("VehicleNotFound") . '</span>';
        }
    } else {
        print '<span class="opacitymedium">' . $langs->trans("NotAssigned") . '</span>';
    }
}
print '</td></tr>';

// Registration Number
print '<tr><td>' . $langs->trans('RegistrationNumber') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    print '<input type="text" class="flat" name="registration_number" value="' . dol_escape_htmltag(isset($object->registration_number) ? $object->registration_number : '') . '" size="20">';
} else {
    print dol_escape_htmltag($object->registration_number);
}
print '</td></tr>';

// Date Out
print '<tr><td>' . $langs->trans('DateOut') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    print $form->selectDate((isset($object->datetime_out) ? $object->datetime_out : ''), 'datetime_out', 1, 1, 1, '', 1, 1);
} else {
    print dol_print_date($object->datetime_out, 'dayhour');
}
print '</td></tr>';

// Date In
print '<tr><td>' . $langs->trans('DateIn') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    print $form->selectDate((isset($object->datetime_in) ? $object->datetime_in : ''), 'datetime_in', 1, 1, 1, '', 1, 1);
} else {
    print dol_print_date($object->datetime_in, 'dayhour');
}
print '</td></tr>';

print '</table>';

print '</div>';
print '<div class="fichehalfright">';

// Meter and Fuel Information
print load_fiche_titre($langs->trans('MeterAndFuelInformation'), '', '');
print '<table class="border tableforfield" width="100%">';

// Meter Out
print '<tr><td class="titlefield">' . $langs->trans('MeterOut') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    print '<input type="number" class="flat" name="meter_out" value="' . dol_escape_htmltag(isset($object->meter_out) ? $object->meter_out : '') . '" min="0">';
} else {
    print ($object->meter_out ? dol_escape_htmltag($object->meter_out) : '');
}
print '</td></tr>';

// Meter In
print '<tr><td>' . $langs->trans('MeterIn') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    print '<input type="number" class="flat" name="meter_in" value="' . dol_escape_htmltag(isset($object->meter_in) ? $object->meter_in : '') . '" min="0">';
} else {
    print ($object->meter_in ? dol_escape_htmltag($object->meter_in) : '');
}
print '</td></tr>';

// Fuel Out
print '<tr><td>' . $langs->trans('FuelOut') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    print '<input type="text" class="flat" name="fuel_out" value="' . dol_escape_htmltag(isset($object->fuel_out) ? $object->fuel_out : '') . '" size="10">';
} else {
    print dol_escape_htmltag($object->fuel_out);
}
print '</td></tr>';

// Fuel In
print '<tr><td>' . $langs->trans('FuelIn') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    print '<input type="text" class="flat" name="fuel_in" value="' . dol_escape_htmltag(isset($object->fuel_in) ? $object->fuel_in : '') . '" size="10">';
} else {
    print dol_escape_htmltag($object->fuel_in);
}
print '</td></tr>';

print '</table>';

print '</div>';
print '</div>';

print '<div class="clearboth"></div>';

// Inspection Checklist
print load_fiche_titre($langs->trans('InspectionChecklist'), '', '');
print '<table class="border tableforfield" width="100%">';

$checklist_items = array(
    'petrol_card' => $langs->trans('PetrolCard'),
    'lights_indicators' => $langs->trans('LightsIndicators'),
    'inverter_cigarette' => $langs->trans('InverterCigarette'),
    'mats_seats' => $langs->trans('MatsSeats'),
    'interior_damage' => $langs->trans('InteriorDamage'),
    'interior_lights' => $langs->trans('InteriorLights'),
    'exterior_damage' => $langs->trans('ExteriorDamage'),
    'tyres_condition' => $langs->trans('TyresCondition'),
    'ladders' => $langs->trans('Ladders'),
    'extension_leeds' => $langs->trans('ExtensionLeeds'),
    'power_tools' => $langs->trans('PowerTools'),
    'ac_working' => $langs->trans('ACWorking'),
    'headlights_working' => $langs->trans('HeadlightsWorking'),
    'locks_alarms' => $langs->trans('LocksAlarms'),
    'windows_condition' => $langs->trans('WindowsCondition'),
    'seats_condition' => $langs->trans('SeatsCondition'),
    'oil_check' => $langs->trans('OilCheck'),
    'suspension' => $langs->trans('Suspension'),
    'toolboxes_condition' => $langs->trans('ToolboxesCondition')
);

$i = 0;
foreach ($checklist_items as $field => $label) {
    if ($i % 3 == 0) print '<tr>';
    
    print '<td width="33%" class="nowrap">';
    if ($action == 'create' || $action == 'edit') {
        print '<input type="checkbox" name="' . $field . '" value="1" ' . (isset($object->$field) && $object->$field ? 'checked' : '') . '> ';
        print $label;
    } else {
        if (isset($object->$field) && $object->$field) {
            print '<span class="badge badge-status4 badge-status">' . $label . '</span>';
        } else {
            print '<span class="badge badge-status8 badge-status">' . $label . '</span>';
        }
    }
    print '</td>';
    
    if ($i % 3 == 2) print '</tr>';
    $i++;
}

// Add empty cells if needed to complete the last row
while ($i % 3 != 0) {
    print '<td></td>';
    $i++;
    if ($i % 3 == 0) print '</tr>';
}

print '</table>';

print '<div class="clearboth"></div>';

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
    print '<a class="flotte-btn flotte-btn-cancel" href="' . ($id > 0 ? $_SERVER['PHP_SELF'] . '?id=' . $id : 'inspection_list.php') . '">' . $langs->trans('Cancel') . '</a>';
    print '<a class="flotte-btn flotte-btn-back" href="' . dol_buildpath('/flotte/inspection_list.php', 1) . '">' . $langs->trans('BackToList') . '</a>';
    print '</div>';
    print '</form>';
} elseif ($id > 0) {
    // Action buttons
    print '<div class="center" style="margin-top: 20px; margin-bottom: 10px;">';
    print '<a class="flotte-btn flotte-btn-primary" href="' . $_SERVER['PHP_SELF'] . '?id=' . $id . '&action=edit">' . $langs->trans('Modify') . '</a>';
    print '<a class="flotte-btn flotte-btn-delete" href="' . $_SERVER['PHP_SELF'] . '?id=' . $id . '&action=delete">' . $langs->trans('Delete') . '</a>';
    print '<a class="flotte-btn flotte-btn-back" href="' . dol_buildpath('/flotte/inspection_list.php', 1) . '">' . $langs->trans('BackToList') . '</a>';
    print '</div>';
}

dol_fiche_end();

// End of page
llxFooter();
$db->close();
?>
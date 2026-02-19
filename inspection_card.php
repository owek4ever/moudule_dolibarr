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

/* ── Checklist grid ── */
.dc-checklist {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 0;
    padding: 0;
}
@media (max-width: 640px) { .dc-checklist { grid-template-columns: repeat(2, 1fr); } }
.dc-check-item {
    display: flex; align-items: center; gap: 10px;
    padding: 11px 20px;
    border-bottom: 1px solid #f5f6fb;
    border-right: 1px solid #f5f6fb;
    font-size: 13px; color: #2d3748;
}
.dc-check-item:nth-child(3n) { border-right: none; }
.dc-check-item label { display: flex; align-items: center; gap: 9px; cursor: pointer; font-size: 13px; color: #2d3748; margin: 0; }
.dc-check-item input[type="checkbox"] { width: 15px !important; height: 15px !important; cursor: pointer; accent-color: #3c4758; }
.dc-pill-yes { background: #edfaf3; color: #1a7d4a; padding: 3px 10px; border-radius: 20px; font-size: 12px; font-weight: 600; display: inline-flex; align-items: center; gap: 5px; }
.dc-pill-no  { background: #f5f6fb; color: #8b92a9; padding: 3px 10px; border-radius: 20px; font-size: 12px; font-weight: 600; display: inline-flex; align-items: center; gap: 5px; }

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

$isEdit   = ($action == 'edit');
$isCreate = ($action == 'create');
$isView   = (!$isEdit && !$isCreate);

$pageTitle = $isCreate ? $langs->trans('NewInspection') : ($isEdit ? $langs->trans('EditInspection') : $langs->trans('Inspection'));
$pageSub   = $isCreate ? $langs->trans('FillInInspectionDetails') : (isset($object->ref) ? $object->ref : '');

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
print '    <div class="dc-header-icon"><i class="fa fa-clipboard-list"></i></div>';
print '    <div>';
print '      <div class="dc-header-title">'.dol_escape_htmltag($pageTitle).'</div>';
if ($pageSub) print '      <div class="dc-header-sub">'.dol_escape_htmltag($pageSub).'</div>';
print '    </div>';
print '  </div>';
print '  <div class="dc-header-actions">';
if ($isView && $id > 0) {
    print '<a class="dc-btn dc-btn-ghost" href="'.dol_buildpath('/flotte/inspection_list.php', 1).'"><i class="fa fa-arrow-left"></i> '.$langs->trans('BackToList').'</a>';
    print '<a class="dc-btn dc-btn-ghost" href="'.$_SERVER['PHP_SELF'].'?id='.$id.'&action=edit"><i class="fa fa-pen"></i> '.$langs->trans('Modify').'</a>';
    print '<a class="dc-btn dc-btn-danger" href="'.$_SERVER['PHP_SELF'].'?id='.$id.'&action=delete"><i class="fa fa-trash"></i> '.$langs->trans('Delete').'</a>';
}
print '  </div>';
print '</div>';

/* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
   ROW 1 — Inspection Info + Meter & Fuel
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */
print '<div class="dc-grid">';

/* ── Card: Inspection Information ── */
print '<div class="dc-card">';
print '  <div class="dc-card-header">';
print '    <div class="dc-card-header-icon blue"><i class="fa fa-clipboard-list"></i></div>';
print '    <span class="dc-card-title">'.$langs->trans('InspectionInformation').'</span>';
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
    $vehicles = array();
    $sql = "SELECT rowid, ref, maker, model FROM ".MAIN_DB_PREFIX."flotte_vehicle WHERE entity IN (".getEntity('flotte').")";
    $resql = $db->query($sql);
    if ($resql) {
        while ($obj = $db->fetch_object($resql)) {
            $vehicles[$obj->rowid] = $obj->ref . ' - ' . $obj->maker . ' ' . $obj->model;
        }
    }
    print $form->selectarray('fk_vehicle', $vehicles, (isset($object->fk_vehicle) ? $object->fk_vehicle : ''), 1);
} else {
    if (!empty($object->fk_vehicle)) {
        $sql = "SELECT ref, maker, model FROM ".MAIN_DB_PREFIX."flotte_vehicle WHERE rowid = ".((int) $object->fk_vehicle);
        $resql = $db->query($sql);
        if ($resql && $db->num_rows($resql) > 0) {
            $obj = $db->fetch_object($resql);
            print '<span class="dc-chip"><i class="fa fa-car" style="font-size:11px;opacity:0.6;"></i>'.dol_escape_htmltag($obj->ref.' - '.$obj->maker.' '.$obj->model).'</span>';
        } else {
            print '<span style="color:#c4c9d8;">&mdash;</span>';
        }
    } else {
        print '<span style="color:#c4c9d8;">&mdash;</span>';
    }
}
print '    </div></div>';

// Registration Number
print '  <div class="dc-field">';
print '    <div class="dc-field-label">'.$langs->trans('RegistrationNumber').'</div>';
print '    <div class="dc-field-value">';
if ($isCreate || $isEdit) {
    print '<input type="text" name="registration_number" value="'.(isset($object->registration_number) ? dol_escape_htmltag($object->registration_number) : '').'">';
} else {
    print (!empty($object->registration_number) ? '<span class="dc-mono">'.dol_escape_htmltag($object->registration_number).'</span>' : '&mdash;');
}
print '    </div></div>';

// Date Out
print '  <div class="dc-field">';
print '    <div class="dc-field-label">'.$langs->trans('DateOut').'</div>';
print '    <div class="dc-field-value">';
if ($isCreate || $isEdit) {
    print $form->selectDate((isset($object->datetime_out) ? $object->datetime_out : ''), 'datetime_out', 1, 1, 1, '', 1, 1);
} else {
    print (!empty($object->datetime_out) ? dol_print_date($object->datetime_out, 'dayhour') : '&mdash;');
}
print '    </div></div>';

// Date In
print '  <div class="dc-field">';
print '    <div class="dc-field-label">'.$langs->trans('DateIn').'</div>';
print '    <div class="dc-field-value">';
if ($isCreate || $isEdit) {
    print $form->selectDate((isset($object->datetime_in) ? $object->datetime_in : ''), 'datetime_in', 1, 1, 1, '', 1, 1);
} else {
    print (!empty($object->datetime_in) ? dol_print_date($object->datetime_in, 'dayhour') : '&mdash;');
}
print '    </div></div>';

print '  </div>';// card-body
print '</div>';  // dc-card

/* ── Card: Meter & Fuel Information ── */
print '<div class="dc-card">';
print '  <div class="dc-card-header">';
print '    <div class="dc-card-header-icon amber"><i class="fa fa-tachometer-alt"></i></div>';
print '    <span class="dc-card-title">'.$langs->trans('MeterAndFuelInformation').'</span>';
print '  </div>';
print '  <div class="dc-card-body">';

// Meter Out
print '  <div class="dc-field">';
print '    <div class="dc-field-label">'.$langs->trans('MeterOut').'</div>';
print '    <div class="dc-field-value">';
if ($isCreate || $isEdit) {
    print '<input type="number" name="meter_out" value="'.(isset($object->meter_out) ? dol_escape_htmltag($object->meter_out) : '').'" min="0">';
} else {
    print (!empty($object->meter_out) ? '<span class="dc-mono">'.number_format((int)$object->meter_out).' km</span>' : '&mdash;');
}
print '    </div></div>';

// Meter In
print '  <div class="dc-field">';
print '    <div class="dc-field-label">'.$langs->trans('MeterIn').'</div>';
print '    <div class="dc-field-value">';
if ($isCreate || $isEdit) {
    print '<input type="number" name="meter_in" value="'.(isset($object->meter_in) ? dol_escape_htmltag($object->meter_in) : '').'" min="0">';
} else {
    print (!empty($object->meter_in) ? '<span class="dc-mono">'.number_format((int)$object->meter_in).' km</span>' : '&mdash;');
}
print '    </div></div>';

// Fuel Out
print '  <div class="dc-field">';
print '    <div class="dc-field-label">'.$langs->trans('FuelOut').'</div>';
print '    <div class="dc-field-value">';
if ($isCreate || $isEdit) {
    print '<input type="text" name="fuel_out" value="'.(isset($object->fuel_out) ? dol_escape_htmltag($object->fuel_out) : '').'">';
} else {
    print (!empty($object->fuel_out) ? '<span class="dc-mono">'.dol_escape_htmltag($object->fuel_out).'</span>' : '&mdash;');
}
print '    </div></div>';

// Fuel In
print '  <div class="dc-field">';
print '    <div class="dc-field-label">'.$langs->trans('FuelIn').'</div>';
print '    <div class="dc-field-value">';
if ($isCreate || $isEdit) {
    print '<input type="text" name="fuel_in" value="'.(isset($object->fuel_in) ? dol_escape_htmltag($object->fuel_in) : '').'">';
} else {
    print (!empty($object->fuel_in) ? '<span class="dc-mono">'.dol_escape_htmltag($object->fuel_in).'</span>' : '&mdash;');
}
print '    </div></div>';

print '  </div>';// card-body
print '</div>';  // dc-card

print '</div>';// dc-grid row1

/* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
   ROW 2 — Inspection Checklist
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */
$checklist_items = array(
    'petrol_card'        => $langs->trans('Petrol Card'),
    'lights_indicators'  => $langs->trans('Lights Indicators'),
    'inverter_cigarette' => $langs->trans('Inverter Cigarette'),
    'mats_seats'         => $langs->trans('Mats Seats'),
    'interior_damage'    => $langs->trans('Interior Damage'),
    'interior_lights'    => $langs->trans('Interior Lights'),
    'exterior_damage'    => $langs->trans('Exterior Damage'),
    'tyres_condition'    => $langs->trans('Tyres Condition'),
    'ladders'            => $langs->trans('Ladders'),
    'extension_leeds'    => $langs->trans('Extension Leeds'),
    'power_tools'        => $langs->trans('Power Tools'),
    'ac_working'         => $langs->trans('AC Working'),
    'headlights_working' => $langs->trans('Headlights Working'),
    'locks_alarms'       => $langs->trans('Locks Alarms'),
    'windows_condition'  => $langs->trans('Windows Condition'),
    'seats_condition'    => $langs->trans('Seats Condition'),
    'oil_check'          => $langs->trans('Oil Check'),
    'suspension'         => $langs->trans('Suspension'),
    'toolboxes_condition'=> $langs->trans('Toolboxes Condition'),
);

print '<div class="dc-card" style="margin-bottom:20px;">';
print '  <div class="dc-card-header">';
print '    <div class="dc-card-header-icon green"><i class="fa fa-check-double"></i></div>';
print '    <span class="dc-card-title">'.$langs->trans('InspectionChecklist').'</span>';
print '  </div>';
print '  <div class="dc-card-body">';
print '  <div class="dc-checklist">';

foreach ($checklist_items as $field => $label) {
    print '<div class="dc-check-item">';
    if ($isCreate || $isEdit) {
        print '<label>';
        print '<input type="checkbox" name="'.$field.'" value="1"'.(isset($object->$field) && $object->$field ? ' checked' : '').'>';
        print dol_escape_htmltag($label);
        print '</label>';
    } else {
        if (isset($object->$field) && $object->$field) {
            print '<span class="dc-pill-yes"><i class="fa fa-check" style="font-size:10px;"></i> '.dol_escape_htmltag($label).'</span>';
        } else {
            print '<span class="dc-pill-no"><i class="fa fa-times" style="font-size:10px;"></i> '.dol_escape_htmltag($label).'</span>';
        }
    }
    print '</div>';
}

print '  </div>';// dc-checklist
print '  </div>';// card-body
print '</div>';  // dc-card

/* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
   BOTTOM ACTION BAR
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */
if ($isCreate || $isEdit) {
    print '<div class="dc-action-bar">';
    print '<a class="dc-btn dc-btn-ghost dc-action-bar-left" href="'.dol_buildpath('/flotte/inspection_list.php', 1).'"><i class="fa fa-arrow-left"></i> '.$langs->trans('BackToList').'</a>';
    print '<a class="dc-btn dc-btn-ghost" href="'.($id > 0 ? $_SERVER['PHP_SELF'].'?id='.$id : dol_buildpath('/flotte/inspection_list.php', 1)).'"><i class="fa fa-times"></i> '.$langs->trans('Cancel').'</a>';
    print '<button type="submit" class="dc-btn dc-btn-primary"><i class="fa fa-check"></i> '.($isCreate ? $langs->trans('Create') : $langs->trans('Save')).'</button>';
    print '</div>';
    print '</form>';
} elseif ($id > 0) {
    print '<div class="dc-action-bar">';
    print '<a class="dc-btn dc-btn-ghost dc-action-bar-left" href="'.dol_buildpath('/flotte/inspection_list.php', 1).'"><i class="fa fa-arrow-left"></i> '.$langs->trans('BackToList').'</a>';
    print '<a class="dc-btn dc-btn-ghost" href="'.$_SERVER['PHP_SELF'].'?id='.$id.'&action=edit"><i class="fa fa-pen"></i> '.$langs->trans('Modify').'</a>';
    print '<a class="dc-btn dc-btn-danger" href="'.$_SERVER['PHP_SELF'].'?id='.$id.'&action=delete"><i class="fa fa-trash"></i> '.$langs->trans('Delete').'</a>';
    print '</div>';
}

print '</div>';// dc-page

// End of page
llxFooter();
$db->close();
?>
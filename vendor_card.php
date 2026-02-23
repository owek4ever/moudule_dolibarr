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
require_once DOL_DOCUMENT_ROOT.'/core/class/html.formother.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.formfile.class.php';

// Function to generate next vehicle reference
function getNextVehicleRef($db, $entity) {
    $prefix = "VEH-";
    
    // Get the last reference from database
    $sql = "SELECT ref FROM ".MAIN_DB_PREFIX."flotte_vehicle";
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
    
    // Format with leading zeros (e.g., VEH-0001)
    return $prefix.str_pad($next_number, 4, '0', STR_PAD_LEFT);
}

// Function to handle file upload
function handleFileUpload($file_field_name, $upload_dir) {
    if (isset($_FILES[$file_field_name]) && $_FILES[$file_field_name]['error'] == 0) {
        $allowed = array('jpg', 'jpeg', 'png', 'pdf');
        $filename = $_FILES[$file_field_name]['name'];
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        
        if (in_array($ext, $allowed)) {
            $new_filename = uniqid() . '_' . $filename;
            $destination = $upload_dir . '/' . $new_filename;
            
            if (move_uploaded_file($_FILES[$file_field_name]['tmp_name'], $destination)) {
                return $new_filename;
            }
        }
    }
    return null;
}

// Get parameters
$id = GETPOST('id', 'int');
$action = GETPOST('action', 'aZ09') ? GETPOST('action', 'aZ09') : 'view';
$confirm = GETPOST('confirm', 'alpha');

// Security check
restrictedArea($user, 'flotte');

// Initialize variables
$object = new stdClass();
$error = 0;
$errors = array();

// Generate reference for new vehicle
if ($action == 'create' && empty($object->ref)) {
    $object->ref = getNextVehicleRef($db, $conf->entity);
}

// Define upload directory
$upload_dir = DOL_DATA_ROOT.'/flotte/vehicle';
if (!is_dir($upload_dir)) {
    dol_mkdir($upload_dir);
}

/*
 * Actions
 */

if ($action == 'add' && $_POST) {
    $db->begin();
    
    // Prepare data
    $ref = GETPOST('ref', 'alpha');
    $maker = GETPOST('maker', 'alpha');
    $model = GETPOST('model', 'alpha');
    $type = GETPOST('type', 'alpha');
    $year = GETPOST('year', 'int');
    $initial_mileage = GETPOST('initial_mileage', 'int');
    $registration_expiry = GETPOST('registration_expiry', 'alpha');
    $in_service = GETPOST('in_service', 'int') ? 1 : 0;
    $department = GETPOST('department', 'alpha');
    $engine_type = GETPOST('engine_type', 'alpha');
    $horsepower = GETPOST('horsepower', 'alpha');
    $color = GETPOST('color', 'alpha');
    $vin = GETPOST('vin', 'alpha');
    $license_plate = GETPOST('license_plate', 'alpha');
    $license_expiry = GETPOST('license_expiry', 'alpha');
    
    // NEW FIELDS
    $length_cm = GETPOST('length_cm', 'alpha');
    $width_cm = GETPOST('width_cm', 'alpha');
    $height_cm = GETPOST('height_cm', 'alpha');
    $max_weight_kg = GETPOST('max_weight_kg', 'alpha');
    $ground_height_cm = GETPOST('ground_height_cm', 'alpha');
    $insurance_expiry = GETPOST('insurance_expiry', 'alpha');
    
    // Auto-generate reference if empty
    if (empty($ref)) {
        $ref = getNextVehicleRef($db, $conf->entity);
    }
    
    // Validate required fields
    if (empty($ref)) {
        $error++;
        $errors[] = 'Reference is required';
    }
    
    if (!$error) {
        // Handle file uploads
        $vehicle_photo = handleFileUpload('vehicle_photo', $upload_dir);
        $registration_card = handleFileUpload('registration_card', $upload_dir);
        $platform_registration_card = handleFileUpload('platform_registration_card', $upload_dir);
        $insurance_document = handleFileUpload('insurance_document', $upload_dir);
        
        // Convert dates to timestamps and validate
        $registration_expiry_ts = dol_stringtotime($registration_expiry);
        $license_expiry_ts = dol_stringtotime($license_expiry);
        $insurance_expiry_ts = dol_stringtotime($insurance_expiry);
        
        // Insert into database
        $sql = "INSERT INTO ".MAIN_DB_PREFIX."flotte_vehicle (";
        $sql .= "ref, entity, maker, model, type, year, initial_mileage, registration_expiry, in_service, department, ";
        $sql .= "engine_type, horsepower, color, vin, license_plate, license_expiry, ";
        $sql .= "length_cm, width_cm, height_cm, max_weight_kg, ground_height_cm, insurance_expiry, ";
        $sql .= "vehicle_photo, registration_card, platform_registration_card, insurance_document, fk_user_author";
        $sql .= ") VALUES (";
        $sql .= "'".$db->escape($ref)."', ".getEntity('flotte').", '".$db->escape($maker)."', '".$db->escape($model)."', ";
        $sql .= "'".$db->escape($type)."', ".($year ? $year : "NULL").", ".($initial_mileage ? $initial_mileage : "NULL").", ";
        $sql .= (!empty($registration_expiry) && $registration_expiry_ts > 0 ? "'".$db->idate($registration_expiry_ts)."'" : "NULL").", ";
        $sql .= $in_service.", '".$db->escape($department)."', '".$db->escape($engine_type)."', ";
        $sql .= "'".$db->escape($horsepower)."', '".$db->escape($color)."', '".$db->escape($vin)."', ";
        $sql .= "'".$db->escape($license_plate)."', ";
        $sql .= (!empty($license_expiry) && $license_expiry_ts > 0 ? "'".$db->idate($license_expiry_ts)."'" : "NULL").", ";
        $sql .= ($length_cm ? "'".$db->escape($length_cm)."'" : "NULL").", ";
        $sql .= ($width_cm ? "'".$db->escape($width_cm)."'" : "NULL").", ";
        $sql .= ($height_cm ? "'".$db->escape($height_cm)."'" : "NULL").", ";
        $sql .= ($max_weight_kg ? "'".$db->escape($max_weight_kg)."'" : "NULL").", ";
        $sql .= ($ground_height_cm ? "'".$db->escape($ground_height_cm)."'" : "NULL").", ";
        $sql .= (!empty($insurance_expiry) && $insurance_expiry_ts > 0 ? "'".$db->idate($insurance_expiry_ts)."'" : "NULL").", ";
        $sql .= ($vehicle_photo ? "'".$db->escape($vehicle_photo)."'" : "NULL").", ";
        $sql .= ($registration_card ? "'".$db->escape($registration_card)."'" : "NULL").", ";
        $sql .= ($platform_registration_card ? "'".$db->escape($platform_registration_card)."'" : "NULL").", ";
        $sql .= ($insurance_document ? "'".$db->escape($insurance_document)."'" : "NULL").", ";
        $sql .= $user->id;
        $sql .= ")";
        
        $resql = $db->query($sql);
        if ($resql) {
            $id = $db->last_insert_id(MAIN_DB_PREFIX."flotte_vehicle");
            $db->commit();
            setEventMessages($langs->trans("VehicleCreatedSuccessfully"), null, 'mesgs');
            header("Location: ".$_SERVER['PHP_SELF']."?id=".$id);
            exit;
        } else {
            $error++;
            $errors[] = $db->lasterror();
            $db->rollback();
        }
    }
}

if ($action == 'update' && $_POST && $id > 0) {
    $db->begin();
    
    // Prepare data
    $ref = GETPOST('ref', 'alpha');
    $maker = GETPOST('maker', 'alpha');
    $model = GETPOST('model', 'alpha');
    $type = GETPOST('type', 'alpha');
    $year = GETPOST('year', 'int');
    $initial_mileage = GETPOST('initial_mileage', 'int');
    $registration_expiry = GETPOST('registration_expiry', 'alpha');
    $in_service = GETPOST('in_service', 'int') ? 1 : 0;
    $department = GETPOST('department', 'alpha');
    $engine_type = GETPOST('engine_type', 'alpha');
    $horsepower = GETPOST('horsepower', 'alpha');
    $color = GETPOST('color', 'alpha');
    $vin = GETPOST('vin', 'alpha');
    $license_plate = GETPOST('license_plate', 'alpha');
    $license_expiry = GETPOST('license_expiry', 'alpha');
    
    // NEW FIELDS
    $length_cm = GETPOST('length_cm', 'alpha');
    $width_cm = GETPOST('width_cm', 'alpha');
    $height_cm = GETPOST('height_cm', 'alpha');
    $max_weight_kg = GETPOST('max_weight_kg', 'alpha');
    $ground_height_cm = GETPOST('ground_height_cm', 'alpha');
    $insurance_expiry = GETPOST('insurance_expiry', 'alpha');
    
    // Validate required fields
    if (empty($ref)) {
        $error++;
        $errors[] = 'Reference is required';
    }
    
    if (!$error) {
        // Handle file uploads (only update if new files are uploaded)
        $vehicle_photo = handleFileUpload('vehicle_photo', $upload_dir);
        $registration_card = handleFileUpload('registration_card', $upload_dir);
        $platform_registration_card = handleFileUpload('platform_registration_card', $upload_dir);
        $insurance_document = handleFileUpload('insurance_document', $upload_dir);
        
        // Convert dates to timestamps and validate
        $registration_expiry_ts = dol_stringtotime($registration_expiry);
        $license_expiry_ts = dol_stringtotime($license_expiry);
        $insurance_expiry_ts = dol_stringtotime($insurance_expiry);
        
        // Update database
        $sql = "UPDATE ".MAIN_DB_PREFIX."flotte_vehicle SET ";
        $sql .= "ref = '".$db->escape($ref)."', ";
        $sql .= "maker = '".$db->escape($maker)."', ";
        $sql .= "model = '".$db->escape($model)."', ";
        $sql .= "type = '".$db->escape($type)."', ";
        $sql .= "year = ".($year ? $year : "NULL").", ";
        $sql .= "initial_mileage = ".($initial_mileage ? $initial_mileage : "NULL").", ";
        $sql .= "registration_expiry = ".(!empty($registration_expiry) && $registration_expiry_ts > 0 ? "'".$db->idate($registration_expiry_ts)."'" : "NULL").", ";
        $sql .= "in_service = ".$in_service.", ";
        $sql .= "department = '".$db->escape($department)."', ";
        $sql .= "engine_type = '".$db->escape($engine_type)."', ";
        $sql .= "horsepower = '".$db->escape($horsepower)."', ";
        $sql .= "color = '".$db->escape($color)."', ";
        $sql .= "vin = '".$db->escape($vin)."', ";
        $sql .= "license_plate = '".$db->escape($license_plate)."', ";
        $sql .= "license_expiry = ".(!empty($license_expiry) && $license_expiry_ts > 0 ? "'".$db->idate($license_expiry_ts)."'" : "NULL").", ";
        $sql .= "length_cm = ".($length_cm ? "'".$db->escape($length_cm)."'" : "NULL").", ";
        $sql .= "width_cm = ".($width_cm ? "'".$db->escape($width_cm)."'" : "NULL").", ";
        $sql .= "height_cm = ".($height_cm ? "'".$db->escape($height_cm)."'" : "NULL").", ";
        $sql .= "max_weight_kg = ".($max_weight_kg ? "'".$db->escape($max_weight_kg)."'" : "NULL").", ";
        $sql .= "ground_height_cm = ".($ground_height_cm ? "'".$db->escape($ground_height_cm)."'" : "NULL").", ";
        $sql .= "insurance_expiry = ".(!empty($insurance_expiry) && $insurance_expiry_ts > 0 ? "'".$db->idate($insurance_expiry_ts)."'" : "NULL").", ";
        
        // Update file fields only if new files were uploaded
        if ($vehicle_photo) {
            $sql .= "vehicle_photo = '".$db->escape($vehicle_photo)."', ";
        }
        if ($registration_card) {
            $sql .= "registration_card = '".$db->escape($registration_card)."', ";
        }
        if ($platform_registration_card) {
            $sql .= "platform_registration_card = '".$db->escape($platform_registration_card)."', ";
        }
        if ($insurance_document) {
            $sql .= "insurance_document = '".$db->escape($insurance_document)."', ";
        }
        
        $sql .= "fk_user_modif = ".$user->id." ";
        $sql .= "WHERE rowid = ".((int) $id);
        
        $resql = $db->query($sql);
        if ($resql) {
            $db->commit();
            setEventMessages($langs->trans("VehicleUpdatedSuccessfully"), null, 'mesgs');
            header("Location: ".$_SERVER['PHP_SELF']."?id=".$id);
            exit;
        } else {
            $error++;
            $errors[] = $db->lasterror();
            $db->rollback();
        }
    }
}

// Delete action
if ($action == 'confirm_delete' && $confirm == 'yes' && $id > 0) {
    $db->begin();
    
    $sql = "DELETE FROM ".MAIN_DB_PREFIX."flotte_vehicle WHERE rowid = ".((int) $id);
    $resql = $db->query($sql);
    
    if ($resql) {
        $db->commit();
        setEventMessages($langs->trans("VehicleDeletedSuccessfully"), null, 'mesgs');
        header("Location: vehicle_list.php");
        exit;
    } else {
        $error++;
        $errors[] = $db->lasterror();
        $db->rollback();
    }
}

// Load object data
if ($id > 0) {
    $sql = "SELECT * FROM ".MAIN_DB_PREFIX."flotte_vehicle WHERE rowid = ".((int) $id);
    $resql = $db->query($sql);
    if ($resql) {
        $object = $db->fetch_object($resql);
        if (!$object) {
            print 'Record not found';
            exit;
        }
    } else {
        dol_print_error($db);
        exit;
    }
}

$form = new Form($db);
$formother = new FormOther($db);
$formfile = new FormFile($db);

// Initialize technical object to manage hooks. Note that conf->hooks_modules contains array of hooks
$hookmanager->initHooks(array('vehiclecard'));

/*
 * View
 */

$title = $langs->trans('Vehicle');
if ($action == 'create') {
    $title = $langs->trans('NewVehicle');
} elseif ($action == 'edit') {
    $title = $langs->trans('EditVehicle');
} elseif ($id > 0) {
    $title = $langs->trans('Vehicle') . " " . $object->ref;
}

llxHeader('', $title);

?>
<meta name="viewport" content="width=device-width, initial-scale=1.0">

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

/* ── Status badge ── */
.dc-badge {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 4px 11px; border-radius: 20px;
    font-size: 11.5px; font-weight: 600; white-space: nowrap;
}
.dc-badge::before { content: ''; width: 6px; height: 6px; border-radius: 50%; flex-shrink: 0; }
.dc-badge.inservice   { background: #edfaf3; color: #1a7d4a; }
.dc-badge.inservice::before   { background: #22c55e; }
.dc-badge.outofservice { background: #fef2f2; color: #b91c1c; }
.dc-badge.outofservice::before { background: #ef4444; }

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

/* ══════════════════════════════════════
   RESPONSIVE BREAKPOINTS
══════════════════════════════════════ */

/* Tablet (≤ 1024px) — tighten spacing */
@media (max-width: 1024px) {
    .dc-page {
        padding: 0 12px 40px;
    }
    .dc-field-label {
        flex: 0 0 130px;
    }
}

/* Small tablet (≤ 860px) — single column grid */
@media (max-width: 860px) {
    .dc-grid {
        grid-template-columns: 1fr;
    }
    .dc-page {
        padding: 0 10px 36px;
    }
}

/* Mobile (≤ 600px) — full reflow */
@media (max-width: 600px) {
    .dc-page {
        padding: 0 8px 28px;
    }

    /* Header stacks */
    .dc-header {
        flex-direction: column;
        align-items: flex-start;
        padding: 16px 0 14px;
        gap: 10px;
    }
    .dc-header-left {
        gap: 10px;
    }
    .dc-header-icon {
        width: 38px;
        height: 38px;
        font-size: 16px;
    }
    .dc-header-title {
        font-size: 17px;
    }
    .dc-header-actions {
        width: 100%;
        justify-content: flex-start;
        flex-wrap: wrap;
        gap: 6px;
    }
    .dc-btn {
        padding: 7px 12px;
        font-size: 12px;
    }

    /* Field rows stack vertically */
    .dc-field {
        flex-direction: column;
        gap: 5px;
        padding: 11px 14px;
    }
    .dc-field-label {
        flex: none;
        width: 100%;
        font-size: 11px;
    }
    .dc-field-value {
        width: 100%;
    }

    /* Card header padding */
    .dc-card-header {
        padding: 12px 14px;
    }

    /* Action bar stacks on mobile */
    .dc-action-bar {
        flex-direction: column-reverse;
        align-items: stretch;
        gap: 8px;
        padding: 14px 0 4px;
    }
    .dc-action-bar .dc-btn {
        justify-content: center;
        width: 100%;
    }
    .dc-action-bar-left {
        margin-right: 0;
        order: 99;
    }
}

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

/* ── Color swatch ── */
.dc-color-swatch {
    display: inline-block; width: 14px; height: 14px;
    border-radius: 3px; border: 1px solid rgba(0,0,0,0.15);
    vertical-align: middle; margin-right: 5px;
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
.dc-page input[type="file"] {
    font-size: 12.5px; color: #5a6482;
    font-family: 'DM Sans', sans-serif;
}
.dc-page textarea { resize: vertical !important; }

/* ── File upload zone ── */
.dc-file-zone {
    border: 1.5px dashed #d1d5e0;
    border-radius: 8px; padding: 14px 16px;
    background: #fafbfe; text-align: center;
    cursor: pointer; transition: border-color 0.15s;
}
.dc-file-zone:hover { border-color: #3c4758; background: #f5f6fa; }
.dc-file-zone i { font-size: 22px; color: #c4c9d8; margin-bottom: 6px; display: block; }
.dc-file-zone small { font-size: 11.5px; color: #9aa0b4; display: block; }
.dc-file-current {
    margin-top: 8px; font-size: 12px; color: #6b7280;
    background: #f0f2fa; padding: 4px 10px; border-radius: 5px;
    display: inline-flex; align-items: center; gap: 5px;
}

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
        $langs->trans('DeleteVehicle'),
        $langs->trans('ConfirmDeleteVehicle'),
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

$pageTitle = $isCreate ? $langs->trans('NewVehicle') : ($isEdit ? $langs->trans('EditVehicle') : $langs->trans('Vehicle'));
$pageSub   = $isCreate ? $langs->trans('FillInVehicleDetails') : (isset($object->ref) ? $object->ref : '');

// Form start
if ($isCreate || $isEdit) {
    print '<form method="POST" action="'.$_SERVER['PHP_SELF'].($id > 0 ? '?id='.$id : '').'" enctype="multipart/form-data">';
    print '<input type="hidden" name="action" value="'.($isCreate ? 'add' : 'update').'">';
    print '<input type="hidden" name="token" value="'.newToken().'">';
}

print '<div class="dc-page">';

/* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
   PAGE HEADER
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */
print '<div class="dc-header">';
print '  <div class="dc-header-left">';
print '    <div class="dc-header-icon"><i class="fa fa-car"></i></div>';
print '    <div>';
print '      <div class="dc-header-title">'.dol_escape_htmltag($pageTitle).'</div>';
if ($pageSub) print '      <div class="dc-header-sub">'.dol_escape_htmltag($pageSub).'</div>';
print '    </div>';
print '  </div>';
print '  <div class="dc-header-actions">';
if ($isView && $id > 0) {
    $inSvc = !empty($object->in_service);
    print '<span class="dc-badge '.($inSvc ? 'inservice' : 'outofservice').'">'.($inSvc ? $langs->trans('InService') : $langs->trans('OutOfService')).'</span>';
    print '<a class="dc-btn dc-btn-ghost" href="'.dol_buildpath('/flotte/vehicle_list.php', 1).'"><i class="fa fa-arrow-left"></i> '.$langs->trans('BackToList').'</a>';
    print '<a class="dc-btn dc-btn-ghost" href="'.$_SERVER['PHP_SELF'].'?id='.$id.'&action=edit"><i class="fa fa-pen"></i> '.$langs->trans('Modify').'</a>';
    print '<a class="dc-btn dc-btn-danger" href="'.$_SERVER['PHP_SELF'].'?id='.$id.'&action=delete"><i class="fa fa-trash"></i> '.$langs->trans('Delete').'</a>';
}
print '  </div>';
print '</div>';

/* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
   ROW 1 — Basic Info + Vehicle Details
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */
print '<div class="dc-grid">';

/* ── Card: Basic Information ── */
print '<div class="dc-card">';
print '  <div class="dc-card-header">';
print '    <div class="dc-card-header-icon blue"><i class="fa fa-car"></i></div>';
print '    <span class="dc-card-title">'.$langs->trans('BasicInformation').'</span>';
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

// Maker
print '  <div class="dc-field">';
print '    <div class="dc-field-label required">'.$langs->trans('Maker').'</div>';
print '    <div class="dc-field-value">';
if ($isCreate || $isEdit) print '<input type="text" name="maker" value="'.(isset($object->maker) ? dol_escape_htmltag($object->maker) : '').'" required>';
else print dol_escape_htmltag($object->maker);
print '    </div></div>';

// Model
print '  <div class="dc-field">';
print '    <div class="dc-field-label required">'.$langs->trans('VehicleModel').'</div>';
print '    <div class="dc-field-value">';
if ($isCreate || $isEdit) print '<input type="text" name="model" value="'.(isset($object->model) ? dol_escape_htmltag($object->model) : '').'" required>';
else print dol_escape_htmltag($object->model);
print '    </div></div>';

// Type
print '  <div class="dc-field">';
print '    <div class="dc-field-label">'.$langs->trans('Type').'</div>';
print '    <div class="dc-field-value">';
if ($isCreate || $isEdit) {
    $type_options = array(
        'Car' => $langs->trans('Car'),
        'Truck' => $langs->trans('Truck'),
        'Van' => $langs->trans('Van'),
        'Bus' => $langs->trans('Bus'),
        'Motorcycle' => $langs->trans('Motorcycle')
    );
    print $form->selectarray('type', $type_options, (isset($object->type) ? $object->type : ''), 1);
} else {
    print dol_escape_htmltag($object->type);
}
print '    </div></div>';

// Year
print '  <div class="dc-field">';
print '    <div class="dc-field-label">'.$langs->trans('Year').'</div>';
print '    <div class="dc-field-value">';
if ($isCreate || $isEdit) print '<input type="number" name="year" value="'.(isset($object->year) ? dol_escape_htmltag($object->year) : '').'" min="1900" max="2030">';
else print dol_escape_htmltag($object->year);
print '    </div></div>';

// Color
print '  <div class="dc-field">';
print '    <div class="dc-field-label">'.$langs->trans('Color').'</div>';
print '    <div class="dc-field-value">';
if ($isCreate || $isEdit) print '<input type="text" name="color" value="'.(isset($object->color) ? dol_escape_htmltag($object->color) : '').'">';
else {
    if (!empty($object->color)) print '<span class="dc-color-swatch" style="background-color:'.strtolower(dol_escape_htmltag($object->color)).'"></span>';
    print dol_escape_htmltag($object->color);
}
print '    </div></div>';

// Department
print '  <div class="dc-field">';
print '    <div class="dc-field-label">'.$langs->trans('Department').'</div>';
print '    <div class="dc-field-value">';
if ($isCreate || $isEdit) print '<input type="text" name="department" value="'.(isset($object->department) ? dol_escape_htmltag($object->department) : '').'">';
else print dol_escape_htmltag($object->department);
print '    </div></div>';

print '  </div>';// card-body
print '</div>';  // dc-card

/* ── Card: Vehicle Details ── */
print '<div class="dc-card">';
print '  <div class="dc-card-header">';
print '    <div class="dc-card-header-icon green"><i class="fa fa-cog"></i></div>';
print '    <span class="dc-card-title">'.$langs->trans('VehicleDetails').'</span>';
print '  </div>';
print '  <div class="dc-card-body">';

// License Plate
print '  <div class="dc-field">';
print '    <div class="dc-field-label">'.$langs->trans('LicensePlate').'</div>';
print '    <div class="dc-field-value">';
if ($isCreate || $isEdit) print '<input type="text" name="license_plate" value="'.(isset($object->license_plate) ? dol_escape_htmltag($object->license_plate) : '').'">';
else print (!empty($object->license_plate) ? '<span class="dc-mono">'.dol_escape_htmltag($object->license_plate).'</span>' : '&mdash;');
print '    </div></div>';

// VIN
print '  <div class="dc-field">';
print '    <div class="dc-field-label">'.$langs->trans('VIN').'</div>';
print '    <div class="dc-field-value">';
if ($isCreate || $isEdit) print '<input type="text" name="vin" value="'.(isset($object->vin) ? dol_escape_htmltag($object->vin) : '').'">';
else print (!empty($object->vin) ? '<span class="dc-mono">'.dol_escape_htmltag($object->vin).'</span>' : '&mdash;');
print '    </div></div>';

// Engine Type
print '  <div class="dc-field">';
print '    <div class="dc-field-label">'.$langs->trans('EngineType').'</div>';
print '    <div class="dc-field-value">';
if ($isCreate || $isEdit) {
    $engine_options = array(
        'Petrol'   => $langs->trans('Petrol'),
        'Diesel'   => $langs->trans('Diesel'),
        'Electric' => $langs->trans('Electric'),
        'Hybrid'   => $langs->trans('Hybrid')
    );
    print $form->selectarray('engine_type', $engine_options, (isset($object->engine_type) ? $object->engine_type : ''), 0);
} else {
    print dol_escape_htmltag($object->engine_type);
}
print '    </div></div>';

// Horsepower
print '  <div class="dc-field">';
print '    <div class="dc-field-label">'.$langs->trans('HorsePower').'</div>';
print '    <div class="dc-field-value">';
if ($isCreate || $isEdit) print '<input type="text" name="horsepower" value="'.(isset($object->horsepower) ? dol_escape_htmltag($object->horsepower) : '').'">';
else print (!empty($object->horsepower) ? dol_escape_htmltag($object->horsepower).' HP' : '&mdash;');
print '    </div></div>';

// Initial Mileage
print '  <div class="dc-field">';
print '    <div class="dc-field-label">'.$langs->trans('InitialMileage').'</div>';
print '    <div class="dc-field-value">';
if ($isCreate || $isEdit) print '<input type="number" name="initial_mileage" value="'.(isset($object->initial_mileage) ? dol_escape_htmltag($object->initial_mileage) : '').'" min="0">';
else print (!empty($object->initial_mileage) ? number_format($object->initial_mileage).' km' : '&mdash;');
print '    </div></div>';

// In Service
print '  <div class="dc-field">';
print '    <div class="dc-field-label">'.$langs->trans('InService').'</div>';
print '    <div class="dc-field-value">';
if ($isCreate || $isEdit) {
    print $form->selectyesno('in_service', (isset($object->in_service) ? $object->in_service : 1), 1);
} else {
    $inSvc2 = !empty($object->in_service);
    print '<span class="dc-badge '.($inSvc2 ? 'inservice' : 'outofservice').'">'.($inSvc2 ? $langs->trans('Yes') : $langs->trans('No')).'</span>';
}
print '    </div></div>';

print '  </div>';// card-body
print '</div>';  // dc-card

print '</div>';// dc-grid row1

/* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
   ROW 2 — Service/Expiry Info + Dimensions
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */
print '<div class="dc-grid">';

/* ── Card: Service Information ── */
print '<div class="dc-card">';
print '  <div class="dc-card-header">';
print '    <div class="dc-card-header-icon amber"><i class="fa fa-calendar-alt"></i></div>';
print '    <span class="dc-card-title">'.$langs->trans('ServiceInformation').'</span>';
print '  </div>';
print '  <div class="dc-card-body">';

// Registration Expiry
print '  <div class="dc-field">';
print '    <div class="dc-field-label">'.$langs->trans('RegistrationExpiry').'</div>';
print '    <div class="dc-field-value">';
if ($isCreate || $isEdit) print $form->selectDate((!empty($object->registration_expiry) ? $object->registration_expiry : ''), 'registration_expiry', 0, 0, 1, '', 1, 0);
else print (!empty($object->registration_expiry) ? dol_print_date($object->registration_expiry, 'day') : '&mdash;');
print '    </div></div>';

// License Expiry
print '  <div class="dc-field">';
print '    <div class="dc-field-label">'.$langs->trans('LicenseExpiry').'</div>';
print '    <div class="dc-field-value">';
if ($isCreate || $isEdit) print $form->selectDate((!empty($object->license_expiry) ? $object->license_expiry : ''), 'license_expiry', 0, 0, 1, '', 1, 0);
else print (!empty($object->license_expiry) ? dol_print_date($object->license_expiry, 'day') : '&mdash;');
print '    </div></div>';

// Insurance Expiry
print '  <div class="dc-field">';
print '    <div class="dc-field-label">'.$langs->trans('InsuranceExpiry').'</div>';
print '    <div class="dc-field-value">';
if ($isCreate || $isEdit) print $form->selectDate((!empty($object->insurance_expiry) ? $object->insurance_expiry : ''), 'insurance_expiry', 0, 0, 1, '', 1, 0);
else print (!empty($object->insurance_expiry) ? dol_print_date($object->insurance_expiry, 'day') : '&mdash;');
print '    </div></div>';

print '  </div>';// card-body
print '</div>';  // dc-card

/* ── Card: Dimensions & Technical Specs ── */
print '<div class="dc-card">';
print '  <div class="dc-card-header">';
print '    <div class="dc-card-header-icon purple"><i class="fa fa-ruler-combined"></i></div>';
print '    <span class="dc-card-title">'.$langs->trans('DimensionsAndSpecs').'</span>';
print '  </div>';
print '  <div class="dc-card-body">';

// Length
print '  <div class="dc-field">';
print '    <div class="dc-field-label">'.$langs->trans('Length').' (cm)</div>';
print '    <div class="dc-field-value">';
if ($isCreate || $isEdit) print '<input type="number" step="0.01" name="length_cm" value="'.(isset($object->length_cm) ? dol_escape_htmltag($object->length_cm) : '').'" min="0">';
else print (!empty($object->length_cm) ? dol_escape_htmltag($object->length_cm).' cm' : '&mdash;');
print '    </div></div>';

// Width
print '  <div class="dc-field">';
print '    <div class="dc-field-label">'.$langs->trans('Width').' (cm)</div>';
print '    <div class="dc-field-value">';
if ($isCreate || $isEdit) print '<input type="number" step="0.01" name="width_cm" value="'.(isset($object->width_cm) ? dol_escape_htmltag($object->width_cm) : '').'" min="0">';
else print (!empty($object->width_cm) ? dol_escape_htmltag($object->width_cm).' cm' : '&mdash;');
print '    </div></div>';

// Height
print '  <div class="dc-field">';
print '    <div class="dc-field-label">'.$langs->trans('Height').' (cm)</div>';
print '    <div class="dc-field-value">';
if ($isCreate || $isEdit) print '<input type="number" step="0.01" name="height_cm" value="'.(isset($object->height_cm) ? dol_escape_htmltag($object->height_cm) : '').'" min="0">';
else print (!empty($object->height_cm) ? dol_escape_htmltag($object->height_cm).' cm' : '&mdash;');
print '    </div></div>';

// Max Weight
print '  <div class="dc-field">';
print '    <div class="dc-field-label">'.$langs->trans('MaxWeight').' (kg)</div>';
print '    <div class="dc-field-value">';
if ($isCreate || $isEdit) print '<input type="number" step="0.01" name="max_weight_kg" value="'.(isset($object->max_weight_kg) ? dol_escape_htmltag($object->max_weight_kg) : '').'" min="0">';
else print (!empty($object->max_weight_kg) ? dol_escape_htmltag($object->max_weight_kg).' kg' : '&mdash;');
print '    </div></div>';

// Ground Height
print '  <div class="dc-field">';
print '    <div class="dc-field-label">'.$langs->trans('GroundHeight').' (cm)</div>';
print '    <div class="dc-field-value">';
if ($isCreate || $isEdit) print '<input type="number" step="0.01" name="ground_height_cm" value="'.(isset($object->ground_height_cm) ? dol_escape_htmltag($object->ground_height_cm) : '').'" min="0">';
else print (!empty($object->ground_height_cm) ? dol_escape_htmltag($object->ground_height_cm).' cm' : '&mdash;');
print '    </div></div>';

print '  </div>';// card-body
print '</div>';  // dc-card

print '</div>';// dc-grid row2

/* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
   ROW 3 — Documents & Photos (full width)
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */
print '<div class="dc-card" style="margin-bottom:20px;">';
print '  <div class="dc-card-header">';
print '    <div class="dc-card-header-icon red"><i class="fa fa-paperclip"></i></div>';
print '    <span class="dc-card-title">'.$langs->trans('DocumentsAndPhotos').'</span>';
print '  </div>';
print '  <div class="dc-card-body">';
print '  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:0;">';

$file_fields = array(
    array('field' => 'vehicle_photo',             'label' => $langs->trans('VehiclePhoto'),            'accept' => 'image/*,.pdf'),
    array('field' => 'registration_card',         'label' => $langs->trans('RegistrationCard'),        'accept' => 'image/*,.pdf'),
    array('field' => 'platform_registration_card','label' => $langs->trans('PlatformRegistrationCard'),'accept' => 'image/*,.pdf'),
    array('field' => 'insurance_document',        'label' => $langs->trans('InsuranceDocument'),       'accept' => 'image/*,.pdf'),
);

foreach ($file_fields as $ff) {
    $fieldVal = isset($object->{$ff['field']}) ? $object->{$ff['field']} : '';
    print '<div class="dc-field" style="flex-direction:column;gap:8px;">';
    print '  <div class="dc-field-label" style="flex:none;">'.$ff['label'].'</div>';
    print '  <div class="dc-field-value" style="width:100%;">';
    if ($isCreate || $isEdit) {
        print '<div class="dc-file-zone">';
        print '  <i class="fa fa-cloud-upload-alt"></i>';
        print '  <input type="file" name="'.$ff['field'].'" accept="'.$ff['accept'].'" style="width:100%;cursor:pointer;">';
        print '  <small>Accepted: JPG, PNG, PDF</small>';
        print '</div>';
        if (!empty($fieldVal)) {
            print '<div class="dc-file-current"><i class="fa fa-paperclip"></i> '.$langs->trans('Current').': '.dol_escape_htmltag($fieldVal).'</div>';
        }
    } else {
        if (!empty($fieldVal)) {
            $file_path = $upload_dir.'/'.$fieldVal;
            $ext = strtolower(pathinfo($fieldVal, PATHINFO_EXTENSION));
            if (file_exists($file_path)) {
                if (in_array($ext, array('jpg','jpeg','png','gif'))) {
                    print '<a href="'.DOL_URL_ROOT.'/document.php?modulepart=flotte&file=vehicle/'.urlencode($fieldVal).'" target="_blank">';
                    print '<img src="'.DOL_URL_ROOT.'/document.php?modulepart=flotte&file=vehicle/'.urlencode($fieldVal).'" style="max-width:100px;max-height:80px;border-radius:6px;border:1px solid #e8eaf0;" />';
                    print '</a>';
                } else {
                    print '<a href="'.DOL_URL_ROOT.'/document.php?modulepart=flotte&file=vehicle/'.urlencode($fieldVal).'" target="_blank" class="dc-file-current"><i class="fa fa-download"></i> '.dol_escape_htmltag($fieldVal).'</a>';
                }
            } else {
                print '<span class="dc-mono">'.dol_escape_htmltag($fieldVal).'</span>';
            }
        } else {
            print '<span style="color:#c4c9d8;font-size:13px;">&mdash;</span>';
        }
    }
    print '  </div>';
    print '</div>';
}

print '  </div>';// inner grid
print '  </div>';// card-body
print '</div>';   // dc-card

/* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
   BOTTOM ACTION BAR
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */
if ($isCreate || $isEdit) {
    print '<div class="dc-action-bar">';
    print '<a class="dc-btn dc-btn-ghost dc-action-bar-left" href="'.dol_buildpath('/flotte/vehicle_list.php', 1).'"><i class="fa fa-arrow-left"></i> '.$langs->trans('BackToList').'</a>';
    print '<a class="dc-btn dc-btn-ghost" href="'.($id > 0 ? $_SERVER['PHP_SELF'].'?id='.$id : dol_buildpath('/flotte/vehicle_list.php', 1)).'"><i class="fa fa-times"></i> '.$langs->trans('Cancel').'</a>';
    print '<button type="submit" class="dc-btn dc-btn-primary"><i class="fa fa-check"></i> '.($isCreate ? $langs->trans('Create') : $langs->trans('Save')).'</button>';
    print '</div>';
    print '</form>';
} elseif ($id > 0) {
    print '<div class="dc-action-bar">';
    print '<a class="dc-btn dc-btn-ghost dc-action-bar-left" href="'.dol_buildpath('/flotte/vehicle_list.php', 1).'"><i class="fa fa-arrow-left"></i> '.$langs->trans('BackToList').'</a>';
    print '<a class="dc-btn dc-btn-ghost" href="'.$_SERVER['PHP_SELF'].'?id='.$id.'&action=edit"><i class="fa fa-pen"></i> '.$langs->trans('Modify').'</a>';
    print '<a class="dc-btn dc-btn-danger" href="'.$_SERVER['PHP_SELF'].'?id='.$id.'&action=delete"><i class="fa fa-trash"></i> '.$langs->trans('Delete').'</a>';
    print '</div>';
}

print '</div>';// dc-page

// End of page
llxFooter();
$db->close();
?>
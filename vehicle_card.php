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

// Subheader
$linkback = '<a href="' . DOL_URL_ROOT . '/flotte/vehicle_list.php">' . $langs->trans('BackToList') . '</a>';

$h = 0;
$head = array();
$head[$h][0] = 'javascript:void(0);'; // Non-clickable link
$head[$h][1] = $langs->trans('Card');
$head[$h][2] = 'card';
$h++;

dol_fiche_head($head, 'card', $langs->trans('Vehicle'), -1, 'vehicle');

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

if ($action == 'create' || $action == 'edit') {
    print '<form method="POST" action="' . $_SERVER['PHP_SELF'] . ($id > 0 ? '?id=' . $id : '') . '" enctype="multipart/form-data">';
    print '<input type="hidden" name="action" value="' . ($action == 'create' ? 'add' : 'update') . '">';
    print '<input type="hidden" name="token" value="' . newToken() . '">';
}

print '<div class="fichecenter">';
print '<div class="fichehalfleft">';

// Basic Information
print load_fiche_titre($langs->trans('Basic Information'), '', '');
print '<table class="border tableforfield" width="100%">';

// Reference
print '<tr><td class="titlefield">' . $langs->trans('Reference') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    print $form->textwithpicto('<input type="text" class="flat" name="ref" value="' . (isset($object->ref) ? $object->ref : '') . '" size="20" readonly>', $langs->trans('AutoGenerated'));
} else {
    print $object->ref;
}
print '</td></tr>';

// Maker
print '<tr><td>' . $langs->trans('Maker') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    print '<input type="text" class="flat" name="maker" value="' . (isset($object->maker) ? $object->maker : '') . '" size="20" required>';
} else {
    print $object->maker;
}
print '</td></tr>';

// Model
print '<tr><td>' . $langs->trans('Vehicle Model') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    print '<input type="text" class="flat" name="model" value="' . (isset($object->model) ? $object->model : '') . '" size="20" required>';
} else {
    print $object->model;
}
print '</td></tr>';

// Type
print '<tr><td>' . $langs->trans('Type') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    $type_options = array(
        'Car' => $langs->trans('Car'),
        'Truck' => $langs->trans('Truck'),
        'Van' => $langs->trans('Van'),
        'Bus' => $langs->trans('Bus'),
        'Motorcycle' => $langs->trans('Motorcycle')
    );
    print $form->selectarray('type', $type_options, (isset($object->type) ? $object->type : ''), 1);
} else {
    print $object->type;
}
print '</td></tr>';

// Year
print '<tr><td>' . $langs->trans('Year') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    print '<input type="number" class="flat" name="year" value="' . (isset($object->year) ? $object->year : '') . '" min="1900" max="2030">';
} else {
    print $object->year;
}
print '</td></tr>';

print '</table>';

print '</div>';
print '<div class="fichehalfright">';

// Vehicle Details
print load_fiche_titre($langs->trans('Vehicle Details'), '', '');
print '<table class="border tableforfield" width="100%">';

// License Plate
print '<tr><td class="titlefield">' . $langs->trans('License Plate') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    print '<input type="text" class="flat" name="license_plate" value="' . (isset($object->license_plate) ? $object->license_plate : '') . '" size="20">';
} else {
    print $object->license_plate;
}
print '</td></tr>';

// VIN
print '<tr><td>' . $langs->trans('VIN') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    print '<input type="text" class="flat" name="vin" value="' . (isset($object->vin) ? $object->vin : '') . '" size="20">';
} else {
    print $object->vin;
}
print '</td></tr>';

// Color
print '<tr><td>' . $langs->trans('Color') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    print '<input type="text" class="flat" name="color" value="' . (isset($object->color) ? $object->color : '') . '" size="20">';
} else {
    if (!empty($object->color)) {
        print '<span class="colorbadge" style="background-color:' . strtolower($object->color) . '"></span> ';
    }
    print $object->color;
}
print '</td></tr>';

// Engine Type
print '<tr><td>' . $langs->trans('Engine Type') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    $engine_options = array(
        'Petrol' => $langs->trans('Petrol'),
        'Diesel' => $langs->trans('Diesel'),
        'Electric' => $langs->trans('Electric'),
        'Hybrid' => $langs->trans('Hybrid')
    );
    print $form->selectarray('engine_type', $engine_options, (isset($object->engine_type) ? $object->engine_type : ''), 0);
} else {
    print $object->engine_type;
}
print '</td></tr>';

// Horsepower
print '<tr><td>' . $langs->trans('Horse power') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    print '<input type="text" class="flat" name="horsepower" value="' . (isset($object->horsepower) ? $object->horsepower : '') . '" size="20">';
} else {
    print $object->horsepower . ' HP';
}
print '</td></tr>';

print '</table>';

print '</div>';
print '</div>';

print '<div class="clearboth"></div><br>';

print '<div class="fichecenter">';
print '<div class="fichehalfleft">';

// Service Information
print load_fiche_titre($langs->trans('Service Information'), '', '');
print '<table class="border tableforfield" width="100%">';

// Initial Mileage
print '<tr><td class="titlefield">' . $langs->trans('Initial Mileage') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    print '<input type="number" class="flat" name="initial_mileage" value="' . (isset($object->initial_mileage) ? $object->initial_mileage : '') . '" min="0"> km';
} else {
    print number_format($object->initial_mileage) . ' km';
}
print '</td></tr>';

// Department
print '<tr><td>' . $langs->trans('Department') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    print '<input type="text" class="flat" name="department" value="' . (isset($object->department) ? $object->department : '') . '" size="20">';
} else {
    print $object->department;
}
print '</td></tr>';

// Registration Expiry
print '<tr><td>' . $langs->trans('Registration Expiry') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    print $form->selectDate(
        (!empty($object->registration_expiry) ? $object->registration_expiry : ''), 
        'registration_expiry'
    );
} else {
    if (!empty($object->registration_expiry)) {
        print dol_print_date($object->registration_expiry, 'day');
    } else {
        print '&nbsp;';
    }
}
print '</td></tr>';

// License Expiry
print '<tr><td>' . $langs->trans('License Expiry') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    print $form->selectDate(
        (!empty($object->license_expiry) ? $object->license_expiry : ''), 
        'license_expiry'
    );
} else {
    if (!empty($object->license_expiry)) {
        print dol_print_date($object->license_expiry, 'day');
    } else {
        print '&nbsp;';
    }
}
print '</td></tr>';

// In Service
print '<tr><td>' . $langs->trans('In Service') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    print $form->selectyesno('in_service', (isset($object->in_service) ? $object->in_service : 1), 1);
} else {
    print yn($object->in_service);
}
print '</td></tr>';

print '</table>';

print '</div>';

print '<div class="fichehalfright">';

// ============ NEW SECTION: Dimensions & Technical Specs ============
print load_fiche_titre($langs->trans('Dimensions & Technical Specs'), '', '');
print '<table class="border tableforfield" width="100%">';

// Length (cm)
print '<tr><td class="titlefield">' . $langs->trans('Length') . ' (cm)</td><td>';
if ($action == 'create' || $action == 'edit') {
    print '<input type="number" step="0.01" class="flat" name="length_cm" value="' . (isset($object->length_cm) ? $object->length_cm : '') . '" min="0">';
} else {
    print !empty($object->length_cm) ? $object->length_cm . ' cm' : '&nbsp;';
}
print '</td></tr>';

// Width (cm)
print '<tr><td>' . $langs->trans('Width') . ' (cm)</td><td>';
if ($action == 'create' || $action == 'edit') {
    print '<input type="number" step="0.01" class="flat" name="width_cm" value="' . (isset($object->width_cm) ? $object->width_cm : '') . '" min="0">';
} else {
    print !empty($object->width_cm) ? $object->width_cm . ' cm' : '&nbsp;';
}
print '</td></tr>';

// Height (cm)
print '<tr><td>' . $langs->trans('Height') . ' (cm)</td><td>';
if ($action == 'create' || $action == 'edit') {
    print '<input type="number" step="0.01" class="flat" name="height_cm" value="' . (isset($object->height_cm) ? $object->height_cm : '') . '" min="0">';
} else {
    print !empty($object->height_cm) ? $object->height_cm . ' cm' : '&nbsp;';
}
print '</td></tr>';

// Max Weight (Kg)
print '<tr><td>' . $langs->trans('Max Weight') . ' (Kg)</td><td>';
if ($action == 'create' || $action == 'edit') {
    print '<input type="number" step="0.01" class="flat" name="max_weight_kg" value="' . (isset($object->max_weight_kg) ? $object->max_weight_kg : '') . '" min="0">';
} else {
    print !empty($object->max_weight_kg) ? $object->max_weight_kg . ' Kg' : '&nbsp;';
}
print '</td></tr>';

// Ground Height (cm)
print '<tr><td>' . $langs->trans('Ground Height') . ' (cm)</td><td>';
if ($action == 'create' || $action == 'edit') {
    print '<input type="number" step="0.01" class="flat" name="ground_height_cm" value="' . (isset($object->ground_height_cm) ? $object->ground_height_cm : '') . '" min="0">';
} else {
    print !empty($object->ground_height_cm) ? $object->ground_height_cm . ' cm' : '&nbsp;';
}
print '</td></tr>';

// Insurance Expiry Date
print '<tr><td>' . $langs->trans('Insurance Expiry Date') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    print $form->selectDate(
        (!empty($object->insurance_expiry) ? $object->insurance_expiry : ''), 
        'insurance_expiry'
    );
} else {
    if (!empty($object->insurance_expiry)) {
        print dol_print_date($object->insurance_expiry, 'day');
    } else {
        print '&nbsp;';
    }
}
print '</td></tr>';

print '</table>';

print '</div>';
print '</div>';

print '<div class="clearboth"></div><br>';

// ============ NEW SECTION: Documents & Photos ============
print '<div class="fichecenter">';
print load_fiche_titre($langs->trans('Documents & Photos'), '', '');
print '<table class="border tableforfield" width="100%">';

// Vehicle Photo
print '<tr><td class="titlefield">' . $langs->trans('Vehicle Photo') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    print '<input type="file" name="vehicle_photo" accept="image/*,.pdf">';
    if (!empty($object->vehicle_photo)) {
        print '<br><small>' . $langs->trans('Current') . ': ' . $object->vehicle_photo . '</small>';
    }
} else {
    if (!empty($object->vehicle_photo)) {
        $file_path = $upload_dir . '/' . $object->vehicle_photo;
        if (file_exists($file_path)) {
            $ext = strtolower(pathinfo($object->vehicle_photo, PATHINFO_EXTENSION));
            if (in_array($ext, array('jpg', 'jpeg', 'png', 'gif'))) {
                print '<a href="' . DOL_URL_ROOT . '/document.php?modulepart=flotte&file=vehicle/' . urlencode($object->vehicle_photo) . '" target="_blank">';
                print '<img src="' . DOL_URL_ROOT . '/document.php?modulepart=flotte&file=vehicle/' . urlencode($object->vehicle_photo) . '" style="max-width:100px; max-height:100px;" />';
                print '</a>';
            } else {
                print '<a href="' . DOL_URL_ROOT . '/document.php?modulepart=flotte&file=vehicle/' . urlencode($object->vehicle_photo) . '" target="_blank">' . $object->vehicle_photo . '</a>';
            }
        } else {
            print $object->vehicle_photo;
        }
    } else {
        print '&nbsp;';
    }
}
print '</td></tr>';

// Registration Card
print '<tr><td>' . $langs->trans('Registration Card') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    print '<input type="file" name="registration_card" accept="image/*,.pdf">';
    if (!empty($object->registration_card)) {
        print '<br><small>' . $langs->trans('Current') . ': ' . $object->registration_card . '</small>';
    }
} else {
    if (!empty($object->registration_card)) {
        $file_path = $upload_dir . '/' . $object->registration_card;
        if (file_exists($file_path)) {
            print '<a href="' . DOL_URL_ROOT . '/document.php?modulepart=flotte&file=vehicle/' . urlencode($object->registration_card) . '" target="_blank">' . $object->registration_card . '</a>';
        } else {
            print $object->registration_card;
        }
    } else {
        print '&nbsp;';
    }
}
print '</td></tr>';

// Platform Registration Card
print '<tr><td>' . $langs->trans('Platform Registration Card') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    print '<input type="file" name="platform_registration_card" accept="image/*,.pdf">';
    if (!empty($object->platform_registration_card)) {
        print '<br><small>' . $langs->trans('Current') . ': ' . $object->platform_registration_card . '</small>';
    }
} else {
    if (!empty($object->platform_registration_card)) {
        $file_path = $upload_dir . '/' . $object->platform_registration_card;
        if (file_exists($file_path)) {
            print '<a href="' . DOL_URL_ROOT . '/document.php?modulepart=flotte&file=vehicle/' . urlencode($object->platform_registration_card) . '" target="_blank">' . $object->platform_registration_card . '</a>';
        } else {
            print $object->platform_registration_card;
        }
    } else {
        print '&nbsp;';
    }
}
print '</td></tr>';

// Insurance Document
print '<tr><td>' . $langs->trans('Insurance Document') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    print '<input type="file" name="insurance_document" accept="image/*,.pdf">';
    if (!empty($object->insurance_document)) {
        print '<br><small>' . $langs->trans('Current') . ': ' . $object->insurance_document . '</small>';
    }
} else {
    if (!empty($object->insurance_document)) {
        $file_path = $upload_dir . '/' . $object->insurance_document;
        if (file_exists($file_path)) {
            print '<a href="' . DOL_URL_ROOT . '/document.php?modulepart=flotte&file=vehicle/' . urlencode($object->insurance_document) . '" target="_blank">' . $object->insurance_document . '</a>';
        } else {
            print $object->insurance_document;
        }
    } else {
        print '&nbsp;';
    }
}
print '</td></tr>';

print '</table>';
print '</div>';

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
    print '<a class="flotte-btn flotte-btn-cancel" href="' . ($id > 0 ? $_SERVER['PHP_SELF'] . '?id=' . $id : 'vehicle_list.php') . '">' . $langs->trans('Cancel') . '</a>';
    print '<a class="flotte-btn flotte-btn-back" href="' . dol_buildpath('/flotte/vehicle_list.php', 1) . '">' . $langs->trans('BackToList') . '</a>';
    print '</div>';
    print '</form>';
} elseif ($id > 0) {
    // Action buttons
    print '<div class="center" style="margin-top: 20px; margin-bottom: 10px;">';
    print '<a class="flotte-btn flotte-btn-primary" href="' . $_SERVER['PHP_SELF'] . '?id=' . $id . '&action=edit">' . $langs->trans('Modify') . '</a>';
    print '<a class="flotte-btn flotte-btn-delete" href="' . $_SERVER['PHP_SELF'] . '?id=' . $id . '&action=delete">' . $langs->trans('Delete') . '</a>';
    print '<a class="flotte-btn flotte-btn-back" href="' . dol_buildpath('/flotte/vehicle_list.php', 1) . '">' . $langs->trans('BackToList') . '</a>';
    print '</div>';
}

dol_fiche_end();

// End of page
llxFooter();
$db->close();
?>
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
        // Insert into database
        $sql = "INSERT INTO ".MAIN_DB_PREFIX."flotte_vehicle (";
        $sql .= "ref, entity, maker, model, type, year, initial_mileage, registration_expiry, in_service, department, ";
        $sql .= "engine_type, horsepower, color, vin, license_plate, license_expiry, fk_user_author";
        $sql .= ") VALUES (";
        $sql .= "'".$db->escape($ref)."', ".getEntity('flotte').", '".$db->escape($maker)."', '".$db->escape($model)."', ";
        $sql .= "'".$db->escape($type)."', ".($year ? $year : "NULL").", ".($initial_mileage ? $initial_mileage : "NULL").", ";
        $sql .= ($registration_expiry ? "'".$db->idate(dol_stringtotime($registration_expiry))."'" : "NULL").", ";
        $sql .= $in_service.", '".$db->escape($department)."', '".$db->escape($engine_type)."', ";
        $sql .= "'".$db->escape($horsepower)."', '".$db->escape($color)."', '".$db->escape($vin)."', ";
        $sql .= "'".$db->escape($license_plate)."', ";
        $sql .= ($license_expiry ? "'".$db->idate(dol_stringtotime($license_expiry))."'" : "NULL").", ";
        $sql .= $user->id;
        $sql .= ")";
        
        $resql = $db->query($sql);
        if ($resql) {
            $id = $db->last_insert_id(MAIN_DB_PREFIX."flotte_vehicle");
            $db->commit();
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
    
    // Validate required fields
    if (empty($ref)) {
        $error++;
        $errors[] = 'Reference is required';
    }
    
    if (!$error) {
        // Update database
        $sql = "UPDATE ".MAIN_DB_PREFIX."flotte_vehicle SET ";
        $sql .= "ref = '".$db->escape($ref)."', ";
        $sql .= "maker = '".$db->escape($maker)."', ";
        $sql .= "model = '".$db->escape($model)."', ";
        $sql .= "type = '".$db->escape($type)."', ";
        $sql .= "year = ".($year ? $year : "NULL").", ";
        $sql .= "initial_mileage = ".($initial_mileage ? $initial_mileage : "NULL").", ";
        $sql .= "registration_expiry = ".($registration_expiry ? "'".$db->idate(dol_stringtotime($registration_expiry))."'" : "NULL").", ";
        $sql .= "in_service = ".$in_service.", ";
        $sql .= "department = '".$db->escape($department)."', ";
        $sql .= "engine_type = '".$db->escape($engine_type)."', ";
        $sql .= "horsepower = '".$db->escape($horsepower)."', ";
        $sql .= "color = '".$db->escape($color)."', ";
        $sql .= "vin = '".$db->escape($vin)."', ";
        $sql .= "license_plate = '".$db->escape($license_plate)."', ";
        $sql .= "license_expiry = ".($license_expiry ? "'".$db->idate(dol_stringtotime($license_expiry))."'" : "NULL").", ";
        $sql .= "fk_user_modif = ".$user->id." ";
        $sql .= "WHERE rowid = ".((int) $id);
        
        $resql = $db->query($sql);
        if ($resql) {
            $db->commit();
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
    print '<form method="POST" action="' . $_SERVER['PHP_SELF'] . ($id > 0 ? '?id=' . $id : '') . '">';
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
print '<tr><td>' . $langs->trans('VehicleModel') . '</td><td>';
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
print '<tr><td>' . $langs->trans('Horsepower') . '</td><td>';
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
    // Simple date picker - no time selection
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
    // Simple date picker - no time selection
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

// Form buttons - Fixed the syntax error here
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

// Related records section
if ($id > 0 && $action != 'edit' && $action != 'create') {
    print '<br>';
    
    $head = array();
    $h = 0;
    $head[$h][0] = DOL_URL_ROOT.'/flotte/vehicle_card.php?id='.$id;
    $head[$h][1] = $langs->trans('RelatedRecords');
    $head[$h][2] = 'related';
    $h++;
    
    dol_fiche_head($head, 'related', $langs->trans('RelatedRecords'), -1, '');

    print '<table class="noborder" width="100%">';
    print '<tr class="liste_titre">';
    print '<td>'.$langs->trans('Type').'</td>';
    print '<td>'.$langs->trans('Count').'</td>';
    print '<td class="right">'.$langs->trans('Action').'</td>';
    print '</tr>';
    
    // Bookings
    $sql_bookings = "SELECT COUNT(*) as count FROM ".MAIN_DB_PREFIX."flotte_booking WHERE fk_vehicle = ".((int) $id);
    $resql_bookings = $db->query($sql_bookings);
    $bookings_count = 0;
    if ($resql_bookings) {
        $obj_bookings = $db->fetch_object($resql_bookings);
        $bookings_count = $obj_bookings->count;
    }
    
    print '<tr class="oddeven">';
    print '<td>'.$langs->trans('Bookings').'</td>';
    print '<td>'.$bookings_count.'</td>';
    print '<td class="right"><a class="butAction" href="'.DOL_URL_ROOT.'/flotte/booking_list.php?search_vehicle='.$object->ref.'">'.$langs->trans('View').'</a></td>';
    print '</tr>';
    
    // Fuel Records
    $sql_fuel = "SELECT COUNT(*) as count FROM ".MAIN_DB_PREFIX."flotte_fuel WHERE fk_vehicle = ".((int) $id);
    $resql_fuel = $db->query($sql_fuel);
    $fuel_count = 0;
    if ($resql_fuel) {
        $obj_fuel = $db->fetch_object($resql_fuel);
        $fuel_count = $obj_fuel->count;
    }
    
    print '<tr class="oddeven">';
    print '<td>'.$langs->trans('FuelRecords').'</td>';
    print '<td>'.$fuel_count.'</td>';
    print '<td class="right"><a class="butAction" href="'.DOL_URL_ROOT.'/flotte/fuel_list.php?search_vehicle='.$object->ref.'">'.$langs->trans('View').'</a></td>';
    print '</tr>';
    
    // Work Orders
    $sql_workorders = "SELECT COUNT(*) as count FROM ".MAIN_DB_PREFIX."flotte_workorder WHERE fk_vehicle = ".((int) $id);
    $resql_workorders = $db->query($sql_workorders);
    $workorders_count = 0;
    if ($resql_workorders) {
        $obj_workorders = $db->fetch_object($resql_workorders);
        $workorders_count = $obj_workorders->count;
    }
    
    print '<tr class="oddeven">';
    print '<td>'.$langs->trans('WorkOrders').'</td>';
    print '<td>'.$workorders_count.'</td>';
    print '<td class="right"><a class="butAction" href="'.DOL_URL_ROOT.'/flotte/workorder_list.php?search_vehicle='.$object->ref.'">'.$langs->trans('View').'</a></td>';
    print '</tr>';
    
    // Inspections
    $sql_inspections = "SELECT COUNT(*) as count FROM ".MAIN_DB_PREFIX."flotte_inspection WHERE fk_vehicle = ".((int) $id);
    $resql_inspections = $db->query($sql_inspections);
    $inspections_count = 0;
    if ($resql_inspections) {
        $obj_inspections = $db->fetch_object($resql_inspections);
        $inspections_count = $obj_inspections->count;
    }
    
    print '<tr class="oddeven">';
    print '<td>'.$langs->trans('Inspections').'</td>';
    print '<td>'.$inspections_count.'</td>';
    print '<td class="right"><a class="butAction" href="'.DOL_URL_ROOT.'/flotte/inspection_list.php?search_vehicle='.$object->ref.'">'.$langs->trans('View').'</a></td>';
    print '</tr>';
    
    print '</table>';
    
    dol_fiche_end();
}

// End of page
llxFooter();
$db->close();
?>
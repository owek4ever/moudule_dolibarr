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

// Subheader
$linkback = '<a href="' . DOL_URL_ROOT . '/flotte/workorder_list.php">' . $langs->trans('BackToList') . '</a>';

$h = 0;
$head = array();
$head[$h][0] = $_SERVER["PHP_SELF"] . '?id=' . $id;
$head[$h][1] = $langs->trans('Card');
$head[$h][2] = 'card';
$h++;

dol_fiche_head($head, 'card', $langs->trans('WorkOrder'), -1, 'generic');

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
print load_fiche_titre($langs->trans('WorkOrderInformation'), '', '');
print '<table class="border tableforfield" width="100%">';

// Reference
print '<tr><td class="titlefield">' . $langs->trans('Reference') . '</td><td>';
if ($action == 'create') {
    print $form->textwithpicto('<input type="text" class="flat" name="ref" value="' . dol_escape_htmltag($object->ref) . '" size="20" readonly>', $langs->trans('AutoGenerated'));
} elseif ($action == 'edit') {
    print '<input type="text" class="flat" name="ref" value="' . dol_escape_htmltag($object->ref) . '" size="20">';
} else {
    print dol_escape_htmltag($object->ref);
}
print '</td></tr>';

// Vehicle
print '<tr><td>' . $langs->trans('Vehicle') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    print $form->selectarray('fk_vehicle', $vehicles, $object->fk_vehicle, 1, 0, 0, '', 0, 0, 0, '', 'minwidth300');
} else {
    if (!empty($object->vehicle_ref)) {
        $vehicle_info = $object->vehicle_ref;
        if (!empty($object->maker) && !empty($object->model)) {
            $vehicle_info .= ' - ' . $object->maker . ' ' . $object->model;
        }
        print dol_escape_htmltag($vehicle_info);
    } else {
        print '<span class="opacitymedium">' . $langs->trans("NotAssigned") . '</span>';
    }
}
print '</td></tr>';

// Vendor
print '<tr><td>' . $langs->trans('Vendor') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    print $form->selectarray('fk_vendor', $vendors, $object->fk_vendor, 0, 0, 0, '', 0, 0, 0, '', 'minwidth300');
} else {
    if (!empty($object->vendor_name)) {
        print dol_escape_htmltag($object->vendor_name);
    } else {
        print '<span class="opacitymedium">' . $langs->trans("NotAssigned") . '</span>';
    }
}
print '</td></tr>';

// Required By
print '<tr><td>' . $langs->trans('RequiredBy') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    print $form->selectDate($object->required_by, 'required_by', '', '', 1, '', 1, 1);
} else {
    print dol_print_date($object->required_by, 'day');
}
print '</td></tr>';

// Reading
print '<tr><td>' . $langs->trans('Reading') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    print '<input type="number" class="flat" name="reading" value="' . (int) $object->reading . '" min="0">';
} else {
    print $object->reading ? number_format($object->reading, 0) : '-';
}
print '</td></tr>';

print '</table>';

print '</div>';
print '<div class="fichehalfright">';

// Additional Information
print load_fiche_titre($langs->trans('AdditionalInformation'), '', '');
print '<table class="border tableforfield" width="100%">';

// Price
print '<tr><td class="titlefield">' . $langs->trans('Price') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    print '<input type="number" class="flat" name="price" value="' . (float) $object->price . '" step="0.01" min="0">';
} else {
    print $object->price ? price($object->price) : '-';
}
print '</td></tr>';

// Status
print '<tr><td>' . $langs->trans('Status') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    $status_options = array(
        '' => $langs->trans('SelectStatus'),
        'Pending' => $langs->trans('Pending'),
        'In Progress' => $langs->trans('InProgress'),
        'Completed' => $langs->trans('Completed'),
        'Cancelled' => $langs->trans('Cancelled')
    );
    print $form->selectarray('status', $status_options, $object->status, 0);
} else {
    if ($object->status == 'Pending') {
        print dolGetStatus($langs->trans('Pending'), '', '', 'status0', 1);
    } elseif ($object->status == 'In Progress') {
        print dolGetStatus($langs->trans('InProgress'), '', '', 'status4', 1);
    } elseif ($object->status == 'Completed') {
        print dolGetStatus($langs->trans('Completed'), '', '', 'status6', 1);
    } elseif ($object->status == 'Cancelled') {
        print dolGetStatus($langs->trans('Cancelled'), '', '', 'status9', 1);
    } else {
        print dolGetStatus($langs->trans('Unknown'), '', '', 'status0', 1);
    }
}
print '</td></tr>';

print '</table>';

print '</div>';
print '</div>';

print '<div class="clearboth"></div>';

// Description and Note
print '<table class="border tableforfield" width="100%">';

// Description
print '<tr><td class="titlefield">' . $langs->trans('Description') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    print '<textarea name="description" class="flat" rows="3" style="width:100%">' . dol_escape_htmltag($object->description) . '</textarea>';
} else {
    print nl2br(dol_escape_htmltag($object->description ?: '-'));
}
print '</td></tr>';

// Note
print '<tr><td>' . $langs->trans('Note') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    print '<textarea name="note" class="flat" rows="4" style="width:100%">' . dol_escape_htmltag($object->note) . '</textarea>';
} else {
    print nl2br(dol_escape_htmltag($object->note ?: '-'));
}
print '</td></tr>';

print '</table>';

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
    print '<a class="flotte-btn flotte-btn-cancel" href="' . ($id > 0 ? $_SERVER['PHP_SELF'] . '?id=' . $id : 'workorder_list.php') . '">' . $langs->trans('Cancel') . '</a>';
    print '<a class="flotte-btn flotte-btn-back" href="' . dol_buildpath('/flotte/workorder_list.php', 1) . '">' . $langs->trans('BackToList') . '</a>';
    print '</div>';
    print '</form>';
} elseif ($id > 0) {
    // Action buttons
    print '<div class="center" style="margin-top: 20px; margin-bottom: 10px;">';
    print '<a class="flotte-btn flotte-btn-primary" href="' . $_SERVER['PHP_SELF'] . '?id=' . $id . '&action=edit">' . $langs->trans('Modify') . '</a>';
    print '<a class="flotte-btn flotte-btn-delete" href="' . $_SERVER['PHP_SELF'] . '?id=' . $id . '&action=delete">' . $langs->trans('Delete') . '</a>';
    print '<a class="flotte-btn flotte-btn-back" href="' . dol_buildpath('/flotte/workorder_list.php', 1) . '">' . $langs->trans('BackToList') . '</a>';
    print '</div>';
}

dol_fiche_end();

// End of page
llxFooter();
$db->close();
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
require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';

// Load translation files
$langs->loadLangs(array("flotte@flotte", "other"));

// Get parameters
$action = GETPOST('action', 'aZ09') ? GETPOST('action', 'aZ09') : 'view';
$confirm = GETPOST('confirm', 'alpha');
$id = GETPOST('id', 'int');
$cancel = GETPOST('cancel', 'alpha');

// Initialize technical objects
$form = new Form($db);
$formcompany = new FormCompany($db);
$hookmanager->initHooks(array('drivercard', 'globalcard'));

// Security check
restrictedArea($user, 'flotte');

// Get driver data if editing/viewing
$driver_data = array();
if ($id > 0) {
    $sql = "SELECT * FROM ".MAIN_DB_PREFIX."flotte_driver WHERE rowid = ".(int)$id." AND entity IN (".getEntity('flotte').")";
    $resql = $db->query($sql);
    if ($resql) {
        if ($db->num_rows($resql)) {
            $driver_data = $db->fetch_array($resql);
        } else {
            header('Location: driver_list.php');
            exit;
        }
        $db->free($resql);
    }
}

// Get vehicles for dropdown
$vehicles = array();
$sql = "SELECT rowid, ref, maker, model FROM ".MAIN_DB_PREFIX."flotte_vehicle WHERE entity IN (".getEntity('flotte').") ORDER BY ref";
$resql = $db->query($sql);
if ($resql) {
    while ($obj = $db->fetch_object($resql)) {
        $vehicles[$obj->rowid] = $obj->ref . " - " . $obj->maker . " " . $obj->model;
    }
    $db->free($resql);
}

// Function to generate next reference
function getNextDriverRef($db) {
    $prefix = "DRV";
    $sql = "SELECT MAX(CAST(SUBSTRING(ref, 4) AS UNSIGNED)) as max_ref FROM ".MAIN_DB_PREFIX."flotte_driver WHERE ref LIKE '".$prefix."%'";
    $resql = $db->query($sql);
    if ($resql) {
        $obj = $db->fetch_object($resql);
        $next_num = $obj && $obj->max_ref ? $obj->max_ref + 1 : 1;
        return $prefix . str_pad($next_num, 4, '0', STR_PAD_LEFT);
    }
    return $prefix . "0001";
}

/*
 * Actions
 */
$error = 0;
$errors = array();

// Handle file uploads
function handleFileUpload($fieldName, $driverId, $currentFile = null) {
    global $conf, $db;
    
    if (isset($_FILES[$fieldName]) && $_FILES[$fieldName]['error'] == UPLOAD_ERR_OK) {
        $uploadDir = $conf->flotte->dir_output . '/drivers/' . $driverId . '/';
        if (!dol_mkdir($uploadDir)) {
            return null;
        }
        
        $fileName = $_FILES[$fieldName]['name'];
        $fileExt = pathinfo($fileName, PATHINFO_EXTENSION);
        $newFileName = $fieldName . '_' . $driverId . '_' . dol_print_date(dol_now(), 'dayhourlog') . '.' . $fileExt;
        $newFileName = dol_sanitizeFileName($newFileName);
        
        $fullPath = $uploadDir . $newFileName;
        
        if (dol_move_uploaded_file($_FILES[$fieldName]['tmp_name'], $fullPath, 0, 0, $_FILES[$fieldName]['error'])) {
            // Delete old file if exists
            if (!empty($currentFile)) {
                $oldFile = $uploadDir . $currentFile;
                if (file_exists($oldFile)) {
                    unlink($oldFile);
                }
            }
            return $newFileName;
        }
    }
    return $currentFile; // Return current file if no new upload
}

// Handle form submission
if ($action == 'add' && !$cancel && $_SERVER["REQUEST_METHOD"] == "POST") {
    
    // Generate reference automatically
    $ref = getNextDriverRef($db);
    $firstname = GETPOST('firstname', 'alpha');
    $middlename = GETPOST('middlename', 'alpha');
    $lastname = GETPOST('lastname', 'alpha');
    $address = GETPOST('address', 'restricthtml');
    $email = GETPOST('email', 'email');
    $phone = GETPOST('phone', 'alpha');
    $employee_id = GETPOST('employee_id', 'alpha');
    $contract_number = GETPOST('contract_number', 'alpha');
    $license_number = GETPOST('license_number', 'alpha');
    
    // Handle dates properly
    $license_issue_date = null;
    if (GETPOST('license_issue_dateday', 'int') && GETPOST('license_issue_datemonth', 'int') && GETPOST('license_issue_dateyear', 'int')) {
        $license_issue_date = dol_mktime(12, 0, 0, GETPOST('license_issue_datemonth', 'int'), GETPOST('license_issue_dateday', 'int'), GETPOST('license_issue_dateyear', 'int'));
    }
    
    $license_expiry_date = null;
    if (GETPOST('license_expiry_dateday', 'int') && GETPOST('license_expiry_datemonth', 'int') && GETPOST('license_expiry_dateyear', 'int')) {
        $license_expiry_date = dol_mktime(12, 0, 0, GETPOST('license_expiry_datemonth', 'int'), GETPOST('license_expiry_dateday', 'int'), GETPOST('license_expiry_dateyear', 'int'));
    }
    
    $join_date = null;
    if (GETPOST('join_dateday', 'int') && GETPOST('join_datemonth', 'int') && GETPOST('join_dateyear', 'int')) {
        $join_date = dol_mktime(12, 0, 0, GETPOST('join_datemonth', 'int'), GETPOST('join_dateday', 'int'), GETPOST('join_dateyear', 'int'));
    }
    
    $leave_date = null;
    if (GETPOST('leave_dateday', 'int') && GETPOST('leave_datemonth', 'int') && GETPOST('leave_dateyear', 'int')) {
        $leave_date = dol_mktime(12, 0, 0, GETPOST('leave_datemonth', 'int'), GETPOST('leave_dateday', 'int'), GETPOST('leave_dateyear', 'int'));
    }
    
    $password = GETPOST('password', 'alpha');
    $department = GETPOST('department', 'alpha');
    $status = GETPOST('status', 'alpha');
    $gender = GETPOST('gender', 'alpha');
    $emergency_contact = GETPOST('emergency_contact', 'restricthtml');
    $fk_vehicle = GETPOST('fk_vehicle', 'int');

    // Validation
    if (empty($firstname)) {
        $error++;
        $errors[] = $langs->trans("ErrorFieldRequired", "FirstName");
    }
    if (empty($lastname)) {
        $error++;
        $errors[] = $langs->trans("ErrorFieldRequired", "LastName");
    }

    if (!$error) {
        $db->begin();
        
        $sql = "INSERT INTO ".MAIN_DB_PREFIX."flotte_driver (";
        $sql .= "ref, entity, firstname, middlename, lastname, address, email, phone, employee_id, contract_number,";
        $sql .= "license_number, license_issue_date, license_expiry_date, join_date, leave_date, password,";
        $sql .= "department, status, gender, emergency_contact, fk_vehicle, fk_user_author";
        $sql .= ") VALUES (";
        $sql .= "'".$db->escape($ref)."', ".$conf->entity.", '".$db->escape($firstname)."', '".$db->escape($middlename)."', '".$db->escape($lastname)."',";
        $sql .= "'".$db->escape($address)."', '".$db->escape($email)."', '".$db->escape($phone)."', '".$db->escape($employee_id)."',";
        $sql .= "'".$db->escape($contract_number)."', '".$db->escape($license_number)."',";
        $sql .= ($license_issue_date ? "'".$db->idate($license_issue_date)."'" : "NULL").",";
        $sql .= ($license_expiry_date ? "'".$db->idate($license_expiry_date)."'" : "NULL").",";
        $sql .= ($join_date ? "'".$db->idate($join_date)."'" : "NULL").",";
        $sql .= ($leave_date ? "'".$db->idate($leave_date)."'" : "NULL").",";
        $sql .= "'".$db->escape($password)."', '".$db->escape($department)."', '".$db->escape($status)."',";
        $sql .= "'".$db->escape($gender)."', '".$db->escape($emergency_contact)."', ".($fk_vehicle ? $fk_vehicle : "NULL").", ".$user->id;
        $sql .= ")";
        
        $resql = $db->query($sql);
        if ($resql) {
            $newid = $db->last_insert_id(MAIN_DB_PREFIX."flotte_driver");
            
            // Handle file uploads
            $driver_image = handleFileUpload('driver_image', $newid);
            $license_image = handleFileUpload('license_image', $newid);
            $documents = handleFileUpload('documents', $newid);
            
            // Update record with file names if any files were uploaded
            if ($driver_image || $license_image || $documents) {
                $update_sql = "UPDATE ".MAIN_DB_PREFIX."flotte_driver SET ";
                $updates = array();
                if ($driver_image) $updates[] = "driver_image = '".$db->escape($driver_image)."'";
                if ($license_image) $updates[] = "license_image = '".$db->escape($license_image)."'";
                if ($documents) $updates[] = "documents = '".$db->escape($documents)."'";
                $update_sql .= implode(", ", $updates) . " WHERE rowid = ".(int)$newid;
                $db->query($update_sql);
            }
            
            $db->commit();
            setEventMessages($langs->trans("DriverCreatedSuccessfully"), null, 'mesgs');
            header('Location: driver_card.php?id='.$newid);
            exit;
        } else {
            $error++;
            $db->rollback();
            $errors[] = "Error in SQL: ".$db->lasterror();
        }
    }

    if ($error) {
        setEventMessages('', $errors, 'errors');
        $action = 'create';
    }
}

if ($action == 'update' && !$cancel && $_SERVER["REQUEST_METHOD"] == "POST" && $id > 0) {
    
    // Get form data
    $firstname = GETPOST('firstname', 'alpha');
    $middlename = GETPOST('middlename', 'alpha');
    $lastname = GETPOST('lastname', 'alpha');
    $address = GETPOST('address', 'restricthtml');
    $email = GETPOST('email', 'email');
    $phone = GETPOST('phone', 'alpha');
    $employee_id = GETPOST('employee_id', 'alpha');
    $contract_number = GETPOST('contract_number', 'alpha');
    $license_number = GETPOST('license_number', 'alpha');
    
    // Handle dates properly
    $license_issue_date = null;
    if (GETPOST('license_issue_dateday', 'int') && GETPOST('license_issue_datemonth', 'int') && GETPOST('license_issue_dateyear', 'int')) {
        $license_issue_date = dol_mktime(12, 0, 0, GETPOST('license_issue_datemonth', 'int'), GETPOST('license_issue_dateday', 'int'), GETPOST('license_issue_dateyear', 'int'));
    }
    
    $license_expiry_date = null;
    if (GETPOST('license_expiry_dateday', 'int') && GETPOST('license_expiry_datemonth', 'int') && GETPOST('license_expiry_dateyear', 'int')) {
        $license_expiry_date = dol_mktime(12, 0, 0, GETPOST('license_expiry_datemonth', 'int'), GETPOST('license_expiry_dateday', 'int'), GETPOST('license_expiry_dateyear', 'int'));
    }
    
    $join_date = null;
    if (GETPOST('join_dateday', 'int') && GETPOST('join_datemonth', 'int') && GETPOST('join_dateyear', 'int')) {
        $join_date = dol_mktime(12, 0, 0, GETPOST('join_datemonth', 'int'), GETPOST('join_dateday', 'int'), GETPOST('join_dateyear', 'int'));
    }
    
    $leave_date = null;
    if (GETPOST('leave_dateday', 'int') && GETPOST('leave_datemonth', 'int') && GETPOST('leave_dateyear', 'int')) {
        $leave_date = dol_mktime(12, 0, 0, GETPOST('leave_datemonth', 'int'), GETPOST('leave_dateday', 'int'), GETPOST('leave_dateyear', 'int'));
    }
    
    $password = GETPOST('password', 'alpha');
    $department = GETPOST('department', 'alpha');
    $status = GETPOST('status', 'alpha');
    $gender = GETPOST('gender', 'alpha');
    $emergency_contact = GETPOST('emergency_contact', 'restricthtml');
    $fk_vehicle = GETPOST('fk_vehicle', 'int');

    // Validation
    if (empty($firstname)) {
        $error++;
        $errors[] = $langs->trans("ErrorFieldRequired", "FirstName");
    }
    if (empty($lastname)) {
        $error++;
        $errors[] = $langs->trans("ErrorFieldRequired", "LastName");
    }

    if (!$error) {
        $db->begin();
        
        // Handle file uploads
        $driver_image = handleFileUpload('driver_image', $id, $driver_data['driver_image']);
        $license_image = handleFileUpload('license_image', $id, $driver_data['license_image']);
        $documents = handleFileUpload('documents', $id, $driver_data['documents']);
        
        $sql = "UPDATE ".MAIN_DB_PREFIX."flotte_driver SET";
        $sql .= " firstname = '".$db->escape($firstname)."',";
        $sql .= " middlename = '".$db->escape($middlename)."',";
        $sql .= " lastname = '".$db->escape($lastname)."',";
        $sql .= " address = '".$db->escape($address)."',";
        $sql .= " email = '".$db->escape($email)."',";
        $sql .= " phone = '".$db->escape($phone)."',";
        $sql .= " employee_id = '".$db->escape($employee_id)."',";
        $sql .= " contract_number = '".$db->escape($contract_number)."',";
        $sql .= " license_number = '".$db->escape($license_number)."',";
        $sql .= " license_issue_date = ".($license_issue_date ? "'".$db->idate($license_issue_date)."'" : "NULL").",";
        $sql .= " license_expiry_date = ".($license_expiry_date ? "'".$db->idate($license_expiry_date)."'" : "NULL").",";
        $sql .= " join_date = ".($join_date ? "'".$db->idate($join_date)."'" : "NULL").",";
        $sql .= " leave_date = ".($leave_date ? "'".$db->idate($leave_date)."'" : "NULL").",";
        $sql .= " password = '".$db->escape($password)."',";
        $sql .= " department = '".$db->escape($department)."',";
        $sql .= " status = '".$db->escape($status)."',";
        $sql .= " gender = '".$db->escape($gender)."',";
        $sql .= " emergency_contact = '".$db->escape($emergency_contact)."',";
        $sql .= " fk_vehicle = ".($fk_vehicle ? $fk_vehicle : "NULL").",";
        
        // Add file fields if they were updated
        if ($driver_image !== null) $sql .= " driver_image = ".($driver_image ? "'".$db->escape($driver_image)."'" : "NULL").",";
        if ($license_image !== null) $sql .= " license_image = ".($license_image ? "'".$db->escape($license_image)."'" : "NULL").",";
        if ($documents !== null) $sql .= " documents = ".($documents ? "'".$db->escape($documents)."'" : "NULL").",";
        
        $sql .= " fk_user_modif = ".$user->id;
        $sql .= " WHERE rowid = ".(int)$id;

        $resql = $db->query($sql);
        if ($resql) {
            $db->commit();
            setEventMessages($langs->trans("DriverUpdatedSuccessfully"), null, 'mesgs');
            // Refresh data
            $sql = "SELECT * FROM ".MAIN_DB_PREFIX."flotte_driver WHERE rowid = ".(int)$id;
            $resql = $db->query($sql);
            if ($resql) {
                $driver_data = $db->fetch_array($resql);
                $db->free($resql);
            }
            $action = 'view';
        } else {
            $error++;
            $db->rollback();
            $errors[] = "Error in SQL: ".$db->lasterror();
        }
    }

    if ($error) {
        setEventMessages('', $errors, 'errors');
        $action = 'edit';
    }
}

// Actions to delete
if ($action == 'confirm_delete' && $confirm == 'yes') {
    $db->begin();
    $sql = "DELETE FROM ".MAIN_DB_PREFIX."flotte_driver WHERE rowid = ".(int)$id;
    $resql = $db->query($sql);
    if ($resql) {
        // Delete associated files
        $uploadDir = $conf->flotte->dir_output . '/drivers/' . $id . '/';
        if (is_dir($uploadDir)) {
            dol_delete_dir_recursive($uploadDir);
        }
        
        $db->commit();
        setEventMessages($langs->trans("DriverDeletedSuccessfully"), null, 'mesgs');
        header('Location: driver_list.php');
        exit;
    } else {
        $db->rollback();
        setEventMessages("Error in SQL: ".$db->lasterror(), null, 'errors');
    }
}

/*
 * View
 */

$title = $langs->trans("Driver");
if ($action == 'create') $title = $langs->trans("NewDriver");
if ($action == 'edit') $title = $langs->trans("EditDriver");

$help_url = '';
llxHeader('', $title, $help_url);

// Confirmation of action
if ($action == 'delete') {
    $formconfirm = $form->formconfirm($_SERVER["PHP_SELF"].'?id='.$id, $langs->trans('DeleteDriver'), $langs->trans('ConfirmDeleteDriver'), 'confirm_delete', '', 0, 1);
    print $formconfirm;
}

// Subheader
$linkback = '<a href="' . DOL_URL_ROOT . '/flotte/driver_list.php">' . $langs->trans('BackToList') . '</a>';

$h = 0;
$head = array();
$head[$h][0] = $_SERVER["PHP_SELF"] . '?id=' . $id;
$head[$h][1] = $langs->trans('Card');
$head[$h][2] = 'card';
$h++;

dol_fiche_head($head, 'card', $langs->trans('Driver'), -1, 'user');

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
print load_fiche_titre($langs->trans('DriverInformation'), '', '');
print '<table class="border tableforfield" width="100%">';

// Reference
print '<tr><td class="titlefield">' . $langs->trans('Reference') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    print $form->textwithpicto('<input type="text" class="flat" name="ref" value="' . (isset($driver_data['ref']) ? $driver_data['ref'] : getNextDriverRef($db)) . '" size="20" readonly>', $langs->trans('AutoGenerated'));
} else {
    print $driver_data['ref'];
}
print '</td></tr>';

// First Name
print '<tr><td>' . $langs->trans('FirstName') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    print '<input type="text" class="flat" name="firstname" value="' . (isset($driver_data['firstname']) ? $driver_data['firstname'] : '') . '" size="20" required>';
} else {
    print $driver_data['firstname'];
}
print '</td></tr>';

// Middle Name
print '<tr><td>' . $langs->trans('MiddleName') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    print '<input type="text" class="flat" name="middlename" value="' . (isset($driver_data['middlename']) ? $driver_data['middlename'] : '') . '" size="20">';
} else {
    print $driver_data['middlename'];
}
print '</td></tr>';

// Last Name
print '<tr><td>' . $langs->trans('LastName') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    print '<input type="text" class="flat" name="lastname" value="' . (isset($driver_data['lastname']) ? $driver_data['lastname'] : '') . '" size="20" required>';
} else {
    print $driver_data['lastname'];
}
print '</td></tr>';

// Email
print '<tr><td>' . $langs->trans('Email') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    print '<input type="email" class="flat" name="email" value="' . (isset($driver_data['email']) ? $driver_data['email'] : '') . '" size="20">';
} else {
    print $driver_data['email'];
}
print '</td></tr>';

// Phone
print '<tr><td>' . $langs->trans('Phone') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    print '<input type="text" class="flat" name="phone" value="' . (isset($driver_data['phone']) ? $driver_data['phone'] : '') . '" size="20">';
} else {
    print $driver_data['phone'];
}
print '</td></tr>';

// Employee ID
print '<tr><td>' . $langs->trans('EmployeeID') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    print '<input type="text" class="flat" name="employee_id" value="' . (isset($driver_data['employee_id']) ? $driver_data['employee_id'] : '') . '" size="20">';
} else {
    print $driver_data['employee_id'];
}
print '</td></tr>';

// Contract Number
print '<tr><td>' . $langs->trans('ContractNumber') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    print '<input type="text" class="flat" name="contract_number" value="' . (isset($driver_data['contract_number']) ? $driver_data['contract_number'] : '') . '" size="20">';
} else {
    print $driver_data['contract_number'];
}
print '</td></tr>';

// License Number
print '<tr><td>' . $langs->trans('LicenseNumber') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    print '<input type="text" class="flat" name="license_number" value="' . (isset($driver_data['license_number']) ? $driver_data['license_number'] : '') . '" size="20">';
} else {
    print $driver_data['license_number'];
}
print '</td></tr>';

print '</table>';

print '</div>';
print '<div class="fichehalfright">';

// Additional Information
print load_fiche_titre($langs->trans('AdditionalInformation'), '', '');
print '<table class="border tableforfield" width="100%">';

// License Issue Date
print '<tr><td class="titlefield">' . $langs->trans('LicenseIssueDate') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    $license_issue_date = '';
    if (!empty($driver_data['license_issue_date'])) {
        $license_issue_date = $db->jdate($driver_data['license_issue_date']);
    }
    print $form->selectDate($license_issue_date, 'license_issue_date', 0, 0, 1, '', 1, 1);
} else {
    if ($driver_data['license_issue_date']) {
        print dol_print_date($db->jdate($driver_data['license_issue_date']), 'day');
    }
}
print '</td></tr>';

// License Expiry Date
print '<tr><td>' . $langs->trans('LicenseExpiryDate') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    $license_expiry_date = '';
    if (!empty($driver_data['license_expiry_date'])) {
        $license_expiry_date = $db->jdate($driver_data['license_expiry_date']);
    }
    print $form->selectDate($license_expiry_date, 'license_expiry_date', 0, 0, 1, '', 1, 1);
} else {
    if ($driver_data['license_expiry_date']) {
        print dol_print_date($db->jdate($driver_data['license_expiry_date']), 'day');
    }
}
print '</td></tr>';

// Join Date
print '<tr><td>' . $langs->trans('JoinDate') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    $join_date = '';
    if (!empty($driver_data['join_date'])) {
        $join_date = $db->jdate($driver_data['join_date']);
    }
    print $form->selectDate($join_date, 'join_date', 0, 0, 1, '', 1, 1);
} else {
    if ($driver_data['join_date']) {
        print dol_print_date($db->jdate($driver_data['join_date']), 'day');
    }
}
print '</td></tr>';

// Leave Date
print '<tr><td>' . $langs->trans('LeaveDate') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    $leave_date = '';
    if (!empty($driver_data['leave_date'])) {
        $leave_date = $db->jdate($driver_data['leave_date']);
    }
    print $form->selectDate($leave_date, 'leave_date', 0, 0, 1, '', 1, 1);
} else {
    if ($driver_data['leave_date']) {
        print dol_print_date($db->jdate($driver_data['leave_date']), 'day');
    }
}
print '</td></tr>';

// Password
print '<tr><td>' . $langs->trans('Password') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    print '<input type="password" class="flat" name="password" value="' . (isset($driver_data['password']) ? $driver_data['password'] : '') . '" size="20">';
} else {
    print '********'; // Mask password for security
}
print '</td></tr>';

// Department
print '<tr><td>' . $langs->trans('Department') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    print '<input type="text" class="flat" name="department" value="' . (isset($driver_data['department']) ? $driver_data['department'] : '') . '" size="20">';
} else {
    print $driver_data['department'];
}
print '</td></tr>';

// Status
print '<tr><td>' . $langs->trans('Status') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    $status_options = array(
        '' => $langs->trans('SelectStatus'),
        'active' => $langs->trans('Active'),
        'inactive' => $langs->trans('Inactive'),
        'suspended' => $langs->trans('Suspended')
    );
    print $form->selectarray('status', $status_options, (isset($driver_data['status']) ? $driver_data['status'] : ''), 1);
} else {
    print $driver_data['status'];
}
print '</td></tr>';

// Gender
print '<tr><td>' . $langs->trans('Gender') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    $gender_options = array(
        '' => $langs->trans('SelectGender'),
        'male' => $langs->trans('Male'),
        'female' => $langs->trans('Female'),
        'other' => $langs->trans('Other')
    );
    print $form->selectarray('gender', $gender_options, (isset($driver_data['gender']) ? $driver_data['gender'] : ''), 1);
} else {
    print $driver_data['gender'];
}
print '</td></tr>';

// Emergency Contact
print '<tr><td>' . $langs->trans('EmergencyContact') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    print '<input type="text" class="flat" name="emergency_contact" value="' . (isset($driver_data['emergency_contact']) ? $driver_data['emergency_contact'] : '') . '" size="20">';
} else {
    print $driver_data['emergency_contact'];
}
print '</td></tr>';

// Assigned Vehicle
print '<tr><td>' . $langs->trans('AssignedVehicle') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    print '<select name="fk_vehicle" class="flat">';
    print '<option value="">' . $langs->trans('NoVehicleAssigned') . '</option>';
    foreach ($vehicles as $vehicle_id => $vehicle_name) {
        $selected = (isset($driver_data['fk_vehicle']) && $driver_data['fk_vehicle'] == $vehicle_id) ? ' selected' : '';
        print '<option value="' . $vehicle_id . '"' . $selected . '>' . $vehicle_name . '</option>';
    }
    print '</select>';
} else {
    if (!empty($driver_data['fk_vehicle'])) {
        print $vehicles[$driver_data['fk_vehicle']];
    } else {
        print $langs->trans('NoVehicleAssigned');
    }
}
print '</td></tr>';

print '</table>';

print '</div>';
print '</div>';

print '<div class="clearboth"></div><br>';

// Address
print '<div class="fichecenter">';
print '<div class="ficheaddleft">';

print load_fiche_titre($langs->trans('Address'), '', '');
print '<table class="border tableforfield" width="100%">';

print '<tr><td>';
if ($action == 'create' || $action == 'edit') {
    print '<textarea name="address" class="flat" rows="3" cols="80">' . (isset($driver_data['address']) ? $driver_data['address'] : '') . '</textarea>';
} else {
    print nl2br($driver_data['address']);
}
print '</td></tr>';

print '</table>';

print '</div>';
print '</div>';

print '<div class="clearboth"></div><br>';

// File uploads
print '<div class="fichecenter">';
print '<div class="ficheaddleft">';

print load_fiche_titre($langs->trans('Files'), '', '');
print '<table class="border tableforfield" width="100%">';

// Driver Image
print '<tr><td class="titlefield">' . $langs->trans('DriverImage') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    print '<input type="file" name="driver_image" accept="image/*">';
    if (!empty($driver_data['driver_image'])) {
        print '<br><img src="' . $conf->flotte->dir_output . '/drivers/' . $id . '/' . $driver_data['driver_image'] . '" height="100" style="margin-top:10px;">';
    }
} else {
    if (!empty($driver_data['driver_image'])) {
        print '<img src="' . $conf->flotte->dir_output . '/drivers/' . $id . '/' . $driver_data['driver_image'] . '" height="100">';
    } else {
        print $langs->trans('NoImageUploaded');
    }
}
print '</td></tr>';

// License Image
print '<tr><td>' . $langs->trans('LicenseImage') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    print '<input type="file" name="license_image" accept="image/*">';
    if (!empty($driver_data['license_image'])) {
        print '<br><img src="' . $conf->flotte->dir_output . '/drivers/' . $id . '/' . $driver_data['license_image'] . '" height="100" style="margin-top:10px;">';
    }
} else {
    if (!empty($driver_data['license_image'])) {
        print '<img src="' . $conf->flotte->dir_output . '/drivers/' . $id . '/' . $driver_data['license_image'] . '" height="100">';
    } else {
        print $langs->trans('NoImageUploaded');
    }
}
print '</td></tr>';

// Documents
print '<tr><td>' . $langs->trans('Documents') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    print '<input type="file" name="documents" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png">';
    if (!empty($driver_data['documents'])) {
        print '<br><a href="' . $conf->flotte->dir_output . '/drivers/' . $id . '/' . $driver_data['documents'] . '" target="_blank">' . $langs->trans('ViewDocument') . '</a>';
    }
} else {
    if (!empty($driver_data['documents'])) {
        print '<a href="' . $conf->flotte->dir_output . '/drivers/' . $id . '/' . $driver_data['documents'] . '" target="_blank">' . $langs->trans('ViewDocument') . '</a>';
    } else {
        print $langs->trans('NoDocumentUploaded');
    }
}
print '</td></tr>';

print '</table>';

print '</div>';
print '</div>';

print '<div class="clearboth"></div>';

// Form buttons
if ($action == 'create' || $action == 'edit') {
    print '<div class="center">';
    print '<input type="submit" class="button" value="' . ($action == 'create' ? $langs->trans('Create') : $langs->trans('Save')) . '">';
    print '&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;';
    print '<a class="button button-cancel" href="' . ($id > 0 ? $_SERVER['PHP_SELF'] . '?id=' . $id : 'driver_list.php') . '">' . $langs->trans('Cancel') . '</a>';
    print '</div>';
    print '</form>';
} elseif ($id > 0) {
    // Action buttons
    print '<div class="tabsAction">';
    print '<div class="inline-block divButAction">';
    print '<a class="butAction" href="' . $_SERVER['PHP_SELF'] . '?id=' . $id . '&action=edit">' . $langs->trans('Modify') . '</a>';
    print '<a class="butActionDelete" href="' . $_SERVER['PHP_SELF'] . '?id=' . $id . '&action=delete">' . $langs->trans('Delete') . '</a>';
    print '</div>';
    print '</div>';
}

dol_fiche_end();

// End of page
llxFooter();
$db->close();
?>
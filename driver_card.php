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
require_once DOL_DOCUMENT_ROOT.'/core/class/html.formfile.class.php';
require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';

// Load translation files
$langs->loadLangs(array("flotte@flotte", "users", "hrm", "other"));

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

// Define upload directory
$upload_dir = DOL_DATA_ROOT.'/flotte/driver';
if (!is_dir($upload_dir)) {
    dol_mkdir($upload_dir);
}

// Get driver data if editing/viewing
$driver_data = array();
$employee = new User($db);

if ($id > 0) {
    $sql = "SELECT * FROM ".MAIN_DB_PREFIX."flotte_driver WHERE rowid = ".(int)$id." AND entity IN (".getEntity('flotte').")";
    $resql = $db->query($sql);
    if ($resql) {
        if ($db->num_rows($resql)) {
            $driver_data = $db->fetch_array($resql);
            
            // Load employee information
            if ($driver_data['fk_user'] > 0) {
                $employee->fetch($driver_data['fk_user']);
            }
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

// Function to handle file upload
function handleFileUpload($file_field_name, $upload_dir) {
    if (isset($_FILES[$file_field_name]) && $_FILES[$file_field_name]['error'] == 0) {
        $allowed = array('jpg', 'jpeg', 'png', 'pdf', 'doc', 'docx');
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

// Handle AJAX request to get Employee data
if ($action == 'fetch_employee' && GETPOST('fk_user', 'int') > 0) {
    $userid = GETPOST('fk_user', 'int');
    $tmpuser = new User($db);
    $result = $tmpuser->fetch($userid);
    
    if ($result > 0) {
        $data = array(
            'firstname' => $tmpuser->firstname,
            'lastname' => $tmpuser->lastname,
            'email' => $tmpuser->email,
            'phone' => $tmpuser->office_phone ? $tmpuser->office_phone : $tmpuser->user_mobile,
            'address' => $tmpuser->address,
            'employee_id' => $tmpuser->employee ? $tmpuser->employee : '',
        );
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }
}

// Handle delete confirmation
if ($action == 'confirm_delete' && $confirm == 'yes') {
    $db->begin();
    $sql_del = "DELETE FROM ".MAIN_DB_PREFIX."flotte_driver WHERE rowid = ".(int)$id." AND entity IN (".getEntity('flotte').")";
    $resql_del = $db->query($sql_del);
    if ($resql_del) {
        $db->commit();
        setEventMessages($langs->trans("DriverDeletedSuccessfully"), null, 'mesgs');
        header('Location: driver_list.php');
        exit;
    } else {
        $db->rollback();
        setEventMessages("Error: ".$db->lasterror(), null, 'errors');
    }
}

// Handle form submission - ADD
if ($action == 'add' && !$cancel && $_SERVER["REQUEST_METHOD"] == "POST") {
    
    // Generate reference automatically
    $ref = getNextDriverRef($db);
    $fk_user = GETPOST('fk_user', 'int');
    $firstname = GETPOST('firstname', 'alpha');
    $middlename = GETPOST('middlename', 'alpha');
    $lastname = GETPOST('lastname', 'alpha');
    $address = GETPOST('address', 'restricthtml');
    $email = GETPOST('email', 'email');
    $phone = GETPOST('phone', 'alphanohtml');
    $employee_id = GETPOST('employee_id', 'alphanohtml');
    $contract_number = GETPOST('contract_number', 'alphanohtml');
    $license_number = GETPOST('license_number', 'alphanohtml');
    
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
    if (empty($fk_user) || $fk_user <= 0) {
        $error++;
        $errors[] = "Please select an Employee from HRM";
    }
    
    if (empty($firstname)) {
        $error++;
        $errors[] = $langs->trans("ErrorFieldRequired", "FirstName");
    }
    if (empty($lastname)) {
        $error++;
        $errors[] = $langs->trans("ErrorFieldRequired", "LastName");
    }
    
    // Check if this employee is already a driver
    if (!$error) {
        $sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."flotte_driver";
        $sql .= " WHERE fk_user = ".((int) $fk_user);
        $sql .= " AND entity IN (".getEntity('flotte').")";
        $resql = $db->query($sql);
        if ($resql && $db->num_rows($resql) > 0) {
            $error++;
            $errors[] = "This Employee is already registered as a driver";
        }
    }

    if (!$error) {
        $db->begin();
        
        // Handle file uploads
        $driver_image = handleFileUpload('driver_image', $upload_dir);
        $license_image = handleFileUpload('license_image', $upload_dir);
        $documents = handleFileUpload('documents', $upload_dir);
        
        $sql = "INSERT INTO ".MAIN_DB_PREFIX."flotte_driver (";
        $sql .= "ref, entity, fk_user, firstname, middlename, lastname, address, email, phone, employee_id, contract_number,";
        $sql .= "license_number, license_issue_date, license_expiry_date, join_date, leave_date, password,";
        $sql .= "department, status, gender, emergency_contact, fk_vehicle, ";
        $sql .= "driver_image, license_image, documents, fk_user_author, datec";
        $sql .= ") VALUES (";
        $sql .= "'".$db->escape($ref)."', ".$conf->entity.", ".((int) $fk_user).", '".$db->escape($firstname)."', '".$db->escape($middlename)."', '".$db->escape($lastname)."',";
        $sql .= "'".$db->escape($address)."', '".$db->escape($email)."', '".$db->escape($phone)."', '".$db->escape($employee_id)."',";
        $sql .= "'".$db->escape($contract_number)."', '".$db->escape($license_number)."',";
        $sql .= ($license_issue_date ? "'".$db->idate($license_issue_date)."'" : "NULL").",";
        $sql .= ($license_expiry_date ? "'".$db->idate($license_expiry_date)."'" : "NULL").",";
        $sql .= ($join_date ? "'".$db->idate($join_date)."'" : "NULL").",";
        $sql .= ($leave_date ? "'".$db->idate($leave_date)."'" : "NULL").",";
        $sql .= "'".$db->escape($password)."', '".$db->escape($department)."', '".$db->escape($status)."',";
        $sql .= "'".$db->escape($gender)."', '".$db->escape($emergency_contact)."', ".($fk_vehicle ? $fk_vehicle : "NULL").", ";
        $sql .= ($driver_image ? "'".$db->escape($driver_image)."'" : "NULL").", ";
        $sql .= ($license_image ? "'".$db->escape($license_image)."'" : "NULL").", ";
        $sql .= ($documents ? "'".$db->escape($documents)."'" : "NULL").", ";
        $sql .= $user->id.", ";
        $sql .= "'".$db->idate(dol_now())."'";
        $sql .= ")";
        
        $resql = $db->query($sql);
        if ($resql) {
            $newid = $db->last_insert_id(MAIN_DB_PREFIX."flotte_driver");
            $db->commit();
            setEventMessages($langs->trans("DriverCreatedSuccessfully"), null, 'mesgs');
            header('Location: '.$_SERVER['PHP_SELF'].'?id='.$newid);
            exit;
        } else {
            $db->rollback();
            $error++;
            $errors[] = "Error in SQL: ".$db->lasterror();
        }
    }
    
    if ($error) {
        setEventMessages($errors, null, 'errors');
        $action = 'create';
    }
}

// Handle form submission - UPDATE
if ($action == 'update' && $id > 0 && !$cancel && $_SERVER["REQUEST_METHOD"] == "POST") {
    
    $firstname = GETPOST('firstname', 'alpha');
    $middlename = GETPOST('middlename', 'alpha');
    $lastname = GETPOST('lastname', 'alpha');
    $address = GETPOST('address', 'restricthtml');
    $email = GETPOST('email', 'email');
    $phone = GETPOST('phone', 'alphanohtml');
    $employee_id = GETPOST('employee_id', 'alphanohtml');
    $contract_number = GETPOST('contract_number', 'alphanohtml');
    $license_number = GETPOST('license_number', 'alphanohtml');
    
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
        $driver_image = handleFileUpload('driver_image', $upload_dir);
        if (!$driver_image) $driver_image = $driver_data['driver_image'];
        
        $license_image = handleFileUpload('license_image', $upload_dir);
        if (!$license_image) $license_image = $driver_data['license_image'];
        
        $documents = handleFileUpload('documents', $upload_dir);
        if (!$documents) $documents = $driver_data['documents'];
        
        $sql = "UPDATE ".MAIN_DB_PREFIX."flotte_driver SET ";
        $sql .= "firstname = '".$db->escape($firstname)."', ";
        $sql .= "middlename = '".$db->escape($middlename)."', ";
        $sql .= "lastname = '".$db->escape($lastname)."', ";
        $sql .= "address = '".$db->escape($address)."', ";
        $sql .= "email = '".$db->escape($email)."', ";
        $sql .= "phone = '".$db->escape($phone)."', ";
        $sql .= "employee_id = '".$db->escape($employee_id)."', ";
        $sql .= "contract_number = '".$db->escape($contract_number)."', ";
        $sql .= "license_number = '".$db->escape($license_number)."', ";
        $sql .= "license_issue_date = ".($license_issue_date ? "'".$db->idate($license_issue_date)."'" : "NULL").", ";
        $sql .= "license_expiry_date = ".($license_expiry_date ? "'".$db->idate($license_expiry_date)."'" : "NULL").", ";
        $sql .= "join_date = ".($join_date ? "'".$db->idate($join_date)."'" : "NULL").", ";
        $sql .= "leave_date = ".($leave_date ? "'".$db->idate($leave_date)."'" : "NULL").", ";
        $sql .= "password = '".$db->escape($password)."', ";
        $sql .= "department = '".$db->escape($department)."', ";
        $sql .= "status = '".$db->escape($status)."', ";
        $sql .= "gender = '".$db->escape($gender)."', ";
        $sql .= "emergency_contact = '".$db->escape($emergency_contact)."', ";
        $sql .= "fk_vehicle = ".($fk_vehicle ? $fk_vehicle : "NULL").", ";
        $sql .= "driver_image = ".($driver_image ? "'".$db->escape($driver_image)."'" : "NULL").", ";
        $sql .= "license_image = ".($license_image ? "'".$db->escape($license_image)."'" : "NULL").", ";
        $sql .= "documents = ".($documents ? "'".$db->escape($documents)."'" : "NULL").", ";
        $sql .= "fk_user_modif = ".$user->id.", ";
        $sql .= "tms = '".$db->idate(dol_now())."' ";
        $sql .= "WHERE rowid = ".(int)$id;
        
        $resql = $db->query($sql);
        if ($resql) {
            $db->commit();
            setEventMessages($langs->trans("DriverUpdatedSuccessfully"), null, 'mesgs');
            $action = 'view';
            // Reload driver data
            header('Location: '.$_SERVER['PHP_SELF'].'?id='.$id);
            exit;
        } else {
            $db->rollback();
            $error++;
            $errors[] = "Error in SQL: ".$db->lasterror();
        }
    }
    
    if ($error) {
        setEventMessages($errors, null, 'errors');
        $action = 'edit';
    }
}

if ($cancel) {
    $action = ($id > 0 ? 'view' : 'list');
    if ($action == 'list') {
        header('Location: driver_list.php');
        exit;
    }
}

/*
 * View
 */

llxHeader('', $langs->trans('Driver'), '');

// Add JavaScript for auto-fill functionality
?>
<script type="text/javascript">
jQuery(document).ready(function() {
    // When Employee is selected, auto-fill the fields
    jQuery('#fk_user').change(function() {
        var userid = jQuery(this).val();
        
        if (userid > 0) {
            // Fetch Employee data via AJAX
            jQuery.ajax({
                url: '<?php echo $_SERVER['PHP_SELF']; ?>',
                type: 'GET',
                data: {
                    action: 'fetch_employee',
                    fk_user: userid
                },
                dataType: 'json',
                success: function(data) {
                    // Auto-fill the form fields
                    jQuery('input[name="firstname"]').val(data.firstname);
                    jQuery('input[name="lastname"]').val(data.lastname);
                    jQuery('input[name="email"]').val(data.email);
                    jQuery('input[name="phone"]').val(data.phone);
                    jQuery('textarea[name="address"]').val(data.address);
                    jQuery('input[name="employee_id"]').val(data.employee_id);
                },
                error: function() {
                    console.log('Error fetching Employee data');
                }
            });
        }
    });
});
</script>
<?php

// Show delete confirmation dialog
if ($action == 'delete') {
    $formconfirm = $form->formconfirm($_SERVER["PHP_SELF"].'?id='.$id, $langs->trans('DeleteDriver'), $langs->trans('ConfirmDeleteDriver'), 'confirm_delete', '', 0, 1);
    print $formconfirm;
}

// Build page header
if ($id > 0 && $action != 'create') {
    $head = array();
    $head[0][0] = $_SERVER['PHP_SELF'].'?id='.$id;
    $head[0][1] = $langs->trans('Card');
    $head[0][2] = 'card';
    
    dol_fiche_head($head, 'card', $langs->trans('Driver').' : '.(isset($driver_data['ref']) ? $driver_data['ref'] : ''), -1, 'user');
} else {
    dol_fiche_head(array(), '', ($action == 'create' ? $langs->trans('NewDriver') : $langs->trans('Driver')), -1, 'user');
}

// Form start
if ($action == 'create' || $action == 'edit') {
    print '<form method="POST" action="'.$_SERVER['PHP_SELF'].($id > 0 ? '?id='.$id : '').'" enctype="multipart/form-data">';
    print '<input type="hidden" name="token" value="'.newToken().'">';
    print '<input type="hidden" name="action" value="'.($action == 'create' ? 'add' : 'update').'">';
    if ($id > 0) {
        print '<input type="hidden" name="id" value="'.$id.'">';
    }
}

print '<div class="fichecenter">';
print '<div class="fichehalfleft">';

// Basic Information
print load_fiche_titre($langs->trans('Basic Information'), '', '');
print '<table class="border tableforfield" width="100%">';

// Reference
print '<tr><td class="titlefield">' . $langs->trans('Reference') . '</td><td>';
if ($action == 'create') {
    print '<em>' . $langs->trans('AutoGenerated') . '</em>';
} else {
    print dol_escape_htmltag($driver_data['ref']);
}
print '</td></tr>';

// Employee Selection (HRM)
print '<tr><td class="fieldrequired">' . $langs->trans('Employee') . '</td><td>';
if ($action == 'create') {
    // Build employee selection
    $sql_users = "SELECT u.rowid, u.lastname, u.firstname, u.employee";
    $sql_users .= " FROM ".MAIN_DB_PREFIX."user as u";
    $sql_users .= " WHERE u.employee = 1";
    $sql_users .= " AND u.entity IN (".getEntity('user').")";
    $sql_users .= " AND u.statut = 1";
    $sql_users .= " ORDER BY u.lastname, u.firstname";
    
    print '<select class="flat minwidth300" name="fk_user" id="fk_user">';
    print '<option value="">-- '.$langs->trans('Select Employee').' --</option>';
    
    $resql_users = $db->query($sql_users);
    if ($resql_users) {
        while ($obj_user = $db->fetch_object($resql_users)) {
            $selected = '';
            print '<option value="'.$obj_user->rowid.'"'.$selected.'>';
            print dol_escape_htmltag($obj_user->lastname.' '.$obj_user->firstname);
            if ($obj_user->employee) {
                print ' ('.$obj_user->employee.')';
            }
            print '</option>';
        }
    }
    print '</select>';
    
    print ' <a href="'.DOL_URL_ROOT.'/user/card.php?action=create&employee=1&backtopage='.urlencode($_SERVER['PHP_SELF'].'?action=create').'" target="_blank">';
    print img_picto($langs->trans("Create Employee"), 'add', 'class="paddingleft"');
    print '</a>';
} else {
    if ($employee->id > 0) {
        print $employee->getNomUrl(1);
        print ' ('.$employee->employee.')';
    } else {
        print '&nbsp;';
    }
}
print '</td></tr>';

// First Name
print '<tr><td class="fieldrequired">' . $langs->trans('First Name') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    print '<input type="text" class="flat minwidth200" name="firstname" value="' . (isset($driver_data['firstname']) ? dol_escape_htmltag($driver_data['firstname']) : '') . '">';
} else {
    print dol_escape_htmltag($driver_data['firstname']);
}
print '</td></tr>';

// Middle Name
print '<tr><td>' . $langs->trans('Middle Name') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    print '<input type="text" class="flat minwidth200" name="middlename" value="' . (isset($driver_data['middlename']) ? dol_escape_htmltag($driver_data['middlename']) : '') . '">';
} else {
    print dol_escape_htmltag($driver_data['middlename']);
}
print '</td></tr>';

// Last Name
print '<tr><td class="fieldrequired">' . $langs->trans('Last Name') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    print '<input type="text" class="flat minwidth200" name="lastname" value="' . (isset($driver_data['lastname']) ? dol_escape_htmltag($driver_data['lastname']) : '') . '">';
} else {
    print dol_escape_htmltag($driver_data['lastname']);
}
print '</td></tr>';

// Email
print '<tr><td>' . $langs->trans('Email') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    print '<input type="email" class="flat minwidth300" name="email" value="' . (isset($driver_data['email']) ? dol_escape_htmltag($driver_data['email']) : '') . '">';
} else {
    print dol_print_email($driver_data['email']);
}
print '</td></tr>';

// Phone
print '<tr><td>' . $langs->trans('Phone') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    print '<input type="text" class="flat minwidth200" name="phone" value="' . (isset($driver_data['phone']) ? dol_escape_htmltag($driver_data['phone']) : '') . '">';
} else {
    print dol_print_phone($driver_data['phone']);
}
print '</td></tr>';

// Gender
print '<tr><td>' . $langs->trans('Gender') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    print '<select class="flat minwidth100" name="gender">';
    print '<option value="">--</option>';
    print '<option value="Male"'.(isset($driver_data['gender']) && $driver_data['gender'] == 'Male' ? ' selected' : '').'>'.$langs->trans('Male').'</option>';
    print '<option value="Female"'.(isset($driver_data['gender']) && $driver_data['gender'] == 'Female' ? ' selected' : '').'>'.$langs->trans('Female').'</option>';
    print '</select>';
} else {
    print dol_escape_htmltag($driver_data['gender']);
}
print '</td></tr>';

print '</table>';
print '</div>';

print '<div class="fichehalfright">';

// Employment Information
print load_fiche_titre($langs->trans('Employment Information'), '', '');
print '<table class="border tableforfield" width="100%">';

// Employee ID
print '<tr><td class="titlefield">' . $langs->trans('Employee ID') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    print '<input type="text" class="flat minwidth150" name="employee_id" value="' . (isset($driver_data['employee_id']) ? dol_escape_htmltag($driver_data['employee_id']) : '') . '">';
} else {
    print dol_escape_htmltag($driver_data['employee_id']);
}
print '</td></tr>';

// Contract Number
print '<tr><td>' . $langs->trans('Contract Number') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    print '<input type="text" class="flat minwidth150" name="contract_number" value="' . (isset($driver_data['contract_number']) ? dol_escape_htmltag($driver_data['contract_number']) : '') . '">';
} else {
    print dol_escape_htmltag($driver_data['contract_number']);
}
print '</td></tr>';

// Department
print '<tr><td>' . $langs->trans('Department') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    print '<input type="text" class="flat minwidth150" name="department" value="' . (isset($driver_data['department']) ? dol_escape_htmltag($driver_data['department']) : '') . '">';
} else {
    print dol_escape_htmltag($driver_data['department']);
}
print '</td></tr>';

// Join Date
print '<tr><td>' . $langs->trans('Join Date') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    print $form->selectDate(isset($driver_data['join_date']) ? $driver_data['join_date'] : -1, 'join_date', 0, 0, 1, '', 1, 0);
} else {
    print dol_print_date($driver_data['join_date'], 'day');
}
print '</td></tr>';

// Leave Date
print '<tr><td>' . $langs->trans('Leave Date') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    print $form->selectDate(isset($driver_data['leave_date']) ? $driver_data['leave_date'] : -1, 'leave_date', 0, 0, 1, '', 1, 0);
} else {
    print dol_print_date($driver_data['leave_date'], 'day');
}
print '</td></tr>';

// Status
print '<tr><td>' . $langs->trans('Status') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    print '<select class="flat minwidth150" name="status">';
    print '<option value="">--</option>';
    print '<option value="Active"'.(isset($driver_data['status']) && $driver_data['status'] == 'Active' ? ' selected' : '').'>'.$langs->trans('Active').'</option>';
    print '<option value="Inactive"'.(isset($driver_data['status']) && $driver_data['status'] == 'Inactive' ? ' selected' : '').'>'.$langs->trans('Inactive').'</option>';
    print '<option value="On Leave"'.(isset($driver_data['status']) && $driver_data['status'] == 'On Leave' ? ' selected' : '').'>'.$langs->trans('OnLeave').'</option>';
    print '</select>';
} else {
    if (!empty($driver_data['status'])) {
        if ($driver_data['status'] == 'Active') {
            print dolGetStatus($langs->trans('Active'), '', '', 'status4', 1);
        } elseif ($driver_data['status'] == 'Inactive') {
            print dolGetStatus($langs->trans('Inactive'), '', '', 'status8', 1);
        } else {
            print dolGetStatus($driver_data['status'], '', '', 'status3', 1);
        }
    }
}
print '</td></tr>';

// Assigned Vehicle
print '<tr><td>' . $langs->trans('Assigned Vehicle') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    print '<select class="flat minwidth200" name="fk_vehicle">';
    print '<option value="">-- '.$langs->trans('SelectVehicle').' --</option>';
    foreach ($vehicles as $vid => $vlabel) {
        $selected = (isset($driver_data['fk_vehicle']) && $driver_data['fk_vehicle'] == $vid) ? ' selected' : '';
        print '<option value="'.$vid.'"'.$selected.'>'.$vlabel.'</option>';
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

// License Information
print '<div class="fichecenter">';
print '<div class="ficheaddleft">';

print load_fiche_titre($langs->trans('License Information'), '', '');
print '<table class="border tableforfield" width="100%">';

// License Number
print '<tr><td class="titlefield">' . $langs->trans('License Number') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    print '<input type="text" class="flat minwidth200" name="license_number" value="' . (isset($driver_data['license_number']) ? dol_escape_htmltag($driver_data['license_number']) : '') . '">';
} else {
    print dol_escape_htmltag($driver_data['license_number']);
}
print '</td></tr>';

// License Issue Date
print '<tr><td>' . $langs->trans('License Issue Date') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    print $form->selectDate(isset($driver_data['license_issue_date']) ? $driver_data['license_issue_date'] : -1, 'license_issue_date', 0, 0, 1, '', 1, 0);
} else {
    print dol_print_date($driver_data['license_issue_date'], 'day');
}
print '</td></tr>';

// License Expiry Date
print '<tr><td>' . $langs->trans('License Expiry Date') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    print $form->selectDate(isset($driver_data['license_expiry_date']) ? $driver_data['license_expiry_date'] : -1, 'license_expiry_date', 0, 0, 1, '', 1, 0);
} else {
    print dol_print_date($driver_data['license_expiry_date'], 'day');
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
    print '<textarea name="address" class="flat" rows="3" cols="80">' . (isset($driver_data['address']) ? dol_escape_htmltag($driver_data['address']) : '') . '</textarea>';
} else {
    print nl2br(dol_escape_htmltag($driver_data['address']));
}
print '</td></tr>';

print '</table>';
print '</div>';
print '</div>';

print '<div class="clearboth"></div><br>';

// Emergency Contact
print '<div class="fichecenter">';
print '<div class="ficheaddleft">';

print load_fiche_titre($langs->trans('Emergency Contact'), '', '');
print '<table class="border tableforfield" width="100%">';

print '<tr><td>';
if ($action == 'create' || $action == 'edit') {
    print '<textarea name="emergency_contact" class="flat" rows="2" cols="80">' . (isset($driver_data['emergency_contact']) ? dol_escape_htmltag($driver_data['emergency_contact']) : '') . '</textarea>';
} else {
    print nl2br(dol_escape_htmltag($driver_data['emergency_contact']));
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
print '<tr><td class="titlefield">' . $langs->trans('Driver Image') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    print '<input type="file" name="driver_image" accept="image/*">';
    if (!empty($driver_data['driver_image'])) {
        print '<br><small>' . $langs->trans('Current') . ': ' . $driver_data['driver_image'] . '</small>';
    }
} else {
    if (!empty($driver_data['driver_image'])) {
        $file_path = $upload_dir . '/' . $driver_data['driver_image'];
        if (file_exists($file_path)) {
            print '<a href="' . DOL_URL_ROOT . '/document.php?modulepart=flotte&file=driver/' . urlencode($driver_data['driver_image']) . '" target="_blank">' . $driver_data['driver_image'] . '</a>';
        } else {
            print $driver_data['driver_image'];
        }
    } else {
        print '&nbsp;';
    }
}
print '</td></tr>';

// License Image
print '<tr><td>' . $langs->trans('License Image') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    print '<input type="file" name="license_image" accept="image/*">';
    if (!empty($driver_data['license_image'])) {
        print '<br><small>' . $langs->trans('Current') . ': ' . $driver_data['license_image'] . '</small>';
    }
} else {
    if (!empty($driver_data['license_image'])) {
        $file_path = $upload_dir . '/' . $driver_data['license_image'];
        if (file_exists($file_path)) {
            print '<a href="' . DOL_URL_ROOT . '/document.php?modulepart=flotte&file=driver/' . urlencode($driver_data['license_image']) . '" target="_blank">' . $driver_data['license_image'] . '</a>';
        } else {
            print $driver_data['license_image'];
        }
    } else {
        print '&nbsp;';
    }
}
print '</td></tr>';

// Documents
print '<tr><td>' . $langs->trans('Documents') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    print '<input type="file" name="documents" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png">';
    if (!empty($driver_data['documents'])) {
        print '<br><small>' . $langs->trans('Current') . ': ' . $driver_data['documents'] . '</small>';
    }
} else {
    if (!empty($driver_data['documents'])) {
        $file_path = $upload_dir . '/' . $driver_data['documents'];
        if (file_exists($file_path)) {
            print '<a href="' . DOL_URL_ROOT . '/document.php?modulepart=flotte&file=driver/' . urlencode($driver_data['documents']) . '" target="_blank">' . $driver_data['documents'] . '</a>';
        } else {
            print $driver_data['documents'];
        }
    } else {
        print '&nbsp;';
    }
}
print '</td></tr>';

print '</table>';
print '</div>';
print '</div>';

print '<div class="clearboth"></div>';

// Button styling
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
    input.flotte-btn {
        background: #3c6d9f;
        border: 1px solid #2e5a85;
        color: #fff;
    }
    input.flotte-btn:hover {
        background: #2e5a85;
    }
    a.flotte-btn-primary {
        background: #3c6d9f;
        border: 1px solid #2e5a85;
        color: #fff;
    }
    a.flotte-btn-primary:hover {
        background: #2e5a85;
        color: #fff;
    }
    a.flotte-btn-cancel {
        background: #fff;
        border: 1px solid #3c6d9f;
        color: #3c6d9f;
    }
    a.flotte-btn-cancel:hover {
        background: #eef3f8;
        color: #2e5a85;
    }
    a.flotte-btn-back {
        background: #fff;
        border: 1px solid #3c6d9f;
        color: #3c6d9f;
    }
    a.flotte-btn-back:hover {
        background: #eef3f8;
        color: #2e5a85;
    }
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
    print '<a class="flotte-btn flotte-btn-cancel" href="' . ($id > 0 ? $_SERVER['PHP_SELF'] . '?id=' . $id : 'driver_list.php') . '">' . $langs->trans('Cancel') . '</a>';
    print '<a class="flotte-btn flotte-btn-back" href="' . dol_buildpath('/flotte/driver_list.php', 1) . '">' . $langs->trans('BackToList') . '</a>';
    print '</div>';
    print '</form>';
} elseif ($id > 0) {
    // Action buttons
    print '<div class="center" style="margin-top: 20px; margin-bottom: 10px;">';
    print '<a class="flotte-btn flotte-btn-primary" href="' . $_SERVER['PHP_SELF'] . '?id=' . $id . '&action=edit">' . $langs->trans('Modify') . '</a>';
    print '<a class="flotte-btn flotte-btn-delete" href="' . $_SERVER['PHP_SELF'] . '?id=' . $id . '&action=delete">' . $langs->trans('Delete') . '</a>';
    print '<a class="flotte-btn flotte-btn-back" href="' . dol_buildpath('/flotte/driver_list.php', 1) . '">' . $langs->trans('BackToList') . '</a>';
    print '</div>';
}

dol_fiche_end();

// End of page
llxFooter();
$db->close();
?>
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

// Auto-fill JS
?>
<script type="text/javascript">
jQuery(document).ready(function() {
    jQuery('#fk_user').change(function() {
        var userid = jQuery(this).val();
        if (userid > 0) {
            jQuery.ajax({
                url: '<?php echo $_SERVER['PHP_SELF']; ?>',
                type: 'GET',
                data: { action: 'fetch_employee', fk_user: userid },
                dataType: 'json',
                success: function(data) {
                    jQuery('input[name="firstname"]').val(data.firstname);
                    jQuery('input[name="lastname"]').val(data.lastname);
                    jQuery('input[name="email"]').val(data.email);
                    jQuery('input[name="phone"]').val(data.phone);
                    jQuery('textarea[name="address"]').val(data.address);
                    jQuery('input[name="employee_id"]').val(data.employee_id);
                },
                error: function() { console.log('Error fetching Employee data'); }
            });
        }
    });
});
</script>
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
.dc-badge.active    { background: #edfaf3; color: #1a7d4a; }
.dc-badge.active::before    { background: #22c55e; }
.dc-badge.inactive  { background: #fef2f2; color: #b91c1c; }
.dc-badge.inactive::before  { background: #ef4444; }
.dc-badge.onleave   { background: #fff8ec; color: #b45309; }
.dc-badge.onleave::before   { background: #f59e0b; }

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
.dc-grid-full { grid-template-columns: 1fr; }
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

/* ── Mono chip ── */
.dc-mono {
    font-family: 'DM Mono', monospace; font-size: 12px;
    background: #f0f2fa; color: #4a5568;
    padding: 3px 9px; border-radius: 5px; display: inline-block;
}

/* ── Vehicle chip ── */
.dc-vehicle {
    display: inline-flex; align-items: center; gap: 6px;
    font-size: 12.5px; font-weight: 600; color: #3c4758;
    background: rgba(60,71,88,0.07); padding: 4px 10px; border-radius: 6px;
}

/* ── Form inputs ── */
.dc-page input[type="text"],
.dc-page input[type="email"],
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

// Show delete confirmation dialog
if ($action == 'delete') {
    $formconfirm = $form->formconfirm($_SERVER["PHP_SELF"].'?id='.$id, $langs->trans('DeleteDriver'), $langs->trans('ConfirmDeleteDriver'), 'confirm_delete', '', 0, 1);
    print $formconfirm;
}

// Determine page title & subtitle
$isEdit   = ($action == 'edit');
$isCreate = ($action == 'create');
$isView   = (!$isEdit && !$isCreate);

$pageTitle = $isCreate ? $langs->trans('NewDriver') : ($isEdit ? $langs->trans('EditDriver') : $langs->trans('Driver'));
$pageSub   = $isCreate ? $langs->trans('FillInDriverDetails') : (isset($driver_data['ref']) ? $driver_data['ref'] : '');

// Form start
if ($isCreate || $isEdit) {
    print '<form method="POST" action="'.$_SERVER['PHP_SELF'].($id > 0 ? '?id='.$id : '').'" enctype="multipart/form-data">';
    print '<input type="hidden" name="token" value="'.newToken().'">';
    print '<input type="hidden" name="action" value="'.($isCreate ? 'add' : 'update').'">';
    if ($id > 0) print '<input type="hidden" name="id" value="'.$id.'">';
}

print '<div class="dc-page">';

/* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
   PAGE HEADER
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */
print '<div class="dc-header">';
print '  <div class="dc-header-left">';
print '    <div class="dc-header-icon"><i class="fa fa-user"></i></div>';
print '    <div>';
print '      <div class="dc-header-title">'.dol_escape_htmltag($pageTitle).'</div>';
if ($pageSub) print '      <div class="dc-header-sub">'.dol_escape_htmltag($pageSub).'</div>';
print '    </div>';
print '  </div>';
print '  <div class="dc-header-actions">';
if ($isView && $id > 0) {
    if (!empty($driver_data['status'])) {
        $st = $driver_data['status'];
        $stClass = ($st == 'Active') ? 'active' : (($st == 'Inactive') ? 'inactive' : 'onleave');
        print '<span class="dc-badge '.$stClass.'">'.dol_escape_htmltag($st).'</span>';
    }
    print '<a class="dc-btn dc-btn-ghost" href="'.dol_buildpath('/flotte/driver_list.php', 1).'"><i class="fa fa-arrow-left"></i> '.$langs->trans('BackToList').'</a>';
    print '<a class="dc-btn dc-btn-ghost" href="'.$_SERVER['PHP_SELF'].'?id='.$id.'&action=edit"><i class="fa fa-pen"></i> '.$langs->trans('Modify').'</a>';
    print '<a class="dc-btn dc-btn-danger" href="'.$_SERVER['PHP_SELF'].'?id='.$id.'&action=delete"><i class="fa fa-trash"></i> '.$langs->trans('Delete').'</a>';
}

print '  </div>';
print '</div>';

/* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
   ROW 1 — Basic Info + Employment
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */
print '<div class="dc-grid">';

/* ── Card: Basic Information ── */
print '<div class="dc-card">';
print '  <div class="dc-card-header">';
print '    <div class="dc-card-header-icon blue"><i class="fa fa-id-card"></i></div>';
print '    <span class="dc-card-title">'.$langs->trans('BasicInformation').'</span>';
print '  </div>';
print '  <div class="dc-card-body">';

// Reference
print '  <div class="dc-field">';
print '    <div class="dc-field-label">'.$langs->trans('Reference').'</div>';
print '    <div class="dc-field-value">';
if ($isCreate) {
    print '<em style="color:#9aa0b4;font-size:12.5px;">'.$langs->trans('AutoGenerated').'</em>';
} else {
    print '<span class="dc-ref-tag">'.dol_escape_htmltag($driver_data['ref']).'</span>';
}
print '    </div></div>';

// Employee
print '  <div class="dc-field">';
print '    <div class="dc-field-label required">'.$langs->trans('Employee').'</div>';
print '    <div class="dc-field-value">';
if ($isCreate) {
    $sql_users = "SELECT u.rowid, u.lastname, u.firstname, u.employee FROM ".MAIN_DB_PREFIX."user as u WHERE u.employee = 1 AND u.entity IN (".getEntity('user').") AND u.statut = 1 ORDER BY u.lastname, u.firstname";
    print '<div style="display:flex;gap:8px;align-items:center;">';
    print '<select name="fk_user" id="fk_user"><option value="">-- '.$langs->trans('Select Employee').' --</option>';
    $resql_users = $db->query($sql_users);
    if ($resql_users) {
        while ($obj_user = $db->fetch_object($resql_users)) {
            print '<option value="'.$obj_user->rowid.'">';
            print dol_escape_htmltag($obj_user->lastname.' '.$obj_user->firstname);
            if ($obj_user->employee) print ' ('.$obj_user->employee.')';
            print '</option>';
        }
    }
    print '</select>';
    print '<a href="'.DOL_URL_ROOT.'/user/card.php?action=create&employee=1&backtopage='.urlencode($_SERVER['PHP_SELF'].'?action=create').'" target="_blank" style="flex-shrink:0;">'.img_picto($langs->trans("Create Employee"), 'add').'</a>';
    print '</div>';
} else {
    if ($employee->id > 0) { print $employee->getNomUrl(1); if ($employee->employee) print ' <span class="dc-mono">'.$employee->employee.'</span>'; }
    else print '&nbsp;';
}
print '    </div></div>';

// First Name
print '  <div class="dc-field">';
print '    <div class="dc-field-label required">'.$langs->trans('FirstName').'</div>';
print '    <div class="dc-field-value">';
if ($isCreate || $isEdit) print '<input type="text" name="firstname" value="'.(isset($driver_data['firstname']) ? dol_escape_htmltag($driver_data['firstname']) : '').'">';
else print dol_escape_htmltag($driver_data['firstname']);
print '    </div></div>';

// Middle Name
print '  <div class="dc-field">';
print '    <div class="dc-field-label">'.$langs->trans('MiddleName').'</div>';
print '    <div class="dc-field-value">';
if ($isCreate || $isEdit) print '<input type="text" name="middlename" value="'.(isset($driver_data['middlename']) ? dol_escape_htmltag($driver_data['middlename']) : '').'">';
else print dol_escape_htmltag($driver_data['middlename']);
print '    </div></div>';

// Last Name
print '  <div class="dc-field">';
print '    <div class="dc-field-label required">'.$langs->trans('LastName').'</div>';
print '    <div class="dc-field-value">';
if ($isCreate || $isEdit) print '<input type="text" name="lastname" value="'.(isset($driver_data['lastname']) ? dol_escape_htmltag($driver_data['lastname']) : '').'">';
else print dol_escape_htmltag($driver_data['lastname']);
print '    </div></div>';

// Email
print '  <div class="dc-field">';
print '    <div class="dc-field-label">'.$langs->trans('Email').'</div>';
print '    <div class="dc-field-value">';
if ($isCreate || $isEdit) print '<input type="email" name="email" value="'.(isset($driver_data['email']) ? dol_escape_htmltag($driver_data['email']) : '').'">';
else print dol_print_email($driver_data['email']);
print '    </div></div>';

// Phone
print '  <div class="dc-field">';
print '    <div class="dc-field-label">'.$langs->trans('Phone').'</div>';
print '    <div class="dc-field-value">';
if ($isCreate || $isEdit) print '<input type="text" name="phone" value="'.(isset($driver_data['phone']) ? dol_escape_htmltag($driver_data['phone']) : '').'">';
else print dol_print_phone($driver_data['phone']);
print '    </div></div>';

// Gender
print '  <div class="dc-field">';
print '    <div class="dc-field-label">'.$langs->trans('Gender').'</div>';
print '    <div class="dc-field-value">';
if ($isCreate || $isEdit) {
    print '<select name="gender"><option value="">--</option>';
    print '<option value="Male"'.(isset($driver_data['gender']) && $driver_data['gender'] == 'Male' ? ' selected' : '').'>'.$langs->trans('Male').'</option>';
    print '<option value="Female"'.(isset($driver_data['gender']) && $driver_data['gender'] == 'Female' ? ' selected' : '').'>'.$langs->trans('Female').'</option>';
    print '</select>';
} else print dol_escape_htmltag($driver_data['gender']);
print '    </div></div>';

print '  </div>';// card-body
print '</div>';  // dc-card

/* ── Card: Employment Information ── */
print '<div class="dc-card">';
print '  <div class="dc-card-header">';
print '    <div class="dc-card-header-icon green"><i class="fa fa-briefcase"></i></div>';
print '    <span class="dc-card-title">'.$langs->trans('EmploymentInformation').'</span>';
print '  </div>';
print '  <div class="dc-card-body">';

// Employee ID
print '  <div class="dc-field">';
print '    <div class="dc-field-label">'.$langs->trans('EmployeeID').'</div>';
print '    <div class="dc-field-value">';
if ($isCreate || $isEdit) print '<input type="text" name="employee_id" value="'.(isset($driver_data['employee_id']) ? dol_escape_htmltag($driver_data['employee_id']) : '').'">';
else print (!empty($driver_data['employee_id']) ? '<span class="dc-mono">'.dol_escape_htmltag($driver_data['employee_id']).'</span>' : '&mdash;');
print '    </div></div>';

// Contract Number
print '  <div class="dc-field">';
print '    <div class="dc-field-label">'.$langs->trans('ContractNumber').'</div>';
print '    <div class="dc-field-value">';
if ($isCreate || $isEdit) print '<input type="text" name="contract_number" value="'.(isset($driver_data['contract_number']) ? dol_escape_htmltag($driver_data['contract_number']) : '').'">';
else print (!empty($driver_data['contract_number']) ? '<span class="dc-mono">'.dol_escape_htmltag($driver_data['contract_number']).'</span>' : '&mdash;');
print '    </div></div>';

// Department
print '  <div class="dc-field">';
print '    <div class="dc-field-label">'.$langs->trans('Department').'</div>';
print '    <div class="dc-field-value">';
if ($isCreate || $isEdit) print '<input type="text" name="department" value="'.(isset($driver_data['department']) ? dol_escape_htmltag($driver_data['department']) : '').'">';
else print dol_escape_htmltag($driver_data['department']);
print '    </div></div>';

// Join Date
print '  <div class="dc-field">';
print '    <div class="dc-field-label">'.$langs->trans('JoinDate').'</div>';
print '    <div class="dc-field-value">';
if ($isCreate || $isEdit) print $form->selectDate(isset($driver_data['join_date']) ? $driver_data['join_date'] : -1, 'join_date', 0, 0, 1, '', 1, 0);
else print dol_print_date($driver_data['join_date'], 'day');
print '    </div></div>';

// Leave Date
print '  <div class="dc-field">';
print '    <div class="dc-field-label">'.$langs->trans('LeaveDate').'</div>';
print '    <div class="dc-field-value">';
if ($isCreate || $isEdit) print $form->selectDate(isset($driver_data['leave_date']) ? $driver_data['leave_date'] : -1, 'leave_date', 0, 0, 1, '', 1, 0);
else print dol_print_date($driver_data['leave_date'], 'day');
print '    </div></div>';

// Status
print '  <div class="dc-field">';
print '    <div class="dc-field-label">'.$langs->trans('Status').'</div>';
print '    <div class="dc-field-value">';
if ($isCreate || $isEdit) {
    print '<select name="status"><option value="">--</option>';
    print '<option value="Active"'.(isset($driver_data['status']) && $driver_data['status'] == 'Active' ? ' selected' : '').'>'.$langs->trans('Active').'</option>';
    print '<option value="Inactive"'.(isset($driver_data['status']) && $driver_data['status'] == 'Inactive' ? ' selected' : '').'>'.$langs->trans('Inactive').'</option>';
    print '<option value="On Leave"'.(isset($driver_data['status']) && $driver_data['status'] == 'On Leave' ? ' selected' : '').'>'.$langs->trans('OnLeave').'</option>';
    print '</select>';
} else {
    if (!empty($driver_data['status'])) {
        $st = $driver_data['status'];
        $stClass = ($st == 'Active') ? 'active' : (($st == 'Inactive') ? 'inactive' : 'onleave');
        print '<span class="dc-badge '.$stClass.'">'.dol_escape_htmltag($st).'</span>';
    }
}
print '    </div></div>';

// Assigned Vehicle
print '  <div class="dc-field">';
print '    <div class="dc-field-label">'.$langs->trans('AssignedVehicle').'</div>';
print '    <div class="dc-field-value">';
if ($isCreate || $isEdit) {
    print '<select name="fk_vehicle"><option value="">-- '.$langs->trans('SelectVehicle').' --</option>';
    foreach ($vehicles as $vid => $vlabel) {
        $selected = (isset($driver_data['fk_vehicle']) && $driver_data['fk_vehicle'] == $vid) ? ' selected' : '';
        print '<option value="'.$vid.'"'.$selected.'>'.dol_escape_htmltag($vlabel).'</option>';
    }
    print '</select>';
} else {
    if (!empty($driver_data['fk_vehicle']) && isset($vehicles[$driver_data['fk_vehicle']])) {
        print '<span class="dc-vehicle"><i class="fa fa-car" style="font-size:11px;opacity:0.6;"></i>'.dol_escape_htmltag($vehicles[$driver_data['fk_vehicle']]).'</span>';
    } else {
        print '<span style="color:#c4c9d8;font-size:13px;">'.$langs->trans('NoVehicleAssigned').'</span>';
    }
}
print '    </div></div>';

print '  </div>';// card-body
print '</div>';  // dc-card

print '</div>';// dc-grid row1

/* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
   ROW 2 — License Info + Address/Emergency
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */
print '<div class="dc-grid">';

/* ── Card: License Information ── */
print '<div class="dc-card">';
print '  <div class="dc-card-header">';
print '    <div class="dc-card-header-icon amber"><i class="fa fa-id-badge"></i></div>';
print '    <span class="dc-card-title">'.$langs->trans('LicenseInformation').'</span>';
print '  </div>';
print '  <div class="dc-card-body">';

// License Number
print '  <div class="dc-field">';
print '    <div class="dc-field-label">'.$langs->trans('LicenseNumber').'</div>';
print '    <div class="dc-field-value">';
if ($isCreate || $isEdit) print '<input type="text" name="license_number" value="'.(isset($driver_data['license_number']) ? dol_escape_htmltag($driver_data['license_number']) : '').'">';
else print (!empty($driver_data['license_number']) ? '<span class="dc-mono">'.dol_escape_htmltag($driver_data['license_number']).'</span>' : '&mdash;');
print '    </div></div>';

// License Issue Date
print '  <div class="dc-field">';
print '    <div class="dc-field-label">'.$langs->trans('LicenseIssueDate').'</div>';
print '    <div class="dc-field-value">';
if ($isCreate || $isEdit) print $form->selectDate(isset($driver_data['license_issue_date']) ? $driver_data['license_issue_date'] : -1, 'license_issue_date', 0, 0, 1, '', 1, 0);
else print dol_print_date($driver_data['license_issue_date'], 'day');
print '    </div></div>';

// License Expiry Date
print '  <div class="dc-field">';
print '    <div class="dc-field-label">'.$langs->trans('LicenseExpiryDate').'</div>';
print '    <div class="dc-field-value">';
if ($isCreate || $isEdit) print $form->selectDate(isset($driver_data['license_expiry_date']) ? $driver_data['license_expiry_date'] : -1, 'license_expiry_date', 0, 0, 1, '', 1, 0);
else print dol_print_date($driver_data['license_expiry_date'], 'day');
print '    </div></div>';

print '  </div>';// card-body
print '</div>';  // dc-card

/* ── Card: Address & Emergency ── */
print '<div class="dc-card">';
print '  <div class="dc-card-header">';
print '    <div class="dc-card-header-icon purple"><i class="fa fa-map-marker-alt"></i></div>';
print '    <span class="dc-card-title">'.$langs->trans('AddressAndContact').'</span>';
print '  </div>';
print '  <div class="dc-card-body">';

// Address
print '  <div class="dc-field">';
print '    <div class="dc-field-label">'.$langs->trans('Address').'</div>';
print '    <div class="dc-field-value">';
if ($isCreate || $isEdit) print '<textarea name="address" rows="3">'.(isset($driver_data['address']) ? dol_escape_htmltag($driver_data['address']) : '').'</textarea>';
else print nl2br(dol_escape_htmltag($driver_data['address']));
print '    </div></div>';

// Emergency Contact
print '  <div class="dc-field">';
print '    <div class="dc-field-label">'.$langs->trans('EmergencyContact').'</div>';
print '    <div class="dc-field-value">';
if ($isCreate || $isEdit) print '<textarea name="emergency_contact" rows="3">'.(isset($driver_data['emergency_contact']) ? dol_escape_htmltag($driver_data['emergency_contact']) : '').'</textarea>';
else print nl2br(dol_escape_htmltag($driver_data['emergency_contact']));
print '    </div></div>';

print '  </div>';// card-body
print '</div>';  // dc-card

print '</div>';// dc-grid row2

/* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
   ROW 3 — Files (full width)
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */
print '<div class="dc-card" style="margin-bottom:20px;">';
print '  <div class="dc-card-header">';
print '    <div class="dc-card-header-icon red"><i class="fa fa-paperclip"></i></div>';
print '    <span class="dc-card-title">'.$langs->trans('Files').'</span>';
print '  </div>';
print '  <div class="dc-card-body">';
print '  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:0;">';

// Helper for file fields
$file_fields = array(
    array('field' => 'driver_image',  'label' => $langs->trans('DriverImage'),  'accept' => 'image/*'),
    array('field' => 'license_image', 'label' => $langs->trans('LicenseImage'), 'accept' => 'image/*'),
    array('field' => 'documents',     'label' => $langs->trans('Documents'),    'accept' => '.pdf,.doc,.docx,.jpg,.jpeg,.png'),
);
foreach ($file_fields as $ff) {
    print '<div class="dc-field" style="flex-direction:column;gap:8px;">';
    print '  <div class="dc-field-label" style="flex:none;">'.$ff['label'].'</div>';
    print '  <div class="dc-field-value" style="width:100%;">';
    if ($isCreate || $isEdit) {
        print '<div class="dc-file-zone">';
        print '  <i class="fa fa-cloud-upload-alt"></i>';
        print '  <input type="file" name="'.$ff['field'].'" accept="'.$ff['accept'].'" style="width:100%;cursor:pointer;">';
        print '  <small>Accepted: '.str_replace('image/*','JPG, PNG',$ff['accept']).'</small>';
        print '</div>';
        if (!empty($driver_data[$ff['field']])) {
            print '<div class="dc-file-current"><i class="fa fa-paperclip"></i> '.$langs->trans('Current').': '.dol_escape_htmltag($driver_data[$ff['field']]).'</div>';
        }
    } else {
        if (!empty($driver_data[$ff['field']])) {
            $file_path = $upload_dir.'/'.$driver_data[$ff['field']];
            if (file_exists($file_path)) {
                print '<a href="'.DOL_URL_ROOT.'/document.php?modulepart=flotte&file=driver/'.urlencode($driver_data[$ff['field']]).'" target="_blank" class="dc-file-current"><i class="fa fa-download"></i> '.dol_escape_htmltag($driver_data[$ff['field']]).'</a>';
            } else {
                print '<span class="dc-mono">'.dol_escape_htmltag($driver_data[$ff['field']]).'</span>';
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
    print '<a class="dc-btn dc-btn-ghost dc-action-bar-left" href="'.dol_buildpath('/flotte/driver_list.php', 1).'"><i class="fa fa-arrow-left"></i> '.$langs->trans('BackToList').'</a>';
    print '<a class="dc-btn dc-btn-ghost" href="'.($id > 0 ? $_SERVER['PHP_SELF'].'?id='.$id : dol_buildpath('/flotte/driver_list.php', 1)).'"><i class="fa fa-times"></i> '.$langs->trans('Cancel').'</a>';
    print '<button type="submit" class="dc-btn dc-btn-primary"><i class="fa fa-check"></i> '.($isCreate ? $langs->trans('Create') : $langs->trans('Save')).'</button>';
    print '</div>';
    print '</form>';
} elseif ($id > 0) {
    print '<div class="dc-action-bar">';
    print '<a class="dc-btn dc-btn-ghost dc-action-bar-left" href="'.dol_buildpath('/flotte/driver_list.php', 1).'"><i class="fa fa-arrow-left"></i> '.$langs->trans('BackToList').'</a>';
    print '<a class="dc-btn dc-btn-ghost" href="'.$_SERVER['PHP_SELF'].'?id='.$id.'&action=edit"><i class="fa fa-pen"></i> '.$langs->trans('Modify').'</a>';
    print '<a class="dc-btn dc-btn-danger" href="'.$_SERVER['PHP_SELF'].'?id='.$id.'&action=delete"><i class="fa fa-trash"></i> '.$langs->trans('Delete').'</a>';
    print '</div>';
}

print '</div>';// dc-page

// End of page
llxFooter();
$db->close();
?>
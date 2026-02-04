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

// Function to generate next customer reference
function getNextCustomerRef($db, $entity) {
    $prefix = "CUST-";
    
    // Get the last reference from database
    $sql = "SELECT ref FROM ".MAIN_DB_PREFIX."flotte_customer";
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
    
    // Format with leading zeros (e.g., CUST-0001)
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
$object->firstname = '';
$object->lastname = '';
$object->phone = '';
$object->email = '';
$object->company_name = '';
$object->tax_no = '';
$object->payment_delay = '';
$object->gender = '';

$error = 0;
$errors = array();

// Generate reference for new customer
if ($action == 'create' && empty($object->ref)) {
    $object->ref = getNextCustomerRef($db, $conf->entity);
}

/*
 * Actions
 */

if ($cancel) {
    $action = 'view';
}

if ($action == 'confirm_delete' && $confirm == 'yes' && $user->rights->flotte->delete) {
    $db->begin();
    
    $sql = "DELETE FROM ".MAIN_DB_PREFIX."flotte_customer WHERE rowid = ".((int) $id);
    $result = $db->query($sql);
    
    if ($result) {
        $db->commit();
        header("Location: " . dol_buildpath('/flotte/customer_list.php', 1));
        exit;
    } else {
        $db->rollback();
        $error++;
        setEventMessages($langs->trans("ErrorDeletingCustomer"), null, 'errors');
    }
}

if ($action == 'add') {
    $db->begin();
    
    // Get form data
    $ref = GETPOST('ref', 'alpha');
    $firstname = GETPOST('firstname', 'alpha');
    $lastname = GETPOST('lastname', 'alpha');
    $phone = GETPOST('phone', 'alpha');
    $email = GETPOST('email', 'alpha');
    $company_name = GETPOST('company_name', 'alpha');
    $tax_no = GETPOST('tax_no', 'alpha');
    $payment_delay = GETPOST('payment_delay', 'int');
    $gender = GETPOST('gender', 'alpha');
    
    // Auto-generate reference if empty
    if (empty($ref)) {
        $ref = getNextCustomerRef($db, $conf->entity);
    }
    
    if (empty($firstname)) {
        $error++;
        $errors[] = $langs->trans("ErrorFieldRequired", $langs->trans("FirstName"));
    }
    if (empty($lastname)) {
        $error++;
        $errors[] = $langs->trans("ErrorFieldRequired", $langs->trans("LastName"));
    }
    
    if (!$error) {
        $sql = "INSERT INTO ".MAIN_DB_PREFIX."flotte_customer (";
        $sql .= "ref, entity, firstname, lastname, phone, email, company_name, tax_no, payment_delay, gender, fk_user_author";
        $sql .= ") VALUES (";
        $sql .= "'".$db->escape($ref)."', ".$conf->entity.", ";
        $sql .= "'".$db->escape($firstname)."', '".$db->escape($lastname)."', ";
        $sql .= "'".$db->escape($phone)."', '".$db->escape($email)."', ";
        $sql .= "'".$db->escape($company_name)."', '".$db->escape($tax_no)."', ";
        $sql .= ($payment_delay ? $payment_delay : 'NULL').", ";
        $sql .= "'".$db->escape($gender)."', ".$user->id;
        $sql .= ")";
        
        $result = $db->query($sql);
        if ($result) {
            $id = $db->last_insert_id(MAIN_DB_PREFIX."flotte_customer");
            $db->commit();
            $action = 'view';
            setEventMessages($langs->trans("CustomerCreatedSuccessfully"), null, 'mesgs');
        } else {
            $db->rollback();
            $error++;
            $errors[] = $langs->trans("ErrorCreatingCustomer") . ": " . $db->lasterror();
        }
    } else {
        $db->rollback();
    }
}

if ($action == 'update') {
    $db->begin();
    
    // Get form data
    $ref = GETPOST('ref', 'alpha');
    $firstname = GETPOST('firstname', 'alpha');
    $lastname = GETPOST('lastname', 'alpha');
    $phone = GETPOST('phone', 'alpha');
    $email = GETPOST('email', 'alpha');
    $company_name = GETPOST('company_name', 'alpha');
    $tax_no = GETPOST('tax_no', 'alpha');
    $payment_delay = GETPOST('payment_delay', 'int');
    $gender = GETPOST('gender', 'alpha');
    
    if (empty($firstname)) {
        $error++;
        $errors[] = $langs->trans("ErrorFieldRequired", $langs->trans("FirstName"));
    }
    if (empty($lastname)) {
        $error++;
        $errors[] = $langs->trans("ErrorFieldRequired", $langs->trans("LastName"));
    }
    
    if (!$error) {
        $sql = "UPDATE ".MAIN_DB_PREFIX."flotte_customer SET ";
        $sql .= "ref = '".$db->escape($ref)."', ";
        $sql .= "firstname = '".$db->escape($firstname)."', ";
        $sql .= "lastname = '".$db->escape($lastname)."', ";
        $sql .= "phone = '".$db->escape($phone)."', ";
        $sql .= "email = '".$db->escape($email)."', ";
        $sql .= "company_name = '".$db->escape($company_name)."', ";
        $sql .= "tax_no = '".$db->escape($tax_no)."', ";
        $sql .= "payment_delay = ".($payment_delay ? $payment_delay : 'NULL').", ";
        $sql .= "gender = '".$db->escape($gender)."', ";
        $sql .= "fk_user_modif = ".$user->id." ";
        $sql .= "WHERE rowid = ".((int) $id);
        
        $result = $db->query($sql);
        if ($result) {
            $db->commit();
            $action = 'view';
            setEventMessages($langs->trans("CustomerUpdatedSuccessfully"), null, 'mesgs');
        } else {
            $db->rollback();
            $error++;
            $errors[] = $langs->trans("ErrorUpdatingCustomer") . ": " . $db->lasterror();
        }
    } else {
        $db->rollback();
    }
}

// Load object data
if ($id > 0) {
    $sql = "SELECT * FROM ".MAIN_DB_PREFIX."flotte_customer WHERE rowid = ".((int) $id);
    $resql = $db->query($sql);
    if ($resql) {
        $object = $db->fetch_object($resql);
        if (!$object) {
            print $langs->trans("CustomerNotFound");
            exit;
        }
    } else {
        print $langs->trans("ErrorLoadingCustomer");
        exit;
    }
}

$form = new Form($db);

// Initialize technical object to manage hooks. Note that conf->hooks_modules contains array of hooks
$hookmanager->initHooks(array('customercard'));

/*
 * View
 */

$title = $langs->trans('Customer');
if ($action == 'create') {
    $title = $langs->trans('NewCustomer');
} elseif ($action == 'edit') {
    $title = $langs->trans('EditCustomer');
} elseif ($id > 0) {
    $title = $langs->trans('Customer') . " " . $object->ref;
}

llxHeader('', $title);

// Subheader
$linkback = '<a href="' . DOL_URL_ROOT . '/flotte/customer_list.php">' . $langs->trans('BackToList') . '</a>';

$h = 0;
$head = array();
$head[$h][0] = $_SERVER["PHP_SELF"] . '?id=' . $id;
$head[$h][1] = $langs->trans('Card');
$head[$h][2] = 'card';
$h++;

dol_fiche_head($head, 'card', $langs->trans('Customer'), -1, 'user');

// Confirmation to delete
if ($action == 'delete') {
    $formconfirm = $form->formconfirm(
        $_SERVER["PHP_SELF"] . '?id=' . $id,
        $langs->trans('DeleteCustomer'),
        $langs->trans('ConfirmDeleteCustomer'),
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
print load_fiche_titre($langs->trans('CustomerInformation'), '', '');
print '<table class="border tableforfield" width="100%">';

// Reference
print '<tr><td class="titlefield">' . $langs->trans('Reference') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    print $form->textwithpicto('<input type="text" class="flat" name="ref" value="' . (isset($object->ref) ? $object->ref : '') . '" size="20" readonly>', $langs->trans('AutoGenerated'));
} else {
    print $object->ref;
}
print '</td></tr>';

// First Name
print '<tr><td>' . $langs->trans('FirstName') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    print '<input type="text" class="flat" name="firstname" value="' . (isset($object->firstname) ? $object->firstname : '') . '" size="20" required>';
} else {
    print $object->firstname;
}
print '</td></tr>';

// Last Name
print '<tr><td>' . $langs->trans('LastName') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    print '<input type="text" class="flat" name="lastname" value="' . (isset($object->lastname) ? $object->lastname : '') . '" size="20" required>';
} else {
    print $object->lastname;
}
print '</td></tr>';

// Phone
print '<tr><td>' . $langs->trans('Phone') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    print '<input type="text" class="flat" name="phone" value="' . (isset($object->phone) ? $object->phone : '') . '" size="20">';
} else {
    print $object->phone;
}
print '</td></tr>';

// Email
print '<tr><td>' . $langs->trans('Email') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    print '<input type="email" class="flat" name="email" value="' . (isset($object->email) ? $object->email : '') . '" size="20">';
} else {
    print $object->email;
}
print '</td></tr>';

print '</table>';

print '</div>';
print '<div class="fichehalfright">';

// Additional Information
print load_fiche_titre($langs->trans('AdditionalInformation'), '', '');
print '<table class="border tableforfield" width="100%">';

// Company Name
print '<tr><td class="titlefield">' . $langs->trans('CompanyName') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    print '<input type="text" class="flat" name="company_name" value="' . (isset($object->company_name) ? $object->company_name : '') . '" size="20">';
} else {
    print $object->company_name;
}
print '</td></tr>';

// Tax Number
print '<tr><td>' . $langs->trans('TaxNumber') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    print '<input type="text" class="flat" name="tax_no" value="' . (isset($object->tax_no) ? $object->tax_no : '') . '" size="20">';
} else {
    print $object->tax_no;
}
print '</td></tr>';

// Payment Delay
print '<tr><td>' . $langs->trans('PaymentDelay') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    print '<input type="number" class="flat" name="payment_delay" value="' . (isset($object->payment_delay) ? $object->payment_delay : '') . '" min="0"> ' . $langs->trans('Days');
} else {
    print ($object->payment_delay ? $object->payment_delay . ' ' . $langs->trans('Days') : '');
}
print '</td></tr>';

// Gender - REMOVED "SelectGender" option
print '<tr><td>' . $langs->trans('Gender') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    $gender_options = array(
        'male' => $langs->trans('Male'),
        'female' => $langs->trans('Female'),
        'other' => $langs->trans('Other')
    );
    print $form->selectarray('gender', $gender_options, (isset($object->gender) ? $object->gender : ''), 1);
} else {
    print $object->gender ? $langs->trans(ucfirst($object->gender)) : '';
}
print '</td></tr>';

print '</table>';

print '</div>';
print '</div>';

print '<div class="clearboth"></div>';

// Add button styling CSS (same as driver card)
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

// Form buttons - Updated to match driver card style
if ($action == 'create' || $action == 'edit') {
    print '<div class="center" style="margin-top: 20px; margin-bottom: 10px;">';
    print '<input type="submit" class="flotte-btn" value="' . ($action == 'create' ? $langs->trans('Create') : $langs->trans('Save')) . '">';
    print '<a class="flotte-btn flotte-btn-cancel" href="' . ($id > 0 ? $_SERVER['PHP_SELF'] . '?id=' . $id : 'customer_list.php') . '">' . $langs->trans('Cancel') . '</a>';
    print '<a class="flotte-btn flotte-btn-back" href="' . dol_buildpath('/flotte/customer_list.php', 1) . '">' . $langs->trans('BackToList') . '</a>';
    print '</div>';
    print '</form>';
} elseif ($id > 0) {
    // Action buttons - Updated to match driver card style
    print '<div class="center" style="margin-top: 20px; margin-bottom: 10px;">';
    print '<a class="flotte-btn flotte-btn-primary" href="' . $_SERVER['PHP_SELF'] . '?id=' . $id . '&action=edit">' . $langs->trans('Modify') . '</a>';
    if ($user->rights->flotte->delete) {
        print '<a class="flotte-btn flotte-btn-delete" href="' . $_SERVER['PHP_SELF'] . '?id=' . $id . '&action=delete">' . $langs->trans('Delete') . '</a>';
    }
    print '<a class="flotte-btn flotte-btn-back" href="' . dol_buildpath('/flotte/customer_list.php', 1) . '">' . $langs->trans('BackToList') . '</a>';
    print '</div>';
}

dol_fiche_end();

// End of page
llxFooter();
$db->close();
?>
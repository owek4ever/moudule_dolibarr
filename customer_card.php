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
button.dc-btn-primary {
    background: #3c4758 !important; color: #fff !important; border: none !important;
}
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
.dc-page select:focus {
    border-color: #3c4758 !important;
    box-shadow: 0 0 0 3px rgba(60,71,88,0.1) !important;
    background: #fff !important;
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

$isEdit   = ($action == 'edit');
$isCreate = ($action == 'create');
$isView   = (!$isEdit && !$isCreate);

$pageTitle = $isCreate ? $langs->trans('NewCustomer') : ($isEdit ? $langs->trans('EditCustomer') : $langs->trans('Customer'));
$pageSub   = $isCreate ? $langs->trans('FillInCustomerDetails') : (isset($object->ref) ? $object->ref : '');

// Form start
if ($isCreate || $isEdit) {
    print '<form method="POST" action="'.$_SERVER['PHP_SELF'].($id > 0 ? '?id='.$id : '').'">';
    print '<input type="hidden" name="action" value="'.($isCreate ? 'add' : 'update').'">';
    print '<input type="hidden" name="token" value="'.newToken().'">';
}

print '<div class="dc-page">';

/* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
   PAGE HEADER
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */
print '<div class="dc-header">';
print '  <div class="dc-header-left">';
print '    <div class="dc-header-icon"><i class="fa fa-user-circle"></i></div>';
print '    <div>';
print '      <div class="dc-header-title">'.dol_escape_htmltag($pageTitle).'</div>';
if ($pageSub) print '      <div class="dc-header-sub">'.dol_escape_htmltag($pageSub).'</div>';
print '    </div>';
print '  </div>';
print '  <div class="dc-header-actions">';
if ($isView && $id > 0) {
    print '<a class="dc-btn dc-btn-ghost" href="'.dol_buildpath('/flotte/customer_list.php', 1).'"><i class="fa fa-arrow-left"></i> '.$langs->trans('BackToList').'</a>';
    print '<a class="dc-btn dc-btn-ghost" href="'.$_SERVER['PHP_SELF'].'?id='.$id.'&action=edit"><i class="fa fa-pen"></i> '.$langs->trans('Modify').'</a>';
    if ($user->rights->flotte->delete) {
        print '<a class="dc-btn dc-btn-danger" href="'.$_SERVER['PHP_SELF'].'?id='.$id.'&action=delete"><i class="fa fa-trash"></i> '.$langs->trans('Delete').'</a>';
    }
}
print '  </div>';
print '</div>';

/* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
   ROW 1 — Customer Info + Additional Info
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */
print '<div class="dc-grid">';

/* ── Card: Customer Information ── */
print '<div class="dc-card">';
print '  <div class="dc-card-header">';
print '    <div class="dc-card-header-icon blue"><i class="fa fa-id-card"></i></div>';
print '    <span class="dc-card-title">'.$langs->trans('CustomerInformation').'</span>';
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

// First Name
print '  <div class="dc-field">';
print '    <div class="dc-field-label required">'.$langs->trans('FirstName').'</div>';
print '    <div class="dc-field-value">';
if ($isCreate || $isEdit) print '<input type="text" name="firstname" value="'.(isset($object->firstname) ? dol_escape_htmltag($object->firstname) : '').'" required>';
else print dol_escape_htmltag($object->firstname);
print '    </div></div>';

// Last Name
print '  <div class="dc-field">';
print '    <div class="dc-field-label required">'.$langs->trans('LastName').'</div>';
print '    <div class="dc-field-value">';
if ($isCreate || $isEdit) print '<input type="text" name="lastname" value="'.(isset($object->lastname) ? dol_escape_htmltag($object->lastname) : '').'" required>';
else print dol_escape_htmltag($object->lastname);
print '    </div></div>';

// Phone
print '  <div class="dc-field">';
print '    <div class="dc-field-label">'.$langs->trans('Phone').'</div>';
print '    <div class="dc-field-value">';
if ($isCreate || $isEdit) print '<input type="text" name="phone" value="'.(isset($object->phone) ? dol_escape_htmltag($object->phone) : '').'">';
else print (!empty($object->phone) ? dol_print_phone($object->phone) : '&mdash;');
print '    </div></div>';

// Email
print '  <div class="dc-field">';
print '    <div class="dc-field-label">'.$langs->trans('Email').'</div>';
print '    <div class="dc-field-value">';
if ($isCreate || $isEdit) print '<input type="email" name="email" value="'.(isset($object->email) ? dol_escape_htmltag($object->email) : '').'">';
else print (!empty($object->email) ? dol_print_email($object->email) : '&mdash;');
print '    </div></div>';

print '  </div>';// card-body
print '</div>';  // dc-card

/* ── Card: Additional Information ── */
print '<div class="dc-card">';
print '  <div class="dc-card-header">';
print '    <div class="dc-card-header-icon green"><i class="fa fa-building"></i></div>';
print '    <span class="dc-card-title">'.$langs->trans('AdditionalInformation').'</span>';
print '  </div>';
print '  <div class="dc-card-body">';

// Company Name
print '  <div class="dc-field">';
print '    <div class="dc-field-label">'.$langs->trans('CompanyName').'</div>';
print '    <div class="dc-field-value">';
if ($isCreate || $isEdit) print '<input type="text" name="company_name" value="'.(isset($object->company_name) ? dol_escape_htmltag($object->company_name) : '').'">';
else print (!empty($object->company_name) ? dol_escape_htmltag($object->company_name) : '&mdash;');
print '    </div></div>';

// Tax Number
print '  <div class="dc-field">';
print '    <div class="dc-field-label">'.$langs->trans('TaxNumber').'</div>';
print '    <div class="dc-field-value">';
if ($isCreate || $isEdit) print '<input type="text" name="tax_no" value="'.(isset($object->tax_no) ? dol_escape_htmltag($object->tax_no) : '').'">';
else print (!empty($object->tax_no) ? '<span class="dc-mono">'.dol_escape_htmltag($object->tax_no).'</span>' : '&mdash;');
print '    </div></div>';

// Payment Delay
print '  <div class="dc-field">';
print '    <div class="dc-field-label">'.$langs->trans('PaymentDelay').'</div>';
print '    <div class="dc-field-value">';
if ($isCreate || $isEdit) print '<input type="number" name="payment_delay" value="'.(isset($object->payment_delay) ? dol_escape_htmltag($object->payment_delay) : '').'" min="0" placeholder="0">';
else print (!empty($object->payment_delay) ? dol_escape_htmltag($object->payment_delay).' '.$langs->trans('Days') : '&mdash;');
print '    </div></div>';

// Gender
print '  <div class="dc-field">';
print '    <div class="dc-field-label">'.$langs->trans('Gender').'</div>';
print '    <div class="dc-field-value">';
if ($isCreate || $isEdit) {
    $gender_options = array(
        'male'   => $langs->trans('Male'),
        'female' => $langs->trans('Female'),
        'other'  => $langs->trans('Other')
    );
    print $form->selectarray('gender', $gender_options, (isset($object->gender) ? $object->gender : ''), 1);
} else {
    print (!empty($object->gender) ? dol_escape_htmltag($langs->trans(ucfirst($object->gender))) : '&mdash;');
}
print '    </div></div>';

print '  </div>';// card-body
print '</div>';  // dc-card

print '</div>';// dc-grid

/* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
   BOTTOM ACTION BAR
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */
if ($isCreate || $isEdit) {
    print '<div class="dc-action-bar">';
    print '<a class="dc-btn dc-btn-ghost dc-action-bar-left" href="'.dol_buildpath('/flotte/customer_list.php', 1).'"><i class="fa fa-arrow-left"></i> '.$langs->trans('BackToList').'</a>';
    print '<a class="dc-btn dc-btn-ghost" href="'.($id > 0 ? $_SERVER['PHP_SELF'].'?id='.$id : dol_buildpath('/flotte/customer_list.php', 1)).'"><i class="fa fa-times"></i> '.$langs->trans('Cancel').'</a>';
    print '<button type="submit" class="dc-btn dc-btn-primary"><i class="fa fa-check"></i> '.($isCreate ? $langs->trans('Create') : $langs->trans('Save')).'</button>';
    print '</div>';
    print '</form>';
} elseif ($id > 0) {
    print '<div class="dc-action-bar">';
    print '<a class="dc-btn dc-btn-ghost dc-action-bar-left" href="'.dol_buildpath('/flotte/customer_list.php', 1).'"><i class="fa fa-arrow-left"></i> '.$langs->trans('BackToList').'</a>';
    print '<a class="dc-btn dc-btn-ghost" href="'.$_SERVER['PHP_SELF'].'?id='.$id.'&action=edit"><i class="fa fa-pen"></i> '.$langs->trans('Modify').'</a>';
    if ($user->rights->flotte->delete) {
        print '<a class="dc-btn dc-btn-danger" href="'.$_SERVER['PHP_SELF'].'?id='.$id.'&action=delete"><i class="fa fa-trash"></i> '.$langs->trans('Delete').'</a>';
    }
    print '</div>';
}

print '</div>';// dc-page

// End of page
llxFooter();
$db->close();
?>
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

// Function to generate next vendor reference
function getNextVendorRef($db, $entity) {
    $prefix = "VEND-";
    
    // Get the last reference from database
    $sql = "SELECT ref FROM ".MAIN_DB_PREFIX."flotte_vendor";
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
    
    // Format with leading zeros (e.g., VEND-0001)
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
$object->name = '';
$object->phone = '';
$object->email = '';
$object->type = '';
$object->website = '';
$object->note = '';
$object->address1 = '';
$object->address2 = '';
$object->city = '';
$object->state = '';
$object->picture = '';

$error = 0;
$errors = array();

// Generate reference for new vendor
if ($action == 'create' && empty($object->ref)) {
    $object->ref = getNextVendorRef($db, $conf->entity);
}

/*
 * Actions
 */

if ($cancel) {
    $action = 'view';
}

if ($action == 'confirm_delete' && $confirm == 'yes' && $user->rights->flotte->delete) {
    $db->begin();
    
    $sql = "DELETE FROM ".MAIN_DB_PREFIX."flotte_vendor WHERE rowid = ".((int) $id);
    $result = $db->query($sql);
    
    if ($result) {
        $db->commit();
        header("Location: " . dol_buildpath('/flotte/vendor_list.php', 1));
        exit;
    } else {
        $db->rollback();
        $error++;
        setEventMessages("Error deleting vendor", null, 'errors');
    }
}

if ($action == 'add') {
    $db->begin();
    
    // Get form data
    $ref = GETPOST('ref', 'alpha');
    $name = GETPOST('name', 'alpha');
    $phone = GETPOST('phone', 'alpha');
    $email = GETPOST('email', 'alpha');
    $type = GETPOST('type', 'alpha');
    $website = GETPOST('website', 'alpha');
    $note = GETPOST('note', 'alpha');
    $address1 = GETPOST('address1', 'alpha');
    $address2 = GETPOST('address2', 'alpha');
    $city = GETPOST('city', 'alpha');
    $state = GETPOST('state', 'alpha');
    
    // Auto-generate reference if empty
    if (empty($ref)) {
        $ref = getNextVendorRef($db, $conf->entity);
    }
    
    if (empty($name)) {
        $error++;
        $errors[] = "Vendor name is required";
    }
    
    if (!$error) {
        $sql = "INSERT INTO ".MAIN_DB_PREFIX."flotte_vendor (";
        $sql .= "ref, entity, name, phone, email, type, website, note, address1, address2, city, state, fk_user_author";
        $sql .= ") VALUES (";
        $sql .= "'".$db->escape($ref)."', ".$conf->entity.", ";
        $sql .= "'".$db->escape($name)."', ";
        $sql .= "'".$db->escape($phone)."', ";
        $sql .= "'".$db->escape($email)."', ";
        $sql .= "'".$db->escape($type)."', ";
        $sql .= "'".$db->escape($website)."', ";
        $sql .= "'".$db->escape($note)."', ";
        $sql .= "'".$db->escape($address1)."', ";
        $sql .= "'".$db->escape($address2)."', ";
        $sql .= "'".$db->escape($city)."', ";
        $sql .= "'".$db->escape($state)."', ";
        $sql .= $user->id;
        $sql .= ")";
        
        $result = $db->query($sql);
        if ($result) {
            $id = $db->last_insert_id(MAIN_DB_PREFIX."flotte_vendor");
            $db->commit();
            $action = 'view';
            setEventMessages("Vendor created successfully", null, 'mesgs');
        } else {
            $db->rollback();
            $error++;
            $errors[] = "Error creating vendor: " . $db->lasterror();
        }
    } else {
        $db->rollback();
    }
}

if ($action == 'update') {
    $db->begin();
    
    // Get form data
    $ref = GETPOST('ref', 'alpha');
    $name = GETPOST('name', 'alpha');
    $phone = GETPOST('phone', 'alpha');
    $email = GETPOST('email', 'alpha');
    $type = GETPOST('type', 'alpha');
    $website = GETPOST('website', 'alpha');
    $note = GETPOST('note', 'alpha');
    $address1 = GETPOST('address1', 'alpha');
    $address2 = GETPOST('address2', 'alpha');
    $city = GETPOST('city', 'alpha');
    $state = GETPOST('state', 'alpha');
    
    if (empty($name)) {
        $error++;
        $errors[] = "Vendor name is required";
    }
    
    if (!$error) {
        $sql = "UPDATE ".MAIN_DB_PREFIX."flotte_vendor SET ";
        $sql .= "ref = '".$db->escape($ref)."', ";
        $sql .= "name = '".$db->escape($name)."', ";
        $sql .= "phone = '".$db->escape($phone)."', ";
        $sql .= "email = '".$db->escape($email)."', ";
        $sql .= "type = '".$db->escape($type)."', ";
        $sql .= "website = '".$db->escape($website)."', ";
        $sql .= "note = '".$db->escape($note)."', ";
        $sql .= "address1 = '".$db->escape($address1)."', ";
        $sql .= "address2 = '".$db->escape($address2)."', ";
        $sql .= "city = '".$db->escape($city)."', ";
        $sql .= "state = '".$db->escape($state)."', ";
        $sql .= "fk_user_modif = ".$user->id." ";
        $sql .= "WHERE rowid = ".((int) $id);
        
        $result = $db->query($sql);
        if ($result) {
            $db->commit();
            $action = 'view';
            setEventMessages("Vendor updated successfully", null, 'mesgs');
        } else {
            $db->rollback();
            $error++;
            $errors[] = "Error updating vendor: " . $db->lasterror();
        }
    } else {
        $db->rollback();
    }
}

// Load object data
if ($id > 0) {
    $sql = "SELECT * FROM ".MAIN_DB_PREFIX."flotte_vendor WHERE rowid = ".((int) $id);
    $resql = $db->query($sql);
    if ($resql) {
        $object = $db->fetch_object($resql);
        if (!$object) {
            print "Vendor not found";
            exit;
        }
    } else {
        print "Error loading vendor";
        exit;
    }
} else {
    // Set default values for new vendor
    $object->ref = getNextVendorRef($db, $conf->entity);
}

$form = new Form($db);

// Initialize technical object to manage hooks
$hookmanager->initHooks(array('vendorcard'));

/*
 * View
 */

$title = $langs->trans('Vendor');
if ($action == 'create') {
    $title = $langs->trans('NewVendor');
} elseif ($action == 'edit') {
    $title = $langs->trans('EditVendor');
} elseif ($id > 0) {
    $title = $langs->trans('Vendor') . " " . $object->ref;
}

llxHeader('', $title);

// Subheader
$linkback = '<a href="' . DOL_URL_ROOT . '/flotte/vendor_list.php">' . $langs->trans('BackToList') . '</a>';

$h = 0;
$head = array();
$head[$h][0] = $_SERVER["PHP_SELF"] . '?id=' . $id;
$head[$h][1] = $langs->trans('Card');
$head[$h][2] = 'card';
$h++;

dol_fiche_head($head, 'card', $langs->trans('Vendor'), -1, 'company');

// Confirmation to delete
if ($action == 'delete') {
    $formconfirm = $form->formconfirm(
        $_SERVER["PHP_SELF"] . '?id=' . $id,
        $langs->trans('DeleteVendor'),
        $langs->trans('ConfirmDeleteVendor'),
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
print load_fiche_titre($langs->trans('VendorInformation'), '', '');
print '<table class="border tableforfield" width="100%">';

// Reference
print '<tr><td class="titlefield">' . $langs->trans('Reference') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    print $form->textwithpicto('<input type="text" class="flat" name="ref" value="' . dol_escape_htmltag($object->ref) . '" size="20" readonly>', $langs->trans('AutoGenerated'));
} else {
    print dol_escape_htmltag($object->ref);
}
print '</td></tr>';

// Name
print '<tr><td>' . $langs->trans('Name') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    print '<input type="text" class="flat" name="name" value="' . dol_escape_htmltag($object->name) . '" size="40">';
} else {
    print dol_escape_htmltag($object->name);
}
print '</td></tr>';

// Phone
print '<tr><td>' . $langs->trans('Phone') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    print '<input type="text" class="flat" name="phone" value="' . dol_escape_htmltag($object->phone) . '" size="20">';
} else {
    print dol_escape_htmltag($object->phone);
}
print '</td></tr>';

// Email
print '<tr><td>' . $langs->trans('Email') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    print '<input type="text" class="flat" name="email" value="' . dol_escape_htmltag($object->email) . '" size="40">';
} else {
    print dol_escape_htmltag($object->email);
}
print '</td></tr>';

// Type
print '<tr><td>' . $langs->trans('Type') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    $typearray = array(
        '' => $langs->trans('SelectType'),
        'Parts' => $langs->trans('Parts'),
        'Fuel' => $langs->trans('Fuel'),
        'Maintenance' => $langs->trans('Maintenance'),
        'Insurance' => $langs->trans('Insurance'),
        'Service' => $langs->trans('Service'),
        'Other' => $langs->trans('Other')
    );
    print $form->selectarray('type', $typearray, $object->type, 0, 0, 0, '', 0, 0, 0, '', 'minwidth200');
} else {
    print dol_escape_htmltag($object->type);
}
print '</td></tr>';

// Website
print '<tr><td>' . $langs->trans('Website') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    print '<input type="text" class="flat" name="website" value="' . dol_escape_htmltag($object->website) . '" size="40">';
} else {
    if (!empty($object->website)) {
        print '<a href="' . (strpos($object->website, 'http') === 0 ? $object->website : 'http://' . $object->website) . '" target="_blank">' . dol_escape_htmltag($object->website) . '</a>';
    } else {
        print '&nbsp;';
    }
}
print '</td></tr>';

print '</table>';

print '</div>';
print '<div class="fichehalfright">';

// Address Information
print load_fiche_titre($langs->trans('AddressInformation'), '', '');
print '<table class="border tableforfield" width="100%">';

// Address Line 1
print '<tr><td class="titlefield">' . $langs->trans('AddressLine1') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    print '<input type="text" class="flat" name="address1" value="' . dol_escape_htmltag($object->address1) . '" size="40">';
} else {
    print dol_escape_htmltag($object->address1);
}
print '</td></tr>';

// Address Line 2
print '<tr><td>' . $langs->trans('AddressLine2') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    print '<input type="text" class="flat" name="address2" value="' . dol_escape_htmltag($object->address2) . '" size="40">';
} else {
    print dol_escape_htmltag($object->address2);
}
print '</td></tr>';

// City
print '<tr><td>' . $langs->trans('City') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    print '<input type="text" class="flat" name="city" value="' . dol_escape_htmltag($object->city) . '" size="20">';
} else {
    print dol_escape_htmltag($object->city);
}
print '</td></tr>';

// State
print '<tr><td>' . $langs->trans('State') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    print '<input type="text" class="flat" name="state" value="' . dol_escape_htmltag($object->state) . '" size="20">';
} else {
    print dol_escape_htmltag($object->state);
}
print '</td></tr>';

print '</table>';

print '</div>';
print '</div>';

print '<div class="clearboth"></div>';

// Notes
print load_fiche_titre($langs->trans('Notes'), '', '');
print '<table class="border tableforfield" width="100%">';
print '<tr><td>';
if ($action == 'create' || $action == 'edit') {
    print '<textarea name="note" class="flat" rows="4" cols="80">' . dol_escape_htmltag($object->note) . '</textarea>';
} else {
    print nl2br(dol_escape_htmltag($object->note));
}
print '</td></tr>';
print '</table>';

// Form buttons
if ($action == 'create' || $action == 'edit') {
    print '<div class="center">';
    print '<input type="submit" class="button" value="' . ($action == 'create' ? $langs->trans('Create') : $langs->trans('Save')) . '">';
    print '&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;';
    print '<a class="button button-cancel" href="' . ($id > 0 ? $_SERVER['PHP_SELF'] . '?id=' . $id : 'vendor_list.php') . '">' . $langs->trans('Cancel') . '</a>';
    print '</div>';
    print '</form>';
} elseif ($id > 0) {
    // Action buttons
    print '<div class="tabsAction">';
    print '<div class="inline-block divButAction">';
    if ($user->rights->flotte->write) {
        print '<a class="butAction" href="' . $_SERVER['PHP_SELF'] . '?id=' . $id . '&action=edit">' . $langs->trans('Modify') . '</a>';
    }
    if ($user->rights->flotte->delete) {
        print '<a class="butActionDelete" href="' . $_SERVER['PHP_SELF'] . '?id=' . $id . '&action=delete">' . $langs->trans('Delete') . '</a>';
    }
    print '</div>';
    print '</div>';
}

dol_fiche_end();

// End of page
llxFooter();
$db->close();
?>
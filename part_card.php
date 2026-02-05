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

// Function to generate next part reference
function getNextPartRef($db, $entity) {
    $prefix = "PART-";
    
    // Get the last reference from database
    $sql = "SELECT ref FROM ".MAIN_DB_PREFIX."flotte_part";
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
    
    // Format with leading zeros (e.g., PART-0001)
    return $prefix.str_pad($next_number, 4, '0', STR_PAD_LEFT);
}

// Get parameters
$id = GETPOST('id', 'int');
$action = GETPOST('action', 'aZ09') ? GETPOST('action', 'aZ09') : 'view';
$confirm = GETPOST('confirm', 'alpha');
$cancel = GETPOST('cancel', 'alpha');
$contextpage = GETPOST('contextpage','aZ')?GETPOST('contextpage','aZ'):'partcard';

// Security check
restrictedArea($user, 'flotte');

// Initialize variables
$object = new stdClass();
$object->id = 0;
$object->ref = '';
$object->barcode = '';
$object->title = '';
$object->number = '';
$object->description = '';
$object->status = '';
$object->availability = 1;
$object->fk_vendor = 0;
$object->fk_category = 0;
$object->manufacturer = '';
$object->year = '';
$object->model = '';
$object->qty_on_hand = 0;
$object->unit_cost = 0;
$object->note = '';
$object->picture = '';

$error = 0;
$errors = array();

// Generate reference for new part
if ($action == 'create' && empty($object->ref)) {
    $object->ref = getNextPartRef($db, $conf->entity);
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
    
    $sql = "DELETE FROM ".MAIN_DB_PREFIX."flotte_part WHERE rowid = ".((int) $id);
    $result = $db->query($sql);
    
    if ($result) {
        $db->commit();
        header("Location: " . dol_buildpath('/flotte/part_list.php', 1));
        exit;
    } else {
        $db->rollback();
        $error++;
        setEventMessages("Error deleting part", null, 'errors');
    }
}

if ($action == 'add') {
    $db->begin();
    
    // Get form data
    $ref = GETPOST('ref', 'alpha');
    $barcode = GETPOST('barcode', 'alpha');
    $title = GETPOST('title', 'alpha');
    $number = GETPOST('number', 'alpha');
    $description = GETPOST('description', 'alpha');
    $status = GETPOST('status', 'alpha');
    $availability = GETPOST('availability', 'int');
    $fk_vendor = GETPOST('fk_vendor', 'int');
    $fk_category = GETPOST('fk_category', 'int');
    $manufacturer = GETPOST('manufacturer', 'alpha');
    $year = GETPOST('year', 'int');
    $model = GETPOST('model', 'alpha');
    $qty_on_hand = GETPOST('qty_on_hand', 'int');
    $unit_cost = GETPOST('unit_cost', 'alpha');
    $note = GETPOST('note', 'alpha');
    
    // Auto-generate reference if empty
    if (empty($ref)) {
        $ref = getNextPartRef($db, $conf->entity);
    }
    
    if (empty($title)) {
        $error++;
        $errors[] = "Part title is required";
    }
    
    if (!$error) {
        $sql = "INSERT INTO ".MAIN_DB_PREFIX."flotte_part (";
        $sql .= "ref, entity, barcode, title, number, description, status, availability, ";
        $sql .= "fk_vendor, fk_category, manufacturer, year, model, qty_on_hand, unit_cost, note, fk_user_author";
        $sql .= ") VALUES (";
        $sql .= "'".$db->escape($ref)."', ".$conf->entity.", ";
        $sql .= "'".$db->escape($barcode)."', ";
        $sql .= "'".$db->escape($title)."', ";
        $sql .= "'".$db->escape($number)."', ";
        $sql .= "'".$db->escape($description)."', ";
        $sql .= "'".$db->escape($status)."', ";
        $sql .= $availability.", ";
        $sql .= ($fk_vendor > 0 ? $fk_vendor : "NULL").", ";
        $sql .= ($fk_category > 0 ? $fk_category : "NULL").", ";
        $sql .= "'".$db->escape($manufacturer)."', ";
        $sql .= ($year > 0 ? $year : "NULL").", ";
        $sql .= "'".$db->escape($model)."', ";
        $sql .= $qty_on_hand.", ";
        $sql .= ($unit_cost ? $unit_cost : "0").", ";
        $sql .= "'".$db->escape($note)."', ";
        $sql .= $user->id;
        $sql .= ")";
        
        $result = $db->query($sql);
        if ($result) {
            $id = $db->last_insert_id(MAIN_DB_PREFIX."flotte_part");
            $db->commit();
            $action = 'view';
            setEventMessages("Part created successfully", null, 'mesgs');
        } else {
            $db->rollback();
            $error++;
            $errors[] = "Error creating part: " . $db->lasterror();
        }
    } else {
        $db->rollback();
    }
}

if ($action == 'update') {
    $db->begin();
    
    // Get form data
    $ref = GETPOST('ref', 'alpha');
    $barcode = GETPOST('barcode', 'alpha');
    $title = GETPOST('title', 'alpha');
    $number = GETPOST('number', 'alpha');
    $description = GETPOST('description', 'alpha');
    $status = GETPOST('status', 'alpha');
    $availability = GETPOST('availability', 'int');
    $fk_vendor = GETPOST('fk_vendor', 'int');
    $fk_category = GETPOST('fk_category', 'int');
    $manufacturer = GETPOST('manufacturer', 'alpha');
    $year = GETPOST('year', 'int');
    $model = GETPOST('model', 'alpha');
    $qty_on_hand = GETPOST('qty_on_hand', 'int');
    $unit_cost = GETPOST('unit_cost', 'alpha');
    $note = GETPOST('note', 'alpha');
    
    if (empty($title)) {
        $error++;
        $errors[] = "Part title is required";
    }
    
    if (!$error) {
        $sql = "UPDATE ".MAIN_DB_PREFIX."flotte_part SET ";
        $sql .= "ref = '".$db->escape($ref)."', ";
        $sql .= "barcode = '".$db->escape($barcode)."', ";
        $sql .= "title = '".$db->escape($title)."', ";
        $sql .= "number = '".$db->escape($number)."', ";
        $sql .= "description = '".$db->escape($description)."', ";
        $sql .= "status = '".$db->escape($status)."', ";
        $sql .= "availability = ".$availability.", ";
        $sql .= "fk_vendor = ".($fk_vendor > 0 ? $fk_vendor : "NULL").", ";
        $sql .= "fk_category = ".($fk_category > 0 ? $fk_category : "NULL").", ";
        $sql .= "manufacturer = '".$db->escape($manufacturer)."', ";
        $sql .= "year = ".($year > 0 ? $year : "NULL").", ";
        $sql .= "model = '".$db->escape($model)."', ";
        $sql .= "qty_on_hand = ".$qty_on_hand.", ";
        $sql .= "unit_cost = ".($unit_cost ? $unit_cost : "0").", ";
        $sql .= "note = '".$db->escape($note)."', ";
        $sql .= "fk_user_modif = ".$user->id." ";
        $sql .= "WHERE rowid = ".((int) $id);
        
        $result = $db->query($sql);
        if ($result) {
            $db->commit();
            $action = 'view';
            setEventMessages("Part updated successfully", null, 'mesgs');
        } else {
            $db->rollback();
            $error++;
            $errors[] = "Error updating part: " . $db->lasterror();
        }
    } else {
        $db->rollback();
    }
}

// Load object data
if ($id > 0) {
    $sql = "SELECT p.*, v.name as vendor_name, c.category_name 
            FROM ".MAIN_DB_PREFIX."flotte_part as p 
            LEFT JOIN ".MAIN_DB_PREFIX."flotte_vendor as v ON p.fk_vendor = v.rowid 
            LEFT JOIN ".MAIN_DB_PREFIX."flotte_part_category as c ON p.fk_category = c.rowid 
            WHERE p.rowid = ".((int) $id);
    $resql = $db->query($sql);
    if ($resql) {
        $object = $db->fetch_object($resql);
        if (!$object) {
            print "Part not found";
            exit;
        }
    } else {
        print "Error loading part";
        exit;
    }
} else {
    // Set default values for new part
    $object->ref = getNextPartRef($db, $conf->entity);
}

// Get vendors and categories for dropdowns
$vendors = array();
$sql_vendors = "SELECT rowid, name FROM ".MAIN_DB_PREFIX."flotte_vendor WHERE entity = ".$conf->entity." ORDER BY name";
$resql_vendors = $db->query($sql_vendors);
if ($resql_vendors) {
    while ($obj = $db->fetch_object($resql_vendors)) {
        $vendors[$obj->rowid] = $obj->name;
    }
}

$categories = array();
$sql_categories = "SELECT rowid, category_name FROM ".MAIN_DB_PREFIX."flotte_part_category WHERE entity = ".$conf->entity." ORDER BY category_name";
$resql_categories = $db->query($sql_categories);
if ($resql_categories) {
    while ($obj = $db->fetch_object($resql_categories)) {
        $categories[$obj->rowid] = $obj->category_name;
    }
}

$form = new Form($db);

// Initialize technical object to manage hooks
$hookmanager->initHooks(array('partcard'));

/*
 * View
 */

$title = $langs->trans('Part');
if ($action == 'create') {
    $title = $langs->trans('NewPart');
} elseif ($action == 'edit') {
    $title = $langs->trans('EditPart');
} elseif ($id > 0) {
    $title = $langs->trans('Part') . " " . $object->ref;
}

llxHeader('', $title);

// Subheader
$linkback = '<a href="' . DOL_URL_ROOT . '/flotte/part_list.php">' . $langs->trans('BackToList') . '</a>';

$h = 0;
$head = array();
$head[$h][0] = $_SERVER["PHP_SELF"] . '?id=' . $id;
$head[$h][1] = $langs->trans('Card');
$head[$h][2] = 'card';
$h++;

dol_fiche_head($head, 'card', $langs->trans('Part'), -1, 'generic');

// Confirmation to delete
if ($action == 'delete') {
    $formconfirm = $form->formconfirm(
        $_SERVER["PHP_SELF"] . '?id=' . $id,
        $langs->trans('DeletePart'),
        $langs->trans('ConfirmDeletePart'),
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
print load_fiche_titre($langs->trans('PartInformation'), '', '');
print '<table class="border tableforfield" width="100%">';

// Reference
print '<tr><td class="titlefield">' . $langs->trans('Reference') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    print $form->textwithpicto('<input type="text" class="flat" name="ref" value="' . dol_escape_htmltag($object->ref) . '" size="20" readonly>', $langs->trans('AutoGenerated'));
} else {
    print dol_escape_htmltag($object->ref);
}
print '</td></tr>';

// Part Title
print '<tr><td>' . $langs->trans('PartTitle') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    print '<input type="text" class="flat" name="title" value="' . dol_escape_htmltag($object->title) . '" size="40">';
} else {
    print dol_escape_htmltag($object->title);
}
print '</td></tr>';

// Part Number
print '<tr><td>' . $langs->trans('PartNumber') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    print '<input type="text" class="flat" name="number" value="' . dol_escape_htmltag($object->number) . '" size="20">';
} else {
    print dol_escape_htmltag($object->number ?: '-');
}
print '</td></tr>';

// Barcode
print '<tr><td>' . $langs->trans('Barcode') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    print '<input type="text" class="flat" name="barcode" value="' . dol_escape_htmltag($object->barcode) . '" size="20">';
} else {
    print dol_escape_htmltag($object->barcode ?: '-');
}
print '</td></tr>';

// Manufacturer
print '<tr><td>' . $langs->trans('Manufacturer') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    print '<input type="text" class="flat" name="manufacturer" value="' . dol_escape_htmltag($object->manufacturer) . '" size="30">';
} else {
    print dol_escape_htmltag($object->manufacturer ?: '-');
}
print '</td></tr>';

// Model
print '<tr><td>' . $langs->trans('Model') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    print '<input type="text" class="flat" name="model" value="' . dol_escape_htmltag($object->model) . '" size="30">';
} else {
    print dol_escape_htmltag($object->model ?: '-');
}
print '</td></tr>';

// Year
print '<tr><td>' . $langs->trans('Year') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    print '<input type="number" class="flat" name="year" value="' . dol_escape_htmltag($object->year) . '" min="1900" max="2100">';
} else {
    print dol_escape_htmltag($object->year ?: '-');
}
print '</td></tr>';

// Quantity on Hand
print '<tr><td>' . $langs->trans('QuantityOnHand') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    print '<input type="number" class="flat" name="qty_on_hand" value="' . (int) $object->qty_on_hand . '" min="0">';
} else {
    $stock_class = 'stock-good';
    if ($object->qty_on_hand <= 0) {
        $stock_class = 'stock-low';
    } elseif ($object->qty_on_hand <= 5) {
        $stock_class = 'stock-medium';
    }
    print '<span class="' . $stock_class . '">' . (int) $object->qty_on_hand . '</span>';
}
print '</td></tr>';

print '</table>';

print '</div>';
print '<div class="fichehalfright">';

// Additional Information
print load_fiche_titre($langs->trans('AdditionalInformation'), '', '');
print '<table class="border tableforfield" width="100%">';

// Unit Cost
print '<tr><td class="titlefield">' . $langs->trans('UnitCost') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    print '<input type="number" class="flat" name="unit_cost" value="' . (float) $object->unit_cost . '" step="0.01" min="0">';
} else {
    print $object->unit_cost ? price($object->unit_cost) : '-';
}
print '</td></tr>';

// Status
print '<tr><td>' . $langs->trans('Status') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    $status_options = array(
        '' => $langs->trans('SelectStatus'),
        'Active' => $langs->trans('Active'),
        'Inactive' => $langs->trans('Inactive'),
        'Maintenance' => $langs->trans('Maintenance'),
        'Discontinued' => $langs->trans('Discontinued')
    );
    print $form->selectarray('status', $status_options, $object->status, 0);
} else {
    if ($object->status) {
        $status_class = 'status-' . strtolower($object->status);
        print '<span class="status-badge ' . $status_class . '">' . $object->status . '</span>';
    } else {
        print '-';
    }
}
print '</td></tr>';

// Availability
print '<tr><td>' . $langs->trans('Availability') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    $availability_options = array(
        '1' => $langs->trans('Available'),
        '0' => $langs->trans('NotAvailable')
    );
    print $form->selectarray('availability', $availability_options, $object->availability, 0);
} else {
    $availability_class = 'availability-' . ($object->availability ? '1' : '0');
    $availability_text = $object->availability ? $langs->trans('Available') : $langs->trans('NotAvailable');
    print '<span class="availability-badge ' . $availability_class . '">' . $availability_text . '</span>';
}
print '</td></tr>';


// Vendor
print '<tr><td>' . $langs->trans('Vendor') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    print $form->selectarray('fk_vendor', $vendors, $object->fk_vendor, 0, 0, 0, '', 0, 0, 0, '', 'minwidth200');
} else {
    print dol_escape_htmltag($object->vendor_name ?: '-');
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
    print '<a class="flotte-btn flotte-btn-cancel" href="' . ($id > 0 ? $_SERVER['PHP_SELF'] . '?id=' . $id : 'part_list.php') . '">' . $langs->trans('Cancel') . '</a>';
    print '<a class="flotte-btn flotte-btn-back" href="' . dol_buildpath('/flotte/part_list.php', 1) . '">' . $langs->trans('BackToList') . '</a>';
    print '</div>';
    print '</form>';
} elseif ($id > 0) {
    // Action buttons
    print '<div class="center" style="margin-top: 20px; margin-bottom: 10px;">';
    print '<a class="flotte-btn flotte-btn-primary" href="' . $_SERVER['PHP_SELF'] . '?id=' . $id . '&action=edit">' . $langs->trans('Modify') . '</a>';
    print '<a class="flotte-btn flotte-btn-delete" href="' . $_SERVER['PHP_SELF'] . '?id=' . $id . '&action=delete">' . $langs->trans('Delete') . '</a>';
    print '<a class="flotte-btn flotte-btn-back" href="' . dol_buildpath('/flotte/part_list.php', 1) . '">' . $langs->trans('BackToList') . '</a>';
    print '</div>';
}

dol_fiche_end();

// End of page
llxFooter();
$db->close();
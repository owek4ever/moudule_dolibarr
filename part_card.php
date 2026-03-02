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
    display: flex; align-items: center; justify-content: space-between;
    padding: 26px 0 22px; border-bottom: 1px solid #e8eaf0;
    margin-bottom: 28px; gap: 16px; flex-wrap: wrap;
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

/* ── Status badges ── */
.dc-badge {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 4px 11px; border-radius: 20px;
    font-size: 11.5px; font-weight: 600; white-space: nowrap;
}
.dc-badge::before { content: ''; width: 6px; height: 6px; border-radius: 50%; flex-shrink: 0; }
.dc-badge.active       { background: #edfaf3; color: #1a7d4a; }
.dc-badge.active::before       { background: #22c55e; }
.dc-badge.inactive     { background: #fef2f2; color: #b91c1c; }
.dc-badge.inactive::before     { background: #ef4444; }
.dc-badge.maintenance  { background: #fff8ec; color: #b45309; }
.dc-badge.maintenance::before  { background: #f59e0b; }
.dc-badge.discontinued { background: #f5f6fb; color: #8b92a9; }
.dc-badge.discontinued::before { background: #c4c9d8; }
.dc-badge.available    { background: #edfaf3; color: #1a7d4a; }
.dc-badge.available::before    { background: #22c55e; }
.dc-badge.notavailable { background: #fef2f2; color: #b91c1c; }
.dc-badge.notavailable::before { background: #ef4444; }

/* ── Stock indicators ── */
.dc-stock {
    display: inline-flex; align-items: center; gap: 6px;
    font-family: 'DM Mono', monospace; font-size: 13px; font-weight: 600;
    padding: 4px 10px; border-radius: 6px;
}
.dc-stock.good   { background: #edfaf3; color: #1a7d4a; }
.dc-stock.medium { background: #fff8ec; color: #b45309; }
.dc-stock.low    { background: #fef2f2; color: #b91c1c; }

/* ── Buttons ── */
.dc-btn {
    display: inline-flex; align-items: center; gap: 7px;
    padding: 8px 16px; border-radius: 6px; font-size: 13px; font-weight: 600;
    text-decoration: none !important; cursor: pointer;
    font-family: 'DM Sans', sans-serif; white-space: nowrap;
    transition: all 0.15s ease; border: none;
}
.dc-btn-primary { background: #3c4758 !important; color: #fff !important; }
.dc-btn-primary:hover { background: #2a3346 !important; color: #fff !important; }
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
button.dc-btn-primary { background: #3c4758 !important; color: #fff !important; border: none !important; }
button.dc-btn-primary:hover { background: #2a3346 !important; }

/* ── Two-column grid ── */
.dc-grid {
    display: grid; grid-template-columns: 1fr 1fr;
    gap: 20px; margin-bottom: 20px;
}

/* ── Section card ── */
.dc-card {
    background: #fff; border: 1px solid #e8eaf0;
    border-radius: 12px; overflow: hidden;
    box-shadow: 0 1px 6px rgba(0,0,0,0.04);
}
.dc-card-header {
    display: flex; align-items: center; gap: 10px;
    padding: 14px 20px; border-bottom: 1px solid #f0f2f8;
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
.dc-card-title { font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.7px; color: #8b92a9; }
.dc-card-body { padding: 0; }

/* ── Field rows ── */
.dc-field {
    display: flex; align-items: flex-start;
    padding: 12px 20px; border-bottom: 1px solid #f5f6fb; gap: 12px;
}
.dc-field:last-child { border-bottom: none; }
.dc-field-label {
    flex: 0 0 160px; font-size: 12px; font-weight: 600;
    color: #8b92a9; text-transform: uppercase; letter-spacing: 0.5px;
    padding-top: 2px; line-height: 1.4;
}
.dc-field-label.required::after { content: ' *'; color: #ef4444; }
.dc-field-value { flex: 1; font-size: 13.5px; color: #2d3748; line-height: 1.5; min-width: 0; }

/* ── Mono / chip ── */
.dc-mono {
    font-family: 'DM Mono', monospace; font-size: 12px;
    background: #f0f2fa; color: #4a5568;
    padding: 3px 9px; border-radius: 5px; display: inline-block;
}
.dc-ref-tag {
    font-family: 'DM Mono', monospace; font-size: 13px;
    background: rgba(60,71,88,0.08); color: #3c4758;
    padding: 4px 10px; border-radius: 6px; font-weight: 500;
}
.dc-price {
    font-family: 'DM Mono', monospace; font-size: 13px; font-weight: 500; color: #2d3748;
}

/* ── Form inputs ── */
.dc-page input[type="text"],
.dc-page input[type="number"],
.dc-page select,
.dc-page textarea {
    padding: 8px 12px !important; border: 1.5px solid #e2e5f0 !important;
    border-radius: 8px !important; font-size: 13px !important;
    font-family: 'DM Sans', sans-serif !important; color: #2d3748 !important;
    background: #fafbfe !important; outline: none !important;
    transition: border-color 0.15s, box-shadow 0.15s !important;
    width: 100% !important; max-width: 100% !important; box-sizing: border-box !important;
}
.dc-page input[type="text"]:focus,
.dc-page input[type="number"]:focus,
.dc-page select:focus,
.dc-page textarea:focus {
    border-color: #3c4758 !important;
    box-shadow: 0 0 0 3px rgba(60,71,88,0.1) !important;
    background: #fff !important;
}
.dc-page textarea { resize: vertical !important; }

/* ── Bottom action bar ── */
.dc-action-bar {
    display: flex; align-items: center; justify-content: flex-end;
    gap: 8px; padding: 18px 0 4px; flex-wrap: wrap;
}
.dc-action-bar-left { margin-right: auto; }

/* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
   RESPONSIVE STYLES
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */

/* ── 960px: tighten padding, shrink label column ── */
@media (max-width: 960px) {
    .dc-page { padding: 0 12px 40px; }
    .dc-header { padding: 18px 0 16px; margin-bottom: 20px; }
    .dc-header-title { font-size: 18px; }
    .dc-field-label { flex: 0 0 130px; }
}

/* ── 780px: stack grid, header, fields ── */
@media (max-width: 780px) {
    .dc-page { padding: 0 10px 32px; }

    /* Grid collapses to single column */
    .dc-grid { grid-template-columns: 1fr; gap: 14px; margin-bottom: 14px; }

    /* Header stacks vertically */
    .dc-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 12px;
        padding: 16px 0 14px;
        margin-bottom: 16px;
    }
    .dc-header-actions { width: 100%; justify-content: flex-start; }

    /* Field rows: label stacks above value */
    .dc-field {
        flex-direction: column;
        gap: 4px;
        padding: 10px 16px;
    }
    .dc-field-label { flex: none; width: 100%; padding-top: 0; }
    .dc-field-value { width: 100%; }

    /* Action bar */
    .dc-action-bar { flex-wrap: wrap; gap: 8px; padding: 14px 0 4px; }
    .dc-action-bar-left { width: 100%; margin-right: 0; }
    .dc-action-bar .dc-btn { flex: 1 1 auto; justify-content: center; min-width: 120px; }
}

/* ── 480px: small phones ── */
@media (max-width: 480px) {
    .dc-page { padding: 0 6px 24px; }

    .dc-header-title { font-size: 16px; }
    .dc-header-sub { font-size: 11.5px; }
    .dc-header-icon { width: 38px; height: 38px; font-size: 16px; border-radius: 10px; }
    .dc-header-left { gap: 10px; }

    .dc-card { border-radius: 10px; }
    .dc-card-header { padding: 11px 14px; }
    .dc-field { padding: 9px 14px; }

    /* Larger touch targets for inputs */
    .dc-page input[type="text"],
    .dc-page input[type="number"],
    .dc-page select { font-size: 14px !important; }

    /* Action bar buttons go full width */
    .dc-action-bar .dc-btn { flex: 1 1 100%; }

    /* Header action buttons shrink gracefully */
    .dc-header-actions .dc-btn { font-size: 12px; padding: 6px 10px; }
}
</style>
<?php

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

$isEdit   = ($action == 'edit');
$isCreate = ($action == 'create');
$isView   = (!$isEdit && !$isCreate);

$pageTitle = $isCreate ? $langs->trans('NewPart') : ($isEdit ? $langs->trans('EditPart') : $langs->trans('Part'));
$pageSub   = $isCreate ? '' : (isset($object->ref) ? $object->ref : '');

// Stock level helper
$qty        = isset($object->qty_on_hand) ? (int)$object->qty_on_hand : 0;
$stockClass = $qty <= 0 ? 'low' : ($qty <= 5 ? 'medium' : 'good');
$stockIcon  = $qty <= 0 ? 'fa-exclamation-circle' : ($qty <= 5 ? 'fa-exclamation-triangle' : 'fa-check-circle');

// Form start
if ($isCreate || $isEdit) {
    print '<form method="POST" action="'.$_SERVER['PHP_SELF'].($id > 0 ? '?id='.$id : '').'">';
    print '<input type="hidden" name="action" value="'.($isCreate ? 'add' : 'update').'">';
    print '<input type="hidden" name="token" value="'.newToken().'">';
    if ($id > 0) print '<input type="hidden" name="id" value="'.$id.'">';
}

print '<div class="dc-page">';

/* ── PAGE HEADER ── */
print '<div class="dc-header">';
print '  <div class="dc-header-left">';
print '    <div class="dc-header-icon"><i class="fa fa-puzzle-piece"></i></div>';
print '    <div>';
print '      <div class="dc-header-title">'.dol_escape_htmltag($pageTitle).'</div>';
if ($pageSub) print '      <div class="dc-header-sub">'.dol_escape_htmltag($pageSub).'</div>';
print '    </div>';
print '  </div>';
print '  <div class="dc-header-actions">';
if ($isView && $id > 0) {
    if (!empty($object->status)) {
        $stClass = strtolower($object->status);
        print '<span class="dc-badge '.$stClass.'">'.dol_escape_htmltag($object->status).'</span>';
    }
    $availText  = $object->availability ? $langs->trans('Available') : $langs->trans('NotAvailable');
    $availClass = $object->availability ? 'available' : 'notavailable';
    print '<span class="dc-badge '.$availClass.'">'.dol_escape_htmltag($availText).'</span>';
    print '<a class="dc-btn dc-btn-ghost" href="'.dol_buildpath('/flotte/part_list.php', 1).'"><i class="fa fa-arrow-left"></i> '.$langs->trans('BackToList').'</a>';
    print '<a class="dc-btn dc-btn-ghost" href="'.$_SERVER['PHP_SELF'].'?id='.$id.'&action=edit"><i class="fa fa-pen"></i> '.$langs->trans('Modify').'</a>';
    print '<a class="dc-btn dc-btn-danger" href="'.$_SERVER['PHP_SELF'].'?id='.$id.'&action=delete"><i class="fa fa-trash"></i> '.$langs->trans('Delete').'</a>';
}
print '  </div>';
print '</div>';

/* ── ROW 1: Part Information + Inventory & Sourcing ── */
print '<div class="dc-grid">';

/* Card: Part Information */
print '<div class="dc-card">';
print '  <div class="dc-card-header">';
print '    <div class="dc-card-header-icon blue"><i class="fa fa-puzzle-piece"></i></div>';
print '    <span class="dc-card-title">'.$langs->trans('PartInformation').'</span>';
print '  </div>';
print '  <div class="dc-card-body">';

// Reference
print '  <div class="dc-field">';
print '    <div class="dc-field-label">'.$langs->trans('Reference').'</div>';
print '    <div class="dc-field-value">';
if ($isCreate) {
    print '<em style="color:#9aa0b4;font-size:12.5px;">'.$langs->trans('AutoGenerated').'</em>';
    print '<input type="hidden" name="ref" value="'.dol_escape_htmltag($object->ref).'">';
} elseif ($isEdit) {
    print '<input type="text" name="ref" value="'.dol_escape_htmltag($object->ref).'" readonly style="background:#f5f6fa!important;color:#9aa0b4!important;">';
} else {
    print '<span class="dc-ref-tag">'.dol_escape_htmltag($object->ref).'</span>';
}
print '    </div></div>';

// Part Title
print '  <div class="dc-field">';
print '    <div class="dc-field-label required">'.$langs->trans('PartTitle').'</div>';
print '    <div class="dc-field-value">';
if ($isCreate || $isEdit) {
    print '<input type="text" name="title" value="'.dol_escape_htmltag($object->title).'" required>';
} else {
    print '<strong style="font-size:14px;">'.dol_escape_htmltag($object->title).'</strong>';
}
print '    </div></div>';

// Part Number
print '  <div class="dc-field">';
print '    <div class="dc-field-label">'.$langs->trans('PartNumber').'</div>';
print '    <div class="dc-field-value">';
if ($isCreate || $isEdit) {
    print '<input type="text" name="number" value="'.dol_escape_htmltag($object->number).'">';
} else {
    print (!empty($object->number) ? '<span class="dc-mono">'.dol_escape_htmltag($object->number).'</span>' : '&mdash;');
}
print '    </div></div>';

// Barcode
print '  <div class="dc-field">';
print '    <div class="dc-field-label">'.$langs->trans('Barcode').'</div>';
print '    <div class="dc-field-value">';
if ($isCreate || $isEdit) {
    print '<input type="text" name="barcode" value="'.dol_escape_htmltag($object->barcode).'">';
} else {
    print (!empty($object->barcode) ? '<span class="dc-mono">'.dol_escape_htmltag($object->barcode).'</span>' : '&mdash;');
}
print '    </div></div>';

// Manufacturer
print '  <div class="dc-field">';
print '    <div class="dc-field-label">'.$langs->trans('Manufacturer').'</div>';
print '    <div class="dc-field-value">';
if ($isCreate || $isEdit) {
    print '<input type="text" name="manufacturer" value="'.dol_escape_htmltag($object->manufacturer).'">';
} else {
    print (!empty($object->manufacturer) ? dol_escape_htmltag($object->manufacturer) : '&mdash;');
}
print '    </div></div>';

// Model
print '  <div class="dc-field">';
print '    <div class="dc-field-label">'.$langs->trans('Model').'</div>';
print '    <div class="dc-field-value">';
if ($isCreate || $isEdit) {
    print '<input type="text" name="model" value="'.dol_escape_htmltag($object->model).'">';
} else {
    print (!empty($object->model) ? dol_escape_htmltag($object->model) : '&mdash;');
}
print '    </div></div>';

// Year
print '  <div class="dc-field">';
print '    <div class="dc-field-label">'.$langs->trans('Year').'</div>';
print '    <div class="dc-field-value">';
if ($isCreate || $isEdit) {
    print '<input type="number" name="year" value="'.dol_escape_htmltag($object->year).'" min="1900" max="2100">';
} else {
    print (!empty($object->year) ? dol_escape_htmltag($object->year) : '&mdash;');
}
print '    </div></div>';

print '  </div>';
print '</div>';

/* Card: Inventory & Sourcing */
print '<div class="dc-card">';
print '  <div class="dc-card-header">';
print '    <div class="dc-card-header-icon green"><i class="fa fa-boxes"></i></div>';
print '    <span class="dc-card-title">'.$langs->trans('InventoryAndSourcing').'</span>';
print '  </div>';
print '  <div class="dc-card-body">';

// Quantity on Hand
print '  <div class="dc-field">';
print '    <div class="dc-field-label">'.$langs->trans('QtyOnHand').'</div>';
print '    <div class="dc-field-value">';
if ($isCreate || $isEdit) {
    print '<input type="number" name="qty_on_hand" value="'.(int)$object->qty_on_hand.'" min="0">';
} else {
    print '<span class="dc-stock '.$stockClass.'"><i class="fa '.$stockIcon.'" style="font-size:11px;"></i> '.(int)$object->qty_on_hand.' '.$langs->trans('Units').'</span>';
}
print '    </div></div>';

// Unit Cost
print '  <div class="dc-field">';
print '    <div class="dc-field-label">'.$langs->trans('UnitCost').'</div>';
print '    <div class="dc-field-value">';
if ($isCreate || $isEdit) {
    print '<input type="number" name="unit_cost" value="'.(float)$object->unit_cost.'" step="0.01" min="0">';
} else {
    print (!empty($object->unit_cost) ? '<span class="dc-price">'.price($object->unit_cost).'</span>' : '&mdash;');
}
print '    </div></div>';

// Status
print '  <div class="dc-field">';
print '    <div class="dc-field-label">'.$langs->trans('Status').'</div>';
print '    <div class="dc-field-value">';
if ($isCreate || $isEdit) {
    $status_options = array(
        ''             => $langs->trans('SelectStatus'),
        'Active'       => $langs->trans('Active'),
        'Inactive'     => $langs->trans('Inactive'),
        'Maintenance'  => $langs->trans('Maintenance'),
        'Discontinued' => $langs->trans('Discontinued'),
    );
    print $form->selectarray('status', $status_options, $object->status, 0);
} else {
    if (!empty($object->status)) {
        $stClass = strtolower($object->status);
        print '<span class="dc-badge '.$stClass.'">'.dol_escape_htmltag($object->status).'</span>';
    } else {
        print '&mdash;';
    }
}
print '    </div></div>';

// Availability
print '  <div class="dc-field">';
print '    <div class="dc-field-label">'.$langs->trans('Availability').'</div>';
print '    <div class="dc-field-value">';
if ($isCreate || $isEdit) {
    $availability_options = array(
        '1' => $langs->trans('Available'),
        '0' => $langs->trans('NotAvailable'),
    );
    print $form->selectarray('availability', $availability_options, $object->availability, 0);
} else {
    $availText  = $object->availability ? $langs->trans('Available') : $langs->trans('NotAvailable');
    $availClass = $object->availability ? 'available' : 'notavailable';
    print '<span class="dc-badge '.$availClass.'">'.dol_escape_htmltag($availText).'</span>';
}
print '    </div></div>';

// Vendor
print '  <div class="dc-field">';
print '    <div class="dc-field-label">'.$langs->trans('Vendor').'</div>';
print '    <div class="dc-field-value">';
if ($isCreate || $isEdit) {
    print $form->selectarray('fk_vendor', $vendors, $object->fk_vendor, 1);
} else {
    print (!empty($object->vendor_name) ? dol_escape_htmltag($object->vendor_name) : '&mdash;');
}
print '    </div></div>';

// Category
print '  <div class="dc-field">';
print '    <div class="dc-field-label">'.$langs->trans('Category').'</div>';
print '    <div class="dc-field-value">';
if ($isCreate || $isEdit) {
    print $form->selectarray('fk_category', $categories, $object->fk_category, 1);
} else {
    print (!empty($object->category_name) ? dol_escape_htmltag($object->category_name) : '&mdash;');
}
print '    </div></div>';

print '  </div>';
print '</div>';

print '</div>';// dc-grid row1

/* ── ROW 2: Description + Notes ── */
print '<div class="dc-grid" style="margin-bottom:20px;">';

/* Card: Description */
print '<div class="dc-card">';
print '  <div class="dc-card-header">';
print '    <div class="dc-card-header-icon amber"><i class="fa fa-align-left"></i></div>';
print '    <span class="dc-card-title">'.$langs->trans('Description').'</span>';
print '  </div>';
print '  <div class="dc-card-body">';
print '  <div class="dc-field" style="flex-direction:column;gap:8px;">';
print '    <div class="dc-field-value" style="width:100%;">';
if ($isCreate || $isEdit) {
    print '<textarea name="description" rows="5" style="min-height:110px;">'.dol_escape_htmltag($object->description).'</textarea>';
} else {
    if (!empty($object->description)) {
        print '<div style="font-size:13.5px;color:#2d3748;line-height:1.7;">'.nl2br(dol_escape_htmltag($object->description)).'</div>';
    } else {
        print '<span style="color:#c4c9d8;">&mdash;</span>';
    }
}
print '    </div></div>';
print '  </div>';
print '</div>';

/* Card: Notes */
print '<div class="dc-card">';
print '  <div class="dc-card-header">';
print '    <div class="dc-card-header-icon purple"><i class="fa fa-sticky-note"></i></div>';
print '    <span class="dc-card-title">'.$langs->trans('Note').'</span>';
print '  </div>';
print '  <div class="dc-card-body">';
print '  <div class="dc-field" style="flex-direction:column;gap:8px;">';
print '    <div class="dc-field-value" style="width:100%;">';
if ($isCreate || $isEdit) {
    print '<textarea name="note" rows="5" style="min-height:110px;">'.dol_escape_htmltag($object->note).'</textarea>';
} else {
    if (!empty($object->note)) {
        print '<div style="font-size:13.5px;color:#2d3748;line-height:1.7;">'.nl2br(dol_escape_htmltag($object->note)).'</div>';
    } else {
        print '<span style="color:#c4c9d8;">&mdash;</span>';
    }
}
print '    </div></div>';
print '  </div>';
print '</div>';

print '</div>';// dc-grid row2

/* ── BOTTOM ACTION BAR ── */
if ($isCreate || $isEdit) {
    print '<div class="dc-action-bar">';
    print '<a class="dc-btn dc-btn-ghost dc-action-bar-left" href="'.dol_buildpath('/flotte/part_list.php', 1).'"><i class="fa fa-arrow-left"></i> '.$langs->trans('BackToList').'</a>';
    print '<a class="dc-btn dc-btn-ghost" href="'.($id > 0 ? $_SERVER['PHP_SELF'].'?id='.$id : dol_buildpath('/flotte/part_list.php', 1)).'"><i class="fa fa-times"></i> '.$langs->trans('Cancel').'</a>';
    print '<button type="submit" class="dc-btn dc-btn-primary"><i class="fa fa-check"></i> '.($isCreate ? $langs->trans('Create') : $langs->trans('Save')).'</button>';
    print '</div>';
    print '</form>';
} elseif ($id > 0) {
    print '<div class="dc-action-bar">';
    print '<a class="dc-btn dc-btn-ghost dc-action-bar-left" href="'.dol_buildpath('/flotte/part_list.php', 1).'"><i class="fa fa-arrow-left"></i> '.$langs->trans('BackToList').'</a>';
    print '<a class="dc-btn dc-btn-ghost" href="'.$_SERVER['PHP_SELF'].'?id='.$id.'&action=edit"><i class="fa fa-pen"></i> '.$langs->trans('Modify').'</a>';
    print '<a class="dc-btn dc-btn-danger" href="'.$_SERVER['PHP_SELF'].'?id='.$id.'&action=delete"><i class="fa fa-trash"></i> '.$langs->trans('Delete').'</a>';
    print '</div>';
}

print '</div>';// dc-page

// End of page
llxFooter();
$db->close();
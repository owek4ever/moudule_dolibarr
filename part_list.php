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

// Get parameters
$action = GETPOST('action', 'aZ09') ? GETPOST('action', 'aZ09') : 'view';
$massaction = GETPOST('massaction', 'alpha');
$confirm = GETPOST('confirm', 'alpha');
$toselect = GETPOST('toselect', 'array');

$search_ref = GETPOST('search_ref', 'alpha');
$search_title = GETPOST('search_title', 'alpha');
$search_number = GETPOST('search_number', 'alpha');
$search_barcode = GETPOST('search_barcode', 'alpha');
$search_manufacturer = GETPOST('search_manufacturer', 'alpha');
$search_model = GETPOST('search_model', 'alpha');
$search_status = GETPOST('search_status', 'alpha');
$search_availability = GETPOST('search_availability', 'alpha');
$search_category = GETPOST('search_category', 'int');
$search_vendor = GETPOST('search_vendor', 'int');

$limit = GETPOST('limit', 'int') ? GETPOST('limit', 'int') : $conf->liste_limit;
$sortfield = GETPOST('sortfield', 'aZ09comma');
$sortorder = GETPOST('sortorder', 'aZ09comma');
$page = GETPOSTISSET('pageplusone') ? (GETPOST('pageplusone') - 1) : GETPOST("page", 'int');

if (empty($page) || $page == -1) {
    $page = 0;
}

$offset = $limit * $page;

if (!$sortfield) {
    $sortfield = "t.title";
}
if (!$sortorder) {
    $sortorder = "ASC";
}

// Security check
restrictedArea($user, 'flotte');

/*
 * Actions
 */
if (GETPOST('cancel', 'alpha')) {
    $action = 'list';
    $massaction = '';
}

if (GETPOST('button_removefilter_x', 'alpha') || GETPOST('button_removefilter.x', 'alpha') || GETPOST('button_removefilter', 'alpha')) {
    $search_ref = '';
    $search_title = '';
    $search_number = '';
    $search_barcode = '';
    $search_manufacturer = '';
    $search_model = '';
    $search_status = '';
    $search_availability = '';
    $search_category = '';
    $search_vendor = '';
}

// Delete action
if ($action == 'confirm_delete' && $confirm == 'yes' && $user->rights->flotte->write) {
    $id = GETPOST('id', 'int');
    if ($id > 0) {
        $sql = "DELETE FROM ".MAIN_DB_PREFIX."flotte_part WHERE rowid = ".(int)$id;
        $result = $db->query($sql);
        if ($result) {
            setEventMessages($langs->trans("RecordDeleted"), null, 'mesgs');
            header("Location: ".$_SERVER['PHP_SELF']);
            exit;
        } else {
            setEventMessages($langs->trans("Error"), null, 'errors');
        }
    }
}

// Build and execute select
$sql = 'SELECT t.rowid, t.ref, t.barcode, t.title, t.number, t.description, t.status, t.availability,';
$sql .= ' t.fk_vendor, t.fk_category, t.manufacturer, t.year, t.model, t.qty_on_hand, t.unit_cost, t.note, t.picture,';
$sql .= ' v.name as vendor_name, c.category_name';
$sql .= ' FROM '.MAIN_DB_PREFIX.'flotte_part as t';
$sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'flotte_vendor as v ON t.fk_vendor = v.rowid';
$sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'flotte_part_category as c ON t.fk_category = c.rowid';
$sql .= ' WHERE 1 = 1';
$sql .= ' AND t.entity IN ('.getEntity('flotte').')';

if ($search_ref) {
    $sql .= " AND t.ref LIKE '%".$db->escape($search_ref)."%'";
}
if ($search_title) {
    $sql .= " AND t.title LIKE '%".$db->escape($search_title)."%'";
}
if ($search_number) {
    $sql .= " AND t.number LIKE '%".$db->escape($search_number)."%'";
}
if ($search_barcode) {
    $sql .= " AND t.barcode LIKE '%".$db->escape($search_barcode)."%'";
}
if ($search_manufacturer) {
    $sql .= " AND t.manufacturer LIKE '%".$db->escape($search_manufacturer)."%'";
}
if ($search_model) {
    $sql .= " AND t.model LIKE '%".$db->escape($search_model)."%'";
}
if ($search_status) {
    $sql .= " AND t.status = '".$db->escape($search_status)."'";
}
if ($search_availability) {
    $sql .= " AND t.availability = ".((int) $search_availability);
}
if ($search_category) {
    $sql .= " AND t.fk_category = ".((int) $search_category);
}
if ($search_vendor) {
    $sql .= " AND t.fk_vendor = ".((int) $search_vendor);
}

$sql .= $db->order($sortfield, $sortorder);

// Count total nb of records
$sqlcount = preg_replace('/SELECT.*FROM/', 'SELECT COUNT(*) as nb FROM', $sql);
$resql = $db->query($sqlcount);
$nbtotalofrecords = 0;
if ($resql) {
    $obj = $db->fetch_object($resql);
    $nbtotalofrecords = $obj->nb;
}

$sql .= $db->plimit($limit + 1, $offset);
$resql = $db->query($sql);

// Get vendors and categories for filters
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
$num = 0;
if ($resql) {
    $num = $db->num_rows($resql);
}

// Page header
llxHeader('', $langs->trans("PartsList"), '');

// Actions bar
print '<div class="tabsAction">'."\n";
if ($user->rights->flotte->write) {
    print '<a class="butAction" href="'.dol_buildpath('/flotte/part_card.php', 1).'?action=create">'.$langs->trans("New Part").'</a>'."\n";
}
if ($user->rights->flotte->read) {
    print '<a class="butAction" href="'.dol_buildpath('/flotte/part_list.php', 1).'?action=export">'.$langs->trans("Export").'</a>'."\n";
}
print '</div>'."\n";

// Build param string for URL
$param = '';
if (!empty($search_ref))           $param .= '&search_ref='.urlencode($search_ref);
if (!empty($search_title))         $param .= '&search_title='.urlencode($search_title);
if (!empty($search_number))        $param .= '&search_number='.urlencode($search_number);
if (!empty($search_barcode))       $param .= '&search_barcode='.urlencode($search_barcode);
if (!empty($search_manufacturer))  $param .= '&search_manufacturer='.urlencode($search_manufacturer);
if (!empty($search_model))         $param .= '&search_model='.urlencode($search_model);
if (!empty($search_status))        $param .= '&search_status='.urlencode($search_status);
if (!empty($search_availability))  $param .= '&search_availability='.urlencode($search_availability);
if (!empty($search_category))      $param .= '&search_category='.urlencode($search_category);
if (!empty($search_vendor))        $param .= '&search_vendor='.urlencode($search_vendor);

// Confirmation to delete
if ($action == 'delete') {
    $id = GETPOST('id', 'int');
    $formconfirm = $form->formconfirm($_SERVER["PHP_SELF"].'?id='.$id.$param, $langs->trans('DeletePart'), $langs->trans('ConfirmDeletePart'), 'confirm_delete', '', 0, 1);
    print $formconfirm;
    
    // Clear the action from URL after showing confirmation to prevent reappearing on refresh
    if (!empty($formconfirm)) {
        echo '<script type="text/javascript">
        if (window.history.replaceState) {
            window.history.replaceState(null, null, "'.$_SERVER["PHP_SELF"].'?'.$param.'");
        }
        </script>';
    }
}

// Search form
print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'">'."\n";
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="formfilteraction" id="formfilteraction" value="list">';
print '<input type="hidden" name="action" value="list">';
print '<input type="hidden" name="sortfield" value="'.$sortfield.'">';
print '<input type="hidden" name="sortorder" value="'.$sortorder.'">';
print '<input type="hidden" name="page" value="'.$page.'">';

// Print barre liste
print_barre_liste($langs->trans("PartsList"), $page, $_SERVER["PHP_SELF"], $param, $sortfield, $sortorder, '', $num, $nbtotalofrecords, '0', 0);

print '<div class="div-table-responsive">';
print '<table class="tagtable liste" id="tablelines">'."\n";

// Search fields
print '<tr class="liste_titre_filter">';
print '<td class="liste_titre"><input type="text" class="flat maxwidth100" name="search_ref" value="'.dol_escape_htmltag($search_ref).'"></td>';
print '<td class="liste_titre"><input type="text" class="flat maxwidth100" name="search_title" value="'.dol_escape_htmltag($search_title).'"></td>';
print '<td class="liste_titre"><input type="text" class="flat maxwidth100" name="search_number" value="'.dol_escape_htmltag($search_number).'"></td>';
print '<td class="liste_titre"><input type="text" class="flat maxwidth100" name="search_barcode" value="'.dol_escape_htmltag($search_barcode).'"></td>';
print '<td class="liste_titre"><input type="text" class="flat maxwidth100" name="search_manufacturer" value="'.dol_escape_htmltag($search_manufacturer).'"></td>';
print '<td class="liste_titre">';
print $form->selectarray('search_category', $categories, $search_category, 1, 0, 0, '', 0, 0, 0, '', 'search_category width100 onrightofpage');
print '</td>';
print '<td class="liste_titre">';
print $form->selectarray('search_vendor', $vendors, $search_vendor, 1, 0, 0, '', 0, 0, 0, '', 'search_vendor width100 onrightofpage');
print '</td>';
print '<td class="liste_titre right">&nbsp;</td>'; // Stock - no filter
print '<td class="liste_titre right">&nbsp;</td>'; // UnitCost - no filter
print '<td class="liste_titre center">';
$statusarray = array(''=>'', 'Active'=>$langs->trans('Active'), 'Inactive'=>$langs->trans('Inactive'), 'Maintenance'=>$langs->trans('Maintenance'), 'Discontinued'=>$langs->trans('Discontinued'));
print $form->selectarray('search_status', $statusarray, $search_status, 1, 0, 0, '', 0, 0, 0, '', 'search_status width100 onrightofpage');
print '</td>';
print '<td class="liste_titre center">';
$availabilityarray = array(''=>'', '1'=>$langs->trans('Available'), '0'=>$langs->trans('NotAvailable'));
print $form->selectarray('search_availability', $availabilityarray, $search_availability, 1, 0, 0, '', 0, 0, 0, '', 'search_availability width100 onrightofpage');
print '</td>';
print '<td class="liste_titre maxwidthsearch">';
$searchpicto = $form->showFilterButtons();
print $searchpicto;
print '</td>';
print '</tr>'."\n";

// Table headers
print '<tr class="liste_titre">';
print_liste_field_titre("Ref", $_SERVER["PHP_SELF"], "t.ref", "", $param, '', $sortfield, $sortorder);
print_liste_field_titre("Title", $_SERVER["PHP_SELF"], "t.title", "", $param, '', $sortfield, $sortorder);
print_liste_field_titre("PartNumber", $_SERVER["PHP_SELF"], "t.number", "", $param, '', $sortfield, $sortorder);
print_liste_field_titre("Barcode", $_SERVER["PHP_SELF"], "t.barcode", "", $param, '', $sortfield, $sortorder);
print_liste_field_titre("Manufacturer", $_SERVER["PHP_SELF"], "t.manufacturer", "", $param, '', $sortfield, $sortorder);
print_liste_field_titre("Category", $_SERVER["PHP_SELF"], "c.category_name", "", $param, '', $sortfield, $sortorder);
print_liste_field_titre("Vendor", $_SERVER["PHP_SELF"], "v.name", "", $param, '', $sortfield, $sortorder);
print_liste_field_titre("Stock", $_SERVER["PHP_SELF"], "t.qty_on_hand", "", $param, '', $sortfield, $sortorder, 'right ');
print_liste_field_titre("UnitCost", $_SERVER["PHP_SELF"], "t.unit_cost", "", $param, '', $sortfield, $sortorder, 'right ');
print_liste_field_titre("Status", $_SERVER["PHP_SELF"], "t.status", "", $param, '', $sortfield, $sortorder, 'center ');
print_liste_field_titre("Available", $_SERVER["PHP_SELF"], "t.availability", "", $param, '', $sortfield, $sortorder, 'center ');
print_liste_field_titre("Action", $_SERVER["PHP_SELF"], "", "", "", '', '', '', 'maxwidthsearch ');
print '</tr>'."\n";

// Display data
if ($resql && $num > 0) {
    $i = 0;
    while ($i < min($num, $limit)) {
        $obj = $db->fetch_object($resql);
        if (empty($obj)) break;
        
        print '<tr class="oddeven">';
        
        // Reference
        print '<td class="nowrap"><a href="'.dol_buildpath('/flotte/part_card.php', 1).'?id='.$obj->rowid.'">';
        print img_object($langs->trans("ShowPart"), "generic", 'class="pictofixedwidth"');
        print '<strong>'.dol_escape_htmltag($obj->ref).'</strong></a></td>';
        
        // Title
        print '<td>'.dol_escape_htmltag($obj->title).'</td>';
        
        // Part Number
        print '<td>'.dol_escape_htmltag($obj->number).'</td>';
        
        // Barcode
        print '<td>'.dol_escape_htmltag($obj->barcode).'</td>';
        
        // Manufacturer
        print '<td>'.dol_escape_htmltag($obj->manufacturer);
        if ($obj->model) {
            print '<br><small>'.dol_escape_htmltag($obj->model).'</small>';
        }
        if ($obj->year) {
            print '<br><small>'.dol_escape_htmltag($obj->year).'</small>';
        }
        print '</td>';
        
        // Category
        print '<td>'.dol_escape_htmltag($obj->category_name).'</td>';
        
        // Vendor
        print '<td>'.dol_escape_htmltag($obj->vendor_name).'</td>';
        
        // Stock
        print '<td class="right">';
        $stock_class = '';
        if ($obj->qty_on_hand <= 0) {
            $stock_class = 'class="error"';
        } elseif ($obj->qty_on_hand <= 5) {
            $stock_class = 'class="warning"';
        }
        print '<span '.$stock_class.'>'.(int)$obj->qty_on_hand.'</span>';
        print '</td>';
        
        // Unit Cost
        print '<td class="right">'.($obj->unit_cost ? price($obj->unit_cost) : '').'</td>';
        
        // Status
        print '<td class="center">';
        if ($obj->status == 'Active') {
            print dolGetStatus($langs->trans('Active'), '', '', 'status4', 1);
        } elseif ($obj->status == 'Inactive') {
            print dolGetStatus($langs->trans('Inactive'), '', '', 'status8', 1);
        } elseif ($obj->status == 'Maintenance') {
            print dolGetStatus($langs->trans('Maintenance'), '', '', 'status6', 1);
        } elseif ($obj->status == 'Discontinued') {
            print dolGetStatus($langs->trans('Discontinued'), '', '', 'status9', 1);
        }
        print '</td>';
        
        // Available
        print '<td class="center">';
        if ($obj->availability == 1) {
            print dolGetStatus($langs->trans('Available'), '', '', 'status4', 1);
        } else {
            print dolGetStatus($langs->trans('NotAvailable'), '', '', 'status8', 1);
        }
        print '</td>';
        
        // Actions
        print '<td class="nowrap center">';
        if ($user->rights->flotte->delete) {
            print '<a class="editfielda reposition" href="'.$_SERVER["PHP_SELF"].'?id='.$obj->rowid.'&action=delete&token='.newToken().$param.'" title="'.$langs->trans("Delete").'">'.img_delete($langs->trans("Delete")).'</a>';
        }
        if ($user->rights->flotte->write) {
            print '<a class="editfielda" href="'.dol_buildpath('/flotte/part_card.php', 1).'?id='.$obj->rowid.'&action=edit" title="'.$langs->trans("Edit").'">'.img_edit($langs->trans("Edit")).'</a>';
        }
        print '</td>';
        
        print '</tr>'."\n";
        $i++;
    }
} else {
    $colspan = 12;
    print '<tr class="oddeven"><td colspan="'.$colspan.'" class="opacitymedium">'.$langs->trans("NoRecordFound").'</td></tr>';
}

print '</table>'."\n";
print '</div>'."\n";

print '</form>'."\n";

// Print pagination
if ($nbtotalofrecords > $limit) {
    print_barre_liste('', $page, $_SERVER["PHP_SELF"], $param, $sortfield, $sortorder, '', $num, $nbtotalofrecords, '', 0, '', '', $limit, 1);
}

if ($resql) {
    $db->free($resql);
}

// End of page
llxFooter();
$db->close();
?>
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
$search_maker = GETPOST('search_maker', 'alpha');
$search_model = GETPOST('search_model', 'alpha');
$search_license_plate = GETPOST('search_license_plate', 'alpha');
$search_status = GETPOST('search_status', 'alpha');

$limit = GETPOST('limit', 'int') ? GETPOST('limit', 'int') : $conf->liste_limit;
$sortfield = GETPOST('sortfield', 'aZ09comma');
$sortorder = GETPOST('sortorder', 'aZ09comma');
$page = GETPOSTISSET('pageplusone') ? (GETPOST('pageplusone') - 1) : GETPOST("page", 'int');

if (empty($page) || $page == -1) {
    $page = 0;
}

$offset = $limit * $page;

if (!$sortfield) {
    $sortfield = "t.ref";
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
    $search_maker = '';
    $search_model = '';
    $search_license_plate = '';
    $search_status = '';
}

// Build and execute select
$sql = 'SELECT t.rowid, t.ref, t.maker, t.model, t.type, t.year, t.license_plate, t.color, t.vin, t.in_service, t.initial_mileage, t.registration_expiry, t.license_expiry';
$sql .= ' FROM '.MAIN_DB_PREFIX.'flotte_vehicle as t';
$sql .= ' WHERE 1 = 1';
$sql .= ' AND t.entity IN ('.getEntity('flotte').')';

if ($search_ref) {
    $sql .= " AND t.ref LIKE '%".$db->escape($search_ref)."%'";
}
if ($search_maker) {
    $sql .= " AND t.maker LIKE '%".$db->escape($search_maker)."%'";
}
if ($search_model) {
    $sql .= " AND t.model LIKE '%".$db->escape($search_model)."%'";
}
if ($search_license_plate) {
    $sql .= " AND t.license_plate LIKE '%".$db->escape($search_license_plate)."%'";
}
if ($search_status !== '') {
    if ($search_status == '1') {
        $sql .= " AND t.in_service = 1";
    } elseif ($search_status == '0') {
        $sql .= " AND t.in_service = 0";
    }
}

$sql .= $db->order($sortfield, $sortorder);

// Count total nb of records
$sqlcount = str_replace('SELECT t.rowid, t.ref, t.maker, t.model, t.type, t.year, t.license_plate, t.color, t.vin, t.in_service, t.initial_mileage, t.registration_expiry, t.license_expiry', 'SELECT COUNT(*) as nb', $sql);
$resql = $db->query($sqlcount);
$nbtotalofrecords = 0;
if ($resql) {
    $obj = $db->fetch_object($resql);
    $nbtotalofrecords = $obj->nb;
}

$sql .= $db->plimit($limit + 1, $offset);
$resql = $db->query($sql);

$form = new Form($db);
$num = 0;
if ($resql) {
    $num = $db->num_rows($resql);
}

// Page header
llxHeader('', $langs->trans("Vehicles List"), '');

// Page title and buttons
print load_fiche_titre($langs->trans("Vehicles List"), $newCardButton, 'vehicle@flotte');

// Buttons
$newCardButton = '';
if ($user->rights->flotte->write) {
    $newCardButton = dolGetButtonTitle($langs->trans('New Vehicle'), '', 'fa fa-plus-circle', dol_buildpath('/flotte/vehicle_card.php', 1).'?action=create', '', $permissiontoread);
}

// Actions bar
print '<div class="tabsAction">'."\n";
if ($user->rights->flotte->write) {
    print '<a class="butAction" href="'.dol_buildpath('/flotte/vehicle_card.php', 1).'?action=create">'.$langs->trans("New Vehicle").'</a>'."\n";
}
if ($user->rights->flotte->read) {
    print '<a class="butAction" href="'.dol_buildpath('/flotte/vehicle_list.php', 1).'?action=export">'.$langs->trans("Export").'</a>'."\n";
}
print '</div>'."\n";

// Search form
print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'">'."\n";
if ($optioncss != '') print '<input type="hidden" name="optioncss" value="'.$optioncss.'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="formfilteraction" id="formfilteraction" value="list">';
print '<input type="hidden" name="action" value="list">';
print '<input type="hidden" name="sortfield" value="'.$sortfield.'">';
print '<input type="hidden" name="sortorder" value="'.$sortorder.'">';
print '<input type="hidden" name="page" value="'.$page.'">';
print '<input type="hidden" name="contextpage" value="'.$contextpage.'">';

// Search criteria
print_barre_liste($langs->trans("Vehicles List"), $page, $_SERVER["PHP_SELF"], $param, $sortfield, $sortorder, '', $num, $nbtotalofrecords, 'vehicle@flotte', 0);

print '<div class="div-table-responsive">';
print '<table class="tagtable liste'.($moreforfilter ? " listwithfilterbefore" : "").'" id="tablelines">'."\n";

// Search fields
print '<tr class="liste_titre_filter">';
print '<td class="liste_titre"><input type="text" class="flat maxwidth100" name="search_ref" value="'.dol_escape_htmltag($search_ref).'"></td>';
print '<td class="liste_titre"><input type="text" class="flat maxwidth100" name="search_maker" value="'.dol_escape_htmltag($search_maker).'"></td>';
print '<td class="liste_titre"><input type="text" class="flat maxwidth100" name="search_model" value="'.dol_escape_htmltag($search_model).'"></td>';
print '<td class="liste_titre"></td>'; // Type
print '<td class="liste_titre"></td>'; // Year
print '<td class="liste_titre"><input type="text" class="flat maxwidth100" name="search_license_plate" value="'.dol_escape_htmltag($search_license_plate).'"></td>';
print '<td class="liste_titre"></td>'; // Color
print '<td class="liste_titre"></td>'; // VIN
print '<td class="liste_titre"></td>'; // Mileage
print '<td class="liste_titre">';
print $form->selectarray('search_status', array(''=>'', '1'=>$langs->trans('InService'), '0'=>$langs->trans('OutOfService')), $search_status);
print '</td>';
print '<td class="liste_titre maxwidthsearch">';
$searchpicto = $form->showFilterButtons();
print $searchpicto;
print '</td>';
print '</tr>'."\n";

// Table headers
print '<tr class="liste_titre">';
print_liste_field_titre("Ref", $_SERVER["PHP_SELF"], "t.ref", "", $param, '', $sortfield, $sortorder);
print_liste_field_titre("Maker", $_SERVER["PHP_SELF"], "t.maker", "", $param, '', $sortfield, $sortorder);
print_liste_field_titre($langs->trans("VehicleModel"), $_SERVER["PHP_SELF"], "t.model", "", $param, '', $sortfield, $sortorder);
print_liste_field_titre("Type", $_SERVER["PHP_SELF"], "t.type", "", $param, '', $sortfield, $sortorder);
print_liste_field_titre("Year", $_SERVER["PHP_SELF"], "t.year", "", $param, '', $sortfield, $sortorder);
print_liste_field_titre("LicensePlate", $_SERVER["PHP_SELF"], "t.license_plate", "", $param, '', $sortfield, $sortorder);
print_liste_field_titre("Color", $_SERVER["PHP_SELF"], "t.color", "", $param, '', $sortfield, $sortorder);
print_liste_field_titre("VIN", $_SERVER["PHP_SELF"], "t.vin", "", $param, '', $sortfield, $sortorder);
print_liste_field_titre("Mileage", $_SERVER["PHP_SELF"], "t.initial_mileage", "", $param, '', $sortfield, $sortorder);
print_liste_field_titre("Status", $_SERVER["PHP_SELF"], "t.in_service", "", $param, '', $sortfield, $sortorder);
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
        print '<td class="nowrap"><a href="'.dol_buildpath('/flotte/vehicle_card.php', 1).'?id='.$obj->rowid.'">';
        print img_object($langs->trans("ShowVehicle"), "vehicle@flotte", 'class="pictofixedwidth"');
        print '<strong>'.dol_escape_htmltag($obj->ref).'</strong></a></td>';
        
        // Maker
        print '<td>'.dol_escape_htmltag($obj->maker).'</td>';
        
        // Model
        print '<td>'.dol_escape_htmltag($obj->model).'</td>';
        
        // Type
        print '<td>'.dol_escape_htmltag($obj->type).'</td>';
        
        // Year
        print '<td class="center">'.dol_escape_htmltag($obj->year).'</td>';
        
        // License Plate
        print '<td class="nowrap">'.dol_escape_htmltag($obj->license_plate).'</td>';
        
        // Color
        print '<td>';
        if (!empty($obj->color)) {
            print '<span class="badge" style="background-color: '.strtolower($obj->color).'; color: white; padding: 2px 6px; border-radius: 3px;">'.dol_escape_htmltag($obj->color).'</span>';
        }
        print '</td>';
        
        // VIN
        print '<td class="tdoverflowmax150" title="'.dol_escape_htmltag($obj->vin).'">'.dol_escape_htmltag($obj->vin).'</td>';
        
        // Mileage
        print '<td class="right">'.dol_escape_htmltag($obj->initial_mileage).' km</td>';
        
        // Status
        print '<td class="center">';
        if ($obj->in_service == 1) {
            print '<span class="badge badge-status4 badge-status">'.dolGetStatus($langs->trans('InService'), '', '', 'status4', 1).'</span>';
        } else {
            print '<span class="badge badge-status8 badge-status">'.dolGetStatus($langs->trans('OutOfService'), '', '', 'status8', 1).'</span>';
        }
        print '</td>';
        
        // Actions
        print '<td class="nowrap center">';
        if ($user->rights->flotte->read) {
            print '<a class="editfielda" href="'.dol_buildpath('/flotte/vehicle_card.php', 1).'?id='.$obj->rowid.'" title="'.$langs->trans("View").'">'.img_view($langs->trans("View")).'</a>';
        }
        if ($user->rights->flotte->write) {
            print '<a class="editfielda" href="'.dol_buildpath('/flotte/vehicle_card.php', 1).'?id='.$obj->rowid.'&action=edit" title="'.$langs->trans("Edit").'">'.img_edit($langs->trans("Edit")).'</a>';
        }
        if ($user->rights->flotte->delete) {
            print '<a class="editfielda" href="'.dol_buildpath('/flotte/vehicle_card.php', 1).'?id='.$obj->rowid.'&action=delete&token='.newToken().'" title="'.$langs->trans("Delete").'">'.img_delete($langs->trans("Delete")).'</a>';
        }
        print '</td>';
        
        print '</tr>'."\n";
        $i++;
    }
} else {
    $colspan = 11;
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
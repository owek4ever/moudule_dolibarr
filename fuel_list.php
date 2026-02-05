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
$search_vehicle = GETPOST('search_vehicle', 'alpha');
$search_date = GETPOST('search_date', 'alpha');
$search_reference = GETPOST('search_reference', 'alpha');
$search_state = GETPOST('search_state', 'alpha');
$search_fuel_source = GETPOST('search_fuel_source', 'alpha');

$limit = GETPOST('limit', 'int') ? GETPOST('limit', 'int') : $conf->liste_limit;
$sortfield = GETPOST('sortfield', 'aZ09comma');
$sortorder = GETPOST('sortorder', 'aZ09comma');
$page = GETPOSTISSET('pageplusone') ? (GETPOST('pageplusone') - 1) : GETPOST("page", 'int');

if (empty($page) || $page == -1) {
    $page = 0;
}

$offset = $limit * $page;

if (!$sortfield) {
    $sortfield = "t.date";
}
if (!$sortorder) {
    $sortorder = "DESC";
}

// Initialize technical objects
$form = new Form($db);
$formcompany = new FormCompany($db);
$hookmanager->initHooks(array('fuellist', 'globalcard'));

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
    $search_vehicle = '';
    $search_date = '';
    $search_reference = '';
    $search_state = '';
    $search_fuel_source = '';
}

// Handle confirmed delete from list page
if ($action == 'confirm_delete' && GETPOST('confirm', 'alpha') == 'yes') {
    $id_to_delete = GETPOST('id', 'int');
    if ($id_to_delete > 0) {
        $db->begin();
        $sql_del = "DELETE FROM ".MAIN_DB_PREFIX."flotte_fuel WHERE rowid = ".(int)$id_to_delete." AND entity IN (".getEntity('flotte').")";
        $resql_del = $db->query($sql_del);
        if ($resql_del) {
            // Delete associated files if any
            $uploadDir = $conf->flotte->dir_output . '/fuel/' . $id_to_delete . '/';
            if (is_dir($uploadDir)) {
                dol_delete_dir_recursive($uploadDir);
            }
            $db->commit();
            setEventMessages($langs->trans("FuelRecordDeletedSuccessfully"), null, 'mesgs');
        } else {
            $db->rollback();
            setEventMessages("Error in SQL: ".$db->lasterror(), null, 'errors');
        }
    }
    $action = 'list';
}

// Build and execute select
$sql = 'SELECT t.rowid, t.ref, t.fk_vehicle, t.date, t.start_meter, t.reference, t.state, t.note, t.complete_fillup, t.fuel_source, t.qty, t.cost_unit, (t.qty * t.cost_unit) as total_cost, v.maker, v.model, v.license_plate';
$sql .= ' FROM '.MAIN_DB_PREFIX.'flotte_fuel as t';
$sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'flotte_vehicle as v ON t.fk_vehicle = v.rowid';
$sql .= ' WHERE 1 = 1';
$sql .= ' AND t.entity IN ('.getEntity('flotte').')';

if ($search_ref) {
    $sql .= " AND t.ref LIKE '%".$db->escape($search_ref)."%'";
}
if ($search_vehicle) {
    $sql .= " AND (v.maker LIKE '%".$db->escape($search_vehicle)."%' OR v.model LIKE '%".$db->escape($search_vehicle)."%' OR v.license_plate LIKE '%".$db->escape($search_vehicle)."%')";
}
if ($search_date) {
    $sql .= " AND DATE(t.date) = '".$db->escape($search_date)."'";
}
if ($search_reference) {
    $sql .= " AND t.reference LIKE '%".$db->escape($search_reference)."%'";
}
if ($search_state) {
    $sql .= " AND t.state = '".$db->escape($search_state)."'";
}
if ($search_fuel_source) {
    $sql .= " AND t.fuel_source = '".$db->escape($search_fuel_source)."'";
}

$sql .= $db->order($sortfield, $sortorder);

// Count total nb of records - Updated to match driver_list.php
$sqlcount = preg_replace('/^SELECT[^,]+(,\s*[^,]+)*\s+FROM/', 'SELECT COUNT(*) as nb FROM', $sql);
$resql = $db->query($sqlcount);
$nbtotalofrecords = 0;
if ($resql) {
    $obj = $db->fetch_object($resql);
    $nbtotalofrecords = $obj->nb;
}

$sql .= $db->plimit($limit + 1, $offset);
$resql = $db->query($sql);

$num = 0;
if ($resql) {
    $num = $db->num_rows($resql);
}

// Page header
llxHeader('', $langs->trans("Fuel Records List"), '');

// Page title and buttons
$newCardButton = '';
if ($user->rights->flotte->write) {
    $newCardButton = dolGetButtonTitle($langs->trans('New Fuel Record'), '', 'fa fa-plus-circle', dol_buildpath('/flotte/fuel_card.php', 1).'?action=create', '', $user->rights->flotte->read);
}

// Show delete confirmation dialog if requested
if ($action == 'delete') {
    $id_to_delete = GETPOST('id', 'int');
    if ($id_to_delete > 0) {
        $formconfirm = $form->formconfirm($_SERVER["PHP_SELF"].'?id='.$id_to_delete, $langs->trans('DeleteFuelRecord'), $langs->trans('ConfirmDeleteFuelRecord'), 'confirm_delete', '', 0, 1);
        print $formconfirm;
    }
}

// Actions bar
print '<div class="tabsAction">'."\n";
if ($user->rights->flotte->write) {
    print '<a class="butAction" href="'.dol_buildpath('/flotte/fuel_card.php', 1).'?action=create">'.$langs->trans("New Fuel Record").'</a>'."\n";
}
if ($user->rights->flotte->read) {
    print '<a class="butAction" href="'.dol_buildpath('/flotte/fuel_list.php', 1).'?action=export">'.$langs->trans("Export").'</a>'."\n";
}
print '</div>'."\n";

// Build param string for URL
$param = '';
if (!empty($search_ref))           $param .= '&search_ref='.urlencode($search_ref);
if (!empty($search_vehicle))       $param .= '&search_vehicle='.urlencode($search_vehicle);
if (!empty($search_date))          $param .= '&search_date='.urlencode($search_date);
if (!empty($search_reference))     $param .= '&search_reference='.urlencode($search_reference);
if (!empty($search_state))         $param .= '&search_state='.urlencode($search_state);
if (!empty($search_fuel_source))   $param .= '&search_fuel_source='.urlencode($search_fuel_source);

// Search form
print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'">'."\n";
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="formfilteraction" id="formfilteraction" value="list">';
print '<input type="hidden" name="action" value="list">';
print '<input type="hidden" name="sortfield" value="'.$sortfield.'">';
print '<input type="hidden" name="sortorder" value="'.$sortorder.'">';
print '<input type="hidden" name="page" value="'.$page.'">';

// Print barre liste - removed 'fuel' icon parameter
print_barre_liste($langs->trans("Fuel Records List"), $page, $_SERVER["PHP_SELF"], $param, $sortfield, $sortorder, '', $num, $nbtotalofrecords, '', 0);

print '<div class="div-table-responsive">';
print '<table class="tagtable liste" id="tablelines">'."\n";

// Search fields - restructured to match driver_list.php style
print '<tr class="liste_titre_filter">';
print '<td class="liste_titre"><input type="text" class="flat maxwidth100" name="search_ref" value="'.dol_escape_htmltag($search_ref).'"></td>';
print '<td class="liste_titre"><input type="text" class="flat maxwidth150" name="search_vehicle" value="'.dol_escape_htmltag($search_vehicle).'" placeholder="'.$langs->trans('VehicleSearch').'"></td>';
print '<td class="liste_titre"></td>'; // Date
print '<td class="liste_titre"></td>'; // Meter Reading
print '<td class="liste_titre"><input type="text" class="flat maxwidth100" name="search_reference" value="'.dol_escape_htmltag($search_reference).'"></td>';
print '<td class="liste_titre"></td>'; // Quantity
print '<td class="liste_titre"></td>'; // Cost/Unit
print '<td class="liste_titre"></td>'; // Total Cost
print '<td class="liste_titre center">';
$fuelsourcearray = array(''=>'', 'Station'=>$langs->trans('Station'), 'Tank'=>$langs->trans('Tank'), 'Other'=>$langs->trans('Other'));
print $form->selectarray('search_fuel_source', $fuelsourcearray, $search_fuel_source, 0, 0, 0, '', 0, 0, 0, '', 'search_fuel_source width100 onrightofpage');
print '</td>';
print '<td class="liste_titre center">';
$statearray = array(''=>'', 'pending'=>$langs->trans('Pending'), 'approved'=>$langs->trans('Approved'), 'rejected'=>$langs->trans('Rejected'), 'completed'=>$langs->trans('Completed'));
print $form->selectarray('search_state', $statearray, $search_state, 0, 0, 0, '', 0, 0, 0, '', 'search_state width100 onrightofpage');
print '</td>';
print '<td class="liste_titre maxwidthsearch">';
$searchpicto = $form->showFilterButtons();
print $searchpicto;
print '</td>';
print '</tr>'."\n";

// Table headers - restructured to match driver_list.php style
print '<tr class="liste_titre">';
print_liste_field_titre("Ref", $_SERVER["PHP_SELF"], "t.ref", "", $param, '', $sortfield, $sortorder);
print_liste_field_titre("Vehicle", $_SERVER["PHP_SELF"], "v.license_plate", "", $param, '', $sortfield, $sortorder);
print_liste_field_titre("Date", $_SERVER["PHP_SELF"], "t.date", "", $param, '', $sortfield, $sortorder, 'center ');
print_liste_field_titre("MeterReading", $_SERVER["PHP_SELF"], "t.start_meter", "", $param, '', $sortfield, $sortorder, 'center ');
print_liste_field_titre("Reference", $_SERVER["PHP_SELF"], "t.reference", "", $param, '', $sortfield, $sortorder);
print_liste_field_titre("Quantity", $_SERVER["PHP_SELF"], "t.qty", "", $param, '', $sortfield, $sortorder, 'center ');
print_liste_field_titre("CostUnit", $_SERVER["PHP_SELF"], "t.cost_unit", "", $param, '', $sortfield, $sortorder, 'center ');
print_liste_field_titre("TotalCost", $_SERVER["PHP_SELF"], "total_cost", "", $param, '', $sortfield, $sortorder, 'center ');
// Manually create centered header for fuel source column like driver list
print '<td class="liste_titre center">';
print '<a class="reposition" href="'.$_SERVER["PHP_SELF"].'?sortfield=t.fuel_source&sortorder='.($sortfield == 't.fuel_source' && $sortorder == 'ASC' ? 'DESC' : 'ASC').$param.'">';
print $langs->trans("FuelSource");
if ($sortfield == 't.fuel_source') print img_picto('', 'sort'.strtolower($sortorder));
print '</a></td>';
// Manually create centered header for state column like driver list
print '<td class="liste_titre center">';
print '<a class="reposition" href="'.$_SERVER["PHP_SELF"].'?sortfield=t.state&sortorder='.($sortfield == 't.state' && $sortorder == 'ASC' ? 'DESC' : 'ASC').$param.'">';
print $langs->trans("State");
if ($sortfield == 't.state') print img_picto('', 'sort'.strtolower($sortorder));
print '</a></td>';
print_liste_field_titre("Action", $_SERVER["PHP_SELF"], "", "", "", '', '', '', 'maxwidthsearch ');
print '</tr>'."\n";

// Display data
if ($resql && $num > 0) {
    $i = 0;
    while ($i < min($num, $limit)) {
        $obj = $db->fetch_object($resql);
        if (empty($obj)) break;
        
        print '<tr class="oddeven">';
        
        // Reference - like driver_list.php style (removed icon)
        print '<td class="nowrap"><a href="'.dol_buildpath('/flotte/fuel_card.php', 1).'?id='.$obj->rowid.'">';
        print '<strong>'.dol_escape_htmltag($obj->ref).'</strong></a></td>';
        
        // Vehicle - like driver_list.php style
        print '<td>';
        $vehicle_info = '';
        if ($obj->maker || $obj->model || $obj->license_plate) {
            $vehicle_parts = array();
            if ($obj->maker) $vehicle_parts[] = $obj->maker;
            if ($obj->model) $vehicle_parts[] = $obj->model;
            if ($obj->license_plate) $vehicle_parts[] = '['.$obj->license_plate.']';
            $vehicle_info = implode(' ', $vehicle_parts);
        }
        print dol_escape_htmltag($vehicle_info);
        print '</td>';
        
        // Date
        print '<td class="center">'.dol_print_date($db->jdate($obj->date), 'day').'</td>';
        
        // Meter Reading
        print '<td class="center">'.($obj->start_meter ? number_format($obj->start_meter).' km' : '-').'</td>';
        
        // Reference
        print '<td>'.dol_escape_htmltag($obj->reference ?: '-').'</td>';
        
        // Quantity
        print '<td class="center">'.($obj->qty ? number_format($obj->qty, 2).' L' : '-').'</td>';
        
        // Cost/Unit
        print '<td class="center">'.($obj->cost_unit ? price($obj->cost_unit) : '-').'</td>';
        
        // Total Cost
        print '<td class="center"><strong>'.($obj->total_cost ? price($obj->total_cost) : '-').'</strong></td>';
        
        // Fuel Source - centered like driver_list.php
        print '<td class="center">';
        if ($obj->fuel_source) {
            $source_label = $langs->trans($obj->fuel_source);
            $status_color = 'status4';
            if ($obj->fuel_source == 'Tank') $status_color = 'status8';
            elseif ($obj->fuel_source == 'Other') $status_color = 'status9';
            print dolGetStatus($source_label, '', '', $status_color, 1);
        } else {
            print '-';
        }
        print '</td>';
        
        // State - centered like driver_list.php
        print '<td class="center">';
        if ($obj->state) {
            $state_label = $langs->trans(ucfirst($obj->state));
            $status_color = 'status1';
            if ($obj->state == 'approved') $status_color = 'status4';
            elseif ($obj->state == 'completed') $status_color = 'status6';
            elseif ($obj->state == 'rejected') $status_color = 'status8';
            print dolGetStatus($state_label, '', '', $status_color, 1);
        } else {
            print '-';
        }
        print '</td>';
        
        // Actions - like driver_list.php (only edit and delete, no view button)
        print '<td class="nowrap center">';
        if ($user->rights->flotte->write) {
            print '<a class="editfielda" href="'.dol_buildpath('/flotte/fuel_card.php', 1).'?id='.$obj->rowid.'&action=edit" title="'.$langs->trans("Edit").'">'.img_edit($langs->trans("Edit")).'</a>';
        }
        if ($user->rights->flotte->delete) {
            print '<a class="editfielda" href="'.dol_buildpath('/flotte/fuel_list.php', 1).'?action=delete&id='.$obj->rowid.'&token='.newToken().'" title="'.$langs->trans("Delete").'">'.img_delete($langs->trans("Delete")).'</a>';
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
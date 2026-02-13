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

// Initialize form object
$form = new Form($db);

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

// Handle confirmed delete from list page
if ($action == 'confirm_delete' && GETPOST('confirm', 'alpha') == 'yes') {
    $id_to_delete = GETPOST('id', 'int');
    if ($id_to_delete > 0) {
        $db->begin();
        $sql_del = "DELETE FROM ".MAIN_DB_PREFIX."flotte_vehicle WHERE rowid = ".(int)$id_to_delete." AND entity IN (".getEntity('flotte').")";
        $resql_del = $db->query($sql_del);
        if ($resql_del) {
            $db->commit();
            setEventMessages($langs->trans("VehicleDeletedSuccessfully"), null, 'mesgs');
        } else {
            $db->rollback();
            setEventMessages("Error in SQL: ".$db->lasterror(), null, 'errors');
        }
    }
    $action = 'list';
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

$num = 0;
if ($resql) {
    $num = $db->num_rows($resql);
}

// Page header
llxHeader('', $langs->trans("Vehicles List"), '');

// Page title and buttons
$newCardButton = '';
if ($user->rights->flotte->write) {
    $newCardButton = dolGetButtonTitle($langs->trans('New Vehicle'), '', 'fa fa-plus-circle', dol_buildpath('/flotte/vehicle_card.php', 1).'?action=create', '', $user->rights->flotte->read);
}

// Show delete confirmation dialog if requested
if ($action == 'delete') {
    $id_to_delete = GETPOST('id', 'int');
    if ($id_to_delete > 0) {
        $formconfirm = $form->formconfirm($_SERVER["PHP_SELF"].'?id='.$id_to_delete, $langs->trans('DeleteVehicle'), $langs->trans('ConfirmDeleteVehicle'), 'confirm_delete', '', 0, 1);
        print $formconfirm;
    }
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

// Build param string for URL
$param = '';
if (!empty($search_ref))           $param .= '&search_ref='.urlencode($search_ref);
if (!empty($search_maker))         $param .= '&search_maker='.urlencode($search_maker);
if (!empty($search_model))         $param .= '&search_model='.urlencode($search_model);
if (!empty($search_license_plate)) $param .= '&search_license_plate='.urlencode($search_license_plate);
if (!empty($search_status))        $param .= '&search_status='.urlencode($search_status);

// Search form
print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'">'."\n";
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="formfilteraction" id="formfilteraction" value="list">';
print '<input type="hidden" name="action" value="list">';
print '<input type="hidden" name="sortfield" value="'.$sortfield.'">';
print '<input type="hidden" name="sortorder" value="'.$sortorder.'">';
print '<input type="hidden" name="page" value="'.$page.'">';

// Print barre liste
print_barre_liste($langs->trans("Vehicles List"), $page, $_SERVER["PHP_SELF"], $param, $sortfield, $sortorder, '', $num, $nbtotalofrecords, '', 0);

print '<div class="div-table-responsive">';
print '<table class="tagtable liste" id="tablelines">'."\n";

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
print '<td class="liste_titre right"></td>'; // Mileage
print '<td class="liste_titre center">';
print $form->selectarray('search_status', array(''=>'', '1'=>$langs->trans('InService'), '0'=>$langs->trans('OutOfService')), $search_status);
print '</td>';
print '<td class="liste_titre maxwidthsearch">';
$searchpicto = $form->showFilterButtons();
print $searchpicto;
print '</td>';
print '</tr>'."\n";

// Table headers
print '<tr class="liste_titre">';
print '<td class="liste_titre"><a class="reposition" href="'.$_SERVER["PHP_SELF"].'?sortfield=t.ref&sortorder='.($sortfield == 't.ref' && $sortorder == 'ASC' ? 'DESC' : 'ASC').'&'.$param.'">'.$langs->trans("Ref");
if ($sortfield == 't.ref') print img_picto('', 'sort'.strtolower($sortorder));
print '</a></td>';
print '<td class="liste_titre"><a class="reposition" href="'.$_SERVER["PHP_SELF"].'?sortfield=t.maker&sortorder='.($sortfield == 't.maker' && $sortorder == 'ASC' ? 'DESC' : 'ASC').'&'.$param.'">'.$langs->trans("Maker");
if ($sortfield == 't.maker') print img_picto('', 'sort'.strtolower($sortorder));
print '</a></td>';
print '<td class="liste_titre"><a class="reposition" href="'.$_SERVER["PHP_SELF"].'?sortfield=t.model&sortorder='.($sortfield == 't.model' && $sortorder == 'ASC' ? 'DESC' : 'ASC').'&'.$param.'">'.$langs->trans("VehicleModel");
if ($sortfield == 't.model') print img_picto('', 'sort'.strtolower($sortorder));
print '</a></td>';
print '<td class="liste_titre"><a class="reposition" href="'.$_SERVER["PHP_SELF"].'?sortfield=t.type&sortorder='.($sortfield == 't.type' && $sortorder == 'ASC' ? 'DESC' : 'ASC').'&'.$param.'">'.$langs->trans("Type");
if ($sortfield == 't.type') print img_picto('', 'sort'.strtolower($sortorder));
print '</a></td>';
print '<td class="liste_titre"><a class="reposition" href="'.$_SERVER["PHP_SELF"].'?sortfield=t.year&sortorder='.($sortfield == 't.year' && $sortorder == 'ASC' ? 'DESC' : 'ASC').'&'.$param.'">'.$langs->trans("Year");
if ($sortfield == 't.year') print img_picto('', 'sort'.strtolower($sortorder));
print '</a></td>';
print '<td class="liste_titre"><a class="reposition" href="'.$_SERVER["PHP_SELF"].'?sortfield=t.license_plate&sortorder='.($sortfield == 't.license_plate' && $sortorder == 'ASC' ? 'DESC' : 'ASC').'&'.$param.'">'.$langs->trans("LicensePlate");
if ($sortfield == 't.license_plate') print img_picto('', 'sort'.strtolower($sortorder));
print '</a></td>';
print '<td class="liste_titre"><a class="reposition" href="'.$_SERVER["PHP_SELF"].'?sortfield=t.color&sortorder='.($sortfield == 't.color' && $sortorder == 'ASC' ? 'DESC' : 'ASC').'&'.$param.'">'.$langs->trans("Color");
if ($sortfield == 't.color') print img_picto('', 'sort'.strtolower($sortorder));
print '</a></td>';
print '<td class="liste_titre"><a class="reposition" href="'.$_SERVER["PHP_SELF"].'?sortfield=t.vin&sortorder='.($sortfield == 't.vin' && $sortorder == 'ASC' ? 'DESC' : 'ASC').'&'.$param.'">'.$langs->trans("VIN");
if ($sortfield == 't.vin') print img_picto('', 'sort'.strtolower($sortorder));
print '</a></td>';
print '<td class="liste_titre right"><a class="reposition" href="'.$_SERVER["PHP_SELF"].'?sortfield=t.initial_mileage&sortorder='.($sortfield == 't.initial_mileage' && $sortorder == 'ASC' ? 'DESC' : 'ASC').'&'.$param.'">'.$langs->trans("Mileage");
if ($sortfield == 't.initial_mileage') print img_picto('', 'sort'.strtolower($sortorder));
print '</a></td>';
print '<td class="liste_titre center">';
print '<a class="reposition" href="'.$_SERVER["PHP_SELF"].'?sortfield=t.in_service&sortorder='.($sortfield == 't.in_service' && $sortorder == 'ASC' ? 'DESC' : 'ASC').$param.'">';
print $langs->trans("Status");
if ($sortfield == 't.in_service') print img_picto('', 'sort'.strtolower($sortorder));
print '</a></td>';
print '<td class="liste_titre maxwidthsearch">'.$langs->trans("Action").'</td>';
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
            print dolGetStatus($langs->trans('InService'), '', '', 'status4', 1);
        } else {
            print dolGetStatus($langs->trans('OutOfService'), '', '', 'status8', 1);
        }
        print '</td>';
        
        // Actions
        print '<td class="nowrap center">';
        if ($user->rights->flotte->write) {
            print '<a class="editfielda" href="'.dol_buildpath('/flotte/vehicle_card.php', 1).'?id='.$obj->rowid.'&action=edit" title="'.$langs->trans("Edit").'">'.img_edit($langs->trans("Edit")).'</a>';
        }
        if ($user->rights->flotte->delete) {
            print '<a class="editfielda" href="'.dol_buildpath('/flotte/vehicle_list.php', 1).'?action=delete&id='.$obj->rowid.'&token='.newToken().'" title="'.$langs->trans("Delete").'">'.img_delete($langs->trans("Delete")).'</a>';
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
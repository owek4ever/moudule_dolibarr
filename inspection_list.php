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
$search_registration = GETPOST('search_registration', 'alpha');
$search_date_out = GETPOST('search_date_out', 'alpha');
$search_date_in = GETPOST('search_date_in', 'alpha');

$limit = GETPOST('limit', 'int') ? GETPOST('limit', 'int') : $conf->liste_limit;
$sortfield = GETPOST('sortfield', 'aZ09comma');
$sortorder = GETPOST('sortorder', 'aZ09comma');
$page = GETPOSTISSET('pageplusone') ? (GETPOST('pageplusone') - 1) : GETPOST("page", 'int');

if (empty($page) || $page == -1) {
    $page = 0;
}

$offset = $limit * $page;

if (!$sortfield) {
    $sortfield = "i.tms";
}
if (!$sortorder) {
    $sortorder = "DESC";
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
    $search_vehicle = '';
    $search_registration = '';
    $search_date_out = '';
    $search_date_in = '';
}

// Delete action
if ($action == 'confirm_delete' && $confirm == 'yes' && $user->rights->flotte->write) {
    $id = GETPOST('id', 'int');
    if ($id > 0) {
        $sql = "DELETE FROM ".MAIN_DB_PREFIX."flotte_inspection WHERE rowid = ".(int)$id;
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
$sql = 'SELECT i.rowid, i.ref, i.registration_number, i.meter_out, i.meter_in, i.fuel_out, i.fuel_in,';
$sql .= ' i.datetime_out, i.datetime_in, i.petrol_card, i.lights_indicators, i.inverter_cigarette,';
$sql .= ' i.mats_seats, i.interior_damage, i.interior_lights, i.exterior_damage, i.tyres_condition,';
$sql .= ' i.ladders, i.extension_leeds, i.power_tools, i.ac_working, i.headlights_working,';
$sql .= ' i.locks_alarms, i.windows_condition, i.seats_condition, i.oil_check, i.suspension,';
$sql .= ' i.toolboxes_condition, i.tms,';
$sql .= ' v.ref as vehicle_ref, v.maker, v.model, v.license_plate';
$sql .= ' FROM '.MAIN_DB_PREFIX.'flotte_inspection as i';
$sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'flotte_vehicle as v ON i.fk_vehicle = v.rowid';
$sql .= ' WHERE 1 = 1';
$sql .= ' AND i.entity IN ('.getEntity('flotte').')';

// Add search filters
if ($search_ref) {
    $sql .= " AND i.ref LIKE '%".$db->escape($search_ref)."%'";
}
if ($search_vehicle) {
    $sql .= " AND (v.ref LIKE '%".$db->escape($search_vehicle)."%' OR v.maker LIKE '%".$db->escape($search_vehicle)."%' OR v.model LIKE '%".$db->escape($search_vehicle)."%')";
}
if ($search_registration) {
    $sql .= " AND i.registration_number LIKE '%".$db->escape($search_registration)."%'";
}
if ($search_date_out) {
    $sql .= " AND DATE(i.datetime_out) = '".$db->escape($search_date_out)."'";
}
if ($search_date_in) {
    $sql .= " AND DATE(i.datetime_in) = '".$db->escape($search_date_in)."'";
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

$form = new Form($db);
$num = 0;
if ($resql) {
    $num = $db->num_rows($resql);
}

// Page header
llxHeader('', $langs->trans("InspectionsList"), '');

// Page title and buttons
$newCardButton = '';
if ($user->rights->flotte->write) {
    $newCardButton = dolGetButtonTitle($langs->trans('New Inspection'), '', 'fa fa-plus-circle', dol_buildpath('/flotte/inspection_card.php', 1).'?action=create', '', $user->rights->flotte->read);
}

// Actions bar
print '<div class="tabsAction">'."\n";
if ($user->rights->flotte->write) {
    print '<a class="butAction" href="'.dol_buildpath('/flotte/inspection_card.php', 1).'?action=create">'.$langs->trans("New Inspection").'</a>'."\n";
}
if ($user->rights->flotte->read) {
    print '<a class="butAction" href="'.dol_buildpath('/flotte/inspection_list.php', 1).'?action=export">'.$langs->trans("Export").'</a>'."\n";
}
print '</div>'."\n";

// Build param string for URL
$param = '';
if (!empty($search_ref))           $param .= '&search_ref='.urlencode($search_ref);
if (!empty($search_vehicle))       $param .= '&search_vehicle='.urlencode($search_vehicle);
if (!empty($search_registration))  $param .= '&search_registration='.urlencode($search_registration);
if (!empty($search_date_out))      $param .= '&search_date_out='.urlencode($search_date_out);
if (!empty($search_date_in))       $param .= '&search_date_in='.urlencode($search_date_in);

// Confirmation to delete
if ($action == 'delete') {
    $id = GETPOST('id', 'int');
    $formconfirm = $form->formconfirm($_SERVER["PHP_SELF"].'?id='.$id.$param, $langs->trans('DeleteInspection'), $langs->trans('ConfirmDeleteInspection'), 'confirm_delete', '', 0, 1);
    print $formconfirm;
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
print_barre_liste($langs->trans("InspectionsList"), $page, $_SERVER["PHP_SELF"], $param, $sortfield, $sortorder, '', $num, $nbtotalofrecords, 'clipboard-list', 0);

print '<div class="div-table-responsive">';
print '<table class="tagtable liste" id="tablelines">'."\n";

// Search fields
print '<tr class="liste_titre_filter">';
print '<td class="liste_titre"><input type="text" class="flat maxwidth100" name="search_ref" value="'.dol_escape_htmltag($search_ref).'"></td>';
print '<td class="liste_titre"><input type="text" class="flat maxwidth100" name="search_vehicle" value="'.dol_escape_htmltag($search_vehicle).'"></td>';
print '<td class="liste_titre"><input type="text" class="flat maxwidth100" name="search_registration" value="'.dol_escape_htmltag($search_registration).'"></td>';
print '<td class="liste_titre center"><input type="date" class="flat maxwidth100" name="search_date_out" value="'.dol_escape_htmltag($search_date_out).'" style="text-align: center;"></td>';
print '<td class="liste_titre center"><input type="date" class="flat maxwidth100" name="search_date_in" value="'.dol_escape_htmltag($search_date_in).'" style="text-align: center;"></td>';
print '<td class="liste_titre maxwidthsearch">';
$searchpicto = $form->showFilterButtons();
print $searchpicto;
print '</td>';
print '</tr>'."\n";

// Table headers
print '<tr class="liste_titre">';
print_liste_field_titre("Ref", $_SERVER["PHP_SELF"], "i.ref", "", $param, '', $sortfield, $sortorder);
print_liste_field_titre("Vehicle", $_SERVER["PHP_SELF"], "v.ref", "", $param, '', $sortfield, $sortorder);
print_liste_field_titre("Registration", $_SERVER["PHP_SELF"], "i.registration_number", "", $param, '', $sortfield, $sortorder);
// Manually create centered headers for date columns
print '<td class="liste_titre center">';
print '<a class="reposition" href="'.$_SERVER["PHP_SELF"].'?sortfield=i.datetime_out&sortorder='.($sortfield == 'i.datetime_out' && $sortorder == 'ASC' ? 'DESC' : 'ASC').$param.'">';
print $langs->trans("DateOut");
if ($sortfield == 'i.datetime_out') print img_picto('', 'sort'.strtolower($sortorder));
print '</a></td>';
print '<td class="liste_titre center">';
print '<a class="reposition" href="'.$_SERVER["PHP_SELF"].'?sortfield=i.datetime_in&sortorder='.($sortfield == 'i.datetime_in' && $sortorder == 'ASC' ? 'DESC' : 'ASC').$param.'">';
print $langs->trans("DateIn");
if ($sortfield == 'i.datetime_in') print img_picto('', 'sort'.strtolower($sortorder));
print '</a></td>';
print_liste_field_titre("Action", $_SERVER["PHP_SELF"], "", "", "", '', '', '', 'maxwidthsearch ');
print '</tr>'."\n";

// Display data
if ($resql && $num > 0) {
    $i = 0;
    while ($i < min($num, $limit)) {
        $obj = $db->fetch_object($resql);
        if (empty($obj)) break;
        
        // Calculate condition score
        $condition_score = 0;
        $total_checks = 0;
        
        $checks = array(
            'petrol_card' => $obj->petrol_card,
            'lights_indicators' => $obj->lights_indicators,
            'inverter_cigarette' => $obj->inverter_cigarette,
            'mats_seats' => $obj->mats_seats,
            'interior_damage' => !$obj->interior_damage,
            'interior_lights' => $obj->interior_lights,
            'exterior_damage' => !$obj->exterior_damage,
            'tyres_condition' => $obj->tyres_condition,
            'ladders' => $obj->ladders,
            'extension_leeds' => $obj->extension_leeds,
            'power_tools' => $obj->power_tools,
            'ac_working' => $obj->ac_working,
            'headlights_working' => $obj->headlights_working,
            'locks_alarms' => $obj->locks_alarms,
            'windows_condition' => $obj->windows_condition,
            'seats_condition' => $obj->seats_condition,
            'oil_check' => $obj->oil_check,
            'suspension' => $obj->suspension,
            'toolboxes_condition' => $obj->toolboxes_condition
        );
        
        foreach ($checks as $check => $value) {
            if ($value !== null && $value !== '') {
                $total_checks++;
                if ($value) $condition_score++;
            }
        }
        
        $condition_percentage = $total_checks > 0 ? round(($condition_score / $total_checks) * 100) : 0;
        
        // Calculate distance
        $distance = '';
        if ($obj->meter_out && $obj->meter_in && $obj->meter_in > $obj->meter_out) {
            $distance = number_format($obj->meter_in - $obj->meter_out) . ' km';
        }
        
        print '<tr class="oddeven">';
        
        // Reference
        print '<td class="nowrap"><a href="'.dol_buildpath('/flotte/inspection_card.php', 1).'?id='.$obj->rowid.'">';
        print img_object($langs->trans("ShowInspection"), "clipboard-list", 'class="pictofixedwidth"');
        print '<strong>'.dol_escape_htmltag($obj->ref).'</strong></a></td>';
        
        // Vehicle
        print '<td>';
        if ($obj->vehicle_ref) {
            print dol_escape_htmltag($obj->vehicle_ref);
            if ($obj->maker || $obj->model) {
                print '<br><small style="color: #666;">'.dol_escape_htmltag(trim($obj->maker . ' ' . $obj->model)).'</small>';
            }
        } else {
            print '<em style="color: #999;">'.$langs->trans("NoVehicleAssigned").'</em>';
        }
        print '</td>';
        
        // Registration
        print '<td>'.dol_escape_htmltag($obj->registration_number ?: '-').'</td>';
        
        // Date Out
        print '<td class="center">';
        if ($obj->datetime_out) {
            print dol_print_date($obj->datetime_out, 'dayhour');
        } else {
            print '-';
        }
        print '</td>';
        
        // Date In
        print '<td class="center">';
        if ($obj->datetime_in) {
            print dol_print_date($obj->datetime_in, 'dayhour');
        } else {
            print '-';
        }
        print '</td>';
        
        // Actions
        print '<td class="nowrap center">';
        if ($user->rights->flotte->write) {
            print '<a class="editfielda reposition" href="'.$_SERVER["PHP_SELF"].'?id='.$obj->rowid.'&action=delete&token='.newToken().'" title="'.$langs->trans("Delete").'">'.img_delete($langs->trans("Delete")).'</a>';
        }
        if ($user->rights->flotte->write) {
            print '<a class="editfielda" href="'.dol_buildpath('/flotte/inspection_card.php', 1).'?id='.$obj->rowid.'&action=edit" title="'.$langs->trans("Edit").'">'.img_edit($langs->trans("Edit")).'</a>';
        }
        print '</td>';
        
        print '</tr>'."\n";
        $i++;
    }
} else {
    $colspan = 6;
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
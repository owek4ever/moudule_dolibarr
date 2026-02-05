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
$search_firstname = GETPOST('search_firstname', 'alpha');
$search_lastname = GETPOST('search_lastname', 'alpha');
$search_phone = GETPOST('search_phone', 'alpha');
$search_status = GETPOST('search_status', 'alpha');
$search_employee_id = GETPOST('search_employee_id', 'alpha');
$search_license_number = GETPOST('search_license_number', 'alpha');

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

// Initialize technical objects
$form = new Form($db);
$formcompany = new FormCompany($db);
$hookmanager->initHooks(array('driverlist', 'globalcard'));

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
    $search_firstname = '';
    $search_lastname = '';
    $search_phone = '';
    $search_status = '';
    $search_employee_id = '';
    $search_license_number = '';
}

// Handle confirmed delete from list page
if ($action == 'confirm_delete' && GETPOST('confirm', 'alpha') == 'yes') {
    $id_to_delete = GETPOST('id', 'int');
    if ($id_to_delete > 0) {
        $db->begin();
        $sql_del = "DELETE FROM ".MAIN_DB_PREFIX."flotte_driver WHERE rowid = ".(int)$id_to_delete." AND entity IN (".getEntity('flotte').")";
        $resql_del = $db->query($sql_del);
        if ($resql_del) {
            // Delete associated files
            $uploadDir = $conf->flotte->dir_output . '/drivers/' . $id_to_delete . '/';
            if (is_dir($uploadDir)) {
                dol_delete_dir_recursive($uploadDir);
            }
            $db->commit();
            setEventMessages($langs->trans("DriverDeletedSuccessfully"), null, 'mesgs');
        } else {
            $db->rollback();
            setEventMessages("Error in SQL: ".$db->lasterror(), null, 'errors');
        }
    }
    $action = 'list';
}

// Build and execute select
$sql = 'SELECT t.rowid, t.ref, t.firstname, t.middlename, t.lastname, t.phone, t.email, t.status, t.license_number, t.employee_id, t.department, t.gender, t.join_date, t.fk_vehicle';
$sql .= ', v.ref as vehicle_ref, v.maker as vehicle_maker, v.model as vehicle_model';
$sql .= ' FROM '.MAIN_DB_PREFIX.'flotte_driver as t';
$sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'flotte_vehicle as v ON t.fk_vehicle = v.rowid';
$sql .= ' WHERE 1 = 1';
$sql .= ' AND t.entity IN ('.getEntity('flotte').')';

if ($search_ref) {
    $sql .= " AND t.ref LIKE '%".$db->escape($search_ref)."%'";
}
if ($search_firstname) {
    $sql .= " AND t.firstname LIKE '%".$db->escape($search_firstname)."%'";
}
if ($search_lastname) {
    $sql .= " AND t.lastname LIKE '%".$db->escape($search_lastname)."%'";
}
if ($search_phone) {
    $sql .= " AND t.phone LIKE '%".$db->escape($search_phone)."%'";
}
if ($search_status) {
    $sql .= " AND t.status = '".$db->escape($search_status)."'";
}
if ($search_employee_id) {
    $sql .= " AND t.employee_id LIKE '%".$db->escape($search_employee_id)."%'";
}
if ($search_license_number) {
    $sql .= " AND t.license_number LIKE '%".$db->escape($search_license_number)."%'";
}

$sql .= $db->order($sortfield, $sortorder);

// Count total nb of records
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
llxHeader('', $langs->trans("DrivervsList"), '');

// Page title and buttons
$newCardButton = '';
if ($user->rights->flotte->write) {
    $newCardButton = dolGetButtonTitle($langs->trans('New Driver'), '', 'fa fa-plus-circle', dol_buildpath('/flotte/driver_card.php', 1).'?action=create', '', $user->rights->flotte->read);
}

// Show delete confirmation dialog if requested
if ($action == 'delete') {
    $id_to_delete = GETPOST('id', 'int');
    if ($id_to_delete > 0) {
        $formconfirm = $form->formconfirm($_SERVER["PHP_SELF"].'?id='.$id_to_delete, $langs->trans('DeleteDriver'), $langs->trans('ConfirmDeleteDriver'), 'confirm_delete', '', 0, 1);
        print $formconfirm;
    }
}

// Actions bar
print '<div class="tabsAction">'."\n";
if ($user->rights->flotte->write) {
    print '<a class="butAction" href="'.dol_buildpath('/flotte/driver_card.php', 1).'?action=create">'.$langs->trans("New Driver").'</a>'."\n";
}
if ($user->rights->flotte->read) {
    print '<a class="butAction" href="'.dol_buildpath('/flotte/driver_list.php', 1).'?action=export">'.$langs->trans("Export").'</a>'."\n";
}
print '</div>'."\n";

// Build param string for URL
$param = '';
if (!empty($search_ref))           $param .= '&search_ref='.urlencode($search_ref);
if (!empty($search_firstname))     $param .= '&search_firstname='.urlencode($search_firstname);
if (!empty($search_lastname))      $param .= '&search_lastname='.urlencode($search_lastname);
if (!empty($search_phone))         $param .= '&search_phone='.urlencode($search_phone);
if (!empty($search_status))        $param .= '&search_status='.urlencode($search_status);
if (!empty($search_employee_id))   $param .= '&search_employee_id='.urlencode($search_employee_id);
if (!empty($search_license_number)) $param .= '&search_license_number='.urlencode($search_license_number);

// Search form
print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'">'."\n";
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="formfilteraction" id="formfilteraction" value="list">';
print '<input type="hidden" name="action" value="list">';
print '<input type="hidden" name="sortfield" value="'.$sortfield.'">';
print '<input type="hidden" name="sortorder" value="'.$sortorder.'">';
print '<input type="hidden" name="page" value="'.$page.'">';

// Print barre liste
print_barre_liste($langs->trans("DriversList"), $page, $_SERVER["PHP_SELF"], $param, $sortfield, $sortorder, '', $num, $nbtotalofrecords, '', 0);

print '<div class="div-table-responsive">';
print '<table class="tagtable liste" id="tablelines">'."\n";

// Search fields
print '<tr class="liste_titre_filter">';
print '<td class="liste_titre"><input type="text" class="flat maxwidth100" name="search_ref" value="'.dol_escape_htmltag($search_ref).'"></td>';
print '<td class="liste_titre"><input type="text" class="flat maxwidth100" name="search_firstname" value="'.dol_escape_htmltag($search_firstname).'"></td>';
print '<td class="liste_titre"><input type="text" class="flat maxwidth100" name="search_lastname" value="'.dol_escape_htmltag($search_lastname).'"></td>';
print '<td class="liste_titre"><input type="text" class="flat maxwidth100" name="search_phone" value="'.dol_escape_htmltag($search_phone).'"></td>';
print '<td class="liste_titre"><input type="text" class="flat maxwidth100" name="search_employee_id" value="'.dol_escape_htmltag($search_employee_id).'"></td>';
print '<td class="liste_titre"><input type="text" class="flat maxwidth100" name="search_license_number" value="'.dol_escape_htmltag($search_license_number).'"></td>';
print '<td class="liste_titre"></td>'; // Department
print '<td class="liste_titre"></td>'; // Vehicle
print '<td class="liste_titre center">';
$statusarray = array(''=>'', 'active'=>$langs->trans('Active'), 'inactive'=>$langs->trans('Inactive'), 'suspended'=>$langs->trans('Suspended'));
print $form->selectarray('search_status', $statusarray, $search_status, 0, 0, 0, '', 0, 0, 0, '', 'search_status width100 onrightofpage');
print '</td>';
print '<td class="liste_titre maxwidthsearch">';
$searchpicto = $form->showFilterButtons();
print $searchpicto;
print '</td>';
print '</tr>'."\n";

// Table headers
print '<tr class="liste_titre">';
print_liste_field_titre("Ref", $_SERVER["PHP_SELF"], "t.ref", "", $param, '', $sortfield, $sortorder);
print_liste_field_titre("FirstName", $_SERVER["PHP_SELF"], "t.firstname", "", $param, '', $sortfield, $sortorder);
print_liste_field_titre("LastName", $_SERVER["PHP_SELF"], "t.lastname", "", $param, '', $sortfield, $sortorder);
print_liste_field_titre("Phone", $_SERVER["PHP_SELF"], "t.phone", "", $param, '', $sortfield, $sortorder);
print_liste_field_titre("EmployeeID", $_SERVER["PHP_SELF"], "t.employee_id", "", $param, '', $sortfield, $sortorder);
print_liste_field_titre("LicenseNumber", $_SERVER["PHP_SELF"], "t.license_number", "", $param, '', $sortfield, $sortorder);
print_liste_field_titre("Department", $_SERVER["PHP_SELF"], "t.department", "", $param, '', $sortfield, $sortorder);
print_liste_field_titre("AssignedVehicle", $_SERVER["PHP_SELF"], "v.ref", "", $param, '', $sortfield, $sortorder);
// Manually create centered header for status column like inspection list
print '<td class="liste_titre center">';
print '<a class="reposition" href="'.$_SERVER["PHP_SELF"].'?sortfield=t.status&sortorder='.($sortfield == 't.status' && $sortorder == 'ASC' ? 'DESC' : 'ASC').$param.'">';
print $langs->trans("Status");
if ($sortfield == 't.status') print img_picto('', 'sort'.strtolower($sortorder));
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
        
        // Reference
        print '<td class="nowrap"><a href="'.dol_buildpath('/flotte/driver_card.php', 1).'?id='.$obj->rowid.'">';
        print '<strong>'.dol_escape_htmltag($obj->ref).'</strong></a></td>';
        
        // First Name
        print '<td>'.dol_escape_htmltag($obj->firstname).'</td>';
        
        // Last Name
        print '<td>'.dol_escape_htmltag($obj->lastname).'</td>';
        
        // Phone
        print '<td>'.dol_escape_htmltag($obj->phone ?: '-').'</td>';
        
        // Employee ID
        print '<td>'.dol_escape_htmltag($obj->employee_id ?: '-').'</td>';
        
        // License Number
        print '<td>'.dol_escape_htmltag($obj->license_number ?: '-').'</td>';
        
        // Department
        print '<td>'.dol_escape_htmltag($obj->department ?: '-').'</td>';
        
        // Assigned Vehicle
        print '<td>';
        if ($obj->vehicle_ref) {
            print dol_escape_htmltag($obj->vehicle_ref);
            if ($obj->vehicle_maker || $obj->vehicle_model) {
                print '<br><small style="color: #666;">'.dol_escape_htmltag(trim($obj->vehicle_maker . ' ' . $obj->vehicle_model)).'</small>';
            }
        } else {
            print '<em style="color: #999;">'.$langs->trans("NotAssigned").'</em>';
        }
        print '</td>';
        
        // Status
        print '<td class="center">';
        if ($obj->status == 'active') {
            print dolGetStatus($langs->trans('Active'), '', '', 'status4', 1);
        } elseif ($obj->status == 'suspended') {
            print dolGetStatus($langs->trans('Suspended'), '', '', 'status8', 1);
        } else {
            print dolGetStatus($langs->trans('Inactive'), '', '', 'status9', 1);
        }
        print '</td>';
        
        // Actions
        print '<td class="nowrap center">';
        if ($user->rights->flotte->write) {
            print '<a class="editfielda" href="'.dol_buildpath('/flotte/driver_card.php', 1).'?id='.$obj->rowid.'&action=edit" title="'.$langs->trans("Edit").'">'.img_edit($langs->trans("Edit")).'</a>';
        }
        if ($user->rights->flotte->delete) {
            print '<a class="editfielda" href="'.dol_buildpath('/flotte/driver_list.php', 1).'?action=delete&id='.$obj->rowid.'&token='.newToken().'" title="'.$langs->trans("Delete").'">'.img_delete($langs->trans("Delete")).'</a>';
        }
        print '</td>';
        
        print '</tr>'."\n";
        $i++;
    }
} else {
    $colspan = 10;
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
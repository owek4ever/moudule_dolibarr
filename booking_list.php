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
$search_driver = GETPOST('search_driver', 'alpha');
$search_customer = GETPOST('search_customer', 'alpha');
$search_status = GETPOST('search_status', 'alpha');
$search_date_from = GETPOST('search_date_from', 'alpha');
$search_date_to = GETPOST('search_date_to', 'alpha');

$limit = GETPOST('limit', 'int') ? GETPOST('limit', 'int') : $conf->liste_limit;
$sortfield = GETPOST('sortfield', 'aZ09comma');
$sortorder = GETPOST('sortorder', 'aZ09comma');
$page = GETPOSTISSET('pageplusone') ? (GETPOST('pageplusone') - 1) : GETPOST("page", 'int');

if (empty($page) || $page == -1) {
    $page = 0;
}

$offset = $limit * $page;

if (!$sortfield) {
    $sortfield = "t.booking_date";
}
if (!$sortorder) {
    $sortorder = "DESC";
}

// Initialize technical objects
$form = new Form($db);
$formcompany = new FormCompany($db);
$hookmanager->initHooks(array('bookinglist', 'globalcard'));

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
    $search_driver = '';
    $search_customer = '';
    $search_status = '';
    $search_date_from = '';
    $search_date_to = '';
}

// Handle confirmed delete from list page
if ($action == 'confirm_delete' && GETPOST('confirm', 'alpha') == 'yes') {
    $id_to_delete = GETPOST('id', 'int');
    if ($id_to_delete > 0) {
        $db->begin();
        $sql_del = "DELETE FROM ".MAIN_DB_PREFIX."flotte_booking WHERE rowid = ".(int)$id_to_delete." AND entity IN (".getEntity('flotte').")";
        $resql_del = $db->query($sql_del);
        if ($resql_del) {
            // Delete associated files if any
            $uploadDir = $conf->flotte->dir_output . '/bookings/' . $id_to_delete . '/';
            if (is_dir($uploadDir)) {
                dol_delete_dir_recursive($uploadDir);
            }
            $db->commit();
            setEventMessages($langs->trans("BookingDeletedSuccessfully"), null, 'mesgs');
        } else {
            $db->rollback();
            setEventMessages("Error in SQL: ".$db->lasterror(), null, 'errors');
        }
    }
    $action = 'list';
}

// Build and execute select
$sql = 'SELECT t.rowid, t.ref, t.booking_date, t.status, t.distance, t.arriving_address, t.departure_address, t.buying_amount, t.selling_amount,';
$sql .= ' v.ref as vehicle_ref, v.maker, v.model, v.license_plate,';
$sql .= ' d.firstname as driver_firstname, d.lastname as driver_lastname,';
$sql .= ' c.firstname as customer_firstname, c.lastname as customer_lastname, c.company_name';
$sql .= ' FROM '.MAIN_DB_PREFIX.'flotte_booking as t';
$sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'flotte_vehicle as v ON t.fk_vehicle = v.rowid';
$sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'flotte_driver as d ON t.fk_driver = d.rowid';
$sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'flotte_customer as c ON t.fk_customer = c.rowid';
$sql .= ' WHERE 1 = 1';
$sql .= ' AND t.entity IN ('.getEntity('flotte').')';

if ($search_ref) {
    $sql .= " AND t.ref LIKE '%".$db->escape($search_ref)."%'";
}
if ($search_vehicle) {
    $sql .= " AND (v.ref LIKE '%".$db->escape($search_vehicle)."%' OR v.maker LIKE '%".$db->escape($search_vehicle)."%' OR v.model LIKE '%".$db->escape($search_vehicle)."%')";
}
if ($search_driver) {
    $sql .= " AND (d.firstname LIKE '%".$db->escape($search_driver)."%' OR d.lastname LIKE '%".$db->escape($search_driver)."%')";
}
if ($search_customer) {
    $sql .= " AND (c.firstname LIKE '%".$db->escape($search_customer)."%' OR c.lastname LIKE '%".$db->escape($search_customer)."%' OR c.company_name LIKE '%".$db->escape($search_customer)."%')";
}
if ($search_status) {
    $sql .= " AND t.status = '".$db->escape($search_status)."'";
}
if ($search_date_from) {
    $sql .= " AND t.booking_date >= '".$db->escape($search_date_from)."'";
}
if ($search_date_to) {
    $sql .= " AND t.booking_date <= '".$db->escape($search_date_to)."'";
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
llxHeader('', $langs->trans("Bookings List"), '');

// Page title and buttons
$newCardButton = '';
if ($user->rights->flotte->write) {
    $newCardButton = dolGetButtonTitle($langs->trans('New Booking'), '', 'fa fa-plus-circle', dol_buildpath('/flotte/booking_card.php', 1).'?action=create', '', $user->rights->flotte->read);
}

// Show delete confirmation dialog if requested
if ($action == 'delete') {
    $id_to_delete = GETPOST('id', 'int');
    if ($id_to_delete > 0) {
        $formconfirm = $form->formconfirm($_SERVER["PHP_SELF"].'?id='.$id_to_delete, $langs->trans('DeleteBooking'), $langs->trans('ConfirmDeleteBooking'), 'confirm_delete', '', 0, 1);
        print $formconfirm;
    }
}

// Actions bar
print '<div class="tabsAction">'."\n";
if ($user->rights->flotte->write) {
    print '<a class="butAction" href="'.dol_buildpath('/flotte/booking_card.php', 1).'?action=create">'.$langs->trans("New Booking").'</a>'."\n";
}
if ($user->rights->flotte->read) {
    print '<a class="butAction" href="'.dol_buildpath('/flotte/booking_list.php', 1).'?action=export">'.$langs->trans("Export").'</a>'."\n";
}
print '</div>'."\n";

// Build param string for URL
$param = '';
if (!empty($search_ref))           $param .= '&search_ref='.urlencode($search_ref);
if (!empty($search_vehicle))       $param .= '&search_vehicle='.urlencode($search_vehicle);
if (!empty($search_driver))        $param .= '&search_driver='.urlencode($search_driver);
if (!empty($search_customer))      $param .= '&search_customer='.urlencode($search_customer);
if (!empty($search_status))        $param .= '&search_status='.urlencode($search_status);
if (!empty($search_date_from))     $param .= '&search_date_from='.urlencode($search_date_from);
if (!empty($search_date_to))       $param .= '&search_date_to='.urlencode($search_date_to);

// Search form
print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'">'."\n";
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="formfilteraction" id="formfilteraction" value="list">';
print '<input type="hidden" name="action" value="list">';
print '<input type="hidden" name="sortfield" value="'.$sortfield.'">';
print '<input type="hidden" name="sortorder" value="'.$sortorder.'">';
print '<input type="hidden" name="page" value="'.$page.'">';

// Print barre liste - removed 'calendar' icon parameter
print_barre_liste($langs->trans("Bookings List"), $page, $_SERVER["PHP_SELF"], $param, $sortfield, $sortorder, '', $num, $nbtotalofrecords, '', 0);

print '<div class="div-table-responsive">';
print '<table class="tagtable liste" id="tablelines">'."\n";

// Search fields - restructured to match driver_list.php style
print '<tr class="liste_titre_filter">';
print '<td class="liste_titre"><input type="text" class="flat maxwidth100" name="search_ref" value="'.dol_escape_htmltag($search_ref).'"></td>';
print '<td class="liste_titre"><input type="text" class="flat maxwidth100" name="search_vehicle" value="'.dol_escape_htmltag($search_vehicle).'"></td>';
print '<td class="liste_titre"><input type="text" class="flat maxwidth100" name="search_driver" value="'.dol_escape_htmltag($search_driver).'"></td>';
print '<td class="liste_titre"><input type="text" class="flat maxwidth100" name="search_customer" value="'.dol_escape_htmltag($search_customer).'"></td>';
print '<td class="liste_titre"></td>'; // Booking Date
print '<td class="liste_titre"></td>'; // Distance
print '<td class="liste_titre"></td>'; // Amount
print '<td class="liste_titre center">';
$statusarray = array(
    '' => '',
    'pending' => $langs->trans('Pending'),
    'confirmed' => $langs->trans('Confirmed'),
    'in_progress' => $langs->trans('InProgress'),
    'completed' => $langs->trans('Completed'),
    'cancelled' => $langs->trans('Cancelled')
);
print $form->selectarray('search_status', $statusarray, $search_status, 0, 0, 0, '', 0, 0, 0, '', 'search_status width100 onrightofpage');
print '</td>';
print '<td class="liste_titre maxwidthsearch">';
$searchpicto = $form->showFilterButtons();
print $searchpicto;
print '</td>';
print '</tr>'."\n";

// Table headers - restructured to match driver_list.php style
print '<tr class="liste_titre">';
print_liste_field_titre("Ref", $_SERVER["PHP_SELF"], "t.ref", "", $param, '', $sortfield, $sortorder);
print_liste_field_titre("Vehicle", $_SERVER["PHP_SELF"], "v.ref", "", $param, '', $sortfield, $sortorder);
print_liste_field_titre("Driver", $_SERVER["PHP_SELF"], "d.lastname", "", $param, '', $sortfield, $sortorder);
print_liste_field_titre("Customer", $_SERVER["PHP_SELF"], "c.lastname", "", $param, '', $sortfield, $sortorder);
print_liste_field_titre("BookingDate", $_SERVER["PHP_SELF"], "t.booking_date", "", $param, '', $sortfield, $sortorder);
print_liste_field_titre("Distance", $_SERVER["PHP_SELF"], "t.distance", "", $param, '', $sortfield, $sortorder);
print_liste_field_titre("Amount", $_SERVER["PHP_SELF"], "t.selling_amount", "", $param, '', $sortfield, $sortorder);
// Manually create centered header for status column like driver list
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
        
        // Reference - Removed the calendar icon
        print '<td class="nowrap"><a href="'.dol_buildpath('/flotte/booking_card.php', 1).'?id='.$obj->rowid.'">';
        print '<strong>'.dol_escape_htmltag($obj->ref).'</strong></a></td>';
        
        // Vehicle - like driver_list.php style
        print '<td>';
        if (!empty($obj->vehicle_ref)) {
            print dol_escape_htmltag($obj->vehicle_ref);
            if ($obj->maker || $obj->model) {
                print '<br><small style="color: #666;">'.dol_escape_htmltag(trim($obj->maker . ' ' . $obj->model)).'</small>';
            }
        } else {
            print '<em style="color: #999;">'.$langs->trans("NotAssigned").'</em>';
        }
        print '</td>';
        
        // Driver - like driver_list.php style
        print '<td>';
        if (!empty($obj->driver_firstname) || !empty($obj->driver_lastname)) {
            print dol_escape_htmltag($obj->driver_firstname.' '.$obj->driver_lastname);
        } else {
            print '<em style="color: #999;">'.$langs->trans("NotAssigned").'</em>';
        }
        print '</td>';
        
        // Customer - like driver_list.php style
        print '<td>';
        if (!empty($obj->customer_firstname) || !empty($obj->customer_lastname) || !empty($obj->company_name)) {
            $customer_info = '';
            if (!empty($obj->customer_firstname) || !empty($obj->customer_lastname)) {
                $customer_info = $obj->customer_firstname.' '.$obj->customer_lastname;
            }
            if (!empty($obj->company_name)) {
                if (!empty($customer_info)) {
                    $customer_info .= ' ('.$obj->company_name.')';
                } else {
                    $customer_info = $obj->company_name;
                }
            }
            print dol_escape_htmltag($customer_info);
        } else {
            print '<em style="color: #999;">'.$langs->trans("NotAssigned").'</em>';
        }
        print '</td>';
        
        // Booking Date
        print '<td class="center">'.dol_print_date($db->jdate($obj->booking_date), 'day').'</td>';
        
        // Distance
        print '<td class="right">'.($obj->distance ? $obj->distance.' km' : '-').'</td>';
        
        // Amount
        print '<td class="right">'.($obj->selling_amount ? price($obj->selling_amount) : '-').'</td>';
        
        // Status - centered like driver_list.php
        print '<td class="center">';
        if ($obj->status == 'pending') {
            print dolGetStatus($langs->trans('Pending'), '', '', 'status0', 1);
        } elseif ($obj->status == 'confirmed') {
            print dolGetStatus($langs->trans('Confirmed'), '', '', 'status1', 1);
        } elseif ($obj->status == 'in_progress') {
            print dolGetStatus($langs->trans('InProgress'), '', '', 'status4', 1);
        } elseif ($obj->status == 'completed') {
            print dolGetStatus($langs->trans('Completed'), '', '', 'status6', 1);
        } elseif ($obj->status == 'cancelled') {
            print dolGetStatus($langs->trans('Cancelled'), '', '', 'status9', 1);
        } else {
            print dolGetStatus($langs->trans('Unknown'), '', '', 'status0', 1);
        }
        print '</td>';
        
        // Actions - like driver_list.php (only edit and delete, no view button)
        print '<td class="nowrap center">';
        if ($user->rights->flotte->write) {
            print '<a class="editfielda" href="'.dol_buildpath('/flotte/booking_card.php', 1).'?id='.$obj->rowid.'&action=edit" title="'.$langs->trans("Edit").'">'.img_edit($langs->trans("Edit")).'</a>';
        }
        if ($user->rights->flotte->delete) {
            print '<a class="editfielda" href="'.dol_buildpath('/flotte/booking_list.php', 1).'?action=delete&id='.$obj->rowid.'&token='.newToken().'" title="'.$langs->trans("Delete").'">'.img_delete($langs->trans("Delete")).'</a>';
        }
        print '</td>';
        
        print '</tr>'."\n";
        $i++;
    }
} else {
    $colspan = 9;
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
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
$search_vendor = GETPOST('search_vendor', 'alpha');
$search_status = GETPOST('search_status', 'alpha');
$search_required_by = GETPOST('search_required_by', 'alpha');

$limit = GETPOST('limit', 'int') ? GETPOST('limit', 'int') : $conf->liste_limit;
$sortfield = GETPOST('sortfield', 'aZ09comma');
$sortorder = GETPOST('sortorder', 'aZ09comma');
$page = GETPOSTISSET('pageplusone') ? (GETPOST('pageplusone') - 1) : GETPOST("page", 'int');

if (empty($page) || $page == -1) {
    $page = 0;
}

$offset = $limit * $page;

if (!$sortfield) {
    $sortfield = "t.required_by";
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
    $search_vendor = '';
    $search_status = '';
    $search_required_by = '';
}

// Delete action
if ($action == 'confirm_delete' && $confirm == 'yes' && $user->rights->flotte->write) {
    $id = GETPOST('id', 'int');
    if ($id > 0) {
        $sql = "DELETE FROM ".MAIN_DB_PREFIX."flotte_workorder WHERE rowid = ".(int)$id;
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
$sql = 'SELECT t.rowid, t.ref, t.required_by, t.reading, t.note, t.status, t.price, t.description,';
$sql .= ' v.ref as vehicle_ref, v.maker, v.model,';
$sql .= ' ven.name as vendor_name';
$sql .= ' FROM '.MAIN_DB_PREFIX.'flotte_workorder as t';
$sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'flotte_vehicle as v ON t.fk_vehicle = v.rowid';
$sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'flotte_vendor as ven ON t.fk_vendor = ven.rowid';
$sql .= ' WHERE 1 = 1';
$sql .= ' AND t.entity IN ('.getEntity('flotte').')';

// Add search filters
if ($search_ref) {
    $sql .= " AND t.ref LIKE '%".$db->escape($search_ref)."%'";
}
if ($search_vehicle) {
    $sql .= " AND (v.ref LIKE '%".$db->escape($search_vehicle)."%' OR v.maker LIKE '%".$db->escape($search_vehicle)."%' OR v.model LIKE '%".$db->escape($search_vehicle)."%')";
}
if ($search_vendor) {
    $sql .= " AND ven.name LIKE '%".$db->escape($search_vendor)."%'";
}
if ($search_status) {
    $sql .= " AND t.status = '".$db->escape($search_status)."'";
}
if ($search_required_by) {
    $sql .= " AND t.required_by = '".$db->escape($search_required_by)."'";
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
llxHeader('', $langs->trans("WorkOrdersList"), '');

// Page title and buttons
$newCardButton = '';
if ($user->rights->flotte->write) {
    $newCardButton = dolGetButtonTitle($langs->trans('New WorkOrder'), '', 'fa fa-plus-circle', dol_buildpath('/flotte/workorder_card.php', 1).'?action=create', '', $user->rights->flotte->read);
}

// Actions bar
print '<div class="tabsAction">'."\n";
if ($user->rights->flotte->write) {
    print '<a class="butAction" href="'.dol_buildpath('/flotte/workorder_card.php', 1).'?action=create">'.$langs->trans("New WorkOrder").'</a>'."\n";
}
if ($user->rights->flotte->read) {
    print '<a class="butAction" href="'.dol_buildpath('/flotte/workorder_list.php', 1).'?action=export">'.$langs->trans("Export").'</a>'."\n";
}
print '</div>'."\n";

// Build param string for URL
$param = '';
if (!empty($search_ref))           $param .= '&search_ref='.urlencode($search_ref);
if (!empty($search_vehicle))       $param .= '&search_vehicle='.urlencode($search_vehicle);
if (!empty($search_vendor))        $param .= '&search_vendor='.urlencode($search_vendor);
if (!empty($search_status))        $param .= '&search_status='.urlencode($search_status);
if (!empty($search_required_by))   $param .= '&search_required_by='.urlencode($search_required_by);

// Confirmation to delete
if ($action == 'delete') {
    $id = GETPOST('id', 'int');
    $formconfirm = $form->formconfirm($_SERVER["PHP_SELF"].'?id='.$id.$param, $langs->trans('DeleteWorkOrder'), $langs->trans('ConfirmDeleteWorkOrder'), 'confirm_delete', '', 0, 1);
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
print_barre_liste($langs->trans("WorkOrdersList"), $page, $_SERVER["PHP_SELF"], $param, $sortfield, $sortorder, '', $num, $nbtotalofrecords, 'clipboard-list', 0);

print '<div class="div-table-responsive">';
print '<table class="tagtable liste" id="tablelines">'."\n";

// Search fields
print '<tr class="liste_titre_filter">';
print '<td class="liste_titre"><input type="text" class="flat maxwidth100" name="search_ref" value="'.dol_escape_htmltag($search_ref).'"></td>';
print '<td class="liste_titre"><input type="text" class="flat maxwidth100" name="search_vehicle" value="'.dol_escape_htmltag($search_vehicle).'"></td>';
print '<td class="liste_titre"><input type="text" class="flat maxwidth100" name="search_vendor" value="'.dol_escape_htmltag($search_vendor).'"></td>';
print '<td class="liste_titre center"><input type="date" class="flat maxwidth100" name="search_required_by" value="'.dol_escape_htmltag($search_required_by).'" style="text-align: center;"></td>';
print '<td class="liste_titre right">&nbsp;</td>'; // Reading column - no search
print '<td class="liste_titre right">&nbsp;</td>'; // Price column - no search
print '<td class="liste_titre center">';
$statusarray = array(
    '' => '',
    'Pending' => $langs->trans('Pending'),
    'In Progress' => $langs->trans('InProgress'),
    'Completed' => $langs->trans('Completed'),
    'Cancelled' => $langs->trans('Cancelled')
);
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
print_liste_field_titre("Vehicle", $_SERVER["PHP_SELF"], "v.ref", "", $param, '', $sortfield, $sortorder);
print_liste_field_titre("Vendor", $_SERVER["PHP_SELF"], "ven.name", "", $param, '', $sortfield, $sortorder);
// Manually create centered header for RequiredBy column
print '<td class="liste_titre center">';
print '<a class="reposition" href="'.$_SERVER["PHP_SELF"].'?sortfield=t.required_by&sortorder='.($sortfield == 't.required_by' && $sortorder == 'ASC' ? 'DESC' : 'ASC').$param.'">';
print $langs->trans("RequiredBy");
if ($sortfield == 't.required_by') print img_picto('', 'sort'.strtolower($sortorder));
print '</a></td>';
// Manually create right-aligned header for Reading column
print '<td class="liste_titre right">';
print '<a class="reposition" href="'.$_SERVER["PHP_SELF"].'?sortfield=t.reading&sortorder='.($sortfield == 't.reading' && $sortorder == 'ASC' ? 'DESC' : 'ASC').$param.'">';
print $langs->trans("Reading");
if ($sortfield == 't.reading') print img_picto('', 'sort'.strtolower($sortorder));
print '</a></td>';
// Manually create right-aligned header for Price column
print '<td class="liste_titre right">';
print '<a class="reposition" href="'.$_SERVER["PHP_SELF"].'?sortfield=t.price&sortorder='.($sortfield == 't.price' && $sortorder == 'ASC' ? 'DESC' : 'ASC').$param.'">';
print $langs->trans("Price");
if ($sortfield == 't.price') print img_picto('', 'sort'.strtolower($sortorder));
print '</a></td>';
// Manually create centered header for Status column
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
        print '<td class="nowrap"><a href="'.dol_buildpath('/flotte/workorder_card.php', 1).'?id='.$obj->rowid.'">';
        print '<strong>'.dol_escape_htmltag($obj->ref).'</strong></a></td>';
        
        // Vehicle
        print '<td>';
        if (!empty($obj->vehicle_ref)) {
            $vehicle_info = $obj->vehicle_ref;
            if (!empty($obj->maker) && !empty($obj->model)) {
                $vehicle_info .= ' - '.$obj->maker.' '.$obj->model;
            }
            print dol_escape_htmltag($vehicle_info);
        } else {
            print '<span class="opacitymedium">'.$langs->trans("NotAssigned").'</span>';
        }
        print '</td>';
        
        // Vendor
        print '<td>';
        if (!empty($obj->vendor_name)) {
            print dol_escape_htmltag($obj->vendor_name);
        } else {
            print '<span class="opacitymedium">'.$langs->trans("NotAssigned").'</span>';
        }
        print '</td>';
        
        // Required By
        print '<td class="center">'.dol_print_date($db->jdate($obj->required_by), 'day').'</td>';
        
        // Reading
        print '<td class="right">'.($obj->reading ? number_format($obj->reading, 0) : '-').'</td>';
        
        // Price
        print '<td class="right">'.($obj->price ? price($obj->price) : '-').'</td>';
        
        // Status
        print '<td class="center">';
        if ($obj->status == 'Pending') {
            print dolGetStatus($langs->trans('Pending'), '', '', 'status0', 1);
        } elseif ($obj->status == 'In Progress') {
            print dolGetStatus($langs->trans('InProgress'), '', '', 'status4', 1);
        } elseif ($obj->status == 'Completed') {
            print dolGetStatus($langs->trans('Completed'), '', '', 'status6', 1);
        } elseif ($obj->status == 'Cancelled') {
            print dolGetStatus($langs->trans('Cancelled'), '', '', 'status9', 1);
        } else {
            print dolGetStatus($langs->trans('Unknown'), '', '', 'status0', 1);
        }
        print '</td>';
        
        // Actions
        print '<td class="nowrap center">';
        if ($user->rights->flotte->write) {
            print '<a class="editfielda reposition" href="'.$_SERVER["PHP_SELF"].'?id='.$obj->rowid.'&action=delete&token='.newToken().'" title="'.$langs->trans("Delete").'">'.img_delete($langs->trans("Delete")).'</a>';
        }
        if ($user->rights->flotte->write) {
            print '<a class="editfielda" href="'.dol_buildpath('/flotte/workorder_card.php', 1).'?id='.$obj->rowid.'&action=edit" title="'.$langs->trans("Edit").'">'.img_edit($langs->trans("Edit")).'</a>';
        }
        print '</td>';
        
        print '</tr>'."\n";
        $i++;
    }
} else {
    $colspan = 8;
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
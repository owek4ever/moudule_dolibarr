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
$search_name = GETPOST('search_name', 'alpha');
$search_phone = GETPOST('search_phone', 'alpha');
$search_email = GETPOST('search_email', 'alpha');
$search_type = GETPOST('search_type', 'alpha');
$search_city = GETPOST('search_city', 'alpha');
$search_state = GETPOST('search_state', 'alpha');

$limit = GETPOST('limit', 'int') ? GETPOST('limit', 'int') : $conf->liste_limit;
$sortfield = GETPOST('sortfield', 'aZ09comma');
$sortorder = GETPOST('sortorder', 'aZ09comma');
$page = GETPOSTISSET('pageplusone') ? (GETPOST('pageplusone') - 1) : GETPOST("page", 'int');

if (empty($page) || $page == -1) {
    $page = 0;
}

$offset = $limit * $page;

if (!$sortfield) {
    $sortfield = "t.name";
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
    $search_name = '';
    $search_phone = '';
    $search_email = '';
    $search_type = '';
    $search_city = '';
    $search_state = '';
}

// Build and execute select
$sql = 'SELECT t.rowid, t.ref, t.name, t.phone, t.email, t.type, t.website, t.note, t.address1, t.address2, t.city, t.state, t.picture';
$sql .= ' FROM '.MAIN_DB_PREFIX.'flotte_vendor as t';
$sql .= ' WHERE 1 = 1';
$sql .= ' AND t.entity IN ('.getEntity('flotte').')';

if ($search_ref) {
    $sql .= " AND t.ref LIKE '%".$db->escape($search_ref)."%'";
}
if ($search_name) {
    $sql .= " AND t.name LIKE '%".$db->escape($search_name)."%'";
}
if ($search_phone) {
    $sql .= " AND t.phone LIKE '%".$db->escape($search_phone)."%'";
}
if ($search_email) {
    $sql .= " AND t.email LIKE '%".$db->escape($search_email)."%'";
}
if ($search_type) {
    $sql .= " AND t.type = '".$db->escape($search_type)."'";
}
if ($search_city) {
    $sql .= " AND t.city LIKE '%".$db->escape($search_city)."%'";
}
if ($search_state) {
    $sql .= " AND t.state LIKE '%".$db->escape($search_state)."%'";
}

$sql .= $db->order($sortfield, $sortorder);

// Count total nb of records
$sqlcount = str_replace('SELECT t.rowid, t.ref, t.name, t.phone, t.email, t.type, t.website, t.note, t.address1, t.address2, t.city, t.state, t.picture', 'SELECT COUNT(*) as nb', $sql);
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
llxHeader('', $langs->trans("VendorsList"), '');

// Page title and buttons
$newCardButton = '';
if ($user->rights->flotte->write) {
    $newCardButton = dolGetButtonTitle($langs->trans('NewVendor'), '', 'fa fa-plus-circle', dol_buildpath('/flotte/vendor_card.php', 1).'?action=create', '', $user->rights->flotte->read);
}

print load_fiche_titre($langs->trans("VendorsList"), $newCardButton, 'company');

// Actions bar
print '<div class="tabsAction">'."\n";
if ($user->rights->flotte->write) {
    print '<a class="butAction" href="'.dol_buildpath('/flotte/vendor_card.php', 1).'?action=create">'.$langs->trans("NewVendor").'</a>'."\n";
}
if ($user->rights->flotte->read) {
    print '<a class="butAction" href="'.dol_buildpath('/flotte/vendor_list.php', 1).'?action=export">'.$langs->trans("Export").'</a>'."\n";
}
print '</div>'."\n";

// Build param string for URL
$param = '';
if (!empty($search_ref))           $param .= '&search_ref='.urlencode($search_ref);
if (!empty($search_name))          $param .= '&search_name='.urlencode($search_name);
if (!empty($search_phone))         $param .= '&search_phone='.urlencode($search_phone);
if (!empty($search_email))         $param .= '&search_email='.urlencode($search_email);
if (!empty($search_type))          $param .= '&search_type='.urlencode($search_type);
if (!empty($search_city))          $param .= '&search_city='.urlencode($search_city);
if (!empty($search_state))         $param .= '&search_state='.urlencode($search_state);

// Search form
print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'">'."\n";
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="formfilteraction" id="formfilteraction" value="list">';
print '<input type="hidden" name="action" value="list">';
print '<input type="hidden" name="sortfield" value="'.$sortfield.'">';
print '<input type="hidden" name="sortorder" value="'.$sortorder.'">';
print '<input type="hidden" name="page" value="'.$page.'">';

// Print barre liste
print_barre_liste($langs->trans("VendorsList"), $page, $_SERVER["PHP_SELF"], $param, $sortfield, $sortorder, '', $num, $nbtotalofrecords, 'company', 0, $newCardButton, '', $limit, 0, 0, 1);

print '<div class="div-table-responsive">';
print '<table class="tagtable liste" id="tablelines">'."\n";

// Search fields
print '<tr class="liste_titre_filter">';
print '<td class="liste_titre"><input type="text" class="flat maxwidth100" name="search_ref" value="'.dol_escape_htmltag($search_ref).'"></td>';
print '<td class="liste_titre"><input type="text" class="flat maxwidth100" name="search_name" value="'.dol_escape_htmltag($search_name).'"></td>';
print '<td class="liste_titre"><input type="text" class="flat maxwidth100" name="search_phone" value="'.dol_escape_htmltag($search_phone).'"></td>';
print '<td class="liste_titre"><input type="text" class="flat maxwidth100" name="search_email" value="'.dol_escape_htmltag($search_email).'"></td>';
print '<td class="liste_titre">';
$typearray = array(''=>'', 'Parts'=>$langs->trans('Parts'), 'Fuel'=>$langs->trans('Fuel'), 'Maintenance'=>$langs->trans('Maintenance'), 'Insurance'=>$langs->trans('Insurance'), 'Service'=>$langs->trans('Service'), 'Other'=>$langs->trans('Other'));
print $form->selectarray('search_type', $typearray, $search_type, 0, 0, 0, '', 0, 0, 0, '', 'search_type width100 onrightofpage');
print '</td>';
print '<td class="liste_titre"><input type="text" class="flat maxwidth100" name="search_city" value="'.dol_escape_htmltag($search_city).'"></td>';
print '<td class="liste_titre"><input type="text" class="flat maxwidth100" name="search_state" value="'.dol_escape_htmltag($search_state).'"></td>';
print '<td class="liste_titre"></td>'; // Website
print '<td class="liste_titre maxwidthsearch">';
$searchpicto = $form->showFilterButtons();
print $searchpicto;
print '</td>';
print '</tr>'."\n";

// Table headers
print '<tr class="liste_titre">';
print_liste_field_titre("Ref", $_SERVER["PHP_SELF"], "t.ref", "", $param, '', $sortfield, $sortorder);
print_liste_field_titre("Name", $_SERVER["PHP_SELF"], "t.name", "", $param, '', $sortfield, $sortorder);
print_liste_field_titre("Phone", $_SERVER["PHP_SELF"], "t.phone", "", $param, '', $sortfield, $sortorder);
print_liste_field_titre("Email", $_SERVER["PHP_SELF"], "t.email", "", $param, '', $sortfield, $sortorder);
print_liste_field_titre("Type", $_SERVER["PHP_SELF"], "t.type", "", $param, '', $sortfield, $sortorder);
print_liste_field_titre("City", $_SERVER["PHP_SELF"], "t.city", "", $param, '', $sortfield, $sortorder);
print_liste_field_titre("State", $_SERVER["PHP_SELF"], "t.state", "", $param, '', $sortfield, $sortorder);
print_liste_field_titre("Website", $_SERVER["PHP_SELF"], "t.website", "", $param, '', $sortfield, $sortorder);
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
        print '<td class="nowrap"><a href="'.dol_buildpath('/flotte/vendor_card.php', 1).'?id='.$obj->rowid.'">';
        print img_object($langs->trans("ShowVendor"), "company", 'class="pictofixedwidth"');
        print '<strong>'.dol_escape_htmltag($obj->ref).'</strong></a></td>';
        
        // Name
        print '<td>'.dol_escape_htmltag($obj->name).'</td>';
        
        // Phone
        print '<td>'.dol_escape_htmltag($obj->phone).'</td>';
        
        // Email
        print '<td>'.dol_escape_htmltag($obj->email).'</td>';
        
        // Type
        print '<td>';
        if ($obj->type) {
            $type_class = 'status4'; // Default color
            switch($obj->type) {
                case 'Parts': $type_class = 'status4'; break;
                case 'Fuel': $type_class = 'status6'; break;
                case 'Maintenance': $type_class = 'status1'; break;
                case 'Insurance': $type_class = 'status8'; break;
                case 'Service': $type_class = 'status2'; break;
                case 'Other': $type_class = 'status9'; break;
            }
            print dolGetStatus($langs->trans($obj->type), '', '', $type_class, 1);
        }
        print '</td>';
        
        // City
        print '<td>'.dol_escape_htmltag($obj->city).'</td>';
        
        // State
        print '<td>'.dol_escape_htmltag($obj->state).'</td>';
        
        // Website
        print '<td>';
        if (!empty($obj->website)) {
            $website_url = strpos($obj->website, 'http') === 0 ? $obj->website : 'http://' . $obj->website;
            print '<a href="'.$website_url.'" target="_blank">'.dol_trunc($obj->website, 24).'</a>';
        }
        print '</td>';
        
        // Actions
        print '<td class="nowrap center">';
        if ($user->rights->flotte->read) {
            print '<a class="editfielda" href="'.dol_buildpath('/flotte/vendor_card.php', 1).'?id='.$obj->rowid.'" title="'.$langs->trans("View").'">'.img_view($langs->trans("View")).'</a>';
        }
        if ($user->rights->flotte->write) {
            print '<a class="editfielda" href="'.dol_buildpath('/flotte/vendor_card.php', 1).'?id='.$obj->rowid.'&action=edit" title="'.$langs->trans("Edit").'">'.img_edit($langs->trans("Edit")).'</a>';
        }
        if ($user->rights->flotte->delete) {
            print '<a class="editfielda" href="'.dol_buildpath('/flotte/vendor_card.php', 1).'?id='.$obj->rowid.'&action=delete&token='.newToken().'" title="'.$langs->trans("Delete").'">'.img_delete($langs->trans("Delete")).'</a>';
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
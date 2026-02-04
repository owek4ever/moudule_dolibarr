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
$search_email = GETPOST('search_email', 'alpha');
$search_company = GETPOST('search_company', 'alpha');
$search_gender = GETPOST('search_gender', 'alpha');
$search_tax_no = GETPOST('search_tax_no', 'alpha');
$search_payment_delay = GETPOST('search_payment_delay', 'alpha');

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
$hookmanager->initHooks(array('customerlist', 'globalcard'));

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
    $search_email = '';
    $search_company = '';
    $search_gender = '';
    $search_tax_no = '';
    $search_payment_delay = '';
}

// Handle confirmed delete from list page
if ($action == 'confirm_delete' && GETPOST('confirm', 'alpha') == 'yes') {
    $id_to_delete = GETPOST('id', 'int');
    if ($id_to_delete > 0) {
        $db->begin();
        $sql_del = "DELETE FROM ".MAIN_DB_PREFIX."flotte_customer WHERE rowid = ".(int)$id_to_delete." AND entity IN (".getEntity('flotte').")";
        $resql_del = $db->query($sql_del);
        if ($resql_del) {
            // Delete associated files if any
            $uploadDir = $conf->flotte->dir_output . '/customers/' . $id_to_delete . '/';
            if (is_dir($uploadDir)) {
                dol_delete_dir_recursive($uploadDir);
            }
            $db->commit();
            setEventMessages($langs->trans("CustomerDeletedSuccessfully"), null, 'mesgs');
        } else {
            $db->rollback();
            setEventMessages("Error in SQL: ".$db->lasterror(), null, 'errors');
        }
    }
    $action = 'list';
}

// Build and execute select
$sql = 'SELECT t.rowid, t.ref, t.firstname, t.lastname, t.phone, t.email, t.company_name, t.tax_no, t.payment_delay, t.gender';
$sql .= ' FROM '.MAIN_DB_PREFIX.'flotte_customer as t';
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
if ($search_email) {
    $sql .= " AND t.email LIKE '%".$db->escape($search_email)."%'";
}
if ($search_company) {
    $sql .= " AND t.company_name LIKE '%".$db->escape($search_company)."%'";
}
if ($search_gender) {
    $sql .= " AND t.gender = '".$db->escape($search_gender)."'";
}
if ($search_tax_no) {
    $sql .= " AND t.tax_no LIKE '%".$db->escape($search_tax_no)."%'";
}
if ($search_payment_delay) {
    $sql .= " AND t.payment_delay LIKE '%".$db->escape($search_payment_delay)."%'";
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
llxHeader('', $langs->trans("CustomersList"), '');

// Page title and buttons
$newCardButton = '';
if ($user->rights->flotte->write) {
    $newCardButton = dolGetButtonTitle($langs->trans('New Customer'), '', 'fa fa-plus-circle', dol_buildpath('/flotte/customer_card.php', 1).'?action=create', '', $user->rights->flotte->read);
}

// Show delete confirmation dialog if requested
if ($action == 'delete') {
    $id_to_delete = GETPOST('id', 'int');
    if ($id_to_delete > 0) {
        $formconfirm = $form->formconfirm($_SERVER["PHP_SELF"].'?id='.$id_to_delete, $langs->trans('DeleteCustomer'), $langs->trans('ConfirmDeleteCustomer'), 'confirm_delete', '', 0, 1);
        print $formconfirm;
    }
}

// Actions bar
print '<div class="tabsAction">'."\n";
if ($user->rights->flotte->write) {
    print '<a class="butAction" href="'.dol_buildpath('/flotte/customer_card.php', 1).'?action=create">'.$langs->trans("New Customer").'</a>'."\n";
}
if ($user->rights->flotte->read) {
    print '<a class="butAction" href="'.dol_buildpath('/flotte/customer_list.php', 1).'?action=export">'.$langs->trans("Export").'</a>'."\n";
}
print '</div>'."\n";

// Build param string for URL
$param = '';
if (!empty($search_ref))           $param .= '&search_ref='.urlencode($search_ref);
if (!empty($search_firstname))     $param .= '&search_firstname='.urlencode($search_firstname);
if (!empty($search_lastname))      $param .= '&search_lastname='.urlencode($search_lastname);
if (!empty($search_phone))         $param .= '&search_phone='.urlencode($search_phone);
if (!empty($search_email))         $param .= '&search_email='.urlencode($search_email);
if (!empty($search_company))       $param .= '&search_company='.urlencode($search_company);
if (!empty($search_gender))        $param .= '&search_gender='.urlencode($search_gender);
if (!empty($search_tax_no))        $param .= '&search_tax_no='.urlencode($search_tax_no);
if (!empty($search_payment_delay)) $param .= '&search_payment_delay='.urlencode($search_payment_delay);

// Search form
print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'">'."\n";
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="formfilteraction" id="formfilteraction" value="list">';
print '<input type="hidden" name="action" value="list">';
print '<input type="hidden" name="sortfield" value="'.$sortfield.'">';
print '<input type="hidden" name="sortorder" value="'.$sortorder.'">';
print '<input type="hidden" name="page" value="'.$page.'">';

// Print barre liste
print_barre_liste($langs->trans("CustomersList"), $page, $_SERVER["PHP_SELF"], $param, $sortfield, $sortorder, '', $num, $nbtotalofrecords, '', 0);

print '<div class="div-table-responsive">';
print '<table class="tagtable liste" id="tablelines">'."\n";

// Search fields - Matching driver_list.php structure
print '<tr class="liste_titre_filter">';
print '<td class="liste_titre"><input type="text" class="flat maxwidth100" name="search_ref" value="'.dol_escape_htmltag($search_ref).'"></td>';
print '<td class="liste_titre"><input type="text" class="flat maxwidth100" name="search_firstname" value="'.dol_escape_htmltag($search_firstname).'"></td>';
print '<td class="liste_titre"><input type="text" class="flat maxwidth100" name="search_lastname" value="'.dol_escape_htmltag($search_lastname).'"></td>';
print '<td class="liste_titre"><input type="text" class="flat maxwidth100" name="search_phone" value="'.dol_escape_htmltag($search_phone).'"></td>';
print '<td class="liste_titre"><input type="text" class="flat maxwidth100" name="search_email" value="'.dol_escape_htmltag($search_email).'"></td>';
print '<td class="liste_titre"><input type="text" class="flat maxwidth100" name="search_company" value="'.dol_escape_htmltag($search_company).'"></td>';
print '<td class="liste_titre"><input type="text" class="flat maxwidth100" name="search_tax_no" value="'.dol_escape_htmltag($search_tax_no).'"></td>';
print '<td class="liste_titre"><input type="text" class="flat maxwidth100" name="search_payment_delay" value="'.dol_escape_htmltag($search_payment_delay).'"></td>';
print '<td class="liste_titre center">';
$genderarray = array(''=>'', 'male'=>$langs->trans('Male'), 'female'=>$langs->trans('Female'), 'other'=>$langs->trans('Other'));
print $form->selectarray('search_gender', $genderarray, $search_gender, 0, 0, 0, '', 0, 0, 0, '', 'search_gender width100 onrightofpage');
print '</td>';
print '<td class="liste_titre maxwidthsearch">';
$searchpicto = $form->showFilterButtons();
print $searchpicto;
print '</td>';
print '</tr>'."\n";

// Table headers - Matching driver_list.php structure
print '<tr class="liste_titre">';
print_liste_field_titre("Ref", $_SERVER["PHP_SELF"], "t.ref", "", $param, '', $sortfield, $sortorder);
print_liste_field_titre("FirstName", $_SERVER["PHP_SELF"], "t.firstname", "", $param, '', $sortfield, $sortorder);
print_liste_field_titre("LastName", $_SERVER["PHP_SELF"], "t.lastname", "", $param, '', $sortfield, $sortorder);
print_liste_field_titre("Phone", $_SERVER["PHP_SELF"], "t.phone", "", $param, '', $sortfield, $sortorder);
print_liste_field_titre("Email", $_SERVER["PHP_SELF"], "t.email", "", $param, '', $sortfield, $sortorder);
print_liste_field_titre("CompanyName", $_SERVER["PHP_SELF"], "t.company_name", "", $param, '', $sortfield, $sortorder);
print_liste_field_titre("TaxNumber", $_SERVER["PHP_SELF"], "t.tax_no", "", $param, '', $sortfield, $sortorder);
print_liste_field_titre("PaymentDelay", $_SERVER["PHP_SELF"], "t.payment_delay", "", $param, '', $sortfield, $sortorder);
// Manually create centered header for gender column like driver list status column
print '<td class="liste_titre center">';
print '<a class="reposition" href="'.$_SERVER["PHP_SELF"].'?sortfield=t.gender&sortorder='.($sortfield == 't.gender' && $sortorder == 'ASC' ? 'DESC' : 'ASC').$param.'">';
print $langs->trans("Gender");
if ($sortfield == 't.gender') print img_picto('', 'sort'.strtolower($sortorder));
print '</a></td>';
print_liste_field_titre("Action", $_SERVER["PHP_SELF"], "", "", "", '', '', '', 'maxwidthsearch ');
print '</tr>'."\n";

// Display data - Matching driver_list.php style
if ($resql && $num > 0) {
    $i = 0;
    while ($i < min($num, $limit)) {
        $obj = $db->fetch_object($resql);
        if (empty($obj)) break;
        
        print '<tr class="oddeven">';
        
        // Reference - REMOVED THE ICON
        print '<td class="nowrap"><a href="'.dol_buildpath('/flotte/customer_card.php', 1).'?id='.$obj->rowid.'">';
        print '<strong>'.dol_escape_htmltag($obj->ref).'</strong></a></td>';
        
        // First Name
        print '<td>'.dol_escape_htmltag($obj->firstname ?: '-').'</td>';
        
        // Last Name
        print '<td>'.dol_escape_htmltag($obj->lastname ?: '-').'</td>';
        
        // Phone
        print '<td>'.dol_escape_htmltag($obj->phone ?: '-').'</td>';
        
        // Email
        print '<td>'.dol_escape_htmltag($obj->email ?: '-').'</td>';
        
        // Company Name
        print '<td>'.dol_escape_htmltag($obj->company_name ?: '-').'</td>';
        
        // Tax Number
        print '<td>'.dol_escape_htmltag($obj->tax_no ?: '-').'</td>';
        
        // Payment Delay - Formatted like driver list vehicle info
        print '<td>';
        if ($obj->payment_delay) {
            print dol_escape_htmltag($obj->payment_delay);
            print '<br><small style="color: #666;">'.$langs->trans('Days').'</small>';
        } else {
            print '<em style="color: #999;">'.$langs->trans("NotSpecified").'</em>';
        }
        print '</td>';
        
        // Gender - Centered with status style like driver list
        print '<td class="center">';
        if ($obj->gender == 'male') {
            print dolGetStatus($langs->trans('Male'), '', '', 'status4', 1);
        } elseif ($obj->gender == 'female') {
            print dolGetStatus($langs->trans('Female'), '', '', 'status8', 1);
        } elseif ($obj->gender == 'other') {
            print dolGetStatus($langs->trans('Other'), '', '', 'status9', 1);
        } else {
            print '<em style="color: #999;">'.$langs->trans("NotSpecified").'</em>';
        }
        print '</td>';
        
        // Actions - Same style as driver list
        print '<td class="nowrap center">';
        if ($user->rights->flotte->write) {
            print '<a class="editfielda" href="'.dol_buildpath('/flotte/customer_card.php', 1).'?id='.$obj->rowid.'&action=edit" title="'.$langs->trans("Edit").'">'.img_edit($langs->trans("Edit")).'</a>';
        }
        if ($user->rights->flotte->delete) {
            print '<a class="editfielda" href="'.dol_buildpath('/flotte/customer_list.php', 1).'?action=delete&id='.$obj->rowid.'&token='.newToken().'" title="'.$langs->trans("Delete").'">'.img_delete($langs->trans("Delete")).'</a>';
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

// Print pagination - Same as driver list
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
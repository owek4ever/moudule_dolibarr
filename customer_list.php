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

// ── Helper: generate next customer reference ──────────────────────────────
if (!function_exists('getNextCustomerRef')) {
    function getNextCustomerRef($db, $entity) {
        $prefix = "CUST-";
        $sql = "SELECT ref FROM ".MAIN_DB_PREFIX."flotte_customer";
        $sql .= " WHERE entity = ".(int)$entity;
        $sql .= " AND ref LIKE '".$prefix."%'";
        $sql .= " ORDER BY ref DESC LIMIT 1";
        $resql = $db->query($sql);
        if ($resql && $db->num_rows($resql) > 0) {
            $obj = $db->fetch_object($resql);
            $next_number = (int)str_replace($prefix, '', $obj->ref) + 1;
        } else {
            $next_number = 1;
        }
        return $prefix.str_pad($next_number, 4, '0', STR_PAD_LEFT);
    }
}

// ── Download CSV template ─────────────────────────────────────────────────
if ($action == 'download_template') {
    $columns = array(
        'firstname','lastname','phone','email',
        'company_name','tax_no','payment_delay','gender'
    );
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="customers_import_template.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, $columns);
    fputcsv($out, array('John','Doe','+21612345678','john.doe@example.com','Acme Corp','TN123456789','30','male'));
    fclose($out);
    exit;
}

// ── CSV Import ────────────────────────────────────────────────────────────
if ($action == 'import_csv' && $user->rights->flotte->write) {
    if (isset($_FILES['import_file']) && $_FILES['import_file']['error'] == 0) {
        $ext = strtolower(pathinfo($_FILES['import_file']['name'], PATHINFO_EXTENSION));
        if ($ext === 'csv') {
            $handle = fopen($_FILES['import_file']['tmp_name'], 'r');
            if ($handle) {
                fgetcsv($handle); // skip header
                $imported      = 0;
                $import_errors = array();
                $row_num       = 1;

                while (($row = fgetcsv($handle)) !== false) {
                    $row_num++;
                    if (count($row) < 1) continue;

                    $firstname     = isset($row[0]) ? trim($row[0]) : '';
                    $lastname      = isset($row[1]) ? trim($row[1]) : '';
                    $phone         = isset($row[2]) ? trim($row[2]) : '';
                    $email         = isset($row[3]) ? trim($row[3]) : '';
                    $company_name  = isset($row[4]) ? trim($row[4]) : '';
                    $tax_no        = isset($row[5]) ? trim($row[5]) : '';
                    $payment_delay = isset($row[6]) && $row[6] !== '' ? (int)trim($row[6]) : null;
                    $gender        = isset($row[7]) ? strtolower(trim($row[7])) : '';

                    $ref = getNextCustomerRef($db, $conf->entity);

                    $db->begin();
                    $sql_i  = "INSERT INTO ".MAIN_DB_PREFIX."flotte_customer (";
                    $sql_i .= "ref, entity, firstname, lastname, phone, email, company_name, tax_no, payment_delay, gender, fk_user_author";
                    $sql_i .= ") VALUES (";
                    $sql_i .= "'".$db->escape($ref)."', ".$conf->entity.", ";
                    $sql_i .= "'".$db->escape($firstname)."', '".$db->escape($lastname)."', ";
                    $sql_i .= "'".$db->escape($phone)."', '".$db->escape($email)."', ";
                    $sql_i .= "'".$db->escape($company_name)."', '".$db->escape($tax_no)."', ";
                    $sql_i .= ($payment_delay !== null ? (int)$payment_delay : "NULL").", ";
                    $sql_i .= "'".$db->escape($gender)."', ".$user->id;
                    $sql_i .= ")";

                    $resql_i = $db->query($sql_i);
                    if ($resql_i) {
                        $db->commit();
                        $imported++;
                    } else {
                        $db->rollback();
                        $import_errors[] = $langs->trans("Row").' '.$row_num.': '.$db->lasterror();
                    }
                }
                fclose($handle);

                if ($imported > 0) {
                    setEventMessages(sprintf($langs->trans("ImportedCustomersCount"), $imported), null, 'mesgs');
                }
                foreach ($import_errors as $ie) {
                    setEventMessages($ie, null, 'errors');
                }
            }
        } else {
            setEventMessages($langs->trans("ErrorOnlyCSVAllowed"), null, 'errors');
        }
    } else {
        setEventMessages($langs->trans("ErrorNoFileUploaded"), null, 'errors');
    }
    header('Location: '.$_SERVER['PHP_SELF']);
    exit;
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

// Build param string for URL
$param = '';
if (!empty($search_ref))            $param .= '&search_ref='.urlencode($search_ref);
if (!empty($search_firstname))      $param .= '&search_firstname='.urlencode($search_firstname);
if (!empty($search_lastname))       $param .= '&search_lastname='.urlencode($search_lastname);
if (!empty($search_phone))          $param .= '&search_phone='.urlencode($search_phone);
if (!empty($search_email))          $param .= '&search_email='.urlencode($search_email);
if (!empty($search_company))        $param .= '&search_company='.urlencode($search_company);
if (!empty($search_gender))         $param .= '&search_gender='.urlencode($search_gender);
if (!empty($search_tax_no))         $param .= '&search_tax_no='.urlencode($search_tax_no);
if (!empty($search_payment_delay))  $param .= '&search_payment_delay='.urlencode($search_payment_delay);

// Page header
llxHeader('', $langs->trans("Customers List"), '');

// Show delete confirmation dialog if requested
if ($action == 'delete') {
    $id_to_delete = GETPOST('id', 'int');
    if ($id_to_delete > 0) {
        $formconfirm = $form->formconfirm($_SERVER["PHP_SELF"].'?id='.$id_to_delete, $langs->trans('DeleteCustomer'), $langs->trans('ConfirmDeleteCustomer'), 'confirm_delete', '', 0, 1);
        print $formconfirm;
    }
}

// Collect rows
$rows = array();
if ($resql && $num > 0) {
    $i = 0;
    while ($i < min($num, $limit)) {
        $obj = $db->fetch_object($resql);
        if (empty($obj)) break;
        $rows[] = $obj;
        $i++;
    }
}

// Sort helpers
function cl_sortArrow($field, $sortfield, $sortorder) {
    if ($sortfield == $field) return $sortorder == 'ASC' ? ' <span class="vl-sort-arrow">↑</span>' : ' <span class="vl-sort-arrow">↓</span>';
    return ' <span class="vl-sort-arrow muted">↕</span>';
}
function cl_sortHref($field, $sortfield, $sortorder, $self, $param) {
    $dir = ($sortfield == $field && $sortorder == 'ASC') ? 'DESC' : 'ASC';
    return $self.'?sortfield='.$field.'&sortorder='.$dir.'&'.$param;
}

$self = $_SERVER["PHP_SELF"];

// Count by gender
$cnt_male = 0; $cnt_female = 0; $cnt_other = 0;
foreach ($rows as $r) {
    if ($r->gender == 'male') $cnt_male++;
    elseif ($r->gender == 'female') $cnt_female++;
    elseif ($r->gender == 'other') $cnt_other++;
}
?>

<style>
@import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=DM+Mono:wght@400;500&display=swap');

.vl-wrap * { box-sizing: border-box; }

.vl-wrap {
    font-family: 'DM Sans', sans-serif;
    max-width: 100%;
    margin: 0 auto;
    padding: 0 4px 40px;
    color: #1a1f2e;
}

/* Header */
.vl-header {
    display: flex; align-items: center; justify-content: space-between;
    padding: 28px 0 24px; border-bottom: 1px solid #e8eaf0;
    margin-bottom: 24px; gap: 16px; flex-wrap: wrap;
}
.vl-header-left h1 {
    font-size: 22px; font-weight: 700; color: #1a1f2e;
    margin: 0 0 4px; letter-spacing: -0.3px;
}
.vl-header-left .vl-subtitle { font-size: 13px; color: #7c859c; font-weight: 400; }
.vl-header-actions { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }

/* Buttons */
.vl-btn {
    display: inline-flex; align-items: center; gap: 7px;
    padding: 8px 16px; border-radius: 6px; font-size: 13px; font-weight: 600;
    text-decoration: none !important; transition: all 0.15s ease;
    border: none; cursor: pointer; font-family: 'DM Sans', sans-serif; white-space: nowrap;
}
.vl-btn-primary   { background: #3c4758 !important; color: #fff !important; }
.vl-btn-primary:hover  { background: #2a3346 !important; color: #fff !important; }
.vl-btn-secondary { background: #3c4758 !important; color: #fff !important; border: none !important; }
.vl-btn-secondary:hover { background: #2a3346 !important; color: #fff !important; }

/* Filters */
.vl-filters {
    background: #fff; border: 1px solid #e8eaf0; border-radius: 12px;
    padding: 18px 20px; margin-bottom: 20px; display: flex;
    gap: 12px; align-items: flex-end; flex-wrap: wrap;
    box-shadow: 0 1px 4px rgba(0,0,0,0.04);
}
.vl-filter-group { display: flex; flex-direction: column; gap: 5px; flex: 1; min-width: 130px; }
.vl-filter-group label { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.6px; color: #9aa0b4; }
.vl-filter-group input,
.vl-filter-group select {
    padding: 8px 12px; border: 1.5px solid #e2e5f0; border-radius: 8px;
    font-size: 13px; font-family: 'DM Sans', sans-serif; color: #2d3748;
    background: #fafbfe; outline: none; transition: border-color 0.15s, box-shadow 0.15s; width: 100%;
}
.vl-filter-group input:focus,
.vl-filter-group select:focus {
    border-color: #3c4758; box-shadow: 0 0 0 3px rgba(60,71,88,0.1); background: #fff;
}
.vl-filter-actions { display: flex; gap: 8px; align-items: flex-end; padding-bottom: 1px; }
.vl-btn-filter {
    padding: 8px 16px; font-size: 13px; border-radius: 6px; font-weight: 600;
    border: none; cursor: pointer; font-family: 'DM Sans', sans-serif;
    transition: all 0.15s; white-space: nowrap;
}
.vl-btn-filter.apply { background: #3c4758 !important; color: #fff !important; }
.vl-btn-filter.apply:hover { background: #2a3346 !important; }
.vl-btn-filter.reset  { background: #3c4758 !important; color: #fff !important; }
.vl-btn-filter.reset:hover  { background: #2a3346 !important; }

/* Stats chips */
.vl-stats { display: flex; gap: 10px; margin-bottom: 16px; flex-wrap: wrap; }
.vl-stat-chip {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 6px 14px; border-radius: 20px; font-size: 12px;
    font-weight: 600; background: #f0f2fa; color: #5a6482;
}
.vl-stat-chip .vl-stat-num { font-size: 14px; font-weight: 700; color: #1a1f2e; }
.vl-stat-chip.company { background: #eff6ff; color: #1d4ed8; }
.vl-stat-chip.company .vl-stat-num { color: #1d4ed8; }

/* Table */
.vl-table-card {
    background: #fff; border: 1px solid #e8eaf0; border-radius: 14px;
    overflow: hidden; box-shadow: 0 2px 12px rgba(0,0,0,0.05);
}
.vl-table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }

table.vl-table { width: 100%; border-collapse: collapse; font-size: 13.5px; }
table.vl-table thead tr { background: #f7f8fc; border-bottom: 2px solid #e8eaf0; }
table.vl-table thead th {
    padding: 13px 16px; text-align: left; font-size: 11px; font-weight: 700;
    text-transform: uppercase; letter-spacing: 0.7px; color: #8b92a9; white-space: nowrap;
}
table.vl-table thead th a { color: #8b92a9; text-decoration: none; display: inline-flex; align-items: center; gap: 4px; transition: color 0.15s; }
table.vl-table thead th a:hover { color: #3c4758; }
table.vl-table thead th.center { text-align: center; }

.vl-sort-arrow { font-size: 10px; opacity: 0.6; }
.vl-sort-arrow.muted { opacity: 0.25; }

table.vl-table tbody tr { border-bottom: 1px solid #f0f2f8; transition: background 0.12s; }
table.vl-table tbody tr:last-child { border-bottom: none; }
table.vl-table tbody tr:hover { background: #fafbff; }
table.vl-table tbody td { padding: 14px 16px; color: #2d3748; vertical-align: middle; }
table.vl-table tbody td.center { text-align: center; }

/* Ref link */
.vl-ref-link {
    display: inline-flex; align-items: center; gap: 8px;
    text-decoration: none; color: #3c4758; font-weight: 600;
    font-family: 'DM Mono', monospace; font-size: 13px; transition: color 0.15s;
}
.vl-ref-link:hover { color: #2a3346; text-decoration: none; }
.vl-ref-icon {
    width: 30px; height: 30px; background: rgba(60,71,88,0.08);
    border-radius: 8px; display: flex; align-items: center;
    justify-content: center; color: #3c4758; font-size: 14px; flex-shrink: 0;
}

/* Customer name */
.vl-customer-name { font-weight: 600; color: #1a1f2e; font-size: 13.5px; }
.vl-customer-sub  { font-size: 11.5px; color: #9aa0b4; margin-top: 2px; }

/* Company chip */
.vl-company-chip {
    display: inline-flex; align-items: center; gap: 6px;
    font-size: 12.5px; font-weight: 600; color: #1d4ed8;
    background: #eff6ff; padding: 4px 10px; border-radius: 6px;
}

/* Mono */
.vl-mono {
    font-family: 'DM Mono', monospace; font-size: 12px; color: #4a5568;
    background: #f0f2fa; padding: 3px 8px; border-radius: 5px; display: inline-block;
}

/* Payment delay */
.vl-delay {
    display: inline-flex; align-items: baseline; gap: 4px;
    font-weight: 600; color: #2d3748; font-size: 13px;
}
.vl-delay-unit { font-size: 11px; color: #9aa0b4; font-weight: 400; }

/* Gender badge */
.vl-badge {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 4px 10px; border-radius: 20px;
    font-size: 11.5px; font-weight: 600; white-space: nowrap;
}
.vl-badge::before { content: ''; width: 6px; height: 6px; border-radius: 50%; flex-shrink: 0; }
.vl-badge.male   { background: #eff6ff; color: #1d4ed8; }
.vl-badge.male::before { background: #3b82f6; }
.vl-badge.female { background: #fdf2f8; color: #9d174d; }
.vl-badge.female::before { background: #ec4899; }
.vl-badge.other  { background: #f5f3ff; color: #5b21b6; }
.vl-badge.other::before  { background: #8b5cf6; }

/* Action buttons */
.vl-actions { display: flex; gap: 4px; justify-content: center; }
.vl-action-btn {
    width: 32px; height: 32px; border-radius: 8px; display: flex;
    align-items: center; justify-content: center; text-decoration: none;
    transition: all 0.15s; font-size: 13px; border: 1.5px solid transparent;
}
.vl-action-btn.view { color: #3c4758; background: #eaecf0; border-color: #c4c9d4; }
.vl-action-btn.edit { color: #d97706; background: #fef9ec; border-color: #fde9a2; }
.vl-action-btn.del  { color: #dc2626; background: #fef2f2; border-color: #fecaca; }
.vl-action-btn:hover { transform: translateY(-1px); box-shadow: 0 3px 8px rgba(0,0,0,0.1); text-decoration: none; }

/* Empty state */
.vl-empty { padding: 70px 20px; text-align: center; color: #9aa0b4; }
.vl-empty-icon { font-size: 52px; opacity: 0.3; margin-bottom: 16px; }
.vl-empty p { font-size: 15px; font-weight: 500; margin: 0 0 20px; color: #7c859c; }

/* Pagination */
.vl-pagination {
    display: flex; align-items: center; justify-content: space-between;
    padding: 16px 20px; border-top: 1px solid #f0f2f8; flex-wrap: wrap; gap: 12px;
}
.vl-pagination-info { font-size: 12.5px; color: #9aa0b4; }
.vl-page-btns { display: flex; gap: 4px; }
.vl-page-btn {
    min-width: 34px; height: 34px; padding: 0 10px; border-radius: 8px;
    display: inline-flex; align-items: center; justify-content: center;
    font-size: 13px; font-weight: 600; text-decoration: none; transition: all 0.15s;
    border: 1.5px solid #e2e5f0; color: #5a6482; background: #fff;
}
.vl-page-btn:hover { background: #f0f2fa; border-color: #c4c9d8; text-decoration: none; color: #2d3748; }
.vl-page-btn.active { background: #3c4758; color: #fff; border-color: transparent; }
.vl-page-btn.disabled { opacity: 0.35; pointer-events: none; }

/* ══════════════════════════════════════
   RESPONSIVE BREAKPOINTS
══════════════════════════════════════ */

/* Tablet (≤ 1024px) */
@media (max-width: 1024px) {
    .vl-wrap { padding: 0 12px 40px; }
    table.vl-table thead th,
    table.vl-table tbody td { padding: 11px 12px; }
}

/* Small tablet (≤ 900px) */
@media (max-width: 900px) {
    .vl-filters { flex-direction: column; }
    .vl-filter-group { min-width: 100% !important; max-width: 100% !important; }
    .vl-filter-actions { width: 100%; justify-content: flex-end; }

    /* Hide Tax No. and Payment Delay columns */
    table.vl-table th:nth-child(5),
    table.vl-table td:nth-child(5),
    table.vl-table th:nth-child(6),
    table.vl-table td:nth-child(6) { display: none; }
}

/* Mobile (≤ 600px) */
@media (max-width: 600px) {
    .vl-wrap { padding: 0 8px 32px; }

    /* Header */
    .vl-header {
        flex-direction: column;
        align-items: flex-start;
        padding: 18px 0 16px;
        gap: 12px;
    }
    .vl-header-left h1 { font-size: 18px; }
    .vl-header-actions { width: 100%; justify-content: flex-start; }
    .vl-btn { padding: 8px 12px; font-size: 12px; }

    /* Stats */
    .vl-stats { gap: 6px; }
    .vl-stat-chip { padding: 5px 10px; font-size: 11px; }

    /* Convert table to stacked card layout */
    .vl-table-wrap { overflow-x: unset; }

    table.vl-table,
    table.vl-table thead,
    table.vl-table tbody,
    table.vl-table th,
    table.vl-table td,
    table.vl-table tr { display: block; }

    table.vl-table thead { display: none; }

    table.vl-table tbody tr {
        border: 1px solid #e8eaf0;
        border-radius: 10px;
        margin-bottom: 12px;
        padding: 8px 4px;
        background: #fff;
        box-shadow: 0 1px 6px rgba(0,0,0,0.05);
    }
    table.vl-table tbody tr:hover { background: #fafbff; }

    table.vl-table tbody td {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 8px 14px;
        border-bottom: 1px solid #f4f5fb;
        font-size: 13px;
        text-align: right !important;
    }
    table.vl-table tbody td:last-child { border-bottom: none; }

    /* data-label pseudo headers */
    table.vl-table tbody td::before {
        content: attr(data-label);
        font-size: 11px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        color: #9aa0b4;
        text-align: left;
        flex-shrink: 0;
        margin-right: 10px;
    }

    /* Ref cell — no label, full left-align */
    table.vl-table tbody td:first-child {
        justify-content: flex-start;
        text-align: left !important;
        padding-top: 12px;
        padding-bottom: 12px;
    }
    table.vl-table tbody td:first-child::before { display: none; }

    .vl-actions { justify-content: flex-end; }

    /* Pagination */
    .vl-pagination {
        flex-direction: column;
        align-items: center;
        text-align: center;
        gap: 10px;
        padding: 14px 12px;
    }
    .vl-page-btns { flex-wrap: wrap; justify-content: center; }
    .vl-page-btn { min-width: 30px; height: 30px; font-size: 12px; }
}

/* ── Import button ── */
.vl-btn-import {
    background: #3c4758 !important;
    color: #fff !important;
    border: none !important;
}
.vl-btn-import:hover {
    background: #2a3346 !important;
    color: #fff !important;
    text-decoration: none !important;
}

/* ── Import Modal ── */
.vl-modal-overlay {
    display: none; position: fixed; inset: 0;
    background: rgba(15,20,35,0.45); backdrop-filter: blur(3px);
    z-index: 9999; align-items: center; justify-content: center; padding: 16px;
}
.vl-modal-overlay.open { display: flex; }
.vl-modal {
    background: #fff; border-radius: 14px; width: 100%; max-width: 560px;
    box-shadow: 0 20px 60px rgba(0,0,0,0.18);
    font-family: 'DM Sans', sans-serif; overflow: hidden;
    animation: clModalIn 0.18s ease;
}
@keyframes clModalIn {
    from { opacity:0; transform: translateY(-14px) scale(0.97); }
    to   { opacity:1; transform: translateY(0) scale(1); }
}
.vl-modal-header {
    display: flex; align-items: center; justify-content: space-between;
    padding: 18px 22px 16px; border-bottom: 1px solid #eaecf5; background: #f7f8fc;
}
.vl-modal-header-left { display: flex; align-items: center; gap: 11px; }
.vl-modal-icon {
    width: 38px; height: 38px; border-radius: 10px;
    background: rgba(60,71,88,0.1); display: flex; align-items: center;
    justify-content: center; color: #3c4758; font-size: 16px; flex-shrink: 0;
}
.vl-modal-title { font-size: 15px; font-weight: 700; color: #1a1f2e; margin: 0; }
.vl-modal-sub   { font-size: 12px; color: #9aa0b4; margin: 2px 0 0; }
.vl-modal-close {
    background: none; border: none; cursor: pointer; color: #9aa0b4;
    font-size: 18px; padding: 4px; border-radius: 6px; line-height: 1;
    transition: color 0.15s, background 0.15s;
}
.vl-modal-close:hover { color: #1a1f2e; background: #e8eaf0; }
.vl-modal-body { padding: 22px; max-height: 65vh; overflow-y: auto; }

.vl-import-notice {
    background: #f0f4ff; border: 1px solid #c7d4fb; border-radius: 8px;
    padding: 12px 14px; font-size: 12.5px; color: #3c4758;
    margin-bottom: 18px; display: flex; align-items: flex-start; gap: 10px;
}
.vl-import-notice i { flex-shrink: 0; margin-top: 2px; color: #4a6cf7; }
.vl-import-notice a { color: #4a6cf7; font-weight: 600; text-decoration: underline; }

.vl-fields-table {
    width: 100%; border-collapse: collapse; font-size: 12px;
    margin-bottom: 18px; border-radius: 8px; overflow: hidden; border: 1px solid #e8eaf0;
}
.vl-fields-table thead tr { background: #f7f8fc; }
.vl-fields-table th {
    padding: 8px 12px; text-align: left; font-size: 11px; font-weight: 700;
    text-transform: uppercase; letter-spacing: 0.5px; color: #8b92a9; border-bottom: 1px solid #e8eaf0;
}
.vl-fields-table td { padding: 7px 12px; border-bottom: 1px solid #f2f3f8; color: #2d3748; vertical-align: top; }
.vl-fields-table tr:last-child td { border-bottom: none; }
.vl-fields-table tbody tr:nth-child(even) { background: #fafbfe; }
.vl-col-name { font-family: 'DM Mono', monospace; font-size: 11px; color: #3c4758; font-weight: 500; }
.vl-col-req  { color: #ef4444; font-weight: 700; font-size: 11px; text-align: center; }
.vl-col-opt  { color: #9aa0b4; font-size: 11px; text-align: center; }

.vl-fields-toggle {
    font-size: 12px; color: #4a6cf7; font-weight: 600; cursor: pointer;
    background: none; border: none; padding: 0; margin-bottom: 12px;
    display: inline-flex; align-items: center; gap: 5px; font-family: 'DM Sans', sans-serif;
}
.vl-fields-toggle:hover { text-decoration: underline; }

.vl-dropzone {
    border: 2px dashed #c8cddf; border-radius: 10px; padding: 28px 20px;
    text-align: center; cursor: pointer; transition: border-color 0.15s, background 0.15s;
    background: #fafbfe; position: relative;
}
.vl-dropzone:hover, .vl-dropzone.drag-over { border-color: #3c4758; background: #f2f4fa; }
.vl-dropzone input[type=file] {
    position: absolute; inset: 0; opacity: 0; cursor: pointer; width: 100%; height: 100%;
}
.vl-dropzone-icon  { font-size: 28px; color: #9aa0b4; margin-bottom: 10px; }
.vl-dropzone-text  { font-size: 13px; font-weight: 600; color: #3c4758; margin-bottom: 4px; }
.vl-dropzone-sub   { font-size: 11.5px; color: #9aa0b4; }
.vl-dropzone-file  { font-size: 12.5px; color: #1a7d4a; font-weight: 600; margin-top: 8px; display: none; }
.vl-dropzone.has-file .vl-dropzone-icon { color: #22c55e; }
.vl-dropzone.has-file .vl-dropzone-file { display: block; }
.vl-dropzone.has-file .vl-dropzone-sub  { display: none; }
</style>

<meta name="viewport" content="width=device-width, initial-scale=1.0">

<div class="vl-wrap">

<!-- Header -->
<div class="vl-header">
    <div class="vl-header-left">
        <h1><i class="fa fa-user-tie" style="color:#3c4758;margin-right:10px;"></i><?php echo $langs->trans("Customers List"); ?></h1>
        <div class="vl-subtitle"><?php echo $nbtotalofrecords; ?> <?php echo $langs->trans("CustomersFound"); ?></div>
    </div>
    <div class="vl-header-actions">
        <?php if ($user->rights->flotte->read) { ?>
        <a class="vl-btn vl-btn-secondary" href="<?php echo dol_buildpath('/flotte/customer_list.php', 1); ?>?action=export">
            <i class="fa fa-download"></i> <?php echo $langs->trans("Export"); ?>
        </a>
        <?php } ?>
        <?php if ($user->rights->flotte->write) { ?>
        <button type="button" class="vl-btn vl-btn-import" onclick="clOpenImport()">
            <i class="fa fa-file-import"></i> <?php echo $langs->trans("Import"); ?>
        </button>
        <?php } ?>
        <?php if ($user->rights->flotte->write) { ?>
        <a class="vl-btn vl-btn-primary" href="<?php echo dol_buildpath('/flotte/customer_card.php', 1); ?>?action=create">
            <i class="fa fa-plus"></i> <?php echo $langs->trans("NewCustomer"); ?>
        </a>
        <?php } ?>
    </div>
</div>

<!-- Filter Form -->
<form method="POST" action="<?php echo $self; ?>">
<input type="hidden" name="token" value="<?php echo newToken(); ?>">
<input type="hidden" name="formfilteraction" value="list">
<input type="hidden" name="action" value="list">
<input type="hidden" name="sortfield" value="<?php echo $sortfield; ?>">
<input type="hidden" name="sortorder" value="<?php echo $sortorder; ?>">
<input type="hidden" name="page" value="<?php echo $page; ?>">

<div class="vl-filters">
    <div class="vl-filter-group">
        <label><?php echo $langs->trans("Ref"); ?></label>
        <input type="text" name="search_ref" placeholder="<?php echo $langs->trans('SearchRef'); ?>" value="<?php echo dol_escape_htmltag($search_ref); ?>">
    </div>
    <div class="vl-filter-group">
        <label><?php echo $langs->trans("FirstName"); ?></label>
        <input type="text" name="search_firstname" placeholder="<?php echo $langs->trans('SearchFirstName'); ?>" value="<?php echo dol_escape_htmltag($search_firstname); ?>">
    </div>
    <div class="vl-filter-group">
        <label><?php echo $langs->trans("LastName"); ?></label>
        <input type="text" name="search_lastname" placeholder="<?php echo $langs->trans('SearchLastName'); ?>" value="<?php echo dol_escape_htmltag($search_lastname); ?>">
    </div>
    <div class="vl-filter-group">
        <label><?php echo $langs->trans("Phone"); ?></label>
        <input type="text" name="search_phone" placeholder="<?php echo $langs->trans('SearchPhone'); ?>" value="<?php echo dol_escape_htmltag($search_phone); ?>">
    </div>
    <div class="vl-filter-group">
        <label><?php echo $langs->trans("Email"); ?></label>
        <input type="text" name="search_email" placeholder="<?php echo $langs->trans('SearchEmail'); ?>" value="<?php echo dol_escape_htmltag($search_email); ?>">
    </div>
    <div class="vl-filter-group">
        <label><?php echo $langs->trans("Company"); ?></label>
        <input type="text" name="search_company" placeholder="<?php echo $langs->trans('SearchCompany'); ?>" value="<?php echo dol_escape_htmltag($search_company); ?>">
    </div>
    <div class="vl-filter-group">
        <label><?php echo $langs->trans("TaxNo"); ?></label>
        <input type="text" name="search_tax_no" placeholder="<?php echo $langs->trans('SearchTaxNo'); ?>" value="<?php echo dol_escape_htmltag($search_tax_no); ?>">
    </div>
    <div class="vl-filter-group" style="max-width:150px;">
        <label><?php echo $langs->trans("Gender"); ?></label>
        <select name="search_gender">
            <option value=""><?php echo $langs->trans("All"); ?></option>
            <option value="male"   <?php echo $search_gender === 'male'   ? 'selected' : ''; ?>><?php echo $langs->trans("Male"); ?></option>
            <option value="female" <?php echo $search_gender === 'female' ? 'selected' : ''; ?>><?php echo $langs->trans("Female"); ?></option>
            <option value="other"  <?php echo $search_gender === 'other'  ? 'selected' : ''; ?>><?php echo $langs->trans("Other"); ?></option>
        </select>
    </div>
    <div class="vl-filter-actions">
        <button type="submit" class="vl-btn-filter apply"><i class="fa fa-search"></i> <?php echo $langs->trans("Search"); ?></button>
        <button type="submit" name="button_removefilter" value="1" class="vl-btn-filter reset"><i class="fa fa-times"></i> <?php echo $langs->trans("Reset"); ?></button>
    </div>
</div>

<!-- Stats chips -->
<?php
$cnt_with_company = 0;
foreach ($rows as $r) { if (!empty($r->company_name)) $cnt_with_company++; }
?>
<div class="vl-stats">
    <div class="vl-stat-chip">
        <span class="vl-stat-num"><?php echo $nbtotalofrecords; ?></span> <?php echo $langs->trans("Total"); ?>
    </div>
    <?php if ($cnt_with_company > 0) { ?>
    <div class="vl-stat-chip company">
        <span class="vl-stat-num"><?php echo $cnt_with_company; ?></span> <?php echo $langs->trans("WithCompany"); ?>
    </div>
    <?php } ?>
    <?php if ($cnt_male > 0) { ?>
    <div class="vl-stat-chip" style="background:#eff6ff;color:#1d4ed8;">
        <span class="vl-stat-num" style="color:#1d4ed8;"><?php echo $cnt_male; ?></span> <?php echo $langs->trans("Male"); ?>
    </div>
    <?php } ?>
    <?php if ($cnt_female > 0) { ?>
    <div class="vl-stat-chip" style="background:#fdf2f8;color:#9d174d;">
        <span class="vl-stat-num" style="color:#9d174d;"><?php echo $cnt_female; ?></span> <?php echo $langs->trans("Female"); ?>
    </div>
    <?php } ?>
</div>

<!-- Table -->
<div class="vl-table-card">
    <div class="vl-table-wrap">
    <table class="vl-table">
        <thead>
            <tr>
                <th><a href="<?php echo cl_sortHref('t.ref', $sortfield, $sortorder, $self, $param); ?>"><?php echo $langs->trans("Ref"); ?> <?php echo cl_sortArrow('t.ref', $sortfield, $sortorder); ?></a></th>
                <th><a href="<?php echo cl_sortHref('t.firstname', $sortfield, $sortorder, $self, $param); ?>"><?php echo $langs->trans("Customer"); ?> <?php echo cl_sortArrow('t.firstname', $sortfield, $sortorder); ?></a></th>
                <th><a href="<?php echo cl_sortHref('t.phone', $sortfield, $sortorder, $self, $param); ?>"><?php echo $langs->trans("Phone"); ?> <?php echo cl_sortArrow('t.phone', $sortfield, $sortorder); ?></a></th>
                <th><a href="<?php echo cl_sortHref('t.company_name', $sortfield, $sortorder, $self, $param); ?>"><?php echo $langs->trans("Company"); ?> <?php echo cl_sortArrow('t.company_name', $sortfield, $sortorder); ?></a></th>
                <th><a href="<?php echo cl_sortHref('t.tax_no', $sortfield, $sortorder, $self, $param); ?>"><?php echo $langs->trans("TaxNo"); ?> <?php echo cl_sortArrow('t.tax_no', $sortfield, $sortorder); ?></a></th>
                <th><a href="<?php echo cl_sortHref('t.payment_delay', $sortfield, $sortorder, $self, $param); ?>"><?php echo $langs->trans("PaymentDelay"); ?> <?php echo cl_sortArrow('t.payment_delay', $sortfield, $sortorder); ?></a></th>
                <th class="center"><a href="<?php echo cl_sortHref('t.gender', $sortfield, $sortorder, $self, $param); ?>"><?php echo $langs->trans("Gender"); ?> <?php echo cl_sortArrow('t.gender', $sortfield, $sortorder); ?></a></th>
                <th class="center"><?php echo $langs->trans("Action"); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php if (!empty($rows)) {
            foreach ($rows as $obj) {
                $cardUrl = dol_buildpath('/flotte/customer_card.php', 1).'?id='.$obj->rowid;
                $fullName = trim(($obj->firstname ?? '').' '.($obj->lastname ?? ''));
        ?>
            <tr>
                <!-- Ref -->
                <td data-label="<?php echo $langs->trans('Ref'); ?>">
                    <a href="<?php echo $cardUrl; ?>" class="vl-ref-link">
                        <span class="vl-ref-icon"><i class="fa fa-user"></i></span>
                        <?php echo dol_escape_htmltag($obj->ref); ?>
                    </a>
                </td>

                <!-- Customer name + email -->
                <td data-label="<?php echo $langs->trans('Customer'); ?>">
                    <div class="vl-customer-name"><?php echo dol_escape_htmltag($fullName ?: '—'); ?></div>
                    <?php if (!empty($obj->email)) { ?>
                    <div class="vl-customer-sub"><?php echo dol_escape_htmltag($obj->email); ?></div>
                    <?php } ?>
                </td>

                <!-- Phone -->
                <td data-label="<?php echo $langs->trans('Phone'); ?>"><?php echo dol_escape_htmltag($obj->phone ?: '—'); ?></td>

                <!-- Company -->
                <td data-label="<?php echo $langs->trans('Company'); ?>">
                    <?php if (!empty($obj->company_name)) { ?>
                    <div class="vl-company-chip">
                        <i class="fa fa-building" style="font-size:11px;opacity:0.7;"></i>
                        <?php echo dol_escape_htmltag($obj->company_name); ?>
                    </div>
                    <?php } else { echo '<span style="color:#c4c9d8;">—</span>'; } ?>
                </td>

                <!-- Tax No -->
                <td data-label="<?php echo $langs->trans('TaxNo'); ?>">
                    <?php if (!empty($obj->tax_no)) { ?>
                    <span class="vl-mono"><?php echo dol_escape_htmltag($obj->tax_no); ?></span>
                    <?php } else { echo '<span style="color:#c4c9d8;">—</span>'; } ?>
                </td>

                <!-- Payment Delay -->
                <td data-label="<?php echo $langs->trans('PaymentDelay'); ?>">
                    <?php if (!empty($obj->payment_delay)) { ?>
                    <span class="vl-delay"><?php echo dol_escape_htmltag($obj->payment_delay); ?><span class="vl-delay-unit"><?php echo $langs->trans("Days"); ?></span></span>
                    <?php } else { echo '<span style="color:#c4c9d8;">—</span>'; } ?>
                </td>

                <!-- Gender -->
                <td class="center" data-label="<?php echo $langs->trans('Gender'); ?>">
                    <?php
                    $g = $obj->gender;
                    if ($g == 'male')        echo '<span class="vl-badge male">'.$langs->trans('Male').'</span>';
                    elseif ($g == 'female')  echo '<span class="vl-badge female">'.$langs->trans('Female').'</span>';
                    elseif ($g == 'other')   echo '<span class="vl-badge other">'.$langs->trans('Other').'</span>';
                    else                     echo '<span style="color:#c4c9d8;font-size:13px;">—</span>';
                    ?>
                </td>

                <!-- Actions -->
                <td data-label="<?php echo $langs->trans('Action'); ?>">
                    <div class="vl-actions">
                        <a href="<?php echo $cardUrl; ?>" class="vl-action-btn view" title="<?php echo $langs->trans('View'); ?>"><i class="fa fa-eye"></i></a>
                        <?php if ($user->rights->flotte->write) { ?>
                        <a href="<?php echo $cardUrl; ?>&action=edit" class="vl-action-btn edit" title="<?php echo $langs->trans('Edit'); ?>"><i class="fa fa-pen"></i></a>
                        <?php } ?>
                        <?php if ($user->rights->flotte->delete) { ?>
                        <a href="<?php echo dol_buildpath('/flotte/customer_list.php', 1); ?>?action=delete&id=<?php echo $obj->rowid; ?>&token=<?php echo newToken(); ?>" class="vl-action-btn del" title="<?php echo $langs->trans('Delete'); ?>"><i class="fa fa-trash"></i></a>
                        <?php } ?>
                    </div>
                </td>
            </tr>
        <?php }} else { ?>
            <tr>
                <td colspan="8">
                    <div class="vl-empty">
                        <div class="vl-empty-icon"><i class="fa fa-user-tie"></i></div>
                        <p><?php echo $langs->trans("NoCustomersFound"); ?></p>
                        <?php if ($user->rights->flotte->write) { ?>
                        <a class="vl-btn vl-btn-primary" href="<?php echo dol_buildpath('/flotte/customer_card.php', 1); ?>?action=create">
                            <i class="fa fa-plus"></i> <?php echo $langs->trans("AddFirstCustomer"); ?>
                        </a>
                        <?php } ?>
                    </div>
                </td>
            </tr>
        <?php } ?>
        </tbody>
    </table>
    </div>

    <!-- Pagination -->
    <?php if ($nbtotalofrecords > $limit) {
        $totalpages   = ceil($nbtotalofrecords / $limit);
        $prevpage     = max(0, $page - 1);
        $nextpage     = min($totalpages - 1, $page + 1);
        $showing_from = $offset + 1;
        $showing_to   = min($offset + $limit, $nbtotalofrecords);
    ?>
    <div class="vl-pagination">
        <div class="vl-pagination-info">
            <?php echo sprintf($langs->trans("ShowingCustomers"), $showing_from, $showing_to, $nbtotalofrecords); ?>
        </div>
        <div class="vl-page-btns">
            <a class="vl-page-btn <?php echo $page == 0 ? 'disabled' : ''; ?>" href="<?php echo $self; ?>?page=0&sortfield=<?php echo $sortfield; ?>&sortorder=<?php echo $sortorder; ?>&<?php echo $param; ?>">«</a>
            <a class="vl-page-btn <?php echo $page == 0 ? 'disabled' : ''; ?>" href="<?php echo $self; ?>?page=<?php echo $prevpage; ?>&sortfield=<?php echo $sortfield; ?>&sortorder=<?php echo $sortorder; ?>&<?php echo $param; ?>">‹</a>
            <?php
            $start = max(0, $page - 2);
            $end   = min($totalpages - 1, $page + 2);
            for ($p = $start; $p <= $end; $p++) {
                $active = $p == $page ? 'active' : '';
                echo '<a class="vl-page-btn '.$active.'" href="'.$self.'?page='.$p.'&sortfield='.$sortfield.'&sortorder='.$sortorder.'&'.$param.'">'.($p + 1).'</a>';
            }
            ?>
            <a class="vl-page-btn <?php echo $page >= $totalpages - 1 ? 'disabled' : ''; ?>" href="<?php echo $self; ?>?page=<?php echo $nextpage; ?>&sortfield=<?php echo $sortfield; ?>&sortorder=<?php echo $sortorder; ?>&<?php echo $param; ?>">›</a>
            <a class="vl-page-btn <?php echo $page >= $totalpages - 1 ? 'disabled' : ''; ?>" href="<?php echo $self; ?>?page=<?php echo $totalpages - 1; ?>&sortfield=<?php echo $sortfield; ?>&sortorder=<?php echo $sortorder; ?>&<?php echo $param; ?>">»</a>
        </div>
    </div>
    <?php } ?>
</div>

</form>
</div><!-- .vl-wrap -->

<!-- ═══════════════════════════════════════════════════════
     IMPORT MODAL
═══════════════════════════════════════════════════════ -->
<?php if ($user->rights->flotte->write) { ?>
<div class="vl-modal-overlay" id="cl-import-modal" onclick="if(event.target===this)clCloseImport()">
  <div class="vl-modal">

    <div class="vl-modal-header">
      <div class="vl-modal-header-left">
        <div class="vl-modal-icon"><i class="fa fa-file-import"></i></div>
        <div>
          <p class="vl-modal-title"><?php echo $langs->trans("ImportCustomers"); ?></p>
          <p class="vl-modal-sub"><?php echo $langs->trans("ImportCustomersSubtitle"); ?></p>
        </div>
      </div>
      <button class="vl-modal-close" onclick="clCloseImport()" title="<?php echo $langs->trans('Close'); ?>">&#x2715;</button>
    </div>

    <div class="vl-modal-body">

      <div class="vl-import-notice">
        <i class="fa fa-info-circle"></i>
        <div>
          <?php echo $langs->trans("ImportNoticeText"); ?>
          <a href="<?php echo dol_buildpath('/flotte/customer_list.php', 1); ?>?action=download_template&token=<?php echo newToken(); ?>">
            <i class="fa fa-download"></i> <?php echo $langs->trans("DownloadCSVTemplate"); ?>
          </a>
        </div>
      </div>

      <button type="button" class="vl-fields-toggle" onclick="clToggleFields(this)">
        <i class="fa fa-table"></i> <?php echo $langs->trans("ShowCSVColumns"); ?> <i class="fa fa-chevron-down" id="cl-fields-chevron"></i>
      </button>
      <div id="cl-fields-panel" style="display:none;margin-bottom:14px;">
        <table class="vl-fields-table">
          <thead>
            <tr>
              <th>#</th>
              <th><?php echo $langs->trans("ColumnName"); ?></th>
              <th><?php echo $langs->trans("Description"); ?></th>
              <th style="text-align:center;"><?php echo $langs->trans("Required"); ?></th>
            </tr>
          </thead>
          <tbody>
            <tr><td>1</td><td class="vl-col-name">firstname</td><td><?php echo $langs->trans("FirstName"); ?></td><td class="vl-col-opt">—</td></tr>
            <tr><td>2</td><td class="vl-col-name">lastname</td><td><?php echo $langs->trans("LastName"); ?></td><td class="vl-col-opt">—</td></tr>
            <tr><td>3</td><td class="vl-col-name">phone</td><td><?php echo $langs->trans("Phone"); ?></td><td class="vl-col-opt">—</td></tr>
            <tr><td>4</td><td class="vl-col-name">email</td><td><?php echo $langs->trans("Email"); ?></td><td class="vl-col-opt">—</td></tr>
            <tr><td>5</td><td class="vl-col-name">company_name</td><td><?php echo $langs->trans("Company"); ?></td><td class="vl-col-opt">—</td></tr>
            <tr><td>6</td><td class="vl-col-name">tax_no</td><td><?php echo $langs->trans("TaxNo"); ?></td><td class="vl-col-opt">—</td></tr>
            <tr><td>7</td><td class="vl-col-name">payment_delay</td><td><?php echo $langs->trans("PaymentDelay"); ?> (<?php echo $langs->trans("Days"); ?>)</td><td class="vl-col-opt">—</td></tr>
            <tr><td>8</td><td class="vl-col-name">gender</td><td>male / female / other</td><td class="vl-col-opt">—</td></tr>
          </tbody>
        </table>
      </div>

      <form method="POST" action="<?php echo dol_buildpath('/flotte/customer_list.php', 1); ?>"
            enctype="multipart/form-data" id="cl-import-form">
        <input type="hidden" name="token"  value="<?php echo newToken(); ?>">
        <input type="hidden" name="action" value="import_csv">

        <div class="vl-dropzone" id="cl-dropzone">
          <input type="file" name="import_file" id="cl-file-input" accept=".csv,text/csv"
                 onchange="clFileChosen(this)">
          <div class="vl-dropzone-icon"><i class="fa fa-cloud-upload-alt"></i></div>
          <div class="vl-dropzone-text"><?php echo $langs->trans("DropCSVHere"); ?></div>
          <div class="vl-dropzone-sub"><?php echo $langs->trans("OnlyCSVAccepted"); ?></div>
          <div class="vl-dropzone-file" id="cl-file-name"></div>
        </div>

        <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 0 0;border-top:1px solid #eaecf5;margin-top:18px;gap:10px;flex-wrap:wrap;">
          <button type="button" class="vl-btn" style="background:#fff;color:#5a6482;border:1.5px solid #d1d5e0;" onclick="clCloseImport()">
            <i class="fa fa-times"></i> <?php echo $langs->trans("Cancel"); ?>
          </button>
          <button type="submit" class="vl-btn vl-btn-primary" id="cl-import-submit" disabled>
            <i class="fa fa-check"></i> <?php echo $langs->trans("ImportNow"); ?>
          </button>
        </div>
      </form>

    </div>
  </div>
</div>
<?php } ?>

<script>
function clOpenImport()  { document.getElementById('cl-import-modal').classList.add('open'); }
function clCloseImport() {
    document.getElementById('cl-import-modal').classList.remove('open');
    document.getElementById('cl-import-form').reset();
    var dz = document.getElementById('cl-dropzone');
    if (dz) dz.classList.remove('has-file');
    document.getElementById('cl-file-name').textContent = '';
    document.getElementById('cl-import-submit').disabled = true;
}
function clFileChosen(input) {
    var dz  = document.getElementById('cl-dropzone');
    var fn  = document.getElementById('cl-file-name');
    var btn = document.getElementById('cl-import-submit');
    if (input.files && input.files.length > 0) {
        dz.classList.add('has-file');
        fn.textContent = input.files[0].name;
        btn.disabled = false;
    } else {
        dz.classList.remove('has-file');
        fn.textContent = '';
        btn.disabled = true;
    }
}
function clToggleFields(btn) {
    var panel   = document.getElementById('cl-fields-panel');
    var chevron = document.getElementById('cl-fields-chevron');
    if (panel.style.display === 'none') {
        panel.style.display = 'block';
        chevron.className = 'fa fa-chevron-up';
    } else {
        panel.style.display = 'none';
        chevron.className = 'fa fa-chevron-down';
    }
}
(function(){
    var dz = document.getElementById('cl-dropzone');
    if (!dz) return;
    dz.addEventListener('dragover',  function(e){ e.preventDefault(); dz.classList.add('drag-over'); });
    dz.addEventListener('dragleave', function(){ dz.classList.remove('drag-over'); });
    dz.addEventListener('drop',      function(){ dz.classList.remove('drag-over'); });
})();
document.addEventListener('keydown', function(e){ if (e.key === 'Escape') clCloseImport(); });
</script>

<?php
if ($resql) { $db->free($resql); }
llxFooter();
$db->close();
?>
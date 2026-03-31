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

// ── Helper: generate next driver reference ────────────────────────────────
if (!function_exists('getNextDriverRef')) {
    function getNextDriverRef($db) {
        $prefix = "DRV";
        $sql = "SELECT MAX(CAST(SUBSTRING(ref, 4) AS UNSIGNED)) as max_ref FROM ".MAIN_DB_PREFIX."flotte_driver WHERE ref LIKE '".$prefix."%'";
        $resql = $db->query($sql);
        if ($resql) {
            $obj = $db->fetch_object($resql);
            $next_num = ($obj && $obj->max_ref) ? $obj->max_ref + 1 : 1;
            return $prefix.str_pad($next_num, 4, '0', STR_PAD_LEFT);
        }
        return $prefix."0001";
    }
}

// ── Download CSV template ─────────────────────────────────────────────────
if ($action == 'download_template') {
    $columns = array(
        'firstname','middlename','lastname','email','phone',
        'employee_id','contract_number','license_number',
        'license_issue_date','license_expiry_date','join_date','leave_date',
        'department','status','gender','address','emergency_contact','vehicle_ref'
    );
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="drivers_import_template.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, $columns);
    // Example row
    fputcsv($out, array(
        'John','Michael','Doe','john.doe@example.com','0123456789',
        'EMP-001','CTR-2024-001','LIC-987654',
        date('Y-m-d', strtotime('-2 year')), date('Y-m-d', strtotime('+3 year')),
        date('Y-m-d', strtotime('-1 year')), '',
        'Logistics','active','male','123 Main Street','Jane Doe +21612345678','VEH-0001'
    ));
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

                // Pre-load vehicle ref → rowid map
                $vehicle_map = array();
                $vsql = "SELECT rowid, ref FROM ".MAIN_DB_PREFIX."flotte_vehicle WHERE entity IN (".getEntity('flotte').")";
                $vres = $db->query($vsql);
                if ($vres) { while ($vo = $db->fetch_object($vres)) { $vehicle_map[strtoupper(trim($vo->ref))] = $vo->rowid; } }

                while (($row = fgetcsv($handle)) !== false) {
                    $row_num++;
                    if (count($row) < 1) continue;

                    $firstname        = isset($row[0])  ? trim($row[0])  : '';
                    $middlename       = isset($row[1])  ? trim($row[1])  : '';
                    $lastname         = isset($row[2])  ? trim($row[2])  : '';
                    $email            = isset($row[3])  ? trim($row[3])  : '';
                    $phone            = isset($row[4])  ? trim($row[4])  : '';
                    $employee_id      = isset($row[5])  ? trim($row[5])  : '';
                    $contract_number  = isset($row[6])  ? trim($row[6])  : '';
                    $license_number   = isset($row[7])  ? trim($row[7])  : '';
                    $license_issue    = isset($row[8])  ? trim($row[8])  : '';
                    $license_expiry   = isset($row[9])  ? trim($row[9])  : '';
                    $join_date_str    = isset($row[10]) ? trim($row[10]) : '';
                    $leave_date_str   = isset($row[11]) ? trim($row[11]) : '';
                    $department       = isset($row[12]) ? trim($row[12]) : '';
                    $status           = isset($row[13]) ? trim($row[13]) : 'active';
                    $gender           = isset($row[14]) ? trim($row[14]) : '';
                    $address          = isset($row[15]) ? trim($row[15]) : '';
                    $emergency_contact= isset($row[16]) ? trim($row[16]) : '';
                    $vehicle_ref_str  = isset($row[17]) ? strtoupper(trim($row[17])) : '';

                    // Validate required fields
                    if (empty($firstname) || empty($lastname)) {
                        $import_errors[] = $langs->trans("Row").' '.$row_num.': firstname and lastname are required.';
                        continue;
                    }

                    // Resolve vehicle FK
                    $fk_vehicle = (!empty($vehicle_ref_str) && isset($vehicle_map[$vehicle_ref_str])) ? $vehicle_map[$vehicle_ref_str] : null;

                    // Convert dates
                    $lic_issue_ts  = !empty($license_issue)  ? dol_stringtotime($license_issue)  : 0;
                    $lic_expiry_ts = !empty($license_expiry) ? dol_stringtotime($license_expiry) : 0;
                    $join_ts       = !empty($join_date_str)  ? dol_stringtotime($join_date_str)  : 0;
                    $leave_ts      = !empty($leave_date_str) ? dol_stringtotime($leave_date_str) : 0;

                    $ref = getNextDriverRef($db);

                    $db->begin();
                    $sql_i  = "INSERT INTO ".MAIN_DB_PREFIX."flotte_driver (";
                    $sql_i .= "ref, entity, fk_user, firstname, middlename, lastname, address, email, phone, employee_id, contract_number, ";
                    $sql_i .= "license_number, license_issue_date, license_expiry_date, join_date, leave_date, ";
                    $sql_i .= "department, status, gender, emergency_contact, fk_vehicle, fk_user_author, datec";
                    $sql_i .= ") VALUES (";
                    $sql_i .= "'".$db->escape($ref)."', ".$conf->entity.", NULL, ";
                    $sql_i .= "'".$db->escape($firstname)."', '".$db->escape($middlename)."', '".$db->escape($lastname)."', ";
                    $sql_i .= "'".$db->escape($address)."', '".$db->escape($email)."', '".$db->escape($phone)."', ";
                    $sql_i .= "'".$db->escape($employee_id)."', '".$db->escape($contract_number)."', ";
                    $sql_i .= "'".$db->escape($license_number)."', ";
                    $sql_i .= ($lic_issue_ts  > 0 ? "'".$db->idate($lic_issue_ts)."'"  : "NULL").", ";
                    $sql_i .= ($lic_expiry_ts > 0 ? "'".$db->idate($lic_expiry_ts)."'" : "NULL").", ";
                    $sql_i .= ($join_ts  > 0 ? "'".$db->idate($join_ts)."'"  : "NULL").", ";
                    $sql_i .= ($leave_ts > 0 ? "'".$db->idate($leave_ts)."'" : "NULL").", ";
                    $sql_i .= "'".$db->escape($department)."', '".$db->escape($status)."', '".$db->escape($gender)."', ";
                    $sql_i .= "'".$db->escape($emergency_contact)."', ";
                    $sql_i .= ($fk_vehicle ? (int)$fk_vehicle : "NULL").", ";
                    $sql_i .= $user->id.", '".$db->idate(dol_now())."'";
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
                    setEventMessages(sprintf($langs->trans("ImportedDriversCount"), $imported), null, 'mesgs');
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

// Build param string for URL
$param = '';
if (!empty($search_ref))            $param .= '&search_ref='.urlencode($search_ref);
if (!empty($search_firstname))      $param .= '&search_firstname='.urlencode($search_firstname);
if (!empty($search_lastname))       $param .= '&search_lastname='.urlencode($search_lastname);
if (!empty($search_phone))          $param .= '&search_phone='.urlencode($search_phone);
if (!empty($search_status))         $param .= '&search_status='.urlencode($search_status);
if (!empty($search_employee_id))    $param .= '&search_employee_id='.urlencode($search_employee_id);
if (!empty($search_license_number)) $param .= '&search_license_number='.urlencode($search_license_number);

// Page header
llxHeader('', $langs->trans("Drivers List"), '');

// Show delete confirmation dialog if requested
if ($action == 'delete') {
    $id_to_delete = GETPOST('id', 'int');
    if ($id_to_delete > 0) {
        $formconfirm = $form->formconfirm($_SERVER["PHP_SELF"].'?id='.$id_to_delete, $langs->trans('DeleteDriver'), $langs->trans('ConfirmDeleteDriver'), 'confirm_delete', '', 0, 1);
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
function dl_sortArrow($field, $sortfield, $sortorder) {
    if ($sortfield == $field) return $sortorder == 'ASC' ? ' <span class="vl-sort-arrow">↑</span>' : ' <span class="vl-sort-arrow">↓</span>';
    return ' <span class="vl-sort-arrow muted">↕</span>';
}
function dl_sortHref($field, $sortfield, $sortorder, $self, $param) {
    $dir = ($sortfield == $field && $sortorder == 'ASC') ? 'DESC' : 'ASC';
    return $self.'?sortfield='.$field.'&sortorder='.$dir.'&'.$param;
}

$self = $_SERVER["PHP_SELF"];

// Count by status
$cnt_active = 0; $cnt_inactive = 0; $cnt_suspended = 0;
foreach ($rows as $r) {
    $s = strtolower($r->status);
    if ($s == 'active') $cnt_active++;
    elseif ($s == 'suspended' || $s == 'on leave') $cnt_suspended++;
    else $cnt_inactive++;
}
?>

<meta name="viewport" content="width=device-width, initial-scale=1.0">

<style>
@import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=DM+Mono:wght@400;500&display=swap');

.vl-wrap * { box-sizing: border-box; }

.vl-wrap {
    font-family: 'DM Sans', sans-serif;
    max-width: 100%;
    margin: 0;
    padding: 0 4px 40px;
    color: #1a1f2e;
    box-sizing: border-box;
}

/* Header */
.vl-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 28px 0 24px;
    border-bottom: 1px solid #e8eaf0;
    margin-bottom: 24px;
    gap: 16px;
    flex-wrap: wrap;
}
.vl-header-left h1 {
    font-size: 22px;
    font-weight: 700;
    color: #1a1f2e;
    margin: 0 0 4px;
    letter-spacing: -0.3px;
}
.vl-header-left .vl-subtitle { font-size: 13px; color: #7c859c; font-weight: 400; }
.vl-header-actions { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }

/* Buttons */
.vl-btn {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 8px 16px;
    border-radius: 6px;
    font-size: 13px;
    font-weight: 600;
    text-decoration: none !important;
    transition: all 0.15s ease;
    border: none;
    cursor: pointer;
    font-family: 'DM Sans', sans-serif;
    white-space: nowrap;
}
.vl-btn-primary  { background: #3c4758 !important; color: #fff !important; }
.vl-btn-primary:hover  { background: #2a3346 !important; color: #fff !important; }
.vl-btn-secondary { background: #3c4758 !important; color: #fff !important; border: none !important; }
.vl-btn-secondary:hover { background: #2a3346 !important; color: #fff !important; }

/* Filters */
.vl-filters {
    background: #fff;
    border: 1px solid #e8eaf0;
    border-radius: 12px;
    padding: 18px 20px;
    margin-bottom: 20px;
    display: flex;
    gap: 12px;
    align-items: flex-end;
    flex-wrap: wrap;
    box-shadow: 0 1px 4px rgba(0,0,0,0.04);
}
.vl-filter-group { display: flex; flex-direction: column; gap: 5px; flex: 1 1 120px; min-width: 0; }
.vl-filter-group label { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.6px; color: #9aa0b4; }
.vl-filter-group input,
.vl-filter-group select {
    padding: 8px 12px;
    border: 1.5px solid #e2e5f0;
    border-radius: 8px;
    font-size: 13px;
    font-family: 'DM Sans', sans-serif;
    color: #2d3748;
    background: #fafbfe;
    outline: none;
    transition: border-color 0.15s, box-shadow 0.15s;
    width: 100%;
}
.vl-filter-group input:focus,
.vl-filter-group select:focus {
    border-color: #3c4758;
    box-shadow: 0 0 0 3px rgba(60,71,88,0.1);
    background: #fff;
}
.vl-filter-actions { display: flex; gap: 8px; align-items: flex-end; padding-bottom: 1px; }
.vl-btn-filter {
    padding: 8px 16px;
    font-size: 13px;
    border-radius: 6px;
    font-weight: 600;
    border: none;
    cursor: pointer;
    font-family: 'DM Sans', sans-serif;
    transition: all 0.15s;
    white-space: nowrap;
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
.vl-stat-chip.active   { background: #edfaf3; color: #1a7d4a; }
.vl-stat-chip.active .vl-stat-num { color: #1a7d4a; }
.vl-stat-chip.suspended { background: #fff8ec; color: #b45309; }
.vl-stat-chip.suspended .vl-stat-num { color: #b45309; }
.vl-stat-chip.inactive { background: #fef2f2; color: #b91c1c; }
.vl-stat-chip.inactive .vl-stat-num { color: #b91c1c; }

/* Table */
.vl-table-card {
    background: #fff;
    border: 1px solid #e8eaf0;
    border-radius: 14px;
    overflow: hidden;
    box-shadow: 0 2px 12px rgba(0,0,0,0.05);
}
.vl-table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }

table.vl-table { width: 100%; border-collapse: collapse; font-size: 13.5px; }
table.vl-table thead tr { background: #f7f8fc; border-bottom: 2px solid #e8eaf0; }
table.vl-table thead th {
    padding: 13px 16px;
    text-align: left;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.7px;
    color: #8b92a9;
    white-space: normal;
    word-break: break-word;
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
    width: 30px; height: 30px;
    background: rgba(60,71,88,0.08);
    border-radius: 8px; display: flex; align-items: center;
    justify-content: center; color: #3c4758; font-size: 14px; flex-shrink: 0;
}

/* Driver name */
.vl-driver-name { font-weight: 600; color: #1a1f2e; font-size: 13.5px; }
.vl-driver-sub  { font-size: 11.5px; color: #9aa0b4; margin-top: 2px; }

/* Mono fields */
.vl-mono {
    font-family: 'DM Mono', monospace;
    font-size: 12px; color: #4a5568;
    background: #f0f2fa; padding: 3px 8px;
    border-radius: 5px; display: inline-block;
}

/* Vehicle chip */
.vl-vehicle-chip {
    display: inline-flex; align-items: center; gap: 6px;
    font-size: 12.5px; font-weight: 600;
    color: #3c4758; background: rgba(60,71,88,0.07);
    padding: 4px 10px; border-radius: 6px;
}
.vl-vehicle-sub { font-size: 11px; color: #9aa0b4; margin-top: 2px; }

/* Status badge */
.vl-badge {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 4px 10px; border-radius: 20px;
    font-size: 11.5px; font-weight: 600; white-space: nowrap;
}
.vl-badge::before { content: ''; width: 6px; height: 6px; border-radius: 50%; flex-shrink: 0; }
.vl-badge.active    { background: #edfaf3; color: #1a7d4a; }
.vl-badge.active::before { background: #22c55e; }
.vl-badge.suspended { background: #fff8ec; color: #b45309; }
.vl-badge.suspended::before { background: #f59e0b; }
.vl-badge.inactive  { background: #fef2f2; color: #b91c1c; }
.vl-badge.inactive::before  { background: #ef4444; }

/* Action buttons */
.vl-actions { display: flex; gap: 4px; justify-content: center; }
.vl-action-btn {
    width: 32px; height: 32px; border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    text-decoration: none; transition: all 0.15s;
    font-size: 13px; border: 1.5px solid transparent;
}
.vl-action-btn.view  { color: #3c4758; background: #eaecf0; border-color: #c4c9d4; }
.vl-action-btn.edit  { color: #d97706; background: #fef9ec; border-color: #fde9a2; }
.vl-action-btn.del   { color: #dc2626; background: #fef2f2; border-color: #fecaca; }
.vl-action-btn:hover { transform: translateY(-1px); box-shadow: 0 3px 8px rgba(0,0,0,0.1); text-decoration: none; }

/* Empty state */
.vl-empty { padding: 70px 20px; text-align: center; color: #9aa0b4; }
.vl-empty-icon { font-size: 52px; opacity: 0.3; margin-bottom: 16px; }
.vl-empty p { font-size: 15px; font-weight: 500; margin: 0 0 20px; color: #7c859c; }

/* Pagination */
.vl-pagination {
    display: flex; align-items: center; justify-content: space-between;
    padding: 16px 20px; border-top: 1px solid #f0f2f8;
    flex-wrap: wrap; gap: 12px;
}
.vl-pagination-info { font-size: 12.5px; color: #9aa0b4; }
.vl-page-btns { display: flex; gap: 4px; }
.vl-page-btn {
    min-width: 34px; height: 34px; padding: 0 10px;
    border-radius: 8px; display: inline-flex; align-items: center;
    justify-content: center; font-size: 13px; font-weight: 600;
    text-decoration: none; transition: all 0.15s;
    border: 1.5px solid #e2e5f0; color: #5a6482; background: #fff;
}
.vl-page-btn:hover { background: #f0f2fa; border-color: #c4c9d8; text-decoration: none; color: #2d3748; }
.vl-page-btn.active { background: #3c4758; color: #fff; border-color: transparent; }
.vl-page-btn.disabled { opacity: 0.35; pointer-events: none; }

/* ══════════════════════════════════════
   RESPONSIVE BREAKPOINTS
══════════════════════════════════════ */

/* Sidebar-aware (≤ 1300px): hide Department + LicenseNumber */
@media (max-width: 1300px) {
    table.vl-table th:nth-child(5),
    table.vl-table td:nth-child(5),
    table.vl-table th:nth-child(6),
    table.vl-table td:nth-child(6) { display: none; }
}

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

    /* Hide less critical columns: Employee ID, Department */
    table.vl-table th:nth-child(4),
    table.vl-table td:nth-child(4),
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

    /* Ref cell — no label, left-aligned */
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
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(15,20,35,0.45);
    backdrop-filter: blur(3px);
    z-index: 9999;
    align-items: center;
    justify-content: center;
    padding: 16px;
}
.vl-modal-overlay.open { display: flex; }
.vl-modal {
    background: #fff;
    border-radius: 14px;
    width: 100%;
    max-width: 600px;
    box-shadow: 0 20px 60px rgba(0,0,0,0.18);
    font-family: 'DM Sans', sans-serif;
    overflow: hidden;
    animation: dlModalIn 0.18s ease;
}
@keyframes dlModalIn {
    from { opacity:0; transform: translateY(-14px) scale(0.97); }
    to   { opacity:1; transform: translateY(0)     scale(1);    }
}
.vl-modal-header {
    display: flex; align-items: center; justify-content: space-between;
    padding: 18px 22px 16px;
    border-bottom: 1px solid #eaecf5;
    background: #f7f8fc;
}
.vl-modal-header-left { display: flex; align-items: center; gap: 11px; }
.vl-modal-icon {
    width: 38px; height: 38px; border-radius: 10px;
    background: rgba(60,71,88,0.1);
    display: flex; align-items: center; justify-content: center;
    color: #3c4758; font-size: 16px; flex-shrink: 0;
}
.vl-modal-title { font-size: 15px; font-weight: 700; color: #1a1f2e; margin: 0; }
.vl-modal-sub   { font-size: 12px; color: #9aa0b4; margin: 2px 0 0; }
.vl-modal-close {
    background: none; border: none; cursor: pointer;
    color: #9aa0b4; font-size: 18px; padding: 4px;
    border-radius: 6px; line-height: 1; transition: color 0.15s, background 0.15s;
}
.vl-modal-close:hover { color: #1a1f2e; background: #e8eaf0; }
.vl-modal-body { padding: 22px; max-height: 65vh; overflow-y: auto; }

.vl-import-notice {
    background: #f0f4ff;
    border: 1px solid #c7d4fb;
    border-radius: 8px;
    padding: 12px 14px;
    font-size: 12.5px;
    color: #3c4758;
    margin-bottom: 18px;
    display: flex; align-items: flex-start; gap: 10px;
}
.vl-import-notice i { flex-shrink: 0; margin-top: 2px; color: #4a6cf7; }
.vl-import-notice a { color: #4a6cf7; font-weight: 600; text-decoration: underline; }

.vl-fields-table {
    width: 100%; border-collapse: collapse; font-size: 12px;
    margin-bottom: 18px; border-radius: 8px; overflow: hidden;
    border: 1px solid #e8eaf0;
}
.vl-fields-table thead tr { background: #f7f8fc; }
.vl-fields-table th {
    padding: 8px 12px; text-align: left; font-size: 11px; font-weight: 700;
    text-transform: uppercase; letter-spacing: 0.5px; color: #8b92a9;
    border-bottom: 1px solid #e8eaf0;
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
    display: inline-flex; align-items: center; gap: 5px;
    font-family: 'DM Sans', sans-serif;
}
.vl-fields-toggle:hover { text-decoration: underline; }

.vl-dropzone {
    border: 2px dashed #c8cddf; border-radius: 10px;
    padding: 28px 20px; text-align: center; cursor: pointer;
    transition: border-color 0.15s, background 0.15s;
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

<div class="vl-wrap">

<div class="vl-header">
    <div class="vl-header-left">
        <h1><i class="fa fa-users" style="color:#3c4758;margin-right:10px;"></i><?php echo $langs->trans("Drivers List"); ?></h1>
        <div class="vl-subtitle"><?php echo $nbtotalofrecords.' '.$langs->trans("DriversFound"); ?></div>
    </div>
    <div class="vl-header-actions">
        <?php if ($user->rights->flotte->read) { ?>
        <a class="vl-btn vl-btn-secondary" href="<?php echo dol_buildpath('/flotte/driver_list.php', 1); ?>?action=export">
            <i class="fa fa-download"></i> <?php echo $langs->trans('Export'); ?>
        </a>
        <?php } ?>
        <?php if ($user->rights->flotte->write) { ?>
        <button type="button" class="vl-btn vl-btn-import" onclick="dlOpenImport()">
            <i class="fa fa-file-import"></i> <?php echo $langs->trans('Import'); ?>
        </button>
        <?php } ?>
        <?php if ($user->rights->flotte->write) { ?>
        <a class="vl-btn vl-btn-primary" href="<?php echo dol_buildpath('/flotte/driver_card.php', 1); ?>?action=create">
            <i class="fa fa-plus"></i> <?php echo $langs->trans('NewDriver'); ?>
        </a>
        <?php } ?>
    </div>
</div>

<form method="POST" action="<?php echo $self; ?>">
<input type="hidden" name="token" value="<?php echo newToken(); ?>">
<input type="hidden" name="formfilteraction" value="list">
<input type="hidden" name="action" value="list">
<input type="hidden" name="sortfield" value="<?php echo $sortfield; ?>">
<input type="hidden" name="sortorder" value="<?php echo $sortorder; ?>">
<input type="hidden" name="page" value="<?php echo $page; ?>">

<div class="vl-filters">
    <div class="vl-filter-group">
        <label><?php echo $langs->trans('Reference'); ?></label>
        <input type="text" name="search_ref" placeholder="<?php echo $langs->trans('SearchRef'); ?>" value="<?php echo dol_escape_htmltag($search_ref); ?>">
    </div>
    <div class="vl-filter-group">
        <label><?php echo $langs->trans('FirstName'); ?></label>
        <input type="text" name="search_firstname" placeholder="<?php echo $langs->trans('SearchFirstName'); ?>" value="<?php echo dol_escape_htmltag($search_firstname); ?>">
    </div>
    <div class="vl-filter-group">
        <label><?php echo $langs->trans('LastName'); ?></label>
        <input type="text" name="search_lastname" placeholder="<?php echo $langs->trans('SearchLastName'); ?>" value="<?php echo dol_escape_htmltag($search_lastname); ?>">
    </div>
    <div class="vl-filter-group">
        <label><?php echo $langs->trans('Phone'); ?></label>
        <input type="text" name="search_phone" placeholder="<?php echo $langs->trans('SearchPhone'); ?>" value="<?php echo dol_escape_htmltag($search_phone); ?>">
    </div>
    <div class="vl-filter-group">
        <label><?php echo $langs->trans('EmployeeID'); ?></label>
        <input type="text" name="search_employee_id" placeholder="<?php echo $langs->trans('SearchEmployeeId'); ?>" value="<?php echo dol_escape_htmltag($search_employee_id); ?>">
    </div>
    <div class="vl-filter-group">
        <label><?php echo $langs->trans('LicenseNumber'); ?></label>
        <input type="text" name="search_license_number" placeholder="<?php echo $langs->trans('SearchLicenseNo'); ?>" value="<?php echo dol_escape_htmltag($search_license_number); ?>">
    </div>
    <div class="vl-filter-group" style="max-width:150px;">
        <label><?php echo $langs->trans('Status'); ?></label>
        <select name="search_status">
            <option value=""><?php echo $langs->trans('AllStatuses'); ?></option>
            <option value="active"    <?php echo $search_status === 'active'    ? 'selected' : ''; ?>><?php echo $langs->trans('Active'); ?></option>
            <option value="inactive"  <?php echo $search_status === 'inactive'  ? 'selected' : ''; ?>><?php echo $langs->trans('Inactive'); ?></option>
            <option value="suspended" <?php echo $search_status === 'suspended' ? 'selected' : ''; ?>><?php echo $langs->trans('Suspended'); ?></option>
        </select>
    </div>
    <div class="vl-filter-actions">
        <button type="submit" class="vl-btn-filter apply"><i class="fa fa-search"></i> <?php echo $langs->trans('Search'); ?></button>
        <button type="submit" name="button_removefilter" value="1" class="vl-btn-filter reset"><i class="fa fa-times"></i> <?php echo $langs->trans('Reset'); ?></button>
    </div>
</div>

<div class="vl-stats">
    <div class="vl-stat-chip">
        <span class="vl-stat-num"><?php echo $nbtotalofrecords; ?></span> <?php echo $langs->trans('Total'); ?>
    </div>
    <?php if ($cnt_active > 0) { ?>
    <div class="vl-stat-chip active">
        <span class="vl-stat-num"><?php echo $cnt_active; ?></span> <?php echo $langs->trans('Active'); ?>
    </div>
    <?php } ?>
    <?php if ($cnt_suspended > 0) { ?>
    <div class="vl-stat-chip suspended">
        <span class="vl-stat-num"><?php echo $cnt_suspended; ?></span> <?php echo $langs->trans('Suspended'); ?>
    </div>
    <?php } ?>
    <?php if ($cnt_inactive > 0) { ?>
    <div class="vl-stat-chip inactive">
        <span class="vl-stat-num"><?php echo $cnt_inactive; ?></span> <?php echo $langs->trans('Inactive'); ?>
    </div>
    <?php } ?>
</div>

<div class="vl-table-card">
    <div class="vl-table-wrap">
    <table class="vl-table">
        <thead>
            <tr>
                <th><a href="<?php echo dl_sortHref('t.ref', $sortfield, $sortorder, $self, $param); ?>"><?php echo $langs->trans('Ref'); ?> <?php echo dl_sortArrow('t.ref', $sortfield, $sortorder); ?></a></th>
                <th><a href="<?php echo dl_sortHref('t.firstname', $sortfield, $sortorder, $self, $param); ?>"><?php echo $langs->trans('Driver'); ?> <?php echo dl_sortArrow('t.firstname', $sortfield, $sortorder); ?></a></th>
                <th><a href="<?php echo dl_sortHref('t.phone', $sortfield, $sortorder, $self, $param); ?>"><?php echo $langs->trans('Phone'); ?> <?php echo dl_sortArrow('t.phone', $sortfield, $sortorder); ?></a></th>
                <th><a href="<?php echo dl_sortHref('t.employee_id', $sortfield, $sortorder, $self, $param); ?>"><?php echo $langs->trans('EmployeeID'); ?> <?php echo dl_sortArrow('t.employee_id', $sortfield, $sortorder); ?></a></th>
                <th><a href="<?php echo dl_sortHref('t.license_number', $sortfield, $sortorder, $self, $param); ?>"><?php echo $langs->trans('LicenseNumber'); ?> <?php echo dl_sortArrow('t.license_number', $sortfield, $sortorder); ?></a></th>
                <th><a href="<?php echo dl_sortHref('t.department', $sortfield, $sortorder, $self, $param); ?>"><?php echo $langs->trans('Department'); ?> <?php echo dl_sortArrow('t.department', $sortfield, $sortorder); ?></a></th>
                <th><a href="<?php echo dl_sortHref('v.ref', $sortfield, $sortorder, $self, $param); ?>"><?php echo $langs->trans('AssignedVehicle'); ?> <?php echo dl_sortArrow('v.ref', $sortfield, $sortorder); ?></a></th>
                <th class="center"><a href="<?php echo dl_sortHref('t.status', $sortfield, $sortorder, $self, $param); ?>"><?php echo $langs->trans('Status'); ?> <?php echo dl_sortArrow('t.status', $sortfield, $sortorder); ?></a></th>
                <th class="center"><?php echo $langs->trans('Action'); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php if (!empty($rows)) {
            foreach ($rows as $obj) {
                $cardUrl = dol_buildpath('/flotte/driver_card.php', 1).'?id='.$obj->rowid;
                $fullName = trim($obj->firstname.' '.($obj->middlename ? $obj->middlename.' ' : '').$obj->lastname);
        ?>
            <tr>
                <td data-label="<?php echo $langs->trans('Ref'); ?>">
                    <a href="<?php echo $cardUrl; ?>" class="vl-ref-link">
                        <span class="vl-ref-icon"><i class="fa fa-user"></i></span>
                        <?php echo dol_escape_htmltag($obj->ref); ?>
                    </a>
                </td>

                <td data-label="<?php echo $langs->trans('Driver'); ?>">
                    <div class="vl-driver-name"><?php echo dol_escape_htmltag($fullName); ?></div>
                    <?php if (!empty($obj->email)) { ?>
                    <div class="vl-driver-sub"><?php echo dol_escape_htmltag($obj->email); ?></div>
                    <?php } ?>
                </td>

                <td data-label="<?php echo $langs->trans('Phone'); ?>"><?php echo dol_escape_htmltag($obj->phone ?: '—'); ?></td>

                <td data-label="<?php echo $langs->trans('EmployeeID'); ?>">
                    <?php if (!empty($obj->employee_id)) { ?>
                    <span class="vl-mono"><?php echo dol_escape_htmltag($obj->employee_id); ?></span>
                    <?php } else { echo '—'; } ?>
                </td>

                <td data-label="<?php echo $langs->trans('LicenseNumber'); ?>">
                    <?php if (!empty($obj->license_number)) { ?>
                    <span class="vl-mono"><?php echo dol_escape_htmltag($obj->license_number); ?></span>
                    <?php } else { echo '—'; } ?>
                </td>

                <td data-label="<?php echo $langs->trans('Department'); ?>"><?php echo dol_escape_htmltag($obj->department ?: '—'); ?></td>

                <td data-label="<?php echo $langs->trans('AssignedVehicle'); ?>">
                    <?php if ($obj->vehicle_ref) { ?>
                    <div class="vl-vehicle-chip">
                        <i class="fa fa-car" style="font-size:11px;opacity:0.6;"></i>
                        <?php echo dol_escape_htmltag($obj->vehicle_ref); ?>
                    </div>
                    <?php if ($obj->vehicle_maker || $obj->vehicle_model) { ?>
                    <div class="vl-vehicle-sub"><?php echo dol_escape_htmltag(trim($obj->vehicle_maker.' '.$obj->vehicle_model)); ?></div>
                    <?php } ?>
                    <?php } else { ?>
                    <span style="color:#c4c9d8;font-size:13px;"><?php echo $langs->trans('NotAssigned'); ?></span>
                    <?php } ?>
                </td>

                <td class="center" data-label="<?php echo $langs->trans('Status'); ?>">
                    <?php
                    $s = strtolower($obj->status);
                    if ($s == 'active') echo '<span class="vl-badge active">'.$langs->trans('Active').'</span>';
                    elseif ($s == 'suspended' || $s == 'on leave') echo '<span class="vl-badge suspended">'.$langs->trans('Suspended').'</span>';
                    else echo '<span class="vl-badge inactive">'.$langs->trans('Inactive').'</span>';
                    ?>
                </td>

                <td data-label="<?php echo $langs->trans('Action'); ?>">
                    <div class="vl-actions">
                        <a href="<?php echo $cardUrl; ?>" class="vl-action-btn view" title="<?php echo $langs->trans('View'); ?>"><i class="fa fa-eye"></i></a>
                        <?php if ($user->rights->flotte->write) { ?>
                        <a href="<?php echo $cardUrl; ?>&action=edit" class="vl-action-btn edit" title="<?php echo $langs->trans('Edit'); ?>"><i class="fa fa-pen"></i></a>
                        <?php } ?>
                        <?php if ($user->rights->flotte->delete) { ?>
                        <a href="<?php echo dol_buildpath('/flotte/driver_list.php', 1); ?>?action=delete&id=<?php echo $obj->rowid; ?>&token=<?php echo newToken(); ?>" class="vl-action-btn del" title="<?php echo $langs->trans('Delete'); ?>"><i class="fa fa-trash"></i></a>
                        <?php } ?>
                    </div>
                </td>
            </tr>
        <?php }} else { ?>
            <tr>
                <td colspan="9">
                    <div class="vl-empty">
                        <div class="vl-empty-icon"><i class="fa fa-users"></i></div>
                        <p><?php echo $langs->trans('NoDriversFound'); ?></p>
                        <?php if ($user->rights->flotte->write) { ?>
                        <a class="vl-btn vl-btn-primary" href="<?php echo dol_buildpath('/flotte/driver_card.php', 1); ?>?action=create">
                            <i class="fa fa-plus"></i> <?php echo $langs->trans('AddFirstDriver'); ?>
                        </a>
                        <?php } ?>
                    </div>
                </td>
            </tr>
        <?php } ?>
        </tbody>
    </table>
    </div>

    <?php if ($nbtotalofrecords > $limit) {
        $totalpages   = ceil($nbtotalofrecords / $limit);
        $prevpage     = max(0, $page - 1);
        $nextpage     = min($totalpages - 1, $page + 1);
        $showing_from = $offset + 1;
        $showing_to   = min($offset + $limit, $nbtotalofrecords);
    ?>
    <div class="vl-pagination">
        <div class="vl-pagination-info">
            <?php echo sprintf($langs->trans("ShowingDrivers"), $showing_from, $showing_to, $nbtotalofrecords); ?>
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

<!-- ═══════════════════════════════════════════════════════
     IMPORT MODAL
═══════════════════════════════════════════════════════ -->
<?php if ($user->rights->flotte->write) { ?>
<div class="vl-modal-overlay" id="dl-import-modal" onclick="if(event.target===this)dlCloseImport()">
  <div class="vl-modal">

    <div class="vl-modal-header">
      <div class="vl-modal-header-left">
        <div class="vl-modal-icon"><i class="fa fa-file-import"></i></div>
        <div>
          <p class="vl-modal-title"><?php echo $langs->trans("ImportDrivers"); ?></p>
          <p class="vl-modal-sub"><?php echo $langs->trans("ImportDriversSubtitle"); ?></p>
        </div>
      </div>
      <button class="vl-modal-close" onclick="dlCloseImport()" title="<?php echo $langs->trans('Close'); ?>">&#x2715;</button>
    </div>

    <div class="vl-modal-body">

      <div class="vl-import-notice">
        <i class="fa fa-info-circle"></i>
        <div>
          <?php echo $langs->trans("ImportNoticeText"); ?>
          <a href="<?php echo dol_buildpath('/flotte/driver_list.php', 1); ?>?action=download_template&token=<?php echo newToken(); ?>">
            <i class="fa fa-download"></i> <?php echo $langs->trans("DownloadCSVTemplate"); ?>
          </a>
          <br><small style="color:#5a6482;font-size:11.5px;"><?php echo $langs->trans("ImportDriversNote"); ?></small>
        </div>
      </div>

      <button type="button" class="vl-fields-toggle" onclick="dlToggleFields(this)">
        <i class="fa fa-table"></i> <?php echo $langs->trans("ShowCSVColumns"); ?> <i class="fa fa-chevron-down" id="dl-fields-chevron"></i>
      </button>
      <div id="dl-fields-panel" style="display:none;margin-bottom:14px;">
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
            <tr><td>1</td><td class="vl-col-name">firstname</td><td><?php echo $langs->trans("FirstName"); ?></td><td class="vl-col-req">✓</td></tr>
            <tr><td>2</td><td class="vl-col-name">middlename</td><td><?php echo $langs->trans("MiddleName"); ?></td><td class="vl-col-opt">—</td></tr>
            <tr><td>3</td><td class="vl-col-name">lastname</td><td><?php echo $langs->trans("LastName"); ?></td><td class="vl-col-req">✓</td></tr>
            <tr><td>4</td><td class="vl-col-name">email</td><td><?php echo $langs->trans("Email"); ?></td><td class="vl-col-opt">—</td></tr>
            <tr><td>5</td><td class="vl-col-name">phone</td><td><?php echo $langs->trans("Phone"); ?></td><td class="vl-col-opt">—</td></tr>
            <tr><td>6</td><td class="vl-col-name">employee_id</td><td><?php echo $langs->trans("EmployeeID"); ?></td><td class="vl-col-opt">—</td></tr>
            <tr><td>7</td><td class="vl-col-name">contract_number</td><td><?php echo $langs->trans("ContractNumber"); ?></td><td class="vl-col-opt">—</td></tr>
            <tr><td>8</td><td class="vl-col-name">license_number</td><td><?php echo $langs->trans("LicenseNumber"); ?></td><td class="vl-col-opt">—</td></tr>
            <tr><td>9</td><td class="vl-col-name">license_issue_date</td><td><?php echo $langs->trans("LicenseIssueDate"); ?> (YYYY-MM-DD)</td><td class="vl-col-opt">—</td></tr>
            <tr><td>10</td><td class="vl-col-name">license_expiry_date</td><td><?php echo $langs->trans("LicenseExpiryDate"); ?> (YYYY-MM-DD)</td><td class="vl-col-opt">—</td></tr>
            <tr><td>11</td><td class="vl-col-name">join_date</td><td><?php echo $langs->trans("JoinDate"); ?> (YYYY-MM-DD)</td><td class="vl-col-opt">—</td></tr>
            <tr><td>12</td><td class="vl-col-name">leave_date</td><td><?php echo $langs->trans("LeaveDate"); ?> (YYYY-MM-DD)</td><td class="vl-col-opt">—</td></tr>
            <tr><td>13</td><td class="vl-col-name">department</td><td><?php echo $langs->trans("Department"); ?></td><td class="vl-col-opt">—</td></tr>
            <tr><td>14</td><td class="vl-col-name">status</td><td>active / inactive / suspended</td><td class="vl-col-opt">—</td></tr>
            <tr><td>15</td><td class="vl-col-name">gender</td><td>male / female</td><td class="vl-col-opt">—</td></tr>
            <tr><td>16</td><td class="vl-col-name">address</td><td><?php echo $langs->trans("Address"); ?></td><td class="vl-col-opt">—</td></tr>
            <tr><td>17</td><td class="vl-col-name">emergency_contact</td><td><?php echo $langs->trans("EmergencyContact"); ?></td><td class="vl-col-opt">—</td></tr>
            <tr><td>18</td><td class="vl-col-name">vehicle_ref</td><td><?php echo $langs->trans("AssignedVehicle"); ?> (e.g. VEH-0001)</td><td class="vl-col-opt">—</td></tr>
          </tbody>
        </table>
      </div>

      <form method="POST" action="<?php echo dol_buildpath('/flotte/driver_list.php', 1); ?>"
            enctype="multipart/form-data" id="dl-import-form">
        <input type="hidden" name="token"  value="<?php echo newToken(); ?>">
        <input type="hidden" name="action" value="import_csv">

        <div class="vl-dropzone" id="dl-dropzone">
          <input type="file" name="import_file" id="dl-file-input" accept=".csv,text/csv"
                 onchange="dlFileChosen(this)">
          <div class="vl-dropzone-icon"><i class="fa fa-cloud-upload-alt"></i></div>
          <div class="vl-dropzone-text"><?php echo $langs->trans("DropCSVHere"); ?></div>
          <div class="vl-dropzone-sub"><?php echo $langs->trans("OnlyCSVAccepted"); ?></div>
          <div class="vl-dropzone-file" id="dl-file-name"></div>
        </div>

        <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 0 0;border-top:1px solid #eaecf5;margin-top:18px;gap:10px;flex-wrap:wrap;">
          <button type="button" class="vl-btn" style="background:#fff;color:#5a6482;border:1.5px solid #d1d5e0;" onclick="dlCloseImport()">
            <i class="fa fa-times"></i> <?php echo $langs->trans("Cancel"); ?>
          </button>
          <button type="submit" class="vl-btn vl-btn-primary" id="dl-import-submit" disabled>
            <i class="fa fa-check"></i> <?php echo $langs->trans("ImportNow"); ?>
          </button>
        </div>
      </form>

    </div>
  </div>
</div>
<?php } ?>

<script>
function dlOpenImport()  { document.getElementById('dl-import-modal').classList.add('open'); }
function dlCloseImport() {
    document.getElementById('dl-import-modal').classList.remove('open');
    document.getElementById('dl-import-form').reset();
    var dz = document.getElementById('dl-dropzone');
    if (dz) dz.classList.remove('has-file');
    document.getElementById('dl-file-name').textContent = '';
    document.getElementById('dl-import-submit').disabled = true;
}
function dlFileChosen(input) {
    var dz  = document.getElementById('dl-dropzone');
    var fn  = document.getElementById('dl-file-name');
    var btn = document.getElementById('dl-import-submit');
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
function dlToggleFields(btn) {
    var panel   = document.getElementById('dl-fields-panel');
    var chevron = document.getElementById('dl-fields-chevron');
    if (panel.style.display === 'none') {
        panel.style.display = 'block';
        chevron.className = 'fa fa-chevron-up';
    } else {
        panel.style.display = 'none';
        chevron.className = 'fa fa-chevron-down';
    }
}
(function(){
    var dz = document.getElementById('dl-dropzone');
    if (!dz) return;
    dz.addEventListener('dragover',  function(e){ e.preventDefault(); dz.classList.add('drag-over'); });
    dz.addEventListener('dragleave', function(){ dz.classList.remove('drag-over'); });
    dz.addEventListener('drop',      function(){ dz.classList.remove('drag-over'); });
})();
document.addEventListener('keydown', function(e){ if (e.key === 'Escape') dlCloseImport(); });
</script>

</div><?php
if ($resql) { $db->free($resql); }
llxFooter();
$db->close();
?>
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
require_once DOL_DOCUMENT_ROOT.'/categories/class/categorie.class.php';

// Load translation files
$langs->loadLangs(array("flotte@flotte", "other"));

// Get parameters
$action = GETPOST('action', 'aZ09') ? GETPOST('action', 'aZ09') : 'view';
$massaction = GETPOST('massaction', 'alpha');
$confirm = GETPOST('confirm', 'alpha');
$toselect = GETPOST('toselect', 'array');

$search_ref = GETPOST('search_ref', 'alpha');
$search_title = GETPOST('search_title', 'alpha');
$search_number = GETPOST('search_number', 'alpha');
$search_barcode = GETPOST('search_barcode', 'alpha');
$search_manufacturer = GETPOST('search_manufacturer', 'alpha');
$search_model = GETPOST('search_model', 'alpha');
$search_status = GETPOST('search_status', 'alpha');
$search_availability = GETPOST('search_availability', 'alpha');
$search_vendor = GETPOST('search_vendor', 'int');

$limit = GETPOST('limit', 'int') ? GETPOST('limit', 'int') : $conf->liste_limit;
$sortfield = GETPOST('sortfield', 'aZ09comma');
$sortorder = GETPOST('sortorder', 'aZ09comma');
$page = GETPOSTISSET('pageplusone') ? (GETPOST('pageplusone') - 1) : GETPOST("page", 'int');

if (empty($page) || $page == -1) {
    $page = 0;
}

$offset = $limit * $page;

if (!$sortfield) {
    $sortfield = "t.title";
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
    $search_title = '';
    $search_number = '';
    $search_barcode = '';
    $search_manufacturer = '';
    $search_model = '';
    $search_status = '';
    $search_availability = '';
    $search_vendor = '';
}

// Delete action
if ($action == 'confirm_delete' && $confirm == 'yes' && $user->rights->flotte->write) {
    $id = GETPOST('id', 'int');
    if ($id > 0) {
        $sql = "DELETE FROM ".MAIN_DB_PREFIX."flotte_part WHERE rowid = ".(int)$id;
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

// ── Helper: generate next part reference ─────────────────────────────────
if (!function_exists('getNextPartRef')) {
    function getNextPartRef($db, $entity) {
        $prefix = "PART-";
        $sql = "SELECT ref FROM ".MAIN_DB_PREFIX."flotte_part";
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
        'title','number','barcode','manufacturer','model','year',
        'status','availability','qty_on_hand','unit_cost','vendor_name','description','note'
    );
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="parts_import_template.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, $columns);
    fputcsv($out, array('Air Filter','AF-1234','8901234567890','Bosch','Clio','2019','Active','1','10','12.50','Bosch Vendor','OEM air filter',''));
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
                fgetcsv($handle); // skip header row
                $imported      = 0;
                $import_errors = array();
                $row_num       = 1;

                while (($row = fgetcsv($handle)) !== false) {
                    $row_num++;
                    if (count($row) < 1) continue;

                    $title        = isset($row[0])  ? trim($row[0])  : '';
                    $number       = isset($row[1])  ? trim($row[1])  : '';
                    $barcode      = isset($row[2])  ? trim($row[2])  : '';
                    $manufacturer = isset($row[3])  ? trim($row[3])  : '';
                    $model        = isset($row[4])  ? trim($row[4])  : '';
                    $year         = isset($row[5])  && $row[5] !== '' ? (int)trim($row[5]) : null;
                    $status       = isset($row[6])  ? trim($row[6])  : 'Active';
                    $availability = isset($row[7])  && $row[7] !== '' ? (int)trim($row[7]) : 1;
                    $qty_on_hand  = isset($row[8])  && $row[8] !== '' ? (float)trim($row[8]) : 0;
                    $unit_cost    = isset($row[9])  && $row[9] !== '' ? (float)trim($row[9]) : null;
                    $vendor_name  = isset($row[10]) ? trim($row[10]) : '';
                    $description  = isset($row[11]) ? trim($row[11]) : '';
                    $note         = isset($row[12]) ? trim($row[12]) : '';

                    if (empty($title)) {
                        $import_errors[] = "Row $row_num: title is required.";
                        continue;
                    }

                    // Validate status
                    $valid_statuses = array('Active','Inactive','Maintenance','Discontinued');
                    if (!in_array($status, $valid_statuses)) $status = 'Active';
                    $availability = ($availability == 1) ? 1 : 0;

                    // Resolve vendor FK by name
                    $fk_vendor = null;
                    if (!empty($vendor_name)) {
                        $rq = $db->query("SELECT rowid FROM ".MAIN_DB_PREFIX."flotte_vendor WHERE name = '".$db->escape($vendor_name)."' AND entity IN (".getEntity('flotte').") LIMIT 1");
                        if ($rq && $db->num_rows($rq) > 0) {
                            $fk_vendor = (int)$db->fetch_object($rq)->rowid;
                        }
                    }

                    $ref = getNextPartRef($db, $conf->entity);

                    $db->begin();
                    $sql_ins  = "INSERT INTO ".MAIN_DB_PREFIX."flotte_part ";
                    $sql_ins .= "(ref, entity, title, number, barcode, manufacturer, model, year, ";
                    $sql_ins .= "status, availability, qty_on_hand, unit_cost, fk_vendor, description, note) VALUES (";
                    $sql_ins .= "'".$db->escape($ref)."', ".(int)$conf->entity.", ";
                    $sql_ins .= "'".$db->escape($title)."', ";
                    $sql_ins .= (!empty($number)       ? "'".$db->escape($number)."'"       : "NULL").", ";
                    $sql_ins .= (!empty($barcode)      ? "'".$db->escape($barcode)."'"      : "NULL").", ";
                    $sql_ins .= (!empty($manufacturer) ? "'".$db->escape($manufacturer)."'" : "NULL").", ";
                    $sql_ins .= (!empty($model)        ? "'".$db->escape($model)."'"        : "NULL").", ";
                    $sql_ins .= ($year !== null        ? (int)$year                         : "NULL").", ";
                    $sql_ins .= "'".$db->escape($status)."', ";
                    $sql_ins .= (int)$availability.", ";
                    $sql_ins .= (float)$qty_on_hand.", ";
                    $sql_ins .= ($unit_cost !== null   ? (float)$unit_cost                  : "NULL").", ";
                    $sql_ins .= ($fk_vendor !== null   ? (int)$fk_vendor                    : "NULL").", ";
                    $sql_ins .= "'".$db->escape($description)."', ";
                    $sql_ins .= "'".$db->escape($note)."'";
                    $sql_ins .= ")";

                    if ($db->query($sql_ins)) {
                        $db->commit();
                        $imported++;
                    } else {
                        $db->rollback();
                        $import_errors[] = "Row $row_num: DB error — ".$db->lasterror();
                    }
                }
                fclose($handle);

                if ($imported > 0) {
                    setEventMessages($langs->trans("ImportSuccess", $imported), null, 'mesgs');
                }
                foreach ($import_errors as $ie) {
                    setEventMessages($ie, null, 'warnings');
                }
            }
        } else {
            setEventMessages($langs->trans("ErrorOnlyCSVAccepted"), null, 'errors');
        }
    }
    header('Location: '.dol_buildpath('/flotte/part_list.php', 1));
    exit;
}

// Build and execute select
$sql = 'SELECT t.rowid, t.ref, t.barcode, t.title, t.number, t.description, t.status, t.availability,';
$sql .= ' t.fk_vendor, t.manufacturer, t.year, t.model, t.qty_on_hand, t.unit_cost, t.note, t.picture,';
$sql .= ' v.name as vendor_name';
$sql .= ' FROM '.MAIN_DB_PREFIX.'flotte_part as t';
$sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'flotte_vendor as v ON t.fk_vendor = v.rowid';
$sql .= ' WHERE 1 = 1';
$sql .= ' AND t.entity IN ('.getEntity('flotte').')';

if ($search_ref) {
    $sql .= " AND t.ref LIKE '%".$db->escape($search_ref)."%'";
}
if ($search_title) {
    $sql .= " AND t.title LIKE '%".$db->escape($search_title)."%'";
}
if ($search_number) {
    $sql .= " AND t.number LIKE '%".$db->escape($search_number)."%'";
}
if ($search_barcode) {
    $sql .= " AND t.barcode LIKE '%".$db->escape($search_barcode)."%'";
}
if ($search_manufacturer) {
    $sql .= " AND t.manufacturer LIKE '%".$db->escape($search_manufacturer)."%'";
}
if ($search_model) {
    $sql .= " AND t.model LIKE '%".$db->escape($search_model)."%'";
}
if ($search_status) {
    $sql .= " AND t.status = '".$db->escape($search_status)."'";
}
if ($search_availability) {
    $sql .= " AND t.availability = ".((int) $search_availability);
}
if ($search_vendor) {
    $sql .= " AND t.fk_vendor = ".((int) $search_vendor);
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

// Get vendors and categories for filters
$vendors = array();
$sql_vendors = "SELECT rowid, name FROM ".MAIN_DB_PREFIX."flotte_vendor WHERE entity = ".$conf->entity." ORDER BY name";
$resql_vendors = $db->query($sql_vendors);
if ($resql_vendors) {
    while ($obj = $db->fetch_object($resql_vendors)) {
        $vendors[$obj->rowid] = $obj->name;
    }
}

$form = new Form($db);
$num = 0;
if ($resql) {
    $num = $db->num_rows($resql);
}

// Page header
llxHeader('', $langs->trans("PartsList"), '');

// Build param string for URL
$param = '';
if (!empty($search_ref))           $param .= '&search_ref='.urlencode($search_ref);
if (!empty($search_title))         $param .= '&search_title='.urlencode($search_title);
if (!empty($search_number))        $param .= '&search_number='.urlencode($search_number);
if (!empty($search_barcode))       $param .= '&search_barcode='.urlencode($search_barcode);
if (!empty($search_manufacturer))  $param .= '&search_manufacturer='.urlencode($search_manufacturer);
if (!empty($search_model))         $param .= '&search_model='.urlencode($search_model);
if (!empty($search_status))        $param .= '&search_status='.urlencode($search_status);
if (!empty($search_availability))  $param .= '&search_availability='.urlencode($search_availability);
if (!empty($search_vendor))        $param .= '&search_vendor='.urlencode($search_vendor);

// Confirmation to delete
if ($action == 'delete') {
    $id = GETPOST('id', 'int');
    $formconfirm = $form->formconfirm($_SERVER["PHP_SELF"].'?id='.$id.$param, $langs->trans('DeletePart'), $langs->trans('ConfirmDeletePart'), 'confirm_delete', '', 0, 1);
    print $formconfirm;

    // Clear the action from URL after showing confirmation to prevent reappearing on refresh
    if (!empty($formconfirm)) {
        echo '<script type="text/javascript">
        if (window.history.replaceState) {
            window.history.replaceState(null, null, "'.$_SERVER["PHP_SELF"].'?'.$param.'");
        }
        </script>';
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
function pl_sortArrow($field, $sortfield, $sortorder) {
    if ($sortfield == $field) return $sortorder == 'ASC' ? ' <span class="vl-sort-arrow">↑</span>' : ' <span class="vl-sort-arrow">↓</span>';
    return ' <span class="vl-sort-arrow muted">↕</span>';
}
function pl_sortHref($field, $sortfield, $sortorder, $self, $param) {
    $dir = ($sortfield == $field && $sortorder == 'ASC') ? 'DESC' : 'ASC';
    return $self.'?sortfield='.$field.'&sortorder='.$dir.'&'.$param;
}

$self = $_SERVER["PHP_SELF"];

// Count by status
$cnt_active = 0; $cnt_inactive = 0; $cnt_maintenance = 0; $cnt_discontinued = 0;
$cnt_available = 0; $cnt_out_of_stock = 0;
foreach ($rows as $r) {
    if ($r->status == 'Active')        $cnt_active++;
    elseif ($r->status == 'Inactive')  $cnt_inactive++;
    elseif ($r->status == 'Maintenance') $cnt_maintenance++;
    elseif ($r->status == 'Discontinued') $cnt_discontinued++;
    if ($r->availability == 1) $cnt_available++;
    if ($r->qty_on_hand <= 0)  $cnt_out_of_stock++;
}
?>

<style>
@import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=DM+Mono:wght@400;500&display=swap');

.vl-wrap * { box-sizing: border-box; }

.vl-wrap {
    font-family: 'DM Sans', sans-serif;
    max-width: 100%;
    margin: 0;
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
.vl-filter-group { display: flex; flex-direction: column; gap: 5px; flex: 1 1 120px; min-width: 0; }
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
    text-transform: uppercase; letter-spacing: 0.7px; color: #8b92a9; white-space: normal; word-break: break-word;
}
table.vl-table thead th a { color: #8b92a9; text-decoration: none; display: inline-flex; align-items: center; gap: 4px; transition: color 0.15s; }
table.vl-table thead th a:hover { color: #3c4758; }
table.vl-table thead th.center { text-align: center; }
table.vl-table thead th.right  { text-align: right; }

.vl-sort-arrow { font-size: 10px; opacity: 0.6; }
.vl-sort-arrow.muted { opacity: 0.25; }

table.vl-table tbody tr { border-bottom: 1px solid #f0f2f8; transition: background 0.12s; }
table.vl-table tbody tr:last-child { border-bottom: none; }
table.vl-table tbody tr:hover { background: #fafbff; }
table.vl-table tbody td { padding: 14px 16px; color: #2d3748; vertical-align: middle; }
table.vl-table tbody td.center { text-align: center; }
table.vl-table tbody td.right  { text-align: right; }

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

/* Part title */
.vl-part-name { font-weight: 600; color: #1a1f2e; font-size: 13.5px; }
.vl-part-sub  { font-size: 11.5px; color: #9aa0b4; margin-top: 2px; }

/* Mono */
.vl-mono {
    font-family: 'DM Mono', monospace; font-size: 12px; color: #4a5568;
    background: #f0f2fa; padding: 3px 8px; border-radius: 5px; display: inline-block;
}

/* Category chip */
.vl-category-chip {
    display: inline-flex; align-items: center; gap: 6px;
    font-size: 12.5px; font-weight: 600; color: #1d4ed8;
    background: #eff6ff; padding: 4px 10px; border-radius: 6px;
}

/* Stock indicator */
.vl-stock {
    display: inline-flex; align-items: baseline; gap: 4px;
    font-weight: 700; font-size: 13px;
}
.vl-stock.ok      { color: #166534; }
.vl-stock.low     { color: #92400e; }
.vl-stock.empty   { color: #991b1b; }

/* Status / availability badge */
.vl-badge {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 4px 10px; border-radius: 20px;
    font-size: 11.5px; font-weight: 600; white-space: nowrap;
}
.vl-badge::before { content: ''; width: 6px; height: 6px; border-radius: 50%; flex-shrink: 0; }
.vl-badge.active        { background: #f0fdf4; color: #166534; }
.vl-badge.active::before { background: #22c55e; }
.vl-badge.inactive      { background: #fef2f2; color: #991b1b; }
.vl-badge.inactive::before { background: #ef4444; }
.vl-badge.maintenance   { background: #fffbeb; color: #92400e; }
.vl-badge.maintenance::before { background: #f59e0b; }
.vl-badge.discontinued  { background: #f0f2fa; color: #5a6482; }
.vl-badge.discontinued::before { background: #8b92a9; }
.vl-badge.available     { background: #f0fdf4; color: #166534; }
.vl-badge.available::before { background: #22c55e; }
.vl-badge.unavailable   { background: #fef2f2; color: #991b1b; }
.vl-badge.unavailable::before { background: #ef4444; }

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

/* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
   RESPONSIVE STYLES
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */

/* ── 1100px: tighten table cell padding ── */
/* Sidebar-aware (≤ 1300px): hide less critical columns */
@media (max-width: 1300px) {
    table.vl-table th:nth-child(4),
    table.vl-table td:nth-child(4),
    table.vl-table th:nth-child(5),
    table.vl-table td:nth-child(5) { display: none; }
}

@media (max-width: 1100px) {
    .vl-wrap { padding: 0 10px 40px; }
    table.vl-table thead th,
    table.vl-table tbody td { padding: 11px 10px; }
}

/* ── 900px: stack filters ── */
@media (max-width: 900px) {
    .vl-filters { flex-direction: column; gap: 10px; }
    .vl-filter-group { min-width: 100%; max-width: 100% !important; }
    .vl-filter-actions { width: 100%; }
    .vl-btn-filter { flex: 1; justify-content: center; }
    .vl-stats { gap: 8px; }
}

/* ── 760px: hide lower-priority columns, stack header ── */
@media (max-width: 760px) {
    .vl-wrap { padding: 0 8px 32px; }

    .vl-header { flex-direction: column; align-items: flex-start; gap: 12px; padding: 18px 0 16px; }
    .vl-header-actions { width: 100%; }
    .vl-btn { flex: 1; justify-content: center; }

    /* Hide: Barcode (4), Manufacturer (5), Available (10) */
    table.vl-table thead th:nth-child(4),
    table.vl-table tbody td:nth-child(4),
    table.vl-table thead th:nth-child(5),
    table.vl-table tbody td:nth-child(5),
    table.vl-table thead th:nth-child(10),
    table.vl-table tbody td:nth-child(10) { display: none; }

    table.vl-table thead th,
    table.vl-table tbody td { padding: 10px 8px; font-size: 12.5px; }

    .vl-pagination { flex-direction: column; align-items: center; gap: 10px; padding: 14px 12px; }
    .vl-page-btn { min-width: 30px; height: 30px; font-size: 12px; }
}

/* ── 540px: card-style rows for mobile ── */
@media (max-width: 540px) {
    .vl-wrap { padding: 0 6px 24px; }
    .vl-header-left h1 { font-size: 18px; }
    .vl-header-left .vl-subtitle { font-size: 12px; }

    /* Switch table to card layout */
    .vl-table-wrap { overflow-x: unset; }
    table.vl-table { display: block; }
    table.vl-table thead { display: none; }
    table.vl-table tbody { display: flex; flex-direction: column; gap: 10px; padding: 10px; }
    table.vl-table tbody tr {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 6px 12px;
        border: 1px solid #e8eaf0;
        border-radius: 10px;
        padding: 12px 14px;
        background: #fff;
        box-shadow: 0 1px 4px rgba(0,0,0,0.04);
    }
    table.vl-table tbody tr:hover { background: #fafbff; }
    table.vl-table tbody td {
        display: flex;
        flex-direction: column;
        padding: 0;
        border: none;
        font-size: 13px;
        text-align: left !important;
    }

    /* Restore hidden columns */
    table.vl-table tbody td:nth-child(4),
    table.vl-table tbody td:nth-child(5),
    table.vl-table tbody td:nth-child(10) { display: flex; }

    /* Column labels via pseudo-elements */
    table.vl-table tbody td::before {
        content: attr(data-label);
        font-size: 10px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        color: #9aa0b4;
        margin-bottom: 2px;
    }

    /* Ref spans full width at top */
    table.vl-table tbody td:nth-child(1) {
        grid-column: 1 / -1;
        border-bottom: 1px solid #f0f2f8;
        padding-bottom: 8px;
        margin-bottom: 2px;
    }
    /* Actions span full width at bottom */
    table.vl-table tbody td:last-child {
        grid-column: 1 / -1;
        border-top: 1px solid #f0f2f8;
        padding-top: 8px;
        margin-top: 2px;
    }
    .vl-actions { justify-content: flex-start; }

    /* Empty state row: undo grid */
    table.vl-table tbody tr:has(.vl-empty) {
        display: block;
        border: none;
        box-shadow: none;
        padding: 0;
    }

    /* Filters & stats */
    .vl-filters { padding: 14px 12px; border-radius: 10px; }
    .vl-filter-actions { flex-direction: row; }
    .vl-stats { gap: 6px; }
    .vl-stat-chip { font-size: 11.5px; padding: 5px 10px; }

    /* Pagination */
    .vl-page-btns { flex-wrap: wrap; justify-content: center; }
}
</style>

<div class="vl-wrap">

<!-- Header -->
<div class="vl-header">
    <div class="vl-header-left">
        <h1><i class="fa fa-cogs" style="color:#3c4758;margin-right:10px;"></i><?php echo $langs->trans("PartsList"); ?></h1>
        <div class="vl-subtitle"><?php echo $nbtotalofrecords; ?> <?php echo $langs->trans("PartsList"); ?></div>
    </div>
    <div class="vl-header-actions">
        <?php if ($user->rights->flotte->read) { ?>
        <a class="vl-btn vl-btn-secondary" href="<?php echo dol_buildpath('/flotte/part_list.php', 1); ?>?action=export">
            <i class="fa fa-download"></i> <?php echo $langs->trans("Export"); ?>
        </a>
        <?php } ?>
        <?php if ($user->rights->flotte->write) { ?>
        <button type="button" class="vl-btn vl-btn-secondary" onclick="plOpenImport()">
            <i class="fa fa-file-import"></i> <?php echo $langs->trans("Import"); ?>
        </button>
        <a class="vl-btn vl-btn-primary" href="<?php echo dol_buildpath('/flotte/part_card.php', 1); ?>?action=create">
            <i class="fa fa-plus"></i> <?php echo $langs->trans("NewPart"); ?>
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
        <label><?php echo $langs->trans("Reference"); ?></label>
        <input type="text" name="search_ref" placeholder="<?php echo $langs->trans('Reference'); ?>…" value="<?php echo dol_escape_htmltag($search_ref); ?>">
    </div>
    <div class="vl-filter-group">
        <label><?php echo $langs->trans("PartTitle"); ?></label>
        <input type="text" name="search_title" placeholder="<?php echo $langs->trans('PartTitle'); ?>…" value="<?php echo dol_escape_htmltag($search_title); ?>">
    </div>
    <div class="vl-filter-group">
        <label><?php echo $langs->trans("PartNumber"); ?></label>
        <input type="text" name="search_number" placeholder="<?php echo $langs->trans('PartNumber'); ?>…" value="<?php echo dol_escape_htmltag($search_number); ?>">
    </div>
    <div class="vl-filter-group">
        <label><?php echo $langs->trans("Barcode"); ?></label>
        <input type="text" name="search_barcode" placeholder="<?php echo $langs->trans('Barcode'); ?>…" value="<?php echo dol_escape_htmltag($search_barcode); ?>">
    </div>
    <div class="vl-filter-group">
        <label><?php echo $langs->trans("Manufacturer"); ?></label>
        <input type="text" name="search_manufacturer" placeholder="<?php echo $langs->trans('Manufacturer'); ?>…" value="<?php echo dol_escape_htmltag($search_manufacturer); ?>">
    </div>
    <div class="vl-filter-group" style="max-width:170px;">
        <label><?php echo $langs->trans("Vendor"); ?></label>
        <select name="search_vendor">
            <option value=""><?php echo $langs->trans("All"); ?></option>
            <?php foreach ($vendors as $v_id => $v_name) { ?>
            <option value="<?php echo (int)$v_id; ?>" <?php echo (int)$search_vendor === (int)$v_id ? 'selected' : ''; ?>><?php echo dol_escape_htmltag($v_name); ?></option>
            <?php } ?>
        </select>
    </div>
    <div class="vl-filter-group" style="max-width:160px;">
        <label><?php echo $langs->trans("Status"); ?></label>
        <select name="search_status">
            <option value=""><?php echo $langs->trans("All"); ?></option>
            <option value="Active"       <?php echo $search_status === 'Active'       ? 'selected' : ''; ?>><?php echo $langs->trans("Active"); ?></option>
            <option value="Inactive"     <?php echo $search_status === 'Inactive'     ? 'selected' : ''; ?>><?php echo $langs->trans("Inactive"); ?></option>
            <option value="Maintenance"  <?php echo $search_status === 'Maintenance'  ? 'selected' : ''; ?>><?php echo $langs->trans("Maintenance"); ?></option>
            <option value="Discontinued" <?php echo $search_status === 'Discontinued' ? 'selected' : ''; ?>><?php echo $langs->trans("Discontinued"); ?></option>
        </select>
    </div>
    <div class="vl-filter-group" style="max-width:160px;">
        <label><?php echo $langs->trans("Availability"); ?></label>
        <select name="search_availability">
            <option value=""><?php echo $langs->trans("All"); ?></option>
            <option value="1" <?php echo $search_availability === '1' ? 'selected' : ''; ?>><?php echo $langs->trans("Available"); ?></option>
            <option value="0" <?php echo $search_availability === '0' ? 'selected' : ''; ?>><?php echo $langs->trans("NotAvailable"); ?></option>
        </select>
    </div>
    <div class="vl-filter-actions">
        <button type="submit" class="vl-btn-filter apply"><i class="fa fa-search"></i> <?php echo $langs->trans("Search"); ?></button>
        <button type="submit" name="button_removefilter" value="1" class="vl-btn-filter reset"><i class="fa fa-times"></i> <?php echo $langs->trans("Reset"); ?></button>
    </div>
</div>

<!-- Stats chips -->
<div class="vl-stats">
    <div class="vl-stat-chip">
        <span class="vl-stat-num"><?php echo $nbtotalofrecords; ?></span> <?php echo $langs->trans("Total"); ?>
    </div>
    <?php if ($cnt_active > 0) { ?>
    <div class="vl-stat-chip" style="background:#f0fdf4;color:#166534;">
        <span class="vl-stat-num" style="color:#166534;"><?php echo $cnt_active; ?></span> <?php echo $langs->trans("Active"); ?>
    </div>
    <?php } ?>
    <?php if ($cnt_maintenance > 0) { ?>
    <div class="vl-stat-chip" style="background:#fffbeb;color:#92400e;">
        <span class="vl-stat-num" style="color:#92400e;"><?php echo $cnt_maintenance; ?></span> <?php echo $langs->trans("Maintenance"); ?>
    </div>
    <?php } ?>
    <?php if ($cnt_inactive > 0) { ?>
    <div class="vl-stat-chip" style="background:#fef2f2;color:#991b1b;">
        <span class="vl-stat-num" style="color:#991b1b;"><?php echo $cnt_inactive; ?></span> <?php echo $langs->trans("Inactive"); ?>
    </div>
    <?php } ?>
    <?php if ($cnt_discontinued > 0) { ?>
    <div class="vl-stat-chip" style="background:#f0f2fa;color:#5a6482;">
        <span class="vl-stat-num" style="color:#5a6482;"><?php echo $cnt_discontinued; ?></span> <?php echo $langs->trans("Discontinued"); ?>
    </div>
    <?php } ?>
    <?php if ($cnt_out_of_stock > 0) { ?>
    <div class="vl-stat-chip" style="background:#fef2f2;color:#991b1b;">
        <span class="vl-stat-num" style="color:#991b1b;"><?php echo $cnt_out_of_stock; ?></span> <?php echo $langs->trans("OutOfStock"); ?>
    </div>
    <?php } ?>
</div>

<!-- Table -->
<div class="vl-table-card">
    <div class="vl-table-wrap">
    <table class="vl-table">
        <thead>
            <tr>
                <th><a href="<?php echo pl_sortHref('t.ref', $sortfield, $sortorder, $self, $param); ?>"><?php echo $langs->trans("Ref"); ?> <?php echo pl_sortArrow('t.ref', $sortfield, $sortorder); ?></a></th>
                <th><a href="<?php echo pl_sortHref('t.title', $sortfield, $sortorder, $self, $param); ?>"><?php echo $langs->trans("PartTitle"); ?> <?php echo pl_sortArrow('t.title', $sortfield, $sortorder); ?></a></th>
                <th><a href="<?php echo pl_sortHref('t.number', $sortfield, $sortorder, $self, $param); ?>"><?php echo $langs->trans("PartNumber"); ?> <?php echo pl_sortArrow('t.number', $sortfield, $sortorder); ?></a></th>
                <th><a href="<?php echo pl_sortHref('t.barcode', $sortfield, $sortorder, $self, $param); ?>"><?php echo $langs->trans("Barcode"); ?> <?php echo pl_sortArrow('t.barcode', $sortfield, $sortorder); ?></a></th>
                <th><a href="<?php echo pl_sortHref('t.manufacturer', $sortfield, $sortorder, $self, $param); ?>"><?php echo $langs->trans("Manufacturer"); ?> <?php echo pl_sortArrow('t.manufacturer', $sortfield, $sortorder); ?></a></th>
                <th><a href="<?php echo pl_sortHref('v.name', $sortfield, $sortorder, $self, $param); ?>"><?php echo $langs->trans("Vendor"); ?> <?php echo pl_sortArrow('v.name', $sortfield, $sortorder); ?></a></th>
                <th class="right"><a href="<?php echo pl_sortHref('t.qty_on_hand', $sortfield, $sortorder, $self, $param); ?>"><?php echo $langs->trans("Stock"); ?> <?php echo pl_sortArrow('t.qty_on_hand', $sortfield, $sortorder); ?></a></th>
                <th class="right"><a href="<?php echo pl_sortHref('t.unit_cost', $sortfield, $sortorder, $self, $param); ?>"><?php echo $langs->trans("UnitCost"); ?> <?php echo pl_sortArrow('t.unit_cost', $sortfield, $sortorder); ?></a></th>
                <th class="center"><a href="<?php echo pl_sortHref('t.status', $sortfield, $sortorder, $self, $param); ?>"><?php echo $langs->trans("Status"); ?> <?php echo pl_sortArrow('t.status', $sortfield, $sortorder); ?></a></th>
                <th class="center"><a href="<?php echo pl_sortHref('t.availability', $sortfield, $sortorder, $self, $param); ?>"><?php echo $langs->trans("Availability"); ?> <?php echo pl_sortArrow('t.availability', $sortfield, $sortorder); ?></a></th>
                <th class="center"><?php echo $langs->trans("Action"); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php if (!empty($rows)) {
            foreach ($rows as $obj) {
                $cardUrl = dol_buildpath('/flotte/part_card.php', 1).'?id='.$obj->rowid;
        ?>
            <tr>
                <!-- Ref -->
                <td data-label="<?php echo $langs->trans('Ref'); ?>">
                    <a href="<?php echo $cardUrl; ?>" class="vl-ref-link">
                        <span class="vl-ref-icon"><i class="fa fa-cog"></i></span>
                        <?php echo dol_escape_htmltag($obj->ref); ?>
                    </a>
                </td>

                <!-- Title -->
                <td data-label="<?php echo $langs->trans('PartTitle'); ?>">
                    <div class="vl-part-name"><?php echo dol_escape_htmltag($obj->title ?: '—'); ?></div>
                    <?php if (!empty($obj->model) || !empty($obj->year)) { ?>
                    <div class="vl-part-sub"><?php echo dol_escape_htmltag(trim(($obj->model ?? '').' '.($obj->year ?? ''))); ?></div>
                    <?php } ?>
                </td>

                <!-- Part Number -->
                <td data-label="<?php echo $langs->trans('PartNumber'); ?>">
                    <?php if (!empty($obj->number)) { ?>
                    <span class="vl-mono"><?php echo dol_escape_htmltag($obj->number); ?></span>
                    <?php } else { echo '<span style="color:#c4c9d8;">—</span>'; } ?>
                </td>

                <!-- Barcode -->
                <td data-label="<?php echo $langs->trans('Barcode'); ?>">
                    <?php if (!empty($obj->barcode)) { ?>
                    <span class="vl-mono"><?php echo dol_escape_htmltag($obj->barcode); ?></span>
                    <?php } else { echo '<span style="color:#c4c9d8;">—</span>'; } ?>
                </td>

                <!-- Manufacturer -->
                <td data-label="<?php echo $langs->trans('Manufacturer'); ?>"><?php echo dol_escape_htmltag($obj->manufacturer ?: '—'); ?></td>

                <!-- Vendor -->
                <td data-label="<?php echo $langs->trans('Vendor'); ?>"><?php echo dol_escape_htmltag($obj->vendor_name ?: '—'); ?></td>

                <!-- Stock -->
                <td class="right" data-label="<?php echo $langs->trans('Stock'); ?>">
                    <?php
                    $qty = (int)$obj->qty_on_hand;
                    if ($qty <= 0)     $stock_cls = 'empty';
                    elseif ($qty <= 5) $stock_cls = 'low';
                    else               $stock_cls = 'ok';
                    ?>
                    <span class="vl-stock <?php echo $stock_cls; ?>"><?php echo $qty; ?></span>
                </td>

                <!-- Unit Cost -->
                <td class="right" data-label="<?php echo $langs->trans('UnitCost'); ?>">
                    <?php echo !empty($obj->unit_cost) ? price($obj->unit_cost) : '<span style="color:#c4c9d8;">—</span>'; ?>
                </td>

                <!-- Status -->
                <td class="center" data-label="<?php echo $langs->trans('Status'); ?>">
                    <?php
                    $st = $obj->status;
                    if ($st == 'Active')        echo '<span class="vl-badge active">'.$langs->trans('Active').'</span>';
                    elseif ($st == 'Inactive')  echo '<span class="vl-badge inactive">'.$langs->trans('Inactive').'</span>';
                    elseif ($st == 'Maintenance') echo '<span class="vl-badge maintenance">'.$langs->trans('Maintenance').'</span>';
                    elseif ($st == 'Discontinued') echo '<span class="vl-badge discontinued">'.$langs->trans('Discontinued').'</span>';
                    else echo '<span style="color:#c4c9d8;font-size:13px;">—</span>';
                    ?>
                </td>

                <!-- Availability -->
                <td class="center" data-label="<?php echo $langs->trans('Availability'); ?>">
                    <?php
                    if ($obj->availability == 1) echo '<span class="vl-badge available">'.$langs->trans('Available').'</span>';
                    else                         echo '<span class="vl-badge unavailable">'.$langs->trans('NotAvailable').'</span>';
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
                        <a href="<?php echo $self; ?>?id=<?php echo $obj->rowid; ?>&action=delete&token=<?php echo newToken(); ?><?php echo $param; ?>" class="vl-action-btn del" title="<?php echo $langs->trans('Delete'); ?>"><i class="fa fa-trash"></i></a>
                        <?php } ?>
                    </div>
                </td>
            </tr>
        <?php }} else { ?>
            <tr>
                <td colspan="11">
                    <div class="vl-empty">
                        <div class="vl-empty-icon"><i class="fa fa-cogs"></i></div>
                        <p><?php echo $langs->trans("PartsList"); ?></p>
                        <?php if ($user->rights->flotte->write) { ?>
                        <a class="vl-btn vl-btn-primary" href="<?php echo dol_buildpath('/flotte/part_card.php', 1); ?>?action=create">
                            <i class="fa fa-plus"></i> <?php echo $langs->trans("NewPart"); ?>
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
            <?php echo $langs->trans("ShowingVehicles", $showing_from, $showing_to, $nbtotalofrecords); ?>
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

<?php if ($user->rights->flotte->write) { ?>
<!-- ═══════════════════════════════════════════════════════
     IMPORT MODAL
═══════════════════════════════════════════════════════ -->
<div class="vl-modal-overlay" id="pl-import-modal" onclick="if(event.target===this)plCloseImport()">
  <div class="vl-modal">

    <div class="vl-modal-header">
      <div class="vl-modal-header-left">
        <div class="vl-modal-icon"><i class="fa fa-file-import"></i></div>
        <div>
          <p class="vl-modal-title"><?php echo $langs->trans("ImportParts"); ?></p>
          <p class="vl-modal-sub"><?php echo $langs->trans("ImportPartsSubtitle"); ?></p>
        </div>
      </div>
      <button class="vl-modal-close" onclick="plCloseImport()" title="<?php echo $langs->trans('Close'); ?>">&#x2715;</button>
    </div>

    <div class="vl-modal-body">

      <div class="vl-import-notice">
        <i class="fa fa-info-circle"></i>
        <div>
          <?php echo $langs->trans("ImportNoticeText"); ?>
          <a href="<?php echo dol_buildpath('/flotte/part_list.php', 1); ?>?action=download_template&token=<?php echo newToken(); ?>">
            <i class="fa fa-download"></i> <?php echo $langs->trans("DownloadCSVTemplate"); ?>
          </a>
        </div>
      </div>

      <button type="button" class="vl-fields-toggle" onclick="plToggleFields(this)">
        <i class="fa fa-table"></i> <?php echo $langs->trans("ShowCSVColumns"); ?> <i class="fa fa-chevron-down" id="pl-fields-chevron"></i>
      </button>
      <div id="pl-fields-panel" style="display:none;margin-bottom:14px;">
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
            <tr><td>1</td><td class="vl-col-name">title</td><td><?php echo $langs->trans("PartTitle"); ?></td><td style="text-align:center;color:#e53e3e;font-weight:700;">✓</td></tr>
            <tr><td>2</td><td class="vl-col-name">number</td><td><?php echo $langs->trans("PartNumber"); ?></td><td class="vl-col-opt">—</td></tr>
            <tr><td>3</td><td class="vl-col-name">barcode</td><td><?php echo $langs->trans("Barcode"); ?></td><td class="vl-col-opt">—</td></tr>
            <tr><td>4</td><td class="vl-col-name">manufacturer</td><td><?php echo $langs->trans("Manufacturer"); ?></td><td class="vl-col-opt">—</td></tr>
            <tr><td>5</td><td class="vl-col-name">model</td><td><?php echo $langs->trans("Model"); ?></td><td class="vl-col-opt">—</td></tr>
            <tr><td>6</td><td class="vl-col-name">year</td><td><?php echo $langs->trans("Year"); ?></td><td class="vl-col-opt">—</td></tr>
            <tr><td>7</td><td class="vl-col-name">status</td><td>Active / Inactive / Maintenance / Discontinued</td><td class="vl-col-opt">—</td></tr>
            <tr><td>8</td><td class="vl-col-name">availability</td><td>1 = <?php echo $langs->trans("Available"); ?>, 0 = <?php echo $langs->trans("NotAvailable"); ?></td><td class="vl-col-opt">—</td></tr>
            <tr><td>9</td><td class="vl-col-name">qty_on_hand</td><td><?php echo $langs->trans("Stock"); ?></td><td class="vl-col-opt">—</td></tr>
            <tr><td>10</td><td class="vl-col-name">unit_cost</td><td><?php echo $langs->trans("UnitCost"); ?></td><td class="vl-col-opt">—</td></tr>
            <tr><td>11</td><td class="vl-col-name">vendor_name</td><td><?php echo $langs->trans("Vendor"); ?> (exact name)</td><td class="vl-col-opt">—</td></tr>
            <tr><td>12</td><td class="vl-col-name">description</td><td><?php echo $langs->trans("Description"); ?></td><td class="vl-col-opt">—</td></tr>
            <tr><td>13</td><td class="vl-col-name">note</td><td><?php echo $langs->trans("Notes"); ?></td><td class="vl-col-opt">—</td></tr>
          </tbody>
        </table>
      </div>

      <form method="POST" action="<?php echo dol_buildpath('/flotte/part_list.php', 1); ?>"
            enctype="multipart/form-data" id="pl-import-form">
        <input type="hidden" name="token"  value="<?php echo newToken(); ?>">
        <input type="hidden" name="action" value="import_csv">

        <div class="vl-dropzone" id="pl-dropzone">
          <input type="file" name="import_file" id="pl-file-input" accept=".csv,text/csv"
                 onchange="plFileChosen(this)">
          <div class="vl-dropzone-icon"><i class="fa fa-cloud-upload-alt"></i></div>
          <div class="vl-dropzone-text"><?php echo $langs->trans("DropCSVHere"); ?></div>
          <div class="vl-dropzone-sub"><?php echo $langs->trans("OnlyCSVAccepted"); ?></div>
          <div class="vl-dropzone-file" id="pl-file-name"></div>
        </div>

        <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 0 0;border-top:1px solid #eaecf5;margin-top:18px;gap:10px;flex-wrap:wrap;">
          <button type="button" class="vl-btn" style="background:#fff;color:#5a6482;border:1.5px solid #d1d5e0;" onclick="plCloseImport()">
            <i class="fa fa-times"></i> <?php echo $langs->trans("Cancel"); ?>
          </button>
          <button type="submit" class="vl-btn vl-btn-primary" id="pl-import-submit" disabled>
            <i class="fa fa-check"></i> <?php echo $langs->trans("ImportNow"); ?>
          </button>
        </div>
      </form>

    </div>
  </div>
</div>

<style>
.vl-modal-overlay { display:none;position:fixed;inset:0;z-index:10000;background:rgba(15,20,40,0.45);backdrop-filter:blur(3px);align-items:center;justify-content:center; }
.vl-modal-overlay.open { display:flex; }
.vl-modal { background:#fff;border-radius:16px;width:100%;max-width:600px;box-shadow:0 24px 64px rgba(0,0,0,0.18);overflow:hidden;max-height:92vh;display:flex;flex-direction:column; }
.vl-modal-header { display:flex;align-items:center;justify-content:space-between;padding:22px 24px 18px;border-bottom:1px solid #eaecf5;flex-shrink:0; }
.vl-modal-header-left { display:flex;align-items:center;gap:14px; }
.vl-modal-icon { width:42px;height:42px;background:rgba(60,71,88,0.1);border-radius:10px;display:flex;align-items:center;justify-content:center;color:#3c4758;font-size:18px;flex-shrink:0; }
.vl-modal-title { font-size:16px;font-weight:700;color:#1a1f2e;margin:0 0 2px; }
.vl-modal-sub { font-size:12.5px;color:#7c859c;margin:0; }
.vl-modal-close { background:none;border:none;font-size:18px;color:#9aa0b4;cursor:pointer;padding:4px 8px;border-radius:6px;line-height:1;transition:all 0.15s; }
.vl-modal-close:hover { background:#f0f2fa;color:#3c4758; }
.vl-modal-body { padding:22px 24px 24px;overflow-y:auto; }
.vl-import-notice { display:flex;gap:12px;background:#f0f6ff;border:1px solid #c3d9ff;border-radius:10px;padding:14px 16px;margin-bottom:18px;font-size:13px;color:#2d4a8a;line-height:1.5; }
.vl-import-notice i { color:#3c7de0;font-size:16px;margin-top:1px;flex-shrink:0; }
.vl-import-notice a { color:#3c7de0;font-weight:600;text-decoration:none;display:inline-flex;align-items:center;gap:5px;margin-left:4px; }
.vl-import-notice a:hover { text-decoration:underline; }
.vl-fields-toggle { display:inline-flex;align-items:center;gap:7px;padding:8px 14px;background:#f7f8fc;border:1.5px solid #e2e5f0;border-radius:8px;font-size:12.5px;font-weight:600;color:#5a6482;cursor:pointer;font-family:'DM Sans',sans-serif;transition:all 0.15s;margin-bottom:12px; }
.vl-fields-toggle:hover { background:#eef0f8;border-color:#c8cce0; }
.vl-fields-table { width:100%;border-collapse:collapse;font-size:12.5px;margin-bottom:4px; }
.vl-fields-table th { background:#f7f8fc;padding:8px 10px;text-align:left;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;color:#8b92a9;border-bottom:1px solid #e8eaf0; }
.vl-fields-table td { padding:8px 10px;border-bottom:1px solid #f0f2f8;color:#2d3748; }
.vl-fields-table tr:last-child td { border-bottom:none; }
.vl-col-name { font-family:'DM Mono',monospace;font-size:12px;color:#3c4758;font-weight:600; }
.vl-col-opt  { text-align:center;color:#9aa0b4; }
.vl-dropzone { position:relative;border:2px dashed #d1d5e0;border-radius:12px;padding:36px 24px;text-align:center;cursor:pointer;transition:all 0.2s;background:#fafbfe; }
.vl-dropzone:hover,.vl-dropzone.drag-over { border-color:#3c4758;background:#f5f6fb; }
.vl-dropzone.has-file { border-color:#1a7d4a;background:#f0fdf4; }
.vl-dropzone input[type="file"] { position:absolute;inset:0;opacity:0;cursor:pointer;width:100%;height:100%; }
.vl-dropzone-icon { font-size:32px;color:#c4c9d8;margin-bottom:10px; }
.vl-dropzone.has-file .vl-dropzone-icon { color:#1a7d4a; }
.vl-dropzone-text { font-size:14px;font-weight:600;color:#3c4758;margin-bottom:4px; }
.vl-dropzone-sub  { font-size:12px;color:#9aa0b4; }
.vl-dropzone-file { font-size:13px;font-weight:600;color:#1a7d4a;margin-top:8px; }
</style>

<script>
function plOpenImport()  { document.getElementById('pl-import-modal').classList.add('open'); }
function plCloseImport() {
    document.getElementById('pl-import-modal').classList.remove('open');
    document.getElementById('pl-import-form').reset();
    var dz = document.getElementById('pl-dropzone');
    if (dz) dz.classList.remove('has-file');
    document.getElementById('pl-file-name').textContent = '';
    document.getElementById('pl-import-submit').disabled = true;
}
function plFileChosen(input) {
    var dz  = document.getElementById('pl-dropzone');
    var fn  = document.getElementById('pl-file-name');
    var btn = document.getElementById('pl-import-submit');
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
function plToggleFields(btn) {
    var panel   = document.getElementById('pl-fields-panel');
    var chevron = document.getElementById('pl-fields-chevron');
    if (panel.style.display === 'none') {
        panel.style.display = 'block';
        chevron.className = 'fa fa-chevron-up';
    } else {
        panel.style.display = 'none';
        chevron.className = 'fa fa-chevron-down';
    }
}
(function(){
    var dz = document.getElementById('pl-dropzone');
    if (!dz) return;
    dz.addEventListener('dragover',  function(e){ e.preventDefault(); dz.classList.add('drag-over'); });
    dz.addEventListener('dragleave', function(){ dz.classList.remove('drag-over'); });
    dz.addEventListener('drop',      function(){ dz.classList.remove('drag-over'); });
})();
document.addEventListener('keydown', function(e){ if (e.key === 'Escape') plCloseImport(); });
</script>
<?php } ?>

<?php
if ($resql) { $db->free($resql); }
llxFooter();
$db->close();
?>
<?php
/* Copyright (C) 2024 Your Company
 * Expenses list — reads from flotte_expense table
 */

$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) { $res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php"; }
$tmp  = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1; $j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) { $i--; $j--; }
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i+1))."/main.inc.php"))          { $res = @include substr($tmp, 0, ($i+1))."/main.inc.php"; }
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i+1)))."/main.inc.php")) { $res = @include dirname(substr($tmp, 0, ($i+1)))."/main.inc.php"; }
if (!$res && file_exists("../main.inc.php"))       { $res = @include "../main.inc.php"; }
if (!$res && file_exists("../../main.inc.php"))    { $res = @include "../../main.inc.php"; }
if (!$res && file_exists("../../../main.inc.php")) { $res = @include "../../../main.inc.php"; }
if (!$res) { die("Include of main fails"); }

require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';

$langs->loadLangs(array("flotte@flotte", "other"));
restrictedArea($user, 'flotte');

$action = GETPOST('action', 'aZ09');

/* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
   ENSURE TABLE EXISTS
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */
$db->query("CREATE TABLE IF NOT EXISTS ".MAIN_DB_PREFIX."flotte_expense (
  rowid              INT AUTO_INCREMENT PRIMARY KEY,
  ref                VARCHAR(30)      DEFAULT NULL,
  fk_booking         INT              DEFAULT NULL,
  expense_date       DATE             DEFAULT NULL,
  category           VARCHAR(30)      DEFAULT 'other',
  amount             DECIMAL(15,2)    DEFAULT NULL,
  notes              TEXT,
  fuel_vendor        INT              DEFAULT NULL,
  fuel_type          VARCHAR(50)      DEFAULT NULL,
  fuel_qty           DECIMAL(15,4)    DEFAULT NULL,
  fuel_price         DECIMAL(15,4)    DEFAULT NULL,
  road_toll          DECIMAL(15,2)    DEFAULT NULL,
  road_parking       DECIMAL(15,2)    DEFAULT NULL,
  road_other         DECIMAL(15,2)    DEFAULT NULL,
  driver_salary      DECIMAL(15,2)    DEFAULT NULL,
  driver_overnight   DECIMAL(15,2)    DEFAULT NULL,
  driver_bonus       DECIMAL(15,2)    DEFAULT NULL,
  commission_agent   DECIMAL(15,2)    DEFAULT NULL,
  commission_tax     DECIMAL(5,2)     DEFAULT NULL,
  commission_other   DECIMAL(15,2)    DEFAULT NULL,
  other_label        VARCHAR(255)     DEFAULT NULL,
  source             VARCHAR(20)      DEFAULT 'manual',
  entity             INT              DEFAULT 1,
  date_creation      DATETIME         DEFAULT NULL,
  fk_user_creat      INT              DEFAULT NULL,
  tms                TIMESTAMP        DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

/* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
   PARAMS
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */
$fk_booking   = GETPOST('fk_booking', 'int');
$filter_from  = GETPOST('date_from', 'alpha');
$filter_to    = GETPOST('date_to', 'alpha');
$search_ref   = GETPOST('search_ref', 'alpha');
$filter_cat   = GETPOST('category', 'alphanohtml');

$sortfield = GETPOST('sortfield', 'aZ09') ?: 'e.expense_date';
$sortorder = GETPOST('sortorder', 'aZ09') ?: 'DESC';

$limit  = 25;
$page   = max(0, (int)GETPOST('page', 'int'));
$offset = $page * $limit;

/* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
   IMPORT ACTIONS
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */

// ── Helper: generate next expense ref ────────────────────────────────────
if (!function_exists('getNextExpenseRef')) {
    function getNextExpenseRef($db, $entity) {
        $year = date('Y');
        $sql  = "SELECT MAX(CAST(SUBSTRING(ref, 9) AS UNSIGNED)) AS mx FROM ".MAIN_DB_PREFIX."flotte_expense WHERE ref LIKE 'EXP-".$year."-%' AND entity = ".((int)$entity);
        $res  = $db->query($sql);
        $mx   = 0;
        if ($res) { $obj = $db->fetch_object($res); $mx = (int)$obj->mx; }
        return 'EXP-'.$year.'-'.str_pad($mx + 1, 4, '0', STR_PAD_LEFT);
    }
}

// ── Download CSV template ─────────────────────────────────────────────────
if ($action == 'download_template') {
    $columns = array('expense_date','category','amount','booking_ref','notes',
                     'fuel_qty','fuel_price','fuel_type',
                     'road_toll','road_parking','road_other',
                     'driver_salary','driver_overnight','driver_bonus',
                     'commission_agent','commission_tax','commission_other','other_label');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="expenses_import_template.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, $columns);
    fputcsv($out, array('2024-06-15','fuel','','BOOK-0001','Fuel stop','50','1.85','diesel','','','','','','','','','',''));
    fputcsv($out, array('2024-06-15','road','','BOOK-0001','Toll','','','','12.50','5.00','0','','','','','','',''));
    fputcsv($out, array('2024-06-15','other','80','BOOK-0001','Misc expense','','','','','','','','','','','','','Parking fee'));
    fclose($out);
    exit;
}

// ── CSV Import ────────────────────────────────────────────────────────────
if ($action == 'import_csv' && !empty($user->rights->flotte->write)) {
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

                    $expense_date      = isset($row[0])  ? trim($row[0])           : '';
                    $category          = isset($row[1])  ? strtolower(trim($row[1])): 'other';
                    $amount_raw        = isset($row[2])  && $row[2] !== '' ? (float)trim($row[2]) : null;
                    $booking_ref       = isset($row[3])  ? trim($row[3])           : '';
                    $notes             = isset($row[4])  ? trim($row[4])           : '';
                    $fuel_qty          = isset($row[5])  && $row[5]  !== '' ? (float)trim($row[5])  : null;
                    $fuel_price        = isset($row[6])  && $row[6]  !== '' ? (float)trim($row[6])  : null;
                    $fuel_type         = isset($row[7])  ? trim($row[7])           : '';
                    $road_toll         = isset($row[8])  && $row[8]  !== '' ? (float)trim($row[8])  : null;
                    $road_parking      = isset($row[9])  && $row[9]  !== '' ? (float)trim($row[9])  : null;
                    $road_other        = isset($row[10]) && $row[10] !== '' ? (float)trim($row[10]) : null;
                    $driver_salary     = isset($row[11]) && $row[11] !== '' ? (float)trim($row[11]) : null;
                    $driver_overnight  = isset($row[12]) && $row[12] !== '' ? (float)trim($row[12]) : null;
                    $driver_bonus      = isset($row[13]) && $row[13] !== '' ? (float)trim($row[13]) : null;
                    $commission_agent  = isset($row[14]) && $row[14] !== '' ? (float)trim($row[14]) : null;
                    $commission_tax    = isset($row[15]) && $row[15] !== '' ? (float)trim($row[15]) : null;
                    $commission_other  = isset($row[16]) && $row[16] !== '' ? (float)trim($row[16]) : null;
                    $other_label       = isset($row[17]) ? trim($row[17])          : '';

                    // Validate date
                    if (empty($expense_date)) {
                        $import_errors[] = "Row $row_num: expense_date is required.";
                        continue;
                    }
                    $ts = strtotime($expense_date);
                    if ($ts === false) {
                        $import_errors[] = "Row $row_num: invalid expense_date '$expense_date'.";
                        continue;
                    }
                    $expense_date = date('Y-m-d', $ts);

                    // Validate category
                    $valid_cats = array('fuel','road','driver','commission','other');
                    if (!in_array($category, $valid_cats)) $category = 'other';

                    // Calculate amount from sub-fields if not given directly
                    if ($amount_raw === null) {
                        switch ($category) {
                            case 'fuel':
                                $amount_raw = ($fuel_qty !== null && $fuel_price !== null) ? round($fuel_qty * $fuel_price, 2) : 0;
                                break;
                            case 'road':
                                $amount_raw = round((float)$road_toll + (float)$road_parking + (float)$road_other, 2);
                                break;
                            case 'driver':
                                $amount_raw = round((float)$driver_salary + (float)$driver_overnight + (float)$driver_bonus, 2);
                                break;
                            case 'commission':
                                $agent = (float)$commission_agent;
                                $amount_raw = round($agent + ($agent * (float)$commission_tax / 100) + (float)$commission_other, 2);
                                break;
                            default:
                                $amount_raw = 0;
                        }
                    }

                    // Resolve booking FK
                    $fk_booking_v = null;
                    if (!empty($booking_ref)) {
                        $rq = $db->query("SELECT rowid FROM ".MAIN_DB_PREFIX."flotte_booking WHERE ref = '".$db->escape($booking_ref)."' AND entity IN (".getEntity('flotte').") LIMIT 1");
                        if ($rq && $db->num_rows($rq) > 0) {
                            $fk_booking_v = (int)$db->fetch_object($rq)->rowid;
                        }
                    }

                    $ref = getNextExpenseRef($db, $conf->entity);

                    $db->begin();
                    $sql_ins  = "INSERT INTO ".MAIN_DB_PREFIX."flotte_expense ";
                    $sql_ins .= "(ref, entity, expense_date, category, amount, fk_booking, notes, source, date_creation, fk_user_creat, ";
                    $sql_ins .= "fuel_qty, fuel_price, fuel_type, road_toll, road_parking, road_other, ";
                    $sql_ins .= "driver_salary, driver_overnight, driver_bonus, ";
                    $sql_ins .= "commission_agent, commission_tax, commission_other, other_label) VALUES (";
                    $sql_ins .= "'".$db->escape($ref)."', ".(int)$conf->entity.", ";
                    $sql_ins .= "'".$db->escape($expense_date)."', ";
                    $sql_ins .= "'".$db->escape($category)."', ";
                    $sql_ins .= (float)$amount_raw.", ";
                    $sql_ins .= ($fk_booking_v !== null ? (int)$fk_booking_v : "NULL").", ";
                    $sql_ins .= "'".$db->escape($notes)."', 'manual', NOW(), ".(int)$user->id.", ";
                    $sql_ins .= ($fuel_qty       !== null ? (float)$fuel_qty       : "NULL").", ";
                    $sql_ins .= ($fuel_price      !== null ? (float)$fuel_price     : "NULL").", ";
                    $sql_ins .= ($fuel_type !== '' ? "'".$db->escape($fuel_type)."'" : "NULL").", ";
                    $sql_ins .= ($road_toll       !== null ? (float)$road_toll       : "NULL").", ";
                    $sql_ins .= ($road_parking    !== null ? (float)$road_parking    : "NULL").", ";
                    $sql_ins .= ($road_other      !== null ? (float)$road_other      : "NULL").", ";
                    $sql_ins .= ($driver_salary   !== null ? (float)$driver_salary   : "NULL").", ";
                    $sql_ins .= ($driver_overnight !== null ? (float)$driver_overnight : "NULL").", ";
                    $sql_ins .= ($driver_bonus    !== null ? (float)$driver_bonus    : "NULL").", ";
                    $sql_ins .= ($commission_agent !== null ? (float)$commission_agent : "NULL").", ";
                    $sql_ins .= ($commission_tax  !== null ? (float)$commission_tax  : "NULL").", ";
                    $sql_ins .= ($commission_other !== null ? (float)$commission_other : "NULL").", ";
                    $sql_ins .= ($other_label !== '' ? "'".$db->escape($other_label)."'" : "NULL").")";

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
                if (!empty($import_errors)) {
                    foreach ($import_errors as $ie) {
                        setEventMessages($ie, null, 'warnings');
                    }
                }
            }
        } else {
            setEventMessages($langs->trans("ErrorOnlyCSVAccepted"), null, 'errors');
        }
    }
    // PRG: redirect so reload doesn't re-submit
    header('Location: '.dol_buildpath('/flotte/expenses_list.php', 1));
    exit;
}

/* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
   QUERY
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */
$where = " WHERE e.entity IN (".getEntity('flotte').")";
if ($fk_booking > 0)      $where .= " AND e.fk_booking = ".((int)$fk_booking);
if (!empty($search_ref))  $where .= " AND (e.ref LIKE '%".$db->escape($search_ref)."%' OR b.ref LIKE '%".$db->escape($search_ref)."%')";
if (!empty($filter_cat))  $where .= " AND e.category = '".$db->escape($filter_cat)."'";
if (!empty($filter_from)) $where .= " AND e.expense_date >= '".$db->escape($filter_from)."'";
if (!empty($filter_to))   $where .= " AND e.expense_date <= '".$db->escape($filter_to)."'";

$allowed_sort = array('e.expense_date','e.ref','e.category','e.amount','b.ref');
if (!in_array($sortfield, $allowed_sort)) $sortfield = 'e.expense_date';
$sortorder = ($sortorder === 'ASC') ? 'ASC' : 'DESC';

$sql_count = "SELECT COUNT(*) AS cnt FROM ".MAIN_DB_PREFIX."flotte_expense e LEFT JOIN ".MAIN_DB_PREFIX."flotte_booking b ON b.rowid = e.fk_booking".$where;
$res_count = $db->query($sql_count);
$total_rows = 0;
if ($res_count) { $obj = $db->fetch_object($res_count); $total_rows = (int)$obj->cnt; }

$sql = "SELECT e.*, b.ref AS booking_ref, b.booking_date,
               CONCAT(c.firstname,' ',c.lastname) AS customer_name
        FROM ".MAIN_DB_PREFIX."flotte_expense e
        LEFT JOIN ".MAIN_DB_PREFIX."flotte_booking b ON b.rowid = e.fk_booking
        LEFT JOIN ".MAIN_DB_PREFIX."flotte_customer c ON c.rowid = b.fk_customer"
        .$where
        ." ORDER BY ".$sortfield." ".$sortorder
        ." LIMIT ".$limit." OFFSET ".$offset;

$resql = $db->query($sql);
$rows  = array();
if ($resql) { while ($obj = $db->fetch_object($resql)) { $rows[] = $obj; } }

// Summary totals by category (across all matching records)
$sql_tot = "SELECT e.category, SUM(COALESCE(e.amount,0)) AS cat_total
            FROM ".MAIN_DB_PREFIX."flotte_expense e
            LEFT JOIN ".MAIN_DB_PREFIX."flotte_booking b ON b.rowid = e.fk_booking"
           .$where." GROUP BY e.category";
$res_tot = $db->query($sql_tot);
$totals  = array('fuel'=>0,'road'=>0,'driver'=>0,'commission'=>0,'other'=>0);
if ($res_tot) { while ($t = $db->fetch_object($res_tot)) { if (isset($totals[$t->category])) $totals[$t->category] = (float)$t->cat_total; } }
$grand_total = array_sum($totals);

llxHeader('', $langs->trans('ExpensesList'));
?>
<style>
@import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=DM+Mono:wght@400;500&display=swap');
.dc-page * { box-sizing:border-box; }
.dc-page { font-family:'DM Sans',sans-serif; max-width:1260px; margin:0 auto; padding:0 2px 48px; color:#1a1f2e; }
/* Header */
.dc-header { display:flex;align-items:center;justify-content:space-between;padding:26px 0 22px;border-bottom:1px solid #e8eaf0;margin-bottom:28px;gap:16px;flex-wrap:wrap; }
.dc-header-left { display:flex;align-items:center;gap:14px; }
.dc-header-icon { width:46px;height:46px;border-radius:12px;background:rgba(217,119,6,0.1);display:flex;align-items:center;justify-content:center;color:#d97706;font-size:20px;flex-shrink:0; }
.dc-header-title { font-size:21px;font-weight:700;color:#1a1f2e;margin:0 0 3px;letter-spacing:-0.3px; }
.dc-header-sub { font-size:12.5px;color:#8b92a9; }
.dc-header-actions { display:flex;gap:8px;align-items:center;flex-wrap:wrap; }
/* Buttons */
.dc-btn { display:inline-flex;align-items:center;gap:7px;padding:8px 16px;border-radius:6px;font-size:13px;font-weight:600;text-decoration:none!important;cursor:pointer;font-family:'DM Sans',sans-serif;white-space:nowrap;transition:all 0.15s;border:none; }
.dc-btn-primary { background:#3c4758!important;color:#fff!important; }
.dc-btn-primary:hover { background:#2a3346!important;color:#fff!important; }
.dc-btn-ghost { background:#fff!important;color:#5a6482!important;border:1.5px solid #d1d5e0!important; }
.dc-btn-ghost:hover { background:#f5f6fa!important;color:#2d3748!important; }
/* Summary */
.dc-summary-grid { display:grid;grid-template-columns:repeat(6,1fr);gap:12px;margin-bottom:22px; }
@media(max-width:1000px){ .dc-summary-grid{grid-template-columns:repeat(3,1fr);} }
@media(max-width:560px){ .dc-summary-grid{grid-template-columns:repeat(2,1fr);} }
.dc-summary-card { background:#fff;border:1px solid #e8eaf0;border-radius:12px;padding:16px 18px;box-shadow:0 1px 4px rgba(0,0,0,0.04); }
.dc-summary-card.total { background:#3c4758;border-color:#3c4758; }
.dc-summary-icon { width:32px;height:32px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:14px;margin-bottom:10px; }
.dc-summary-label { font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;color:#8b92a9;margin-bottom:4px; }
.dc-summary-card.total .dc-summary-label { color:rgba(255,255,255,0.65); }
.dc-summary-val { font-size:18px;font-weight:700;color:#1a1f2e;font-family:'DM Mono',monospace; }
.dc-summary-card.total .dc-summary-val { color:#fff; }
/* Filter bar */
.dc-filter-bar { display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:16px; }
.dc-filter-bar input, .dc-filter-bar select { border:1.5px solid #e2e5f0;border-radius:6px;padding:7px 12px;font-size:13px;font-family:'DM Sans',sans-serif;color:#1a1f2e;outline:none; }
.dc-filter-bar input:focus, .dc-filter-bar select:focus { border-color:#3c4758; }
/* Table */
.dc-table-wrap { background:#fff;border:1px solid #e8eaf0;border-radius:12px;overflow:hidden;box-shadow:0 1px 6px rgba(0,0,0,0.04); }
table.dc-table { width:100%;border-collapse:collapse;font-size:13px; }
table.dc-table th { background:#f7f8fc;padding:11px 16px;text-align:left;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.6px;color:#8b92a9;border-bottom:1px solid #e8eaf0;white-space:nowrap; }
table.dc-table th a { color:#8b92a9;text-decoration:none; }
table.dc-table th a:hover { color:#3c4758; }
table.dc-table td { padding:12px 16px;border-bottom:1px solid #f5f6fb;vertical-align:middle;color:#1a1f2e; }
table.dc-table tr:last-child td { border-bottom:none; }
table.dc-table tr:hover td { background:#fafbfd; }
.dc-amount { font-weight:600;font-family:'DM Mono',monospace; }
/* Category badge */
.dc-cat-badge { display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:600;white-space:nowrap; }
.dc-cat-badge.fuel       { background:rgba(245,158,11,0.12);color:#b45309; }
.dc-cat-badge.road       { background:rgba(59,130,246,0.12);color:#1d4ed8; }
.dc-cat-badge.driver     { background:rgba(109,40,217,0.12);color:#6d28d9; }
.dc-cat-badge.commission { background:rgba(22,163,74,0.12);color:#166534; }
.dc-cat-badge.other      { background:rgba(60,71,88,0.1);color:#3c4758; }
/* Source badge */
.dc-source { font-size:10px;font-weight:600;padding:2px 7px;border-radius:10px; }
.dc-source.booking { background:#fef3c7;color:#92400e; }
.dc-source.manual  { background:#eff6ff;color:#1d4ed8; }
.dc-source.fuel    { background:rgba(245,158,11,0.12);color:#b45309; }
/* Pagination */
.dc-pagination { display:flex;align-items:center;justify-content:space-between;padding:14px 18px;background:#f7f8fc;border-top:1px solid #e8eaf0;font-size:12.5px;color:#8b92a9; }
.dc-pagination-links { display:flex;gap:4px; }
.dc-pagination-links a, .dc-pagination-links span { display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;border-radius:6px;font-size:12.5px;font-weight:600;text-decoration:none;color:#5a6482;border:1.5px solid #e2e5f0;background:#fff; }
.dc-pagination-links a:hover { border-color:#3c4758;color:#3c4758; }
.dc-pagination-links span.active { background:#3c4758;color:#fff;border-color:#3c4758; }
.dc-empty { padding:48px 20px;text-align:center;color:#8b92a9; }
.dc-empty i { font-size:36px;margin-bottom:12px;opacity:0.3;display:block; }
</style>
<?php

$sortUrl = function($field) use ($sortfield, $sortorder, $fk_booking, $search_ref, $filter_from, $filter_to, $filter_cat) {
    $neworder = ($sortfield === $field && $sortorder === 'ASC') ? 'DESC' : 'ASC';
    $arrow    = ($sortfield === $field) ? ($sortorder === 'ASC' ? ' <i class="fa fa-sort-up"></i>' : ' <i class="fa fa-sort-down"></i>') : ' <i class="fa fa-sort" style="opacity:0.3;"></i>';
    $href = $_SERVER['PHP_SELF'].'?sortfield='.urlencode($field).'&sortorder='.urlencode($neworder)
        .($fk_booking > 0     ? '&fk_booking='.$fk_booking   : '')
        .(!empty($search_ref) ? '&search_ref='.urlencode($search_ref) : '')
        .(!empty($filter_cat) ? '&category='.urlencode($filter_cat)   : '')
        .(!empty($filter_from)? '&date_from='.urlencode($filter_from) : '')
        .(!empty($filter_to)  ? '&date_to='.urlencode($filter_to)     : '');
    return '<a href="'.$href.'">'.$arrow.'</a>';
};

$catIcons = array(
    'fuel'       => array('icon'=>'fa-gas-pump',    'label'=>$langs->trans('FuelExpenses'),       'color'=>'#f59e0b','bg'=>'rgba(245,158,11,0.1)'),
    'road'       => array('icon'=>'fa-road',         'label'=>$langs->trans('RoadExpenses'),       'color'=>'#3b82f6','bg'=>'rgba(59,130,246,0.1)'),
    'driver'     => array('icon'=>'fa-user-tie',     'label'=>$langs->trans('DriverExpenses'),     'color'=>'#8b5cf6','bg'=>'rgba(139,92,246,0.1)'),
    'commission' => array('icon'=>'fa-coins',        'label'=>$langs->trans('CommissionExpenses'), 'color'=>'#16a34a','bg'=>'rgba(22,163,74,0.1)'),
    'other'      => array('icon'=>'fa-tag',          'label'=>$langs->trans('OtherExpenses'),      'color'=>'#64748b','bg'=>'rgba(100,116,139,0.1)'),
);

print '<div class="dc-page">';

/* ── Header ── */
print '<div class="dc-header">';
print '  <div class="dc-header-left">';
print '    <div class="dc-header-icon"><i class="fa fa-receipt"></i></div>';
print '    <div>';
print '      <div class="dc-header-title">'.$langs->trans('ExpensesList').'</div>';
print '      <div class="dc-header-sub">'.number_format($total_rows).' '.$langs->trans('records').'</div>';
print '    </div>';
print '  </div>';
print '  <div class="dc-header-actions">';
print '    <a href="'.dol_buildpath('/flotte/booking_list.php',1).'" class="dc-btn dc-btn-ghost"><i class="fa fa-arrow-left"></i> '.$langs->trans('BackToList').'</a>';
if (!empty($user->rights->flotte->write)) {
    $new_url = dol_buildpath('/flotte/expenses_card.php',1).'?action=create'.($fk_booking > 0 ? '&fk_booking='.$fk_booking : '');
    print '    <button type="button" class="dc-btn dc-btn-primary" onclick="elOpenImport()"><i class="fa fa-file-import"></i> '.$langs->trans('Import').'</button>';
    print '    <a href="'.$new_url.'" class="dc-btn dc-btn-primary"><i class="fa fa-plus"></i> '.$langs->trans('NewExpense').'</a>';
}
print '  </div>';
print '</div>';

/* ── Summary cards ── */
print '<div class="dc-summary-grid">';
foreach ($catIcons as $ck => $ci) {
    print '<div class="dc-summary-card">';
    print '  <div class="dc-summary-icon" style="color:'.$ci['color'].';background:'.$ci['bg'].';"><i class="fa '.$ci['icon'].'"></i></div>';
    print '  <div class="dc-summary-label">'.$ci['label'].'</div>';
    print '  <div class="dc-summary-val">'.price($totals[$ck]).'</div>';
    print '</div>';
}
print '<div class="dc-summary-card total">';
print '  <div class="dc-summary-icon" style="color:#fff;background:rgba(255,255,255,0.15);"><i class="fa fa-calculator"></i></div>';
print '  <div class="dc-summary-label">'.$langs->trans('TotalExpenses').'</div>';
print '  <div class="dc-summary-val">'.price($grand_total).'</div>';
print '</div>';
print '</div>';

/* ── Filter bar ── */
$clear_url = $_SERVER['PHP_SELF'].($fk_booking > 0 ? '?fk_booking='.$fk_booking : '');
print '<form method="GET" action="'.$_SERVER['PHP_SELF'].'">';
if ($fk_booking > 0) print '<input type="hidden" name="fk_booking" value="'.$fk_booking.'">';
print '<div class="dc-filter-bar">';
print '  <input type="text" name="search_ref" placeholder="'.$langs->trans('SearchByRef').'" value="'.dol_escape_htmltag($search_ref).'">';

// Category filter
print '  <select name="category">';
print '    <option value="">'.$langs->trans('AllCategories').'</option>';
foreach ($catIcons as $ck => $ci) {
    print '    <option value="'.dol_escape_htmltag($ck).'"'.($filter_cat==$ck?' selected':'').'>'.dol_escape_htmltag($ci['label']).'</option>';
}
print '  </select>';

print '  <input type="date" name="date_from" value="'.dol_escape_htmltag($filter_from).'" title="'.$langs->trans('From').'">';
print '  <input type="date" name="date_to"   value="'.dol_escape_htmltag($filter_to).'"   title="'.$langs->trans('To').'">';
print '  <button type="submit" class="dc-btn dc-btn-primary"><i class="fa fa-search"></i> '.$langs->trans('Search').'</button>';
if (!empty($search_ref) || !empty($filter_from) || !empty($filter_to) || !empty($filter_cat)) {
    print '  <a href="'.$clear_url.'" class="dc-btn dc-btn-ghost"><i class="fa fa-times"></i> '.$langs->trans('Clear').'</a>';
}
print '</div>';
print '</form>';

/* ── Table ── */
print '<div class="dc-table-wrap">';
if (empty($rows)) {
    print '<div class="dc-empty"><i class="fa fa-receipt"></i><div>'.$langs->trans('NoExpensesFound').'</div>';
    if (!empty($user->rights->flotte->write)) {
        print '<br><a href="'.dol_buildpath('/flotte/expenses_card.php',1).'?action=create'.($fk_booking > 0 ? '&fk_booking='.$fk_booking : '').'" class="dc-btn dc-btn-primary" style="margin-top:12px;"><i class="fa fa-plus"></i> '.$langs->trans('NewExpense').'</a>';
    }
    print '</div>';
} else {
    print '<table class="dc-table">';
    print '<thead><tr>';
    print '<th>'.$langs->trans('Reference').$sortUrl('e.ref').'</th>';
    print '<th>'.$langs->trans('Date').$sortUrl('e.expense_date').'</th>';
    print '<th>'.$langs->trans('Category').$sortUrl('e.category').'</th>';
    print '<th>'.$langs->trans('Booking').$sortUrl('b.ref').'</th>';
    print '<th>'.$langs->trans('Customer').'</th>';
    print '<th>'.$langs->trans('Details').'</th>';
    print '<th style="text-align:right;">'.$langs->trans('Amount').$sortUrl('e.amount').'</th>';
    print '<th>'.$langs->trans('Source').'</th>';
    print '<th></th>';
    print '</tr></thead>';
    print '<tbody>';

    $col_total = 0;
    foreach ($rows as $row) {
        $amount = (float)$row->amount;
        $col_total += $amount;
        $cat = $row->category;
        $ci  = $catIcons[$cat] ?? array('icon'=>'fa-tag','label'=>ucfirst($cat),'color'=>'#64748b','bg'=>'rgba(0,0,0,0.05)');

        // Build detail snippet
        $detail = '';
        if ($cat === 'fuel') {
            $parts = array();
            if (!empty($row->fuel_qty))   $parts[] = dol_escape_htmltag($row->fuel_qty).' L';
            if (!empty($row->fuel_type))  $parts[] = dol_escape_htmltag(ucfirst($row->fuel_type));
            $detail = implode(' · ', $parts);
        } elseif ($cat === 'road') {
            $parts = array();
            if (!empty($row->road_toll))    $parts[] = $langs->trans('TollFees').': '.price($row->road_toll);
            if (!empty($row->road_parking)) $parts[] = $langs->trans('ParkingFees').': '.price($row->road_parking);
            $detail = implode(' · ', $parts);
        } elseif ($cat === 'driver') {
            $parts = array();
            if (!empty($row->driver_salary)) $parts[] = $langs->trans('Salary').': '.price($row->driver_salary);
            $detail = implode(' · ', $parts);
        } elseif ($cat === 'commission') {
            $parts = array();
            if (!empty($row->commission_agent)) $parts[] = $langs->trans('Agent').': '.price($row->commission_agent);
            if (!empty($row->commission_tax))   $parts[] = $row->commission_tax.'%';
            $detail = implode(' · ', $parts);
        } elseif ($cat === 'other') {
            $detail = dol_escape_htmltag($row->other_label ?? '');
        }

        print '<tr>';
        print '<td><a href="'.dol_buildpath('/flotte/expenses_card.php',1).'?id='.$row->rowid.'" style="font-weight:600;color:#3c4758;text-decoration:none;">'.dol_escape_htmltag($row->ref).'</a></td>';
        print '<td style="white-space:nowrap;">'.dol_print_date($db->jdate($row->expense_date), 'day').'</td>';
        print '<td><span class="dc-cat-badge '.dol_escape_htmltag($cat).'"><i class="fa '.$ci['icon'].'"></i>'.dol_escape_htmltag($ci['label']).'</span></td>';
        print '<td>';
        if (!empty($row->fk_booking)) {
            print '<a href="'.dol_buildpath('/flotte/booking_card.php',1).'?id='.((int)$row->fk_booking).'" style="color:#3c4758;font-weight:600;text-decoration:none;">'.dol_escape_htmltag($row->booking_ref).'</a>';
        } else { print '<span style="color:#c4c9d8;">—</span>'; }
        print '</td>';
        print '<td>'.dol_escape_htmltag($row->customer_name ?? '—').'</td>';
        print '<td style="font-size:12px;color:#8b92a9;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="'.dol_escape_htmltag($detail).'">'.$detail.'</td>';
        print '<td style="text-align:right;"><span class="dc-amount">'.price($amount).'</span></td>';
        $source_val   = $row->source ?? 'manual';
        $source_class = ($row->category === 'fuel') ? 'fuel' : $source_val;
        $source_label = ($row->category === 'fuel') ? $langs->trans('Fuel') : ($source_val === 'booking' ? $langs->trans('Booking') : $langs->trans('Manual'));
        print '<td><span class="dc-source '.dol_escape_htmltag($source_class).'">'.dol_escape_htmltag($source_label).'</span></td>';
        print '<td style="white-space:nowrap;">';
        print '<a href="'.dol_buildpath('/flotte/expenses_card.php',1).'?id='.$row->rowid.'" class="dc-btn dc-btn-ghost" style="padding:4px 10px;font-size:12px;"><i class="fa fa-eye"></i></a>';
        if ($row->source !== 'booking' && $row->category !== 'fuel' && !empty($user->rights->flotte->write)) {
            print ' <a href="'.dol_buildpath('/flotte/expenses_card.php',1).'?id='.$row->rowid.'&action=edit" class="dc-btn dc-btn-ghost" style="padding:4px 10px;font-size:12px;"><i class="fa fa-pen"></i></a>';
        }
        print '</td>';
        print '</tr>';
    }

    // Footer totals
    print '<tr style="background:#f7f8fc;font-weight:700;">';
    print '<td colspan="6" style="font-size:12px;text-transform:uppercase;letter-spacing:0.5px;color:#8b92a9;">'.$langs->trans('PageTotal').'</td>';
    print '<td style="text-align:right;font-family:\'DM Mono\',monospace;">'.price($col_total).'</td>';
    print '<td colspan="2"></td>';
    print '</tr>';

    print '</tbody></table>';

    // Pagination
    $total_pages = max(1, ceil($total_rows / $limit));
    if ($total_pages > 1) {
        $base_url = $_SERVER['PHP_SELF'].'?sortfield='.urlencode($sortfield).'&sortorder='.urlencode($sortorder)
            .($fk_booking  > 0    ? '&fk_booking='.$fk_booking   : '')
            .(!empty($search_ref) ? '&search_ref='.urlencode($search_ref) : '')
            .(!empty($filter_cat) ? '&category='.urlencode($filter_cat)   : '')
            .(!empty($filter_from)? '&date_from='.urlencode($filter_from) : '')
            .(!empty($filter_to)  ? '&date_to='.urlencode($filter_to)     : '');
        print '<div class="dc-pagination">';
        print '<span>'.$langs->trans('Page').' '.($page+1).' / '.$total_pages.'</span>';
        print '<div class="dc-pagination-links">';
        if ($page > 0) print '<a href="'.$base_url.'&page='.($page-1).'"><i class="fa fa-chevron-left"></i></a>';
        $start = max(0, $page - 2); $end = min($total_pages - 1, $page + 2);
        for ($p = $start; $p <= $end; $p++) {
            if ($p == $page) print '<span class="active">'.($p+1).'</span>';
            else             print '<a href="'.$base_url.'&page='.$p.'">'.($p+1).'</a>';
        }
        if ($page < $total_pages - 1) print '<a href="'.$base_url.'&page='.($page+1).'"><i class="fa fa-chevron-right"></i></a>';
        print '</div>';
        print '</div>';
    }
}
print '</div>'; // table-wrap
print '</div>'; // dc-page

/* ── Import Modal ── */
if (!empty($user->rights->flotte->write)) {
    print '
<div class="el-modal-overlay" id="el-import-modal" onclick="if(event.target===this)elCloseImport()">
  <div class="el-modal">
    <div class="el-modal-header">
      <div class="el-modal-header-left">
        <div class="el-modal-icon"><i class="fa fa-file-import"></i></div>
        <div>
          <p class="el-modal-title">'.$langs->trans("ImportExpenses").'</p>
          <p class="el-modal-sub">'.$langs->trans("ImportExpensesSubtitle").'</p>
        </div>
      </div>
      <button class="el-modal-close" onclick="elCloseImport()" title="'.$langs->trans('Close').'">&#x2715;</button>
    </div>
    <div class="el-modal-body">
      <div class="el-import-notice">
        <i class="fa fa-info-circle"></i>
        <div>
          '.$langs->trans("ImportNoticeText").'
          <a href="'.dol_buildpath('/flotte/expenses_list.php',1).'?action=download_template&token='.newToken().'">
            <i class="fa fa-download"></i> '.$langs->trans("DownloadCSVTemplate").'
          </a>
        </div>
      </div>
      <button type="button" class="el-fields-toggle" onclick="elToggleFields(this)">
        <i class="fa fa-table"></i> '.$langs->trans("ShowCSVColumns").' <i class="fa fa-chevron-down" id="el-fields-chevron"></i>
      </button>
      <div id="el-fields-panel" style="display:none;margin-bottom:14px;">
        <table class="el-fields-table">
          <thead><tr><th>#</th><th>'.$langs->trans("ColumnName").'</th><th>'.$langs->trans("Description").'</th><th style="text-align:center;">'.$langs->trans("Required").'</th></tr></thead>
          <tbody>
            <tr><td>1</td><td class="el-col-name">expense_date</td><td>'.$langs->trans("Date").' (YYYY-MM-DD)</td><td style="text-align:center;color:#e53e3e;font-weight:700;">✓</td></tr>
            <tr><td>2</td><td class="el-col-name">category</td><td>fuel / road / driver / commission / other</td><td style="text-align:center;color:#e53e3e;font-weight:700;">✓</td></tr>
            <tr><td>3</td><td class="el-col-name">amount</td><td>'.$langs->trans("Amount").' (auto-calc if blank)</td><td class="el-col-opt">—</td></tr>
            <tr><td>4</td><td class="el-col-name">booking_ref</td><td>'.$langs->trans("BookingRef").' (e.g. BOOK-0001)</td><td class="el-col-opt">—</td></tr>
            <tr><td>5</td><td class="el-col-name">notes</td><td>'.$langs->trans("Notes").'</td><td class="el-col-opt">—</td></tr>
            <tr><td>6</td><td class="el-col-name">fuel_qty</td><td>'.$langs->trans("FuelQty").' (L)</td><td class="el-col-opt">—</td></tr>
            <tr><td>7</td><td class="el-col-name">fuel_price</td><td>'.$langs->trans("FuelPrice").' / L</td><td class="el-col-opt">—</td></tr>
            <tr><td>8</td><td class="el-col-name">fuel_type</td><td>diesel / gasoline / …</td><td class="el-col-opt">—</td></tr>
            <tr><td>9</td><td class="el-col-name">road_toll</td><td>'.$langs->trans("TollFees").'</td><td class="el-col-opt">—</td></tr>
            <tr><td>10</td><td class="el-col-name">road_parking</td><td>'.$langs->trans("ParkingFees").'</td><td class="el-col-opt">—</td></tr>
            <tr><td>11</td><td class="el-col-name">road_other</td><td>'.$langs->trans("OtherRoadExpenses").'</td><td class="el-col-opt">—</td></tr>
            <tr><td>12</td><td class="el-col-name">driver_salary</td><td>'.$langs->trans("DriverSalary").'</td><td class="el-col-opt">—</td></tr>
            <tr><td>13</td><td class="el-col-name">driver_overnight</td><td>'.$langs->trans("OvernightFees").'</td><td class="el-col-opt">—</td></tr>
            <tr><td>14</td><td class="el-col-name">driver_bonus</td><td>'.$langs->trans("Bonus").'</td><td class="el-col-opt">—</td></tr>
            <tr><td>15</td><td class="el-col-name">commission_agent</td><td>'.$langs->trans("AgentCommission").'</td><td class="el-col-opt">—</td></tr>
            <tr><td>16</td><td class="el-col-name">commission_tax</td><td>'.$langs->trans("CommissionTax").' (%)</td><td class="el-col-opt">—</td></tr>
            <tr><td>17</td><td class="el-col-name">commission_other</td><td>'.$langs->trans("OtherCommission").'</td><td class="el-col-opt">—</td></tr>
            <tr><td>18</td><td class="el-col-name">other_label</td><td>'.$langs->trans("Label").' (for other category)</td><td class="el-col-opt">—</td></tr>
          </tbody>
        </table>
      </div>
      <form method="POST" action="'.dol_buildpath('/flotte/expenses_list.php',1).'" enctype="multipart/form-data" id="el-import-form">
        <input type="hidden" name="token"  value="'.newToken().'">
        <input type="hidden" name="action" value="import_csv">
        <div class="el-dropzone" id="el-dropzone">
          <input type="file" name="import_file" id="el-file-input" accept=".csv,text/csv" onchange="elFileChosen(this)">
          <div class="el-dropzone-icon"><i class="fa fa-cloud-upload-alt"></i></div>
          <div class="el-dropzone-text">'.$langs->trans("DropCSVHere").'</div>
          <div class="el-dropzone-sub">'.$langs->trans("OnlyCSVAccepted").'</div>
          <div class="el-dropzone-file" id="el-file-name"></div>
        </div>
        <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 0 0;border-top:1px solid #eaecf5;margin-top:18px;gap:10px;flex-wrap:wrap;">
          <button type="button" class="dc-btn dc-btn-ghost" onclick="elCloseImport()"><i class="fa fa-times"></i> '.$langs->trans("Cancel").'</button>
          <button type="submit" class="dc-btn dc-btn-primary" id="el-import-submit" disabled><i class="fa fa-check"></i> '.$langs->trans("ImportNow").'</button>
        </div>
      </form>
    </div>
  </div>
</div>

<style>
.el-modal-overlay { display:none;position:fixed;inset:0;z-index:10000;background:rgba(15,20,40,0.45);backdrop-filter:blur(3px);align-items:center;justify-content:center; }
.el-modal-overlay.open { display:flex; }
.el-modal { background:#fff;border-radius:16px;width:100%;max-width:600px;box-shadow:0 24px 64px rgba(0,0,0,0.18);overflow:hidden;max-height:92vh;display:flex;flex-direction:column; }
.el-modal-header { display:flex;align-items:center;justify-content:space-between;padding:22px 24px 18px;border-bottom:1px solid #eaecf5;flex-shrink:0; }
.el-modal-header-left { display:flex;align-items:center;gap:14px; }
.el-modal-icon { width:42px;height:42px;background:rgba(217,119,6,0.1);border-radius:10px;display:flex;align-items:center;justify-content:center;color:#d97706;font-size:18px;flex-shrink:0; }
.el-modal-title { font-size:16px;font-weight:700;color:#1a1f2e;margin:0 0 2px; }
.el-modal-sub { font-size:12.5px;color:#7c859c;margin:0; }
.el-modal-close { background:none;border:none;font-size:18px;color:#9aa0b4;cursor:pointer;padding:4px 8px;border-radius:6px;line-height:1;transition:all 0.15s; }
.el-modal-close:hover { background:#f0f2fa;color:#3c4758; }
.el-modal-body { padding:22px 24px 24px;overflow-y:auto; }
.el-import-notice { display:flex;gap:12px;background:#f0f6ff;border:1px solid #c3d9ff;border-radius:10px;padding:14px 16px;margin-bottom:18px;font-size:13px;color:#2d4a8a;line-height:1.5; }
.el-import-notice i { color:#3c7de0;font-size:16px;margin-top:1px;flex-shrink:0; }
.el-import-notice a { color:#3c7de0;font-weight:600;text-decoration:none;display:inline-flex;align-items:center;gap:5px;margin-left:4px; }
.el-import-notice a:hover { text-decoration:underline; }
.el-fields-toggle { display:inline-flex;align-items:center;gap:7px;padding:8px 14px;background:#f7f8fc;border:1.5px solid #e2e5f0;border-radius:8px;font-size:12.5px;font-weight:600;color:#5a6482;cursor:pointer;font-family:\'DM Sans\',sans-serif;transition:all 0.15s;margin-bottom:12px; }
.el-fields-toggle:hover { background:#eef0f8;border-color:#c8cce0; }
.el-fields-table { width:100%;border-collapse:collapse;font-size:12.5px;margin-bottom:4px; }
.el-fields-table th { background:#f7f8fc;padding:8px 10px;text-align:left;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;color:#8b92a9;border-bottom:1px solid #e8eaf0; }
.el-fields-table td { padding:8px 10px;border-bottom:1px solid #f0f2f8;color:#2d3748; }
.el-fields-table tr:last-child td { border-bottom:none; }
.el-col-name { font-family:\'DM Mono\',monospace;font-size:12px;color:#d97706;font-weight:600; }
.el-col-opt  { text-align:center;color:#9aa0b4; }
.el-dropzone { position:relative;border:2px dashed #d1d5e0;border-radius:12px;padding:36px 24px;text-align:center;cursor:pointer;transition:all 0.2s;background:#fafbfe; }
.el-dropzone:hover, .el-dropzone.drag-over { border-color:#d97706;background:#fffbf0; }
.el-dropzone.has-file { border-color:#1a7d4a;background:#f0fdf4; }
.el-dropzone input[type="file"] { position:absolute;inset:0;opacity:0;cursor:pointer;width:100%;height:100%; }
.el-dropzone-icon { font-size:32px;color:#c4c9d8;margin-bottom:10px; }
.el-dropzone.has-file .el-dropzone-icon { color:#1a7d4a; }
.el-dropzone-text { font-size:14px;font-weight:600;color:#3c4758;margin-bottom:4px; }
.el-dropzone-sub  { font-size:12px;color:#9aa0b4; }
.el-dropzone-file { font-size:13px;font-weight:600;color:#1a7d4a;margin-top:8px; }
</style>

<script>
function elOpenImport()  { document.getElementById("el-import-modal").classList.add("open"); }
function elCloseImport() {
    document.getElementById("el-import-modal").classList.remove("open");
    document.getElementById("el-import-form").reset();
    var dz = document.getElementById("el-dropzone");
    if (dz) dz.classList.remove("has-file");
    document.getElementById("el-file-name").textContent = "";
    document.getElementById("el-import-submit").disabled = true;
}
function elFileChosen(input) {
    var dz  = document.getElementById("el-dropzone");
    var fn  = document.getElementById("el-file-name");
    var btn = document.getElementById("el-import-submit");
    if (input.files && input.files.length > 0) {
        dz.classList.add("has-file");
        fn.textContent = input.files[0].name;
        btn.disabled = false;
    } else {
        dz.classList.remove("has-file");
        fn.textContent = "";
        btn.disabled = true;
    }
}
function elToggleFields(btn) {
    var panel   = document.getElementById("el-fields-panel");
    var chevron = document.getElementById("el-fields-chevron");
    if (panel.style.display === "none") {
        panel.style.display = "block";
        chevron.className = "fa fa-chevron-up";
    } else {
        panel.style.display = "none";
        chevron.className = "fa fa-chevron-down";
    }
}
(function(){
    var dz = document.getElementById("el-dropzone");
    if (!dz) return;
    dz.addEventListener("dragover",  function(e){ e.preventDefault(); dz.classList.add("drag-over"); });
    dz.addEventListener("dragleave", function(){ dz.classList.remove("drag-over"); });
    dz.addEventListener("drop",      function(){ dz.classList.remove("drag-over"); });
})();
document.addEventListener("keydown", function(e){ if (e.key === "Escape") elCloseImport(); });
</script>
';
}

llxFooter();
$db->close();
?>
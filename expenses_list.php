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
        print '<td><span class="dc-source '.dol_escape_htmltag($row->source ?? 'manual').'">'.dol_escape_htmltag($row->source === 'booking' ? $langs->trans('Booking') : $langs->trans('Manual')).'</span></td>';
        print '<td style="white-space:nowrap;">';
        print '<a href="'.dol_buildpath('/flotte/expenses_card.php',1).'?id='.$row->rowid.'" class="dc-btn dc-btn-ghost" style="padding:4px 10px;font-size:12px;"><i class="fa fa-eye"></i></a>';
        if ($row->source !== 'booking' && !empty($user->rights->flotte->write)) {
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

llxFooter();
$db->close();
?>
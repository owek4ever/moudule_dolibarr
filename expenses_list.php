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

// Filters
$fk_booking  = GETPOST('fk_booking', 'int');
$filter_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$filter_to   = isset($_GET['date_to'])   ? $_GET['date_to']   : '';
$search_ref  = GETPOST('search_ref', 'alpha');

// Sorting
$sortfield = GETPOST('sortfield', 'aZ09') ?: 'b.booking_date';
$sortorder = GETPOST('sortorder', 'aZ09') ?: 'DESC';

// Pagination
$limit = 25;
$page  = max(0, (int) GETPOST('page', 'int'));
$offset = $page * $limit;

// Ensure expense columns exist (in case user opens this page before booking_card.php)
foreach (array('expense_fuel','expense_road','expense_driver','expense_commission') as $_col) {
    $_chk = $db->query("SHOW COLUMNS FROM ".MAIN_DB_PREFIX."flotte_booking LIKE '".$_col."'");
    if ($_chk && $db->num_rows($_chk) == 0) {
        $db->query("ALTER TABLE ".MAIN_DB_PREFIX."flotte_booking ADD COLUMN ".$_col." DECIMAL(15,2) DEFAULT NULL");
    }
}

// Build WHERE
$where = " WHERE b.entity IN (".getEntity('flotte').")";
$where .= " AND (b.expense_fuel IS NOT NULL OR b.expense_road IS NOT NULL OR b.expense_driver IS NOT NULL OR b.expense_commission IS NOT NULL)";
if ($fk_booking > 0)    $where .= " AND b.rowid = ".((int)$fk_booking);
if (!empty($search_ref)) $where .= " AND b.ref LIKE '%".$db->escape($search_ref)."%'";
if (!empty($filter_from)) $where .= " AND b.booking_date >= '".$db->escape($filter_from)."'";
if (!empty($filter_to))   $where .= " AND b.booking_date <= '".$db->escape($filter_to)."'";

// Validate sort
$allowed_sort = array('b.booking_date','b.ref','b.expense_fuel','b.expense_road','b.expense_driver','b.expense_commission','total_expenses');
if (!in_array($sortfield, $allowed_sort)) $sortfield = 'b.booking_date';
$sortorder = ($sortorder === 'ASC') ? 'ASC' : 'DESC';

// Count total
$sql_count = "SELECT COUNT(*) as cnt FROM ".MAIN_DB_PREFIX."flotte_booking b".$where;
$res_count = $db->query($sql_count);
$total_rows = 0;
if ($res_count) { $obj = $db->fetch_object($res_count); $total_rows = (int)$obj->cnt; }

// Fetch rows
$sql = "SELECT b.rowid, b.ref, b.booking_date, b.status,
               b.expense_fuel, b.expense_road, b.expense_driver, b.expense_commission,
               COALESCE(b.expense_fuel,0)+COALESCE(b.expense_road,0)+COALESCE(b.expense_driver,0)+COALESCE(b.expense_commission,0) AS total_expenses,
               CONCAT(c.firstname,' ',c.lastname) AS customer_name
        FROM ".MAIN_DB_PREFIX."flotte_booking b
        LEFT JOIN ".MAIN_DB_PREFIX."flotte_customer c ON c.rowid = b.fk_customer"
        .$where
        ." ORDER BY ".$sortfield." ".$sortorder
        ." LIMIT ".$limit." OFFSET ".$offset;

$resql   = $db->query($sql);
$rows    = array();
if ($resql) { while ($obj = $db->fetch_object($resql)) { $rows[] = $obj; } }

// Totals for summary cards
$sql_totals = "SELECT
    SUM(COALESCE(b.expense_fuel,0))       AS tot_fuel,
    SUM(COALESCE(b.expense_road,0))       AS tot_road,
    SUM(COALESCE(b.expense_driver,0))     AS tot_driver,
    SUM(COALESCE(b.expense_commission,0)) AS tot_commission
    FROM ".MAIN_DB_PREFIX."flotte_booking b".$where;
$res_tot = $db->query($sql_totals);
$totals  = array('fuel'=>0,'road'=>0,'driver'=>0,'commission'=>0);
if ($res_tot) { $t = $db->fetch_object($res_tot); $totals = array('fuel'=>(float)$t->tot_fuel,'road'=>(float)$t->tot_road,'driver'=>(float)$t->tot_driver,'commission'=>(float)$t->tot_commission); }
$grand_total = array_sum($totals);

llxHeader('', $langs->trans('ExpensesList'));
?>
<style>
@import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&display=swap');
.dc-page * { box-sizing: border-box; }
.dc-page {
    font-family: 'DM Sans', sans-serif;
    max-width: 1260px; margin: 0 auto;
    padding: 0 2px 48px; color: #1a1f2e;
}
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
/* Summary cards */
.dc-summary-grid { display:grid;grid-template-columns:repeat(5,1fr);gap:14px;margin-bottom:22px; }
@media(max-width:900px){ .dc-summary-grid{grid-template-columns:repeat(3,1fr);} }
@media(max-width:560px){ .dc-summary-grid{grid-template-columns:repeat(2,1fr);} }
.dc-summary-card { background:#fff;border:1px solid #e8eaf0;border-radius:12px;padding:16px 18px;box-shadow:0 1px 4px rgba(0,0,0,0.04); }
.dc-summary-card.total { background:#3c4758;border-color:#3c4758; }
.dc-summary-icon { width:32px;height:32px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:14px;margin-bottom:10px; }
.dc-summary-label { font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;color:#8b92a9;margin-bottom:4px; }
.dc-summary-card.total .dc-summary-label { color:rgba(255,255,255,0.65); }
.dc-summary-val { font-size:19px;font-weight:700;color:#1a1f2e; }
.dc-summary-card.total .dc-summary-val { color:#fff; }
/* Filter bar */
.dc-filter-bar { display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:16px; }
.dc-filter-bar input[type="text"], .dc-filter-bar input[type="date"] {
    border:1.5px solid #e2e5f0;border-radius:6px;padding:7px 12px;font-size:13px;font-family:'DM Sans',sans-serif;color:#1a1f2e;outline:none;
}
.dc-filter-bar input:focus { border-color:#3c4758; }
/* Table */
.dc-table-wrap { background:#fff;border:1px solid #e8eaf0;border-radius:12px;overflow:hidden;box-shadow:0 1px 6px rgba(0,0,0,0.04); }
table.dc-table { width:100%;border-collapse:collapse;font-size:13px; }
table.dc-table th { background:#f7f8fc;padding:11px 16px;text-align:left;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.6px;color:#8b92a9;border-bottom:1px solid #e8eaf0;white-space:nowrap; }
table.dc-table th a { color:#8b92a9;text-decoration:none; }
table.dc-table th a:hover { color:#3c4758; }
table.dc-table td { padding:12px 16px;border-bottom:1px solid #f5f6fb;vertical-align:middle;color:#1a1f2e; }
table.dc-table tr:last-child td { border-bottom:none; }
table.dc-table tr:hover td { background:#fafbfd; }
.dc-amount { font-weight:600;font-variant-numeric:tabular-nums; }
.dc-amount.zero { color:#c4c9d8;font-weight:400; }
.dc-total-amount { font-weight:700;color:#3c4758; }
/* Status badges */
.dc-badge { display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:600;white-space:nowrap; }
.dc-badge::before { content:'';width:5px;height:5px;border-radius:50%;flex-shrink:0; }
.dc-badge.pending   { background:#fff8ec;color:#b45309; } .dc-badge.pending::before   { background:#f59e0b; }
.dc-badge.confirmed { background:#eff6ff;color:#1d4ed8; } .dc-badge.confirmed::before { background:#3b82f6; }
.dc-badge.in_progress { background:#f5f3ff;color:#6d28d9; } .dc-badge.in_progress::before { background:#8b5cf6; }
.dc-badge.completed { background:#edfaf3;color:#1a7d4a; } .dc-badge.completed::before { background:#22c55e; }
.dc-badge.cancelled { background:#fef2f2;color:#b91c1c; } .dc-badge.cancelled::before { background:#ef4444; }
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

$sortUrl = function($field) use ($sortfield, $sortorder, $fk_booking, $search_ref, $filter_from, $filter_to) {
    $neworder = ($sortfield === $field && $sortorder === 'ASC') ? 'DESC' : 'ASC';
    $arrow    = ($sortfield === $field) ? ($sortorder === 'ASC' ? ' <i class="fa fa-sort-up"></i>' : ' <i class="fa fa-sort-down"></i>') : ' <i class="fa fa-sort" style="opacity:0.3;"></i>';
    $href = $_SERVER['PHP_SELF'].'?sortfield='.urlencode($field).'&sortorder='.urlencode($neworder)
        .($fk_booking  > 0    ? '&fk_booking='.$fk_booking   : '')
        .(!empty($search_ref) ? '&search_ref='.urlencode($search_ref) : '')
        .(!empty($filter_from)? '&date_from='.urlencode($filter_from) : '')
        .(!empty($filter_to)  ? '&date_to='.urlencode($filter_to)     : '');
    return '<a href="'.$href.'">'.$arrow.'</a>';
};

print '<div class="dc-page">';

// ── Header ──
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
print '  </div>';
print '</div>';

// ── Summary cards ──
$summary_items = array(
    array('label'=>$langs->trans('FuelExpenses'),       'icon'=>'fa-gas-pump',       'color'=>'#f59e0b', 'bg'=>'rgba(245,158,11,0.1)',  'val'=>$totals['fuel']),
    array('label'=>$langs->trans('RoadExpenses'),       'icon'=>'fa-road',            'color'=>'#3b82f6', 'bg'=>'rgba(59,130,246,0.1)',   'val'=>$totals['road']),
    array('label'=>$langs->trans('DriverExpenses'),     'icon'=>'fa-user-tie',        'color'=>'#8b5cf6', 'bg'=>'rgba(139,92,246,0.1)',   'val'=>$totals['driver']),
    array('label'=>$langs->trans('CommissionExpenses'), 'icon'=>'fa-coins','color'=>'#16a34a', 'bg'=>'rgba(22,163,74,0.1)',    'val'=>$totals['commission']),
    array('label'=>$langs->trans('TotalExpenses'),      'icon'=>'fa-calculator',      'color'=>'#fff',    'bg'=>'rgba(255,255,255,0.15)', 'val'=>$grand_total, 'total'=>true),
);
print '<div class="dc-summary-grid">';
foreach ($summary_items as $item) {
    $cls = !empty($item['total']) ? ' total' : '';
    print '<div class="dc-summary-card'.$cls.'">';
    print '  <div class="dc-summary-icon" style="color:'.$item['color'].';background:'.$item['bg'].';"><i class="fa '.$item['icon'].'"></i></div>';
    print '  <div class="dc-summary-label">'.$item['label'].'</div>';
    print '  <div class="dc-summary-val">'.price($item['val']).'</div>';
    print '</div>';
}
print '</div>';

// ── Filter bar ──
print '<form method="GET" action="'.$_SERVER['PHP_SELF'].'">';
if ($fk_booking > 0) print '<input type="hidden" name="fk_booking" value="'.$fk_booking.'">';
print '<div class="dc-filter-bar">';
print '  <input type="text" name="search_ref" placeholder="'.$langs->trans('SearchByRef').'" value="'.dol_escape_htmltag($search_ref).'">';
print '  <input type="date" name="date_from" value="'.dol_escape_htmltag($filter_from).'" title="'.$langs->trans('From').'">';
print '  <input type="date" name="date_to"   value="'.dol_escape_htmltag($filter_to).'"   title="'.$langs->trans('To').'">';
print '  <button type="submit" class="dc-btn dc-btn-primary"><i class="fa fa-search"></i> '.$langs->trans('Search').'</button>';
if (!empty($search_ref) || !empty($filter_from) || !empty($filter_to)) {
    print '  <a href="'.$_SERVER['PHP_SELF'].($fk_booking > 0 ? '?fk_booking='.$fk_booking : '').'" class="dc-btn dc-btn-ghost"><i class="fa fa-times"></i> '.$langs->trans('Clear').'</a>';
}
print '</div>';
print '</form>';

// ── Table ──
print '<div class="dc-table-wrap">';
if (empty($rows)) {
    print '<div class="dc-empty"><i class="fa fa-receipt"></i><div>'.$langs->trans('NoExpensesFound').'</div></div>';
} else {
    print '<table class="dc-table">';
    print '<thead><tr>';
    print '<th>'.$langs->trans('Reference').$sortUrl('b.ref').'</th>';
    print '<th>'.$langs->trans('BookingDate').$sortUrl('b.booking_date').'</th>';
    print '<th>'.$langs->trans('Customer').'</th>';
    print '<th>'.$langs->trans('Status').'</th>';
    print '<th style="text-align:right;">'.$langs->trans('FuelExpenses').$sortUrl('b.expense_fuel').'</th>';
    print '<th style="text-align:right;">'.$langs->trans('RoadExpenses').$sortUrl('b.expense_road').'</th>';
    print '<th style="text-align:right;">'.$langs->trans('DriverExpenses').$sortUrl('b.expense_driver').'</th>';
    print '<th style="text-align:right;">'.$langs->trans('CommissionExpenses').$sortUrl('b.expense_commission').'</th>';
    print '<th style="text-align:right;">'.$langs->trans('Total').$sortUrl('total_expenses').'</th>';
    print '<th></th>';
    print '</tr></thead>';
    print '<tbody>';

    $col_totals = array('fuel'=>0,'road'=>0,'driver'=>0,'commission'=>0,'total'=>0);

    foreach ($rows as $row) {
        $fuel   = (float)$row->expense_fuel;
        $road   = (float)$row->expense_road;
        $driver = (float)$row->expense_driver;
        $comm   = (float)$row->expense_commission;
        $total  = (float)$row->total_expenses;
        $col_totals['fuel']       += $fuel;
        $col_totals['road']       += $road;
        $col_totals['driver']     += $driver;
        $col_totals['commission'] += $comm;
        $col_totals['total']      += $total;

        $stClass = strtolower(str_replace(' ', '_', $row->status));
        $stLabel = $langs->trans(ucfirst(str_replace('_', '', ucwords($row->status, '_'))));

        print '<tr>';
        print '<td><a href="'.dol_buildpath('/flotte/booking_card.php',1).'?id='.$row->rowid.'" style="font-weight:600;color:#3c4758;text-decoration:none;">'.dol_escape_htmltag($row->ref).'</a></td>';
        print '<td>'.dol_print_date($row->booking_date, 'day').'</td>';
        print '<td>'.dol_escape_htmltag($row->customer_name).'</td>';
        print '<td><span class="dc-badge '.$stClass.'">'.dol_escape_htmltag($stLabel).'</span></td>';
        print '<td style="text-align:right;"><span class="dc-amount'.($fuel  == 0 ? ' zero' : '').'">'.($fuel  > 0 ? price($fuel)  : '&mdash;').'</span></td>';
        print '<td style="text-align:right;"><span class="dc-amount'.($road  == 0 ? ' zero' : '').'">'.($road  > 0 ? price($road)  : '&mdash;').'</span></td>';
        print '<td style="text-align:right;"><span class="dc-amount'.($driver== 0 ? ' zero' : '').'">'.($driver> 0 ? price($driver): '&mdash;').'</span></td>';
        print '<td style="text-align:right;"><span class="dc-amount'.($comm  == 0 ? ' zero' : '').'">'.($comm  > 0 ? price($comm)  : '&mdash;').'</span></td>';
        print '<td style="text-align:right;"><span class="dc-total-amount">'.price($total).'</span></td>';
        print '<td><a href="'.dol_buildpath('/flotte/booking_card.php',1).'?id='.$row->rowid.'" class="dc-btn dc-btn-ghost" style="padding:4px 10px;font-size:12px;"><i class="fa fa-eye"></i></a></td>';
        print '</tr>';
    }

    // Footer totals row
    print '<tr style="background:#f7f8fc;font-weight:700;">';
    print '<td colspan="4" style="font-size:12px;text-transform:uppercase;letter-spacing:0.5px;color:#8b92a9;">'.$langs->trans('PageTotal').'</td>';
    print '<td style="text-align:right;">'.price($col_totals['fuel']).'</td>';
    print '<td style="text-align:right;">'.price($col_totals['road']).'</td>';
    print '<td style="text-align:right;">'.price($col_totals['driver']).'</td>';
    print '<td style="text-align:right;">'.price($col_totals['commission']).'</td>';
    print '<td style="text-align:right;color:#3c4758;">'.price($col_totals['total']).'</td>';
    print '<td></td>';
    print '</tr>';

    print '</tbody></table>';

    // Pagination
    $total_pages = max(1, ceil($total_rows / $limit));
    if ($total_pages > 1) {
        $base_url = $_SERVER['PHP_SELF'].'?sortfield='.urlencode($sortfield).'&sortorder='.urlencode($sortorder)
            .($fk_booking  > 0    ? '&fk_booking='.$fk_booking   : '')
            .(!empty($search_ref) ? '&search_ref='.urlencode($search_ref) : '')
            .(!empty($filter_from)? '&date_from='.urlencode($filter_from) : '')
            .(!empty($filter_to)  ? '&date_to='.urlencode($filter_to)     : '');

        print '<div class="dc-pagination">';
        print '<span>'.$langs->trans('Page').' '.($page+1).' / '.$total_pages.'</span>';
        print '<div class="dc-pagination-links">';
        if ($page > 0)               print '<a href="'.$base_url.'&page='.($page-1).'"><i class="fa fa-chevron-left"></i></a>';
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

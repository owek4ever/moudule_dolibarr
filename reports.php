<?php
/* Copyright (C) 2025 Flotte Module - Professional Reports & Analytics */

$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) { $res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php"; }
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1; $j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) { $i--; $j--; }
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i+1))."/main.inc.php"))       { $res = @include substr($tmp, 0, ($i+1))."/main.inc.php"; }
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i+1)))."/main.inc.php")){ $res = @include dirname(substr($tmp, 0, ($i+1)))."/main.inc.php"; }
if (!$res && file_exists("../main.inc.php"))       { $res = @include "../main.inc.php"; }
if (!$res && file_exists("../../main.inc.php"))    { $res = @include "../../main.inc.php"; }
if (!$res && file_exists("../../../main.inc.php")) { $res = @include "../../../main.inc.php"; }
if (!$res) { die("Include of main fails"); }

$langs->loadLangs(array("flotte@flotte", "other"));
if (!$user->rights->flotte->read) { accessforbidden(); }

// ════════════════════════════════════════════════════════════
// FILTER PARAMETERS
// ════════════════════════════════════════════════════════════
$period          = GETPOST('period',          'alpha');   if (empty($period))  $period  = 'month';
$report_tab      = GETPOST('report_tab',      'alpha');   if (empty($report_tab)) $report_tab = 'overview';
$date_from_input = GETPOST('date_from',       'alpha');
$date_to_input   = GETPOST('date_to',         'alpha');
$fk_vehicle      = (int) GETPOST('fk_vehicle',  'int');
$fk_driver       = (int) GETPOST('fk_driver',   'int');
$fk_customer     = (int) GETPOST('fk_customer', 'int');
$fk_vendor       = (int) GETPOST('fk_vendor',   'int');
$filter_status   = GETPOST('filter_status',   'alpha');
$filter_fuel_src = GETPOST('filter_fuel_src', 'alpha');
$filter_wo_priority = GETPOST('filter_wo_priority', 'alpha');
$filter_vehicle_type= GETPOST('filter_vehicle_type','alpha');
$filter_min_revenue = GETPOST('filter_min_revenue','alpha');
$filter_max_revenue = GETPOST('filter_max_revenue','alpha');

$today_ts = mktime(0, 0, 0, (int)date('m'), (int)date('d'), (int)date('Y'));

switch ($period) {
    case 'week':    $date_from = date('Y-m-d', strtotime('-7 days', $today_ts));  $date_to = date('Y-m-d', $today_ts); $period_label = 'Last 7 Days'; break;
    case 'month':   $date_from = date('Y-m-01', $today_ts); $date_to = date('Y-m-d', $today_ts); $period_label = date('F Y', $today_ts); break;
    case 'quarter': $date_from = date('Y-m-d', strtotime('-90 days', $today_ts)); $date_to = date('Y-m-d', $today_ts); $period_label = 'Last 90 Days'; break;
    case 'year':    $date_from = date('Y-01-01', $today_ts); $date_to = date('Y-m-d', $today_ts); $period_label = 'Year '.date('Y'); break;
    case 'last_month': $lm = strtotime('first day of last month', $today_ts); $date_from = date('Y-m-01', $lm); $date_to = date('Y-m-t', $lm); $period_label = date('F Y', $lm); break;
    case 'last_year':  $date_from = (date('Y')-1).'-01-01'; $date_to = (date('Y')-1).'-12-31'; $period_label = 'Year '.(date('Y')-1); break;
    case 'custom':
        $date_from = !empty($date_from_input) ? $date_from_input : date('Y-m-01', $today_ts);
        $date_to   = !empty($date_to_input)   ? $date_to_input   : date('Y-m-d', $today_ts);
        $period_label = $date_from.' → '.$date_to;
        break;
    default: $date_from = date('Y-m-01', $today_ts); $date_to = date('Y-m-d', $today_ts); $period_label = date('F Y', $today_ts);
}

// ════════════════════════════════════════════════════════════
// HELPERS
// ════════════════════════════════════════════════════════════
function rpt_val($db, $sql, $col = 'val', $default = 0) {
    $r = $db->query($sql);
    if ($r) { $o = $db->fetch_object($r); if ($o && isset($o->$col)) return $o->$col; }
    return $default;
}
function rpt_rows($db, $sql) {
    $rows = array();
    $r = $db->query($sql);
    while ($r && $o = $db->fetch_object($r)) { $rows[] = $o; }
    return $rows;
}
function rpt_fmt($n, $dec=0) { return number_format((float)$n, $dec, '.', ','); }
function rpt_badge($label, $type='info') {
    $map = array('pending'=>'warning','confirmed'=>'info','in_progress'=>'info','completed'=>'success','cancelled'=>'danger','canceled'=>'danger','done'=>'success','High'=>'danger','Critical'=>'danger','Medium'=>'warning','Low'=>'success');
    $cls = isset($map[$label]) ? $map[$label] : $type;
    echo '<span class="sbadge sbadge-'.$cls.'">'.htmlspecialchars($label).'</span>';
}

$entity = (int) $conf->entity;
$df = $db->escape($date_from);
$dt = $db->escape($date_to);

// Build SQL fragments
$base  = " AND b.entity=$entity AND b.booking_date BETWEEN '$df' AND '$dt'";
if ($fk_vehicle)   $base .= " AND b.fk_vehicle=".(int)$fk_vehicle;
if ($fk_driver)    $base .= " AND b.fk_driver=".(int)$fk_driver;
if ($fk_customer)  $base .= " AND b.fk_customer=".(int)$fk_customer;
if ($fk_vendor)    $base .= " AND b.fk_vendor=".(int)$fk_vendor;
if ($filter_status) $base .= " AND b.status='".$db->escape($filter_status)."'";
if (!empty($filter_min_revenue) && is_numeric($filter_min_revenue)) $base .= " AND b.selling_amount >= ".(float)$filter_min_revenue;
if (!empty($filter_max_revenue) && is_numeric($filter_max_revenue)) $base .= " AND b.selling_amount <= ".(float)$filter_max_revenue;

$ffuel = " AND f.entity=$entity AND f.date BETWEEN '$df' AND '$dt'";
if ($fk_vehicle)    $ffuel .= " AND f.fk_vehicle=".(int)$fk_vehicle;
if ($filter_fuel_src) $ffuel .= " AND f.fuel_source='".$db->escape($filter_fuel_src)."'";

$fwo = " AND w.entity=$entity";
if ($fk_vehicle)         $fwo .= " AND w.fk_vehicle=".(int)$fk_vehicle;
if ($fk_driver)          $fwo .= " AND w.fk_driver=".(int)$fk_driver;
if ($filter_wo_priority) $fwo .= " AND w.priority='".$db->escape($filter_wo_priority)."'";

$fveh = " WHERE v.entity=$entity";
if ($filter_vehicle_type) $fveh .= " AND v.type='".$db->escape($filter_vehicle_type)."'";

// ════════════════════════════════════════════════════════════
// BOOKING KPIs
// ════════════════════════════════════════════════════════════
$total_bookings    = (int)   rpt_val($db, "SELECT COUNT(*) as val FROM ".MAIN_DB_PREFIX."flotte_booking b WHERE 1=1".$base);
$total_revenue     = (float) rpt_val($db, "SELECT COALESCE(SUM(selling_amount),0) as val FROM ".MAIN_DB_PREFIX."flotte_booking b WHERE 1=1".$base);
$total_cost        = (float) rpt_val($db, "SELECT COALESCE(SUM(buying_amount),0) as val FROM ".MAIN_DB_PREFIX."flotte_booking b WHERE 1=1".$base);
$total_distance    = (int)   rpt_val($db, "SELECT COALESCE(SUM(distance),0) as val FROM ".MAIN_DB_PREFIX."flotte_booking b WHERE 1=1".$base);
$gross_margin      = $total_revenue - $total_cost;
$margin_pct        = $total_revenue > 0 ? round(($gross_margin / $total_revenue) * 100, 1) : 0;
$avg_revenue_trip  = $total_bookings > 0 ? $total_revenue / $total_bookings : 0;
$avg_distance_trip = $total_bookings > 0 ? $total_distance / $total_bookings : 0;

// Previous period for trend comparison
$days_diff = max(1, (strtotime($date_to) - strtotime($date_from)) / 86400);
$prev_from = date('Y-m-d', strtotime('-'.(int)$days_diff.' days', strtotime($date_from)));
$prev_to   = date('Y-m-d', strtotime('-1 day', strtotime($date_from)));
$prev_revenue  = (float) rpt_val($db, "SELECT COALESCE(SUM(selling_amount),0) as val FROM ".MAIN_DB_PREFIX."flotte_booking b WHERE b.entity=$entity AND b.booking_date BETWEEN '".$db->escape($prev_from)."' AND '".$db->escape($prev_to)."'");
$prev_bookings = (int)   rpt_val($db, "SELECT COUNT(*) as val FROM ".MAIN_DB_PREFIX."flotte_booking b WHERE b.entity=$entity AND b.booking_date BETWEEN '".$db->escape($prev_from)."' AND '".$db->escape($prev_to)."'");
$rev_trend_pct  = $prev_revenue  > 0 ? round((($total_revenue  - $prev_revenue)  / $prev_revenue)  * 100, 1) : null;
$bkg_trend_pct  = $prev_bookings > 0 ? round((($total_bookings - $prev_bookings) / $prev_bookings) * 100, 1) : null;

// Status breakdown
$status_rows = rpt_rows($db, "SELECT COALESCE(status,'N/A') as lbl, COUNT(*) as cnt, COALESCE(SUM(selling_amount),0) as rev FROM ".MAIN_DB_PREFIX."flotte_booking b WHERE 1=1".$base." GROUP BY status ORDER BY cnt DESC");

// 6-month trend
$trend_data = array();
for ($m = 5; $m >= 0; $m--) {
    $ts = strtotime("-$m months", $today_ts);
    $y  = (int)date('Y',$ts); $mo = (int)date('m',$ts);
    $trend_data[] = array(
        'month'    => date('M Y',$ts),
        'bookings' => (int)   rpt_val($db, "SELECT COUNT(*) as val FROM ".MAIN_DB_PREFIX."flotte_booking WHERE entity=$entity AND YEAR(booking_date)=$y AND MONTH(booking_date)=$mo"),
        'revenue'  => (float) rpt_val($db, "SELECT COALESCE(SUM(selling_amount),0) as val FROM ".MAIN_DB_PREFIX."flotte_booking WHERE entity=$entity AND YEAR(booking_date)=$y AND MONTH(booking_date)=$mo"),
        'cost'     => (float) rpt_val($db, "SELECT COALESCE(SUM(buying_amount),0) as val FROM ".MAIN_DB_PREFIX."flotte_booking WHERE entity=$entity AND YEAR(booking_date)=$y AND MONTH(booking_date)=$mo"),
    );
}

// Top vehicles / drivers / customers
$top_vehicles  = rpt_rows($db, "SELECT v.ref, v.maker, v.model, v.license_plate, COUNT(*) as bookings, COALESCE(SUM(b.selling_amount),0) as revenue, COALESCE(SUM(b.distance),0) as distance FROM ".MAIN_DB_PREFIX."flotte_booking b LEFT JOIN ".MAIN_DB_PREFIX."flotte_vehicle v ON v.rowid=b.fk_vehicle WHERE 1=1".$base." GROUP BY b.fk_vehicle ORDER BY bookings DESC LIMIT 10");
$top_drivers   = rpt_rows($db, "SELECT d.ref, d.lastname, d.firstname, COUNT(*) as bookings, COALESCE(SUM(b.distance),0) as distance, COALESCE(SUM(b.selling_amount),0) as revenue FROM ".MAIN_DB_PREFIX."flotte_booking b LEFT JOIN ".MAIN_DB_PREFIX."flotte_driver d ON d.rowid=b.fk_driver WHERE 1=1".$base." GROUP BY b.fk_driver ORDER BY bookings DESC LIMIT 10");
$top_customers = rpt_rows($db, "SELECT c.ref, c.firstname, c.lastname, c.company_name, COUNT(*) as bookings, COALESCE(SUM(b.selling_amount),0) as revenue FROM ".MAIN_DB_PREFIX."flotte_booking b LEFT JOIN ".MAIN_DB_PREFIX."flotte_customer c ON c.rowid=b.fk_customer WHERE 1=1".$base." GROUP BY b.fk_customer ORDER BY revenue DESC LIMIT 10");

// Revenue by day of week
$dow_data = array();
$dow_names = array('Sun','Mon','Tue','Wed','Thu','Fri','Sat');
foreach ($dow_names as $k => $dn) {
    $dow_data[] = array('day'=>$dn, 'cnt'=>(int)rpt_val($db,"SELECT COUNT(*) as val FROM ".MAIN_DB_PREFIX."flotte_booking b WHERE DAYOFWEEK(b.booking_date)=".($k+1).$base), 'rev'=>(float)rpt_val($db,"SELECT COALESCE(SUM(selling_amount),0) as val FROM ".MAIN_DB_PREFIX."flotte_booking b WHERE DAYOFWEEK(b.booking_date)=".($k+1).$base));
}

// ════════════════════════════════════════════════════════════
// FUEL KPIs
// ════════════════════════════════════════════════════════════
$total_fuel_qty    = (float) rpt_val($db, "SELECT COALESCE(SUM(qty),0) as val FROM ".MAIN_DB_PREFIX."flotte_fuel f WHERE 1=1".$ffuel);
$total_fuel_cost   = (float) rpt_val($db, "SELECT COALESCE(SUM(qty*cost_unit),0) as val FROM ".MAIN_DB_PREFIX."flotte_fuel f WHERE 1=1".$ffuel);
$avg_cost_per_litre= $total_fuel_qty > 0 ? $total_fuel_cost / $total_fuel_qty : 0;
$km_per_litre      = $total_fuel_qty > 0 && $total_distance > 0 ? $total_distance / $total_fuel_qty : 0;

$fuel_by_vehicle = rpt_rows($db, "SELECT COALESCE(v.ref,'Unknown') as ref, COALESCE(v.maker,'') as maker, COALESCE(v.model,'') as model, COALESCE(SUM(f.qty),0) as total_qty, COALESCE(SUM(f.qty*f.cost_unit),0) as total_cost, COUNT(*) as entries FROM ".MAIN_DB_PREFIX."flotte_fuel f LEFT JOIN ".MAIN_DB_PREFIX."flotte_vehicle v ON v.rowid=f.fk_vehicle WHERE 1=1".$ffuel." GROUP BY f.fk_vehicle ORDER BY total_qty DESC LIMIT 10");
$fuel_by_source  = rpt_rows($db, "SELECT COALESCE(fuel_source,'N/A') as lbl, COUNT(*) as cnt, COALESCE(SUM(qty),0) as qty, COALESCE(SUM(qty*cost_unit),0) as cost FROM ".MAIN_DB_PREFIX."flotte_fuel f WHERE 1=1".$ffuel." GROUP BY fuel_source ORDER BY qty DESC");

// Monthly fuel trend
$fuel_trend = array();
for ($m = 5; $m >= 0; $m--) {
    $ts = strtotime("-$m months", $today_ts);
    $y=(int)date('Y',$ts); $mo=(int)date('m',$ts);
    $fuel_trend[] = array('month'=>date('M Y',$ts), 'qty'=>(float)rpt_val($db,"SELECT COALESCE(SUM(qty),0) as val FROM ".MAIN_DB_PREFIX."flotte_fuel WHERE entity=$entity AND YEAR(date)=$y AND MONTH(date)=$mo"), 'cost'=>(float)rpt_val($db,"SELECT COALESCE(SUM(qty*cost_unit),0) as val FROM ".MAIN_DB_PREFIX."flotte_fuel WHERE entity=$entity AND YEAR(date)=$y AND MONTH(date)=$mo"));
}

// ════════════════════════════════════════════════════════════
// MAINTENANCE KPIs
// ════════════════════════════════════════════════════════════
$wo_total     = (int)   rpt_val($db, "SELECT COUNT(*) as val FROM ".MAIN_DB_PREFIX."flotte_workorder w WHERE 1=1".$fwo);
$wo_pending   = (int)   rpt_val($db, "SELECT COUNT(*) as val FROM ".MAIN_DB_PREFIX."flotte_workorder w WHERE status IN ('open','pending','in_progress')".$fwo);
$wo_completed = (int)   rpt_val($db, "SELECT COUNT(*) as val FROM ".MAIN_DB_PREFIX."flotte_workorder w WHERE status IN ('completed','done','closed')".$fwo);
$wo_cost      = (float) rpt_val($db, "SELECT COALESCE(SUM(price),0) as val FROM ".MAIN_DB_PREFIX."flotte_workorder w WHERE 1=1".$fwo);
$wo_comp_rate = $wo_total > 0 ? round(($wo_completed/$wo_total)*100) : 0;
$wo_avg_cost  = $wo_total > 0 ? $wo_cost/$wo_total : 0;

$wo_by_priority = rpt_rows($db, "SELECT COALESCE(priority,'N/A') as lbl, COUNT(*) as cnt, COALESCE(SUM(price),0) as cost FROM ".MAIN_DB_PREFIX."flotte_workorder w WHERE 1=1".$fwo." GROUP BY priority ORDER BY cnt DESC");
$wo_by_status   = rpt_rows($db, "SELECT COALESCE(status,'N/A') as lbl, COUNT(*) as cnt FROM ".MAIN_DB_PREFIX."flotte_workorder w WHERE 1=1".$fwo." GROUP BY status ORDER BY cnt DESC");
$wo_by_vehicle  = rpt_rows($db, "SELECT COALESCE(v.ref,'Unknown') as ref, COALESCE(v.maker,'') as maker, COALESCE(v.model,'') as model, COUNT(*) as cnt, COALESCE(SUM(w.price),0) as cost FROM ".MAIN_DB_PREFIX."flotte_workorder w LEFT JOIN ".MAIN_DB_PREFIX."flotte_vehicle v ON v.rowid=w.fk_vehicle WHERE 1=1".$fwo." GROUP BY w.fk_vehicle ORDER BY cost DESC LIMIT 10");

// ════════════════════════════════════════════════════════════
// FLEET STATUS
// ════════════════════════════════════════════════════════════
$fleet_total    = (int) rpt_val($db, "SELECT COUNT(*) as val FROM ".MAIN_DB_PREFIX."flotte_vehicle v".$fveh);
$fleet_active   = (int) rpt_val($db, "SELECT COUNT(*) as val FROM ".MAIN_DB_PREFIX."flotte_vehicle v".$fveh." AND v.in_service=1");
$fleet_inactive = $fleet_total - $fleet_active;
$fleet_util     = $fleet_total > 0 ? round(($fleet_active/$fleet_total)*100) : 0;
$fleet_by_type  = rpt_rows($db, "SELECT COALESCE(type,'N/A') as lbl, COUNT(*) as cnt, SUM(in_service) as active FROM ".MAIN_DB_PREFIX."flotte_vehicle v".$fveh." GROUP BY type ORDER BY cnt DESC");
$fleet_by_maker = rpt_rows($db, "SELECT COALESCE(maker,'Unknown') as lbl, COUNT(*) as cnt FROM ".MAIN_DB_PREFIX."flotte_vehicle v".$fveh." GROUP BY maker ORDER BY cnt DESC LIMIT 8");

$total_drivers    = (int) rpt_val($db, "SELECT COUNT(*) as val FROM ".MAIN_DB_PREFIX."flotte_driver WHERE entity=$entity");
$total_customers  = (int) rpt_val($db, "SELECT COUNT(*) as val FROM ".MAIN_DB_PREFIX."flotte_customer WHERE entity=$entity");
$total_inspections= (int) rpt_val($db, "SELECT COUNT(*) as val FROM ".MAIN_DB_PREFIX."flotte_inspection WHERE entity=$entity");

// ════════════════════════════════════════════════════════════
// EXPENSES KPIs  (flotte_expense table)
// ════════════════════════════════════════════════════════════
// Ensure table exists (same DDL as expenses_card.php / expenses_list.php)
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

$fexp = " AND e.entity=$entity AND e.expense_date BETWEEN '$df' AND '$dt'";
if ($fk_vehicle) $fexp .= " AND b2.fk_vehicle=".(int)$fk_vehicle;

$exp_total_count  = (int)   rpt_val($db, "SELECT COUNT(*) as val FROM ".MAIN_DB_PREFIX."flotte_expense e LEFT JOIN ".MAIN_DB_PREFIX."flotte_booking b2 ON b2.rowid=e.fk_booking WHERE 1=1".$fexp);
$exp_total_amount = (float) rpt_val($db, "SELECT COALESCE(SUM(e.amount),0) as val FROM ".MAIN_DB_PREFIX."flotte_expense e LEFT JOIN ".MAIN_DB_PREFIX."flotte_booking b2 ON b2.rowid=e.fk_booking WHERE 1=1".$fexp);
$exp_avg_amount   = $exp_total_count > 0 ? $exp_total_amount / $exp_total_count : 0;

// Previous period for expenses trend
$prev_exp_amount  = (float) rpt_val($db, "SELECT COALESCE(SUM(e.amount),0) as val FROM ".MAIN_DB_PREFIX."flotte_expense e WHERE e.entity=$entity AND e.expense_date BETWEEN '".$db->escape($prev_from)."' AND '".$db->escape($prev_to)."'");
$exp_trend_pct    = $prev_exp_amount > 0 ? round((($exp_total_amount - $prev_exp_amount) / $prev_exp_amount) * 100, 1) : null;

// Expenses by category
$exp_by_cat = rpt_rows($db, "SELECT COALESCE(e.category,'other') as lbl, COUNT(*) as cnt, COALESCE(SUM(e.amount),0) as total FROM ".MAIN_DB_PREFIX."flotte_expense e WHERE 1=1".$fexp." GROUP BY e.category ORDER BY total DESC");

// Monthly expense trend (6 months)
$exp_trend = array();
for ($m = 5; $m >= 0; $m--) {
    $ts2 = strtotime("-$m months", $today_ts);
    $y2=(int)date('Y',$ts2); $mo2=(int)date('m',$ts2);
    $exp_trend[] = array(
        'month' => date('M Y',$ts2),
        'total' => (float)rpt_val($db,"SELECT COALESCE(SUM(amount),0) as val FROM ".MAIN_DB_PREFIX."flotte_expense WHERE entity=$entity AND YEAR(expense_date)=$y2 AND MONTH(expense_date)=$mo2"),
        'fuel'  => (float)rpt_val($db,"SELECT COALESCE(SUM(amount),0) as val FROM ".MAIN_DB_PREFIX."flotte_expense WHERE entity=$entity AND category='fuel' AND YEAR(expense_date)=$y2 AND MONTH(expense_date)=$mo2"),
        'road'  => (float)rpt_val($db,"SELECT COALESCE(SUM(amount),0) as val FROM ".MAIN_DB_PREFIX."flotte_expense WHERE entity=$entity AND category='road' AND YEAR(expense_date)=$y2 AND MONTH(expense_date)=$mo2"),
        'driver'=> (float)rpt_val($db,"SELECT COALESCE(SUM(amount),0) as val FROM ".MAIN_DB_PREFIX."flotte_expense WHERE entity=$entity AND category='driver' AND YEAR(expense_date)=$y2 AND MONTH(expense_date)=$mo2"),
    );
}

// Top expense vehicles (via booking link)
$exp_by_vehicle = rpt_rows($db, "SELECT COALESCE(v.ref,'Unknown') as ref, COALESCE(v.maker,'') as maker, COALESCE(v.model,'') as model, COUNT(*) as cnt, COALESCE(SUM(e.amount),0) as total FROM ".MAIN_DB_PREFIX."flotte_expense e JOIN ".MAIN_DB_PREFIX."flotte_booking b2 ON b2.rowid=e.fk_booking JOIN ".MAIN_DB_PREFIX."flotte_vehicle v ON v.rowid=b2.fk_vehicle WHERE e.entity=$entity AND e.expense_date BETWEEN '$df' AND '$dt' GROUP BY b2.fk_vehicle ORDER BY total DESC LIMIT 10");

// Expense category breakdown details
$exp_fuel_total   = (float) rpt_val($db, "SELECT COALESCE(SUM(amount),0) as val FROM ".MAIN_DB_PREFIX."flotte_expense e WHERE e.entity=$entity AND e.category='fuel' AND e.expense_date BETWEEN '$df' AND '$dt'");
$exp_road_total   = (float) rpt_val($db, "SELECT COALESCE(SUM(amount),0) as val FROM ".MAIN_DB_PREFIX."flotte_expense e WHERE e.entity=$entity AND e.category='road' AND e.expense_date BETWEEN '$df' AND '$dt'");
$exp_driver_total = (float) rpt_val($db, "SELECT COALESCE(SUM(amount),0) as val FROM ".MAIN_DB_PREFIX."flotte_expense e WHERE e.entity=$entity AND e.category='driver' AND e.expense_date BETWEEN '$df' AND '$dt'");
$exp_comm_total   = (float) rpt_val($db, "SELECT COALESCE(SUM(amount),0) as val FROM ".MAIN_DB_PREFIX."flotte_expense e WHERE e.entity=$entity AND e.category='commission' AND e.expense_date BETWEEN '$df' AND '$dt'");
$exp_other_total  = (float) rpt_val($db, "SELECT COALESCE(SUM(amount),0) as val FROM ".MAIN_DB_PREFIX."flotte_expense e WHERE e.entity=$entity AND e.category='other' AND e.expense_date BETWEEN '$df' AND '$dt'");

// Recent expenses list (last 20)
$exp_recent = rpt_rows($db, "SELECT e.rowid, e.ref, e.expense_date, e.category, e.amount, e.notes, e.other_label, b2.ref as booking_ref FROM ".MAIN_DB_PREFIX."flotte_expense e LEFT JOIN ".MAIN_DB_PREFIX."flotte_booking b2 ON b2.rowid=e.fk_booking WHERE e.entity=$entity AND e.expense_date BETWEEN '$df' AND '$dt' ORDER BY e.expense_date DESC, e.rowid DESC LIMIT 20");

// Expense vs Revenue ratio
$exp_vs_rev_pct = $total_revenue > 0 ? round(($exp_total_amount / $total_revenue) * 100, 1) : 0;

// Net profit (used in overview and summary ribbon)
$net_profit     = $total_revenue - $total_cost - $exp_total_amount;
$net_margin_pct = $total_revenue > 0 ? round(($net_profit / $total_revenue) * 100, 1) : 0;

// ════════════════════════════════════════════════════════════
// FILTER DROPDOWNS DATA
// ════════════════════════════════════════════════════════════
$vehicles_list  = array(0=>'— All Vehicles —');
foreach (rpt_rows($db,"SELECT rowid, ref, maker, model FROM ".MAIN_DB_PREFIX."flotte_vehicle WHERE entity=$entity ORDER BY ref") as $o) {
    $vehicles_list[$o->rowid] = $o->ref.' · '.trim($o->maker.' '.$o->model);
}
$drivers_list   = array(0=>'— All Drivers —');
foreach (rpt_rows($db,"SELECT rowid, ref, lastname, firstname FROM ".MAIN_DB_PREFIX."flotte_driver WHERE entity=$entity ORDER BY lastname") as $o) {
    $drivers_list[$o->rowid] = $o->ref.' · '.trim($o->firstname.' '.$o->lastname);
}
$customers_list = array(0=>'— All Customers —');
foreach (rpt_rows($db,"SELECT rowid, ref, firstname, lastname, company_name FROM ".MAIN_DB_PREFIX."flotte_customer WHERE entity=$entity ORDER BY lastname") as $o) {
    $name = !empty($o->company_name) ? $o->company_name : trim($o->firstname.' '.$o->lastname);
    $customers_list[$o->rowid] = $o->ref.' · '.$name;
}
$vendors_list   = array(0=>'— All Vendors —');
foreach (rpt_rows($db,"SELECT rowid, ref, name FROM ".MAIN_DB_PREFIX."flotte_vendor WHERE entity=$entity ORDER BY name") as $o) {
    $vendors_list[$o->rowid] = $o->ref.' · '.$o->name;
}

// Distinct statuses from bookings
$booking_statuses = array(''=>'— All Statuses —');
foreach (rpt_rows($db,"SELECT DISTINCT status FROM ".MAIN_DB_PREFIX."flotte_booking WHERE entity=$entity AND status IS NOT NULL AND status != '' ORDER BY status") as $o) {
    $booking_statuses[$o->status] = ucfirst($o->status);
}
// Distinct fuel sources
$fuel_sources = array(''=>'— All Sources —');
foreach (rpt_rows($db,"SELECT DISTINCT fuel_source FROM ".MAIN_DB_PREFIX."flotte_fuel WHERE entity=$entity AND fuel_source IS NOT NULL AND fuel_source != '' ORDER BY fuel_source") as $o) {
    $fuel_sources[$o->fuel_source] = ucfirst($o->fuel_source);
}
// Vehicle types
$vehicle_types = array(''=>'— All Types —');
foreach (rpt_rows($db,"SELECT DISTINCT type FROM ".MAIN_DB_PREFIX."flotte_vehicle WHERE entity=$entity AND type IS NOT NULL AND type != '' ORDER BY type") as $o) {
    $vehicle_types[$o->type] = ucfirst($o->type);
}

// Build URL param string for tab switching
function rpt_params($extra='') {
    $p = '';
    $fields = array('period','date_from','date_to','fk_vehicle','fk_driver','fk_customer','fk_vendor','filter_status','filter_fuel_src','filter_wo_priority','filter_vehicle_type','filter_min_revenue','filter_max_revenue');
    foreach ($fields as $f) { $v = GETPOST($f,'alpha'); if (!empty($v)) $p .= '&'.$f.'='.urlencode($v); }
    return $p.$extra;
}

// ════════════════════════════════════════════════════════════
// RENDER
// ════════════════════════════════════════════════════════════
llxHeader('', 'Fleet Reports & Analytics', '');
print '<div class="fichecenter">';
?>

<style>
/* ─── Base Layout ──────────────────────────────────────── */
.rpt            { max-width:1500px; margin:0 auto; padding:24px 20px; font-family:inherit; }
.rpt *          { box-sizing:border-box; }

/* ─── Page Header ──────────────────────────────────────── */
.rpt-header     { display:flex; align-items:center; justify-content:space-between; margin-bottom:24px; flex-wrap:wrap; gap:12px; }
.rpt-header-left h1 { font-size:24px; font-weight:800; color:#1a202c; margin:0 0 4px; letter-spacing:-.3px; }
.rpt-header-left p  { font-size:13px; color:#718096; margin:0; }
.rpt-header-right   { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
.period-chip    { display:inline-flex; align-items:center; gap:6px; background:linear-gradient(135deg,#667eea,#764ba2); color:#fff; padding:7px 16px; border-radius:20px; font-size:12px; font-weight:700; letter-spacing:.03em; box-shadow:0 2px 8px rgba(102,126,234,.35); }
.export-btn     { display:inline-flex; align-items:center; gap:6px; background:#fff; color:#4a5568; border:1.5px solid #e2e8f0; padding:7px 14px; border-radius:8px; font-size:12px; font-weight:600; text-decoration:none; cursor:pointer; transition:all .2s; }
.export-btn:hover { border-color:#667eea; color:#667eea; }

/* ─── Filter Panel ──────────────────────────────────────── */
.rpt-filter-panel { background:#fff; border-radius:14px; box-shadow:0 2px 16px rgba(0,0,0,.07); margin-bottom:28px; overflow:hidden; }
.rpt-filter-head  { display:flex; align-items:center; justify-content:space-between; padding:16px 22px; background:linear-gradient(135deg,#f7f8fc,#eef0f8); border-bottom:1px solid #e8ecf5; cursor:pointer; user-select:none; }
.rpt-filter-head h3 { margin:0; font-size:14px; font-weight:700; color:#2d3748; display:flex; align-items:center; gap:8px; }
.rpt-filter-head h3 i { color:#667eea; }
.rpt-filter-toggle  { font-size:11px; color:#667eea; font-weight:700; display:flex; align-items:center; gap:5px; }
.rpt-filter-body    { padding:20px 22px; display:grid; grid-template-columns:repeat(auto-fit, minmax(180px,1fr)); gap:14px; }
.rpt-filter-body.collapsed { display:none; }
.rft             { display:flex; flex-direction:column; gap:5px; }
.rft label       { font-size:10.5px; font-weight:800; text-transform:uppercase; letter-spacing:.08em; color:#667eea; }
.rft select, .rft input { border:1.5px solid #e2e8f0; border-radius:8px; padding:8px 12px; font-size:13px; color:#2d3748; background:#f7f8fc; transition:border-color .15s,background .15s; width:100%; }
.rft select:focus, .rft input:focus { outline:none; border-color:#667eea; background:#fff; box-shadow:0 0 0 3px rgba(102,126,234,.1); }
.rft-range       { display:flex; gap:6px; align-items:center; }
.rft-range span  { font-size:12px; color:#a0aec0; font-weight:600; flex-shrink:0; }
.rft-range input { width:auto; flex:1; min-width:0; }
.rpt-filter-actions { padding:0 22px 18px; display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
.btn-apply  { background:linear-gradient(135deg,#667eea,#764ba2); color:#fff; border:none; border-radius:8px; padding:9px 22px; font-size:13px; font-weight:700; cursor:pointer; transition:all .2s; display:inline-flex; align-items:center; gap:6px; }
.btn-apply:hover { transform:translateY(-1px); box-shadow:0 4px 14px rgba(102,126,234,.45); }
.btn-reset  { background:#f7f8fc; color:#718096; border:1.5px solid #e2e8f0; border-radius:8px; padding:8px 18px; font-size:13px; font-weight:600; text-decoration:none; display:inline-flex; align-items:center; gap:6px; transition:all .2s; }
.btn-reset:hover { border-color:#667eea; color:#667eea; }
.active-filters { display:flex; flex-wrap:wrap; gap:6px; margin-left:auto; }
.af-chip        { display:inline-flex; align-items:center; gap:5px; background:#eff0ff; color:#667eea; border:1px solid #c7d2fe; padding:4px 10px; border-radius:14px; font-size:11px; font-weight:700; }

/* ─── Tab Navigation ────────────────────────────────────── */
.rpt-tabs       { display:flex; gap:4px; margin-bottom:24px; background:#f7f8fc; padding:5px; border-radius:12px; flex-wrap:wrap; }
.rpt-tab        { flex:1; min-width:120px; text-align:center; padding:10px 16px; border-radius:9px; font-size:13px; font-weight:600; color:#718096; text-decoration:none; transition:all .2s; display:flex; align-items:center; justify-content:center; gap:7px; border:none; background:transparent; cursor:pointer; }
.rpt-tab:hover  { color:#667eea; background:rgba(102,126,234,.07); }
.rpt-tab.active { background:#fff; color:#667eea; box-shadow:0 2px 10px rgba(0,0,0,.08); font-weight:700; }
.rpt-tab i      { font-size:14px; }

/* ─── KPI Cards ─────────────────────────────────────────── */
.kpi-grid       { display:grid; grid-template-columns:repeat(4,1fr); gap:18px; margin-bottom:28px; }
.kpi-card       { border-radius:14px; padding:22px; color:#fff; position:relative; overflow:hidden; box-shadow:0 4px 18px rgba(0,0,0,.1); transition:transform .25s,box-shadow .25s; cursor:default; }
.kpi-card:hover { transform:translateY(-4px); box-shadow:0 10px 28px rgba(0,0,0,.15); }
.kpi-card.clickable { cursor:pointer; text-decoration:none; display:block; }
.kpi-card::before { content:''; position:absolute; top:-40%; right:-40%; width:160%; height:160%; background:radial-gradient(circle,rgba(255,255,255,.12) 0%,transparent 65%); pointer-events:none; }
.kpi-shine      { position:absolute; top:0; left:0; right:0; height:1px; background:linear-gradient(90deg,transparent,rgba(255,255,255,.4),transparent); }
.kpi-icon       { position:absolute; right:18px; top:50%; transform:translateY(-50%); font-size:52px; opacity:.15; }
.kpi-body       { position:relative; z-index:1; }
.kpi-val        { font-size:32px; font-weight:800; line-height:1; margin-bottom:6px; letter-spacing:-.5px; }
.kpi-val.sm     { font-size:24px; }
.kpi-lbl        { font-size:11px; text-transform:uppercase; letter-spacing:.1em; opacity:.85; font-weight:700; }
.kpi-sub        { margin-top:10px; font-size:12px; opacity:.8; display:flex; align-items:center; gap:5px; flex-wrap:wrap; }
.kpi-trend      { display:inline-flex; align-items:center; gap:3px; background:rgba(255,255,255,.2); padding:2px 7px; border-radius:10px; font-size:11px; font-weight:700; }
.kpi-trend.up   { background:rgba(72,199,142,.3); }
.kpi-trend.down { background:rgba(255,91,91,.3); }

.kpi-bookings   { background:linear-gradient(135deg,#667eea 0%,#764ba2 100%); }
.kpi-revenue    { background:linear-gradient(135deg,#11998e 0%,#38ef7d 100%); }
.kpi-cost       { background:linear-gradient(135deg,#f093fb 0%,#f5576c 100%); }
.kpi-margin     { background:linear-gradient(135deg,#4facfe 0%,#00f2fe 100%); }
.kpi-distance   { background:linear-gradient(135deg,#43e97b 0%,#38f9d7 100%); }
.kpi-fuel       { background:linear-gradient(135deg,#f7971e 0%,#ffd200 100%); }
.kpi-maint      { background:linear-gradient(135deg,#fc4a1a 0%,#f7b733 100%); }
.kpi-fleet      { background:linear-gradient(135deg,#6a11cb 0%,#2575fc 100%); }

/* ─── Section ───────────────────────────────────────────── */
.rpt-section    { margin-bottom:28px; }
.rpt-sec-hdr    { display:flex; align-items:center; justify-content:space-between; margin-bottom:18px; padding-bottom:14px; border-bottom:2px solid #edf2f7; }
.rpt-sec-title  { font-size:17px; font-weight:700; color:#1a202c; display:flex; align-items:center; gap:9px; margin:0; }
.rpt-sec-title i { color:#667eea; }
.rpt-sec-meta   { font-size:12px; color:#a0aec0; }

/* ─── Cards ─────────────────────────────────────────────── */
.card           { background:#fff; border-radius:14px; padding:22px; box-shadow:0 2px 14px rgba(0,0,0,.06); border:1px solid #f0f4f8; }
.card-title     { font-size:14px; font-weight:700; color:#1a202c; margin:0 0 18px; padding-bottom:12px; border-bottom:1.5px solid #f0f4f8; display:flex; align-items:center; gap:8px; }
.card-title i   { color:#667eea; font-size:15px; }
.two-col        { display:grid; grid-template-columns:1fr 1fr; gap:20px; }
.three-col      { display:grid; grid-template-columns:1fr 1fr 1fr; gap:20px; }
.col-7-3        { display:grid; grid-template-columns:2fr 1fr; gap:20px; }

/* ─── Charts ────────────────────────────────────────────── */
.chart-wrap     { position:relative; height:260px; }
.chart-wrap.tall{ height:300px; }
.chart-wrap.sm  { height:200px; }

/* ─── Tables ────────────────────────────────────────────── */
.rpt-table      { width:100%; border-collapse:separate; border-spacing:0; font-size:13px; }
.rpt-table thead tr { background:transparent; }
.rpt-table th   { padding:9px 14px; font-size:10.5px; font-weight:800; text-transform:uppercase; letter-spacing:.08em; color:#667eea; border-bottom:2px solid #edf2f7; white-space:nowrap; }
.rpt-table td   { padding:11px 14px; border-bottom:1px solid #f7fafc; color:#2d3748; vertical-align:middle; }
.rpt-table tbody tr:last-child td { border-bottom:none; }
.rpt-table tbody tr { transition:background .12s; }
.rpt-table tbody tr:hover td { background:#f7f9ff; }
.rpt-table .rank { width:32px; font-weight:800; color:#667eea; font-size:15px; }
.rpt-table .name-cell .main { font-weight:600; color:#1a202c; }
.rpt-table .name-cell .sub  { font-size:11px; color:#a0aec0; margin-top:1px; }
.rpt-table .num  { font-weight:700; color:#2d3748; }
.rpt-table .rev  { font-weight:700; color:#38a169; }
.rpt-table .cost { font-weight:700; color:#e53e3e; }

/* ─── Progress bars ─────────────────────────────────────── */
.prog           { background:#edf2f7; border-radius:6px; height:7px; overflow:hidden; }
.prog-fill      { height:100%; border-radius:6px; transition:width .5s ease; }
.prog-fill.blue { background:linear-gradient(90deg,#667eea,#764ba2); }
.prog-fill.green{ background:linear-gradient(90deg,#11998e,#38ef7d); }
.prog-fill.orange{background:linear-gradient(90deg,#f7971e,#ffd200); }
.prog-fill.pink { background:linear-gradient(90deg,#f093fb,#f5576c); }

/* ─── Badges ────────────────────────────────────────────── */
.sbadge         { display:inline-block; padding:3px 10px; border-radius:12px; font-size:11px; font-weight:700; text-transform:capitalize; letter-spacing:.02em; }
.sbadge-success { background:#c6f6d5; color:#276749; }
.sbadge-warning { background:#fefcbf; color:#744210; }
.sbadge-danger  { background:#fed7d7; color:#742a2a; }
.sbadge-info    { background:#bee3f8; color:#1a365d; }
.sbadge-secondary{background:#e2e8f0; color:#4a5568; }

/* ─── Stat summary rows ─────────────────────────────────── */
.stat-row       { display:flex; align-items:center; justify-content:space-between; padding:13px 0; border-bottom:1px solid #f7fafc; }
.stat-row:last-child { border-bottom:none; }
.stat-row-lbl   { font-size:13px; color:#718096; display:flex; align-items:center; gap:8px; }
.stat-row-lbl i { color:#667eea; width:14px; text-align:center; }
.stat-row-val   { font-size:14px; font-weight:700; color:#1a202c; }

/* ─── Fleet mini ────────────────────────────────────────── */
.fleet-mini     { display:grid; grid-template-columns:repeat(3,1fr); gap:12px; margin-bottom:18px; }
.fmc            { border-radius:10px; padding:16px; text-align:center; }
.fmc.blue       { background:linear-gradient(135deg,#667eea,#764ba2); color:#fff; }
.fmc.green      { background:linear-gradient(135deg,#11998e,#38ef7d); color:#fff; }
.fmc.red        { background:linear-gradient(135deg,#fc4a1a,#f7b733); color:#fff; }
.fmc-val        { font-size:28px; font-weight:800; line-height:1; }
.fmc-lbl        { font-size:10px; text-transform:uppercase; letter-spacing:.1em; opacity:.85; margin-top:4px; font-weight:700; }

/* ─── Insight cards ─────────────────────────────────────── */
.insight-row    { display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:14px; margin-bottom:24px; }
.insight        { background:#fff; border-radius:12px; padding:18px; box-shadow:0 2px 12px rgba(0,0,0,.05); border:1px solid #f0f4f8; border-left:3px solid #667eea; }
.insight.green  { border-left-color:#38a169; }
.insight.orange { border-left-color:#d69e2e; }
.insight.red    { border-left-color:#e53e3e; }
.insight.purple { border-left-color:#764ba2; }
.insight-val    { font-size:22px; font-weight:800; color:#1a202c; line-height:1; margin-bottom:4px; }
.insight-lbl    { font-size:11px; color:#718096; font-weight:600; text-transform:uppercase; letter-spacing:.06em; }
.insight-sub    { font-size:12px; color:#a0aec0; margin-top:5px; }

/* ─── Empty state ───────────────────────────────────────── */
.empty-state    { text-align:center; padding:48px 20px; color:#a0aec0; }
.empty-state i  { font-size:48px; opacity:.3; display:block; margin-bottom:12px; }
.empty-state p  { font-size:13px; margin:0; }

/* ─── Responsive ────────────────────────────────────────── */
@media(max-width:1200px){ .kpi-grid{ grid-template-columns:repeat(3,1fr); } }
@media(max-width:900px) { .kpi-grid,.two-col,.three-col,.col-7-3{ grid-template-columns:repeat(2,1fr); } }
@media(max-width:600px) { .kpi-grid,.two-col,.three-col,.col-7-3,.fleet-mini,.insight-row{ grid-template-columns:1fr; } .rpt-tabs{ flex-direction:column; } }
</style>

<div class="rpt">

<!-- ══════════════════════════════════════════════════
     PAGE HEADER
     ══════════════════════════════════════════════════ -->
<div class="rpt-header">
    <div class="rpt-header-left">
        <h1><i class="fa fa-chart-bar" style="color:#667eea;margin-right:8px;"></i>Fleet Reports &amp; Analytics</h1>
        <p>Executive dashboard &amp; performance overview &middot; <strong style="color:#667eea;"><?php echo htmlspecialchars($period_label); ?></strong></p>
    </div>
    <div class="rpt-header-right">
        <span class="period-chip"><i class="fa fa-calendar-alt"></i><?php echo htmlspecialchars($period_label); ?></span>
        <a href="reports.php?report_tab=overview&period=month" class="export-btn"><i class="fa fa-sync-alt"></i> This Month</a>
        <span class="export-btn" onclick="window.print()"><i class="fa fa-print"></i> Print</span>
    </div>
</div>

<!-- Summary ribbon (always visible, all tabs) -->
<div style="display:grid;grid-template-columns:repeat(6,1fr);gap:0;background:#fff;border-radius:14px;box-shadow:0 2px 16px rgba(0,0,0,.07);margin-bottom:24px;overflow:hidden;border:1px solid #f0f4f8;">
    <?php
    $ribbon = array(
        array('#667eea','fa-calendar-check','Bookings',       rpt_fmt($total_bookings)),
        array('#11998e','fa-dollar-sign',   'Revenue',        rpt_fmt($total_revenue,2)),
        array('#e53e3e','fa-chart-line',    'Net Profit',     rpt_fmt($net_profit ?? $gross_margin, 2)),
        array('#f7971e','fa-gas-pump',      'Fuel Cost',      rpt_fmt($total_fuel_cost,2)),
        array('#764ba2','fa-tools',         'Maintenance',    rpt_fmt($wo_cost,2)),
        array('#3182ce','fa-receipt',       'Expenses',       rpt_fmt($exp_total_amount,2)),
    );
    foreach($ribbon as $i=>$rb):
    ?>
    <div style="padding:14px 16px;border-right:<?php echo $i<5?'1px solid #f0f4f8':'none'; ?>;text-align:center;">
        <div style="display:flex;align-items:center;justify-content:center;gap:6px;margin-bottom:4px;">
            <i class="fa <?php echo $rb[1]; ?>" style="color:<?php echo $rb[0]; ?>;font-size:13px;"></i>
            <span style="font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.07em;color:#a0aec0;"><?php echo $rb[2]; ?></span>
        </div>
        <div style="font-size:16px;font-weight:800;color:#1a202c;letter-spacing:-.3px;"><?php echo $rb[3]; ?></div>
    </div>
    <?php endforeach; ?>
</div>

<!-- ══════════════════════════════════════════════════
     FILTER PANEL
     ══════════════════════════════════════════════════ -->
<form method="GET" action="reports.php" id="filterForm">
<input type="hidden" name="report_tab" value="<?php echo htmlspecialchars($report_tab); ?>">
<div class="rpt-filter-panel">
    <div class="rpt-filter-head" onclick="toggleFilters()">
        <h3><i class="fa fa-sliders-h"></i> Filters &amp; Date Range
            <?php
            $active_count = 0;
            if ($fk_vehicle) $active_count++; if ($fk_driver) $active_count++; if ($fk_customer) $active_count++;
            if ($fk_vendor) $active_count++; if ($filter_status) $active_count++; if ($filter_fuel_src) $active_count++;
            if ($filter_wo_priority) $active_count++; if ($filter_vehicle_type) $active_count++;
            if (!empty($filter_min_revenue)||!empty($filter_max_revenue)) $active_count++;
            if ($active_count > 0) echo ' <span class="sbadge sbadge-info">'.$active_count.' active</span>';
            ?>
        </h3>
        <span class="rpt-filter-toggle" id="filterToggleBtn"><i class="fa fa-chevron-up" id="filterChevron"></i> <span id="filterToggleText">Collapse</span></span>
    </div>
    <div class="rpt-filter-body" id="filterBody">
        <!-- Row 1: Period & dates -->
        <div class="rft">
            <label><i class="fa fa-clock"></i> Time Period</label>
            <select name="period" onchange="handlePeriodChange(this)">
                <option value="week"      <?php echo $period=='week'      ?'selected':''; ?>>Last 7 Days</option>
                <option value="month"     <?php echo $period=='month'     ?'selected':''; ?>>This Month</option>
                <option value="last_month"<?php echo $period=='last_month'?'selected':''; ?>>Last Month</option>
                <option value="quarter"   <?php echo $period=='quarter'   ?'selected':''; ?>>Last 90 Days</option>
                <option value="year"      <?php echo $period=='year'      ?'selected':''; ?>>This Year</option>
                <option value="last_year" <?php echo $period=='last_year' ?'selected':''; ?>>Last Year</option>
                <option value="custom"    <?php echo $period=='custom'    ?'selected':''; ?>>Custom Range</option>
            </select>
        </div>
        <div class="rft" id="customDateRow" style="<?php echo $period!='custom'?'opacity:.4;pointer-events:none;':''; ?>">
            <label><i class="fa fa-calendar-check"></i> Date Range</label>
            <div class="rft-range">
                <input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>" placeholder="From">
                <span>→</span>
                <input type="date" name="date_to"   value="<?php echo htmlspecialchars($date_to);   ?>" placeholder="To">
            </div>
        </div>
        <!-- Row 2: Entity filters -->
        <div class="rft">
            <label><i class="fa fa-car"></i> Vehicle</label>
            <select name="fk_vehicle">
                <?php foreach ($vehicles_list as $vid=>$vl): ?>
                <option value="<?php echo (int)$vid; ?>" <?php echo $fk_vehicle==(int)$vid?'selected':''; ?>><?php echo htmlspecialchars($vl); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="rft">
            <label><i class="fa fa-user-tie"></i> Driver</label>
            <select name="fk_driver">
                <?php foreach ($drivers_list as $did=>$dl): ?>
                <option value="<?php echo (int)$did; ?>" <?php echo $fk_driver==(int)$did?'selected':''; ?>><?php echo htmlspecialchars($dl); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="rft">
            <label><i class="fa fa-users"></i> Customer</label>
            <select name="fk_customer">
                <?php foreach ($customers_list as $cid=>$cl): ?>
                <option value="<?php echo (int)$cid; ?>" <?php echo $fk_customer==(int)$cid?'selected':''; ?>><?php echo htmlspecialchars($cl); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="rft">
            <label><i class="fa fa-store"></i> Vendor</label>
            <select name="fk_vendor">
                <?php foreach ($vendors_list as $vid=>$vl): ?>
                <option value="<?php echo (int)$vid; ?>" <?php echo $fk_vendor==(int)$vid?'selected':''; ?>><?php echo htmlspecialchars($vl); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <!-- Row 3: Operational filters -->
        <div class="rft">
            <label><i class="fa fa-tag"></i> Booking Status</label>
            <select name="filter_status">
                <?php foreach ($booking_statuses as $sv=>$sl): ?>
                <option value="<?php echo htmlspecialchars($sv); ?>" <?php echo $filter_status===$sv?'selected':''; ?>><?php echo htmlspecialchars($sl); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="rft">
            <label><i class="fa fa-gas-pump"></i> Fuel Source</label>
            <select name="filter_fuel_src">
                <?php foreach ($fuel_sources as $sv=>$sl): ?>
                <option value="<?php echo htmlspecialchars($sv); ?>" <?php echo $filter_fuel_src===$sv?'selected':''; ?>><?php echo htmlspecialchars($sl); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="rft">
            <label><i class="fa fa-exclamation-circle"></i> WO Priority</label>
            <select name="filter_wo_priority">
                <option value="">— All Priorities —</option>
                <option value="Critical" <?php echo $filter_wo_priority=='Critical'?'selected':''; ?>>Critical</option>
                <option value="High"     <?php echo $filter_wo_priority=='High'    ?'selected':''; ?>>High</option>
                <option value="Medium"   <?php echo $filter_wo_priority=='Medium'  ?'selected':''; ?>>Medium</option>
                <option value="Low"      <?php echo $filter_wo_priority=='Low'     ?'selected':''; ?>>Low</option>
            </select>
        </div>
        <div class="rft">
            <label><i class="fa fa-car-side"></i> Vehicle Type</label>
            <select name="filter_vehicle_type">
                <?php foreach ($vehicle_types as $sv=>$sl): ?>
                <option value="<?php echo htmlspecialchars($sv); ?>" <?php echo $filter_vehicle_type===$sv?'selected':''; ?>><?php echo htmlspecialchars($sl); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="rft">
            <label><i class="fa fa-dollar-sign"></i> Revenue Range</label>
            <div class="rft-range">
                <input type="number" name="filter_min_revenue" placeholder="Min" value="<?php echo htmlspecialchars($filter_min_revenue); ?>" step="0.01" min="0">
                <span>—</span>
                <input type="number" name="filter_max_revenue" placeholder="Max" value="<?php echo htmlspecialchars($filter_max_revenue); ?>" step="0.01" min="0">
            </div>
        </div>
    </div>
    <div class="rpt-filter-actions">
        <button type="submit" class="btn-apply"><i class="fa fa-filter"></i> Apply Filters</button>
        <a href="reports.php" class="btn-reset"><i class="fa fa-times"></i> Clear All</a>
        <?php if ($active_count > 0): ?>
        <div class="active-filters">
            <?php if ($fk_vehicle && isset($vehicles_list[$fk_vehicle])): ?><span class="af-chip"><i class="fa fa-car"></i><?php echo htmlspecialchars(explode(' · ',$vehicles_list[$fk_vehicle])[0]); ?></span><?php endif; ?>
            <?php if ($fk_driver  && isset($drivers_list[$fk_driver])): ?><span class="af-chip"><i class="fa fa-user"></i><?php echo htmlspecialchars(explode(' · ',$drivers_list[$fk_driver])[0]); ?></span><?php endif; ?>
            <?php if ($fk_customer && isset($customers_list[$fk_customer])): ?><span class="af-chip"><i class="fa fa-users"></i><?php echo htmlspecialchars(explode(' · ',$customers_list[$fk_customer])[0]); ?></span><?php endif; ?>
            <?php if ($filter_status): ?><span class="af-chip"><i class="fa fa-tag"></i><?php echo htmlspecialchars(ucfirst($filter_status)); ?></span><?php endif; ?>
            <?php if ($filter_wo_priority): ?><span class="af-chip"><i class="fa fa-flag"></i><?php echo htmlspecialchars($filter_wo_priority); ?></span><?php endif; ?>
            <?php if ($filter_vehicle_type): ?><span class="af-chip"><i class="fa fa-car-side"></i><?php echo htmlspecialchars($filter_vehicle_type); ?></span><?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>
</form>

<!-- ══════════════════════════════════════════════════
     TABS
     ══════════════════════════════════════════════════ -->
<div class="rpt-tabs">
    <?php
    $tabs = array(
        'overview'    => array('fa fa-tachometer-alt',    'Overview'),
        'bookings'    => array('fa fa-calendar-check',    'Bookings'),
        'fuel'        => array('fa fa-gas-pump',          'Fuel'),
        'maintenance' => array('fa fa-tools',             'Maintenance'),
        'expenses'    => array('fa fa-receipt',           'Expenses'),
        'fleet'       => array('fa fa-car',               'Fleet'),
    );
    foreach ($tabs as $tab_key => $tab_info):
        $params = rpt_params('&report_tab='.$tab_key.'&period='.urlencode($period).'&date_from='.urlencode($date_from).'&date_to='.urlencode($date_to));
    ?>
    <a href="reports.php?<?php echo ltrim($params,'&'); ?>" class="rpt-tab <?php echo $report_tab==$tab_key?'active':''; ?>">
        <i class="<?php echo $tab_info[0]; ?>"></i><?php echo $tab_info[1]; ?>
    </a>
    <?php endforeach; ?>
</div>

<?php // ══════════════════════════════════════════════
// TAB: OVERVIEW
// ════════════════════════════════════════════════ ?>
<?php if ($report_tab == 'overview'): ?>

<?php
$total_all_cost = $total_cost + $exp_total_amount + $total_fuel_cost + $wo_cost;
?>

<!-- ═══ ROW 1: PRIMARY FINANCIAL KPIs ═══ -->
<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:18px;margin-bottom:18px;">

    <!-- Revenue — biggest card feel -->
    <div class="kpi-card kpi-revenue" style="grid-column:span 1;">
        <div class="kpi-shine"></div>
        <div class="kpi-icon"><i class="fa fa-dollar-sign"></i></div>
        <div class="kpi-body">
            <div class="kpi-lbl" style="margin-bottom:8px;">Total Revenue</div>
            <div class="kpi-val sm"><?php echo rpt_fmt($total_revenue,2); ?></div>
            <div class="kpi-sub">
                <?php if ($rev_trend_pct !== null): ?>
                <span class="kpi-trend <?php echo $rev_trend_pct>=0?'up':'down'; ?>">
                    <i class="fa fa-arrow-<?php echo $rev_trend_pct>=0?'up':'down'; ?>"></i><?php echo abs($rev_trend_pct); ?>% vs prev period
                </span>
                <?php endif; ?>
            </div>
            <div style="margin-top:14px;padding-top:12px;border-top:1px solid rgba(255,255,255,.2);display:flex;gap:16px;">
                <div><div style="font-size:11px;opacity:.75;margin-bottom:2px;">Avg / Trip</div><div style="font-size:14px;font-weight:700;"><?php echo rpt_fmt($avg_revenue_trip,2); ?></div></div>
                <div><div style="font-size:11px;opacity:.75;margin-bottom:2px;">Bookings</div><div style="font-size:14px;font-weight:700;"><?php echo rpt_fmt($total_bookings); ?></div></div>
            </div>
        </div>
    </div>

    <!-- Net Profit -->
    <div class="kpi-card" style="background:linear-gradient(135deg,<?php echo $net_profit>=0?'#0f9b8e 0%,#00f2fe':'#e53e3e 0%,#f093fb'; ?> 100%);">
        <div class="kpi-shine"></div>
        <div class="kpi-icon"><i class="fa fa-chart-line"></i></div>
        <div class="kpi-body">
            <div class="kpi-lbl" style="margin-bottom:8px;">Net Profit</div>
            <div class="kpi-val sm"><?php echo rpt_fmt($net_profit,2); ?></div>
            <div class="kpi-sub"><span>Net margin: <?php echo $net_margin_pct; ?>%</span></div>
            <div style="margin-top:14px;padding-top:12px;border-top:1px solid rgba(255,255,255,.2);display:flex;gap:16px;">
                <div><div style="font-size:11px;opacity:.75;margin-bottom:2px;">Gross Margin</div><div style="font-size:14px;font-weight:700;"><?php echo $margin_pct; ?>%</div></div>
                <div><div style="font-size:11px;opacity:.75;margin-bottom:2px;">Gross Profit</div><div style="font-size:14px;font-weight:700;"><?php echo rpt_fmt($gross_margin,2); ?></div></div>
            </div>
        </div>
    </div>

    <!-- Total Cost (booking cost) -->
    <div class="kpi-card kpi-cost">
        <div class="kpi-shine"></div>
        <div class="kpi-icon"><i class="fa fa-file-invoice-dollar"></i></div>
        <div class="kpi-body">
            <div class="kpi-lbl" style="margin-bottom:8px;">Booking Cost</div>
            <div class="kpi-val sm"><?php echo rpt_fmt($total_cost,2); ?></div>
            <div class="kpi-sub"><span><?php echo $total_revenue>0?round($total_cost/$total_revenue*100):'0'; ?>% of revenue</span></div>
            <div style="margin-top:14px;padding-top:12px;border-top:1px solid rgba(255,255,255,.2);display:flex;gap:16px;">
                <div><div style="font-size:11px;opacity:.75;margin-bottom:2px;">Fuel Cost</div><div style="font-size:14px;font-weight:700;"><?php echo rpt_fmt($total_fuel_cost,2); ?></div></div>
                <div><div style="font-size:11px;opacity:.75;margin-bottom:2px;">Expenses</div><div style="font-size:14px;font-weight:700;"><?php echo rpt_fmt($exp_total_amount,2); ?></div></div>
            </div>
        </div>
    </div>

    <!-- Total Expenses -->
    <div class="kpi-card" style="background:linear-gradient(135deg,#f7971e 0%,#f5576c 100%);">
        <div class="kpi-shine"></div>
        <div class="kpi-icon"><i class="fa fa-receipt"></i></div>
        <div class="kpi-body">
            <div class="kpi-lbl" style="margin-bottom:8px;">Total Expenses</div>
            <div class="kpi-val sm"><?php echo rpt_fmt($exp_total_amount,2); ?></div>
            <div class="kpi-sub">
                <?php if ($exp_trend_pct !== null): ?>
                <span class="kpi-trend <?php echo $exp_trend_pct<=0?'up':'down'; ?>">
                    <i class="fa fa-arrow-<?php echo $exp_trend_pct<=0?'down':'up'; ?>"></i><?php echo abs($exp_trend_pct); ?>% vs prev
                </span>
                <?php endif; ?>
                <span><?php echo $exp_vs_rev_pct; ?>% of revenue</span>
            </div>
            <div style="margin-top:14px;padding-top:12px;border-top:1px solid rgba(255,255,255,.2);display:flex;gap:16px;">
                <div><div style="font-size:11px;opacity:.75;margin-bottom:2px;">Entries</div><div style="font-size:14px;font-weight:700;"><?php echo $exp_total_count; ?></div></div>
                <div><div style="font-size:11px;opacity:.75;margin-bottom:2px;">Avg</div><div style="font-size:14px;font-weight:700;"><?php echo rpt_fmt($exp_avg_amount,2); ?></div></div>
            </div>
        </div>
    </div>
</div>

<!-- ═══ ROW 2: OPERATIONAL KPIs ═══ -->
<div style="display:grid;grid-template-columns:repeat(5,1fr);gap:14px;margin-bottom:24px;">

    <div class="kpi-card kpi-bookings" style="padding:18px;">
        <div class="kpi-shine"></div>
        <div class="kpi-icon" style="font-size:38px;"><i class="fa fa-calendar-check"></i></div>
        <div class="kpi-body">
            <div class="kpi-val" style="font-size:26px;"><?php echo rpt_fmt($total_bookings); ?></div>
            <div class="kpi-lbl">Bookings</div>
            <div class="kpi-sub" style="margin-top:6px;">
                <?php if ($bkg_trend_pct !== null): ?>
                <span class="kpi-trend <?php echo $bkg_trend_pct>=0?'up':'down'; ?>"><i class="fa fa-arrow-<?php echo $bkg_trend_pct>=0?'up':'down'; ?>"></i><?php echo abs($bkg_trend_pct); ?>%</span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="kpi-card kpi-distance" style="padding:18px;">
        <div class="kpi-shine"></div>
        <div class="kpi-icon" style="font-size:38px;"><i class="fa fa-road"></i></div>
        <div class="kpi-body">
            <div class="kpi-val" style="font-size:26px;"><?php echo rpt_fmt($total_distance); ?></div>
            <div class="kpi-lbl">km Driven</div>
            <div class="kpi-sub" style="margin-top:6px;"><span><?php echo rpt_fmt($avg_distance_trip,1); ?> avg/trip</span></div>
        </div>
    </div>

    <div class="kpi-card kpi-fuel" style="padding:18px;">
        <div class="kpi-shine"></div>
        <div class="kpi-icon" style="font-size:38px;"><i class="fa fa-gas-pump"></i></div>
        <div class="kpi-body">
            <div class="kpi-val" style="font-size:26px;"><?php echo rpt_fmt($total_fuel_qty,0); ?> <span style="font-size:14px;">L</span></div>
            <div class="kpi-lbl">Fuel Used</div>
            <div class="kpi-sub" style="margin-top:6px;"><span><?php echo $km_per_litre>0?rpt_fmt($km_per_litre,1).' km/L':'—'; ?></span></div>
        </div>
    </div>

    <div class="kpi-card kpi-maint" style="padding:18px;">
        <div class="kpi-shine"></div>
        <div class="kpi-icon" style="font-size:38px;"><i class="fa fa-tools"></i></div>
        <div class="kpi-body">
            <div class="kpi-val" style="font-size:26px;"><?php echo $wo_pending; ?></div>
            <div class="kpi-lbl">Open Work Orders</div>
            <div class="kpi-sub" style="margin-top:6px;"><span><?php echo $wo_comp_rate; ?>% done</span></div>
        </div>
    </div>

    <div class="kpi-card kpi-fleet" style="padding:18px;">
        <div class="kpi-shine"></div>
        <div class="kpi-icon" style="font-size:38px;"><i class="fa fa-car"></i></div>
        <div class="kpi-body">
            <div class="kpi-val" style="font-size:26px;"><?php echo $fleet_active; ?><span style="font-size:14px;opacity:.7;"> / <?php echo $fleet_total; ?></span></div>
            <div class="kpi-lbl">Fleet In Service</div>
            <div class="kpi-sub" style="margin-top:6px;"><span><?php echo $fleet_util; ?>% available</span></div>
        </div>
    </div>
</div>

<!-- ═══ ROW 3: ALERTS / STATUS STRIP ═══ -->
<?php
$alerts = array();
if ($wo_pending > 0)
    $alerts[] = array('danger', 'fa-exclamation-triangle', $wo_pending.' work order'.($wo_pending>1?'s':'').' pending action');
if ($fleet_inactive > 0)
    $alerts[] = array('warning', 'fa-car', $fleet_inactive.' vehicle'.($fleet_inactive>1?'s':'').' out of service');
if ($margin_pct < 10 && $total_revenue > 0)
    $alerts[] = array('danger', 'fa-chart-line', 'Gross margin critically low at '.$margin_pct.'%');
if ($exp_vs_rev_pct > 50 && $total_revenue > 0)
    $alerts[] = array('warning', 'fa-receipt', 'Expenses at '.$exp_vs_rev_pct.'% of revenue — review spending');
if ($km_per_litre > 0 && $km_per_litre < 5)
    $alerts[] = array('warning', 'fa-gas-pump', 'Low fuel efficiency: '.rpt_fmt($km_per_litre,1).' km/L');
if ($fleet_util >= 90)
    $alerts[] = array('info', 'fa-check-circle', 'Fleet utilisation excellent at '.$fleet_util.'%');
if ($margin_pct >= 30 && $total_revenue > 0)
    $alerts[] = array('info', 'fa-thumbs-up', 'Strong margin: '.$margin_pct.'% gross margin this period');
?>
<?php if (!empty($alerts)): ?>
<div style="display:flex;flex-direction:column;gap:8px;margin-bottom:24px;">
<?php foreach($alerts as $al):
    $colors = array('danger'=>array('#fff5f5','#feb2b2','#e53e3e'),'warning'=>array('#fffbeb','#fbd38d','#d69e2e'),'info'=>array('#ebf8ff','#bee3f8','#3182ce'));
    $c = isset($colors[$al[0]])?$colors[$al[0]]:$colors['info'];
?>
<div style="display:flex;align-items:center;gap:12px;background:<?php echo $c[0]; ?>;border:1px solid <?php echo $c[1]; ?>;border-left:4px solid <?php echo $c[2]; ?>;border-radius:10px;padding:12px 18px;">
    <i class="fa <?php echo $al[1]; ?>" style="color:<?php echo $c[2]; ?>;font-size:15px;flex-shrink:0;"></i>
    <span style="font-size:13px;font-weight:600;color:#2d3748;"><?php echo htmlspecialchars($al[2]); ?></span>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ═══ ROW 4: CHARTS — Trend + Cost Breakdown ═══ -->
<div style="display:grid;grid-template-columns:2fr 1fr;gap:20px;margin-bottom:20px;">

    <!-- Main trend chart -->
    <div class="card">
        <h3 class="card-title"><i class="fa fa-chart-area"></i> Revenue, Cost &amp; Profit — Last 6 Months</h3>
        <div class="chart-wrap tall"><canvas id="rptTrend"></canvas></div>
    </div>

    <!-- Cost breakdown donut -->
    <div class="card">
        <h3 class="card-title"><i class="fa fa-chart-pie"></i> Cost Breakdown</h3>
        <?php
        $cb_labels = array('Booking Cost','Fuel','Expenses','Maintenance');
        $cb_data   = array($total_cost, $total_fuel_cost, $exp_total_amount, $wo_cost);
        $cb_total  = array_sum($cb_data); if ($cb_total <= 0) $cb_total = 1;
        ?>
        <div class="chart-wrap sm"><canvas id="rptCostBreak"></canvas></div>
        <div style="margin-top:14px;">
        <?php
        $cb_colors = array('#667eea','#f7971e','#f5576c','#fc4a1a');
        foreach ($cb_labels as $i=>$cl):
            $pct = round($cb_data[$i]/$cb_total*100);
        ?>
        <div style="display:flex;align-items:center;justify-content:space-between;padding:7px 0;border-bottom:1px solid #f7fafc;">
            <div style="display:flex;align-items:center;gap:8px;">
                <span style="width:10px;height:10px;border-radius:50%;background:<?php echo $cb_colors[$i]; ?>;flex-shrink:0;display:inline-block;"></span>
                <span style="font-size:12px;color:#4a5568;font-weight:500;"><?php echo $cl; ?></span>
            </div>
            <div style="display:flex;align-items:center;gap:10px;">
                <span style="font-size:12px;font-weight:700;color:#1a202c;"><?php echo rpt_fmt($cb_data[$i],2); ?></span>
                <span style="font-size:11px;background:#f0f4f8;color:#718096;padding:2px 7px;border-radius:8px;font-weight:600;"><?php echo $pct; ?>%</span>
            </div>
        </div>
        <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- ═══ ROW 5: TOP PERFORMERS + QUICK STATS ═══ -->
<div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:20px;margin-bottom:20px;">

    <!-- Top Vehicles -->
    <div class="card">
        <h3 class="card-title"><i class="fa fa-car"></i> Top Vehicles</h3>
        <?php if (empty($top_vehicles)): ?>
        <div class="empty-state"><i class="fa fa-car"></i><p>No data for this period</p></div>
        <?php else: $mx=max(1,max(array_column($top_vehicles,'bookings'))); ?>
        <?php foreach(array_slice($top_vehicles,0,5) as $idx=>$v):
            $pct=round(($v->bookings/$mx)*100);
            $vname=htmlspecialchars(trim(($v->maker?:'').($v->model?' '.$v->model:'')) ?: ($v->ref?:'—'));
        ?>
        <div style="margin-bottom:14px;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:5px;">
                <div>
                    <span style="font-size:13px;font-weight:600;color:#1a202c;"><?php echo $vname; ?></span>
                    <span style="font-size:11px;color:#a0aec0;margin-left:6px;"><?php echo htmlspecialchars($v->ref?:''); ?></span>
                </div>
                <span style="font-size:12px;font-weight:700;color:#38a169;"><?php echo rpt_fmt($v->revenue,2); ?></span>
            </div>
            <div style="display:flex;align-items:center;gap:8px;">
                <div class="prog" style="flex:1;"><div class="prog-fill blue" style="width:<?php echo $pct; ?>%"></div></div>
                <span style="font-size:11px;color:#718096;width:40px;text-align:right;"><?php echo $v->bookings; ?> trips</span>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Top Drivers -->
    <div class="card">
        <h3 class="card-title"><i class="fa fa-user-tie"></i> Top Drivers</h3>
        <?php if (empty($top_drivers)): ?>
        <div class="empty-state"><i class="fa fa-users"></i><p>No data</p></div>
        <?php else: $mx=max(1,max(array_column($top_drivers,'bookings'))); ?>
        <?php foreach(array_slice($top_drivers,0,5) as $idx=>$d):
            $pct=round(($d->bookings/$mx)*100);
            $dname=htmlspecialchars(trim($d->firstname.' '.$d->lastname)?:($d->ref?:'—'));
        ?>
        <div style="margin-bottom:14px;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:5px;">
                <div>
                    <span style="font-size:13px;font-weight:600;color:#1a202c;"><?php echo $dname; ?></span>
                    <span style="font-size:11px;color:#a0aec0;margin-left:6px;"><?php echo htmlspecialchars($d->ref?:''); ?></span>
                </div>
                <span style="font-size:12px;font-weight:700;color:#3182ce;"><?php echo rpt_fmt($d->distance); ?> km</span>
            </div>
            <div style="display:flex;align-items:center;gap:8px;">
                <div class="prog" style="flex:1;"><div class="prog-fill pink" style="width:<?php echo $pct; ?>%"></div></div>
                <span style="font-size:11px;color:#718096;width:40px;text-align:right;"><?php echo $d->bookings; ?> trips</span>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Quick stats panel -->
    <div class="card">
        <h3 class="card-title"><i class="fa fa-tachometer-alt"></i> Key Metrics</h3>
        <?php
        $metrics = array(
            array('fa-road',       '#667eea', 'Avg Distance / Trip',    rpt_fmt($avg_distance_trip,1).' km'),
            array('fa-dollar-sign','#38a169', 'Avg Revenue / Trip',     rpt_fmt($avg_revenue_trip,2)),
            array('fa-tint',       '#f7971e', 'Fuel Efficiency',        $km_per_litre>0?rpt_fmt($km_per_litre,2).' km/L':'—'),
            array('fa-dollar-sign','#e53e3e', 'Avg Cost / Litre',       rpt_fmt($avg_cost_per_litre,3)),
            array('fa-tools',      '#fc4a1a', 'Avg Maint. Cost / WO',  rpt_fmt($wo_avg_cost,2)),
            array('fa-users',      '#764ba2', 'Active Drivers',         $total_drivers),
            array('fa-address-book','#11998e','Customers',              $total_customers),
            array('fa-clipboard-check','#4facfe','Inspections',         $total_inspections),
        );
        foreach($metrics as $m):
        ?>
        <div style="display:flex;align-items:center;justify-content:space-between;padding:9px 0;border-bottom:1px solid #f7fafc;">
            <div style="display:flex;align-items:center;gap:9px;">
                <span style="width:28px;height:28px;border-radius:7px;background:<?php echo $m[1]; ?>1a;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <i class="fa <?php echo $m[0]; ?>" style="color:<?php echo $m[1]; ?>;font-size:12px;"></i>
                </span>
                <span style="font-size:12px;color:#718096;"><?php echo $m[2]; ?></span>
            </div>
            <span style="font-size:13px;font-weight:700;color:#1a202c;"><?php echo $m[3]; ?></span>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- ═══ ROW 6: DOW Activity + Top Customers ═══ -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px;">
    <div class="card">
        <h3 class="card-title"><i class="fa fa-calendar-week"></i> Activity by Day of Week</h3>
        <div class="chart-wrap"><canvas id="rptDow"></canvas></div>
    </div>
    <div class="card">
        <h3 class="card-title"><i class="fa fa-users"></i> Top Customers by Revenue</h3>
        <?php if (empty($top_customers)): ?>
        <div class="empty-state"><i class="fa fa-users"></i><p>No data for this period</p></div>
        <?php else: $mx=max(1,max(array_column($top_customers,'revenue'))); ?>
        <?php foreach(array_slice($top_customers,0,5) as $idx=>$c):
            $pct=round(($c->revenue/$mx)*100);
            $cname=!empty($c->company_name)?$c->company_name:trim($c->firstname.' '.$c->lastname);
        ?>
        <div style="margin-bottom:14px;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:5px;">
                <div>
                    <span style="font-size:13px;font-weight:600;color:#1a202c;"><?php echo htmlspecialchars($cname?:'—'); ?></span>
                    <span style="font-size:11px;color:#a0aec0;margin-left:6px;"><?php echo $c->bookings; ?> trips</span>
                </div>
                <span style="font-size:12px;font-weight:700;color:#38a169;"><?php echo rpt_fmt($c->revenue,2); ?></span>
            </div>
            <div class="prog"><div class="prog-fill green" style="width:<?php echo $pct; ?>%"></div></div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<?php // ══════════════════════════════════════════
// TAB: BOOKINGS
// ═══════════════════════════════════════════ ?>
<?php elseif ($report_tab == 'bookings'): ?>

<div class="kpi-grid">
    <div class="kpi-card kpi-bookings"><div class="kpi-shine"></div><div class="kpi-icon"><i class="fa fa-calendar-check"></i></div><div class="kpi-body"><div class="kpi-val"><?php echo rpt_fmt($total_bookings); ?></div><div class="kpi-lbl">Total Bookings</div><div class="kpi-sub"><span>Avg <?php echo rpt_fmt($avg_revenue_trip,2); ?> revenue</span></div></div></div>
    <div class="kpi-card kpi-revenue"><div class="kpi-shine"></div><div class="kpi-icon"><i class="fa fa-dollar-sign"></i></div><div class="kpi-body"><div class="kpi-val sm"><?php echo rpt_fmt($total_revenue,2); ?></div><div class="kpi-lbl">Revenue</div><div class="kpi-sub"><span>Margin <?php echo $margin_pct; ?>%</span></div></div></div>
    <div class="kpi-card kpi-cost"><div class="kpi-shine"></div><div class="kpi-icon"><i class="fa fa-receipt"></i></div><div class="kpi-body"><div class="kpi-val sm"><?php echo rpt_fmt($total_cost,2); ?></div><div class="kpi-lbl">Total Cost</div><div class="kpi-sub"><span>Profit <?php echo rpt_fmt($gross_margin,2); ?></span></div></div></div>
    <div class="kpi-card kpi-distance"><div class="kpi-shine"></div><div class="kpi-icon"><i class="fa fa-road"></i></div><div class="kpi-body"><div class="kpi-val"><?php echo rpt_fmt($total_distance); ?></div><div class="kpi-lbl">km Driven</div><div class="kpi-sub"><span><?php echo rpt_fmt($avg_distance_trip,1); ?> avg / trip</span></div></div></div>
</div>

<div class="two-col" style="margin-bottom:20px;">
    <div class="card">
        <h3 class="card-title"><i class="fa fa-chart-bar"></i> Monthly Trend (6 months)</h3>
        <div class="chart-wrap tall"><canvas id="rptTrend"></canvas></div>
    </div>
    <div class="card">
        <h3 class="card-title"><i class="fa fa-chart-pie"></i> Bookings by Status</h3>
        <div class="chart-wrap tall"><canvas id="rptStatus"></canvas></div>
        <div style="margin-top:16px;">
        <table class="rpt-table">
            <thead><tr><th>Status</th><th style="text-align:right">Bookings</th><th style="text-align:right">Revenue</th><th style="text-align:right">Share</th></tr></thead>
            <tbody>
            <?php $tot_b=max(1,$total_bookings); foreach($status_rows as $s): ?>
            <tr><td><?php rpt_badge($s->lbl); ?></td><td style="text-align:right" class="num"><?php echo rpt_fmt($s->cnt); ?></td><td style="text-align:right" class="rev"><?php echo rpt_fmt($s->rev,2); ?></td><td style="text-align:right" class="num"><?php echo round($s->cnt/$tot_b*100); ?>%</td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>

<div class="two-col" style="margin-bottom:20px;">
    <div class="card">
        <h3 class="card-title"><i class="fa fa-user-tie"></i> Top Drivers</h3>
        <?php if(empty($top_drivers)): ?><div class="empty-state"><i class="fa fa-users"></i><p>No data</p></div>
        <?php else: $mx=max(1,max(array_column($top_drivers,'bookings'))); ?>
        <table class="rpt-table">
            <thead><tr><th>#</th><th>Driver</th><th style="text-align:right">Trips</th><th style="text-align:right">km</th><th style="text-align:right">Revenue</th><th style="width:90px">Share</th></tr></thead>
            <tbody>
            <?php foreach($top_drivers as $idx=>$d): $pct=round(($d->bookings/$mx)*100); ?>
            <tr>
                <td class="rank"><?php echo $idx+1; ?></td>
                <td class="name-cell"><div class="main"><?php echo htmlspecialchars(trim($d->firstname.' '.$d->lastname)?:($d->ref?:'—')); ?></div><div class="sub"><?php echo htmlspecialchars($d->ref?:'—'); ?></div></td>
                <td style="text-align:right" class="num"><?php echo rpt_fmt($d->bookings); ?></td>
                <td style="text-align:right" class="num"><?php echo rpt_fmt($d->distance); ?></td>
                <td style="text-align:right" class="rev"><?php echo rpt_fmt($d->revenue,2); ?></td>
                <td><div class="prog"><div class="prog-fill pink" style="width:<?php echo $pct; ?>%"></div></div></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
    <div class="card">
        <h3 class="card-title"><i class="fa fa-calendar-week"></i> Activity by Day of Week</h3>
        <div class="chart-wrap"><canvas id="rptDow"></canvas></div>
    </div>
</div>

<?php // ══════════════════════════════════════════
// TAB: FUEL
// ═══════════════════════════════════════════ ?>
<?php elseif ($report_tab == 'fuel'): ?>

<div class="kpi-grid">
    <div class="kpi-card kpi-fuel"><div class="kpi-shine"></div><div class="kpi-icon"><i class="fa fa-gas-pump"></i></div><div class="kpi-body"><div class="kpi-val"><?php echo rpt_fmt($total_fuel_qty,1); ?> L</div><div class="kpi-lbl">Total Consumed</div><div class="kpi-sub"><span>Cost <?php echo rpt_fmt($total_fuel_cost,2); ?></span></div></div></div>
    <div class="kpi-card kpi-revenue"><div class="kpi-shine"></div><div class="kpi-icon"><i class="fa fa-dollar-sign"></i></div><div class="kpi-body"><div class="kpi-val sm"><?php echo rpt_fmt($total_fuel_cost,2); ?></div><div class="kpi-lbl">Fuel Spend</div><div class="kpi-sub"><span><?php echo rpt_fmt($avg_cost_per_litre,3); ?> / litre avg</span></div></div></div>
    <div class="kpi-card kpi-distance"><div class="kpi-shine"></div><div class="kpi-icon"><i class="fa fa-road"></i></div><div class="kpi-body"><div class="kpi-val"><?php echo $km_per_litre>0?rpt_fmt($km_per_litre,2):'—'; ?></div><div class="kpi-lbl">km / Litre</div><div class="kpi-sub"><span><?php echo $total_distance>0?rpt_fmt($total_distance).' km driven':'—'; ?></span></div></div></div>
    <div class="kpi-card kpi-margin"><div class="kpi-shine"></div><div class="kpi-icon"><i class="fa fa-tachometer-alt"></i></div><div class="kpi-body"><div class="kpi-val sm"><?php echo rpt_fmt($avg_cost_per_litre,3); ?></div><div class="kpi-lbl">Avg Cost / Litre</div></div></div>
</div>

<div class="two-col" style="margin-bottom:20px;">
    <div class="card">
        <h3 class="card-title"><i class="fa fa-chart-line"></i> Fuel Trend (6 months)</h3>
        <div class="chart-wrap tall"><canvas id="rptFuelTrend"></canvas></div>
    </div>
    <div class="card">
        <h3 class="card-title"><i class="fa fa-chart-pie"></i> Consumption by Fuel Source</h3>
        <div class="chart-wrap sm"><canvas id="rptFuelSource"></canvas></div>
        <table class="rpt-table" style="margin-top:14px;">
            <thead><tr><th>Source</th><th style="text-align:right">Entries</th><th style="text-align:right">Litres</th><th style="text-align:right">Cost</th></tr></thead>
            <tbody>
            <?php foreach($fuel_by_source as $fs): ?>
            <tr><td class="num"><?php echo htmlspecialchars($fs->lbl); ?></td><td style="text-align:right" class="num"><?php echo rpt_fmt($fs->cnt); ?></td><td style="text-align:right" class="num"><?php echo rpt_fmt($fs->qty,1); ?></td><td style="text-align:right" class="cost"><?php echo rpt_fmt($fs->cost,2); ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <h3 class="card-title"><i class="fa fa-car"></i> Fuel Consumption by Vehicle</h3>
    <?php if(empty($fuel_by_vehicle)): ?><div class="empty-state"><i class="fa fa-gas-pump"></i><p>No fuel data for this period</p></div>
    <?php else: $mx=max(1,max(array_map(function($r){return (float)$r->total_qty;},$fuel_by_vehicle))); ?>
    <table class="rpt-table">
        <thead><tr><th>#</th><th>Vehicle</th><th style="text-align:right">Entries</th><th style="text-align:right">Litres</th><th style="text-align:right">Cost</th><th style="width:120px">Share</th></tr></thead>
        <tbody>
        <?php foreach($fuel_by_vehicle as $idx=>$fv): $pct=round(($fv->total_qty/$mx)*100); ?>
        <tr>
            <td class="rank"><?php echo $idx+1; ?></td>
            <td class="name-cell"><div class="main"><?php echo htmlspecialchars(trim(($fv->maker?:'').($fv->model?' '.$fv->model:'')) ?: $fv->ref); ?></div><div class="sub"><?php echo htmlspecialchars($fv->ref); ?></div></td>
            <td style="text-align:right" class="num"><?php echo rpt_fmt($fv->entries); ?></td>
            <td style="text-align:right" class="num"><?php echo rpt_fmt($fv->total_qty,1); ?> L</td>
            <td style="text-align:right" class="cost"><?php echo rpt_fmt($fv->total_cost,2); ?></td>
            <td><div class="prog"><div class="prog-fill orange" style="width:<?php echo $pct; ?>%"></div></div><div style="font-size:10px;color:#a0aec0;margin-top:2px;"><?php echo $pct; ?>%</div></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<?php // ══════════════════════════════════════════
// TAB: MAINTENANCE
// ═══════════════════════════════════════════ ?>
<?php elseif ($report_tab == 'maintenance'): ?>

<div class="kpi-grid">
    <div class="kpi-card kpi-maint"><div class="kpi-shine"></div><div class="kpi-icon"><i class="fa fa-tools"></i></div><div class="kpi-body"><div class="kpi-val"><?php echo $wo_total; ?></div><div class="kpi-lbl">Total Work Orders</div><div class="kpi-sub"><span><?php echo $wo_comp_rate; ?>% completed</span></div></div></div>
    <div class="kpi-card kpi-cost"><div class="kpi-shine"></div><div class="kpi-icon"><i class="fa fa-dollar-sign"></i></div><div class="kpi-body"><div class="kpi-val sm"><?php echo rpt_fmt($wo_cost,2); ?></div><div class="kpi-lbl">Total Maint. Cost</div><div class="kpi-sub"><span>Avg <?php echo rpt_fmt($wo_avg_cost,2); ?> / WO</span></div></div></div>
    <div class="kpi-card kpi-fuel"><div class="kpi-shine"></div><div class="kpi-icon"><i class="fa fa-clock"></i></div><div class="kpi-body"><div class="kpi-val"><?php echo $wo_pending; ?></div><div class="kpi-lbl">Pending / Open</div><div class="kpi-sub"><span><?php echo $wo_total-$wo_pending-$wo_completed; ?> other</span></div></div></div>
    <div class="kpi-card kpi-revenue"><div class="kpi-shine"></div><div class="kpi-icon"><i class="fa fa-check-circle"></i></div><div class="kpi-body"><div class="kpi-val"><?php echo $wo_completed; ?></div><div class="kpi-lbl">Completed</div><div class="kpi-sub"><span><?php echo $wo_comp_rate; ?>% completion rate</span></div></div></div>
</div>

<div class="two-col" style="margin-bottom:20px;">
    <div class="card">
        <h3 class="card-title"><i class="fa fa-chart-pie"></i> Work Orders by Priority</h3>
        <div class="chart-wrap sm"><canvas id="rptWoPriority"></canvas></div>
        <table class="rpt-table" style="margin-top:14px;">
            <thead><tr><th>Priority</th><th style="text-align:right">Count</th><th style="text-align:right">Cost</th></tr></thead>
            <tbody>
            <?php foreach($wo_by_priority as $wp): ?>
            <tr><td><?php rpt_badge($wp->lbl); ?></td><td style="text-align:right" class="num"><?php echo rpt_fmt($wp->cnt); ?></td><td style="text-align:right" class="cost"><?php echo rpt_fmt($wp->cost,2); ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="card">
        <h3 class="card-title"><i class="fa fa-chart-donut"></i> Work Orders by Status</h3>
        <div class="chart-wrap sm"><canvas id="rptWoStatus"></canvas></div>
        <table class="rpt-table" style="margin-top:14px;">
            <thead><tr><th>Status</th><th style="text-align:right">Count</th><th style="text-align:right">Share</th></tr></thead>
            <tbody>
            <?php $tot_wo=max(1,$wo_total); foreach($wo_by_status as $ws): ?>
            <tr><td><?php rpt_badge($ws->lbl); ?></td><td style="text-align:right" class="num"><?php echo rpt_fmt($ws->cnt); ?></td><td style="text-align:right" class="num"><?php echo round($ws->cnt/$tot_wo*100); ?>%</td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <h3 class="card-title"><i class="fa fa-car"></i> Maintenance Cost by Vehicle</h3>
    <?php if(empty($wo_by_vehicle)): ?><div class="empty-state"><i class="fa fa-tools"></i><p>No maintenance data available</p></div>
    <?php else: $mx=max(1,max(array_map(function($r){return (float)$r->cost;},$wo_by_vehicle))); ?>
    <table class="rpt-table">
        <thead><tr><th>#</th><th>Vehicle</th><th style="text-align:right">Work Orders</th><th style="text-align:right">Total Cost</th><th style="width:120px">Cost Share</th></tr></thead>
        <tbody>
        <?php foreach($wo_by_vehicle as $idx=>$wv): $pct=round(($wv->cost/$mx)*100); ?>
        <tr>
            <td class="rank"><?php echo $idx+1; ?></td>
            <td class="name-cell"><div class="main"><?php echo htmlspecialchars(trim(($wv->maker?:'').($wv->model?' '.$wv->model:''))?:$wv->ref); ?></div><div class="sub"><?php echo htmlspecialchars($wv->ref); ?></div></td>
            <td style="text-align:right" class="num"><?php echo rpt_fmt($wv->cnt); ?></td>
            <td style="text-align:right" class="cost"><?php echo rpt_fmt($wv->cost,2); ?></td>
            <td><div class="prog"><div class="prog-fill pink" style="width:<?php echo $pct; ?>%"></div></div><div style="font-size:10px;color:#a0aec0;margin-top:2px;"><?php echo $pct; ?>%</div></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<?php // ══════════════════════════════════════════
// TAB: EXPENSES
// ═══════════════════════════════════════════ ?>
<?php elseif ($report_tab == 'expenses'): ?>

<?php
$cat_meta = array(
    'fuel'       => array('icon'=>'fa-gas-pump',          'label'=>'Fuel'),
    'road'       => array('icon'=>'fa-road',              'label'=>'Road'),
    'driver'     => array('icon'=>'fa-user-tie',          'label'=>'Driver'),
    'commission' => array('icon'=>'fa-coins',             'label'=>'Commission'),
    'other'      => array('icon'=>'fa-tag',               'label'=>'Other'),
);
?>

<!-- KPIs row -->
<div class="kpi-grid" style="grid-template-columns:repeat(auto-fit,minmax(195px,1fr));margin-bottom:28px;">
    <div class="kpi-card kpi-cost">
        <div class="kpi-shine"></div><div class="kpi-icon"><i class="fa fa-receipt"></i></div>
        <div class="kpi-body">
            <div class="kpi-val sm"><?php echo rpt_fmt($exp_total_amount,2); ?></div>
            <div class="kpi-lbl">Total Expenses</div>
            <div class="kpi-sub">
                <?php if ($exp_trend_pct !== null): ?>
                <span class="kpi-trend <?php echo $exp_trend_pct<=0?'up':'down'; ?>"><i class="fa fa-arrow-<?php echo $exp_trend_pct<=0?'down':'up'; ?>"></i><?php echo abs($exp_trend_pct); ?>% vs prev</span>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="kpi-card kpi-bookings">
        <div class="kpi-shine"></div><div class="kpi-icon"><i class="fa fa-list-alt"></i></div>
        <div class="kpi-body">
            <div class="kpi-val"><?php echo rpt_fmt($exp_total_count); ?></div>
            <div class="kpi-lbl">Expense Entries</div>
            <div class="kpi-sub"><span>Avg <?php echo rpt_fmt($exp_avg_amount,2); ?> / entry</span></div>
        </div>
    </div>
    <div class="kpi-card kpi-fuel">
        <div class="kpi-shine"></div><div class="kpi-icon"><i class="fa fa-gas-pump"></i></div>
        <div class="kpi-body">
            <div class="kpi-val sm"><?php echo rpt_fmt($exp_fuel_total,2); ?></div>
            <div class="kpi-lbl">Fuel Expenses</div>
            <div class="kpi-sub"><span><?php echo $exp_total_amount>0?round($exp_fuel_total/$exp_total_amount*100).'%':'—'; ?> of total</span></div>
        </div>
    </div>
    <div class="kpi-card kpi-distance">
        <div class="kpi-shine"></div><div class="kpi-icon"><i class="fa fa-road"></i></div>
        <div class="kpi-body">
            <div class="kpi-val sm"><?php echo rpt_fmt($exp_road_total,2); ?></div>
            <div class="kpi-lbl">Road Expenses</div>
            <div class="kpi-sub"><span>Toll · Parking · Other</span></div>
        </div>
    </div>
    <div class="kpi-card" style="background:linear-gradient(135deg,#3c8ce7,#00eaff);color:#fff;">
        <div class="kpi-shine"></div><div class="kpi-icon"><i class="fa fa-user-tie"></i></div>
        <div class="kpi-body">
            <div class="kpi-val sm"><?php echo rpt_fmt($exp_driver_total,2); ?></div>
            <div class="kpi-lbl">Driver Costs</div>
            <div class="kpi-sub"><span>Salary · Overnight · Bonus</span></div>
        </div>
    </div>
    <div class="kpi-card kpi-margin">
        <div class="kpi-shine"></div><div class="kpi-icon"><i class="fa fa-coins"></i></div>
        <div class="kpi-body">
            <div class="kpi-val sm"><?php echo rpt_fmt($exp_comm_total,2); ?></div>
            <div class="kpi-lbl">Commissions</div>
            <div class="kpi-sub"><span>Agent · Tax · Fees</span></div>
        </div>
    </div>
    <div class="kpi-card kpi-fleet">
        <div class="kpi-shine"></div><div class="kpi-icon"><i class="fa fa-tag"></i></div>
        <div class="kpi-body">
            <div class="kpi-val sm"><?php echo rpt_fmt($exp_other_total,2); ?></div>
            <div class="kpi-lbl">Other Expenses</div>
            <div class="kpi-sub"><span><?php echo $exp_vs_rev_pct; ?>% of revenue</span></div>
        </div>
    </div>
</div>

<!-- Insight bar -->
<div class="insight-row" style="margin-bottom:24px;">
    <div class="insight red"><div class="insight-val"><?php echo rpt_fmt($exp_total_amount,2); ?></div><div class="insight-lbl">Total Spend</div></div>
    <div class="insight green"><div class="insight-val"><?php echo rpt_fmt($total_revenue,2); ?></div><div class="insight-lbl">Period Revenue</div></div>
    <div class="insight <?php echo $exp_vs_rev_pct>60?'red':($exp_vs_rev_pct>40?'orange':'green'); ?>"><div class="insight-val"><?php echo $exp_vs_rev_pct; ?>%</div><div class="insight-lbl">Expenses / Revenue</div></div>
    <div class="insight orange"><div class="insight-val"><?php echo rpt_fmt($exp_avg_amount,2); ?></div><div class="insight-lbl">Avg Per Entry</div></div>
    <?php if($exp_trend_pct!==null): ?>
    <div class="insight <?php echo $exp_trend_pct>0?'red':'green'; ?>"><div class="insight-val"><?php echo ($exp_trend_pct>0?'+':'').abs($exp_trend_pct); ?>%</div><div class="insight-lbl">vs Previous Period</div></div>
    <?php endif; ?>
</div>

<!-- Charts -->
<div class="two-col" style="margin-bottom:20px;">
    <div class="card">
        <h3 class="card-title"><i class="fa fa-chart-bar"></i> Monthly Expense Trend (6 months)</h3>
        <div class="chart-wrap tall"><canvas id="rptExpTrend"></canvas></div>
    </div>
    <div class="card">
        <h3 class="card-title"><i class="fa fa-chart-pie"></i> Breakdown by Category</h3>
        <div class="chart-wrap" style="height:200px;"><canvas id="rptExpCat"></canvas></div>
        <?php if (empty($exp_by_cat)): ?>
        <div class="empty-state" style="padding:20px 0;"><i class="fa fa-receipt"></i><p>No expense data for this period</p></div>
        <?php else: $exp_max=max(1,max(array_map(function($r){return (float)$r->total;},$exp_by_cat))); ?>
        <table class="rpt-table" style="margin-top:14px;">
            <thead><tr><th>Category</th><th style="text-align:right">Entries</th><th style="text-align:right">Total</th><th style="text-align:right">Share</th></tr></thead>
            <tbody>
            <?php foreach($exp_by_cat as $ec):
                $cat_info = isset($cat_meta[$ec->lbl]) ? $cat_meta[$ec->lbl] : array('icon'=>'fa-tag','label'=>ucfirst($ec->lbl));
                $share = $exp_total_amount>0 ? round($ec->total/$exp_total_amount*100) : 0;
            ?>
            <tr>
                <td><span style="display:inline-flex;align-items:center;gap:7px;font-weight:600;"><i class="fa <?php echo $cat_info['icon']; ?>" style="color:#667eea;width:14px;text-align:center;"></i><?php echo htmlspecialchars($cat_info['label']); ?></span></td>
                <td style="text-align:right" class="num"><?php echo rpt_fmt($ec->cnt); ?></td>
                <td style="text-align:right" class="cost"><?php echo rpt_fmt($ec->total,2); ?></td>
                <td style="text-align:right">
                    <div style="display:flex;align-items:center;gap:8px;justify-content:flex-end;">
                        <div class="prog" style="width:60px;"><div class="prog-fill blue" style="width:<?php echo round($ec->total/$exp_max*100); ?>%"></div></div>
                        <span class="num"><?php echo $share; ?>%</span>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<!-- Expenses by Vehicle -->
<?php if (!empty($exp_by_vehicle)): $mx_ev=max(1,max(array_map(function($r){return (float)$r->total;},$exp_by_vehicle))); ?>
<div class="rpt-section">
    <div class="rpt-sec-hdr"><h2 class="rpt-sec-title"><i class="fa fa-car"></i> Expenses by Vehicle</h2><span class="rpt-sec-meta">Top 10 via linked bookings</span></div>
    <div class="card">
        <table class="rpt-table">
            <thead><tr><th>#</th><th>Vehicle</th><th style="text-align:right">Entries</th><th style="text-align:right">Total Expense</th><th style="width:140px">Share</th></tr></thead>
            <tbody>
            <?php foreach($exp_by_vehicle as $idx=>$ev): $pct=round(($ev->total/$mx_ev)*100); ?>
            <tr>
                <td class="rank"><?php echo $idx+1; ?></td>
                <td class="name-cell"><div class="main"><?php echo htmlspecialchars(trim(($ev->maker?:'').($ev->model?' '.$ev->model:''))?:$ev->ref); ?></div><div class="sub"><?php echo htmlspecialchars($ev->ref); ?></div></td>
                <td style="text-align:right" class="num"><?php echo rpt_fmt($ev->cnt); ?></td>
                <td style="text-align:right" class="cost"><?php echo rpt_fmt($ev->total,2); ?></td>
                <td><div class="prog"><div class="prog-fill pink" style="width:<?php echo $pct; ?>%"></div></div><div style="font-size:10px;color:#a0aec0;margin-top:2px;"><?php echo $pct; ?>%</div></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- Recent expenses table -->
<div class="rpt-section">
    <div class="rpt-sec-hdr">
        <h2 class="rpt-sec-title"><i class="fa fa-clock"></i> Recent Expenses</h2>
        <div style="display:flex;gap:10px;align-items:center;">
            <?php if (!empty($user->rights->flotte->write)): ?>
            <a href="<?php echo dol_buildpath('/flotte/expenses_card.php',1).'?action=create'; ?>" class="btn-apply" style="padding:7px 16px;font-size:12px;"><i class="fa fa-plus"></i> New Expense</a>
            <?php endif; ?>
            <a href="<?php echo dol_buildpath('/flotte/expenses_list.php',1); ?>" class="export-btn"><i class="fa fa-external-link-alt"></i> View All</a>
        </div>
    </div>
    <?php if (empty($exp_recent)): ?>
    <div class="card"><div class="empty-state"><i class="fa fa-receipt"></i><p>No expenses recorded for this period. <a href="<?php echo dol_buildpath('/flotte/expenses_card.php',1).'?action=create'; ?>">Add one now →</a></p></div></div>
    <?php else: ?>
    <div class="card" style="padding:0;overflow:hidden;">
        <table class="rpt-table">
            <thead><tr>
                <th>Ref</th>
                <th>Date</th>
                <th>Category</th>
                <th>Booking</th>
                <th>Notes / Label</th>
                <th style="text-align:right">Amount</th>
                <th style="text-align:center"></th>
            </tr></thead>
            <tbody>
            <?php
            $cat_badge_map = array(
                'fuel'       => array('sbadge-warning',   'fa-gas-pump',  'Fuel'),
                'road'       => array('sbadge-info',      'fa-road',      'Road'),
                'driver'     => array('sbadge-secondary', 'fa-user-tie',  'Driver'),
                'commission' => array('sbadge-success',   'fa-coins',     'Commission'),
                'other'      => array('sbadge-secondary', 'fa-tag',       'Other'),
            );
            foreach ($exp_recent as $er):
                $cbm = isset($cat_badge_map[$er->category]) ? $cat_badge_map[$er->category] : array('sbadge-secondary','fa-tag',ucfirst($er->category));
            ?>
            <tr>
                <td style="font-family:'DM Mono',monospace;font-size:12px;font-weight:600;color:#667eea;"><?php echo htmlspecialchars($er->ref?:'—'); ?></td>
                <td style="white-space:nowrap;font-size:12px;"><?php echo htmlspecialchars($er->expense_date?:'—'); ?></td>
                <td><span class="sbadge <?php echo $cbm[0]; ?>"><i class="fa <?php echo $cbm[1]; ?>" style="margin-right:4px;font-size:10px;"></i><?php echo $cbm[2]; ?></span></td>
                <td style="font-size:12px;color:#718096;"><?php echo !empty($er->booking_ref)?htmlspecialchars($er->booking_ref):'<span style="color:#c4c9d8;">—</span>'; ?></td>
                <td style="font-size:12px;color:#4a5568;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                    <?php
                    $note = !empty($er->other_label) ? $er->other_label : ($er->notes ?? '');
                    echo $note ? htmlspecialchars(mb_strimwidth($note,0,55,'…')) : '<span style="color:#c4c9d8;">—</span>';
                    ?>
                </td>
                <td style="text-align:right;font-weight:700;color:#e53e3e;"><?php echo !empty($er->amount)?rpt_fmt((float)$er->amount,2):'<span style="color:#c4c9d8;">—</span>'; ?></td>
                <td style="text-align:center;">
                    <a href="<?php echo dol_buildpath('/flotte/expenses_card.php',1).'?id='.(int)$er->rowid; ?>" class="det-action-btn view" title="View"><i class="fa fa-eye"></i></a>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php // ══════════════════════════════════════════
// TAB: FLEET
// ═══════════════════════════════════════════ ?>
<?php elseif ($report_tab == 'fleet'): ?>
    <div class="kpi-card kpi-fleet"><div class="kpi-shine"></div><div class="kpi-icon"><i class="fa fa-car"></i></div><div class="kpi-body"><div class="kpi-val"><?php echo $fleet_total; ?></div><div class="kpi-lbl">Total Vehicles</div><div class="kpi-sub"><span><?php echo $fleet_util; ?>% availability</span></div></div></div>
    <div class="kpi-card kpi-revenue"><div class="kpi-shine"></div><div class="kpi-icon"><i class="fa fa-check-circle"></i></div><div class="kpi-body"><div class="kpi-val"><?php echo $fleet_active; ?></div><div class="kpi-lbl">In Service</div></div></div>
    <div class="kpi-card kpi-maint"><div class="kpi-shine"></div><div class="kpi-icon"><i class="fa fa-times-circle"></i></div><div class="kpi-body"><div class="kpi-val"><?php echo $fleet_inactive; ?></div><div class="kpi-lbl">Out of Service</div></div></div>
    <div class="kpi-card kpi-margin"><div class="kpi-shine"></div><div class="kpi-icon"><i class="fa fa-users"></i></div><div class="kpi-body"><div class="kpi-val"><?php echo $total_drivers; ?></div><div class="kpi-lbl">Drivers</div><div class="kpi-sub"><span><?php echo $total_customers; ?> customers</span></div></div></div>
</div>

<div class="two-col" style="margin-bottom:20px;">
    <div class="card">
        <h3 class="card-title"><i class="fa fa-traffic-light"></i> Fleet Availability</h3>
        <div class="fleet-mini">
            <div class="fmc blue"><div class="fmc-val"><?php echo $fleet_total; ?></div><div class="fmc-lbl">Total</div></div>
            <div class="fmc green"><div class="fmc-val"><?php echo $fleet_active; ?></div><div class="fmc-lbl">In Service</div></div>
            <div class="fmc red"><div class="fmc-val"><?php echo $fleet_inactive; ?></div><div class="fmc-lbl">Out of Service</div></div>
        </div>
        <div style="margin-bottom:10px;">
            <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:6px;"><span style="color:#718096;font-weight:600;">Fleet Availability Rate</span><span style="font-weight:800;color:#1a202c;"><?php echo $fleet_util; ?>%</span></div>
            <div class="prog" style="height:10px;"><div class="prog-fill <?php echo $fleet_util>=70?'green':($fleet_util>=40?'orange':'pink'); ?>" style="width:<?php echo $fleet_util; ?>%"></div></div>
        </div>
        <div class="chart-wrap sm"><canvas id="rptFleetStatus"></canvas></div>
    </div>
    <div class="card">
        <h3 class="card-title"><i class="fa fa-layer-group"></i> Fleet by Vehicle Type</h3>
        <?php if(empty($fleet_by_type)): ?><div class="empty-state"><i class="fa fa-car-side"></i><p>No type data</p></div>
        <?php else: ?>
        <table class="rpt-table">
            <thead><tr><th>Type</th><th style="text-align:right">Total</th><th style="text-align:right">Active</th><th style="text-align:right">Rate</th></tr></thead>
            <tbody>
            <?php foreach($fleet_by_type as $ft): $rate=$ft->cnt>0?round($ft->active/$ft->cnt*100):0; ?>
            <tr><td class="num"><?php echo htmlspecialchars($ft->lbl); ?></td><td style="text-align:right" class="num"><?php echo $ft->cnt; ?></td><td style="text-align:right" class="num"><?php echo $ft->active; ?></td><td style="text-align:right"><span class="sbadge <?php echo $rate>=70?'sbadge-success':($rate>=40?'sbadge-warning':'sbadge-danger'); ?>"><?php echo $rate; ?>%</span></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <h3 class="card-title"><i class="fa fa-car-side"></i> Fleet by Manufacturer</h3>
    <div class="chart-wrap"><canvas id="rptMaker"></canvas></div>
</div>

<?php endif; // end tabs ?>

</div><!-- /rpt -->

<!-- ══════════════════════════════════════════════════
     CHART.JS
     ══════════════════════════════════════════════════ -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
<script>
(function(){
var GRAD_BLUE   = ['rgba(102,126,234,.8)','rgba(118,75,162,.8)'];
var GRAD_GREEN  = ['rgba(17,153,142,.8)','rgba(56,239,125,.8)'];
var GRAD_ORANGE = ['rgba(247,151,30,.8)','rgba(255,210,0,.8)'];
var GRAD_PINK   = ['rgba(240,147,251,.8)','rgba(245,87,108,.8)'];
var PALETTE     = ['rgba(102,126,234,.8)','rgba(56,239,125,.8)','rgba(245,87,108,.8)','rgba(79,172,254,.8)','rgba(247,151,30,.8)','rgba(155,89,182,.8)','rgba(26,188,156,.8)','rgba(149,165,166,.8)'];
var DEFAULTS = { responsive:true, maintainAspectRatio:false, plugins:{ legend:{ labels:{ font:{family:'inherit',size:12}, padding:14 } } } };

function makeGrad(ctx, c1, c2) {
    var g = ctx.createLinearGradient(0,0,0,300);
    g.addColorStop(0, c1); g.addColorStop(1, c2); return g;
}

// ── Trend chart (overview + bookings tabs) ──
var tEl = document.getElementById('rptTrend');
if (tEl) {
    var ctx = tEl.getContext('2d');
    // compute profit per month
    var tRevenue = <?php echo json_encode(array_column($trend_data,'revenue')); ?>;
    var tCost    = <?php echo json_encode(array_column($trend_data,'cost')); ?>;
    var tProfit  = tRevenue.map(function(r,i){ return Math.round((r - tCost[i])*100)/100; });
    new Chart(tEl, {
        type: 'bar',
        data: {
            labels: <?php echo json_encode(array_column($trend_data,'month')); ?>,
            datasets: [
                { label:'Revenue', data:tRevenue, backgroundColor:'rgba(56,239,125,.65)', borderColor:'#38ef7d', borderWidth:0, borderRadius:6, yAxisID:'y1' },
                { label:'Cost',    data:tCost,    backgroundColor:'rgba(245,87,108,.55)', borderColor:'rgba(245,87,108,.8)', borderWidth:0, borderRadius:6, yAxisID:'y1' },
                { label:'Profit',  data:tProfit,  type:'line', tension:.4, fill:false, borderColor:'#667eea', backgroundColor:'rgba(102,126,234,.1)', pointBackgroundColor:'#667eea', pointRadius:5, borderWidth:2.5, yAxisID:'y1' },
                { label:'Trips',   data:<?php echo json_encode(array_column($trend_data,'bookings')); ?>, type:'line', tension:.4, fill:false, borderColor:'rgba(255,210,0,.9)', pointBackgroundColor:'rgba(255,210,0,.9)', pointRadius:4, borderWidth:2, borderDash:[4,3], yAxisID:'y' }
            ]
        },
        options: Object.assign({}, DEFAULTS, {
            interaction:{ mode:'index', intersect:false },
            scales:{
                y:  { type:'linear', position:'right', grid:{drawOnChartArea:false}, title:{display:true,text:'Trips',font:{size:11}}, ticks:{color:'rgba(255,210,0,.9)'} },
                y1: { type:'linear', position:'left',  grid:{color:'rgba(0,0,0,.04)'}, title:{display:true,text:'Amount',font:{size:11}} }
            }
        })
    });
}

// ── Cost breakdown donut (overview) ──
var cbEl = document.getElementById('rptCostBreak');
if (cbEl) {
    new Chart(cbEl, {
        type:'doughnut',
        data:{
            labels:['Booking Cost','Fuel','Expenses','Maintenance'],
            datasets:[{ data:[<?php echo $total_cost; ?>,<?php echo $total_fuel_cost; ?>,<?php echo $exp_total_amount; ?>,<?php echo $wo_cost; ?>], backgroundColor:['rgba(102,126,234,.8)','rgba(247,151,30,.8)','rgba(245,87,108,.8)','rgba(252,74,26,.8)'], borderWidth:3, borderColor:'#fff', hoverOffset:8 }]
        },
        options: Object.assign({},DEFAULTS,{ cutout:'62%', plugins:{ legend:{display:false} } })
    });
}

// ── Day of week chart ──
var dowEl = document.getElementById('rptDow');
if (dowEl) {
    new Chart(dowEl, {
        type: 'bar',
        data: {
            labels: <?php echo json_encode(array_column($dow_data,'day')); ?>,
            datasets: [
                { label:'Trips', data:<?php echo json_encode(array_column($dow_data,'cnt')); ?>, backgroundColor:PALETTE, borderRadius:8 }
            ]
        },
        options: Object.assign({},DEFAULTS,{ plugins:{ legend:{display:false} }, scales:{ x:{grid:{display:false}}, y:{grid:{color:'rgba(0,0,0,.04)'}} } })
    });
}

// ── Status doughnut ──
var sEl = document.getElementById('rptStatus');
if (sEl) {
    new Chart(sEl, {
        type:'doughnut',
        data:{ labels:<?php echo json_encode(array_column($status_rows,'lbl')); ?>, datasets:[{ data:<?php echo json_encode(array_column($status_rows,'cnt')); ?>, backgroundColor:PALETTE, borderWidth:3, borderColor:'#fff', hoverOffset:8 }] },
        options: Object.assign({},DEFAULTS,{ cutout:'62%', plugins:{ legend:{position:'right'} } })
    });
}

// ── Fuel trend ──
var ftEl = document.getElementById('rptFuelTrend');
if (ftEl) {
    new Chart(ftEl, {
        type:'bar',
        data:{
            labels:<?php echo json_encode(array_column($fuel_trend,'month')); ?>,
            datasets:[
                { label:'Litres', data:<?php echo json_encode(array_column($fuel_trend,'qty')); ?>, backgroundColor:'rgba(247,151,30,.75)', borderColor:'#f7971e', borderWidth:0, borderRadius:6, yAxisID:'y' },
                { label:'Cost',   data:<?php echo json_encode(array_column($fuel_trend,'cost')); ?>, type:'line', tension:.4, borderColor:'rgba(245,87,108,.8)', backgroundColor:'rgba(245,87,108,.08)', fill:true, pointRadius:4, borderWidth:2.5, yAxisID:'y1' }
            ]
        },
        options: Object.assign({},DEFAULTS,{ interaction:{mode:'index',intersect:false}, scales:{ y:{type:'linear',position:'left',title:{display:true,text:'Litres',font:{size:11}},grid:{color:'rgba(0,0,0,.04)'}}, y1:{type:'linear',position:'right',grid:{drawOnChartArea:false},title:{display:true,text:'Cost',font:{size:11}}} } })
    });
}

// ── Fuel source doughnut ──
var fsEl = document.getElementById('rptFuelSource');
if (fsEl) {
    new Chart(fsEl, { type:'doughnut', data:{ labels:<?php echo json_encode(array_column($fuel_by_source,'lbl')); ?>, datasets:[{ data:<?php echo json_encode(array_map(function($r){return round((float)$r->qty,1);},$fuel_by_source)); ?>, backgroundColor:PALETTE, borderWidth:3, borderColor:'#fff', hoverOffset:8 }] }, options:Object.assign({},DEFAULTS,{ cutout:'58%', plugins:{legend:{position:'right'}} }) });
}

// ── WO priority pie ──
var wpEl = document.getElementById('rptWoPriority');
if (wpEl) {
    new Chart(wpEl, { type:'pie', data:{ labels:<?php echo json_encode(array_column($wo_by_priority,'lbl')); ?>, datasets:[{ data:<?php echo json_encode(array_column($wo_by_priority,'cnt')); ?>, backgroundColor:['rgba(245,87,108,.8)','rgba(247,151,30,.8)','rgba(102,126,234,.8)','rgba(56,239,125,.8)'], borderWidth:3, borderColor:'#fff', hoverOffset:8 }] }, options:Object.assign({},DEFAULTS,{ plugins:{legend:{position:'right'}} }) });
}

// ── WO status doughnut ──
var wsEl = document.getElementById('rptWoStatus');
if (wsEl) {
    new Chart(wsEl, { type:'doughnut', data:{ labels:<?php echo json_encode(array_column($wo_by_status,'lbl')); ?>, datasets:[{ data:<?php echo json_encode(array_column($wo_by_status,'cnt')); ?>, backgroundColor:PALETTE, borderWidth:3, borderColor:'#fff', hoverOffset:8 }] }, options:Object.assign({},DEFAULTS,{ cutout:'58%', plugins:{legend:{position:'right'}} }) });
}

// ── Fleet status doughnut ──
var flEl = document.getElementById('rptFleetStatus');
if (flEl) {
    new Chart(flEl, { type:'doughnut', data:{ labels:['In Service','Out of Service'], datasets:[{ data:[<?php echo $fleet_active; ?>,<?php echo $fleet_inactive; ?>], backgroundColor:['rgba(56,239,125,.8)','rgba(245,87,108,.8)'], borderWidth:3, borderColor:'#fff', hoverOffset:8 }] }, options:Object.assign({},DEFAULTS,{ cutout:'60%', plugins:{legend:{position:'right'}} }) });
}

// ── Fleet by maker ──
var mkEl = document.getElementById('rptMaker');
if (mkEl) {
    new Chart(mkEl, { type:'bar', data:{ labels:<?php echo json_encode(array_column($fleet_by_maker,'lbl')); ?>, datasets:[{ label:'Vehicles', data:<?php echo json_encode(array_column($fleet_by_maker,'cnt')); ?>, backgroundColor:PALETTE, borderRadius:8 }] }, options:Object.assign({},DEFAULTS,{ indexAxis:'y', plugins:{legend:{display:false}}, scales:{ x:{grid:{color:'rgba(0,0,0,.04)'}}, y:{grid:{display:false}} } }) });
}

// ── Expense trend chart ──
var expTrEl = document.getElementById('rptExpTrend');
if (expTrEl) {
    var ctx5 = expTrEl.getContext('2d');
    new Chart(expTrEl, {
        type:'bar',
        data:{
            labels:<?php echo json_encode(array_column($exp_trend,'month')); ?>,
            datasets:[
                { label:'Total',      data:<?php echo json_encode(array_column($exp_trend,'total')); ?>,  backgroundColor:'rgba(229,62,62,.75)',  borderColor:'#e53e3e', borderWidth:0, borderRadius:6, yAxisID:'y' },
                { label:'Fuel',       data:<?php echo json_encode(array_column($exp_trend,'fuel')); ?>,   type:'line', tension:.4, borderColor:'rgba(247,151,30,.9)',  backgroundColor:'rgba(247,151,30,.08)',  fill:false, pointRadius:4, borderWidth:2.5, yAxisID:'y' },
                { label:'Road',       data:<?php echo json_encode(array_column($exp_trend,'road')); ?>,   type:'line', tension:.4, borderColor:'rgba(79,172,254,.9)',   backgroundColor:'rgba(79,172,254,.08)',   fill:false, pointRadius:4, borderWidth:2, yAxisID:'y' },
                { label:'Driver',     data:<?php echo json_encode(array_column($exp_trend,'driver')); ?>, type:'line', tension:.4, borderColor:'rgba(102,126,234,.9)',  backgroundColor:'rgba(102,126,234,.08)', fill:false, pointRadius:4, borderWidth:2, yAxisID:'y' }
            ]
        },
        options: Object.assign({},DEFAULTS,{ interaction:{mode:'index',intersect:false}, scales:{ y:{type:'linear',position:'left',grid:{color:'rgba(0,0,0,.04)'},title:{display:true,text:'Amount',font:{size:11}}} } })
    });
}

// ── Expense category doughnut ──
var expCatEl = document.getElementById('rptExpCat');
if (expCatEl) {
    var expCatLabels = <?php echo json_encode(array_map(function($r){ $m=array('fuel'=>'Fuel','road'=>'Road','driver'=>'Driver','commission'=>'Commission','other'=>'Other'); return isset($m[$r->lbl])?$m[$r->lbl]:ucfirst($r->lbl); },$exp_by_cat)); ?>;
    var expCatData   = <?php echo json_encode(array_column($exp_by_cat,'total')); ?>;
    new Chart(expCatEl, {
        type:'doughnut',
        data:{ labels:expCatLabels, datasets:[{ data:expCatData, backgroundColor:['rgba(247,151,30,.8)','rgba(79,172,254,.8)','rgba(102,126,234,.8)','rgba(56,239,125,.8)','rgba(149,165,166,.8)'], borderWidth:3, borderColor:'#fff', hoverOffset:8 }] },
        options: Object.assign({},DEFAULTS,{ cutout:'60%', plugins:{ legend:{position:'right', labels:{font:{size:12},padding:12}} } })
    });
}

})();
</script>

<script>
// Filter panel toggle
var _filterOpen = true;
function toggleFilters() {
    _filterOpen = !_filterOpen;
    document.getElementById('filterBody').classList.toggle('collapsed', !_filterOpen);
    document.getElementById('filterChevron').className = _filterOpen ? 'fa fa-chevron-up' : 'fa fa-chevron-down';
    document.getElementById('filterToggleText').textContent = _filterOpen ? 'Collapse' : 'Expand';
}

// Custom date range toggle
function handlePeriodChange(sel) {
    var row = document.getElementById('customDateRow');
    if (sel.value === 'custom') { row.style.opacity='1'; row.style.pointerEvents='auto'; }
    else { row.style.opacity='.4'; row.style.pointerEvents='none'; }
    sel.form.submit();
}
</script>

<?php
// ════════════════════════════════════════════════════════════
// DETAIL RECORDS TABLE — shown whenever any filter is active
// ════════════════════════════════════════════════════════════

// Determine if any filter is active
$any_filter = ($fk_vehicle || $fk_driver || $fk_customer || $fk_vendor
    || !empty($filter_status) || !empty($filter_fuel_src)
    || !empty($filter_wo_priority) || !empty($filter_vehicle_type)
    || !empty($filter_min_revenue) || !empty($filter_max_revenue)
    || $period != 'month'); // also show when a non-default period is selected

if ($any_filter):

// ════════════════════════════════════════════════════════════
// UNIFIED DETAIL TABLE — one query tailored to active filter
// ════════════════════════════════════════════════════════════

$unified_rows   = array();
$unified_mode   = 'booking'; // booking | vehicle | driver | customer | vendor
$filter_label   = '';
$filter_icon    = 'fa-list-alt';

if ($fk_driver) {
    // DRIVER selected → bookings + work orders merged into one flat list
    $unified_mode = 'driver';
    $filter_icon  = 'fa-user-tie';
    if (isset($drivers_list[$fk_driver])) $filter_label = $drivers_list[$fk_driver];

    $usql  = "SELECT 'booking' as rec_type, b.rowid, b.ref, b.booking_date as rec_date, b.status,";
    $usql .= " b.selling_amount as revenue, b.buying_amount as cost, b.distance,";
    $usql .= " v.ref as vref, TRIM(v.maker) as maker, TRIM(v.model) as model, v.license_plate,";
    $usql .= " COALESCE(c.company_name, TRIM(CONCAT(COALESCE(c.firstname,''),' ',COALESCE(c.lastname,'')))) as customer_name,";
    $usql .= " vn.name as vendor_name,";
    $usql .= " '' as task_display, '' as priority, NULL as wo_price";
    $usql .= " FROM ".MAIN_DB_PREFIX."flotte_booking b";
    $usql .= " LEFT JOIN ".MAIN_DB_PREFIX."flotte_vehicle v ON v.rowid = b.fk_vehicle";
    $usql .= " LEFT JOIN ".MAIN_DB_PREFIX."flotte_customer c ON c.rowid = b.fk_customer";
    $usql .= " LEFT JOIN ".MAIN_DB_PREFIX."flotte_vendor vn ON vn.rowid = b.fk_vendor";
    $usql .= " WHERE b.entity=$entity AND b.fk_driver=".(int)$fk_driver;
    if ($filter_status) $usql .= " AND b.status='".$db->escape($filter_status)."'";
    $usql .= " UNION ALL";
    $usql .= " SELECT 'workorder' as rec_type, w.rowid, w.ref, COALESCE(w.due_date,w.required_by) as rec_date, w.status,";
    $usql .= " NULL as revenue, NULL as cost, NULL as distance,";
    $usql .= " v.ref as vref, TRIM(v.maker) as maker, TRIM(v.model) as model, v.license_plate,";
    $usql .= " '' as customer_name, '' as vendor_name,";
    $usql .= " COALESCE(w.task_to_perform, w.description, '') as task_display, w.priority, w.price as wo_price";
    $usql .= " FROM ".MAIN_DB_PREFIX."flotte_workorder w";
    $usql .= " LEFT JOIN ".MAIN_DB_PREFIX."flotte_vehicle v ON v.rowid = w.fk_vehicle";
    $usql .= " WHERE w.entity=$entity AND w.fk_driver=".(int)$fk_driver;
    $usql .= " ORDER BY rec_date DESC LIMIT 400";
    $unified_rows = rpt_rows($db, $usql);

} elseif ($fk_vehicle) {
    // VEHICLE selected → bookings + fuel + work orders
    $unified_mode = 'vehicle';
    $filter_icon  = 'fa-car';
    if (isset($vehicles_list[$fk_vehicle])) $filter_label = $vehicles_list[$fk_vehicle];

    $usql  = "SELECT 'booking' as rec_type, b.rowid, b.ref, b.booking_date as rec_date, b.status,";
    $usql .= " b.selling_amount as revenue, b.buying_amount as cost, b.distance,";
    $usql .= " TRIM(CONCAT(COALESCE(d.firstname,''),' ',COALESCE(d.lastname,''))) as driver_name,";
    $usql .= " COALESCE(c.company_name, TRIM(CONCAT(COALESCE(c.firstname,''),' ',COALESCE(c.lastname,'')))) as customer_name,";
    $usql .= " vn.name as vendor_name,";
    $usql .= " '' as fuel_source, NULL as qty, NULL as cost_unit, NULL as total_cost, '' as fuel_state,";
    $usql .= " '' as task_display, '' as priority, NULL as wo_price";
    $usql .= " FROM ".MAIN_DB_PREFIX."flotte_booking b";
    $usql .= " LEFT JOIN ".MAIN_DB_PREFIX."flotte_driver d ON d.rowid = b.fk_driver";
    $usql .= " LEFT JOIN ".MAIN_DB_PREFIX."flotte_customer c ON c.rowid = b.fk_customer";
    $usql .= " LEFT JOIN ".MAIN_DB_PREFIX."flotte_vendor vn ON vn.rowid = b.fk_vendor";
    $usql .= " WHERE b.entity=$entity AND b.fk_vehicle=".(int)$fk_vehicle;
    if ($filter_status) $usql .= " AND b.status='".$db->escape($filter_status)."'";
    $usql .= " UNION ALL";
    $usql .= " SELECT 'fuel' as rec_type, f.rowid, f.ref, f.date as rec_date, f.state as status,";
    $usql .= " NULL as revenue, (f.qty*f.cost_unit) as cost, NULL as distance,";
    $usql .= " '' as driver_name, '' as customer_name, '' as vendor_name,";
    $usql .= " f.fuel_source, f.qty, f.cost_unit, (f.qty*f.cost_unit) as total_cost, f.state as fuel_state,";
    $usql .= " '' as task_display, '' as priority, NULL as wo_price";
    $usql .= " FROM ".MAIN_DB_PREFIX."flotte_fuel f";
    $usql .= " WHERE f.entity=$entity AND f.fk_vehicle=".(int)$fk_vehicle;
    if ($filter_fuel_src) $usql .= " AND f.fuel_source='".$db->escape($filter_fuel_src)."'";
    $usql .= " UNION ALL";
    $usql .= " SELECT 'workorder' as rec_type, w.rowid, w.ref, COALESCE(w.due_date,w.required_by) as rec_date, w.status,";
    $usql .= " NULL as revenue, NULL as cost, NULL as distance,";
    $usql .= " TRIM(CONCAT(COALESCE(d2.firstname,''),' ',COALESCE(d2.lastname,''))) as driver_name,";
    $usql .= " '' as customer_name, '' as vendor_name,";
    $usql .= " '' as fuel_source, NULL as qty, NULL as cost_unit, NULL as total_cost, '' as fuel_state,";
    $usql .= " COALESCE(w.task_to_perform, w.description, '') as task_display, w.priority, w.price as wo_price";
    $usql .= " FROM ".MAIN_DB_PREFIX."flotte_workorder w";
    $usql .= " LEFT JOIN ".MAIN_DB_PREFIX."flotte_driver d2 ON d2.rowid = w.fk_driver";
    $usql .= " WHERE w.entity=$entity AND w.fk_vehicle=".(int)$fk_vehicle;
    if ($filter_wo_priority) $usql .= " AND w.priority='".$db->escape($filter_wo_priority)."'";
    $usql .= " ORDER BY rec_date DESC LIMIT 400";
    $unified_rows = rpt_rows($db, $usql);

} elseif ($fk_customer) {
    // CUSTOMER selected → all their bookings
    $unified_mode = 'customer';
    $filter_icon  = 'fa-users';
    if (isset($customers_list[$fk_customer])) $filter_label = $customers_list[$fk_customer];

    $usql  = "SELECT b.rowid, b.ref, b.booking_date as rec_date, b.status,";
    $usql .= " b.selling_amount as revenue, b.buying_amount as cost, b.distance,";
    $usql .= " v.ref as vref, TRIM(v.maker) as maker, TRIM(v.model) as model, v.license_plate,";
    $usql .= " TRIM(CONCAT(COALESCE(d.firstname,''),' ',COALESCE(d.lastname,''))) as driver_name,";
    $usql .= " vn.name as vendor_name";
    $usql .= " FROM ".MAIN_DB_PREFIX."flotte_booking b";
    $usql .= " LEFT JOIN ".MAIN_DB_PREFIX."flotte_vehicle v ON v.rowid = b.fk_vehicle";
    $usql .= " LEFT JOIN ".MAIN_DB_PREFIX."flotte_driver d ON d.rowid = b.fk_driver";
    $usql .= " LEFT JOIN ".MAIN_DB_PREFIX."flotte_vendor vn ON vn.rowid = b.fk_vendor";
    $usql .= " WHERE b.entity=$entity AND b.fk_customer=".(int)$fk_customer;
    if ($filter_status) $usql .= " AND b.status='".$db->escape($filter_status)."'";
    $usql .= " ORDER BY rec_date DESC LIMIT 400";
    $unified_rows = rpt_rows($db, $usql);

} elseif ($fk_vendor) {
    // VENDOR selected → all bookings linked to this vendor
    $unified_mode = 'vendor';
    $filter_icon  = 'fa-store';
    if (isset($vendors_list[$fk_vendor])) $filter_label = $vendors_list[$fk_vendor];

    $usql  = "SELECT b.rowid, b.ref, b.booking_date as rec_date, b.status,";
    $usql .= " b.selling_amount as revenue, b.buying_amount as cost, b.distance,";
    $usql .= " v.ref as vref, TRIM(v.maker) as maker, TRIM(v.model) as model, v.license_plate,";
    $usql .= " TRIM(CONCAT(COALESCE(d.firstname,''),' ',COALESCE(d.lastname,''))) as driver_name,";
    $usql .= " COALESCE(c.company_name, TRIM(CONCAT(COALESCE(c.firstname,''),' ',COALESCE(c.lastname,'')))) as customer_name";
    $usql .= " FROM ".MAIN_DB_PREFIX."flotte_booking b";
    $usql .= " LEFT JOIN ".MAIN_DB_PREFIX."flotte_vehicle v ON v.rowid = b.fk_vehicle";
    $usql .= " LEFT JOIN ".MAIN_DB_PREFIX."flotte_driver d ON d.rowid = b.fk_driver";
    $usql .= " LEFT JOIN ".MAIN_DB_PREFIX."flotte_customer c ON c.rowid = b.fk_customer";
    $usql .= " WHERE b.entity=$entity AND b.fk_vendor=".(int)$fk_vendor;
    if ($filter_status) $usql .= " AND b.status='".$db->escape($filter_status)."'";
    $usql .= " ORDER BY rec_date DESC LIMIT 400";
    $unified_rows = rpt_rows($db, $usql);

} else {
    // Generic fallback (period/status/other filters only) → bookings list
    $unified_mode = 'booking';
    $usql  = "SELECT b.rowid, b.ref, b.booking_date as rec_date, b.status,";
    $usql .= " b.selling_amount as revenue, b.buying_amount as cost, b.distance,";
    $usql .= " v.ref as vref, TRIM(v.maker) as maker, TRIM(v.model) as model, v.license_plate,";
    $usql .= " TRIM(CONCAT(COALESCE(d.firstname,''),' ',COALESCE(d.lastname,''))) as driver_name,";
    $usql .= " COALESCE(c.company_name, TRIM(CONCAT(COALESCE(c.firstname,''),' ',COALESCE(c.lastname,'')))) as customer_name,";
    $usql .= " vn.name as vendor_name";
    $usql .= " FROM ".MAIN_DB_PREFIX."flotte_booking b";
    $usql .= " LEFT JOIN ".MAIN_DB_PREFIX."flotte_vehicle v ON v.rowid = b.fk_vehicle";
    $usql .= " LEFT JOIN ".MAIN_DB_PREFIX."flotte_driver d ON d.rowid = b.fk_driver";
    $usql .= " LEFT JOIN ".MAIN_DB_PREFIX."flotte_customer c ON c.rowid = b.fk_customer";
    $usql .= " LEFT JOIN ".MAIN_DB_PREFIX."flotte_vendor vn ON vn.rowid = b.fk_vendor";
    $usql .= " WHERE 1=1".$base." ORDER BY b.booking_date DESC LIMIT 400";
    $unified_rows = rpt_rows($db, $usql);
}

// filter chips label
$filter_desc_parts = array();
if ($fk_vehicle && isset($vehicles_list[$fk_vehicle]))    $filter_desc_parts[] = '<i class="fa fa-car"></i> '.htmlspecialchars(explode(' · ',$vehicles_list[$fk_vehicle])[0]);
if ($fk_driver  && isset($drivers_list[$fk_driver]))      $filter_desc_parts[] = '<i class="fa fa-user-tie"></i> '.htmlspecialchars(explode(' · ',$drivers_list[$fk_driver])[0]);
if ($fk_customer && isset($customers_list[$fk_customer])) $filter_desc_parts[] = '<i class="fa fa-users"></i> '.htmlspecialchars(explode(' · ',$customers_list[$fk_customer])[0]);
if ($fk_vendor  && isset($vendors_list[$fk_vendor]))      $filter_desc_parts[] = '<i class="fa fa-store"></i> '.htmlspecialchars(explode(' · ',$vendors_list[$fk_vendor])[0]);
if (!empty($filter_status))       $filter_desc_parts[] = '<i class="fa fa-tag"></i> '.htmlspecialchars(ucfirst($filter_status));
if (!empty($filter_fuel_src))     $filter_desc_parts[] = '<i class="fa fa-gas-pump"></i> '.htmlspecialchars(ucfirst($filter_fuel_src));
if (!empty($filter_wo_priority))  $filter_desc_parts[] = '<i class="fa fa-flag"></i> '.htmlspecialchars($filter_wo_priority);
if (!empty($filter_vehicle_type)) $filter_desc_parts[] = '<i class="fa fa-car-side"></i> '.htmlspecialchars($filter_vehicle_type);
if (!empty($filter_min_revenue)||!empty($filter_max_revenue)) $filter_desc_parts[] = '<i class="fa fa-dollar-sign"></i> Revenue range';
if ($period != 'month') $filter_desc_parts[] = '<i class="fa fa-calendar-alt"></i> '.htmlspecialchars($period_label);

?>

<!-- ══════════════════════════════════════════════════
     UNIFIED DETAIL TABLE
     ══════════════════════════════════════════════════ -->
<style>
.det-section    { margin-top:36px; }
.det-divider    { display:flex; align-items:center; gap:14px; margin-bottom:28px; }
.det-divider-line { flex:1; height:2px; background:linear-gradient(90deg,#667eea,rgba(102,126,234,.1)); border-radius:2px; }
.det-divider-line.right { background:linear-gradient(270deg,#667eea,rgba(102,126,234,.1)); }
.det-divider h2 { font-size:18px; font-weight:800; color:#1a202c; white-space:nowrap; display:flex; align-items:center; gap:9px; margin:0; }
.det-divider h2 i { color:#667eea; }
.det-filter-chips { display:flex; flex-wrap:wrap; gap:8px; margin-bottom:22px; align-items:center; }
.det-filter-chips span.label { font-size:12px; color:#718096; font-weight:600; }
.det-chip { display:inline-flex; align-items:center; gap:6px; background:linear-gradient(135deg,rgba(102,126,234,.12),rgba(118,75,162,.12)); color:#5a4fcf; border:1px solid rgba(102,126,234,.25); padding:5px 13px; border-radius:16px; font-size:12px; font-weight:700; }
.det-chip i { font-size:11px; }
/* Unified table header bar */
.det-unified-bar { display:flex; align-items:center; justify-content:space-between; padding:14px 20px; background:#fafbff; border:1px solid #e8ecf5; border-radius:12px 12px 0 0; }
.det-unified-bar-left { display:flex; align-items:center; gap:10px; font-size:13px; color:#718096; }
.det-unified-bar-left strong { color:#1a202c; font-weight:700; font-size:15px; }
.det-unified-bar-left .det-bar-icon { width:32px; height:32px; background:rgba(102,126,234,.1); border-radius:8px; display:flex; align-items:center; justify-content:center; color:#667eea; font-size:15px; }
.det-export-btn { display:inline-flex; align-items:center; gap:6px; font-size:12px; font-weight:700; color:#667eea; background:#fff; border:1.5px solid #667eea; border-radius:8px; padding:6px 16px; cursor:pointer; transition:all .2s; text-decoration:none; }
.det-export-btn:hover { background:#667eea; color:#fff; }
/* Table */
.det-table-wrap { background:#fff; border:1px solid #e8ecf5; border-top:none; border-radius:0 0 12px 12px; box-shadow:0 2px 14px rgba(0,0,0,.06); overflow:hidden; }
.det-scroll-wrap { overflow-x:auto; }
.det-tbl        { width:100%; border-collapse:separate; border-spacing:0; font-size:13px; }
.det-tbl thead tr { background:#f7f8fc; }
.det-tbl th     { padding:10px 14px; font-size:10.5px; font-weight:800; text-transform:uppercase; letter-spacing:.08em; color:#667eea; border-bottom:2px solid #edf2f7; white-space:nowrap; }
.det-tbl td     { padding:11px 14px; border-bottom:1px solid #f7fafc; color:#2d3748; vertical-align:middle; }
.det-tbl tbody tr:last-child td { border-bottom:none; }
.det-tbl tbody tr:hover td { background:#f7f9ff; }
/* Row type badge */
.det-type-badge { display:inline-flex; align-items:center; gap:5px; padding:2px 9px; border-radius:8px; font-size:11px; font-weight:700; white-space:nowrap; }
.det-type-booking   { background:#eff6ff; color:#1d4ed8; }
.det-type-fuel      { background:#fff7ed; color:#c2410c; }
.det-type-workorder { background:#f0fdf4; color:#15803d; }
/* Shared chips */
.det-ref-link   { display:inline-flex; align-items:center; gap:7px; text-decoration:none; color:#3c4758; font-weight:700; font-size:13px; }
.det-ref-link:hover { color:#667eea; }
.det-ref-icon   { width:28px; height:28px; background:rgba(102,126,234,.1); border-radius:7px; display:flex; align-items:center; justify-content:center; color:#667eea; font-size:13px; flex-shrink:0; }
.det-veh-chip   { display:inline-flex; align-items:center; gap:5px; font-size:12px; font-weight:600; color:#3c4758; background:rgba(60,71,88,.07); padding:3px 9px; border-radius:6px; }
.det-sub        { font-size:11px; color:#a0aec0; margin-top:2px; }
.det-amount     { font-weight:700; color:#38a169; }
.det-cost       { font-weight:700; color:#e53e3e; }
.det-date-chip  { display:inline-flex; align-items:center; gap:5px; font-size:12px; color:#4a5568; background:#f0f2fa; padding:3px 9px; border-radius:6px; }
.det-status     { display:inline-flex; align-items:center; gap:5px; padding:3px 10px; border-radius:12px; font-size:11.5px; font-weight:700; }
.det-status::before { content:''; width:5px; height:5px; border-radius:50%; flex-shrink:0; }
.det-status-pending   { background:#fff8ec; color:#b45309; } .det-status-pending::before   { background:#f59e0b; }
.det-status-confirmed { background:#eff6ff; color:#1d4ed8; } .det-status-confirmed::before { background:#3b82f6; }
.det-status-in_progress,.det-status-in-progress { background:#edfaf3; color:#1a7d4a; } .det-status-in_progress::before { background:#22c55e; }
.det-status-completed,.det-status-done { background:#f0fdf4; color:#15803d; } .det-status-completed::before { background:#16a34a; }
.det-status-cancelled,.det-status-canceled { background:#fef2f2; color:#b91c1c; } .det-status-cancelled::before { background:#ef4444; }
.det-status-paid   { background:#f0fdf4; color:#15803d; } .det-status-paid::before { background:#16a34a; }
.det-status-open   { background:#eff6ff; color:#1d4ed8; } .det-status-open::before { background:#3b82f6; }
.det-priority { display:inline-block; padding:2px 9px; border-radius:10px; font-size:11px; font-weight:700; }
.det-priority-Critical { background:#fed7d7; color:#742a2a; }
.det-priority-High     { background:#feebcb; color:#7b341e; }
.det-priority-Medium   { background:#fefcbf; color:#744210; }
.det-priority-Low      { background:#c6f6d5; color:#276749; }
.det-empty { text-align:center; padding:48px; color:#a0aec0; }
.det-empty i { font-size:40px; opacity:.25; display:block; margin-bottom:10px; }
.det-action-btn { width:28px; height:28px; border-radius:6px; display:inline-flex; align-items:center; justify-content:center; text-decoration:none; font-size:12px; transition:all .15s; }
.det-action-btn.view { color:#3c4758; background:#eaecf0; }
.det-action-btn:hover { transform:translateY(-1px); box-shadow:0 2px 6px rgba(0,0,0,.12); text-decoration:none; }
</style>

<div class="det-section">
    <div class="det-divider">
        <div class="det-divider-line"></div>
        <h2><i class="fa fa-<?php echo $filter_icon; ?>"></i> <?php echo $filter_label ? htmlspecialchars($filter_label).' — All Records' : 'Filtered Records'; ?></h2>
        <div class="det-divider-line right"></div>
    </div>

    <div class="det-filter-chips">
        <span class="label">Showing results for:</span>
        <?php foreach ($filter_desc_parts as $part): ?>
        <span class="det-chip"><?php echo $part; ?></span>
        <?php endforeach; ?>
    </div>

    <!-- Unified table -->
    <div class="det-unified-bar">
        <div class="det-unified-bar-left">
            <span class="det-bar-icon"><i class="fa fa-<?php echo $filter_icon; ?>"></i></span>
            <div><strong><?php echo count($unified_rows); ?></strong> record<?php echo count($unified_rows)!=1?'s':''; ?> found<?php if(count($unified_rows)==400) echo ' (showing first 400)'; ?></div>
        </div>
        <button class="det-export-btn" onclick="detExportUnified()"><i class="fa fa-file-csv"></i> Export CSV</button>
    </div>

    <div class="det-table-wrap">
    <?php if (empty($unified_rows)): ?>
        <div class="det-empty"><i class="fa fa-search"></i><p>No records match the current filters.</p></div>
    <?php else: ?>
    <div class="det-scroll-wrap">
    <table class="det-tbl" id="det-unified-tbl">
        <thead>
            <tr>
                <th>Type</th>
                <th>Ref</th>
                <th>Date</th>
                <?php if ($unified_mode === 'vehicle'): ?>
                <th>Driver</th>
                <th>Customer</th>
                <th>Vendor</th>
                <?php elseif ($unified_mode === 'driver'): ?>
                <th>Vehicle</th>
                <th>Customer / Task</th>
                <th>Vendor</th>
                <?php elseif ($unified_mode === 'customer'): ?>
                <th>Vehicle</th>
                <th>Driver</th>
                <th>Vendor</th>
                <?php elseif ($unified_mode === 'vendor'): ?>
                <th>Vehicle</th>
                <th>Driver</th>
                <th>Customer</th>
                <?php else: ?>
                <th>Vehicle</th>
                <th>Driver</th>
                <th>Customer</th>
                <th>Vendor</th>
                <?php endif; ?>
                <th style="text-align:right">Amount</th>
                <th style="text-align:center">Status / Priority</th>
                <th style="text-align:center">View</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($unified_rows as $row):
            $rec_type = isset($row->rec_type) ? $row->rec_type : 'booking';
            $status_key = 'det-status-'.str_replace('_','-', strtolower($row->status ?: 'pending'));
            $link = '#';
            $icon = 'fa-calendar';
            if ($rec_type === 'booking')   { $link = 'booking_card.php?id='.(int)$row->rowid;   $icon = 'fa-calendar-check'; }
            if ($rec_type === 'fuel')      { $link = 'fuel_card.php?id='.(int)$row->rowid;       $icon = 'fa-gas-pump'; }
            if ($rec_type === 'workorder') { $link = 'workorder_card.php?id='.(int)$row->rowid;  $icon = 'fa-tools'; }
            $pricls = isset($row->priority) && in_array($row->priority,['Critical','High','Medium','Low']) ? 'det-priority-'.$row->priority : '';
        ?>
        <tr>
            <td>
                <?php if ($rec_type === 'booking'):   ?><span class="det-type-badge det-type-booking"><i class="fa fa-calendar-check" style="font-size:10px;"></i> Booking</span>
                <?php elseif ($rec_type === 'fuel'):  ?><span class="det-type-badge det-type-fuel"><i class="fa fa-gas-pump" style="font-size:10px;"></i> Fuel</span>
                <?php else:                           ?><span class="det-type-badge det-type-workorder"><i class="fa fa-tools" style="font-size:10px;"></i> Work Order</span>
                <?php endif; ?>
            </td>
            <td>
                <a href="<?php echo $link; ?>" class="det-ref-link">
                    <span class="det-ref-icon"><i class="fa <?php echo $icon; ?>"></i></span>
                    <?php echo htmlspecialchars($row->ref); ?>
                </a>
            </td>
            <td>
                <?php if (!empty($row->rec_date)): ?>
                <span class="det-date-chip"><i class="fa fa-calendar-day" style="font-size:10px;opacity:.6;"></i><?php echo htmlspecialchars(substr($row->rec_date,0,10)); ?></span>
                <?php else: echo '<span style="color:#c4c9d8;">—</span>'; endif; ?>
            </td>

            <?php if ($unified_mode === 'vehicle'): ?>
            <td><?php echo !empty($row->driver_name) ? '<span style="font-weight:500;">'.htmlspecialchars(trim($row->driver_name)).'</span>' : '<span style="color:#c4c9d8;">—</span>'; ?></td>
            <td>
                <?php if ($rec_type === 'workorder'): ?>
                    <span style="font-size:12px;color:#4a5568;"><?php echo htmlspecialchars(mb_strimwidth($row->task_display ?: '—', 0, 50, '…')); ?></span>
                <?php else: ?>
                    <?php echo !empty($row->customer_name) ? htmlspecialchars(trim($row->customer_name)) : '<span style="color:#c4c9d8;">—</span>'; ?>
                <?php endif; ?>
            </td>
            <td><?php echo !empty($row->vendor_name) ? htmlspecialchars($row->vendor_name) : (!empty($row->fuel_source) ? '<span class="sbadge sbadge-info">'.htmlspecialchars(ucfirst($row->fuel_source)).'</span>' : '<span style="color:#c4c9d8;">—</span>'); ?></td>

            <?php elseif ($unified_mode === 'driver'): ?>
            <td>
                <?php if (!empty($row->vref)): ?>
                <span class="det-veh-chip"><i class="fa fa-car" style="font-size:10px;opacity:.6;"></i><?php echo htmlspecialchars($row->vref); ?></span>
                <?php if (!empty($row->maker) || !empty($row->model)): ?><div class="det-sub"><?php echo htmlspecialchars(trim($row->maker.' '.$row->model)); ?></div><?php endif; ?>
                <?php else: echo '<span style="color:#c4c9d8;">—</span>'; endif; ?>
            </td>
            <td>
                <?php if ($rec_type === 'workorder'): ?>
                    <span style="font-size:12px;color:#4a5568;"><?php echo htmlspecialchars(mb_strimwidth($row->task_display ?: '—', 0, 50, '…')); ?></span>
                <?php else: ?>
                    <?php echo !empty($row->customer_name) ? htmlspecialchars(trim($row->customer_name)) : '<span style="color:#c4c9d8;">—</span>'; ?>
                <?php endif; ?>
            </td>
            <td><?php echo !empty($row->vendor_name) ? htmlspecialchars($row->vendor_name) : '<span style="color:#c4c9d8;">—</span>'; ?></td>

            <?php elseif ($unified_mode === 'customer'): ?>
            <td>
                <?php if (!empty($row->vref)): ?>
                <span class="det-veh-chip"><i class="fa fa-car" style="font-size:10px;opacity:.6;"></i><?php echo htmlspecialchars($row->vref); ?></span>
                <?php if (!empty($row->maker) || !empty($row->model)): ?><div class="det-sub"><?php echo htmlspecialchars(trim($row->maker.' '.$row->model)); ?></div><?php endif; ?>
                <?php else: echo '<span style="color:#c4c9d8;">—</span>'; endif; ?>
            </td>
            <td><?php echo !empty($row->driver_name) ? '<span style="font-weight:500;">'.htmlspecialchars(trim($row->driver_name)).'</span>' : '<span style="color:#c4c9d8;">—</span>'; ?></td>
            <td><?php echo !empty($row->vendor_name) ? htmlspecialchars($row->vendor_name) : '<span style="color:#c4c9d8;">—</span>'; ?></td>

            <?php elseif ($unified_mode === 'vendor'): ?>
            <td>
                <?php if (!empty($row->vref)): ?>
                <span class="det-veh-chip"><i class="fa fa-car" style="font-size:10px;opacity:.6;"></i><?php echo htmlspecialchars($row->vref); ?></span>
                <?php if (!empty($row->maker) || !empty($row->model)): ?><div class="det-sub"><?php echo htmlspecialchars(trim($row->maker.' '.$row->model)); ?></div><?php endif; ?>
                <?php else: echo '<span style="color:#c4c9d8;">—</span>'; endif; ?>
            </td>
            <td><?php echo !empty($row->driver_name) ? '<span style="font-weight:500;">'.htmlspecialchars(trim($row->driver_name)).'</span>' : '<span style="color:#c4c9d8;">—</span>'; ?></td>
            <td><?php echo !empty($row->customer_name) ? htmlspecialchars(trim($row->customer_name)) : '<span style="color:#c4c9d8;">—</span>'; ?></td>

            <?php else: /* generic booking mode */ ?>
            <td>
                <?php if (!empty($row->vref)): ?>
                <span class="det-veh-chip"><i class="fa fa-car" style="font-size:10px;opacity:.6;"></i><?php echo htmlspecialchars($row->vref); ?></span>
                <?php if (!empty($row->maker) || !empty($row->model)): ?><div class="det-sub"><?php echo htmlspecialchars(trim($row->maker.' '.$row->model)); ?></div><?php endif; ?>
                <?php else: echo '<span style="color:#c4c9d8;">—</span>'; endif; ?>
            </td>
            <td><?php echo !empty($row->driver_name) ? '<span style="font-weight:500;">'.htmlspecialchars(trim($row->driver_name)).'</span>' : '<span style="color:#c4c9d8;">—</span>'; ?></td>
            <td><?php echo !empty($row->customer_name) ? htmlspecialchars(trim($row->customer_name)) : '<span style="color:#c4c9d8;">—</span>'; ?></td>
            <td><?php echo !empty($row->vendor_name) ? htmlspecialchars($row->vendor_name) : '<span style="color:#c4c9d8;">—</span>'; ?></td>
            <?php endif; ?>

            <td style="text-align:right">
                <?php
                if ($rec_type === 'fuel' && !empty($row->total_cost)) {
                    echo '<span class="det-cost">'.rpt_fmt($row->total_cost,2).'</span>';
                } elseif ($rec_type === 'workorder' && !empty($row->wo_price)) {
                    echo '<span class="det-amount">'.rpt_fmt($row->wo_price,2).'</span>';
                } elseif (!empty($row->revenue)) {
                    echo '<span class="det-amount">'.rpt_fmt($row->revenue,2).'</span>';
                    if (!empty($row->distance)) echo '<div class="det-sub">'.rpt_fmt($row->distance).' km</div>';
                } else {
                    echo '<span style="color:#c4c9d8;">—</span>';
                }
                ?>
            </td>
            <td style="text-align:center">
                <?php
                if ($rec_type === 'workorder' && !empty($row->priority) && $pricls) {
                    echo '<span class="det-priority '.htmlspecialchars($pricls).'">'.htmlspecialchars($row->priority).'</span>';
                } elseif (!empty($row->status)) {
                    echo '<span class="det-status '.htmlspecialchars($status_key).'">'.htmlspecialchars(ucfirst(str_replace('_',' ',$row->status))).'</span>';
                } else {
                    echo '<span style="color:#c4c9d8;">—</span>';
                }
                ?>
            </td>
            <td style="text-align:center">
                <a href="<?php echo $link; ?>" class="det-action-btn view"><i class="fa fa-eye"></i></a>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
    </div>

</div><!-- /det-section -->

<script>
function detExportUnified() {
    var tbl = document.getElementById('det-unified-tbl');
    if (!tbl) return;
    var rows = tbl.querySelectorAll('tr');
    var csv  = [];
    rows.forEach(function(row) {
        var cells = row.querySelectorAll('th, td');
        var line  = [];
        cells.forEach(function(cell, i) {
            if (i === cells.length - 1) return; // skip View column
            var txt = cell.innerText.replace(/\s+/g,' ').trim().replace(/"/g,'""');
            line.push('"' + txt + '"');
        });
        csv.push(line.join(','));
    });
    var blob = new Blob([csv.join('\n')], { type:'text/csv;charset=utf-8;' });
    var a    = document.createElement('a');
    a.href   = URL.createObjectURL(blob);
    a.download = 'records_export.csv';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(a.href);
}
</script>

<?php endif; // end $any_filter ?>

<?php
print '</div>';
llxFooter();
$db->close();
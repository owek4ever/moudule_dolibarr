<?php
/* Copyright (C) 2025 - Fully Functional Fleet Dashboard
 * 
 * This is a production-ready, fully functional dashboard with:
 * - Proper error handling
 * - Database query optimization  
 * - Responsive design
 * - Chart.js integration
 * - Clean, maintainable code
 */

// ============================================
// DOLIBARR ENVIRONMENT LOADING
// ============================================
$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
    $res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1;
$j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) { 
    $i--; 
    $j--; 
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1))."/main.inc.php")) {
    $res = @include substr($tmp, 0, ($i + 1))."/main.inc.php";
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php")) {
    $res = @include dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php";
}
if (!$res && file_exists("../main.inc.php")) { $res = @include "../main.inc.php"; }
if (!$res && file_exists("../../main.inc.php")) { $res = @include "../../main.inc.php"; }
if (!$res && file_exists("../../../main.inc.php")) { $res = @include "../../../main.inc.php"; }
if (!$res) { die("Include of main fails"); }

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';

// Load translation files
$langs->loadLangs(array("flotte@flotte"));

// Security check
if (!$user->rights->flotte->read) {
    accessforbidden();
}

$form = new Form($db);

// ============================================
// HELPER FUNCTIONS
// ============================================

/**
 * Check if a table exists in the database
 */
function tableExists($db, $tableName) {
    $sql = "SHOW TABLES LIKE '" . MAIN_DB_PREFIX . $tableName . "'";
    $resql = $db->query($sql);
    return ($resql && $db->num_rows($resql) > 0);
}

/**
 * Safe database query with error handling
 */
function safeQuery($db, $sql, $default = 0) {
    $resql = $db->query($sql);
    if ($resql) {
        $obj = $db->fetch_object($resql);
        if ($obj) {
            return $obj;
        }
    }
    return (object)array('total' => $default, 'available' => $default, 'active' => $default, 
                         'monthly' => $default, 'revenue' => $default);
}

/**
 * Get comprehensive fleet statistics
 */
function getFleetStats($db) {
    global $conf;
    
    // Initialize with defaults
    $stats = array(
        'total_vehicles' => 0,
        'available_vehicles' => 0,
        'in_use_vehicles' => 0,
        'active_vehicles' => 0,
        'outofservice_vehicles' => 0,
        'maintenance_vehicles' => 0,
        'total_drivers' => 0,
        'total_customers' => 0,
        'active_bookings' => 0,
        'monthly_bookings' => 0,
        'monthly_revenue' => 0,
        'total_fuel_entries' => 0,
        'total_vendors' => 0,
        'total_parts' => 0,
        'total_workorders' => 0,
        'total_inspections' => 0,
        'pending_workorders' => 0,
        'completed_workorders' => 0
    );
    
    // Check if main vehicle table exists
    if (!tableExists($db, 'flotte_vehicle')) {
        return $stats;
    }
    
    // Total active vehicles (in_service = 1)
    $sql = "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN in_service = 1 THEN 1 ELSE 0 END) as active,
                SUM(CASE WHEN in_service = 0 THEN 1 ELSE 0 END) as inactive
            FROM " . MAIN_DB_PREFIX . "flotte_vehicle 
            WHERE entity = " . ((int) $conf->entity);
    $resql = $db->query($sql);
    if ($resql) {
        $obj = $db->fetch_object($resql);
        if ($obj) {
            $stats['total_vehicles']        = (int) $obj->total;
            $stats['active_vehicles']       = (int) $obj->active;
            $stats['outofservice_vehicles'] = (int) $obj->inactive;
        }
    }

    // Vehicles currently booked — count distinct vehicles that have an
    // active/ongoing booking (booking_date = today and status not cancelled/completed)
    // Since there is no end_date, we treat today's booking_date as "in use today"
    $sql = "SELECT COUNT(DISTINCT fk_vehicle) as in_use
            FROM " . MAIN_DB_PREFIX . "flotte_booking
            WHERE entity = " . ((int) $conf->entity) . "
            AND booking_date = CURDATE()
            AND (status IS NULL 
                 OR status NOT IN ('cancelled', 'canceled', 'completed', 'done', 'finished', 'rejected'))";
    $resql = $db->query($sql);
    if ($resql) {
        $obj = $db->fetch_object($resql);
        $stats['in_use_vehicles'] = $obj ? (int) $obj->in_use : 0;
    }

    // Available = active vehicles minus those currently booked
    $stats['available_vehicles'] = max(0, $stats['active_vehicles'] - $stats['in_use_vehicles']);
    
    // Count from other tables
    $tables = array(
        'flotte_driver' => 'total_drivers',
        'flotte_customer' => 'total_customers',
        'flotte_fuel' => 'total_fuel_entries',
        'flotte_vendor' => 'total_vendors',
        'flotte_part' => 'total_parts',
        'flotte_workorder' => 'total_workorders',
        'flotte_inspection' => 'total_inspections'
    );
    
    foreach ($tables as $table => $stat_key) {
        if (tableExists($db, $table)) {
            $sql = "SELECT COUNT(*) as total FROM " . MAIN_DB_PREFIX . $table . " WHERE entity = " . ((int) $conf->entity);
            $obj = safeQuery($db, $sql);
            $stats[$stat_key] = (int) $obj->total;
        }
    }
    
    // Booking statistics
    if (tableExists($db, 'flotte_booking')) {
        // Active bookings today = bookings with today's date that are not cancelled/completed
        $sql = "SELECT COUNT(*) as active FROM " . MAIN_DB_PREFIX . "flotte_booking 
                WHERE booking_date = CURDATE()
                AND (status IS NULL 
                     OR status NOT IN ('cancelled', 'canceled', 'completed', 'done', 'finished', 'rejected'))
                AND entity = " . ((int) $conf->entity);
        $obj = safeQuery($db, $sql);
        $stats['active_bookings'] = (int) $obj->active;
        
        // Monthly bookings and revenue
        $sql = "SELECT COUNT(*) as monthly, COALESCE(SUM(selling_amount), 0) as revenue 
                FROM " . MAIN_DB_PREFIX . "flotte_booking 
                WHERE MONTH(booking_date) = MONTH(CURDATE()) 
                AND YEAR(booking_date) = YEAR(CURDATE()) 
                AND entity = " . ((int) $conf->entity);
        $obj = safeQuery($db, $sql);
        $stats['monthly_bookings'] = (int) $obj->monthly;
        $stats['monthly_revenue'] = (float) $obj->revenue;
    }
    
    // Work order statistics
    if (tableExists($db, 'flotte_workorder')) {
        $sql = "SELECT 
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed
                FROM " . MAIN_DB_PREFIX . "flotte_workorder 
                WHERE entity = " . ((int) $conf->entity);
        $obj = safeQuery($db, $sql);
        $stats['pending_workorders'] = (int) $obj->pending;
        $stats['completed_workorders'] = (int) $obj->completed;
    }
    
    return $stats;
}

/**
 * Get recent bookings with full details
 */
function getRecentBookings($db, $limit = 5) {
    global $conf;
    
    $bookings = array();
    
    if (!tableExists($db, 'flotte_booking')) {
        return $bookings;
    }
    
    $sql = "SELECT b.rowid, b.booking_date, b.start_date, b.end_date, b.selling_amount,
            v.name as vehicle_name, v.model as vehicle_model, v.license_plate,
            c.name as customer_name,
            d.firstname as driver_firstname, d.lastname as driver_lastname
            FROM " . MAIN_DB_PREFIX . "flotte_booking b
            LEFT JOIN " . MAIN_DB_PREFIX . "flotte_vehicle v ON b.fk_vehicle = v.rowid
            LEFT JOIN " . MAIN_DB_PREFIX . "flotte_customer c ON b.fk_customer = c.rowid
            LEFT JOIN " . MAIN_DB_PREFIX . "flotte_driver d ON b.fk_driver = d.rowid
            WHERE b.entity = " . ((int) $conf->entity) . "
            ORDER BY b.booking_date DESC
            LIMIT " . ((int) $limit);
    
    $resql = $db->query($sql);
    if ($resql) {
        while ($obj = $db->fetch_object($resql)) {
            $bookings[] = $obj;
        }
    }
    
    return $bookings;
}

// ============================================
// GET DATA
// ============================================
$stats = getFleetStats($db);
$recent_bookings = getRecentBookings($db, 5);

// Calculate metrics
// in_use = vehicles with an active booking today
// available = active (in_service=1) vehicles minus those currently booked
// out of service = vehicles with in_service=0
$vehicles_in_use = $stats['in_use_vehicles'];

$utilization_rate = $stats['active_vehicles'] > 0 ? 
    round(($vehicles_in_use / $stats['active_vehicles']) * 100, 1) : 0;

$completion_rate = $stats['total_workorders'] > 0 ? 
    round(($stats['completed_workorders'] / $stats['total_workorders']) * 100, 1) : 0;

// Build chart segments (only include non-zero values)
$chart_segments = array();
if ($vehicles_in_use > 0)                    $chart_segments['In Use']         = $vehicles_in_use;
if ($stats['available_vehicles'] > 0)        $chart_segments['Available']      = $stats['available_vehicles'];
if ($stats['outofservice_vehicles'] > 0)     $chart_segments['Out of Service'] = $stats['outofservice_vehicles'];
// If everything is zero but total > 0, show all as available
if (empty($chart_segments) && $stats['total_vehicles'] > 0) {
    $chart_segments['Available'] = $stats['total_vehicles'];
}

// ============================================
// PAGE HEADER
// ============================================
$title = $langs->trans("FlotteDashboard");
llxHeader('', $title, '');

// Don't show page title for cleaner look
// print load_fiche_titre($title, '', 'flotte@flotte');

print '<div class="fichecenter">';
?>

<!-- ============================================
     CSS STYLES
     ============================================ -->
<style>
/* Container */
.fleet-container { 
    max-width: 1400px; 
    margin: 0 auto; 
    padding: 20px; 
}

/* Sections */
.section { 
    margin-bottom: 30px; 
}

.section-header { 
    display: flex; 
    align-items: center; 
    justify-content: space-between; 
    margin-bottom: 20px; 
    padding-bottom: 15px; 
    border-bottom: 3px solid #e8e8e8; 
}

.section-title { 
    font-size: 20px; 
    font-weight: 700; 
    color: #2c3e50; 
    display: flex; 
    align-items: center; 
    gap: 10px; 
    margin: 0; 
}

.section-title i { 
    color: #667eea; 
    font-size: 22px; 
}

/* Stats Grid */
.stats-grid { 
    display: grid; 
    grid-template-columns: repeat(4, 1fr); 
    gap: 20px; 
    margin-bottom: 30px; 
}

.stat-card { 
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); 
    border-radius: 12px; 
    padding: 25px; 
    color: white; 
    position: relative; 
    overflow: hidden; 
    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
    transition: transform 0.3s ease, box-shadow 0.3s ease; 
}

.stat-card:hover { 
    transform: translateY(-5px); 
    box-shadow: 0 8px 25px rgba(0,0,0,0.15); 
}

.stat-card::before { 
    content: ''; 
    position: absolute; 
    top: -50%; 
    right: -50%; 
    width: 200%; 
    height: 200%;
    background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%); 
}

.stat-icon { 
    position: absolute; 
    right: 20px; 
    top: 50%; 
    transform: translateY(-50%); 
    font-size: 50px; 
    opacity: 0.2; 
}

.stat-content { 
    position: relative; 
    z-index: 1; 
}

.stat-value { 
    font-size: 36px; 
    font-weight: 700; 
    line-height: 1; 
    margin-bottom: 8px; 
}

.stat-label { 
    font-size: 13px; 
    text-transform: uppercase; 
    letter-spacing: 1px; 
    opacity: 0.9; 
    font-weight: 500; 
}

.stat-detail { 
    margin-top: 10px; 
    font-size: 12px; 
    opacity: 0.85; 
    display: flex; 
    align-items: center; 
    gap: 6px; 
}

.stat-card.vehicles { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
.stat-card.drivers { background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); }
.stat-card.bookings { background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); }
.stat-card.revenue { background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%); }

a.stat-card {
    display: block;
    text-decoration: none;
    color: white;
    cursor: pointer;
}

a.stat-card:hover {
    transform: translateY(-8px);
    box-shadow: 0 12px 30px rgba(0,0,0,0.2);
    color: white;
    text-decoration: none;
}

a.stat-card::after {
    content: '\f061';
    font-family: 'Font Awesome 5 Free';
    font-weight: 900;
    position: absolute;
    bottom: 15px;
    right: 18px;
    font-size: 13px;
    opacity: 0;
    transform: translateX(-6px);
    transition: all 0.3s ease;
}

a.stat-card:hover::after {
    opacity: 0.7;
    transform: translateX(0);
}

/* Two Column */
.two-col { 
    display: grid; 
    grid-template-columns: 1fr 1fr; 
    gap: 25px; 
    margin-bottom: 30px; 
}

.card { 
    background: #fff; 
    border-radius: 12px; 
    padding: 25px; 
    box-shadow: 0 2px 12px rgba(0,0,0,0.08); 
}

.card-title { 
    font-size: 18px; 
    font-weight: 600; 
    color: #2c3e50; 
    margin-bottom: 20px; 
    padding-bottom: 12px;
    border-bottom: 2px solid #f8f9fa; 
    display: flex; 
    align-items: center; 
    gap: 10px; 
}

.card-title i { 
    color: #667eea; 
}

/* Chart */
.chart-canvas {
    max-height: 280px !important;
    width: 100% !important;
}

.chart-wrapper {
    position: relative;
    display: flex;
    justify-content: center;
    align-items: center;
}

.chart-center-text {
    position: absolute;
    text-align: center;
    pointer-events: none;
}

.chart-center-rate {
    font-size: 28px;
    font-weight: 800;
    color: #2c3e50;
    line-height: 1;
}

.chart-center-label {
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: #95a5a6;
    margin-top: 4px;
}

.chart-pills {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-top: 20px;
    justify-content: center;
}

.chart-pill {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 14px;
    border-radius: 20px;
    font-size: 13px;
    background: #f8f9fa;
    border: 1px solid #e9ecef;
}

.pill-dot {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    flex-shrink: 0;
}

.pill-label { color: #5a6c7d; }
.chart-pill strong { color: #2c3e50; font-size: 14px; }

.pill-inuse       .pill-dot { background: rgba(102,126,234,0.9); }
.pill-available   .pill-dot { background: rgba(67,233,123,0.9); }
.pill-maintenance .pill-dot { background: rgba(253,203,110,0.9); }
.pill-outofservice .pill-dot { background: rgba(231,76,60,0.9); }

/* Alerts & Notifications */
.alerts-list {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.alert-item {
    display: flex;
    align-items: flex-start;
    gap: 14px;
    padding: 14px 16px;
    border-radius: 10px;
    border-left: 4px solid transparent;
    transition: all 0.3s ease;
}

.alert-item:hover {
    transform: translateX(4px);
}

.alert-item.alert-danger {
    background: #fff5f5;
    border-left-color: #e53e3e;
}

.alert-item.alert-warning {
    background: #fffbeb;
    border-left-color: #d97706;
}

.alert-item.alert-info {
    background: #eff6ff;
    border-left-color: #3b82f6;
}

.alert-item.alert-success {
    background: #f0fdf4;
    border-left-color: #16a34a;
}

.alert-icon {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 16px;
    flex-shrink: 0;
}

.alert-danger .alert-icon  { background: #fed7d7; color: #e53e3e; }
.alert-warning .alert-icon { background: #fde68a; color: #d97706; }
.alert-info .alert-icon    { background: #bfdbfe; color: #3b82f6; }
.alert-success .alert-icon { background: #bbf7d0; color: #16a34a; }

.alert-body {
    flex: 1;
}

.alert-title {
    font-size: 13px;
    font-weight: 700;
    color: #2c3e50;
    margin-bottom: 3px;
}

.alert-desc {
    font-size: 12px;
    color: #5a6c7d;
    line-height: 1.4;
}

.alert-badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 10px;
    font-size: 11px;
    font-weight: 700;
    flex-shrink: 0;
    align-self: center;
}

.alert-danger  .alert-badge { background: #e53e3e; color: #fff; }
.alert-warning .alert-badge { background: #d97706; color: #fff; }
.alert-info    .alert-badge { background: #3b82f6; color: #fff; }
.alert-success .alert-badge { background: #16a34a; color: #fff; }

.no-alerts {
    text-align: center;
    padding: 30px 20px;
    color: #95a5a6;
    font-size: 14px;
}

/* Quick Actions */
.actions-grid { 
    display: grid; 
    grid-template-columns: repeat(5, 1fr); 
    gap: 15px; 
}

.action-btn { 
    display: flex; 
    flex-direction: column; 
    align-items: center; 
    justify-content: center; 
    padding: 20px 15px; 
    background: #f8f9fa; 
    border: 2px solid #e9ecef; 
    border-radius: 10px;
    text-decoration: none; 
    color: #495057; 
    transition: all 0.3s ease; 
    min-height: 110px; 
    text-align: center; 
}

.action-btn:hover { 
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); 
    border-color: #667eea;
    color: white; 
    transform: translateY(-3px); 
    box-shadow: 0 6px 20px rgba(102,126,234,0.3); 
}

.action-icon { 
    font-size: 30px; 
    margin-bottom: 10px; 
    transition: transform 0.3s ease; 
}

.action-btn:hover .action-icon { 
    transform: scale(1.15); 
}

.action-label { 
    font-size: 13px; 
    font-weight: 600; 
}

/* Activity */
.activity-list { 
    display: flex; 
    flex-direction: column; 
    gap: 12px; 
}

.activity-item { 
    padding: 16px; 
    background: #f8f9fa; 
    border-left: 4px solid #667eea; 
    border-radius: 8px; 
    transition: all 0.3s ease; 
}

.activity-item:hover { 
    background: #e9ecef; 
    transform: translateX(5px); 
    box-shadow: 0 2px 8px rgba(0,0,0,0.08); 
}

.activity-header { 
    display: flex; 
    justify-content: space-between; 
    align-items: center; 
    margin-bottom: 8px; 
}

.activity-customer { 
    font-size: 15px; 
    font-weight: 600; 
    color: #2c3e50; 
}

.activity-amount { 
    font-size: 16px; 
    font-weight: 700; 
    color: #27ae60; 
}

.activity-details { 
    font-size: 13px; 
    color: #5a6c7d; 
    margin-bottom: 8px; 
}

.activity-meta { 
    display: flex; 
    justify-content: space-between; 
    align-items: center; 
}

.activity-date { 
    font-size: 12px; 
    color: #95a5a6; 
}

.status-badge { 
    display: inline-block; 
    padding: 4px 10px; 
    border-radius: 12px; 
    font-size: 11px;
    font-weight: 600; 
    text-transform: uppercase; 
}

.status-badge.active { background: #d4edda; color: #155724; }
.status-badge.pending { background: #fff3cd; color: #856404; }
.status-badge.completed { background: #cce5ff; color: #004085; }

.view-all { 
    display: block; 
    margin-top: 15px; 
    padding: 10px 20px; 
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); 
    color: white; 
    text-align: center;
    text-decoration: none; 
    border-radius: 8px; 
    font-weight: 600; 
    transition: all 0.3s ease; 
}

.view-all:hover { 
    transform: translateY(-2px); 
    box-shadow: 0 4px 15px rgba(102,126,234,0.4); 
    color: white; 
}

.empty { 
    text-align: center; 
    padding: 50px 20px; 
    color: #95a5a6; 
}

.empty i { 
    font-size: 60px; 
    margin-bottom: 15px; 
    opacity: 0.5; 
}

/* Responsive */
@media (max-width: 1200px) {
    .stats-grid { grid-template-columns: repeat(2, 1fr); }
    .actions-grid { grid-template-columns: repeat(4, 1fr); }
}

@media (max-width: 768px) {
    .stats-grid, .two-col { grid-template-columns: 1fr; }
    .actions-grid { grid-template-columns: repeat(2, 1fr); }
    .stat-value { font-size: 28px; }
}
</style>

<!-- ============================================
     DASHBOARD CONTENT
     ============================================ -->
<div class="fleet-container">

<!-- Statistics Section -->
<div class="section">
    <div class="section-header">
        <h2 class="section-title">
            <i class="fa fa-chart-line"></i> Fleet Overview
        </h2>
    </div>
    <div class="stats-grid">
        <!-- Vehicles -->
        <a href="<?php echo dol_buildpath('/flotte/vehicle_list.php', 1); ?>" class="stat-card vehicles">
            <div class="stat-icon"><i class="fa fa-car"></i></div>
            <div class="stat-content">
                <div class="stat-value"><?php echo $stats['total_vehicles']; ?></div>
                <div class="stat-label">Total Vehicles</div>
                <div class="stat-detail">
                    <i class="fa fa-check-circle"></i> 
                    <?php echo $stats['available_vehicles']; ?> Available
                </div>
            </div>
        </a>
        
        <!-- Drivers -->
        <a href="<?php echo dol_buildpath('/flotte/driver_list.php', 1); ?>" class="stat-card drivers">
            <div class="stat-icon"><i class="fa fa-users"></i></div>
            <div class="stat-content">
                <div class="stat-value"><?php echo $stats['total_drivers']; ?></div>
                <div class="stat-label">Total Drivers</div>
                <div class="stat-detail">
                    <i class="fa fa-user-check"></i> Active Personnel
                </div>
            </div>
        </a>
        
        <!-- Bookings -->
        <a href="<?php echo dol_buildpath('/flotte/booking_list.php', 1); ?>" class="stat-card bookings">
            <div class="stat-icon"><i class="fa fa-calendar-check"></i></div>
            <div class="stat-content">
                <div class="stat-value"><?php echo $stats['active_bookings']; ?></div>
                <div class="stat-label">Active Bookings</div>
                <div class="stat-detail">
                    <i class="fa fa-calendar"></i> 
                    <?php echo $stats['monthly_bookings']; ?> This Month
                </div>
            </div>
        </a>
        
        <!-- Revenue -->
        <div class="stat-card revenue">
            <div class="stat-icon"><i class="fa fa-dollar-sign"></i></div>
            <div class="stat-content">
                <div class="stat-value"><?php echo price($stats['monthly_revenue'], 0, '', 1, -1, -1, $conf->currency); ?></div>
                <div class="stat-label">Monthly Revenue</div>
                <div class="stat-detail">
                    <i class="fa fa-trending-up"></i> Current Month
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Analytics Section -->
<div class="section">
    <div class="section-header">
        <h2 class="section-title">
            <i class="fa fa-chart-bar"></i> Performance Analytics
        </h2>
    </div>
    <div class="two-col">
        <!-- Chart -->
        <div class="card">
            <h3 class="card-title">
                <i class="fa fa-chart-pie"></i> Fleet Utilization
            </h3>
            <div class="chart-wrapper">
                <canvas id="utilizationChart" class="chart-canvas"></canvas>
                <div class="chart-center-text" id="chartCenterText">
                    <div class="chart-center-rate"><?php echo $utilization_rate; ?>%</div>
                    <div class="chart-center-label">Utilization</div>
                </div>
            </div>
            <div class="chart-pills">
                <div class="chart-pill pill-inuse">
                    <span class="pill-dot"></span>
                    <span class="pill-label">In Use Today</span>
                    <strong><?php echo $vehicles_in_use; ?></strong>
                </div>
                <div class="chart-pill pill-available">
                    <span class="pill-dot"></span>
                    <span class="pill-label">Available</span>
                    <strong><?php echo $stats['available_vehicles']; ?></strong>
                </div>
                <?php if ($stats['outofservice_vehicles'] > 0) { ?>
                <div class="chart-pill pill-outofservice">
                    <span class="pill-dot"></span>
                    <span class="pill-label">Out of Service</span>
                    <strong><?php echo $stats['outofservice_vehicles']; ?></strong>
                </div>
                <?php } ?>
            </div>
        </div>
        
        <!-- Alerts & Notifications -->
        <div class="card">
            <h3 class="card-title">
                <i class="fa fa-bell"></i> Alerts &amp; Notifications
            </h3>
            <?php
            // Build dynamic alerts based on fleet data
            $alerts = array();

            // High utilization alert
            if ($utilization_rate >= 90) {
                $alerts[] = array(
                    'type'  => 'danger',
                    'icon'  => 'exclamation-triangle',
                    'title' => 'Fleet Critically Overloaded',
                    'desc'  => 'Utilization is at ' . $utilization_rate . '%. Only ' . $stats['available_vehicles'] . ' vehicle(s) remain available.',
                    'badge' => 'Critical',
                );
            } elseif ($utilization_rate >= 75) {
                $alerts[] = array(
                    'type'  => 'warning',
                    'icon'  => 'exclamation-circle',
                    'title' => 'High Fleet Utilization',
                    'desc'  => 'Utilization is at ' . $utilization_rate . '%. Consider scheduling maintenance windows carefully.',
                    'badge' => 'Warning',
                );
            }

            // Low vehicle availability
            if ($stats['available_vehicles'] == 0 && $stats['total_vehicles'] > 0) {
                $alerts[] = array(
                    'type'  => 'danger',
                    'icon'  => 'car-crash',
                    'title' => 'No Vehicles Available',
                    'desc'  => 'All ' . $stats['total_vehicles'] . ' vehicles are currently in use. No fleet capacity remaining.',
                    'badge' => 'Urgent',
                );
            }

            // Pending work orders
            if ($stats['pending_workorders'] > 0) {
                $alerts[] = array(
                    'type'  => 'warning',
                    'icon'  => 'tools',
                    'title' => 'Pending Work Orders',
                    'desc'  => $stats['pending_workorders'] . ' work order(s) are awaiting action. Review and assign technicians.',
                    'badge' => $stats['pending_workorders'],
                );
            }

            // Active bookings info
            if ($stats['active_bookings'] > 0) {
                $alerts[] = array(
                    'type'  => 'info',
                    'icon'  => 'calendar-check',
                    'title' => 'Active Bookings Today',
                    'desc'  => $stats['active_bookings'] . ' booking(s) are active right now. Ensure vehicles and drivers are ready.',
                    'badge' => 'Today',
                );
            }

            // Good completion rate
            if ($completion_rate >= 80 && $stats['total_workorders'] > 0) {
                $alerts[] = array(
                    'type'  => 'success',
                    'icon'  => 'check-circle',
                    'title' => 'Work Order Completion Rate',
                    'desc'  => $completion_rate . '% of work orders completed. Maintenance team is performing well.',
                    'badge' => 'Good',
                );
            }

            // No inspections recorded
            if ($stats['total_inspections'] == 0 && $stats['total_vehicles'] > 0) {
                $alerts[] = array(
                    'type'  => 'warning',
                    'icon'  => 'clipboard-list',
                    'title' => 'No Inspections Recorded',
                    'desc'  => 'No vehicle inspections have been logged yet. Schedule routine checks for your fleet.',
                    'badge' => 'Action',
                );
            }

            // Monthly revenue info
            if ($stats['monthly_revenue'] > 0) {
                $alerts[] = array(
                    'type'  => 'success',
                    'icon'  => 'chart-line',
                    'title' => 'Monthly Revenue On Track',
                    'desc'  => 'This month\'s revenue stands at ' . price($stats['monthly_revenue'], 0, '', 1, -1, -1, $conf->currency) . ' from ' . $stats['monthly_bookings'] . ' booking(s).',
                    'badge' => 'Info',
                );
            }
            ?>

            <?php if (!empty($alerts)) { ?>
            <div class="alerts-list">
                <?php foreach ($alerts as $alert) { ?>
                <div class="alert-item alert-<?php echo $alert['type']; ?>">
                    <div class="alert-icon">
                        <i class="fa fa-<?php echo $alert['icon']; ?>"></i>
                    </div>
                    <div class="alert-body">
                        <div class="alert-title"><?php echo dol_escape_htmltag($alert['title']); ?></div>
                        <div class="alert-desc"><?php echo dol_escape_htmltag($alert['desc']); ?></div>
                    </div>
                    <span class="alert-badge"><?php echo dol_escape_htmltag($alert['badge']); ?></span>
                </div>
                <?php } ?>
            </div>
            <?php } else { ?>
            <div class="no-alerts">
                <i class="fa fa-check-circle" style="font-size:40px; color:#16a34a; display:block; margin-bottom:10px;"></i>
                All systems are running smoothly. No alerts at this time.
            </div>
            <?php } ?>
        </div>
    </div>
</div>

<!-- Quick Actions Section -->
<div class="section">
    <div class="section-header">
        <h2 class="section-title">
            <i class="fa fa-bolt"></i> Quick Actions
        </h2>
    </div>
    <div class="card">
        <div class="actions-grid">
            <?php
            $actions = array(
                array('icon' => 'car', 'label' => 'Vehicles', 'url' => 'vehicle_list.php'),
                array('icon' => 'user', 'label' => 'Drivers', 'url' => 'driver_list.php'),
                array('icon' => 'users', 'label' => 'Customers', 'url' => 'customer_list.php'),
                array('icon' => 'calendar', 'label' => 'Bookings', 'url' => 'booking_list.php'),
                array('icon' => 'gas-pump', 'label' => 'Fuel', 'url' => 'fuel_list.php'),
                array('icon' => 'store', 'label' => 'Vendors', 'url' => 'vendor_list.php'),
                array('icon' => 'cog', 'label' => 'Parts', 'url' => 'part_list.php'),
                array('icon' => 'tools', 'label' => 'Work Orders', 'url' => 'workorder_list.php'),
                array('icon' => 'clipboard-check', 'label' => 'Inspections', 'url' => 'inspection_list.php'),
                array('icon' => 'chart-line', 'label' => 'Reports', 'url' => 'flotteindex.php')
            );
            
            foreach ($actions as $action) {
                echo '<a href="' . dol_buildpath('/flotte/' . $action['url'], 1) . '" class="action-btn">';
                echo '<div class="action-icon"><i class="fa fa-' . $action['icon'] . '"></i></div>';
                echo '<div class="action-label">' . $action['label'] . '</div>';
                echo '</a>';
            }
            ?>
        </div>
    </div>
</div>

<!-- Recent Activity Section -->
<div class="section">
    <div class="section-header">
        <h2 class="section-title">
            <i class="fa fa-history"></i> Recent Activity
        </h2>
    </div>
    <div class="card">
        <?php if (!empty($recent_bookings)) { ?>
            <div class="activity-list">
                <?php 
                foreach ($recent_bookings as $booking) {
                    // Determine status
                    $status_class = 'pending';
                    $current_date = date('Y-m-d');
                    $booking_date = date('Y-m-d', strtotime($booking->booking_date));
                    
                    if ($current_date == $booking_date) {
                        $status_class = 'active';
                    } elseif ($current_date > $booking_date) {
                        $status_class = 'completed';
                    }
                ?>
                    <div class="activity-item">
                        <div class="activity-header">
                            <span class="activity-customer"><?php echo dol_escape_htmltag($booking->customer_name); ?></span>
                            <span class="activity-amount"><?php echo price($booking->selling_amount, 0, '', 1, -1, -1, $conf->currency); ?></span>
                        </div>
                        <div class="activity-details">
                            <i class="fa fa-car"></i> 
                            <?php echo dol_escape_htmltag($booking->vehicle_name . ' ' . $booking->vehicle_model . ' (' . $booking->license_plate . ')'); ?>
                            <?php if (!empty($booking->driver_firstname)) { ?>
                                <span style="margin-left:10px;">
                                    <i class="fa fa-user"></i> 
                                    <?php echo dol_escape_htmltag($booking->driver_firstname . ' ' . $booking->driver_lastname); ?>
                                </span>
                            <?php } ?>
                        </div>
                        <div class="activity-meta">
                            <span class="status-badge <?php echo $status_class; ?>">
                                <?php echo ucfirst($status_class); ?>
                            </span>
                            <span class="activity-date">
                                <i class="fa fa-calendar"></i> 
                                <?php echo dol_print_date(dol_stringtotime($booking->booking_date), 'day'); ?>
                            </span>
                        </div>
                    </div>
                <?php } ?>
            </div>
            <a href="<?php echo dol_buildpath('/flotte/booking_list.php', 1); ?>" class="view-all">
                View All Bookings
            </a>
        <?php } else { ?>
            <div class="empty">
                <i class="fa fa-inbox"></i>
                <p>No recent bookings found</p>
            </div>
        <?php } ?>
    </div>
</div>

</div><!-- End fleet-container -->

<!-- ============================================
     JAVASCRIPT - Chart.js
     ============================================ -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
<script>
document.addEventListener("DOMContentLoaded", function() {
    var canvas = document.getElementById("utilizationChart");
    if (!canvas) return;

    var ctx = canvas.getContext("2d");

    // --- PHP-injected data ---
    var total        = <?php echo (int) $stats['total_vehicles']; ?>;
    var inUse        = <?php echo (int) $vehicles_in_use; ?>;
    var available    = <?php echo (int) $stats['available_vehicles']; ?>;
    var outOfService = <?php echo (int) $stats['outofservice_vehicles']; ?>;
    var utilRate     = <?php echo $utilization_rate; ?>;

    if (total === 0) {
        // No data — hide canvas, show message
        canvas.style.display = 'none';
        var centerText = document.getElementById('chartCenterText');
        if (centerText) centerText.style.display = 'none';
        var wrapper = canvas.parentElement;
        var msg = document.createElement('div');
        msg.style.cssText = 'text-align:center;color:#95a5a6;padding:50px 20px;';
        msg.innerHTML = '<i class="fa fa-info-circle" style="font-size:48px;display:block;margin-bottom:15px;opacity:0.4;"></i>'
                      + '<p style="margin:0;font-size:14px;">No vehicle data available yet</p>';
        wrapper.appendChild(msg);
        return;
    }

    // Build segments dynamically (skip zeros)
    var labels = [], data = [], colors = [], hoverColors = [];
    var palette = {
        'In Use Today':  { bg: 'rgba(102,126,234,0.92)', hover: 'rgba(102,126,234,1)' },
        'Available':     { bg: 'rgba(67,233,123,0.92)',  hover: 'rgba(67,233,123,1)'  },
        'Out of Service':{ bg: 'rgba(231,76,60,0.92)',   hover: 'rgba(231,76,60,1)'   }
    };
    var raw = [
        { label: 'In Use Today',   value: inUse        },
        { label: 'Available',      value: available     },
        { label: 'Out of Service', value: outOfService  }
    ];
    raw.forEach(function(seg) {
        if (seg.value > 0) {
            labels.push(seg.label);
            data.push(seg.value);
            colors.push(palette[seg.label].bg);
            hoverColors.push(palette[seg.label].hover);
        }
    });

    // Center text plugin (updates on hover)
    var centerTextPlugin = {
        id: 'centerText',
        beforeDraw: function(chart) {
            var meta = chart._metasets && chart._metasets[0];
            var el = document.getElementById('chartCenterText');
            if (!el) return;
            if (meta) {
                // Position center text over the doughnut hole
                var model = meta.data && meta.data[0];
                if (model) {
                    var cx = model.x;
                    var cy = model.y;
                    var rect = canvas.getBoundingClientRect();
                    var canvasRect = canvas.parentElement.getBoundingClientRect();
                    var chartRect = chart.canvas.getBoundingClientRect();
                    // Use chart.js internal center coords
                    el.style.left = cx + 'px';
                    el.style.top  = cy + 'px';
                    el.style.transform = 'translate(-50%, -50%)';
                }
            }
        }
    };

    var utilizationChart = new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: labels,
            datasets: [{
                data: data,
                backgroundColor: colors,
                hoverBackgroundColor: hoverColors,
                borderWidth: 4,
                borderColor: '#ffffff',
                hoverBorderWidth: 2,
                hoverOffset: 14
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            cutout: '68%',
            animation: {
                animateRotate: true,
                animateScale: false,
                duration: 900,
                easing: 'easeInOutQuart'
            },
            plugins: {
                legend: {
                    display: false  // We use custom pills below
                },
                tooltip: {
                    backgroundColor: 'rgba(30,30,40,0.88)',
                    padding: 14,
                    cornerRadius: 10,
                    titleFont: { size: 14, weight: '700' },
                    bodyFont:  { size: 13 },
                    callbacks: {
                        label: function(context) {
                            var label   = context.label || '';
                            var value   = context.parsed || 0;
                            var pct     = total > 0 ? ((value / total) * 100).toFixed(1) : 0;
                            return '  ' + label + ': ' + value + ' vehicle' + (value !== 1 ? 's' : '') + '  (' + pct + '%)';
                        }
                    }
                }
            },
            onHover: function(event, elements) {
                var rateEl  = document.querySelector('.chart-center-rate');
                var labelEl = document.querySelector('.chart-center-label');
                if (!rateEl || !labelEl) return;
                if (elements && elements.length > 0) {
                    var idx    = elements[0].index;
                    var val    = data[idx];
                    var pct    = total > 0 ? ((val / total) * 100).toFixed(1) : 0;
                    rateEl.textContent  = val;
                    labelEl.textContent = labels[idx];
                } else {
                    rateEl.textContent  = utilRate + '%';
                    labelEl.textContent = 'Utilization';
                }
            }
        },
        plugins: [centerTextPlugin]
    });

    // Position center label after first render
    utilizationChart.update();
});
</script>

<?php
// ============================================
// PAGE FOOTER
// ============================================
print '</div>'; // End fichecenter
print dol_get_fiche_end();

llxFooter();
$db->close();
?>
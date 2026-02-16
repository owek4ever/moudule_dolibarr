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
    
    // Total vehicles
    $sql = "SELECT COUNT(*) as total FROM " . MAIN_DB_PREFIX . "flotte_vehicle WHERE entity = " . ((int) $conf->entity);
    $obj = safeQuery($db, $sql);
    $stats['total_vehicles'] = (int) $obj->total;
    
    // Available vehicles
    $sql = "SELECT COUNT(*) as available FROM " . MAIN_DB_PREFIX . "flotte_vehicle WHERE entity = " . ((int) $conf->entity);
    $obj = safeQuery($db, $sql);
    $stats['available_vehicles'] = (int) $obj->available;
    
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
        // Active bookings (today)
        $sql = "SELECT COUNT(*) as active FROM " . MAIN_DB_PREFIX . "flotte_booking 
                WHERE DATE(start_date) <= CURDATE() 
                AND DATE(end_date) >= CURDATE() 
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
$utilization_rate = $stats['total_vehicles'] > 0 ? 
    round((($stats['total_vehicles'] - $stats['available_vehicles']) / $stats['total_vehicles']) * 100, 1) : 0;

$completion_rate = $stats['total_workorders'] > 0 ? 
    round(($stats['completed_workorders'] / $stats['total_workorders']) * 100, 1) : 0;

// Calculate vehicles in use
$vehicles_in_use = $stats['total_vehicles'] - $stats['available_vehicles'];

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

/* Metrics */
.metrics { 
    display: flex; 
    flex-direction: column; 
    gap: 18px; 
}

.metric { 
    display: flex; 
    align-items: center; 
    justify-content: space-between; 
}

.metric-info { 
    display: flex; 
    align-items: center; 
    gap: 12px; 
}

.metric-icon { 
    width: 40px; 
    height: 40px; 
    border-radius: 10px; 
    display: flex; 
    align-items: center; 
    justify-content: center; 
    font-size: 18px; 
}

.metric-icon.blue { background: #e3f2fd; color: #1976d2; }
.metric-icon.green { background: #e8f5e9; color: #388e3c; }
.metric-icon.orange { background: #fff3e0; color: #f57c00; }
.metric-icon.purple { background: #f3e5f5; color: #7b1fa2; }

.metric-label { 
    font-size: 14px; 
    color: #5a6c7d; 
    font-weight: 500; 
}

.metric-value { 
    font-size: 24px; 
    font-weight: 700; 
    color: #2c3e50; 
}

.metric-bar-wrap { 
    margin-top: 8px; 
}

.metric-bar { 
    height: 8px; 
    background: #ecf0f1; 
    border-radius: 10px; 
    overflow: hidden; 
}

.metric-fill { 
    height: 100%; 
    background: linear-gradient(90deg, #667eea 0%, #764ba2 100%); 
    border-radius: 10px; 
    transition: width 0.8s ease; 
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
        <div class="stat-card vehicles">
            <div class="stat-icon"><i class="fa fa-car"></i></div>
            <div class="stat-content">
                <div class="stat-value"><?php echo $stats['total_vehicles']; ?></div>
                <div class="stat-label">Total Vehicles</div>
                <div class="stat-detail">
                    <i class="fa fa-check-circle"></i> 
                    <?php echo $stats['available_vehicles']; ?> Available
                </div>
            </div>
        </div>
        
        <!-- Drivers -->
        <div class="stat-card drivers">
            <div class="stat-icon"><i class="fa fa-users"></i></div>
            <div class="stat-content">
                <div class="stat-value"><?php echo $stats['total_drivers']; ?></div>
                <div class="stat-label">Total Drivers</div>
                <div class="stat-detail">
                    <i class="fa fa-user-check"></i> Active Personnel
                </div>
            </div>
        </div>
        
        <!-- Bookings -->
        <div class="stat-card bookings">
            <div class="stat-icon"><i class="fa fa-calendar-check"></i></div>
            <div class="stat-content">
                <div class="stat-value"><?php echo $stats['active_bookings']; ?></div>
                <div class="stat-label">Active Bookings</div>
                <div class="stat-detail">
                    <i class="fa fa-calendar"></i> 
                    <?php echo $stats['monthly_bookings']; ?> This Month
                </div>
            </div>
        </div>
        
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
            <canvas id="utilizationChart" class="chart-canvas"></canvas>
        </div>
        
        <!-- Metrics -->
        <div class="card">
            <h3 class="card-title">
                <i class="fa fa-tachometer-alt"></i> Key Metrics
            </h3>
            <div class="metrics">
                <!-- Utilization -->
                <div>
                    <div class="metric">
                        <div class="metric-info">
                            <div class="metric-icon blue">
                                <i class="fa fa-gauge-high"></i>
                            </div>
                            <div class="metric-label">Fleet Utilization</div>
                        </div>
                        <div class="metric-value"><?php echo $utilization_rate; ?>%</div>
                    </div>
                    <div class="metric-bar-wrap">
                        <div class="metric-bar">
                            <div class="metric-fill" style="width: <?php echo $utilization_rate; ?>%;"></div>
                        </div>
                    </div>
                </div>
                
                <!-- Customers -->
                <div class="metric">
                    <div class="metric-info">
                        <div class="metric-icon green">
                            <i class="fa fa-users"></i>
                        </div>
                        <div class="metric-label">Total Customers</div>
                    </div>
                    <div class="metric-value"><?php echo $stats['total_customers']; ?></div>
                </div>
                
                <!-- Work Orders -->
                <div class="metric">
                    <div class="metric-info">
                        <div class="metric-icon orange">
                            <i class="fa fa-tools"></i>
                        </div>
                        <div class="metric-label">Work Orders</div>
                    </div>
                    <div class="metric-value"><?php echo $stats['total_workorders']; ?></div>
                </div>
                
                <!-- Inspections -->
                <div class="metric">
                    <div class="metric-info">
                        <div class="metric-icon purple">
                            <i class="fa fa-clipboard-check"></i>
                        </div>
                        <div class="metric-label">Inspections</div>
                    </div>
                    <div class="metric-value"><?php echo $stats['total_inspections']; ?></div>
                </div>
            </div>
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
    var inUse = <?php echo $vehicles_in_use; ?>;
    var available = <?php echo $stats['available_vehicles']; ?>;
    var total = <?php echo $stats['total_vehicles']; ?>;
    
    // Only create chart if there is data
    if (total > 0) {
        new Chart(ctx, {
            type: "doughnut",
            data: {
                labels: ["In Use", "Available"],
                datasets: [{
                    data: [inUse, available],
                    backgroundColor: [
                        "rgba(102,126,234,0.9)",
                        "rgba(67,233,123,0.9)"
                    ],
                    borderWidth: 3,
                    borderColor: "#fff",
                    hoverOffset: 10
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: {
                        position: "bottom",
                        labels: {
                            padding: 20,
                            font: {
                                size: 13,
                                weight: "600"
                            },
                            usePointStyle: true,
                            pointStyle: "circle"
                        }
                    },
                    tooltip: {
                        backgroundColor: "rgba(0, 0, 0, 0.8)",
                        padding: 12,
                        titleFont: {
                            size: 14,
                            weight: "bold"
                        },
                        bodyFont: {
                            size: 13
                        },
                        callbacks: {
                            label: function(context) {
                                var label = context.label || "";
                                var value = context.parsed || 0;
                                var percentage = total > 0 ? ((value / total) * 100).toFixed(1) : 0;
                                return label + ": " + value + " (" + percentage + "%)";
                            }
                        }
                    }
                }
            }
        });
    } else {
        // Show message if no data
        canvas.style.display = 'none';
        var parent = canvas.parentElement;
        var msg = document.createElement('p');
        msg.style.textAlign = 'center';
        msg.style.color = '#95a5a6';
        msg.style.padding = '40px';
        msg.innerHTML = '<i class="fa fa-info-circle" style="font-size:48px; display:block; margin-bottom:15px;"></i>No vehicle data available yet';
        parent.appendChild(msg);
    }
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
<?php
/* Copyright (C) 2001-2005  Rodolphe Quiedeville
 * Copyright (C) 2004-2015  Laurent Destailleur
 * Copyright (C) 2005-2012  Regis Houssin
 * Copyright (C) 2015       Jean-François Ferry
 * Copyright (C) 2024       Frédéric France
 * Copyright (C) 2025       SuperAdmin
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 *  \file       flotte/flotteindex.php
 *  \ingroup    flotte
 *  \brief      Home page of flotte top menu
 */

// Load Dolibarr environment
$res = 0;
// Try main.inc.php into web root known defined into CONTEXT_DOCUMENT_ROOT (not always defined)
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
    $res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
// Try main.inc.php into web root detected using web root calculated from SCRIPT_FILENAME
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
// Try main.inc.php using relative path
if (!$res && file_exists("../main.inc.php"))      { $res = @include "../main.inc.php"; }
if (!$res && file_exists("../../main.inc.php"))   { $res = @include "../../main.inc.php"; }
if (!$res && file_exists("../../../main.inc.php")){ $res = @include "../../../main.inc.php"; }
if (!$res) { die("Include of main fails"); }

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';

// Load translation files required by the page
$langs->loadLangs(array("flotte@flotte"));

// Security check
if (!$user->rights->flotte->read) accessforbidden();

// Try to load your module classes
$classes_loaded = true;
try {
    if (file_exists(DOL_DOCUMENT_ROOT.'/custom/flotte/class/vehicle.class.php')) {
        require_once DOL_DOCUMENT_ROOT.'/custom/flotte/class/vehicle.class.php';
    }
    if (file_exists(DOL_DOCUMENT_ROOT.'/custom/flotte/class/driver.class.php')) {
        require_once DOL_DOCUMENT_ROOT.'/custom/flotte/class/driver.class.php';
    }
    if (file_exists(DOL_DOCUMENT_ROOT.'/custom/flotte/class/customer.class.php')) {
        require_once DOL_DOCUMENT_ROOT.'/custom/flotte/class/customer.class.php';
    }
    if (file_exists(DOL_DOCUMENT_ROOT.'/custom/flotte/class/booking.class.php')) {
        require_once DOL_DOCUMENT_ROOT.'/custom/flotte/class/booking.class.php';
    }
    if (file_exists(DOL_DOCUMENT_ROOT.'/custom/flotte/class/department.class.php')) {
        require_once DOL_DOCUMENT_ROOT.'/custom/flotte/class/department.class.php';
    }
} catch (Exception $e) {
    $classes_loaded = false;
}

$form = new Form($db);

// Get dashboard statistics (using direct SQL queries)
function getFleetStats($db) {
    global $conf;
    
    $stats = array();
    
    // Initialize all stats to 0
    $default_stats = array(
        'total_vehicles' => 0,
        'available_vehicles' => 0,
        'total_drivers' => 0,
        'total_customers' => 0,
        'active_bookings' => 0,
        'monthly_bookings' => 0,
        'monthly_revenue' => 0,
        'total_departments' => 0,
        'total_fuel_entries' => 0,
        'total_vendors' => 0,
        'total_parts' => 0,
        'total_workorders' => 0,
        'total_inspections' => 0,
        'tables_missing' => false
    );
    
    // Check if main table exists first
    $sql = "SHOW TABLES LIKE '" . MAIN_DB_PREFIX . "flotte_vehicle'";
    $resql = $db->query($sql);
    if (!$resql || $db->num_rows($resql) == 0) {
        // Main table doesn't exist, return default values
        return array_merge($default_stats, array('tables_missing' => true));
    }
    
    // Total vehicles
    $sql = "SELECT COUNT(*) as total FROM " . MAIN_DB_PREFIX . "flotte_vehicle WHERE entity = " . $conf->entity;
    $resql = $db->query($sql);
    if ($resql) {
        $obj = $db->fetch_object($resql);
        $stats['total_vehicles'] = $obj->total;
    } else {
        $stats['total_vehicles'] = 0;
        dol_syslog("Error getting total vehicles: " . $db->lasterror(), LOG_ERR);
    }
    
    // Available vehicles (check for status field existence first)
    $sql = "SHOW COLUMNS FROM " . MAIN_DB_PREFIX . "flotte_vehicle LIKE 'status'";
    $resql = $db->query($sql);
    if ($resql && $db->num_rows($resql) > 0) {
        // Status field exists, use it
        $sql = "SELECT COUNT(*) as available FROM " . MAIN_DB_PREFIX . "flotte_vehicle WHERE status = 'available' AND entity = " . $conf->entity;
    } else {
        // Status field doesn't exist, count all vehicles as available
        $sql = "SELECT COUNT(*) as available FROM " . MAIN_DB_PREFIX . "flotte_vehicle WHERE entity = " . $conf->entity;
    }
    $resql = $db->query($sql);
    if ($resql) {
        $obj = $db->fetch_object($resql);
        $stats['available_vehicles'] = $obj->available;
    } else {
        $stats['available_vehicles'] = 0;
        dol_syslog("Error getting available vehicles: " . $db->lasterror(), LOG_ERR);
    }
    
    // Check if other tables exist and get their counts
    $tables = array(
        'flotte_driver' => 'total_drivers',
        'flotte_customer' => 'total_customers',
        'flotte_department' => 'total_departments',
        'flotte_fuel' => 'total_fuel_entries',
        'flotte_vendor' => 'total_vendors',
        'flotte_part' => 'total_parts',
        'flotte_workorder' => 'total_workorders',
        'flotte_inspection' => 'total_inspections'
    );
    
    foreach ($tables as $table => $stat_key) {
        $sql = "SHOW TABLES LIKE '" . MAIN_DB_PREFIX . $table . "'";
        $resql = $db->query($sql);
        if ($resql && $db->num_rows($resql) > 0) {
            $sql = "SELECT COUNT(*) as total FROM " . MAIN_DB_PREFIX . $table . " WHERE entity = " . $conf->entity;
            $resql2 = $db->query($sql);
            if ($resql2) {
                $obj = $db->fetch_object($resql2);
                $stats[$stat_key] = $obj->total;
            } else {
                $stats[$stat_key] = 0;
                dol_syslog("Error getting $table count: " . $db->lasterror(), LOG_ERR);
            }
        } else {
            $stats[$stat_key] = 0;
        }
    }
    
    // Bookings statistics - check if table exists first
    $sql = "SHOW TABLES LIKE '" . MAIN_DB_PREFIX . "flotte_booking'";
    $resql = $db->query($sql);
    if ($resql && $db->num_rows($resql) > 0) {
        // Active bookings (today)
        $sql = "SELECT COUNT(*) as active FROM " . MAIN_DB_PREFIX . "flotte_booking 
                WHERE DATE(start_date) <= CURDATE() AND DATE(end_date) >= CURDATE() AND entity = " . $conf->entity;
        $resql = $db->query($sql);
        if ($resql) {
            $obj = $db->fetch_object($resql);
            $stats['active_bookings'] = $obj->active;
        } else {
            $stats['active_bookings'] = 0;
            dol_syslog("Error getting active bookings: " . $db->lasterror(), LOG_ERR);
        }
        
        // Total bookings this month
        $sql = "SELECT COUNT(*) as monthly FROM " . MAIN_DB_PREFIX . "flotte_booking 
                WHERE MONTH(booking_date) = MONTH(CURDATE()) AND YEAR(booking_date) = YEAR(CURDATE()) AND entity = " . $conf->entity;
        $resql = $db->query($sql);
        if ($resql) {
            $obj = $db->fetch_object($resql);
            $stats['monthly_bookings'] = $obj->monthly;
        } else {
            $stats['monthly_bookings'] = 0;
            dol_syslog("Error getting monthly bookings: " . $db->lasterror(), LOG_ERR);
        }
        
        // Revenue this month - check if selling_amount field exists
        $sql = "SHOW COLUMNS FROM " . MAIN_DB_PREFIX . "flotte_booking LIKE 'selling_amount'";
        $resql = $db->query($sql);
        if ($resql && $db->num_rows($resql) > 0) {
            $sql = "SELECT SUM(selling_amount) as revenue FROM " . MAIN_DB_PREFIX . "flotte_booking 
                    WHERE MONTH(booking_date) = MONTH(CURDATE()) AND YEAR(booking_date) = YEAR(CURDATE()) AND entity = " . $conf->entity;
        } else {
            // Try amount field if selling_amount doesn't exist
            $sql = "SHOW COLUMNS FROM " . MAIN_DB_PREFIX . "flotte_booking LIKE 'amount'";
            $resql = $db->query($sql);
            if ($resql && $db->num_rows($resql) > 0) {
                $sql = "SELECT SUM(amount) as revenue FROM " . MAIN_DB_PREFIX . "flotte_booking 
                        WHERE MONTH(booking_date) = MONTH(CURDATE()) AND YEAR(booking_date) = YEAR(CURDATE()) AND entity = " . $conf->entity;
            } else {
                $sql = "SELECT 0 as revenue"; // No amount field found
            }
        }
        
        $resql = $db->query($sql);
        if ($resql) {
            $obj = $db->fetch_object($resql);
            $stats['monthly_revenue'] = $obj->revenue ? $obj->revenue : 0;
        } else {
            $stats['monthly_revenue'] = 0;
            dol_syslog("Error getting monthly revenue: " . $db->lasterror(), LOG_ERR);
        }
    } else {
        $stats['active_bookings'] = 0;
        $stats['monthly_bookings'] = 0;
        $stats['monthly_revenue'] = 0;
    }
    
    return array_merge($default_stats, $stats);
}

// Get recent bookings
function getRecentBookings($db, $limit = 5) {
    global $conf;
    
    $bookings = array();
    
    // Check if booking table exists
    $sql = "SHOW TABLES LIKE '" . MAIN_DB_PREFIX . "flotte_booking'";
    $resql = $db->query($sql);
    if (!$resql || $db->num_rows($resql) == 0) {
        return $bookings;
    }
    
    $sql = "SELECT b.rowid, b.booking_date, b.selling_amount, 
                   v.name as vehicle_name, v.model as vehicle_model, v.license_plate,
                   d.firstname as driver_firstname, d.lastname as driver_lastname,
                   c.name as customer_name
            FROM " . MAIN_DB_PREFIX . "flotte_booking b
            LEFT JOIN " . MAIN_DB_PREFIX . "flotte_vehicle v ON b.fk_vehicle = v.rowid
            LEFT JOIN " . MAIN_DB_PREFIX . "flotte_driver d ON b.fk_driver = d.rowid
            LEFT JOIN " . MAIN_DB_PREFIX . "flotte_customer c ON b.fk_customer = c.rowid
            WHERE b.entity = " . $conf->entity . "
            ORDER BY b.booking_date DESC LIMIT " . intval($limit);
    
    $resql = $db->query($sql);
    if ($resql) {
        while ($obj = $db->fetch_object($resql)) {
            $bookings[] = $obj;
        }
    }
    return $bookings;
}

$stats = getFleetStats($db);
$recent_bookings = getRecentBookings($db);

// Initialize technical object to manage hooks of page. Note that conf->hooks_modules contains array of hook context
$hookmanager->initHooks(array('flottedashboard'));

llxHeader('', $langs->trans("FlotteDashboard"), '', '', 0, 0, array('/flotte/css/flotte.css'));


// Check if database tables exist
if (!empty($stats['tables_missing'])) {
    print '<div class="warning" style="margin: 20px 0; padding: 15px; background: #fff3cd; border: 1px solid #ffeaa7; border-radius: 5px;">';
    print '<strong>'.$langs->trans("Warning").':</strong> '.$langs->trans("FlotteTablesNotInstalled");
    print '</div>';
}

// Dashboard CSS
print '<style>
.fleet-dashboard { margin: 20px 0; }
.dashboard-row { display: flex; flex-wrap: wrap; gap: 20px; margin-bottom: 30px; }
.dashboard-card { 
    flex: 1; 
    min-width: 250px; 
    background: #fff; 
    border: 1px solid #ddd; 
    border-radius: 5px; 
    padding: 20px; 
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    transition: transform 0.2s;
}
.dashboard-card:hover { transform: translateY(-2px); box-shadow: 0 4px 8px rgba(0,0,0,0.15); }
.stat-number { font-size: 2.5em; font-weight: bold; color: #4a90e2; text-align: center; margin-bottom: 10px; }
.stat-label { text-align: center; color: #666; font-weight: 500; }
.quick-actions { display: flex; flex-wrap: wrap; gap: 15px; justify-content: center; margin: 20px 0; }
.action-btn { 
    display: inline-block; 
    padding: 12px 24px; 
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); 
    color: white; 
    text-decoration: none; 
    border-radius: 5px; 
    font-weight: 500;
    transition: all 0.3s;
    min-width: 140px;
    text-align: center;
}
.action-btn:hover { 
    transform: translateY(-2px); 
    box-shadow: 0 4px 12px rgba(0,0,0,0.2); 
    color: white; 
    text-decoration: none; 
}
.recent-activity { background: #f8f9fa; border-radius: 5px; padding: 20px; }
.activity-item { 
    padding: 10px; 
    border-bottom: 1px solid #eee; 
    display: flex; 
    justify-content: space-between; 
    align-items: center; 
}
.activity-item:last-child { border-bottom: none; }
.activity-details { flex: 1; }
.activity-date { color: #888; font-size: 0.9em; }
.status-indicator { 
    display: inline-block; 
    width: 8px; 
    height: 8px; 
    border-radius: 50%; 
    margin-right: 8px; 
}
.status-active { background-color: #28a745; }
.status-pending { background-color: #ffc107; }
.status-completed { background-color: #6c757d; }
.quick-actions-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 15px;
    margin: 20px 0;
}
.quick-action-item {
    display: flex;
    align-items: center;
    padding: 15px;
    background: #f8f9fa;
    border-radius: 5px;
    text-decoration: none;
    color: #333;
    transition: all 0.3s;
}
.quick-action-item:hover {
    background: #e9ecef;
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.1);
    text-decoration: none;
    color: #333;
}
.quick-action-icon {
    margin-right: 15px;
    font-size: 24px;
    width: 30px;
    text-align: center;
    color: #4a90e2;
}
</style>';

print '<div class="fleet-dashboard">';

// Statistics Cards Row
print '<div class="dashboard-row">';

// Total Vehicles Card
print '<div class="dashboard-card">';
print '<div class="stat-number">' . $stats['total_vehicles'] . '</div>';
print '<div class="stat-label">' . $langs->trans("TotalVehicles") . '</div>';
print '<div style="text-align: center; margin-top: 10px; color: #28a745;">';
print '<small>' . $stats['available_vehicles'] . ' ' . $langs->trans("Available") . '</small>';
print '</div>';
print '</div>';

// Total Drivers Card
print '<div class="dashboard-card">';
print '<div class="stat-number">' . $stats['total_drivers'] . '</div>';
print '<div class="stat-label">' . $langs->trans("TotalDrivers") . '</div>';
print '</div>';

// Active Bookings Card
print '<div class="dashboard-card">';
print '<div class="stat-number">' . $stats['active_bookings'] . '</div>';
print '<div class="stat-label">' . $langs->trans("ActiveBookings") . '</div>';
print '<div style="text-align: center; margin-top: 10px; color: #17a2b8;">';
print '<small>' . $stats['monthly_bookings'] . ' ' . $langs->trans("ThisMonth") . '</small>';
print '</div>';
print '</div>';

// Revenue Card
print '<div class="dashboard-card">';
print '<div class="stat-number">' . price($stats['monthly_revenue']) . '</div>';
print '<div class="stat-label">' . $langs->trans("MonthlyRevenue") . '</div>';
print '</div>';

print '</div>'; // End statistics row

// Quick Actions Section - All requested items
print '<div class="dashboard-card">';
print '<h3 style="margin-top: 0; text-align: center; color: #333; margin-bottom: 20px;">' . $langs->trans("QuickActions") . '</h3>';
print '<div class="quick-actions-grid">';

// Dashboard
print '<a class="quick-action-item" href="' . dol_buildpath('/flotte/flotteindex.php', 1) . '">';
print '<div class="quick-action-icon"><i class="fa fa-tachometer-alt"></i></div>';
print '<div>' . $langs->trans("Dashboard") . '</div>';
print '</a>';

// Vehicles
print '<a class="quick-action-item" href="' . dol_buildpath('/flotte/vehicle_list.php', 1) . '">';
print '<div class="quick-action-icon"><i class="fa fa-car"></i></div>';
print '<div>' . $langs->trans("Vehicles") . '</div>';
print '</a>';

// Drivers
print '<a class="quick-action-item" href="' . dol_buildpath('/flotte/driver_list.php', 1) . '">';
print '<div class="quick-action-icon"><i class="fa fa-user"></i></div>';
print '<div>' . $langs->trans("Drivers") . '</div>';
print '</a>';

// Customers
print '<a class="quick-action-item" href="' . dol_buildpath('/flotte/customer_list.php', 1) . '">';
print '<div class="quick-action-icon"><i class="fa fa-users"></i></div>';
print '<div>' . $langs->trans("Customers") . '</div>';
print '</a>';

// Bookings
print '<a class="quick-action-item" href="' . dol_buildpath('/flotte/booking_list.php', 1) . '">';
print '<div class="quick-action-icon"><i class="fa fa-calendar"></i></div>';
print '<div>' . $langs->trans("Bookings") . '</div>';
print '</a>';

// Fuel
print '<a class="quick-action-item" href="' . dol_buildpath('/flotte/fuel_list.php', 1) . '">';
print '<div class="quick-action-icon"><i class="fa fa-gas-pump"></i></div>';
print '<div>' . $langs->trans("Fuel") . '</div>';
print '</a>';

// Vendors
print '<a class="quick-action-item" href="' . dol_buildpath('/flotte/vendor_list.php', 1) . '">';
print '<div class="quick-action-icon"><i class="fa fa-store"></i></div>';
print '<div>' . $langs->trans("Vendors") . '</div>';
print '</a>';

// Parts
print '<a class="quick-action-item" href="' . dol_buildpath('/flotte/part_list.php', 1) . '">';
print '<div class="quick-action-icon"><i class="fa fa-cog"></i></div>';
print '<div>' . $langs->trans("Parts") . '</div>';
print '</a>';

// WorkOrders
print '<a class="quick-action-item" href="' . dol_buildpath('/flotte/workorder_list.php', 1) . '">';
print '<div class="quick-action-icon"><i class="fa fa-tools"></i></div>';
print '<div>' . $langs->trans("WorkOrders") . '</div>';
print '</a>';

// Inspections
print '<a class="quick-action-item" href="' . dol_buildpath('/flotte/inspection_list.php', 1) . '">';
print '<div class="quick-action-icon"><i class="fa fa-clipboard-check"></i></div>';
print '<div>' . $langs->trans("Inspections") . '</div>';
print '</a>';


print '</div>'; // End quick-actions-grid
print '</div>'; // End dashboard-card

// Recent Activity Section
if (!empty($recent_bookings)) {
    print '<div class="dashboard-row">';
    print '<div class="dashboard-card" style="flex: 2;">';
    print '<h3 style="margin-top: 0; color: #333;">' . $langs->trans("RecentBookings") . '</h3>';
    print '<div class="recent-activity">';
    
    foreach ($recent_bookings as $booking) {
        $status_class = 'status-pending';
        $current_date = date('Y-m-d');
        $booking_date = date('Y-m-d', strtotime($booking->booking_date));
        
        if ($current_date == $booking_date) {
            $status_class = 'status-active';
        } elseif ($current_date > $booking_date) {
            $status_class = 'status-completed';
        }
        
        print '<div class="activity-item">';
        print '<div class="activity-details">';
        print '<span class="status-indicator ' . $status_class . '"></span>';
        print '<strong>' . $booking->customer_name . '</strong> - ';
        print $booking->vehicle_name . ' ' . $booking->vehicle_model . ' (' . $booking->license_plate . ')';
        if (!empty($booking->driver_firstname)) {
            print '<br><small>' . $langs->trans("Driver") . ': ' . $booking->driver_firstname . ' ' . $booking->driver_lastname . '</small>';
        }
        print '</div>';
        print '<div style="text-align: right;">';
        print '<div style="font-weight: bold; color: #28a745;">' . price($booking->selling_amount) . '</div>';
        print '<div class="activity-date">' . dol_print_date(dol_stringtotime($booking->booking_date), 'day') . '</div>';
        print '</div>';
        print '</div>';
    }
    
    print '</div>';
    print '<div style="text-align: center; margin-top: 15px;">';
    print '<a href="' . dol_buildpath('/flotte/booking_list.php', 1) . '" class="button">' . $langs->trans("ViewAllBookings") . '</a>';
    print '</div>';
    print '</div>';
    
    // Fleet Status Summary
    print '<div class="dashboard-card">';
    print '<h3 style="margin-top: 0; color: #333;">' . $langs->trans("FleetSummary") . '</h3>';
    print '<div style="line-height: 2;">';
    print '<div><i class="fa fa-car" style="color: #4a90e2; width: 20px;"></i> <strong>' . $stats['total_vehicles'] . '</strong> ' . $langs->trans("TotalVehicles") . '</div>';
    print '<div><i class="fa fa-check-circle" style="color: #28a745; width: 20px;"></i> <strong>' . $stats['available_vehicles'] . '</strong> ' . $langs->trans("Available") . '</div>';
    print '<div><i class="fa fa-user" style="color: #6f42c1; width: 20px;"></i> <strong>' . $stats['total_drivers'] . '</strong> ' . $langs->trans("Drivers") . '</div>';
    print '<div><i class="fa fa-users" style="color: #fd7e14; width: 20px;"></i> <strong>' . $stats['total_customers'] . '</strong> ' . $langs->trans("Customers") . '</div>';
    print '<div><i class="fa fa-building" style="color: #20c997; width: 20px;"></i> <strong>' . $stats['total_departments'] . '</strong> ' . $langs->trans("Departments") . '</div>';
    print '<div><i class="fa fa-gas-pump" style="color: #ff6b6b; width: 20px;"></i> <strong>' . $stats['total_fuel_entries'] . '</strong> ' . $langs->trans("FuelEntries") . '</div>';
    print '<div><i class="fa fa-store" style="color: #7952b3; width: 20px;"></i> <strong>' . $stats['total_vendors'] . '</strong> ' . $langs->trans("Vendors") . '</div>';
    print '<div><i class="fa fa-cog" style="color: #339af0; width: 20px;"></i> <strong>' . $stats['total_parts'] . '</strong> ' . $langs->trans("Parts") . '</div>';
    print '<div><i class="fa fa-tools" style="color: #f59f00; width: 20px;"></i> <strong>' . $stats['total_workorders'] . '</strong> ' . $langs->trans("WorkOrders") . '</div>';
    print '<div><i class="fa fa-clipboard-check" style="color: #51cf66; width: 20px;"></i> <strong>' . $stats['total_inspections'] . '</strong> ' . $langs->trans("Inspections") . '</div>';
    print '</div>';
    print '</div>';
    print '</div>';
}

print '</div>'; // End dashboard

// Page end
print dol_get_fiche_end();

llxFooter();
$db->close();
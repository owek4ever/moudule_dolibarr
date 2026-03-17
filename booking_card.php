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

// ── OSRM proxy endpoint ──────────────────────────────────────────────────────
if (GETPOST('osrm_proxy', 'int') == 1) {
    $coords = isset($_GET['coords']) ? $_GET['coords'] : '';
    if (!preg_match('/^[-0-9.,;]+$/', $coords) || strlen($coords) < 5 || strlen($coords) > 512) {
        header('Content-Type: application/json');
        http_response_code(400);
        echo json_encode(array('error' => 'invalid coords', 'received' => $coords));
        exit;
    }
    $osrm_servers = array(
        'https://router.project-osrm.org/route/v1/driving/',
        'https://routing.openstreetmap.de/routed-car/route/v1/driving/',
    );
    $osrm_result = null;
    $osrm_log = array();
    foreach ($osrm_servers as $osrm_base) {
        if (!function_exists('curl_init')) { $osrm_log[] = array('status'=>'no_curl'); break; }
        $ch = curl_init($osrm_base . $coords . '?overview=full&geometries=geojson');
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_USERAGENT => 'DolibarrFlotte/1.0',
            CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_FOLLOWLOCATION => true,
        ));
        $osrm_raw  = curl_exec($ch);
        $osrm_err  = curl_error($ch);
        $osrm_http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($osrm_raw !== false && $osrm_http >= 200 && $osrm_http < 300) {
            $osrm_json = json_decode($osrm_raw, true);
            if (!empty($osrm_json['routes'])) { $osrm_result = $osrm_raw; break; }
        }
        $osrm_log[] = array('server' => $osrm_base, 'err' => $osrm_err, 'http' => $osrm_http);
    }
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    if (isset($_GET['debug'])) { echo json_encode(array('log'=>$osrm_log,'coords'=>$coords)); }
    elseif ($osrm_result) { echo $osrm_result; }
    else { http_response_code(503); echo json_encode(array('error'=>'routing unavailable','log'=>$osrm_log)); }
    exit;
}
// ── End OSRM proxy ───────────────────────────────────────────────────────────

// Load translation files
$langs->loadLangs(array("flotte@flotte", "other"));

// Function to convert date to MySQL format
function convertDateToMysql($datestring) {
    if (empty($datestring)) {
        return '';
    }
    
    // Handle different date formats
    $timestamp = dol_stringtotime($datestring);
    if ($timestamp === false) {
        // Try parsing common formats
        $formats = ['m/d/Y', 'd/m/Y', 'Y-m-d', 'Y/m/d'];
        foreach ($formats as $format) {
            $date = DateTime::createFromFormat($format, $datestring);
            if ($date !== false) {
                return $date->format('Y-m-d');
            }
        }
        return '';
    }
    
    return dol_print_date($timestamp, '%Y-%m-%d', 'tzserver');
}

// Function to generate next booking reference
function getNextBookingRef($db, $entity) {
    $prefix = "BOOK-";
    
    // Get the last reference from database
    $sql = "SELECT ref FROM ".MAIN_DB_PREFIX."flotte_booking";
    $sql .= " WHERE entity = ".((int) $entity);
    $sql .= " AND ref LIKE '".$db->escape($prefix)."%'";
    $sql .= " ORDER BY ref DESC LIMIT 1";
    
    $resql = $db->query($sql);
    if ($resql && $db->num_rows($resql) > 0) {
        $obj = $db->fetch_object($resql);
        $last_ref = $obj->ref;
        
        // Extract the numeric part and increment
        $numeric_part = (int)str_replace($prefix, '', $last_ref);
        $next_number = $numeric_part + 1;
    } else {
        // No existing references, start from 1
        $next_number = 1;
    }
    
    // Format with leading zeros (e.g., BOOK-0001)
    return $prefix.str_pad($next_number, 4, '0', STR_PAD_LEFT);
}

/**
 * Sync booking expense data into the flotte_expense table.
 * Deletes existing booking-sourced rows for this booking, then re-inserts
 * one row per category that has a non-zero amount.
 */
function syncBookingExpensesToTable($db, $booking_id, $booking_date, $conf, $user, $expense_data) {
    // Ensure flotte_expense table exists
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

    // Remove old booking-sourced rows for this booking
    $db->query("DELETE FROM ".MAIN_DB_PREFIX."flotte_expense WHERE fk_booking = ".((int)$booking_id)." AND source = 'booking'");

    $year      = date('Y', strtotime($booking_date));
    $exp_date  = !empty($booking_date) ? $db->escape($booking_date) : date('Y-m-d');

    // Helper to get next expense ref
    $getRef = function() use ($db, $conf, $year) {
        $sql = "SELECT MAX(CAST(SUBSTRING(ref, 9) AS UNSIGNED)) AS mx FROM ".MAIN_DB_PREFIX."flotte_expense WHERE ref LIKE 'EXP-".$year."-%' AND entity = ".((int)$conf->entity);
        $res = $db->query($sql);
        $mx  = 0;
        if ($res) { $obj = $db->fetch_object($res); $mx = (int)$obj->mx; }
        return 'EXP-'.$year.'-'.str_pad($mx + 1, 4, '0', STR_PAD_LEFT);
    };

    // ── FUEL ──
    if (!empty($expense_data['expense_fuel']) && (float)$expense_data['expense_fuel'] > 0) {
        $ref = $getRef();
        $db->query("INSERT INTO ".MAIN_DB_PREFIX."flotte_expense
            (ref, fk_booking, expense_date, category, amount,
             fuel_vendor, fuel_type, fuel_qty, fuel_price,
             source, entity, date_creation, fk_user_creat) VALUES (
            '".$db->escape($ref)."', ".((int)$booking_id).", '".$exp_date."', 'fuel',
            ".((float)$expense_data['expense_fuel']).",
            ".(!empty($expense_data['expense_fuel_vendor']) ? (int)$expense_data['expense_fuel_vendor'] : 'NULL').",
            ".(!empty($expense_data['expense_fuel_type'])   ? "'".$db->escape($expense_data['expense_fuel_type'])."'" : 'NULL').",
            ".(!empty($expense_data['expense_fuel_qty'])    ? (float)$expense_data['expense_fuel_qty']    : 'NULL').",
            ".(!empty($expense_data['expense_fuel_price'])  ? (float)$expense_data['expense_fuel_price']  : 'NULL').",
            'booking', ".((int)$conf->entity).", NOW(), ".((int)$user->id).")");
    }

    // ── ROAD ──
    if (!empty($expense_data['expense_road']) && (float)$expense_data['expense_road'] > 0) {
        $ref = $getRef();
        $db->query("INSERT INTO ".MAIN_DB_PREFIX."flotte_expense
            (ref, fk_booking, expense_date, category, amount,
             road_toll, road_parking, road_other,
             source, entity, date_creation, fk_user_creat) VALUES (
            '".$db->escape($ref)."', ".((int)$booking_id).", '".$exp_date."', 'road',
            ".((float)$expense_data['expense_road']).",
            ".(!empty($expense_data['expense_road_toll'])    ? (float)$expense_data['expense_road_toll']    : 'NULL').",
            ".(!empty($expense_data['expense_road_parking']) ? (float)$expense_data['expense_road_parking'] : 'NULL').",
            ".(!empty($expense_data['expense_road_other'])   ? (float)$expense_data['expense_road_other']   : 'NULL').",
            'booking', ".((int)$conf->entity).", NOW(), ".((int)$user->id).")");
    }

    // ── DRIVER ──
    if (!empty($expense_data['expense_driver']) && (float)$expense_data['expense_driver'] > 0) {
        $ref = $getRef();
        $db->query("INSERT INTO ".MAIN_DB_PREFIX."flotte_expense
            (ref, fk_booking, expense_date, category, amount,
             driver_salary, driver_overnight, driver_bonus,
             source, entity, date_creation, fk_user_creat) VALUES (
            '".$db->escape($ref)."', ".((int)$booking_id).", '".$exp_date."', 'driver',
            ".((float)$expense_data['expense_driver']).",
            ".(!empty($expense_data['expense_driver_salary'])    ? (float)$expense_data['expense_driver_salary']    : 'NULL').",
            ".(!empty($expense_data['expense_driver_overnight']) ? (float)$expense_data['expense_driver_overnight'] : 'NULL').",
            ".(!empty($expense_data['expense_driver_bonus'])     ? (float)$expense_data['expense_driver_bonus']     : 'NULL').",
            'booking', ".((int)$conf->entity).", NOW(), ".((int)$user->id).")");
    }

    // ── COMMISSION ──
    if (!empty($expense_data['expense_commission']) && (float)$expense_data['expense_commission'] > 0) {
        $ref = $getRef();
        $db->query("INSERT INTO ".MAIN_DB_PREFIX."flotte_expense
            (ref, fk_booking, expense_date, category, amount,
             commission_agent, commission_tax, commission_other,
             source, entity, date_creation, fk_user_creat) VALUES (
            '".$db->escape($ref)."', ".((int)$booking_id).", '".$exp_date."', 'commission',
            ".((float)$expense_data['expense_commission']).",
            ".(!empty($expense_data['expense_commission_agent']) ? (float)$expense_data['expense_commission_agent'] : 'NULL').",
            ".(!empty($expense_data['expense_commission_tax'])   ? (float)$expense_data['expense_commission_tax']   : 'NULL').",
            ".(!empty($expense_data['expense_commission_other']) ? (float)$expense_data['expense_commission_other'] : 'NULL').",
            'booking', ".((int)$conf->entity).", NOW(), ".((int)$user->id).")");
    }
}

// Get parameters
$id = GETPOST('id', 'int');
$action = GETPOST('action', 'aZ09') ? GETPOST('action', 'aZ09') : 'view';
$confirm = GETPOST('confirm', 'alpha');
$cancel = GETPOST('cancel', 'alpha');

// Security check
restrictedArea($user, 'flotte');

// Initialize variables
$object = new stdClass();
$object->id = 0;
$object->ref = '';
$object->fk_vehicle = '';
$object->fk_driver = '';
$object->fk_vendor = '';
$object->fk_customer = '';
$object->buying_tax_rate = '';
$object->selling_tax_rate = '';
$object->booking_date = '';
$object->status = 'pending';
$object->distance = '';
$object->arriving_address = '';
$object->departure_address = '';
$object->dep_lat = '';
$object->dep_lon = '';
$object->arr_lat = '';
$object->arr_lon = '';
$object->buying_amount = '';
$object->selling_amount = '';
$object->buying_qty = '';
$object->buying_price = '';
$object->buying_unit = '';
$object->buying_amount_ttc = '';
$object->selling_qty = '';
$object->selling_price = '';
$object->selling_unit = '';
$object->selling_amount_ttc = '';
$object->stops = '';
$object->eta = '';
$object->pickup_datetime = '';
$object->dropoff_datetime = '';
$object->expense_fuel = '';
$object->expense_fuel_qty = '';
$object->expense_fuel_price = '';
$object->expense_fuel_type = '';
$object->expense_fuel_vendor = '';
$object->expense_road = '';
$object->expense_road_toll = '';
$object->expense_road_parking = '';
$object->expense_road_other = '';
$object->expense_driver = '';
$object->expense_driver_salary = '';
$object->expense_driver_overnight = '';
$object->expense_driver_bonus = '';
$object->expense_commission = '';
$object->expense_commission_agent = '';
$object->expense_commission_tax = '';
$object->expense_commission_other = '';

$error = 0;
$errors = array();

// Generate reference for new booking
if ($action == 'create' && empty($object->ref)) {
    $object->ref = getNextBookingRef($db, $conf->entity);
}

/*
 * Actions
 */

if ($cancel) {
    $action = 'view';
}

if ($action == 'confirm_delete' && $confirm == 'yes' && !empty($user->rights->flotte->delete)) {
    $db->begin();
    
    $sql = "DELETE FROM ".MAIN_DB_PREFIX."flotte_booking WHERE rowid = ".((int) $id);
    $result = $db->query($sql);
    
    if ($result) {
        $db->commit();
        header("Location: " . dol_buildpath('/flotte/booking_list.php', 1));
        exit;
    } else {
        $db->rollback();
        $error++;
        setEventMessages($langs->trans("ErrorDeletingBooking"), null, 'errors');
    }
}

if ($action == 'add') {
    $db->begin();
    
    // Get form data with proper validation
    $ref = GETPOST('ref', 'alpha');
    $fk_vehicle = GETPOST('fk_vehicle', 'int');
    $fk_driver = GETPOST('fk_driver', 'int');
    $fk_vendor = GETPOST('fk_vendor', 'int');
    $fk_customer = GETPOST('fk_customer', 'int');
    $buying_tax_rate  = GETPOST('buying_tax_rate', 'alpha');
    $selling_tax_rate = GETPOST('selling_tax_rate', 'alpha');

    // Fix: Convert date to MySQL format
    $booking_date_raw = GETPOST('booking_date', 'alpha');
    $booking_date = '';
    if (!empty($booking_date_raw)) {
        $day = GETPOST('booking_dateday', 'int');
        $month = GETPOST('booking_datemonth', 'int');
        $year = GETPOST('booking_dateyear', 'int');
        if ($day > 0 && $month > 0 && $year > 0) {
            $booking_date = sprintf('%04d-%02d-%02d', $year, $month, $day);
        } else {
            $booking_date = convertDateToMysql($booking_date_raw);
        }
    }

    $status           = GETPOST('status', 'alpha');
    $distance         = GETPOST('distance', 'int');
    $arriving_address  = GETPOST('arriving_address', 'restricthtml');
    $departure_address = GETPOST('departure_address', 'restricthtml');
    $dep_lat = GETPOST('dep_lat', 'alpha');
    $dep_lon = GETPOST('dep_lon', 'alpha');
    $arr_lat = GETPOST('arr_lat', 'alpha');
    $arr_lon = GETPOST('arr_lon', 'alpha');
    $buying_amount      = GETPOST('buying_amount', 'alpha');
    $selling_amount     = GETPOST('selling_amount', 'alpha');
    $buying_qty         = GETPOST('buying_qty', 'alpha');
    $buying_price       = GETPOST('buying_price', 'alpha');
    $buying_unit        = GETPOST('buying_unit', 'alphanohtml');
    $buying_amount_ttc  = GETPOST('buying_amount_ttc', 'alpha');
    $selling_qty        = GETPOST('selling_qty', 'alpha');
    $selling_price      = GETPOST('selling_price', 'alpha');
    $selling_unit       = GETPOST('selling_unit', 'alphanohtml');
    $selling_amount_ttc = GETPOST('selling_amount_ttc', 'alpha');
    $stops            = GETPOST('stops', 'restricthtml');
    $eta              = GETPOST('eta', 'alpha');
    $pickup_ts  = dol_mktime(GETPOST('pickup_datetimehour','int'), GETPOST('pickup_datetimemin','int'), 0, GETPOST('pickup_datetimemonth','int'), GETPOST('pickup_datetimeday','int'), GETPOST('pickup_datetimeyear','int'));
    $pickup_datetime  = ($pickup_ts > 0) ? date('Y-m-d H:i:s', $pickup_ts) : '';
    $dropoff_ts = dol_mktime(GETPOST('dropoff_datetimehour','int'), GETPOST('dropoff_datetimemin','int'), 0, GETPOST('dropoff_datetimemonth','int'), GETPOST('dropoff_datetimeday','int'), GETPOST('dropoff_datetimeyear','int'));
    $dropoff_datetime = ($dropoff_ts > 0) ? date('Y-m-d H:i:s', $dropoff_ts) : '';
    $expense_fuel_qty        = GETPOST('expense_fuel_qty', 'alpha');
    $expense_fuel_price      = GETPOST('expense_fuel_price', 'alpha');
    $expense_fuel_type       = GETPOST('expense_fuel_type', 'alphanohtml');
    $expense_fuel_vendor     = GETPOST('expense_fuel_vendor', 'int');
    $expense_fuel            = ($expense_fuel_qty && $expense_fuel_price) ? round((float)$expense_fuel_qty * (float)$expense_fuel_price, 2) : GETPOST('expense_fuel', 'alpha');
    $expense_road_toll       = GETPOST('expense_road_toll', 'alpha');
    $expense_road_parking    = GETPOST('expense_road_parking', 'alpha');
    $expense_road_other      = GETPOST('expense_road_other', 'alpha');
    $expense_road            = round((float)$expense_road_toll + (float)$expense_road_parking + (float)$expense_road_other, 2);
    $expense_driver_salary   = GETPOST('expense_driver_salary', 'alpha');
    $expense_driver_overnight= GETPOST('expense_driver_overnight', 'alpha');
    $expense_driver_bonus    = GETPOST('expense_driver_bonus', 'alpha');
    $expense_driver          = round((float)$expense_driver_salary + (float)$expense_driver_overnight + (float)$expense_driver_bonus, 2);
    $expense_commission_agent= GETPOST('expense_commission_agent', 'alpha');
    $expense_commission_tax  = GETPOST('expense_commission_tax', 'alpha');
    $expense_commission_other= GETPOST('expense_commission_other', 'alpha');
    $expense_commission_tax_amt = round((float)$expense_commission_agent * (float)$expense_commission_tax / 100, 2);
    $expense_commission      = round((float)$expense_commission_agent + $expense_commission_tax_amt + (float)$expense_commission_other, 2);

    // Auto-generate reference if empty
    if (empty($ref)) {
        $ref = getNextBookingRef($db, $conf->entity);
    }
    
    // Validation
    if (empty($fk_vehicle)) {
        $error++;
        $errors[] = $langs->trans("ErrorFieldRequired", $langs->trans("Vehicle"));
    }
    if (empty($fk_customer)) {
        $error++;
        $errors[] = $langs->trans("ErrorFieldRequired", $langs->trans("Customer"));
    }
    if (empty($booking_date)) {
        $error++;
        $errors[] = $langs->trans("ErrorFieldRequired", $langs->trans("BookingDate"));
    }
    if (empty($status)) {
        $status = 'pending';
    }
    
    if (!$error) {
        $sql = "INSERT INTO ".MAIN_DB_PREFIX."flotte_booking (";
        $sql .= "ref, entity, fk_vehicle, fk_driver, fk_vendor, fk_customer, booking_date, status, distance, ";
        $sql .= "arriving_address, departure_address, dep_lat, dep_lon, arr_lat, arr_lon, buying_amount, selling_amount, stops, eta, pickup_datetime, dropoff_datetime, ";
        $sql .= "buying_tax_rate, buying_qty, buying_price, buying_unit, buying_amount_ttc, ";
        $sql .= "selling_tax_rate, selling_qty, selling_price, selling_unit, selling_amount_ttc, ";
        $sql .= "expense_fuel, expense_fuel_qty, expense_fuel_price, expense_fuel_type, expense_fuel_vendor, ";
        $sql .= "expense_road, expense_road_toll, expense_road_parking, expense_road_other, ";
        $sql .= "expense_driver, expense_driver_salary, expense_driver_overnight, expense_driver_bonus, ";
        $sql .= "expense_commission, expense_commission_agent, expense_commission_tax, expense_commission_other, ";
        $sql .= "fk_user_author";
        $sql .= ") VALUES (";
        $sql .= "'".$db->escape($ref)."', ".((int) $conf->entity).", ";
        $sql .= "".((int) $fk_vehicle).", ";
        $sql .= ($fk_driver > 0 ? ((int) $fk_driver) : "NULL").", ";
        $sql .= ($fk_vendor > 0 ? ((int) $fk_vendor) : "NULL").", ";
        $sql .= "".((int) $fk_customer).", ";
        $sql .= "'".$db->escape($booking_date)."', ";
        $sql .= "'".$db->escape($status)."', ";
        $sql .= ($distance > 0 ? ((int) $distance) : "NULL").", ";
        $sql .= "'".$db->escape($arriving_address)."', ";
        $sql .= "'".$db->escape($departure_address)."', ";
        $sql .= (!empty($dep_lat) ? "'".$db->escape($dep_lat)."'" : "NULL").", ";
        $sql .= (!empty($dep_lon) ? "'".$db->escape($dep_lon)."'" : "NULL").", ";
        $sql .= (!empty($arr_lat) ? "'".$db->escape($arr_lat)."'" : "NULL").", ";
        $sql .= (!empty($arr_lon) ? "'".$db->escape($arr_lon)."'" : "NULL").", ";
        $sql .= ($buying_amount ? ((float) $buying_amount) : "NULL").", ";
        $sql .= ($selling_amount ? ((float) $selling_amount) : "NULL").", ";
        $sql .= (!empty($stops) ? "'".$db->escape($stops)."'" : "NULL").", ";
        $sql .= (!empty($eta) ? "'".$db->escape($eta)."'" : "NULL").", ";
        $sql .= (!empty($pickup_datetime) ? "'".$db->escape($pickup_datetime)."'" : "NULL").", ";
        $sql .= (!empty($dropoff_datetime) ? "'".$db->escape($dropoff_datetime)."'" : "NULL").", ";
        $sql .= (!empty($buying_tax_rate) ? "'".$db->escape($buying_tax_rate)."'" : "NULL").", ";
        $sql .= ($buying_qty ? ((float) $buying_qty) : "NULL").", ";
        $sql .= ($buying_price ? ((float) $buying_price) : "NULL").", ";
        $sql .= (!empty($buying_unit) ? "'".$db->escape($buying_unit)."'" : "NULL").", ";
        $sql .= ($buying_amount_ttc ? ((float) $buying_amount_ttc) : "NULL").", ";
        $sql .= (!empty($selling_tax_rate) ? "'".$db->escape($selling_tax_rate)."'" : "NULL").", ";
        $sql .= ($selling_qty ? ((float) $selling_qty) : "NULL").", ";
        $sql .= ($selling_price ? ((float) $selling_price) : "NULL").", ";
        $sql .= (!empty($selling_unit) ? "'".$db->escape($selling_unit)."'" : "NULL").", ";
        $sql .= ($selling_amount_ttc ? ((float) $selling_amount_ttc) : "NULL").", ";
        $sql .= ($expense_fuel       ? ((float) $expense_fuel)       : "NULL").", ";
        $sql .= ($expense_fuel_qty   ? ((float) $expense_fuel_qty)   : "NULL").", ";
        $sql .= ($expense_fuel_price ? ((float) $expense_fuel_price) : "NULL").", ";
        $sql .= (!empty($expense_fuel_type)   ? "'".$db->escape($expense_fuel_type)."'" : "NULL").", ";
        $sql .= ($expense_fuel_vendor > 0 ? ((int) $expense_fuel_vendor) : "NULL").", ";
        $sql .= ($expense_road        ? ((float) $expense_road)        : "NULL").", ";
        $sql .= ($expense_road_toll   ? ((float) $expense_road_toll)   : "NULL").", ";
        $sql .= ($expense_road_parking? ((float) $expense_road_parking): "NULL").", ";
        $sql .= ($expense_road_other  ? ((float) $expense_road_other)  : "NULL").", ";
        $sql .= ($expense_driver          ? ((float) $expense_driver)          : "NULL").", ";
        $sql .= ($expense_driver_salary   ? ((float) $expense_driver_salary)   : "NULL").", ";
        $sql .= ($expense_driver_overnight? ((float) $expense_driver_overnight): "NULL").", ";
        $sql .= ($expense_driver_bonus    ? ((float) $expense_driver_bonus)    : "NULL").", ";
        $sql .= ($expense_commission       ? ((float) $expense_commission)       : "NULL").", ";
        $sql .= ($expense_commission_agent ? ((float) $expense_commission_agent) : "NULL").", ";
        $sql .= ($expense_commission_tax   ? ((float) $expense_commission_tax)   : "NULL").", ";
        $sql .= ($expense_commission_other ? ((float) $expense_commission_other) : "NULL").", ";
        $sql .= ((int) $user->id);
        $sql .= ")";
        
        $result = $db->query($sql);
        if ($result) {
            $id = $db->last_insert_id(MAIN_DB_PREFIX."flotte_booking");
            $db->commit();
            // Sync expenses to flotte_expense table
            $expense_sync_data = compact(
                'expense_fuel','expense_fuel_qty','expense_fuel_price','expense_fuel_type','expense_fuel_vendor',
                'expense_road','expense_road_toll','expense_road_parking','expense_road_other',
                'expense_driver','expense_driver_salary','expense_driver_overnight','expense_driver_bonus',
                'expense_commission','expense_commission_agent','expense_commission_tax','expense_commission_other'
            );
            syncBookingExpensesToTable($db, $id, $booking_date, $conf, $user, $expense_sync_data);
            $action = 'view';
            setEventMessages($langs->trans("BookingCreatedSuccessfully"), null, 'mesgs');
        } else {
            $db->rollback();
            $error++;
            $errors[] = $langs->trans("ErrorCreatingBooking") . ": " . $db->lasterror();
        }
    }
    
    if ($error) {
        $db->rollback();
    }
}

if ($action == 'update' && $id > 0) {
    $db->begin();
    
    // Get form data with proper validation
    $ref = GETPOST('ref', 'alpha');
    $fk_vehicle = GETPOST('fk_vehicle', 'int');
    $fk_driver = GETPOST('fk_driver', 'int');
    $fk_vendor = GETPOST('fk_vendor', 'int');
    $fk_customer = GETPOST('fk_customer', 'int');
    $buying_tax_rate  = GETPOST('buying_tax_rate', 'alpha');
    $selling_tax_rate = GETPOST('selling_tax_rate', 'alpha');
    $booking_date_raw = GETPOST('booking_date', 'alpha');
    $booking_date = '';
    if (!empty($booking_date_raw)) {
        // Handle Dolibarr date format
        $day = GETPOST('booking_dateday', 'int');
        $month = GETPOST('booking_datemonth', 'int');
        $year = GETPOST('booking_dateyear', 'int');
        
        if ($day > 0 && $month > 0 && $year > 0) {
            $booking_date = sprintf('%04d-%02d-%02d', $year, $month, $day);
        } else {
            $booking_date = convertDateToMysql($booking_date_raw);
        }
    }
    
    $status = GETPOST('status', 'alpha');
    $distance = GETPOST('distance', 'int');
    $arriving_address = GETPOST('arriving_address', 'restricthtml');
    $departure_address = GETPOST('departure_address', 'restricthtml');
    $dep_lat = GETPOST('dep_lat', 'alpha');
    $dep_lon = GETPOST('dep_lon', 'alpha');
    $arr_lat = GETPOST('arr_lat', 'alpha');
    $arr_lon = GETPOST('arr_lon', 'alpha');
    $buying_amount      = GETPOST('buying_amount', 'alpha');
    $selling_amount     = GETPOST('selling_amount', 'alpha');
    $buying_qty         = GETPOST('buying_qty', 'alpha');
    $buying_price       = GETPOST('buying_price', 'alpha');
    $buying_unit        = GETPOST('buying_unit', 'alphanohtml');
    $buying_amount_ttc  = GETPOST('buying_amount_ttc', 'alpha');
    $selling_qty        = GETPOST('selling_qty', 'alpha');
    $selling_price      = GETPOST('selling_price', 'alpha');
    $selling_unit       = GETPOST('selling_unit', 'alphanohtml');
    $selling_amount_ttc = GETPOST('selling_amount_ttc', 'alpha');
    $stops = GETPOST('stops', 'restricthtml');
    $eta = GETPOST('eta', 'alpha');
    $pickup_ts  = dol_mktime(GETPOST('pickup_datetimehour','int'), GETPOST('pickup_datetimemin','int'), 0, GETPOST('pickup_datetimemonth','int'), GETPOST('pickup_datetimeday','int'), GETPOST('pickup_datetimeyear','int'));
    $pickup_datetime  = ($pickup_ts > 0) ? date('Y-m-d H:i:s', $pickup_ts) : '';
    $dropoff_ts = dol_mktime(GETPOST('dropoff_datetimehour','int'), GETPOST('dropoff_datetimemin','int'), 0, GETPOST('dropoff_datetimemonth','int'), GETPOST('dropoff_datetimeday','int'), GETPOST('dropoff_datetimeyear','int'));
    $dropoff_datetime = ($dropoff_ts > 0) ? date('Y-m-d H:i:s', $dropoff_ts) : '';
    $expense_fuel_qty        = GETPOST('expense_fuel_qty', 'alpha');
    $expense_fuel_price      = GETPOST('expense_fuel_price', 'alpha');
    $expense_fuel_type       = GETPOST('expense_fuel_type', 'alphanohtml');
    $expense_fuel_vendor     = GETPOST('expense_fuel_vendor', 'int');
    $expense_fuel            = ($expense_fuel_qty && $expense_fuel_price) ? round((float)$expense_fuel_qty * (float)$expense_fuel_price, 2) : GETPOST('expense_fuel', 'alpha');
    $expense_road_toll       = GETPOST('expense_road_toll', 'alpha');
    $expense_road_parking    = GETPOST('expense_road_parking', 'alpha');
    $expense_road_other      = GETPOST('expense_road_other', 'alpha');
    $expense_road            = round((float)$expense_road_toll + (float)$expense_road_parking + (float)$expense_road_other, 2);
    $expense_driver_salary   = GETPOST('expense_driver_salary', 'alpha');
    $expense_driver_overnight= GETPOST('expense_driver_overnight', 'alpha');
    $expense_driver_bonus    = GETPOST('expense_driver_bonus', 'alpha');
    $expense_driver          = round((float)$expense_driver_salary + (float)$expense_driver_overnight + (float)$expense_driver_bonus, 2);
    $expense_commission_agent= GETPOST('expense_commission_agent', 'alpha');
    $expense_commission_tax  = GETPOST('expense_commission_tax', 'alpha');
    $expense_commission_other= GETPOST('expense_commission_other', 'alpha');
    $expense_commission_tax_amt = round((float)$expense_commission_agent * (float)$expense_commission_tax / 100, 2);
    $expense_commission      = round((float)$expense_commission_agent + $expense_commission_tax_amt + (float)$expense_commission_other, 2);
    
    // Validation
    if (empty($fk_vehicle)) {
        $error++;
        $errors[] = $langs->trans("ErrorFieldRequired", $langs->trans("Vehicle"));
    }
    if (empty($fk_customer)) {
        $error++;
        $errors[] = $langs->trans("ErrorFieldRequired", $langs->trans("Customer"));
    }
    if (empty($booking_date)) {
        $error++;
        $errors[] = $langs->trans("ErrorFieldRequired", $langs->trans("BookingDate"));
    }
    if (empty($status)) {
        $status = 'pending';
    }
    
    if (!$error) {
        $now = dol_now();
        
        $sql = "UPDATE ".MAIN_DB_PREFIX."flotte_booking SET ";
        $sql .= "ref = '".$db->escape($ref)."', ";
        $sql .= "fk_vehicle = ".((int) $fk_vehicle).", ";
        $sql .= "fk_driver = ".($fk_driver > 0 ? ((int) $fk_driver) : "NULL").", ";
        $sql .= "fk_vendor = ".($fk_vendor > 0 ? ((int) $fk_vendor) : "NULL").", ";
        $sql .= "fk_customer = ".((int) $fk_customer).", ";
        $sql .= "booking_date = '".$db->escape($booking_date)."', ";
        $sql .= "status = '".$db->escape($status)."', ";
        $sql .= "distance = ".($distance > 0 ? ((int) $distance) : "NULL").", ";
        $sql .= "arriving_address = '".$db->escape($arriving_address)."', ";
        $sql .= "departure_address = '".$db->escape($departure_address)."', ";
        $sql .= "dep_lat = ".(!empty($dep_lat) ? "'".$db->escape($dep_lat)."'" : "NULL").", ";
        $sql .= "dep_lon = ".(!empty($dep_lon) ? "'".$db->escape($dep_lon)."'" : "NULL").", ";
        $sql .= "arr_lat = ".(!empty($arr_lat) ? "'".$db->escape($arr_lat)."'" : "NULL").", ";
        $sql .= "arr_lon = ".(!empty($arr_lon) ? "'".$db->escape($arr_lon)."'" : "NULL").", ";
        $sql .= "buying_amount = ".($buying_amount ? ((float) $buying_amount) : "NULL").", ";
        $sql .= "selling_amount = ".($selling_amount ? ((float) $selling_amount) : "NULL").", ";
        $sql .= "buying_qty = ".($buying_qty ? ((float) $buying_qty) : "NULL").", ";
        $sql .= "buying_price = ".($buying_price ? ((float) $buying_price) : "NULL").", ";
        $sql .= "buying_unit = ".(!empty($buying_unit) ? "'".$db->escape($buying_unit)."'" : "NULL").", ";
        $sql .= "buying_amount_ttc = ".($buying_amount_ttc ? ((float) $buying_amount_ttc) : "NULL").", ";
        $sql .= "selling_qty = ".($selling_qty ? ((float) $selling_qty) : "NULL").", ";
        $sql .= "selling_price = ".($selling_price ? ((float) $selling_price) : "NULL").", ";
        $sql .= "selling_unit = ".(!empty($selling_unit) ? "'".$db->escape($selling_unit)."'" : "NULL").", ";
        $sql .= "selling_amount_ttc = ".($selling_amount_ttc ? ((float) $selling_amount_ttc) : "NULL").", ";
        $sql .= "stops = ".(!empty($stops) ? "'".$db->escape($stops)."'" : "NULL").", ";
        $sql .= "eta = ".(!empty($eta) ? "'".$db->escape($eta)."'" : "NULL").", ";
        $sql .= "pickup_datetime = ".(!empty($pickup_datetime) ? "'".$db->escape($pickup_datetime)."'" : "NULL").", ";
        $sql .= "dropoff_datetime = ".(!empty($dropoff_datetime) ? "'".$db->escape($dropoff_datetime)."'" : "NULL").", ";
        $sql .= "buying_tax_rate = ".(!empty($buying_tax_rate) ? "'".$db->escape($buying_tax_rate)."'" : "NULL").", ";
        $sql .= "selling_tax_rate = ".(!empty($selling_tax_rate) ? "'".$db->escape($selling_tax_rate)."'" : "NULL").", ";
        $sql .= "expense_fuel = ".($expense_fuel ? ((float) $expense_fuel) : "NULL").", ";
        $sql .= "expense_fuel_qty = ".($expense_fuel_qty ? ((float) $expense_fuel_qty) : "NULL").", ";
        $sql .= "expense_fuel_price = ".($expense_fuel_price ? ((float) $expense_fuel_price) : "NULL").", ";
        $sql .= "expense_fuel_type = ".(!empty($expense_fuel_type) ? "'".$db->escape($expense_fuel_type)."'" : "NULL").", ";
        $sql .= "expense_fuel_vendor = ".($expense_fuel_vendor > 0 ? ((int) $expense_fuel_vendor) : "NULL").", ";
        $sql .= "expense_road = ".($expense_road ? ((float) $expense_road) : "NULL").", ";
        $sql .= "expense_road_toll = ".($expense_road_toll ? ((float) $expense_road_toll) : "NULL").", ";
        $sql .= "expense_road_parking = ".($expense_road_parking ? ((float) $expense_road_parking) : "NULL").", ";
        $sql .= "expense_road_other = ".($expense_road_other ? ((float) $expense_road_other) : "NULL").", ";
        $sql .= "expense_driver = ".($expense_driver ? ((float) $expense_driver) : "NULL").", ";
        $sql .= "expense_driver_salary = ".($expense_driver_salary ? ((float) $expense_driver_salary) : "NULL").", ";
        $sql .= "expense_driver_overnight = ".($expense_driver_overnight ? ((float) $expense_driver_overnight) : "NULL").", ";
        $sql .= "expense_driver_bonus = ".($expense_driver_bonus ? ((float) $expense_driver_bonus) : "NULL").", ";
        $sql .= "expense_commission = ".($expense_commission ? ((float) $expense_commission) : "NULL").", ";
        $sql .= "expense_commission_agent = ".($expense_commission_agent ? ((float) $expense_commission_agent) : "NULL").", ";
        $sql .= "expense_commission_tax = ".($expense_commission_tax ? ((float) $expense_commission_tax) : "NULL").", ";
        $sql .= "expense_commission_other = ".($expense_commission_other ? ((float) $expense_commission_other) : "NULL").", ";
        $sql .= "fk_user_modif = ".((int) $user->id).", ";
        $sql .= "tms = '".$db->idate($now)."' ";
        $sql .= "WHERE rowid = ".((int) $id);
        
        $result = $db->query($sql);
        if ($result) {
            $db->commit();
            // Sync expenses to flotte_expense table
            $expense_sync_data = compact(
                'expense_fuel','expense_fuel_qty','expense_fuel_price','expense_fuel_type','expense_fuel_vendor',
                'expense_road','expense_road_toll','expense_road_parking','expense_road_other',
                'expense_driver','expense_driver_salary','expense_driver_overnight','expense_driver_bonus',
                'expense_commission','expense_commission_agent','expense_commission_tax','expense_commission_other'
            );
            syncBookingExpensesToTable($db, $id, $booking_date, $conf, $user, $expense_sync_data);
            $action = 'view';
            setEventMessages($langs->trans("BookingUpdatedSuccessfully"), null, 'mesgs');
        } else {
            $db->rollback();
            $error++;
            $errors[] = $langs->trans("ErrorUpdatingBooking") . ": " . $db->lasterror();
        }
    }
    
    if ($error) {
        $db->rollback();
    }
}

// Ensure pickup/dropoff columns exist (safe for all MySQL versions)
$_chk = $db->query("SHOW COLUMNS FROM ".MAIN_DB_PREFIX."flotte_booking LIKE 'pickup_datetime'");
if ($_chk && $db->num_rows($_chk) == 0) {
    $db->query("ALTER TABLE ".MAIN_DB_PREFIX."flotte_booking ADD COLUMN pickup_datetime DATETIME DEFAULT NULL");
}
$_chk = $db->query("SHOW COLUMNS FROM ".MAIN_DB_PREFIX."flotte_booking LIKE 'dropoff_datetime'");
if ($_chk && $db->num_rows($_chk) == 0) {
    $db->query("ALTER TABLE ".MAIN_DB_PREFIX."flotte_booking ADD COLUMN dropoff_datetime DATETIME DEFAULT NULL");
}
foreach (array('expense_fuel','expense_road','expense_driver','expense_commission') as $exp_col) {
    $_chk = $db->query("SHOW COLUMNS FROM ".MAIN_DB_PREFIX."flotte_booking LIKE '".$exp_col."'");
    if ($_chk && $db->num_rows($_chk) == 0) {
        $db->query("ALTER TABLE ".MAIN_DB_PREFIX."flotte_booking ADD COLUMN ".$exp_col." DECIMAL(15,2) DEFAULT NULL");
    }
}
// New sub-columns
$_new_cols = array(
    'expense_fuel_qty'           => 'DECIMAL(15,4) DEFAULT NULL',
    'expense_fuel_price'         => 'DECIMAL(15,4) DEFAULT NULL',
    'expense_fuel_type'          => 'VARCHAR(50) DEFAULT NULL',
    'expense_fuel_vendor'        => 'INT DEFAULT NULL',
    'expense_road_toll'          => 'DECIMAL(15,2) DEFAULT NULL',
    'expense_road_parking'       => 'DECIMAL(15,2) DEFAULT NULL',
    'expense_road_other'         => 'DECIMAL(15,2) DEFAULT NULL',
    'expense_driver_salary'      => 'DECIMAL(15,2) DEFAULT NULL',
    'expense_driver_overnight'   => 'DECIMAL(15,2) DEFAULT NULL',
    'expense_driver_bonus'       => 'DECIMAL(15,2) DEFAULT NULL',
    'expense_commission_agent'   => 'DECIMAL(15,2) DEFAULT NULL',
    'expense_commission_tax'     => 'DECIMAL(5,2) DEFAULT NULL',
    'expense_commission_other'   => 'DECIMAL(15,2) DEFAULT NULL',
);
foreach ($_new_cols as $_col => $_type) {
    $_chk = $db->query("SHOW COLUMNS FROM ".MAIN_DB_PREFIX."flotte_booking LIKE '".$_col."'");
    if ($_chk && $db->num_rows($_chk) == 0) {
        $db->query("ALTER TABLE ".MAIN_DB_PREFIX."flotte_booking ADD COLUMN ".$_col." ".$_type);
    }
}

// Load object data
if ($id > 0) {
    $sql = "SELECT * FROM ".MAIN_DB_PREFIX."flotte_booking WHERE rowid = ".((int) $id);
    $resql = $db->query($sql);
    if ($resql) {
        $object = $db->fetch_object($resql);
        if (!$object) {
            header("HTTP/1.0 404 Not Found");
            print $langs->trans("BookingNotFound");
            exit;
        }
    } else {
        header("HTTP/1.0 500 Internal Server Error");
        print $langs->trans("ErrorLoadingBooking") . ": " . $db->lasterror();
        exit;
    }
}

$form = new Form($db);

// Initialize technical object to manage hooks
$hookmanager->initHooks(array('bookingcard'));

/*
 * View
 */

$title = $langs->trans('Booking');
if ($action == 'create') {
    $title = $langs->trans('NewBooking');
} elseif ($action == 'edit') {
    $title = $langs->trans('EditBooking');
} elseif ($id > 0) {
    $title = $langs->trans('Booking') . " " . $object->ref;
}

llxHeader('', $title);

?>
<style>


</style>

<style>
@import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=DM+Mono:wght@400;500&display=swap');

.dc-page * { box-sizing: border-box; }
.dc-page {
    font-family: 'DM Sans', sans-serif;
    max-width: 1160px;
    margin: 0 auto;
    padding: 0 2px 48px;
    color: #1a1f2e;
}

/* ── Page header ── */
.dc-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 26px 0 22px;
    border-bottom: 1px solid #e8eaf0;
    margin-bottom: 28px;
    gap: 16px;
    flex-wrap: wrap;
}
.dc-header-left { display: flex; align-items: center; gap: 14px; }
.dc-header-icon {
    width: 46px; height: 46px; border-radius: 12px;
    background: rgba(60,71,88,0.1);
    display: flex; align-items: center; justify-content: center;
    color: #3c4758; font-size: 20px; flex-shrink: 0;
}
.dc-header-title { font-size: 21px; font-weight: 700; color: #1a1f2e; margin: 0 0 3px; letter-spacing: -0.3px; }
.dc-header-sub { font-size: 12.5px; color: #8b92a9; font-weight: 400; }
.dc-header-actions { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }

/* ── Status badges ── */
.dc-badge {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 4px 11px; border-radius: 20px;
    font-size: 11.5px; font-weight: 600; white-space: nowrap;
}
.dc-badge::before { content: ''; width: 6px; height: 6px; border-radius: 50%; flex-shrink: 0; }
.dc-badge.pending     { background: #fff8ec; color: #b45309; }
.dc-badge.pending::before     { background: #f59e0b; }
.dc-badge.confirmed   { background: #eff6ff; color: #1d4ed8; }
.dc-badge.confirmed::before   { background: #3b82f6; }
.dc-badge.in_progress { background: #f5f3ff; color: #6d28d9; }
.dc-badge.in_progress::before { background: #8b5cf6; }
.dc-badge.completed   { background: #edfaf3; color: #1a7d4a; }
.dc-badge.completed::before   { background: #22c55e; }
.dc-badge.cancelled   { background: #fef2f2; color: #b91c1c; }
.dc-badge.cancelled::before   { background: #ef4444; }

/* ── Buttons ── */
.dc-btn {
    display: inline-flex; align-items: center; gap: 7px;
    padding: 8px 16px; border-radius: 6px;
    font-size: 13px; font-weight: 600;
    text-decoration: none !important; cursor: pointer;
    font-family: 'DM Sans', sans-serif; white-space: nowrap;
    transition: all 0.15s ease; border: none;
}
.dc-btn-primary  { background: #3c4758 !important; color: #fff !important; }
.dc-btn-primary:hover  { background: #2a3346 !important; color: #fff !important; }
.dc-btn-ghost {
    background: #fff !important; color: #5a6482 !important;
    border: 1.5px solid #d1d5e0 !important;
}
.dc-btn-ghost:hover { background: #f5f6fa !important; color: #2d3748 !important; }
.dc-btn-danger {
    background: #fef2f2 !important; color: #dc2626 !important;
    border: 1.5px solid #fecaca !important;
}
.dc-btn-danger:hover { background: #fee2e2 !important; color: #b91c1c !important; }
button.dc-btn-primary {
    background: #3c4758 !important; color: #fff !important; border: none !important;
}
button.dc-btn-primary:hover { background: #2a3346 !important; }

/* ── Two-column grid ── */
.dc-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
    margin-bottom: 20px;
}
@media (max-width: 780px) { .dc-grid { grid-template-columns: 1fr; } }

/* ── Section card ── */
.dc-card {
    background: #fff;
    border: 1px solid #e8eaf0;
    border-radius: 12px;
    overflow: visible;
    box-shadow: 0 1px 6px rgba(0,0,0,0.04);
    /* No position/z-index here — avoids trapping child dropdowns in a stacking context */
}
/* Keep header/body rounded corners without overflow:hidden on the card */
.dc-card-header {
    border-radius: 12px 12px 0 0;
}
.dc-card-body > .dc-field:last-child {
    border-radius: 0 0 12px 12px;
}
.dc-card-header {
    display: flex; align-items: center; gap: 10px;
    padding: 14px 20px;
    border-bottom: 1px solid #f0f2f8;
    background: #f7f8fc;
}
.dc-card-header-icon {
    width: 28px; height: 28px; border-radius: 7px;
    display: flex; align-items: center; justify-content: center;
    font-size: 13px; flex-shrink: 0;
}
.dc-card-header-icon.blue   { background: rgba(60,71,88,0.1);  color: #3c4758; }
.dc-card-header-icon.green  { background: rgba(22,163,74,0.1);  color: #16a34a; }
.dc-card-header-icon.amber  { background: rgba(217,119,6,0.1);  color: #d97706; }
.dc-card-header-icon.purple { background: rgba(109,40,217,0.1); color: #6d28d9; }
.dc-card-title { font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.7px; color: #8b92a9; }
.dc-card-body { padding: 0; }

/* ── Field rows ── */
.dc-field {
    display: flex; align-items: flex-start;
    padding: 12px 20px;
    border-bottom: 1px solid #f5f6fb;
    gap: 12px;
}
.dc-field:last-child { border-bottom: none; }
.dc-field-label {
    flex: 0 0 160px; font-size: 12px; font-weight: 600;
    color: #8b92a9; text-transform: uppercase; letter-spacing: 0.5px;
    padding-top: 2px; line-height: 1.4;
}
.dc-field-label.required::after { content: ' *'; color: #ef4444; }
.dc-field-value { flex: 1; font-size: 13.5px; color: #2d3748; line-height: 1.5; min-width: 0; }
.dc-field-value a { color: #3c4758; }

/* ── Mono / chip ── */
.dc-mono {
    font-family: 'DM Mono', monospace; font-size: 12px;
    background: #f0f2fa; color: #4a5568;
    padding: 3px 9px; border-radius: 5px; display: inline-block;
}
.dc-chip {
    display: inline-flex; align-items: center; gap: 6px;
    font-size: 12.5px; font-weight: 600; color: #3c4758;
    background: rgba(60,71,88,0.07); padding: 4px 10px; border-radius: 6px;
}

/* ── Amount display ── */
.dc-amount {
    font-family: 'DM Mono', monospace; font-size: 13px;
    font-weight: 500; color: #2d3748;
}
.dc-amount.selling { color: #16a34a; }
.dc-amount.buying  { color: #dc2626; }
.dc-tax-badge { background: rgba(109,40,217,0.08) !important; color: #6d28d9 !important; }

/* ── Inline tax selector ── */
.dc-field-value-inline { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.dc-field-value-inline > select,
.dc-field-value-inline > div > select { flex: 1; min-width: 120px; }
.dc-inline-tax { display: flex; align-items: center; gap: 6px; flex-shrink: 0; }
.dc-inline-tax-label { font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: #8b92a9; white-space: nowrap; }
select.dc-tax-select { width: auto !important; min-width: 70px !important; max-width: 80px !important; padding: 8px 8px !important; color: #6d28d9 !important; border-color: rgba(109,40,217,0.25) !important; background: rgba(109,40,217,0.04) !important; font-weight: 600 !important; }
select.dc-tax-select:focus { border-color: #6d28d9 !important; box-shadow: 0 0 0 3px rgba(109,40,217,0.1) !important; }
.dc-tax-input-wrap { display: inline-flex; align-items: center; gap: 3px; }
input.dc-tax-input { width: 54px !important; max-width: 54px !important; min-width: 0 !important; padding: 8px 6px !important; text-align: center !important; color: #6d28d9 !important; border-color: rgba(109,40,217,0.25) !important; background: rgba(109,40,217,0.04) !important; font-weight: 600 !important; }
input.dc-tax-input:focus { border-color: #6d28d9 !important; box-shadow: 0 0 0 3px rgba(109,40,217,0.1) !important; background: #fff !important; }
.dc-tax-pct-symbol { font-size: 12px; font-weight: 700; color: #6d28d9; }
.dc-pricing-header .dc-field-value { flex-direction: column; gap: 0; }
.dc-pricing-grid { width: 100%; display: flex; flex-direction: column; gap: 10px; }
.dc-pricing-row { display: flex; gap: 10px; align-items: flex-end; flex-wrap: wrap; }
.dc-pricing-inputs { border-bottom: 1px dashed #e8eaf0; padding-bottom: 10px; }
.dc-pricing-col { display: flex; flex-direction: column; gap: 4px; flex: 1; min-width: 80px; }
.dc-pricing-label { font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: #8b92a9; }
.dc-pricing-results { align-items: center; gap: 8px; flex-wrap: wrap; }
.dc-pricing-result-item { display: flex; flex-direction: column; gap: 4px; flex: 1; min-width: 100px; }
.dc-pricing-result-sep { display: flex; flex-direction: column; align-items: center; gap: 2px; padding-top: 18px; color: #8b92a9; font-size: 11px; }
.dc-tax-icon { font-size: 9px; }
.dc-tax-pct { font-size: 10px; font-weight: 700; color: #6d28d9; }
input.dc-calc-result { background: #f5f6fa !important; color: #4a5568 !important; font-family: 'DM Mono', monospace !important; font-weight: 500 !important; }
input.dc-incl-val { background: #edfaf3 !important; color: #16a34a !important; font-family: 'DM Mono', monospace !important; font-weight: 600 !important; border-color: #bbf7d0 !important; }

/* view mode pricing */
.dc-pricing-view { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.dc-pricing-meta { font-size: 11.5px; color: #8b92a9; background: #f5f6fa; padding: 3px 8px; border-radius: 5px; }
.dc-pricing-arrow { color: #b0b8cc; font-size: 13px; }
.dc-amount.buying-ttc { color: #16a34a; font-weight: 700; }
.dc-amount.selling-ttc { color: #16a34a; font-weight: 700; }

/* ── Form inputs ── */
.dc-page input[type="text"],
.dc-page input[type="email"],
.dc-page input[type="number"],
.dc-page select,
.dc-page textarea {
    padding: 8px 12px !important;
    border: 1.5px solid #e2e5f0 !important;
    border-radius: 8px !important;
    font-size: 13px !important;
    font-family: 'DM Sans', sans-serif !important;
    color: #2d3748 !important;
    background: #fafbfe !important;
    outline: none !important;
    transition: border-color 0.15s, box-shadow 0.15s !important;
    width: 100% !important;
    max-width: 100% !important;
    box-sizing: border-box !important;
}
/* Date picker: let Dolibarr control its own layout */
.dc-page .blockdatepicker input[type="text"],
.dc-page input.hasDatepicker,
.dc-page .inputdate,
.dc-page input[id$="day"],
.dc-page input[id$="month"],
.dc-page input[id$="year"],
.dc-page select[id$="hour"],
.dc-page select[id$="min"] {
    width: 75px !important;
    max-width: 75px !important;
    min-width: 75px !important;
    display: inline-block !important;
    box-sizing: border-box !important;
}
.dc-page select.maxwidth50 { max-width: 75px !important; }
.dc-page input.maxwidthdate { width: 110px !important; max-width: 110px !important; }
.dc-page .blockdatepicker { display: inline-flex !important; align-items: center !important; gap: 4px !important; flex-wrap: wrap !important; }
.dc-page .blockdatepicker a,
.dc-page .blockdatepicker img { flex-shrink: 0 !important; }
.dc-page input[type="text"]:focus,
.dc-page input[type="email"]:focus,
.dc-page input[type="number"]:focus,
.dc-page select:focus,
.dc-page textarea:focus {
    border-color: #3c4758 !important;
    box-shadow: 0 0 0 3px rgba(60,71,88,0.1) !important;
    background: #fff !important;
}
.dc-page textarea { resize: vertical !important; }

/* ── Bottom action bar ── */
.dc-action-bar {
    display: flex; align-items: center; justify-content: flex-end;
    gap: 8px; padding: 18px 0 4px;
    flex-wrap: wrap;
}
.dc-action-bar-left { margin-right: auto; }

/* ── Ref tag ── */
.dc-ref-tag {
    font-family: 'DM Mono', monospace; font-size: 13px;
    background: rgba(60,71,88,0.08); color: #3c4758;
    padding: 4px 10px; border-radius: 6px; font-weight: 500;
}

/* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
   RESPONSIVE STYLES
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */

/* Tablet: 960px and below */
@media (max-width: 960px) {
    .dc-page {
        padding: 0 12px 40px;
    }
    .dc-header {
        padding: 18px 0 16px;
        margin-bottom: 20px;
    }
    .dc-header-title { font-size: 18px; }
    .dc-field-label { flex: 0 0 130px; }
}

/* Tablet portrait / large phone: 780px and below */
@media (max-width: 780px) {
    /* dc-grid already stacks to 1 column here */
    .dc-page { padding: 0 10px 32px; }

    .dc-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 12px;
        padding: 16px 0 14px;
        margin-bottom: 16px;
    }
    .dc-header-actions {
        width: 100%;
        justify-content: flex-start;
    }

    .dc-field {
        flex-direction: column;
        gap: 4px;
        padding: 10px 16px;
    }
    .dc-field-label {
        flex: none;
        width: 100%;
        padding-top: 0;
    }
    .dc-field-value { width: 100%; }

    .dc-action-bar {
        flex-wrap: wrap;
        gap: 8px;
        padding: 14px 0 4px;
    }
    .dc-action-bar-left {
        width: 100%;
        margin-right: 0;
    }
    .dc-action-bar .dc-btn {
        flex: 1 1 auto;
        justify-content: center;
        min-width: 120px;
    }
}

/* ── OSM Autocomplete ── */
.osm-autocomplete-wrap { position: relative; width: 100%; }
.osm-suggestions {
    /* base styles — JS overrides position/z-index after appending to body */
    position: fixed; z-index: 2147483647; left: 0; top: 0;
    background: #fff; border: 1.5px solid #e2e5f0; border-radius: 10px;
    box-shadow: 0 8px 24px rgba(0,0,0,0.10); list-style: none;
    margin: 0; padding: 4px 0; max-height: 220px; overflow-y: auto; display: none;
}
.osm-suggestions li {
    padding: 9px 14px; font-size: 12.5px; color: #2d3748; cursor: pointer;
    display: flex; align-items: flex-start; gap: 8px; line-height: 1.4;
}
.osm-suggestions li:hover, .osm-suggestions li.active { background: #f0f4ff; color: #3c4758; }
.osm-suggestions li i { color: #16a34a; margin-top: 2px; flex-shrink: 0; }
.osm-suggestions li.osm-loading { color: #8b92a9; cursor: default; }
.osm-suggestions li.osm-loading:hover { background: none; }
.osm-stop-row { display: flex; align-items: center; gap: 8px; margin-bottom: 8px; }
.osm-stop-row .osm-autocomplete-wrap { flex: 1; }
.osm-stop-num-badge {
    width: 22px; height: 22px; border-radius: 50%; background: #3c4758; color: #fff;
    font-size: 10px; font-weight: 700; display: flex; align-items: center;
    justify-content: center; flex-shrink: 0;
}
.osm-remove-stop { background: none; border: none; cursor: pointer; color: #ef4444; padding: 4px; font-size: 14px; line-height: 1; border-radius: 4px; transition: background 0.15s; }
.osm-remove-stop:hover { background: #fef2f2; }
.osm-add-stop { margin-top: 6px; font-size: 12px !important; padding: 5px 12px !important; }
.osm-stops-view { display: flex; flex-direction: column; gap: 6px; }
.osm-stop-badge { display: inline-flex; align-items: center; gap: 8px; font-size: 12.5px; color: #2d3748; }
.osm-stop-badge .osm-stop-num { width: 20px; height: 20px; border-radius: 50%; background: #3c4758; color: #fff; font-size: 10px; font-weight: 700; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
/* Hide Leaflet Routing Machine's default panel — we only want the route line */
.leaflet-routing-container { display: none !important; }
/* The map container creates its own stacking context at z-index 0.
   This confines Leaflet's internal high z-indexes (200, 400…) inside it,
   so they can never bleed above dropdowns/datepickers in the rest of the page. */
#osm-map-wrap { width: 100%; border-radius: 10px; overflow: hidden; border: 1.5px solid #e2e5f0; position: relative; min-height: 240px; z-index: 0; }
/* Do NOT set z-index on #osm-map itself — Leaflet manages its own internal layers */
#osm-map { width: 100%; height: 240px; }
/* Address suggestion dropdowns: no ancestor stacking context traps them,
   so z-index: 9999 freely floats above page content including the map. */
/* jQuery UI datepicker is appended to <body> — force it above everything */
.ui-datepicker,
div.ui-datepicker { z-index: 99999 !important; }
/* OSM autocomplete suggestions also need to float above the map */
.osm-suggestions { z-index: 99999 !important; }
#osm-map-empty { position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 10px; background: #f7f8fc; color: #b0b8cc; font-size: 13px; }
#osm-map-empty i { font-size: 28px; opacity: 0.4; }

/* Small phones: 480px and below */
@media (max-width: 480px) {
    .dc-page { padding: 0 6px 24px; }

    .dc-header-title { font-size: 16px; }
    .dc-header-sub { font-size: 11.5px; }
    .dc-header-icon { width: 38px; height: 38px; font-size: 16px; border-radius: 10px; }

    .dc-card { border-radius: 10px; }
    .dc-card-header { padding: 12px 14px; }

    .dc-field { padding: 9px 14px; }

    .dc-btn {
        font-size: 12.5px;
        padding: 7px 12px;
    }

    .dc-action-bar .dc-btn {
        flex: 1 1 100%;
    }

    .dc-header-actions .dc-btn {
        font-size: 12px;
        padding: 6px 10px;
    }

    .dc-grid { gap: 14px; margin-bottom: 14px; }

    /* Make select/input full width and readable on small screens */
    .dc-page select,
    .dc-page input[type="text"],
    .dc-page input[type="number"] {
        font-size: 14px !important; /* slightly larger for touch */
    }
}

/* ---- Expense sub-card grid ---- */
.dc-expense-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 12px;
    padding: 12px;
}
@media(max-width:780px){ .dc-expense-grid { grid-template-columns: 1fr; } }
.dc-expense-subtotal {
    margin-left: auto;
    font-family: 'DM Mono', monospace; font-size: 12px; font-weight: 500;
    background: #f0f2fa; color: #4a5568;
    padding: 3px 9px; border-radius: 5px;
}
</style>
<?php

// Confirmation to delete
if ($action == 'delete') {
    $formconfirm = $form->formconfirm(
        $_SERVER["PHP_SELF"] . '?id=' . $id,
        $langs->trans('DeleteBooking'),
        $langs->trans('ConfirmDeleteBooking'),
        'confirm_delete',
        '',
        0,
        1
    );
    print $formconfirm;
}

// Show error messages
if (!empty($errors)) {
    foreach ($errors as $error_msg) {
        setEventMessage($error_msg, 'errors');
    }
}

$isEdit   = ($action == 'edit');
$isCreate = ($action == 'create');
$isView   = (!$isEdit && !$isCreate);

$pageTitle = $isCreate ? $langs->trans('NewBooking') : ($isEdit ? $langs->trans('EditBooking') : $langs->trans('Booking'));
$pageSub   = $isCreate ? $langs->trans('FillInBookingDetails') : (isset($object->ref) ? $object->ref : '');

// Determine status class for badge
$statusClass = '';
if (!empty($object->status)) {
    $statusClass = strtolower(str_replace(' ', '_', $object->status));
}

// Form start
if ($isCreate || $isEdit) {
    print '<form method="POST" action="'.$_SERVER['PHP_SELF'].($id > 0 ? '?id='.$id : '').'">';
    print '<input type="hidden" name="action" value="'.($isCreate ? 'add' : 'update').'">';
    print '<input type="hidden" name="token" value="'.newToken().'">';
    if ($id > 0) print '<input type="hidden" name="id" value="'.$id.'">';
}

print '<div class="dc-page">';

/* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
   PAGE HEADER
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */
print '<div class="dc-header">';
print '  <div class="dc-header-left">';
print '    <div class="dc-header-icon"><i class="fa fa-calendar-check"></i></div>';
print '    <div>';
print '      <div class="dc-header-title">'.dol_escape_htmltag($pageTitle).'</div>';
if ($pageSub) print '      <div class="dc-header-sub">'.dol_escape_htmltag($pageSub).'</div>';
print '    </div>';
print '  </div>';
print '  <div class="dc-header-actions">';
if ($isView && $id > 0) {
    if (!empty($object->status)) {
        $statusLabel = $langs->trans(ucfirst(str_replace('_', '', ucwords($object->status, '_'))));
        print '<span class="dc-badge '.$statusClass.'">'.dol_escape_htmltag($statusLabel).'</span>';
    }
    print '<a class="dc-btn dc-btn-ghost" href="'.dol_buildpath('/flotte/booking_list.php', 1).'"><i class="fa fa-arrow-left"></i> '.$langs->trans('BackToList').'</a>';
    if (!empty($user->rights->flotte->write)) {
        print '<a class="dc-btn dc-btn-ghost" href="'.$_SERVER['PHP_SELF'].'?id='.$id.'&action=edit"><i class="fa fa-pen"></i> '.$langs->trans('Modify').'</a>';
    }
    print '<a class="dc-btn dc-btn-danger" href="'.$_SERVER['PHP_SELF'].'?id='.$id.'&action=delete"><i class="fa fa-trash"></i> '.$langs->trans('Delete').'</a>';
}
print '  </div>';
print '</div>';

/* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
   ROW 1 — Booking Info + Trip Details
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */
print '<div class="dc-grid">';

/* ── Card: Booking Information ── */
print '<div class="dc-card">';
print '  <div class="dc-card-header">';
print '    <div class="dc-card-header-icon blue"><i class="fa fa-calendar-check"></i></div>';
print '    <span class="dc-card-title">'.$langs->trans('BookingInformation').'</span>';
print '  </div>';
print '  <div class="dc-card-body">';

// Reference
print '  <div class="dc-field">';
print '    <div class="dc-field-label">'.$langs->trans('Reference').'</div>';
print '    <div class="dc-field-value">';
if ($isCreate) {
    print '<em style="color:#9aa0b4;font-size:12.5px;">'.$langs->trans('AutoGenerated').'</em>';
    print '<input type="hidden" name="ref" value="'.(isset($object->ref) ? dol_escape_htmltag($object->ref) : '').'">';
} elseif ($isEdit) {
    print '<input type="text" name="ref" value="'.(isset($object->ref) ? dol_escape_htmltag($object->ref) : '').'" readonly style="background:#f5f6fa!important;color:#9aa0b4!important;">';
} else {
    print '<span class="dc-ref-tag">'.dol_escape_htmltag($object->ref).'</span>';
}
print '    </div></div>';

// Vehicle
print '  <div class="dc-field">';
print '    <div class="dc-field-label required">'.$langs->trans('Vehicle').'</div>';
print '    <div class="dc-field-value">';
if ($isCreate || $isEdit) {
    $vehicles = array();
    $sql = "SELECT rowid, ref, maker, model FROM ".MAIN_DB_PREFIX."flotte_vehicle WHERE entity IN (".getEntity('flotte').")";
    $resql = $db->query($sql);
    if ($resql) {
        while ($obj = $db->fetch_object($resql)) {
            $vehicles[$obj->rowid] = dol_escape_htmltag($obj->ref . ' - ' . $obj->maker . ' ' . $obj->model);
        }
    }
    print $form->selectarray('fk_vehicle', $vehicles, (isset($object->fk_vehicle) ? $object->fk_vehicle : ''), 1);
} else {
    if (!empty($object->fk_vehicle)) {
        $sql = "SELECT ref, maker, model FROM ".MAIN_DB_PREFIX."flotte_vehicle WHERE rowid = ".((int) $object->fk_vehicle);
        $resql = $db->query($sql);
        if ($resql && $db->num_rows($resql) > 0) {
            $obj = $db->fetch_object($resql);
            print '<span class="dc-chip"><i class="fa fa-car" style="font-size:11px;opacity:0.6;"></i>'.dol_escape_htmltag($obj->ref.' - '.$obj->maker.' '.$obj->model).'</span>';
        } else {
            print '<span style="color:#c4c9d8;">'.$langs->trans('VehicleNotFound').'</span>';
        }
    } else {
        print '<span style="color:#c4c9d8;">'.$langs->trans('Not Assigned').'</span>';
    }
}
print '    </div></div>';

// Driver
print '  <div class="dc-field">';
print '    <div class="dc-field-label">'.$langs->trans('Driver').'</div>';
print '    <div class="dc-field-value">';
if ($isCreate || $isEdit) {
    $drivers = array();
    $sql = "SELECT rowid, firstname, lastname FROM ".MAIN_DB_PREFIX."flotte_driver WHERE entity IN (".getEntity('flotte').")";
    $resql = $db->query($sql);
    if ($resql) {
        while ($obj = $db->fetch_object($resql)) {
            $drivers[$obj->rowid] = dol_escape_htmltag($obj->firstname . ' ' . $obj->lastname);
        }
    }
    print $form->selectarray('fk_driver', $drivers, (isset($object->fk_driver) ? $object->fk_driver : ''), 1);
} else {
    if (!empty($object->fk_driver)) {
        $sql = "SELECT firstname, lastname FROM ".MAIN_DB_PREFIX."flotte_driver WHERE rowid = ".((int) $object->fk_driver);
        $resql = $db->query($sql);
        if ($resql && $db->num_rows($resql) > 0) {
            $obj = $db->fetch_object($resql);
            print '<span class="dc-chip"><i class="fa fa-user" style="font-size:11px;opacity:0.6;"></i>'.dol_escape_htmltag($obj->firstname.' '.$obj->lastname).'</span>';
        } else {
            print '<span style="color:#c4c9d8;">'.$langs->trans('DriverNotFound').'</span>';
        }
    } else {
        print '<span style="color:#c4c9d8;">'.$langs->trans('Not Assigned').'</span>';
    }
}
print '    </div></div>';

// Vendor + Buying Tax (inline)
print '  <div class="dc-field">';
print '    <div class="dc-field-label">'.$langs->trans('Vendor').'</div>';
print '    <div class="dc-field-value dc-field-value-inline">';
if ($isCreate || $isEdit) {
    $vendors = array();
    $sql = "SELECT rowid, name FROM ".MAIN_DB_PREFIX."flotte_vendor WHERE entity IN (".getEntity('flotte').")";
    $resql = $db->query($sql);
    if ($resql) {
        while ($obj = $db->fetch_object($resql)) {
            $vendors[$obj->rowid] = dol_escape_htmltag($obj->name);
        }
    }
    print $form->selectarray('fk_vendor', $vendors, (isset($object->fk_vendor) ? $object->fk_vendor : ''), 1);
    $btr = isset($object->buying_tax_rate) ? $object->buying_tax_rate : '';
    print '<div class="dc-inline-tax">';
    print '<label class="dc-inline-tax-label">'.$langs->trans('Tax').'</label>';
    print '<div class="dc-tax-input-wrap"><input type="number" name="buying_tax_rate" id="buying_tax_rate" class="dc-tax-input" value="'.dol_escape_htmltag($btr).'" min="0" max="100" step="any" placeholder="0"><span class="dc-tax-pct-symbol">%</span></div>';
    print '</div>';
} else {
    if (!empty($object->fk_vendor)) {
        $sql = "SELECT name FROM ".MAIN_DB_PREFIX."flotte_vendor WHERE rowid = ".((int) $object->fk_vendor);
        $resql = $db->query($sql);
        if ($resql && $db->num_rows($resql) > 0) {
            $obj = $db->fetch_object($resql);
            print '<span class="dc-chip"><i class="fa fa-store" style="font-size:11px;opacity:0.6;"></i>'.dol_escape_htmltag($obj->name).'</span>';
        } else {
            print '<span style="color:#c4c9d8;">'.$langs->trans('Vendor Not Found').'</span>';
        }
    } else {
        print '<span style="color:#c4c9d8;">'.$langs->trans('Not Assigned').'</span>';
    }
    if (isset($object->buying_tax_rate) && $object->buying_tax_rate !== '') {
        print '<span class="dc-chip dc-tax-badge" style="margin-left:8px;"><i class="fa fa-percent" style="font-size:10px;opacity:0.6;"></i>'.$object->buying_tax_rate.'%</span>';
    }
}
print '    </div></div>';

// Customer + Selling Tax (inline)
print '  <div class="dc-field">';
print '    <div class="dc-field-label required">'.$langs->trans('Customer').'</div>';
print '    <div class="dc-field-value dc-field-value-inline">';
if ($isCreate || $isEdit) {
    $customers = array();
    $sql = "SELECT rowid, firstname, lastname, company_name FROM ".MAIN_DB_PREFIX."flotte_customer WHERE entity IN (".getEntity('flotte').")";
    $resql = $db->query($sql);
    if ($resql) {
        while ($obj = $db->fetch_object($resql)) {
            $name = $obj->firstname . ' ' . $obj->lastname;
            if (!empty($obj->company_name)) $name .= ' (' . $obj->company_name . ')';
            $customers[$obj->rowid] = dol_escape_htmltag($name);
        }
    }
    print $form->selectarray('fk_customer', $customers, (isset($object->fk_customer) ? $object->fk_customer : ''), 1);
    $str = isset($object->selling_tax_rate) ? $object->selling_tax_rate : '';
    print '<div class="dc-inline-tax">';
    print '<label class="dc-inline-tax-label">'.$langs->trans('Tax').'</label>';
    print '<div class="dc-tax-input-wrap"><input type="number" name="selling_tax_rate" id="selling_tax_rate" class="dc-tax-input" value="'.dol_escape_htmltag($str).'" min="0" max="100" step="any" placeholder="0"><span class="dc-tax-pct-symbol">%</span></div>';
    print '</div>';
} else {
    if (!empty($object->fk_customer)) {
        $sql = "SELECT firstname, lastname, company_name FROM ".MAIN_DB_PREFIX."flotte_customer WHERE rowid = ".((int) $object->fk_customer);
        $resql = $db->query($sql);
        if ($resql && $db->num_rows($resql) > 0) {
            $obj = $db->fetch_object($resql);
            $name = $obj->firstname . ' ' . $obj->lastname;
            if (!empty($obj->company_name)) $name .= ' (' . $obj->company_name . ')';
            print '<span class="dc-chip"><i class="fa fa-user-circle" style="font-size:11px;opacity:0.6;"></i>'.dol_escape_htmltag($name).'</span>';
        } else {
            print '<span style="color:#c4c9d8;">'.$langs->trans('CustomerNotFound').'</span>';
        }
    } else {
        print '<span style="color:#c4c9d8;">'.$langs->trans('Not Assigned').'</span>';
    }
    if (isset($object->selling_tax_rate) && $object->selling_tax_rate !== '') {
        print '<span class="dc-chip dc-tax-badge" style="margin-left:8px;"><i class="fa fa-percent" style="font-size:10px;opacity:0.6;"></i>'.$object->selling_tax_rate.'%</span>';
    }
}
print '    </div></div>';


// ── Buying Section ──
print '  <div class="dc-field dc-pricing-header">';
print '    <div class="dc-field-label">'.$langs->trans('BuyingAmount').'</div>';
print '    <div class="dc-field-value">';
if ($isCreate || $isEdit) {
    $bq = isset($object->buying_qty)   ? dol_escape_htmltag($object->buying_qty)   : '';
    $bp = isset($object->buying_price) ? dol_escape_htmltag($object->buying_price) : '';
    $bu = isset($object->buying_unit) && $object->buying_unit !== '' ? dol_escape_htmltag($object->buying_unit) : 'All Inclusive';
    $ba = isset($object->buying_amount)     ? dol_escape_htmltag($object->buying_amount)     : '';
    $bt = isset($object->buying_amount_ttc) ? dol_escape_htmltag($object->buying_amount_ttc) : '';
    print '<div class="dc-pricing-grid">';
    print '  <div class="dc-pricing-row dc-pricing-inputs">';
    print '    <div class="dc-pricing-col"><label class="dc-pricing-label">'.$langs->trans('Qty').'</label>';
    print '    <input type="number" id="buying_qty" name="buying_qty" value="'.$bq.'" min="0" step="any" placeholder="0"></div>';
    print '    <div class="dc-pricing-col"><label class="dc-pricing-label">'.$langs->trans('Unit').'</label>';
    print '    <select id="buying_unit" name="buying_unit"><option value="">&mdash;</option>';
    foreach (array('All Inclusive','Ton','Kg','Km','Hour','Day','Week') as $u) {
        $sel = ($bu === $u) ? ' selected' : '';
        print '<option value="'.dol_escape_htmltag($u).'"'.$sel.'>'.dol_escape_htmltag($u).'</option>';
    }
    print '</select></div>';
    print '    <div class="dc-pricing-col"><label class="dc-pricing-label">'.$langs->trans('Unit Price').'</label>';
    print '    <input type="number" id="buying_price" name="buying_price" value="'.$bp.'" min="0" step="any" placeholder="0.00"></div>';
    print '  </div>';
    print '  <div class="dc-pricing-row dc-pricing-results">';
    print '    <div class="dc-pricing-result-item"><span class="dc-pricing-result-label">'.$langs->trans('Excl Tax').'</span>';
    print '    <input type="number" id="buying_amount" name="buying_amount" value="'.$ba.'" step="any" readonly placeholder="0.00" class="dc-calc-result"></div>';
    print '    <div class="dc-pricing-result-sep"><i class="fa fa-plus dc-tax-icon"></i><span class="dc-tax-pct" id="buying_tax_pct">0%</span></div>';
    print '    <div class="dc-pricing-result-item dc-incl"><span class="dc-pricing-result-label">'.$langs->trans('Incl Tax').'</span>';
    print '    <input type="number" id="buying_amount_ttc" name="buying_amount_ttc" value="'.$bt.'" step="any" readonly placeholder="0.00" class="dc-calc-result dc-incl-val"></div>';
    print '  </div>';
    print '</div>';
} else {
    print '<div class="dc-pricing-view">';
    $bexcl = !empty($object->buying_amount)     ? price($object->buying_amount)     : '—';
    $bincl = !empty($object->buying_amount_ttc) ? price($object->buying_amount_ttc) : '—';
    $bqv   = !empty($object->buying_qty)   ? $object->buying_qty   : '—';
    $bpv   = !empty($object->buying_price) ? price($object->buying_price) : '—';
    $buv   = !empty($object->buying_unit)  ? dol_escape_htmltag($object->buying_unit) : '';
    print '<span class="dc-pricing-meta">'.$bqv.($buv?' '.$buv:'').' &times; '.$bpv.'</span>';
    print '<span class="dc-pricing-excl dc-amount buying">'.$bexcl.'</span>';
    print '<span class="dc-pricing-arrow">&rarr;</span>';
    print '<span class="dc-pricing-incl dc-amount buying-ttc">'.$bincl.'</span>';
    print '</div>';
}
print '    </div></div>';

// ── Selling Section ──
print '  <div class="dc-field dc-pricing-header">';
print '    <div class="dc-field-label">'.$langs->trans('SellingAmount').'</div>';
print '    <div class="dc-field-value">';
if ($isCreate || $isEdit) {
    $sq = isset($object->selling_qty)   ? dol_escape_htmltag($object->selling_qty)   : '';
    $sp = isset($object->selling_price) ? dol_escape_htmltag($object->selling_price) : '';
    $su = isset($object->selling_unit) && $object->selling_unit !== '' ? dol_escape_htmltag($object->selling_unit) : 'All Inclusive';
    $sa = isset($object->selling_amount)     ? dol_escape_htmltag($object->selling_amount)     : '';
    $st = isset($object->selling_amount_ttc) ? dol_escape_htmltag($object->selling_amount_ttc) : '';
    print '<div class="dc-pricing-grid">';
    print '  <div class="dc-pricing-row dc-pricing-inputs">';
    print '    <div class="dc-pricing-col"><label class="dc-pricing-label">'.$langs->trans('Qty').'</label>';
    print '    <input type="number" id="selling_qty" name="selling_qty" value="'.$sq.'" min="0" step="any" placeholder="0"></div>';
    print '    <div class="dc-pricing-col"><label class="dc-pricing-label">'.$langs->trans('Unit').'</label>';
    print '    <select id="selling_unit" name="selling_unit"><option value="">&mdash;</option>';
    foreach (array('All Inclusive','Ton','Kg','Km','Hour','Day','Week') as $u) {
        $sel = ($su === $u) ? ' selected' : '';
        print '<option value="'.dol_escape_htmltag($u).'"'.$sel.'>'.dol_escape_htmltag($u).'</option>';
    }
    print '</select></div>';
    print '    <div class="dc-pricing-col"><label class="dc-pricing-label">'.$langs->trans('Unit Price').'</label>';
    print '    <input type="number" id="selling_price" name="selling_price" value="'.$sp.'" min="0" step="any" placeholder="0.00"></div>';
    print '  </div>';
    print '  <div class="dc-pricing-row dc-pricing-results">';
    print '    <div class="dc-pricing-result-item"><span class="dc-pricing-result-label">'.$langs->trans('Excl Tax').'</span>';
    print '    <input type="number" id="selling_amount" name="selling_amount" value="'.$sa.'" step="any" readonly placeholder="0.00" class="dc-calc-result"></div>';
    print '    <div class="dc-pricing-result-sep"><i class="fa fa-plus dc-tax-icon"></i><span class="dc-tax-pct" id="selling_tax_pct">0%</span></div>';
    print '    <div class="dc-pricing-result-item dc-incl"><span class="dc-pricing-result-label">'.$langs->trans('Incl Tax').'</span>';
    print '    <input type="number" id="selling_amount_ttc" name="selling_amount_ttc" value="'.$st.'" step="any" readonly placeholder="0.00" class="dc-calc-result dc-incl-val"></div>';
    print '  </div>';
    print '</div>';
} else {
    print '<div class="dc-pricing-view">';
    $sexcl = !empty($object->selling_amount)     ? price($object->selling_amount)     : '—';
    $sincl = !empty($object->selling_amount_ttc) ? price($object->selling_amount_ttc) : '—';
    $sqv   = !empty($object->selling_qty)   ? $object->selling_qty   : '—';
    $spv   = !empty($object->selling_price) ? price($object->selling_price) : '—';
    $suv   = !empty($object->selling_unit)  ? dol_escape_htmltag($object->selling_unit) : '';
    print '<span class="dc-pricing-meta">'.$sqv.($suv?' '.$suv:'').' &times; '.$spv.'</span>';
    print '<span class="dc-pricing-excl dc-amount selling">'.$sexcl.'</span>';
    print '<span class="dc-pricing-arrow">&rarr;</span>';
    print '<span class="dc-pricing-incl dc-amount selling-ttc">'.$sincl.'</span>';
    print '</div>';
}
print '    </div></div>';

// Booking Date

// Booking Date
print '  <div class="dc-field">';
print '    <div class="dc-field-label required">'.$langs->trans('BookingDate').'</div>';
print '    <div class="dc-field-value">';
if ($isCreate || $isEdit) {
    $selected_date = (!empty($object->booking_date)) ? $object->booking_date : '';
    print $form->selectDate($selected_date, 'booking_date', '', '', 1, '', 1, 1);
} else {
    print dol_print_date($object->booking_date, 'day');
}
print '    </div></div>';

// Status
print '  <div class="dc-field">';
print '    <div class="dc-field-label">'.$langs->trans('Status').'</div>';
print '    <div class="dc-field-value">';
if ($isCreate || $isEdit) {
    $status_options = array(
        'pending'     => $langs->trans('Pending'),
        'confirmed'   => $langs->trans('Confirmed'),
        'in_progress' => $langs->trans('InProgress'),
        'completed'   => $langs->trans('Completed'),
        'cancelled'   => $langs->trans('Cancelled'),
    );
    print $form->selectarray('status', $status_options, (isset($object->status) ? $object->status : 'pending'), 0);
} else {
    if (!empty($object->status)) {
        $stClass = strtolower(str_replace(' ', '_', $object->status));
        $stLabel = $langs->trans(ucfirst(str_replace('_', '', ucwords($object->status, '_'))));
        print '<span class="dc-badge '.$stClass.'">'.dol_escape_htmltag($stLabel).'</span>';
    }
}
print '    </div></div>';

print '  </div>';// card-body
print '</div>';  // dc-card

/* ── Card: Trip Details ── */
print '<div class="dc-card" id="trip-details-card">';
print '  <div class="dc-card-header">';
print '    <div class="dc-card-header-icon green"><i class="fa fa-route"></i></div>';
print '    <span class="dc-card-title">'.$langs->trans('TripDetails').'</span>';
print '  </div>';
print '  <div class="dc-card-body">';

// Departure Address
print '  <div class="dc-field">';
print '    <div class="dc-field-label">'.$langs->trans('Departure Address').'</div>';
print '    <div class="dc-field-value">';
if ($isCreate || $isEdit) {
    print '<div class="osm-autocomplete-wrap">';
    print '<input type="text" id="departure_address" name="departure_address" value="'.dol_escape_htmltag(isset($object->departure_address) ? $object->departure_address : '').'" placeholder="'.$langs->trans('TypeToSearch').'" autocomplete="off">';
    print '<ul class="osm-suggestions" id="dep-suggestions"></ul>';
    print '<input type="hidden" id="dep_lat" name="dep_lat" value="'.dol_escape_htmltag(isset($object->dep_lat) ? $object->dep_lat : '').'">';
    print '<input type="hidden" id="dep_lon" name="dep_lon" value="'.dol_escape_htmltag(isset($object->dep_lon) ? $object->dep_lon : '').'">';
    print '</div>';
} else {
    print (!empty($object->departure_address) ? dol_escape_htmltag($object->departure_address) : '&mdash;');
}
print '    </div></div>';

// Stops
print '  <div class="dc-field" id="stops-field">';
print '    <div class="dc-field-label">'.$langs->trans('Stops').'</div>';
print '    <div class="dc-field-value">';
if ($isCreate || $isEdit) {
    $stopsJson = isset($object->stops) ? dol_escape_htmltag($object->stops) : '[]';
    print '<div id="stops-container"></div>';
    print '<input type="hidden" id="stops" name="stops" value="'.$stopsJson.'">';
    print '<button type="button" class="dc-btn dc-btn-ghost osm-add-stop" onclick="addStop()"><i class="fa fa-plus"></i> '.$langs->trans('Add Stop').'</button>';
} else {
    if (!empty($object->stops) && $object->stops !== '[]') {
        $stops_arr = json_decode($object->stops, true);
        if (!empty($stops_arr)) {
            print '<div class="osm-stops-view">';
            foreach ($stops_arr as $i => $s) {
                print '<div class="osm-stop-badge"><span class="osm-stop-num">'.($i+1).'</span>'.dol_escape_htmltag($s['address']).'</div>';
            }
            print '</div>';
        } else { print '&mdash;'; }
    } else { print '&mdash;'; }
}
print '    </div></div>';

// Arriving Address
print '  <div class="dc-field">';
print '    <div class="dc-field-label">'.$langs->trans('Arriving Address').'</div>';
print '    <div class="dc-field-value">';
if ($isCreate || $isEdit) {
    print '<div class="osm-autocomplete-wrap">';
    print '<input type="text" id="arriving_address" name="arriving_address" value="'.dol_escape_htmltag(isset($object->arriving_address) ? $object->arriving_address : '').'" placeholder="'.$langs->trans('TypeToSearch').'" autocomplete="off">';
    print '<ul class="osm-suggestions" id="arr-suggestions"></ul>';
    print '<input type="hidden" id="arr_lat" name="arr_lat" value="'.dol_escape_htmltag(isset($object->arr_lat) ? $object->arr_lat : '').'">';
    print '<input type="hidden" id="arr_lon" name="arr_lon" value="'.dol_escape_htmltag(isset($object->arr_lon) ? $object->arr_lon : '').'">';
    print '</div>';
} else {
    print (!empty($object->arriving_address) ? dol_escape_htmltag($object->arriving_address) : '&mdash;');
}
print '    </div></div>';

// Distance
print '  <div class="dc-field">';
print '    <div class="dc-field-label">'.$langs->trans('Distance').'</div>';
print '    <div class="dc-field-value">';
if ($isCreate || $isEdit) {
    print '<div style="display:flex;gap:8px;align-items:center;">';
    print '<input type="number" id="distance" name="distance" value="'.dol_escape_htmltag(isset($object->distance) ? $object->distance : '').'" min="0" placeholder="'.$langs->trans('AutoCalculated').'">';
    print '<span id="distance-loader" style="display:none;font-size:11px;color:#8b92a9;"><i class="fa fa-spinner fa-spin"></i> '.$langs->trans('Calculating').'...</span>';
    print '</div>';
} else {
    print (!empty($object->distance) ? dol_escape_htmltag($object->distance).' '.$langs->trans('Km') : '&mdash;');
}
print '    </div></div>';

// ETA
print '  <div class="dc-field">';
print '    <div class="dc-field-label">'.$langs->trans('ETA').'</div>';
print '    <div class="dc-field-value">';
if ($isCreate || $isEdit) {
    print '<div style="display:flex;gap:8px;align-items:center;">';
    print '<input type="text" id="eta" name="eta" value="'.dol_escape_htmltag(isset($object->eta) ? $object->eta : '').'" placeholder="'.$langs->trans('AutoCalculated').'" readonly style="background:#f5f6fa!important;color:#5a6482!important;">';
    print '</div>';
} else {
    print (!empty($object->eta) ? '<span class="dc-chip"><i class="fa fa-clock" style="font-size:11px;opacity:0.6;"></i>'.dol_escape_htmltag($object->eta).'</span>' : '&mdash;');
}
print '    </div></div>';

// Pickup DateTime
$pickup_ts_val  = (!empty($object->pickup_datetime)  ? $db->jdate($object->pickup_datetime)  : '');
$dropoff_ts_val = (!empty($object->dropoff_datetime) ? $db->jdate($object->dropoff_datetime) : '');
print '  <div class="dc-field">';
print '    <div class="dc-field-label">'.($langs->trans('PickupDateTime')).'</div>';
print '    <div class="dc-field-value">';
if ($isCreate || $isEdit) {
    print $form->selectDate($pickup_ts_val, 'pickup_datetime', 1, 1, 0, '', 1, 1);
} else {
    print (!empty($object->pickup_datetime) ? '<span class="dc-chip"><i class="fa fa-calendar-check" style="font-size:11px;opacity:0.6;"></i>'.dol_print_date($db->jdate($object->pickup_datetime), 'dayhour').'</span>' : '&mdash;');
}
print '    </div></div>';

// Dropoff DateTime
print '  <div class="dc-field">';
print '    <div class="dc-field-label">'.($langs->trans('DropoffDateTime')).'</div>';
print '    <div class="dc-field-value">';
if ($isCreate || $isEdit) {
    print $form->selectDate($dropoff_ts_val, 'dropoff_datetime', 1, 1, 0, '', 1, 1);
    print '<span style="font-size:11px;color:#9aa0b4;margin-top:5px;display:block;"><i class="fa fa-info-circle" style="font-size:10px;"></i> '.$langs->trans('DropoffAutoCalculated').'</span>';
} else {
    print (!empty($object->dropoff_datetime) ? '<span class="dc-chip"><i class="fa fa-map-marker-alt" style="font-size:11px;opacity:0.6;"></i>'.dol_print_date($db->jdate($object->dropoff_datetime), 'dayhour').'</span>' : '&mdash;');
}
print '    </div></div>';

// Map preview
if ($isCreate || $isEdit) {
    print '  <div class="dc-field dc-field-maprow" style="flex-direction:column;gap:10px;">';
    print '    <div class="dc-field-label" style="flex:none;">'.$langs->trans('Route Preview').'</div>';
    print '    <div id="osm-map-wrap">';
    print '      <div id="osm-map"></div>';
    print '      <div id="osm-map-empty"><i class="fa fa-map-marked-alt"></i><span>'.$langs->trans('Enter Addresses To See Route').'</span></div>';
    print '    </div>';
    print '  </div>';
} else {
    if (!empty($object->departure_address) && !empty($object->arriving_address)) {
        print '  <div class="dc-field dc-field-maprow" style="flex-direction:column;gap:10px;">';
        print '    <div class="dc-field-label" style="flex:none;">'.$langs->trans('Route Preview').'</div>';
        print '    <div id="osm-map-wrap"><div id="osm-map"></div></div>';
        print '  </div>';
    }
}

print '  </div>';// card-body
print '</div>';  // dc-card

print '</div>';// dc-grid

/* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
   BOTTOM ACTION BAR
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */
// ---- Expenses Card ----
print '<div class="dc-card" style="margin-bottom:20px;">';
print '<div class="dc-card-header">';
print '<div class="dc-card-header-icon amber"><i class="fa fa-receipt"></i></div>';
print '<span class="dc-card-title">'.$langs->trans('Expenses').'</span>';
if ($isView && $id > 0) {
    print '<a href="'.dol_buildpath('/flotte/expenses_list.php',1).'?fk_booking='.$id.'" class="dc-btn dc-btn-ghost" style="margin-left:auto;font-size:12px;padding:5px 12px;"><i class="fa fa-list"></i> '.$langs->trans('ViewAllExpenses').'</a>';
}
print '</div>';

// ── Load vendors for fuel vendor selector ──
$vendors_list = array();
$_vsql = "SELECT rowid, name FROM ".MAIN_DB_PREFIX."flotte_vendor WHERE entity IN (".getEntity('flotte').")";
$_vres = $db->query($_vsql);
if ($_vres) { while ($_vobj = $db->fetch_object($_vres)) { $vendors_list[$_vobj->rowid] = dol_escape_htmltag($_vobj->name); } }

$exp_subtotals = array(
    'fuel'       => 0,
    'road'       => 0,
    'driver'     => 0,
    'commission' => 0,
);
if (!empty($object->expense_fuel))             $exp_subtotals['fuel']       += (float)$object->expense_fuel;
if (!empty($object->expense_road))             $exp_subtotals['road']       += (float)$object->expense_road;
if (!empty($object->expense_driver))           $exp_subtotals['driver']     += (float)$object->expense_driver;
if (!empty($object->expense_commission))       $exp_subtotals['commission'] += (float)$object->expense_commission;

print '<div class="dc-card-body">';
print '<div class="dc-expense-grid">';

/* ── FUEL ── */
$fuel_sub = $exp_subtotals['fuel'];
print '<div class="dc-card">';
print '  <div class="dc-card-header">';
print '    <div class="dc-card-header-icon amber"><i class="fa fa-gas-pump"></i></div>';
print '    <span class="dc-card-title">'.$langs->trans('FuelExpenses').'</span>';
print '    <span class="dc-expense-subtotal" id="fuel_subtotal">'.($fuel_sub > 0 ? price($fuel_sub) : '&#8212;').'</span>';
print '  </div>';
print '  <div class="dc-card-body">';

print '  <div class="dc-field"><div class="dc-field-label">'.$langs->trans('Vendor').'</div><div class="dc-field-value">';
if ($isCreate || $isEdit) {
    print $form->selectarray('expense_fuel_vendor', $vendors_list, (isset($object->expense_fuel_vendor) ? $object->expense_fuel_vendor : ''), 1);
} else {
    $fv_name = '&#8212;';
    if (!empty($object->expense_fuel_vendor)) { foreach ($vendors_list as $vid => $vname) { if ($vid == $object->expense_fuel_vendor) { $fv_name = '<span class="dc-chip"><i class="fa fa-store" style="font-size:11px;opacity:0.6;"></i>'.dol_escape_htmltag($vname).'</span>'; break; } } }
    print $fv_name;
}
print '</div></div>';

print '  <div class="dc-field"><div class="dc-field-label">'.$langs->trans('FuelType').'</div><div class="dc-field-value">';
if ($isCreate || $isEdit) {
    $fuel_types = array('gasoline'=>$langs->trans('Gasoline'),'diesel'=>$langs->trans('Diesel'),'lpg'=>'LPG','electric'=>$langs->trans('Electric'),'hybrid'=>$langs->trans('Hybrid'),'other'=>$langs->trans('Other'));
    print $form->selectarray('expense_fuel_type', $fuel_types, (isset($object->expense_fuel_type) ? $object->expense_fuel_type : ''), 1);
} else {
    print (!empty($object->expense_fuel_type) ? '<span class="dc-chip">'.dol_escape_htmltag(ucfirst($object->expense_fuel_type)).'</span>' : '&#8212;');
}
print '</div></div>';

print '  <div class="dc-field"><div class="dc-field-label">'.$langs->trans('Liters').'</div><div class="dc-field-value">';
if ($isCreate || $isEdit) {
    print '<input type="number" id="expense_fuel_qty" name="expense_fuel_qty" value="'.dol_escape_htmltag(isset($object->expense_fuel_qty)?$object->expense_fuel_qty:'').'" min="0" step="any" placeholder="0.00" oninput="calcFuelTotal()">';
} else {
    print (!empty($object->expense_fuel_qty) ? '<span class="dc-amount">'.dol_escape_htmltag($object->expense_fuel_qty).' L</span>' : '&#8212;');
}
print '</div></div>';

print '  <div class="dc-field"><div class="dc-field-label">'.$langs->trans('PricePerLiter').'</div><div class="dc-field-value">';
if ($isCreate || $isEdit) {
    print '<input type="number" id="expense_fuel_price" name="expense_fuel_price" value="'.dol_escape_htmltag(isset($object->expense_fuel_price)?$object->expense_fuel_price:'').'" min="0" step="any" placeholder="0.00" oninput="calcFuelTotal()">';
} else {
    print (!empty($object->expense_fuel_price) ? '<span class="dc-amount">'.price($object->expense_fuel_price).'</span>' : '&#8212;');
}
print '</div></div>';

print '  <div class="dc-field" style="background:#f7f8fc;"><div class="dc-field-label" style="color:#3c4758;font-weight:700;">'.$langs->trans('Total').'</div><div class="dc-field-value">';
if ($isCreate || $isEdit) {
    print '<input type="number" id="expense_fuel" name="expense_fuel" value="'.dol_escape_htmltag(isset($object->expense_fuel)?$object->expense_fuel:'').'" min="0" step="any" placeholder="0.00" oninput="calcExpenseTotal()" style="font-weight:600!important;background:#f7f8fc!important;">';
} else {
    print (!empty($object->expense_fuel) ? '<span class="dc-amount" style="font-weight:700;">'.price($object->expense_fuel).'</span>' : '&#8212;');
}
print '</div></div>';

print '  </div></div>';

/* ── DRIVER ── */
$driver_sub = $exp_subtotals['driver'];
print '<div class="dc-card">';
print '  <div class="dc-card-header">';
print '    <div class="dc-card-header-icon purple"><i class="fa fa-user-tie"></i></div>';
print '    <span class="dc-card-title">'.$langs->trans('DriverExpenses').'</span>';
print '    <span class="dc-expense-subtotal" id="driver_subtotal">'.($driver_sub > 0 ? price($driver_sub) : '&#8212;').'</span>';
print '  </div>';
print '  <div class="dc-card-body">';

foreach (array(
    'expense_driver_salary'    => $langs->trans('SalaryDayRate'),
    'expense_driver_overnight' => $langs->trans('OvernightFee'),
    'expense_driver_bonus'     => $langs->trans('Bonus'),
) as $dkey => $dlabel) {
    $dval = isset($object->$dkey) ? $object->$dkey : '';
    print '  <div class="dc-field"><div class="dc-field-label">'.dol_escape_htmltag($dlabel).'</div><div class="dc-field-value">';
    if ($isCreate || $isEdit) {
        print '<input type="number" id="'.dol_escape_htmltag($dkey).'" name="'.dol_escape_htmltag($dkey).'" value="'.dol_escape_htmltag($dval).'" min="0" step="any" placeholder="0.00" oninput="calcDriverTotal()">';
    } else {
        print (!empty($dval) ? '<span class="dc-amount">'.price($dval).'</span>' : '&#8212;');
    }
    print '</div></div>';
}

print '  <div class="dc-field" style="background:#f7f8fc;"><div class="dc-field-label" style="color:#3c4758;font-weight:700;">'.$langs->trans('Total').'</div><div class="dc-field-value">';
if ($isCreate || $isEdit) {
    print '<input type="number" id="expense_driver" name="expense_driver" value="'.dol_escape_htmltag(isset($object->expense_driver)?$object->expense_driver:'').'" min="0" step="any" placeholder="0.00" readonly style="font-weight:600!important;background:#f7f8fc!important;">';
} else {
    print (!empty($object->expense_driver) ? '<span class="dc-amount" style="font-weight:700;">'.price($object->expense_driver).'</span>' : '&#8212;');
}
print '</div></div>';

print '  </div></div>';

/* ── ROAD ── */
$road_sub = $exp_subtotals['road'];
print '<div class="dc-card">';
print '  <div class="dc-card-header">';
print '    <div class="dc-card-header-icon blue"><i class="fa fa-road"></i></div>';
print '    <span class="dc-card-title">'.$langs->trans('RoadExpenses').'</span>';
print '    <span class="dc-expense-subtotal" id="road_subtotal">'.($road_sub > 0 ? price($road_sub) : '&#8212;').'</span>';
print '  </div>';
print '  <div class="dc-card-body">';

foreach (array(
    'expense_road_toll'    => $langs->trans('TollFees'),
    'expense_road_parking' => $langs->trans('ParkingFees'),
    'expense_road_other'   => $langs->trans('OtherFees'),
) as $rkey => $rlabel) {
    $rval = isset($object->$rkey) ? $object->$rkey : '';
    print '  <div class="dc-field"><div class="dc-field-label">'.dol_escape_htmltag($rlabel).'</div><div class="dc-field-value">';
    if ($isCreate || $isEdit) {
        print '<input type="number" id="'.dol_escape_htmltag($rkey).'" name="'.dol_escape_htmltag($rkey).'" value="'.dol_escape_htmltag($rval).'" min="0" step="any" placeholder="0.00" oninput="calcRoadTotal()">';
    } else {
        print (!empty($rval) ? '<span class="dc-amount">'.price($rval).'</span>' : '&#8212;');
    }
    print '</div></div>';
}

print '  <div class="dc-field" style="background:#f7f8fc;"><div class="dc-field-label" style="color:#3c4758;font-weight:700;">'.$langs->trans('Total').'</div><div class="dc-field-value">';
if ($isCreate || $isEdit) {
    print '<input type="number" id="expense_road" name="expense_road" value="'.dol_escape_htmltag(isset($object->expense_road)?$object->expense_road:'').'" min="0" step="any" placeholder="0.00" readonly style="font-weight:600!important;background:#f7f8fc!important;">';
} else {
    print (!empty($object->expense_road) ? '<span class="dc-amount" style="font-weight:700;">'.price($object->expense_road).'</span>' : '&#8212;');
}
print '</div></div>';

print '  </div></div>';

/* ── COMMISSION ── */
$comm_sub = $exp_subtotals['commission'];
print '<div class="dc-card">';
print '  <div class="dc-card-header">';
print '    <div class="dc-card-header-icon green"><i class="fa fa-coins"></i></div>';
print '    <span class="dc-card-title">'.$langs->trans('CommissionExpenses').'</span>';
print '    <span class="dc-expense-subtotal" id="commission_subtotal">'.($comm_sub > 0 ? price($comm_sub) : '&#8212;').'</span>';
print '  </div>';
print '  <div class="dc-card-body">';

$cval_agent = isset($object->expense_commission_agent) ? $object->expense_commission_agent : '';
print '  <div class="dc-field"><div class="dc-field-label">'.$langs->trans('AgentCommission').'</div><div class="dc-field-value">';
if ($isCreate || $isEdit) {
    print '<input type="number" id="expense_commission_agent" name="expense_commission_agent" value="'.dol_escape_htmltag($cval_agent).'" min="0" step="any" placeholder="0.00" oninput="calcCommissionTotal()">';
} else {
    print (!empty($cval_agent) ? '<span class="dc-amount">'.price($cval_agent).'</span>' : '&#8212;');
}
print '</div></div>';

$cval_tax = isset($object->expense_commission_tax) ? $object->expense_commission_tax : '';
print '  <div class="dc-field"><div class="dc-field-label">'.$langs->trans('TaxRate').'</div><div class="dc-field-value">';
if ($isCreate || $isEdit) {
    print '<div style="display:flex;align-items:center;gap:8px;">';
    print '<input type="number" id="expense_commission_tax" name="expense_commission_tax" value="'.dol_escape_htmltag($cval_tax).'" min="0" max="100" step="any" placeholder="0" oninput="calcCommissionTotal()" style="max-width:90px!important;">';
    print '<span style="font-size:13px;font-weight:600;color:#8b92a9;">%</span>';
    print '<span style="font-size:12px;color:#8b92a9;">&#8594; <span id="commission_tax_amount" style="color:#2d3748;font-weight:600;">0.00</span></span>';
    print '</div>';
} else {
    if (!empty($cval_tax)) {
        $tax_amount = round((float)$cval_agent * (float)$cval_tax / 100, 2);
        print '<span class="dc-chip">'.dol_escape_htmltag($cval_tax).'%</span>&nbsp;<span class="dc-amount">= '.price($tax_amount).'</span>';
    } else { print '&#8212;'; }
}
print '</div></div>';

$cval_other = isset($object->expense_commission_other) ? $object->expense_commission_other : '';
print '  <div class="dc-field"><div class="dc-field-label">'.$langs->trans('OtherFees').'</div><div class="dc-field-value">';
if ($isCreate || $isEdit) {
    print '<input type="number" id="expense_commission_other" name="expense_commission_other" value="'.dol_escape_htmltag($cval_other).'" min="0" step="any" placeholder="0.00" oninput="calcCommissionTotal()">';
} else {
    print (!empty($cval_other) ? '<span class="dc-amount">'.price($cval_other).'</span>' : '&#8212;');
}
print '</div></div>';

print '  <div class="dc-field" style="background:#f7f8fc;"><div class="dc-field-label" style="color:#3c4758;font-weight:700;">'.$langs->trans('Total').'</div><div class="dc-field-value">';
if ($isCreate || $isEdit) {
    print '<input type="number" id="expense_commission" name="expense_commission" value="'.dol_escape_htmltag(isset($object->expense_commission)?$object->expense_commission:'').'" min="0" step="any" placeholder="0.00" readonly style="font-weight:600!important;background:#f7f8fc!important;">';
} else {
    print (!empty($object->expense_commission) ? '<span class="dc-amount" style="font-weight:700;">'.price($object->expense_commission).'</span>' : '&#8212;');
}
print '</div></div>';

print '  </div></div>';

print '</div>'; // dc-expense-grid
print '</div>'; // dc-card-body

// Grand total as a standard dc-field
$exp_total = $exp_subtotals['fuel'] + $exp_subtotals['road'] + $exp_subtotals['driver'] + $exp_subtotals['commission'];
print '<div class="dc-field" style="background:#f7f8fc;border-top:1px solid #e8eaf0;">';
print '<div class="dc-field-label" style="color:#3c4758;font-weight:700;"><i class="fa fa-calculator" style="font-size:11px;margin-right:5px;opacity:0.6;"></i>'.$langs->trans('TotalExpenses').'</div>';
print '<div class="dc-field-value">';
if ($isCreate || $isEdit) {
    print '<span id="expense_total" style="font-family:\'DM Mono\',monospace;font-size:16px;font-weight:700;color:#1a1f2e;">0.00</span>';
} else {
    print '<span style="font-family:\'DM Mono\',monospace;font-size:16px;font-weight:700;color:#1a1f2e;">'.($exp_total > 0 ? price($exp_total) : '&mdash;').'</span>';
}
print '</div></div>';

print '</div>'; // dc-card
// ---- End Expenses Card ----


if ($isCreate || $isEdit) {
    print '<div class="dc-action-bar">';
    print '<a class="dc-btn dc-btn-ghost dc-action-bar-left" href="'.dol_buildpath('/flotte/booking_list.php', 1).'"><i class="fa fa-arrow-left"></i> '.$langs->trans('BackToList').'</a>';
    print '<a class="dc-btn dc-btn-ghost" href="'.($id > 0 ? $_SERVER['PHP_SELF'].'?id='.$id : dol_buildpath('/flotte/booking_list.php', 1)).'"><i class="fa fa-times"></i> '.$langs->trans('Cancel').'</a>';
    print '<button type="submit" class="dc-btn dc-btn-primary"><i class="fa fa-check"></i> '.($isCreate ? $langs->trans('Create') : $langs->trans('Save')).'</button>';
    print '</div>';
    print '</form>';
} elseif ($id > 0) {
    print '<div class="dc-action-bar">';
    print '<a class="dc-btn dc-btn-ghost dc-action-bar-left" href="'.dol_buildpath('/flotte/booking_list.php', 1).'"><i class="fa fa-arrow-left"></i> '.$langs->trans('BackToList').'</a>';
    if (!empty($user->rights->flotte->write)) {
        print '<a class="dc-btn dc-btn-ghost" href="'.$_SERVER['PHP_SELF'].'?id='.$id.'&action=edit"><i class="fa fa-pen"></i> '.$langs->trans('Modify').'</a>';
    }
    print '<a class="dc-btn dc-btn-danger" href="'.$_SERVER['PHP_SELF'].'?id='.$id.'&action=delete"><i class="fa fa-trash"></i> '.$langs->trans('Delete').'</a>';
    print '</div>';
}

print '</div>';// dc-page

// Pricing calculation script
print '<script>
(function(){
function calcPricing() {
    var buyTax  = parseFloat(document.getElementById("buying_tax_rate")  ? document.getElementById("buying_tax_rate").value  : 0) || 0;
    var sellTax = parseFloat(document.getElementById("selling_tax_rate") ? document.getElementById("selling_tax_rate").value : 0) || 0;

    // Buying
    var bQty   = parseFloat(document.getElementById("buying_qty")   ? document.getElementById("buying_qty").value   : 0) || 0;
    var bPrice = parseFloat(document.getElementById("buying_price") ? document.getElementById("buying_price").value : 0) || 0;
    var bExcl  = Math.round(bQty * bPrice * 100) / 100;
    var bTTC   = Math.round(bExcl * (1 + buyTax / 100) * 100) / 100;
    var bAmtEl = document.getElementById("buying_amount");
    var bTTCEl = document.getElementById("buying_amount_ttc");
    var bPctEl = document.getElementById("buying_tax_pct");
    if (bAmtEl) bAmtEl.value = bExcl > 0 ? bExcl.toFixed(2) : "";
    if (bTTCEl) bTTCEl.value = bTTC  > 0 ? bTTC.toFixed(2)  : "";
    if (bPctEl) bPctEl.textContent = buyTax + "%";

    // Selling
    var sQty   = parseFloat(document.getElementById("selling_qty")   ? document.getElementById("selling_qty").value   : 0) || 0;
    var sPrice = parseFloat(document.getElementById("selling_price") ? document.getElementById("selling_price").value : 0) || 0;
    var sExcl  = Math.round(sQty * sPrice * 100) / 100;
    var sTTC   = Math.round(sExcl * (1 + sellTax / 100) * 100) / 100;
    var sAmtEl = document.getElementById("selling_amount");
    var sTTCEl = document.getElementById("selling_amount_ttc");
    var sPctEl = document.getElementById("selling_tax_pct");
    if (sAmtEl) sAmtEl.value = sExcl > 0 ? sExcl.toFixed(2) : "";
    if (sTTCEl) sTTCEl.value = sTTC  > 0 ? sTTC.toFixed(2)  : "";
    if (sPctEl) sPctEl.textContent = sellTax + "%";
}
document.addEventListener("DOMContentLoaded", function(){
    ["buying_qty","buying_price","selling_qty","selling_price"].forEach(function(id){
        var el = document.getElementById(id); if(el) el.addEventListener("input", calcPricing);
    });
    ["buying_tax_rate","selling_tax_rate"].forEach(function(id){
        var el = document.getElementById(id); if(el) el.addEventListener("input", calcPricing);
    });
    calcPricing();
});
})();
</script>';

// OSM/Leaflet scripts
print '<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>';
print '<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>';

$existingDep    = isset($object->departure_address) ? dol_escape_js($object->departure_address) : '';
$existingArr    = isset($object->arriving_address)  ? dol_escape_js($object->arriving_address)  : '';
// Use json_encode for stops to avoid double-escaping issues
$stopsRawData   = isset($object->stops) && $object->stops ? $object->stops : '[]';
$stopsDecoded   = json_decode($stopsRawData, true);
$existingStopsJS = json_encode(is_array($stopsDecoded) ? $stopsDecoded : array());
$existingDepLat = isset($object->dep_lat) && $object->dep_lat !== '' ? (float)$object->dep_lat : 0;
$existingDepLon = isset($object->dep_lon) && $object->dep_lon !== '' ? (float)$object->dep_lon : 0;
$existingArrLat = isset($object->arr_lat) && $object->arr_lat !== '' ? (float)$object->arr_lat : 0;
$existingArrLon = isset($object->arr_lon) && $object->arr_lon !== '' ? (float)$object->arr_lon : 0;
$isEditMode     = ($isCreate || $isEdit) ? 'true' : 'false';

print '<script>
(function(){
"use strict";
var EDIT_MODE = '.$isEditMode.';
var map = null, routeLayer = null, outlineLayer = null, markers = [], depCoords = null, arrCoords = null, stopCoords = [], stopCount = 0;

// Stored coords from DB (0 means not saved yet)
var storedDep = { lat: '.$existingDepLat.', lon: '.$existingDepLon.' };
var storedArr = { lat: '.$existingArrLat.', lon: '.$existingArrLon.' };
var storedStops = '.$existingStopsJS.';

document.addEventListener("DOMContentLoaded", function() {
    if (!EDIT_MODE) {
        var dA = "'.addslashes($existingDep).'", aA = "'.addslashes($existingArr).'";
        if (dA && aA) {
            initMap();
            // Use stored coords if available, otherwise geocode
            var depPromise = (storedDep.lat && storedDep.lon)
                ? Promise.resolve(storedDep)
                : geocode(dA);
            var arrPromise = (storedArr.lat && storedArr.lon)
                ? Promise.resolve(storedArr)
                : geocode(aA);
            Promise.all([depPromise, arrPromise]).then(function(r) {
                if (!r[0] || !r[1]) return;
                depCoords = r[0]; arrCoords = r[1];
                var sa = storedStops;
                Promise.all(sa.map(function(s){ return (s.lat&&s.lon) ? Promise.resolve({lat:parseFloat(s.lat),lon:parseFloat(s.lon)}) : geocode(s.address); })).then(function(sc){
                    stopCoords = sc.filter(Boolean); drawRoute();
                });
            });
        }
        return;
    }
    setupAutocomplete("departure_address","dep-suggestions",function(c){ depCoords=c; document.getElementById("dep_lat").value=c.lat; document.getElementById("dep_lon").value=c.lon; recalcRoute(); });
    setupAutocomplete("arriving_address","arr-suggestions",function(c){ arrCoords=c; document.getElementById("arr_lat").value=c.lat; document.getElementById("arr_lon").value=c.lon; recalcRoute(); });
    storedStops.forEach(function(s){ addStop(s.address, s.lat, s.lon); });
    initMap();
    var dV = document.getElementById("departure_address"), aV = document.getElementById("arriving_address");
    if (dV && aV && dV.value && aV.value) {
        // Use stored coords if available, otherwise geocode
        var depP = (storedDep.lat && storedDep.lon) ? Promise.resolve(storedDep) : geocode(dV.value);
        var arrP = (storedArr.lat && storedArr.lon) ? Promise.resolve(storedArr) : geocode(aV.value);
        Promise.all([depP, arrP]).then(function(r){
            if (r[0]) { depCoords=r[0]; document.getElementById("dep_lat").value=r[0].lat; document.getElementById("dep_lon").value=r[0].lon; }
            if (r[1]) { arrCoords=r[1]; document.getElementById("arr_lat").value=r[1].lat; document.getElementById("arr_lon").value=r[1].lon; }
            recalcRoute();
        });
    }
});

function geocode(q) {
    return fetch("https://nominatim.openstreetmap.org/search?format=json&q="+encodeURIComponent(q)+"&limit=1&accept-language=en")
    .then(function(r){return r.json();}).then(function(d){return d.length?{lat:parseFloat(d[0].lat),lon:parseFloat(d[0].lon)}:null;}).catch(function(){return null;});
}

var ntTimers = {};
function setupAutocomplete(inId, sugId, onSel) {
    var inp = document.getElementById(inId), lst = document.getElementById(sugId);
    if (!inp||!lst) return;
    // Move suggestion list to <body> so it is never clipped by any ancestor stacking context
    document.body.appendChild(lst);
    lst.style.position = "fixed";
    lst.style.zIndex   = "2147483647";
    lst.style.display  = "none";
    function positionList() {
        var r = inp.getBoundingClientRect();
        lst.style.top   = (r.bottom + 2) + "px";
        lst.style.left  = r.left + "px";
        lst.style.width = r.width + "px";
    }
    inp.addEventListener("input", function(){
        var q = inp.value.trim(); lst.innerHTML=""; lst.style.display="none";
        if (q.length<3) return;
        clearTimeout(ntTimers[inId]);
        ntTimers[inId] = setTimeout(function(){
            positionList();
            lst.innerHTML="<li class=\'osm-loading\'><i class=\'fa fa-spinner fa-spin\'></i> Searching...</li>"; lst.style.display="block";
            fetch("https://nominatim.openstreetmap.org/search?format=json&q="+encodeURIComponent(q)+"&limit=5&accept-language=en")
            .then(function(r){return r.json();}).then(function(data){
                lst.innerHTML="";
                if (!data.length){lst.innerHTML="<li style=\'color:#8b92a9;padding:9px 14px;font-size:12.5px;\'>No results</li>";positionList();lst.style.display="block";return;}
                data.forEach(function(p){
                    var li=document.createElement("li");
                    li.innerHTML="<i class=\'fa fa-map-marker-alt\'></i><span>"+escH(p.display_name)+"</span>";
                    li.addEventListener("mousedown",function(e){e.preventDefault();inp.value=p.display_name;lst.style.display="none";onSel({lat:parseFloat(p.lat),lon:parseFloat(p.lon)},p.display_name);});
                    lst.appendChild(li);
                });
                positionList(); lst.style.display="block";
            }).catch(function(){lst.style.display="none";});
        },400);
    });
    // Keep dropdown aligned if user scrolls or resizes
    window.addEventListener("scroll", function(){ if(lst.style.display!=="none") positionList(); }, true);
    window.addEventListener("resize", function(){ if(lst.style.display!=="none") positionList(); });
    inp.addEventListener("blur",function(){setTimeout(function(){lst.style.display="none";},200);});
    inp.addEventListener("focus",function(){if(lst.children.length){positionList();lst.style.display="block";}});
}

window.addStop = function(existAddr, existLat, existLon) {
    stopCount++;
    var idx = stopCount;
    var cont = document.getElementById("stops-container"); if(!cont) return;
    var row=document.createElement("div"); row.className="osm-stop-row"; row.id="stop-row-"+idx;
    var badge=document.createElement("span"); badge.className="osm-stop-num-badge"; badge.textContent=cont.children.length+1;
    var wrap=document.createElement("div"); wrap.className="osm-autocomplete-wrap";
    var inp=document.createElement("input"); inp.type="text"; inp.id="stop-input-"+idx; inp.placeholder="Stop address..."; inp.autocomplete="off";
    if(existAddr) inp.value=existAddr;
    var sug=document.createElement("ul"); sug.className="osm-suggestions"; sug.id="stop-sug-"+idx;
    wrap.appendChild(inp); wrap.appendChild(sug);
    var latI=document.createElement("input"); latI.type="hidden"; latI.id="stop-lat-"+idx; if(existLat) latI.value=existLat;
    var lonI=document.createElement("input"); lonI.type="hidden"; lonI.id="stop-lon-"+idx; if(existLon) lonI.value=existLon;
    var rm=document.createElement("button"); rm.type="button"; rm.className="osm-remove-stop"; rm.innerHTML="<i class=\'fa fa-times\'></i>";
    rm.addEventListener("click",function(){
        var orphan=document.getElementById("stop-sug-"+idx); if(orphan) orphan.remove();
        row.remove();renumberStops();updateStopsHidden();recalcRoute();
    });
    row.appendChild(badge); row.appendChild(wrap); row.appendChild(latI); row.appendChild(lonI); row.appendChild(rm);
    cont.appendChild(row);
    setupAutocomplete("stop-input-"+idx,"stop-sug-"+idx,function(c){document.getElementById("stop-lat-"+idx).value=c.lat;document.getElementById("stop-lon-"+idx).value=c.lon;updateStopsHidden();recalcRoute();});
};

function renumberStops() {
    document.querySelectorAll("#stops-container .osm-stop-row").forEach(function(r,i){var b=r.querySelector(".osm-stop-num-badge");if(b)b.textContent=i+1;});
}
function updateStopsHidden() {
    var rows=document.querySelectorAll("#stops-container .osm-stop-row"), stops=[];
    rows.forEach(function(row){
        var i=row.querySelector("input[type=\'text\']"), lI=row.querySelector("input[id^=\'stop-lat\']"), nI=row.querySelector("input[id^=\'stop-lon\']");
        stops.push({address:i?i.value:"",lat:lI?parseFloat(lI.value)||null:null,lon:nI?parseFloat(nI.value)||null:null});
    });
    var h=document.getElementById("stops"); if(h) h.value=JSON.stringify(stops);
}

function initMap() {
    var el=document.getElementById("osm-map"); if(!el) return;
    map=L.map("osm-map",{zoomControl:true,scrollWheelZoom:false}).setView([34.0,9.0],6);
    L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png",{attribution:"&copy; OpenStreetMap contributors",maxZoom:18}).addTo(map);
    // Force Leaflet to recalculate container size after page layout fully settles
    setTimeout(function(){ if(map) map.invalidateSize(); }, 250);
}

var lrmControl = null;

function drawWithLRM(wps) {
    if (!map) return;
    var emp = document.getElementById("osm-map-empty"); if (emp) emp.style.display = "none";
    if (lrmControl) { try { map.removeControl(lrmControl); } catch(e){} lrmControl = null; }
    if (outlineLayer) { map.removeLayer(outlineLayer); outlineLayer = null; }
    if (routeLayer)   { map.removeLayer(routeLayer);   routeLayer   = null; }
    markers.forEach(function(m){ map.removeLayer(m); }); markers = [];
    var valid = wps.filter(function(c){
        return c && !isNaN(parseFloat(c.lat)) && !isNaN(parseFloat(c.lon))
            && parseFloat(c.lat) !== 0 && parseFloat(c.lon) !== 0;
    });
    if (valid.length < 2) { return; }
    var coords = valid.map(function(c){
        return parseFloat(c.lon).toFixed(6) + "," + parseFloat(c.lat).toFixed(6);
    }).join(";");
    var proxyUrl = window.location.pathname + "?osrm_proxy=1&coords=" + encodeURIComponent(coords);
    var controller = typeof AbortController !== "undefined" ? new AbortController() : null;
    var timer = controller ? setTimeout(function(){ controller.abort(); }, 20000) : null;
    var fetchOpts = controller ? { signal: controller.signal } : {};
    fetch(proxyUrl, fetchOpts)
        .then(function(r) {
            if (timer) clearTimeout(timer);
            if (!r.ok) throw new Error("HTTP " + r.status);
            return r.json();
        })
        .then(function(data) {
            if (!data.routes || !data.routes.length) { drawFallbackRoute(wps); return; }
            var route = data.routes[0];
            var dInp = document.getElementById("distance");
            if (dInp) dInp.value = Math.round(route.distance / 1000);
            var eInp = document.getElementById("eta");
            if (eInp) {
                var sec = route.duration;
                var h = Math.floor(sec / 3600), m = Math.round((sec % 3600) / 60);
                eInp.value = (h > 0 ? h + "h " : "") + m + "min";
                calcDropoff(sec);
            }
            var latlngs = route.geometry.coordinates.map(function(c){ return [c[1], c[0]]; });
            drawRoutePolyline(latlngs, wps);
        })
        .catch(function() {
            if (timer) clearTimeout(timer);
            drawFallbackRoute(wps);
        });
}


function recalcRoute() {
    updateStopsHidden();
    if (!depCoords||!arrCoords) return;
    var rows = document.querySelectorAll("#stops-container .osm-stop-row");
    var proms = Array.from(rows).map(function(row){
        var lI=row.querySelector("input[id^=\'stop-lat\']"),nI=row.querySelector("input[id^=\'stop-lon\']"),iI=row.querySelector("input[type=\'text\']");
        if(lI&&nI&&lI.value&&nI.value) return Promise.resolve({lat:parseFloat(lI.value),lon:parseFloat(nI.value)});
        if(iI&&iI.value.trim().length>2) return geocode(iI.value.trim());
        return Promise.resolve(null);
    });
    Promise.all(proms).then(function(sc){
        var wps=[depCoords]; sc.forEach(function(c){if(c)wps.push(c);}); wps.push(arrCoords);
        drawWithLRM(wps);
    });
}

function drawRoute() {
    if(!depCoords||!arrCoords) return;
    var wps=[depCoords].concat(stopCoords).concat([arrCoords]);
    drawWithLRM(wps);
}

function drawFallbackRoute(wps) {
    if(!map) return;
    var emp=document.getElementById("osm-map-empty"); if(emp) emp.style.display="none";
    if(outlineLayer) map.removeLayer(outlineLayer);
    if(routeLayer) map.removeLayer(routeLayer);
    markers.forEach(function(m){map.removeLayer(m);}); markers=[];
    var latlngs=wps.map(function(c){return [c.lat,c.lon];});
    // White outline underneath for depth, then solid coloured line on top
    outlineLayer=L.polyline(latlngs,{color:"#ffffff",weight:8,opacity:1,lineJoin:"round",lineCap:"round"}).addTo(map);
    routeLayer=L.polyline(latlngs,{color:"#3c4758",weight:5,opacity:1,lineJoin:"round",lineCap:"round"}).addTo(map);
    var iDep=L.divIcon({className:"",html:"<div style=\'background:#16a34a;width:14px;height:14px;border-radius:50%;border:2px solid #fff;box-shadow:0 2px 6px rgba(0,0,0,0.3);\'></div>",iconSize:[14,14],iconAnchor:[7,7]});
    var iArr=L.divIcon({className:"",html:"<div style=\'background:#dc2626;width:14px;height:14px;border-radius:50%;border:2px solid #fff;box-shadow:0 2px 6px rgba(0,0,0,0.3);\'></div>",iconSize:[14,14],iconAnchor:[7,7]});
    var iStp=L.divIcon({className:"",html:"<div style=\'background:#3c4758;width:11px;height:11px;border-radius:50%;border:2px solid #fff;box-shadow:0 2px 6px rgba(0,0,0,0.25);\'></div>",iconSize:[11,11],iconAnchor:[5.5,5.5]});
    wps.forEach(function(c,i){var ic=(i===0)?iDep:(i===wps.length-1?iArr:iStp);markers.push(L.marker([c.lat,c.lon],{icon:ic}).addTo(map));});
    map.fitBounds(routeLayer.getBounds(),{padding:[24,24]});
}

function drawRoutePolyline(latlngs, wps) {
    if(!map) return;
    var emp=document.getElementById("osm-map-empty"); if(emp) emp.style.display="none";
    if(outlineLayer){map.removeLayer(outlineLayer);outlineLayer=null;}
    if(routeLayer){map.removeLayer(routeLayer);routeLayer=null;}
    markers.forEach(function(m){map.removeLayer(m);}); markers=[];
    outlineLayer=L.polyline(latlngs,{color:"#ffffff",weight:8,opacity:1,lineJoin:"round",lineCap:"round"}).addTo(map);
    routeLayer=L.polyline(latlngs,{color:"#3c4758",weight:5,opacity:1,lineJoin:"round",lineCap:"round"}).addTo(map);
    var iDep=L.divIcon({className:"",html:"<div style=\'background:#16a34a;width:14px;height:14px;border-radius:50%;border:2px solid #fff;box-shadow:0 2px 6px rgba(0,0,0,0.3);\'></div>",iconSize:[14,14],iconAnchor:[7,7]});
    var iArr=L.divIcon({className:"",html:"<div style=\'background:#dc2626;width:14px;height:14px;border-radius:50%;border:2px solid #fff;box-shadow:0 2px 6px rgba(0,0,0,0.3);\'></div>",iconSize:[14,14],iconAnchor:[7,7]});
    var iStp=L.divIcon({className:"",html:"<div style=\'background:#3c4758;width:11px;height:11px;border-radius:50%;border:2px solid #fff;box-shadow:0 2px 6px rgba(0,0,0,0.25);\'></div>",iconSize:[11,11],iconAnchor:[5.5,5.5]});
    wps.forEach(function(c,idx){var ic=(idx===0)?iDep:(idx===wps.length-1?iArr:iStp);markers.push(L.marker([c.lat,c.lon],{icon:ic}).addTo(map));});
    map.fitBounds(routeLayer.getBounds(),{padding:[24,24]});
}

document.addEventListener("DOMContentLoaded", function() {
    document.querySelectorAll("select").forEach(function(el) {
        if (el.id && (el.id.slice(-4) === "hour" || el.id.slice(-3) === "min")) {
            el.style.setProperty("width", "75px", "important");
            el.style.setProperty("max-width", "75px", "important");
            el.style.setProperty("min-width", "75px", "important");
        }
    });
});
function calcDropoff(etaSeconds) {
    var pd = document.getElementById("pickup_datetimeday");
    var pm = document.getElementById("pickup_datetimemonth");
    var py = document.getElementById("pickup_datetimeyear");
    var ph = document.getElementById("pickup_datetimehour");
    var pmin = document.getElementById("pickup_datetimemin");
    if (!pd || !pd.value || !pm || !py) return;
    var pDate = new Date(parseInt(py.value), parseInt(pm.value)-1, parseInt(pd.value),
        ph ? parseInt(ph.value) : 0, pmin ? parseInt(pmin.value) : 0, 0);
    if (isNaN(pDate.getTime())) return;
    var d = new Date(pDate.getTime() + etaSeconds * 1000);
    var dd = document.getElementById("dropoff_datetimeday");
    var dm = document.getElementById("dropoff_datetimemonth");
    var dy = document.getElementById("dropoff_datetimeyear");
    var dh = document.getElementById("dropoff_datetimehour");
    var dmin = document.getElementById("dropoff_datetimemin");
    if (dd) dd.value = d.getDate();
    if (dm) { for (var i=0;i<dm.options.length;i++) { if (parseInt(dm.options[i].value)===(d.getMonth()+1)) { dm.selectedIndex=i; break; } } }
    if (dy) dy.value = d.getFullYear();
    if (dh) { for (var i=0;i<dh.options.length;i++) { if (parseInt(dh.options[i].value)===d.getHours()) { dh.selectedIndex=i; break; } } }
    if (dmin) { var rm=Math.round(d.getMinutes()/5)*5; if(rm>=60)rm=55; for (var i=0;i<dmin.options.length;i++) { if (parseInt(dmin.options[i].value)===rm) { dmin.selectedIndex=i; break; } } }
}

function showLoaders(s) {
    var dl=document.getElementById("distance-loader"); if(dl) dl.style.display=s?"inline-flex":"none";
}
function escH(str){var d=document.createElement("div");d.appendChild(document.createTextNode(str));return d.innerHTML;}
})();
</script>';

// Expense calculators — must be global scope so oninput attributes can reach them
print '<script>
function calcFuelTotal() {
    var qty   = parseFloat(document.getElementById("expense_fuel_qty")   ? document.getElementById("expense_fuel_qty").value   : 0) || 0;
    var price = parseFloat(document.getElementById("expense_fuel_price") ? document.getElementById("expense_fuel_price").value : 0) || 0;
    var el    = document.getElementById("expense_fuel");
    if (qty > 0 && price > 0) {
        var total = Math.round(qty * price * 100) / 100;
        if (el) el.value = total.toFixed(2);
        var sub = document.getElementById("fuel_subtotal");
        if (sub) sub.textContent = total.toFixed(2);
    } else {
        var manual = parseFloat(el ? el.value : 0) || 0;
        var sub = document.getElementById("fuel_subtotal");
        if (sub) sub.textContent = manual > 0 ? manual.toFixed(2) : "—";
    }
    calcExpenseTotal();
}
function calcDriverTotal() {
    var keys = ["expense_driver_salary","expense_driver_overnight","expense_driver_bonus"];
    var total = 0;
    keys.forEach(function(k){ var el=document.getElementById(k); if(el&&el.value) total+=parseFloat(el.value)||0; });
    total = Math.round(total * 100) / 100;
    var el = document.getElementById("expense_driver");
    if (el) el.value = total > 0 ? total.toFixed(2) : "";
    var sub = document.getElementById("driver_subtotal");
    if (sub) sub.textContent = total > 0 ? total.toFixed(2) : "—";
    calcExpenseTotal();
}
function calcRoadTotal() {
    var keys = ["expense_road_toll","expense_road_parking","expense_road_other"];
    var total = 0;
    keys.forEach(function(k){ var el=document.getElementById(k); if(el&&el.value) total+=parseFloat(el.value)||0; });
    total = Math.round(total * 100) / 100;
    var el = document.getElementById("expense_road");
    if (el) el.value = total > 0 ? total.toFixed(2) : "";
    var sub = document.getElementById("road_subtotal");
    if (sub) sub.textContent = total > 0 ? total.toFixed(2) : "—";
    calcExpenseTotal();
}
function calcCommissionTotal() {
    var agent   = parseFloat(document.getElementById("expense_commission_agent") ? document.getElementById("expense_commission_agent").value : 0) || 0;
    var taxRate = parseFloat(document.getElementById("expense_commission_tax")   ? document.getElementById("expense_commission_tax").value   : 0) || 0;
    var other   = parseFloat(document.getElementById("expense_commission_other") ? document.getElementById("expense_commission_other").value : 0) || 0;
    var taxAmt  = Math.round(agent * taxRate / 100 * 100) / 100;
    var total   = Math.round((agent + taxAmt + other) * 100) / 100;
    var taxSpan = document.getElementById("commission_tax_amount");
    if (taxSpan) taxSpan.textContent = taxAmt.toFixed(2);
    var el = document.getElementById("expense_commission");
    if (el) el.value = total > 0 ? total.toFixed(2) : "";
    var sub = document.getElementById("commission_subtotal");
    if (sub) sub.textContent = total > 0 ? total.toFixed(2) : "—";
    calcExpenseTotal();
}
function calcExpenseTotal() {
    var keys = ["expense_fuel","expense_road","expense_driver","expense_commission"];
    var total = 0;
    keys.forEach(function(k){ var el=document.getElementById(k); if(el&&el.value) total+=parseFloat(el.value)||0; });
    total = Math.round(total * 100) / 100;
    var t = document.getElementById("expense_total");
    if (t) t.textContent = total > 0 ? total.toFixed(2) : "0.00";
}
document.addEventListener("DOMContentLoaded", function() {
    calcFuelTotal();
    calcDriverTotal();
    calcRoadTotal();
    calcCommissionTotal();
});
</script>';

// End of page
llxFooter();
$db->close();
?>
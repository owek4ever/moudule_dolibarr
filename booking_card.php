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
$object->fk_customer = '';
$object->booking_date = '';
$object->status = 'pending';
$object->distance = '';
$object->arriving_address = '';
$object->departure_address = '';
$object->buying_amount = '';
$object->selling_amount = '';

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
    $fk_customer = GETPOST('fk_customer', 'int');
    
    // Fix: Convert date to MySQL format
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
    $buying_amount = GETPOST('buying_amount', 'alpha');
    $selling_amount = GETPOST('selling_amount', 'alpha');
    
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
        $sql .= "ref, entity, fk_vehicle, fk_driver, fk_customer, booking_date, status, distance, ";
        $sql .= "arriving_address, departure_address, buying_amount, selling_amount, fk_user_author";
        $sql .= ") VALUES (";
        $sql .= "'".$db->escape($ref)."', ".((int) $conf->entity).", ";
        $sql .= "".((int) $fk_vehicle).", ";
        $sql .= ($fk_driver > 0 ? ((int) $fk_driver) : "NULL").", ";
        $sql .= "".((int) $fk_customer).", ";
        $sql .= "'".$db->escape($booking_date)."', ";
        $sql .= "'".$db->escape($status)."', ";
        $sql .= ($distance > 0 ? ((int) $distance) : "NULL").", ";
        $sql .= "'".$db->escape($arriving_address)."', ";
        $sql .= "'".$db->escape($departure_address)."', ";
        $sql .= ($buying_amount ? ((float) $buying_amount) : "NULL").", ";
        $sql .= ($selling_amount ? ((float) $selling_amount) : "NULL").", ";
        $sql .= ((int) $user->id);
        $sql .= ")";
        
        $result = $db->query($sql);
        if ($result) {
            $id = $db->last_insert_id(MAIN_DB_PREFIX."flotte_booking");
            $db->commit();
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
    $fk_customer = GETPOST('fk_customer', 'int');
    
    // Fix: Convert date to MySQL format
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
    $buying_amount = GETPOST('buying_amount', 'alpha');
    $selling_amount = GETPOST('selling_amount', 'alpha');
    
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
        $sql .= "fk_customer = ".((int) $fk_customer).", ";
        $sql .= "booking_date = '".$db->escape($booking_date)."', ";
        $sql .= "status = '".$db->escape($status)."', ";
        $sql .= "distance = ".($distance > 0 ? ((int) $distance) : "NULL").", ";
        $sql .= "arriving_address = '".$db->escape($arriving_address)."', ";
        $sql .= "departure_address = '".$db->escape($departure_address)."', ";
        $sql .= "buying_amount = ".($buying_amount ? ((float) $buying_amount) : "NULL").", ";
        $sql .= "selling_amount = ".($selling_amount ? ((float) $selling_amount) : "NULL").", ";
        $sql .= "fk_user_modif = ".((int) $user->id).", ";
        $sql .= "tms = '".$db->idate($now)."' ";
        $sql .= "WHERE rowid = ".((int) $id);
        
        $result = $db->query($sql);
        if ($result) {
            $db->commit();
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
    overflow: hidden;
    box-shadow: 0 1px 6px rgba(0,0,0,0.04);
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
        print '<span style="color:#c4c9d8;">'.$langs->trans('NotAssigned').'</span>';
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
        print '<span style="color:#c4c9d8;">'.$langs->trans('NotAssigned').'</span>';
    }
}
print '    </div></div>';

// Customer
print '  <div class="dc-field">';
print '    <div class="dc-field-label required">'.$langs->trans('Customer').'</div>';
print '    <div class="dc-field-value">';
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
        print '<span style="color:#c4c9d8;">'.$langs->trans('NotAssigned').'</span>';
    }
}
print '    </div></div>';

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
print '<div class="dc-card">';
print '  <div class="dc-card-header">';
print '    <div class="dc-card-header-icon green"><i class="fa fa-route"></i></div>';
print '    <span class="dc-card-title">'.$langs->trans('TripDetails').'</span>';
print '  </div>';
print '  <div class="dc-card-body">';

// Departure Address
print '  <div class="dc-field">';
print '    <div class="dc-field-label">'.$langs->trans('DepartureAddress').'</div>';
print '    <div class="dc-field-value">';
if ($isCreate || $isEdit) print '<input type="text" name="departure_address" value="'.dol_escape_htmltag(isset($object->departure_address) ? $object->departure_address : '').'">';
else print (!empty($object->departure_address) ? dol_escape_htmltag($object->departure_address) : '&mdash;');
print '    </div></div>';

// Arriving Address
print '  <div class="dc-field">';
print '    <div class="dc-field-label">'.$langs->trans('ArrivingAddress').'</div>';
print '    <div class="dc-field-value">';
if ($isCreate || $isEdit) print '<input type="text" name="arriving_address" value="'.dol_escape_htmltag(isset($object->arriving_address) ? $object->arriving_address : '').'">';
else print (!empty($object->arriving_address) ? dol_escape_htmltag($object->arriving_address) : '&mdash;');
print '    </div></div>';

// Distance
print '  <div class="dc-field">';
print '    <div class="dc-field-label">'.$langs->trans('Distance').'</div>';
print '    <div class="dc-field-value">';
if ($isCreate || $isEdit) print '<input type="number" name="distance" value="'.dol_escape_htmltag(isset($object->distance) ? $object->distance : '').'" min="0">';
else print (!empty($object->distance) ? dol_escape_htmltag($object->distance).' '.$langs->trans('Km') : '&mdash;');
print '    </div></div>';

// Buying Amount
print '  <div class="dc-field">';
print '    <div class="dc-field-label">'.$langs->trans('BuyingAmount').'</div>';
print '    <div class="dc-field-value">';
if ($isCreate || $isEdit) print '<input type="number" name="buying_amount" value="'.dol_escape_htmltag(isset($object->buying_amount) ? $object->buying_amount : '').'" min="0" step="0.01">';
else print (!empty($object->buying_amount) ? '<span class="dc-amount buying">'.price($object->buying_amount).'</span>' : '&mdash;');
print '    </div></div>';

// Selling Amount
print '  <div class="dc-field">';
print '    <div class="dc-field-label">'.$langs->trans('SellingAmount').'</div>';
print '    <div class="dc-field-value">';
if ($isCreate || $isEdit) print '<input type="number" name="selling_amount" value="'.dol_escape_htmltag(isset($object->selling_amount) ? $object->selling_amount : '').'" min="0" step="0.01">';
else print (!empty($object->selling_amount) ? '<span class="dc-amount selling">'.price($object->selling_amount).'</span>' : '&mdash;');
print '    </div></div>';

print '  </div>';// card-body
print '</div>';  // dc-card

print '</div>';// dc-grid

/* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
   BOTTOM ACTION BAR
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */
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

// End of page
llxFooter();
$db->close();
?>
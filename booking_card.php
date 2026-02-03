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

// Subheader
$linkback = '<a href="' . DOL_URL_ROOT . '/flotte/booking_list.php">' . $langs->trans('BackToList') . '</a>';

$h = 0;
$head = array();
$head[$h][0] = $_SERVER["PHP_SELF"] . '?id=' . $id;
$head[$h][1] = $langs->trans('Card');
$head[$h][2] = 'card';
$h++;

dol_fiche_head($head, 'card', $langs->trans('Booking'), -1, 'calendar');

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

if ($action == 'create' || $action == 'edit') {
    print '<form method="POST" action="' . $_SERVER['PHP_SELF'] . ($id > 0 ? '?id=' . $id : '') . '">';
    print '<input type="hidden" name="action" value="' . ($action == 'create' ? 'add' : 'update') . '">';
    print '<input type="hidden" name="token" value="' . newToken() . '">';
    if ($id > 0) {
        print '<input type="hidden" name="id" value="' . $id . '">';
    }
}

print '<div class="fichecenter">';
print '<div class="fichehalfleft">';

// Basic Information
print load_fiche_titre($langs->trans('BookingInformation'), '', '');
print '<table class="border tableforfield" width="100%">';

// Reference
print '<tr><td class="titlefield">' . $langs->trans('Reference') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    print $form->textwithpicto('<input type="text" class="flat" name="ref" value="' . dol_escape_htmltag(isset($object->ref) ? $object->ref : '') . '" size="20" readonly>', $langs->trans('AutoGenerated'));
} else {
    print dol_escape_htmltag($object->ref);
}
print '</td></tr>';

// Vehicle
print '<tr><td>' . $langs->trans('Vehicle') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    // Get available vehicles
    $vehicles = array();
    $sql = "SELECT rowid, ref, maker, model FROM ".MAIN_DB_PREFIX."flotte_vehicle WHERE entity IN (".getEntity('flotte').")";
    $resql = $db->query($sql);
    if ($resql) {
        while ($obj = $db->fetch_object($resql)) {
            $vehicles[$obj->rowid] = dol_escape_htmltag($obj->ref . ' - ' . $obj->maker . ' ' . $obj->model);
        }
    }
    print $form->selectarray('fk_vehicle', $vehicles, (isset($object->fk_vehicle) ? $object->fk_vehicle : ''), 1, 0, 0, '', 0, 0, 0, '', 'minwidth300');
} else {
    if (!empty($object->fk_vehicle)) {
        $sql = "SELECT ref, maker, model FROM ".MAIN_DB_PREFIX."flotte_vehicle WHERE rowid = " . ((int) $object->fk_vehicle);
        $resql = $db->query($sql);
        if ($resql && $db->num_rows($resql) > 0) {
            $obj = $db->fetch_object($resql);
            print dol_escape_htmltag($obj->ref . ' - ' . $obj->maker . ' ' . $obj->model);
        } else {
            print '<span class="opacitymedium">' . $langs->trans("VehicleNotFound") . '</span>';
        }
    } else {
        print '<span class="opacitymedium">' . $langs->trans("NotAssigned") . '</span>';
    }
}
print '</td></tr>';

// Driver
print '<tr><td>' . $langs->trans('Driver') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    // Get available drivers
    $drivers = array('' => $langs->trans('SelectDriver'));
    $sql = "SELECT rowid, firstname, lastname FROM ".MAIN_DB_PREFIX."flotte_driver WHERE entity IN (".getEntity('flotte').")";
    $resql = $db->query($sql);
    if ($resql) {
        while ($obj = $db->fetch_object($resql)) {
            $drivers[$obj->rowid] = dol_escape_htmltag($obj->firstname . ' ' . $obj->lastname);
        }
    }
    print $form->selectarray('fk_driver', $drivers, (isset($object->fk_driver) ? $object->fk_driver : ''), 0, 0, 0, '', 0, 0, 0, '', 'minwidth300');
} else {
    if (!empty($object->fk_driver)) {
        $sql = "SELECT firstname, lastname FROM ".MAIN_DB_PREFIX."flotte_driver WHERE rowid = " . ((int) $object->fk_driver);
        $resql = $db->query($sql);
        if ($resql && $db->num_rows($resql) > 0) {
            $obj = $db->fetch_object($resql);
            print dol_escape_htmltag($obj->firstname . ' ' . $obj->lastname);
        } else {
            print '<span class="opacitymedium">' . $langs->trans("DriverNotFound") . '</span>';
        }
    } else {
        print '<span class="opacitymedium">' . $langs->trans("NotAssigned") . '</span>';
    }
}
print '</td></tr>';

// Customer
print '<tr><td>' . $langs->trans('Customer') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    // Get available customers
    $customers = array();
    $sql = "SELECT rowid, firstname, lastname, company_name FROM ".MAIN_DB_PREFIX."flotte_customer WHERE entity IN (".getEntity('flotte').")";
    $resql = $db->query($sql);
    if ($resql) {
        while ($obj = $db->fetch_object($resql)) {
            $name = $obj->firstname . ' ' . $obj->lastname;
            if (!empty($obj->company_name)) {
                $name .= ' (' . $obj->company_name . ')';
            }
            $customers[$obj->rowid] = dol_escape_htmltag($name);
        }
    }
    print $form->selectarray('fk_customer', $customers, (isset($object->fk_customer) ? $object->fk_customer : ''), 1, 0, 0, '', 0, 0, 0, '', 'minwidth300');
} else {
    if (!empty($object->fk_customer)) {
        $sql = "SELECT firstname, lastname, company_name FROM ".MAIN_DB_PREFIX."flotte_customer WHERE rowid = " . ((int) $object->fk_customer);
        $resql = $db->query($sql);
        if ($resql && $db->num_rows($resql) > 0) {
            $obj = $db->fetch_object($resql);
            $name = $obj->firstname . ' ' . $obj->lastname;
            if (!empty($obj->company_name)) {
                $name .= ' (' . $obj->company_name . ')';
            }
            print dol_escape_htmltag($name);
        } else {
            print '<span class="opacitymedium">' . $langs->trans("CustomerNotFound") . '</span>';
        }
    } else {
        print '<span class="opacitymedium">' . $langs->trans("NotAssigned") . '</span>';
    }
}
print '</td></tr>';

// Booking Date
print '<tr><td>' . $langs->trans('BookingDate') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    $selected_date = '';
    if (isset($object->booking_date) && !empty($object->booking_date)) {
        $selected_date = $object->booking_date;
    }
    print $form->selectDate($selected_date, 'booking_date', '', '', 1, '', 1, 1);
} else {
    print dol_print_date($object->booking_date, 'day');
}
print '</td></tr>';

// Status
print '<tr><td>' . $langs->trans('Status') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    $status_options = array(
        'pending' => $langs->trans('Pending'),
        'confirmed' => $langs->trans('Confirmed'),
        'in_progress' => $langs->trans('InProgress'),
        'completed' => $langs->trans('Completed'),
        'cancelled' => $langs->trans('Cancelled')
    );
    print $form->selectarray('status', $status_options, (isset($object->status) ? $object->status : 'pending'), 0);
} else {
    print $langs->trans(ucfirst($object->status));
}
print '</td></tr>';

print '</table>';

print '</div>';
print '<div class="fichehalfright">';

// Additional Information
print load_fiche_titre($langs->trans('AdditionalInformation'), '', '');
print '<table class="border tableforfield" width="100%">';

// Distance
print '<tr><td class="titlefield">' . $langs->trans('Distance') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    print '<input type="number" class="flat" name="distance" value="' . dol_escape_htmltag(isset($object->distance) ? $object->distance : '') . '" min="0"> ' . $langs->trans('Km');
} else {
    print ($object->distance ? dol_escape_htmltag($object->distance) . ' ' . $langs->trans('Km') : '');
}
print '</td></tr>';

// Departure Address
print '<tr><td>' . $langs->trans('DepartureAddress') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    print '<input type="text" class="flat" name="departure_address" value="' . dol_escape_htmltag(isset($object->departure_address) ? $object->departure_address : '') . '" size="40">';
} else {
    print dol_escape_htmltag($object->departure_address);
}
print '</td></tr>';

// Arriving Address
print '<tr><td>' . $langs->trans('ArrivingAddress') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    print '<input type="text" class="flat" name="arriving_address" value="' . dol_escape_htmltag(isset($object->arriving_address) ? $object->arriving_address : '') . '" size="40">';
} else {
    print dol_escape_htmltag($object->arriving_address);
}
print '</td></tr>';

// Buying Amount
print '<tr><td>' . $langs->trans('BuyingAmount') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    print '<input type="number" class="flat" name="buying_amount" value="' . dol_escape_htmltag(isset($object->buying_amount) ? $object->buying_amount : '') . '" min="0" step="0.01">';
} else {
    print ($object->buying_amount ? price($object->buying_amount) : '');
}
print '</td></tr>';

// Selling Amount
print '<tr><td>' . $langs->trans('SellingAmount') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    print '<input type="number" class="flat" name="selling_amount" value="' . dol_escape_htmltag(isset($object->selling_amount) ? $object->selling_amount : '') . '" min="0" step="0.01">';
} else {
    print ($object->selling_amount ? price($object->selling_amount) : '');
}
print '</td></tr>';

print '</table>';

print '</div>';
print '</div>';

print '<div class="clearboth"></div>';

// Form buttons
if ($action == 'create' || $action == 'edit') {
    print '<div class="center">';
    print '<input type="submit" class="button" value="' . ($action == 'create' ? $langs->trans('Create') : $langs->trans('Save')) . '">';
    print '&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;';
    print '<a class="button button-cancel" href="' . ($id > 0 ? $_SERVER['PHP_SELF'] . '?id=' . $id : 'booking_list.php') . '">' . $langs->trans('Cancel') . '</a>';
    print '</div>';
    print '</form>';
} elseif ($id > 0) {
    // Action buttons
    print '<div class="tabsAction">';
    print '<div class="inline-block divButAction">';
    print '<a class="butAction" href="' . $_SERVER['PHP_SELF'] . '?id=' . $id . '&action=edit">' . $langs->trans('Modify') . '</a>';
    if (!empty($user->rights->flotte->delete)) {
        print '<a class="butActionDelete" href="' . $_SERVER['PHP_SELF'] . '?id=' . $id . '&action=delete">' . $langs->trans('Delete') . '</a>';
    }
    print '</div>';
    print '</div>';
}

dol_fiche_end();

// End of page
llxFooter();
$db->close();
?>
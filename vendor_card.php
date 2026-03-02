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
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';

// Load translation files
$langs->loadLangs(array("flotte@flotte", "companies", "other"));

// Function to generate next vendor reference
function getNextVendorRef($db, $entity) {
    $prefix = "VEND-";
    
    // Get the last reference from database
    $sql = "SELECT ref FROM ".MAIN_DB_PREFIX."flotte_vendor";
    $sql .= " WHERE entity = ".$entity;
    $sql .= " AND ref LIKE '".$prefix."%'";
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
    
    // Format with leading zeros (e.g., VEND-0001)
    return $prefix.str_pad($next_number, 4, '0', STR_PAD_LEFT);
}

// Get parameters
$id = GETPOST('id', 'int');
$action = GETPOST('action', 'aZ09') ? GETPOST('action', 'aZ09') : 'view';
$confirm = GETPOST('confirm', 'alpha');
$cancel = GETPOST('cancel', 'alpha');

// Security check
restrictedArea($user, 'flotte');

// Initialize form and societe object
$form = new Form($db);
$formcompany = new FormCompany($db);

// Initialize variables
$object = new stdClass();
$object->id = 0;
$object->ref = '';
$object->fk_soc = 0;
$object->name = '';
$object->phone = '';
$object->email = '';
$object->website = '';
$object->address1 = '';
$object->address2 = '';
$object->city = '';
$object->state = '';
$object->type = '';
$object->note = '';

// For display purposes - loaded from societe
$thirdparty = new Societe($db);

$error = 0;
$errors = array();

// Generate reference for new vendor
if ($action == 'create' && empty($object->ref)) {
    $object->ref = getNextVendorRef($db, $conf->entity);
}

/*
 * Actions
 */

if ($cancel) {
    $action = 'view';
}

// Handle AJAX request to get Third Party data
if ($action == 'fetch_thirdparty' && GETPOST('fk_soc', 'int') > 0) {
    $socid = GETPOST('fk_soc', 'int');
    $soc = new Societe($db);
    $result = $soc->fetch($socid);
    
    if ($result > 0) {
        $data = array(
            'name' => $soc->name,
            'phone' => $soc->phone,
            'email' => $soc->email,
            'website' => $soc->url,
            'address1' => $soc->address,
            'address2' => '',
            'city' => $soc->town,
            'state' => $soc->state,
        );
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }
}

if ($action == 'confirm_delete' && $confirm == 'yes' && $user->rights->flotte->delete) {
    $db->begin();
    
    $sql = "DELETE FROM ".MAIN_DB_PREFIX."flotte_vendor WHERE rowid = ".((int) $id);
    $result = $db->query($sql);
    
    if ($result) {
        $db->commit();
        header("Location: " . dol_buildpath('/flotte/vendor_list.php', 1));
        exit;
    } else {
        $db->rollback();
        $error++;
        setEventMessages("Error deleting vendor", null, 'errors');
    }
}

if ($action == 'add') {
    $db->begin();
    
    // Get form data
    $ref = GETPOST('ref', 'alphanohtml');
    $fk_soc = GETPOST('fk_soc', 'int');
    $name = GETPOST('name', 'alphanohtml');
    $phone = GETPOST('phone', 'alphanohtml');
    $email = GETPOST('email', 'email');
    $website = GETPOST('website', 'alphanohtml');
    $address1 = GETPOST('address1', 'alphanohtml');
    $address2 = GETPOST('address2', 'alphanohtml');
    $city = GETPOST('city', 'alphanohtml');
    $state = GETPOST('state', 'alphanohtml');
    $type = GETPOST('type', 'alpha');
    $note = GETPOST('note', 'restricthtml');
    
    // Auto-generate reference if empty
    if (empty($ref)) {
        $ref = getNextVendorRef($db, $conf->entity);
    }
    
    // Validation
    if (empty($fk_soc) || $fk_soc <= 0) {
        $error++;
        $errors[] = "Please select a Third Party";
    }
    
    if (empty($name)) {
        $error++;
        $errors[] = "Vendor name is required";
    }
    
    // Check if this third party is already a vendor
    if (!$error) {
        $sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."flotte_vendor";
        $sql .= " WHERE fk_soc = ".((int) $fk_soc);
        $sql .= " AND entity = ".$conf->entity;
        $resql = $db->query($sql);
        if ($resql && $db->num_rows($resql) > 0) {
            $error++;
            $errors[] = "This Third Party is already registered as a vendor";
        }
    }
    
    if (!$error) {
        $sql = "INSERT INTO ".MAIN_DB_PREFIX."flotte_vendor (";
        $sql .= "ref, entity, fk_soc, name, phone, email, website, address1, address2, city, state, type, note, fk_user_author, datec";
        $sql .= ") VALUES (";
        $sql .= "'".$db->escape($ref)."', ";
        $sql .= $conf->entity.", ";
        $sql .= ((int) $fk_soc).", ";
        $sql .= "'".$db->escape($name)."', ";
        $sql .= "'".$db->escape($phone)."', ";
        $sql .= "'".$db->escape($email)."', ";
        $sql .= "'".$db->escape($website)."', ";
        $sql .= "'".$db->escape($address1)."', ";
        $sql .= "'".$db->escape($address2)."', ";
        $sql .= "'".$db->escape($city)."', ";
        $sql .= "'".$db->escape($state)."', ";
        $sql .= "'".$db->escape($type)."', ";
        $sql .= "'".$db->escape($note)."', ";
        $sql .= $user->id.", ";
        $sql .= "'".$db->idate(dol_now())."'";
        $sql .= ")";
        
        $result = $db->query($sql);
        if ($result) {
            $id = $db->last_insert_id(MAIN_DB_PREFIX."flotte_vendor");
            $db->commit();
            $action = 'view';
            setEventMessages("Vendor created successfully", null, 'mesgs');
        } else {
            $db->rollback();
            $error++;
            $errors[] = "Error creating vendor: " . $db->lasterror();
        }
    } else {
        $db->rollback();
    }
    
    if ($error) {
        setEventMessages($errors, null, 'errors');
    }
}

if ($action == 'update') {
    $db->begin();
    
    // Get form data
    $name = GETPOST('name', 'alphanohtml');
    $phone = GETPOST('phone', 'alphanohtml');
    $email = GETPOST('email', 'email');
    $website = GETPOST('website', 'alphanohtml');
    $address1 = GETPOST('address1', 'alphanohtml');
    $address2 = GETPOST('address2', 'alphanohtml');
    $city = GETPOST('city', 'alphanohtml');
    $state = GETPOST('state', 'alphanohtml');
    $type = GETPOST('type', 'alpha');
    $note = GETPOST('note', 'restricthtml');
    
    if (empty($name)) {
        $error++;
        $errors[] = "Vendor name is required";
    }
    
    if (!$error) {
        $sql = "UPDATE ".MAIN_DB_PREFIX."flotte_vendor SET ";
        $sql .= "name = '".$db->escape($name)."', ";
        $sql .= "phone = '".$db->escape($phone)."', ";
        $sql .= "email = '".$db->escape($email)."', ";
        $sql .= "website = '".$db->escape($website)."', ";
        $sql .= "address1 = '".$db->escape($address1)."', ";
        $sql .= "address2 = '".$db->escape($address2)."', ";
        $sql .= "city = '".$db->escape($city)."', ";
        $sql .= "state = '".$db->escape($state)."', ";
        $sql .= "type = '".$db->escape($type)."', ";
        $sql .= "note = '".$db->escape($note)."', ";
        $sql .= "fk_user_modif = ".$user->id.", ";
        $sql .= "tms = '".$db->idate(dol_now())."' ";
        $sql .= "WHERE rowid = ".((int) $id);
        
        $result = $db->query($sql);
        if ($result) {
            $db->commit();
            $action = 'view';
            setEventMessages("Vendor updated successfully", null, 'mesgs');
        } else {
            $db->rollback();
            $error++;
            $errors[] = "Error updating vendor: " . $db->lasterror();
        }
    } else {
        $db->rollback();
    }
    
    if ($error) {
        setEventMessages($errors, null, 'errors');
    }
}

// Load object data
if ($id > 0) {
    $sql = "SELECT * FROM ".MAIN_DB_PREFIX."flotte_vendor WHERE rowid = ".((int) $id);
    $resql = $db->query($sql);
    if ($resql) {
        $obj = $db->fetch_object($resql);
        if ($obj) {
            $object->id = $obj->rowid;
            $object->ref = $obj->ref;
            $object->fk_soc = $obj->fk_soc;
            $object->name = $obj->name;
            $object->phone = $obj->phone;
            $object->email = $obj->email;
            $object->website = $obj->website;
            $object->address1 = $obj->address1;
            $object->address2 = $obj->address2;
            $object->city = $obj->city;
            $object->state = $obj->state;
            $object->type = $obj->type;
            $object->note = $obj->note;
            
            // Load third party information for reference
            if ($object->fk_soc > 0) {
                $thirdparty->fetch($object->fk_soc);
            }
        }
    }
}

/*
 * View
 */

$title = $langs->trans('Vendor');
if ($action == 'create') {
    $title = $langs->trans('NewVendor');
} elseif ($action == 'edit') {
    $title = $langs->trans('EditVendor');
} elseif ($id > 0) {
    $title = $langs->trans('Vendor') . " " . $object->ref;
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
    display: flex; align-items: center; justify-content: space-between;
    padding: 26px 0 22px; border-bottom: 1px solid #e8eaf0;
    margin-bottom: 28px; gap: 16px; flex-wrap: wrap;
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

/* ── Type badge ── */
.dc-badge {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 4px 11px; border-radius: 20px;
    font-size: 11.5px; font-weight: 600; white-space: nowrap;
}
.dc-badge::before { content: ''; width: 6px; height: 6px; border-radius: 50%; flex-shrink: 0; }
.dc-badge.parts       { background: #eff6ff; color: #1d4ed8; }
.dc-badge.parts::before       { background: #3b82f6; }
.dc-badge.fuel        { background: #fff8ec; color: #b45309; }
.dc-badge.fuel::before        { background: #f59e0b; }
.dc-badge.maintenance { background: #f5f3ff; color: #6d28d9; }
.dc-badge.maintenance::before { background: #8b5cf6; }
.dc-badge.insurance   { background: #edfaf3; color: #1a7d4a; }
.dc-badge.insurance::before   { background: #22c55e; }
.dc-badge.service     { background: #fef2f2; color: #b91c1c; }
.dc-badge.service::before     { background: #ef4444; }
.dc-badge.other       { background: #f5f6fb; color: #8b92a9; }
.dc-badge.other::before       { background: #c4c9d8; }

/* ── Buttons ── */
.dc-btn {
    display: inline-flex; align-items: center; gap: 7px;
    padding: 8px 16px; border-radius: 6px; font-size: 13px; font-weight: 600;
    text-decoration: none !important; cursor: pointer;
    font-family: 'DM Sans', sans-serif; white-space: nowrap;
    transition: all 0.15s ease; border: none;
}
.dc-btn-primary { background: #3c4758 !important; color: #fff !important; }
.dc-btn-primary:hover { background: #2a3346 !important; color: #fff !important; }
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
button.dc-btn-primary { background: #3c4758 !important; color: #fff !important; border: none !important; }
button.dc-btn-primary:hover { background: #2a3346 !important; }

/* ── Two-column grid ── */
.dc-grid {
    display: grid; grid-template-columns: 1fr 1fr;
    gap: 20px; margin-bottom: 20px;
}
@media (max-width: 780px) { .dc-grid { grid-template-columns: 1fr; } }

/* ── Section card ── */
.dc-card {
    background: #fff; border: 1px solid #e8eaf0;
    border-radius: 12px; overflow: hidden;
    box-shadow: 0 1px 6px rgba(0,0,0,0.04);
}
.dc-card-header {
    display: flex; align-items: center; gap: 10px;
    padding: 14px 20px; border-bottom: 1px solid #f0f2f8;
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
    padding: 12px 20px; border-bottom: 1px solid #f5f6fb; gap: 12px;
}
.dc-field:last-child { border-bottom: none; }
.dc-field-label {
    flex: 0 0 150px; font-size: 12px; font-weight: 600;
    color: #8b92a9; text-transform: uppercase; letter-spacing: 0.5px;
    padding-top: 2px; line-height: 1.4;
}
.dc-field-label.required::after { content: ' *'; color: #ef4444; }
.dc-field-value { flex: 1; font-size: 13.5px; color: #2d3748; line-height: 1.5; min-width: 0; }
.dc-field-value a { color: #3c4758; }

/* ── Mono / chips ── */
.dc-mono {
    font-family: 'DM Mono', monospace; font-size: 12px;
    background: #f0f2fa; color: #4a5568;
    padding: 3px 9px; border-radius: 5px; display: inline-block;
}
.dc-ref-tag {
    font-family: 'DM Mono', monospace; font-size: 13px;
    background: rgba(60,71,88,0.08); color: #3c4758;
    padding: 4px 10px; border-radius: 6px; font-weight: 500;
}

/* ── Address block ── */
.dc-address {
    font-size: 13.5px; color: #2d3748; line-height: 1.8;
}

/* ── Auto-fill notice ── */
.dc-autofill-notice {
    font-size: 12px; color: #8b92a9; font-style: italic;
    margin-top: 6px; display: flex; align-items: center; gap: 5px;
}

/* ── Form inputs ── */
.dc-page input[type="text"],
.dc-page input[type="email"],
.dc-page input[type="url"],
.dc-page input[type="number"],
.dc-page select,
.dc-page textarea {
    padding: 8px 12px !important; border: 1.5px solid #e2e5f0 !important;
    border-radius: 8px !important; font-size: 13px !important;
    font-family: 'DM Sans', sans-serif !important; color: #2d3748 !important;
    background: #fafbfe !important; outline: none !important;
    transition: border-color 0.15s, box-shadow 0.15s !important;
    width: 100% !important; max-width: 100% !important; box-sizing: border-box !important;
}
.dc-page input[type="text"]:focus,
.dc-page input[type="email"]:focus,
.dc-page input[type="url"]:focus,
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
    gap: 8px; padding: 18px 0 4px; flex-wrap: wrap;
}
.dc-action-bar-left { margin-right: auto; }
</style>

<script type="text/javascript">
jQuery(document).ready(function() {
    jQuery('#fk_soc').change(function() {
        var socid = jQuery(this).val();
        if (socid > 0) {
            jQuery.ajax({
                url: '<?php echo $_SERVER['PHP_SELF']; ?>',
                type: 'GET',
                data: { action: 'fetch_thirdparty', fk_soc: socid },
                dataType: 'json',
                success: function(data) {
                    jQuery('input[name="name"]').val(data.name);
                    jQuery('input[name="phone"]').val(data.phone);
                    jQuery('input[name="email"]').val(data.email);
                    jQuery('input[name="website"]').val(data.website);
                    jQuery('input[name="address1"]').val(data.address1);
                    jQuery('input[name="address2"]').val(data.address2);
                    jQuery('input[name="city"]').val(data.city);
                    jQuery('input[name="state"]').val(data.state);
                },
                error: function() { console.log('Error fetching Third Party data'); }
            });
        }
    });
});
</script>
<?php

// Confirmation to delete
if ($action == 'delete') {
    $formconfirm = $form->formconfirm(
        $_SERVER['PHP_SELF'].'?id='.$id,
        $langs->trans('DeleteVendor'),
        $langs->trans('ConfirmDeleteVendor'),
        'confirm_delete', '', 0, 1
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

$pageTitle = $isCreate ? $langs->trans('NewVendor') : ($isEdit ? $langs->trans('EditVendor') : $langs->trans('Vendor'));
$pageSub   = $isCreate ? '' : (isset($object->ref) ? $object->ref : '');

// Type badge class
$typeClass = !empty($object->type) ? strtolower($object->type) : '';

// Form start
if ($isCreate || $isEdit) {
    print '<form method="POST" action="'.$_SERVER['PHP_SELF'].($id > 0 ? '?id='.$id : '').'">';
    print '<input type="hidden" name="action" value="'.($isCreate ? 'add' : 'update').'">';
    print '<input type="hidden" name="token" value="'.newToken().'">';
    if ($id > 0) print '<input type="hidden" name="id" value="'.$id.'">';
}

print '<div class="dc-page">';

/* ── PAGE HEADER ── */
print '<div class="dc-header">';
print '  <div class="dc-header-left">';
print '    <div class="dc-header-icon"><i class="fa fa-truck"></i></div>';
print '    <div>';
print '      <div class="dc-header-title">'.dol_escape_htmltag($pageTitle).'</div>';
if ($pageSub) print '      <div class="dc-header-sub">'.dol_escape_htmltag($pageSub).'</div>';
print '    </div>';
print '  </div>';
print '  <div class="dc-header-actions">';
if ($isView && $id > 0) {
    if (!empty($object->type)) {
        print '<span class="dc-badge '.$typeClass.'">'.dol_escape_htmltag($object->type).'</span>';
    }
    print '<a class="dc-btn dc-btn-ghost" href="'.dol_buildpath('/flotte/vendor_list.php', 1).'"><i class="fa fa-arrow-left"></i> '.$langs->trans('BackToList').'</a>';
    print '<a class="dc-btn dc-btn-ghost" href="'.$_SERVER['PHP_SELF'].'?id='.$id.'&action=edit"><i class="fa fa-pen"></i> '.$langs->trans('Modify').'</a>';
    if ($user->rights->flotte->delete) {
        print '<a class="dc-btn dc-btn-danger" href="'.$_SERVER['PHP_SELF'].'?id='.$id.'&action=delete"><i class="fa fa-trash"></i> '.$langs->trans('Delete').'</a>';
    }
}
print '  </div>';
print '</div>';

/* ── ROW 1: Vendor Information + Address ── */
print '<div class="dc-grid">';

/* Card: Vendor Information */
print '<div class="dc-card">';
print '  <div class="dc-card-header">';
print '    <div class="dc-card-header-icon blue"><i class="fa fa-truck"></i></div>';
print '    <span class="dc-card-title">'.$langs->trans('VendorInformation').'</span>';
print '  </div>';
print '  <div class="dc-card-body">';

// Reference
print '  <div class="dc-field">';
print '    <div class="dc-field-label">'.$langs->trans('Reference').'</div>';
print '    <div class="dc-field-value">';
if ($isCreate) {
    print '<em style="color:#9aa0b4;font-size:12.5px;">'.$langs->trans('AutoGenerated').'</em>';
    print '<input type="hidden" name="ref" value="'.dol_escape_htmltag($object->ref).'">';
} elseif ($isEdit) {
    print '<input type="text" name="ref" value="'.dol_escape_htmltag($object->ref).'" readonly style="background:#f5f6fa!important;color:#9aa0b4!important;">';
} else {
    print '<span class="dc-ref-tag">'.dol_escape_htmltag($object->ref).'</span>';
}
print '    </div></div>';

// Third Party
print '  <div class="dc-field">';
print '    <div class="dc-field-label required">'.$langs->trans('ThirdParty').'</div>';
print '    <div class="dc-field-value">';
if ($isCreate) {
    print $formcompany->select_company($object->fk_soc, 'fk_soc', 's.fournisseur = 1', 'SelectThirdParty', 0, 0, array(), 0, '');
    print ' <a href="'.DOL_URL_ROOT.'/societe/card.php?action=create&type=f&backtopage='.urlencode($_SERVER['PHP_SELF'].'?action=create').'" target="_blank" title="'.$langs->trans('CreateThirdParty').'" style="margin-left:6px;">';
    print '<i class="fa fa-plus-circle" style="color:#3c4758;"></i></a>';
    print '<div class="dc-autofill-notice"><i class="fa fa-magic" style="font-size:10px;"></i>'.$langs->trans('SelectThirdPartyToAutofill').'</div>';
} else {
    if ($thirdparty->id > 0) {
        print $thirdparty->getNomUrl(1);
    } else {
        print '&mdash;';
    }
}
print '    </div></div>';

// Name
print '  <div class="dc-field">';
print '    <div class="dc-field-label required">'.$langs->trans('Name').'</div>';
print '    <div class="dc-field-value">';
if ($isCreate || $isEdit) {
    print '<input type="text" name="name" value="'.dol_escape_htmltag($object->name).'" required>';
} else {
    print '<strong style="font-size:14px;">'.dol_escape_htmltag($object->name).'</strong>';
}
print '    </div></div>';

// Type
print '  <div class="dc-field">';
print '    <div class="dc-field-label">'.$langs->trans('Type').'</div>';
print '    <div class="dc-field-value">';
if ($isCreate || $isEdit) {
    $typearray = array(
        ''            => $langs->trans('SelectType'),
        'Parts'       => $langs->trans('Parts'),
        'Fuel'        => $langs->trans('Fuel'),
        'Maintenance' => $langs->trans('Maintenance'),
        'Insurance'   => $langs->trans('Insurance'),
        'Service'     => $langs->trans('Service'),
        'Other'       => $langs->trans('Other'),
    );
    print $form->selectarray('type', $typearray, $object->type, 0);
} else {
    if (!empty($object->type)) {
        print '<span class="dc-badge '.$typeClass.'">'.dol_escape_htmltag($object->type).'</span>';
    } else {
        print '&mdash;';
    }
}
print '    </div></div>';

// Phone
print '  <div class="dc-field">';
print '    <div class="dc-field-label">'.$langs->trans('Phone').'</div>';
print '    <div class="dc-field-value">';
if ($isCreate || $isEdit) {
    print '<input type="text" name="phone" value="'.dol_escape_htmltag($object->phone).'">';
} else {
    print (!empty($object->phone) ? dol_print_phone($object->phone) : '&mdash;');
}
print '    </div></div>';

// Email
print '  <div class="dc-field">';
print '    <div class="dc-field-label">'.$langs->trans('Email').'</div>';
print '    <div class="dc-field-value">';
if ($isCreate || $isEdit) {
    print '<input type="text" name="email" value="'.dol_escape_htmltag($object->email).'">';
} else {
    print (!empty($object->email) ? dol_print_email($object->email) : '&mdash;');
}
print '    </div></div>';

// Website
print '  <div class="dc-field">';
print '    <div class="dc-field-label">'.$langs->trans('Website').'</div>';
print '    <div class="dc-field-value">';
if ($isCreate || $isEdit) {
    print '<input type="text" name="website" value="'.dol_escape_htmltag($object->website).'">';
} else {
    if (!empty($object->website)) {
        $url = (strpos($object->website, 'http') === 0) ? $object->website : 'http://'.$object->website;
        print '<a href="'.dol_escape_htmltag($url).'" target="_blank" style="display:inline-flex;align-items:center;gap:5px;color:#3c4758;">';
        print '<i class="fa fa-external-link-alt" style="font-size:11px;opacity:0.6;"></i>';
        print dol_escape_htmltag($object->website).'</a>';
    } else {
        print '&mdash;';
    }
}
print '    </div></div>';

print '  </div>';
print '</div>';

/* Card: Address Information */
print '<div class="dc-card">';
print '  <div class="dc-card-header">';
print '    <div class="dc-card-header-icon green"><i class="fa fa-map-marker-alt"></i></div>';
print '    <span class="dc-card-title">'.$langs->trans('AddressInformation').'</span>';
print '  </div>';
print '  <div class="dc-card-body">';

if ($isView) {
    // View mode: show address as a formatted block
    $hasAddress = !empty($object->address1) || !empty($object->address2) || !empty($object->city) || !empty($object->state);
    print '  <div class="dc-field" style="flex-direction:column;gap:4px;">';
    print '    <div class="dc-field-label" style="flex:none;">'.$langs->trans('Address').'</div>';
    print '    <div class="dc-field-value">';
    if ($hasAddress) {
        print '<div class="dc-address">';
        if (!empty($object->address1)) print dol_escape_htmltag($object->address1).'<br>';
        if (!empty($object->address2)) print dol_escape_htmltag($object->address2).'<br>';
        $cityState = array_filter(array(dol_escape_htmltag($object->city), dol_escape_htmltag($object->state)));
        if ($cityState) print implode(', ', $cityState);
        print '</div>';
    } else {
        print '<span style="color:#c4c9d8;">&mdash;</span>';
    }
    print '    </div>';
    print '  </div>';
} else {
    // Edit/Create mode: individual fields
    // Address Line 1
    print '  <div class="dc-field">';
    print '    <div class="dc-field-label">'.$langs->trans('AddressLine1').'</div>';
    print '    <div class="dc-field-value"><input type="text" name="address1" value="'.dol_escape_htmltag($object->address1).'"></div>';
    print '  </div>';

    // Address Line 2
    print '  <div class="dc-field">';
    print '    <div class="dc-field-label">'.$langs->trans('AddressLine2').'</div>';
    print '    <div class="dc-field-value"><input type="text" name="address2" value="'.dol_escape_htmltag($object->address2).'"></div>';
    print '  </div>';

    // City
    print '  <div class="dc-field">';
    print '    <div class="dc-field-label">'.$langs->trans('City').'</div>';
    print '    <div class="dc-field-value"><input type="text" name="city" value="'.dol_escape_htmltag($object->city).'"></div>';
    print '  </div>';

    // State
    print '  <div class="dc-field">';
    print '    <div class="dc-field-label">'.$langs->trans('State').'</div>';
    print '    <div class="dc-field-value"><input type="text" name="state" value="'.dol_escape_htmltag($object->state).'"></div>';
    print '  </div>';
}

print '  </div>';
print '</div>';

print '</div>';// dc-grid row1

/* ── ROW 2: Notes (full width) ── */
print '<div class="dc-card" style="margin-bottom:20px;">';
print '  <div class="dc-card-header">';
print '    <div class="dc-card-header-icon purple"><i class="fa fa-sticky-note"></i></div>';
print '    <span class="dc-card-title">'.$langs->trans('Notes').'</span>';
print '  </div>';
print '  <div class="dc-card-body">';
print '  <div class="dc-field" style="flex-direction:column;gap:8px;">';
print '    <div class="dc-field-value" style="width:100%;">';
if ($isCreate || $isEdit) {
    print '<textarea name="note" rows="4" style="min-height:100px;">'.dol_escape_htmltag($object->note).'</textarea>';
} else {
    if (!empty($object->note)) {
        print '<div style="font-size:13.5px;color:#2d3748;line-height:1.7;">'.nl2br(dol_escape_htmltag($object->note)).'</div>';
    } else {
        print '<span style="color:#c4c9d8;">&mdash;</span>';
    }
}
print '    </div></div>';
print '  </div>';
print '</div>';

/* ── BOTTOM ACTION BAR ── */
if ($isCreate || $isEdit) {
    print '<div class="dc-action-bar">';
    print '<a class="dc-btn dc-btn-ghost dc-action-bar-left" href="'.dol_buildpath('/flotte/vendor_list.php', 1).'"><i class="fa fa-arrow-left"></i> '.$langs->trans('BackToList').'</a>';
    print '<a class="dc-btn dc-btn-ghost" href="'.($id > 0 ? $_SERVER['PHP_SELF'].'?id='.$id : dol_buildpath('/flotte/vendor_list.php', 1)).'"><i class="fa fa-times"></i> '.$langs->trans('Cancel').'</a>';
    print '<button type="submit" class="dc-btn dc-btn-primary"><i class="fa fa-check"></i> '.($isCreate ? $langs->trans('Create') : $langs->trans('Save')).'</button>';
    print '</div>';
    print '</form>';
} elseif ($id > 0) {
    print '<div class="dc-action-bar">';
    print '<a class="dc-btn dc-btn-ghost dc-action-bar-left" href="'.dol_buildpath('/flotte/vendor_list.php', 1).'"><i class="fa fa-arrow-left"></i> '.$langs->trans('BackToList').'</a>';
    print '<a class="dc-btn dc-btn-ghost" href="'.$_SERVER['PHP_SELF'].'?id='.$id.'&action=edit"><i class="fa fa-pen"></i> '.$langs->trans('Modify').'</a>';
    if ($user->rights->flotte->delete) {
        print '<a class="dc-btn dc-btn-danger" href="'.$_SERVER['PHP_SELF'].'?id='.$id.'&action=delete"><i class="fa fa-trash"></i> '.$langs->trans('Delete').'</a>';
    }
    print '</div>';
}

print '</div>';// dc-page

// End of page
llxFooter();
$db->close();
?>
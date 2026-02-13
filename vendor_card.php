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
$help_url = '';

llxHeader('', $title, $help_url);

// Add JavaScript for auto-fill functionality
?>
<script type="text/javascript">
jQuery(document).ready(function() {
    // When Third Party is selected, auto-fill the fields
    jQuery('#fk_soc').change(function() {
        var socid = jQuery(this).val();
        
        if (socid > 0) {
            // Fetch Third Party data via AJAX
            jQuery.ajax({
                url: '<?php echo $_SERVER['PHP_SELF']; ?>',
                type: 'GET',
                data: {
                    action: 'fetch_thirdparty',
                    fk_soc: socid
                },
                dataType: 'json',
                success: function(data) {
                    // Auto-fill the form fields
                    jQuery('input[name="name"]').val(data.name);
                    jQuery('input[name="phone"]').val(data.phone);
                    jQuery('input[name="email"]').val(data.email);
                    jQuery('input[name="website"]').val(data.website);
                    jQuery('input[name="address1"]').val(data.address1);
                    jQuery('input[name="address2"]').val(data.address2);
                    jQuery('input[name="city"]').val(data.city);
                    jQuery('input[name="state"]').val(data.state);
                },
                error: function() {
                    console.log('Error fetching Third Party data');
                }
            });
        }
    });
});
</script>
<?php

// Confirmation to delete
if ($action == 'delete') {
    $formconfirm = $form->formconfirm($_SERVER['PHP_SELF'].'?id='.$id, $langs->trans('DeleteVendor'), $langs->trans('ConfirmDeleteVendor'), 'confirm_delete', '', 0, 1);
    print $formconfirm;
}

// Display page header
if ($id > 0) {
    $head = array();
    $head[0][0] = $_SERVER['PHP_SELF'].'?id='.$id;
    $head[0][1] = $langs->trans('Card');
    $head[0][2] = 'card';
    
    dol_fiche_head($head, 'card', $langs->trans('Vendor').' : '.$object->ref, -1, 'flotte@flotte');
} else {
    dol_fiche_head(array(), '', $langs->trans('NewVendor'), -1, 'flotte@flotte');
}

// Form for create/edit
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
print load_fiche_titre($langs->trans('Vendor Information'), '', '');
print '<table class="border tableforfield" width="100%">';

// Reference
print '<tr><td class="titlefield">' . $langs->trans('Reference') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    print $form->textwithpicto('<input type="text" class="flat" name="ref" value="' . dol_escape_htmltag($object->ref) . '" size="20" readonly>', $langs->trans('AutoGenerated'));
} else {
    print dol_escape_htmltag($object->ref);
}
print '</td></tr>';

// Third Party Selection (only for create, read-only for edit/view)
print '<tr><td class="fieldrequired">' . $langs->trans('ThirdParty') . '</td><td>';
if ($action == 'create') {
    // Use Dolibarr's form helper to select a third party
    // Filter to show only suppliers (fournisseur=1)
    print $formcompany->select_company($object->fk_soc, 'fk_soc', 's.fournisseur = 1', 'SelectThirdParty', 0, 0, array(), 0, 'minwidth300');
    print ' <a href="'.DOL_URL_ROOT.'/societe/card.php?action=create&type=f&backtopage='.urlencode($_SERVER['PHP_SELF'].'?action=create').'" target="_blank">';
    print img_picto($langs->trans("CreateThirdParty"), 'add', 'class="paddingleft"');
    print '</a>';
} else {
    if ($thirdparty->id > 0) {
        print $thirdparty->getNomUrl(1);
    } else {
        print '&nbsp;';
    }
}
print '</td></tr>';

// Name
print '<tr><td class="fieldrequired">' . $langs->trans('Name') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    print '<input type="text" class="flat minwidth300" name="name" value="' . dol_escape_htmltag($object->name) . '" size="40">';
} else {
    print dol_escape_htmltag($object->name);
}
print '</td></tr>';

// Phone
print '<tr><td>' . $langs->trans('Phone') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    print '<input type="text" class="flat minwidth200" name="phone" value="' . dol_escape_htmltag($object->phone) . '" size="20">';
} else {
    print dol_print_phone($object->phone);
}
print '</td></tr>';

// Email
print '<tr><td>' . $langs->trans('Email') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    print '<input type="text" class="flat minwidth300" name="email" value="' . dol_escape_htmltag($object->email) . '" size="40">';
} else {
    print dol_print_email($object->email);
}
print '</td></tr>';

// Website
print '<tr><td>' . $langs->trans('Website') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    print '<input type="text" class="flat minwidth300" name="website" value="' . dol_escape_htmltag($object->website) . '" size="40">';
} else {
    if (!empty($object->website)) {
        print '<a href="' . (strpos($object->website, 'http') === 0 ? $object->website : 'http://' . $object->website) . '" target="_blank">' . dol_escape_htmltag($object->website) . '</a>';
    } else {
        print '&nbsp;';
    }
}
print '</td></tr>';

// Type (Fleet-specific)
print '<tr><td>' . $langs->trans('Type') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    $typearray = array(
        '' => $langs->trans('SelectType'),
        'Parts' => $langs->trans('Parts'),
        'Fuel' => $langs->trans('Fuel'),
        'Maintenance' => $langs->trans('Maintenance'),
        'Insurance' => $langs->trans('Insurance'),
        'Service' => $langs->trans('Service'),
        'Other' => $langs->trans('Other')
    );
    print $form->selectarray('type', $typearray, $object->type, 0, 0, 0, '', 0, 0, 0, '', 'minwidth200');
} else {
    print dol_escape_htmltag($object->type);
}
print '</td></tr>';

print '</table>';

print '</div>';
print '<div class="fichehalfright">';

// Address Information
print load_fiche_titre($langs->trans('Address Information'), '', '');
print '<table class="border tableforfield" width="100%">';

// Address Line 1
print '<tr><td class="titlefield">' . $langs->trans('Address Line1') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    print '<input type="text" class="flat minwidth300" name="address1" value="' . dol_escape_htmltag($object->address1) . '" size="40">';
} else {
    print dol_escape_htmltag($object->address1);
}
print '</td></tr>';

// Address Line 2
print '<tr><td>' . $langs->trans('Address Line2') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    print '<input type="text" class="flat minwidth300" name="address2" value="' . dol_escape_htmltag($object->address2) . '" size="40">';
} else {
    print dol_escape_htmltag($object->address2);
}
print '</td></tr>';

// City
print '<tr><td>' . $langs->trans('City') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    print '<input type="text" class="flat minwidth200" name="city" value="' . dol_escape_htmltag($object->city) . '" size="20">';
} else {
    print dol_escape_htmltag($object->city);
}
print '</td></tr>';

// State
print '<tr><td>' . $langs->trans('State') . '</td><td>';
if ($action == 'create' || $action == 'edit') {
    print '<input type="text" class="flat minwidth200" name="state" value="' . dol_escape_htmltag($object->state) . '" size="20">';
} else {
    print dol_escape_htmltag($object->state);
}
print '</td></tr>';

print '</table>';

print '</div>';
print '</div>';

print '<div class="clearboth"></div>';

// Notes (Fleet-specific)
print load_fiche_titre($langs->trans('Notes'), '', '');
print '<table class="border tableforfield" width="100%">';
print '<tr><td>';
if ($action == 'create' || $action == 'edit') {
    print '<textarea name="note" class="flat" rows="4" cols="80">' . dol_escape_htmltag($object->note) . '</textarea>';
} else {
    print nl2br(dol_escape_htmltag($object->note));
}
print '</td></tr>';
print '</table>';

// Add button styling CSS
print '<style>
    .flotte-btn {
        display: inline-block;
        min-width: 120px;
        height: 34px;
        line-height: 34px;
        padding: 0 20px;
        text-align: center;
        box-sizing: border-box;
        font-size: 13px;
        border-radius: 3px;
        cursor: pointer;
        text-decoration: none;
        vertical-align: middle;
        margin: 0 4px;
    }
    /* Submit / Create / Save — solid blue fill */
    input.flotte-btn {
        background: #3c6d9f;
        border: 1px solid #2e5a85;
        color: #fff;
    }
    input.flotte-btn:hover {
        background: #2e5a85;
    }
    /* Modify — solid blue fill (same weight as submit) */
    a.flotte-btn-primary {
        background: #3c6d9f;
        border: 1px solid #2e5a85;
        color: #fff;
    }
    a.flotte-btn-primary:hover {
        background: #2e5a85;
        color: #fff;
    }
    /* Cancel — blue outline, white fill */
    a.flotte-btn-cancel {
        background: #fff;
        border: 1px solid #3c6d9f;
        color: #3c6d9f;
    }
    a.flotte-btn-cancel:hover {
        background: #eef3f8;
        color: #2e5a85;
    }
    /* Back to List — blue outline, white fill */
    a.flotte-btn-back {
        background: #fff;
        border: 1px solid #3c6d9f;
        color: #3c6d9f;
    }
    a.flotte-btn-back:hover {
        background: #eef3f8;
        color: #2e5a85;
    }
    /* Delete — red fill */
    a.flotte-btn-delete {
        background: #c9302c;
        border: 1px solid #ac2925;
        color: #fff;
    }
    a.flotte-btn-delete:hover {
        background: #ac2925;
        color: #fff;
    }
</style>'."\n";

// Form buttons
if ($action == 'create' || $action == 'edit') {
    print '<div class="center" style="margin-top: 20px; margin-bottom: 10px;">';
    print '<input type="submit" class="flotte-btn" value="' . ($action == 'create' ? $langs->trans('Create') : $langs->trans('Save')) . '">';
    print '<a class="flotte-btn flotte-btn-cancel" href="' . ($id > 0 ? $_SERVER['PHP_SELF'] . '?id=' . $id : 'vendor_list.php') . '">' . $langs->trans('Cancel') . '</a>';
    print '<a class="flotte-btn flotte-btn-back" href="' . dol_buildpath('/flotte/vendor_list.php', 1) . '">' . $langs->trans('BackToList') . '</a>';
    print '</div>';
    print '</form>';
} elseif ($id > 0) {
    // Action buttons
    print '<div class="center" style="margin-top: 20px; margin-bottom: 10px;">';
    print '<a class="flotte-btn flotte-btn-primary" href="' . $_SERVER['PHP_SELF'] . '?id=' . $id . '&action=edit">' . $langs->trans('Modify') . '</a>';
    print '<a class="flotte-btn flotte-btn-delete" href="' . $_SERVER['PHP_SELF'] . '?id=' . $id . '&action=delete">' . $langs->trans('Delete') . '</a>';
    print '<a class="flotte-btn flotte-btn-back" href="' . dol_buildpath('/flotte/vendor_list.php', 1) . '">' . $langs->trans('BackToList') . '</a>';
    print '</div>';
}

dol_fiche_end();

// End of page
llxFooter();
$db->close();
?>
<?php
/**
 * Data Migration Script for Fleet Vendors
 * 
 * This script helps migrate existing vendors to link them with Third Parties
 * It tries to match existing vendors to third parties by name/email
 * 
 * Usage: Run this from command line or browser AFTER running the SQL migration
 * php migrate_vendors_to_thirdparties.php
 */

// Load Dolibarr environment
$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
    $res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
if (!$res && file_exists("../main.inc.php"))      { $res = @include "../main.inc.php"; }
if (!$res && file_exists("../../main.inc.php"))   { $res = @include "../../main.inc.php"; }
if (!$res && file_exists("../../../main.inc.php")){ $res = @include "../../../main.inc.php"; }
if (!$res) { die("Include of main fails"); }

require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';

echo "<h2>Fleet Vendor to Third Party Migration</h2>\n";
echo "<pre>\n";

// Get all vendors without a linked third party
$sql = "SELECT rowid, ref, name, email, phone, address1, city, type";
$sql .= " FROM ".MAIN_DB_PREFIX."flotte_vendor";
$sql .= " WHERE (fk_soc IS NULL OR fk_soc = 0)";
$sql .= " AND entity = ".$conf->entity;

$resql = $db->query($sql);

if (!$resql) {
    echo "ERROR: Could not retrieve vendors: " . $db->lasterror() . "\n";
    exit;
}

$num = $db->num_rows($resql);
echo "Found $num vendors without linked Third Party\n\n";

$matched = 0;
$created = 0;
$skipped = 0;
$errors = 0;

while ($obj = $db->fetch_object($resql)) {
    echo "Processing Vendor: {$obj->ref} - {$obj->name}\n";
    
    $matched_soc_id = null;
    
    // Try to find matching third party by name
    if (!empty($obj->name)) {
        $sql_search = "SELECT rowid FROM ".MAIN_DB_PREFIX."societe";
        $sql_search .= " WHERE nom = '".$db->escape($obj->name)."'";
        $sql_search .= " AND entity IN (0, ".$conf->entity.")";
        $sql_search .= " AND fournisseur = 1"; // Only suppliers
        $sql_search .= " LIMIT 1";
        
        $res_search = $db->query($sql_search);
        if ($res_search && $db->num_rows($res_search) > 0) {
            $obj_soc = $db->fetch_object($res_search);
            $matched_soc_id = $obj_soc->rowid;
            echo "  ✓ Found matching Third Party by name (ID: $matched_soc_id)\n";
        }
    }
    
    // If not found by name, try by email
    if (!$matched_soc_id && !empty($obj->email)) {
        $sql_search = "SELECT rowid FROM ".MAIN_DB_PREFIX."societe";
        $sql_search .= " WHERE email = '".$db->escape($obj->email)."'";
        $sql_search .= " AND entity IN (0, ".$conf->entity.")";
        $sql_search .= " AND fournisseur = 1";
        $sql_search .= " LIMIT 1";
        
        $res_search = $db->query($sql_search);
        if ($res_search && $db->num_rows($res_search) > 0) {
            $obj_soc = $db->fetch_object($res_search);
            $matched_soc_id = $obj_soc->rowid;
            echo "  ✓ Found matching Third Party by email (ID: $matched_soc_id)\n";
        }
    }
    
    // If still not found, create a new third party
    if (!$matched_soc_id && !empty($obj->name)) {
        $societe = new Societe($db);
        $societe->name = $obj->name;
        $societe->email = $obj->email;
        $societe->phone = $obj->phone;
        $societe->address = $obj->address1;
        $societe->town = $obj->city;
        $societe->fournisseur = 1; // Mark as supplier
        $societe->client = 0;
        
        $result = $societe->create($user);
        
        if ($result > 0) {
            $matched_soc_id = $result;
            echo "  ✓ Created new Third Party (ID: $matched_soc_id)\n";
            $created++;
        } else {
            echo "  ✗ Error creating Third Party: " . $societe->error . "\n";
            $errors++;
            continue;
        }
    }
    
    // Update vendor with the linked third party
    if ($matched_soc_id) {
        $sql_update = "UPDATE ".MAIN_DB_PREFIX."flotte_vendor";
        $sql_update .= " SET fk_soc = ".((int) $matched_soc_id);
        $sql_update .= " WHERE rowid = ".((int) $obj->rowid);
        
        $res_update = $db->query($sql_update);
        
        if ($res_update) {
            echo "  ✓ Vendor linked to Third Party\n";
            $matched++;
        } else {
            echo "  ✗ Error updating vendor: " . $db->lasterror() . "\n";
            $errors++;
        }
    } else {
        echo "  ⚠ Skipped - could not find or create Third Party\n";
        $skipped++;
    }
    
    echo "\n";
}

echo "\n=== Migration Summary ===\n";
echo "Total vendors processed: $num\n";
echo "Successfully linked: $matched\n";
echo "New Third Parties created: $created\n";
echo "Skipped: $skipped\n";
echo "Errors: $errors\n";

echo "\n=== Next Steps ===\n";
echo "1. Review the migration results above\n";
echo "2. Manually check any skipped vendors in the database\n";
echo "3. Consider removing old redundant columns from llx_flotte_vendor table\n";
echo "   (See commented lines in migration_add_fk_soc.sql)\n";
echo "4. Test the vendor_card.php interface to ensure everything works\n";

echo "</pre>\n";

$db->close();
?>

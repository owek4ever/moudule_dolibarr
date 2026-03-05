<?php
/* ─────────────────────────────────────────────────────
   booking_invoice.php
   Creates a Dolibarr invoice (customer or vendor/supplier)
   from one or more fleet bookings, then redirects to it.
───────────────────────────────────────────────────────── */

// Load Dolibarr environment
$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
    $res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1; $j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) { $i--; $j--; }
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1))."/main.inc.php"))            { $res = @include substr($tmp, 0, ($i + 1))."/main.inc.php"; }
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php"))   { $res = @include dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php"; }
if (!$res && file_exists("../main.inc.php"))       { $res = @include "../main.inc.php"; }
if (!$res && file_exists("../../main.inc.php"))    { $res = @include "../../main.inc.php"; }
if (!$res && file_exists("../../../main.inc.php")) { $res = @include "../../../main.inc.php"; }
if (!$res) { die("Include of main fails"); }

// Security
restrictedArea($user, 'flotte');
if (!$user->rights->flotte->write) {
    accessforbidden();
}

// Verify token
if (!empty($conf->global->MAIN_SECURITY_CSRF_WITH_TOKEN)) {
    if (empty($_POST['token']) || $_POST['token'] != $_SESSION['newtoken']) {
        setEventMessages($langs->trans("ErrorBadToken"), null, 'errors');
        header("Location: ".dol_buildpath('/flotte/booking_list.php', 1));
        exit;
    }
}

// Get params
$invoice_type = GETPOST('invoice_type', 'alpha'); // 'customer' or 'vendor'
$ids_raw      = GETPOST('booking_ids', 'alpha');

$booking_ids = array();
foreach (explode(',', $ids_raw) as $bid) {
    $bid = (int) trim($bid);
    if ($bid > 0) $booking_ids[] = $bid;
}

$listUrl = dol_buildpath('/flotte/booking_list.php', 1);

if (empty($booking_ids)) {
    setEventMessages('No bookings selected.', null, 'errors');
    header("Location: ".$listUrl);
    exit;
}
if (!in_array($invoice_type, array('customer', 'vendor'))) {
    setEventMessages('Invalid invoice type.', null, 'errors');
    header("Location: ".$listUrl);
    exit;
}

// ── Ensure invoiced flag columns exist ───────────────────
$db->query("ALTER TABLE ".MAIN_DB_PREFIX."flotte_booking ADD COLUMN IF NOT EXISTS invoiced_customer TINYINT(1) NOT NULL DEFAULT 0");
$db->query("ALTER TABLE ".MAIN_DB_PREFIX."flotte_booking ADD COLUMN IF NOT EXISTS invoiced_vendor TINYINT(1) NOT NULL DEFAULT 0");

// ── Load booking rows ──────────────────────────────────
$placeholders = implode(',', array_fill(0, count($booking_ids), '?'));
$sql = "SELECT t.rowid, t.ref, t.booking_date, t.departure_address, t.arriving_address,
               t.selling_amount,
               IFNULL(t.selling_amount_ttc, t.selling_amount) as selling_amount_ttc,
               IFNULL(t.selling_qty, 1)  as selling_qty,
               IFNULL(t.selling_price, t.selling_amount) as selling_price,
               IFNULL(t.selling_unit, '') as selling_unit,
               t.buying_amount,
               IFNULL(t.buying_amount_ttc, t.buying_amount) as buying_amount_ttc,
               IFNULL(t.buying_qty, 1)   as buying_qty,
               IFNULL(t.buying_price, t.buying_amount)  as buying_price,
               IFNULL(t.buying_unit, '')  as buying_unit,
               t.fk_customer, t.fk_vendor,
               IFNULL(t.selling_tax_rate, 0) as selling_tax_rate,
               IFNULL(t.buying_tax_rate, 0)  as buying_tax_rate,
               c.firstname as cust_firstname, c.lastname as cust_lastname, c.company_name as cust_company,
               vn.name as vendor_name
        FROM ".MAIN_DB_PREFIX."flotte_booking as t
        LEFT JOIN ".MAIN_DB_PREFIX."flotte_customer as c  ON t.fk_customer = c.rowid
        LEFT JOIN ".MAIN_DB_PREFIX."flotte_vendor   as vn ON t.fk_vendor   = vn.rowid
        WHERE t.rowid IN (".implode(',', array_map('intval', $booking_ids)).")
          AND t.entity IN (".getEntity('flotte').")";

$resql = $db->query($sql);
if (!$resql) {
    setEventMessages('DB error: '.$db->lasterror(), null, 'errors');
    header("Location: ".$listUrl);
    exit;
}

$bookings = array();
while ($row = $db->fetch_object($resql)) {
    $bookings[] = $row;
}

if (empty($bookings)) {
    setEventMessages('No bookings found.', null, 'errors');
    header("Location: ".$listUrl);
    exit;
}

// ── Determine socid (must be consistent for all bookings) ──
$socid = 0;
foreach ($bookings as $b) {
    $soc = ($invoice_type === 'customer') ? (int)$b->fk_customer : (int)$b->fk_vendor;
    if ($soc <= 0) {
        $party = ($invoice_type === 'customer') ? 'customer' : 'vendor';
        setEventMessages('Booking '.$b->ref.' has no '.$party.' assigned.', null, 'errors');
        header("Location: ".$listUrl);
        exit;
    }
    if ($socid === 0) {
        $socid = $soc;
    }
}

// ── Create the invoice ────────────────────────────────
$db->begin();

if ($invoice_type === 'customer') {
    // ── Customer Invoice ──
    require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';

    $facture = new Facture($db);
    $facture->socid       = $socid;
    $facture->type        = Facture::TYPE_STANDARD;
    $facture->date        = dol_now();
    $facture->date_lim_reglement = dol_time_plus_duree(dol_now(), 30, 'd');
    $facture->note_public  = 'Bookings: '.implode(', ', array_column($bookings, 'ref'));
    $facture->note_private = 'FLOTTE_BOOKING_IDS:'.implode(',', array_map('intval', $booking_ids));

    $facid = $facture->create($user);
    if ($facid <= 0) {
        $db->rollback();
        setEventMessages('Error creating invoice: '.$facture->error, null, 'errors');
        header("Location: ".$listUrl);
        exit;
    }

    // Force note_private into DB — Facture::create() does not always persist it
    $db->query("UPDATE ".MAIN_DB_PREFIX."facture SET note_private = '".$db->escape('FLOTTE_BOOKING_IDS:'.implode(',', array_map('intval', $booking_ids)))."' WHERE rowid = ".(int)$facid);

    // Add one line per booking
    foreach ($bookings as $b) {
        $label = $b->ref;
        if ($b->departure_address || $b->arriving_address) {
            $label .= ' — '.trim($b->departure_address.' → '.$b->arriving_address);
        }
        $qty   = 1;
        $pu_ht = (float)$b->selling_amount;
        $tva   = (float)$b->selling_tax_rate;

        $result = $facture->addline(
            $label,  // desc
            $pu_ht,  // pu_ht
            $qty,    // qty
            $tva,    // tva_tx
            0,       // localtax1_tx
            0,       // localtax2_tx
            0,       // fk_product
            0        // remise_percent
        );

        if ($result < 0) {
            $db->rollback();
            setEventMessages('Error adding line for '.$b->ref.': '.$facture->error, null, 'errors');
            header("Location: ".$listUrl);
            exit;
        }
    }

    $db->commit();
    header("Location: ".DOL_URL_ROOT."/compta/facture/card.php?facid=".$facid);
    exit;

} else {
    // ── Vendor / Supplier Invoice ──
    require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.facture.class.php';

    $facture = new FactureFournisseur($db);
    $facture->socid               = $socid;
    $facture->type                = FactureFournisseur::TYPE_STANDARD;
    $facture->date                = dol_now();
    $facture->date_lim_reglement  = dol_time_plus_duree(dol_now(), 30, 'd');
    $facture->ref_supplier        = 'DRAFT-'.dol_now(); // temporary unique value; user can update it on the invoice
    $facture->note_public         = 'Bookings: '.implode(', ', array_column($bookings, 'ref'));
    $facture->note_private        = 'FLOTTE_VENDOR_BOOKING_IDS:'.implode(',', array_map('intval', $booking_ids));

    $facid = $facture->create($user);
    if ($facid <= 0) {
        $db->rollback();
        setEventMessages('Error creating supplier invoice: '.$facture->error, null, 'errors');
        header("Location: ".$listUrl);
        exit;
    }

    // Force note_private into DB — FactureFournisseur::create() does not always persist it
    $db->query("UPDATE ".MAIN_DB_PREFIX."facture_fourn SET note_private = '".$db->escape('FLOTTE_VENDOR_BOOKING_IDS:'.implode(',', array_map('intval', $booking_ids)))."' WHERE rowid = ".(int)$facid);

    // Add one line per booking
    foreach ($bookings as $b) {
        $label = $b->ref;
        if ($b->departure_address || $b->arriving_address) {
            $label .= ' — '.trim($b->departure_address.' → '.$b->arriving_address);
        }
        $qty   = (float)$b->buying_qty   > 0 ? (float)$b->buying_qty   : 1;
        $pu_ht = (float)$b->buying_price  > 0 ? (float)$b->buying_price  : (float)$b->buying_amount;
        $tva   = (float)$b->buying_tax_rate;

        // FactureFournisseur::addline(desc, pu_ht, vatrate, localtax1rate, localtax2rate, qty, extralabels, date_start, date_end, ventil, info_bits, ...)
        $result = $facture->addline(
            $label,  // desc
            $pu_ht,  // pu_ht
            $tva,    // vatrate
            0,       // localtax1rate
            0,       // localtax2rate
            $qty,    // qty
            '',    // extralabels (legacy, must be empty string — NOT product_type)
            '',    // date_start
            '',    // date_end
            0,       // ventil
            0        // info_bits
        );

        if ($result < 0) {
            $db->rollback();
            setEventMessages('Error adding line for '.$b->ref.': '.$facture->error, null, 'errors');
            header("Location: ".$listUrl);
            exit;
        }
    }

    $db->commit();
    header("Location: ".DOL_URL_ROOT."/fourn/facture/card.php?facid=".$facid);
    exit;
}
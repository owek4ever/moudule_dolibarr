<?php
/* Journey Management PDF View - flotte module
 * Redesigned UI — Clean, Professional A4 Document format
 */

// Load Dolibarr environment
$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
    $res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1; $j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) { $i--; $j--; }
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1))."/main.inc.php"))          { $res = @include substr($tmp, 0, ($i + 1))."/main.inc.php"; }
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php")) { $res = @include dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php"; }
if (!$res && file_exists("../main.inc.php"))       { $res = @include "../main.inc.php"; }
if (!$res && file_exists("../../main.inc.php"))    { $res = @include "../../main.inc.php"; }
if (!$res && file_exists("../../../main.inc.php")) { $res = @include "../../../main.inc.php"; }
if (!$res) { die("Include of main fails"); }

require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';

$langs->loadLangs(array("flotte@flotte", "other"));
restrictedArea($user, 'flotte');

$id = GETPOST('id', 'int');
if ($id <= 0) { accessforbidden(); }

/* ── Load booking ──────────────────────────────────────────────────────── */
$sql = "SELECT b.*,
        v.ref        AS v_ref,
        v.maker      AS v_maker,
        v.model      AS v_model,
        d.firstname  AS d_firstname,
        d.lastname   AS d_lastname,
        d.phone      AS d_phone,
        s.name          AS s_name,
        c.nom           AS c_name
        FROM ".MAIN_DB_PREFIX."flotte_booking b
        LEFT JOIN ".MAIN_DB_PREFIX."flotte_vehicle v ON v.rowid = b.fk_vehicle
        LEFT JOIN ".MAIN_DB_PREFIX."socpeople       d ON d.rowid = b.fk_driver
        LEFT JOIN ".MAIN_DB_PREFIX."flotte_vendor   s ON s.rowid = b.fk_vendor
        LEFT JOIN ".MAIN_DB_PREFIX."societe         c ON c.rowid = b.fk_customer
        WHERE b.rowid = ".((int)$id);

$resql = $db->query($sql);
if (!$resql) {
    header("HTTP/1.0 500 Internal Server Error");
    print "Erreur SQL : " . dol_escape_htmltag($db->lasterror());
    exit;
}
if (!$db->num_rows($resql)) {
    header("HTTP/1.0 404 Not Found");
    print "Booking introuvable (id=" . (int)$id . ")";
    exit;
}
$o = $db->fetch_object($resql);

/* ── Load flotte_driver row — used for name fallback AND document fields ──
 * Always query flotte_driver when fk_driver is set.  The row may hold
 * d_image, d_lic_image, d_documents even when the booking row itself does
 * not (fields left empty at booking creation time).                         */
$_flotte_driver_obj = null;
if (!empty($o->fk_driver)) {
    // Try by rowid first, then by fk_socpeople link
    foreach (array(
        "SELECT * FROM ".MAIN_DB_PREFIX."flotte_driver WHERE rowid = ".((int)$o->fk_driver),
        "SELECT * FROM ".MAIN_DB_PREFIX."flotte_driver WHERE fk_socpeople = ".((int)$o->fk_driver),
    ) as $_fdsql) {
        $_fdres = @$db->query($_fdsql);
        if ($_fdres && $db->num_rows($_fdres)) {
            $_flotte_driver_obj = $db->fetch_object($_fdres);
            break;
        }
    }
    if ($_flotte_driver_obj) {
        $dobj = $_flotte_driver_obj;
        // Populate name if missing from the socpeople JOIN
        if (empty($o->d_firstname) && empty($o->d_lastname)) {
            $o->d_firstname = isset($dobj->firstname) ? $dobj->firstname
                            : (isset($dobj->prenom)   ? $dobj->prenom
                            : (isset($dobj->nom)       ? $dobj->nom : null));
            $o->d_lastname  = isset($dobj->lastname)  ? $dobj->lastname
                            : (isset($dobj->name)      ? $dobj->name : null);
            $o->d_phone     = isset($dobj->phone)     ? $dobj->phone
                            : (isset($dobj->tel)       ? $dobj->tel
                            : (isset($dobj->mobile)    ? $dobj->mobile : null));
        }
        // Pull document fields from flotte_driver if the booking row has none
        if (empty($o->d_image)     && isset($dobj->d_image))     $o->d_image     = $dobj->d_image;
        if (empty($o->d_lic_image) && isset($dobj->d_lic_image)) $o->d_lic_image = $dobj->d_lic_image;
        if (empty($o->d_documents) && isset($dobj->d_documents)) $o->d_documents = $dobj->d_documents;
        // Also handle alternate column names modules sometimes use
        if (empty($o->d_image)     && isset($dobj->cin_image))   $o->d_image     = $dobj->cin_image;
        if (empty($o->d_lic_image) && isset($dobj->license_image)) $o->d_lic_image = $dobj->license_image;
        if (empty($o->d_lic_image) && isset($dobj->permis_image))  $o->d_lic_image = $dobj->permis_image;
    }
}

/* ── Resolve the socpeople ID to use for document lookups ──────────────────
 * fk_driver on the booking points to flotte_driver.rowid, but documents
 * are attached to the socpeople (contact) record via flotte_driver.fk_socpeople.
 * Use that ID for both the ecm_files query and the filesystem directory scan. */
$_doc_lookup_socpeople_id = (int)$o->fk_driver;   // safe default
if (!empty($_flotte_driver_obj) && !empty($_flotte_driver_obj->fk_socpeople)) {
    $_doc_lookup_socpeople_id = (int)$_flotte_driver_obj->fk_socpeople;
}

/* ── Helpers ───────────────────────────────────────────────────────────── */
function jmDate($val, $withTime = false) {
    if (empty($val) || $val === '0000-00-00' || $val === '0000-00-00 00:00:00') return '—';
    $ts = strtotime($val);
    if (!$ts) return htmlspecialchars($val);
    return $withTime ? date('d/m/Y H:i', $ts) : date('d/m/Y', $ts);
}
function jmVal($v, $fallback = '—') {
    return (!empty($v) && $v !== '0') ? htmlspecialchars($v) : $fallback;
}

/* ── Load driver's linked files from Dolibarr ecm_files table ─────────── */
$driver_ecm_files   = array();
$_debug_ecm_rows    = array();   // ALL ecm_files rows for this driver (any type)
$_debug_ecm_sql_err = '';
if (!empty($o->fk_driver)) {
    // Narrow query (original)
    $fsql = "SELECT rowid, filename, filepath, label, description, src_object_type"
          . " FROM ".MAIN_DB_PREFIX."ecm_files"
          . " WHERE src_object_type IN ('contact', 'socpeople')"
          . "   AND src_object_id = ".$_doc_lookup_socpeople_id
          . " ORDER BY position ASC, rowid ASC";
    $fres = $db->query($fsql);
    if ($fres) {
        while ($fobj = $db->fetch_object($fres)) {
            $driver_ecm_files[] = array(
                'filename' => $fobj->filename,
                'filepath' => $fobj->filepath,
                'label'    => !empty($fobj->label) ? $fobj->label : $fobj->filename,
            );
        }
    } else {
        $_debug_ecm_sql_err = $db->lasterror();
    }

    // Wide query — find ALL ecm_files rows for this src_object_id regardless of type
    $fsql2 = "SELECT rowid, filename, filepath, src_object_type, src_object_id, label"
           . " FROM ".MAIN_DB_PREFIX."ecm_files"
           . " WHERE src_object_id = ".$_doc_lookup_socpeople_id
           . " ORDER BY rowid ASC LIMIT 50";
    $fres2 = @$db->query($fsql2);
    if ($fres2) {
        while ($fobj2 = $db->fetch_object($fres2)) {
            $_debug_ecm_rows[] = (array)$fobj2;
        }
    }
}

/* ── Resolve full disk path for an ecm_files record ───────────────────── */
function jmEcmFullPath($filepath, $filename) {
    global $conf;
    $fp = trim($filepath, '/');
    $candidates = array(
        DOL_DATA_ROOT.'/'.$fp.'/'.$filename,
        DOL_DATA_ROOT.'/'.$conf->entity.'/'.$fp.'/'.$filename,
    );
    foreach ($candidates as $p) {
        if (file_exists($p)) return $p;
    }
    return '';
}

/* ── Render an ECM image as a base64 <img> tag ─────────────────────────── */
function jmEcmImgTag($filepath, $filename, $alt = '', $style = '') {
    $path = jmEcmFullPath($filepath, $filename);
    if (empty($path)) return '';
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    if (!in_array($ext, array('jpg','jpeg','png','gif','webp'))) return '';
    $mime = 'image/jpeg';
    if ($ext === 'png')  $mime = 'image/png';
    if ($ext === 'gif')  $mime = 'image/gif';
    if ($ext === 'webp') $mime = 'image/webp';
    $data = base64_encode(file_get_contents($path));
    $s = $style ?: 'max-width:100%;max-height:300px;object-fit:cover;display:block; border-radius:4px;';
    return '<img src="data:'.$mime.';base64,'.$data.'" alt="'.htmlspecialchars($alt).'" style="'.$s.'">';
}

/* ── Load driver's contact files (Files From Contact) ──────────────────── */
$contact_files_images = array();
$contact_files_docs   = array();
if (!empty($o->fk_driver)) {
    $_sp_id = $_doc_lookup_socpeople_id;
    $_ent = isset($conf->entity) ? (int)$conf->entity : 1;
    $_contact_dir_candidates = array(
        // ── Most common standard Dolibarr layout ──────────────────────────
        DOL_DATA_ROOT.'/societe/contact/'.$_sp_id,
        // ── Multi-entity variants ─────────────────────────────────────────
        DOL_DATA_ROOT.'/'.$_ent.'/societe/contact/'.$_sp_id,
        // ── Via module dir_output (set in conf) ───────────────────────────
        (isset($conf->societe->dir_output) ? $conf->societe->dir_output.'/contact/'.$_sp_id : ''),
        (isset($conf->contact->dir_output) ? $conf->contact->dir_output.'/'.$_sp_id : ''),
        // ── Legacy / alternate layouts ────────────────────────────────────
        DOL_DATA_ROOT.'/contact/'.$_sp_id,
        DOL_DATA_ROOT.'/'.$_ent.'/contact/'.$_sp_id,
    );
    $_contact_dir = '';
    $_contact_dir_tried = array();
    foreach ($_contact_dir_candidates as $_c) {
        if (empty($_c)) continue;
        $_contact_dir_tried[] = $_c;
        if (is_dir($_c)) { $_contact_dir = $_c; break; }
    }
    if (!empty($_contact_dir)) {
        $_dh = opendir($_contact_dir);
        if ($_dh) {
            $_all_cf = array();
            while (($_fn = readdir($_dh)) !== false) {
                if ($_fn === '.' || $_fn === '..') continue;
                if (is_file($_contact_dir.'/'.$_fn)) $_all_cf[] = $_fn;
            }
            closedir($_dh);
            sort($_all_cf);
            $_img_exts_cf = array('jpg','jpeg','png','gif','webp');
            foreach ($_all_cf as $_fn) {
                $_ext_cf = strtolower(pathinfo($_fn, PATHINFO_EXTENSION));
                if (in_array($_ext_cf, $_img_exts_cf)) {
                    $contact_files_images[] = array('filename' => $_fn, 'path' => $_contact_dir.'/'.$_fn, 'ext' => $_ext_cf);
                } else {
                    $contact_files_docs[] = array('filename' => $_fn, 'path' => $_contact_dir.'/'.$_fn, 'ext' => $_ext_cf);
                }
            }
        }
    }
}

/* ── Merge ECM-tracked files into the contact_files arrays ─────────────────
 * $driver_ecm_files was queried from llx_ecm_files but never fed into the
 * display arrays.  Do that now so uploads made through Dolibarr's file
 * manager are always visible, regardless of filesystem path resolution.     */
$_ecm_img_exts = array('jpg','jpeg','png','gif','webp');
foreach ($driver_ecm_files as $_ecm) {
    $_ecm_fn  = $_ecm['filename'];
    $_ecm_fp  = $_ecm['filepath'];
    $_ecm_ext = strtolower(pathinfo($_ecm_fn, PATHINFO_EXTENSION));

    // Build the full disk path using the same helper used elsewhere
    $_ecm_full = jmEcmFullPath($_ecm_fp, $_ecm_fn);
    if (empty($_ecm_full)) continue;           // file not readable on disk — skip

    // Avoid duplicates already found via filesystem scan
    $_already = false;
    foreach (array_merge($contact_files_images, $contact_files_docs) as $_ex) {
        if ($_ex['filename'] === $_ecm_fn) { $_already = true; break; }
    }
    if ($_already) continue;

    $_entry = array('filename' => $_ecm_fn, 'path' => $_ecm_full, 'ext' => $_ecm_ext,
                    'label' => $_ecm['label']);
    if (in_array($_ecm_ext, $_ecm_img_exts)) {
        $contact_files_images[] = $_entry;
    } else {
        $contact_files_docs[] = $_entry;
    }
}

/* ── Render a contact image file as a base64 <img> tag ─────────────────── */
function jmContactImgTag($path, $alt = '', $style = '') {
    if (empty($path) || !file_exists($path)) return '';
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if (!in_array($ext, array('jpg','jpeg','png','gif','webp'))) return '';
    $mime = 'image/jpeg';
    if ($ext === 'png')  $mime = 'image/png';
    if ($ext === 'gif')  $mime = 'image/gif';
    if ($ext === 'webp') $mime = 'image/webp';
    $data = base64_encode(file_get_contents($path));
    $s = $style ?: 'max-width:100%;max-height:300px;object-fit:cover;display:block;border-radius:4px;';
    return '<img src="data:'.$mime.';base64,'.$data.'" alt="'.htmlspecialchars($alt).'" style="'.$s.'">';
}

// Driver upload directory (custom flotte module storage)
$driver_upload_dir = DOL_DATA_ROOT.'/flotte/driver/';

function jmDriverPath($f) {
    global $driver_upload_dir;
    if (empty($f)) return '';
    $p = $driver_upload_dir . basename($f);
    return file_exists($p) ? $p : '';
}

function jmImgTag($f, $alt = '', $style = '') {
    $path = jmDriverPath($f);
    if (empty($path)) return '';
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if (!in_array($ext, array('jpg','jpeg','png','gif','webp'))) return '';
    $mime = 'image/jpeg';
    if ($ext === 'png')  $mime = 'image/png';
    if ($ext === 'gif')  $mime = 'image/gif';
    if ($ext === 'webp') $mime = 'image/webp';
    $data = base64_encode(file_get_contents($path));
    $s = $style ?: 'max-width:100%;max-height:300px;object-fit:cover;display:block; border-radius:4px;';
    return '<img src="data:'.$mime.';base64,'.$data.'" alt="'.htmlspecialchars($alt).'" style="'.$s.'">';
}

$_img_exts   = array('jpg','jpeg','png','gif','webp');
$has_cin_img = false;
$has_lic_img = false;
$has_doc_img = false;

function jmLogo() {
    global $conf;
    $logo_path = '';
    if (!empty($conf->global->MAIN_INFO_SOCIETE_LOGO)) {
        $logo_path = DOL_DATA_ROOT.'/mycompany/logos/'.$conf->global->MAIN_INFO_SOCIETE_LOGO;
    }
    if ($logo_path && file_exists($logo_path)) {
        $ext  = strtolower(pathinfo($logo_path, PATHINFO_EXTENSION));
        $mime = ($ext === 'png') ? 'image/png' : (($ext === 'gif') ? 'image/gif' : 'image/jpeg');
        $data = base64_encode(file_get_contents($logo_path));
        return '<img src="data:'.$mime.';base64,'.$data.'" alt="Logo" style="max-height:60px;max-width:250px;object-fit:contain;">';
    }
    return '<span style="font-size:24px;font-weight:bold;color:#1e3a8a;">OPTIMA LOGISTIC S.A</span>';
}

/* ── Data preparation ──────────────────────────────────────────────────── */
$stops_list = array();
if (!empty($o->stops)) {
    $decoded = json_decode($o->stops, true);
    if (is_array($decoded)) $stops_list = $decoded;
}

$has_map = (!empty($o->dep_lat) && !empty($o->dep_lon) && !empty($o->arr_lat) && !empty($o->arr_lon));

$driver_fullname = trim(
    jmVal($o->d_firstname, '') .
    (!empty($o->d_lastname) ? ' ' . htmlspecialchars($o->d_lastname) : '')
);
if (empty($driver_fullname)) $driver_fullname = '—';

$merchandise_type = '';
if (!empty($o->note_public))  $merchandise_type = trim($o->note_public);
if (empty($merchandise_type) && !empty($o->buying_unit)) $merchandise_type = trim($o->buying_unit);

/* ── i18n ───────────────────────────────────────────────────────────────── */
$_jmLang = (substr($langs->defaultlang, 0, 2) === 'fr') ? 'fr' : 'en';

$_jmStrings = array(
    'back'                => array('fr' => 'Retour',                    'en' => 'Back'),
    'print_pdf'           => array('fr' => 'Imprimer / PDF',            'en' => 'Print / PDF'),
    'subtitle'            => array('fr' => 'ORDRE DE TRANSPORT',        'en' => 'TRANSPORT ORDER'),
    'stat_vehicle'        => array('fr' => 'Véhicule',                  'en' => 'Vehicle'),
    'stat_driver'         => array('fr' => 'Chauffeur',                 'en' => 'Driver'),
    'stat_distance'       => array('fr' => 'Distance estimée',          'en' => 'Est. Distance'),
    'customer'            => array('fr' => 'Client',                    'en' => 'Customer'),
    'vendor'              => array('fr' => 'Fournisseur',               'en' => 'Vendor'),
    'merchandise'         => array('fr' => 'Marchandise',               'en' => 'Merchandise'),
    'loading'             => array('fr' => 'Date de chargement',        'en' => 'Loading Date'),
    'est_arrival'         => array('fr' => 'Arrivée estimée',           'en' => 'Est. Arrival Date'),
    'company_stamp'       => array('fr' => 'Cachet Entreprise',          'en' => 'Company Stamp'),
    'company_signature'   => array('fr' => 'Signature Entreprise',       'en' => 'Company Signature'),
    'departure'           => array('fr' => 'Lieu de Départ',            'en' => 'Departure Location'),
    'stop'                => array('fr' => 'Étape',                     'en' => 'Stop'),
    'arrival'             => array('fr' => 'Lieu d\'Arrivée',           'en' => 'Arrival Location'),
    'driver_docs'         => array('fr' => 'DOCUMENTS DU CHAUFFEUR',    'en' => 'DRIVER DOCUMENTS'),
    'contact_imgs'        => array('fr' => 'Images',                    'en' => 'Images'),
    'contact_other_docs'  => array('fr' => 'Documents',                 'en' => 'Documents'),
    'no_contact_files'    => array('fr' => 'Aucun fichier trouvé',      'en' => 'No files found'),
    'driving_license'     => array('fr' => 'Permis de conduire',        'en' => 'Driving License'),
    'id_card'             => array('fr' => "Carte d'identité (CIN)",    'en' => 'Identity Card (CIN)'),
    'other_docs'          => array('fr' => 'Autres documents',          'en' => 'Other Documents'),
    'not_available'       => array('fr' => 'Non disponible',            'en' => 'Not available'),
);

function jmT($key) {
    global $_jmStrings, $_jmLang;
    return isset($_jmStrings[$key][$_jmLang]) ? $_jmStrings[$key][$_jmLang] : $key;
}

function jmResolveFile($raw, $uploadDir = '') {
    if (empty($raw)) return '';
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        foreach ($decoded as $f) {
            $f = trim($f);
            if (!empty($f) && !empty(jmDriverPath($f))) return $f;
        }
        foreach ($decoded as $f) {
            $f = trim($f);
            if (!empty($f)) return $f;
        }
        return '';
    }
    return trim($raw);
}

function jmResolveAllFiles($raw) {
    if (empty($raw)) return array();
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        return array_values(array_filter(array_map('trim', $decoded)));
    }
    $single = trim($raw);
    return $single !== '' ? array($single) : array();
}

$_d_image_file    = jmResolveFile($o->d_image,     $driver_upload_dir);
$_d_lic_img_file  = jmResolveFile($o->d_lic_image, $driver_upload_dir);
$_d_doc_files     = jmResolveAllFiles($o->d_documents);

$has_cin_img = !empty($_d_image_file)   && !empty(jmDriverPath($_d_image_file))  && in_array(strtolower(pathinfo($_d_image_file,  PATHINFO_EXTENSION)), $_img_exts);
$has_lic_img = !empty($_d_lic_img_file) && !empty(jmDriverPath($_d_lic_img_file)) && in_array(strtolower(pathinfo($_d_lic_img_file, PATHINFO_EXTENSION)), $_img_exts);
$has_doc_img = !empty($_d_doc_files);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= jmT('subtitle') ?> - <?= jmVal($o->ref) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
<style>
/* ═══════════════════════════════════════════════════
   CSS RESET & VARIABLES (Professional Document Style)
═══════════════════════════════════════════════════ */
:root {
    --text-main: #111827;
    --text-muted: #4b5563;
    --border-color: #d1d5db;
    --bg-page: #f3f4f6;
    --bg-paper: #ffffff;
    --brand-color: #1e3a8a;
    --accent-bg: #f9fafb;
}

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

body {
    font-family: 'Inter', sans-serif;
    font-size: 13px;
    background: var(--bg-page);
    color: var(--text-main);
    padding: 20px;
    line-height: 1.5;
}

/* ═══════════════════════════════════════════════════
   ACTION BAR (Screen only)
═══════════════════════════════════════════════════ */
.action-bar {
    max-width: 210mm;
    margin: 0 auto 20px;
    display: flex;
    justify-content: flex-end;
    gap: 10px;
}
.btn {
    padding: 8px 16px;
    border-radius: 4px;
    font-size: 13px;
    font-weight: 500;
    cursor: pointer;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    border: 1px solid var(--border-color);
    background: #fff;
    color: var(--text-main);
    transition: background 0.2s;
}
.btn:hover { background: #f3f4f6; }
.btn-primary {
    background: var(--brand-color);
    color: #fff;
    border-color: var(--brand-color);
}
.btn-primary:hover { background: #1e40af; }

/* ═══════════════════════════════════════════════════
   A4 DOCUMENT PAGE
═══════════════════════════════════════════════════ */
.a4-page {
    width: 210mm;
    min-height: 297mm;
    background: var(--bg-paper);
    margin: 0 auto 20px auto;
    padding: 15mm;
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
    position: relative;
    display: flex;
    flex-direction: column;
}

/* ═══════════════════════════════════════════════════
   HEADER
═══════════════════════════════════════════════════ */
.doc-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    border-bottom: 2px solid var(--brand-color);
    padding-bottom: 15px;
    margin-bottom: 20px;
}
.doc-title-area {
    text-align: right;
}
.doc-title-area h1 {
    font-size: 20px;
    font-weight: 700;
    color: var(--brand-color);
    margin-bottom: 5px;
    text-transform: uppercase;
}
.doc-meta {
    font-size: 12px;
    color: var(--text-muted);
}
.doc-meta strong { color: var(--text-main); }

/* ═══════════════════════════════════════════════════
   DATA TABLES
═══════════════════════════════════════════════════ */
.data-table {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 20px;
}
.data-table th, .data-table td {
    border: 1px solid var(--border-color);
    padding: 10px 12px;
    vertical-align: middle;
}
.data-table th {
    background-color: var(--accent-bg);
    width: 20%;
    font-size: 11px;
    text-transform: uppercase;
    color: var(--text-muted);
    font-weight: 600;
    letter-spacing: 0.5px;
}
.data-table td {
    width: 30%;
    font-weight: 600;
    font-size: 13px;
}

/* ═══════════════════════════════════════════════════
   ROUTE & MAP LAYOUT
═══════════════════════════════════════════════════ */
.route-section {
    display: flex;
    gap: 20px;
    margin-bottom: 30px;
    flex: 1; /* Pushes signature to bottom */
}
.map-col {
    flex: 1;
    border: 1px solid var(--border-color);
    padding: 5px;
    background: var(--accent-bg);
}
#jm-map {
    width: 100%;
    height: 450px; /* Large map like PDF */
    background: #e5e5e5;
}
.address-col {
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 15px;
}
.address-box {
    border: 1px solid var(--border-color);
    padding: 15px;
    background: #fff;
    flex: 1;
}
.address-header {
    font-size: 11px;
    text-transform: uppercase;
    font-weight: 700;
    color: var(--text-muted);
    margin-bottom: 8px;
    display: flex;
    align-items: center;
    gap: 8px;
    padding-bottom: 8px;
    border-bottom: 1px solid #eee;
}
.dot {
    width: 10px; height: 10px; border-radius: 50%;
}
.dot.green { background: #10b981; }
.dot.orange { background: #f59e0b; }
.dot.red { background: #ef4444; }

.address-text {
    font-size: 13px;
    font-weight: 600;
    line-height: 1.4;
}

/* ═══════════════════════════════════════════════════
   STAMPS
═══════════════════════════════════════════════════ */
.stamp-section {
    display: flex;
    justify-content: space-between;
    margin-top: auto;
    padding-top: 20px;
    border-top: 1px solid var(--border-color);
}
.stamp-box {
    width: 45%;
    min-height: 120px;
    border: 1px dashed var(--border-color);
    border-radius: 4px;
    padding: 15px;
    text-align: center;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 10px;
}
.stamp-title {
    font-size: 11px;
    font-weight: 600;
    color: var(--text-muted);
    text-transform: uppercase;
}
.stamp-box img {
    max-height: 80px;
    max-width: 100%;
    object-fit: contain;
}

/* ═══════════════════════════════════════════════════
   DOCUMENTS GRID (Page 2)
═══════════════════════════════════════════════════ */
.doc-grid-header {
    text-align: center;
    font-size: 18px;
    font-weight: 700;
    text-transform: uppercase;
    margin-bottom: 20px;
    color: var(--brand-color);
}
.docs-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 15px;
}
.doc-item {
    border: 1px solid var(--border-color);
    padding: 10px;
    background: var(--accent-bg);
    text-align: center;
}
.doc-item-title {
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    color: var(--text-muted);
    margin-bottom: 10px;
}
.doc-item img {
    width: 100%;
    height: 350px;
    object-fit: cover;
    border: 1px solid #ddd;
    border-radius: 4px;
}
.doc-section-title {
    font-size: 11px;
    font-weight: 700;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.6px;
    margin: 18px 0 10px;
    padding-bottom: 6px;
    border-bottom: 1px solid var(--border-color);
}
.doc-section-title:first-child { margin-top: 0; }
.doc-file-row {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 14px;
    border: 1px solid var(--border-color);
    border-radius: 8px;
    background: var(--accent-bg);
    margin-bottom: 8px;
}
.doc-file-icon {
    width: 34px; height: 34px;
    border-radius: 7px;
    display: flex; align-items: center; justify-content: center;
    font-size: 15px; flex-shrink: 0;
}
.doc-file-name {
    font-size: 12px; font-weight: 600; color: var(--text-main);
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.doc-file-ext {
    font-size: 10px; color: var(--text-muted); text-transform: uppercase; margin-top: 2px;
}

/* ═══════════════════════════════════════════════════
   PRINT STYLES
═══════════════════════════════════════════════════ */
@media print {
    body {
        background: #fff;
        padding: 0;
        margin: 0;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    .action-bar { display: none !important; }
    .a4-page {
        box-shadow: none;
        margin: 0;
        padding: 10mm;
        width: 100%;
        min-height: auto;
    }
    .page-break {
        page-break-before: always;
        break-before: page;
    }
    .route-section {
        flex: none;
    }
    #jm-map {
        height: 320px;
    }
    .stamp-section {
        margin-top: 15px;
        page-break-inside: avoid;
        break-inside: avoid;
    }
}
</style>
</head>
<body>

<div class="action-bar">
    <a class="btn" href="javascript:history.back()">
        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
        <?= jmT('back') ?>
    </a>
    <button class="btn btn-primary" onclick="window.print()">
        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
        <?= jmT('print_pdf') ?>
    </button>
</div>

<div class="a4-page">
    
    <div class="doc-header">
        <div><?= jmLogo() ?></div>
        <div class="doc-title-area">
            <h1><?= jmT('subtitle') ?></h1>
            <div class="doc-meta">
                <strong>Réf:</strong> <?= jmVal($o->bl_number) ?> &nbsp;|&nbsp; 
                <strong>Date:</strong> <?= jmDate($o->booking_date, true) ?>
            </div>
        </div>
    </div>

    <table class="data-table">
        <tbody>
            <tr>
                <th><?= jmT('stat_vehicle') ?></th>
                <td><?= jmVal($o->v_ref) ?> <br><span style="font-size:11px;font-weight:400;"><?= trim(jmVal($o->v_maker) . ' ' . jmVal($o->v_model)) ?></span></td>
                <th><?= jmT('stat_driver') ?></th>
                <td><?= $driver_fullname ?> <br><span style="font-size:11px;font-weight:400;">Tél: <?= jmVal($o->d_phone) ?></span></td>
            </tr>
            <tr>
                <th><?= jmT('customer') ?></th>
                <td><?= jmVal($o->c_name) ?></td>
                <th><?= jmT('vendor') ?></th>
                <td><?= jmVal($o->s_name) ?></td>
            </tr>
            <tr>
                <th><?= jmT('merchandise') ?></th>
                <td colspan="3"><?= htmlspecialchars($merchandise_type ?: '—') ?></td>
            </tr>
            <tr>
                <th><?= jmT('loading') ?></th>
                <td><?= jmDate($o->pickup_datetime, true) ?></td>
                <th><?= jmT('est_arrival') ?></th>
                <td><?= jmDate($o->dropoff_datetime, true) ?></td>
            </tr>
            <tr>
                <th><?= jmT('stat_distance') ?></th>
                <td colspan="3"><?= (!empty($o->distance) && $o->distance > 0) ? (int)$o->distance . ' KM' : '—' ?></td>
            </tr>
        </tbody>
    </table>

    <div class="route-section">
        <div class="map-col">
            <?php if ($has_map): ?>
                <div id="jm-map"></div>
            <?php else: ?>
                <div style="height: 100%; display: flex; align-items: center; justify-content: center; color: var(--text-muted);">
                    <?= jmT('not_available') ?> (GPS)
                </div>
            <?php endif; ?>
        </div>

        <div class="address-col">
            <div class="address-box">
                <div class="address-header">
                    <div class="dot green"></div> <?= jmT('departure') ?>
                </div>
                <div class="address-text"><?= nl2br(htmlspecialchars($o->departure_address ?? '—')) ?></div>
            </div>

            <?php foreach ($stops_list as $idx => $st):
                $sa = is_array($st) ? ($st['address'] ?? (is_string($st) ? $st : '')) : $st;
                if (empty($sa)) continue;
            ?>
            <div class="address-box">
                <div class="address-header">
                    <div class="dot orange"></div> <?= jmT('stop') ?> <?= $idx + 1 ?>
                </div>
                <div class="address-text"><?= nl2br(htmlspecialchars($sa)) ?></div>
            </div>
            <?php endforeach; ?>

            <div class="address-box">
                <div class="address-header">
                    <div class="dot red"></div> <?= jmT('arrival') ?>
                </div>
                <div class="address-text"><?= nl2br(htmlspecialchars($o->arriving_address ?? '—')) ?></div>
            </div>
        </div>
    </div>

    <div class="stamp-section">
        <div class="stamp-box">
            <div class="stamp-title"><?= jmT('company_stamp') ?></div>
            <?php
            $flotteCachetFile = getDolGlobalString('FLOTTE_CACHET_FILE');
            if (!empty($flotteCachetFile) && file_exists(DOL_DATA_ROOT.'/'.$flotteCachetFile)) {
                $ext = strtolower(pathinfo($flotteCachetFile, PATHINFO_EXTENSION));
                $mime = ($ext === 'png') ? 'image/png' : 'image/jpeg';
                $data = base64_encode(file_get_contents(DOL_DATA_ROOT.'/'.$flotteCachetFile));
                echo '<img src="data:'.$mime.';base64,'.$data.'" alt="Cachet">';
            }
            ?>
        </div>
        <div class="stamp-box">
            <div class="stamp-title"><?= jmT('company_signature') ?></div>
            <?php
            $flotteSignatureFile = getDolGlobalString('FLOTTE_SIGNATURE_FILE');
            if (!empty($flotteSignatureFile) && file_exists(DOL_DATA_ROOT.'/'.$flotteSignatureFile)) {
                $ext = strtolower(pathinfo($flotteSignatureFile, PATHINFO_EXTENSION));
                $mime = ($ext === 'png') ? 'image/png' : 'image/jpeg';
                $data = base64_encode(file_get_contents(DOL_DATA_ROOT.'/'.$flotteSignatureFile));
                echo '<img src="data:'.$mime.';base64,'.$data.'" alt="Signature">';
            } else {
                echo '<div style="height: 60px;"></div>';
            }
            ?>
        </div>
    </div>

</div>

<div class="a4-page page-break">

    <div class="doc-grid-header">
        <?= jmT('driver_docs') ?> — <?= $driver_fullname ?>
    </div>

    <?php if (!empty($contact_files_images)): ?>
    <div class="doc-section-title"><?= jmT('contact_imgs') ?></div>
    <div class="docs-grid">
        <?php foreach ($contact_files_images as $_cf): ?>
        <div class="doc-item">
            <div class="doc-item-title"><?= htmlspecialchars(!empty($_cf['label']) ? $_cf['label'] : $_cf['filename']) ?></div>
            <?= jmContactImgTag($_cf['path'], $_cf['filename']) ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (!empty($contact_files_docs)): ?>
    <div class="doc-section-title"><?= jmT('contact_other_docs') ?></div>
    <?php foreach ($contact_files_docs as $_df):
        $_dext  = strtolower($_df['ext'] ?? pathinfo($_df['filename'], PATHINFO_EXTENSION));
        $_dicon_color = $_dext === 'pdf' ? '#dc2626' : (in_array($_dext, array('doc','docx')) ? '#2563eb' : '#4b5563');
        $_dicon_fa    = $_dext === 'pdf' ? 'fa-file-pdf' : (in_array($_dext, array('doc','docx')) ? 'fa-file-word' : 'fa-file-alt');
    ?>
    <div class="doc-file-row">
        <div class="doc-file-icon" style="background:<?= $_dicon_color ?>1a;">
            <i class="fa <?= $_dicon_fa ?>" style="color:<?= $_dicon_color ?>;"></i>
        </div>
        <div style="flex:1;min-width:0;">
            <div class="doc-file-name"><?= htmlspecialchars(!empty($_df['label']) ? $_df['label'] : $_df['filename']) ?></div>
            <div class="doc-file-ext"><?= strtoupper(htmlspecialchars($_dext)) ?> File</div>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>

    <?php if (empty($contact_files_images) && empty($contact_files_docs)): ?>
    <div style="text-align:center;padding:40px 0;color:var(--text-muted);">
        <i class="fa fa-folder-open" style="font-size:32px;opacity:0.3;display:block;margin-bottom:10px;"></i>
        <?= jmT('no_contact_files') ?>
        <!-- PATHS TRIED: <?= implode(' | ', array_map('htmlspecialchars', $_contact_dir_tried ?? [])) ?> -->
    </div>
    <?php endif; ?>

</div>

<?php if ($has_map): ?>
<script>
(function() {
    var depLat = <?= (float)$o->dep_lat ?>,
        depLon = <?= (float)$o->dep_lon ?>,
        arrLat = <?= (float)$o->arr_lat ?>,
        arrLon = <?= (float)$o->arr_lon ?>;

    var stops = <?= !empty($stops_list) ? json_encode(array_values(array_filter(array_map(function($s) {
        if (is_array($s) && isset($s['lat']) && isset($s['lon'])) return array('lat' => (float)$s['lat'], 'lon' => (float)$s['lon']);
        return null;
    }, $stops_list), 'is_array'))) : '[]' ?>;

    var mapEl = document.getElementById('jm-map');
    if (!mapEl) return;

    var map = L.map('jm-map', { zoomControl: false, attributionControl: false });
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 18,
        crossOrigin: true
    }).addTo(map);

    function mkIcon(color) {
        return L.divIcon({
            className: '',
            html: '<div style="background:'+color+';width:16px;height:16px;border-radius:50%;border:3px solid #fff;box-shadow:0 2px 4px rgba(0,0,0,0.3);"></div>',
            iconSize: [16, 16],
            iconAnchor: [8, 8]
        });
    }

    var wps = [[depLat, depLon]];
    stops.forEach(function(s){ wps.push([s.lat, s.lon]); });
    wps.push([arrLat, arrLon]);

    L.marker([depLat, depLon], { icon: mkIcon('#10b981') }).addTo(map); // Green start
    stops.forEach(function(s){ L.marker([s.lat, s.lon], { icon: mkIcon('#f59e0b') }).addTo(map); }); // Orange stops
    L.marker([arrLat, arrLon], { icon: mkIcon('#ef4444') }).addTo(map); // Red end

    var coordStr = wps.map(function(c){ return c[1]+','+c[0]; }).join(';');
    var proxyUrl = '<?= dol_buildpath('/flotte/booking_card.php', 1) ?>?osrm_proxy=1&coords=' + encodeURIComponent(coordStr);
    
    fetch(proxyUrl)
        .then(function(r){ return r.json(); })
        .then(function(data){
            if (data && data.routes && data.routes[0]) {
                var coords = data.routes[0].geometry.coordinates.map(function(c){ return [c[1], c[0]]; });
                L.polyline(coords, { color:'#1e3a8a', weight:5, opacity:0.8 }).addTo(map);
                map.fitBounds(L.polyline(coords).getBounds(), { padding:[20,20] });
            } else { fallback(); }
        }).catch(function(){ fallback(); });

    function fallback() {
        L.polyline(wps, { color:'#1e3a8a', weight:4, opacity:0.8, dashArray: '5, 10' }).addTo(map);
        map.fitBounds(L.latLngBounds(wps), { padding:[20,20] });
    }
    // Capture map as static image before printing (tiles are cross-origin, cannot be drawn otherwise)
    var _jmPrintImg = null;
    window.addEventListener('beforeprint', function() {
        if (typeof html2canvas !== 'undefined' && mapEl) {
            // Synchronous-style: create canvas capture, inject image, hide live map
            html2canvas(mapEl, { useCORS: true, allowTaint: false, logging: false }).then(function(canvas) {
                _jmPrintImg = document.createElement('img');
                _jmPrintImg.src = canvas.toDataURL('image/png');
                _jmPrintImg.style.cssText = 'width:100%;height:320px;object-fit:cover;display:block;border-radius:2px;';
                mapEl.style.display = 'none';
                mapEl.parentNode.insertBefore(_jmPrintImg, mapEl);
            });
        } else {
            setTimeout(function() { map.invalidateSize(); }, 100);
        }
    });
    window.addEventListener('afterprint', function() {
        if (_jmPrintImg) { _jmPrintImg.remove(); _jmPrintImg = null; }
        if (mapEl) mapEl.style.display = '';
    });
})();
</script>
<?php endif; ?>


</body>
</html>
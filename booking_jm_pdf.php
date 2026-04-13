<?php
/* Journey Management PDF View - flotte module
 * Generates a printable JM card matching the optimalogistic design.
 *
 * FIX (v2): Removed v.plate / v.type / v.max_load which do NOT exist in
 *   llx_flotte_vehicle.  Vehicle table only exposes: ref, maker, model.
 *   Also aliased d.employee_id → d_cin (used as the national-ID / CIN field).
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
/*
 * IMPORTANT: only select columns that actually exist in each joined table.
 *
 * llx_flotte_vehicle  →  rowid, ref, maker, model  (no plate / type / max_load)
 * llx_flotte_driver   →  firstname, middlename, lastname, phone, employee_id,
 *                         license_number, license_issue_date, license_expiry_date,
 *                         driver_image, license_image, documents
 */
$sql = "SELECT b.*,
        v.ref        AS v_ref,
        v.maker      AS v_maker,
        v.model      AS v_model,
        d.firstname  AS d_firstname,
        d.lastname   AS d_lastname,
        d.middlename AS d_middlename,
        d.phone      AS d_phone,
        d.employee_id        AS d_cin,
        d.license_number     AS d_license,
        d.license_issue_date  AS d_lic_issue,
        d.license_expiry_date AS d_lic_expiry,
        d.driver_image  AS d_image,
        d.license_image AS d_lic_image,
        d.documents     AS d_documents,
        s.nom           AS s_name,
        c.nom           AS c_name
        FROM ".MAIN_DB_PREFIX."flotte_booking b
        LEFT JOIN ".MAIN_DB_PREFIX."flotte_vehicle v ON v.rowid = b.fk_vehicle
        LEFT JOIN ".MAIN_DB_PREFIX."flotte_driver  d ON d.rowid = b.fk_driver
        LEFT JOIN ".MAIN_DB_PREFIX."societe         s ON s.rowid = b.fk_vendor
        LEFT JOIN ".MAIN_DB_PREFIX."societe         c ON c.rowid = b.fk_customer
        WHERE b.rowid = ".((int)$id);

$resql = $db->query($sql);
if (!$resql) {
    // Show a more useful error in dev; in production this hides internals
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

/* ── Helper: format a stored date/datetime string ─────────── */
function jmDate($val, $withTime = false) {
    if (empty($val) || $val === '0000-00-00' || $val === '0000-00-00 00:00:00') return '—';
    $ts = strtotime($val);
    if (!$ts) return htmlspecialchars($val);
    return $withTime ? date('Y-m-d H:i', $ts) : date('Y-m-d', $ts);
}
function jmVal($v, $fallback = '—') {
    return (!empty($v) && $v !== '0') ? htmlspecialchars($v) : $fallback;
}

/* ── Driver document image helper ─────────────────────────── */
$driver_upload_dir = DOL_DATA_ROOT.'/flotte/driver/';
function jmImgTag($filename, $alt = '', $style = '') {
    global $driver_upload_dir;
    if (empty($filename)) return '';
    $path = $driver_upload_dir . $filename;
    if (!file_exists($path)) return '';
    $mime = 'image/jpeg';
    $ext  = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    if ($ext === 'png')  $mime = 'image/png';
    if ($ext === 'gif')  $mime = 'image/gif';
    if ($ext === 'webp') $mime = 'image/webp';
    if (!in_array($ext, array('jpg','jpeg','png','gif','webp'))) return '';
    $data = base64_encode(file_get_contents($path));
    $s = $style ?: 'max-width:100%;max-height:160px;border-radius:6px;border:1px solid #ccd0db;object-fit:contain;';
    return '<img src="data:'.$mime.';base64,'.$data.'" alt="'.htmlspecialchars($alt).'" style="'.$s.'">';
}

/* ── Company logo (Dolibarr global logo) ─────────────────── */
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
        return '<img src="data:'.$mime.';base64,'.$data.'" alt="Logo" style="max-height:60px;max-width:200px;">';
    }
    return '<div style="font-size:22px;font-weight:800;color:#1a5276;letter-spacing:-0.5px;">optima<span style="color:#e67e22;">logistic</span></div>';
}

/* ── Vehicle label helper ─────────────────────────────────── */
/*
 * v_ref   = vehicle reference (used as registration / plate in this system)
 * v_maker = manufacturer
 * v_model = model
 * We build a human-readable label from these.
 */
function jmVehicleLabel($o) {
    $parts = array();
    if (!empty($o->v_maker)) $parts[] = htmlspecialchars($o->v_maker);
    if (!empty($o->v_model)) $parts[] = htmlspecialchars($o->v_model);
    return implode(' ', $parts) ?: '—';
}

/* ── Build stops list from JSON ───────────────────────────── */
$stops_list = array();
if (!empty($o->stops)) {
    $decoded = json_decode($o->stops, true);
    if (is_array($decoded)) $stops_list = $decoded;
}

/* ── Map availability ───────────────────────────────────────*/
$has_map = (!empty($o->dep_lat) && !empty($o->dep_lon) && !empty($o->arr_lat) && !empty($o->arr_lon));

/* ── Driver name ────────────────────────────────────────────*/
$driver_fullname = trim(
    jmVal($o->d_firstname, '') .
    (!empty($o->d_middlename) ? ' ' . htmlspecialchars($o->d_middlename) : '') .
    (!empty($o->d_lastname)   ? ' ' . htmlspecialchars($o->d_lastname)   : '')
);
if (empty($driver_fullname)) $driver_fullname = '—';

/* ── Merchandise type from note_public or buying_unit ───────*/
$merchandise_type = '';
if (!empty($o->note_public))  $merchandise_type = trim($o->note_public);
if (empty($merchandise_type) && !empty($o->buying_unit)) $merchandise_type = trim($o->buying_unit);

/* ── Page print ─────────────────────────────────────────────*/
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Journey Management – <?= jmVal($o->ref) ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js"></script>
<style>
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

body {
    font-family: 'Inter', 'Segoe UI', Arial, sans-serif;
    font-size: 13px;
    color: #1a1f2e;
    background: #f0f2f5;
    padding: 20px;
}

/* ── Print / action bar ──────────────────────────────────── */
.jm-print-bar {
    max-width: 900px;
    margin: 0 auto 12px;
    display: flex;
    gap: 10px;
    align-items: center;
}
.jm-btn-back {
    background: #fff;
    color: #3c4758;
    border: 1.5px solid #d1d5e0;
    border-radius: 7px;
    padding: 8px 16px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-family: inherit;
}
.jm-btn-print {
    background: #1a5276;
    color: #fff;
    border: none;
    border-radius: 7px;
    padding: 8px 20px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 7px;
    font-family: inherit;
    transition: background 0.15s;
}
.jm-btn-print:hover { background: #154360; }

/* ── Card wrapper ─────────────────────────────────────────── */
.jm-card {
    max-width: 900px;
    margin: 0 auto 24px;
    background: #fff;
    border-radius: 14px;
    box-shadow: 0 2px 20px rgba(0,0,0,0.09);
    overflow: hidden;
    border: 1px solid #e2e5ee;
}

/* ── Top header ───────────────────────────────────────────── */
.jm-header {
    text-align: center;
    padding: 24px 32px 18px;
    border-bottom: 1.5px solid #e8eaf0;
}
.jm-header-logo {
    margin-bottom: 12px;
}
.jm-header-logo img {
    max-height: 52px;
    max-width: 200px;
}
.jm-header-title {
    font-size: 17px;
    font-weight: 800;
    color: #1a2e44;
    letter-spacing: 0.2px;
    margin-bottom: 6px;
}
.jm-header-time {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 13px;
    font-weight: 500;
    color: #5a6482;
}
.jm-header-time svg {
    width: 15px; height: 15px;
    fill: none; stroke: #5a6482; stroke-width: 2;
    stroke-linecap: round; stroke-linejoin: round;
    flex-shrink: 0;
}

/* ── Two-column body ──────────────────────────────────────── */
.jm-body {
    display: grid;
    grid-template-columns: 45% 55%;
}
.jm-left {
    padding: 26px 24px 26px 32px;
    border-right: 1.5px solid #e8eaf0;
}
.jm-right {
    padding: 26px 32px 26px 24px;
    display: flex;
    flex-direction: column;
    gap: 0;
}

/* ── Info rows ────────────────────────────────────────────── */
.jm-row {
    display: flex;
    align-items: flex-start;
    gap: 16px;
    margin-bottom: 20px;
}
.jm-row:last-child { margin-bottom: 0; }

/* The large orange ring bullet */
.jm-bullet {
    width: 22px;
    height: 22px;
    border-radius: 50%;
    border: 2.5px solid #e07b39;
    background: #fff;
    flex-shrink: 0;
    margin-top: 1px;
}

.jm-row-content { flex: 1; min-width: 0; }

.jm-row-label {
    font-size: 13px;
    font-weight: 700;
    color: #1a2e44;
    display: flex;
    align-items: center;
    gap: 6px;
    margin-bottom: 5px;
}
.jm-row-label .lico {
    font-size: 14px;
}

.jm-row-value {
    font-size: 13px;
    color: #374151;
    line-height: 1.7;
}
.jm-row-value strong {
    color: #1a2e44;
    font-weight: 700;
}
.jm-row-value .sub-line {
    display: block;
    font-size: 12.5px;
    color: #5a6482;
    line-height: 1.6;
}

/* ── Route stops ──────────────────────────────────────────── */
.jm-route {
    display: flex;
    flex-direction: column;
    gap: 0;
    position: relative;
    padding-left: 4px;
}
.jm-route-stop {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    position: relative;
    padding-bottom: 14px;
}
.jm-route-stop:last-child { padding-bottom: 0; }

/* Vertical line connecting stops */
.jm-route-stop:not(:last-child)::before {
    content: '';
    position: absolute;
    left: 6px;
    top: 16px;
    bottom: 0;
    width: 1.5px;
    background: #d1d5e0;
}

.jm-stop-dot {
    width: 14px;
    height: 14px;
    border-radius: 50%;
    flex-shrink: 0;
    margin-top: 3px;
    position: relative;
    z-index: 1;
}
.jm-stop-dot.dep { background: #16a34a; }
.jm-stop-dot.arr { background: #dc2626; }
.jm-stop-dot.mid { background: #ea580c; }

.jm-stop-name {
    font-size: 13px;
    font-weight: 700;
    color: #1a5276;
    margin-bottom: 2px;
}
.jm-stop-addr {
    font-size: 12px;
    color: #5a6482;
    line-height: 1.5;
}

/* ── Stamp area (bottom of left col) ─────────────────────── */
.jm-stamp-area {
    margin-top: 22px;
    display: flex;
    align-items: center;
    justify-content: flex-start;
}
.jm-stamp-area img {
    max-height: 110px;
    max-width: 200px;
    object-fit: contain;
}
.jm-stamp-placeholder {
    border: 1.5px dashed #c5cae0;
    border-radius: 8px;
    padding: 14px 18px;
    text-align: center;
    color: #b0b8cc;
    font-size: 11.5px;
    min-width: 150px;
}

/* ── Map ──────────────────────────────────────────────────── */
#jm-map {
    height: 100%;
    min-height: 340px;
    border-radius: 10px;
    border: 1.5px solid #ccd0db;
    flex: 1;
}
.jm-map-placeholder {
    height: 340px;
    border-radius: 10px;
    border: 1.5px solid #e2e5f0;
    background: #f7f8fc;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #b0b8cc;
    font-size: 13px;
    flex-direction: column;
    gap: 10px;
    flex: 1;
}
.jm-map-placeholder svg {
    width: 48px; height: 48px; opacity: 0.25;
}

/* ── Documents section (page 2) ───────────────────────────── */
.jm-docs-card {
    max-width: 900px;
    margin: 0 auto;
    background: #fff;
    border-radius: 14px;
    box-shadow: 0 2px 20px rgba(0,0,0,0.09);
    overflow: hidden;
    border: 1px solid #e2e5ee;
    padding: 28px 32px;
}
.jm-docs-section-title {
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    color: #9aa0b4;
    margin-bottom: 18px;
    padding-bottom: 10px;
    border-bottom: 1.5px solid #f0f2f8;
}
.jm-docs-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 16px;
    margin-bottom: 24px;
}
.jm-doc-block {}
.jm-doc-label {
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #7b8299;
    margin-bottom: 8px;
}
.jm-doc-img-wrap {
    border: 1px solid #d1d5e0;
    border-radius: 10px;
    overflow: hidden;
    background: #f7f8fc;
    min-height: 130px;
    display: flex;
    align-items: center;
    justify-content: center;
}
.jm-doc-img-wrap img {
    max-width: 100%;
    max-height: 200px;
    object-fit: contain;
    display: block;
}
.jm-doc-empty {
    color: #c4c9d8;
    font-size: 12px;
    padding: 16px;
    text-align: center;
}

/* ── Print styles ─────────────────────────────────────────── */
@media print {
    body { background: #fff; padding: 0; }
    .jm-print-bar { display: none !important; }
    .jm-card, .jm-docs-card { box-shadow: none; border-radius: 0; margin: 0; border: none; }
    .jm-docs-card { page-break-before: always; }
    #jm-map { min-height: 280px; }
    .jm-body { grid-template-columns: 45% 55%; }
}

@media (max-width: 640px) {
    body { padding: 10px; }
    .jm-body { grid-template-columns: 1fr; }
    .jm-left { border-right: none; border-bottom: 1.5px solid #e8eaf0; padding: 20px 18px; }
    .jm-right { padding: 20px 18px; }
    .jm-docs-grid { grid-template-columns: 1fr 1fr; }
    #jm-map { min-height: 240px; }
}
</style>
</head>
<body>

<!-- ── Print bar ─────────────────────────────────────────── -->
<div class="jm-print-bar">
    <a class="jm-btn-back" href="javascript:history.back()">&#8592; Retour</a>
    <button class="jm-btn-print" onclick="window.print()">
        <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
        Imprimer / PDF
    </button>
</div>

<!-- ══════════════════════════════════════════════════════
     PAGE 1 — Journey Management Card
══════════════════════════════════════════════════════ -->
<div class="jm-card">

    <!-- Header -->
    <div class="jm-header">
        <div class="jm-header-logo">
            <?= jmLogo() ?>
        </div>
        <div class="jm-header-title">Journey Management &ndash; <?= jmVal($o->ref) ?></div>
        <div class="jm-header-time">
            <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            <?= jmDate($o->booking_date, true) ?>
        </div>
    </div>

    <!-- Body: left info + right map -->
    <div class="jm-body">

        <!-- ── LEFT: Info rows ─────────────────────────────── -->
        <div class="jm-left">

            <!-- Véhicule -->
            <div class="jm-row">
                <div class="jm-bullet"></div>
                <div class="jm-row-content">
                    <div class="jm-row-label">
                        <span class="lico">🚛</span> Véhicule :
                    </div>
                    <div class="jm-row-value">
                        <?php
                        $vtype = '';
                        if (!empty($o->v_maker)) $vtype .= htmlspecialchars($o->v_maker);
                        if (!empty($o->v_model)) $vtype .= ' '.htmlspecialchars($o->v_model);
                        echo $vtype ?: '—';
                        ?>
                        <?php if (!empty($o->v_ref)): ?>
                        <span class="sub-line"><strong>Tracteur :</strong> <?= jmVal($o->v_ref) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Chauffeur -->
            <div class="jm-row">
                <div class="jm-bullet"></div>
                <div class="jm-row-content">
                    <div class="jm-row-label">
                        <span class="lico">👤</span> Chauffeur :
                    </div>
                    <div class="jm-row-value">
                        <span class="sub-line"><strong>Nom complet :</strong> <?= $driver_fullname ?></span>
                        <?php if (!empty($o->d_cin)): ?>
                        <span class="sub-line"><strong>CIN :</strong> <?= jmVal($o->d_cin) ?></span>
                        <?php endif; ?>
                        <?php if (!empty($o->d_phone)): ?>
                        <span class="sub-line"><strong>Téléphone :</strong> <?= jmVal($o->d_phone) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Type de marchandise -->
            <?php if (!empty($merchandise_type)): ?>
            <div class="jm-row">
                <div class="jm-bullet"></div>
                <div class="jm-row-content">
                    <div class="jm-row-label">
                        <span class="lico">📦</span> Type de marchandise :
                    </div>
                    <div class="jm-row-value">
                        <?= htmlspecialchars($merchandise_type) ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Distance + Dates -->
            <div class="jm-row">
                <div class="jm-bullet"></div>
                <div class="jm-row-content">
                    <div class="jm-row-label">
                        <span class="lico">📍</span>
                        <?php if (!empty($o->distance) && $o->distance > 0): ?>
                            Distance estimée : <strong><?= (int)$o->distance ?> KM</strong>
                        <?php else: ?>
                            Distance &amp; Dates
                        <?php endif; ?>
                    </div>
                    <div class="jm-row-value">
                        <?php if (!empty($o->pickup_datetime) && $o->pickup_datetime !== '0000-00-00 00:00:00'): ?>
                        <span class="sub-line">
                            <strong>Date de chargement:</strong> <?= jmDate($o->pickup_datetime, true) ?>
                        </span>
                        <?php endif; ?>
                        <?php if (!empty($o->dropoff_datetime) && $o->dropoff_datetime !== '0000-00-00 00:00:00'): ?>
                        <span class="sub-line">
                            <strong>heure d&rsquo;arrivée estimée :</strong> <?= jmDate($o->dropoff_datetime, true) ?>
                        </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Route / Itinéraire -->
            <div class="jm-row">
                <div class="jm-bullet"></div>
                <div class="jm-row-content">
                    <div class="jm-route">

                        <!-- Departure -->
                        <div class="jm-route-stop">
                            <div class="jm-stop-dot dep"></div>
                            <div>
                                <?php
                                // Try to extract location name from departure_address (first line)
                                $dep_lines = array_filter(array_map('trim', explode("\n", $o->departure_address ?? '')));
                                $dep_name  = !empty($dep_lines) ? array_shift($dep_lines) : '';
                                $dep_rest  = implode(', ', $dep_lines);
                                ?>
                                <?php if (!empty($dep_name)): ?>
                                <div class="jm-stop-name"><?= htmlspecialchars($dep_name) ?></div>
                                <?php endif; ?>
                                <div class="jm-stop-addr"><?= nl2br(htmlspecialchars($dep_rest ?: ($o->departure_address ?? '—'))) ?></div>
                            </div>
                        </div>

                        <!-- Intermediate stops -->
                        <?php foreach ($stops_list as $idx => $st):
                            $sa = is_array($st) ? ($st['address'] ?? (is_string($st) ? $st : '')) : $st;
                            if (empty($sa)) continue;
                        ?>
                        <div class="jm-route-stop">
                            <div class="jm-stop-dot mid"></div>
                            <div>
                                <div class="jm-stop-name">Étape <?= $idx + 1 ?></div>
                                <div class="jm-stop-addr"><?= nl2br(htmlspecialchars($sa)) ?></div>
                            </div>
                        </div>
                        <?php endforeach; ?>

                        <!-- Arrival -->
                        <div class="jm-route-stop">
                            <div class="jm-stop-dot arr"></div>
                            <div>
                                <?php
                                $arr_lines = array_filter(array_map('trim', explode("\n", $o->arriving_address ?? '')));
                                $arr_name  = !empty($arr_lines) ? array_shift($arr_lines) : '';
                                $arr_rest  = implode(', ', $arr_lines);
                                ?>
                                <?php if (!empty($arr_name)): ?>
                                <div class="jm-stop-name"><?= htmlspecialchars($arr_name) ?></div>
                                <?php endif; ?>
                                <div class="jm-stop-addr"><?= nl2br(htmlspecialchars($arr_rest ?: ($o->arriving_address ?? '—'))) ?></div>
                            </div>
                        </div>

                    </div><!-- /.jm-route -->
                </div>
            </div>

            <!-- Company stamp -->
            <div class="jm-stamp-area">
                <?php
                // Try to show company stamp/logo
                $stamp_shown = false;
                if (!empty($conf->global->MAIN_INFO_SOCIETE_LOGO)) {
                    $lp = DOL_DATA_ROOT.'/mycompany/logos/'.$conf->global->MAIN_INFO_SOCIETE_LOGO;
                    if (file_exists($lp)) {
                        $ext = strtolower(pathinfo($lp, PATHINFO_EXTENSION));
                        $mime = $ext === 'png' ? 'image/png' : ($ext === 'gif' ? 'image/gif' : 'image/jpeg');
                        $data = base64_encode(file_get_contents($lp));
                        echo '<img src="data:'.$mime.';base64,'.$data.'" alt="Cachet" style="max-height:110px;max-width:200px;object-fit:contain;">';
                        $stamp_shown = true;
                    }
                }
                if (!$stamp_shown): ?>
                <div class="jm-stamp-placeholder">
                    Cachet &amp; Signature<br>expéditeur
                </div>
                <?php endif; ?>
            </div>

        </div><!-- /.jm-left -->

        <!-- ── RIGHT: Map ─────────────────────────────────── -->
        <div class="jm-right">
            <?php if ($has_map): ?>
            <div id="jm-map"></div>
            <?php else: ?>
            <div class="jm-map-placeholder">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7"/></svg>
                <span>Carte non disponible</span>
            </div>
            <?php endif; ?>
        </div><!-- /.jm-right -->

    </div><!-- /.jm-body -->

</div><!-- /.jm-card -->


<!-- ══════════════════════════════════════════════════════
     PAGE 2 — Driver Documents
══════════════════════════════════════════════════════ -->
<?php
$has_cin_img  = !empty($o->d_image)     && file_exists($driver_upload_dir.$o->d_image)     && in_array(strtolower(pathinfo($o->d_image,     PATHINFO_EXTENSION)), array('jpg','jpeg','png','gif','webp'));
$has_lic_img  = !empty($o->d_lic_image) && file_exists($driver_upload_dir.$o->d_lic_image) && in_array(strtolower(pathinfo($o->d_lic_image,  PATHINFO_EXTENSION)), array('jpg','jpeg','png','gif','webp'));
$has_doc_img  = !empty($o->d_documents) && file_exists($driver_upload_dir.$o->d_documents) && in_array(strtolower(pathinfo($o->d_documents,  PATHINFO_EXTENSION)), array('jpg','jpeg','png','gif','webp'));
$has_any_doc  = $has_cin_img || $has_lic_img || $has_doc_img;
?>
<div class="jm-docs-card">

    <div class="jm-docs-section-title">📎 Documents chauffeur</div>

    <div class="jm-docs-grid">

        <!-- Permis de conduire -->
        <div class="jm-doc-block">
            <div class="jm-doc-label">Permis de conduire</div>
            <div class="jm-doc-img-wrap">
                <?php if ($has_lic_img): ?>
                    <?= jmImgTag($o->d_lic_image, 'Permis', 'max-width:100%;max-height:200px;object-fit:contain;display:block;') ?>
                <?php else: ?>
                    <span class="jm-doc-empty">Non disponible</span>
                <?php endif; ?>
            </div>
        </div>

        <!-- CIN -->
        <div class="jm-doc-block">
            <div class="jm-doc-label">Carte d&rsquo;identité (CIN)</div>
            <div class="jm-doc-img-wrap">
                <?php if ($has_cin_img): ?>
                    <?= jmImgTag($o->d_image, 'CIN', 'max-width:100%;max-height:200px;object-fit:contain;display:block;') ?>
                <?php else: ?>
                    <span class="jm-doc-empty">Non disponible</span>
                <?php endif; ?>
            </div>
        </div>

        <!-- Autres documents (carte grise / etc.) -->
        <div class="jm-doc-block">
            <div class="jm-doc-label">Autres documents</div>
            <div class="jm-doc-img-wrap">
                <?php if ($has_doc_img): ?>
                    <?= jmImgTag($o->d_documents, 'Document', 'max-width:100%;max-height:200px;object-fit:contain;display:block;') ?>
                <?php else: ?>
                    <span class="jm-doc-empty">Non disponible</span>
                <?php endif; ?>
            </div>
        </div>

    </div><!-- /.jm-docs-grid -->

</div><!-- /.jm-docs-card -->

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

    var map = L.map('jm-map', { zoomControl: true, attributionControl: false });
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 18 }).addTo(map);

    function mkIcon(color, size) {
        size = size || 14;
        return L.divIcon({
            className: '',
            html: '<div style="background:'+color+';width:'+size+'px;height:'+size+'px;border-radius:50%;border:2.5px solid #fff;box-shadow:0 2px 8px rgba(0,0,0,.35);"></div>',
            iconSize: [size, size],
            iconAnchor: [size/2, size/2]
        });
    }

    var wps = [[depLat, depLon]];
    stops.forEach(function(s){ wps.push([s.lat, s.lon]); });
    wps.push([arrLat, arrLon]);

    // Markers
    L.marker([depLat, depLon], { icon: mkIcon('#16a34a', 16) }).addTo(map);
    stops.forEach(function(s){ L.marker([s.lat, s.lon], { icon: mkIcon('#ea580c', 12) }).addTo(map); });
    L.marker([arrLat, arrLon], { icon: mkIcon('#dc2626', 16) }).addTo(map);

    // Route
    var coordStr = wps.map(function(c){ return c[1]+','+c[0]; }).join(';');
    var proxyUrl = '<?= dol_buildpath('/flotte/booking_card.php', 1) ?>?osrm_proxy=1&coords=' + encodeURIComponent(coordStr);
    fetch(proxyUrl)
        .then(function(r){ return r.json(); })
        .then(function(data){
            if (data && data.routes && data.routes[0]) {
                var coords = data.routes[0].geometry.coordinates.map(function(c){ return [c[1], c[0]]; });
                L.polyline(coords, { color:'#ffffff', weight:8, opacity:1 }).addTo(map);
                L.polyline(coords, { color:'#3c4758', weight:4.5, opacity:0.9 }).addTo(map);
                map.fitBounds(L.polyline(coords).getBounds(), { padding:[28,28] });
            } else { fallback(); }
        }).catch(function(){ fallback(); });

    function fallback() {
        L.polyline(wps, { color:'#ffffff', weight:8, opacity:1 }).addTo(map);
        L.polyline(wps, { color:'#3c4758', weight:4.5, opacity:0.9 }).addTo(map);
        map.fitBounds(L.latLngBounds(wps), { padding:[28,28] });
    }
})();
</script>
<?php endif; ?>

</body>
</html>
<?php
/**
 * View a booking confirmation photo
 * Serves the image file from the server filesystem
 * Usage: booking_photo_view.php?id=N[&thumb=1]
 */

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

require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';

$id = (int) GETPOST('id', 'int');
$thumb = (int) GETPOST('thumb', 'int');

if ($id <= 0) {
    header('HTTP/1.0 400 Bad Request');
    exit;
}

// Fetch photo record
$sql = "SELECT file_path, file_name, type FROM " . MAIN_DB_PREFIX . "flotte_booking_photo"
    . " WHERE rowid = " . $id . " AND status = 1";
$res = $db->query($sql);
if (!$res || !$db->num_rows($res)) {
    header('HTTP/1.0 404 Not Found');
    exit;
}

$photo = $db->fetch_object($res);
$file_path = $photo->file_path;

if (!file_exists($file_path)) {
    header('HTTP/1.0 404 Not Found');
    exit;
}

$ext = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
$mime_types = [
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'gif' => 'image/gif',
    'webp' => 'image/webp',
];
$mime = isset($mime_types[$ext]) ? $mime_types[$ext] : 'application/octet-stream';

// Serve full image
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($file_path));
header('Cache-Control: max-age=86400');
header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 86400) . ' GMT');
readfile($file_path);

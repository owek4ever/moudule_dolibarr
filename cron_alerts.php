<?php
/**
 * cron_alerts.php
 *
 * Cron-triggered script that scans for expiring documents and sends Firebase notifications.
 * Call via cron: php /path/to/htdocs/modules/flotte/cron_alerts.php
 * Or register in Dolibarr cron system.
 */

// Allow CLI execution
if (php_sapi_name() !== 'cli') {
    // Allow web call only from localhost or internal IP
    $allowed = array('127.0.0.1', '::1');
    if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', $allowed)) {
        http_response_code(403);
        die('Access denied');
    }
}

define('NOTOKENRENEWAL', 1);
define('NOCSRFCHECK', 1);
define('NOREQUIREMENU', 1);
define('NOREQUIREHTML', 1);
define('NOREQUIREAJAX', 1);

// Load Dolibarr environment
$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
    $res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"] . "/main.inc.php";
}
if (!$res && defined('DOL_DOCUMENT_ROOT') && file_exists(DOL_DOCUMENT_ROOT . '/main.inc.php')) {
    $res = @include DOL_DOCUMENT_ROOT . '/main.inc.php';
}
// Try relative paths for CLI
if (!$res) {
    $scriptDir = dirname(__FILE__);
    $candidates = array(
        $scriptDir . '/../../main.inc.php',
        $scriptDir . '/../../../main.inc.php',
        $scriptDir . '/../../../../htdocs/main.inc.php',
    );
    foreach ($candidates as $candidate) {
        if (file_exists($candidate)) {
            $res = @include $candidate;
            if ($res) break;
        }
    }
}
if (!$res) {
    die("[FLOTTE CRON] Cannot load Dolibarr main.inc.php\n");
}

dol_include_once('/flotte/class/FirebaseNotificationService.class.php');

$start = microtime(true);
echo "[FLOTTE ALERTS] Starting at " . date('Y-m-d H:i:s') . "\n";

$svc     = new FirebaseNotificationService($db);
$summary = $svc->runAlertScan();

$elapsed = round(microtime(true) - $start, 2);
echo "[FLOTTE ALERTS] Done. Rules processed: {$summary['processed']}, Notifications sent: {$summary['sent']}, Time: {$elapsed}s\n";

$db->close();
exit(0);

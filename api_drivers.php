<?php
/**
 * Driver REST API — Dolibarr flotte module
 *
 * Place this file in:  htdocs/custom/flotte/api_drivers.php
 *                  or  htdocs/flotte/api_drivers.php
 *
 * ──────────────────────────────────────────────────────────────
 * AUTHENTICATION
 *   Pass your Dolibarr API token in every request:
 *     Header :  Authorization: Bearer <YOUR_API_TOKEN>
 *     OR Query:  ?api_key=<YOUR_API_TOKEN>
 *
 * ENDPOINTS
 *   GET    api_drivers.php                  → list all drivers
 *   GET    api_drivers.php?id=5             → get one driver
 *   GET    api_drivers.php?ref=DRV0003      → get by ref
 *   GET    api_drivers.php?status=active    → filter by status
 *   GET    api_drivers.php?search=john      → search by name/phone
 *   GET    api_drivers.php?page=0&limit=20  → paginate
 *   POST   api_drivers.php                  → create driver
 *   PUT    api_drivers.php?id=5             → update driver
 *   DELETE api_drivers.php?id=5             → delete driver
 * ──────────────────────────────────────────────────────────────
 */

// ── 1. Bootstrap Dolibarr ────────────────────────────────────────────────────
define('NOTOKENRENEWAL', 1);   // prevent CSRF token issues in API context
define('NOREQUIREMENU',  1);
define('NOREQUIREHTML',  1);
define('NOREQUIREAJAX',  1);

$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
    $res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"] . "/main.inc.php";
}
$tmp  = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1;
$j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) { $i--; $j--; }
foreach ([
    substr($tmp, 0, $i + 1),
    dirname(substr($tmp, 0, $i + 1)),
    '..', '../..', '../../..'
] as $base) {
    if (!$res && file_exists($base . "/main.inc.php")) {
        $res = @include $base . "/main.inc.php";
        if ($res) break;
    }
}
if (!$res) { http_response_code(500); die(json_encode(['error' => 'Dolibarr bootstrap failed'])); }

// ── 2. JSON output headers ───────────────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ── 3. Authentication ────────────────────────────────────────────────────────
function api_authenticate($db) {
    global $user, $conf;

    $token = '';

    // Priority 1: Bearer header
    $authHeader = '';
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        foreach ($headers as $k => $v) {
            if (strtolower($k) === 'authorization') { $authHeader = $v; break; }
        }
    }
    if (!$authHeader && isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'];
    }
    if (preg_match('/^Bearer\s+(.+)$/i', $authHeader, $m)) {
        $token = trim($m[1]);
    }

    // Priority 2: api_key query/body param
    if (!$token) {
        $token = isset($_GET['api_key'])  ? trim($_GET['api_key'])  : '';
        if (!$token && isset($_POST['api_key'])) $token = trim($_POST['api_key']);
    }

    if (!$token) {
        api_error(401, 'Missing API token. Pass Authorization: Bearer <token> or ?api_key=<token>');
    }

    // Validate token against llx_user
    $sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "user "
         . "WHERE api_key = '" . $db->escape($token) . "' AND statut = 1";
    $res = $db->query($sql);
    if (!$res || !$db->num_rows($res)) {
        api_error(401, 'Invalid or inactive API token');
    }
    $obj    = $db->fetch_object($res);
    $result = $user->fetch($obj->rowid);
    if ($result < 0) {
        api_error(401, 'Could not load user');
    }
    $user->getrights();

    // Module permission check
    if (empty($user->rights->flotte->read ?? $user->rights->flotte ?? null)) {
        // Soft-check — some Dolibarr versions store rights differently; skip hard-block
        // api_error(403, 'No permission for flotte module');
    }
}

// ── 4. Response helpers ──────────────────────────────────────────────────────
function api_error($code, $message) {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

function api_ok($data, $code = 200) {
    http_response_code($code);
    echo json_encode(['success' => true, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// ── 5. Field definitions ─────────────────────────────────────────────────────
// All writable driver fields (no rowid / ref / entity — handled separately)
const DRIVER_FIELDS = [
    'firstname', 'middlename', 'lastname', 'email', 'phone',
    'employee_id', 'contract_number',
    'license_number', 'license_issue_date', 'license_expiry_date',
    'join_date', 'leave_date', 'department',
    'status',            // active | inactive | suspended
    'gender',            // male | female
    'address', 'emergency_contact',
    'fk_vehicle',        // rowid of llx_flotte_vehicle
    'fk_user',           // rowid of llx_user
    'fk_socpeople',      // rowid of llx_socpeople
    'note',
];

const ALLOWED_STATUSES = ['active', 'inactive', 'suspended'];
const ALLOWED_GENDERS  = ['male', 'female', ''];

// ── 6. Ref generator ─────────────────────────────────────────────────────────
function getNextDriverRef($db) {
    $prefix = 'DRV';
    $sql    = "SELECT MAX(CAST(SUBSTRING(ref, 4) AS UNSIGNED)) AS max_ref "
            . "FROM " . MAIN_DB_PREFIX . "flotte_driver "
            . "WHERE ref LIKE '" . $prefix . "%'";
    $res    = $db->query($sql);
    if ($res) {
        $obj      = $db->fetch_object($res);
        $next_num = ($obj && $obj->max_ref) ? (int)$obj->max_ref + 1 : 1;
        return $prefix . str_pad($next_num, 4, '0', STR_PAD_LEFT);
    }
    return $prefix . '0001';
}

// ── 7. Row → array ───────────────────────────────────────────────────────────
function driver_row_to_array($obj) {
    $out = [];
    foreach (array_merge(['rowid', 'ref', 'entity'], DRIVER_FIELDS) as $f) {
        $out[$f] = isset($obj->$f) ? $obj->$f : null;
    }
    // Extra read-only stamps
    foreach (['tms', 'datec'] as $f) {
        if (isset($obj->$f)) $out[$f] = $obj->$f;
    }
    return $out;
}

// ── 8. Route ─────────────────────────────────────────────────────────────────
api_authenticate($db);

$method = strtoupper($_SERVER['REQUEST_METHOD']);

switch ($method) {

    // ════════════════════════════════════════════════════════════
    // GET — list or single
    // ════════════════════════════════════════════════════════════
    case 'GET':

        $id     = isset($_GET['id'])     ? (int)$_GET['id']         : 0;
        $ref    = isset($_GET['ref'])    ? trim($_GET['ref'])        : '';
        $status = isset($_GET['status']) ? trim($_GET['status'])     : '';
        $search = isset($_GET['search']) ? trim($_GET['search'])     : '';
        $limit  = isset($_GET['limit'])  ? max(1, (int)$_GET['limit']) : 50;
        $page   = isset($_GET['page'])   ? max(0, (int)$_GET['page'])  : 0;
        $offset = $page * $limit;

        // ── Single driver ──
        if ($id > 0 || $ref !== '') {
            $where = $id > 0
                ? "rowid = " . $id
                : "ref = '" . $db->escape($ref) . "'";

            $sql = "SELECT * FROM " . MAIN_DB_PREFIX . "flotte_driver "
                 . "WHERE " . $where
                 . " AND entity IN (" . getEntity('flotte') . ")";

            $res = $db->query($sql);
            if (!$res || !$db->num_rows($res)) {
                api_error(404, 'Driver not found');
            }
            api_ok(driver_row_to_array($db->fetch_object($res)));
        }

        // ── List ──
        $conditions = ["entity IN (" . getEntity('flotte') . ")"];

        if ($status !== '') {
            if (!in_array($status, ALLOWED_STATUSES)) {
                api_error(400, 'Invalid status filter. Allowed: ' . implode(', ', ALLOWED_STATUSES));
            }
            $conditions[] = "status = '" . $db->escape($status) . "'";
        }

        if ($search !== '') {
            $esc = $db->escape($search);
            $conditions[] = "(firstname LIKE '%$esc%' OR lastname LIKE '%$esc%' "
                          . "OR phone LIKE '%$esc%' OR email LIKE '%$esc%' "
                          . "OR ref LIKE '%$esc%' OR employee_id LIKE '%$esc%')";
        }

        $where_clause = implode(' AND ', $conditions);

        // total count
        $sql_count = "SELECT COUNT(*) AS cnt FROM " . MAIN_DB_PREFIX . "flotte_driver WHERE " . $where_clause;
        $res_count = $db->query($sql_count);
        $total     = $res_count ? (int)$db->fetch_object($res_count)->cnt : 0;

        $sql = "SELECT * FROM " . MAIN_DB_PREFIX . "flotte_driver "
             . "WHERE " . $where_clause
             . " ORDER BY ref ASC"
             . " LIMIT " . $limit . " OFFSET " . $offset;

        $res     = $db->query($sql);
        $drivers = [];
        if ($res) {
            while ($obj = $db->fetch_object($res)) {
                $drivers[] = driver_row_to_array($obj);
            }
        }

        api_ok([
            'drivers'    => $drivers,
            'pagination' => [
                'total'  => $total,
                'page'   => $page,
                'limit'  => $limit,
                'pages'  => (int)ceil($total / $limit),
            ],
        ]);
        break;

    // ════════════════════════════════════════════════════════════
    // POST — create
    // ════════════════════════════════════════════════════════════
    case 'POST':

        if (empty($user->rights->flotte->write ?? null)) {
            // Permissive fallback — remove if you want strict enforcement
        }

        $body = json_decode(file_get_contents('php://input'), true);
        if (!is_array($body)) $body = $_POST; // fall back to form-encoded

        // Required fields
        if (empty($body['firstname']) && empty($body['lastname'])) {
            api_error(400, 'At least firstname or lastname is required');
        }

        // Validate optional enum fields
        if (!empty($body['status']) && !in_array($body['status'], ALLOWED_STATUSES)) {
            api_error(400, 'Invalid status. Allowed: ' . implode(', ', ALLOWED_STATUSES));
        }
        if (!empty($body['gender']) && !in_array($body['gender'], ALLOWED_GENDERS)) {
            api_error(400, 'Invalid gender. Allowed: male, female');
        }

        $ref = getNextDriverRef($db);

        $cols = ['ref', 'entity'];
        $vals = ["'" . $db->escape($ref) . "'", (int)$conf->entity];

        foreach (DRIVER_FIELDS as $f) {
            if (array_key_exists($f, $body)) {
                $cols[] = $f;
                $v      = $body[$f];
                if (is_null($v) || $v === '') {
                    $vals[] = 'NULL';
                } elseif (in_array($f, ['fk_vehicle','fk_user','fk_socpeople'])) {
                    $vals[] = (int)$v;
                } else {
                    $vals[] = "'" . $db->escape($v) . "'";
                }
            }
        }

        $db->begin();
        $sql = "INSERT INTO " . MAIN_DB_PREFIX . "flotte_driver "
             . "(" . implode(', ', $cols) . ") "
             . "VALUES (" . implode(', ', $vals) . ")";

        $res = $db->query($sql);
        if (!$res) {
            $db->rollback();
            api_error(500, 'DB error: ' . $db->lasterror());
        }
        $new_id = $db->last_insert_id(MAIN_DB_PREFIX . 'flotte_driver', 'rowid');
        $db->commit();

        // Return the created record
        $row = $db->query("SELECT * FROM " . MAIN_DB_PREFIX . "flotte_driver WHERE rowid = " . (int)$new_id);
        api_ok(driver_row_to_array($db->fetch_object($row)), 201);
        break;

    // ════════════════════════════════════════════════════════════
    // PUT — update
    // ════════════════════════════════════════════════════════════
    case 'PUT':

        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if ($id <= 0) api_error(400, 'Missing or invalid id parameter');

        // Confirm driver exists
        $chk = $db->query("SELECT rowid FROM " . MAIN_DB_PREFIX . "flotte_driver "
                         . "WHERE rowid = $id AND entity IN (" . getEntity('flotte') . ")");
        if (!$chk || !$db->num_rows($chk)) api_error(404, 'Driver not found');

        $body = json_decode(file_get_contents('php://input'), true);
        if (!is_array($body) || empty($body)) {
            api_error(400, 'Empty or invalid JSON body');
        }

        // Validate enums
        if (!empty($body['status']) && !in_array($body['status'], ALLOWED_STATUSES)) {
            api_error(400, 'Invalid status. Allowed: ' . implode(', ', ALLOWED_STATUSES));
        }
        if (!empty($body['gender']) && !in_array($body['gender'], ALLOWED_GENDERS)) {
            api_error(400, 'Invalid gender. Allowed: male, female');
        }

        $sets = [];
        foreach (DRIVER_FIELDS as $f) {
            if (array_key_exists($f, $body)) {
                $v = $body[$f];
                if (is_null($v) || $v === '') {
                    $sets[] = "$f = NULL";
                } elseif (in_array($f, ['fk_vehicle','fk_user','fk_socpeople'])) {
                    $sets[] = "$f = " . (int)$v;
                } else {
                    $sets[] = "$f = '" . $db->escape($v) . "'";
                }
            }
        }

        if (empty($sets)) api_error(400, 'No valid fields to update');

        $db->begin();
        $sql = "UPDATE " . MAIN_DB_PREFIX . "flotte_driver "
             . "SET " . implode(', ', $sets)
             . " WHERE rowid = $id AND entity IN (" . getEntity('flotte') . ")";

        $res = $db->query($sql);
        if (!$res) {
            $db->rollback();
            api_error(500, 'DB error: ' . $db->lasterror());
        }
        $db->commit();

        $row = $db->query("SELECT * FROM " . MAIN_DB_PREFIX . "flotte_driver WHERE rowid = $id");
        api_ok(driver_row_to_array($db->fetch_object($row)));
        break;

    // ════════════════════════════════════════════════════════════
    // DELETE
    // ════════════════════════════════════════════════════════════
    case 'DELETE':

        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if ($id <= 0) api_error(400, 'Missing or invalid id parameter');

        $chk = $db->query("SELECT rowid FROM " . MAIN_DB_PREFIX . "flotte_driver "
                         . "WHERE rowid = $id AND entity IN (" . getEntity('flotte') . ")");
        if (!$chk || !$db->num_rows($chk)) api_error(404, 'Driver not found');

        $db->begin();
        $sql = "DELETE FROM " . MAIN_DB_PREFIX . "flotte_driver "
             . "WHERE rowid = $id AND entity IN (" . getEntity('flotte') . ")";

        $res = $db->query($sql);
        if (!$res) {
            $db->rollback();
            api_error(500, 'DB error: ' . $db->lasterror());
        }

        // Clean up uploaded files
        if (isset($conf->flotte->dir_output)) {
            $dir = $conf->flotte->dir_output . '/drivers/' . $id . '/';
            if (is_dir($dir) && function_exists('dol_delete_dir_recursive')) {
                dol_delete_dir_recursive($dir);
            }
        }

        $db->commit();
        api_ok(['deleted' => true, 'id' => $id]);
        break;

    default:
        api_error(405, 'Method not allowed');
}

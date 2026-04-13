<?php
/**
 * Vendor REST API — Dolibarr flotte module
 *
 * Place this file in:  htdocs/custom/flotte/api_vendors.php
 *                  or  htdocs/flotte/api_vendors.php
 *
 * ──────────────────────────────────────────────────────────────
 * AUTHENTICATION
 *   Header :  Authorization: Bearer <YOUR_API_TOKEN>
 *   OR Query:  ?api_key=<YOUR_API_TOKEN>
 *
 * ENDPOINTS
 *   GET    api_vendors.php                   → list all vendors
 *   GET    api_vendors.php?id=5              → get one vendor
 *   GET    api_vendors.php?ref=VEND-0003     → get by ref
 *   GET    api_vendors.php?type=Fuel         → filter by type
 *   GET    api_vendors.php?fk_soc=12         → filter by third-party
 *   GET    api_vendors.php?search=station    → search name/email/city
 *   GET    api_vendors.php?page=0&limit=20   → paginate
 *   GET    api_vendors.php?with_bookings=1   → attach booking count + totals
 *   POST   api_vendors.php                   → create vendor
 *   PUT    api_vendors.php?id=5              → update vendor
 *   DELETE api_vendors.php?id=5              → delete vendor
 * ──────────────────────────────────────────────────────────────
 */

// ── 1. Bootstrap Dolibarr ────────────────────────────────────────────────────
define('NOTOKENRENEWAL', 1);
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

// ── 2. JSON output headers ────────────────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ── 3. Authentication ─────────────────────────────────────────────────────────
function api_authenticate($db) {
    global $user;

    $token = '';
    $authHeader = '';
    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) {
            if (strtolower($k) === 'authorization') { $authHeader = $v; break; }
        }
    }
    if (!$authHeader && isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'];
    }
    if (preg_match('/^Bearer\s+(.+)$/i', $authHeader, $m)) {
        $token = trim($m[1]);
    }
    if (!$token) $token = isset($_GET['api_key'])  ? trim($_GET['api_key'])  : '';
    if (!$token) $token = isset($_POST['api_key']) ? trim($_POST['api_key']) : '';

    if (!$token) {
        api_error(401, 'Missing API token. Pass Authorization: Bearer <token> or ?api_key=<token>');
    }

    $sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "user "
         . "WHERE api_key = '" . $db->escape($token) . "' AND statut = 1";
    $res = $db->query($sql);
    if (!$res || !$db->num_rows($res)) {
        api_error(401, 'Invalid or inactive API token');
    }

    $obj = $db->fetch_object($res);
    if ($user->fetch($obj->rowid) < 0) api_error(401, 'Could not load user');
    $user->getrights();
}

// ── 4. Response helpers ───────────────────────────────────────────────────────
function api_error($code, $message) {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

function api_ok($data, $code = 200) {
    http_response_code($code);
    echo json_encode(
        ['success' => true, 'data' => $data],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

// ── 5. Field definitions ──────────────────────────────────────────────────────
// Writable fields (rowid / ref / entity / datec / tms handled separately)
const VENDOR_FIELDS = [
    'fk_soc',       // INT  — rowid of llx_societe (required on create)
    'name',         // VARCHAR — display name (required)
    'phone',
    'email',
    'website',
    'address1',
    'address2',
    'city',
    'state',
    'type',         // Parts | Fuel | Maintenance | Insurance | Service | Other | ''
    'note',
];

const ALLOWED_TYPES = ['Parts', 'Fuel', 'Maintenance', 'Insurance', 'Service', 'Other', ''];

// ── 6. Ref generator ─────────────────────────────────────────────────────────
function getNextVendorRef($db) {
    global $conf;
    $prefix = 'VEND-';
    $sql = "SELECT ref FROM " . MAIN_DB_PREFIX . "flotte_vendor"
         . " WHERE entity = " . (int)$conf->entity
         . " AND ref LIKE '" . $db->escape($prefix) . "%'"
         . " ORDER BY ref DESC LIMIT 1";
    $res = $db->query($sql);
    if ($res && $db->num_rows($res) > 0) {
        $obj  = $db->fetch_object($res);
        $next = (int)str_replace($prefix, '', $obj->ref) + 1;
    } else {
        $next = 1;
    }
    return $prefix . str_pad($next, 4, '0', STR_PAD_LEFT);
}

// ── 7. Row → array ────────────────────────────────────────────────────────────
function vendor_row_to_array($obj) {
    $out = [];
    foreach (array_merge(['rowid', 'ref', 'entity'], VENDOR_FIELDS) as $f) {
        $out[$f] = isset($obj->$f) ? $obj->$f : null;
    }
    foreach (['datec', 'tms', 'fk_user_author', 'fk_user_modif'] as $f) {
        if (isset($obj->$f)) $out[$f] = $obj->$f;
    }
    return $out;
}

// ── 8. Booking summary for a vendor ──────────────────────────────────────────
function get_booking_summary($db, $vendor_id) {
    $sql = "SELECT "
         . " COUNT(*) AS total_bookings,"
         . " SUM(buying_amount)  AS total_buying,"
         . " SUM(selling_amount) AS total_selling,"
         . " MAX(booking_date)   AS last_booking_date"
         . " FROM " . MAIN_DB_PREFIX . "flotte_booking"
         . " WHERE fk_vendor = " . (int)$vendor_id
         . " AND entity IN (" . getEntity('flotte') . ")";
    $res = $db->query($sql);
    if ($res) {
        $obj = $db->fetch_object($res);
        return [
            'total_bookings'    => (int)$obj->total_bookings,
            'total_buying'      => $obj->total_buying  !== null ? (float)$obj->total_buying  : null,
            'total_selling'     => $obj->total_selling !== null ? (float)$obj->total_selling : null,
            'last_booking_date' => $obj->last_booking_date,
        ];
    }
    return null;
}

// ── 9. Validate type value ────────────────────────────────────────────────────
function validate_type($type) {
    if (!in_array($type, ALLOWED_TYPES, true)) {
        api_error(400, 'Invalid type. Allowed: ' . implode(', ', array_filter(ALLOWED_TYPES)));
    }
}

// ── 10. Route ─────────────────────────────────────────────────────────────────
api_authenticate($db);

$method = strtoupper($_SERVER['REQUEST_METHOD']);

switch ($method) {

    // ════════════════════════════════════════════════════════════
    // GET — list or single
    // ════════════════════════════════════════════════════════════
    case 'GET':

        $id             = isset($_GET['id'])     ? (int)$_GET['id']        : 0;
        $ref            = isset($_GET['ref'])    ? trim($_GET['ref'])       : '';
        $type           = isset($_GET['type'])   ? trim($_GET['type'])      : '';
        $fk_soc         = isset($_GET['fk_soc']) ? (int)$_GET['fk_soc']   : 0;
        $search         = isset($_GET['search']) ? trim($_GET['search'])    : '';
        $with_bookings  = !empty($_GET['with_bookings']);
        $limit          = isset($_GET['limit'])  ? max(1, (int)$_GET['limit']) : 50;
        $page           = isset($_GET['page'])   ? max(0, (int)$_GET['page'])  : 0;
        $offset         = $page * $limit;

        // ── Single vendor ──
        if ($id > 0 || $ref !== '') {
            $where = $id > 0
                ? "rowid = $id"
                : "ref = '" . $db->escape($ref) . "'";

            $sql = "SELECT * FROM " . MAIN_DB_PREFIX . "flotte_vendor "
                 . "WHERE $where AND entity IN (" . getEntity('flotte') . ")";

            $res = $db->query($sql);
            if (!$res || !$db->num_rows($res)) api_error(404, 'Vendor not found');

            $vendor = vendor_row_to_array($db->fetch_object($res));
            if ($with_bookings) {
                $vendor['booking_summary'] = get_booking_summary($db, $vendor['rowid']);
            }
            api_ok($vendor);
        }

        // ── List ──
        $conditions = ["entity IN (" . getEntity('flotte') . ")"];

        if ($type !== '') {
            validate_type($type);
            $conditions[] = "type = '" . $db->escape($type) . "'";
        }
        if ($fk_soc > 0) {
            $conditions[] = "fk_soc = $fk_soc";
        }
        if ($search !== '') {
            $esc = $db->escape($search);
            $conditions[] = "(name LIKE '%$esc%'"
                          . " OR email LIKE '%$esc%'"
                          . " OR phone LIKE '%$esc%'"
                          . " OR city LIKE '%$esc%'"
                          . " OR ref LIKE '%$esc%')";
        }

        $where_clause = implode(' AND ', $conditions);

        $res_count = $db->query(
            "SELECT COUNT(*) AS cnt FROM " . MAIN_DB_PREFIX . "flotte_vendor WHERE $where_clause"
        );
        $total = $res_count ? (int)$db->fetch_object($res_count)->cnt : 0;

        $sql = "SELECT * FROM " . MAIN_DB_PREFIX . "flotte_vendor "
             . "WHERE $where_clause "
             . "ORDER BY name ASC "
             . "LIMIT $limit OFFSET $offset";

        $res     = $db->query($sql);
        $vendors = [];
        if ($res) {
            while ($obj = $db->fetch_object($res)) {
                $v = vendor_row_to_array($obj);
                if ($with_bookings) {
                    $v['booking_summary'] = get_booking_summary($db, $v['rowid']);
                }
                $vendors[] = $v;
            }
        }

        api_ok([
            'vendors'    => $vendors,
            'pagination' => [
                'total' => $total,
                'page'  => $page,
                'limit' => $limit,
                'pages' => (int)ceil($total / $limit),
            ],
        ]);
        break;

    // ════════════════════════════════════════════════════════════
    // POST — create
    // ════════════════════════════════════════════════════════════
    case 'POST':

        $body = json_decode(file_get_contents('php://input'), true);
        if (!is_array($body)) $body = $_POST;

        // Required fields
        $missing = [];
        if (empty($body['fk_soc'])) $missing[] = 'fk_soc';
        if (empty($body['name']))   $missing[] = 'name';
        if (!empty($missing)) {
            api_error(400, 'Required fields missing: ' . implode(', ', $missing));
        }

        // Validate type
        if (!empty($body['type'])) validate_type($body['type']);

        // Validate email format
        if (!empty($body['email']) && !filter_var($body['email'], FILTER_VALIDATE_EMAIL)) {
            api_error(400, 'Invalid email format');
        }

        // Duplicate fk_soc check
        $dup = $db->query(
            "SELECT rowid FROM " . MAIN_DB_PREFIX . "flotte_vendor"
            . " WHERE fk_soc = " . (int)$body['fk_soc']
            . " AND entity = " . (int)$conf->entity
        );
        if ($dup && $db->num_rows($dup) > 0) {
            api_error(409, 'A vendor for this third-party (fk_soc=' . (int)$body['fk_soc'] . ') already exists');
        }

        $ref = !empty($body['ref']) ? trim($body['ref']) : getNextVendorRef($db);

        $cols = ['ref', 'entity', 'fk_user_author', 'datec'];
        $vals = [
            "'" . $db->escape($ref) . "'",
            (int)$conf->entity,
            (int)$user->id,
            "'" . $db->idate(dol_now()) . "'",
        ];

        foreach (VENDOR_FIELDS as $f) {
            if (array_key_exists($f, $body)) {
                $cols[] = $f;
                $v = $body[$f];
                if (is_null($v) || $v === '') {
                    $vals[] = 'NULL';
                } elseif ($f === 'fk_soc') {
                    $vals[] = (int)$v;
                } else {
                    $vals[] = "'" . $db->escape($v) . "'";
                }
            }
        }

        $db->begin();
        $sql = "INSERT INTO " . MAIN_DB_PREFIX . "flotte_vendor "
             . "(" . implode(', ', $cols) . ") "
             . "VALUES (" . implode(', ', $vals) . ")";

        $res = $db->query($sql);
        if (!$res) {
            $db->rollback();
            api_error(500, 'DB error: ' . $db->lasterror());
        }
        $new_id = $db->last_insert_id(MAIN_DB_PREFIX . 'flotte_vendor', 'rowid');
        $db->commit();

        $row = $db->query(
            "SELECT * FROM " . MAIN_DB_PREFIX . "flotte_vendor WHERE rowid = " . (int)$new_id
        );
        api_ok(vendor_row_to_array($db->fetch_object($row)), 201);
        break;

    // ════════════════════════════════════════════════════════════
    // PUT — update
    // ════════════════════════════════════════════════════════════
    case 'PUT':

        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if ($id <= 0) api_error(400, 'Missing or invalid id parameter');

        $chk = $db->query(
            "SELECT rowid FROM " . MAIN_DB_PREFIX . "flotte_vendor "
            . "WHERE rowid = $id AND entity IN (" . getEntity('flotte') . ")"
        );
        if (!$chk || !$db->num_rows($chk)) api_error(404, 'Vendor not found');

        $body = json_decode(file_get_contents('php://input'), true);
        if (!is_array($body) || empty($body)) api_error(400, 'Empty or invalid JSON body');

        // Validate if provided
        if (isset($body['name']) && empty($body['name'])) {
            api_error(400, 'name cannot be empty');
        }
        if (!empty($body['type']))  validate_type($body['type']);
        if (!empty($body['email']) && !filter_var($body['email'], FILTER_VALIDATE_EMAIL)) {
            api_error(400, 'Invalid email format');
        }

        // Note: fk_soc is intentionally not updatable (same as the UI behaviour)
        $updateable = array_diff(VENDOR_FIELDS, ['fk_soc']);

        $sets = ["fk_user_modif = " . (int)$user->id];
        foreach ($updateable as $f) {
            if (array_key_exists($f, $body)) {
                $v = $body[$f];
                $sets[] = (is_null($v) || $v === '')
                    ? "$f = NULL"
                    : "$f = '" . $db->escape($v) . "'";
            }
        }

        if (count($sets) <= 1) api_error(400, 'No valid fields to update');

        $db->begin();
        $sql = "UPDATE " . MAIN_DB_PREFIX . "flotte_vendor "
             . "SET " . implode(', ', $sets)
             . " WHERE rowid = $id AND entity IN (" . getEntity('flotte') . ")";

        $res = $db->query($sql);
        if (!$res) {
            $db->rollback();
            api_error(500, 'DB error: ' . $db->lasterror());
        }
        $db->commit();

        $row = $db->query(
            "SELECT * FROM " . MAIN_DB_PREFIX . "flotte_vendor WHERE rowid = $id"
        );
        api_ok(vendor_row_to_array($db->fetch_object($row)));
        break;

    // ════════════════════════════════════════════════════════════
    // DELETE
    // ════════════════════════════════════════════════════════════
    case 'DELETE':

        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if ($id <= 0) api_error(400, 'Missing or invalid id parameter');

        $chk = $db->query(
            "SELECT rowid FROM " . MAIN_DB_PREFIX . "flotte_vendor "
            . "WHERE rowid = $id AND entity IN (" . getEntity('flotte') . ")"
        );
        if (!$chk || !$db->num_rows($chk)) api_error(404, 'Vendor not found');

        // Safety: warn if vendor has linked bookings
        $linked = $db->query(
            "SELECT COUNT(*) AS cnt FROM " . MAIN_DB_PREFIX . "flotte_booking "
            . "WHERE fk_vendor = $id AND entity IN (" . getEntity('flotte') . ")"
        );
        if ($linked) {
            $cnt = (int)$db->fetch_object($linked)->cnt;
            if ($cnt > 0) {
                // Allow forced delete with ?force=1, otherwise block
                if (empty($_GET['force'])) {
                    api_error(409, "Vendor has $cnt linked booking(s). Add ?force=1 to delete anyway.");
                }
            }
        }

        $db->begin();
        $res = $db->query(
            "DELETE FROM " . MAIN_DB_PREFIX . "flotte_vendor "
            . "WHERE rowid = $id AND entity IN (" . getEntity('flotte') . ")"
        );
        if (!$res) {
            $db->rollback();
            api_error(500, 'DB error: ' . $db->lasterror());
        }
        $db->commit();

        api_ok(['deleted' => true, 'id' => $id]);
        break;

    default:
        api_error(405, 'Method not allowed');
}

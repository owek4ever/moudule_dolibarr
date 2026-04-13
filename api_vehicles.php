<?php
/**
 * Vehicle REST API — Dolibarr flotte module
 *
 * Place this file in:  htdocs/custom/flotte/api_vehicles.php
 *                  or  htdocs/flotte/api_vehicles.php
 *
 * ──────────────────────────────────────────────────────────────
 * AUTHENTICATION
 *   Header :  Authorization: Bearer <YOUR_API_TOKEN>
 *   OR Query:  ?api_key=<YOUR_API_TOKEN>
 *
 * ENDPOINTS
 *   GET    api_vehicles.php                              → list all vehicles
 *   GET    api_vehicles.php?id=5                         → get one vehicle by id
 *   GET    api_vehicles.php?ref=VEH-0003                 → get by ref
 *   GET    api_vehicles.php?status=1                     → filter by in_service (1=yes, 0=no)
 *   GET    api_vehicles.php?maker=Toyota                 → filter by maker
 *   GET    api_vehicles.php?type=truck                   → filter by type
 *   GET    api_vehicles.php?department=logistics         → filter by department
 *   GET    api_vehicles.php?search=ABC123                → search ref/plate/VIN/maker/model
 *   GET    api_vehicles.php?registration_expiry_before=2025-12-31  → expiring registrations
 *   GET    api_vehicles.php?insurance_expiry_before=2025-12-31     → expiring insurance
 *   GET    api_vehicles.php?license_expiry_before=2025-12-31       → expiring licenses
 *   GET    api_vehicles.php?page=0&limit=20             → paginate
 *   GET    api_vehicles.php?include_bookings=1           → attach recent bookings
 *   POST   api_vehicles.php                              → create vehicle
 *   PUT    api_vehicles.php?id=5                         → update vehicle (full or partial)
 *   DELETE api_vehicles.php?id=5                         → delete vehicle
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

    if (!$token) api_error(401, 'Missing API token. Pass Authorization: Bearer <token> or ?api_key=<token>');

    $sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "user "
         . "WHERE api_key = '" . $db->escape($token) . "' AND statut = 1";
    $res = $db->query($sql);
    if (!$res || !$db->num_rows($res)) api_error(401, 'Invalid or inactive API token');

    $obj = $db->fetch_object($res);
    if ($user->fetch($obj->rowid) < 0) api_error(401, 'Could not load user');
    $user->getrights();
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

// ── 5. Field definitions (mirrors vehicle_card.php exactly) ──────────────────

// Integer / FK fields
const FK_FIELDS = ['fk_user_author', 'year', 'initial_mileage', 'in_service', 'horsepower'];

// Decimal fields
const DECIMAL_FIELDS = ['length_cm', 'width_cm', 'height_cm', 'max_weight_kg', 'ground_height_cm'];

// Date fields (stored as Unix timestamp in Dolibarr, sent as YYYY-MM-DD via API)
const DATE_FIELDS = ['registration_expiry', 'license_expiry', 'insurance_expiry'];

// All writable fields — ref / entity / rowid handled separately
const VEHICLE_FIELDS = [
    // Identity
    'maker',                    // required
    'model',                    // required
    'type',
    'year',
    'color',
    // IDs
    'vin',
    'license_plate',
    // Service
    'in_service',               // 1 = yes, 0 = no
    'department',
    'initial_mileage',
    // Engine
    'engine_type',
    'horsepower',
    // Dates
    'registration_expiry',      // YYYY-MM-DD
    'license_expiry',           // YYYY-MM-DD
    'insurance_expiry',         // YYYY-MM-DD
    // Dimensions & specs
    'length_cm',
    'width_cm',
    'height_cm',
    'max_weight_kg',
    'ground_height_cm',
    // Documents (stored filenames, uploaded separately)
    'vehicle_photo',
    'registration_card',
    'platform_registration_card',
    'insurance_document',
    // Author
    'fk_user_author',
];

// ── 6. Ref generator ─────────────────────────────────────────────────────────
function getNextVehicleRef($db) {
    global $conf;
    $prefix = 'VEH-';
    $sql = "SELECT ref FROM " . MAIN_DB_PREFIX . "flotte_vehicle"
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

// ── 7. Row → array ───────────────────────────────────────────────────────────
function vehicle_row_to_array($obj) {
    $out = [];
    $all = array_merge(['rowid', 'ref', 'entity'], VEHICLE_FIELDS);
    foreach ($all as $f) {
        $out[$f] = isset($obj->$f) ? $obj->$f : null;
    }
    // Cast numeric fields
    foreach (['rowid', 'year', 'initial_mileage', 'horsepower'] as $f) {
        if (!is_null($out[$f])) $out[$f] = (int)$out[$f];
    }
    $out['in_service'] = isset($obj->in_service) ? (bool)$obj->in_service : false;
    foreach (DECIMAL_FIELDS as $f) {
        if (!is_null($out[$f])) $out[$f] = (float)$out[$f];
    }
    // Expose date fields as YYYY-MM-DD strings (Dolibarr stores as Unix ts)
    foreach (DATE_FIELDS as $f) {
        if (!empty($obj->$f)) {
            $out[$f] = date('Y-m-d', is_numeric($obj->$f) ? $obj->$f : strtotime($obj->$f));
        }
    }
    // Timestamps
    foreach (['tms', 'date_creation'] as $f) {
        if (isset($obj->$f)) $out[$f] = $obj->$f;
    }
    return $out;
}

// ── 8. SQL value escaping ─────────────────────────────────────────────────────
function field_sql_value($field, $value, $db) {
    if (is_null($value) || $value === '') {
        return 'NULL';
    }
    if (in_array($field, FK_FIELDS)) {
        return (int)$value;
    }
    if (in_array($field, DECIMAL_FIELDS)) {
        return (float)$value;
    }
    if (in_array($field, DATE_FIELDS)) {
        // Accept YYYY-MM-DD → convert to Unix timestamp (Dolibarr style)
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return (int)strtotime($value);
        }
        return 'NULL';
    }
    return "'" . $db->escape($value) . "'";
}

// ── 9. Authenticate ──────────────────────────────────────────────────────────
api_authenticate($db);

// ── 10. Route ────────────────────────────────────────────────────────────────
$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {

    // ════════════════════════════════════════════════════════════
    // GET — list or single
    // ════════════════════════════════════════════════════════════
    case 'GET':

        $id                        = isset($_GET['id'])                          ? (int)$_GET['id']                          : 0;
        $ref                       = isset($_GET['ref'])                          ? trim($_GET['ref'])                         : '';
        $in_service_filter         = isset($_GET['status'])                       ? $_GET['status']                            : null;
        $maker_filter              = isset($_GET['maker'])                         ? trim($_GET['maker'])                       : '';
        $type_filter               = isset($_GET['type'])                          ? trim($_GET['type'])                        : '';
        $department_filter         = isset($_GET['department'])                    ? trim($_GET['department'])                  : '';
        $search                    = isset($_GET['search'])                        ? trim($_GET['search'])                      : '';
        $reg_expiry_before         = isset($_GET['registration_expiry_before'])    ? trim($_GET['registration_expiry_before'])  : '';
        $ins_expiry_before         = isset($_GET['insurance_expiry_before'])       ? trim($_GET['insurance_expiry_before'])     : '';
        $lic_expiry_before         = isset($_GET['license_expiry_before'])         ? trim($_GET['license_expiry_before'])       : '';
        $include_bookings          = !empty($_GET['include_bookings']);
        $page                      = isset($_GET['page'])  ? max(0, (int)$_GET['page'])       : 0;
        $limit                     = isset($_GET['limit']) ? min(200, max(1, (int)$_GET['limit'])) : 50;
        $offset                    = $page * $limit;

        // ── Single record ──────────────────────────────────────
        if ($id > 0 || $ref !== '') {
            $where = $id > 0
                ? "rowid = $id"
                : "ref = '" . $db->escape($ref) . "'";
            $where .= " AND entity IN (" . getEntity('flotte') . ")";

            $res = $db->query("SELECT * FROM " . MAIN_DB_PREFIX . "flotte_vehicle WHERE $where LIMIT 1");
            if (!$res || !$db->num_rows($res)) {
                api_error(404, 'Vehicle not found');
            }
            $vehicle = vehicle_row_to_array($db->fetch_object($res));

            // Optionally attach last 10 bookings for this vehicle
            if ($include_bookings && $vehicle['rowid']) {
                $bsql = "SELECT rowid, ref, booking_date, status, departure_address, arriving_address, fk_driver "
                      . "FROM " . MAIN_DB_PREFIX . "flotte_booking "
                      . "WHERE fk_vehicle = " . (int)$vehicle['rowid']
                      . " AND entity IN (" . getEntity('flotte') . ")"
                      . " ORDER BY booking_date DESC LIMIT 10";
                $bres = $db->query($bsql);
                $bookings = [];
                if ($bres) {
                    while ($bobj = $db->fetch_object($bres)) {
                        $bookings[] = [
                            'rowid'             => (int)$bobj->rowid,
                            'ref'               => $bobj->ref,
                            'booking_date'      => $bobj->booking_date,
                            'status'            => $bobj->status,
                            'departure_address' => $bobj->departure_address,
                            'arriving_address'  => $bobj->arriving_address,
                            'fk_driver'         => $bobj->fk_driver ? (int)$bobj->fk_driver : null,
                        ];
                    }
                }
                $vehicle['recent_bookings'] = $bookings;
            }

            api_ok($vehicle);
        }

        // ── List ───────────────────────────────────────────────
        $wheres = ["entity IN (" . getEntity('flotte') . ")"];

        if (!is_null($in_service_filter) && $in_service_filter !== '') {
            $wheres[] = "in_service = " . ($in_service_filter ? 1 : 0);
        }
        if ($maker_filter !== '') {
            $wheres[] = "maker = '" . $db->escape($maker_filter) . "'";
        }
        if ($type_filter !== '') {
            $wheres[] = "type = '" . $db->escape($type_filter) . "'";
        }
        if ($department_filter !== '') {
            $wheres[] = "department = '" . $db->escape($department_filter) . "'";
        }
        if ($search !== '') {
            $s = "'" . $db->escape('%' . $search . '%') . "'";
            $wheres[] = "(ref LIKE $s OR license_plate LIKE $s OR vin LIKE $s"
                      . " OR maker LIKE $s OR model LIKE $s)";
        }
        if ($reg_expiry_before !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $reg_expiry_before)) {
            $wheres[] = "registration_expiry <= " . (int)strtotime($reg_expiry_before);
        }
        if ($ins_expiry_before !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $ins_expiry_before)) {
            $wheres[] = "insurance_expiry <= " . (int)strtotime($ins_expiry_before);
        }
        if ($lic_expiry_before !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $lic_expiry_before)) {
            $wheres[] = "license_expiry <= " . (int)strtotime($lic_expiry_before);
        }

        $where_clause = implode(' AND ', $wheres);

        // Total count
        $cnt   = $db->query("SELECT COUNT(*) AS nb FROM " . MAIN_DB_PREFIX . "flotte_vehicle WHERE $where_clause");
        $total = $cnt ? (int)$db->fetch_object($cnt)->nb : 0;

        // Fetch page
        $sql = "SELECT * FROM " . MAIN_DB_PREFIX . "flotte_vehicle"
             . " WHERE $where_clause"
             . " ORDER BY ref ASC"
             . " LIMIT $limit OFFSET $offset";

        $res = $db->query($sql);
        if (!$res) api_error(500, 'DB error: ' . $db->lasterror());

        $rows = [];
        while ($obj = $db->fetch_object($res)) {
            $vehicle = vehicle_row_to_array($obj);

            if ($include_bookings) {
                $bsql = "SELECT rowid, ref, booking_date, status "
                      . "FROM " . MAIN_DB_PREFIX . "flotte_booking "
                      . "WHERE fk_vehicle = " . (int)$vehicle['rowid']
                      . " AND entity IN (" . getEntity('flotte') . ")"
                      . " ORDER BY booking_date DESC LIMIT 5";
                $bres = $db->query($bsql);
                $bookings = [];
                if ($bres) {
                    while ($bobj = $db->fetch_object($bres)) {
                        $bookings[] = [
                            'rowid'        => (int)$bobj->rowid,
                            'ref'          => $bobj->ref,
                            'booking_date' => $bobj->booking_date,
                            'status'       => $bobj->status,
                        ];
                    }
                }
                $vehicle['recent_bookings'] = $bookings;
            }

            $rows[] = $vehicle;
        }

        api_ok([
            'total' => $total,
            'page'  => $page,
            'limit' => $limit,
            'pages' => $limit > 0 ? (int)ceil($total / $limit) : 1,
            'items' => $rows,
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
        if (empty($body['maker'])) $missing[] = 'maker';
        if (empty($body['model'])) $missing[] = 'model';
        if (!empty($missing)) {
            api_error(400, 'Required fields missing: ' . implode(', ', $missing));
        }

        // Validate date fields
        foreach (DATE_FIELDS as $df) {
            if (!empty($body[$df]) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $body[$df])) {
                api_error(400, "$df must be YYYY-MM-DD");
            }
        }

        // Default in_service to 1
        if (!isset($body['in_service'])) $body['in_service'] = 1;

        $ref = !empty($body['ref']) ? $body['ref'] : getNextVehicleRef($db);

        $cols = ['ref', 'entity'];
        $vals = ["'" . $db->escape($ref) . "'", (int)$conf->entity];

        foreach (VEHICLE_FIELDS as $f) {
            if (array_key_exists($f, $body)) {
                $cols[] = $f;
                $vals[] = field_sql_value($f, $body[$f], $db);
            }
        }

        if (!in_array('fk_user_author', $cols)) {
            $cols[] = 'fk_user_author';
            $vals[] = (int)$user->id;
        }

        $db->begin();
        $sql = "INSERT INTO " . MAIN_DB_PREFIX . "flotte_vehicle "
             . "(" . implode(', ', $cols) . ") "
             . "VALUES (" . implode(', ', $vals) . ")";

        $res = $db->query($sql);
        if (!$res) {
            $db->rollback();
            api_error(500, 'DB error: ' . $db->lasterror());
        }
        $new_id = $db->last_insert_id(MAIN_DB_PREFIX . 'flotte_vehicle', 'rowid');
        $db->commit();

        $row = $db->query("SELECT * FROM " . MAIN_DB_PREFIX . "flotte_vehicle WHERE rowid = " . (int)$new_id);
        api_ok(vehicle_row_to_array($db->fetch_object($row)), 201);
        break;

    // ════════════════════════════════════════════════════════════
    // PUT — update (full or partial)
    // ════════════════════════════════════════════════════════════
    case 'PUT':

        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if ($id <= 0) api_error(400, 'Missing or invalid id parameter');

        $chk = $db->query("SELECT rowid FROM " . MAIN_DB_PREFIX . "flotte_vehicle "
                         . "WHERE rowid = $id AND entity IN (" . getEntity('flotte') . ")");
        if (!$chk || !$db->num_rows($chk)) api_error(404, 'Vehicle not found');

        $body = json_decode(file_get_contents('php://input'), true);
        if (!is_array($body) || empty($body)) api_error(400, 'Empty or invalid JSON body');

        // Validate date fields if present
        foreach (DATE_FIELDS as $df) {
            if (!empty($body[$df]) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $body[$df])) {
                api_error(400, "$df must be YYYY-MM-DD");
            }
        }

        // Prevent overwriting ref / entity / rowid
        unset($body['ref'], $body['entity'], $body['rowid']);

        $sets = [];
        foreach (VEHICLE_FIELDS as $f) {
            if (array_key_exists($f, $body)) {
                $sets[] = "$f = " . field_sql_value($f, $body[$f], $db);
            }
        }

        if (empty($sets)) api_error(400, 'No valid fields to update');

        $db->begin();
        $sql = "UPDATE " . MAIN_DB_PREFIX . "flotte_vehicle "
             . "SET " . implode(', ', $sets)
             . " WHERE rowid = $id AND entity IN (" . getEntity('flotte') . ")";

        $res = $db->query($sql);
        if (!$res) {
            $db->rollback();
            api_error(500, 'DB error: ' . $db->lasterror());
        }
        $db->commit();

        $row = $db->query("SELECT * FROM " . MAIN_DB_PREFIX . "flotte_vehicle WHERE rowid = $id");
        api_ok(vehicle_row_to_array($db->fetch_object($row)));
        break;

    // ════════════════════════════════════════════════════════════
    // DELETE
    // ════════════════════════════════════════════════════════════
    case 'DELETE':

        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if ($id <= 0) api_error(400, 'Missing or invalid id parameter');

        $chk = $db->query("SELECT rowid FROM " . MAIN_DB_PREFIX . "flotte_vehicle "
                         . "WHERE rowid = $id AND entity IN (" . getEntity('flotte') . ")");
        if (!$chk || !$db->num_rows($chk)) api_error(404, 'Vehicle not found');

        // Safety: block deletion if vehicle has active or in_progress bookings
        $active = $db->query(
            "SELECT COUNT(*) AS nb FROM " . MAIN_DB_PREFIX . "flotte_booking "
          . "WHERE fk_vehicle = $id "
          . "AND status IN ('confirmed','in_progress') "
          . "AND entity IN (" . getEntity('flotte') . ")"
        );
        if ($active) {
            $nb = (int)$db->fetch_object($active)->nb;
            if ($nb > 0) {
                api_error(409, "Cannot delete vehicle: $nb confirmed/in-progress booking(s) linked. Reassign or cancel them first.");
            }
        }

        $db->begin();

        // Nullify historical booking references (keep history, detach vehicle)
        $db->query("UPDATE " . MAIN_DB_PREFIX . "flotte_booking "
                 . "SET fk_vehicle = NULL "
                 . "WHERE fk_vehicle = $id AND entity IN (" . getEntity('flotte') . ")");

        $res = $db->query("DELETE FROM " . MAIN_DB_PREFIX . "flotte_vehicle "
                         . "WHERE rowid = $id AND entity IN (" . getEntity('flotte') . ")");
        if (!$res) {
            $db->rollback();
            api_error(500, 'DB error: ' . $db->lasterror());
        }

        // Remove uploaded documents
        if (isset($conf->flotte->dir_output)) {
            $dir = $conf->flotte->dir_output . '/vehicle/' . $id . '/';
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

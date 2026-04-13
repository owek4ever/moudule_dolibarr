<?php
/**
 * Booking REST API — Dolibarr flotte module
 *
 * Place this file in:  htdocs/custom/flotte/api_bookings.php
 *                  or  htdocs/flotte/api_bookings.php
 *
 * ──────────────────────────────────────────────────────────────
 * AUTHENTICATION
 *   Header :  Authorization: Bearer <YOUR_API_TOKEN>
 *   OR Query:  ?api_key=<YOUR_API_TOKEN>
 *
 * ENDPOINTS
 *   GET    api_bookings.php                          → list all bookings
 *   GET    api_bookings.php?id=5                     → get one booking
 *   GET    api_bookings.php?ref=BOOK-0003            → get by ref
 *   GET    api_bookings.php?status=pending           → filter by status
 *   GET    api_bookings.php?fk_vehicle=3             → filter by vehicle
 *   GET    api_bookings.php?fk_driver=7              → filter by driver
 *   GET    api_bookings.php?fk_customer=2            → filter by customer
 *   GET    api_bookings.php?date_from=2024-01-01     → filter from date
 *   GET    api_bookings.php?date_to=2024-12-31       → filter to date
 *   GET    api_bookings.php?search=tunis             → search by address/ref
 *   GET    api_bookings.php?page=0&limit=20          → paginate
 *   GET    api_bookings.php?include_expenses=1       → attach expense rows
 *   POST   api_bookings.php                          → create booking
 *   PUT    api_bookings.php?id=5                     → update booking
 *   DELETE api_bookings.php?id=5                     → delete booking
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

// ── 5. Field definitions ─────────────────────────────────────────────────────

// Foreign-key integer fields
const FK_FIELDS = ['fk_vehicle', 'fk_driver', 'fk_vendor', 'fk_customer', 'fk_user_author', 'expense_fuel_vendor'];

// Decimal fields
const DECIMAL_FIELDS = [
    'distance',
    'buying_amount', 'buying_qty', 'buying_price', 'buying_amount_ttc', 'buying_tax_rate',
    'selling_amount', 'selling_qty', 'selling_price', 'selling_amount_ttc', 'selling_tax_rate',
    'dep_lat', 'dep_lon', 'arr_lat', 'arr_lon', 'eta',
    'expense_fuel', 'expense_fuel_qty', 'expense_fuel_price',
    'expense_road', 'expense_road_toll', 'expense_road_parking', 'expense_road_other',
    'expense_driver', 'expense_driver_salary', 'expense_driver_overnight', 'expense_driver_bonus',
    'expense_commission', 'expense_commission_agent', 'expense_commission_tax', 'expense_commission_other',
];

// All writable fields (ref / entity / rowid handled separately)
const BOOKING_FIELDS = [
    // Relations
    'fk_vehicle', 'fk_driver', 'fk_vendor', 'fk_customer',
    // Core
    'booking_date',       // DATE  YYYY-MM-DD  — required
    'status',             // pending | confirmed | in_progress | completed | cancelled
    'bl_number',
    // Route
    'departure_address', 'dep_lat', 'dep_lon',
    'arriving_address',  'arr_lat', 'arr_lon',
    'stops',              // JSON string or text
    'distance',           // km
    'eta',                // seconds
    'pickup_datetime',    // DATETIME  YYYY-MM-DD HH:MM:SS
    'dropoff_datetime',   // DATETIME  YYYY-MM-DD HH:MM:SS
    // Commercial — buying side
    'buying_amount', 'buying_qty', 'buying_price', 'buying_unit',
    'buying_tax_rate', 'buying_amount_ttc',
    // Commercial — selling side
    'selling_amount', 'selling_qty', 'selling_price', 'selling_unit',
    'selling_tax_rate', 'selling_amount_ttc',
    // Expenses — fuel
    'expense_fuel', 'expense_fuel_qty', 'expense_fuel_price',
    'expense_fuel_type', 'expense_fuel_vendor',
    // Expenses — road
    'expense_road', 'expense_road_toll', 'expense_road_parking', 'expense_road_other',
    // Expenses — driver
    'expense_driver', 'expense_driver_salary', 'expense_driver_overnight', 'expense_driver_bonus',
    // Expenses — commission
    'expense_commission', 'expense_commission_agent',
    'expense_commission_tax', 'expense_commission_other',
    // Author
    'fk_user_author',
];

const ALLOWED_STATUSES = ['pending', 'confirmed', 'in_progress', 'completed', 'cancelled'];

// ── 6. Ref generator ─────────────────────────────────────────────────────────
function getNextBookingRef($db) {
    global $conf;
    $prefix = 'BOOK-';
    $sql = "SELECT ref FROM " . MAIN_DB_PREFIX . "flotte_booking"
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
function booking_row_to_array($obj) {
    $out = [];
    $all = array_merge(['rowid', 'ref', 'entity'], BOOKING_FIELDS);
    foreach ($all as $f) {
        $out[$f] = isset($obj->$f) ? $obj->$f : null;
    }
    foreach (['tms', 'date_creation', 'fk_user_author'] as $f) {
        if (isset($obj->$f)) $out[$f] = $obj->$f;
    }
    return $out;
}

// ── 8. Expense rows for a booking ────────────────────────────────────────────
function get_expenses_for_booking($db, $booking_id) {
    $sql = "SELECT * FROM " . MAIN_DB_PREFIX . "flotte_expense "
         . "WHERE fk_booking = " . (int)$booking_id
         . " ORDER BY rowid ASC";
    $res  = $db->query($sql);
    $rows = [];
    if ($res) {
        while ($obj = $db->fetch_object($res)) {
            $row = [];
            foreach (['rowid','ref','fk_booking','expense_date','category','amount',
                      'fuel_vendor','fuel_type','fuel_qty','fuel_price',
                      'road_toll','road_parking','road_other',
                      'driver_salary','driver_overnight','driver_bonus',
                      'commission_agent','commission_tax','commission_other',
                      'other_label','source','notes'] as $f) {
                $row[$f] = isset($obj->$f) ? $obj->$f : null;
            }
            $rows[] = $row;
        }
    }
    return $rows;
}

// ── 9. Build a safe SET/VALUES fragment for one field ────────────────────────
function field_sql_value($field, $value, $db) {
    if (is_null($value) || $value === '') return 'NULL';
    if (in_array($field, FK_FIELDS))      return (int)$value;
    if (in_array($field, DECIMAL_FIELDS)) return (float)$value;
    return "'" . $db->escape($value) . "'";
}

// ── 10. Route ─────────────────────────────────────────────────────────────────
api_authenticate($db);

$method = strtoupper($_SERVER['REQUEST_METHOD']);

switch ($method) {

    // ════════════════════════════════════════════════════════════
    // GET — list or single
    // ════════════════════════════════════════════════════════════
    case 'GET':

        $id               = isset($_GET['id'])               ? (int)$_GET['id']           : 0;
        $ref              = isset($_GET['ref'])               ? trim($_GET['ref'])          : '';
        $status           = isset($_GET['status'])            ? trim($_GET['status'])       : '';
        $fk_vehicle       = isset($_GET['fk_vehicle'])        ? (int)$_GET['fk_vehicle']   : 0;
        $fk_driver        = isset($_GET['fk_driver'])         ? (int)$_GET['fk_driver']    : 0;
        $fk_customer      = isset($_GET['fk_customer'])       ? (int)$_GET['fk_customer']  : 0;
        $fk_vendor        = isset($_GET['fk_vendor'])         ? (int)$_GET['fk_vendor']    : 0;
        $date_from        = isset($_GET['date_from'])         ? trim($_GET['date_from'])    : '';
        $date_to          = isset($_GET['date_to'])           ? trim($_GET['date_to'])      : '';
        $search           = isset($_GET['search'])            ? trim($_GET['search'])       : '';
        $include_expenses = !empty($_GET['include_expenses']);
        $limit            = isset($_GET['limit']) ? max(1, (int)$_GET['limit']) : 50;
        $page             = isset($_GET['page'])  ? max(0, (int)$_GET['page'])  : 0;
        $offset           = $page * $limit;

        // ── Single booking ──
        if ($id > 0 || $ref !== '') {
            $where = $id > 0
                ? "t.rowid = $id"
                : "t.ref = '" . $db->escape($ref) . "'";

            $sql = "SELECT t.* FROM " . MAIN_DB_PREFIX . "flotte_booking t "
                 . "WHERE $where AND t.entity IN (" . getEntity('flotte') . ")";

            $res = $db->query($sql);
            if (!$res || !$db->num_rows($res)) api_error(404, 'Booking not found');

            $booking = booking_row_to_array($db->fetch_object($res));
            if ($include_expenses) {
                $booking['expenses'] = get_expenses_for_booking($db, $booking['rowid']);
            }
            api_ok($booking);
        }

        // ── List ──
        $conditions = ["t.entity IN (" . getEntity('flotte') . ")"];

        if ($status !== '') {
            if (!in_array($status, ALLOWED_STATUSES)) {
                api_error(400, 'Invalid status. Allowed: ' . implode(', ', ALLOWED_STATUSES));
            }
            $conditions[] = "t.status = '" . $db->escape($status) . "'";
        }
        if ($fk_vehicle  > 0) $conditions[] = "t.fk_vehicle  = $fk_vehicle";
        if ($fk_driver   > 0) $conditions[] = "t.fk_driver   = $fk_driver";
        if ($fk_customer > 0) $conditions[] = "t.fk_customer = $fk_customer";
        if ($fk_vendor   > 0) $conditions[] = "t.fk_vendor   = $fk_vendor";

        if ($date_from !== '') {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) api_error(400, 'date_from must be YYYY-MM-DD');
            $conditions[] = "t.booking_date >= '" . $db->escape($date_from) . "'";
        }
        if ($date_to !== '') {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) api_error(400, 'date_to must be YYYY-MM-DD');
            $conditions[] = "t.booking_date <= '" . $db->escape($date_to) . "'";
        }

        if ($search !== '') {
            $esc = $db->escape($search);
            $conditions[] = "(t.ref LIKE '%$esc%' "
                          . "OR t.departure_address LIKE '%$esc%' "
                          . "OR t.arriving_address  LIKE '%$esc%' "
                          . "OR t.bl_number LIKE '%$esc%')";
        }

        $where_clause = implode(' AND ', $conditions);

        // total count
        $res_count = $db->query("SELECT COUNT(*) AS cnt FROM " . MAIN_DB_PREFIX . "flotte_booking t WHERE $where_clause");
        $total     = $res_count ? (int)$db->fetch_object($res_count)->cnt : 0;

        $sql = "SELECT t.* FROM " . MAIN_DB_PREFIX . "flotte_booking t "
             . "WHERE $where_clause "
             . "ORDER BY t.booking_date DESC, t.rowid DESC "
             . "LIMIT $limit OFFSET $offset";

        $res      = $db->query($sql);
        $bookings = [];
        if ($res) {
            while ($obj = $db->fetch_object($res)) {
                $b = booking_row_to_array($obj);
                if ($include_expenses) {
                    $b['expenses'] = get_expenses_for_booking($db, $b['rowid']);
                }
                $bookings[] = $b;
            }
        }

        api_ok([
            'bookings'   => $bookings,
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

        $body = json_decode(file_get_contents('php://input'), true);
        if (!is_array($body)) $body = $_POST;

        // Required fields
        $missing = [];
        if (empty($body['fk_vehicle']))   $missing[] = 'fk_vehicle';
        if (empty($body['fk_customer']))  $missing[] = 'fk_customer';
        if (empty($body['booking_date'])) $missing[] = 'booking_date';
        if (!empty($missing)) {
            api_error(400, 'Required fields missing: ' . implode(', ', $missing));
        }

        // Validate date format
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $body['booking_date'])) {
            api_error(400, 'booking_date must be YYYY-MM-DD');
        }

        // Validate status
        $body['status'] = !empty($body['status']) ? $body['status'] : 'pending';
        if (!in_array($body['status'], ALLOWED_STATUSES)) {
            api_error(400, 'Invalid status. Allowed: ' . implode(', ', ALLOWED_STATUSES));
        }

        // Auto-compute expense totals if sub-fields provided but totals missing
        _auto_compute_expense_totals($body);

        $ref = !empty($body['ref']) ? $body['ref'] : getNextBookingRef($db);

        $cols = ['ref', 'entity'];
        $vals = ["'" . $db->escape($ref) . "'", (int)$conf->entity];

        foreach (BOOKING_FIELDS as $f) {
            if (array_key_exists($f, $body)) {
                $cols[] = $f;
                $vals[] = field_sql_value($f, $body[$f], $db);
            }
        }

        // Stamp the author if not explicitly set
        if (!in_array('fk_user_author', $cols)) {
            $cols[] = 'fk_user_author';
            $vals[] = (int)$user->id;
        }

        $db->begin();
        $sql = "INSERT INTO " . MAIN_DB_PREFIX . "flotte_booking "
             . "(" . implode(', ', $cols) . ") "
             . "VALUES (" . implode(', ', $vals) . ")";

        $res = $db->query($sql);
        if (!$res) {
            $db->rollback();
            api_error(500, 'DB error: ' . $db->lasterror());
        }
        $new_id = $db->last_insert_id(MAIN_DB_PREFIX . 'flotte_booking', 'rowid');
        $db->commit();

        $row = $db->query("SELECT * FROM " . MAIN_DB_PREFIX . "flotte_booking WHERE rowid = " . (int)$new_id);
        api_ok(booking_row_to_array($db->fetch_object($row)), 201);
        break;

    // ════════════════════════════════════════════════════════════
    // PUT — update
    // ════════════════════════════════════════════════════════════
    case 'PUT':

        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if ($id <= 0) api_error(400, 'Missing or invalid id parameter');

        $chk = $db->query("SELECT rowid FROM " . MAIN_DB_PREFIX . "flotte_booking "
                         . "WHERE rowid = $id AND entity IN (" . getEntity('flotte') . ")");
        if (!$chk || !$db->num_rows($chk)) api_error(404, 'Booking not found');

        $body = json_decode(file_get_contents('php://input'), true);
        if (!is_array($body) || empty($body)) api_error(400, 'Empty or invalid JSON body');

        // Validate fields if present
        if (!empty($body['status']) && !in_array($body['status'], ALLOWED_STATUSES)) {
            api_error(400, 'Invalid status. Allowed: ' . implode(', ', ALLOWED_STATUSES));
        }
        if (!empty($body['booking_date']) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $body['booking_date'])) {
            api_error(400, 'booking_date must be YYYY-MM-DD');
        }

        _auto_compute_expense_totals($body);

        $sets = [];
        foreach (BOOKING_FIELDS as $f) {
            if (array_key_exists($f, $body)) {
                $sets[] = "$f = " . field_sql_value($f, $body[$f], $db);
            }
        }

        if (empty($sets)) api_error(400, 'No valid fields to update');

        $db->begin();
        $sql = "UPDATE " . MAIN_DB_PREFIX . "flotte_booking "
             . "SET " . implode(', ', $sets)
             . " WHERE rowid = $id AND entity IN (" . getEntity('flotte') . ")";

        $res = $db->query($sql);
        if (!$res) {
            $db->rollback();
            api_error(500, 'DB error: ' . $db->lasterror());
        }
        $db->commit();

        $row = $db->query("SELECT * FROM " . MAIN_DB_PREFIX . "flotte_booking WHERE rowid = $id");
        api_ok(booking_row_to_array($db->fetch_object($row)));
        break;

    // ════════════════════════════════════════════════════════════
    // DELETE
    // ════════════════════════════════════════════════════════════
    case 'DELETE':

        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if ($id <= 0) api_error(400, 'Missing or invalid id parameter');

        $chk = $db->query("SELECT rowid FROM " . MAIN_DB_PREFIX . "flotte_booking "
                         . "WHERE rowid = $id AND entity IN (" . getEntity('flotte') . ")");
        if (!$chk || !$db->num_rows($chk)) api_error(404, 'Booking not found');

        $db->begin();

        // Delete linked expense rows first (booking-sourced)
        $db->query("DELETE FROM " . MAIN_DB_PREFIX . "flotte_expense "
                 . "WHERE fk_booking = $id AND source = 'booking'");

        $res = $db->query("DELETE FROM " . MAIN_DB_PREFIX . "flotte_booking "
                         . "WHERE rowid = $id AND entity IN (" . getEntity('flotte') . ")");
        if (!$res) {
            $db->rollback();
            api_error(500, 'DB error: ' . $db->lasterror());
        }

        // Clean up uploaded files
        if (isset($conf->flotte->dir_output)) {
            $dir = $conf->flotte->dir_output . '/bookings/' . $id . '/';
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

// ── 11. Helpers ───────────────────────────────────────────────────────────────

/**
 * Auto-compute expense totals from sub-fields if the caller omits them.
 * Mirrors the logic in booking_card.php action=add.
 */
function _auto_compute_expense_totals(&$body) {
    // Fuel: qty × price
    if (empty($body['expense_fuel'])
        && !empty($body['expense_fuel_qty'])
        && !empty($body['expense_fuel_price'])) {
        $body['expense_fuel'] = round((float)$body['expense_fuel_qty'] * (float)$body['expense_fuel_price'], 2);
    }

    // Road: sum of sub-fields
    if (empty($body['expense_road'])
        && (isset($body['expense_road_toll']) || isset($body['expense_road_parking']) || isset($body['expense_road_other']))) {
        $body['expense_road'] = round(
            (float)($body['expense_road_toll']    ?? 0) +
            (float)($body['expense_road_parking'] ?? 0) +
            (float)($body['expense_road_other']   ?? 0), 2);
    }

    // Driver: sum of sub-fields
    if (empty($body['expense_driver'])
        && (isset($body['expense_driver_salary']) || isset($body['expense_driver_overnight']) || isset($body['expense_driver_bonus']))) {
        $body['expense_driver'] = round(
            (float)($body['expense_driver_salary']    ?? 0) +
            (float)($body['expense_driver_overnight'] ?? 0) +
            (float)($body['expense_driver_bonus']     ?? 0), 2);
    }

    // Commission: agent + (agent × tax%) + other
    if (empty($body['expense_commission']) && isset($body['expense_commission_agent'])) {
        $agent   = (float)($body['expense_commission_agent'] ?? 0);
        $taxRate = (float)($body['expense_commission_tax']   ?? 0);
        $other   = (float)($body['expense_commission_other'] ?? 0);
        $taxAmt  = round($agent * $taxRate / 100, 2);
        $body['expense_commission'] = round($agent + $taxAmt + $other, 2);
    }
}

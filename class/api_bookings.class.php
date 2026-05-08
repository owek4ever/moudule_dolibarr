<?php
/**
 * Bookings REST API — Dolibarr flotte module
 *
 * File must be placed at:  htdocs/custom/flotte/class/api_bookings.class.php
 *
 * Endpoints (via Dolibarr API explorer at /api/index.php/explorer):
 *   GET    /bookings/                   → list bookings (with filters)
 *   GET    /bookings/{id}               → get one booking
 *   POST   /bookings/                   → create booking
 *   PUT    /bookings/{id}               → update booking
 *   DELETE /bookings/{id}               → delete booking
 */

if (!defined('NOREQUIREMENU'))  define('NOREQUIREMENU', 1);
if (!defined('NOREQUIREHTML'))  define('NOREQUIREHTML', 1);
if (!defined('NOREQUIREAJAX'))  define('NOREQUIREAJAX', 1);
if (!defined('NOCSRFCHECK'))    define('NOCSRFCHECK', 1);

require_once DOL_DOCUMENT_ROOT . '/api/class/api.class.php';

/**
 * @access protected
 * @class  DolibarrApiAccess {@requires user,external}
 *
 * @package DolibarrModules\Flotte
 */
class Bookings extends DolibarrApi
{
	// ── Field definitions ────────────────────────────────────────────────────

	const FK_FIELDS = [
		'fk_vehicle', 'fk_driver', 'fk_vendor', 'fk_customer',
		'fk_user_author', 'expense_fuel_vendor',
	];

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

	const BOOKING_FIELDS = [
		'fk_vehicle', 'fk_driver', 'fk_vendor', 'fk_customer',
		'booking_date',
		'status',
		'bl_number',
		'departure_address', 'dep_lat', 'dep_lon',
		'arriving_address',  'arr_lat', 'arr_lon',
		'stops',
		'distance',
		'eta',
		'pickup_datetime',
		'dropoff_datetime',
		'buying_amount', 'buying_qty', 'buying_price', 'buying_unit',
		'buying_tax_rate', 'buying_amount_ttc',
		'selling_amount', 'selling_qty', 'selling_price', 'selling_unit',
		'selling_tax_rate', 'selling_amount_ttc',
		'expense_fuel', 'expense_fuel_qty', 'expense_fuel_price',
		'expense_fuel_type', 'expense_fuel_vendor',
		'expense_road', 'expense_road_toll', 'expense_road_parking', 'expense_road_other',
		'expense_driver', 'expense_driver_salary', 'expense_driver_overnight', 'expense_driver_bonus',
		'expense_commission', 'expense_commission_agent',
		'expense_commission_tax', 'expense_commission_other',
		'fk_user_author',
	];

	const ALLOWED_STATUSES = ['pending', 'confirmed', 'in_progress', 'completed', 'cancelled'];

	// ────────────────────────────────────────────────────────────────────────

	public function __construct()
	{
		global $db;
		$this->db = $db;
	}

	// ── GET list ─────────────────────────────────────────────────────────────

	/**
	 * List bookings
	 *
	 * @param  string $status           Filter by status (pending|confirmed|in_progress|completed|cancelled)
	 * @param  int    $fk_vehicle       Filter by vehicle ID
	 * @param  int    $fk_driver        Filter by driver ID
	 * @param  int    $fk_customer      Filter by customer ID
	 * @param  int    $fk_vendor        Filter by vendor ID
	 * @param  string $date_from        Filter from date (YYYY-MM-DD)
	 * @param  string $date_to          Filter to date (YYYY-MM-DD)
	 * @param  string $search           Search in ref, addresses, bl_number
	 * @param  int    $include_expenses Attach expense rows (0|1)
	 * @param  int    $limit            Results per page (default 50)
	 * @param  int    $page             Page number (0-based)
	 *
	 * @url    GET /
	 * @throws RestException 401
	 * @throws RestException 400
	 * @return array
	 */
	public function index(
		$status = '',
		$fk_vehicle = 0,
		$fk_driver = 0,
		$fk_customer = 0,
		$fk_vendor = 0,
		$date_from = '',
		$date_to = '',
		$search = '',
		$include_expenses = 0,
		$limit = 50,
		$page = 0
	) {
		if (empty(DolibarrApiAccess::$user->rights->flotte->read)) {
			throw new RestException(401, 'No read permission on flotte module');
		}

		$limit  = max(1, (int) $limit);
		$page   = max(0, (int) $page);
		$offset = $page * $limit;

		$conditions = ['t.entity IN (' . getEntity('flotte') . ')'];

		if ($status !== '') {
			if (!in_array($status, self::ALLOWED_STATUSES)) {
				throw new RestException(400, 'Invalid status. Allowed: ' . implode(', ', self::ALLOWED_STATUSES));
			}
			$conditions[] = "t.status = '" . $this->db->escape($status) . "'";
		}
		if ((int) $fk_vehicle > 0) $conditions[] = 't.fk_vehicle = '  . (int) $fk_vehicle;
		if ((int) $fk_driver > 0) $conditions[] = 't.fk_driver = '   . (int) $fk_driver;
		if ((int) $fk_customer > 0) $conditions[] = 't.fk_customer = ' . (int) $fk_customer;
		if ((int) $fk_vendor > 0) $conditions[] = 't.fk_vendor = '   . (int) $fk_vendor;

		if ($date_from !== '') {
			if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) {
				throw new RestException(400, 'date_from must be YYYY-MM-DD');
			}
			$conditions[] = "t.booking_date >= '" . $this->db->escape($date_from) . "'";
		}
		if ($date_to !== '') {
			if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) {
				throw new RestException(400, 'date_to must be YYYY-MM-DD');
			}
			$conditions[] = "t.booking_date <= '" . $this->db->escape($date_to) . "'";
		}
		if ($search !== '') {
			$esc = $this->db->escape($search);
			$conditions[] = "(t.ref LIKE '%$esc%'"
				. " OR t.departure_address LIKE '%$esc%'"
				. " OR t.arriving_address  LIKE '%$esc%'"
				. " OR t.bl_number LIKE '%$esc%')";
		}

		$where = implode(' AND ', $conditions);

		$cnt   = $this->db->query('SELECT COUNT(*) AS nb FROM ' . MAIN_DB_PREFIX . "flotte_booking t WHERE $where");
		$total = $cnt ? (int) $this->db->fetch_object($cnt)->nb : 0;

		$sql = 'SELECT t.* FROM ' . MAIN_DB_PREFIX . 'flotte_booking t'
			. " WHERE $where"
			. ' ORDER BY t.booking_date DESC, t.rowid DESC'
			. " LIMIT $limit OFFSET $offset";

		$res      = $this->db->query($sql);
		$bookings = [];
		if ($res) {
			while ($obj = $this->db->fetch_object($res)) {
				$b = $this->_bookingToArray($obj);
				if ($include_expenses) {
					$b['expenses'] = $this->_getExpenses($b['rowid']);
				}
				$bookings[] = $b;
			}
		}

		return [
			'bookings'   => $bookings,
			'pagination' => [
				'total' => $total,
				'page'  => $page,
				'limit' => $limit,
				'pages' => (int) ceil($total / $limit),
			],
		];
	}

	// ── GET single ───────────────────────────────────────────────────────────

	/**
	 * Get a booking by ID
	 *
	 * @param  int $id               Booking ID
	 * @param  int $include_expenses Attach expense rows (0|1)
	 *
	 * @url    GET /{id}
	 * @throws RestException 401
	 * @throws RestException 400
	 * @throws RestException 404
	 * @return array
	 */
	public function get($id, $include_expenses = 0)
	{
		if (empty(DolibarrApiAccess::$user->rights->flotte->read)) {
			throw new RestException(401, 'No read permission on flotte module');
		}

		$id = (int) $id;
		if ($id <= 0) throw new RestException(400, 'Invalid ID');

		$res = $this->db->query(
			'SELECT * FROM ' . MAIN_DB_PREFIX . 'flotte_booking'
			. " WHERE rowid = $id AND entity IN (" . getEntity('flotte') . ')'
		);

		if (!$res || !$this->db->num_rows($res)) {
			throw new RestException(404, 'Booking not found');
		}

		$booking = $this->_bookingToArray($this->db->fetch_object($res));
		if ($include_expenses) {
			$booking['expenses'] = $this->_getExpenses($booking['rowid']);
		}

		return $booking;
	}

	// ── POST create ──────────────────────────────────────────────────────────

	/**
	 * Create a booking
	 *
	 * @param  array $request_data Booking data (fk_vehicle, fk_customer, booking_date required)
	 *
	 * @url    POST /
	 * @throws RestException 401
	 * @throws RestException 400
	 * @throws RestException 500
	 * @return array
	 */
	public function post($request_data = null)
	{
		if (empty(DolibarrApiAccess::$user->rights->flotte->write)) {
			throw new RestException(401, 'No write permission on flotte module');
		}

		$body = (array) $request_data;

		$missing = [];
		if (empty($body['fk_vehicle']))   $missing[] = 'fk_vehicle';
		if (empty($body['fk_customer']))  $missing[] = 'fk_customer';
		if (empty($body['booking_date'])) $missing[] = 'booking_date';
		if ($missing) {
			throw new RestException(400, 'Required fields missing: ' . implode(', ', $missing));
		}

		if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $body['booking_date'])) {
			throw new RestException(400, 'booking_date must be YYYY-MM-DD');
		}

		$body['status'] = !empty($body['status']) ? $body['status'] : 'pending';
		if (!in_array($body['status'], self::ALLOWED_STATUSES)) {
			throw new RestException(400, 'Invalid status. Allowed: ' . implode(', ', self::ALLOWED_STATUSES));
		}

		$body = $this->_autoComputeExpenses($body);

		$ref = !empty($body['ref']) ? $body['ref'] : $this->_nextRef();

		$cols = ['ref', 'entity'];
		$vals = ["'" . $this->db->escape($ref) . "'", (int) $GLOBALS['conf']->entity];

		foreach (self::BOOKING_FIELDS as $f) {
			if (array_key_exists($f, $body)) {
				$cols[] = $f;
				$vals[] = $this->_sqlVal($f, $body[$f]);
			}
		}
		if (!in_array('fk_user_author', $cols)) {
			$cols[] = 'fk_user_author';
			$vals[] = (int) DolibarrApiAccess::$user->id;
		}

		$this->db->begin();
		$res = $this->db->query(
			'INSERT INTO ' . MAIN_DB_PREFIX . 'flotte_booking'
			. ' (' . implode(', ', $cols) . ')'
			. ' VALUES (' . implode(', ', $vals) . ')'
		);
		if (!$res) {
			$this->db->rollback();
			throw new RestException(500, 'DB error: ' . $this->db->lasterror());
		}
		$newId = $this->db->last_insert_id(MAIN_DB_PREFIX . 'flotte_booking', 'rowid');
		$this->db->commit();

		$row = $this->db->query('SELECT * FROM ' . MAIN_DB_PREFIX . 'flotte_booking WHERE rowid = ' . (int) $newId);
		return $this->_bookingToArray($this->db->fetch_object($row));
	}

	// ── PUT update ───────────────────────────────────────────────────────────

	/**
	 * Update a booking
	 *
	 * @param  int   $id           Booking ID
	 * @param  array $request_data Fields to update
	 *
	 * @url    PUT /{id}
	 * @throws RestException 401
	 * @throws RestException 400
	 * @throws RestException 404
	 * @throws RestException 500
	 * @return array
	 */
	public function put($id, $request_data = null)
	{
		if (empty(DolibarrApiAccess::$user->rights->flotte->write)) {
			throw new RestException(401, 'No write permission on flotte module');
		}

		$id = (int) $id;
		if ($id <= 0) throw new RestException(400, 'Invalid ID');

		$chk = $this->db->query(
			'SELECT rowid FROM ' . MAIN_DB_PREFIX . 'flotte_booking'
			. " WHERE rowid = $id AND entity IN (" . getEntity('flotte') . ')'
		);
		if (!$chk || !$this->db->num_rows($chk)) {
			throw new RestException(404, 'Booking not found');
		}

		$body = (array) $request_data;
		if (empty($body)) throw new RestException(400, 'Empty request body');

		if (!empty($body['status']) && !in_array($body['status'], self::ALLOWED_STATUSES)) {
			throw new RestException(400, 'Invalid status. Allowed: ' . implode(', ', self::ALLOWED_STATUSES));
		}
		if (!empty($body['booking_date']) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $body['booking_date'])) {
			throw new RestException(400, 'booking_date must be YYYY-MM-DD');
		}

		$body = $this->_autoComputeExpenses($body);

		$sets = [];
		foreach (self::BOOKING_FIELDS as $f) {
			if (array_key_exists($f, $body)) {
				$sets[] = "$f = " . $this->_sqlVal($f, $body[$f]);
			}
		}
		if (empty($sets)) throw new RestException(400, 'No valid fields to update');

		$this->db->begin();
		$res = $this->db->query(
			'UPDATE ' . MAIN_DB_PREFIX . 'flotte_booking'
			. ' SET ' . implode(', ', $sets)
			. " WHERE rowid = $id AND entity IN (" . getEntity('flotte') . ')'
		);
		if (!$res) {
			$this->db->rollback();
			throw new RestException(500, 'DB error: ' . $this->db->lasterror());
		}
		$this->db->commit();

		$row = $this->db->query('SELECT * FROM ' . MAIN_DB_PREFIX . "flotte_booking WHERE rowid = $id");
		return $this->_bookingToArray($this->db->fetch_object($row));
	}

	// ── DELETE ───────────────────────────────────────────────────────────────

	/**
	 * Delete a booking
	 *
	 * @param  int $id Booking ID
	 *
	 * @url    DELETE /{id}
	 * @throws RestException 401
	 * @throws RestException 400
	 * @throws RestException 404
	 * @throws RestException 500
	 * @return array
	 */
	public function delete($id)
	{
		global $conf;

		if (empty(DolibarrApiAccess::$user->rights->flotte->delete)) {
			throw new RestException(401, 'No delete permission on flotte module');
		}

		$id = (int) $id;
		if ($id <= 0) throw new RestException(400, 'Invalid ID');

		$chk = $this->db->query(
			'SELECT rowid FROM ' . MAIN_DB_PREFIX . 'flotte_booking'
			. " WHERE rowid = $id AND entity IN (" . getEntity('flotte') . ')'
		);
		if (!$chk || !$this->db->num_rows($chk)) {
			throw new RestException(404, 'Booking not found');
		}

		$this->db->begin();

		// Remove booking-sourced expense rows first
		$this->db->query(
			'DELETE FROM ' . MAIN_DB_PREFIX . "flotte_expense"
			. " WHERE fk_booking = $id AND source = 'booking'"
		);

		$res = $this->db->query(
			'DELETE FROM ' . MAIN_DB_PREFIX . 'flotte_booking'
			. " WHERE rowid = $id AND entity IN (" . getEntity('flotte') . ')'
		);
		if (!$res) {
			$this->db->rollback();
			throw new RestException(500, 'DB error: ' . $this->db->lasterror());
		}

		// Clean up uploaded files
		if (isset($conf->flotte->dir_output)) {
			$dir = $conf->flotte->dir_output . '/bookings/' . $id . '/';
			if (is_dir($dir) && function_exists('dol_delete_dir_recursive')) {
				dol_delete_dir_recursive($dir);
			}
		}

		$this->db->commit();
		return ['deleted' => true, 'id' => $id];
	}

	// ── Private helpers ──────────────────────────────────────────────────────

	/**
	 * Convert a DB row object to an array
	 */
	private function _bookingToArray($obj)
	{
		$out = [];
		$all = array_merge(['rowid', 'ref', 'entity'], self::BOOKING_FIELDS);
		foreach ($all as $f) {
			$out[$f] = isset($obj->$f) ? $obj->$f : null;
		}
		foreach (['tms', 'date_creation'] as $f) {
			if (isset($obj->$f)) $out[$f] = $obj->$f;
		}
		return $out;
	}

	/**
	 * Fetch expense rows linked to a booking
	 */
	private function _getExpenses($bookingId)
	{
		$sql = 'SELECT * FROM ' . MAIN_DB_PREFIX . 'flotte_expense'
			. ' WHERE fk_booking = ' . (int) $bookingId
			. ' ORDER BY rowid ASC';
		$res  = $this->db->query($sql);
		$rows = [];
		if ($res) {
			while ($obj = $this->db->fetch_object($res)) {
				$row = [];
				foreach ([
					'rowid', 'ref', 'fk_booking', 'expense_date', 'category', 'amount',
					'fuel_vendor', 'fuel_type', 'fuel_qty', 'fuel_price',
					'road_toll', 'road_parking', 'road_other',
					'driver_salary', 'driver_overnight', 'driver_bonus',
					'commission_agent', 'commission_tax', 'commission_other',
					'other_label', 'source', 'notes',
				] as $f) {
					$row[$f] = isset($obj->$f) ? $obj->$f : null;
				}
				$rows[] = $row;
			}
		}
		return $rows;
	}

	/**
	 * Build a safe SQL value for a given field
	 */
	private function _sqlVal($field, $value)
	{
		if (is_null($value) || $value === '') return 'NULL';
		if (in_array($field, self::FK_FIELDS)) return (int) $value;
		if (in_array($field, self::DECIMAL_FIELDS)) return (float) $value;
		return "'" . $this->db->escape($value) . "'";
	}

	/**
	 * Generate next booking ref (BOOK-0001, BOOK-0002, …)
	 */
	private function _nextRef()
	{
		global $conf;
		$prefix = 'BOOK-';
		$sql = 'SELECT ref FROM ' . MAIN_DB_PREFIX . 'flotte_booking'
			. ' WHERE entity = ' . (int) $conf->entity
			. " AND ref LIKE '" . $this->db->escape($prefix) . "%'"
			. ' ORDER BY ref DESC LIMIT 1';
		$res = $this->db->query($sql);
		if ($res && $this->db->num_rows($res) > 0) {
			$obj  = $this->db->fetch_object($res);
			$next = (int) str_replace($prefix, '', $obj->ref) + 1;
		} else {
			$next = 1;
		}
		return $prefix . str_pad($next, 4, '0', STR_PAD_LEFT);
	}

	/**
	 * Auto-compute expense totals from sub-fields when totals are omitted.
	 * Returns the (possibly modified) body array.
	 */
	private function _autoComputeExpenses(array $body)
	{
		if (empty($body['expense_fuel'])
			&& !empty($body['expense_fuel_qty'])
			&& !empty($body['expense_fuel_price'])) {
			$body['expense_fuel'] = round((float) $body['expense_fuel_qty'] * (float) $body['expense_fuel_price'], 2);
		}

		if (empty($body['expense_road'])
			&& (isset($body['expense_road_toll']) || isset($body['expense_road_parking']) || isset($body['expense_road_other']))) {
			$body['expense_road'] = round(
				(float) ($body['expense_road_toll']    ?? 0)
				+ (float) ($body['expense_road_parking'] ?? 0)
				+ (float) ($body['expense_road_other']   ?? 0),
				2
			);
		}

		if (empty($body['expense_driver'])
			&& (isset($body['expense_driver_salary']) || isset($body['expense_driver_overnight']) || isset($body['expense_driver_bonus']))) {
			$body['expense_driver'] = round(
				(float) ($body['expense_driver_salary']    ?? 0)
				+ (float) ($body['expense_driver_overnight'] ?? 0)
				+ (float) ($body['expense_driver_bonus']     ?? 0),
				2
			);
		}

		if (empty($body['expense_commission']) && isset($body['expense_commission_agent'])) {
			$agent   = (float) ($body['expense_commission_agent'] ?? 0);
			$taxRate = (float) ($body['expense_commission_tax']   ?? 0);
			$other   = (float) ($body['expense_commission_other'] ?? 0);
			$body['expense_commission'] = round($agent + round($agent * $taxRate / 100, 2) + $other, 2);
		}

		return $body;
	}
}
<?php
/**
 * Vehicles REST API — Dolibarr flotte module
 *
 * PLACE THIS FILE AT:
 *   htdocs/custom/flotte/class/api_vehicles.class.php
 *
 * Endpoints (via /api/index.php/explorer):
 *   GET    /vehicles           → list vehicles
 *   GET    /vehicles/{id}      → get one vehicle
 *   POST   /vehicles           → create vehicle
 *   PUT    /vehicles/{id}      → update vehicle
 *   DELETE /vehicles/{id}      → delete vehicle
 */

if (!defined('NOREQUIREMENU'))  define('NOREQUIREMENU', 1);
if (!defined('NOREQUIREHTML'))  define('NOREQUIREHTML', 1);
if (!defined('NOREQUIREAJAX'))  define('NOREQUIREAJAX', 1);
if (!defined('NOCSRFCHECK'))    define('NOCSRFCHECK', 1);

require_once DOL_DOCUMENT_ROOT . '/api/class/api.class.php';
require_once DOL_DOCUMENT_ROOT . '/custom/flotte/class/vehicle.class.php';

/**
 * @access protected
 * @class  DolibarrApiAccess {@requires user,external}
 *
 * @package DolibarrModules\Flotte
 */
class Vehicles extends DolibarrApi
{
    public function __construct()
    {
        global $db;
        $this->db = $db;
    }

    // ── GET list ─────────────────────────────────────────────────────────────

    /**
     * List vehicles
     *
     * @param string $sortfield  Sort field (default: v.rowid)
     * @param string $sortorder  ASC or DESC
     * @param int    $limit      Max records (default: 100)
     * @param int    $page       Page number (0-based)
     * @param string $sqlfilters Extra filter e.g. (v.status:=:1)
     *
     * @url    GET /
     * @throws RestException 401
     * @throws RestException 500
     * @return array
     */
    public function index(
        $sortfield  = 'v.rowid',
        $sortorder  = 'ASC',
        $limit      = 100,
        $page       = 0,
        $sqlfilters = ''
    ) {
        if (empty(DolibarrApiAccess::$user->rights->flotte->read)) {
            throw new RestException(401, 'No read permission on flotte module');
        }
        return $this->_listVehicles($sortfield, $sortorder, $limit, $page, $sqlfilters);
    }

    // ── GET single ───────────────────────────────────────────────────────────

    /**
     * Get a vehicle by ID
     *
     * @param int $id Vehicle ID
     *
     * @url    GET /{id}
     * @throws RestException 401
     * @throws RestException 400
     * @throws RestException 404
     * @return array
     */
    public function get($id)
    {
        if (empty(DolibarrApiAccess::$user->rights->flotte->read)) {
            throw new RestException(401, 'No read permission on flotte module');
        }

        $id = (int) $id;
        if ($id <= 0) throw new RestException(400, 'Invalid ID');

        $obj = new FlotteVehicle($this->db);
        if ($obj->fetch($id) <= 0) {
            throw new RestException(404, 'Vehicle not found: ' . $obj->error);
        }
        return $this->_cleanObjectDatas($obj);
    }

    // ── POST create ──────────────────────────────────────────────────────────

    /**
     * Create a vehicle
     *
     * @param array $request_data Vehicle fields
     *
     * @url    POST /
     * @throws RestException 401
     * @throws RestException 500
     * @return int New vehicle ID
     */
    public function post($request_data = null)
    {
        if (empty(DolibarrApiAccess::$user->rights->flotte->write)) {
            throw new RestException(401, 'No write permission on flotte module');
        }

        $obj = new FlotteVehicle($this->db);
        $this->_fill($obj, $request_data);
        $id = $obj->create(DolibarrApiAccess::$user);
        if ($id <= 0) {
            throw new RestException(500, 'Error creating vehicle: ' . $obj->error);
        }
        return $id;
    }

    // ── PUT update ───────────────────────────────────────────────────────────

    /**
     * Update a vehicle
     *
     * @param int   $id           Vehicle ID
     * @param array $request_data Fields to update
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

        $obj = new FlotteVehicle($this->db);
        if ($obj->fetch($id) <= 0) {
            throw new RestException(404, 'Vehicle not found');
        }
        $this->_fill($obj, $request_data);
        if ($obj->update(DolibarrApiAccess::$user) <= 0) {
            throw new RestException(500, 'Error updating vehicle: ' . $obj->error);
        }
        return $this->_cleanObjectDatas($obj);
    }

    // ── DELETE ───────────────────────────────────────────────────────────────

    /**
     * Delete a vehicle
     *
     * @param int $id Vehicle ID
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
        if (empty(DolibarrApiAccess::$user->rights->flotte->delete)) {
            throw new RestException(401, 'No delete permission on flotte module');
        }

        $id = (int) $id;
        if ($id <= 0) throw new RestException(400, 'Invalid ID');

        $obj = new FlotteVehicle($this->db);
        if ($obj->fetch($id) <= 0) {
            throw new RestException(404, 'Vehicle not found');
        }
        if ($obj->delete(DolibarrApiAccess::$user) <= 0) {
            throw new RestException(500, 'Error deleting vehicle: ' . $obj->error);
        }
        return ['deleted' => true, 'id' => $id];
    }

    // ── Private helpers ──────────────────────────────────────────────────────

    private function _listVehicles($sortfield, $sortorder, $limit, $page, $sqlfilters)
    {
        $list = [];
        $sql  = 'SELECT v.rowid FROM ' . MAIN_DB_PREFIX . 'flotte_vehicle AS v';

        if (!empty($sqlfilters)) {
            $regexstring = '\(([^:\'\(\)]+:[^:\'\(\)]+:[^\(\)]+)\)';
            if (preg_match_all('/' . $regexstring . '/', $sqlfilters, $matches)) {
                $conditions = [];
                foreach ($matches[1] as $match) {
                    $parts = explode(':', $match, 3);
                    if (count($parts) === 3) {
                        $field    = $this->db->escape(trim($parts[0]));
                        $operator = trim($parts[1]);
                        $value    = $this->db->escape(trim($parts[2]));
                        if (in_array($operator, ['=', '!=', '<', '>', '<=', '>=', 'like', 'notlike'])) {
                            if ($operator === 'like')    { $operator = 'LIKE';     $value = '%' . $value . '%'; }
                            if ($operator === 'notlike') { $operator = 'NOT LIKE'; $value = '%' . $value . '%'; }
                            $conditions[] = $field . ' ' . $operator . " '" . $value . "'";
                        }
                    }
                }
                if ($conditions) {
                    $sql .= ' WHERE ' . implode(' AND ', $conditions);
                }
            }
        }

        $sql .= $this->db->order($sortfield, $sortorder);
        $sql .= $this->db->plimit((int) $limit, max(0, (int) $limit) * max(0, (int) $page));

        $result = $this->db->query($sql);
        if (!$result) {
            throw new RestException(500, 'Database error: ' . $this->db->lasterror());
        }

        while ($row = $this->db->fetch_object($result)) {
            $obj = new FlotteVehicle($this->db);
            $obj->fetch((int) $row->rowid);
            $list[] = $this->_cleanObjectDatas($obj);
        }
        return $list;
    }

    private function _fill(&$obj, $data)
    {
        if (empty($data) || !is_array($data)) return;
        foreach ($data as $key => $value) {
            if (!in_array($key, ['db', 'error', 'errors', 'id', 'rowid'])) {
                $obj->$key = $value;
            }
        }
    }
}
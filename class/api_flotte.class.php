<?php
/**
 * REST API for the Flotte module — Vehicles & Bookings.
 *
 * PLACE THIS FILE AT:
 *   htdocs/custom/flotte/class/api_flotte.class.php
 */

require_once DOL_DOCUMENT_ROOT . '/custom/flotte/class/vehicle.class.php';
require_once DOL_DOCUMENT_ROOT . '/custom/flotte/class/booking.class.php';

/**
 * @access protected
 * @class  DolibarrApiAccess {@requires user,external}
 */
class Flotte extends DolibarrApi
{
    public function __construct()
    {
        global $db;
        $this->db = $db;
    }


    // =========================================================================
    //  VEHICLES
    // =========================================================================

    /**
     * Get a vehicle by ID
     *
     * @param int $id Vehicle ID
     * @return array
     *
     * @url GET vehicles/{id}
     */
    public function getVehicle($id)
    {
        $obj = new FlotteVehicle($this->db);
        if ($obj->fetch((int) $id) <= 0) {
            throw new RestException(404, 'Vehicle not found: ' . $obj->error);
        }
        return $this->_cleanObjectDatas($obj);
    }

    /**
     * List vehicles
     *
     * @param string $sortfield  Sort field (default: v.rowid)
     * @param string $sortorder  ASC or DESC
     * @param int    $limit      Max records (default: 100)
     * @param int    $page       Page number (0-based)
     * @param string $sqlfilters Extra filter e.g. (v.status:=:1)
     * @return array
     *
     * @url GET vehicles
     */
    public function listVehicles(
        $sortfield  = 'v.rowid',
        $sortorder  = 'ASC',
        $limit      = 100,
        $page       = 0,
        $sqlfilters = ''
    ) {
        return $this->_listObjects('FlotteVehicle', 'flotte_vehicle', 'v', $sortfield, $sortorder, $limit, $page, $sqlfilters);
    }

    /**
     * Create a vehicle
     *
     * @param array $request_data Vehicle fields
     * @return int  New vehicle ID
     *
     * @url POST vehicles
     */
    public function createVehicle($request_data = null)
    {
        $obj = new FlotteVehicle($this->db);
        $this->_fill($obj, $request_data);
        $id = $obj->create(DolibarrApiAccess::$user);
        if ($id <= 0) {
            throw new RestException(500, 'Error creating vehicle: ' . $obj->error);
        }
        return $id;
    }

    /**
     * Update a vehicle
     *
     * @param int   $id           Vehicle ID
     * @param array $request_data Fields to update
     * @return array
     *
     * @url PUT vehicles/{id}
     */
    public function updateVehicle($id, $request_data = null)
    {
        $obj = new FlotteVehicle($this->db);
        if ($obj->fetch((int) $id) <= 0) {
            throw new RestException(404, 'Vehicle not found');
        }
        $this->_fill($obj, $request_data);
        if ($obj->update(DolibarrApiAccess::$user) <= 0) {
            throw new RestException(500, 'Error updating vehicle: ' . $obj->error);
        }
        return $this->_cleanObjectDatas($obj);
    }

    /**
     * Delete a vehicle
     *
     * @param int $id Vehicle ID
     * @return array
     *
     * @url DELETE vehicles/{id}
     */
    public function deleteVehicle($id)
    {
        $obj = new FlotteVehicle($this->db);
        if ($obj->fetch((int) $id) <= 0) {
            throw new RestException(404, 'Vehicle not found');
        }
        if ($obj->delete(DolibarrApiAccess::$user) <= 0) {
            throw new RestException(500, 'Error deleting vehicle: ' . $obj->error);
        }
        return array('success' => array('code' => 200, 'message' => 'Vehicle deleted'));
    }


    // =========================================================================
    //  BOOKINGS
    // =========================================================================

    /**
     * Get a booking by ID
     *
     * @param int $id Booking ID
     * @return array
     *
     * @url GET bookings/{id}
     */
    public function getBooking($id)
    {
        $obj = new FlotteBooking($this->db);
        if ($obj->fetch((int) $id) <= 0) {
            throw new RestException(404, 'Booking not found: ' . $obj->error);
        }
        return $this->_cleanObjectDatas($obj);
    }

    /**
     * List bookings
     *
     * @param string $sortfield  Sort field (default: b.rowid)
     * @param string $sortorder  ASC or DESC
     * @param int    $limit      Max records (default: 100)
     * @param int    $page       Page number (0-based)
     * @param string $sqlfilters Extra filter e.g. (b.status:=:1)
     * @return array
     *
     * @url GET bookings
     */
    public function listBookings(
        $sortfield  = 'b.rowid',
        $sortorder  = 'ASC',
        $limit      = 100,
        $page       = 0,
        $sqlfilters = ''
    ) {
        return $this->_listObjects('FlotteBooking', 'flotte_booking', 'b', $sortfield, $sortorder, $limit, $page, $sqlfilters);
    }

    /**
     * Create a booking
     *
     * @param array $request_data Booking fields
     * @return int  New booking ID
     *
     * @url POST bookings
     */
    public function createBooking($request_data = null)
    {
        $obj = new FlotteBooking($this->db);
        $this->_fill($obj, $request_data);
        $id = $obj->create(DolibarrApiAccess::$user);
        if ($id <= 0) {
            throw new RestException(500, 'Error creating booking: ' . $obj->error);
        }
        return $id;
    }

    /**
     * Update a booking
     *
     * @param int   $id           Booking ID
     * @param array $request_data Fields to update
     * @return array
     *
     * @url PUT bookings/{id}
     */
    public function updateBooking($id, $request_data = null)
    {
        $obj = new FlotteBooking($this->db);
        if ($obj->fetch((int) $id) <= 0) {
            throw new RestException(404, 'Booking not found');
        }
        $this->_fill($obj, $request_data);
        if ($obj->update(DolibarrApiAccess::$user) <= 0) {
            throw new RestException(500, 'Error updating booking: ' . $obj->error);
        }
        return $this->_cleanObjectDatas($obj);
    }

    /**
     * Delete a booking
     *
     * @param int $id Booking ID
     * @return array
     *
     * @url DELETE bookings/{id}
     */
    public function deleteBooking($id)
    {
        $obj = new FlotteBooking($this->db);
        if ($obj->fetch((int) $id) <= 0) {
            throw new RestException(404, 'Booking not found');
        }
        if ($obj->delete(DolibarrApiAccess::$user) <= 0) {
            throw new RestException(500, 'Error deleting booking: ' . $obj->error);
        }
        return array('success' => array('code' => 200, 'message' => 'Booking deleted'));
    }


    // =========================================================================
    //  PRIVATE HELPERS
    // =========================================================================

    private function _listObjects($className, $tableName, $alias, $sortfield, $sortorder, $limit, $page, $sqlfilters)
    {
        $list = array();

        $sql = 'SELECT ' . $alias . '.rowid FROM ' . MAIN_DB_PREFIX . $tableName . ' AS ' . $alias;

        if (!empty($sqlfilters)) {
            $regexstring = '\(([^:\'\(\)]+:[^:\'\(\)]+:[^\(\)]+)\)';
            if (preg_match_all('/' . $regexstring . '/', $sqlfilters, $matches)) {
                $conditions = array();
                foreach ($matches[1] as $match) {
                    $parts = explode(':', $match, 3);
                    if (count($parts) === 3) {
                        $field    = $this->db->escape(trim($parts[0]));
                        $operator = trim($parts[1]);
                        $value    = $this->db->escape(trim($parts[2]));
                        if (in_array($operator, array('=', '!=', '<', '>', '<=', '>=', 'like', 'notlike'))) {
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
            $obj = new $className($this->db);
            $obj->fetch((int) $row->rowid);
            $list[] = $this->_cleanObjectDatas($obj);
        }

        return $list;
    }

    private function _fill(&$obj, $data)
    {
        if (empty($data) || !is_array($data)) {
            return;
        }
        foreach ($data as $key => $value) {
            if (!in_array($key, array('db', 'error', 'errors', 'id', 'rowid'))) {
                $obj->$key = $value;
            }
        }
    }
}
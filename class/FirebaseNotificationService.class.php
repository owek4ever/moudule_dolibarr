<?php
/**
 * FirebaseNotificationService
 *
 * Core service for sending Firebase Cloud Messaging (FCM) push notifications
 * from the Flotte fleet management module.
 *
 * Compatible with llx_flotte.sql schema (vehicle, driver, booking,
 * workorder, inspection, fuel tables).
 *
 * Usage example:
 *   dol_include_once('/flotte/class/FirebaseNotificationService.class.php');
 *   $svc = new FirebaseNotificationService($db);
 *   $svc->sendToUser($user->id, 'Insurance Expiring', 'VEH-0012 expires in 7 days', ['vehicle_id'=>'12']);
 */

if (!defined('DOL_DOCUMENT_ROOT')) {
    die('Cannot run outside Dolibarr context');
}

class FirebaseNotificationService
{
    /** @var DoliDB */
    protected $db;

    /** @var array Config row from llx_flotte_firebase_config */
    protected $config = array();

    /** @var int Current entity */
    protected $entity;

    const FCM_V1_URL     = 'https://fcm.googleapis.com/v1/projects/%s/messages:send';
    const FCM_LEGACY_URL = 'https://fcm.googleapis.com/fcm/send';

    public function __construct($db)
    {
        global $conf;
        $this->db     = $db;
        $this->entity = isset($conf->entity) ? (int) $conf->entity : 1;
        $this->loadConfig();
    }

    // =========================================================
    //  PUBLIC SEND API
    // =========================================================

    /**
     * Send to a specific Dolibarr user (all their registered devices).
     *
     * @param  int    $user_id
     * @param  string $title
     * @param  string $body
     * @param  array  $data      Extra FCM data payload (string key=>value)
     * @param  string $type      Notification type constant
     * @param  int    $priority  1=low 2=normal 3=high 4=critical
     * @param  array  $links     Associative: fk_vehicle, fk_driver, fk_booking, fk_workorder, fk_inspection, fk_fuel
     * @return array  ['success'=>bool, 'sent'=>int, 'failed'=>int, 'log_id'=>int]
     */
    public function sendToUser($user_id, $title, $body, $data = array(), $type = 'custom', $priority = 2, $links = array())
    {
        $tokens = $this->getUserTokens((int) $user_id);
        return $this->dispatchToTokens($tokens, $title, $body, $data, $type, $priority, (int) $user_id, $links);
    }

    /**
     * Broadcast to ALL users with registered devices.
     */
    public function broadcast($title, $body, $data = array(), $type = 'custom', $priority = 2, $links = array())
    {
        $tokens = $this->getAllActiveTokens();
        return $this->dispatchToTokens($tokens, $title, $body, $data, $type, $priority, null, $links);
    }

    /**
     * Send a work order notification.
     * Triggered when a work order is created or goes overdue.
     *
     * @param  object $wo   Row from llx_flotte_workorder
     * @param  string $event 'created'|'overdue'|'completed'
     */
    public function notifyWorkOrder($wo, $event = 'created')
    {
        $icons   = array('created' => '⚙️', 'overdue' => '🚨', 'completed' => '✅');
        $icon    = $icons[$event] ?? '⚙️';
        $title   = $icon . ' Work Order ' . ucfirst($event);
        $body    = 'WO ' . $wo->ref;
        if (!empty($wo->priority))    $body .= ' [' . $wo->priority . ']';
        if (!empty($wo->description)) $body .= ' – ' . substr($wo->description, 0, 80);

        return $this->broadcast($title, $body,
            array('workorder_id' => (string) $wo->rowid, 'event' => $event),
            'workorder_' . $event, $event === 'overdue' ? 3 : 2,
            array('fk_workorder' => $wo->rowid, 'fk_vehicle' => $wo->fk_vehicle ?? null)
        );
    }

    /**
     * Send a booking notification.
     * Triggered when a booking is created or status changes.
     *
     * @param  object $booking  Row from llx_flotte_booking
     * @param  string $event    'created'|'confirmed'|'cancelled'
     */
    public function notifyBooking($booking, $event = 'created')
    {
        $icons  = array('created' => '📅', 'confirmed' => '✅', 'cancelled' => '❌');
        $icon   = $icons[$event] ?? '📅';
        $title  = $icon . ' Booking ' . ucfirst($event);
        $body   = 'Booking ' . $booking->ref;
        if (!empty($booking->departure_address)) $body .= ' from ' . substr($booking->departure_address, 0, 60);

        return $this->broadcast($title, $body,
            array('booking_id' => (string) $booking->rowid, 'event' => $event),
            'booking_' . $event, 2,
            array('fk_booking' => $booking->rowid, 'fk_vehicle' => $booking->fk_vehicle ?? null)
        );
    }

    /**
     * Send a fuel log notification.
     *
     * @param  object $fuel  Row from llx_flotte_fuel
     */
    public function notifyFuelAdded($fuel)
    {
        $title = '⛽ Fuel Recorded';
        $body  = 'Fuel log ' . $fuel->ref;
        if (!empty($fuel->qty)) $body .= ' – ' . $fuel->qty . ' L';

        return $this->broadcast($title, $body,
            array('fuel_id' => (string) $fuel->rowid),
            'fuel_added', 1,
            array('fk_fuel' => $fuel->rowid, 'fk_vehicle' => $fuel->fk_vehicle ?? null)
        );
    }

    // =========================================================
    //  TOKEN MANAGEMENT
    // =========================================================

    /**
     * Register or refresh a device FCM token for a user.
     */
    public function registerToken($user_id, $token, $device_type = 'web', $label = '')
    {
        if (empty($token)) return false;

        $now = $this->db->idate(dol_now());

        $sql  = "SELECT rowid FROM " . MAIN_DB_PREFIX . "flotte_firebase_tokens";
        $sql .= " WHERE device_token = '" . $this->db->escape($token) . "'";
        $res  = $this->db->query($sql);

        if ($res && $this->db->num_rows($res) > 0) {
            $row = $this->db->fetch_object($res);
            $upd  = "UPDATE " . MAIN_DB_PREFIX . "flotte_firebase_tokens SET";
            $upd .= " fk_user = "      . (int) $user_id;
            $upd .= ", device_type = '" . $this->db->escape($device_type) . "'";
            $upd .= ", device_label = '" . $this->db->escape($label) . "'";
            $upd .= ", date_last_used = '" . $now . "'";
            $upd .= ", active = 1";
            $upd .= " WHERE rowid = "   . (int) $row->rowid;
            return (bool) $this->db->query($upd);
        }

        $ins  = "INSERT INTO " . MAIN_DB_PREFIX . "flotte_firebase_tokens";
        $ins .= " (fk_user, device_token, device_type, device_label, entity, date_creation, active)";
        $ins .= " VALUES (";
        $ins .= (int) $user_id . ", '" . $this->db->escape($token) . "', ";
        $ins .= "'" . $this->db->escape($device_type) . "', '" . $this->db->escape($label) . "', ";
        $ins .= (int) $this->entity . ", '" . $now . "', 1)";
        return (bool) $this->db->query($ins);
    }

    /**
     * Deactivate a token (e.g. on logout or FCM rejection).
     */
    public function unregisterToken($token)
    {
        $sql  = "UPDATE " . MAIN_DB_PREFIX . "flotte_firebase_tokens";
        $sql .= " SET active = 0";
        $sql .= " WHERE device_token = '" . $this->db->escape($token) . "'";
        return (bool) $this->db->query($sql);
    }

    public function getUserTokens($user_id)
    {
        $tokens = array();
        $sql    = "SELECT device_token FROM " . MAIN_DB_PREFIX . "flotte_firebase_tokens";
        $sql   .= " WHERE fk_user = " . (int) $user_id . " AND active = 1 AND entity = " . (int) $this->entity;
        $res    = $this->db->query($sql);
        if ($res) {
            while ($row = $this->db->fetch_object($res)) {
                $tokens[] = $row->device_token;
            }
        }
        return $tokens;
    }

    public function getAllActiveTokens()
    {
        $tokens = array();
        $sql    = "SELECT device_token FROM " . MAIN_DB_PREFIX . "flotte_firebase_tokens";
        $sql   .= " WHERE active = 1 AND entity = " . (int) $this->entity;
        $res    = $this->db->query($sql);
        if ($res) {
            while ($row = $this->db->fetch_object($res)) {
                $tokens[] = $row->device_token;
            }
        }
        return $tokens;
    }

    // =========================================================
    //  NOTIFICATION LOG QUERIES
    // =========================================================

    /**
     * Get notification history for the UI.
     *
     * @param  array $filters  type, status, vehicle_id, driver_id, limit, offset
     * @return array of stdClass rows with joined vehicle/driver info
     */
    public function getNotificationLog($filters = array())
    {
        $limit  = isset($filters['limit'])  ? (int) $filters['limit']  : 50;
        $offset = isset($filters['offset']) ? (int) $filters['offset'] : 0;

        $sql  = "SELECT n.*";
        $sql .= ", v.ref AS vehicle_ref, v.maker, v.model, v.license_plate";
        $sql .= ", CONCAT(d.firstname, ' ', d.lastname) AS driver_name";
        $sql .= " FROM " . MAIN_DB_PREFIX . "flotte_notification_log n";
        $sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "flotte_vehicle v ON n.fk_vehicle = v.rowid";
        $sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "flotte_driver d  ON n.fk_driver  = d.rowid";
        $sql .= " WHERE n.entity = " . (int) $this->entity;

        if (!empty($filters['type']))       $sql .= " AND n.type = '"      . $this->db->escape($filters['type'])       . "'";
        if (!empty($filters['status']))     $sql .= " AND n.status = '"    . $this->db->escape($filters['status'])     . "'";
        if (!empty($filters['vehicle_id'])) $sql .= " AND n.fk_vehicle = " . (int) $filters['vehicle_id'];
        if (!empty($filters['driver_id']))  $sql .= " AND n.fk_driver = "  . (int) $filters['driver_id'];

        $sql .= " ORDER BY n.date_creation DESC";
        $sql .= " LIMIT " . $limit . " OFFSET " . $offset;

        $rows = array();
        $res  = $this->db->query($sql);
        if ($res) {
            while ($row = $this->db->fetch_object($res)) {
                $rows[] = $row;
            }
        }
        return $rows;
    }

    public function countNotificationLog($filters = array())
    {
        $sql  = "SELECT COUNT(*) AS total FROM " . MAIN_DB_PREFIX . "flotte_notification_log n";
        $sql .= " WHERE n.entity = " . (int) $this->entity;
        if (!empty($filters['type']))   $sql .= " AND n.type = '"   . $this->db->escape($filters['type'])   . "'";
        if (!empty($filters['status'])) $sql .= " AND n.status = '" . $this->db->escape($filters['status']) . "'";
        $res = $this->db->query($sql);
        if ($res && $row = $this->db->fetch_object($res)) return (int) $row->total;
        return 0;
    }

    // =========================================================
    //  AUTOMATED ALERT SCANNER  (called by cron_alerts.php)
    // =========================================================

    /**
     * Scan all vehicles and drivers for expiring documents, send alerts.
     * Maps directly to llx_flotte_vehicle and llx_flotte_driver columns.
     */
    public function runAlertScan()
    {
        $summary = array('processed' => 0, 'sent' => 0);
        $rules   = $this->getActiveAlertRules();

        foreach ($rules as $rule) {
            $sent = 0;
            switch ($rule->alert_type) {
                // ── Vehicle document expiries ─────────────────────
                case 'registration_expiry':
                    $sent = $this->scanVehicleDate('registration_expiry', $rule, '🪪 Registration Expiry');
                    break;
                case 'license_expiry':
                    // llx_flotte_vehicle.license_expiry
                    $sent = $this->scanVehicleDate('license_expiry', $rule, '📋 License Expiry');
                    break;
                case 'insurance_expiry':
                    $sent = $this->scanVehicleDate('insurance_expiry', $rule, '🛡️ Insurance Expiry');
                    break;

                // ── Driver license expiry ─────────────────────────
                case 'driver_license_expiry':
                    $sent = $this->scanDriverLicense($rule);
                    break;

                // ── Work orders overdue ───────────────────────────
                case 'workorder_overdue':
                    $sent = $this->scanOverdueWorkOrders($rule);
                    break;

                // ── Inspection due ────────────────────────────────
                case 'inspection_due':
                    // llx_flotte_inspection has datetime_out/datetime_in
                    // We alert on vehicles that have had no inspection recently
                    $sent = $this->scanInspectionDue($rule);
                    break;
            }
            $summary['sent']      += $sent;
            $summary['processed'] += 1;
        }
        return $summary;
    }

    // =========================================================
    //  INTERNAL SCANNERS
    // =========================================================

    /**
     * Scan llx_flotte_vehicle for a date column approaching expiry.
     * Columns available: registration_expiry, license_expiry, insurance_expiry
     */
    protected function scanVehicleDate($dateColumn, $rule, $alertLabel)
    {
        $sent = 0;
        $days = array((int) $rule->days_before, (int) $rule->days_before_second);

        foreach ($days as $d) {
            if ($d <= 0) continue;
            $target = dol_time_plus_duree(dol_now(), $d, 'd');
            $dateStr = $this->db->idate($target);

            $sql  = "SELECT rowid, ref, maker, model, license_plate, " . $dateColumn;
            $sql .= " FROM " . MAIN_DB_PREFIX . "flotte_vehicle";
            $sql .= " WHERE entity = " . (int) $this->entity;
            $sql .= " AND in_service = 1";
            $sql .= " AND DATE(" . $dateColumn . ") = DATE('" . $this->db->escape($dateStr) . "')";

            $res = $this->db->query($sql);
            if ($res) {
                while ($v = $this->db->fetch_object($res)) {
                    $title = $alertLabel . ' Alert';
                    $plate = !empty($v->license_plate) ? ' (' . $v->license_plate . ')' : '';
                    $body  = $v->ref . ' ' . $v->maker . ' ' . $v->model . $plate . ' — expires in ' . $d . ' day(s)';

                    $tokens = $this->getNotifyUserTokens($rule);
                    $this->dispatchToTokens($tokens, $title, $body,
                        array('vehicle_id' => (string) $v->rowid, 'days_remaining' => (string) $d, 'doc_type' => $dateColumn),
                        $dateColumn, (int) $rule->priority,
                        array('fk_vehicle' => $v->rowid)
                    );
                    $sent++;
                }
            }
        }
        return $sent;
    }

    /**
     * Scan llx_flotte_driver for drivers whose license_expiry_date is approaching.
     */
    protected function scanDriverLicense($rule)
    {
        $sent = 0;
        $days = array((int) $rule->days_before, (int) $rule->days_before_second);

        foreach ($days as $d) {
            if ($d <= 0) continue;
            $target  = dol_time_plus_duree(dol_now(), $d, 'd');
            $dateStr = $this->db->idate($target);

            // llx_flotte_driver uses license_expiry_date
            $sql  = "SELECT rowid, ref, firstname, lastname, license_number, license_expiry_date, fk_user";
            $sql .= " FROM " . MAIN_DB_PREFIX . "flotte_driver";
            $sql .= " WHERE entity = " . (int) $this->entity;
            $sql .= " AND DATE(license_expiry_date) = DATE('" . $this->db->escape($dateStr) . "')";

            $res = $this->db->query($sql);
            if ($res) {
                while ($drv = $this->db->fetch_object($res)) {
                    $title = '🪪 Driver License Expiry Alert';
                    $body  = $drv->firstname . ' ' . $drv->lastname . ' (License #' . $drv->license_number . ') — expires in ' . $d . ' day(s)';

                    // Notify the driver's linked Dolibarr user directly if set
                    if (!empty($drv->fk_user)) {
                        $tokens = $this->getUserTokens($drv->fk_user);
                    } else {
                        $tokens = $this->getNotifyUserTokens($rule);
                    }

                    $this->dispatchToTokens($tokens, $title, $body,
                        array('driver_id' => (string) $drv->rowid, 'days_remaining' => (string) $d),
                        'driver_license_expiry', (int) $rule->priority,
                        array('fk_driver' => $drv->rowid)
                    );
                    $sent++;
                }
            }
        }
        return $sent;
    }

    /**
     * Scan llx_flotte_workorder for work orders past their due_date with non-completed status.
     */
    protected function scanOverdueWorkOrders($rule)
    {
        $sent = 0;
        $today = $this->db->idate(dol_now());

        $sql  = "SELECT w.rowid, w.ref, w.due_date, w.priority, w.status, w.fk_vehicle,";
        $sql .= " v.ref AS vehicle_ref, v.maker, v.model";
        $sql .= " FROM " . MAIN_DB_PREFIX . "flotte_workorder w";
        $sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "flotte_vehicle v ON w.fk_vehicle = v.rowid";
        $sql .= " WHERE w.entity = " . (int) $this->entity;
        $sql .= " AND w.due_date IS NOT NULL";
        $sql .= " AND DATE(w.due_date) < DATE('" . $this->db->escape($today) . "')";
        $sql .= " AND w.status NOT IN ('Completed','Closed','completed','closed')";

        $res = $this->db->query($sql);
        if ($res) {
            while ($wo = $this->db->fetch_object($res)) {
                $title = '🚨 Work Order Overdue';
                $body  = $wo->ref . ' [' . ($wo->priority ?? 'Medium') . ']';
                if (!empty($wo->vehicle_ref)) $body .= ' — ' . $wo->vehicle_ref . ' ' . $wo->maker . ' ' . $wo->model;

                $tokens = $this->getNotifyUserTokens($rule);
                $this->dispatchToTokens($tokens, $title, $body,
                    array('workorder_id' => (string) $wo->rowid, 'vehicle_id' => (string) ($wo->fk_vehicle ?? '')),
                    'workorder_overdue', (int) $rule->priority,
                    array('fk_workorder' => $wo->rowid, 'fk_vehicle' => $wo->fk_vehicle ?? null)
                );
                $sent++;
            }
        }
        return $sent;
    }

    /**
     * Alert for vehicles with no inspection in the last N days.
     * Uses llx_flotte_inspection.datetime_out and fk_vehicle.
     */
    protected function scanInspectionDue($rule)
    {
        $sent       = 0;
        $threshold  = (int) $rule->days_before ?: 90; // vehicles with no inspection in 90 days

        $sql  = "SELECT v.rowid, v.ref, v.maker, v.model, v.license_plate,";
        $sql .= " MAX(i.datetime_out) AS last_inspection";
        $sql .= " FROM " . MAIN_DB_PREFIX . "flotte_vehicle v";
        $sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "flotte_inspection i ON i.fk_vehicle = v.rowid";
        $sql .= " WHERE v.entity = " . (int) $this->entity . " AND v.in_service = 1";
        $sql .= " GROUP BY v.rowid, v.ref, v.maker, v.model, v.license_plate";
        $sql .= " HAVING last_inspection IS NULL OR last_inspection < DATE_SUB(NOW(), INTERVAL " . $threshold . " DAY)";

        $res = $this->db->query($sql);
        if ($res) {
            while ($v = $this->db->fetch_object($res)) {
                $title = '🔧 Inspection Overdue';
                $plate = !empty($v->license_plate) ? ' (' . $v->license_plate . ')' : '';
                $body  = $v->ref . ' ' . $v->maker . ' ' . $v->model . $plate;
                $body .= $v->last_inspection ? ' — last inspected ' . dol_print_date(strtotime($v->last_inspection), 'day') : ' — never inspected';

                $tokens = $this->getNotifyUserTokens($rule);
                $this->dispatchToTokens($tokens, $title, $body,
                    array('vehicle_id' => (string) $v->rowid),
                    'inspection_due', (int) $rule->priority,
                    array('fk_vehicle' => $v->rowid)
                );
                $sent++;
            }
        }
        return $sent;
    }

    // =========================================================
    //  FCM DISPATCH
    // =========================================================

    protected function dispatchToTokens($tokens, $title, $body, $data, $type, $priority, $user_id, $links)
    {
        $result = array('success' => false, 'sent' => 0, 'failed' => 0, 'log_id' => null);

        if (empty($tokens)) {
            $this->logNotification($type, $priority, $title, $body, $data, $user_id, $links, 'failed', null, 'No registered device tokens');
            return $result;
        }

        if (!$this->isConfigured()) {
            $this->logNotification($type, $priority, $title, $body, $data, $user_id, $links, 'failed', null, 'Firebase not configured');
            return $result;
        }

        $responses = array();
        foreach ($tokens as $token) {
            $resp = $this->sendFcmMessage($token, $title, $body, $data, $priority);
            if ($resp['success']) {
                $result['sent']++;
            } else {
                $result['failed']++;
                if (!empty($resp['should_remove'])) {
                    $this->unregisterToken($token);
                }
            }
            $responses[] = $resp;
        }

        $status = $result['sent'] > 0 ? 'sent' : 'failed';
        $logId  = $this->logNotification($type, $priority, $title, $body, $data, $user_id, $links, $status, json_encode($responses));

        $result['success'] = $result['sent'] > 0;
        $result['log_id']  = $logId;
        return $result;
    }

    protected function sendFcmMessage($token, $title, $body, $data, $priority)
    {
        if (!empty($this->config['use_v1_api'])) {
            return $this->sendFcmV1($token, $title, $body, $data, $priority);
        }
        return $this->sendFcmLegacy($token, $title, $body, $data, $priority);
    }

    protected function sendFcmV1($token, $title, $body, $data, $priority)
    {
        $projectId   = $this->config['project_id'] ?? '';
        $accessToken = $this->getOAuth2AccessToken();

        if (empty($projectId) || empty($accessToken)) {
            return array('success' => false, 'error' => 'Missing project_id or OAuth2 token');
        }

        $androidPriority = $priority >= 3 ? 'high' : 'normal';

        $payload = array(
            'message' => array(
                'token'        => $token,
                'notification' => array('title' => $title, 'body' => $body),
                'data'         => array_map('strval', (array) $data),
                'android'      => array(
                    'priority'     => $androidPriority,
                    'notification' => array('sound' => 'default'),
                ),
                'apns'    => array('payload' => array('aps' => array('sound' => 'default'))),
                'webpush' => array('notification' => array('icon' => '/modules/flotte/img/flotte_icon.png')),
            ),
        );

        $url     = sprintf(self::FCM_V1_URL, $projectId);
        $headers = array(
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json',
        );
        return $this->httpPost($url, $payload, $headers);
    }

    protected function sendFcmLegacy($token, $title, $body, $data, $priority)
    {
        $serverKey = $this->config['server_key'] ?? '';
        if (empty($serverKey)) {
            return array('success' => false, 'error' => 'Missing server key');
        }

        $payload = array(
            'to'               => $token,
            'priority'         => $priority >= 3 ? 'high' : 'normal',
            'notification'     => array('title' => $title, 'body' => $body, 'sound' => 'default'),
            'data'             => $data,
            'content_available'=> true,
        );

        $headers = array(
            'Authorization: key=' . $serverKey,
            'Content-Type: application/json',
        );
        return $this->httpPost(self::FCM_LEGACY_URL, $payload, $headers);
    }

    protected function httpPost($url, $payload, $headers)
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT        => 15,
        ));

        $raw      = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr) return array('success' => false, 'error' => $curlErr);

        $decoded = json_decode($raw, true);

        if ($httpCode === 200) {
            return array('success' => true, 'response' => $decoded);
        }

        // Detect invalid/unregistered tokens
        $shouldRemove = false;
        $errCode      = $decoded['error']['details'][0]['errorCode'] ?? $decoded['error']['code'] ?? '';
        $legacyErr    = $decoded['results'][0]['error'] ?? '';
        if (in_array($errCode, array('UNREGISTERED', 'INVALID_ARGUMENT')) ||
            in_array($legacyErr, array('NotRegistered', 'InvalidRegistration'))) {
            $shouldRemove = true;
        }

        return array(
            'success'       => false,
            'http_code'     => $httpCode,
            'error'         => $decoded['error']['message'] ?? $raw,
            'should_remove' => $shouldRemove,
        );
    }

    /**
     * Exchange service account JSON for a short-lived OAuth2 access token (for FCM v1).
     */
    protected function getOAuth2AccessToken()
    {
        if (empty($this->config['service_account_json'])) return null;

        $sa = json_decode($this->config['service_account_json'], true);
        if (empty($sa['private_key']) || empty($sa['client_email'])) return null;

        // Simple in-memory + DB cache
        global $conf;
        $cacheKey = 'flotte_fcm_oauth_token';
        if (!empty($conf->global->$cacheKey)) {
            $cached = json_decode($conf->global->$cacheKey, true);
            if (!empty($cached['token']) && $cached['expires'] > time() + 60) {
                return $cached['token'];
            }
        }

        $now    = time();
        $header = rtrim(strtr(base64_encode(json_encode(array('alg' => 'RS256', 'typ' => 'JWT'))), '+/', '-_'), '=');
        $claims = rtrim(strtr(base64_encode(json_encode(array(
            'iss'   => $sa['client_email'],
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud'   => 'https://oauth2.googleapis.com/token',
            'iat'   => $now,
            'exp'   => $now + 3600,
        ))), '+/', '-_'), '=');

        $toSign = $header . '.' . $claims;
        openssl_sign($toSign, $signature, $sa['private_key'], OPENSSL_ALGO_SHA256);
        $jwt = $toSign . '.' . rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');

        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt_array($ch, array(
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query(array(
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $jwt,
            )),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
        ));
        $res  = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($res, true);
        if (empty($data['access_token'])) return null;

        dolibarr_set_const($this->db, $cacheKey, json_encode(array(
            'token'   => $data['access_token'],
            'expires' => $now + ($data['expires_in'] ?? 3600),
        )), 'chaine', 0, '', $this->entity);

        return $data['access_token'];
    }

    // =========================================================
    //  NOTIFICATION LOG WRITER
    // =========================================================

    protected function logNotification($type, $priority, $title, $body, $data, $user_id, $links, $status, $fbResponse = null, $errorMsg = null)
    {
        global $user;

        $now = $this->db->idate(dol_now());

        // Extract all possible FK links from the $links array
        $fk_vehicle   = isset($links['fk_vehicle'])   && $links['fk_vehicle']   ? (int) $links['fk_vehicle']   : null;
        $fk_driver    = isset($links['fk_driver'])    && $links['fk_driver']    ? (int) $links['fk_driver']    : null;
        $fk_booking   = isset($links['fk_booking'])   && $links['fk_booking']   ? (int) $links['fk_booking']   : null;
        $fk_workorder = isset($links['fk_workorder']) && $links['fk_workorder'] ? (int) $links['fk_workorder'] : null;
        $fk_inspection= isset($links['fk_inspection'])&& $links['fk_inspection']? (int) $links['fk_inspection']: null;
        $fk_fuel      = isset($links['fk_fuel'])      && $links['fk_fuel']      ? (int) $links['fk_fuel']      : null;

        $sql  = "INSERT INTO " . MAIN_DB_PREFIX . "flotte_notification_log";
        $sql .= " (entity, type, priority, title, body, data_json, fk_user,";
        $sql .= "  fk_vehicle, fk_driver, fk_booking, fk_workorder, fk_inspection, fk_fuel,";
        $sql .= "  channel, status, firebase_response, date_creation, date_sent, error_message, fk_user_author)";
        $sql .= " VALUES (";
        $sql .= (int) $this->entity . ", ";
        $sql .= "'" . $this->db->escape($type) . "', ";
        $sql .= (int) $priority . ", ";
        $sql .= "'" . $this->db->escape($title) . "', ";
        $sql .= "'" . $this->db->escape($body) . "', ";
        $sql .= (!empty($data)        ? "'" . $this->db->escape(json_encode($data))      . "'" : "NULL") . ", ";
        $sql .= ($user_id             ? (int) $user_id                                         : "NULL") . ", ";
        $sql .= ($fk_vehicle          ? $fk_vehicle          : "NULL") . ", ";
        $sql .= ($fk_driver           ? $fk_driver           : "NULL") . ", ";
        $sql .= ($fk_booking          ? $fk_booking          : "NULL") . ", ";
        $sql .= ($fk_workorder        ? $fk_workorder        : "NULL") . ", ";
        $sql .= ($fk_inspection       ? $fk_inspection       : "NULL") . ", ";
        $sql .= ($fk_fuel             ? $fk_fuel             : "NULL") . ", ";
        $sql .= "'firebase', ";
        $sql .= "'" . $this->db->escape($status) . "', ";
        $sql .= ($fbResponse !== null  ? "'" . $this->db->escape($fbResponse) . "'" : "NULL") . ", ";
        $sql .= "'" . $now . "', ";
        $sql .= ($status === 'sent'    ? "'" . $now . "'" : "NULL") . ", ";
        $sql .= ($errorMsg !== null    ? "'" . $this->db->escape($errorMsg) . "'" : "NULL") . ", ";
        $sql .= (isset($user->id)      ? (int) $user->id : "NULL");
        $sql .= ")";

        $this->db->query($sql);
        return $this->db->last_insert_id(MAIN_DB_PREFIX . "flotte_notification_log");
    }

    // =========================================================
    //  CONFIG & HELPERS
    // =========================================================

    protected function loadConfig()
    {
        $sql  = "SELECT * FROM " . MAIN_DB_PREFIX . "flotte_firebase_config";
        $sql .= " WHERE entity = " . (int) $this->entity . " LIMIT 1";
        $res  = $this->db->query($sql);
        if ($res && $row = $this->db->fetch_object($res)) {
            $this->config = (array) $row;
        }
    }

    public function isConfigured()
    {
        if (!empty($this->config['use_v1_api'])) {
            return !empty($this->config['project_id']) && !empty($this->config['service_account_json']);
        }
        return !empty($this->config['server_key']);
    }

    public function getConfig()
    {
        return $this->config;
    }

    public function saveConfig($data)
    {
        global $user;
        $now   = $this->db->idate(dol_now());
        $check = $this->db->query("SELECT rowid FROM " . MAIN_DB_PREFIX . "flotte_firebase_config WHERE entity = " . (int) $this->entity);

        if ($check && $this->db->num_rows($check) > 0) {
            $row  = $this->db->fetch_object($check);
            $sql  = "UPDATE " . MAIN_DB_PREFIX . "flotte_firebase_config SET";
            $sql .= " server_key = "            . (!empty($data['server_key'])           ? "'" . $this->db->escape($data['server_key'])           . "'" : "NULL");
            $sql .= ", project_id = "           . (!empty($data['project_id'])            ? "'" . $this->db->escape($data['project_id'])            . "'" : "NULL");
            $sql .= ", service_account_json = " . (!empty($data['service_account_json'])  ? "'" . $this->db->escape($data['service_account_json'])  . "'" : "NULL");
            $sql .= ", vapid_key = "            . (!empty($data['vapid_key'])             ? "'" . $this->db->escape($data['vapid_key'])             . "'" : "NULL");
            $sql .= ", use_v1_api = "           . (isset($data['use_v1_api'])             ? (int) $data['use_v1_api']                               : 1);
            $sql .= ", date_update = '"         . $now . "', fk_user_update = " . (int) $user->id;
            $sql .= " WHERE rowid = "           . (int) $row->rowid;
        } else {
            $sql  = "INSERT INTO " . MAIN_DB_PREFIX . "flotte_firebase_config";
            $sql .= " (entity, server_key, project_id, service_account_json, vapid_key, use_v1_api, date_update, fk_user_update)";
            $sql .= " VALUES (";
            $sql .= (int) $this->entity . ", ";
            $sql .= (!empty($data['server_key'])          ? "'" . $this->db->escape($data['server_key'])          . "'" : "NULL") . ", ";
            $sql .= (!empty($data['project_id'])           ? "'" . $this->db->escape($data['project_id'])           . "'" : "NULL") . ", ";
            $sql .= (!empty($data['service_account_json']) ? "'" . $this->db->escape($data['service_account_json']) . "'" : "NULL") . ", ";
            $sql .= (!empty($data['vapid_key'])            ? "'" . $this->db->escape($data['vapid_key'])            . "'" : "NULL") . ", ";
            $sql .= (isset($data['use_v1_api']) ? (int) $data['use_v1_api'] : 1) . ", ";
            $sql .= "'" . $now . "', " . (int) $user->id . ")";
        }

        $ok = (bool) $this->db->query($sql);
        if ($ok) $this->loadConfig(); // refresh cache
        return $ok;
    }

    public function getActiveAlertRules()
    {
        $rules = array();
        $sql   = "SELECT * FROM " . MAIN_DB_PREFIX . "flotte_alert_rules";
        $sql  .= " WHERE entity = " . (int) $this->entity . " AND is_active = 1";
        $res   = $this->db->query($sql);
        if ($res) {
            while ($row = $this->db->fetch_object($res)) {
                $rules[] = $row;
            }
        }
        return $rules;
    }

    protected function getNotifyUserTokens($rule)
    {
        if (!empty($rule->notify_users)) {
            $ids    = array_filter(array_map('intval', explode(',', $rule->notify_users)));
            $tokens = array();
            foreach ($ids as $uid) {
                $tokens = array_merge($tokens, $this->getUserTokens($uid));
            }
            return $tokens;
        }
        return $this->getAllActiveTokens();
    }
}

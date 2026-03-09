<?php
/**
 * FirebaseNotificationService.class.php
 *
 * Core service class for Firebase Cloud Messaging (FCM) push notifications.
 * Place this file at: /var/www/html/flotte/class/FirebaseNotificationService.class.php
 */

class FirebaseNotificationService
{
    /** @var DoliDB */
    private $db;

    /** @var array|null Cached config */
    private $config = null;

    /** @var string FCM v1 endpoint */
    private $fcmEndpoint = 'https://fcm.googleapis.com/v1/projects/{project_id}/messages:send';

    /** @var string Legacy FCM endpoint */
    private $fcmLegacyEndpoint = 'https://fcm.googleapis.com/fcm/send';

    /** @var string Google OAuth2 token endpoint */
    private $oauthEndpoint = 'https://oauth2.googleapis.com/token';

    public function __construct($db)
    {
        $this->db = $db;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CONFIG
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Load config from llx_flotte_firebase_config
     */
    public function getConfig()
    {
        if ($this->config !== null) {
            return $this->config;
        }

        global $conf;
        $entity = (int) $conf->entity;

        $sql = "SELECT * FROM " . MAIN_DB_PREFIX . "flotte_firebase_config WHERE entity = " . $entity . " LIMIT 1";
        $res = $this->db->query($sql);

        if ($res && $this->db->num_rows($res) > 0) {
            $obj = $this->db->fetch_object($res);
            $this->config = array(
                'server_key'           => $obj->server_key,
                'project_id'           => $obj->project_id,
                'service_account_json' => $obj->service_account_json,
                'vapid_key'            => $obj->vapid_key,
                'use_v1_api'           => (int) $obj->use_v1_api,
            );
        } else {
            $this->config = array(
                'server_key'           => '',
                'project_id'           => '',
                'service_account_json' => '',
                'vapid_key'            => '',
                'use_v1_api'           => 1,
            );
        }

        return $this->config;
    }

    /**
     * Save config to llx_flotte_firebase_config
     */
    public function saveConfig(array $data)
    {
        global $conf, $user;
        $entity = (int) $conf->entity;

        $server_key  = $this->db->escape($data['server_key']           ?? '');
        $project_id  = $this->db->escape($data['project_id']           ?? '');
        $sa_json     = $this->db->escape($data['service_account_json'] ?? '');
        $vapid       = $this->db->escape($data['vapid_key']            ?? '');
        $use_v1      = (int) ($data['use_v1_api'] ?? 1);
        $now         = $this->db->idate(dol_now());
        $fk_user     = (int) $user->id;

        // Check if row exists
        $check = $this->db->query("SELECT rowid FROM " . MAIN_DB_PREFIX . "flotte_firebase_config WHERE entity = " . $entity);
        if ($check && $this->db->num_rows($check) > 0) {
            $sql  = "UPDATE " . MAIN_DB_PREFIX . "flotte_firebase_config SET";
            $sql .= " server_key = '"           . $server_key . "'";
            $sql .= ", project_id = '"          . $project_id . "'";
            $sql .= ", service_account_json = '" . $sa_json   . "'";
            $sql .= ", vapid_key = '"           . $vapid      . "'";
            $sql .= ", use_v1_api = "           . $use_v1;
            $sql .= ", date_update = '"         . $now        . "'";
            $sql .= ", fk_user_update = "       . $fk_user;
            $sql .= " WHERE entity = "          . $entity;
        } else {
            $sql  = "INSERT INTO " . MAIN_DB_PREFIX . "flotte_firebase_config";
            $sql .= " (entity, server_key, project_id, service_account_json, vapid_key, use_v1_api, date_update, fk_user_update)";
            $sql .= " VALUES (";
            $sql .= $entity . ", '" . $server_key . "', '" . $project_id . "', '" . $sa_json . "', '" . $vapid . "', " . $use_v1 . ", '" . $now . "', " . $fk_user . ")";
        }

        $this->config = null; // Reset cache
        return (bool) $this->db->query($sql);
    }

    /**
     * Returns true if Firebase is properly configured
     */
    public function isConfigured()
    {
        $cfg = $this->getConfig();
        if ($cfg['use_v1_api']) {
            return !empty($cfg['project_id']) && !empty($cfg['service_account_json']);
        }
        return !empty($cfg['server_key']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TOKEN REGISTRATION
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Register or update a device token for a user
     */
    public function registerToken($fk_user, $token, $device_type = 'web', $label = 'Browser')
    {
        global $conf;
        $entity      = (int) $conf->entity;
        $fk_user     = (int) $fk_user;
        $token_esc   = $this->db->escape($token);
        $type_esc    = $this->db->escape($device_type);
        $label_esc   = $this->db->escape($label);
        $now         = $this->db->idate(dol_now());

        // Check if token already exists
        $check = $this->db->query(
            "SELECT rowid FROM " . MAIN_DB_PREFIX . "flotte_firebase_tokens WHERE device_token = '" . $token_esc . "' LIMIT 1"
        );

        if ($check && $this->db->num_rows($check) > 0) {
            $row = $this->db->fetch_object($check);
            $sql = "UPDATE " . MAIN_DB_PREFIX . "flotte_firebase_tokens SET"
                 . " fk_user = " . $fk_user
                 . ", device_label = '" . $label_esc . "'"
                 . ", date_last_used = '" . $now . "'"
                 . ", active = 1"
                 . " WHERE rowid = " . (int) $row->rowid;
        } else {
            $sql = "INSERT INTO " . MAIN_DB_PREFIX . "flotte_firebase_tokens"
                 . " (fk_user, device_token, device_type, device_label, entity, date_creation, date_last_used, active)"
                 . " VALUES ("
                 . $fk_user . ", '" . $token_esc . "', '" . $type_esc . "', '" . $label_esc . "', "
                 . $entity . ", '" . $now . "', '" . $now . "', 1)";
        }

        return (bool) $this->db->query($sql);
    }

    /**
     * Get all active tokens for a user
     */
    public function getTokensForUser($fk_user)
    {
        $fk_user = (int) $fk_user;
        $tokens  = array();

        $sql = "SELECT device_token FROM " . MAIN_DB_PREFIX . "flotte_firebase_tokens"
             . " WHERE fk_user = " . $fk_user . " AND active = 1";
        $res = $this->db->query($sql);
        if ($res) {
            while ($row = $this->db->fetch_object($res)) {
                $tokens[] = $row->device_token;
            }
        }
        return $tokens;
    }

    /**
     * Get all active tokens (for broadcast)
     */
    public function getAllTokens()
    {
        global $conf;
        $tokens = array();

        $sql = "SELECT device_token FROM " . MAIN_DB_PREFIX . "flotte_firebase_tokens"
             . " WHERE active = 1 AND entity = " . (int) $conf->entity;
        $res = $this->db->query($sql);
        if ($res) {
            while ($row = $this->db->fetch_object($res)) {
                $tokens[] = $row->device_token;
            }
        }
        return $tokens;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // SEND NOTIFICATIONS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Send notification to a specific user
     */
    public function sendToUser($fk_user, $title, $body, $data = array(), $type = 'custom', $priority = 2, $fk_vehicle = null)
    {
        $tokens = $this->getTokensForUser($fk_user);

        if (empty($tokens)) {
            return array('success' => false, 'sent' => 0, 'error' => 'No registered devices for this user');
        }

        return $this->sendToTokens($tokens, $title, $body, $data, $type, $priority, $fk_vehicle);
    }

    /**
     * Broadcast notification to all users
     */
    public function broadcast($title, $body, $data = array(), $type = 'custom', $priority = 2, $fk_vehicle = null)
    {
        $tokens = $this->getAllTokens();

        if (empty($tokens)) {
            return array('success' => false, 'sent' => 0, 'error' => 'No registered devices');
        }

        return $this->sendToTokens($tokens, $title, $body, $data, $type, $priority, $fk_vehicle);
    }

    /**
     * Send to a list of tokens
     */
    public function sendToTokens(array $tokens, $title, $body, $data = array(), $type = 'custom', $priority = 2, $fk_vehicle = null)
    {
        if (!$this->isConfigured()) {
            return array('success' => false, 'sent' => 0, 'error' => 'Firebase not configured');
        }

        $cfg     = $this->getConfig();
        $sent    = 0;
        $failed  = 0;
        $lastErr = '';

        foreach ($tokens as $token) {
            if ($cfg['use_v1_api']) {
                $result = $this->sendV1($token, $title, $body, $data);
            } else {
                $result = $this->sendLegacy($token, $title, $body, $data);
            }

            $status = $result['success'] ? 'sent' : 'failed';
            $this->logNotification($type, $title, $body, $priority, $status, $fk_vehicle, $result['raw'] ?? '', $result['error'] ?? '');

            if ($result['success']) {
                $sent++;
            } else {
                $failed++;
                $lastErr = $result['error'] ?? 'Unknown error';
                // Deactivate invalid tokens
                if (!empty($result['invalid_token'])) {
                    $this->deactivateToken($token);
                }
            }
        }

        return array(
            'success' => $sent > 0,
            'sent'    => $sent,
            'failed'  => $failed,
            'error'   => $lastErr,
        );
    }

    /**
     * Send via FCM v1 API (recommended)
     */
    private function sendV1($token, $title, $body, $data = array())
    {
        $cfg        = $this->getConfig();
        $project_id = $cfg['project_id'];
        $url        = str_replace('{project_id}', $project_id, $this->fcmEndpoint);

        $accessToken = $this->getOAuthToken();
        if (!$accessToken) {
            return array('success' => false, 'error' => 'Failed to get OAuth token');
        }

        $payload = array(
            'message' => array(
                'token'        => $token,
                'notification' => array(
                    'title' => $title,
                    'body'  => $body,
                ),
                'data'         => array_map('strval', $data),
                'webpush'      => array(
                    'notification' => array(
                        'icon'  => '/flotte/img/flotte_icon.png',
                        'badge' => '/flotte/img/flotte_badge.png',
                    ),
                ),
            ),
        );

        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => array(
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json',
            ),
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_TIMEOUT        => 10,
        ));

        $raw      = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $response = json_decode($raw, true);

        if ($httpCode === 200) {
            return array('success' => true, 'raw' => $raw);
        }

        $errMsg        = $response['error']['message'] ?? 'HTTP ' . $httpCode;
        $invalidToken  = isset($response['error']['details']) &&
                         strpos(json_encode($response['error']['details']), 'UNREGISTERED') !== false;

        return array(
            'success'       => false,
            'error'         => $errMsg,
            'raw'           => $raw,
            'invalid_token' => $invalidToken,
        );
    }

    /**
     * Send via Legacy FCM API
     */
    private function sendLegacy($token, $title, $body, $data = array())
    {
        $cfg = $this->getConfig();

        $payload = array(
            'to'           => $token,
            'notification' => array(
                'title' => $title,
                'body'  => $body,
                'icon'  => '/flotte/img/flotte_icon.png',
            ),
            'data'         => $data,
        );

        $ch = curl_init($this->fcmLegacyEndpoint);
        curl_setopt_array($ch, array(
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => array(
                'Authorization: key=' . $cfg['server_key'],
                'Content-Type: application/json',
            ),
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_TIMEOUT        => 10,
        ));

        $raw      = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $response     = json_decode($raw, true);
        $success      = $httpCode === 200 && ($response['success'] ?? 0) > 0;
        $invalidToken = isset($response['results'][0]['error']) &&
                        in_array($response['results'][0]['error'], array('NotRegistered', 'InvalidRegistration'));

        return array(
            'success'       => $success,
            'error'         => $response['results'][0]['error'] ?? ($success ? '' : 'HTTP ' . $httpCode),
            'raw'           => $raw,
            'invalid_token' => $invalidToken,
        );
    }

    /**
     * Get OAuth2 access token using service account JSON
     */
    private function getOAuthToken()
    {
        $cfg     = $this->getConfig();
        $sa      = json_decode($cfg['service_account_json'], true);

        if (!$sa || empty($sa['private_key']) || empty($sa['client_email'])) {
            return null;
        }

        $now    = time();
        $header = base64_encode(json_encode(array('alg' => 'RS256', 'typ' => 'JWT')));
        $claim  = base64_encode(json_encode(array(
            'iss'   => $sa['client_email'],
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud'   => $this->oauthEndpoint,
            'iat'   => $now,
            'exp'   => $now + 3600,
        )));

        $header = str_replace(array('+', '/', '='), array('-', '_', ''), $header);
        $claim  = str_replace(array('+', '/', '='), array('-', '_', ''), $claim);

        $toSign = $header . '.' . $claim;
        openssl_sign($toSign, $sig, $sa['private_key'], 'SHA256');
        $sig = str_replace(array('+', '/', '='), array('-', '_', ''), base64_encode($sig));

        $jwt = $toSign . '.' . $sig;

        $ch = curl_init($this->oauthEndpoint);
        curl_setopt_array($ch, array(
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS     => http_build_query(array(
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $jwt,
            )),
            CURLOPT_TIMEOUT        => 10,
        ));

        $raw  = curl_exec($ch);
        curl_close($ch);

        $resp = json_decode($raw, true);
        return $resp['access_token'] ?? null;
    }

    /**
     * Mark a token as inactive (invalid/unregistered)
     */
    private function deactivateToken($token)
    {
        $token_esc = $this->db->escape($token);
        $this->db->query(
            "UPDATE " . MAIN_DB_PREFIX . "flotte_firebase_tokens SET active = 0 WHERE device_token = '" . $token_esc . "'"
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // NOTIFICATION LOG
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Write a notification attempt to the log
     */
    private function logNotification($type, $title, $body, $priority, $status, $fk_vehicle = null, $firebase_response = '', $error_message = '')
    {
        global $conf;
        $entity   = (int) $conf->entity;
        $now      = $this->db->idate(dol_now());
        $fk_veh   = $fk_vehicle ? (int) $fk_vehicle : 'NULL';

        $sql  = "INSERT INTO " . MAIN_DB_PREFIX . "flotte_notification_log";
        $sql .= " (entity, type, title, body, priority, status, fk_vehicle, firebase_response, error_message, date_creation, date_sent)";
        $sql .= " VALUES (";
        $sql .= $entity . ", '" . $this->db->escape($type) . "', '" . $this->db->escape($title) . "', '" . $this->db->escape($body) . "', ";
        $sql .= (int) $priority . ", '" . $this->db->escape($status) . "', " . $fk_veh . ", ";
        $sql .= "'" . $this->db->escape($firebase_response) . "', '" . $this->db->escape($error_message) . "', ";
        $sql .= "'" . $now . "', " . ($status === 'sent' ? "'" . $now . "'" : 'NULL') . ")";

        $this->db->query($sql);
    }

    /**
     * Get notification log with filters and pagination
     */
    public function getNotificationLog($filters = array())
    {
        global $conf;
        $entity = (int) $conf->entity;

        $sql  = "SELECT nl.*, v.ref as vehicle_ref, v.maker, v.model";
        $sql .= " FROM " . MAIN_DB_PREFIX . "flotte_notification_log nl";
        $sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "flotte_vehicle v ON v.rowid = nl.fk_vehicle";
        $sql .= " WHERE nl.entity = " . $entity;

        if (!empty($filters['type'])) {
            $sql .= " AND nl.type = '" . $this->db->escape($filters['type']) . "'";
        }
        if (!empty($filters['status'])) {
            $sql .= " AND nl.status = '" . $this->db->escape($filters['status']) . "'";
        }

        $sql .= " ORDER BY nl.rowid DESC";

        $limit  = isset($filters['limit'])  ? (int) $filters['limit']  : 25;
        $offset = isset($filters['offset']) ? (int) $filters['offset'] : 0;
        $sql .= " LIMIT " . $limit . " OFFSET " . $offset;

        $logs = array();
        $res  = $this->db->query($sql);
        if ($res) {
            while ($row = $this->db->fetch_object($res)) {
                $logs[] = $row;
            }
        }
        return $logs;
    }

    /**
     * Count notification log entries with filters
     */
    public function countNotificationLog($filters = array())
    {
        global $conf;
        $entity = (int) $conf->entity;

        $sql = "SELECT COUNT(*) as total FROM " . MAIN_DB_PREFIX . "flotte_notification_log WHERE entity = " . $entity;

        if (!empty($filters['type'])) {
            $sql .= " AND type = '" . $this->db->escape($filters['type']) . "'";
        }
        if (!empty($filters['status'])) {
            $sql .= " AND status = '" . $this->db->escape($filters['status']) . "'";
        }

        $res = $this->db->query($sql);
        if ($res) {
            $row = $this->db->fetch_object($res);
            return (int) $row->total;
        }
        return 0;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ALERT SCAN (called by cron_alerts.php)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Scan all active alert rules and send notifications for matches
     */
    public function runAlertScan()
    {
        global $conf;
        $entity    = (int) $conf->entity;
        $processed = 0;
        $sent      = 0;

        $sql = "SELECT * FROM " . MAIN_DB_PREFIX . "flotte_alert_rules"
             . " WHERE is_active = 1 AND entity = " . $entity;
        $res = $this->db->query($sql);
        if (!$res) return array('processed' => 0, 'sent' => 0);

        while ($rule = $this->db->fetch_object($res)) {
            $processed++;
            $sent += $this->processRule($rule);
        }

        return array('processed' => $processed, 'sent' => $sent);
    }

    /**
     * Process a single alert rule
     */
    private function processRule($rule)
    {
        $sent      = 0;
        $daysList  = array((int) $rule->days_before);
        if ((int) $rule->days_before_second > 0) {
            $daysList[] = (int) $rule->days_before_second;
        }

        foreach ($daysList as $days) {
            $targetDate = date('Y-m-d', strtotime('+' . $days . ' days'));
            $items      = $this->getExpiringItems($rule->alert_type, $targetDate);

            foreach ($items as $item) {
                // Avoid duplicate: check if already sent today for same object
                if ($this->alreadySentToday($rule->alert_type, $item->rowid)) {
                    continue;
                }

                $title = $this->buildTitle($rule->alert_type, $item, $days);
                $body  = $this->buildBody($rule->alert_type, $item, $days);
                $data  = array(
                    'type'       => $rule->alert_type,
                    'vehicle_id' => (string) ($item->rowid ?? ''),
                    'days'       => (string) $days,
                    'priority'   => (string) $rule->priority,
                );

                // Determine who to notify
                if (!empty($rule->notify_users)) {
                    $userIds = array_map('intval', explode(',', $rule->notify_users));
                    foreach ($userIds as $uid) {
                        $r = $this->sendToUser($uid, $title, $body, $data, $rule->alert_type, $rule->priority, $item->rowid ?? null);
                        $sent += $r['sent'];
                    }
                } else {
                    $r = $this->broadcast($title, $body, $data, $rule->alert_type, $rule->priority, $item->rowid ?? null);
                    $sent += $r['sent'];
                }
            }
        }

        return $sent;
    }

    /**
     * Get items expiring on a specific date based on alert type
     */
    private function getExpiringItems($alert_type, $targetDate)
    {
        global $conf;
        $entity = (int) $conf->entity;
        $date   = $this->db->escape($targetDate);
        $items  = array();

        switch ($alert_type) {
            case 'registration_expiry':
                $sql = "SELECT rowid, ref, maker, model, registration_expiry as expiry_date FROM " . MAIN_DB_PREFIX . "flotte_vehicle"
                     . " WHERE registration_expiry = '" . $date . "' AND entity = " . $entity;
                break;
            case 'license_expiry':
                $sql = "SELECT rowid, ref, maker, model, license_expiry as expiry_date FROM " . MAIN_DB_PREFIX . "flotte_vehicle"
                     . " WHERE license_expiry = '" . $date . "' AND entity = " . $entity;
                break;
            case 'insurance_expiry':
                $sql = "SELECT rowid, ref, maker, model, insurance_expiry as expiry_date FROM " . MAIN_DB_PREFIX . "flotte_vehicle"
                     . " WHERE insurance_expiry = '" . $date . "' AND entity = " . $entity;
                break;
            case 'driver_license_expiry':
                $sql = "SELECT rowid, ref, CONCAT(firstname, ' ', lastname) as ref, license_expiry_date as expiry_date FROM " . MAIN_DB_PREFIX . "flotte_driver"
                     . " WHERE license_expiry_date = '" . $date . "' AND entity = " . $entity;
                break;
            case 'workorder_due':
                $sql = "SELECT rowid, ref, due_date as expiry_date FROM " . MAIN_DB_PREFIX . "flotte_workorder"
                     . " WHERE due_date = '" . $date . "' AND entity = " . $entity;
                break;
            case 'inspection_due':
                $sql = "SELECT rowid, ref, datetime_out as expiry_date FROM " . MAIN_DB_PREFIX . "flotte_inspection"
                     . " WHERE DATE(datetime_out) = '" . $date . "' AND entity = " . $entity;
                break;
            default:
                return array();
        }

        $res = $this->db->query($sql);
        if ($res) {
            while ($row = $this->db->fetch_object($res)) {
                $items[] = $row;
            }
        }
        return $items;
    }

    /**
     * Check if a notification was already sent today for this type + object
     */
    private function alreadySentToday($type, $fk_object_id)
    {
        global $conf;
        $today  = date('Y-m-d');
        $entity = (int) $conf->entity;

        $sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "flotte_notification_log"
             . " WHERE type = '" . $this->db->escape($type) . "'"
             . " AND fk_object_id = " . (int) $fk_object_id
             . " AND DATE(date_creation) = '" . $today . "'"
             . " AND entity = " . $entity
             . " AND status = 'sent'"
             . " LIMIT 1";

        $res = $this->db->query($sql);
        return ($res && $this->db->num_rows($res) > 0);
    }

    /**
     * Build notification title based on alert type
     */
    private function buildTitle($alert_type, $item, $days)
    {
        $ref = $item->ref ?? '';
        switch ($alert_type) {
            case 'registration_expiry':  return "🪪 Registration Expiring — " . $ref;
            case 'license_expiry':       return "📋 License Expiring — " . $ref;
            case 'insurance_expiry':     return "🛡️ Insurance Expiring — " . $ref;
            case 'driver_license_expiry':return "👤 Driver License Expiring — " . $ref;
            case 'workorder_due':        return "⚙️ Work Order Due — " . $ref;
            case 'inspection_due':       return "🔧 Inspection Due — " . $ref;
            default:                     return "🔔 Fleet Alert — " . $ref;
        }
    }

    /**
     * Build notification body based on alert type
     */
    private function buildBody($alert_type, $item, $days)
    {
        $ref   = $item->ref ?? '';
        $extra = isset($item->maker) ? $item->maker . ' ' . ($item->model ?? '') : '';
        $when  = $days === 0 ? 'today' : 'in ' . $days . ' day' . ($days > 1 ? 's' : '');

        switch ($alert_type) {
            case 'registration_expiry':  return "Vehicle {$ref} {$extra} registration expires {$when}.";
            case 'license_expiry':       return "Vehicle {$ref} {$extra} license plate expires {$when}.";
            case 'insurance_expiry':     return "Vehicle {$ref} {$extra} insurance expires {$when}.";
            case 'driver_license_expiry':return "Driver {$ref} license expires {$when}.";
            case 'workorder_due':        return "Work order {$ref} is due {$when}.";
            case 'inspection_due':       return "Inspection {$ref} is due {$when}.";
            default:                     return "Action required for {$ref} {$when}.";
        }
    }
}

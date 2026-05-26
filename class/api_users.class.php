<?php
/**
 * Push Notifications REST API — Dolibarr flotte module
 *
 * PLACE THIS FILE AT:
 *   htdocs/custom/flotte/class/api_push.class.php
 *
 * Endpoints (via /api/index.php/explorer):
 *   POST   /users/pushtoken    → Register device token
 *   DELETE /users/pushtoken    → Unregister device token
 */

if (!defined('NOREQUIREMENU'))  define('NOREQUIREMENU', 1);
if (!defined('NOREQUIREHTML'))  define('NOREQUIREHTML', 1);
if (!defined('NOREQUIREAJAX'))  define('NOREQUIREAJAX', 1);
if (!defined('NOCSRFCHECK'))    define('NOCSRFCHECK', 1);

require_once DOL_DOCUMENT_ROOT . '/api/class/api.class.php';
require_once DOL_DOCUMENT_ROOT . '/custom/flotte/class/FirebaseNotificationService.class.php';

/**
 * @access protected
 * @class  DolibarrApiAccess {@requires user,external}
 *
 * @package DolibarrModules\Flotte
 */
class Users extends DolibarrApi
{
    /**
     * Register a device push token for the authenticated user
     *
     * @param  array $request_data { token: string, platform: string }
     *
     * @url    POST /pushtoken
     * @throws RestException 400
     * @throws RestException 500
     * @return array
     */
    public function postPushtoken($request_data = null)
    {
        if (empty(DolibarrApiAccess::$user->rights->flotte->read)) {
            throw new RestException(401, 'No read permission on flotte module');
        }

        $body = (array) $request_data;

        if (empty($body['token'])) {
            throw new RestException(400, 'token is required');
        }

        $token = trim($body['token']);
        $platform = !empty($body['platform']) ? $body['platform'] : 'unknown';

        try {
            $fcm = new FirebaseNotificationService($this->db);
            $result = $fcm->registerToken(
                DolibarrApiAccess::$user->id,
                $token,
                $platform,
                'Mobile app - ' . $platform
            );

            if ($result) {
                return array(
                    'success' => array('code' => 200),
                    'data' => array('message' => 'Token registered successfully'),
                );
            }

            throw new RestException(500, 'Failed to register token');
        } catch (Exception $e) {
            throw new RestException(500, 'Error: ' . $e->getMessage());
        }
    }

    /**
     * Unregister (deactivate) a device push token
     *
     * @param  array $request_data { token: string }
     *
     * @url    DELETE /pushtoken
     * @throws RestException 400
     * @throws RestException 500
     * @return array
     */
    public function deletePushtoken($request_data = null)
    {
        if (empty(DolibarrApiAccess::$user->rights->flotte->read)) {
            throw new RestException(401, 'No read permission on flotte module');
        }

        $body = (array) $request_data;

        if (empty($body['token'])) {
            throw new RestException(400, 'token is required');
        }

        $token = trim($body['token']);

        try {
            $fcm = new FirebaseNotificationService($this->db);
            $result = $fcm->unregisterToken($token);

            return array(
                'success' => array('code' => 200),
                'data' => array('message' => 'Token unregistered'),
            );
        } catch (Exception $e) {
            throw new RestException(500, 'Error: ' . $e->getMessage());
        }
    }
}

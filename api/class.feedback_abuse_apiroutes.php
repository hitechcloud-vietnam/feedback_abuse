<?php

/**
 * Public JSON API for the Feedback & Abuse module.
 *
 * Routes (registered by HostBill User API metadata/cache):
 *   POST   /api/feedback_abuse/submit            — public submit (form widget + API)
 *   GET    /api/feedback_abuse/status/{publicId} — public status read (rate-limited)
 *   GET    /api/feedback_abuse/types             — public: list enabled report types
 *   OPTIONS /api/feedback_abuse/{any}             — CORS preflight for embedded forms
 *
 * The submit endpoint is the public, anonymous, embed-friendly entry
 * point.  It accepts multipart/form-data (so file attachments work) and
 * returns JSON.
 *
 * @package  Other\feedback_abuse\api
 */
class feedback_abuse_apiroutes
{
    /** @var feedback_abuse */
    public $module;

    public function __construct($module = null)
    {
        $this->module = $module;
        if ($this->module === null) {
            $this->module = HBLoader::LoadModule('Other/feedback_abuse');
        }
    }

    /**
     * Submit feedback or abuse report.
     *
     * Accepts multipart/form-data and returns `{ok, public_id, id}` on success.
     *
     * @route POST /feedback_abuse/submit
     * @auth false
     * @category Support Public feedback and abuse report endpoints.
     */
    public function submit()
    {
        header('Content-Type: application/json; charset=utf-8');
        if (!$this->moduleBoolConfig('public_api_enabled', true)) {
            http_response_code(404);
            echo json_encode(array('ok' => false, 'error' => 'not_found'));
            return;
        }
        $this->corsHeaders();
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            http_response_code(405);
            echo json_encode(array('ok' => false, 'error' => 'method_not_allowed'));
            return;
        }

        $ctx = array(
            'ip'         => Utilities::REMOTE_ADDR(),
            'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? substr((string) $_SERVER['HTTP_USER_AGENT'], 0, 255) : '',
            'referrer'   => isset($_SERVER['HTTP_REFERER'])    ? substr((string) $_SERVER['HTTP_REFERER'], 0, 2048)  : '',
            'source'     => $this->detectSource(),
            'language'   => isset($_POST['language']) ? (string) $_POST['language'] : 'english',
            'client_id'  => null, // set if logged-in (see below)
        );

        // Logged-in client: attach the id (if any).
        try {
            $login = HBConfig::getSetting('login');
            if (is_array($login) && !empty($login['id'])) {
                $ctx['client_id'] = (int) $login['id'];
            }
        } catch (\Exception $ignored) {}

        $input = array(
            'type'      => isset($_POST['type'])      ? (string) $_POST['type']      : '',
            'full_name' => isset($_POST['full_name']) ? (string) $_POST['full_name'] : '',
            'phone'     => isset($_POST['phone'])     ? (string) $_POST['phone']     : '',
            'email'     => isset($_POST['email'])     ? (string) $_POST['email']     : '',
            'url'       => isset($_POST['url'])       ? (string) $_POST['url']       : '',
            'subject'   => isset($_POST['subject'])   ? (string) $_POST['subject']   : '',
            'message'   => isset($_POST['message'])   ? (string) $_POST['message']   : '',
        );

        $db = $this->moduleDatabase();
        if (!$db) {
            http_response_code(503);
            echo json_encode(array('ok' => false, 'error' => 'module_unavailable'));
            return;
        }

        $svc = new \Other\feedback_abuse\ReportService($this->module, $db);
        $out = $svc->submit($input, $ctx, $_FILES);

        if (!empty($out['ok'])) {
            http_response_code(201);
            echo json_encode(array(
                'ok'         => true,
                'public_id'  => $out['public_id'],
                'id'         => (int) $out['id'],
                'message'    => 'report_submitted',
            ));
            return;
        }

        // Map error codes to HTTP statuses.
        $code = $out['error'] ?? 'submit_failed';
        switch ($code) {
            case 'rate_limited':  http_response_code(429); break;
            case 'disabled':
            case 'module_disabled': http_response_code(503); break;
            default:              http_response_code(400);
        }
        $payload = array('ok' => false, 'error' => $code);
        if (!empty($out['errors'])) { $payload['fields'] = $out['errors']; }
        echo json_encode($payload);
    }

    /**
     * Read public report status.
     *
     * @route GET /feedback_abuse/status/$publicId
     * @auth false
     * @category Support Public feedback and abuse report endpoints.
     */
    public function status($publicId = '')
    {
        header('Content-Type: application/json; charset=utf-8');
        $this->corsHeaders();
        $publicId = (string) $publicId;
        if (!preg_match('/^[A-Z2-9]{12,32}$/', $publicId)) {
            http_response_code(400);
            echo json_encode(array('ok' => false, 'error' => 'invalid_id'));
            return;
        }
        // Lightweight rate limit (per IP).
        $ip = Utilities::REMOTE_ADDR();
        $db = $this->moduleDatabase();
        if (!$db) {
            http_response_code(503);
            echo json_encode(array('ok' => false, 'error' => 'module_unavailable'));
            return;
        }
        $rl = new \Other\feedback_abuse\RateLimiter($db);
        $hits = $rl->hit($ip, 'status');
        if ($hits > 60) {
            http_response_code(429);
            echo json_encode(array('ok' => false, 'error' => 'rate_limited'));
            return;
        }
        $row = \Other\feedback_abuse\ORM\Report::query()
            ->where('public_id', $publicId)
            ->first(array('id', 'public_id', 'type', 'status', 'severity', 'submitted_at', 'updated_at'));
        if (!$row) {
            http_response_code(404);
            echo json_encode(array('ok' => false, 'error' => 'not_found'));
            return;
        }
        echo json_encode(array(
            'ok'        => true,
            'public_id' => $row->public_id,
            'type'      => $row->type,
            'status'    => $row->status,
            'severity'  => $row->severity,
            'submitted_at' => $row->submitted_at,
            'updated_at'   => $row->updated_at,
        ));
    }

    /**
     * List enabled report types.
     *
     * @route GET /feedback_abuse/types
     * @auth false
     * @category Support Public feedback and abuse report endpoints.
     */
    public function types()
    {
        header('Content-Type: application/json; charset=utf-8');
        $this->corsHeaders();
        $list = $this->moduleEnabledTypes();
        echo json_encode(array('ok' => true, 'types' => $list));
    }

    /**
     * CORS preflight for embedded report forms.
     *
     * @route OPTIONS /feedback_abuse/$any
     * @auth false
     * @category Support Public feedback and abuse report endpoints.
     */
    public function _cors_preflight($any = '')
    {
        $this->corsHeaders();
        http_response_code(204);
        return;
    }

    /**
     * Safe bool config accessor for partial/stale deployments.
     */
    protected function moduleBoolConfig($name, $default = false)
    {
        if (!$this->module || !method_exists($this->module, 'boolConfig')) {
            return (bool) $default;
        }
        return $this->module->boolConfig($name, $default);
    }

    /**
     * Safe database accessor for partial/stale deployments.
     */
    protected function moduleDatabase()
    {
        if (!$this->module || !method_exists($this->module, 'getDatabase')) {
            return null;
        }
        return $this->module->getDatabase();
    }

    /**
     * Safe enabled-type accessor for route listing and API calls.
     */
    protected function moduleEnabledTypes()
    {
        if ($this->module && method_exists($this->module, 'enabledTypes')) {
            return $this->module->enabledTypes();
        }
        return array('feedback', 'phishing', 'malware', 'botnet', 'spam', 'domain_abuse', 'network_abuse');
    }

    /**
     * Source label detection (web / embed / api / email).
     */
    protected function detectSource()
    {
        if (!empty($_POST['source'])) {
            $s = (string) $_POST['source'];
            if (in_array($s, array('web', 'embed', 'api', 'client_area', 'email'), true)) {
                return $s;
            }
        }
        $origin = isset($_SERVER['HTTP_ORIGIN']) ? (string) $_SERVER['HTTP_ORIGIN'] : '';
        $host   = isset($_SERVER['HTTP_HOST'])   ? (string) $_SERVER['HTTP_HOST']   : '';
        if ($origin !== '' && $host !== '' && stripos($origin, $host) === false) {
            return 'embed';
        }
        return 'api';
    }

    /**
     * Add CORS headers for the embed form.  The 3rd-party site's
     * exact origin is echoed back; the embed HMAC token is the
     * authoritative gate, not CORS.
     */
    protected function corsHeaders()
    {
        $origin = isset($_SERVER['HTTP_ORIGIN']) ? (string) $_SERVER['HTTP_ORIGIN'] : '';
        if ($origin !== '') {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Vary: Origin');
            header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token, X-Embed-Token, X-Embed-Origin');
            header('Access-Control-Allow-Credentials: true');
        }
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
            http_response_code(204);
            exit;
        }
    }
}

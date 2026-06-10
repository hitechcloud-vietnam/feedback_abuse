<?php

namespace Other\feedback_abuse;

use PDO;

/**
 * Submission service — the single entry point for creating a report.
 *
 * Used by:
 *   - The embed widget (widget.feedback_form.php)
 *   - The client-area user controller
 *   - The JSON public API
 *   - The admin "create on behalf of" form
 *
 * Validates, persists, fires the after_report event, and returns the
 * canonical public_id for the reporter to track.
 *
 * @package  Other\feedback_abuse
 */
class ReportService
{
    /** @var feedback_abuse */
    protected $module;

    /** @var PDO */
    protected $db;

    public function __construct($module, PDO $db)
    {
        $this->module = $module;
        $this->db = $db;
    }

    /**
     * Submit a report.  Returns array{ok:bool, public_id?:string, errors?:array, error?:string}.
     *
     * @param array $input  validated input (type, full_name, email, url, message, …)
     * @param array $ctx    context (ip, user_agent, referrer, source, language, client_id)
     * @param array $files  $_FILES style array (may be empty)
     */
    public function submit(array $input, array $ctx, array $files = array())
    {
        if (!$this->module->boolConfig('enabled', true)) {
            return array('ok' => false, 'error' => 'module_disabled');
        }

        $type = isset($input['type']) ? strtolower(trim((string) $input['type'])) : '';
        if (!in_array($type, $this->module->enabledTypes(), true)) {
            return array('ok' => false, 'error' => 'disabled');
        }

        $errors = $this->validate($input);
        if (!empty($errors)) {
            return array('ok' => false, 'errors' => $errors);
        }

        // Rate limit (per IP per hour).
        $rate = (int) $this->module->strConfig('rate_limit_per_hour', '10');
        $ip   = (string) ($ctx['ip'] ?? '');
        if ($rate > 0 && $ip !== '') {
            $rl = new RateLimiter($this->db);
            $hits = $rl->hit($ip, $ctx['source'] === 'api' ? 'api' : ($ctx['source'] === 'embed' ? 'embed' : 'submit'));
            if ($hits > $rate) {
                return array('ok' => false, 'error' => 'rate_limited');
            }
        }

        $now      = date('Y-m-d H:i:s');
        $publicId = EmbedToken::publicId();
        $lang     = isset($ctx['language']) ? (string) $ctx['language'] : 'english';
        if (!in_array($lang, array('english', 'vietnamese'), true)) {
            $lang = 'english';
        }

        try {
            $this->db->beginTransaction();
            $stmt = $this->db->prepare(
                'INSERT INTO `hb_feedback_abuse_reports`
                   (`public_id`, `type`, `status`, `severity`,
                    `full_name`, `phone`, `email`, `url`,
                    `subject`, `message`, `source`, `referrer`,
                    `ip`, `user_agent`, `client_id`, `language`,
                    `extra`, `submitted_at`, `updated_at`)
                 VALUES
                   (:pid, :type, :status, :sev,
                    :fn, :ph, :em, :url,
                    :sub, :msg, :src, :ref,
                    :ip, :ua, :cid, :lang,
                    :extra, :sub_at, :upd_at)'
            );
            $stmt->execute(array(
                'pid'     => $publicId,
                'type'    => $type,
                'status'  => self::STATUS_NEW,
                'sev'     => $this->inferSeverity($type, $input),
                'fn'      => trim((string) $input['full_name']),
                'ph'      => isset($input['phone']) ? trim((string) $input['phone']) : null,
                'em'      => trim((string) $input['email']),
                'url'     => isset($input['url']) ? trim((string) $input['url']) : null,
                'sub'     => isset($input['subject']) ? trim((string) $input['subject']) : null,
                'msg'     => trim((string) $input['message']),
                'src'     => isset($ctx['source']) ? (string) $ctx['source'] : 'web',
                'ref'     => isset($ctx['referrer']) ? (string) $ctx['referrer'] : null,
                'ip'      => $ip !== '' ? $ip : null,
                'ua'      => isset($ctx['user_agent']) ? (string) $ctx['user_agent'] : null,
                'cid'     => isset($ctx['client_id']) ? (int) $ctx['client_id'] : null,
                'lang'    => $lang,
                'extra'   => isset($input['extra']) ? json_encode($input['extra']) : null,
                'sub_at'  => $now,
                'upd_at'  => $now,
            ));
            $reportId = (int) $this->db->lastInsertId();

            // Save attachments, if any.
            if (!empty($files) && $this->module->boolConfig('allow_attachments', true)) {
                $base = $this->module->ensureStoragePath();
                $store = new AttachmentStore(
                    $this->db,
                    $base,
                    $this->module->allowedExtensions(),
                    $this->module->maxFileSizeBytes()
                );
                $this->processUploads($store, $files, $reportId);
            }

            // Audit + initial note.
            $this->writeAudit($reportId, 'system', null, 'created', null, null, null, $ip);
            $this->db->commit();
        } catch (\Exception $ex) {
            if ($this->db->inTransaction()) { $this->db->rollBack(); }
            return array('ok' => false, 'error' => 'submit_failed');
        }

        // Fire the HostBill after_report event. Listeners can:
        //  - send notification email to the configured admin address
        //  - push to an external abuse handler (Spamhaus, Google SafeBrowsing)
        //  - open a HostBill ticket
        try {
            if (class_exists('\\HBEventManager')) {
                \HBEventManager::notify('after_report', array(
                    'module'    => 'feedback_abuse',
                    'report_id' => $reportId,
                    'public_id' => $publicId,
                    'type'      => $type,
                    'email'     => $input['email'],
                ));
            }
        } catch (\Exception $ignored) { /* best-effort */ }

        return array('ok' => true, 'public_id' => $publicId, 'id' => $reportId);
    }

    /**
     * Default-severity heuristic by type.  Admins can later change it
     * via the admin UI; this is just the initial value.
     */
    protected function inferSeverity($type, array $input)
    {
        switch ($type) {
            case 'phishing':    return self::SEV_HIGH;
            case 'malware':     return self::SEV_CRITICAL;
            case 'botnet':      return self::SEV_CRITICAL;
            case 'spam':        return self::SEV_MEDIUM;
            case 'domain_abuse':return self::SEV_MEDIUM;
            case 'network_abuse':return self::SEV_HIGH;
            default:            return self::SEV_LOW;
        }
    }

    /**
     * Field-level validation.  Returns array of error codes keyed by field.
     */
    public function validate(array $input)
    {
        $errors = array();
        if (!isset($input['full_name']) || trim((string) $input['full_name']) === '') {
            $errors['full_name'] = 'required';
        }
        if (!isset($input['email']) || !filter_var($input['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'invalid_email';
        }
        if (!isset($input['message']) || trim((string) $input['message']) === '') {
            $errors['message'] = 'required';
        }
        if (isset($input['url']) && trim((string) $input['url']) !== '') {
            $u = trim((string) $input['url']);
            // Allow http(s)://, bare hostnames, IPv4/IPv6, with optional path.
            if (!preg_match('#^(https?://)?[A-Za-z0-9.\-]+(:[0-9]+)?(/[^\s]*)?$#', $u)
                && !filter_var($u, FILTER_VALIDATE_URL)
                && !filter_var($u, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)
            ) {
                $errors['url'] = 'invalid_url';
            }
        }
        return $errors;
    }

    /**
     * Walk a multi-file upload ($_FILES['attachments']['name'][i]…) and
     * persist each one via the AttachmentStore.
     */
    protected function processUploads(AttachmentStore $store, array $files, $reportId)
    {
        // Normalise: support both 'attachments' (multi) and 'attachment' (single)
        if (isset($files['attachment']) && !is_array($files['attachment']['name'])) {
            $f = $files['attachment'];
            $f['name'] = array($f['name']);
            $f['type'] = array($f['type']);
            $f['tmp_name'] = array($f['tmp_name']);
            $f['error'] = array($f['error']);
            $f['size'] = array($f['size']);
            $files['attachment'] = $f;
        }
        foreach (array('attachments', 'attachment') as $key) {
            if (!isset($files[$key])) { continue; }
            $names = (array) $files[$key]['name'];
            $tmp   = (array) $files[$key]['tmp_name'];
            $err   = (array) $files[$key]['error'];
            $sz    = (array) $files[$key]['size'];
            $typ   = (array) $files[$key]['type'];
            $count = count($names);
            for ($i = 0; $i < $count; $i++) {
                if (empty($names[$i]) || (int) $err[$i] === UPLOAD_ERR_NO_FILE) {
                    continue;
                }
                $store->save(array(
                    'name'     => $names[$i],
                    'type'     => $typ[$i] ?? '',
                    'tmp_name' => $tmp[$i] ?? '',
                    'error'    => $err[$i] ?? UPLOAD_ERR_NO_FILE,
                    'size'     => (int) ($sz[$i] ?? 0),
                ), $reportId, 'reporter');
            }
        }
    }

    /**
     * Append-only audit insert.
     */
    public function writeAudit($reportId, $actorType, $actorId, $action, $fromVal, $toVal, $meta = null, $ip = null)
    {
        $stmt = $this->db->prepare(
            'INSERT INTO `hb_feedback_abuse_audit`
               (`report_id`, `actor_type`, `actor_id`, `action`,
                `from_value`, `to_value`, `meta`, `ip`, `created_at`)
             VALUES (:rid, :at, :aid, :ac, :fv, :tv, :me, :ip, :dt)'
        );
        $stmt->execute(array(
            'rid' => $reportId !== null ? (int) $reportId : null,
            'at'  => (string) $actorType,
            'aid' => $actorId !== null ? (int) $actorId : null,
            'ac'  => (string) $action,
            'fv'  => $fromVal !== null ? (string) $fromVal : null,
            'tv'  => $toVal !== null ? (string) $toVal : null,
            'me'  => $meta !== null ? json_encode($meta) : null,
            'ip'  => $ip !== null ? (string) $ip : null,
            'dt'  => date('Y-m-d H:i:s'),
        ));
    }

    // Mirror the module's enum constants so callers don't need to reach in.
    const STATUS_NEW            = 'new';
    const STATUS_TRIAGED        = 'triaged';
    const STATUS_INVESTIGATING  = 'investigating';
    const STATUS_ACTION_TAKEN   = 'action_taken';
    const STATUS_REJECTED       = 'rejected';
    const STATUS_CLOSED         = 'closed';
    const SEV_LOW               = 'low';
    const SEV_MEDIUM            = 'medium';
    const SEV_HIGH              = 'high';
    const SEV_CRITICAL          = 'critical';
}

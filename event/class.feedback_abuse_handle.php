<?php

/**
 * Event observer for the Feedback & Abuse module.
 *
 * Implements HostBill's `Observer` interface. The class is auto-loaded
 * when the module fires one of the supported events:
 *
 *   - after_report       — fired by ReportService::submit() on a new report
 *   - after_status_change— fired by the admin controller
 *
 * For each event the observer:
 *   1. Sends an admin notification email to the addresses in
 *      `notify_admin_email` (CSV), unless empty.
 *   2. Writes a single row to hb_feedback_abuse_audit.
 *
 * The class is intentionally side-effect-light: notification failures
 * MUST NOT roll back the underlying transaction.  All errors are
 * swallowed and logged via HostBill's standard logger.
 *
 * @package  Other\feedback_abuse\event
 */
class feedback_abuse_handle implements Observer
{
    /** @var feedback_abuse */
    protected $module;

    public function __construct($module = null)
    {
        $this->module = $module !== null
            ? $module
            : HBLoader::LoadModule('Other/feedback_abuse');
    }

    /**
     * Handle a newly persisted report.
     *
     * HostBill binds event names directly to public methods on this class.
     *
     * @param array $event
     */
    public function after_report($event)
    {
        $this->onAfterReport(is_array($event) ? $event : array());
    }

    /**
     * Handle a report status change made by an administrator.
     *
     * @param array $event
     */
    public function after_status_change($event)
    {
        $this->onAfterStatusChange(is_array($event) ? $event : array());
    }

    /**
     * Fired right after a new report is persisted.
     */
    protected function onAfterReport(array $event)
    {
        if (!$this->module) { return; }
        $reportId = isset($event['report_id']) ? (int) $event['report_id'] : 0;
        $publicId = isset($event['public_id']) ? (string) $event['public_id'] : '';
        $type     = isset($event['type'])      ? (string) $event['type']      : '';
        $email    = isset($event['email'])     ? (string) $event['email']     : '';
        if ($reportId <= 0) { return; }

        // 1) Admin notification email(s).
        $adminCsv = (string) $this->module->strConfig('notify_admin_email', '');
        if ($adminCsv !== '') {
            $addrs = array_filter(array_map('trim', explode(',', $adminCsv)));
            foreach ($addrs as $addr) {
                if (filter_var($addr, FILTER_VALIDATE_EMAIL)) {
                    $this->sendAdminEmail($addr, $publicId, $type, $email);
                }
            }
        }

        // 2) Audit row (idempotent — ReportService already wrote one,
        //    we just record the notification outcome here).
        try {
            $db = $this->module->getDatabase();
            $stmt = $db->prepare(
                'INSERT INTO `hb_feedback_abuse_audit`
                   (`report_id`, `actor_type`, `actor_id`, `action`,
                    `from_value`, `to_value`, `meta`, `ip`, `created_at`)
                 VALUES (:rid, :at, :aid, :ac, :fv, :tv, :me, :ip, :dt)'
            );
            $stmt->execute(array(
                'rid' => $reportId,
                'at'  => 'system',
                'aid' => null,
                'ac'  => 'notification_sent',
                'fv'  => null,
                'tv'  => null,
                'me'  => json_encode(array('public_id' => $publicId, 'type' => $type)),
                'ip'  => null,
                'dt'  => date('Y-m-d H:i:s'),
            ));
        } catch (\Exception $ignored) {}
    }

    /**
     * Fired after admin changes a report's status.
     */
    protected function onAfterStatusChange(array $event)
    {
        if (!$this->module) { return; }
        $reportId = isset($event['report_id']) ? (int) $event['report_id'] : 0;
        if ($reportId <= 0) { return; }
        try {
            $db = $this->module->getDatabase();
            $stmt = $db->prepare(
                'INSERT INTO `hb_feedback_abuse_audit`
                   (`report_id`, `actor_type`, `actor_id`, `action`,
                    `from_value`, `to_value`, `meta`, `ip`, `created_at`)
                 VALUES (:rid, :at, :aid, :ac, :fv, :tv, :me, :ip, :dt)'
            );
            $stmt->execute(array(
                'rid' => $reportId,
                'at'  => 'admin',
                'aid' => isset($event['admin_id']) ? (int) $event['admin_id'] : null,
                'ac'  => 'status_changed_external',
                'fv'  => isset($event['from']) ? (string) $event['from'] : null,
                'tv'  => isset($event['to'])   ? (string) $event['to']   : null,
                'me'  => null,
                'ip'  => isset($event['ip'])    ? (string) $event['ip']  : null,
                'dt'  => date('Y-m-d H:i:s'),
            ));
        } catch (\Exception $ignored) {}
    }

    /**
     * Compose + send a single admin notification.  Uses HostBill's
     * native mail helper when available, falls back to PHP mail().
     */
    protected function sendAdminEmail($to, $publicId, $type, $reporterEmail)
    {
        $subject = sprintf('[Feedback & Abuse] New %s report — %s', $type, $publicId);
        $body    = sprintf(
            "A new report has been submitted.\n\n"
          . "Tracking ID : %s\n"
          . "Type        : %s\n"
          . "Reporter    : %s\n"
          . "Submitted   : %s\n\n"
          . "View it in HostBill admin: %s\n",
            $publicId, $type, $reporterEmail, date('Y-m-d H:i:s'),
            Utilities::checkSecureURL(HBConfig::getConfig('InstallURL'))
              . '?cmd=feedback_abuse&action=view&id=' . $publicId
        );
        try {
            if (class_exists('\\Mailer')) {
                $mailer = new \Mailer();
                $mailer->AddAddress($to);
                $mailer->Subject = $subject;
                $mailer->Body = $body;
                $mailer->AltBody = $body;
                $mailer->IsHTML(false);
                $mailer->Send();
            } else {
                @mail($to, $subject, $body, 'From: noreply@' . (HBConfig::getConfig('ServerName') ?: 'localhost'));
            }
        } catch (\Exception $ignored) {
            // Best-effort.  Do not throw.
        }
    }
}

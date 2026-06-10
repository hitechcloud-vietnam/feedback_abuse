<?php

/**
 * Cron job for the Feedback & Abuse module.
 *
 * HostBill calls $module->cronRun() once a day.  The class below is
 * loaded by cron dispatch and the public `run()` method does the work.
 *
 * Operations (all idempotent):
 *   1. Trim rate-limit cache older than 24 h.
 *   2. Purge attachment files orphaned by report deletion.
 *   3. Auto-close `new` reports older than the configured threshold.
 *   4. Mark expired embed tokens as revoked (for audit clarity).
 *
 * Output is a small array suitable for HostBill's cron log.
 *
 * @package  Other\feedback_abuse\cron
 */
class feedback_abuse_cron
{
    /** @var feedback_abuse */
    protected $module;

    public function __construct($module = null)
    {
        $this->module = $module !== null
            ? $module
            : HBLoader::LoadModule('Other/feedback_abuse');
    }

    public function run()
    {
        $results = array(
            'rate_purged'    => 0,
            'orphans_removed'=> 0,
            'auto_closed'    => 0,
            'tokens_expired' => 0,
        );
        if (!$this->module) {
            return $results;
        }
        $db = $this->module->getDatabase();

        try {
            $rl = new \Other\feedback_abuse\RateLimiter($db);
            $results['rate_purged'] = $rl->purgeOlderThan(24);
        } catch (\Exception $ignored) {}

        try {
            $base = $this->module->ensureStoragePath();
            $store = new \Other\feedback_abuse\AttachmentStore(
                $db, $base, $this->module->allowedExtensions(),
                $this->module->maxFileSizeBytes()
            );
            $results['orphans_removed'] = $store->purgeOrphans();
        } catch (\Exception $ignored) {}

        try {
            $days = (int) $this->module->strConfig('auto_close_after_days', '30');
            if ($days > 0) {
                $cut = date('Y-m-d H:i:s', time() - $days * 86400);
                $stmt = $db->prepare(
                    'UPDATE `hb_feedback_abuse_reports`
                        SET `status` = :to,
                            `closed_at` = :now,
                            `updated_at` = :now
                      WHERE `status` = :from
                        AND `submitted_at` < :cut'
                );
                $stmt->execute(array(
                    'to'  => feedback_abuse::STATUS_CLOSED,
                    'now' => date('Y-m-d H:i:s'),
                    'from'=> feedback_abuse::STATUS_NEW,
                    'cut' => $cut,
                ));
                $results['auto_closed'] = $stmt->rowCount();
            }
        } catch (\Exception $ignored) {}

        try {
            $stmt = $db->prepare(
                'UPDATE `hb_feedback_abuse_tokens`
                    SET `revoked_at` = :now
                  WHERE `revoked_at` IS NULL
                    AND `expires_at` < :now'
            );
            $now = date('Y-m-d H:i:s');
            $stmt->execute(array('now' => $now));
            $results['tokens_expired'] = $stmt->rowCount();
        } catch (\Exception $ignored) {}

        return $results;
    }
}

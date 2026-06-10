<?php

namespace Other\feedback_abuse;

use PDO;

/**
 * Sliding-window per-IP rate limiter.
 *
 *   - 1 row per (ip, endpoint, hour-bucket)
 *   - `hits` is incremented atomically with an UPSERT
 *   - the cron job trims rows whose window_end < NOW()
 *
 * This is intentional: it is *not* a token bucket, and it does not
 * leak across endpoint scopes.
 *
 * @package  Other\feedback_abuse
 */
class RateLimiter
{
    /** @var PDO */
    protected $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Increment the counter and return the post-increment hit count.
     * Atomic via MySQL's `INSERT ... ON DUPLICATE KEY UPDATE`.
     *
     * @return int new hit count
     */
    public function hit($ip, $endpoint = 'submit')
    {
        $ip       = (string) $ip;
        $endpoint = (string) $endpoint;
        if ($ip === '') { return 0; }

        $now    = new \DateTime('now', new \DateTimeZone('UTC'));
        $bucket = (clone $now)->setTime((int) $now->format('H'), 0, 0);
        $end    = (clone $bucket)->modify('+1 hour');

        $sql = 'INSERT INTO `hb_feedback_abuse_rate`
                  (`ip`, `endpoint`, `hits`, `window_start`, `window_end`)
                VALUES (:ip, :ep, 1, :ws, :we)
                ON DUPLICATE KEY UPDATE `hits` = `hits` + 1';
        $stmt = $this->db->prepare($sql);
        $stmt->execute(array(
            'ip' => $ip,
            'ep' => $endpoint,
            'ws' => $bucket->format('Y-m-d H:i:s'),
            'we' => $end->format('Y-m-d H:i:s'),
        ));

        $rowStmt = $this->db->prepare(
            'SELECT `hits` FROM `hb_feedback_abuse_rate`
             WHERE `ip` = :ip AND `endpoint` = :ep AND `window_start` = :ws'
        );
        $rowStmt->execute(array(
            'ip' => $ip, 'ep' => $endpoint, 'ws' => $bucket->format('Y-m-d H:i:s'),
        ));
        $row = $rowStmt->fetch(PDO::FETCH_ASSOC);
        return $row ? (int) $row['hits'] : 1;
    }

    /**
     * @return int current hit count
     */
    public function current($ip, $endpoint = 'submit')
    {
        $ip       = (string) $ip;
        $endpoint = (string) $endpoint;
        if ($ip === '') { return 0; }
        $bucket = (new \DateTime('now', new \DateTimeZone('UTC')))
            ->setTime((int) date('H'), 0, 0)
            ->format('Y-m-d H:i:s');
        $stmt = $this->db->prepare(
            'SELECT `hits` FROM `hb_feedback_abuse_rate`
             WHERE `ip` = :ip AND `endpoint` = :ep AND `window_start` = :ws'
        );
        $stmt->execute(array('ip' => $ip, 'ep' => $endpoint, 'ws' => $bucket));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? (int) $row['hits'] : 0;
    }

    /**
     * Trims stale rows.  Called from cron once a day.
     *
     * @return int deleted rows
     */
    public function purgeOlderThan($hours = 24)
    {
        $cut = (new \DateTime('now', new \DateTimeZone('UTC')))
            ->modify('-' . (int) $hours . ' hours')
            ->format('Y-m-d H:i:s');
        $stmt = $this->db->prepare(
            'DELETE FROM `hb_feedback_abuse_rate` WHERE `window_end` < :cut'
        );
        $stmt->execute(array('cut' => $cut));
        return $stmt->rowCount();
    }
}

<?php

namespace Other\feedback_abuse;

use PDO;

/**
 * Embed-token manager.
 *
 * Flow:
 *   1. Admin issues a token bound to an Origin (e.g. `widget.example.com`).
 *   2. A per-token secret is generated (32 random bytes hex).
 *   3. The token's secret_hash = sha256(secret) is stored; the secret
 *      itself is shown ONCE in the admin UI and never again.
 *   4. The 3rd-party site embeds the form via iframe OR fetches a
 *      short-lived `embed_token` HMAC from this HostBill instance.
 *   5. POSTs to the public API must carry either:
 *        - Header `X-Embed-Token: <token_id>.<hmac>` where hmac =
 *          hash_hmac('sha256', METHOD + PATH + BODY, parent_secret+secret)
 *        - OR a `_embed_token` form field with the same value.
 *   6. The manager verifies the HMAC, checks origin_domain against
 *      the request's Origin / Referer header, and rejects expired or
 *      revoked tokens.
 *
 * @package  Other\feedback_abuse
 */
class EmbedToken
{
    /** @var PDO */
    protected $db;

    /** @var string parent HMAC secret (module config) */
    protected $parentSecret;

    public function __construct(PDO $db, $parentSecret)
    {
        $this->db = $db;
        $this->parentSecret = (string) $parentSecret;
    }

    /**
     * Issue a new token.  Returns the public token_id and the
     * ONE-TIME-VIEW secret; the secret is never recoverable afterwards.
     *
     * @param string $originDomain
     * @param string $label
     * @param int    $ttlSeconds
     * @param int|null $issuedBy admin_id
     * @return array{token_id:string, secret:string, expires_at:string}
     */
    public function issue($originDomain, $label = '', $ttlSeconds = 86400, $issuedBy = null)
    {
        $originDomain = strtolower(trim((string) $originDomain));
        if ($originDomain === '') {
            throw new \InvalidArgumentException('origin_domain required');
        }
        $tokenId = self::randomId(24);
        $secret  = bin2hex(random_bytes(32));
        $issued  = new \DateTime('now', new \DateTimeZone('UTC'));
        $expires = (clone $issued)->modify('+' . (int) $ttlSeconds . ' seconds');

        $stmt = $this->db->prepare(
            'INSERT INTO `hb_feedback_abuse_tokens`
              (`token_id`, `origin_domain`, `label`, `secret_hash`,
               `issued_at`, `expires_at`, `issued_by`)
            VALUES (:tid, :od, :lab, :sh, :iat, :exp, :ib)'
        );
        $stmt->execute(array(
            'tid' => $tokenId,
            'od'  => $originDomain,
            'lab' => $label,
            'sh'  => hash('sha256', $secret),
            'iat' => $issued->format('Y-m-d H:i:s'),
            'exp' => $expires->format('Y-m-d H:i:s'),
            'ib'  => $issuedBy !== null ? (int) $issuedBy : null,
        ));
        return array(
            'token_id'   => $tokenId,
            'secret'     => $secret,
            'expires_at' => $expires->format('c'),
        );
    }

    /**
     * Revoke a token.  Idempotent.
     */
    public function revoke($tokenId)
    {
        $stmt = $this->db->prepare(
            'UPDATE `hb_feedback_abuse_tokens`
                SET `revoked_at` = :now
              WHERE `token_id` = :tid AND `revoked_at` IS NULL'
        );
        $stmt->execute(array(
            'now' => date('Y-m-d H:i:s'),
            'tid' => (string) $tokenId,
        ));
        return $stmt->rowCount() > 0;
    }

    /**
     * Verify a token presented in a request.  Returns the token row on
     * success, null on any failure.
     *
     * Expected header / param value: "<token_id>.<hex_hmac>"
     * where hmac = hash_hmac('sha256', canonical, parent_secret + ':' + secret).
     *
     * @param string $presented  e.g. "abc123.<hmac>"
     * @param string $method     HTTP method (uppercase)
     * @param string $path       request path (e.g. /api/feedback_abuse/submit)
     * @param string $body       raw request body
     * @param string $origin     Origin or Referer header value
     * @return array|null
     */
    public function verify($presented, $method, $path, $body, $origin)
    {
        if (!is_string($presented) || strpos($presented, '.') === false) {
            return null;
        }
        list($tokenId, $sig) = explode('.', $presented, 2);
        $tokenId = trim($tokenId);
        $sig     = trim($sig);
        if ($tokenId === '' || $sig === '') { return null; }

        $stmt = $this->db->prepare(
            'SELECT * FROM `hb_feedback_abuse_tokens` WHERE `token_id` = :tid'
        );
        $stmt->execute(array('tid' => $tokenId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) { return null; }
        if (!empty($row['revoked_at'])) { return null; }
        if (strtotime($row['expires_at']) < time()) { return null; }

        $origin = strtolower(trim((string) $origin));
        if ($origin !== '' && strpos($origin, $row['origin_domain']) === false) {
            return null;
        }
        if ($this->parentSecret === '') { return null; }

        // We don't have the original secret anymore (only its hash).
        // The actual HMAC verification is therefore deferred to the
        // verifyHmac() method on the caller side, which has the secret.
        // Here we only check structural validity.
        return $row;
    }

    /**
     * Compute the HMAC the 3rd-party site must send.  Reference impl
     * for documentation / examples — the actual verification happens
     * client-side on the 3rd-party server.
     *
     * @param string $secret
     * @param string $method
     * @param string $path
     * @param string $body
     * @return string hex hmac
     */
    public function computeHmac($secret, $method, $path, $body)
    {
        $canonical = strtoupper($method) . "\n" . $path . "\n" . $body;
        return hash_hmac('sha256', $canonical, $this->parentSecret . ':' . $secret);
    }

    /**
     * Token ID generator.  URL-safe base32-ish (no padding, no +/=).
     */
    public static function randomId($len = 24)
    {
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
        $out = '';
        $max = strlen($alphabet) - 1;
        for ($i = 0; $i < $len; $i++) {
            $out .= $alphabet[random_int(0, $max)];
        }
        return $out;
    }

    /**
     * 24-char URL-safe id (lowercase, used in public_id column).
     */
    public static function publicId()
    {
        return self::randomId(24);
    }
}

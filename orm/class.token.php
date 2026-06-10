<?php

namespace Other\feedback_abuse\ORM;

use Illuminate\Database\Eloquent\Model as ORM_Model;

/**
 * Eloquent model for hb_feedback_abuse_tokens.
 *
 * Each token authorises a single 3rd-party origin to POST reports to
 * this HostBill instance.  The per-token secret is hashed (sha256) and
 * is never stored in cleartext; the parent module's embed_hmac_secret
 * is used to derive the HMAC.
 *
 * @package  Other\feedback_abuse\ORM
 */
class Token extends ORM_Model
{
    /** @var string */
    protected $table = 'hb_feedback_abuse_tokens';

    public $timestamps = false;

    /** @var array<int,string> */
    protected $fillable = array(
        'token_id', 'origin_domain', 'label', 'secret_hash',
        'issued_at', 'expires_at', 'last_used_at',
        'revoked_at', 'issued_by',
    );

    /** @var array<string,string> */
    protected $casts = array(
        'issued_at'    => 'datetime',
        'expires_at'   => 'datetime',
        'last_used_at' => 'datetime',
        'revoked_at'   => 'datetime',
    );

    /**
     * Is the token still usable?
     */
    public function getIsActiveAttribute()
    {
        if ($this->revoked_at !== null) { return false; }
        if ($this->expires_at !== null && $this->expires_at->isPast()) { return false; }
        return true;
    }
}

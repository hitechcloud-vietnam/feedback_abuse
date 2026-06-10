<?php

namespace Other\feedback_abuse\ORM;

use Illuminate\Database\Eloquent\Model as ORM_Model;

/**
 * Eloquent model for hb_feedback_abuse_audit.
 *
 * Append-only log of every state-changing action on a report.
 * Used by the admin dashboard to show "who did what when" and to
 * satisfy ISO 27001 A.12.4.1 (event logging).
 *
 * @package  Other\feedback_abuse\ORM
 */
class Audit extends ORM_Model
{
    /** @var string */
    protected $table = 'hb_feedback_abuse_audit';

    public $timestamps = false;

    /** @var array<int,string> */
    protected $fillable = array(
        'report_id', 'actor_type', 'actor_id', 'action',
        'from_value', 'to_value', 'meta', 'ip', 'created_at',
    );

    /** @var array<string,string> */
    protected $casts = array(
        'meta'       => 'array',
        'created_at' => 'datetime',
    );

    public function report()
    {
        return $this->belongsTo(Report::class, 'report_id');
    }
}

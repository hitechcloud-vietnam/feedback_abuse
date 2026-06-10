<?php

namespace Other\feedback_abuse\ORM;

use Illuminate\Database\Eloquent\Model as ORM_Model;

/**
 * Eloquent model for hb_feedback_abuse_notes.
 *
 * Internal staff commentary.  Never exposed through the public widget
 * or the public API; only the admin controller renders these.
 *
 * @package  Other\feedback_abuse\ORM
 */
class Note extends ORM_Model
{
    /** @var string */
    protected $table = 'hb_feedback_abuse_notes';

    public $timestamps = false;

    /** @var array<int,string> */
    protected $fillable = array(
        'report_id', 'admin_id', 'note', 'is_internal', 'created_at',
    );

    /** @var array<string,string> */
    protected $casts = array(
        'is_internal' => 'boolean',
        'created_at'  => 'datetime',
    );

    public function report()
    {
        return $this->belongsTo(Report::class, 'report_id');
    }
}

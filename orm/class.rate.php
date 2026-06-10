<?php

namespace Other\feedback_abuse\ORM;

use Illuminate\Database\Eloquent\Model as ORM_Model;

/**
 * Eloquent model for hb_feedback_abuse_rate.
 *
 * Sliding-window rate-limit cache.  One row per (ip, endpoint, window_start)
 * where window_start is truncated to the hour.  The cron job trims
 * window_end < NOW() rows once a day.
 *
 * @package  Other\feedback_abuse\ORM
 */
class Rate extends ORM_Model
{
    /** @var string */
    protected $table = 'hb_feedback_abuse_rate';

    public $timestamps = false;

    /** @var array<int,string> */
    protected $fillable = array(
        'ip', 'endpoint', 'hits', 'window_start', 'window_end',
    );

    /** @var array<string,string> */
    protected $casts = array(
        'hits'         => 'integer',
        'window_start' => 'datetime',
        'window_end'   => 'datetime',
    );
}

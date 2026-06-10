<?php

namespace Other\feedback_abuse\ORM;

use Illuminate\Database\Eloquent\Model as ORM_Model;

/**
 * Eloquent model for hb_feedback_abuse_reports.
 *
 * One row per submitted report.  The `extra` column is JSON-decoded
 * into a free-form array on read.  Status / severity are guarded by
 * constants on the parent module class.
 *
 * @package  Other\feedback_abuse\ORM
 * @version  1.0.0
 */
class Report extends ORM_Model
{
    /** @var string */
    protected $table = 'hb_feedback_abuse_reports';

    /** HostBill rows use DATETIME columns, not Eloquent's default timestamps. */
    public $timestamps = false;

    /** @var array<int,string> */
    protected $fillable = array(
        'public_id', 'type', 'status', 'severity',
        'full_name', 'phone', 'email', 'url',
        'subject', 'message', 'source', 'referrer',
        'ip', 'user_agent', 'client_id', 'admin_id',
        'language', 'extra',
        'submitted_at', 'updated_at', 'closed_at',
    );

    /** @var array<string,string> */
    protected $casts = array(
        'extra'        => 'array',
        'submitted_at' => 'datetime',
        'updated_at'   => 'datetime',
        'closed_at'    => 'datetime',
    );

    /**
     * Attachments relation.
     */
    public function attachments()
    {
        return $this->hasMany(Attachment::class, 'report_id');
    }

    /**
     * Internal notes relation.
     */
    public function notes()
    {
        return $this->hasMany(Note::class, 'report_id')->orderBy('created_at', 'asc');
    }

    /**
     * Audit entries.
     */
    public function audits()
    {
        return $this->hasMany(Audit::class, 'report_id')->orderBy('created_at', 'desc');
    }

    /**
     * Scope: only "open" (not yet closed/rejected).
     */
    public function scopeOpen($q)
    {
        return $q->whereNotIn('status', array('closed', 'rejected'));
    }

    /**
     * Scope: filter by type.
     */
    public function scopeOfType($q, $type)
    {
        if ($type === '' || $type === null || $type === 'all') {
            return $q;
        }
        return $q->where('type', (string) $type);
    }

    /**
     * Scope: filter by status.
     */
    public function scopeOfStatus($q, $status)
    {
        if ($status === '' || $status === null || $status === 'all') {
            return $q;
        }
        if ($status === 'open') {
            return $q->whereNotIn('status', array('closed', 'rejected'));
        }
        return $q->where('status', (string) $status);
    }

    /**
     * Scope: text search over message + email + url + name.
     */
    public function scopeSearch($q, $term)
    {
        $term = trim((string) $term);
        if ($term === '') {
            return $q;
        }
        $like = '%' . str_replace(array('%', '_'), array('\\%', '\\_'), $term) . '%';
        return $q->where(function ($w) use ($like) {
            $w->where('message',   'like', $like)
              ->orWhere('email',   'like', $like)
              ->orWhere('full_name','like', $like)
              ->orWhere('url',     'like', $like)
              ->orWhere('subject', 'like', $like)
              ->orWhere('public_id','like', $like);
        });
    }

    /**
     * Convenience accessor for the human-readable status label.
     */
    public function getStatusLabelAttribute()
    {
        $map = array(
            'new'             => 'st_new',
            'triaged'         => 'st_triaged',
            'investigating'   => 'st_investigating',
            'action_taken'    => 'st_action_taken',
            'rejected'        => 'st_rejected',
            'closed'          => 'st_closed',
        );
        return isset($map[$this->status]) ? $map[$this->status] : $this->status;
    }
}

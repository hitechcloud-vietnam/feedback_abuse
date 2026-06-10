<?php

namespace Other\feedback_abuse\ORM;

use Illuminate\Database\Eloquent\Model as ORM_Model;

/**
 * Eloquent model for hb_feedback_abuse_attachments.
 *
 * Files live on disk under <storage_path>/YYYY/MM/<sha256>.<ext>.
 * This table is the authoritative index; rows are deleted (and files
 * removed) when the parent report is deleted via FK CASCADE.
 *
 * @package  Other\feedback_abuse\ORM
 */
class Attachment extends ORM_Model
{
    /** @var string */
    protected $table = 'hb_feedback_abuse_attachments';

    public $timestamps = false;

    /** @var array<int,string> */
    protected $fillable = array(
        'report_id', 'orig_name', 'stored_name', 'extension',
        'mime_type', 'size_bytes', 'storage_path', 'sha256',
        'uploaded_by', 'uploaded_at',
    );

    /** @var array<string,string> */
    protected $casts = array(
        'size_bytes' => 'integer',
        'uploaded_at'=> 'datetime',
    );

    public function report()
    {
        return $this->belongsTo(Report::class, 'report_id');
    }

    /**
     * Absolute on-disk path (resolved at read time against the
     * module's storage_path config).
     */
    public function absolutePath($storageBase)
    {
        $base = rtrim((string) $storageBase, '/\\');
        $rel  = ltrim(str_replace(array('/', '\\'), DS, $this->storage_path), '/\\');
        return $base . DS . $rel;
    }
}

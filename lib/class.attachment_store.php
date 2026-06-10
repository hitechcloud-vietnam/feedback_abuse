<?php

namespace Other\feedback_abuse;

use PDO;

/**
 * Attachment store.
 *
 * Strategy:
 *   1. Stream the uploaded tmp file to a deterministic path keyed by
 *      `sha256(file)` — same file uploaded twice only stores once.
 *   2. Validate extension against the allowlist, size against the cap.
 *   3. Insert a row in hb_feedback_abuse_attachments.  The FK on
 *      report_id (CASCADE) means deleting a report cleans up the rows;
 *      the on-disk file is removed by a `purgeOrphans()` cron pass
 *      and by the explicit `remove()` call below.
 *
 * All file I/O uses the module's `storage_path` configuration value.
 *
 * @package  Other\feedback_abuse
 */
class AttachmentStore
{
    /** @var PDO */
    protected $db;

    /** @var string absolute path to storage root */
    protected $base;

    /** @var array<int,string> lower-case ext allowlist (no dot) */
    protected $allowedExts;

    /** @var int max bytes per file */
    protected $maxBytes;

    public function __construct(PDO $db, $base, array $allowedExts, $maxBytes)
    {
        $this->db = $db;
        $this->base = rtrim((string) $base, '/\\');
        $this->allowedExts = array_map('strtolower', $allowedExts);
        $this->maxBytes = (int) $maxBytes;
    }

    /**
     * Save an uploaded file (single $_FILES['x'] entry).
     *
     * @param array $file  a single $_FILES element
     * @param int   $reportId
     * @param string $uploadedBy 'reporter'|'admin'
     * @return array{ok:bool, attachment_id?:int, error?:string}
     */
    public function save(array $file, $reportId, $uploadedBy = 'reporter')
    {
        if (!isset($file['error']) || is_array($file['error'])) {
            return array('ok' => false, 'error' => 'invalid_upload');
        }
        switch ((int) $file['error']) {
            case UPLOAD_ERR_OK: break;
            case UPLOAD_ERR_NO_FILE: return array('ok' => false, 'error' => 'no_file');
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                return array('ok' => false, 'error' => 'file_too_big');
            default:
                return array('ok' => false, 'error' => 'upload_failed');
        }
        if (!is_uploaded_file($file['tmp_name'])) {
            return array('ok' => false, 'error' => 'upload_failed');
        }
        $size = (int) $file['size'];
        if ($size <= 0 || $size > $this->maxBytes) {
            return array('ok' => false, 'error' => 'file_too_big');
        }

        $orig = (string) $file['name'];
        $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
        if ($ext === '' || !in_array($ext, $this->allowedExts, true)) {
            return array('ok' => false, 'error' => 'file_type');
        }

        $sha = hash_file('sha256', $file['tmp_name']);
        if ($sha === false) {
            return array('ok' => false, 'error' => 'upload_failed');
        }
        // Verify mime via finfo (best-effort — magic bytes can be spoofed
        // but the extension allowlist is the primary defence).
        $mime = null;
        if (function_exists('finfo_open')) {
            $f = finfo_open(FILEINFO_MIME_TYPE);
            if ($f) {
                $mime = @finfo_file($f, $file['tmp_name']) ?: null;
                @finfo_close($f);
            }
        }

        $ym          = date('Y') . '/' . date('m');
        $storedName  = $sha;
        $relPath     = $ym . '/' . $storedName . '.' . $ext;
        $absDir      = $this->base . '/' . $ym;
        $absPath     = $absDir . '/' . $storedName . '.' . $ext;
        if (!is_dir($absDir)) { @mkdir($absDir, 0755, true); }

        // Move tmp → storage.  Use copy+unlink to safely overwrite
        // (an attacker cannot predict sha256 to plant a malicious file
        // we already trust).
        if (!@move_uploaded_file($file['tmp_name'], $absPath)) {
            // Fallback: if same-content file already exists, just unlink tmp.
            if (!file_exists($absPath)) {
                return array('ok' => false, 'error' => 'upload_failed');
            }
            @unlink($file['tmp_name']);
        }
        @chmod($absPath, 0644);

        try {
            $sql = 'INSERT INTO `hb_feedback_abuse_attachments`
                      (`report_id`, `orig_name`, `stored_name`, `extension`,
                       `mime_type`, `size_bytes`, `storage_path`, `sha256`,
                       `uploaded_by`, `uploaded_at`)
                    VALUES (:rid, :orig, :sn, :ext, :mt, :sz, :sp, :sh, :ub, :dt)
                    ON DUPLICATE KEY UPDATE `report_id` = `report_id`';
            $stmt = $this->db->prepare($sql);
            $stmt->execute(array(
                'rid' => (int) $reportId,
                'orig' => $orig,
                'sn'  => $storedName,
                'ext' => $ext,
                'mt'  => $mime,
                'sz'  => $size,
                'sp'  => $relPath,
                'sh'  => $sha,
                'ub'  => $uploadedBy,
                'dt'  => date('Y-m-d H:i:s'),
            ));
            $id = (int) $this->db->lastInsertId();
            return array('ok' => true, 'attachment_id' => $id);
        } catch (\Exception $ex) {
            @unlink($absPath);
            return array('ok' => false, 'error' => 'upload_failed');
        }
    }

    /**
     * Remove a single attachment (row + file).
     */
    public function remove($attachmentId)
    {
        $stmt = $this->db->prepare(
            'SELECT `id`, `storage_path` FROM `hb_feedback_abuse_attachments` WHERE `id` = :id'
        );
        $stmt->execute(array('id' => (int) $attachmentId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) { return false; }
        $abs = $this->base . '/' . str_replace(array('/', '\\'), '/', $row['storage_path']);
        if (is_file($abs)) { @unlink($abs); }
        $del = $this->db->prepare('DELETE FROM `hb_feedback_abuse_attachments` WHERE `id` = :id');
        $del->execute(array('id' => (int) $attachmentId));
        return true;
    }

    /**
     * Find attachments no longer referenced by any report and remove
     * them.  Run from the cron class.
     *
     * @return int files removed
     */
    public function purgeOrphans()
    {
        $stmt = $this->db->query(
            'SELECT a.id, a.storage_path
               FROM `hb_feedback_abuse_attachments` a
          LEFT JOIN `hb_feedback_abuse_reports` r ON r.id = a.report_id
              WHERE r.id IS NULL'
        );
        $removed = 0;
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $abs = $this->base . '/' . str_replace(array('/', '\\'), '/', $row['storage_path']);
            if (is_file($abs)) { @unlink($abs); $removed++; }
            $del = $this->db->prepare('DELETE FROM `hb_feedback_abuse_attachments` WHERE `id` = :id');
            $del->execute(array('id' => (int) $row['id']));
        }
        return $removed;
    }
}

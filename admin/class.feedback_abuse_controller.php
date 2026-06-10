<?php

/**
 * Admin controller for the Feedback & Abuse module.
 *
 * Routes (mapped via the left-menu + slugs):
 *   _default          → list view (with filters + search)
 *   view&id=N         → single report + notes + audit + attachments
 *   status&id=N&to=…  → change status (POST)
 *   assign&id=N&aid=N → assign / re-assign
 *   addnote&id=N      → POST note text
 *   delete&id=N       → POST delete (admin only, audit-logged)
 *   tokens            → list embed tokens
 *   tokens_issue      → POST new token
 *   tokens_revoke&id= → POST revoke
 *   settings          → module configuration form
 *   download&aid=N    → stream a single attachment
 *   export            → CSV export
 *
 * @package  Other\feedback_abuse\admin
 */
class feedback_abuse_controller extends HBController
{
    /** @var feedback_abuse */
    public $module;

    /** @var AdminAuthorization */
    public $authorization;

    /** Pagesize for the list view. */
    const PAGE_SIZE = 25;

    public function beforeCall($params)
    {
        // Admin guard — re-check even though the menu is admin-only.
        $log = HBConfig::getSetting('admin_login');
        if (!is_array($log) || empty($log['id'])) {
            Utilities::redirect('?cmd=login');
            return false;
        }
        $this->template->pageTitle = $this->module->getModName();
        $this->template->module_template_dir =
            APPDIR_MODULES . 'Other' . DS . strtolower($this->module->getModuleDirName()) . DS . 'admin' . DS . 'templates';
        $this->template->assign('moduleurl',
            Utilities::checkSecureURL(HBConfig::getConfig('InstallURL') . 'includes/modules/Other/' . strtolower($this->module->getModuleDirName()) . '/admin/'));
        $this->template->assign('modulename', $this->module->getModuleName());
        $this->template->assign('modname', $this->module->getModName());
        $this->template->assign('moduleid', $this->module->getModuleId());
        $lang = (is_object($this->module) && method_exists($this->module, 'getLang')) ? $this->module->getLang() : array();
        $this->template->assign('lang', $this->pickLang($lang));
        $this->template->assign('enabled_types', $this->module->enabledTypes());
        $this->template->assign('admin_id', (int) $log['id']);
        $this->template->showtpl = 'default';
        return true;
    }

    /** List view + filters. */
    public function _default($params)
    {
        $type   = isset($params['type'])   ? (string) $params['type']   : 'all';
        $status = isset($params['status']) ? (string) $params['status'] : 'all';
        $q      = isset($params['q'])      ? (string) $params['q']      : '';
        $page   = max(1, (int) (isset($params['p']) ? $params['p'] : 1));

        $this->template->assign('active_view', 'list');
        $this->template->assign('type',   $type);
        $this->template->assign('status', $status);
        $this->template->assign('q',      $q);

        /** @var \Other\feedback_abuse\ORM\Report $reportModel */
        $reportModel = \Other\feedback_abuse\ORM\Report::query();
        $qry = $reportModel->ofType($type)->ofStatus($status)->search($q)
            ->orderBy('submitted_at', 'desc');

        $total = (clone $qry)->count();
        $items = $qry->forPage($page, self::PAGE_SIZE)->get();

        $this->template->assign('items', $items);
        $this->template->assign('total', $total);
        $this->template->assign('page',  $page);
        $this->template->assign('pages', (int) ceil($total / self::PAGE_SIZE));
        $this->template->assign('counts_by_status', $this->statusCounts());
        $this->template->assign('counts_by_type',   $this->typeCounts());
    }

    /** Single report. */
    public function view($params)
    {
        $id = (int) (isset($params['id']) ? $params['id'] : 0);
        $report = $id > 0
            ? \Other\feedback_abuse\ORM\Report::query()->find($id)
            : null;
        if (!$report) {
            Engine::addInfo('Report not found');
            Utilities::redirect($this->adminUrl());
            return;
        }
        $this->template->assign('active_view', 'view');
        $this->template->assign('report', $report);
        $this->template->assign('attachments', $report->attachments()->get());
        $this->template->assign('notes',       $report->notes()->get());
        $this->template->assign('audits',      $report->audits()->limit(100)->get());
        $this->template->assign('admins',      $this->adminList());
    }

    /** POST: change status. */
    public function status($params)
    {
        $this->assertPost();
        $id  = (int) (isset($params['id']) ? $params['id'] : 0);
        $to  = (string) (isset($params['to']) ? $params['to'] : '');
        $valid = array(
            feedback_abuse::STATUS_NEW, feedback_abuse::STATUS_TRIAGED,
            feedback_abuse::STATUS_INVESTIGATING, feedback_abuse::STATUS_ACTION_TAKEN,
            feedback_abuse::STATUS_REJECTED, feedback_abuse::STATUS_CLOSED,
        );
        if (!in_array($to, $valid, true)) {
            Engine::addInfo('Invalid status');
            Utilities::redirect($this->adminUrl('view&id=' . $id));
            return;
        }
        $report = $id > 0 ? \Other\feedback_abuse\ORM\Report::query()->find($id) : null;
        if (!$report) {
            Utilities::redirect($this->adminUrl());
            return;
        }
        $from = $report->status;
        $report->status = $to;
        $report->updated_at = date('Y-m-d H:i:s');
        if (in_array($to, array(feedback_abuse::STATUS_CLOSED, feedback_abuse::STATUS_REJECTED), true)) {
            $report->closed_at = date('Y-m-d H:i:s');
        }
        $report->save();
        $svc = new \Other\feedback_abuse\ReportService($this->module, $this->module->getDatabase());
        $adminId = $this->adminId();
        $ip = Utilities::REMOTE_ADDR();
        $svc->writeAudit($id, 'admin', $adminId, 'status_changed', $from, $to, null, $ip);
        try {
            if (class_exists('\\HBEventManager')) {
                \HBEventManager::notify('after_status_change', array(
                    'module'    => 'feedback_abuse',
                    'report_id' => $id,
                    'admin_id'  => $adminId,
                    'from'      => $from,
                    'to'        => $to,
                    'ip'        => $ip,
                ));
            }
        } catch (\Exception $ignored) { /* best-effort */ }
        Engine::addInfo('Status updated');
        Utilities::redirect($this->adminUrl('view&id=' . $id));
    }

    /** POST: assign admin. */
    public function assign($params)
    {
        $this->assertPost();
        $id  = (int) (isset($params['id']) ? $params['id'] : 0);
        $aid = (int) (isset($params['aid']) ? $params['aid'] : 0);
        $report = $id > 0 ? \Other\feedback_abuse\ORM\Report::query()->find($id) : null;
        if (!$report) {
            Utilities::redirect($this->adminUrl());
            return;
        }
        $from = $report->admin_id;
        $report->admin_id = $aid > 0 ? $aid : null;
        $report->updated_at = date('Y-m-d H:i:s');
        $report->save();
        $svc = new \Other\feedback_abuse\ReportService($this->module, $this->module->getDatabase());
        $svc->writeAudit($id, 'admin', $this->adminId(), 'assigned', $from, $report->admin_id, null, Utilities::REMOTE_ADDR());
        Engine::addInfo('Assignee updated');
        Utilities::redirect($this->adminUrl('view&id=' . $id));
    }

    /** POST: add internal note. */
    public function addnote($params)
    {
        $this->assertPost();
        $id   = (int) (isset($params['id']) ? $params['id'] : 0);
        $text = isset($params['note']) ? trim((string) $params['note']) : '';
        if ($id <= 0 || $text === '') {
            Utilities::redirect($this->adminUrl());
            return;
        }
        $report = \Other\feedback_abuse\ORM\Report::query()->find($id);
        if (!$report) {
            Utilities::redirect($this->adminUrl());
            return;
        }
        \Other\feedback_abuse\ORM\Note::query()->create(array(
            'report_id'   => $id,
            'admin_id'    => $this->adminId(),
            'note'        => $text,
            'is_internal' => 1,
            'created_at'  => date('Y-m-d H:i:s'),
        ));
        $report->updated_at = date('Y-m-d H:i:s');
        $report->save();
        $svc = new \Other\feedback_abuse\ReportService($this->module, $this->module->getDatabase());
        $svc->writeAudit($id, 'admin', $this->adminId(), 'note_added', null, null, null, Utilities::REMOTE_ADDR());
        Engine::addInfo('Note added');
        Utilities::redirect($this->adminUrl('view&id=' . $id));
    }

    /** POST: hard-delete. */
    public function delete($params)
    {
        $this->assertPost();
        $id = (int) (isset($params['id']) ? $params['id'] : 0);
        $report = $id > 0 ? \Other\feedback_abuse\ORM\Report::query()->find($id) : null;
        if (!$report) {
            Utilities::redirect($this->adminUrl());
            return;
        }
        // Audit BEFORE delete (FK constraint will cascade to attachments/notes/audit).
        $svc = new \Other\feedback_abuse\ReportService($this->module, $this->module->getDatabase());
        $svc->writeAudit(null, 'admin', $this->adminId(), 'deleted', null, null,
            array('report_id' => $id, 'public_id' => $report->public_id, 'type' => $report->type),
            Utilities::REMOTE_ADDR());
        $report->delete();
        // Sweep files via AttachmentStore.
        $base = $this->module->ensureStoragePath();
        $store = new \Other\feedback_abuse\AttachmentStore(
            $this->module->getDatabase(), $base,
            $this->module->allowedExtensions(), $this->module->maxFileSizeBytes()
        );
        $store->purgeOrphans();
        Engine::addInfo('Report deleted');
        Utilities::redirect($this->adminUrl());
    }

    /** List embed tokens. */
    public function tokens($params)
    {
        $this->template->assign('active_view', 'tokens');
        $tokens = \Other\feedback_abuse\ORM\Token::query()->orderBy('issued_at', 'desc')->get();
        $this->template->assign('tokens', $tokens);
    }

    /** POST: issue a new embed token. */
    public function tokens_issue($params)
    {
        $this->assertPost();
        $origin = isset($params['origin_domain']) ? (string) $params['origin_domain'] : '';
        $label  = isset($params['label'])         ? (string) $params['label']         : '';
        $ttl    = (int)   (isset($params['ttl'])  ? $params['ttl']                   : 86400);
        if ($origin === '') {
            Engine::addInfo('Origin domain is required');
            Utilities::redirect($this->adminUrl('tokens'));
            return;
        }
        $secret = $this->module->strConfig('embed_hmac_secret');
        if ($secret === '') {
            Engine::addInfo('Set the embed_hmac_secret in module settings first');
            Utilities::redirect($this->adminUrl('tokens'));
            return;
        }
        try {
            $tok = new \Other\feedback_abuse\EmbedToken($this->module->getDatabase(), $secret);
            $issued = $tok->issue($origin, $label, $ttl, $this->adminId());
            $svc = new \Other\feedback_abuse\ReportService($this->module, $this->module->getDatabase());
            $svc->writeAudit(null, 'admin', $this->adminId(), 'token_issued',
                null, $issued['token_id'], array('origin' => $origin, 'label' => $label), Utilities::REMOTE_ADDR());
            // Show secret exactly once.
            $this->template->assign('active_view', 'tokens');
            $this->template->assign('tokens', \Other\feedback_abuse\ORM\Token::query()->orderBy('issued_at', 'desc')->get());
            $this->template->assign('issued_secret', $issued['secret']);
            $this->template->assign('issued_token_id', $issued['token_id']);
            return;
        } catch (\Exception $ex) {
            Engine::addInfo('Token issuance failed: ' . $ex->getMessage());
            Utilities::redirect($this->adminUrl('tokens'));
        }
    }

    /** POST: revoke. */
    public function tokens_revoke($params)
    {
        $this->assertPost();
        $tid = isset($params['id']) ? (string) $params['id'] : '';
        if ($tid === '') {
            Utilities::redirect($this->adminUrl('tokens'));
            return;
        }
        $secret = $this->module->strConfig('embed_hmac_secret');
        $tok = new \Other\feedback_abuse\EmbedToken($this->module->getDatabase(), $secret);
        $ok = $tok->revoke($tid);
        $svc = new \Other\feedback_abuse\ReportService($this->module, $this->module->getDatabase());
        $svc->writeAudit(null, 'admin', $this->adminId(), 'token_revoked', null, $tid,
            array('changed' => $ok), Utilities::REMOTE_ADDR());
        Engine::addInfo($ok ? 'Token revoked' : 'Token was already inactive');
        Utilities::redirect($this->adminUrl('tokens'));
    }

    /** Module configuration form (simple proxy to hostbill core). */
    public function settings($params)
    {
        $this->template->assign('active_view', 'settings');
    }

    /** Stream an attachment to the browser. */
    public function download($params)
    {
        $aid = (int) (isset($params['aid']) ? $params['aid'] : 0);
        $att = $aid > 0
            ? \Other\feedback_abuse\ORM\Attachment::query()->find($aid)
            : null;
        if (!$att) {
            Engine::addInfo('Attachment not found');
            Utilities::redirect($this->adminUrl());
            return;
        }
        $base = $this->module->ensureStoragePath();
        $abs  = $base . '/' . str_replace(array('/', '\\'), '/', $att->storage_path);
        if (!is_file($abs)) {
            Engine::addInfo('File missing on disk');
            Utilities::redirect($this->adminUrl('view&id=' . (int) $att->report_id));
            return;
        }
        // Stream, never include.
        header('Content-Type: ' . ($att->mime_type ?: 'application/octet-stream'));
        header('Content-Disposition: attachment; filename="' . rawurlencode($att->orig_name) . '"');
        header('Content-Length: ' . filesize($abs));
        header('X-Content-Type-Options: nosniff');
        readfile($abs);
        exit;
    }

    /** CSV export of current filter. */
    public function export($params)
    {
        $type   = isset($params['type'])   ? (string) $params['type']   : 'all';
        $status = isset($params['status']) ? (string) $params['status'] : 'all';
        $q      = isset($params['q'])      ? (string) $params['q']      : '';
        $qry = \Other\feedback_abuse\ORM\Report::query()
            ->ofType($type)->ofStatus($status)->search($q)
            ->orderBy('submitted_at', 'desc');
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="feedback_abuse_' . date('Ymd_His') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, array('public_id','type','status','severity','full_name','email','phone','url','subject','message','ip','submitted_at'));
        foreach ($qry->cursor() as $r) {
            fputcsv($out, array(
                $r->public_id, $r->type, $r->status, $r->severity,
                $r->full_name, $r->email, $r->phone, $r->url,
                $r->subject, $r->message, $r->ip, $r->submitted_at,
            ));
        }
        fclose($out);
        exit;
    }

    // -----------------------------------------------------------------
    //  Helpers
    // -----------------------------------------------------------------

    protected function adminId()
    {
        $log = HBConfig::getSetting('admin_login');
        return is_array($log) ? (int) ($log['id'] ?? 0) : 0;
    }

    protected function assertPost()
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            $tok = HBConfig::getSetting('csrf_token') ?: '';
            $sent = $_REQUEST['_token'] ?? '';
            if ($tok === '' || !hash_equals((string) $tok, (string) $sent)) {
                Engine::addInfo('Invalid CSRF token');
                Utilities::redirect($this->adminUrl());
                exit;
            }
        }
    }

    protected function adminUrl($action = '')
    {
        $base = '?cmd=' . $this->module->getModuleDirName();
        return $action === '' ? $base : $base . '&' . $action;
    }

    protected function statusCounts()
    {
        $rows = $this->module->getDatabase()->query(
            'SELECT `status`, COUNT(*) AS c FROM `hb_feedback_abuse_reports` GROUP BY `status`'
        )->fetchAll(PDO::FETCH_ASSOC);
        $out = array();
        foreach ($rows as $r) { $out[$r['status']] = (int) $r['c']; }
        return $out;
    }

    protected function typeCounts()
    {
        $rows = $this->module->getDatabase()->query(
            'SELECT `type`, COUNT(*) AS c FROM `hb_feedback_abuse_reports` GROUP BY `type`'
        )->fetchAll(PDO::FETCH_ASSOC);
        $out = array();
        foreach ($rows as $r) { $out[$r['type']] = (int) $r['c']; }
        return $out;
    }

    /** @return array<int,array{id:int,login:string}> */
    protected function adminList()
    {
        try {
            $rows = $this->module->getDatabase()->query(
                'SELECT `id`, `login` FROM `hb_admins` WHERE `status` = 1 ORDER BY `login`'
            )->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Exception $ex) {
            $rows = array();
        }
        return $rows ?: array(array('id' => 0, 'login' => '—'));
    }

    /**
     * Pick the appropriate language pack from the module's $lang array.
     * Mirrors the engine logic in class.module.php — current language
     * first, then english as fallback.
     */
    protected function pickLang(array $lang)
    {
        $code = 'english';
        try {
            $eng = Engine::singleton();
            if (is_object($eng) && method_exists($eng, 'getLanguage')) {
                $code = (string) $eng->getLanguage();
            }
        } catch (\Exception $ignored) {}
        if (isset($lang[$code]) && is_array($lang[$code])) {
            return $lang[$code];
        }
        if (isset($lang['english']) && is_array($lang['english'])) {
            return $lang['english'];
        }
        // Return first available key.
        foreach ($lang as $k => $v) {
            if (is_array($v)) { return $v; }
        }
        return array();
    }
}

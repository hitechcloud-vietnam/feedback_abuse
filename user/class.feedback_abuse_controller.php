<?php

/**
 * Client-area controller for the Feedback & Abuse module.
 *
 * Even though the *primary* client-area surface is the Widget
 * (per CLAUDE.md §-1 widget rules — NO `user/templates/`), HostBill
 * also needs a controller here so the module appears under
 * "Extras" / "Support" in the client portal menu (controlled by the
 * `$info['haveuser']` / `clients_menu` flags on the module class).
 *
 * Routes:
 *   _default    → show the inline form + a per-client list of past reports
 *   submit      → POST handler that delegates to ReportService
 *   view&id=N   → single report (client's own only)
 *
 * @package  Other\feedback_abuse\user
 */
class feedback_abuse_Controller extends HBController
{
    /** @var feedback_abuse */
    public $module;

    /** @var UserAuthorization */
    public $authorization;

    public function beforeCall($params)
    {
        if (!$this->authorization->get_login_status()) {
            Engine::addInfo('restrictedarea');
            Utilities::redirect('?cmd=root');
            return false;
        }
        $modDir = strtolower($this->module->getModuleDirName());
        $this->template->pageTitle = $this->module->getModName();
        // user/ is allowed for OtherModule per CLAUDE.md §7.29.5.
        $this->template->module_template_dir = APPDIR_MODULES . 'Other' . DS . $modDir . DS . 'user';
        $this->template->assign('moduleurl',
            Utilities::checkSecureURL(HBConfig::getConfig('InstallURL') . 'includes/modules/Other/' . $modDir . '/admin/'));
        $this->template->assign('modulename', $this->module->getModuleName());
        $this->template->assign('modname',    $this->module->getModName());
        $this->template->assign('moduleid',   $this->module->getModuleId());
        $lang = (is_object($this->module) && method_exists($this->module, 'getLang')) ? $this->module->getLang() : array();
        $this->template->assign('lang', $this->pickLang($lang));
        $this->template->assign('enabled_types', $this->module->enabledTypes());
        $this->template->assign('allow_attachments', $this->module->boolConfig('allow_attachments', true));
        $this->template->assign('csrf_token', $this->csrfToken());
        $this->template->assign('submit_url', $this->submitUrl());
        $this->template->assign('allowed_exts', $this->module->allowedExtensions());
        $this->template->assign('max_file_size_mb', (int) $this->module->strConfig('max_file_size_mb', '10'));
        $this->template->showtpl = 'template';
        return true;
    }

    public function _default($params)
    {
        if (!$this->module->boolConfig('client_area_enabled', true)) {
            $this->template->assign('disabled', true);
            return;
        }
        $clientId = (int) $this->authorization->get_id();
        $items = \Other\feedback_abuse\ORM\Report::query()
            ->where('client_id', $clientId)
            ->orderBy('submitted_at', 'desc')
            ->limit(20)
            ->get();
        $this->template->assign('items', $items);
        $this->template->assign('disabled', false);
    }

    public function view($params)
    {
        $id = (int) (isset($params['id']) ? $params['id'] : 0);
        $clientId = (int) $this->authorization->get_id();
        $report = $id > 0
            ? \Other\feedback_abuse\ORM\Report::query()
                ->where('id', $id)
                ->where('client_id', $clientId)
                ->first()
            : null;
        if (!$report) {
            Engine::addInfo('Report not found');
            Utilities::redirect('?cmd=' . strtolower($this->module->getModuleDirName()));
            return;
        }
        $this->template->assign('report', $report);
        $this->template->assign('attachments', $report->attachments()->get());
    }

    public function submit($params)
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            Utilities::redirect('?cmd=' . strtolower($this->module->getModuleDirName()));
            return;
        }
        $tok = $this->module->getDatabase()->prepare('SELECT 1'); // sanity
        unset($tok);
        $clientId = (int) $this->authorization->get_id();
        $client   = HBLoader::LoadModel('Clients')->getClient($clientId);
        $email    = is_array($client) ? ($client['email'] ?? '') : '';
        $name     = is_array($client) ? trim(($client['firstname'] ?? '') . ' ' . ($client['lastname'] ?? '')) : '';

        $input = array(
            'type'      => isset($_POST['type'])    ? (string) $_POST['type']    : '',
            'full_name' => isset($_POST['full_name']) && $_POST['full_name'] !== '' ? (string) $_POST['full_name'] : $name,
            'phone'     => isset($_POST['phone'])   ? (string) $_POST['phone']   : '',
            'email'     => isset($_POST['email'])   && $_POST['email']   !== '' ? (string) $_POST['email']   : $email,
            'url'       => isset($_POST['url'])     ? (string) $_POST['url']     : '',
            'subject'   => isset($_POST['subject']) ? (string) $_POST['subject'] : '',
            'message'   => isset($_POST['message']) ? (string) $_POST['message'] : '',
        );
        $ctx = array(
            'ip'         => Utilities::REMOTE_ADDR(),
            'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? substr((string) $_SERVER['HTTP_USER_AGENT'], 0, 255) : '',
            'referrer'   => isset($_SERVER['HTTP_REFERER'])    ? substr((string) $_SERVER['HTTP_REFERER'], 0, 2048)  : '',
            'source'     => 'client_area',
            'language'   => isset($_POST['language']) ? (string) $_POST['language'] : 'english',
            'client_id'  => $clientId,
        );

        $svc = new \Other\feedback_abuse\ReportService($this->module, $this->module->getDatabase());
        $out = $svc->submit($input, $ctx, $_FILES);

        if (!empty($out['ok'])) {
            Engine::addInfo('msg_submitted');
        } else {
            Engine::addInfo('err_' . ($out['error'] ?? 'submit_failed'));
        }
        Utilities::redirect('?cmd=' . strtolower($this->module->getModuleDirName()));
    }

    protected function csrfToken()
    {
        if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
        if (empty($_SESSION['fb_csrf'])) {
            $_SESSION['fb_csrf'] = bin2hex(random_bytes(24));
        }
        return $_SESSION['fb_csrf'];
    }

    protected function submitUrl()
    {
        $base = Utilities::checkSecureURL(HBConfig::getConfig('InstallURL'));
        return $base . '?cmd=' . strtolower($this->module->getModuleDirName()) . '&action=submit';
    }

    /**
     * Pick the appropriate language pack.  Mirrors class.module.php
     * core logic: current engine language first, english fallback.
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
        foreach ($lang as $v) {
            if (is_array($v)) { return $v; }
        }
        return array();
    }
}

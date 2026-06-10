<?php

/**
 * Client-area embeddable form widget.
 *
 * Per CLAUDE.md §-1 widget rules, the client area is delivered as a
 * Widget class — there is no `user/templates/` directory.
 *
 * Two operating modes:
 *   (a) Hosted on the HostBill client area itself, shown to logged-in
 *       clients.  `?embed=1` is omitted, the template renders the
 *       full form with iframe-resize auto-sizing.
 *   (b) Embedded on a 3rd-party site.  The 3rd-party loads the form
 *       inside an `<iframe src="...?embed=1&token=…&origin=…">` and
 *       the form posts cross-origin back to the JSON API.
 *
 * CSRF: the form carries HostBill's standard CSRF token.  For (b) the
 * 3rd-party site must pre-fetch a one-time `_token` value from this
 * HostBill instance (the token endpoint validates the embed HMAC).
 *
 * @package  Other\feedback_abuse\widgets
 */
class Widget_feedback_form extends ServicesWidget
{
    /** @var string */
    public $widgetfullname = 'Submit Feedback / Report Abuse';

    /** @var string */
    public $description = 'Public form for general feedback, phishing, malware, botnet, spam, and domain-abuse reports. Embeddable on 3rd-party sites.';

    /** Visible by default, configurable, multiple-instance. */
    public $options = 2;

    /**
     * Always available (we want it on every page, including logout).
     * The `doesApply()` check below still enforces module enable flag.
     */
    public function doesApply()
    {
        $module = HBLoader::LoadModule('Other/feedback_abuse');
        if (!$module || !$module->boolConfig('enabled', true)) {
            return false;
        }
        return true;
    }

    /**
     * Render the form inline (or the iframe source if `?embed=1`).
     */
    public function controller()
    {
        $module = HBLoader::LoadModule('Other/feedback_abuse');
        $lang   = $module->getLang();

        // Detect visitor language preference.
        $langCode = $this->detectLanguage();
        $strings  = isset($lang[$langCode]) ? $lang[$langCode] : $lang['english'];

        $isEmbed  = (isset($_GET['embed']) && $_GET['embed'] == '1');
        $token    = isset($_GET['token']) ? (string) $_GET['token'] : '';
        $origin   = isset($_GET['origin']) ? (string) $_GET['origin'] : '';

        $this->view->assign('lang',          $strings);
        $this->view->assign('lang_code',     $langCode);
        $this->view->assign('is_embed',      $isEmbed);
        $this->view->assign('embed_token',   $token);
        $this->view->assign('embed_origin',  $origin);
        $this->view->assign('enabled_types', $module->enabledTypes());
        $this->view->assign('allow_attachments', $module->boolConfig('allow_attachments', true));
        $this->view->assign('require_captcha',   $module->boolConfig('require_captcha', false));
        $this->view->assign('csrf_token',     $this->csrfToken());
        $this->view->assign('form_action',   $this->formAction($isEmbed, $token, $origin));
        $this->view->assign('submit_url',    $this->submitUrl($isEmbed));
        $this->view->assign('allowed_exts',  $module->allowedExtensions());
        $this->view->assign('max_file_size_mb', (int) $module->strConfig('max_file_size_mb', '10'));

        // Pick template variant.
        $tpl = $isEmbed ? 'widget_feedback_form_embed' : 'widget_feedback_form_inline';
        $this->view->setTpl($tpl);
    }

    /**
     * Detect best language from Accept-Language.
     */
    protected function detectLanguage()
    {
        $al = isset($_SERVER['HTTP_ACCEPT_LANGUAGE']) ? (string) $_SERVER['HTTP_ACCEPT_LANGUAGE'] : '';
        if ($al !== '' && stripos($al, 'vi') !== false) {
            return 'vietnamese';
        }
        $module = HBLoader::LoadModule('Other/feedback_abuse');
        $default = (string) $module->strConfig('language_default', 'english');
        return in_array($default, array('english', 'vietnamese'), true) ? $default : 'english';
    }

    /**
     * Issue a CSRF token (one per session; rotates on form submit).
     */
    protected function csrfToken()
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        if (empty($_SESSION['fb_csrf'])) {
            $_SESSION['fb_csrf'] = bin2hex(random_bytes(24));
        }
        return $_SESSION['fb_csrf'];
    }

    /**
     * The action URL the <form> posts to.  When embedded, the form
     * posts to a relative path so the 3rd-party iframe stays in the
     * same origin; the server-side controller then proxies / 302s the
     * browser to the JSON endpoint.
     */
    protected function formAction($isEmbed, $token, $origin)
    {
        $base = Utilities::checkSecureURL(HBConfig::getConfig('InstallURL'));
        return $base . '?cmd=feedback_abuse_submit'
            . ($isEmbed ? '&embed=1' : '')
            . ($token  !== '' ? '&token=' . rawurlencode($token) : '')
            . ($origin !== '' ? '&origin=' . rawurlencode($origin) : '');
    }

    /**
     * The URL the JS submitter POSTs JSON to.
     */
    protected function submitUrl($isEmbed)
    {
        $base = Utilities::checkSecureURL(HBConfig::getConfig('InstallURL'));
        return $base . 'api/feedback_abuse/submit';
    }
}

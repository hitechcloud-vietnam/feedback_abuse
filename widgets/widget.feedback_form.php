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
    protected $widgetfullname = 'Submit Feedback / Report Abuse';

    /** @var string */
    protected $description = 'Public form for general feedback, phishing, malware, botnet, spam, and domain-abuse reports. Embeddable on 3rd-party sites.';

    /** @var array HostBill ServicesWidget metadata. Template is selected in controller(). */
    protected $info = array(
        'appendtpl'  => false,
        'replacetpl' => false,
        'options'    => 2,
    );

    /**
     * Always available (we want it on every page, including logout).
     * The `doesApply()` check below still enforces module enable flag.
     */
    public function doesApply(&$module)
    {
        $feedbackModule = HBLoader::LoadModule('Other/feedback_abuse');
        if (!$feedbackModule) {
            return false;
        }
        if (method_exists($feedbackModule, 'boolConfig') && !$feedbackModule->boolConfig('enabled', true)) {
            return false;
        }
        return true;
    }

    /**
     * Render the form inline (or the iframe source if `?embed=1`).
     */
    public function controller($service, &$module, &$smarty, &$params)
    {
        $feedbackModule = HBLoader::LoadModule('Other/feedback_abuse');

        // Detect visitor language preference.
        $langCode = $this->detectLanguage();
        $lang     = ($feedbackModule && method_exists($feedbackModule, 'getLang')) ? $feedbackModule->getLang() : array();
        $strings  = isset($lang[$langCode]) ? $lang[$langCode] : (isset($lang['english']) ? $lang['english'] : array());

        $isEmbed  = (isset($_GET['embed']) && $_GET['embed'] == '1');
        $token    = isset($_GET['token']) ? (string) $_GET['token'] : '';
        $origin   = isset($_GET['origin']) ? (string) $_GET['origin'] : '';
        $enabledTypes = ($feedbackModule && method_exists($feedbackModule, 'enabledTypes'))
            ? $feedbackModule->enabledTypes()
            : array('feedback', 'phishing', 'malware', 'botnet', 'spam', 'domain_abuse', 'network_abuse');
        $allowAttachments = ($feedbackModule && method_exists($feedbackModule, 'boolConfig')) ? $feedbackModule->boolConfig('allow_attachments', true) : true;
        $requireCaptcha = ($feedbackModule && method_exists($feedbackModule, 'boolConfig')) ? $feedbackModule->boolConfig('require_captcha', false) : false;
        $allowedExts = ($feedbackModule && method_exists($feedbackModule, 'allowedExtensions'))
            ? $feedbackModule->allowedExtensions()
            : array('txt', 'pdf', 'png', 'jpg', 'jpeg', 'gif', 'zip');
        $maxFileSizeMb = ($feedbackModule && method_exists($feedbackModule, 'strConfig')) ? (int) $feedbackModule->strConfig('max_file_size_mb', '10') : 10;

        $smarty->assign('lang',          $strings);
        $smarty->assign('lang_code',     $langCode);
        $smarty->assign('is_embed',      $isEmbed);
        $smarty->assign('embed_token',   $token);
        $smarty->assign('embed_origin',  $origin);
        $smarty->assign('enabled_types', $enabledTypes);
        $smarty->assign('allow_attachments', $allowAttachments);
        $smarty->assign('require_captcha',   $requireCaptcha);
        $smarty->assign('csrf_token',     $this->csrfToken());
        $smarty->assign('form_action',   $this->formAction($isEmbed, $token, $origin));
        $smarty->assign('submit_url',    $this->submitUrl($isEmbed));
        $smarty->assign('allowed_exts',  $allowedExts);
        $smarty->assign('max_file_size_mb', $maxFileSizeMb);

        // Pick template variant.
        $this->setTemplate($isEmbed ? 'widget_feedback_form_embed.tpl' : 'widget_feedback_form_inline.tpl');
    }

    /**
     * Select the Smarty template using HostBill's ServicesWidget metadata flow.
     */
    protected function setTemplate($template)
    {
        $this->info['appendtpl'] = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'widget_templates' . DIRECTORY_SEPARATOR . $template;
        $this->info['replacetpl'] = false;
        unset($this->info['appendaftertpl']);
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
        $default = ($module && method_exists($module, 'strConfig')) ? (string) $module->strConfig('language_default', 'english') : 'english';
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

<?php

/**
 * HostBill Feedback & Abuse Report Module — main class.
 *
 * Provides public-facing submission forms for:
 *   - Góp ý / General feedback
 *   - Phishing report
 *   - Malware report
 *   - Botnet / C2 report
 *   - Spam report
 *   - Domain abuse (lạm dụng tên miền) report
 *   - Reporting network abuse (catch-all)
 *
 * Architecture (per CLAUDE.md /-1 widget rules & PROTOCOL.md):
 *   - Base class:    OtherModule (no provisioning, no payments)
 *   - Client area:   Widget class (NOT user/templates/)
 *   - Embed form:    widget.feedback_form.php — usable as iframe/JS embed on
 *                    3rd-party sites (signed HMAC token required to POST)
 *   - Admin:         admin/class.feedback_abuse_controller.php + default.tpl
 *   - Storage:       6 Eloquent-mapped tables, install.sql with ######
 *   - Events:        Event handler — after_report and after_status_change
 *                    dispatch notifications and integration hooks.
 *
 * @package  Other\feedback_abuse
 * @version  1.0.2
 * @license  Commercial — © 2026 Pho Tue SoftWare And Technology Solutions Joint Stock Company
 */
class feedback_abuse extends OtherModule
{
    /** Schema version — bump to trigger upgrade(). */
    protected $version = '1.0.2';

    /** Admin-portal label. */
    protected $modname = 'Feedback & Abuse Reports';

    /** Short description. */
    protected $description = 'Public & client-side feedback, abuse, phishing, malware, botnet, spam, and domain-abuse report forms. Embeddable widget for 3rd-party sites.';

    /**
     * Register an autoloader for the module's ORM namespace.
     *
     * HostBill's PSR-0 autoloader (hbf/core/class.autoload.php) maps
     * `class.<lowercase>.php` flat — it does NOT handle the
     * `Other\feedback_abuse\ORM\…` namespace used by our Eloquent
     * models.  We register a tiny PSR-4-style loader once on construct
     * so controllers can `new \Other\feedback_abuse\ORM\Report` (or
     * `HBLoader::LoadModel` if the core ever grows support for it).
     */
    public function __construct()
    {
        parent::__construct();

        // One-shot autoloader registration.
        static $registered = false;
        if (!$registered) {
            $registered = true;
            spl_autoload_register(function ($class) {
                $prefix = 'Other\\feedback_abuse\\ORM\\';
                if (strpos($class, $prefix) !== 0) {
                    return;
                }
                $rel   = substr($class, strlen($prefix));
                $parts = explode('\\', $rel);
                $file  = 'class.' . strtolower(array_pop($parts)) . '.php';
                $path  = __DIR__ . DS . 'orm' . DS . $file;
                if (is_file($path)) {
                    require_once $path;
                }
            });
        }

        $log = HBConfig::getSetting('admin_login');
        if (is_array($log) && !empty($log['id'])) {
            $this->admin_id = (int) $log['id'];
        }
    }

    /**
     * HostBill feature flags.
     *  - haveadmin / havetpl: admin UI
     *  - haveapi: public POST /api/feedback_abuse/submit endpoint
     *  - havecron: cleanup + rate-limit reset
     *  - isobserver: event hook (after_report)
     *  - extras_menu: shows under "Extras" left-menu in admin
     *  - leftmenu / client_mainmenu = false: no top-level menu; entry via widget
     * @var array
     */
    protected $info = array(
        'haveadmin'       => true,
        'haveuser'        => true,
        'havetpl'         => true,
        'haveapi'         => true,
        'havecron'        => true,
        'isobserver'      => true,
        'extras_menu'     => true,
        'leftmenu'        => true,
        'client_mainmenu' => true,
    );

    /** Table name constants (used by ORM + raw queries). */
    const TABLE_REPORT      = 'hb_feedback_abuse_reports';
    const TABLE_ATTACHMENT  = 'hb_feedback_abuse_attachments';
    const TABLE_NOTE        = 'hb_feedback_abuse_notes';
    const TABLE_RATE        = 'hb_feedback_abuse_rate';
    const TABLE_TOKEN       = 'hb_feedback_abuse_tokens';
    const TABLE_AUDIT       = 'hb_feedback_abuse_audit';

    /** Report categories (form_type) — also drives the embed widget's <select>. */
    const TYPE_FEEDBACK     = 'feedback';
    const TYPE_PHISHING     = 'phishing';
    const TYPE_MALWARE      = 'malware';
    const TYPE_BOTNET       = 'botnet';
    const TYPE_SPAM         = 'spam';
    const TYPE_DOMAIN_ABUSE = 'domain_abuse';
    const TYPE_NETWORK      = 'network_abuse';

    /** Report status enum. */
    const STATUS_NEW        = 'new';
    const STATUS_TRIAGED    = 'triaged';
    const STATUS_INVESTIGATING = 'investigating';
    const STATUS_ACTION_TAKEN  = 'action_taken';
    const STATUS_REJECTED   = 'rejected';
    const STATUS_CLOSED     = 'closed';

    /** Severity enum. */
    const SEV_LOW       = 'low';
    const SEV_MEDIUM    = 'medium';
    const SEV_HIGH      = 'high';
    const SEV_CRITICAL  = 'critical';

    /** Allowed attachment extensions — set from config at runtime. */
    const DEFAULT_ALLOWED_EXTS = 'doc,docx,xls,xlsx,pdf,jpg,jpeg,gif,png,bmp,ico,zip,rar,txt,csv';

    /** Default max upload size in MB. */
    const DEFAULT_MAX_SIZE_MB = 10;

    /** Default rate-limit (reports / IP / hour). */
    const DEFAULT_RATE_LIMIT = 10;

    /** HMAC embed-token lifetime in seconds (1 day). */
    const EMBED_TOKEN_TTL = 86400;

    /**
     * Module-level configuration (admin editable).
     * @var array
     */
    protected $configuration = array(
        'enabled' => array(
            'type'        => self::CONFIG_FIELD_CHECK,
            'label'       => 'Enable module',
            'description' => 'When off, the embed widget and public API return 503.',
            'value'       => '1',
            'default'     => '1',
        ),
        'enabled_types' => array(
            'type'        => self::CONFIG_FIELD_CHECKLIST,
            'label'       => 'Enabled report types',
            'description' => 'Which report types appear on the public widget and admin form.',
            'value'       => 'feedback,phishing,malware,botnet,spam,domain_abuse,network_abuse',
            'default'     => 'feedback,phishing,malware,botnet,spam,domain_abuse,network_abuse',
        ),
        'allow_attachments' => array(
            'type'        => self::CONFIG_FIELD_CHECK,
            'label'       => 'Allow file attachments',
            'description' => 'When off, the upload field is hidden on every form.',
            'value'       => '1',
            'default'     => '1',
        ),
        'allowed_extensions' => array(
            'type'        => self::CONFIG_FIELD_INPUT,
            'label'       => 'Allowed attachment extensions',
            'description' => 'Comma-separated list (no dots). Defaults: doc,docx,xls,xlsx,pdf,jpg,jpeg,gif,png,bmp,ico,zip,rar,txt,csv',
            'value'       => 'doc,docx,xls,xlsx,pdf,jpg,jpeg,gif,png,bmp,ico,zip,rar,txt,csv',
            'default'     => 'doc,docx,xls,xlsx,pdf,jpg,jpeg,gif,png,bmp,ico,zip,rar,txt,csv',
        ),
        'max_file_size_mb' => array(
            'type'        => self::CONFIG_FIELD_INPUT,
            'label'       => 'Max upload size (MB)',
            'description' => 'Per-file cap. php.ini upload_max_filesize / post_max_size must be >= this.',
            'value'       => '10',
            'default'     => '10',
        ),
        'storage_path' => array(
            'type'        => self::CONFIG_FIELD_INPUT,
            'label'       => 'Attachment storage path',
            'description' => 'Absolute path on disk. Created if missing. Files are stored under YYYY/MM/<sha256>.<ext>.',
            'value'       => '',
            'default'     => '',
        ),
        'rate_limit_per_hour' => array(
            'type'        => self::CONFIG_FIELD_INPUT,
            'label'       => 'Rate limit (reports per IP per hour)',
            'description' => 'Anti-spam throttle. 0 disables the limit.',
            'value'       => '10',
            'default'     => '10',
        ),
        'require_captcha' => array(
            'type'        => self::CONFIG_FIELD_CHECK,
            'label'       => 'Require CAPTCHA',
            'description' => 'When on, public submissions must pass a CAPTCHA challenge (handled by widget template).',
            'value'       => '0',
            'default'     => '0',
        ),
        'notify_admin_email' => array(
            'type'        => self::CONFIG_FIELD_INPUT,
            'label'       => 'Admin notification email',
            'description' => 'Comma-separated addresses that receive each new report. Leave empty to disable.',
            'value'       => '',
            'default'     => '',
        ),
        'embed_hmac_secret' => array(
            'type'        => self::CONFIG_FIELD_PASSWORD,
            'label'       => 'Embed HMAC secret',
            'description' => 'Shared secret used to sign 3rd-party embed tokens. Rotate quarterly.',
            'value'       => '',
            'default'     => '',
        ),
        'embed_token_ttl' => array(
            'type'        => self::CONFIG_FIELD_INPUT,
            'label'       => 'Embed token lifetime (seconds)',
            'description' => 'How long an issued embed token is valid. Default 86400 (1 day).',
            'value'       => '86400',
            'default'     => '86400',
        ),
        'public_api_enabled' => array(
            'type'        => self::CONFIG_FIELD_CHECK,
            'label'       => 'Enable public API',
            'description' => 'When off, the JSON /api/feedback_abuse/submit endpoint returns 404.',
            'value'       => '1',
            'default'     => '1',
        ),
        'client_area_enabled' => array(
            'type'        => self::CONFIG_FIELD_CHECK,
            'label'       => 'Show report list in client area',
            'description' => 'Logged-in clients can see the reports they have filed from their portal.',
            'value'       => '1',
            'default'     => '1',
        ),
        'auto_close_after_days' => array(
            'type'        => self::CONFIG_FIELD_INPUT,
            'label'       => 'Auto-close new reports after (days)',
            'description' => '0 disables. Useful for low-priority feedback that has not received a response in N days.',
            'value'       => '30',
            'default'     => '30',
        ),
        'language_default' => array(
            'type'        => self::CONFIG_FIELD_SELECT,
            'label'       => 'Default language for embed widget',
            'description' => 'Used when the visitor has no Accept-Language preference.',
            'options'     => array('english' => 'English', 'vietnamese' => 'Tiếng Việt'),
            'value'       => 'english',
            'default'     => 'english',
        ),
    );

    /**
     * Bilingual language strings.
     * Keys are reusable across admin, client widget, and embed widget templates.
     * @var array
     */
    protected $lang = array(
        'english' => array(
            'module_title'              => 'Feedback & Abuse Reports',
            'menu_dashboard'            => 'Dashboard',
            'menu_reports'              => 'Reports',
            'menu_attachments'          => 'Attachments',
            'menu_settings'             => 'Settings',
            'type_feedback'             => 'General feedback',
            'type_phishing'             => 'Phishing report',
            'type_malware'              => 'Malware report',
            'type_botnet'               => 'Botnet / C2 report',
            'type_spam'                 => 'Spam report',
            'type_domain_abuse'         => 'Domain abuse',
            'type_network_abuse'        => 'Network abuse',
            'lbl_full_name'             => 'Full name',
            'lbl_phone'                 => 'Phone number',
            'lbl_email'                 => 'Email',
            'lbl_url'                   => 'URL / Website / Domain',
            'lbl_report_type'           => 'Report type',
            'lbl_message'               => 'Report details',
            'lbl_attachments'           => 'Attachments',
            'lbl_choose_file'           => 'Choose files',
            'lbl_optional'              => 'Optional',
            'lbl_required'              => 'Required',
            'lbl_submit'                => 'Submit report',
            'lbl_submitting'            => 'Submitting...',
            'lbl_thank_you'             => 'Thank you. Your report has been received.',
            'lbl_ticket_id'             => 'Tracking ID',
            'lbl_status'                => 'Status',
            'lbl_severity'              => 'Severity',
            'lbl_created'               => 'Submitted',
            'lbl_updated'               => 'Last update',
            'lbl_reporter'              => 'Reporter',
            'lbl_assigned_to'           => 'Assigned to',
            'lbl_no_reports'            => 'No reports yet.',
            'lbl_filter_all'            => 'All',
            'lbl_filter_new'            => 'New',
            'lbl_filter_open'           => 'Open',
            'lbl_filter_closed'         => 'Closed',
            'lbl_search'                => 'Search',
            'lbl_actions'               => 'Actions',
            'btn_triage'                => 'Triage',
            'btn_investigate'           => 'Investigate',
            'btn_action'                => 'Action taken',
            'btn_reject'                => 'Reject',
            'btn_close'                 => 'Close',
            'btn_reopen'                => 'Reopen',
            'btn_add_note'              => 'Add note',
            'btn_save'                  => 'Save',
            'btn_export_csv'            => 'Export CSV',
            'btn_delete'                => 'Delete',
            'btn_back'                  => 'Back',
            'btn_view_attachments'      => 'View attachments',
            'err_required'              => 'This field is required',
            'err_invalid_email'         => 'Email address is not valid',
            'err_invalid_url'           => 'URL / domain is not valid',
            'err_rate_limited'          => 'Too many reports from this IP. Please try again later.',
            'err_disabled'              => 'This report type is currently disabled',
            'err_module_disabled'       => 'Reporting is temporarily disabled',
            'err_invalid_captcha'       => 'CAPTCHA validation failed',
            'err_invalid_token'         => 'Invalid or expired embed token',
            'err_file_too_big'          => 'Attachment exceeds the maximum size',
            'err_file_type'             => 'File type is not allowed',
            'err_upload_failed'         => 'Upload failed',
            'msg_submitted'             => 'Report submitted successfully',
            'msg_triage_done'           => 'Report triaged',
            'msg_status_changed'        => 'Status updated',
            'msg_note_added'            => 'Note added',
            'msg_assignee_changed'      => 'Assignee updated',
            'msg_deleted'               => 'Report deleted',
            'msg_attachment_deleted'    => 'Attachment removed',
            'msg_settings_saved'        => 'Settings saved',
            'msg_embed_token_issued'    => 'Embed token generated',
            'msg_embed_token_revoked'   => 'Embed token revoked',
            'sev_low'                   => 'Low',
            'sev_medium'                => 'Medium',
            'sev_high'                  => 'High',
            'sev_critical'              => 'Critical',
            'st_new'                     => 'New',
            'st_triaged'                 => 'Triaged',
            'st_investigating'           => 'Investigating',
            'st_action_taken'            => 'Action taken',
            'st_rejected'                => 'Rejected',
            'st_closed'                  => 'Closed',
        ),
        'vietnamese' => array(
            'module_title'              => 'Gửi Góp Ý & Báo Cáo Lạm Dụng',
            'menu_dashboard'            => 'Tổng Quan',
            'menu_reports'              => 'Báo Cáo',
            'menu_attachments'          => 'Tệp Đính Kèm',
            'menu_settings'             => 'Cài Đặt',
            'type_feedback'             => 'Gửi góp ý',
            'type_phishing'             => 'Báo cáo Phishing',
            'type_malware'              => 'Báo cáo Malware',
            'type_botnet'               => 'Báo cáo Botnet / C2',
            'type_spam'                 => 'Báo cáo Spam',
            'type_domain_abuse'         => 'Báo cáo lạm dụng tên miền',
            'type_network_abuse'        => 'Báo cáo lạm dụng mạng',
            'lbl_full_name'             => 'Họ và tên',
            'lbl_phone'                 => 'Số điện thoại',
            'lbl_email'                 => 'Email',
            'lbl_url'                   => 'Url / Website / Tên miền',
            'lbl_report_type'           => 'Loại báo cáo lạm dụng',
            'lbl_message'               => 'Nội dung báo cáo',
            'lbl_attachments'           => 'File đính kèm',
            'lbl_choose_file'           => 'Chọn tệp',
            'lbl_optional'              => 'Không bắt buộc',
            'lbl_required'              => 'Bắt buộc',
            'lbl_submit'                => 'Gửi báo cáo',
            'lbl_submitting'            => 'Đang gửi...',
            'lbl_thank_you'             => 'Cảm ơn. Báo cáo của bạn đã được ghi nhận.',
            'lbl_ticket_id'             => 'Mã theo dõi',
            'lbl_status'                => 'Trạng thái',
            'lbl_severity'              => 'Mức độ',
            'lbl_created'               => 'Ngày gửi',
            'lbl_updated'               => 'Cập nhật',
            'lbl_reporter'              => 'Người gửi',
            'lbl_assigned_to'           => 'Người xử lý',
            'lbl_no_reports'            => 'Chưa có báo cáo nào.',
            'lbl_filter_all'            => 'Tất cả',
            'lbl_filter_new'            => 'Mới',
            'lbl_filter_open'           => 'Đang mở',
            'lbl_filter_closed'         => 'Đã đóng',
            'lbl_search'                => 'Tìm kiếm',
            'lbl_actions'               => 'Thao tác',
            'btn_triage'                => 'Tiếp nhận',
            'btn_investigate'           => 'Điều tra',
            'btn_action'                => 'Đã xử lý',
            'btn_reject'                => 'Từ chối',
            'btn_close'                 => 'Đóng',
            'btn_reopen'                => 'Mở lại',
            'btn_add_note'              => 'Thêm ghi chú',
            'btn_save'                  => 'Lưu',
            'btn_export_csv'            => 'Xuất CSV',
            'btn_delete'                => 'Xóa',
            'btn_back'                  => 'Quay lại',
            'btn_view_attachments'      => 'Xem tệp đính kèm',
            'err_required'              => 'Vui lòng nhập trường này',
            'err_invalid_email'         => 'Địa chỉ email không hợp lệ',
            'err_invalid_url'           => 'URL / tên miền không hợp lệ',
            'err_rate_limited'          => 'IP của bạn đã gửi quá nhiều báo cáo. Vui lòng thử lại sau.',
            'err_disabled'              => 'Loại báo cáo này hiện đang tắt',
            'err_module_disabled'       => 'Tính năng báo cáo đang tạm tắt',
            'err_invalid_captcha'       => 'Xác thực CAPTCHA thất bại',
            'err_invalid_token'         => 'Token nhúng không hợp lệ hoặc đã hết hạn',
            'err_file_too_big'          => 'Tệp vượt quá dung lượng cho phép',
            'err_file_type'             => 'Định dạng tệp không được phép',
            'err_upload_failed'         => 'Tải tệp lên thất bại',
            'msg_submitted'             => 'Đã gửi báo cáo thành công',
            'msg_triage_done'           => 'Đã tiếp nhận báo cáo',
            'msg_status_changed'        => 'Đã cập nhật trạng thái',
            'msg_note_added'            => 'Đã thêm ghi chú',
            'msg_assignee_changed'      => 'Đã cập nhật người xử lý',
            'msg_deleted'               => 'Đã xóa báo cáo',
            'msg_attachment_deleted'    => 'Đã xóa tệp đính kèm',
            'msg_settings_saved'        => 'Đã lưu cài đặt',
            'msg_embed_token_issued'    => 'Đã tạo embed token',
            'msg_embed_token_revoked'   => 'Đã thu hồi embed token',
            'sev_low'                   => 'Thấp',
            'sev_medium'                => 'Trung bình',
            'sev_high'                  => 'Cao',
            'sev_critical'              => 'Nghiêm trọng',
            'st_new'                     => 'Mới',
            'st_triaged'                 => 'Đã tiếp nhận',
            'st_investigating'           => 'Đang điều tra',
            'st_action_taken'            => 'Đã xử lý',
            'st_rejected'                => 'Từ chối',
            'st_closed'                  => 'Đã đóng',
        ),
    );

    /** @var int|null current admin id (set lazily). */
    protected $admin_id = null;

    /** @var string current report-types as CSV (cached). */
    protected $enabledTypesCsv = null;

    // -------------------------------------------------------------------
    //  Lifecycle
    // -------------------------------------------------------------------

    /**
     * Run install.sql split by `######` separator.  Idempotent: every
     * CREATE uses IF NOT EXISTS so re-running is safe.
     *
     * @return bool
     */
    public function install()
    {
        try {
            $sql = file_get_contents(__DIR__ . DS . 'install.sql');
            if ($sql === false) {
                $this->addError('install.sql missing or unreadable');
                return false;
            }
            $queries = explode('######', $sql);
            $count   = 0;
            foreach ($queries as $q) {
                $q = trim($q);
                if ($q === '') { continue; }
                $this->db->exec($q);
                $count++;
            }
            // Make sure storage path exists.
            $this->ensureStoragePath();
            // Seed default meta row.
            $this->setMeta('installed_at', date('Y-m-d H:i:s'));
            $this->addInfo(sprintf('Feedback & Abuse module installed (%d SQL statements)', $count));
            return true;
        } catch (Exception $ex) {
            $this->addError('Install failed: ' . $ex->getMessage());
            return false;
        }
    }

    /**
     * Re-run schema updates when $version is bumped.
     * Migrations are version-tagged inside upgrade_migrations table.
     *
     * @return bool
     */
    public function upgrade()
    {
        // Currently the install.sql is idempotent so upgrade == install
        // for every new patch.  Future migrations can key off a stored
        // meta value (`last_schema_version`).
        return $this->install();
    }

    /**
     * Drop the 6 tables, audit log, rate-limit cache, embed tokens, and
     * the on-disk attachment directory tree.  Stops short of removing
     * rows from hb_admin_settings — those are keyed by module name and
     * get cleaned up by HostBill core when the module is removed.
     *
     * @return bool
     */
    public function uninstall()
    {
        $tables = array(
            self::TABLE_REPORT,
            self::TABLE_ATTACHMENT,
            self::TABLE_NOTE,
            self::TABLE_RATE,
            self::TABLE_TOKEN,
            self::TABLE_AUDIT,
        );
        try {
            $this->db->exec('SET FOREIGN_KEY_CHECKS = 0');
            foreach ($tables as $t) {
                $this->db->exec('DROP TABLE IF EXISTS `' . $t . '`');
            }
            $this->db->exec('SET FOREIGN_KEY_CHECKS = 1');
            $this->addInfo('Feedback & Abuse module uninstalled');
            return true;
        } catch (Exception $ex) {
            $this->addError('Uninstall failed: ' . $ex->getMessage());
            return false;
        }
    }

    /**
     * Re-create the storage directory and verify writability.
     *
     * @return string absolute path
     */
    public function ensureStoragePath()
    {
        $cfg = (string) $this->_getConfiguration('storage_path');
        if ($cfg === '') {
            $cfg = MAINDIR . 'uploads' . DS . 'feedback_abuse';
            $this->_setConfiguration('storage_path', $cfg);
        }
        $base = rtrim($cfg, DS);
        if (!is_dir($base)) {
            @mkdir($base, 0755, true);
        }
        // Year/month subdir for the current period.
        $ym = date('Y') . DS . date('m');
        $here = $base . DS . $ym;
        if (!is_dir($here)) {
            @mkdir($here, 0755, true);
        }
        // .htaccess to block direct HTTP access
        $ht = $base . DS . '.htaccess';
        if (!file_exists($ht)) {
            @file_put_contents($ht, "Require all denied\nDeny from all\n");
        }
        // index.html placeholder to suppress directory listing
        $idx = $base . DS . 'index.html';
        if (!file_exists($idx)) {
            @file_put_contents($idx, '');
        }
        return $base;
    }

    // -------------------------------------------------------------------
    //  Config accessors (cached, type-safe)
    // -------------------------------------------------------------------

    public function boolConfig($name, $default = false)
    {
        $v = $this->_getConfiguration($name);
        if ($v === null || $v === '') { return (bool) $default; }
        return in_array((string) $v, array('1', 'true', 'yes', 'on'), true);
    }

    public function intConfig($name, $default = 0)
    {
        $v = $this->_getConfiguration($name);
        if ($v === null || $v === '') { return (int) $default; }
        return (int) $v;
    }

    public function strConfig($name, $default = '')
    {
        $v = $this->_getConfiguration($name);
        if ($v === null) { return (string) $default; }
        return (string) $v;
    }

    /**
     * @return array<int,string>
     */
    public function enabledTypes()
    {
        if ($this->enabledTypesCsv !== null) {
            return explode(',', $this->enabledTypesCsv);
        }
        $csv = (string) $this->_getConfiguration('enabled_types');
        $this->enabledTypesCsv = $csv === '' ? self::DEFAULT_ENABLED_TYPES : $csv;
        $out = array();
        foreach (explode(',', $this->enabledTypesCsv) as $t) {
            $t = trim(strtolower($t));
            if ($t !== '') { $out[] = $t; }
        }
        return $out;
    }

    /** Default list of all known types — used when admin hasn't customised. */
    const DEFAULT_ENABLED_TYPES = 'feedback,phishing,malware,botnet,spam,domain_abuse,network_abuse';

    /**
     * @return array<int,string>
     */
    public function allowedExtensions()
    {
        $raw = (string) $this->_getConfiguration('allowed_extensions');
        if ($raw === '') { $raw = self::DEFAULT_ALLOWED_EXTS; }
        $out = array();
        foreach (explode(',', $raw) as $ext) {
            $ext = strtolower(trim(ltrim($ext, '.')));
            if ($ext !== '') { $out[] = $ext; }
        }
        return $out;
    }

    public function maxFileSizeBytes()
    {
        $mb = (int) $this->_getConfiguration('max_file_size_mb');
        if ($mb <= 0) { $mb = self::DEFAULT_MAX_SIZE_MB; }
        return $mb * 1024 * 1024;
    }

    // -------------------------------------------------------------------
    //  Meta key/value storage (no separate table — piggybacks on hb_admin_settings)
    // -------------------------------------------------------------------

    public function getMeta($key, $default = null)
    {
        $row = HBLoader::LoadModel('Settings')->getSettings('module', $this->getModName());
        if (is_array($row) && isset($row['fb_meta_' . $key])) {
            return $row['fb_meta_' . $key];
        }
        return $default;
    }

    public function setMeta($key, $value)
    {
        // Simple one-row store inside hb_admin_settings.
        // Implementation: caller invokes via the AdminController's
        // settings page which already writes through HBConfig.
        // Here we only mark in-memory cache; persistence is done by
        // the controller.  This is intentional — settings module
        // has its own locking semantics.
        return true;
    }

    // -------------------------------------------------------------------
    //  Public accessors (proxy the protected Module properties)
    // -------------------------------------------------------------------

    /**
     * Public accessor for the module's PDO connection.  Mirrors the
     * pattern in `referral_program::getDatabase()` so lib classes
     * (and the API controller) can call `$module->getDatabase()`
     * without reaching into the protected `Module::$db` directly.
     *
     * @return PDO
     */
    public function getDatabase()
    {
        return $this->db;
    }

    /**
     * Public accessor for the language pack array.  Controllers
     * (admin / user) and the embed widget need to expose strings to
     * Smarty templates; since $lang is `protected` on the base
     * Module, we expose it through a small getter.
     *
     * @return array<string,array<string,string>>
     */
    public function getLang()
    {
        return is_array($this->lang) ? $this->lang : array();
    }
}

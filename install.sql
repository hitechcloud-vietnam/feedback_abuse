-- HostBill Feedback & Abuse Module — install.sql
-- Schema v1.0.0
-- All 6 tables prefixed with `hb_` per HostBill convention.
-- Statements are separated by the literal token `######` (HostBill rule).
-- Eloquent-compatible: every row maps 1:1 onto a Model in orm/.

######
-- 1. Reports — one row per submission (feedback / phishing / malware / botnet / spam / domain_abuse / network_abuse).
CREATE TABLE IF NOT EXISTS `hb_feedback_abuse_reports` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `public_id`       CHAR(24)        NOT NULL                   COMMENT 'URL-safe tracking ID surfaced to the reporter',
  `type`            VARCHAR(32)     NOT NULL                   COMMENT 'feedback|phishing|malware|botnet|spam|domain_abuse|network_abuse',
  `status`          VARCHAR(24)     NOT NULL DEFAULT 'new'     COMMENT 'new|triaged|investigating|action_taken|rejected|closed',
  `severity`        VARCHAR(16)     NOT NULL DEFAULT 'medium'  COMMENT 'low|medium|high|critical',
  `full_name`       VARCHAR(128)    NOT NULL,
  `phone`           VARCHAR(40)     DEFAULT NULL,
  `email`           VARCHAR(190)    NOT NULL,
  `url`             VARCHAR(2048)   DEFAULT NULL,
  `subject`         VARCHAR(255)    DEFAULT NULL,
  `message`         MEDIUMTEXT      NOT NULL,
  `source`          VARCHAR(32)     NOT NULL DEFAULT 'web'     COMMENT 'web|embed|api|client_area|email',
  `referrer`        VARCHAR(2048)   DEFAULT NULL,
  `ip`              VARCHAR(45)     DEFAULT NULL,
  `user_agent`      VARCHAR(255)    DEFAULT NULL,
  `client_id`       INT UNSIGNED    DEFAULT NULL               COMMENT 'HostBill client id if logged in',
  `admin_id`        INT UNSIGNED    DEFAULT NULL               COMMENT 'Assigned admin',
  `language`        VARCHAR(16)     NOT NULL DEFAULT 'english',
  `extra`           MEDIUMTEXT      DEFAULT NULL               COMMENT 'JSON: extra fields, captcha payload, abuse-handler IDs',
  `submitted_at`    DATETIME        NOT NULL,
  `updated_at`      DATETIME        NOT NULL,
  `closed_at`       DATETIME        DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_public_id`   (`public_id`),
  KEY        `idx_type`       (`type`),
  KEY        `idx_status`     (`status`),
  KEY        `idx_severity`   (`severity`),
  KEY        `idx_email`      (`email`),
  KEY        `idx_ip`         (`ip`, `submitted_at`),
  KEY        `idx_client_id`  (`client_id`),
  KEY        `idx_admin_id`   (`admin_id`),
  KEY        `idx_submitted`  (`submitted_at`),
  KEY        `idx_url`        (`url`(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

######
-- 2. Attachments — file uploads linked to a report.
CREATE TABLE IF NOT EXISTS `hb_feedback_abuse_attachments` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `report_id`     BIGINT UNSIGNED NOT NULL,
  `orig_name`     VARCHAR(255)    NOT NULL,
  `stored_name`   VARCHAR(128)    NOT NULL                   COMMENT 'sha256 of file content, no extension',
  `extension`     VARCHAR(16)     NOT NULL,
  `mime_type`     VARCHAR(127)    DEFAULT NULL,
  `size_bytes`    INT UNSIGNED    NOT NULL,
  `storage_path`  VARCHAR(512)    NOT NULL                   COMMENT 'relative path under storage_path; absolute is built at runtime',
  `sha256`        CHAR(64)        NOT NULL,
  `uploaded_by`   VARCHAR(32)     NOT NULL DEFAULT 'reporter' COMMENT 'reporter|admin',
  `uploaded_at`   DATETIME        NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_sha256`    (`sha256`),
  KEY        `idx_report_id`(`report_id`),
  CONSTRAINT `fk_att_report` FOREIGN KEY (`report_id`) REFERENCES `hb_feedback_abuse_reports`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

######
-- 3. Internal notes — staff-only commentary on a report (visible in admin only).
CREATE TABLE IF NOT EXISTS `hb_feedback_abuse_notes` (
  `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `report_id`     BIGINT UNSIGNED NOT NULL,
  `admin_id`      INT UNSIGNED    NOT NULL,
  `note`          MEDIUMTEXT      NOT NULL,
  `is_internal`   TINYINT(1)      NOT NULL DEFAULT 1         COMMENT 'reserved — currently always 1 (internal only)',
  `created_at`    DATETIME        NOT NULL,
  PRIMARY KEY (`id`),
  KEY        `idx_report_id`(`report_id`, `created_at`),
  KEY        `idx_admin_id` (`admin_id`),
  CONSTRAINT `fk_note_report` FOREIGN KEY (`report_id`) REFERENCES `hb_feedback_abuse_reports`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

######
-- 4. Rate-limit cache — per IP sliding window.
CREATE TABLE IF NOT EXISTS `hb_feedback_abuse_rate` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ip`            VARCHAR(45)     NOT NULL,
  `endpoint`      VARCHAR(32)     NOT NULL DEFAULT 'submit'   COMMENT 'submit|api|embed',
  `hits`          INT UNSIGNED    NOT NULL DEFAULT 1,
  `window_start`  DATETIME        NOT NULL,
  `window_end`    DATETIME        NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_ip_endpoint` (`ip`, `endpoint`, `window_start`),
  KEY        `idx_window_end` (`window_end`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

######
-- 5. Embed tokens — HMAC-signed tokens that authorise a 3rd-party site to
--    POST a report against this HostBill instance.  Issued per-domain;
--    can be revoked at any time.
CREATE TABLE IF NOT EXISTS `hb_feedback_abuse_tokens` (
  `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `token_id`      CHAR(24)        NOT NULL                   COMMENT 'random public id of the token',
  `origin_domain` VARCHAR(253)    NOT NULL                   COMMENT 'allowed Referer / Origin',
  `label`         VARCHAR(128)    DEFAULT NULL,
  `secret_hash`   CHAR(64)        NOT NULL                   COMMENT 'sha256 of the per-token secret (HMAC parent secret not stored)',
  `issued_at`     DATETIME        NOT NULL,
  `expires_at`    DATETIME        NOT NULL,
  `last_used_at`  DATETIME        DEFAULT NULL,
  `revoked_at`    DATETIME        DEFAULT NULL,
  `issued_by`     INT UNSIGNED    DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_token_id`   (`token_id`),
  KEY        `idx_origin`    (`origin_domain`),
  KEY        `idx_expires`   (`expires_at`),
  KEY        `idx_revoked`   (`revoked_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

######
-- 6. Audit log — every status / assignment / note / token action.
CREATE TABLE IF NOT EXISTS `hb_feedback_abuse_audit` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `report_id`     BIGINT UNSIGNED DEFAULT NULL,
  `actor_type`    VARCHAR(16)     NOT NULL                   COMMENT 'admin|client|system|api',
  `actor_id`      INT UNSIGNED    DEFAULT NULL,
  `action`        VARCHAR(32)     NOT NULL                   COMMENT 'created|status_changed|note_added|assigned|deleted|token_issued|token_revoked|auto_closed',
  `from_value`    VARCHAR(64)     DEFAULT NULL,
  `to_value`      VARCHAR(64)     DEFAULT NULL,
  `meta`          MEDIUMTEXT      DEFAULT NULL               COMMENT 'JSON free-form payload',
  `ip`            VARCHAR(45)     DEFAULT NULL,
  `created_at`    DATETIME        NOT NULL,
  PRIMARY KEY (`id`),
  KEY        `idx_report_id`(`report_id`, `created_at`),
  KEY        `idx_action`   (`action`),
  KEY        `idx_actor`    (`actor_type`, `actor_id`),
  KEY        `idx_created`  (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

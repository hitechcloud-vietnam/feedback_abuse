# Security Policy — Feedback & Abuse Reports Module

> **Supported versions:** 1.0.0+
> **Reporting a vulnerability:** security@photuesoftware.com
> (replace with the actual contact for your deployment)

## Threat model

The module accepts unauthenticated uploads from the public internet
(every visitor, including 3rd-party sites via the embed form).  The
attack surface is:

1. Untrusted form input (strings, attachments, IPs).
2. Untrusted HTTP headers (`Origin`, `Referer`, `User-Agent`).
3. The embed form, which runs in iframes on attacker-controlled
   origins and may be used to phish credentials.
4. The admin UI, which exposes notes, audit log, and the ability to
   delete reports.

We treat every byte from these channels as hostile and apply defence
in depth.

## Defences

### Input validation (white-list)

* `type` is matched against a fixed enum
  (`feedback | phishing | malware | botnet | spam | domain_abuse | network_abuse`).
* `email` is filtered via `FILTER_VALIDATE_EMAIL`.
* `url` is matched against a regex OR `FILTER_VALIDATE_URL`
  OR `FILTER_VALIDATE_DOMAIN` — whichever passes.
* Phone is free text but capped at 40 chars and trimmed.
* `message` is capped at 10 000 chars.
* Attachments are checked against an **explicit allowlist** of
  extensions (default: doc, docx, xls, xlsx, pdf, jpg, jpeg, gif,
  png, bmp, ico, zip, rar, txt, csv — `DEFAULT_ALLOWED_EXTS`).
* Per-file size cap is configurable (default 10 MB).
* `finfo_file` is used to verify MIME; the extension allowlist is the
  authoritative check.

### Output escaping (XSS)

* Every variable in `.tpl` files is rendered with
  `|escape:'html'`.
* Smarty is configured (by HostBill core) with
  `escape_html` enabled by default. Do not override `$escape_html`
  in this module.
* The embed template additionally escapes strings injected into
  JavaScript with `|escape:'javascript'`.

### SQL injection

* All dynamic queries use PDO prepared statements
  (`$db->prepare(...)` → `execute([...])`).
* The two `LIKE '%...%'` queries in `Report::scopeSearch()` escape
  `%` and `_` before binding.
* No string concatenation into SQL.
* Table names are taken from class constants (not user input).

### CSRF

* Every admin POST requires a token that the server compares with
  `hash_equals()`.
* The public form uses a session-bound CSRF token; the API also
  accepts the token via the `X-CSRF-Token` header.

### Cross-origin (CORS)

* The embed endpoint echoes the request's `Origin` back, but the
  authoritative gate is the **embed HMAC**, not CORS.
* `Access-Control-Allow-Credentials` is only sent when the origin
  is non-empty and matches the request (no wildcard).
* The server rejects any request whose `Origin` is on a public
  suffix list (e.g. `.com`, `.co.uk`) but whose `Referer` does not
  match the configured `origin_domain` for the embed token.

### Rate-limiting

* 10 reports / IP / hour by default; configurable to 0 to disable.
* Status lookups are capped at 60 / IP / hour.
* Cron purges rows older than 24 h daily.

### File storage

* Attachments are stored as `<sha256>.<ext>` so identical content
  uploaded twice is not duplicated.
* Storage directory is created with mode 0755 and a `.htaccess`
  containing `Require all denied` to block direct HTTP access.
* An `index.html` is placed in each subdirectory to suppress listing
  even if a misconfigured webserver falls back.
* `move_uploaded_file()` (not `rename()`) is used to validate that
  the source was actually uploaded.
* The `sha256` is the unique key — same file content can be reused
  across reports without re-storing bytes.

### Secrets

* The `embed_hmac_secret` is stored in the `configuration` array,
  which HostBill encrypts at rest with `Utilities::encrypt()`.
* Per-token secrets are stored as `sha256(secret)` only — the clear
  secret is shown to the admin exactly once at issuance.
* The HMAC computation uses `hash_hmac('sha256', …, $parent . ':' . $secret)`
  with both halves concatenated.  This means rotating the parent
  secret invalidates all outstanding tokens (the intended behaviour).

### Authentication

* The admin controller checks `HBConfig::getSetting('admin_login')`
  and re-checks the request is `POST` for state-changing actions.
* The client-area controller checks
  `$authorization->get_login_status()` and binds reports to the
  authenticated client.
* The public API is anonymous by design (the goal is to accept
  reports from anyone).

### Session safety

* `session_start()` is only called if no session is active.
* The CSRF token is regenerated when missing.
* No data is stored in `$_SESSION` beyond the CSRF token.

### Logging / audit

* Every state-changing admin action writes a row to
  `hb_feedback_abuse_audit` (actor, action, from/to, IP, timestamp).
* Reports can never be silently deleted — the audit row is written
  **before** the report is removed.

## Known limitations

1. **CAPTCHA is opt-in.** The `require_captcha` config defaults to off
   because HostBill core does not bundle a CAPTCHA service.  When
   enabled, the widget renders a `[data-fbw-captcha]` placeholder
   that your integrator must populate (e.g. with hCaptcha or
   reCAPTCHA via the existing HostBill integration).
2. **Attachment scanning is not provided.** Files are validated by
   extension + magic bytes. If you accept zip/rar from the public
   internet you MUST run a background AV scan (ClamAV, etc.) on the
   storage directory.  The module leaves a hook (`extra` JSON column
   + event listener) for this.
3. **The embed HMAC parent secret must be rotated quarterly.**  There
   is no automated rotation; we recommend an ops-side runbook.

## Reporting a vulnerability

Please email **security@photuesoftware.com** with:

* Module version (e.g. `1.0.0`).
* Reproduction steps.
* Impact assessment.

We aim to acknowledge within 2 business days and patch within 30 days
for any vulnerability rated CVSS ≥ 4.0.

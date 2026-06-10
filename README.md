# Feedback & Abuse Reports — HostBill Module

> **Module slug:** `feedback_abuse`
> **Base class:** `OtherModule`
> **Version:** 1.0.1
> **Owner:** Pho Tue SoftWare And Technology Solutions Joint Stock Company (MST: 0318222903)
> **License:** Commercial — see [LICENSE](LICENSE)

A single HostBill module that exposes the following report forms:

| # | Type | Form |
|---|---|---|
| 1 | `feedback`       | Gửi góp ý (General feedback) |
| 2 | `phishing`       | Báo cáo PHISHING |
| 3 | `malware`        | Báo cáo MALWARE |
| 4 | `botnet`         | Báo cáo BOTNETS / C2 |
| 5 | `spam`           | Báo cáo SPAM |
| 6 | `domain_abuse`   | Báo cáo lạm dụng tên miền |
| 7 | `network_abuse`  | Reporting network abuse (catch-all) |

All forms share the same 7 fields the user spec calls out:

- **Họ và tên** (full name, required)
- **Số điện thoại** (phone, optional)
- **Email** (required)
- **URL / Website / Tên miền** (required)
- **Loại báo cáo lạm dụng** (required, enum)
- **Nội dung báo cáo** (required, free text)
- **File đính kèm** (optional, multi-file)

---

## What ships

```
module_dev_hostbill/Other/feedback_abuse/
├── class.feedback_abuse.php            ← main module class (OtherModule)
├── install.sql                         ← 6 tables, ###### separator
├── admin/
│   ├── class.feedback_abuse_controller.php
│   └── templates/default.tpl
├── user/
│   ├── class.feedback_abuse_controller.php
│   └── template.tpl
├── widgets/
│   └── widget.feedback_form.php        ← client-area Widget (per CLAUDE.md §-1)
├── widget_templates/
│   ├── widget_feedback_form_inline.tpl ← HostBill-hosted
│   └── widget_feedback_form_embed.tpl  ← 3rd-party iframe
├── orm/
│   ├── class.report.php                ← Eloquent model
│   ├── class.attachment.php
│   ├── class.note.php
│   ├── class.rate.php
│   ├── class.token.php
│   └── class.audit.php
├── lib/
│   ├── class.report_service.php        ← submission service
│   ├── class.attachment_store.php      ← file I/O
│   ├── class.embed_token.php           ← HMAC token manager
│   └── class.rate_limiter.php
├── api/
│   ├── class.feedback_abuse_apiroutes.php
│   └── feedback_abuse_apiroutes.json
├── cron/
│   └── class.feedback_abuse_cron.php
├── event/
│   └── class.feedback_abuse_handle.php ← Observer event methods
├── lang/                               ← reserved for per-language overrides
├── assets/, docs/, tests/              ← dev aids
├── README.md
├── SECURITY.md
├── LICENSE
├── NOTICE
└── DEVKIT.md
```

---

## Install

1. Copy the folder into `includes/modules/Other/feedback_abuse/`.
2. HostBill Admin → **Settings → Modules → Feedback & Abuse Reports** → **Install**.
3. Set:
   - **Notify admin email** — comma-separated recipients for new reports.
   - **Embed HMAC secret** — long random string, used for 3rd-party embeds.
   - **Storage path** — absolute path on disk; created if missing.
4. Place the **Widget_feedback_form** widget on the desired client pages
   (Admin → Settings → Client Portal → Widgets).

> The `install.sql` is idempotent (`CREATE TABLE IF NOT EXISTS`). Re-running
> the install is safe.

## Embedding on a 3rd-party site

1. In HostBill admin: **Extras → Feedback & Abuse → Embed Tokens → Issue**.
2. Provide the 3rd-party origin (e.g. `widget.example.com`) and a label.
3. Save the **secret** (shown exactly once).
4. Drop this iframe into the 3rd-party page:

```html
<iframe src="https://your-hostbill.example.com/?cmd=feedback_abuse_form&embed=1&token=<TOKEN_ID>.<HMAC>&origin=widget.example.com"
        style="width:100%; border:0;" scrolling="no"></iframe>
```

Where `<HMAC>` is computed as
```
hmac-sha256(METHOD + "\n" + PATH + "\n" + BODY, EMBED_HMAC_SECRET + ":" + TOKEN_SECRET)
```
Reference PHP implementation lives in
`lib/class.embed_token.php::computeHmac()`.  A reference JS client is
included in the embed template (the iframe is self-contained — the
3rd-party site does not need to call the API directly).

The form auto-resizes the iframe via `postMessage`.

---

## Public API

`POST /api/feedback_abuse/submit`

```http
POST /api/feedback_abuse/submit HTTP/1.1
Content-Type: multipart/form-data; boundary=…
X-Embed-Token: <token_id>.<hmac>
X-Embed-Origin: widget.example.com

--…
Content-Disposition: form-data; name="type"

phishing
--…
Content-Disposition: form-data; name="full_name"

Nguyen Van A
…
```

Returns:
```json
{ "ok": true, "public_id": "ABCDEFGHJKLMNPQRSTUVW234", "id": 42, "message": "report_submitted" }
```

| Endpoint | Method | Description |
|---|---|---|
| `/api/feedback_abuse/submit`         | POST | Submit a new report (multipart) |
| `/api/feedback_abuse/status/{id}`    | GET  | Look up status by public_id |
| `/api/feedback_abuse/types`          | GET  | List enabled report types |
| `/api/feedback_abuse/{any}`          | OPTIONS | CORS preflight for embedded forms |

HostBill User API route metadata is cached in `api/feedback_abuse_apiroutes.json`.
The PHP route class also carries `@route` docblocks so the cache can be regenerated in `DEV_ENV`.

---

## Security

See [SECURITY.md](SECURITY.md). Highlights:

- All inputs are validated; HTML / SQL / LDAP injection are mitigated
  (prepared statements + escape on render).
- Uploads are content-addressed (sha256) and extension-allowlisted.
- File storage is `.htaccess`-protected (`Require all denied`).
- Rate-limit is per-IP per-hour (default 10, configurable).
- CSRF tokens are issued per session.
- Embed tokens are HMAC-signed with a parent secret; revocation is
  immediate via the admin UI.
- The 3rd-party form posts to the same HostBill origin via the
  embedded iframe; cross-origin access is gated by the HMAC.

---

## Cron

Runs daily (HostBill auto-dispatches). Operations:

1. Trim rate-limit cache older than 24 h.
2. Purge attachment files orphaned by report deletion.
3. Auto-close `new` reports older than `auto_close_after_days` (default 30).
4. Mark expired embed tokens as revoked (audit visibility).

---

## Localization

Two built-in languages: **English** + **Vietnamese**.
Embed visitor language is auto-detected from `Accept-Language`.
The widget can be force-set via the `language_default` config.

---

## Changelog

| Version | Date | Change |
|---|---|---|
| 1.0.1 | 2026-06-11 | Fixed HostBill User API route visibility by replacing malformed route cache metadata and adding `@route` docblocks. Hardened `getLang()` access to avoid stale-deploy fatals. |
| 1.0.0 | 2026-06-11 | Initial release. 7 report types, embed widget, JSON API, admin dashboard, audit log. |

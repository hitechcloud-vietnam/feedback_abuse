# Changelog

All notable changes to the Feedback & Abuse Reports module are documented here.

## [1.0.1] - 2026-06-11

### Fixed
- Replaced malformed `api/feedback_abuse_apiroutes.json` with HostBill User API-compatible route metadata so routes appear in the User API module.
- Added generator-compatible `@route` docblocks to `api/class.feedback_abuse_apiroutes.php` for future cache regeneration.
- Added a real CORS preflight handler for the existing OPTIONS route metadata.
- Hardened admin, client, widget, and API helper access against stale or partial deployments that may miss `getLang()`/module helper methods.
- Corrected event emission to use `HBEventManager::notify()` instead of the nonexistent `dispatch()` method.
- Added HostBill-compatible `after_report()` and `after_status_change()` observer methods and now emit status-change events from the admin controller.
- Removed the unnecessary `Observer` interface from the main module class; the dedicated event handler remains the registered observer.
- Replaced the nonexistent static `Mailer::sendMail()` call with HostBill's native `Mailer`/PHPMailer instance API.

## [1.0.0] - 2026-06-11

### Added
- Initial release with 7 report types, embed widget, JSON API, admin dashboard, attachment handling, and audit log.
# Changelog

All notable changes to **mod_examcheck** are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.1.0] - 2026-05-29

### Added
- Optional **scan extraction pattern**: a regular expression applied to the
  scanned QR/barcode value to extract the part to match against the chosen field
  (e.g. pull an 8-digit student number out of a longer encoded payload). The
  first capturing group is used, or the whole match if there is no group.
  Configurable per activity (with a site default) and overridable per scanning
  session. Validated on save; tests included.

## [1.0.0] - 2026-05-29

### Added
- Initial release for Moodle 5.1.
- Checking dashboard: roster grid with one toggle per check step, live
  multi-teacher refresh, client-side search and a "show only not-yet-checked"
  filter, and group selection.
- Custom, ordered check steps per activity (seeded with one *Attendance* step);
  add, rename, reorder and delete steps.
- Shared single-mark semantics with conflict reporting: a student can be checked
  only once per step, and a second teacher sees who checked them and when.
- QR / barcode scanner page using the native `BarcodeDetector` API, with a
  manual-entry fallback for hardware (keyboard-wedge) scanners. Match against ID
  number, internal user id, or any custom profile field. Optional
  confirm-before-marking mode.
- AJAX web services: `mark_user`, `unmark_user`, `scan_lookup`, `get_marks`.
- Custom completion: complete when checked on **all steps** or on a single
  chosen step; usable as a prerequisite for other activities.
- Export of the roster check status via Moodle data formats (CSV/Excel/ODS).
- Clear recorded checks per step or for the whole activity, plus course-reset
  integration.
- Backup & restore (backup_moodle2) including steps and, as user data, marks.
- Privacy (GDPR) provider for the recorded checks.
- Events: `user_marked`, `user_unmarked`, `course_module_viewed`.
- Capabilities: `addinstance`, `view`, `check`, `override`, `managesteps`.
- PHPUnit test suite and a test data generator.

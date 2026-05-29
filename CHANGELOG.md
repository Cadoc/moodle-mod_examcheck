# Changelog

All notable changes to **mod_examcheck** are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2026-05-29

### Added
- Initial release for Moodle 5.1.
- Checking dashboard: roster grid with one toggle per check step, live
  multi-teacher refresh, client-side search and a "show only not-yet-checked"
  filter, and group selection.
- Room separation via group mode: in separate groups an invigilator without
  "access all groups" sees only their own group's students on the dashboard,
  scanner and export. Request-supplied group/user ids are validated server-side.
- Custom, ordered check steps per activity (seeded with one *Attendance* step);
  add, rename, reorder and delete steps.
- Shared single-mark semantics with conflict reporting: a student can be checked
  only once per step, and a second teacher sees who checked them and when.
- QR / barcode scanner page using the native `BarcodeDetector` API, with a
  manual-entry fallback for hardware (keyboard-wedge) scanners. Match against ID
  number, internal user id, or any custom profile field. Optional
  confirm-before-marking mode.
- Optional scan extraction pattern (regex) to pull the value to match (e.g. a
  student number) out of a longer encoded barcode payload; configurable per
  activity with a site default and a per-session override.
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

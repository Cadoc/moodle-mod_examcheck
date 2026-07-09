# Changelog

All notable changes to **mod_examcheck** are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Releases are versioned against the supported Moodle branch as `<branch>-r<n>`.

## [5.1-r2] - 2026-07-09

### Added

- Seat numbers (#14): a per-activity list of free-text seat labels with a 1:1
  student/seat assignment.
  - New gradebook-style **Seats** tab (capability `mod/examcheck:manageseats`,
    editing teachers and managers) with subpages: *Seat assignment* (instant
    per-row student autocomplete with conflict handling), *Edit seats* (one
    label per line; changing the list resets assignments after an explicit
    acknowledgement), *Import* and *Export* (CSV round trip; students matched
    by username, email or idnumber; identity columns follow the exporting
    user's identity-field visibility).
  - Read-only, sortable (natural order: A2 before A10) and hideable **Seat**
    column on the roster, visible to anyone who can view the activity, plus a
    seat column in the roster export.
  - The scanner info/confirm pop-ups show the scanned student's assigned seat.
  - AJAX web services: `assign_seat`, `unassign_seat`,
    `search_seat_candidates`; events `seat_assigned`, `seat_unassigned`.
  - Backup & restore (assignments as user data), privacy (GDPR) coverage, and
    course reset (clears assignments, keeps the seat list).
  - Per-activity **Enable seat numbers** toggle (site default
    `defaultenableseats`, enabled by default). Disabling hides the Seats tab
    and the roster/export seat columns, blocks the seat pages and web
    services, and keeps all seat data for when it is re-enabled.

### Changed

- The activity form's *Attendance settings* section is now *General settings*
  and hosts the *Enable scanner* and *Enable seat numbers* toggles; every
  *Scanning defaults* field (including the scan match field) is hidden while
  the scanner is disabled.
- The roster no longer shows a dedicated scan-match-field column. It now
  shows the identity fields configured for the site — including custom
  profile fields — respecting each viewer's permission to see them; the scan
  match field only affects the scanner.

## [5.1-r1] - 2026-06-10

First public release for Moodle 5.1.

### Added

- Checking dashboard: roster grid with one toggle per check step, live
  multi-teacher refresh, client-side search and a "show only not-yet-checked"
  filter, and group selection.
- Room separation via group mode: in separate groups an invigilator without
  "access all groups" sees only their own group's students on the dashboard,
  scanner and export. Request-supplied group/user ids are validated server-side.
- Custom, ordered check steps per activity (seeded with one *Attendance* step);
  add, rename, reorder and delete steps. A step can optionally require a
  submitted attempt on a course quiz before a student can be checked.
- Shared single-mark semantics with conflict reporting: a student can be checked
  only once per step, and a second teacher sees who checked them and when.
- QR / barcode scanner page powered by [zxing-wasm](https://github.com/Sec-ant/zxing-wasm),
  a WebAssembly build of upstream `zxing-cpp`, with a manual-entry fallback for
  hardware (keyboard-wedge) scanners. Decodes a broad set of symbologies out of
  the box (QR, Data Matrix, Aztec, PDF417, MaxiCode, Code 128 / 39 / 93, Codabar,
  ITF, EAN-13 / EAN-8, UPC-A / UPC-E, GS1 DataBar). Match against ID number,
  internal user id, or any custom profile field. Optional confirm-before-marking
  mode and a per-activity camera switcher.
- Optional scan extraction pattern (regex) to pull the value to match (e.g. a
  student number) out of a longer encoded barcode payload; configurable per
  activity with a site default and a per-session override.
- AJAX web services: `mark_user`, `unmark_user`, `bulk_action`, `scan_lookup`,
  `get_marks`.
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

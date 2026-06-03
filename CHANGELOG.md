# Changelog

All notable changes to **mod_examcheck** are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.5] - 2026-06-03

### Fixed
- Step form now validates server-side that the submitted quiz cmid
  belongs to the activity's course. The dropdown already lists only the
  current course's quizzes, but a tampered submission could previously
  store a cross-course cmid; the gate would then silently never pass.
- `checker::validate_quiz_attempt` resolves the quiz cmid against the
  examcheck instance's course, so a stale cross-course reference is
  reported as a misconfiguration rather than honoured.
- Roster table constructor now throws a `coding_exception` when the
  unique id doesn't match `examcheck-roster-<cmid>`, making the contract
  explicit instead of silently producing a blank table on misuse.
- View page no longer strip-tags the intro to test emptiness;
  `format_module_intro` already returns the empty string in that case.
- Scan page omits `group=0` from its canonical URL so the page url
  matches the no-group case (cleaner logs, no cache fragmentation).
- Index page guards the `format_<courseformat>` sectionname lookup so
  it doesn't blow up on formats that don't ship that string.
- Scanner template uses escaped `{{name}}` inside `<option>` text
  (defence in depth — options can't render HTML anyway).
- Scanner AMD module pulls the "Camera" label from the plugin's lang
  file via `core/str` instead of hardcoding English when a device has
  no label (common on iOS before camera permission is granted).

### Changed
- Settings: scan-extraction regex setting uses `PARAM_RAW_TRIMMED` so
  leading/trailing whitespace cannot silently change the saved pattern.
- `outcome` helper moved from `classes/external/` to `classes/local/`.
  `classes/external/` now contains only registered web service entry
  points (mark_user, unmark_user, bulk_action, scan_lookup, get_marks).
- Manage-steps page swaps the hand-rolled `<i class="fa fa-…">` icons
  for the `{{#pix}}` helper so themes can swap artwork without touching
  this template.
- "With selected students" action menu rendered from a new
  `mod_examcheck/actions_menu` Mustache template instead of being
  assembled with `html_writer::select` in PHP.
- `mod_examcheck_get_completion_active_rule_descriptions` in `lib.php`
  drives its rule list from `custom_completion::get_defined_custom_rules()`
  rather than duplicating the rule key in two places.
- `MOODLE_INTERNAL` guards removed from autoloaded class files
  (`classes/form/step_form.php`, `classes/table/roster.php`).
- `step_form.php` uses `global $CFG;` instead of reaching into
  `$GLOBALS['CFG']`.

## [1.0.4] - 2026-06-03

### Fixed
- Backup/restore now preserves every per-activity scanner toggle
  (`enablescanner`, `showcameraswitcher`) and every per-step quiz-attempt gate
  (`requirequizattempt`, `quizcmid`). Previous releases silently dropped these
  fields from the backup XML, so restoring a course rolled scanner settings
  back to the XMLDB defaults and lost every step's quiz gate.
- Step `quizcmid` is now declared as a `course_module` reference and remapped
  on restore via the standard backup id mapping. If the gated quiz is not part
  of the same restore the gate is cleared rather than carrying a dangling cmid.
- Bulk unmark from the dashboard now enforces the per-student separate-groups
  gate (matching the single `unmark_user` web service), and replaces the
  exception-flow-based override check with an explicit capability test before
  touching a mark. A teacher restricted to one group can no longer reach marks
  in another group even when they hold `mod/examcheck:override`.
- Stripped five `console.log('[examcheck] …')` debug calls from the scanner
  AMD module so production browsers no longer log scanned values to devtools.
- Dropped the long-deprecated `$plugin->cron = 0;` line from `version.php`
  (superseded by scheduled tasks since Moodle 2.3).

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

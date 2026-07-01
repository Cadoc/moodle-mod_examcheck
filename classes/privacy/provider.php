<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace mod_examcheck\privacy;

use context;
use context_module;
use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider for mod_examcheck.
 *
 * A person can appear in the data both as the student who was checked (userid)
 * and as the teacher who recorded a check (checkedby).
 *
 * @package    mod_examcheck
 * @copyright  2026 André Camacho
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\plugin\provider {
    /**
     * Describe the personal data stored by this plugin.
     *
     * @param collection $collection The metadata collection to add to.
     * @return collection The updated collection.
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('examcheck_marks', [
            'userid'      => 'privacy:metadata:examcheck_marks:userid',
            'checkedby'   => 'privacy:metadata:examcheck_marks:checkedby',
            'stepid'      => 'privacy:metadata:examcheck_marks:stepid',
            'method'      => 'privacy:metadata:examcheck_marks:method',
            'timecreated' => 'privacy:metadata:examcheck_marks:timecreated',
        ], 'privacy:metadata:examcheck_marks');

        $collection->add_database_table('examcheck_uncheck_events', [
            'userid'      => 'privacy:metadata:examcheck_uncheck_events:userid',
            'actingby'    => 'privacy:metadata:examcheck_uncheck_events:actingby',
            'reasonkey'   => 'privacy:metadata:examcheck_uncheck_events:reasonkey',
            'reasontext'  => 'privacy:metadata:examcheck_uncheck_events:reasontext',
            'timecreated' => 'privacy:metadata:examcheck_uncheck_events:timecreated',
        ], 'privacy:metadata:examcheck_uncheck_events');

        $collection->add_database_table('examcheck_exemptions', [
            'userid'      => 'privacy:metadata:examcheck_exemptions:userid',
            'exemptedby'  => 'privacy:metadata:examcheck_exemptions:exemptedby',
            'reason'      => 'privacy:metadata:examcheck_exemptions:reason',
        ], 'privacy:metadata:examcheck_exemptions');

        $collection->add_database_table('examcheck_flags', [
            'userid'    => 'privacy:metadata:examcheck_flags:userid',
            'flaggedby' => 'privacy:metadata:examcheck_flags:flaggedby',
            'flagtype'  => 'privacy:metadata:examcheck_flags:flagtype',
            'note'      => 'privacy:metadata:examcheck_flags:note',
        ], 'privacy:metadata:examcheck_flags');

        return $collection;
    }

    /**
     * Get the list of contexts that contain personal data for a user.
     *
     * @param int $userid The user to search for.
     * @return contextlist The list of contexts.
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $sql = "SELECT ctx.id
                  FROM {context} ctx
                  JOIN {course_modules} cm ON cm.id = ctx.instanceid AND ctx.contextlevel = :modlevel
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                  JOIN {examcheck} e ON e.id = cm.instance
             LEFT JOIN {examcheck_marks} mk ON mk.examcheckid = e.id
                       AND (mk.userid = :userid OR mk.checkedby = :checkedby)
             LEFT JOIN {examcheck_uncheck_events} ue ON ue.examcheckid = e.id
                       AND (ue.userid = :userid2 OR ue.actingby = :actingby)
             LEFT JOIN {examcheck_exemptions} ex ON ex.examcheckid = e.id
                       AND (ex.userid = :userid3 OR ex.exemptedby = :exemptedby)
             LEFT JOIN {examcheck_flags} fl ON fl.examcheckid = e.id
                       AND (fl.userid = :userid4 OR fl.flaggedby = :flaggedby)
                 WHERE mk.id IS NOT NULL OR ue.id IS NOT NULL OR ex.id IS NOT NULL OR fl.id IS NOT NULL";

        $contextlist = new contextlist();
        $contextlist->add_from_sql($sql, [
            'modlevel'   => CONTEXT_MODULE,
            'modname'    => 'examcheck',
            'userid'     => $userid,
            'checkedby'  => $userid,
            'userid2'    => $userid,
            'actingby'   => $userid,
            'userid3'    => $userid,
            'exemptedby' => $userid,
            'userid4'    => $userid,
            'flaggedby'  => $userid,
        ]);

        return $contextlist;
    }

    /**
     * Get the list of users who have data within a context.
     *
     * @param userlist $userlist The userlist to populate.
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        if (!$context instanceof context_module) {
            return;
        }

        $params = ['cmid' => $context->instanceid, 'modname' => 'examcheck'];
        $sql = "SELECT mk.userid, mk.checkedby
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                  JOIN {examcheck} e ON e.id = cm.instance
                  JOIN {examcheck_marks} mk ON mk.examcheckid = e.id
                 WHERE cm.id = :cmid";

        $userlist->add_from_sql('userid', $sql, $params);
        $userlist->add_from_sql('checkedby', $sql, $params);
    }

    /**
     * Export all personal data for the approved contexts.
     *
     * @param approved_contextlist $contextlist The approved contexts to export.
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        $userid = $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof context_module) {
                continue;
            }
            $cm = get_coursemodule_from_id('examcheck', $context->instanceid);
            if (!$cm) {
                continue;
            }

            $marks = $DB->get_records_sql(
                "
                SELECT mk.id, mk.method, mk.timecreated, mk.userid, mk.checkedby, s.name AS stepname
                  FROM {examcheck_marks} mk
                  JOIN {examcheck_steps} s ON s.id = mk.stepid
                 WHERE mk.examcheckid = :examcheckid
                   AND (mk.userid = :userid OR mk.checkedby = :checkedby)
              ORDER BY mk.timecreated ASC",
                ['examcheckid' => $cm->instance, 'userid' => $userid, 'checkedby' => $userid]
            );

            if (!$marks) {
                continue;
            }

            $aschecked = [];
            $aschecker = [];
            foreach ($marks as $mark) {
                $entry = (object) [
                    'step'        => $mark->stepname,
                    'method'      => $mark->method,
                    'timecreated' => \core_privacy\local\request\transform::datetime($mark->timecreated),
                ];
                if ((int) $mark->userid === $userid) {
                    $aschecked[] = $entry;
                }
                if ((int) $mark->checkedby === $userid) {
                    $aschecker[] = $entry;
                }
            }

            $data = (object) [
                'checkedstudent' => $aschecked,
                'checkedbyme'    => $aschecker,
            ];

            // Uncheck events where the user was the student or the acting teacher.
            $uncheckevents = $DB->get_records_sql(
                "SELECT ue.id, ue.reasonkey, ue.reasontext, ue.timecreated, s.name AS stepname
                   FROM {examcheck_uncheck_events} ue
                   JOIN {examcheck_steps} s ON s.id = ue.stepid
                  WHERE ue.examcheckid = :examcheckid
                    AND (ue.userid = :userid OR ue.actingby = :actingby)
               ORDER BY ue.timecreated ASC",
                ['examcheckid' => $cm->instance, 'userid' => $userid, 'actingby' => $userid]
            );
            $data->uncheckevents = array_values(array_map(static function(\stdClass $ue) use ($userid): \stdClass {
                return (object) [
                    'step'        => $ue->stepname,
                    'role'        => ((int) $ue->userid === $userid) ? 'student' : 'teacher',
                    'reasonkey'   => $ue->reasonkey ?? '',
                    'reasontext'  => $ue->reasontext ?? '',
                    'timecreated' => \core_privacy\local\request\transform::datetime((int) $ue->timecreated),
                ];
            }, $uncheckevents));

            // Exemptions granted to or by this user.
            $exemptions = $DB->get_records_sql(
                "SELECT ex.id, ex.reason, ex.timecreated, s.name AS stepname
                   FROM {examcheck_exemptions} ex
                   JOIN {examcheck_steps} s ON s.id = ex.stepid
                  WHERE ex.examcheckid = :examcheckid
                    AND (ex.userid = :userid OR ex.exemptedby = :exemptedby)
               ORDER BY ex.timecreated ASC",
                ['examcheckid' => $cm->instance, 'userid' => $userid, 'exemptedby' => $userid]
            );
            $data->exemptions = array_values(array_map(static function(\stdClass $ex) use ($userid): \stdClass {
                return (object) [
                    'step'        => $ex->stepname,
                    'role'        => ((int) $ex->userid === $userid) ? 'student' : 'teacher',
                    'reason'      => $ex->reason ?? '',
                    'timecreated' => \core_privacy\local\request\transform::datetime((int) $ex->timecreated),
                ];
            }, $exemptions));

            // Flags raised about or by this user.
            $flags = $DB->get_records(
                'examcheck_flags',
                ['examcheckid' => $cm->instance, 'userid' => $userid]
            );
            $data->flags = array_values(array_map(static function(\stdClass $fl): \stdClass {
                return (object) [
                    'flagtype'    => $fl->flagtype,
                    'note'        => $fl->note ?? '',
                    'timecreated' => \core_privacy\local\request\transform::datetime((int) $fl->timecreated),
                ];
            }, $flags));

            writer::with_context($context)->export_data(
                [get_string('pluginname', 'mod_examcheck')],
                $data
            );
        }
    }

    /**
     * Delete all data for all users in a context.
     *
     * @param context $context The context to delete in.
     */
    public static function delete_data_for_all_users_in_context(context $context): void {
        global $DB;

        if (!$context instanceof context_module) {
            return;
        }
        if (!$cm = get_coursemodule_from_id('examcheck', $context->instanceid)) {
            return;
        }
        $DB->delete_records('examcheck_marks',         ['examcheckid' => $cm->instance]);
        $DB->delete_records('examcheck_uncheck_events', ['examcheckid' => $cm->instance]);
        $DB->delete_records('examcheck_exemptions',     ['examcheckid' => $cm->instance]);
        $DB->delete_records('examcheck_flags',          ['examcheckid' => $cm->instance]);
    }

    /**
     * Delete data for a user across the approved contexts.
     *
     * Checks recorded about the user (as a student) are removed; where the user
     * was the recording teacher, the check is kept but the teacher reference is
     * anonymised so another student's record stays intact.
     *
     * @param approved_contextlist $contextlist The approved contexts.
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;

        $userid = $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof context_module) {
                continue;
            }
            if (!$cm = get_coursemodule_from_id('examcheck', $context->instanceid)) {
                continue;
            }
            // Marks: delete where student, anonymise where teacher.
            $DB->delete_records('examcheck_marks', ['examcheckid' => $cm->instance, 'userid' => $userid]);
            $DB->set_field('examcheck_marks', 'checkedby', 0, ['examcheckid' => $cm->instance, 'checkedby' => $userid]);

            // Uncheck events: delete where student, anonymise where acting teacher.
            $DB->delete_records('examcheck_uncheck_events', ['examcheckid' => $cm->instance, 'userid' => $userid]);
            $DB->set_field('examcheck_uncheck_events', 'actingby', 0,
                ['examcheckid' => $cm->instance, 'actingby' => $userid]);

            // Exemptions: delete where student, anonymise where granting teacher.
            $DB->delete_records('examcheck_exemptions', ['examcheckid' => $cm->instance, 'userid' => $userid]);
            $DB->set_field('examcheck_exemptions', 'exemptedby', 0,
                ['examcheckid' => $cm->instance, 'exemptedby' => $userid]);

            // Flags: delete where flagged student, anonymise where flagging teacher.
            $DB->delete_records('examcheck_flags', ['examcheckid' => $cm->instance, 'userid' => $userid]);
            $DB->set_field('examcheck_flags', 'flaggedby', 0,
                ['examcheckid' => $cm->instance, 'flaggedby' => $userid]);
        }
    }

    /**
     * Delete data for several users within one context.
     *
     * @param approved_userlist $userlist The approved users.
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;

        $context = $userlist->get_context();
        if (!$context instanceof context_module) {
            return;
        }
        if (!$cm = get_coursemodule_from_id('examcheck', $context->instanceid)) {
            return;
        }

        $userids = $userlist->get_userids();
        if (empty($userids)) {
            return;
        }

        [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $params = $inparams + ['examcheckid' => $cm->instance];

        $DB->delete_records_select(
            'examcheck_marks',
            "examcheckid = :examcheckid AND userid $insql",
            $params
        );

        [$insql2, $inparams2] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $params2 = $inparams2 + ['examcheckid' => $cm->instance];
        $DB->set_field_select(
            'examcheck_marks',
            'checkedby',
            0,
            "examcheckid = :examcheckid AND checkedby $insql2",
            $params2
        );

        // Uncheck events.
        [$insql3, $inparams3] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $DB->delete_records_select('examcheck_uncheck_events',
            "examcheckid = :examcheckid AND userid $insql3",
            $inparams3 + ['examcheckid' => $cm->instance]);

        [$insql4, $inparams4] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $DB->set_field_select('examcheck_uncheck_events', 'actingby', 0,
            "examcheckid = :examcheckid AND actingby $insql4",
            $inparams4 + ['examcheckid' => $cm->instance]);

        // Exemptions.
        [$insql5, $inparams5] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $DB->delete_records_select('examcheck_exemptions',
            "examcheckid = :examcheckid AND userid $insql5",
            $inparams5 + ['examcheckid' => $cm->instance]);

        [$insql6, $inparams6] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $DB->set_field_select('examcheck_exemptions', 'exemptedby', 0,
            "examcheckid = :examcheckid AND exemptedby $insql6",
            $inparams6 + ['examcheckid' => $cm->instance]);

        // Flags.
        [$insql7, $inparams7] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $DB->delete_records_select('examcheck_flags',
            "examcheckid = :examcheckid AND userid $insql7",
            $inparams7 + ['examcheckid' => $cm->instance]);

        [$insql8, $inparams8] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $DB->set_field_select('examcheck_flags', 'flaggedby', 0,
            "examcheckid = :examcheckid AND flaggedby $insql8",
            $inparams8 + ['examcheckid' => $cm->instance]);
    }
}

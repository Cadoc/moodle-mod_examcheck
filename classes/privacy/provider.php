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
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
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
                  JOIN {examcheck_marks} mk ON mk.examcheckid = e.id
                 WHERE mk.userid = :userid OR mk.checkedby = :checkedby";

        $contextlist = new contextlist();
        $contextlist->add_from_sql($sql, [
            'modlevel'  => CONTEXT_MODULE,
            'modname'   => 'examcheck',
            'userid'    => $userid,
            'checkedby' => $userid,
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
        $DB->delete_records('examcheck_marks', ['examcheckid' => $cm->instance]);
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
            $DB->delete_records('examcheck_marks', ['examcheckid' => $cm->instance, 'userid' => $userid]);
            $DB->set_field('examcheck_marks', 'checkedby', 0, ['examcheckid' => $cm->instance, 'checkedby' => $userid]);
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
    }
}

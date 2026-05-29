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

namespace mod_examcheck;

use mod_examcheck\local\scanfield;

/**
 * Tests for resolving scanned values to users.
 *
 * @package    mod_examcheck
 * @category   test
 * @covers     \mod_examcheck\local\scanfield
 * @copyright  2026 André Camacho
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class scanfield_test extends \advanced_testcase {

    /**
     * Match by ID number, case-insensitively and trimming whitespace.
     */
    public function test_find_by_idnumber(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user(['idnumber' => 'ABC-123']);
        $candidates = [$user->id];

        $this->assertSame($user->id, scanfield::find_user('idnumber', 'ABC-123', $candidates));
        $this->assertSame($user->id, scanfield::find_user('idnumber', '  abc-123 ', $candidates));
        $this->assertSame(0, scanfield::find_user('idnumber', 'OTHER', $candidates));
    }

    /**
     * A value outside the candidate set never matches.
     */
    public function test_respects_candidate_set(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user(['idnumber' => 'X1']);
        $this->assertSame(0, scanfield::find_user('idnumber', 'X1', [-1]));
        $this->assertSame(0, scanfield::find_user('idnumber', 'X1', []));
    }

    /**
     * Match by internal user id.
     */
    public function test_find_by_userid(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->assertSame($user->id, scanfield::find_user('userid', (string) $user->id, [$user->id]));
        $this->assertSame(0, scanfield::find_user('userid', 'notanumber', [$user->id]));
    }

    /**
     * Match by a custom profile field value.
     */
    public function test_find_by_profile_field(): void {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/user/profile/lib.php');
        $this->resetAfterTest();

        $field = $this->getDataGenerator()->create_custom_profile_field([
            'datatype'  => 'text',
            'shortname' => 'badge',
            'name'      => 'Badge',
        ]);
        $user = $this->getDataGenerator()->create_user();
        $DB->insert_record('user_info_data', (object) [
            'userid' => $user->id, 'fieldid' => $field->id, 'data' => 'BDG9', 'dataformat' => 0,
        ]);

        $key = scanfield::PROFILE_PREFIX . 'badge';
        $this->assertTrue(scanfield::is_valid($key));
        $this->assertSame($user->id, scanfield::find_user($key, 'bdg9', [$user->id]));
    }

    /**
     * An ambiguous match (two candidates share a value) returns nothing.
     */
    public function test_ambiguous_match_returns_zero(): void {
        $this->resetAfterTest();
        $one = $this->getDataGenerator()->create_user(['idnumber' => 'DUP']);
        $two = $this->getDataGenerator()->create_user(['idnumber' => 'DUP']);
        $this->assertSame(0, scanfield::find_user('idnumber', 'DUP', [$one->id, $two->id]));
    }

    /**
     * The field menu always offers the built-in fields.
     */
    public function test_field_menu(): void {
        $this->resetAfterTest();
        $menu = scanfield::get_field_menu();
        $this->assertArrayHasKey('idnumber', $menu);
        $this->assertArrayHasKey('userid', $menu);
    }
}

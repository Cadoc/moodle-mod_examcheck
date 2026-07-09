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

namespace mod_examcheck\navigation\views;

use core\navigation\views\secondary as core_secondary;
use navigation_node;
use settings_navigation;

/**
 * Secondary navigation override: rename the default first tab and order our tabs.
 *
 * Two customisations:
 *  - Moodle's core secondary view labels the first activity tab with
 *    get_string('modulename', 'mod_examcheck') ("Exam check"). We keep that string
 *    for the activity chooser and breadcrumbs but show "Roster" on the tab itself,
 *    since that's what the page actually displays.
 *  - The tab order is driven by {@see core_secondary::get_default_module_mapping()},
 *    where core gives "Settings" (modedit) weight 1. Our Scanner / Manage steps
 *    tabs are not in that map, so by default they land after Settings. We override
 *    the map to place them before Settings.
 *
 * Discovered automatically by {@see \moodle_page::magic_get_secondarynav()}.
 *
 * @package    mod_examcheck
 * @copyright  2026 André Camacho
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class secondary extends core_secondary {
    /**
     * Rewrite the modulepage tab text to "Roster" after the core loader runs.
     *
     * @param settings_navigation $settingsnav The settings navigation object.
     * @param navigation_node|null $rootnode Where module nodes were added.
     */
    protected function load_module_navigation(settings_navigation $settingsnav, ?navigation_node $rootnode = null): void {
        parent::load_module_navigation($settingsnav, $rootnode);

        $rootnode = $rootnode ?? $this;
        $node = $rootnode->find('modulepage', null);
        if ($node) {
            $node->text = get_string('roster', 'mod_examcheck');
        }
    }

    /**
     * Order the day-to-day action tabs (Scanner, Manage steps, Seats) ahead of Settings.
     *
     * Core weights "Settings" (modedit) at 1 and leaves our tabs unmapped, so they
     * would otherwise be appended after Settings. We add them to the map and bump
     * modedit so the final order is: Roster, Scanner, Manage steps, Seats, Settings.
     *
     * Weights must be integers: core treats a fractional weight as a nested child
     * (e.g. 7.1 nests under node 7), which would drop these from the top-level tabs.
     *
     * @return array
     */
    protected function get_default_module_mapping(): array {
        $mapping = parent::get_default_module_mapping();

        $mapping[self::TYPE_SETTING] = [
            'mod_examcheck_scanner' => 0,
            'mod_examcheck_managesteps' => 1,
            'mod_examcheck_seats' => 2,
            'modedit' => 3,
        ] + $mapping[self::TYPE_SETTING];

        return $mapping;
    }
}

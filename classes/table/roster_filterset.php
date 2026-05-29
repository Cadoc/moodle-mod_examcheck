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

declare(strict_types=1);

namespace mod_examcheck\table;

use core_table\local\filter\filterset;
use core_table\local\filter\integer_filter;
use core_table\local\filter\string_filter;

/**
 * Filterset for the checking roster dynamic table.
 *
 * The course module is carried in the table's unique id (examcheck-roster-{cmid}),
 * so no required filter is needed here; only the optional keyword and group filters.
 *
 * @package    mod_examcheck
 * @copyright  2026 André Camacho
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class roster_filterset extends filterset {
    /**
     * No required filters.
     *
     * @return array
     */
    public function get_required_filters(): array {
        return [];
    }

    /**
     * The optional keyword (name/id search) and group filters.
     *
     * @return array
     */
    public function get_optional_filters(): array {
        return [
            'keywords' => string_filter::class,
            'groups'   => integer_filter::class,
        ];
    }
}

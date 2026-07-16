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

/**
 * CSV export helpers.
 *
 * @package   local_cohortmembership
 * @copyright Thomas Korner <thomas.korner@edu.zh.ch>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cohortmembership\local;

/**
 * Shared helpers for writing CSV report exports (UI download + both CLI scripts).
 */
final class csv_util {
    /**
     * Neutralise CSV formula injection: if a cell starts with a character a
     * spreadsheet application would interpret as a formula prefix (=, +, -, @),
     * prefix it with a single quote so it is opened as literal text.
     *
     * Usernames and cohort idnumbers are free text chosen by whoever built the
     * CSV, not by this plugin, so they are the only fields that need this.
     *
     * @param string $value
     * @return string
     */
    public static function sanitise_cell(string $value): string {
        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@'], true)) {
            return "'" . $value;
        }
        return $value;
    }
}

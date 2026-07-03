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
 * Operation: add.
 *
 * @package   local_cohortmembership
 * @copyright Thomas Korner <thomas.korner@edu.zh.ch>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cohortmembership\local;

/**
 * Adds a user to a cohort, if not already a member.
 *
 * Assumes user and cohort have already been resolved by the caller. Never
 * creates a cohort - an unknown cohort is rejected by the caller before this
 * is reached.
 */
final class operation_add {
    /**
     * Add the given user to the given cohort.
     *
     * @param int $cohortid
     * @param int $userid
     * @param bool $dryrun
     * @return array ['status' => string]
     */
    public static function execute(int $cohortid, int $userid, bool $dryrun): array {
        global $DB;

        $ismember = $DB->record_exists('cohort_members', ['cohortid' => $cohortid, 'userid' => $userid]);
        if ($ismember) {
            return ['status' => 'status_alreadymember'];
        }

        if (!$dryrun) {
            cohort_add_member($cohortid, $userid);
        }

        return ['status' => 'status_added'];
    }
}

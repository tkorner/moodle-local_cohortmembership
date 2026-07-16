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
 * Cohort resolution helper file.
 *
 * @package   local_cohortmembership
 * @copyright Thomas Korner <thomas.korner@edu.zh.ch>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cohortmembership\local;

/**
 * Resolves a cohort by numeric id or by idnumber, shared by the delta
 * (processor) and sync (operation_sync) paths.
 *
 * Cohort idnumbers are not unique across category contexts. An ambiguous
 * idnumber is never guessed at (SPEC §10: "nicht raten") - it is reported
 * back as ambiguous so the caller can produce a status_cohortambiguous row
 * instead of silently writing to an arbitrary matching cohort.
 */
final class cohort_resolver {
    /**
     * Resolve a cohort, memoising the result for the given cache array so a
     * single processing run never looks up the same id/idnumber twice.
     *
     * @param int|null $id Numeric cohort id, or null to resolve by idnumber.
     * @param string|null $idnumber Cohort idnumber, used only when $id is null.
     * @param array $cache Memoisation cache; pass the same array by reference for a whole run.
     * @return array ['status' => 'found'|'notfound'|'ambiguous', 'id' => int|null]
     */
    public static function resolve(?int $id, ?string $idnumber, array &$cache): array {
        global $DB;

        $key = $id !== null ? ('id:' . $id) : ('idn:' . $idnumber);
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        if ($id !== null) {
            $cohort = $DB->get_record('cohort', ['id' => $id], 'id', IGNORE_MISSING);
            $result = $cohort ? ['status' => 'found', 'id' => (int)$cohort->id] : ['status' => 'notfound', 'id' => null];
        } else {
            $matches = $DB->get_records('cohort', ['idnumber' => $idnumber], '', 'id');
            if (count($matches) > 1) {
                $result = ['status' => 'ambiguous', 'id' => null];
            } else if ($matches) {
                $result = ['status' => 'found', 'id' => (int)reset($matches)->id];
            } else {
                $result = ['status' => 'notfound', 'id' => null];
            }
        }

        $cache[$key] = $result;
        return $result;
    }
}

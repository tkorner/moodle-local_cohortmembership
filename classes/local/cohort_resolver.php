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
 *
 * When a run is restricted to a course category (a category manager working
 * from the category's own entry point), cohorts outside that category's
 * subtree are invisible: they count as 'notfound' and are filtered out before
 * the ambiguity check, so the report reveals nothing about cohorts elsewhere
 * and an idnumber reused in another category does not block an in-scope
 * cohort. A cohort inside the subtree where the current user lacks
 * moodle/cohort:assign is reported as 'nopermission' and never touched.
 */
final class cohort_resolver {
    /**
     * Resolve a cohort, memoising the result for the given cache array so a
     * single processing run never looks up the same id/idnumber twice.
     *
     * @param int|null $id Numeric cohort id, or null to resolve by idnumber.
     * @param string|null $idnumber Cohort idnumber, used only when $id is null.
     * @param array $cache Memoisation cache; pass the same array by reference for a whole run.
     * @param \context_coursecat|null $scope Category the run is restricted to, or null for an unrestricted
     *                                       (site-level) run. Must stay the same for every call sharing $cache.
     * @return array ['status' => 'found'|'notfound'|'ambiguous'|'nopermission', 'id' => int|null]
     */
    public static function resolve(?int $id, ?string $idnumber, array &$cache, ?\context_coursecat $scope = null): array {
        global $DB;

        $key = $id !== null ? ('id:' . $id) : ('idn:' . $idnumber);
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        if ($id !== null) {
            $cohort = $DB->get_record('cohort', ['id' => $id], 'id, contextid', IGNORE_MISSING);
            $matches = $cohort ? [$cohort] : [];
        } else {
            $matches = $DB->get_records('cohort', ['idnumber' => $idnumber], '', 'id, contextid');
        }
        if ($scope !== null) {
            $matches = array_filter($matches, fn($cohort) => self::in_subtree((int)$cohort->contextid, $scope));
        }

        if (count($matches) > 1) {
            $result = ['status' => 'ambiguous', 'id' => null];
        } else if (!$matches) {
            $result = ['status' => 'notfound', 'id' => null];
        } else {
            $cohort = reset($matches);
            $result = $scope !== null && !has_capability('moodle/cohort:assign', \context::instance_by_id($cohort->contextid))
                ? ['status' => 'nopermission', 'id' => null]
                : ['status' => 'found', 'id' => (int)$cohort->id];
        }

        $cache[$key] = $result;
        return $result;
    }

    /**
     * Whether a cohort context is the scope category itself or lies below it.
     * The capability is checked separately, in the cohort's own context as
     * core's cohort/assign.php does, so a prohibit on a subcategory is honoured.
     *
     * @param int $contextid The cohort's contextid.
     * @param \context_coursecat $scope
     * @return bool
     */
    private static function in_subtree(int $contextid, \context_coursecat $scope): bool {
        $context = \context::instance_by_id($contextid, IGNORE_MISSING);
        return $context && $context->is_child_of($scope, true);
    }
}

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
 * Cohort-sync enrolment detector.
 *
 * @package   local_cohortmembership
 * @copyright Thomas Korner <thomas.korner@edu.zh.ch>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cohortmembership\local;

/**
 * Detects whether removing a user from a cohort could trigger a course
 * unenrolment via an active cohort-sync enrolment method (enrol_cohort).
 *
 * Results are cached per cohortid for the lifetime of the request, since a
 * single CSV run can reference the same cohort in many rows.
 */
final class cohortsync_detector {
    /** @var array<int, int[]> cohortid => list of affected course ids */
    private static array $cache = [];

    /**
     * List the course ids that use the given cohort via an active
     * enrol_cohort instance.
     *
     * @param int $cohortid
     * @return int[]
     */
    public static function courses_using(int $cohortid): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/lib/enrollib.php');

        if (!array_key_exists($cohortid, self::$cache)) {
            // A site-wide disabled enrol_cohort plugin means none of its instances
            // actually run, regardless of their individual 'status' field.
            if (!enrol_is_enabled('cohort')) {
                self::$cache[$cohortid] = [];
                return self::$cache[$cohortid];
            }

            $courseids = $DB->get_fieldset_sql(
                'SELECT DISTINCT e.courseid
                   FROM {enrol} e
                  WHERE e.enrol = :enrol
                    AND e.status = :status
                    AND e.customint1 = :cohortid',
                ['enrol' => 'cohort', 'status' => ENROL_INSTANCE_ENABLED, 'cohortid' => $cohortid]
            );
            self::$cache[$cohortid] = array_map('intval', $courseids);
        }

        return self::$cache[$cohortid];
    }

    /**
     * Whether the given cohort is used by at least one active enrol_cohort instance.
     *
     * @param int $cohortid
     * @return bool
     */
    public static function uses(int $cohortid): bool {
        return self::courses_using($cohortid) !== [];
    }

    /**
     * Clear the per-cohort cache. Intended to scope the cache to a single
     * processing run (and to keep unit tests hermetic).
     *
     * @return void
     */
    public static function reset_cache(): void {
        self::$cache = [];
    }
}

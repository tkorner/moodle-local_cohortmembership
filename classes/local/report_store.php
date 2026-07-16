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
 * Report storage helper file.
 *
 * @package   local_cohortmembership
 * @copyright Thomas Korner <thomas.korner@edu.zh.ch>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cohortmembership\local;

/**
 * Persists the last run's report as a temp file instead of in $SESSION.
 *
 * A large CSV run (tens of thousands of rows) turns into a report array big
 * enough to bloat the session store and, depending on the session driver,
 * risk hitting memory limits or session-write contention. The report is
 * transient by nature (Post/Redirect/Get + one CSV download), so a per-session
 * temp file under $CFG->tempdir is a better fit than $SESSION: same one-report-
 * per-session lifetime, without ever holding the full array in session data.
 *
 * Keyed by sesskey() rather than userid, so two concurrent sessions for the
 * same user (e.g. two devices) each keep their own report instead of one
 * clobbering the other, matching how $SESSION itself would have scoped it.
 */
final class report_store {
    /** @var string Subdirectory under $CFG->tempdir. */
    private const COMPONENT = 'local_cohortmembership_report';

    /**
     * Save a report, overwriting any previous one for this session.
     *
     * @param string $key Caller-provided key, e.g. sesskey().
     * @param array $data
     * @return void
     */
    public static function save(string $key, array $data): void {
        $file = self::path($key);
        if ($file !== null) {
            file_put_contents($file, json_encode($data));
        }
    }

    /**
     * Load a previously saved report, if any.
     *
     * @param string $key
     * @return array|null
     */
    public static function load(string $key): ?array {
        $file = self::path($key);
        if ($file === null || !is_readable($file)) {
            return null;
        }
        $data = json_decode(file_get_contents($file), true);
        return is_array($data) ? $data : null;
    }

    /**
     * Delete a stored report, if any.
     *
     * @param string $key
     * @return void
     */
    public static function delete(string $key): void {
        $file = self::path($key);
        if ($file !== null) {
            @unlink($file);
        }
    }

    /**
     * Build the storage path for a key.
     *
     * @param string $key
     * @return string|null Null if the temp directory could not be created/is not writable.
     */
    private static function path(string $key): ?string {
        $dir = make_temp_directory(self::COMPONENT, false);
        if ($dir === false) {
            return null;
        }
        return $dir . '/' . clean_param($key, PARAM_ALPHANUM) . '.json';
    }
}

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
 * Processor file.
 *
 * @package   local_cohortmembership
 * @copyright Thomas Korner <thomas.korner@edu.zh.ch>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cohortmembership\local;

/**
 * Service class: processes username + cohort mappings and removes memberships.
 */
class processor {
    /**
     * Process rows and return results and counters.
     *
     * @param array $rows Each: ['username' => string, 'cohortid' => int|null, 'cohortidnumber' => string|null]
     * @param array $options Options: ['standardise' => bool, 'dryrun' => bool]
     * @return array ['results' => array, 'counters' => array]
     */
    public static function process(array $rows, array $options = []): array {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/cohort/lib.php');

        $standardise = !empty($options['standardise']);
        $dryrun      = !empty($options['dryrun']);

        $seenpairs = [];
        $results   = [];
        $counters  = ['total' => 0, 'valid' => 0, 'processed' => 0, 'skipped' => 0, 'errors' => 0];

        $transaction = $dryrun ? null : $DB->start_delegated_transaction();

        foreach ($rows as $r) {
            $counters['total']++;

            // Normalise input.
            $username = isset($r['username']) ? trim((string)$r['username']) : '';
            if ($standardise && $username !== '') {
                $username = \core_text::strtolower($username);
            }
            $cohortid       = $r['cohortid'] ?? null;
            $cohortidnumber = isset($r['cohortidnumber']) ? trim((string)$r['cohortidnumber']) : null;

            // Basic validation.
            if ($username === '' || ($cohortid === null && ($cohortidnumber === null || $cohortidnumber === ''))) {
                $results[] = ['username' => $username, 'cohortid' => $cohortid, 'cohortidnumber' => $cohortidnumber,
                    'status' => 'status_invalid'];
                $counters['errors']++;
                $counters['skipped']++;
                continue;
            }

            // De-duplicate within this run.
            $pairkey = $username . '|' . ($cohortid !== null ? ('id:' . $cohortid) : ('idn:' . $cohortidnumber));
            if (isset($seenpairs[$pairkey])) {
                $results[] = ['username' => $username, 'cohortid' => $cohortid, 'cohortidnumber' => $cohortidnumber,
                    'status' => 'status_duplicate'];
                $counters['errors']++;
                $counters['skipped']++;
                continue;
            }
            $seenpairs[$pairkey] = true;

            // Resolve user.
            $user = $DB->get_record('user', ['username' => $username, 'deleted' => 0], 'id', IGNORE_MISSING);
            if (!$user) {
                $results[] = ['username' => $username, 'cohortid' => $cohortid, 'cohortidnumber' => $cohortidnumber,
                    'status' => 'status_usernotfound'];
                $counters['errors']++;
                $counters['skipped']++;
                continue;
            }

            // Resolve cohort.
            if ($cohortid !== null) {
                $cohort = $DB->get_record('cohort', ['id' => $cohortid], 'id', IGNORE_MISSING);
            } else {
                $cohort = $DB->get_record('cohort', ['idnumber' => $cohortidnumber], 'id', IGNORE_MISSING);
            }
            if (!$cohort) {
                $results[] = ['username' => $username, 'cohortid' => $cohortid, 'cohortidnumber' => $cohortidnumber,
                    'status' => 'status_cohortnotfound'];
                $counters['errors']++;
                $counters['skipped']++;
                continue;
            }

            // Membership check.
            $ismember = $DB->record_exists('cohort_members', ['cohortid' => $cohort->id, 'userid' => $user->id]);
            if (!$ismember) {
                $results[] = ['username' => $username, 'cohortid' => $cohort->id, 'cohortidnumber' => $cohortidnumber ?? '',
                    'status' => 'status_notmember'];
                $counters['valid']++;
                $counters['skipped']++;
                continue;
            }

            // Remove membership (if not a dry run).
            if (!$dryrun) {
                cohort_remove_member($cohort->id, $user->id);
            }

            $results[] = ['username' => $username, 'cohortid' => $cohort->id, 'cohortidnumber' => $cohortidnumber ?? '',
                'status' => 'status_removed'];
            $counters['valid']++;
            $counters['processed']++;
        }

        if (!$dryrun) {
            $transaction->allow_commit();
        }

        return ['results' => $results, 'counters' => $counters];
    }
}

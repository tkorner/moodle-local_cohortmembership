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
 * Operation: sync.
 *
 * @package   local_cohortmembership
 * @copyright Thomas Korner <thomas.korner@edu.zh.ch>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cohortmembership\local;

/**
 * Brings each user's cohort memberships to the state listed in their 'sync'
 * rows, scoped to the file-wide "universe" of cohorts named anywhere in the
 * file (SPEC §3.2). Cohorts outside that universe are never touched, even if
 * the user is a member of one - there is no "full replace".
 */
final class operation_sync {
    /**
     * Process a pure-sync set of rows.
     *
     * @param array $rows Each: ['username' => string, 'cohortid' => int|null, 'cohortidnumber' => string|null,
     *                            'operation' => string]
     * @param array $options Options: ['standardise' => bool, 'dryrun' => bool]
     * @return array ['results' => array, 'counters' => array, 'legacy_format' => bool]
     */
    public static function process(array $rows, array $options = []): array {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/cohort/lib.php');

        $standardise = !empty($options['standardise']);
        $dryrun      = !empty($options['dryrun']);

        // Pass 1: normalise every row and resolve its cohort once. The union of
        // all cohorts that resolve anywhere in the file is the "universe" -
        // computed unconditionally, before we know whether each row's user exists.
        $parsed    = [];
        $universe  = [];
        foreach ($rows as $r) {
            $username = isset($r['username']) ? trim((string)$r['username']) : '';
            if ($standardise && $username !== '') {
                $username = \core_text::strtolower($username);
            }
            $cohortidin       = $r['cohortid'] ?? null;
            $cohortidnumberin = isset($r['cohortidnumber']) ? trim((string)$r['cohortidnumber']) : null;

            $invalid = ($username === '' || ($cohortidin === null && ($cohortidnumberin === null || $cohortidnumberin === '')));

            $cohort = null;
            if (!$invalid) {
                if ($cohortidin !== null) {
                    $cohort = $DB->get_record('cohort', ['id' => $cohortidin], 'id', IGNORE_MISSING) ?: null;
                } else {
                    $cohort = $DB->get_record('cohort', ['idnumber' => $cohortidnumberin], 'id', IGNORE_MISSING) ?: null;
                }
                if ($cohort) {
                    $universe[$cohort->id] = true;
                }
            }

            $parsed[] = [
                'username'          => $username,
                'cohortid_in'       => $cohortidin,
                'cohortidnumber_in' => $cohortidnumberin,
                'invalid'           => $invalid,
                'cohort'            => $cohort,
            ];
        }

        // Group by username, preserving each row's original position within its group.
        $groups = [];
        foreach ($parsed as $entry) {
            $groups[$entry['username']][] = $entry;
        }

        $results  = [];
        $counters = ['total' => 0, 'valid' => 0, 'processed' => 0, 'skipped' => 0, 'errors' => 0];

        $transaction = $dryrun ? null : $DB->start_delegated_transaction();

        foreach ($groups as $username => $entries) {
            // Structurally invalid rows never reach user/cohort resolution.
            $validentries = [];
            foreach ($entries as $entry) {
                $counters['total']++;
                if ($entry['invalid']) {
                    $results[] = self::rowresult($username, $entry, 'status_invalid');
                    $counters['errors']++;
                    $counters['skipped']++;
                    continue;
                }
                $validentries[] = $entry;
            }
            if (!$validentries) {
                continue;
            }

            // Per SPEC §6: resolve the user once per group; an unknown user fails all their rows.
            $user = false;
            if ($username !== '') {
                $user = $DB->get_record('user', ['username' => $username, 'deleted' => 0], 'id', IGNORE_MISSING);
            }
            if (!$user) {
                foreach ($validentries as $entry) {
                    $results[] = self::rowresult($username, $entry, 'status_usernotfound');
                    $counters['errors']++;
                    $counters['skipped']++;
                }
                continue;
            }

            // Resolve this user's target set, de-duplicating rows that repeat a cohort.
            $target      = [];
            $rowbycohort = [];
            $seen        = [];
            foreach ($validentries as $entry) {
                if (!$entry['cohort']) {
                    $results[] = self::rowresult($username, $entry, 'status_cohortnotfound');
                    $counters['errors']++;
                    $counters['skipped']++;
                    continue;
                }
                $cohortid = $entry['cohort']->id;
                if (isset($seen[$cohortid])) {
                    $results[] = self::rowresult($username, $entry, 'status_duplicate');
                    $counters['errors']++;
                    $counters['skipped']++;
                    continue;
                }
                $seen[$cohortid]        = true;
                $target[$cohortid]      = true;
                $rowbycohort[$cohortid] = $entry;
            }

            // Scope rule: only cohorts named somewhere in the file may ever be touched.
            $currentids = $DB->get_fieldset_select('cohort_members', 'cohortid', 'userid = :userid', ['userid' => $user->id]);
            $current    = array_intersect_key(array_fill_keys(array_map('intval', $currentids), true), $universe);

            $toadd     = array_diff_key($target, $current);
            $unchanged = array_intersect_key($target, $current);
            $toremove  = array_diff_key($current, $target);

            foreach ($toadd as $cohortid => $unused) {
                if (!$dryrun) {
                    cohort_add_member($cohortid, $user->id);
                }
                $results[] = self::rowresult($username, $rowbycohort[$cohortid], 'status_added');
                $counters['valid']++;
                $counters['processed']++;
            }

            foreach ($unchanged as $cohortid => $unused) {
                $results[] = self::rowresult($username, $rowbycohort[$cohortid], 'status_alreadymember');
                $counters['valid']++;
                $counters['skipped']++;
            }

            // Cohorts in-universe and currently held, but not listed in any of this
            // user's rows: removed. These have no source row, so the report gets a
            // synthetic line for them (still one report line per action taken).
            foreach ($toremove as $cohortid => $unused) {
                $warning = cohortsync_detector::uses($cohortid);
                if (!$dryrun) {
                    cohort_remove_member($cohortid, $user->id);
                }
                $results[] = [
                    'username'           => $username,
                    'cohortid'           => $cohortid,
                    'cohortidnumber'     => '',
                    'operation'          => 'sync',
                    'status'             => 'status_removed',
                    'cohortsync_warning' => $warning,
                ];
                $counters['total']++;
                $counters['valid']++;
                $counters['processed']++;
            }
        }

        if (!$dryrun) {
            $transaction->allow_commit();
        }

        return ['results' => $results, 'counters' => $counters, 'legacy_format' => false];
    }

    /**
     * Build a report row for a parsed sync entry.
     *
     * @param string $username
     * @param array $entry
     * @param string $status
     * @return array
     */
    private static function rowresult(string $username, array $entry, string $status): array {
        return [
            'username'           => $username,
            'cohortid'           => $entry['cohort'] ? $entry['cohort']->id : $entry['cohortid_in'],
            'cohortidnumber'     => $entry['cohortidnumber_in'] ?? '',
            'operation'          => 'sync',
            'status'             => $status,
            'cohortsync_warning' => false,
        ];
    }
}

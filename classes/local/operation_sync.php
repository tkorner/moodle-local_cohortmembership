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
     *                            'operation' => string, 'cohortid_invalid' => bool|null]
     * @param array $options Options: ['standardise' => bool, 'dryrun' => bool]
     * @return array ['results' => array, 'counters' => array, 'legacy_format' => bool]
     */
    public static function process(array $rows, array $options = []): array {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/cohort/lib.php');

        $standardise = !empty($options['standardise']);
        $dryrun      = !empty($options['dryrun']);

        $cohortcache = [];
        [$parsed, $universe] = self::parse_rows($rows, $standardise, $cohortcache);

        // Group by username, preserving each row's original position within its group.
        $groups = [];
        foreach ($parsed as $entry) {
            $groups[$entry['username']][] = $entry;
        }

        $results  = [];
        $counters = ['total' => 0, 'valid' => 0, 'processed' => 0, 'skipped' => 0, 'errors' => 0];

        $transaction = $dryrun ? null : $DB->start_delegated_transaction();

        foreach ($groups as $username => $entries) {
            self::process_group($username, $entries, $universe, $dryrun, $results, $counters);
        }

        if (!$dryrun) {
            $transaction->allow_commit();
        }

        return ['results' => $results, 'counters' => $counters, 'legacy_format' => false];
    }

    /**
     * Pass 1: normalise every row and resolve its cohort once. The union of
     * all cohorts that resolve anywhere in the file is the "universe" -
     * computed unconditionally, before we know whether each row's user exists.
     * An ambiguous cohort idnumber is never guessed at, so it never joins the
     * universe (SPEC §10: "nicht raten").
     *
     * @param array $rows
     * @param bool $standardise
     * @param array $cohortcache Memoisation cache for cohort_resolver, shared across all rows in this run.
     * @return array [array $parsed, array $universe] universe is cohortid => true.
     */
    private static function parse_rows(array $rows, bool $standardise, array &$cohortcache): array {
        $parsed   = [];
        $universe = [];

        foreach ($rows as $r) {
            $parsed[] = self::parse_row($r, $standardise, $cohortcache, $universe);
        }

        return [$parsed, $universe];
    }

    /**
     * Normalise and resolve a single sync row. Adds its cohort to $universe
     * (passed by reference) when it resolves unambiguously.
     *
     * @param array $r Raw row.
     * @param bool $standardise
     * @param array $cohortcache Memoisation cache for cohort_resolver.
     * @param array $universe cohortid => true, built up across all rows.
     * @return array A parsed entry (see parse_rows()).
     */
    private static function parse_row(array $r, bool $standardise, array &$cohortcache, array &$universe): array {
        $username = isset($r['username']) ? trim((string)$r['username']) : '';
        if ($standardise && $username !== '') {
            $username = \core_text::strtolower($username);
        }
        // A malformed cohortid cell (present column, non-numeric/blank value) is
        // always invalid: it must never silently fall back to cohortidnumber.
        $cohortidmalformed = !empty($r['cohortid_invalid']);
        $cohortidin        = $cohortidmalformed ? null : ($r['cohortid'] ?? null);
        $cohortidnumberin  = isset($r['cohortidnumber']) ? trim((string)$r['cohortidnumber']) : null;

        $invalid = $cohortidmalformed || $username === ''
            || ($cohortidin === null && ($cohortidnumberin === null || $cohortidnumberin === ''));

        $cohortid  = null;
        $ambiguous = false;
        if (!$invalid) {
            $resolved = cohort_resolver::resolve($cohortidin, $cohortidnumberin, $cohortcache);
            if ($resolved['status'] === 'ambiguous') {
                $ambiguous = true;
            } else if ($resolved['status'] === 'found') {
                $cohortid = $resolved['id'];
                $universe[$cohortid] = true;
            }
        }

        return [
            'username'          => $username,
            'cohortid_in'       => $cohortidin,
            'cohortidnumber_in' => $cohortidnumberin,
            'invalid'           => $invalid,
            'ambiguous'         => $ambiguous,
            'cohortid'          => $cohortid,
        ];
    }

    /**
     * Process one user's group of sync rows: resolve the user, compute their
     * target cohort set, and apply add/remove against the file-wide universe.
     * Appends to $results and $counters (passed by reference).
     *
     * @param string $username
     * @param array $entries Parsed rows for this user, from parse_rows().
     * @param array $universe cohortid => true, every cohort named anywhere in the file.
     * @param bool $dryrun
     * @param array $results
     * @param array $counters
     * @return void
     */
    private static function process_group(
        string $username,
        array $entries,
        array $universe,
        bool $dryrun,
        array &$results,
        array &$counters
    ): void {
        global $DB;

        $validentries = self::filter_valid_entries($username, $entries, $results, $counters);
        if (!$validentries) {
            return;
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
            return;
        }

        [$target, $rowbycohort] = self::resolve_target_set($username, $validentries, $results, $counters);

        // Scope rule: only cohorts named somewhere in the file may ever be touched.
        $currentids = $DB->get_fieldset_select('cohort_members', 'cohortid', 'userid = :userid', ['userid' => $user->id]);
        $current    = array_intersect_key(array_fill_keys(array_map('intval', $currentids), true), $universe);

        self::apply_diff((int)$user->id, $username, $target, $current, $rowbycohort, $dryrun, $results, $counters);
    }

    /**
     * Split one user's rows into structurally invalid/ambiguous (reported
     * immediately) and valid (returned for further processing).
     *
     * @param string $username
     * @param array $entries
     * @param array $results
     * @param array $counters
     * @return array Valid entries only.
     */
    private static function filter_valid_entries(string $username, array $entries, array &$results, array &$counters): array {
        $validentries = [];
        foreach ($entries as $entry) {
            $counters['total']++;
            if ($entry['invalid']) {
                $results[] = self::rowresult($username, $entry, 'status_invalid');
                $counters['errors']++;
                $counters['skipped']++;
            } else if ($entry['ambiguous']) {
                $results[] = self::rowresult($username, $entry, 'status_cohortambiguous');
                $counters['errors']++;
                $counters['skipped']++;
            } else {
                $validentries[] = $entry;
            }
        }
        return $validentries;
    }

    /**
     * Resolve a user's target cohort set, de-duplicating rows that repeat a
     * cohort. A duplicate is a benign skip, not an error - a repeated line
     * should never trip cron/monitoring that treats an error count as fatal.
     *
     * @param string $username
     * @param array $validentries
     * @param array $results
     * @param array $counters
     * @return array [array $target cohortid => true, array $rowbycohort cohortid => entry]
     */
    private static function resolve_target_set(string $username, array $validentries, array &$results, array &$counters): array {
        $target      = [];
        $rowbycohort = [];
        $seen        = [];
        foreach ($validentries as $entry) {
            if ($entry['cohortid'] === null) {
                $results[] = self::rowresult($username, $entry, 'status_cohortnotfound');
                $counters['errors']++;
                $counters['skipped']++;
                continue;
            }
            $cohortid = $entry['cohortid'];
            if (isset($seen[$cohortid])) {
                $results[] = self::rowresult($username, $entry, 'status_duplicate');
                $counters['skipped']++;
                continue;
            }
            $seen[$cohortid]        = true;
            $target[$cohortid]      = true;
            $rowbycohort[$cohortid] = $entry;
        }
        return [$target, $rowbycohort];
    }

    /**
     * Apply the add/unchanged/remove diff between the target and current
     * cohort sets for one user.
     *
     * @param int $userid
     * @param string $username
     * @param array $target cohortid => true.
     * @param array $current cohortid => true, already scoped to the universe.
     * @param array $rowbycohort cohortid => source entry, for add/unchanged rows.
     * @param bool $dryrun
     * @param array $results
     * @param array $counters
     * @return void
     */
    private static function apply_diff(
        int $userid,
        string $username,
        array $target,
        array $current,
        array $rowbycohort,
        bool $dryrun,
        array &$results,
        array &$counters
    ): void {
        $toadd     = array_diff_key($target, $current);
        $unchanged = array_intersect_key($target, $current);
        $toremove  = array_diff_key($current, $target);

        foreach ($toadd as $cohortid => $unused) {
            if (!$dryrun) {
                cohort_add_member($cohortid, $userid);
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
        // synthetic line for them (still one report line per action taken). Not
        // counted in 'total': that counter reflects actual file rows, not actions.
        foreach ($toremove as $cohortid => $unused) {
            $warning = cohortsync_detector::uses($cohortid);
            if (!$dryrun) {
                cohort_remove_member($cohortid, $userid);
            }
            $results[] = [
                'username'           => $username,
                'cohortid'           => $cohortid,
                'cohortidnumber'     => '',
                'operation'          => 'sync',
                'status'             => 'status_removed',
                'cohortsync_warning' => $warning,
            ];
            $counters['valid']++;
            $counters['processed']++;
        }
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
            'cohortid'           => $entry['cohortid'] ?? $entry['cohortid_in'],
            'cohortidnumber'     => $entry['cohortidnumber_in'] ?? '',
            'operation'          => 'sync',
            'status'             => $status,
            'cohortsync_warning' => false,
        ];
    }
}

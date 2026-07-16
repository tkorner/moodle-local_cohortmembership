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
 * Service class: resolves username + cohort mappings and dispatches to the
 * operation (add/del/sync) handler.
 */
class processor {
    /**
     * Process rows and return results and counters.
     *
     * A CSV without an 'operation' column is treated as legacy: every row is
     * processed as 'del' (status quo of the old cohortunenroller plugin).
     *
     * A file whose rows are entirely 'sync' is processed per-user against the
     * file-wide cohort universe (see operation_sync). Any other mix
     * (including 'sync' mixed with add/del) fails the file-level validation
     * below and is rejected before any row is touched.
     *
     * @param array $rows Each: ['username' => string, 'cohortid' => int|null, 'cohortidnumber' => string|null,
     *                            'operation' => string|null, 'cohortid_invalid' => bool|null]
     * @param array $options Options: ['standardise' => bool, 'dryrun' => bool]
     * @return array ['results' => array, 'counters' => array, 'legacy_format' => bool,
     *                 'cohortidnumber_ignored' => bool, 'validation_error' => string|null]
     */
    public static function process(array $rows, array $options = []): array {
        global $CFG;
        require_once($CFG->dirroot . '/cohort/lib.php');

        if (!$rows) {
            return self::rejected($rows, false, 'error_empty_file');
        }

        $analysis = self::analyse_file($rows);
        if ($analysis['error'] !== null) {
            return self::rejected($rows, $analysis['legacy_format'], $analysis['error']);
        }

        if ($analysis['issync']) {
            $payload = operation_sync::process($rows, $options);
        } else {
            $payload = self::process_delta($rows, $analysis['legacy_format'], $options);
        }

        $payload['cohortidnumber_ignored'] = $analysis['cohortidnumber_ignored'];
        $payload['validation_error'] = null;
        return $payload;
    }

    /**
     * File-level analysis: legacy-format detection, cohort-column presence,
     * and the sync/delta mix guard. Never touches the database.
     *
     * @param array $rows
     * @return array ['legacy_format' => bool, 'cohortidnumber_ignored' => bool,
     *                 'issync' => bool, 'error' => string|null]
     */
    private static function analyse_file(array $rows): array {
        // No row carries an 'operation' key at all -> legacy CSV, treat everything as 'del'.
        $legacyformat = true;
        foreach ($rows as $r) {
            if (array_key_exists('operation', $r)) {
                $legacyformat = false;
                break;
            }
        }

        // A column is "present" if at least one row carries its key, regardless of value -
        // mirrors how the CSV importer only adds a key for columns that exist in the header.
        $hascohortid = false;
        $hascohortidnumber = false;
        foreach ($rows as $r) {
            $hascohortid = $hascohortid || array_key_exists('cohortid', $r) || array_key_exists('cohortid_invalid', $r);
            $hascohortidnumber = $hascohortidnumber || array_key_exists('cohortidnumber', $r);
        }
        if (!$hascohortid && !$hascohortidnumber) {
            return self::analysis($legacyformat, false, false, 'error_missing_cohort_column');
        }

        $ops = [];
        if (!$legacyformat) {
            foreach ($rows as $r) {
                $ops[\core_text::strtolower(trim((string)($r['operation'] ?? '')))] = true;
            }
            if (isset($ops['sync']) && count($ops) > 1) {
                return self::analysis($legacyformat, false, false, 'error_mixed_operations');
            }
        }

        $issync = count($ops) === 1 && isset($ops['sync']);
        return self::analysis($legacyformat, $hascohortid && $hascohortidnumber, $issync, null);
    }

    /**
     * Build an analyse_file() result array.
     *
     * @param bool $legacyformat
     * @param bool $cohortidnumberignored
     * @param bool $issync
     * @param string|null $error
     * @return array
     */
    private static function analysis(bool $legacyformat, bool $cohortidnumberignored, bool $issync, ?string $error): array {
        return [
            'legacy_format' => $legacyformat,
            'cohortidnumber_ignored' => $cohortidnumberignored,
            'issync' => $issync,
            'error' => $error,
        ];
    }

    /**
     * Build the payload for a file that fails file-level validation: nothing
     * is processed, no row in the file is touched.
     *
     * @param array $rows
     * @param bool $legacyformat
     * @param string $error Lang string identifier describing the failure.
     * @return array
     */
    private static function rejected(array $rows, bool $legacyformat, string $error): array {
        return [
            'results' => [],
            'counters' => ['total' => count($rows), 'valid' => 0, 'processed' => 0, 'skipped' => 0, 'errors' => 0],
            'legacy_format' => $legacyformat,
            'cohortidnumber_ignored' => false,
            'validation_error' => $error,
        ];
    }

    /**
     * Process rows one at a time as add/del (or legacy del) operations.
     *
     * @param array $rows
     * @param bool $legacyformat
     * @param array $options
     * @return array ['results' => array, 'counters' => array, 'legacy_format' => bool]
     */
    private static function process_delta(array $rows, bool $legacyformat, array $options): array {
        global $DB;

        $standardise = !empty($options['standardise']);
        $dryrun      = !empty($options['dryrun']);

        $seenpairs   = [];
        $usercache   = [];
        $cohortcache = [];
        $results     = [];
        $counters    = ['total' => 0, 'valid' => 0, 'processed' => 0, 'skipped' => 0, 'errors' => 0];

        $transaction = $dryrun ? null : $DB->start_delegated_transaction();

        foreach ($rows as $r) {
            $counters['total']++;
            $row = self::normalise_delta_row($r, $legacyformat, $standardise);

            if (!$row['valid']) {
                $results[] = self::deltarow($row, 'status_invalid');
                $counters['errors']++;
                $counters['skipped']++;
                continue;
            }

            // De-duplicate within this run (same operation + same username/cohort pair).
            // A duplicate is a benign skip, not an error - a repeated line should
            // never trip cron/monitoring that treats an error count as fatal.
            $pairkey = $row['operation'] . '|' . $row['username'] . '|' .
                ($row['cohortid'] !== null ? ('id:' . $row['cohortid']) : ('idn:' . $row['cohortidnumber']));
            if (isset($seenpairs[$pairkey])) {
                $results[] = self::deltarow($row, 'status_duplicate');
                $counters['skipped']++;
                continue;
            }
            $seenpairs[$pairkey] = true;

            $outcome = self::resolve_and_dispatch($row, $usercache, $cohortcache, $dryrun);
            $row['cohortid'] = $outcome['cohortid'];
            $status = $outcome['status'];
            $results[] = self::deltarow($row, $status, $outcome['cohortsync_warning'] ?? false);

            if (in_array($status, ['status_removed', 'status_added'], true)) {
                $counters['valid']++;
                $counters['processed']++;
            } else if (in_array($status, ['status_notmember', 'status_alreadymember'], true)) {
                $counters['valid']++;
                $counters['skipped']++;
            } else {
                // Covers usernotfound, cohortnotfound, cohortambiguous, error_bad_operation.
                $counters['errors']++;
                $counters['skipped']++;
            }
        }

        if (!$dryrun) {
            $transaction->allow_commit();
        }

        return ['results' => $results, 'counters' => $counters, 'legacy_format' => $legacyformat];
    }

    /**
     * Normalise one delta row's input fields.
     *
     * @param array $r Raw row.
     * @param bool $legacyformat
     * @param bool $standardise
     * @return array ['username' => string, 'cohortid' => int|null, 'cohortidnumber' => string|null,
     *                 'operation' => string, 'valid' => bool]
     */
    private static function normalise_delta_row(array $r, bool $legacyformat, bool $standardise): array {
        $username = isset($r['username']) ? trim((string)$r['username']) : '';
        if ($standardise && $username !== '') {
            $username = \core_text::strtolower($username);
        }
        $cohortidmalformed = !empty($r['cohortid_invalid']);
        $cohortid          = $cohortidmalformed ? null : ($r['cohortid'] ?? null);
        $cohortidnumber    = isset($r['cohortidnumber']) ? trim((string)$r['cohortidnumber']) : null;
        $operation         = $legacyformat ? 'del' : \core_text::strtolower(trim((string)($r['operation'] ?? '')));

        // A malformed cohortid cell (present column, non-numeric/blank value) is
        // always invalid: it must never silently fall back to cohortidnumber,
        // which would contradict the "cohortid always wins" precedence rule.
        $hastarget = !$cohortidmalformed && ($cohortid !== null || ($cohortidnumber !== null && $cohortidnumber !== ''));
        $valid     = $username !== '' && $hastarget;

        return [
            'username'       => $username,
            'cohortid'       => $cohortid,
            'cohortidnumber' => $cohortidnumber,
            'operation'      => $operation,
            'valid'          => $valid,
        ];
    }

    /**
     * Resolve the user and cohort for a normalised row and dispatch to the
     * operation handler.
     *
     * @param array $row From normalise_delta_row().
     * @param array $usercache Memoisation cache for username -> userid|false.
     * @param array $cohortcache Memoisation cache for cohort_resolver.
     * @param bool $dryrun
     * @return array ['status' => string, 'cohortid' => int|null, 'cohortsync_warning' => bool]
     */
    private static function resolve_and_dispatch(array $row, array &$usercache, array &$cohortcache, bool $dryrun): array {
        global $DB;

        $username = $row['username'];
        if (!array_key_exists($username, $usercache)) {
            $user = $DB->get_record('user', ['username' => $username, 'deleted' => 0], 'id', IGNORE_MISSING);
            $usercache[$username] = $user ? (int)$user->id : false;
        }
        $userid = $usercache[$username];
        if ($userid === false) {
            return ['status' => 'status_usernotfound', 'cohortid' => $row['cohortid']];
        }

        $cohort = cohort_resolver::resolve($row['cohortid'], $row['cohortidnumber'], $cohortcache);
        if ($cohort['status'] === 'ambiguous') {
            return ['status' => 'status_cohortambiguous', 'cohortid' => $row['cohortid']];
        }
        if ($cohort['status'] === 'notfound') {
            return ['status' => 'status_cohortnotfound', 'cohortid' => $row['cohortid']];
        }

        switch ($row['operation']) {
            case 'del':
                $opresult = operation_del::execute($cohort['id'], $userid, $dryrun);
                break;
            case 'add':
                $opresult = operation_add::execute($cohort['id'], $userid, $dryrun);
                break;
            default:
                return ['status' => 'error_bad_operation', 'cohortid' => $cohort['id']];
        }

        return [
            'status'             => $opresult['status'],
            'cohortid'           => $cohort['id'],
            'cohortsync_warning' => $opresult['cohortsync_warning'] ?? false,
        ];
    }

    /**
     * Build a report row for a normalised delta row.
     *
     * @param array $row From normalise_delta_row().
     * @param string $status
     * @param bool $warning
     * @return array
     */
    private static function deltarow(array $row, string $status, bool $warning = false): array {
        return [
            'username'           => $row['username'],
            'cohortid'           => $row['cohortid'],
            'cohortidnumber'     => $row['cohortidnumber'] ?? '',
            'operation'          => $row['operation'],
            'status'             => $status,
            'cohortsync_warning' => $warning,
        ];
    }
}

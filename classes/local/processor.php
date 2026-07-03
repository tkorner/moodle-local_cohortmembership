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
 * Service class: resolves username + cohort mappings and dispatches each row
 * to the operation (add/del/sync) handler.
 */
class processor {
    /**
     * Process rows and return results and counters.
     *
     * A CSV without an 'operation' column is treated as legacy: every row is
     * processed as 'del' (status quo of the old cohortunenroller plugin).
     *
     * @param array $rows Each: ['username' => string, 'cohortid' => int|null, 'cohortidnumber' => string|null,
     *                            'operation' => string|null]
     * @param array $options Options: ['standardise' => bool, 'dryrun' => bool]
     * @return array ['results' => array, 'counters' => array, 'legacy_format' => bool]
     */
    public static function process(array $rows, array $options = []): array {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/cohort/lib.php');

        $standardise = !empty($options['standardise']);
        $dryrun      = !empty($options['dryrun']);

        // No row carries an 'operation' key at all -> legacy CSV, treat everything as 'del'.
        $legacyformat = true;
        foreach ($rows as $r) {
            if (array_key_exists('operation', $r)) {
                $legacyformat = false;
                break;
            }
        }

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
            $operation      = $legacyformat ? 'del' : \core_text::strtolower(trim((string)($r['operation'] ?? '')));

            // Basic validation.
            if ($username === '' || ($cohortid === null && ($cohortidnumber === null || $cohortidnumber === ''))) {
                $results[] = ['username' => $username, 'cohortid' => $cohortid, 'cohortidnumber' => $cohortidnumber,
                    'operation' => $operation, 'status' => 'status_invalid'];
                $counters['errors']++;
                $counters['skipped']++;
                continue;
            }

            // De-duplicate within this run (same operation + same username/cohort pair).
            $pairkey = $operation . '|' . $username . '|' . ($cohortid !== null ? ('id:' . $cohortid) : ('idn:' . $cohortidnumber));
            if (isset($seenpairs[$pairkey])) {
                $results[] = ['username' => $username, 'cohortid' => $cohortid, 'cohortidnumber' => $cohortidnumber,
                    'operation' => $operation, 'status' => 'status_duplicate'];
                $counters['errors']++;
                $counters['skipped']++;
                continue;
            }
            $seenpairs[$pairkey] = true;

            // Resolve user.
            $user = $DB->get_record('user', ['username' => $username, 'deleted' => 0], 'id', IGNORE_MISSING);
            if (!$user) {
                $results[] = ['username' => $username, 'cohortid' => $cohortid, 'cohortidnumber' => $cohortidnumber,
                    'operation' => $operation, 'status' => 'status_usernotfound'];
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
                    'operation' => $operation, 'status' => 'status_cohortnotfound'];
                $counters['errors']++;
                $counters['skipped']++;
                continue;
            }

            // Dispatch to the operation handler.
            switch ($operation) {
                case 'del':
                    $opresult = operation_del::execute($cohort->id, $user->id, $dryrun);
                    break;
                case 'add':
                    $opresult = operation_add::execute($cohort->id, $user->id, $dryrun);
                    break;
                default:
                    $opresult = ['status' => 'error_bad_operation'];
            }

            $results[] = ['username' => $username, 'cohortid' => $cohort->id, 'cohortidnumber' => $cohortidnumber ?? '',
                'operation' => $operation, 'status' => $opresult['status']];

            if (in_array($opresult['status'], ['status_removed', 'status_added'], true)) {
                $counters['valid']++;
                $counters['processed']++;
            } else if (in_array($opresult['status'], ['status_notmember', 'status_alreadymember'], true)) {
                $counters['valid']++;
                $counters['skipped']++;
            } else {
                // error_bad_operation and any future unhandled status.
                $counters['errors']++;
                $counters['skipped']++;
            }
        }

        if (!$dryrun) {
            $transaction->allow_commit();
        }

        return ['results' => $results, 'counters' => $counters, 'legacy_format' => $legacyformat];
    }
}

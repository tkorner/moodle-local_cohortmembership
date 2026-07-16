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
 * Edge case tests: mixed operations, username standardisation, duplicate/invalid
 * rows, ambiguous cohort idnumbers, and the cohortid/cohortidnumber precedence
 * rule (code review 2026-07-16, findings 3 and 9).
 *
 * @package   local_cohortmembership
 * @copyright Thomas Korner <thomas.korner@edu.zh.ch>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cohortmembership;

use local_cohortmembership\local\cohortsync_detector;
use local_cohortmembership\local\processor;

/**
 * Class processor_edge_cases_test
 */
final class processor_edge_cases_test extends \advanced_testcase {
    /**
     * Reset the detector's static cache before each test.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        cohortsync_detector::reset_cache();
    }

    /**
     * A file mixing 'add' and 'del' rows (no 'sync') is not a mix-guard
     * violation - only sync mixed with delta is rejected.
     *
     * @covers \local_cohortmembership\local\processor::process
     * @return void
     */
    public function test_add_and_del_in_same_file_is_allowed(): void {
        $this->resetAfterTest(true);

        $alice = $this->getDataGenerator()->create_user(['username' => 'alice']);
        $bob = $this->getDataGenerator()->create_user(['username' => 'bob']);
        $c1 = $this->getDataGenerator()->create_cohort(['idnumber' => 'kurs-inf-2026']);
        $c2 = $this->getDataGenerator()->create_cohort(['idnumber' => 'kurs-bwl-2023']);
        cohort_add_member($c2->id, $bob->id);

        $rows = [
            ['username' => 'alice', 'cohortidnumber' => 'kurs-inf-2026', 'operation' => 'add'],
            ['username' => 'bob', 'cohortidnumber' => 'kurs-bwl-2023', 'operation' => 'del'],
        ];

        $payload = processor::process($rows, ['dryrun' => false]);

        $this->assertNull($payload['validation_error']);
        $this->assertTrue($this->record_exists('cohort_members', ['cohortid' => $c1->id, 'userid' => $alice->id]));
        $this->assertFalse($this->record_exists('cohort_members', ['cohortid' => $c2->id, 'userid' => $bob->id]));
    }

    /**
     * With 'standardise' enabled, a username differing only in case/whitespace
     * still matches (Moodle usernames are effectively case-insensitive).
     *
     * @covers \local_cohortmembership\local\processor::process
     * @return void
     */
    public function test_standardise_matches_username_regardless_of_case(): void {
        $this->resetAfterTest(true);

        $user = $this->getDataGenerator()->create_user(['username' => 'alice']);
        $cohort = $this->getDataGenerator()->create_cohort(['idnumber' => 'cohortZ']);

        $rows = [
            ['username' => '  Alice  ', 'cohortidnumber' => 'cohortZ', 'operation' => 'add'],
        ];

        $payload = processor::process($rows, ['standardise' => true, 'dryrun' => false]);

        $this->assertSame('status_added', $payload['results'][0]['status']);
        $this->assertTrue($this->record_exists('cohort_members', ['cohortid' => $cohort->id, 'userid' => $user->id]));
    }

    /**
     * The same operation/username/cohort pair repeated in one file is a
     * benign skip (status_duplicate), not an error - it must not count
     * towards counters['errors'] (would trip cron/monitoring on exit code).
     *
     * @covers \local_cohortmembership\local\processor::process
     * @return void
     */
    public function test_duplicate_row_is_skipped_not_errored(): void {
        $this->resetAfterTest(true);

        $this->getDataGenerator()->create_user(['username' => 'alice']);
        $this->getDataGenerator()->create_cohort(['idnumber' => 'cohortZ']);

        $rows = [
            ['username' => 'alice', 'cohortidnumber' => 'cohortZ', 'operation' => 'add'],
            ['username' => 'alice', 'cohortidnumber' => 'cohortZ', 'operation' => 'add'],
        ];

        $payload = processor::process($rows, ['dryrun' => false]);

        $this->assertSame('status_duplicate', $payload['results'][1]['status']);
        $this->assertSame(0, $payload['counters']['errors']);
        $this->assertSame(1, $payload['counters']['skipped']);
    }

    /**
     * A row missing its username is invalid, independent of the operation.
     *
     * @covers \local_cohortmembership\local\processor::process
     * @return void
     */
    public function test_row_without_username_is_invalid(): void {
        $this->resetAfterTest(true);

        $this->getDataGenerator()->create_cohort(['idnumber' => 'cohortZ']);

        $rows = [
            ['username' => '', 'cohortidnumber' => 'cohortZ', 'operation' => 'add'],
        ];

        $payload = processor::process($rows, ['dryrun' => false]);

        $this->assertSame('status_invalid', $payload['results'][0]['status']);
        $this->assertSame(1, $payload['counters']['errors']);
    }

    /**
     * A file with zero data rows (header only, or truly empty) is a distinct
     * validation error from "missing cohort column" - there is nothing to
     * inspect columns on.
     *
     * @covers \local_cohortmembership\local\processor::process
     * @return void
     */
    public function test_empty_file_is_rejected_distinctly(): void {
        $this->resetAfterTest(true);

        $payload = processor::process([], ['dryrun' => false]);

        $this->assertSame('error_empty_file', $payload['validation_error']);
    }

    /**
     * When the file has a 'cohortid' column, a row whose cohortid cell is
     * non-numeric/blank must be invalid, never silently resolved via that
     * row's 'cohortidnumber' value - that would contradict the file-level
     * "cohortid always wins" precedence rule (code review finding 9).
     *
     * @covers \local_cohortmembership\local\processor::process
     * @return void
     */
    public function test_malformed_cohortid_cell_is_invalid_not_fallback_to_idnumber(): void {
        $this->resetAfterTest(true);

        $this->getDataGenerator()->create_user(['username' => 'alice']);
        // A real cohort exists at this idnumber - if the fallback bug were
        // still present, this row would resolve through it and succeed.
        $this->getDataGenerator()->create_cohort(['idnumber' => 'cohortZ']);

        $rows = [
            ['username' => 'alice', 'cohortid_invalid' => true, 'cohortidnumber' => 'cohortZ', 'operation' => 'add'],
        ];

        $payload = processor::process($rows, ['dryrun' => false]);

        $this->assertSame('status_invalid', $payload['results'][0]['status']);
    }

    /**
     * Same precedence rule, but on the sync path: a malformed cohortid cell
     * must not resolve via cohortidnumber, and must not join the universe.
     *
     * @covers \local_cohortmembership\local\operation_sync::process
     * @return void
     */
    public function test_malformed_cohortid_cell_is_invalid_in_sync_path(): void {
        $this->resetAfterTest(true);

        $user = $this->getDataGenerator()->create_user(['username' => 'hans']);
        $cohort = $this->getDataGenerator()->create_cohort(['idnumber' => 'kurs-inf-2026']);
        cohort_add_member($cohort->id, $user->id);

        $rows = [
            ['username' => 'hans', 'cohortid_invalid' => true, 'cohortidnumber' => 'kurs-inf-2026', 'operation' => 'sync'],
        ];

        $payload = processor::process($rows, ['dryrun' => false]);

        $this->assertSame('status_invalid', $payload['results'][0]['status']);
        // The cohort must never have joined the universe via the malformed row,
        // so hans's pre-existing membership is left untouched.
        $this->assertTrue($this->record_exists('cohort_members', ['cohortid' => $cohort->id, 'userid' => $user->id]));
    }

    /**
     * An idnumber shared by cohorts in two different contexts must never be
     * guessed at (SPEC §10: "nicht raten") - it is reported as ambiguous
     * instead of silently writing to whichever cohort get_record() happens
     * to return (code review finding 3).
     *
     * @covers \local_cohortmembership\local\processor::process
     * @return void
     */
    public function test_ambiguous_cohort_idnumber_is_reported_not_guessed(): void {
        global $DB;
        $this->resetAfterTest(true);

        $this->getDataGenerator()->create_user(['username' => 'alice']);

        $category = $this->getDataGenerator()->create_category();
        $catcontext = \context_coursecat::instance($category->id);

        $cohort1 = $this->getDataGenerator()->create_cohort(['idnumber' => 'dup-id']);
        $cohort2 = $this->getDataGenerator()->create_cohort(['idnumber' => 'dup-id', 'contextid' => $catcontext->id]);

        $rows = [
            ['username' => 'alice', 'cohortidnumber' => 'dup-id', 'operation' => 'add'],
        ];

        $payload = processor::process($rows, ['dryrun' => false]);

        $this->assertSame('status_cohortambiguous', $payload['results'][0]['status']);
        $this->assertFalse($DB->record_exists('cohort_members', ['cohortid' => $cohort1->id]));
        $this->assertFalse($DB->record_exists('cohort_members', ['cohortid' => $cohort2->id]));
    }

    /**
     * Same ambiguity rule on the sync path: an ambiguous idnumber is reported
     * per-row and never joins the file-wide universe (an ambiguous cohort
     * can never safely be added to or removed from).
     *
     * @covers \local_cohortmembership\local\operation_sync::process
     * @return void
     */
    public function test_ambiguous_cohort_idnumber_in_sync_path(): void {
        $this->resetAfterTest(true);

        $user = $this->getDataGenerator()->create_user(['username' => 'hans']);

        $category = $this->getDataGenerator()->create_category();
        $catcontext = \context_coursecat::instance($category->id);
        $this->getDataGenerator()->create_cohort(['idnumber' => 'dup-id']);
        $this->getDataGenerator()->create_cohort(['idnumber' => 'dup-id', 'contextid' => $catcontext->id]);

        $rows = [
            ['username' => 'hans', 'cohortidnumber' => 'dup-id', 'operation' => 'sync'],
        ];

        $payload = processor::process($rows, ['dryrun' => false]);

        $this->assertSame('status_cohortambiguous', $payload['results'][0]['status']);
        $this->assertSame(1, $payload['counters']['errors']);
    }

    /**
     * Helper to check if a record exists.
     *
     * @param string $table
     * @param array $conditions
     * @return bool
     * @throws \dml_exception
     */
    private function record_exists(string $table, array $conditions): bool {
        global $DB;
        return $DB->record_exists($table, $conditions);
    }
}

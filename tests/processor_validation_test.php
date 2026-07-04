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
 * File-level validation tests (SPEC testfälle 14-16).
 *
 * @package   local_cohortmembership
 * @copyright Thomas Korner <thomas.korner@edu.zh.ch>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cohortmembership;

use local_cohortmembership\local\processor;

/**
 * Class processor_validation_test
 */
final class processor_validation_test extends \advanced_testcase {
    /**
     * SPEC testfall 14: a file mixing 'sync' with 'add'/'del' rows is
     * rejected before any row is processed.
     *
     * @covers \local_cohortmembership\local\processor::process
     * @return void
     */
    public function test_mixing_sync_with_delta_is_rejected(): void {
        $this->resetAfterTest(true);

        $user = $this->getDataGenerator()->create_user(['username' => 'alice']);
        $cohort = $this->getDataGenerator()->create_cohort(['idnumber' => 'cohortZ']);

        $rows = [
            ['username' => 'alice', 'cohortidnumber' => 'cohortZ', 'operation' => 'sync'],
            ['username' => 'alice', 'cohortidnumber' => 'cohortZ', 'operation' => 'add'],
        ];

        $payload = processor::process($rows, ['dryrun' => false]);

        $this->assertSame('error_mixed_operations', $payload['validation_error']);
        $this->assertSame([], $payload['results']);
        // Nothing must have been touched.
        $this->assertFalse($this->record_exists('cohort_members', ['cohortid' => $cohort->id, 'userid' => $user->id]));
    }

    /**
     * SPEC testfall 15: a file with neither a cohortid nor a cohortidnumber
     * column is rejected before any row is processed.
     *
     * @covers \local_cohortmembership\local\processor::process
     * @return void
     */
    public function test_missing_cohort_column_is_rejected(): void {
        $this->resetAfterTest(true);

        $rows = [
            ['username' => 'alice'],
        ];

        $payload = processor::process($rows, ['dryrun' => false]);

        $this->assertSame('error_missing_cohort_column', $payload['validation_error']);
        $this->assertSame([], $payload['results']);
    }

    /**
     * SPEC testfall 16: if both cohort columns are present, cohortid wins
     * and the file is flagged so the report can note that cohortidnumber
     * was ignored.
     *
     * @covers \local_cohortmembership\local\processor::process
     * @return void
     */
    public function test_cohortid_takes_precedence_over_cohortidnumber(): void {
        $this->resetAfterTest(true);

        $user = $this->getDataGenerator()->create_user(['username' => 'alice']);
        $cohort = $this->getDataGenerator()->create_cohort(['idnumber' => 'cohortZ']);

        // Cohortidnumber deliberately points at a cohort that does not exist:
        // if it were used instead of cohortid, this row would fail to resolve.
        $rows = [
            ['username' => 'alice', 'cohortid' => $cohort->id, 'cohortidnumber' => 'doesnotexist', 'operation' => 'add'],
        ];

        $payload = processor::process($rows, ['dryrun' => false]);

        $this->assertNull($payload['validation_error']);
        $this->assertTrue($payload['cohortidnumber_ignored']);
        $this->assertSame('status_added', $payload['results'][0]['status']);
        $this->assertTrue($this->record_exists('cohort_members', ['cohortid' => $cohort->id, 'userid' => $user->id]));
    }

    /**
     * A pure-sync file with both cohort columns present must also carry the
     * cohortidnumber_ignored flag; it is not specific to the delta path.
     *
     * @covers \local_cohortmembership\local\processor::process
     * @return void
     */
    public function test_cohortidnumber_ignored_flag_applies_to_sync_too(): void {
        $this->resetAfterTest(true);

        $this->getDataGenerator()->create_user(['username' => 'alice']);
        $cohort = $this->getDataGenerator()->create_cohort(['idnumber' => 'cohortZ']);

        $rows = [
            ['username' => 'alice', 'cohortid' => $cohort->id, 'cohortidnumber' => 'doesnotexist', 'operation' => 'sync'],
        ];

        $payload = processor::process($rows, ['dryrun' => false]);

        $this->assertNull($payload['validation_error']);
        $this->assertTrue($payload['cohortidnumber_ignored']);
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

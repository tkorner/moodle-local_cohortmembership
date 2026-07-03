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
 * Processor / operation_del test.
 *
 * Regression tests for the 'del' operation (SPEC testfälle 6-8), covering both
 * the legacy CSV format (no 'operation' column, status quo of the old
 * cohortunenroller plugin) and the explicit operation-dispatch path.
 *
 * @package   local_cohortmembership
 * @copyright Thomas Korner <thomas.korner@edu.zh.ch>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cohortmembership;

use local_cohortmembership\local\processor;

/**
 * Class processor_del_test
 */
final class processor_del_test extends \advanced_testcase {
    /**
     * SPEC testfälle 6, 7, 8: CSV without an 'operation' column is treated
     * entirely as 'del' (backward compatibility with the old plugin).
     *
     * @covers \local_cohortmembership\local\processor::process
     * @covers \local_cohortmembership\local\operation_del::execute
     * @return void
     */
    public function test_legacy_csv_without_operation_column_is_treated_as_del(): void {
        $this->resetAfterTest(true);

        // Create users.
        $u1 = $this->getDataGenerator()->create_user(['username' => 'alice']);
        $this->getDataGenerator()->create_user(['username' => 'bob']);
        $u3 = $this->getDataGenerator()->create_user(['username' => 'charlie']);

        // Create cohorts.
        $c1 = $this->getDataGenerator()->create_cohort(['idnumber' => 'cohortZ']);
        $c2 = $this->getDataGenerator()->create_cohort(['idnumber' => '2016class']);

        // Add memberships: alice in cohortZ, charlie in 2016class.
        cohort_add_member($c1->id, $u1->id);
        cohort_add_member($c2->id, $u3->id);

        // Rows without an 'operation' key: legacy format.
        $rows = [
            ['username' => 'alice', 'cohortidnumber' => 'cohortZ'],
            ['username' => 'bob', 'cohortidnumber' => 'cohortZ'],
            ['username' => 'nobody', 'cohortidnumber' => 'cohortZ'],
            ['username' => 'charlie', 'cohortidnumber' => 'doesnotexist'],
        ];

        $payload = processor::process($rows, ['standardise' => true, 'dryrun' => false]);

        // Testfall 8: no 'operation' column -> legacy format flag set.
        $this->assertTrue($payload['legacy_format']);

        $map = [];
        foreach ($payload['results'] as $r) {
            $map[$r['username'] . '|' . ($r['cohortidnumber'] ?? '')] = $r['status'];
            $this->assertSame('del', $r['operation']);
        }

        // Testfall 6: member -> removed.
        $this->assertSame('status_removed', $map['alice|cohortZ']);
        // Testfall 7: not a member -> skipped, no error.
        $this->assertSame('status_notmember', $map['bob|cohortZ']);
        $this->assertSame('status_usernotfound', $map['nobody|cohortZ']);
        $this->assertSame('status_cohortnotfound', $map['charlie|doesnotexist']);

        // Confirm DB state: Alice removed from cohortZ.
        $this->assertFalse($this->record_exists('cohort_members', ['cohortid' => $c1->id, 'userid' => $u1->id]));
        // Charlie still in 2016class (not touched, different cohort).
        $this->assertTrue($this->record_exists('cohort_members', ['cohortid' => $c2->id, 'userid' => $u3->id]));
    }

    /**
     * An explicit 'operation' => 'del' column behaves identically to the
     * legacy path, and correctly reports legacy_format as false.
     *
     * @covers \local_cohortmembership\local\processor::process
     * @return void
     */
    public function test_explicit_del_operation_matches_legacy_behaviour(): void {
        $this->resetAfterTest(true);

        $u1 = $this->getDataGenerator()->create_user(['username' => 'alice']);
        $c1 = $this->getDataGenerator()->create_cohort(['idnumber' => 'cohortZ']);
        cohort_add_member($c1->id, $u1->id);

        $rows = [
            ['username' => 'alice', 'cohortidnumber' => 'cohortZ', 'operation' => 'del'],
        ];

        $payload = processor::process($rows, ['dryrun' => false]);

        $this->assertFalse($payload['legacy_format']);
        $this->assertSame('status_removed', $payload['results'][0]['status']);
        $this->assertFalse($this->record_exists('cohort_members', ['cohortid' => $c1->id, 'userid' => $u1->id]));
    }

    /**
     * A dry run must not change the database, even when the user is a member.
     *
     * @covers \local_cohortmembership\local\operation_del::execute
     * @return void
     */
    public function test_del_dry_run_does_not_change_database(): void {
        $this->resetAfterTest(true);

        $u1 = $this->getDataGenerator()->create_user(['username' => 'alice']);
        $c1 = $this->getDataGenerator()->create_cohort(['idnumber' => 'cohortZ']);
        cohort_add_member($c1->id, $u1->id);

        $rows = [
            ['username' => 'alice', 'cohortidnumber' => 'cohortZ', 'operation' => 'del'],
        ];

        $payload = processor::process($rows, ['dryrun' => true]);

        $this->assertSame('status_removed', $payload['results'][0]['status']);
        // Still a member: dry run must not touch the database.
        $this->assertTrue($this->record_exists('cohort_members', ['cohortid' => $c1->id, 'userid' => $u1->id]));
    }

    /**
     * An operation other than the currently implemented ones is reported as
     * an error and does not change the database.
     *
     * @covers \local_cohortmembership\local\processor::process
     * @return void
     */
    public function test_unknown_operation_yields_error(): void {
        $this->resetAfterTest(true);

        $u1 = $this->getDataGenerator()->create_user(['username' => 'alice']);
        $c1 = $this->getDataGenerator()->create_cohort(['idnumber' => 'cohortZ']);
        cohort_add_member($c1->id, $u1->id);

        $rows = [
            ['username' => 'alice', 'cohortidnumber' => 'cohortZ', 'operation' => 'bogus'],
        ];

        $payload = processor::process($rows, ['dryrun' => false]);

        $this->assertSame('error_bad_operation', $payload['results'][0]['status']);
        $this->assertSame(1, $payload['counters']['errors']);
        // Membership must be untouched.
        $this->assertTrue($this->record_exists('cohort_members', ['cohortid' => $c1->id, 'userid' => $u1->id]));
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

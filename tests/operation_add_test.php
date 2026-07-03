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
 * Tests for the 'add' operation (SPEC testfälle 1-5).
 *
 * @package   local_cohortmembership
 * @copyright Thomas Korner <thomas.korner@edu.zh.ch>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cohortmembership;

use local_cohortmembership\local\processor;

/**
 * Class operation_add_test
 */
final class operation_add_test extends \advanced_testcase {
    /**
     * SPEC testfall 1: add to an existing cohort, user not yet a member ->
     * membership created, status_added.
     *
     * @covers \local_cohortmembership\local\operation_add::execute
     * @return void
     */
    public function test_add_creates_membership(): void {
        $this->resetAfterTest(true);

        $user = $this->getDataGenerator()->create_user(['username' => 'alice']);
        $cohort = $this->getDataGenerator()->create_cohort(['idnumber' => 'cohortZ']);

        $rows = [
            ['username' => 'alice', 'cohortidnumber' => 'cohortZ', 'operation' => 'add'],
        ];

        $payload = processor::process($rows, ['dryrun' => false]);

        $this->assertSame('status_added', $payload['results'][0]['status']);
        $this->assertTrue($this->record_exists('cohort_members', ['cohortid' => $cohort->id, 'userid' => $user->id]));
    }

    /**
     * SPEC testfall 2: add, user already a member -> no change, status_alreadymember.
     *
     * @covers \local_cohortmembership\local\operation_add::execute
     * @return void
     */
    public function test_add_when_already_member_is_a_noop(): void {
        $this->resetAfterTest(true);

        $user = $this->getDataGenerator()->create_user(['username' => 'alice']);
        $cohort = $this->getDataGenerator()->create_cohort(['idnumber' => 'cohortZ']);
        cohort_add_member($cohort->id, $user->id);

        $rows = [
            ['username' => 'alice', 'cohortidnumber' => 'cohortZ', 'operation' => 'add'],
        ];

        $payload = processor::process($rows, ['dryrun' => false]);

        $this->assertSame('status_alreadymember', $payload['results'][0]['status']);
        $this->assertTrue($this->record_exists('cohort_members', ['cohortid' => $cohort->id, 'userid' => $user->id]));
    }

    /**
     * SPEC testfall 3: add to an unknown cohort idnumber -> status_cohortnotfound,
     * and the plugin must never auto-create the cohort.
     *
     * @covers \local_cohortmembership\local\processor::process
     * @return void
     */
    public function test_add_to_unknown_cohort_never_creates_it(): void {
        global $DB;
        $this->resetAfterTest(true);

        $this->getDataGenerator()->create_user(['username' => 'alice']);
        $cohortcountbefore = $DB->count_records('cohort');

        $rows = [
            ['username' => 'alice', 'cohortidnumber' => 'doesnotexist', 'operation' => 'add'],
        ];

        $payload = processor::process($rows, ['dryrun' => false]);

        $this->assertSame('status_cohortnotfound', $payload['results'][0]['status']);
        $this->assertSame($cohortcountbefore, $DB->count_records('cohort'));
    }

    /**
     * SPEC testfall 4: add for an unknown user -> status_usernotfound.
     *
     * @covers \local_cohortmembership\local\processor::process
     * @return void
     */
    public function test_add_with_unknown_user(): void {
        $this->resetAfterTest(true);

        $this->getDataGenerator()->create_cohort(['idnumber' => 'cohortZ']);

        $rows = [
            ['username' => 'nobody', 'cohortidnumber' => 'cohortZ', 'operation' => 'add'],
        ];

        $payload = processor::process($rows, ['dryrun' => false]);

        $this->assertSame('status_usernotfound', $payload['results'][0]['status']);
    }

    /**
     * SPEC testfall 5: add in dry-run mode reports the simulated outcome but
     * never changes the database.
     *
     * @covers \local_cohortmembership\local\operation_add::execute
     * @return void
     */
    public function test_add_dry_run_does_not_change_database(): void {
        $this->resetAfterTest(true);

        $user = $this->getDataGenerator()->create_user(['username' => 'alice']);
        $cohort = $this->getDataGenerator()->create_cohort(['idnumber' => 'cohortZ']);

        $rows = [
            ['username' => 'alice', 'cohortidnumber' => 'cohortZ', 'operation' => 'add'],
        ];

        $payload = processor::process($rows, ['dryrun' => true]);

        $this->assertSame('status_added', $payload['results'][0]['status']);
        $this->assertFalse($this->record_exists('cohort_members', ['cohortid' => $cohort->id, 'userid' => $user->id]));
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

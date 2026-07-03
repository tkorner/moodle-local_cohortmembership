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
 * Processor test.
 *
 * @package   local_cohortmembership
 * @copyright Thomas Korner <thomas.korner@edu.zh.ch>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cohortmembership;

use local_cohortmembership\local\processor;

/**
 * Class processor_test
 */
final class processor_test extends \advanced_testcase {
    /**
     * Test that removes members and skips non-members.
     *
     * @covers \local_cohortmembership\local\processor::process
     * @return void
     */
    public function test_removes_members_and_skips_nonmembers(): void {
        $this->resetAfterTest(true);

        // Create users.
        $u1 = $this->getDataGenerator()->create_user(['username' => 'alice']);
        $u2 = $this->getDataGenerator()->create_user(['username' => 'bob']);
        $u3 = $this->getDataGenerator()->create_user(['username' => 'charlie']);

        // Create cohorts.
        $c1 = $this->getDataGenerator()->create_cohort(['idnumber' => 'cohortZ']);
        $c2 = $this->getDataGenerator()->create_cohort(['idnumber' => '2016class']);

        // Add memberships: alice in cohortZ, charlie in 2016class.
        cohort_add_member($c1->id, $u1->id);
        cohort_add_member($c2->id, $u3->id);

        // Rows: remove alice from cohortZ (should remove), bob from cohortZ (not member => info skip),
        // bogus user, bogus cohort.
        $rows = [
            ['username' => 'alice', 'cohortidnumber' => 'cohortZ'],
            ['username' => 'bob', 'cohortidnumber' => 'cohortZ'],
            ['username' => 'nobody', 'cohortidnumber' => 'cohortZ'],
            ['username' => 'charlie', 'cohortidnumber' => 'doesnotexist'],
        ];

        $payload = processor::process($rows, ['standardise' => true, 'dryrun' => false]);
        $results = $payload['results'];

        $map = [];
        foreach ($results as $r) {
            $map[$r['username'] . '|' . ($r['cohortidnumber'] ?? '')] = $r['status'];
        }

        $this->assertEquals('status_removed', $map['alice|cohortZ']);
        $this->assertEquals('status_notmember', $map['bob|cohortZ']);
        $this->assertEquals('status_usernotfound', $map['nobody|cohortZ']);
        $this->assertEquals('status_cohortnotfound', $map['charlie|doesnotexist']);

        // Confirm DB state: Alice removed from cohortZ.
        $this->assertFalse($this->record_exists('cohort_members', ['cohortid' => $c1->id, 'userid' => $u1->id]));
        // Charlie still in 2016class (not touched).
        $this->assertTrue($this->record_exists('cohort_members', ['cohortid' => $c2->id, 'userid' => $u3->id]));
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

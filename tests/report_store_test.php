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
 * Tests for report_store.
 *
 * @package   local_cohortmembership
 * @copyright Thomas Korner <thomas.korner@edu.zh.ch>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cohortmembership;

use local_cohortmembership\local\report_store;

/**
 * Class report_store_test
 */
final class report_store_test extends \advanced_testcase {
    /**
     * A saved report round-trips through load() with the same structure.
     *
     * @covers \local_cohortmembership\local\report_store::save
     * @covers \local_cohortmembership\local\report_store::load
     * @return void
     */
    public function test_save_and_load_round_trip(): void {
        $this->resetAfterTest(true);

        $data = ['rows' => [['username' => 'alice', 'status' => 'status_added']], 'counters' => ['total' => 1]];
        report_store::save('testkey123', $data);

        $this->assertSame($data, report_store::load('testkey123'));
    }

    /**
     * Loading a key that was never saved returns null, not an error.
     *
     * @covers \local_cohortmembership\local\report_store::load
     * @return void
     */
    public function test_load_missing_key_returns_null(): void {
        $this->resetAfterTest(true);

        $this->assertNull(report_store::load('neversavedkey'));
    }

    /**
     * delete() removes a stored report; a subsequent load() returns null.
     *
     * @covers \local_cohortmembership\local\report_store::delete
     * @return void
     */
    public function test_delete_removes_stored_report(): void {
        $this->resetAfterTest(true);

        report_store::save('deleteme', ['rows' => [], 'counters' => []]);
        report_store::delete('deleteme');

        $this->assertNull(report_store::load('deleteme'));
    }

    /**
     * Two different keys (e.g. two concurrent sessions for the same user)
     * never see each other's report.
     *
     * @covers \local_cohortmembership\local\report_store::save
     * @covers \local_cohortmembership\local\report_store::load
     * @return void
     */
    public function test_different_keys_do_not_collide(): void {
        $this->resetAfterTest(true);

        report_store::save('sessionone', ['rows' => ['first'], 'counters' => []]);
        report_store::save('sessiontwo', ['rows' => ['second'], 'counters' => []]);

        $this->assertSame(['first'], report_store::load('sessionone')['rows']);
        $this->assertSame(['second'], report_store::load('sessiontwo')['rows']);
    }
}

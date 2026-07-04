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
 * Tests for cohortsync_detector (SPEC test cases 17-19).
 *
 * @package   local_cohortmembership
 * @copyright Thomas Korner <thomas.korner@edu.zh.ch>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cohortmembership;

use local_cohortmembership\local\cohortsync_detector;

/**
 * Class cohortsync_detector_test
 */
final class cohortsync_detector_test extends \advanced_testcase {
    /**
     * Reset the detector's static cache before each test so that reused
     * cohort ids across tests never leak a stale result.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        cohortsync_detector::reset_cache();
    }

    /**
     * SPEC test case 17: cohort with an active enrol_cohort instance -> uses()
     * is true, and the course is listed.
     *
     * @covers \local_cohortmembership\local\cohortsync_detector
     * @return void
     */
    public function test_active_cohort_sync_is_detected(): void {
        global $DB;
        $this->resetAfterTest(true);

        $cohort = $this->getDataGenerator()->create_cohort();
        $course = $this->getDataGenerator()->create_course();
        $DB->insert_record('enrol', (object)[
            'enrol' => 'cohort',
            'status' => ENROL_INSTANCE_ENABLED,
            'courseid' => $course->id,
            'customint1' => $cohort->id,
        ]);

        $this->assertTrue(cohortsync_detector::uses($cohort->id));
        $this->assertSame([(int)$course->id], cohortsync_detector::courses_using($cohort->id));
    }

    /**
     * SPEC test case 18: cohort with a disabled (status=1) enrol_cohort
     * instance -> uses() is false.
     *
     * @covers \local_cohortmembership\local\cohortsync_detector
     * @return void
     */
    public function test_disabled_cohort_sync_is_not_detected(): void {
        global $DB;
        $this->resetAfterTest(true);

        $cohort = $this->getDataGenerator()->create_cohort();
        $course = $this->getDataGenerator()->create_course();
        $DB->insert_record('enrol', (object)[
            'enrol' => 'cohort',
            'status' => ENROL_INSTANCE_DISABLED,
            'courseid' => $course->id,
            'customint1' => $cohort->id,
        ]);

        $this->assertFalse(cohortsync_detector::uses($cohort->id));
        $this->assertSame([], cohortsync_detector::courses_using($cohort->id));
    }

    /**
     * SPEC test case 19: cohort without any enrol_cohort instance -> uses()
     * is false.
     *
     * @covers \local_cohortmembership\local\cohortsync_detector
     * @return void
     */
    public function test_cohort_without_cohort_sync_is_not_detected(): void {
        $this->resetAfterTest(true);

        $cohort = $this->getDataGenerator()->create_cohort();

        $this->assertFalse(cohortsync_detector::uses($cohort->id));
    }
}

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
 * Tests for the 'sync' operation (SPEC test cases 9-13).
 *
 * @package   local_cohortmembership
 * @copyright Thomas Korner <thomas.korner@edu.zh.ch>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cohortmembership;

use local_cohortmembership\local\cohortsync_detector;
use local_cohortmembership\local\processor;

/**
 * Class operation_sync_test
 */
final class operation_sync_test extends \advanced_testcase {
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
     * SPEC test case 9: a cohort the user belongs to, but which is never named
     * anywhere in the sync file, is outside the universe and stays untouched.
     *
     * @covers \local_cohortmembership\local\operation_sync::process
     * @return void
     */
    public function test_cohort_outside_universe_is_untouched(): void {
        $this->resetAfterTest(true);

        $user = $this->getDataGenerator()->create_user(['username' => 'hans']);
        $infile = $this->getDataGenerator()->create_cohort(['idnumber' => 'kurs-inf-2026']);
        $outside = $this->getDataGenerator()->create_cohort(['idnumber' => 'sonstiges-2020']);
        cohort_add_member($outside->id, $user->id);

        $rows = [
            ['username' => 'hans', 'cohortidnumber' => 'kurs-inf-2026', 'operation' => 'sync'],
        ];

        $payload = processor::process($rows, ['dryrun' => false]);

        $this->assertFalse($payload['legacy_format']);
        $this->assertTrue($this->record_exists('cohort_members', ['cohortid' => $outside->id, 'userid' => $user->id]));
        $this->assertTrue($this->record_exists('cohort_members', ['cohortid' => $infile->id, 'userid' => $user->id]));
    }

    /**
     * SPEC test case 10: a universe cohort missing from the user's sync rows
     * is removed.
     *
     * @covers \local_cohortmembership\local\operation_sync::process
     * @return void
     */
    public function test_universe_cohort_missing_from_rows_is_removed(): void {
        $this->resetAfterTest(true);

        $user = $this->getDataGenerator()->create_user(['username' => 'hans']);
        $keep = $this->getDataGenerator()->create_cohort(['idnumber' => 'kurs-inf-2026']);
        $drop = $this->getDataGenerator()->create_cohort(['idnumber' => 'kurs-bwl-2026']);
        cohort_add_member($keep->id, $user->id);
        cohort_add_member($drop->id, $user->id);

        // Universe = {kurs-inf-2026, kurs-bwl-2026}; hans only lists kurs-inf-2026.
        $rows = [
            ['username' => 'hans', 'cohortidnumber' => 'kurs-inf-2026', 'operation' => 'sync'],
            ['username' => 'anna', 'cohortidnumber' => 'kurs-bwl-2026', 'operation' => 'sync'],
        ];
        $this->getDataGenerator()->create_user(['username' => 'anna']);

        $payload = processor::process($rows, ['dryrun' => false]);

        $this->assertTrue($this->record_exists('cohort_members', ['cohortid' => $keep->id, 'userid' => $user->id]));
        $this->assertFalse($this->record_exists('cohort_members', ['cohortid' => $drop->id, 'userid' => $user->id]));

        $map = $this->status_by_username_cohort($payload['results']);
        $this->assertSame('status_removed', $map['hans|' . $drop->id] ?? null);
    }

    /**
     * SPEC test case 11: a target cohort the user is missing is added.
     *
     * @covers \local_cohortmembership\local\operation_sync::process
     * @return void
     */
    public function test_missing_target_cohort_is_added(): void {
        $this->resetAfterTest(true);

        $user = $this->getDataGenerator()->create_user(['username' => 'hans']);
        $cohort = $this->getDataGenerator()->create_cohort(['idnumber' => 'kurs-inf-2026']);

        $rows = [
            ['username' => 'hans', 'cohortidnumber' => 'kurs-inf-2026', 'operation' => 'sync'],
        ];

        $payload = processor::process($rows, ['dryrun' => false]);

        $this->assertSame('status_added', $payload['results'][0]['status']);
        $this->assertTrue($this->record_exists('cohort_members', ['cohortid' => $cohort->id, 'userid' => $user->id]));
    }

    /**
     * SPEC test case 12: current state already matches the target -> no-op,
     * reported as status_alreadymember (info, not an error).
     *
     * @covers \local_cohortmembership\local\operation_sync::process
     * @return void
     */
    public function test_noop_when_current_state_matches_target(): void {
        $this->resetAfterTest(true);

        $user = $this->getDataGenerator()->create_user(['username' => 'hans']);
        $cohort = $this->getDataGenerator()->create_cohort(['idnumber' => 'kurs-inf-2026']);
        cohort_add_member($cohort->id, $user->id);

        $rows = [
            ['username' => 'hans', 'cohortidnumber' => 'kurs-inf-2026', 'operation' => 'sync'],
        ];

        $payload = processor::process($rows, ['dryrun' => false]);

        $this->assertSame('status_alreadymember', $payload['results'][0]['status']);
        $this->assertSame(0, $payload['counters']['errors']);
        $this->assertTrue($this->record_exists('cohort_members', ['cohortid' => $cohort->id, 'userid' => $user->id]));
    }

    /**
     * SPEC test case 13: two users in one sync file with different targets
     * are kept correctly separate.
     *
     * @covers \local_cohortmembership\local\operation_sync::process
     * @return void
     */
    public function test_two_users_with_different_targets_are_separated(): void {
        $this->resetAfterTest(true);

        $hans = $this->getDataGenerator()->create_user(['username' => 'hans']);
        $anna = $this->getDataGenerator()->create_user(['username' => 'anna']);
        $inf = $this->getDataGenerator()->create_cohort(['idnumber' => 'kurs-inf-2026']);
        $bwl = $this->getDataGenerator()->create_cohort(['idnumber' => 'kurs-bwl-2026']);

        $rows = [
            ['username' => 'hans', 'cohortidnumber' => 'kurs-inf-2026', 'operation' => 'sync'],
            ['username' => 'anna', 'cohortidnumber' => 'kurs-bwl-2026', 'operation' => 'sync'],
        ];

        $payload = processor::process($rows, ['dryrun' => false]);

        $this->assertTrue($this->record_exists('cohort_members', ['cohortid' => $inf->id, 'userid' => $hans->id]));
        $this->assertFalse($this->record_exists('cohort_members', ['cohortid' => $bwl->id, 'userid' => $hans->id]));
        $this->assertTrue($this->record_exists('cohort_members', ['cohortid' => $bwl->id, 'userid' => $anna->id]));
        $this->assertFalse($this->record_exists('cohort_members', ['cohortid' => $inf->id, 'userid' => $anna->id]));
    }

    /**
     * A sync dry run must not change the database - the most dangerous path in
     * the plugin, since sync can remove memberships no row explicitly names.
     *
     * @covers \local_cohortmembership\local\operation_sync::process
     * @return void
     */
    public function test_sync_dry_run_does_not_change_database(): void {
        $this->resetAfterTest(true);

        $user = $this->getDataGenerator()->create_user(['username' => 'hans']);
        $keep = $this->getDataGenerator()->create_cohort(['idnumber' => 'kurs-inf-2026']);
        $drop = $this->getDataGenerator()->create_cohort(['idnumber' => 'kurs-bwl-2026']);
        $add  = $this->getDataGenerator()->create_cohort(['idnumber' => 'basis-alle']);
        cohort_add_member($keep->id, $user->id);
        cohort_add_member($drop->id, $user->id);

        // Universe = {kurs-inf-2026, kurs-bwl-2026, basis-alle} - kurs-bwl-2026
        // only joins the universe via anna's row; hans's own rows omit it
        // (should be removed) and add basis-alle (should be added).
        $rows = [
            ['username' => 'hans', 'cohortidnumber' => 'kurs-inf-2026', 'operation' => 'sync'],
            ['username' => 'hans', 'cohortidnumber' => 'basis-alle', 'operation' => 'sync'],
            ['username' => 'anna', 'cohortidnumber' => 'kurs-bwl-2026', 'operation' => 'sync'],
        ];
        $this->getDataGenerator()->create_user(['username' => 'anna']);

        $payload = processor::process($rows, ['dryrun' => true]);

        $map = $this->status_by_username_cohort($payload['results']);
        $this->assertSame('status_alreadymember', $map['hans|' . $keep->id] ?? null);
        $this->assertSame('status_added', $map['hans|' . $add->id] ?? null);
        $this->assertSame('status_removed', $map['hans|' . $drop->id] ?? null);

        // Nothing in the database actually changed.
        $this->assertTrue($this->record_exists('cohort_members', ['cohortid' => $keep->id, 'userid' => $user->id]));
        $this->assertTrue($this->record_exists('cohort_members', ['cohortid' => $drop->id, 'userid' => $user->id]));
        $this->assertFalse($this->record_exists('cohort_members', ['cohortid' => $add->id, 'userid' => $user->id]));
    }

    /**
     * A row for an unknown username still resolves and contributes its cohort
     * to the file-wide universe (SPEC §3.2: the universe is every cohort named
     * anywhere in the file, not just in rows for users that exist). This is a
     * deliberate, spec-literal choice, not a bug: it is exercised here so a
     * change in behaviour is a conscious decision, not an accident.
     *
     * @covers \local_cohortmembership\local\operation_sync::process
     * @return void
     */
    public function test_unknown_user_row_still_contributes_cohort_to_universe(): void {
        $this->resetAfterTest(true);

        $hans = $this->getDataGenerator()->create_user(['username' => 'hans']);
        $shared = $this->getDataGenerator()->create_cohort(['idnumber' => 'kurs-bwl-2026']);
        cohort_add_member($shared->id, $hans->id);

        // The username 'nobody' does not exist, but still names kurs-bwl-2026,
        // putting it in the universe. Hans does not list it among his own rows,
        // so it must be removed from him even though the only other row naming
        // it belongs to a user who fails to resolve.
        $rows = [
            ['username' => 'hans', 'cohortidnumber' => 'kurs-inf-2026', 'operation' => 'sync'],
            ['username' => 'nobody', 'cohortidnumber' => 'kurs-bwl-2026', 'operation' => 'sync'],
        ];
        $this->getDataGenerator()->create_cohort(['idnumber' => 'kurs-inf-2026']);

        $payload = processor::process($rows, ['dryrun' => false]);

        $this->assertFalse($this->record_exists('cohort_members', ['cohortid' => $shared->id, 'userid' => $hans->id]));
    }

    /**
     * A sync-triggered removal must carry the cohort-sync warning flag, same
     * as a 'del' removal (CLAUDE.md §Kritische Fachlogik: required for
     * every removal, not just explicit 'del' rows).
     *
     * @covers \local_cohortmembership\local\operation_sync::process
     * @return void
     */
    public function test_sync_removal_flags_cohortsync_warning(): void {
        global $DB;
        $this->resetAfterTest(true);

        $user = $this->getDataGenerator()->create_user(['username' => 'hans']);
        $keep = $this->getDataGenerator()->create_cohort(['idnumber' => 'kurs-inf-2026']);
        $drop = $this->getDataGenerator()->create_cohort(['idnumber' => 'kurs-bwl-2026']);
        cohort_add_member($keep->id, $user->id);
        cohort_add_member($drop->id, $user->id);

        $course = $this->getDataGenerator()->create_course();
        $DB->insert_record('enrol', (object)[
            'enrol' => 'cohort',
            'status' => ENROL_INSTANCE_ENABLED,
            'courseid' => $course->id,
            'customint1' => $drop->id,
        ]);

        // Hans only lists kurs-inf-2026; kurs-bwl-2026 stays in the universe via anna's row.
        $rows = [
            ['username' => 'hans', 'cohortidnumber' => 'kurs-inf-2026', 'operation' => 'sync'],
            ['username' => 'anna', 'cohortidnumber' => 'kurs-bwl-2026', 'operation' => 'sync'],
        ];
        $this->getDataGenerator()->create_user(['username' => 'anna']);

        $payload = processor::process($rows, ['dryrun' => false]);

        $map = $this->status_by_username_cohort($payload['results']);
        $key = 'hans|' . $drop->id;
        $this->assertSame('status_removed', $map[$key] ?? null);

        $warned = false;
        foreach ($payload['results'] as $r) {
            if ($r['username'] === 'hans' && $r['cohortid'] == $drop->id && $r['status'] === 'status_removed') {
                $warned = $r['cohortsync_warning'];
            }
        }
        $this->assertTrue($warned);
    }

    /**
     * Helper: map 'username|cohortid' to status for easy assertions.
     *
     * @param array $results
     * @return array
     */
    private function status_by_username_cohort(array $results): array {
        $map = [];
        foreach ($results as $r) {
            $map[$r['username'] . '|' . $r['cohortid']] = $r['status'];
        }
        return $map;
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

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
 * Category-scoped runs: a category manager may only touch cohorts in their
 * category or below, where they hold moodle/cohort:assign.
 *
 * @package   local_cohortmembership
 * @copyright Thomas Korner <thomas.korner@edu.zh.ch>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cohortmembership;

use local_cohortmembership\local\cohortsync_detector;
use local_cohortmembership\local\processor;

/**
 * Class category_scope_test
 */
final class category_scope_test extends \advanced_testcase {
    /** @var \stdClass Category the manager is assigned to. */
    private $cat;

    /** @var \stdClass Subcategory of $cat. */
    private $subcat;

    /** @var \stdClass Sibling category of $cat, outside the manager's scope. */
    private $othercat;

    /** @var \stdClass User holding the manager role in $cat only. */
    private $manager;

    /**
     * Build a category tree and a manager assigned to one category, and act as that manager.
     *
     * @return void
     */
    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest(true);
        cohortsync_detector::reset_cache();

        $generator = $this->getDataGenerator();
        $this->cat = $generator->create_category(['name' => 'Faculty']);
        $this->subcat = $generator->create_category(['name' => 'Department', 'parent' => $this->cat->id]);
        $this->othercat = $generator->create_category(['name' => 'Other faculty']);

        $this->manager = $generator->create_user();
        $managerrole = $DB->get_field('role', 'id', ['shortname' => 'manager'], MUST_EXIST);
        $generator->role_assign($managerrole, $this->manager->id, \context_coursecat::instance($this->cat->id)->id);
        $this->setUser($this->manager);
    }

    /**
     * Create a cohort in the given category (or at system level for null).
     *
     * @param string $idnumber
     * @param \stdClass|null $category
     * @return \stdClass
     */
    private function cohort(string $idnumber, ?\stdClass $category): \stdClass {
        $context = $category ? \context_coursecat::instance($category->id) : \context_system::instance();
        return $this->getDataGenerator()->create_cohort(['idnumber' => $idnumber, 'contextid' => $context->id]);
    }

    /**
     * Process rows as a live run restricted to the manager's category.
     *
     * @param array $rows
     * @return array processor payload
     */
    private function run_scoped(array $rows): array {
        return processor::process($rows, ['dryrun' => false, 'scope' => \context_coursecat::instance($this->cat->id)]);
    }

    /**
     * The manager role assigned in the category grants both capabilities the
     * category entry point requires, and none at site level.
     *
     * @coversNothing
     * @return void
     */
    public function test_category_manager_has_entry_capabilities_in_category_only(): void {
        $catcontext = \context_coursecat::instance($this->cat->id);
        $this->assertTrue(has_all_capabilities(['local/cohortmembership:manage', 'moodle/cohort:assign'], $catcontext));
        $this->assertFalse(has_capability('local/cohortmembership:manage', \context_system::instance()));
    }

    /**
     * Cohorts in the category itself and in a subcategory are processed normally.
     *
     * @covers \local_cohortmembership\local\processor::process
     * @covers \local_cohortmembership\local\cohort_resolver::resolve
     * @return void
     */
    public function test_delta_in_category_and_subcategory_is_applied(): void {
        $user = $this->getDataGenerator()->create_user(['username' => 'alice']);
        $own = $this->cohort('own', $this->cat);
        $sub = $this->cohort('sub', $this->subcat);
        cohort_add_member($sub->id, $user->id);

        $payload = $this->run_scoped([
            ['username' => 'alice', 'cohortidnumber' => 'own', 'operation' => 'add'],
            ['username' => 'alice', 'cohortidnumber' => 'sub', 'operation' => 'del'],
        ]);

        $this->assertSame(['status_added', 'status_removed'], array_column($payload['results'], 'status'));
        $this->assertTrue(cohort_is_member($own->id, $user->id));
        $this->assertFalse(cohort_is_member($sub->id, $user->id));
    }

    /**
     * System-level and sibling-category cohorts are invisible from the
     * category: reported as not found (revealing nothing about cohorts
     * elsewhere) and never touched, whether addressed by idnumber or by id.
     *
     * @covers \local_cohortmembership\local\processor::process
     * @covers \local_cohortmembership\local\cohort_resolver::resolve
     * @return void
     */
    public function test_delta_outside_category_is_rejected(): void {
        $user = $this->getDataGenerator()->create_user(['username' => 'alice']);
        $system = $this->cohort('system', null);
        $other = $this->cohort('other', $this->othercat);
        cohort_add_member($other->id, $user->id);

        $payload = $this->run_scoped([
            ['username' => 'alice', 'cohortidnumber' => 'system', 'operation' => 'add'],
            ['username' => 'alice', 'cohortid' => (int)$other->id, 'operation' => 'del'],
        ]);

        $this->assertSame(
            ['status_cohortnotfound', 'status_cohortnotfound'],
            array_column($payload['results'], 'status')
        );
        $this->assertSame(2, $payload['counters']['errors']);
        $this->assertFalse(cohort_is_member($system->id, $user->id));
        $this->assertTrue(cohort_is_member($other->id, $user->id));
    }

    /**
     * A prohibit on moodle/cohort:assign in a subcategory makes that
     * subcategory's cohorts read-only for the run, as it would in core's UI.
     *
     * @covers \local_cohortmembership\local\cohort_resolver::resolve
     * @return void
     */
    public function test_prohibit_in_subcategory_is_honoured(): void {
        global $DB;
        $user = $this->getDataGenerator()->create_user(['username' => 'alice']);
        $sub = $this->cohort('sub', $this->subcat);
        $managerrole = $DB->get_field('role', 'id', ['shortname' => 'manager'], MUST_EXIST);
        assign_capability(
            'moodle/cohort:assign',
            CAP_PROHIBIT,
            $managerrole,
            \context_coursecat::instance($this->subcat->id)->id
        );

        $payload = $this->run_scoped([['username' => 'alice', 'cohortidnumber' => 'sub', 'operation' => 'add']]);

        $this->assertSame('status_cohortnopermission', $payload['results'][0]['status']);
        $this->assertFalse(cohort_is_member($sub->id, $user->id));
    }

    /**
     * Sync: a cohort outside the category never joins the universe, so a
     * user's membership there survives even though the file omits it for them.
     *
     * @covers \local_cohortmembership\local\operation_sync::process
     * @return void
     */
    public function test_sync_never_removes_outside_category(): void {
        $alice = $this->getDataGenerator()->create_user(['username' => 'alice']);
        $this->getDataGenerator()->create_user(['username' => 'bob']);
        $own = $this->cohort('own', $this->cat);
        $sub = $this->cohort('sub', $this->subcat);
        $other = $this->cohort('other', $this->othercat);
        cohort_add_member($sub->id, $alice->id);
        cohort_add_member($other->id, $alice->id);

        // Bob's row names both 'sub' and 'other'; alice is listed for 'own' only.
        $payload = $this->run_scoped([
            ['username' => 'alice', 'cohortidnumber' => 'own', 'operation' => 'sync'],
            ['username' => 'bob', 'cohortidnumber' => 'sub', 'operation' => 'sync'],
            ['username' => 'bob', 'cohortidnumber' => 'other', 'operation' => 'sync'],
        ]);

        $this->assertNull($payload['validation_error']);
        $this->assertTrue(cohort_is_member($own->id, $alice->id));
        // In scope and in the universe, not listed for alice: removed.
        $this->assertFalse(cohort_is_member($sub->id, $alice->id));
        // Outside the category: kept, and bob was not added.
        $this->assertTrue(cohort_is_member($other->id, $alice->id));
        $statuses = array_column($payload['results'], 'status', 'cohortidnumber');
        $this->assertSame('status_cohortnotfound', $statuses['other']);
    }

    /**
     * An idnumber reused by a cohort in another category does not make the
     * in-scope cohort ambiguous: cohorts outside the category are filtered
     * out before matches are counted. Site-wide it stays ambiguous.
     *
     * @covers \local_cohortmembership\local\cohort_resolver::resolve
     * @return void
     */
    public function test_idnumber_reused_elsewhere_resolves_in_scope(): void {
        $user = $this->getDataGenerator()->create_user(['username' => 'alice']);
        $own = $this->cohort('class1', $this->cat);
        $this->cohort('class1', $this->othercat);
        $rows = [['username' => 'alice', 'cohortidnumber' => 'class1', 'operation' => 'add']];

        $payload = processor::process($rows, ['dryrun' => true]);
        $this->assertSame('status_cohortambiguous', $payload['results'][0]['status']);

        $payload = $this->run_scoped($rows);
        $this->assertSame('status_added', $payload['results'][0]['status']);
        $this->assertTrue(cohort_is_member($own->id, $user->id));
    }

    /**
     * A scope that is not a category context is rejected before any row is processed.
     *
     * @covers \local_cohortmembership\local\processor::process
     * @return void
     */
    public function test_non_category_scope_is_rejected_up_front(): void {
        $this->expectException(\coding_exception::class);
        processor::process(
            [['username' => 'alice', 'cohortidnumber' => 'own', 'operation' => 'add']],
            ['dryrun' => false, 'scope' => \context_system::instance()]
        );
    }

    /**
     * Without a scope (site-level run), category cohorts anywhere stay reachable.
     *
     * @covers \local_cohortmembership\local\processor::process
     * @return void
     */
    public function test_unscoped_run_reaches_any_category(): void {
        $this->setAdminUser();
        $user = $this->getDataGenerator()->create_user(['username' => 'alice']);
        $other = $this->cohort('other', $this->othercat);

        $payload = processor::process(
            [['username' => 'alice', 'cohortidnumber' => 'other', 'operation' => 'add']],
            ['dryrun' => false]
        );

        $this->assertSame('status_added', $payload['results'][0]['status']);
        $this->assertTrue(cohort_is_member($other->id, $user->id));
    }

    /**
     * The category "More" menu gets the entry point only for users who may use it.
     *
     * @covers ::local_cohortmembership_extend_navigation_category_settings
     * @return void
     */
    public function test_navigation_node_requires_capabilities(): void {
        global $CFG;
        require_once($CFG->dirroot . '/local/cohortmembership/lib.php');
        $catcontext = \context_coursecat::instance($this->cat->id);

        $node = new \navigation_node('root');
        local_cohortmembership_extend_navigation_category_settings($node, $catcontext);
        $this->assertNotFalse($node->get('local_cohortmembership', \navigation_node::TYPE_SETTING));

        $this->setUser($this->getDataGenerator()->create_user());
        $node = new \navigation_node('root');
        local_cohortmembership_extend_navigation_category_settings($node, $catcontext);
        $this->assertFalse($node->get('local_cohortmembership', \navigation_node::TYPE_SETTING));
    }
}

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
 * Library callbacks.
 *
 * @package   local_cohortmembership
 * @copyright Thomas Korner <thomas.korner@edu.zh.ch>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Add the category-level entry point to a course category's "More" menu,
 * next to core's own "Cohorts" node, for users who may use it there.
 *
 * @param navigation_node $parentnode The category settings node.
 * @param context_coursecat $context The category context.
 * @return void
 */
function local_cohortmembership_extend_navigation_category_settings(
    navigation_node $parentnode,
    context_coursecat $context
): void {
    if (!has_all_capabilities(['local/cohortmembership:manage', 'moodle/cohort:assign'], $context)) {
        return;
    }
    $parentnode->add(
        get_string('pluginname', 'local_cohortmembership'),
        new moodle_url('/local/cohortmembership/index.php', ['contextid' => $context->id]),
        navigation_node::TYPE_SETTING,
        null,
        'local_cohortmembership',
        new pix_icon('i/cohort', '')
    );
}

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
 * Index file.
 *
 * @package   local_cohortmembership
 * @copyright Thomas Korner <thomas.korner@edu.zh.ch>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->libdir . '/csvlib.class.php');
require_once($CFG->dirroot . '/cohort/lib.php');

// Two entry points share this page: the site-level admin page (no contextid) and
// a course category's own page (contextid of the category), reached from the
// category's "More" menu, for category managers without site-level rights.
$contextid = optional_param('contextid', 0, PARAM_INT);
$context = $contextid ? context::instance_by_id($contextid, MUST_EXIST) : context_system::instance();

if ($context->contextlevel == CONTEXT_SYSTEM) {
    // Sets up require_login() plus the capability check for 'local/cohortmembership:manage'
    // declared in settings.php, plus the admin breadcrumb/nav highlighting a manual
    // require_login()/set_url() never gets.
    admin_externalpage_setup('local_cohortmembership');
    $scope = null;
    $baseurl = new moodle_url('/local/cohortmembership/index.php');
    $cancelurl = new moodle_url('/admin/search.php', ['query' => 'Cohort Membership']);
} else if ($context->contextlevel == CONTEXT_COURSECAT) {
    require_login();
    // Throws for a category the user cannot see (e.g. hidden without viewhiddencategories),
    // as core's own category pages do.
    core_course_category::get($context->instanceid);
    $baseurl = new moodle_url('/local/cohortmembership/index.php', ['contextid' => $context->id]);
    $PAGE->set_context($context);
    $PAGE->set_url($baseurl);
    $PAGE->set_pagelayout('admin');
    // Category name as page heading, category active in the navigation (as core's cohort/index.php).
    core_course_category::page_setup();
    if ($node = $PAGE->settingsnav->find('local_cohortmembership', navigation_node::TYPE_SETTING)) {
        $node->make_active();
    }
    require_capability('local/cohortmembership:manage', $context);
    // Restricts every row to cohorts in this category or below (see cohort_resolver).
    $scope = $context;
    // Back to core's cohort list where the user may open it, else to the category itself.
    $cancelurl = has_any_capability(['moodle/cohort:view', 'moodle/cohort:manage'], $context)
        ? new moodle_url('/cohort/index.php', ['contextid' => $context->id])
        : new moodle_url('/course/index.php', ['categoryid' => $context->instanceid]);
} else {
    throw new moodle_exception('invalidcontext');
}
require_capability('moodle/cohort:assign', $context);

// Handle secure CSV download of the last run's results (stored in a per-session
// temp file, see report_store), before any HTML output: csv_export_writer::
// download_file() sends its own headers and must run before $OUTPUT->header() does.
$download = optional_param('download', 0, PARAM_BOOL);
if ($download) {
    require_sesskey(); // CSRF protection for the download action.

    $stored = \local_cohortmembership\local\report_store::load(sesskey());
    if ($stored !== null && ($stored['contextid'] ?? null) === $context->id) {
        $export = new csv_export_writer();
        $export->set_filename('cohort_membership_results');
        $export->add_data(['username', 'cohortid', 'cohortidnumber', 'operation', 'status', 'cohortsyncwarning']);

        foreach ($stored['rows'] as $r) {
            $export->add_data([
                \core\dataformat::escape_spreadsheet_formula($r['username'] ?? ''),
                isset($r['cohortid']) ? (string)$r['cohortid'] : '',
                \core\dataformat::escape_spreadsheet_formula($r['cohortidnumber'] ?? ''),
                $r['operation'] ?? '',
                $r['status_readable'] ?? ($r['status'] ?? ''),
                !empty($r['cohortsync_warning']) ? get_string('yes') : get_string('no'),
            ]);
        }
        // The download consumes the stored report; a second click just falls through to the form.
        \local_cohortmembership\local\report_store::delete(sesskey());
        $export->download_file();
        exit;
    }
    // Nothing to download for this page (already downloaded, expired, or produced on the
    // other entry point): say so below instead of silently showing the empty form.
    $reportmissing = true;
}

$PAGE->set_title(get_string('pluginname', 'local_cohortmembership'));
if ($scope === null) {
    // Heading is left empty here and printed below via heading_with_help() instead: set_heading()
    // sanitises its argument (format_string()/clean_text()), which strips the data-bs-* attributes
    // the help icon's popover needs, silently turning it into a non-interactive icon. On a category
    // page the heading stays the category name set by core_course_category::page_setup().
    $PAGE->set_heading('');
}

echo $OUTPUT->header();
echo $OUTPUT->heading_with_help(
    get_string('pageheading', 'local_cohortmembership'),
    'pluginname',
    'local_cohortmembership'
);
if (!empty($reportmissing)) {
    echo $OUTPUT->notification(get_string('error_noreport', 'local_cohortmembership'), 'warning');
}

// Load the upload form (namespaced moodleform subclass).
// Explicit action: the default strips the query string and would lose contextid on submit.
$mform = new \local_cohortmembership\form\upload_form($baseurl);

// Standard moodleform flow.
if ($mform->is_cancelled()) {
    redirect($cancelurl);
} else if ($data = $mform->get_data()) {
    // Form includes sesskey; still enforce explicitly for clarity.
    require_sesskey();

    // Read uploaded CSV content.
    $filecontent = $mform->get_file_content('csvfile');
    if (!$filecontent) {
        echo $OUTPUT->notification(get_string('error_nofile', 'local_cohortmembership'), 'error');
        $mform->display();
        echo $OUTPUT->footer();
        exit;
    }

    // Prepare CSV reader.
    $iid = csv_import_reader::get_new_iid('local_cohortmembership');
    $cir = new csv_import_reader($iid, 'local_cohortmembership');

    $encoding = 'utf-8';
    $delimiter = $data->delimiter ?? 'comma'; // Choices 'comma'|'semicolon'|'tab' as provided by core.
    if ($cir->load_csv_content($filecontent, $encoding, $delimiter) === false) {
        echo $OUTPUT->notification($cir->get_error() ?: get_string('error_headers', 'local_cohortmembership'), 'error');
        $cir->close();
        $cir->cleanup();
        $mform->display();
        echo $OUTPUT->footer();
        exit;
    }

    // Read header columns and initialise iterator.
    // get_columns() returns false (not null) on failure, so ?? [] would not catch it.
    $columns = array_map('strtolower', $cir->get_columns() ?: []);
    $cir->init();

    // Validate required headers. The full sync/delta + column validation
    // happens inside processor::process(); this is just enough to build rows.
    $hasid = in_array('cohortid', $columns, true);
    $hasidnumber = in_array('cohortidnumber', $columns, true);
    $hasoperation = in_array('operation', $columns, true);
    if (!in_array('username', $columns, true) || (!$hasid && !$hasidnumber)) {
        echo $OUTPUT->notification(get_string('error_headers', 'local_cohortmembership'), 'error');
        $cir->close();
        $cir->cleanup();
        $mform->display();
        echo $OUTPUT->footer();
        exit;
    }

    // Map column names to indices for fast lookup.
    $colmap = array_flip($columns);
    $standardise = !empty($data->standardise);
    $dryrun = !empty($data->dryrun);

    // Collect normalized rows (transport format for the processor).
    $rows = [];
    while ($row = $cir->next()) {
        $rec = [
            'username' => trim((string)($row[$colmap['username']] ?? '')),
        ];
        if ($hasid) {
            $rawid = trim((string)($row[$colmap['cohortid']] ?? ''));
            if ($rawid !== '' && ctype_digit($rawid)) {
                $rec['cohortid'] = (int)$rawid;
            } else {
                $rec['cohortid_invalid'] = true;
            }
        }
        if ($hasidnumber) {
            $rec['cohortidnumber'] = trim((string)($row[$colmap['cohortidnumber']] ?? ''));
        }
        if ($hasoperation) {
            $rec['operation'] = trim((string)($row[$colmap['operation']] ?? ''));
        }
        $rows[] = $rec;
    }
    $cir->close();
    $cir->cleanup();

    // Execute business logic via the service class (unit-testable).
    $payload = \local_cohortmembership\local\processor::process($rows, [
        'standardise' => $standardise,
        'dryrun' => $dryrun,
        'scope' => $scope,
    ]);

    // A file-level validation failure means nothing was processed at all.
    if ($payload['validation_error'] !== null) {
        echo $OUTPUT->notification(get_string($payload['validation_error'], 'local_cohortmembership'), 'error');
        $mform->display();
        echo $OUTPUT->footer();
        exit;
    }

    $results = $payload['results'];
    $counters = $payload['counters'];

    // Human-readable status strings and minimal sanitising before templating.
    foreach ($results as &$r) {
        $r['status_readable'] = get_string($r['status'], 'local_cohortmembership');
        if ($dryrun) {
            $r['status_readable'] = get_string('dryrun_status', 'local_cohortmembership', $r['status_readable']);
        }
        // Mustache escapes by default; preparing strings defensively is fine.
        $r['username'] = $r['username'] ?? '';
        $r['cohortid'] = isset($r['cohortid']) ? (string)$r['cohortid'] : '';
        $r['cohortidnumber'] = $r['cohortidnumber'] ?? '';
        $r['operation'] = $r['operation'] ?? '';
    }
    unset($r);

    // Persist for CSV download and for rendering the report after the redirect
    // below. A temp file rather than $SESSION: a large run (tens of thousands
    // of rows) would otherwise bloat the session store for the rest of the
    // user's session, not just for this one report.
    \local_cohortmembership\local\report_store::save(sesskey(), [
        'rows' => $results,
        'counters' => $counters,
        'legacy_format' => $payload['legacy_format'],
        'cohortidnumber_ignored' => $payload['cohortidnumber_ignored'],
        'dryrun' => $dryrun,
        // A report is only shown and downloaded on the page that produced it.
        'contextid' => $context->id,
    ]);

    // Post/Redirect/Get: without this, refreshing the browser after a live run
    // would resubmit the form and re-run the import (sync would re-run its
    // implicit removals, every event re-fires). Render from the stored report instead.
    redirect(new moodle_url($baseurl, ['report' => 1]));
} else if (
    optional_param('report', 0, PARAM_BOOL)
        && ($stored = \local_cohortmembership\local\report_store::load(sesskey()))
        && ($stored['contextid'] ?? null) === $context->id
) {
    // Rendering the outcome of the run that redirected here.

    if ($stored['legacy_format']) {
        echo $OUTPUT->notification(get_string('legacy_format_notice', 'local_cohortmembership'), 'info');
    }
    if ($stored['cohortidnumber_ignored']) {
        echo $OUTPUT->notification(get_string('cohortidnumber_ignored_notice', 'local_cohortmembership'), 'info');
    }
    if ($stored['dryrun']) {
        echo $OUTPUT->notification(get_string('dryrun_notice', 'local_cohortmembership'), 'info');
    }

    // Render the summary + table via plugin renderer and Mustache template.
    $renderer = $PAGE->get_renderer('local_cohortmembership');
    echo $renderer->report(new \local_cohortmembership\output\report($stored['rows'], $stored['counters']));

    // Download button (protected by sesskey) and back-to-upload button.
    $dlurl = new moodle_url($baseurl, ['download' => 1, 'sesskey' => sesskey()]);
    echo $OUTPUT->single_button($dlurl, get_string('download', 'local_cohortmembership'));
    echo $OUTPUT->single_button($baseurl, get_string('uploadcsv', 'local_cohortmembership'));
} else {
    // First page load: show the upload form.
    $mform->display();
}

echo $OUTPUT->footer();

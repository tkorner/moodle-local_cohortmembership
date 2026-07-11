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

require_once('../../config.php');

require_login();
$context = context_system::instance();

// Page access capability (plugin) + cohort assignment capability (core).
require_capability('local/cohortmembership:manage', $context);
require_capability('moodle/cohort:assign', $context);

$PAGE->set_url(new moodle_url('/local/cohortmembership/index.php'));
$PAGE->set_context($context);
$PAGE->set_title(get_string('pluginname', 'local_cohortmembership'));
$PAGE->set_heading(
    get_string('pageheading', 'local_cohortmembership') . $OUTPUT->help_icon('pluginname', 'local_cohortmembership')
);

echo $OUTPUT->header();

// Libs needed below.
require_once($CFG->libdir . '/csvlib.class.php');
require_once($CFG->dirroot . '/cohort/lib.php');

// Load the upload form (namespaced moodleform subclass).
$mform = new \local_cohortmembership\form\upload_form();

// Handle secure CSV download of the last run's results (stored in session).
$download = optional_param('download', 0, PARAM_BOOL);
if ($download) {
    require_sesskey(); // CSRF protection for the download action.

    if (!empty($SESSION->local_cohortmembership_report)) {
        $rows = $SESSION->local_cohortmembership_report['rows'] ?? [];
        $export = new csv_export_writer();
        $export->set_filename('cohort_membership_results');
        $export->add_data(['username', 'cohortid', 'cohortidnumber', 'operation', 'status', 'cohortsyncwarning']);

        foreach ($rows as $r) {
            $export->add_data([
                $r['username'] ?? '',
                isset($r['cohortid']) ? (string)$r['cohortid'] : '',
                $r['cohortidnumber'] ?? '',
                $r['operation'] ?? '',
                $r['status_readable'] ?? ($r['status'] ?? ''),
                !empty($r['cohortsync_warning']) ? get_string('yes') : get_string('no'),
            ]);
        }
        $export->download_file();
        exit;
    }
}

// Standard moodleform flow.
if ($mform->is_cancelled()) {
    redirect(new moodle_url('/admin/search.php?query=Cohort%20Membership'));
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
    $cir->load_csv_content($filecontent, $encoding, $delimiter);

    // Read header columns and initialise iterator.
    $columns = array_map('strtolower', $cir->get_columns() ?? []);
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

    // Persist for CSV download.
    $SESSION->local_cohortmembership_report = ['rows' => $results, 'counters' => $counters];

    // Informational notices.
    if ($payload['legacy_format']) {
        echo $OUTPUT->notification(get_string('legacy_format_notice', 'local_cohortmembership'), 'info');
    }
    if ($payload['cohortidnumber_ignored']) {
        echo $OUTPUT->notification(get_string('cohortidnumber_ignored_notice', 'local_cohortmembership'), 'info');
    }
    if ($dryrun) {
        echo $OUTPUT->notification(get_string('dryrun_notice', 'local_cohortmembership'), 'info');
    }

    // Render the summary + table via plugin renderer and Mustache template.
    $renderer = $PAGE->get_renderer('local_cohortmembership');
    echo $renderer->report(new \local_cohortmembership\output\report($results, $counters));

    // Download button (protected by sesskey) and back-to-upload button.
    $dlurl = new moodle_url('/local/cohortmembership/index.php', ['download' => 1, 'sesskey' => sesskey()]);
    echo $OUTPUT->single_button($dlurl, get_string('download', 'local_cohortmembership'));
    echo $OUTPUT->single_button(
        new moodle_url(
            '/local/cohortmembership/index.php'
        ),
        get_string('uploadcsv', 'local_cohortmembership')
    );
} else {
    // First page load: show the upload form.
    $mform->display();
}

echo $OUTPUT->footer();

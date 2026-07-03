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
 * CLI script to remove users from cohorts by CSV mapping.
 *
 * @package   local_cohortmembership
 * @copyright Thomas Korner <thomas.korner@edu.zh.ch>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/csvlib.class.php');
require_once($CFG->dirroot . '/cohort/lib.php');

use local_cohortmembership\local\processor;

// Parse CLI options.
list($options, $unrecognized) = cli_get_params(
    [
        'csv' => null,
        'report' => null,
        'dry-run' => false,
        'username-standardise' => false,
        'delimiter' => 'comma', // Options: comma | semicolon | tab  (matches core).
        'help' => false,
    ],
    [
        'h' => 'help',
    ]
);

$help = "Cohort Unenroller (CLI)
Removes users from cohorts by CSV mapping (username + cohort id or idnumber).

Options:
  --csv=PATH                        Path to CSV input file (required)
  --report=PATH                     Optional path to write a result CSV (status per row)
  --dry-run                         Validate only; do not change the database
  --username-standardise            Trim + lowercase usernames before lookup
  --delimiter=comma|semicolon|tab   CSV delimiter (default: comma)
  -h, --help                        Show this help

CSV headers (one of):
  username,cohortid
  username,cohortidnumber

Examples:
  php local/cohortmembership/cli/unenrol.php --csv=/data/in.csv --dry-run --delimiter=tab
  php local/cohortmembership/cli/unenrol.php --csv=/data/in.csv --report=/data/out.csv --delimiter=semicolon
";

if (!empty($options['help'])) {
    echo $help;
    exit(0);
}

if (empty($options['csv'])) {
    cli_error("Missing required --csv option.\n\n" . $help, 1);
}

$csvpath = $options['csv'];
$reportpath = $options['report'] ?? null;
$dryrun = !empty($options['dry-run']);
$standardise = !empty($options['username-standardise']);
$delimiter = $options['delimiter'] ?? 'comma';

// Validate delimiter using core list (same keys as upload users).
$allowed = array_keys(csv_import_reader::get_delimiter_list());
if (!in_array($delimiter, $allowed, true)) {
    cli_error("Invalid --delimiter. Allowed: " . implode('|', $allowed), 1);
}

// Safety: require site admin for membership changes.
require_admin();

// Read CSV from disk.
if (!is_readable($csvpath)) {
    cli_error("CSV not readable: {$csvpath}", 2);
}
$content = file_get_contents($csvpath);
if ($content === false || $content === '') {
    cli_error("CSV is empty or cannot be read: {$csvpath}", 4);
}

// Initialise CSV reader.
$iid = csv_import_reader::get_new_iid('local_cohortmembership_cli');
$cir = new csv_import_reader($iid, 'local_cohortmembership_cli');

$encoding = 'utf-8';
$cir->load_csv_content($content, $encoding, $delimiter);

// Read header columns and initialise iterator (required before next()).
$columns = array_map('strtolower', $cir->get_columns() ?? []);
$cir->init();

// Validate required headers.
$hasid = in_array('cohortid', $columns, true);
$hasidnumber = in_array('cohortidnumber', $columns, true);
if (!in_array('username', $columns, true) || (!$hasid && !$hasidnumber)) {
    $cir->close();
    $cir->cleanup();
    cli_error("Invalid headers. Expect 'username,cohortid' or 'username,cohortidnumber'.", 5);
}

// Map columns and collect normalised records for the processor.
$colmap = array_flip($columns);
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
    $rows[] = $rec;
}

$cir->close();
$cir->cleanup();

// Execute business logic.
$payload = processor::process($rows, [
    'standardise' => $standardise,
    'dryrun' => $dryrun,
]);

$results = $payload['results'];
$counters = $payload['counters'];

// Print summary.
cli_writeln("Cohort Unenroller (CLI) finished.");
cli_writeln("- Total rows    : {$counters['total']}");
cli_writeln("- Valid rows    : {$counters['valid']}");
cli_writeln("- Processed     : {$counters['processed']}");
cli_writeln("- Skipped       : {$counters['skipped']}");
cli_writeln("- Error rows    : {$counters['errors']}");
cli_writeln("- Delimiter     : {$delimiter}");
if ($dryrun) {
    cli_writeln("- Mode          : DRY RUN (no changes)");
}

// Optional CSV report.
if (!empty($reportpath)) {
    $dir = dirname($reportpath);
    if (!is_dir($dir) || !is_writable($dir)) {
        cli_problem("Report path not writable: {$reportpath}");
    } else if ($fp = fopen($reportpath, 'w')) {
        // Human-friendly English statuses (CLI context).
        $map = [
            'status_removed'        => 'Removed',
            'status_notmember'      => 'User not a member',
            'status_usernotfound'   => 'User not found',
            'status_cohortnotfound' => 'Cohort not found',
            'status_duplicate'      => 'Duplicate in file',
            'status_invalid'        => 'Invalid data',
        ];

        fputcsv($fp, ['username', 'cohortid', 'cohortidnumber', 'status']);
        foreach ($results as $r) {
            fputcsv($fp, [
                $r['username'] ?? '',
                isset($r['cohortid']) ? (string)$r['cohortid'] : '',
                $r['cohortidnumber'] ?? '',
                $map[$r['status']] ?? $r['status'],
            ]);
        }
        fclose($fp);
        cli_writeln("Report written to: {$reportpath}");
    } else {
        cli_problem("Failed to open report for writing: {$reportpath}");
    }
}

// Exit with 0 if no error rows, otherwise 2 (suitable for cron/monitoring).
exit($counters['errors'] > 0 ? 2 : 0);

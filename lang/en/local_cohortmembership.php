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
 * Lang file.
 *
 * @package   local_cohortmembership
 * @copyright Thomas Korner <thomas.korner@edu.zh.ch>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$string['cohortidnumber_ignored_notice'] = 'Both cohort columns were present: "cohortid" was used, "cohortidnumber" was ignored.';
$string['cohortmembership:manage'] = 'Manage cohort memberships (add/remove/sync via CSV)';
$string['cohortsync_warning_notice'] = 'Removing a user from a cohort used by an active "Cohort sync" enrolment '
    . 'method can also unenrol them from the linked course, along with their grades and group memberships. '
    . 'Consider taking a database backup before running this in live mode.';
$string['cohortsynccolumn'] = 'Cohort-sync';
$string['csvhelp'] = 'CSV headers: username,(cohortid OR cohortidnumber)[,operation]. operation is one of '
    . 'add/del/sync; if the column is omitted, every row is treated as del.';
$string['delimiter'] = 'CSV delimiter';
$string['download'] = 'Download CSV';
$string['dryrun'] = 'Dry run (no changes)';
$string['dryrun_notice'] = 'Dry run: no changes were made.';
$string['dryrun_status'] = '(dry run) {$a}';
$string['error_bad_operation'] = 'Unknown operation';
$string['error_empty_file'] = 'The CSV file contains no data rows.';
$string['error_headers'] = 'Missing headers: expect username,cohortid or username,cohortidnumber';
$string['error_missing_cohort_column'] = 'Missing headers: expect a "cohortid" or "cohortidnumber" column.';
$string['error_mixed_operations'] = 'A file must be either pure "sync" or pure "add"/"del", not a mix of both.';
$string['error_nofile'] = 'Please upload a CSV file.';
$string['exampleaddcsv'] = 'Example CSV (add/del)';
$string['exampleaddcsv_help'] = 'Download this file, open it with a text or spreadsheet editor, and edit the '
    . 'operation/username/cohortidnumber values to match your own add/del rows before uploading it.';
$string['examplesynccsv'] = 'Example CSV (sync)';
$string['examplesynccsv_help'] = 'Download this file, open it with a text or spreadsheet editor, and edit the '
    . 'username/cohortidnumber values to match your own sync rows before uploading it. Remember: sync rows for '
    . 'the same user list their full target cohort set.';
$string['legacy_format_notice'] = 'No "operation" column found: all rows were processed as "del" for backward compatibility.';
$string['pageheading'] = 'Cohort Membership';
$string['pluginname'] = 'Cohort Membership';
$string['pluginname_help'] = 'Manages cohort memberships (add, remove, sync) via CSV upload. See the '
    . '[plugin repository on GitHub](https://github.com/tkorner/moodle-local_cohortmembership) for the full '
    . 'documentation, CSV format examples and specification.';
$string['privacy:metadata'] = 'This plugin does not store any personal data.';
$string['rows_errors'] = 'Rows with errors';
$string['rows_processed'] = 'Rows processed';
$string['rows_skipped'] = 'Rows skipped';
$string['rows_total'] = 'Rows in file';
$string['rows_valid'] = 'Valid rows';
$string['rows_warnings'] = 'Removals with a cohort-sync warning';
$string['standardise_usernames'] = 'Standardise usernames (trim + lowercase)';
$string['status_added'] = 'Added';
$string['status_alreadymember'] = 'Already a member';
$string['status_cohortambiguous'] = 'Cohort idnumber is ambiguous (used by more than one cohort)';
$string['status_cohortnotfound'] = 'Cohort not found';
$string['status_duplicate'] = 'Duplicate in file';
$string['status_invalid'] = 'Invalid data';
$string['status_notmember'] = 'User not a member';
$string['status_removed'] = 'Removed';
$string['status_usernotfound'] = 'User not found';
$string['submit'] = 'Process CSV';
$string['summary'] = 'Summary';
$string['uploadcsv'] = 'Upload CSV';

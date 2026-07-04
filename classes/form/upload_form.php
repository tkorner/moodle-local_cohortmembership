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
 * Upload form file.
 *
 * @package   local_cohortmembership
 * @copyright Thomas Korner <thomas.korner@edu.zh.ch>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cohortmembership\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');
require_once($CFG->libdir . '/csvlib.class.php');

/**
 * Upload form for cohort unenroller CSV.
 */
class upload_form extends \moodleform {
    /**
     * Form definition.
     *
     * @return void
     * @throws \coding_exception
     */
    public function definition() {
        $mform = $this->_form;

        // Cohort-sync risk warning (SPEC §2): removal can trigger a course unenrolment.
        $mform->addElement(
            'static',
            'cohortsyncwarning',
            '',
            \html_writer::div(get_string('cohortsync_warning_notice', 'local_cohortmembership'), 'alert alert-warning')
        );

        // CSV file input.
        $mform->addElement(
            'filepicker',
            'csvfile',
            get_string('uploadcsv', 'local_cohortmembership'),
            null,
            ['maxbytes' => 0, 'accepted_types' => ['.csv']]
        );
        $mform->addRule('csvfile', null, 'required', null, 'client');

        // Help text.
        $mform->addElement('static', 'csvhelp', '', get_string('csvhelp', 'local_cohortmembership'));

        // CSV delimiter (same as core "Upload users").
        $mform->addElement(
            'select',
            'delimiter',
            get_string('csvdelimiter', 'admin'),
            \csv_import_reader::get_delimiter_list()
        );
        $mform->setDefault('delimiter', 'comma');

        // Username normalisation (trim + lowercase).
        $mform->addElement('advcheckbox', 'standardise', get_string('standardise_usernames', 'local_cohortmembership'), '');

        // Dry run (no DB changes).
        $mform->addElement('advcheckbox', 'dryrun', get_string('dryrun', 'local_cohortmembership'), '');

        // Submit.
        $mform->addElement('submit', 'submitbutton', get_string('submit', 'local_cohortmembership'));
    }
}

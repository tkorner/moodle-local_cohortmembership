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
 * Sprachdatei (Deutsch).
 *
 * @package   local_cohortmembership
 * @copyright Thomas Korner <thomas.korner@edu.zh.ch>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$string['cohortidnumber_ignored_notice'] = 'Es waren beide Kohorten-Spalten vorhanden: "cohortid" wurde verwendet, '
    . '"cohortidnumber" wurde ignoriert.';
$string['cohortmembership:manage'] = 'Kohorten-Mitgliedschaften verwalten (Hinzufügen/Entfernen/Sync per CSV)';
$string['cohortsync_warning_notice'] = 'Das Entfernen eines Nutzers aus einer Kohorte, die von einer aktiven '
    . '"Cohort sync"-Einschreibemethode verwendet wird, kann ihn auch aus dem verknüpften Kurs abmelden - inklusive '
    . 'Bewertungen und Gruppenzugehörigkeiten. Erstellen Sie vor einem echten Lauf ein Datenbank-Backup.';
$string['csvhelp'] = 'CSV-Spalten: username,(cohortid ODER cohortidnumber)[,operation]. operation ist eines von '
    . 'add/del/sync; fehlt die Spalte, wird jede Zeile als del behandelt.';
$string['download'] = 'CSV herunterladen';
$string['dryrun'] = 'Testlauf (keine Änderungen)';
$string['dryrun_notice'] = 'Testlauf: es wurden keine Änderungen vorgenommen.';
$string['dryrun_status_prefix'] = '(Testlauf)';
$string['error_bad_operation'] = 'Unbekannte Operation';
$string['error_headers'] = 'Fehlende Spalten: erwartet werden username,cohortid oder username,cohortidnumber';
$string['error_missing_cohort_column'] = 'Fehlende Spalten: erwartet wird eine "cohortid"- oder "cohortidnumber"-Spalte.';
$string['error_mixed_operations'] = 'Eine Datei muss entweder rein "sync" oder rein "add"/"del" sein, keine Mischung.';
$string['error_nofile'] = 'Bitte eine CSV-Datei hochladen.';
$string['legacy_format_notice'] = 'Keine "operation"-Spalte gefunden: alle Zeilen wurden aus Kompatibilitätsgründen '
    . 'als "del" verarbeitet.';
$string['menulink'] = 'Kohorten-Mitgliedschaft';
$string['pageheading'] = 'Kohorten-Mitgliedschaft';
$string['pluginname'] = 'Kohorten-Mitgliedschaft';
$string['privacy:metadata'] = 'Dieses Plugin speichert keine personenbezogenen Daten.';
$string['results'] = 'Ergebnisse';
$string['rows_errors'] = 'Zeilen mit Fehlern';
$string['rows_processed'] = 'Verarbeitete Zeilen';
$string['rows_skipped'] = 'Übersprungene Zeilen';
$string['rows_total'] = 'Zeilen in der Datei';
$string['rows_valid'] = 'Gültige Zeilen';
$string['rows_warnings'] = 'Entfernungen mit Cohort-Sync-Warnung';
$string['standardise_usernames'] = 'Nutzernamen normalisieren (trimmen + kleinschreiben)';
$string['status_added'] = 'Hinzugefügt';
$string['status_alreadymember'] = 'Bereits Mitglied';
$string['status_cohortnotfound'] = 'Kohorte nicht gefunden';
$string['status_duplicate'] = 'Duplikat in der Datei';
$string['status_invalid'] = 'Ungültige Daten';
$string['status_notmember'] = 'Nutzer ist kein Mitglied';
$string['status_removed'] = 'Entfernt';
$string['status_usernotfound'] = 'Nutzer nicht gefunden';
$string['submit'] = 'CSV verarbeiten';
$string['summary'] = 'Zusammenfassung';
$string['uploadcsv'] = 'CSV hochladen';

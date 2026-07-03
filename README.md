[![Moodle plugin CI](https://github.com/tkorner/moodle-local_cohortunenroller/actions/workflows/moodle-ci.yml/badge.svg)](https://github.com/tkorner/moodle-local_cohortunenroller/actions/workflows/moodle-ci.yml)
# Cohort Membership (Moodle local plugin)

Manages cohort **memberships** via CSV: add users to cohorts, remove them, or
sync a user's cohort memberships to a desired state. UI-based (CSV upload)
with dry-run, HTML report and CSV download; a CLI entry point is also
provided.

Moodle core has no built-in way to remove users from cohorts via CSV or UI
(open tracker request [MDL-61007](https://tracker.moodle.org/browse/MDL-61007)).
Add is already covered by core's `tool_uploaduser`; this plugin unifies add,
remove and sync behind one workflow, one report, and one dry-run.

> **Status:** this plugin is being migrated from the earlier
> `local_cohortunenroller` (remove-only) to the broader `local_cohortmembership`
> (add/del/sync). The component name has already been renamed to
> `local_cohortmembership`; the `add` and `sync` operations are still being
> implemented. Until the host folder is renamed from `cohortunenroller` to
> `cohortmembership` and the plugin reinstalled, Moodle will refuse to load it
> (component/folder mismatch) — that is expected during this transition.

## Design principles
- **Never auto-creates cohorts.** An unknown cohort `idnumber`/`id` is an error
  row in the report, never a new cohort (unlike Moodle core's `cohortN` upload
  columns).
- **Dry-run is the default.**
- All membership changes go through Moodle's `cohort_add_member()` /
  `cohort_remove_member()` — no direct database writes.
- Removing a user from a cohort can trigger a course unenrolment if an active
  `enrol_cohort` instance uses that cohort (loss of grades/groups). The report
  flags affected removal rows.

## CSV format
- `operation,username,cohortidnumber` (or `cohortid`), one of `add`, `del`,
  `sync` per row.
- A file without an `operation` column is treated as all-`del` rows
  (backwards compatible with the original plugin).
- `sync` rows are aggregated per user against the union of all cohorts named
  anywhere in the file; cohorts outside that "file universe" are never
  touched. A file may not mix `sync` rows with `add`/`del` rows.

See `SPEC-cohortmembership.md` for the full specification.

## Install
1. Place folder in `moodle/local/cohortmembership`.
2. Site administration → Notifications.
3. Open: Site administration → Plugins → Local plugins → Cohort Membership.

## CLI
```bash
php local/cohortmembership/cli/unenrol.php --csv=/path/in.csv [--report=/path/out.csv] [--dry-run] [--username-standardise]
```

## Tests & CI
This plugin uses Moodle Plugin CI on GitHub Actions:
- PHP lint, Moodle coding style (moodle-cs), PHPUnit, optional Behat.

Run locally:

[![Moodle plugin CI](https://github.com/tkorner/moodle-lokal_cohortmembership/actions/workflows/moodle-ci.yml/badge.svg)](https://github.com/tkorner/moodle-lokal_cohortmembership/actions/workflows/moodle-ci.yml)

# Cohort Membership (Moodle local plugin)

Manages cohort **memberships** via CSV: add users to cohorts, remove them, or
sync a user's cohort memberships to a desired state. UI-based (CSV upload)
with dry-run, HTML report and CSV download; two CLI entry points are also
provided.

## Why this plugin exists

Moodle core has no built-in way to remove users from cohorts via CSV or the
UI — this is an open tracker request,
[MDL-61007](https://tracker.moodle.org/browse/MDL-61007), open since 2017.
Core's `tool_uploaduser` already covers *add* via `cohort1,cohort2,...`
columns, and the `core_cohort_delete_cohort_members` web service covers
*remove* programmatically, but neither has a UI, a dry-run, or a report.

This plugin fills that gap and, since a single CSV-driven workflow already
existed for removal, extends it to cover **add** and **sync** as well - one
CSV format, one report, one dry-run, for all three operations. It began as
`local_cohortunenroller` (remove-only) and was renamed and extended into
`local_cohortmembership`.

## Design principles / guardrails

- **Never auto-creates cohorts.** An unknown cohort `idnumber`/`id` is an
  error row in the report, never a new cohort. This is a deliberate
  deviation from Moodle core, which silently creates a new cohort for an
  unrecognised `cohortN` value in `tool_uploaduser` (MDL-41639) - a frequent
  source of duplicate-cohort chaos.
- **Dry-run is the default** (the checkbox is pre-selected in the UI).
- All membership changes go exclusively through Moodle's
  `cohort_add_member()` / `cohort_remove_member()` - never a direct database
  write.
- A single bad CSV row never aborts the whole run; only file-level problems
  (bad headers, mixing `sync` with `add`/`del`) reject the file before any
  row is processed.
- Every CSV row produces exactly one report line (`sync`-driven removals
  that are not named by any row get an additional, clearly synthetic line).
- User matching is by `username` only (not `idnumber`/email) - out of scope
  for v1.

### ⚠️ Cohort-sync warning

Removing a user from a cohort is **not** limited to the `cohort_members`
table. If a course has an active **Cohort sync** enrolment method
(`enrol_cohort`) pointing at that cohort, removing the membership also
**unenrols the user from that course** - taking their grades, group
memberships, and other course-specific data with it.

Every removal row in the report (`del` or `sync`) is flagged if the cohort
is used by at least one active `enrol_cohort` instance. **Take a database
backup before running a removal in live mode** if you are not certain which
cohorts feed course enrolments.

## CSV format

One row per operation. Header row is required (lower case).

```
operation,username,cohortidnumber
add,hans.muster,kurs-inf-2026
del,hans.muster,kurs-inf-2023
```

- Exactly one of `cohortidnumber` (text) or `cohortid` (numeric) must be
  present as a column. If both are present, `cohortid` wins and the report
  notes that `cohortidnumber` was ignored.
- `operation` is one of `add`, `del`, `sync`. **If the column is omitted
  entirely**, every row is treated as `del` (backward compatible with the
  original `local_cohortunenroller` CSV format).
- A file must be either pure `sync`, or pure `add`/`del` - mixing `sync`
  rows with `add`/`del` rows in the same file is rejected before any row is
  processed.

### `sync`: bring a user's memberships to an exact state

`sync` works **per user, across all of that user's rows in the file**, not
row by row:

```
operation,username,cohortidnumber
sync,hans.muster,kurs-inf-2026
sync,hans.muster,basis-alle
sync,anna.beispiel,kurs-bwl-2026
```

- The **file universe** is the union of every cohort named anywhere in the
  file: `{kurs-inf-2026, basis-alle, kurs-bwl-2026}`.
- For `hans.muster`, the target state is `{kurs-inf-2026, basis-alle}`. If
  he is also a member of `kurs-bwl-2026` (in the universe, but not in his
  rows), that membership is **removed**. If he is a member of some
  `sonstiges-2020` cohort that never appears in the file, it is **never
  touched** - there is no "full replace" against a user's entire cohort
  list, only against the cohorts the file actually mentions.
- `anna.beispiel`'s target is `{kurs-bwl-2026}`, computed independently of
  `hans.muster`'s rows.

This scope rule is what makes `sync` safe to run repeatedly without a
managed/ownership flag on cohorts.

## Using the UI

Site administration → Plugins → Local plugins → **Cohort Membership**.
Upload a CSV, review the dry-run report, then re-run with dry-run unchecked
to apply. The report and the CSV download both show `operation`, `status`
and whether cohort-sync was affected per row; a summary line counts
removals with a cohort-sync warning.

## CLI

Two scripts are provided:

- **`cli/membership.php`** - the current entry point, supporting the full
  `add`/`del`/`sync` format described above (with or without the
  `operation` column).

  ```bash
  php local/cohortmembership/cli/membership.php --csv=/path/in.csv \
    [--report=/path/out.csv] [--dry-run] [--username-standardise] \
    [--delimiter=comma|semicolon|tab]
  ```

- **`cli/unenrol.php`** - the original, `del`-only script from
  `local_cohortunenroller`, kept unchanged for backward compatibility with
  existing cron jobs/scripts. New integrations should use
  `cli/membership.php` instead.

  ```bash
  php local/cohortmembership/cli/unenrol.php --csv=/path/in.csv \
    [--report=/path/out.csv] [--dry-run] [--username-standardise]
  ```

Both exit `0` on a clean run, a non-zero code on error rows or a
file-level validation failure, and require the account running PHP CLI to
already have server-level access (there is no login/capability check - the
same trust model Moodle's own `admin/cli/*.php` scripts use).

## Privacy

This plugin does not store any personal data of its own: the report only
ever lives in the current user's session, for the duration needed to
display it and offer the CSV download. It implements
`\core_privacy\local\metadata\null_provider` accordingly.

## Install

1. Place the folder at `moodle/local/cohortmembership`.
2. Site administration → Notifications, to trigger the install/upgrade.
3. Open: Site administration → Plugins → Local plugins → Cohort Membership.

## Compatibility

Targets Moodle 4.5 LTS and 5.0-5.2, PHP 8.1-8.4. Verified by actually
installing the plugin against a real checkout of each Moodle 5.x point
release (not just reading changelogs) and running the full PHPUnit suite
plus a live CLI smoke test against each:

| Moodle version | Result |
|---|---|
| 5.0.8+ | ✅ PHPUnit 33/33, CLI smoke test (real DB changes verified) |
| 5.1.5+ | ✅ PHPUnit 33/33 |
| 5.2.1+ | ✅ PHPUnit 33/33 (also the primary dev/live-verification target throughout development) |

Moodle 5.1 introduced a `public/` web-root split (the actual codebase
moves one level down, e.g. `public/local/...`); this plugin has no
dependency on the installation's directory layout (no hardcoded
`dirroot`-relative paths outside the standard `$CFG->dirroot`-based
includes), so it is unaffected either way.

## Tests & CI

This plugin uses [Moodle Plugin CI](https://moodlehq.github.io/moodle-plugin-ci/)
on GitHub Actions against PHP 8.1-8.4 and Moodle 4.5 LTS, 5.0, 5.1 and 5.2,
on MariaDB and PostgreSQL: PHP lint, Moodle coding style (moodle-cs), PHPDoc
checker, upgrade savepoints, Mustache lint, PHPUnit, and (best-effort)
Behat.

Run the PHPUnit suite locally (inside a Moodle dev instance that has this
plugin installed under `local/cohortmembership`):

```bash
php admin/tool/phpunit/cli/init.php   # once, to set up the test environment
vendor/bin/phpunit --filter local_cohortmembership
```

Check coding style locally with [moodle-cs](https://github.com/moodlehq/moodle-cs):

```bash
phpcs --standard=moodle local/cohortmembership
```

## Full specification

See [`SPEC-cohortmembership.md`](SPEC-cohortmembership.md) for the complete
behavioural specification, including every validation rule and the full
PHPUnit test case list.

## License

GPL v3 or later - see [`LICENSE`](LICENSE).

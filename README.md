# Moodle Cohort Membership (`local_cohortmembership`)

[![Moodle Plugin CI](https://github.com/tkorner/moodle-local_cohortmembership/actions/workflows/moodle-ci.yml/badge.svg)](https://github.com/tkorner/moodle-local_cohortmembership/actions/workflows/moodle-ci.yml)
[![Moodle Version](https://img.shields.io/badge/Moodle-4.1%2B%20%7C%204.5%2B%20%7C%205.0%2B-orange.svg)](https://moodle.org)
[![License: GPL v3](https://img.shields.io/badge/License-GPLv3-blue.svg)](https://www.gnu.org/licenses/gpl-3.0)
[![Moodle Plugin Type](https://img.shields.io/badge/Plugin%20Type-local-green.svg)](https://docs.moodle.org/dev/Local_plugins)

**`local_cohortmembership`** is a Moodle local plugin that provides comprehensive batch management of cohort memberships via CSV uploads and CLI scripts. It allows administrators to **add**, **remove**, or **sync** user cohort assignments safely with interactive dry-runs, detailed HTML reports, and downloadable results.

---

## 🌟 Why This Plugin Exists

Moodle core lacks a native UI or CSV mechanism to remove users from cohorts—an open issue tracked under [MDL-61007](https://tracker.moodle.org/browse/MDL-61007) since 2017. While core's `tool_uploaduser` supports cohort addition (`cohort1, cohort2`), and Web Services support removal, neither provides a dedicated UI, dry-run simulation, or execution reporting.

`local_cohortmembership` fills this gap by unifying **add**, **remove**, and **exact-state sync** into a single CSV-driven workflow.

---

## ✨ Key Features

- 📄 **Unified CSV Workflow**: Perform `add`, `del` (remove), or `sync` operations in a single standardized format.
- 🛡️ **Safety-First Dry-Run**: Simulation mode is enabled by default to preview all additions and removals before modifying the database.
- 📊 **Detailed Reporting**: Interactive HTML summary reports and downloadable CSV logs for every execution.
- 💻 **CLI Integration**: Full Command-Line Interface support for scheduled automation and bulk backend operations.
- ⚠️ **Enrolment Risk Alerts**: Flags removals for cohorts tied to active **Cohort Sync** (`enrol_cohort`) enrolment methods to prevent accidental student unenrolments.
- 🔒 **Core API Compliance**: Modifies memberships exclusively through Moodle's native `cohort_add_member()` and `cohort_remove_member()` functions.

---

## 📐 Safety Guardrails & Design Principles

- **No Automatic Cohort Creation**: Unknown cohort `idnumber` or `id` entries generate an explicit error row instead of creating duplicate cohorts (fixing core `tool_uploaduser` behavior MDL-41639).
- **Default Dry-Run**: The simulation checkbox is checked by default in both UI and CLI.
- **Fault-Tolerant Row Processing**: Bad CSV rows are logged individually without aborting the entire upload.
- **1-to-1 Audit Log**: Every input CSV row produces exactly one output report line.

---

## ⚠️ Important Warning: Cohort Sync Enrolments

Removing a user from a cohort is **not** limited to the `cohort_members` table. If a course uses an active **Cohort sync** enrolment method (`enrol_cohort`), removing a user from that cohort will **automatically unenrol the user from the course**—purging gradebook records, group memberships, and activity data.

> [!WARNING]
> Always run a **Dry-run** first and verify if the target cohorts are linked to active course enrolments (`enrol_cohort`).

---

## 📄 CSV Format Specifications

The CSV file requires a lowercase header row.

### Standard Format (`cohortidnumber`)
```csv
operation,username,cohortidnumber
add,hans.muster,kurs-inf-2026
del,hans.muster,kurs-inf-2023
```

### Alternative Format (`cohortid`)
```csv
operation,username,cohortid
add,hans.muster,102
del,hans.muster,88
```

- **Supported Operations**: `add`, `del`, `sync`.
- **Default Fallback**: If the `operation` column is omitted, all rows default to `del` (backward compatible with `local_cohortunenroller`).
- **`sync` Mode**: Reconciles a user's cohort memberships to match **exactly** the cohorts listed in the file for that user.

---

## 💻 CLI Usage

The plugin provides two CLI scripts for backend automation:

### 1. Batch Process CSV File
```bash
php local/cohortmembership/cli/process_csv.php --file=/path/to/memberships.csv --dry-run=1
```

### 2. Live Execution
```bash
php local/cohortmembership/cli/process_csv.php --file=/path/to/memberships.csv --dry-run=0
```

---

## 🚀 Installation & Setup

1. Clone or extract this plugin into your Moodle installation at `local/cohortmembership`:
   ```bash
   git clone https://github.com/tkorner/moodle-local_cohortmembership.git local/cohortmembership
   ```
2. Run the Moodle CLI upgrade command:
   ```bash
   php admin/cli/upgrade.php
   ```
3. Purge Moodle caches:
   ```bash
   php admin/cli/purge_caches.php
   ```
4. Access the upload tool under **Site Administration → Users → Accounts → Cohort Membership CSV Upload** (or via URL `/local/cohortmembership/upload.php`).

---

## 🧪 Testing & Quality Assurance

- **PHPUnit Tests**:
  ```bash
  vendor/bin/phpunit local_cohortmembership/tests/processor_test.php
  ```
- **Behat Acceptance Tests**:
  ```bash
  vendor/bin/behat --config /path/to/behat.yml local_cohortmembership/tests/behat/upload.feature
  ```

---

## 🔒 Privacy & GDPR Compliance

Implements the Moodle Privacy API (`\core_privacy\local\metadata\null_provider`). It does not store or process personal data independently outside of core cohort logs.

---

## 📜 License

Licensed under the [GNU General Public License v3.0 or later](http://www.gnu.org/licenses/gpl.html).  
Copyright (C) 2026 Antigravity & Contributors.

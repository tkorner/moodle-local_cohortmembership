# Claude Code Auftrag: local_cohortmembership

## Kontext

Ich bin der Autor des bestehenden Moodle-Plugins `local_cohortunenroller`
(https://github.com/tkorner/moodle-local_cohortunenroller). Es entfernt Nutzer per
CSV aus Kohorten (UI + CLI, Dry-run, HTML-Report + CSV-Download, PHPUnit + Moodle CI).

Ich möchte daraus ein erweitertes, umbenanntes Plugin `local_cohortmembership` bauen,
das drei Operationen über eine `operation`-Spalte unterstützt: `add`, `del`, `sync`.

Das alte Plugin `local_cohortunenroller` bleibt unverändert bestehen (eingefroren).
Wir bauen `local_cohortmembership` als eigenständiges neues Plugin, das den Code des
alten als Ausgangsbasis nimmt.

Die vollständige Spezifikation liegt in `SPEC-cohortmembership.md` im Repo-Root.
**Lies dieses Dokument zuerst vollständig und halte dich daran.** Es ist verbindlich für
CSV-Format, Semantik (besonders die Sync-„Universum"-Scope-Regel), Fehlerbehandlung,
Dateistruktur und Testfälle.

## Zielumgebung
- Moodle 4.5 LTS und 5.x (getestet gegen 5.0.8)
- PHP-Version passend zu Moodle 4.5/5.x
- Managed Hosting: kein SSH beim Nutzer, Plugin wird als ZIP hochgeladen – UI-Weg ist primär, CLI optional

## Nicht-Ziele (nicht implementieren)
- Kein Auto-Create von Kohorten (bewusste Abweichung von Core, siehe SPEC §1)
- Kein User-Matching über idnumber/email – nur `username`
- Keine Kohorten-Erstellung/-Löschung, nur Mitgliedschaften
- Keine `upd`-Operation

## Absolute Leitplanken
1. **Alle Zustandsänderungen ausschließlich über `cohort/lib.php`**
   (`cohort_add_member`, `cohort_remove_member`). Keine direkten DB-Writes auf
   `cohort_members`.
2. **Niemals eine Kohorte anlegen.** Unbekannte Kohorte = Fehlerzeile im Report.
3. **Dry-run ist Default** (Checkbox vorausgewählt).
4. Einzelne fehlerhafte Zeilen brechen den Lauf nicht ab; nur dateiweite
   Validierungsfehler (Header, sync/delta-Mischung) verhindern die Verarbeitung.
5. Jede CSV-Zeile → genau ein Report-Eintrag.

## Umbenennung (Frankenstyle-sauber)
Beim Ableiten vom alten Plugin konsequent umbenennen:
- Verzeichnis: `local/cohortmembership/`
- Namespace: `local_cohortunenroller\*` → `local_cohortmembership\*`
- Alle `get_string(..., 'local_cohortunenroller')` → `'local_cohortmembership'`
- Capability: `local/cohortunenroller:run` → `local/cohortmembership:manage`
  (zusätzlich Core `moodle/cohort:assign` weiterhin verlangen)
- `version.php`: neues `$plugin->component = 'local_cohortmembership'`, frische
  Versionsnummer, `$plugin->requires` auf Moodle 4.5 setzen
- Session-Key für Report-Download umbenennen
- README neu schreiben (siehe unten)

## Bearbeitungsreihenfolge (bitte in dieser Reihenfolge, mit Commits pro Schritt)

**Schritt 0 – Grundgerüst**
Lege `local/cohortmembership/` an, indem du die Struktur des alten Plugins
übernimmst und wie oben umbenennst. Stelle sicher, dass das Plugin sich installieren
lässt und die reine `del`-Funktionalität (Status quo) unverändert läuft.
Commit: "Bootstrap local_cohortmembership from cohortunenroller".

**Schritt 1 – del extrahieren + Regression**
Extrahiere die bestehende del-Logik aus dem Processor in
`classes/local/operation_del.php`. Passe den Processor so an, dass er nach
`operation` dispatcht. CSV **ohne** `operation`-Spalte → alle Zeilen implizit `del`
(Rückwärtskompatibilität, mit Deprecation-Hinweis im Report).
Portiere/erweitere die bestehenden del-Tests als Regression (SPEC Testfälle 6–8).
Commit: "Extract del operation, operation-dispatch, keep backward compat".

**Schritt 2 – add**
Implementiere `classes/local/operation_add.php` gemäß SPEC §4/§6.
Tests: SPEC Testfälle 1–5 (besonders 3: kein Auto-Create).
Commit: "Add 'add' operation".

**Schritt 3 – cohortsync_detector**
Implementiere `classes/local/cohortsync_detector.php` mit der SQL aus SPEC §7,
Ergebnis pro cohortid cachen.
Tests: SPEC Testfälle 17–19.
Commit: "Add cohort-sync enrolment detector".

**Schritt 4 – sync**
Implementiere `classes/local/operation_sync.php` gemäß SPEC §3.2/§6.
Achte besonders auf die Universum-Scope-Regel: `current ∩ universe`, damit Kohorten
außerhalb der Datei nie angefasst werden. Verdrahte den cohortsync_detector für die
Removal-Warnung.
Tests: SPEC Testfälle 9–13.
Commit: "Add 'sync' operation with file-universe scope".

**Schritt 5 – Validierung**
Header-Validierung, Mischverbot sync+delta, cohort-Spalten-Vorrang.
Tests: SPEC Testfälle 14–16.
Commit: "Add file-level validation (header, sync/delta mix guard)".

**Schritt 6 – Report + UI**
`classes/output/report.php` + `templates/report.mustache` um Spalten `operation`
und `cohort-sync betroffen` erweitern; Summary-Zähler ergänzen (added/removed/
skipped/errors/warnings). Upload-Formular: Warnhinweis zu cohort-sync + DB-Backup
gemäß SPEC §2. CSV-Download mit identischen Spalten.
Commit: "Extend report and upload UI".

**Schritt 7 – CLI (optional)**
Falls zeitlich sinnvoll: `cli/membership.php` analog zum alten `cli/unenrol.php`,
das dieselbe Processor-Logik nutzt und `--operation`/CSV unterstützt. Wenn du es
weglässt, dokumentiere das im README.
Commit: "Add membership CLI".

**Schritt 8 – Doku + CI**
README neu: Zweck, del/add/sync-Format mit Beispielen, die cohort-sync-Warnung
prominent, Verweis auf Tracker MDL-61007 als Bedarfsnachweis. Privacy-API:
`\core_privacy\local\metadata\null_provider` (keine persistenten personenbezogenen
Daten; Report nur in Session). Stelle sicher, dass die bestehende GitHub-Actions-
Moodle-CI (PHP-Lint, moodle-cs Codestyle, PHPUnit) grün läuft.
Commit: "Docs, privacy null_provider, CI green".

## Qualitätsanforderungen
- Moodle Coding Style (moodle-cs) einhalten.
- Alle neuen Klassen mit PHPUnit-Tests (`advanced_testcase`, `resetAfterTest`).
- Deutsche und englische Sprachdatei pflegen.
- Nach jedem Schritt: Tests grün, bevor du weitermachst.

## Erste Aktion
Lies `SPEC-cohortmembership.md`, fasse mir in 5–8 Sätzen dein Verständnis der
Sync-Universum-Scope-Regel und des Mischverbots zusammen, und liste die Dateien auf,
die du in Schritt 0 anlegst. Beginne erst danach mit dem Code.

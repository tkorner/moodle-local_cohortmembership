# Spec: `local_cohortmembership` — Cohort Membership Uploader

**Autor:** Thomas Korner (tkorner)
**Ziel-Moodle:** 4.5 LTS + 5.x (aktuell getestet auf 5.0.8)
**Ausgangsbasis:** bestehendes Plugin `local_cohortunenroller` (nur `del` via CSV, UI + CLI, Dry-run, Report)
**Auftrag:** Erweiterung um Operationen `add` und `sync` über eine `operation`-Spalte, ohne die bestehende `del`-Funktionalität zu brechen.

Dieses Dokument ist gleichzeitig Architektur-Doku, Claude-Code-Implementierungsauftrag und Test-Spezifikation.

---

## 1. Motivation & Abgrenzung zu Moodle Core

### Was Core bereits kann (nicht nachbauen)
- **Add** über `tool_uploaduser`: `cohort1,cohort2,...`-Spalten weisen bestehenden Kohorten per `idnumber` zu. Funktioniert gut, inkl. Preview.
- **Webservices** `core_cohort_add_cohort_members` / `core_cohort_delete_cohort_members`: vollständige API, aber ohne UI, Dry-run oder Report.

### Was Core NICHT kann (die echte Lücke)
- **Remove from cohort** in der UI oder per CSV — offener Tracker-Request **MDL-61007** (seit 2017).
- **Sync** (Soll-Zustand herstellen: entfernen was nicht mehr passt, hinzufügen was fehlt).

### Bewusste Design-Abweichung von Core
Core legt bei einem unbekannten, nicht-numerischen Wert in `cohortN` **stillschweigend eine neue Kohorte an** (MDL-41639). Das ist eine häufige Ursache für Duplikat-Chaos.
→ **Dieses Plugin legt NIEMALS Kohorten an.** Unbekannte Kohorte = Fehlerzeile im Report, kein Auto-Create.

### Warum überhaupt ein `add`, wenn Core das kann
Damit derselbe Workflow (eine CSV, ein Tool, ein Report, ein Dry-run) für alle drei Operationen gilt. Der `add`-Zweig ruft dieselbe Core-API wie `tool_uploaduser` auf (`cohort_add_member`), dupliziert also keine Logik, sondern vereinheitlicht nur den Einstieg. Wer nur adden will, darf weiter Core nutzen — beides bleibt gültig.

---

## 2. Kritisches Risiko (MUSS im UI sichtbar sein)

Kohorten-Removal ist **nicht** auf die `cohort_members`-Tabelle beschränkt. Läuft in einem Kurs **Cohort-Sync-Enrolment**, löst das Entfernen aus der Kohorte eine Unenrolment aus. Ohne alternative Einschreibung werden dabei **Bewertungen, Gruppenzugehörigkeiten und kursbezogene Einstellungen** des Users mitentfernt.

**Anforderungen daraus:**
- Dry-run ist Pflicht-Default (Checkbox vorausgewählt).
- Der Report zeigt pro `del`/`sync`-Removal, **ob die betroffene Kohorte in mindestens einem aktiven `enrol_cohort`-Enrolment verwendet wird** ("cohort-sync betroffen"-Warnspalte).
- Warnhinweis auf der Upload-Seite mit Verweis auf DB-Backup.

---

## 3. CSV-Format

### 3.1 Grundformat (Delta: `add` / `del`)

Eine Operation pro Zeile. Header ist Pflicht (Kleinschreibung).

```
operation,username,cohortidnumber
add,hans.muster.zhaw,kurs-inf-2026
del,hans.muster.zhaw,kurs-inf-2023
```

Alternativ `cohortid` (numerisch) statt `cohortidnumber` — genau **eine** der beiden Kohorten-Spalten muss vorhanden sein (wie im Status quo). Bei beiden vorhandenen Spalten hat `cohortid` Vorrang, `cohortidnumber` wird ignoriert (im Report vermerken). Diese Präzedenz gilt auch pro Zeile: ist die `cohortid`-Spalte in der Datei vorhanden, aber in einer einzelnen Zeile leer oder nicht-numerisch, fällt diese Zeile **nicht** auf `cohortidnumber` zurück — sie ist `status_invalid`. Alles andere würde die Vorrang-Regel pro Zeile widersprüchlich zur dateiweiten Meldung machen.

**User-Identifikation:** `username` (Status quo beibehalten). `idnumber`/`email` sind out of scope für v1.

### 3.2 Sync-Format

`sync` funktioniert pro **User über alle seine Zeilen hinweg**, nicht pro Einzelzeile. Semantik:

> Für jeden User in der Sync-Datei: Sein Soll-Zustand ist **exakt** die Menge der Kohorten, die in seinen `sync`-Zeilen aufgelistet sind. Alle Kohorten, die in der Datei als Spalten-Universum vorkommen, aber beim User fehlen, werden entfernt. Kohorten außerhalb des Datei-Universums bleiben unangetastet.

**Scope-Regel (kritisch, verhindert Datenverlust):**
Das "Universum" der vom Sync verwalteten Kohorten = **die Vereinigungsmenge aller Kohorten, die irgendwo in der Sync-Datei vorkommen**. Es wird NIE über den gesamten Kohortenbestand des Users synchronisiert — nur über Kohorten, die die Datei explizit nennt. Damit gibt es kein "Full Replace" und keinen Bedarf für ein Managed-Flag.

```
operation,username,cohortidnumber
sync,hans.muster.zhaw,kurs-inf-2026
sync,hans.muster.zhaw,basis-alle
sync,anna.beispiel.zhaw,kurs-bwl-2026
```

Interpretation:
- Universum = {kurs-inf-2026, basis-alle, kurs-bwl-2026}
- hans → Soll = {kurs-inf-2026, basis-alle}. Ist er zusätzlich in `kurs-bwl-2026` (im Universum, aber nicht bei ihm) → wird entfernt. Ist er in `sonstiges-2020` (nicht im Universum) → bleibt.
- anna → Soll = {kurs-bwl-2026}. Ist sie in `kurs-inf-2026` → wird entfernt (im Universum, nicht bei ihr).

**Mischverbot:** Eine Datei ist entweder reines Delta (`add`/`del`) oder reiner `sync`. Mischung aus `sync`- und `add`/`del`-Zeilen in derselben Datei → Validierungsfehler mit klarer Meldung. (Begründung: Sync-Semantik über das Universum kollidiert mit zeilenweiser Delta-Semantik.)

---

## 4. Verhalten & Fehlerbehandlung

| Situation | Verhalten | Report-Status |
|---|---|---|
| User existiert nicht | Zeile überspringen | `error_user_notfound` |
| Kohorte (idnumber/id) existiert nicht | Zeile überspringen, **kein Auto-Create** | `error_cohort_notfound` |
| `add`, User schon Mitglied | Skip, kein Fehler | `info_already_member` |
| `del`, User nicht Mitglied | Skip, kein Fehler | `info_not_member` |
| `sync`-Removal, Kohorte nutzt aktiven cohort-sync-enrol | Ausführen, aber Warnung setzen | `ok_removed` + Flag `cohortsync_warning` |
| unbekannte `operation` | Zeile überspringen | `error_bad_operation` |
| Datei mischt sync + delta | gesamter Upload abgelehnt | Validierungsfehler vor Verarbeitung |
| leere/fehlende Pflichtspalte | Upload abgelehnt | Validierungsfehler |

**Grundprinzipien:**
- Einzelne fehlerhafte Zeilen brechen den Lauf NICHT ab (außer die dateiweiten Validierungsfehler in §3.2 / Header).
- Jede Zeile erzeugt genau einen Report-Eintrag.
- Alle Zustandsänderungen laufen ausschließlich über die Core-API `cohort/lib.php` (`cohort_add_member`, `cohort_remove_member`) — keine direkten DB-Writes.

---

## 5. Architektur / Dateistruktur

Bestehende Struktur beibehalten, gezielt erweitern. **Fett = neu oder geändert.**

```
local/cohortmembership/
├── index.php                         [GEÄNDERT] operation-aware, Mischverbot-Check
├── version.php                       [GEÄNDERT] version hochzählen, requires 4.5
├── settings.php
├── lang/en/local_cohortmembership.php  [GEÄNDERT] neue Strings
├── lang/de/local_cohortmembership.php  [NEU] deutsche Übersetzung
├── classes/
│   ├── form/upload_form.php          [GEÄNDERT] Hinweis-Text, keine Modus-Auswahl nötig (aus CSV)
│   ├── local/
│   │   ├── processor.php             [GEÄNDERT] dispatch nach operation
│   │   ├── operation_add.php         [NEU] add-Logik
│   │   ├── operation_del.php         [NEU] del-Logik (aus altem processor extrahiert)
│   │   ├── operation_sync.php        [NEU] sync-Logik (Universum + Diff)
│   │   └── cohortsync_detector.php   [NEU] prüft aktive enrol_cohort-Nutzung
│   └── output/report.php             [GEÄNDERT] neue Spalten (operation, warning)
├── cli/unenrol.php                   [GEÄNDERT–behalten] + cli/membership.php [NEU, optional]
├── templates/report.mustache         [GEÄNDERT] Warnspalte
└── tests/
    ├── processor_del_test.php        [behalten]
    ├── operation_add_test.php        [NEU]
    ├── operation_sync_test.php       [NEU]
    └── cohortsync_detector_test.php  [NEU]
```

### Kompatibilität mit dem Status quo
- Eine alte CSV **ohne** `operation`-Spalte soll weiterhin als reines `del` interpretiert werden (Rückwärtskompatibilität). → Wenn `operation`-Spalte fehlt: implizit `del` für alle Zeilen, mit Deprecation-Hinweis im Report.
- Bestehende Capability `local/cohortmembership:manage` (vormals `local/cohortunenroller:run`) + Core `moodle/cohort:assign` bleiben. `moodle/cohort:assign` deckt sowohl add als auch remove ab (Core-`cohort_add_member`/`cohort_remove_member` prüfen dieselbe Capability).

---

## 6. Processor-Logik (Pseudocode)

```
function process(rows, options):
    validate_headers(rows)                    // Pflichtspalten, genau eine cohort-Spalte
    ops = distinct(rows.operation)            // fehlt Spalte -> alle 'del'
    if 'sync' in ops and (ops - {'sync'}):    // Mischverbot
        abort("sync darf nicht mit add/del gemischt werden")

    if ops == {'sync'}:
        return process_sync(rows, options)
    else:
        return process_delta(rows, options)   // add + del zeilenweise

function process_delta(rows, options):
    for row in rows:
        u = resolve_user(row.username)                    or -> error_user_notfound
        c = resolve_cohort(row.cohortid|cohortidnumber)   or -> error_cohort_notfound
        switch row.operation:
          'add': if is_member(c,u): info_already_member
                 else if not dryrun: cohort_add_member(c,u); ok_added
          'del': if not is_member(c,u): info_not_member
                 else:
                    warn = cohortsync_detector.uses(c)
                    if not dryrun: cohort_remove_member(c,u)
                    ok_removed (+cohortsync_warning if warn)
          else:  error_bad_operation

function process_sync(rows, options):
    universe = set(resolve_cohort(r) for r in rows)       // alle in Datei genannten Kohorten
    by_user  = group rows by username
    for user, userrows in by_user:
        u = resolve_user(user) or -> error_user_notfound (alle Zeilen)
        target  = set(resolve_cohort(r) for r in userrows)
        current = current_memberships(u) intersect universe  // nur verwaltete Kohorten!
        to_add    = target  - current
        to_remove = current - target
        for c in to_add:    if not dryrun: cohort_add_member(c,u);    ok_added
        for c in to_remove:
            warn = cohortsync_detector.uses(c)
            if not dryrun: cohort_remove_member(c,u); ok_removed (+warning)
```

---

## 7. `cohortsync_detector` — Prüflogik

Ziel: pro Kohorte feststellen, ob ein Removal eine Kurs-Unenrolment auslösen kann.

```sql
SELECT DISTINCT e.courseid
FROM {enrol} e
WHERE e.enrol = 'cohort'
  AND e.status = 0            -- aktiv
  AND e.customint1 = :cohortid
```

- Rückgabe: Liste betroffener Kurse (oder nur Boolean + Anzahl fürs Report-Flag).
- Ergebnis pro `cohortid` cachen (viele Zeilen können dieselbe Kohorte betreffen).

---

## 8. Report-Ausgabe

Bestehende HTML-Tabelle + CSV-Download erweitern um Spalten:

| username | operation | cohort (idnumber/id) | status | cohort-sync betroffen |
|---|---|---|---|---|

- Zähler-Summary oben: `added`, `removed`, `skipped (already/not member)`, `errors`, `davon mit cohort-sync-Warnung`.
- CSV-Download: identische Spalten, damit als Audit-Trail archivierbar.
- Dry-run: identische Ausgabe, Statuswerte mit Präfix "(simuliert)".

---

## 9. Testfälle (PHPUnit, `advanced_testcase`)

### operation_add
1. add zu existierender Kohorte, User kein Mitglied → Mitglied danach, Status `ok_added`.
2. add, User bereits Mitglied → keine Änderung, `info_already_member`.
3. add, Kohorte-idnumber existiert nicht → `error_cohort_notfound`, **keine** neue Kohorte in DB.
4. add, User existiert nicht → `error_user_notfound`.
5. add im Dry-run → DB unverändert, Status simuliert.

### operation_del (Regression zum Status quo)
6. del, User Mitglied → entfernt, `ok_removed`.
7. del, User nicht Mitglied → `info_not_member`, keine Änderung.
8. CSV ohne operation-Spalte → alle Zeilen als del behandelt (Rückwärtskompat).

### operation_sync
9. Universum-Scope: User in Kohorte außerhalb des Datei-Universums → bleibt unangetastet.
10. Removal-Fall: User in Universum-Kohorte, die in seinen Zeilen fehlt → entfernt.
11. Add-Fall: User fehlt in Ziel-Kohorte seiner Zeilen → hinzugefügt.
12. No-op: aktueller Zustand == Soll → keine Änderungen, alles `info_*`.
13. Zwei User in einer Sync-Datei, unterschiedliche Ziele → korrekt getrennt.

### Validierung
14. Datei mischt sync + add → Abbruch vor Verarbeitung.
15. Fehlende cohort-Spalte → Abbruch.
16. Beide cohort-Spalten vorhanden → cohortid gewinnt, Vermerk im Report.

### cohortsync_detector
17. Kohorte mit aktivem enrol_cohort → uses()==true, Kurs gelistet.
18. Kohorte mit deaktiviertem (status=1) enrol_cohort → uses()==false.
19. Kohorte ohne enrol_cohort → uses()==false.

---

## 10. Nicht-Ziele (v1 out of scope)
- User-Matching über `idnumber`/`email` (nur `username`).
- Kohorten-Erstellung/-Löschung (nur Mitgliedschaften).
- Kategorie-Kontext-Kohorten mit gleicher idnumber in mehreren Kontexten — bei Ambiguität `error_cohort_ambiguous`, nicht raten.
- `upd`-Operation (kein sinnvoller Anwendungsfall für Mitgliedschaften).

---

## 11. Namens-Entscheidung

Entschieden (siehe CLAUDE.md): Umbenennung von `local_cohortunenroller` zu
`local_cohortmembership` als Schritt 0, noch vor der Implementierung von
`add`/`sync`. Der Host-Ordner wird erst nach Abschluss der Code-Umbenennung
manuell von `cohortunenroller` auf `cohortmembership` umbenannt und das Plugin
neu installiert.

---

## 12. Directory-Einreichung (optional, später)
Bei offenem MDL-61007 besteht echter Community-Bedarf. Für die Einreichung nötig:
- Saubere `del`/`add`/`sync`-Doku im README.
- PHPUnit grün, Moodle CI grün (PHP-Lint, moodle-cs Codestyle, Behat optional).
- Privacy-API (`\core_privacy\...\null_provider` reicht, da keine eigenen personenbezogenen Daten gespeichert werden — Report liegt nur in Session).
- Verweis auf MDL-61007 im Einreichungstext als Bedarfsnachweis.

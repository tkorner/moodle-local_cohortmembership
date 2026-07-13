# CLAUDE.md — local_cohortmembership

Kontextdatei für Claude Code. Bei jeder Session zuerst lesen.

## Was dieses Plugin ist

Ein Moodle-`local`-Plugin, das Kohorten-**Mitgliedschaften** per CSV verwaltet:
Nutzer zu Kohorten hinzufügen (`add`), aus Kohorten entfernen (`del`) und einen
Soll-Zustand herstellen (`sync`). UI-basiert (CSV-Upload), mit Dry-run, HTML-Report
und CSV-Download.

Es füllt eine echte Lücke in Moodle Core: Es gibt keinen nativen Weg, Nutzer per
CSV oder UI aus Kohorten zu **entfernen** (offener Tracker-Request MDL-61007). Add
kann Core (`tool_uploaduser`), Remove und Sync nicht.

## WICHTIG: aktuelle Umbenennungs-Situation

Dieses Plugin entsteht aus dem bestehenden `local_cohortunenroller` (mein eigenes
Plugin, https://github.com/tkorner/moodle-local_cohortunenroller). Das alte Plugin
bleibt eingefroren; wir bauen daraus das erweiterte `local_cohortmembership`.

**Stand jetzt:**
- Der Ordner heisst auf dem Host/Container aktuell noch `cohortunenroller`, weil das
  der `component`-Name in der aktuellen `version.php` ist und Moodle sonst die
  Installation verweigert.
- **Ziel:** Component-Name `local_cohortmembership`.

**Reihenfolge (Schritt 0 des Auftrags):**
1. Zuerst konsequent umbenennen (siehe unten), sodass `version.php`
   `$plugin->component = 'local_cohortmembership'` enthält.
2. Erst DANACH wird der Host-Ordner von `cohortunenroller` auf `cohortmembership`
   umbenannt (macht der Nutzer manuell) und in Moodle neu installiert.

Solange der Ordner noch `cohortunenroller` heisst: das ist erwartet, kein Fehler.

### Umbenennung konsequent durchziehen
- Namespace `local_cohortunenroller\*` → `local_cohortmembership\*`
- alle `get_string(..., 'local_cohortunenroller')` → `'local_cohortmembership'`
- Capability `local/cohortunenroller:run` → `local/cohortmembership:manage`
  (zusätzlich weiterhin Core `moodle/cohort:assign` verlangen)
- `version.php`: neuer `component`, frische Versionsnummer, `$plugin->requires` = Moodle 4.5
- Session-Key für Report-Download umbenennen
- README neu

## Umgebung

- **Moodle:** 5.2.x (Docker), Ziel-Kompatibilität 4.5 LTS + 5.x
- **OS:** macOS
- **Docker-Container:** `claude-moodle-1` (Moodle), `claude-mariadb-1` (DB)
- **Mount:** Host `/Users/tkorner/Documents/claude/plugins/local` →
  Container `/var/www/html/local`
  → Plugin liegt unter `.../plugins/local/cohortunenroller` (später `cohortmembership`)
- **Editor:** VS Code + Claude Code
- **PHP/Composer:** lokal vorhanden

### Nützliche Docker-Befehle
```bash
# In den Moodle-Container
docker exec -it claude-moodle-1 bash

# PHPUnit-Testumgebung initialisieren (einmalig, im Container)
docker exec -it claude-moodle-1 php admin/tool/phpunit/cli/init.php

# Tests dieses Plugins ausführen (im Container, aus /var/www/html)
docker exec -it claude-moodle-1 vendor/bin/phpunit --filter local_cohortmembership

# Moodle-Caches leeren nach grösseren Änderungen
docker exec -it claude-moodle-1 php admin/cli/purge_caches.php
```

Codestyle prüfe ich schnell lokal auf dem Host (kein Container nötig):
```bash
# moodle-cs global installiert
phpcs --standard=moodle /Users/tkorner/Documents/claude/plugins/local/cohortunenroller
```

## Moodle-Architektur-Kontext

- **Plugin-Typ:** `local` → liegt in `/local/`, Component `local_cohortmembership`
- **Kohorten-Mitgliedschaft:** Tabelle `mdl_cohort_members`. NUR über die Core-API
  `cohort/lib.php` ändern: `cohort_add_member($cohortid, $userid)` /
  `cohort_remove_member($cohortid, $userid)`. **Keine direkten DB-Writes.**
- **Kohorten-Identifikation:** über `idnumber` (Klartext) oder numerische `id`.
- **Capability:** `moodle/cohort:assign` deckt add und remove ab.
- **Tests:** PHPUnit mit `advanced_testcase`, immer `resetAfterTest()`.

## Absolute Leitplanken (nie verletzen)

1. Zustandsänderungen ausschliesslich über `cohort/lib.php`.
2. **Niemals eine Kohorte anlegen.** Unbekannte Kohorte = Fehlerzeile im Report.
   (Core legt bei unbekanntem Wert automatisch eine an — das machen wir bewusst NICHT,
   weil es Duplikate erzeugt.)
3. **Dry-run ist Default.**
4. Einzelne fehlerhafte Zeilen brechen den Lauf nicht ab; nur dateiweite
   Validierungsfehler (Header, sync/delta-Mischung) verhindern die Verarbeitung.
5. Jede CSV-Zeile → genau ein Report-Eintrag.

## Kritische Fachlogik

### Sync-Universum-Scope (der wichtigste Punkt)
`sync` arbeitet pro User über alle seine Zeilen, gegen ein **Datei-Universum**:
Das Universum = Vereinigung ALLER Kohorten, die irgendwo in der Sync-Datei vorkommen.
Für jeden User: Soll = seine gelisteten Kohorten. Entfernt werden nur Kohorten, die
im Universum sind, aber beim User fehlen. Kohorten AUSSERHALB des Universums bleiben
unangetastet — es gibt kein „Full Replace".

Formel: `to_remove = (current ∩ universe) − target`

Testbeweis: Ein User in einer Kohorte, die nicht in der Datei steht, behält sie.

### cohort-sync-Warnung
Removal kann via aktivem `enrol_cohort` eine Kurs-Unenrolment auslösen (mit Verlust von
Bewertungen/Gruppen). Der Report muss pro Removal anzeigen, ob die Kohorte in einem
aktiven `enrol_cohort` verwendet wird. Detector-SQL: `enrol` mit `enrol='cohort'`,
`status=0`, `customint1=:cohortid`.

### Mischverbot
Eine Datei ist entweder reines Delta (`add`/`del`) oder reiner `sync`. Mischung →
Validierungsfehler vor Verarbeitung.

### Rückwärtskompatibilität
CSV ohne `operation`-Spalte → alle Zeilen als `del` behandeln (Status quo des alten
Plugins), mit Deprecation-Hinweis im Report.

## Verweise
- Vollständige Spezifikation: `SPEC-cohortmembership.md` (verbindlich)
- Test-Fixtures + erwartetes Verhalten: `tests/fixtures/` bzw. `FIXTURES-README.md`
- Auftrags-/Schrittreihenfolge: `claude-code-prompt.md`

## Nicht-Ziele (v1)
- Kein Auto-Create von Kohorten
- User-Matching nur über `username` (nicht idnumber/email)
- Keine Kohorten-Erstellung/-Löschung
- Keine `upd`-Operation

# Test-Fixtures: erwartetes Verhalten

Diese zwei CSVs dienen als PHPUnit-Fixtures (`tests/fixtures/`) und als
README-Beispiele. Sie illustrieren die drei Operationen und — wichtig — die
Universum-Scope-Regel des Sync-Modus.

## `example_delta.csv` (Operationen `add` / `del`)

Zeilenweise, jede Zeile eine eigenständige Operation.

| Zeile | Erwartung bei realer Ausführung |
|---|---|
| `add hans → kurs-inf-2026` | Mitglied danach. Falls schon Mitglied: `info_already_member`. |
| `add anna → kurs-inf-2026` | wie oben |
| `del hans → kurs-inf-2023` | entfernt. Falls nicht Mitglied: `info_not_member`. |
| `del anna → kurs-inf-2023` | wie oben |
| `add peter → basis-alle` | hinzugefügt |
| `del peter → kurs-bwl-2022` | entfernt |

Wichtig: Existiert eine der genannten Kohorten-`idnumber`s nicht, ergibt die Zeile
`error_cohort_notfound` — **es wird keine Kohorte angelegt**.

## `example_sync.csv` (Operation `sync`)

Sync arbeitet pro User über alle seine Zeilen, gegen das **Datei-Universum**.

**Universum dieser Datei** (Vereinigung aller genannten Kohorten):
`{ kurs-inf-2026, basis-alle, kurs-bwl-2026 }`

Soll-Zustand pro User laut Datei:
- **hans** → `{ kurs-inf-2026, basis-alle }`
- **anna** → `{ kurs-bwl-2026 }`
- **peter** → `{ kurs-inf-2026, kurs-bwl-2026 }`

### Beispiel-Ausgangszustand (für den Test so anlegen)

| User | Ist-Mitgliedschaften vorher |
|---|---|
| hans | `kurs-inf-2026`, `kurs-bwl-2026`, `sonstiges-2019` |
| anna | `kurs-inf-2026` |
| peter | (keine) |

### Erwartetes Ergebnis nach Sync

| User | Aktion | Begründung |
|---|---|---|
| hans | **+ basis-alle** | im Soll, fehlt → add |
| hans | **− kurs-bwl-2026** | im Universum, nicht in hans' Soll → remove |
| hans | `kurs-inf-2026` bleibt | im Soll und schon Mitglied → no-op |
| hans | **`sonstiges-2019` bleibt unangetastet** | **nicht im Universum** → Sync fasst es nicht an |
| anna | **+ kurs-bwl-2026** | im Soll, fehlt → add |
| anna | **− kurs-inf-2026** | im Universum, nicht in annas Soll → remove |
| peter | **+ kurs-inf-2026, + kurs-bwl-2026** | beide im Soll, fehlen → add |

Der Fall **`sonstiges-2019` bleibt** ist der entscheidende Testpunkt (SPEC Testfall 9):
Er beweist, dass Sync nur innerhalb des Datei-Universums arbeitet und keine
unbeteiligten Kohorten löscht.

### cohort-sync-Warnung
Falls eine der zu entfernenden Kohorten (z.B. `kurs-inf-2026` bei anna) in einem
aktiven `enrol_cohort`-Enrolment verwendet wird, muss der Report für diese Removal-
Zeile das Flag `cohortsync_warning` setzen (SPEC §2, §7).

## Negativ-Fixture (inline im Test, keine eigene Datei nötig)
Eine Datei, die `sync`- und `add`/`del`-Zeilen mischt, muss vor der Verarbeitung
mit einem Validierungsfehler abgelehnt werden (SPEC Testfall 14).

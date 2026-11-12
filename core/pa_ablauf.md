# core/pa_ablauf.php – Produktion: ein geführter Ablauf (Kern)

Seit 11.11.2026, siehe `plan-produktion-gefuehrt.md` (Teil 3 + 5).

Das ist der **gemeinsame Kern** für das Dashboard (PA-Seite, Nachtragen) und die Werk-App (`/produktion/`).
- Er nutzt nur `q/one/all/scalar/insert_id`.
- Fachfunktionen ruft er über `pa_fn('dashboard_name', 'erp_name', …)` auf.
- Er lädt **nie** `core/schema.php`. Die Tabellen legt `pa_schema()` selbst an (Version in `app_meta pa_ablauf_schema`).

## Tabellen
| Tabelle | Inhalt |
|---|---|
| `pa_unter_charge` | Buchstabe, Kunden-Charge (`2785.A`, Vorgabe/Abweichung mit Grund), MHD, **eine** Bulk-Charge bzw. ein Eimer, Linie, Status (`laeuft`/`fertig`), Fertigware-Charge, Ø-Kapselgewicht, Kapselzahl aus Sackgewicht, Protokoll |
| `pa_teilmenge` | Menge, Umfang (`komplett` / `vor_etikett`), Rückstellmuster, Foto, Art (`werk` / `nachtrag` mit Datum), Mitarbeiter, „schon geliefert“ |
| `pa_karton` | je vollem Karton: fortlaufende Nummer je PA, eine Unter-Charge, Menge, Teilmenge; ohne „von n“ |
| `pa_scan` | gescanntes Material je Unter-Charge (Rolle, Charge/Gebinde/Eimer) |
| `pa_ereignis` | Protokoll: start/wiederanlauf/chargenwechsel (mit Fotos Linie + Stempel), kapselprobe, sackgewicht, teilmenge, abweichung, abschluss, sperre |

Zusätzlich hat `produktion_verbrauch.teilmenge_id` den Verbrauch je Teilmenge.

## Regeln
- **Charge vom System** (`pa_vorgabe`, `pa_naechster_buchstabe`): Basis ist die PR-Nummer, der Buchstabe der nächste freie nach allen benutzten (Unter-Chargen und vorhandene Chargen).
  - **Neuer Buchstabe bei anderem Bulk bzw. anderem Eimer UND an einem neuen Produktionstag** (Nico 12.11.2026, ersetzt die Regel vom 11.11.): einen Tag später gibt es dieselbe Nummer mit dem nächsten Buchstaben.
  - Gleicher Bulk am selben Tag heißt Wiederanlauf (z. B. nach der Pause) mit erneuten Fotos.
  - Der Produktionstag einer Unter-Charge ist `pa_uc_tag()`: die letzte Teilmenge (`datum`), sonst die Anlage (Berlin). „Heute“ ist `pa_tag_heute()` (Berlin). `pa_vorgabe(..., $tag)` vergleicht beides. Ein Nachtrag nutzt das Produktionsdatum aus dem Formular.
  - Abweichende Charge nur mit Grund, das erzeugt eine Aufgabe ans Büro.
  - `charge_naechste_nr()` und `erp_charge_naechste_nr()` nutzen dieselbe Buchstabenregel.
- **MHD** (`pa_mhd_vorgabe`): das frühere aus Bulk-MHD und heute + Haltbarkeit des Produkts (sonst 18 Monate). Ein am PA festgelegtes MHD gilt vorrangig.
- **Start** (`pa_starten`): Fotos „Linie leer“ und „Stempel“ sind Pflicht. Ein anderer Bulk schließt die laufende Unter-Charge (Chargenwechsel).
- **Teilmenge** (`pa_teilmenge_buchen`) bucht sofort:
  - Verbrauch aller Stationen anteilig. **Gescannte Chargen zuerst** (`$GLOBALS['pa_bevorzugt']` in den FEFO-Funktionen von Dashboard und Werk-App), dann FEFO.
  - Fertigware: **eine Charge je Unter-Charge**, jede Teilmenge erhöht sie.
  - Rückstellmuster in `prod_probe`.
  - Verlauf beim Kunden („teilweise x von y“ über die Charge).
  - Ist das Etikett gesperrt, nur „bis vor dem Etikettieren“ (`menge_vor_etikett`), ohne Fertigware.
- **Kartons** (`pa_karton_anlegen`): Nummer fortlaufend je PA, Code `<Charge>-K001`. Kartons ohne Teilmenge belegen die Menge bei „Teilmenge fertig“ vor.
- **Abschluss** (`pa_abschliessen`): Ist gegen Soll.
  - Rest kommt noch: Der PA bleibt offen.
  - Rest storniert: `auftrag.fortschritt_ziel`, Aufgabe ans Büro.
  - Sonst werden die Produktionsschritte (ohne QM-Stationen) erledigt, weiter geht es mit QM und Freigaben.

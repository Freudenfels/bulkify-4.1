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

## Quarantäne-Bulk (13.11.2026)
`pa_starten()` prüft den Status der Bulk-Charge: Quarantäne oder gesperrt = keine Unter-Charge (Start, Wiederanlauf, Nachtrag), Meldung „erst nach der QM-Freigabe produzieren“.

## Tagesregel serverseitig (13.11.2026)
`pa_uc_tag_pruefen($uc, $tag)`: Gehört die laufende Unter-Charge zu einem anderen Produktionstag (Berlin), bucht weder `pa_teilmenge_buchen()` (Nachtrag: gewähltes Datum) noch `pa_karton_anlegen()` noch der Scan in der Werk-App. Meldung „Neuer Tag - bitte neu starten“.

## Datum in Ortszeit (13.11.2026)
`pa_tag_heute()` = `heute_ort()` (core/zeit_ort.php). MHD-Vorgabe ab heute in Berlin, Wareneingang der Fertigcharge = Produktionstag (Berlin, beim Nachtrag das gewählte Datum).

## Nachtrag mit anderem Datum (13.11.2026, Fable-3 T1.4)
- Neue Spalte `pa_unter_charge.tag` (DATE): der Produktionstag der Unter-Charge (Europe/Berlin). `pa_uc_tag()` nimmt sie zuerst; alte Unter-Chargen ohne Tag wie bisher (letzte Teilmenge, sonst Anlagezeit).
- `pa_starten()` setzt den Tag (Werk-App: heute, Nachtrag: das gewählte Datum). Erst wird geprüft (Charge-Abweichung nur mit Grund), dann geschlossen. Ein Nachtrag schließt die laufende Unter-Charge **nie**; er legt seine eigene mit Status `nachtrag` an.
- **`pa_nachtrag_buchen($pa_id, $menge, $d, $o, $von)`** ist der einzige Nachtrag-Weg:
  - gleicher Bulk am gewählten Tag = dieselbe Unter-Charge (laufend oder schon fertig, dann wird das Chargenprotokoll neu abgelegt),
  - sonst neue Unter-Charge mit dem gewählten Tag,
  - Buchung schlägt fehl: `pa_nachtrag_verwerfen()` löscht die eben angelegte Unter-Charge samt Start-Ereignis – kein Buchstabe verbraucht, die laufende Unter-Charge bleibt offen,
  - Buchung gelingt: Nachtrag für heute ohne laufende Unter-Charge läuft weiter (wie bisher), sonst wird die Nachtrag-Unter-Charge abgeschlossen; eine Charge-Abweichung geht erst jetzt als Aufgabe ans Büro,
  - Datum in der Zukunft: abgelehnt.

**Alter Einlager-Weg (13.11.2026, Fable-3 T4.2):** `pa_rest_storniert($pa_id)`: PA mit „Rest storniert“ abgeschlossen = kein offener Rest (für `produktion_rest()` und `erp_produktion_rest()`). `pa_einlagern_alt_sperre($pa_id)`: Einlagern über den alten Weg nicht, solange eine Unter-Charge läuft.

**Noch offen (13.11.2026, Werk-App Teil 2):** `pa_noch_offen($pa)` = offene Produktionsschritte (ohne QM-Stationen, `pa_qm_stationen()`) + offene Menge (Soll minus gebuchte Fertigware); `pa_noch_offen_text()` baut daraus „Noch offen: Verpacken, Etikettieren · 825 Stk“ (+ „Etikett fehlt - nur bis vor dem Etikettieren“). Eine Rechnung für Werk-App, Plan und „Läuft“.

**Voll produziert / Linie leer (13.11.2026, Werk-App Teil 3):** `pa_voll_produziert_erledigen($pa, $uc, $von, $grund)`: Fertigware ≥ Soll → offene Produktionsschritte vor den QM-Stationen erledigt (Etikettieren bleibt ohne freigegebenes Etikett offen), laufende Unter-Charge abgeschlossen, Ereignis `auto_erledigt` „voll produziert (x von y) - automatisch erledigt“ + Auftragsverlauf. Aufgerufen am Ende von `pa_teilmenge_buchen()` (Werk-App, Nachtrag, KI-Meldung) und von der Datenprüfung. `pa_starten()`: Pflicht-Haken `linie_leer` statt Foto „Linie leer“ (Name und Zeit ins Protokoll), Stempelfoto bleibt Pflicht.

**Produktion per KI melden (13.11.2026, Werk-App Teil 4):** `pa_teilmenge_buchen()` kennt `$o['vor_etikett']` (ausdrücklich nur bis vor dem Etikettieren); `pa_nachtrag_buchen()` reicht es durch (dann kein „nachtrag_komplett“).

**Charge-Abweichung nur bei echter Abweichung (13.11.2026):** `pa_charge_abweichend($charge, $vorgabe)` (core/pa_ablauf.php) ist die eine Regel: leer oder gleich der Vorgabe (Groß-/Kleinschreibung egal) = keine Abweichung. Nur dann gibt es das Ereignis „abweichung“ und die Aufgabe ans Büro (Start, Chargenwechsel, Nachtrag, KI-Karte); ein Grund ohne Abweichung wird ignoriert. Das Feld „Grund“ erscheint erst, wenn jemand die Charge ändert (`pa_grund_js()`, Element mit Klasse `pa-grund` und `data-vorgabe`).

**Reihenfolge laufend zuerst (13.11.2026):** `pa_reihenfolge_laufend_zuerst($rows, $laeuft)` (core/pa_ablauf.php) – laufende PAs oben (untereinander `plan_sort`, dann Startzeit aus `pa_startzeiten()` = erste Unter-Charge bzw. erster erledigter Schritt), danach die übrigen in Plan-Reihenfolge (`plan_sort`, `geplant_am`, id). Grund: laufende PAs stehen nicht im Reiter „Plan“ und lassen sich dort nicht ziehen; sie rutschten in der Werk-App nach unten.

**Nachtrag lässt die laufende Unter-Charge laufen (Fable-4 T1.1, 13.11.2026):** `pa_nachtrag_unter_charge($pa_id, $bulk, $eimer, $tag)` ist die eine Stelle, die für einen Nachtrag die vorhandene Unter-Charge findet: gleicher Bulk bzw. Eimer am selben Tag, und bei „ohne“ (Bulk 0, Eimer 0) am Tag der laufenden Unter-Charge die laufende. Liefert `pa_starten()` im Nachtrag eine schon laufende zurück (`neu` = false), gilt sie als gefunden: kein Wiederanlauf-Ereignis, nie schließen. Der Nachtrag kennt Eimer (`prod_charge_id`).

**Einlagern bei Rest 0 (Fable-4 T1.2, 13.11.2026):** `pa_einlagern_alt_sperre($pa_id, $von)` schließt eine noch laufende Unter-Charge selbst, wenn nichts mehr offen ist (Rest 0 oder „Rest storniert“), mit Vermerk „beim Einlagern, nichts mehr offen“ im Chargenprotokoll, und `einlager_buchen()` bucht weiter. Bei Rest > 0 bleibt die Sperre; die Meldung nennt Werk-App oder PA-Seite. Auf der PA-Seite steht „abschließen“ an jeder laufenden Unter-Charge, auch bei erledigtem PA.

## Nachbesserung Fable-5 (13.11.2026, Teil 1)
- `pa_charge_abweichend()` vergleicht über `charge_norm()` („2775 B“ = „2775.B“); `pa_charge_gleich()`, `pa_charge_im_pa()`.
- `pa_starten()`: eine Chargennummer je Auftrag nur einmal (Unter-Charge oder Lager-Charge) – sonst abgelehnt.
- `pa_nachtrag_buchen()`: genannte Charge einer vorhandenen Unter-Charge (gleicher Bulk) = darauf nachtragen (deren Tag); Etikett-Sperre gilt immer (kein `nachtrag_komplett` mehr, also nur „bis vor dem Etikettieren“); bis zum Soll nachgetragen = Unter-Charge immer schließen.
- `pa_voll_produziert_erledigen()` schließt auch die übergebene Unter-Charge (Status laeuft oder nachtrag).
- `pa_teilmenge_buchen()`: Option `ki_vermerk` schreibt das Ereignis `ki_meldung` sofort nach der Teilmenge – vor jedem Protokoll-Ablegen.
- Lädt `core/charge_norm.php` und `core/pa_produziert.php`.

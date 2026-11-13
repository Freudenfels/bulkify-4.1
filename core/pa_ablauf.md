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

## Nachbesserung Fable-5 (13.11.2026, Teil 2)
- `pa_ist_laufend($status, $produziert)` – eine Regel „laufend“ für Dashboard und Werk-App.
- `pa_schritte_vor_qm($pa_id)` – nur offene Schritte vor der ersten QM-Station; genutzt von „voll produziert“ und `pa_abschliessen()` („Einlagern (Bulk)“ nach der QM bleibt offen).
- `pa_noch_offen()` beachtet „Rest storniert“ (Menge 0) und Mengen „bis vor dem Etikettieren“, liefert die Einheit (`pa_einheit`: Bulk-PA = Einheit des Bulk-Artikels); `pa_noch_offen_text()` lässt bei Rest 0 „· 0 Stk“ weg.
- „produziert“ überall über `pa_produziert()`.

## Menge einer laufenden Unter-Charge korrigieren (Nico 13.11.2026)
`pa_uc_menge_korrigieren($pa_id, $uc_id, $neu, $grund, $von)`: nur bei Status „läuft“, Grund Pflicht.
- **Mehr:** der Unterschied wird wie ein Nachtrag gebucht (`pa_teilmenge_buchen`, Tag der Unter-Charge, Notiz „Korrektur: …“) – Material raus, Fertigware-Charge wächst.
- **Weniger:** die Teilmengen der Unter-Charge werden von hinten verringert (ganz leere Teilmenge wird genau diese Zeile entfernt, Kartons gelöst); das Material dieser Teilmengen geht anteilig zurück (`produktion_verbrauch` mit Minus-Zeile je Charge, Charge wieder verfügbar, zuletzt für den Auftrag angebrochenes Gebinde mitgeführt); die Fertigware-Charge wird um den Unterschied kleiner. Geht nur, solange die Fertigware noch im Lager ist (sonst Meldung mit der kleinstmöglichen Menge). Danach `reservierung_abgleichen`.
- Ereignis `korrektur` im Chargenprotokoll und Verlauf am Kunden.

**Fable-6 P5 (13.11.2026) – eine Materialbuchung:** Dashboard-Nachtrag und Werk-App buchen über `pa_teilmenge_buchen()`, und die Stationen buchen jetzt in beiden Programmen gleich.
- Nachgestellt am 13.11.: Der Nachtrag an PR-2722 (ohne Auftrag) buchte weder Etikett noch Bulk; die Werk-App holte danach alles nach (110 Etiketten für 10 Stk). Jetzt buchen beide Wege dieselben Artikel und Mengen.
- Die gewählte Bulk-Charge der Unter-Charge geht als `$GLOBALS['pa_nur_bulk']` an die Station „Fertigware bereitstellen“. Gebucht wird **nur diese Charge** und nur die Menge dieser Teilmenge (`$GLOBALS['pa_tm_menge']`), damit die Rückverfolgung stimmt.
- Hinweis: Lücken aus früheren Dashboard-Nachträgen (z. B. nicht gebuchte Etiketten) holt die nächste Teilmenge einmalig nach, wie die Restbuchung es vorsieht.

**Fable-6 P8 (13.11.2026) – Mengenkorrektur der Unter-Charge neu:** `pa_uc_menge_korrigieren($pa, $uc, $komplett, $grund, $von, $vor)`
- Zwei Mengen: „komplett“ und „bis vor Etikett“ (`null` = unverändert). Mehr „komplett“ geht nicht, solange das Etikett gesperrt ist.
- **Mehr:** Der Unterschied wird gebucht wie ein Nachtrag (`pa_teilmenge_buchen`, gleiche Materialbuchung).
- **Weniger** (`pa_uc_weniger`):
  - Die Teilmengen werden von hinten verringert und die Fertigware zurückgenommen (nur „komplett“).
  - Bei „bis vor Etikett“ wird `menge_vor_etikett` gesenkt.
  - Ganz entfernte Teilmengen nehmen ihre Rückstellmuster mit (`prod_probe.anzahl`).
  - Kartons über der neuen Menge werden storniert (`pa_karton.storniert_am`, Schema-Version 6).
- **Material zurück** (`pa_material_ueberschuss_zurueck`):
  - Je Artikel wird das Soll für den neuen Stand berechnet. Das geht mit denselben Stations-Funktionen wie beim Buchen, im Sammel-Modus `$GLOBALS['pa_soll_sammeln']`.
  - Was der Auftrag mehr verbraucht hat als dieses Soll, geht in genau die Chargen zurück, aus denen gebucht wurde: zuerst die dieser Unter-Charge, dann die übrigen Buchungen des Auftrags, jeweils neueste zuerst. Die Gebinde werden mitgeführt.
  - Getestet: 100 → 50 Stk gibt genau 50 Gläser, 50 Deckel, 50 Etiketten und 6.000 Bulk zurück (vorher anteilig, z. B. 48 Gläser).
- Alles läuft in **einer Transaktion**. Im Handler gilt: nur Produktionsleitung (`production`) und Admin; leere oder ungültige Eingaben (Text, negativ) lehnt der Server ab, statt sie wie bisher als 0 zu nehmen.

**Fable-6 P12 (13.11.2026) – QM-Gate:** Neue Fertigware-Chargen aus der Produktion (`pa_teilmenge_buchen`) entstehen in **Quarantäne**. Sie bleiben dort, bis QM sie freigibt (Lager-App „Freigaben“ bzw. Dashboard). CoA und Produktionsbericht entstehen erst bei dieser Freigabe; `fertigware_qm_freigabe_nach` läuft nur bei „frei“. Wächst eine bestehende Charge, bleibt ihr Status; nur aus „leer“ wird „Quarantäne“. Bestehende Chargen auf live werden nicht umgestellt. Bulk aus einer Lager-Produktion ohne Produkt ist Zwischenware und bleibt frei.

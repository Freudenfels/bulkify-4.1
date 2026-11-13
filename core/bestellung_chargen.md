# core/bestellung_chargen.php – Chargen & Dokumente an der Bestellposition

**Zweck (08.11.2026, Soll-Handbuch 4.4):** Ein Platz für die Unterlagen zur Lieferung – Charge, MHD, CoA, Spezifikation – je Bestellposition. Ein Baustein für zwei Oberflächen: Bestellseite im Dashboard (`?p=bestellung`, Team) und Bestellung im Lieferantenportal (`?p=lieferant_bestellung`, Lieferant). Beide sehen und pflegen dieselben Daten. Gerade bei „Direkt bestellt" lädt niemand im Portal hoch; dann legt das Team die per Mail erhaltenen Unterlagen hier ab.

**Daten:**
- `bestellung_charge` (neu): Charge des Lieferanten, MHD, Menge, `von` (team/lieferant) je Position – mehrere Zeilen je Position (Teillieferung, zwei Chargen). Vergleichsbasis für den späteren blinden Chargen-Abgleich (4.5, Schritt 6); die Lager-App zeigt sie bewusst noch nicht an.
- Dokumente in der bestehenden Ablage `dokument`: `objekt_typ='bestellung'`, `objekt_id`=Bestellung, neue Spalte `bestellung_position_id`, `charge_nr` (CoA je Charge), `typ` coa/spec. `lieferant_id` ist gesetzt, damit der Lieferant sie im Portal öffnen kann (`lieferant_darf_datei`).

**Funktionen:**
- `bestellung_chargen_panel($b, $von, $sprache)` – der Block (de/en/zh). Je Position: Tabelle der Chargen (CoA-Link bzw. Upload, „Eingebucht" sobald eine Charge mit dieser Nummer gegen die Position gebucht ist), Formular „Charge eintragen" (Charge, MHD, Menge, CoA optional), CoA ohne Chargennummer, Spezifikation (liegt am Rohstoff schon eine vor, wird sie nur angezeigt) und „Weiteres Dokument".
- `bestellung_chargen_post($b, $von, $sprache)` – Aktionen `bch_add`, `bch_coa`, `bch_doc`, `bch_del`, `bch_dok_del`; nur Team: `bch_ki` (KI liest Charge/MHD aus einem CoA ohne Nummer, `spec_ki_lesen`, Ergebnis in `dokument.ki_daten`) und `bch_ki_ok` (Vorschlag bestätigen → Charge-Zeile). Der Lieferant darf nur Eigenes entfernen.
- `charge_bestell_dokumente($charge_id)` – Dokumente einer eingebuchten Charge: über `charge.bestellung_position_id` die Spezifikation(en) der Position und das CoA mit gleicher Chargennummer (tolerant, `charge_gleich`) bzw. ein CoA ohne Nummer. Nichts wird kopiert – ein später hochgeladenes CoA hängt so automatisch an der Charge.
- `bch_position_fuer_charge($charge_id, $kandidaten)` – beim Wareneingang: Position mit derselben gemeldeten Chargennummer, sonst die erste gebuchte.
- `charge_coa_fehlt($charge_id)` – für den Hinweis beim Wareneingang (blockiert nichts).

**Lager-App:** Spiegel in `lager/core/erp.php` (`erp_position_fuer_charge`, `erp_charge_bestell_dokumente`), ohne `core/schema.php`.

## CoA auslesen + Prüf-Popup (08.11.2026)
Nico: „CoA hochladen, es wird ausgelesen, dann prüfe ich (Popup)."
1. **Upload** (Team oder Lieferant, jeder CoA-Weg im Block): `bch_coa_job_starten()` markiert `dokument.ki_daten` = `{"status":"laeuft"}` und stößt `ki_job('bestell_coa', dok)` an (`core/ki_job.php`). Ohne KI-Schlüssel passiert nichts – das Popup bittet dann, selbst einzutragen.
2. **Auslesen im Hintergrund:** `bch_coa_auslesen()` → `spec_ki_lesen()` (Charge, MHD, Menge, Analysewerte) → `spec_ki_merken()`. Fehler landen als `{"status":"fehler"}`; hängt es > 10 Min, gilt es als fehlgeschlagen (`bch_coa_stand`).
3. **Popup** (`bch_coa_dialoge`, nur Team): öffnet nach dem Upload automatisch (`?coa=<dok>`), sonst über „Prüfen" am CoA. Links die Datei (iframe + „In neuem Tab öffnen"), rechts Charge/MHD/Menge (vorbelegt, rot wenn die eingetragene Charge abweicht), Hinweise der KI und die Analysewerte. Während die KI liest, fragt das Popup alle 4 s `?p=bestellung&id=…&coa_stand=<dok>` und lädt neu, sobald das Ergebnis da ist.
4. **Abweichungen:** Grenzwert = unsere Spezifikation am Rohstoff (`item_grenzwert`, tolerant über den Parameternamen, `bch_grenzwert_fuer`), sonst der Wert auf dem CoA. `bch_wert_pruefen()` versteht ≤/</max/NMT, ≥/>/min/NLT, Spannen, „x10^n", Tausender (1.000/1,000), negativ/nicht nachweisbar, entspricht/conforms; sonst „selbst prüfen".
5. **„Geprüft – übernehmen"** (`bch_coa_pruefen`): Charge-Zeile anlegen/anpassen, CoA bekommt die Chargennummer, `coa_geprueft_am/_von`, `befund` bestanden/auffaellig, bestätigte Werte in `ki_daten.werte_bestaetigt`. Bei Abweichung: wichtige Benachrichtigung „CoA-Abweichung". Werte gehen an die Charge (`charge_analyse`) – sofort, wenn schon eingebucht, sonst beim Wareneingang (`bch_werte_an_charge` bzw. `erp_charge_werte_aus_bestellung`).
Am CoA steht ein Status: wird ausgelesen / ausgelesen – bitte prüfen / bitte prüfen / geprüft (· Abweichung).

**Nur das CoA hochladen (08.11.2026):** Im Formular der Position ist die Charge kein Pflichtfeld mehr. Wird nur eine CoA-Datei gewählt, legt `bch_add` das CoA ohne Charge-Zeile ab; die KI liest Charge/MHD/Menge, die Zeile entsteht erst mit „Geprüft – übernehmen“ im Popup. Ohne Charge und ohne Datei: Hinweis „Bitte die Chargennummer eintragen oder ein CoA hochladen“.

**Zwei getrennte Wege je Position (08.11.2026, Nico: „entweder wir tippen es nur ein, oder wir laden eines hoch und es wird ausgelesen“):**
- **CoA hochladen** (`bch_doc`, dok_typ coa): nur die Datei – Charge, MHD, Menge und Werte werden ausgelesen, bestätigt im Prüf-Popup (legt dort die Charge-Zeile an bzw. ordnet das CoA einer schon eingetippten Zeile zu).
- **Ohne CoA eintragen** (`bch_add`): Charge (Pflicht), MHD, Menge – ohne Datei.
- In der Chargen-Tabelle steht bei Zeilen ohne CoA nur „kein CoA“ (kein eigenes Upload-Feld mehr). Spezifikation hat einen eigenen kleinen Upload, nur solange keine vorliegt.

**Anordnung (08.11.2026):** In einer Zeile links „Charge des Lieferanten · MHD · Menge · Eintragen“, dann „oder“, rechts „CoA mit KI auslesen“ (Datei + Hochladen). Keine Kästen/Überschriften mehr.

## Bezug zum Rohstoff (08.11.2026, Nico „ok“ zum Vorschlag)
Ein Bestell-CoA gilt für eine Charge – es **überschreibt den Rohstoff nicht**:
1. **Anzeigen:** `bch_item_dokumente($item)` – Rohstoffseite, Reiter Dokumente, Block „Unterlagen aus Bestellungen“ (CoA/Spezifikation, Charge, Bestellung, Datum, Prüfstatus).
2. **Nur Fehlendes ergänzen** beim „Geprüft – übernehmen“ (`bch_rohstoff_ergaenzen`): Grenzwerte nur, wenn der Rohstoff noch **gar keine** hat; Wirkstoffe nur, wenn noch keine da sind, sonst nur der **Gehalt**, wo er fehlt. Danach `spec_nach_datenaenderung()` – Erst-Daten werden automatisch freigegeben, sonst „wartet auf Freigabe“ (Kunde sieht bis dahin die alte Version). Meldung oben („Am Rohstoff ergänzt: …“), Eintrag im Rohstoff-Verlauf.
3. **Unterschiede melden** (`bch_rohstoff_unterschiede`): Im Popup Kasten „Anders als am Rohstoff – wird nicht automatisch geändert“ (Wirkstoffgehalt CoA vs. Rohstoff, Grenzwert CoA vs. unsere Spezifikation) mit Link zur Rohstoffseite.

## Paket 12.11.2026 Teil 4 (Unterlagen-Regel)
bestellung_versand_coa_fehlt(): „versendet“ nur mit CoA je Charge (Rohstoff, Fremdfertigung, Premix, Bulk); geprüft in bestellung_station_setzen().

**13.11.2026 (Paket Teil 5.4):** Bei „Geprüft – übernehmen“ mit Abweichung kommt eine schon eingebuchte, freie Charge in Quarantäne, und es entsteht die Aufgabe „CoA außerhalb Spec“: dringend, an QM (Rolle labor), Ziel die Bestellung. Bezug ist die Charge, sonst die Bestellung. Die Benachrichtigung an QM bleibt.

**Charge vormerken / Jetzt einbuchen (Sofortfix 13.11.2026):** Team-Knopf heißt „Charge vormerken (noch nicht im Lager)“. Jede vorgemerkte, nicht eingebuchte Charge zeigt rot „nicht im Lager“ und „Jetzt einbuchen“ (`?p=wareneingang&bp=…&bch=…`), auch bei geschlossener Bestellung.

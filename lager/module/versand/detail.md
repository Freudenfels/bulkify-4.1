# versand/detail.php – Sendung planen

Route `?p=versand_detail&id=<versand_id>`. Plant eine Sendung: Empfänger, Versandart, Positionen,
Lieferschein, Abschluss.

**Empfänger:** Kunde wählen (optional) → lädt dessen Adressen per AJAX (`aktion=adressen` →
`erp_kunde_adressen()`), **Lieferadresse ist vorausgewählt**; alle Felder (Firma, Name, Straße, PLZ, Ort,
**Land/ISO-Code – weltweit**, E-Mail, Telefon) bleiben frei editierbar. Snapshot wird am Versand
gespeichert (`lg_versand_kopf_speichern`), bleibt also stabil, auch wenn der Kunde seine Adresse später ändert.

**Versandart:** Paket / Palette, Pakete, Gewicht (kg), Notiz (erscheint auf dem Lieferschein).

**Positionen:** Bestand durchsuchen (`erp_bestand`) und per `aktion=pos_add` mit Menge übernehmen
(Charge-Snapshot in `lg_versand_pos`); freie Position auch möglich. Entfernen per `aktion=pos_del`.
Chargen, die gerade **in der Produktion** sind (`charge.standort='produktion'`, Spec 6.2), werden im
Such-Treffer mit „in Produktion" markiert – als Warnung, sie nicht doppelt zu verplanen.

**Versandart:** Bei **Palette/Fracht** erscheinen zusätzlich Maße je Packstück (Länge/Breite/Höhe cm,
`masse_l/b/h`) – für Cargoboard (Standard Europalette, falls leer).

**Versandart Paket:** Auswahl **DHL-Größe** (groß = Paket, klein = Kleinpaket/Warenpost; `dhl_groesse`) →
Produkt + Label-Format je national/international automatisch.

**Zoll (CN23, nur Nicht-EU-Paket):** Panel mit HS-Code, Ursprungsland (aus dem Artikel vorbelegt,
`erp_item_herkunft_iso2`) und Warenwert je Stück pro Position (`aktion=zoll_speichern` →
`lg_versand_pos_zoll_set`). Ohne diese Daten lehnt DHL die Sendung ab (klare Meldung). Nach der
Label-Erstellung gibt es zusätzlich **Zollpapier drucken/öffnen** (A4).

**Versand-Label & Tracking:** Aktion „label" (`versand_label_erstellen()`): **Paket → DHL**,
**Palette → Cargoboard**. Erzeugt Label (PDF, `?p=versand_label`) + Sendungsnummer, speichert den Carrier.
**Storno** (`aktion=storno` → `versand_storno()`, nur DHL, solange nicht übergeben) löscht Label + Tracking.
Fehlt ein Zugang/Absender → klare Meldung. Zugänge/Absender in den Einstellungen.

**Dokumente drucken:** **Lieferschein drucken** und **Label drucken** schicken einen Druckjob an die
Brücke (`?p=druck_job` mit `typ=lieferschein`/`label`) → lautloser Druck auf den jeweils eingestellten
Drucker. „Öffnen (PDF)" zeigt das Dokument im Browser (`?p=lieferschein` / `?p=versand_label`).

**Abschluss:**
- **Als versendet markieren & abbuchen** (`aktion=versenden`): bucht je Position den Bestand über
  `erp_charge_entnehmen()` ab (nur eigener Bestand, nur noch nicht abgebuchte), löst leere Blinker/Kisten,
  schreibt `lg_bewegung`, setzt Status `versendet`. Hinweise (z. B. Fremdlager) werden gemeldet.
- **Stornieren** (`aktion=stornieren`).

Bearbeiten nur solange Status `geplant`. Carrier-Label/Tracking: Phase 2/3.

Soll-Kette 7 (04.11.2026): „Als versendet markieren“ bucht Positionen aus Aufträgen über das Dashboard (je Auftrag die Summe; Lieferschein-Belege werden an der Sendung vermerkt) – übrige Positionen wie bisher selbst. Versandart **Selbstabholung**: kein Versand-Label.

Packstücke (Soll-Kette 7b, 04.11.2026): Panel „Packstücke“ – „Kartons aus der Produktion übernehmen“ (`ps_kartons`, aus den Kartonwerten des PA), „+ Palette“/„+ Paket“ (`ps_palette`/`ps_paket`), angehakte Kartons einer Palette zuordnen (`ps_zuordnen`, „ohne Palette“ löst), Gewichte speichern (`ps_gewicht`), einzeln entfernen (`ps_del`, Kartons einer gelöschten Palette werden frei). Nur im Status geplant; die Paketzahl der Sendung folgt den Packstücken (Paletten + Pakete + lose Kartons). „Paletten-Etiketten drucken/öffnen“ über Druckjob `palette` bzw. `?p=lieferschein&art=palette`.

**Zahlen (Block D, 11.11.2026):** Mengen-, Gewichts- und Wertfelder lesen deutsch (`erp_menge_parse` bzw. `pr_menge_parse`): 135.000 = 135000, 12,5 = 12,5, 0.045 = 0,045.

**Gewichte (13.11.2026, Fable-3 T1.2):** Gewichts- und Wiegefelder lesen über `gewicht_lesen()` (core/gewicht.php): Punkt und Komma sind Dezimaltrenner („25.125“ kg = 25,125; „6.482“ g = 6,482; „25,000“ = 25), nur wenn beide vorkommen, gilt das letzte. Vorbelegungen ohne Tausenderpunkt (`gewicht_feld()`). Stückzahlen bleiben bei der Mengen-Lesart („12.000“ Stk = 12000). Hier: Gewicht der Sendung (kg), Gewicht je Packstück (kg), Zoll-Gewicht je Stück (g).

**Vorbelegung ohne Tausenderpunkt (Fable-4 T2.4, 13.11.2026):** Mengen-/Preisfelder werden mit `zahl_feld()` (Komma, kein Tausenderpunkt) vorbelegt, damit Speichern ohne Änderung den Wert nicht verändert.

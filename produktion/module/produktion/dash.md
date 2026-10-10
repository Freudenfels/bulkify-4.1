# produktion/module/produktion/dash.php
Dashboard / Startseite (`?p=dash`, Standard nach Login). Zeigt auf einen Blick den Stand der Produktion.

- **KPI-Kacheln:** Offen (zu planen), In Planung, Laufend, Abgeschlossen, **Ø Produktionszeit** (erster→letzter erledigter Schritt, `erp_produktionszeit_schnitt()`), Ø Durchlaufzeit (Auftragseingang→fertig, `erp_durchlaufzeit_schnitt()`). Dauer über `dauer_txt()`.
- **Listen (je max. 8, mit „alle anzeigen"):**
  - *Laufende Produktionen* (`status=laufend`) mit Fortschritt → öffnet den Produktionsmodus.
  - *In Planung* = offene Aufträge mit gesetztem `geplant_am` (nach Termin).
  - *Offen · zu planen* = offene Aufträge ohne Termin, mit „Produzierbar?".

Aufteilung offen → „zu planen"/„in Planung" anhand `produktionsauftrag.geplant_am`. Alles lesend über die Naht (`erp_produktionsauftraege`, `erp_pa_count`, `erp_pa_bereitschaft`, Zeit-Schnitte).

**Blinker-Test (nur Admin):** Panel unten – **Chargennummer oder Blinker-Code** eingeben (Barcode mit „XD" wird normalisiert). `aktion=blinktest`: Blinker-Code → `pr_lager_blink_leiste()` (direkter Hardware-Test), sonst Chargennummer → `erp_charge_id_per_nr()` + `pr_lager_blink()`. „Aus" schaltet ab. Dient dem End-to-End-Test der Pick-to-Light-Kette unabhängig vom Auftragstyp.

- Oben rechts der Button **„Mitarbeiter-App öffnen"** (`?p=werk`) – startet die Vollbild-/Tablet-App für Mitarbeiter (PIN-Login, rein schrittweise).

# core/spec_vergleich.php – Vergleich mit der bulkify-Spec (Paket 13.11.2026, Teil 5)

Die bulkify-Spec (Wirkstoffgehalte `item_wirkstoff` als „mind. X %“, Kennwerte `item_kennwert`, Grenzwerte `item_grenzwert`) ist das Versprechen an den Kunden. Ein hochgeladenes Lieferanten-Dokument überschreibt sie nicht mehr still (`spec_ki_anwenden()` in `core/spec_ki.php`).

- `spec_grenz_art($text)`: Art des Grenzwerts aus dem Text – „mind.“, „max.“, „Bereich“, „negativ“ oder leer.
- `spec_vergleich($item_id, $ki_ergebnis)`: Tabelle Kennwert · bulkify-Spec · Wert im Dokument · Ergebnis.
  - grün = innerhalb, rot = außerhalb, grau = im Dokument nicht angegeben, „selbst prüfen“ = nicht automatisch lesbar, blau = nicht in der bulkify-Spec.
  - Bei einer Spec des Lieferanten muss jede Zahl seiner Angabe unsere Grenze erfüllen: mind. 22 % bei unserem mind. 20 % ist grün, max. 8 % bei unserem max. 5 % ist rot.
  - Bei einer CoA wird der Messwert geprüft. Grundlage ist `bch_wert_pruefen()`.
- `spec_vergleich_dialog()`: das Pop-up nach dem Upload am Rohstoff (`?p=rohstoff&id=…&vergleich=<dokument>`), auch später über „Mit bulkify-Spec vergleichen“. Knöpfe: „Werte übernehmen (überschreiben)“ und „Stand beibehalten“ (Standard).
- `spec_vergleich_uebernehmen()`: ersetzt je Parameter (auch bei leicht anderem Namen, z. B. „Blei“ = „Blei (Pb)“).
  - Bei einer Spec auch die Wirkstoffgehalte; bei einer CoA nur die Grenzwert-Spalte, nie Messwerte.
  - Verlauf in `aenderung_log` (alt → neu, wer, wann, Route `spec_vergleich`) und in der Aktivität mit dem Dokumentnamen. Danach `spec_nach_datenaenderung()` (Kundenfreigabe der Spec wie bisher).
  - Löst keine PIB-Neuprüfung aus.
- `spec_coa_charge_pruefen($charge_id, $item_id, $erg)`: Liegt eine CoA außerhalb der Spec, kommt die Charge (falls frei) in Quarantäne. Dazu entsteht die Aufgabe „CoA außerhalb Spec – …“: dringend, an den ersten Nutzer mit Rolle labor (QM), sonst an das Team; Ziel ist der Rohstoff im Reiter Chargen.

# beleg_positionen.php — Rechnungspositionen aus dem Angebot / manuell

- `beleg_positionen_aus_angebot($beleg_id)`: liest über die Naht (`erp_auftrag`/`erp_angebot_positionen`) die
  hinterlegten Angebotspositionen, wählt die Konfigurations-Gruppe, deren Packungspreis × Auftragsmenge
  dem Auftrags-Netto entspricht (bei nur einer Gruppe diese), und schreibt sie als `beleg_position`
  (Menge = Auftragsmenge). Mirror der Dashboard-Logik `beleg_positionen_aus_auftrag`, aber schreibend.
  Rückgabe `['ok','anzahl','grund']`. Ändert NICHT die Kopfsummen (GoBD) – Positionen sind die
  Aufschlüsselung des bestehenden Betrags; Abweichungen zeigt die Detailseite als Hinweis.
  Hat das Angebot nur Staffelpreise (keine Einzelpositionen), gibt es einen erklärenden Grund zurück.
- `beleg_positionen_manuell_setzen($beleg_id, $zeilen)`: ersetzt alle Positionen aus einem Formular
  (bezeichnung/menge/einheit/preis €/ust). Leere Zeilen werden übersprungen. Kopfsummen bleiben.

Automatisch: `rechnung_aus_auftrag()` (finanz.php) ruft `beleg_positionen_aus_angebot()` nach dem Anlegen
auf – neue Rechnungen aus einem Auftrag bekommen die Aufschlüsselung also direkt (falls im Angebot vorhanden).
UI: Buttons + manueller Editor in `module/beleg/detail.php` (Panel „Positionen").

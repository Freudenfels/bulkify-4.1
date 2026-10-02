# core/ki.php – Schlanker KI-Client fürs Lager

Eigener, abhängigfreier Anthropic-Aufruf (nicht das Dashboard-`core/ki.php`, das den Dashboard-Kern
nachzieht und Konstanten doppeln würde). Passt zum self-contained Muster des Lagers (eigene db/layout).

- **Schlüssel**: `lg_ki_key()` aus derselben `secrets.php` (Konstante `ANTHROPIC_API_KEY`) oder Umgebung;
  `lg_ki_bereit()` steuert Knöpfe/Hinweise. Wird nie geloggt/angezeigt.
- **Aufrufe**: `lg_ki_frage($content,$opt)` (Blöcke → Text), `lg_ki_json(...)` (erwartet JSON, liefert
  `daten`), `lg_ki_datei_block($pfad)` (Foto/PDF → API-Block, 25 MB Grenze).
- **Lieferschein**: `lg_lieferschein_lesen(array $pfade)` → `{ok, lieferant, ls_nr, datum,
  positionen:[{name, menge, einheit, charge_nr, mhd, warenart}]}`. `warenart` = KI-Vermutung
  (rohstoff|verpackung|verbrauch|fertig|kapsel|''). Datum wird auf `YYYY-MM-DD` normalisiert.

Benutzt von [../module/bestand/wareneingang.md](../module/bestand/wareneingang.md). Tabellen-Zugriffe
(Artikel-Matching) laufen NICHT hier, sondern über die Naht `erp.php` (`erp_position_zuordnen`).

# beleg_detail.php — Beleg pruefen/erfassen
Route `beleg_detail?id=`. KI-Felder korrigieren (Belegart/Datum/Lieferant/Belegnr/Kategorie/Waehrung/Netto/USt/Notiz) → „Erfassen" (Status erfasst, zaehlt ins Steuerberater-Paket). „Neu per KI auslesen", „Verwerfen". Datei-Vorschau (Bild inline, sonst Oeffnen).

**Zahlen-Lesart (13.11.2026, Fable-3 T2.9):** Betragsfelder lesen mit `betrag_lesen()`, Preise/Prozente/Kurse mit `zahl_lesen()`, Mengen mit `zahl_lesen(…, true)` – alle aus core/zahl_lesen.php (eine Regel mit dem Dashboard). „21.687,75“, „21687,75“ und „21687.75“ ergeben 21687,75.

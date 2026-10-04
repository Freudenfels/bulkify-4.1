# ui.php – kleine Helfer für die Anzeige

`h()` und `fmt_zeit()` heißen absichtlich wie im Dashboard. Zeit wird immer in UTC gespeichert und in Berliner Zeit angezeigt.

Dazu die Wartezeit-Helfer: `tage_seit()`, `warte_text()` („heute", „gestern", „3 Tage") und `warte_stufe()` (ruhig / warm / heiss). Sowie die Listen für Quelle, Phase und Verlaufsart – wer dort etwas ergänzt, ergänzt es für das ganze Programm.

Für den Verkäufer-Workflow: `crm_phase_farbe()` (Farbe je Phase, für die Pipeline-Spalten) und `crm_segfelder()` (Qualifizierungs-/Segmentierungs-Dropdowns am Kontakt: Kontaktart, Erfahrung, Zielmarkt, Nische, Firmentyp, Volumen, Priorität – aus dem v3-CRM übernommen, ohne Emojis). Beide sind die eine Quelle für diese Werte.

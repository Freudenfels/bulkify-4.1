# produktion/core/schema.php – eigene Tabellen (pr_*)
Nur programmeigene Tabellen des Produktions-Programms. Dashboard-Tabellen laufen ausschließlich über `erp.php` (die Naht), nicht hier.

- `pr_pa_daten` (pa_id, feld, wert, von, aktualisiert; PK pa_id+feld): erfasste Werte je Produktionsauftrag – z. B. `mischmenge`, `kontrolle_gewicht`, `rueckstellmuster`, `labormuster`.
- `pr_schema()` legt die Tabelle an (idempotent, lazy aus den Helfern). `pr_daten_setzen($pa_id,$feld,$wert,$von)` schreibt/überschreibt einen Wert, `pr_daten($pa_id)` liest alle als `feld => [wert,von,aktualisiert]`.

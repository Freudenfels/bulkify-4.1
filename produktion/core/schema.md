# produktion/core/schema.php – eigene Tabellen (pr_*)
Nur programmeigene Tabellen des Produktions-Programms. Dashboard-Tabellen laufen ausschließlich über `erp.php` (die Naht), nicht hier.

- `pr_pa_daten` (pa_id, feld, wert, von, aktualisiert; PK pa_id+feld): erfasste Werte je Produktionsauftrag – z. B. `mischmenge`, `kontrolle_gewicht`, Rückstellmuster/Laborprobe/QS-Freigabe.
- `pr_raum`, `pr_maschine` (Betriebsmittel mit Reinigungsintervall + letzte_reinigung). Helfer `pr_raum_*`, `pr_maschine_*`, `pr_gereinigt_setzen`, `pr_reinigungsplaene` (generierte Pläne), `pr_intervalle`. (`pr_reinigung` alt/ungenutzt.)
- `pr_schema()` legt die Tabelle an (idempotent, lazy aus den Helfern). `pr_daten_setzen($pa_id,$feld,$wert,$von)` schreibt/überschreibt einen Wert, `pr_daten($pa_id)` liest alle als `feld => [wert,von,aktualisiert]`.

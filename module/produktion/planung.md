# produktion/planung.php – Produktionsplanung (Termine setzen)

Dashboard-Seite zum **Sammel-Setzen** des geplanten Produktionsdatums. Route `?p=produktion_planung`,
Menü „Produktion → Planung". Rolle: `production` (Admin immer).

- Zeigt alle **offenen/laufenden** Produktionsaufträge (`status IN ('offen','laufend')`) mit je einem
  **Datumsfeld** (`geplant[<pa_id>]`). Sortierung: **ohne Termin zuerst**, dann nach Datum, dann Prio.
- Speichern (`aktion=plan_bulk`): je Zeile `UPDATE produktionsauftrag SET geplant_am=? WHERE id=?`;
  leeres/ungültiges Datum = **Termin entfernen** (NULL). Mehr wird nicht geschrieben.
- Zusatz-Infos je Zeile: Prio-Punkt, Kunde, Produkt, Eigen/Fremd (bzw. „festlegen", wenn
  `art_festgelegt_am` NULL), überfällig-Markierung.

**Vertrag mit `/produktion/`:** Die Produktion **liest** `geplant_am` nur (Spalte „Wann dran",
Kalender, Sortierung). Gesetzt wird ausschließlich hier im Dashboard – keine weiteren Felder/Tabellen.

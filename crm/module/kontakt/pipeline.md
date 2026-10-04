# pipeline.php – die Vertriebs-Pipeline

Route `?p=pipeline`. Der zentrale Blick für Verkäufer: **alle Kontakte als Board**, Spalte je Phase
(`neu` → `im Gespräch` → `Angebot draußen` → `gewonnen` / `verloren`).

- **Verschieben:** Karte per **Drag & Drop** in eine andere Spalte (Desktop) oder per **Dropdown**
  (Handy, `tun=phase`). Beides ruft `kontakt_phase_setzen()` → setzt `phase_at` und schreibt eine
  Verlaufszeile. Drag & Drop speichert still per `fetch` (`ajax=1`).
- **Karte:** Firma/Name, geschätzter Wert, Zuständiger, „seit X Tagen". Bei Phase *Angebot draußen*
  und ≥ `CRM_ANGEBOT_NACHFASSEN` Tagen erscheint rot „nachfassen". Priorität *hoch* → roter Rand.
- **Filter:** Volltextsuche (clientseitig), Quelle, Sortierung, Archiv.

Arbeitet ausschließlich auf `crm_kontakt`; geschrieben wird nur die Phase. Mobil klappt das Board in
eine Spalte und zeigt die Dropdowns.

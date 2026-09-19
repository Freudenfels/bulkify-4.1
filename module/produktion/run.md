# produktion/run.php – Geführte Produktion (Mitarbeiter)

**Zweck:** Schlanke, fokussierte Ansicht, mit der ein Mitarbeiter einen Produktionsauftrag **Schritt für Schritt** abarbeitet – getrennt von der Admin-Detailseite (`produktionsauftrag`), damit sie touch-/App-tauglich bleibt. Route `?p=produktion_run` (Rollen production/labor/fulfillment/admin).

**Zwei Ansichten:**
- **Ohne `id` – Auswahl:** Tabelle aller offenen/laufenden Aufträge, **sortiert nach Priorität, dann Eingangsdatum (FIFO)**. Spalten: Prio · Nummer · Produkt/Kunde · Status · Fortschritt · **Machbar** · Eingang. **Nicht machbare Zeilen sind ausgegraut und nicht anklickbar** (`opacity:.5`). Machbar = `produktion_bereitschaft()` ist nicht „wartet" **und** kein fehlendes Etikett-Design (`etikett_vorhanden()`, nur wenn das Produkt einen Etikett-Slot hat). Badges: produzierbar · in Produktion · wartet auf Material · Etikett-Design fehlt.
- **Mit `id` – Ablauf:** Fortschrittsbalken, **ein** großer Schritt-Block (Station + Anleitung + Scan-Feld/Großer Button „Erledigt/Freigeben/Scannen"), darunter kompakt „Alle Schritte". Am Ende ein großes „✓ Produktion abgeschlossen" mit Charge + Menge.

**Logik:** Das Abschließen eines Schritts läuft über die zentrale Funktion **`produktion_schritt_erledigen($pa_id,$schritt_id,$scan)`** in `core/schema.php` – dieselbe Funktion nutzt auch die Admin-Detailseite (und später App/API). Sie erzwingt die Reihenfolge, prüft Scan bzw. **Master-Scan** (`ist_master_scan()` – eine 8er-Folge überspringt Prüfung + Bestandsabbuchung, für Tests), bucht Material FEFO ab, setzt den Status und schließt beim letzten Schritt den Auftrag ab (Fertigware einbuchen + Auftrag „erledigt").

**Zugänge:** Menü „Geführte Produktion" (Werk-Menü oben, auch Admin-Menü); Button „Geführt produzieren" auf der Admin-Detailseite.

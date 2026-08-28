# lager/naehrstoffe_liste.php – Nährstoff-/NRV-Referenz (Liste)

**Zweck:** Die zentrale Liste aller Nährstoffe/Wirkstoffe mit ihrem **NRV** (Nährstoffbezugswert je Tag). Eine Quelle, an die sich jeder Rohstoff hängt – damit die Rezeptur später „% NRV" rechnen und gleiche Nährstoffe über mehrere Rohstoffe hinweg addieren kann.

**Was passiert hier:**
1. `seed_naehrstoff_if_empty()` – befüllt die Liste einmalig mit allen offiziellen EU-NRV-Nährstoffen (Vitamine + Mineralstoffe) plus eigenen ohne NRV (z. B. Curcumin, Withanolide).
2. **Suche** nach Name, **Sortierung** (Standard Name A–Z).
3. Tabelle: **Name · Kategorie · NRV/Tag · Typ** (offiziell / eigen).
   - Klick öffnet den Nährstoff (`?p=naehrstoff&id=...`).

**Wächst automatisch:** Tippt man beim Rohstoff einen neuen Wirkstoff ein (der nicht in der Liste ist), wird er hier automatisch angelegt (Typ „eigen", ohne NRV) – über `naehrstoff_id_by_name()`.

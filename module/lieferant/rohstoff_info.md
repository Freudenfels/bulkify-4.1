# lieferant/rohstoff_info.php – Rohstoff-Info (JSON) fürs Lieferantenportal

**Zweck:** Liefert als **JSON** die Infos zu einem Rohstoff für das Popup in den Lieferanten-Rezeptur-Ansichten (Anfrage + direkte Rezeptur). Route `?p=lieferant_rohstoff_info&iid=<item_id>` (in `$LIEF_ROUTEN` + `route_rollen_map` als `['*']`, nur eingeloggte Lieferanten via `ist_lieferant()`).

**Was zurückkommt:** `{name, rows, wirkstoffe}` – `name` = Anzeigename (`rohstoff_anzeige_name()`, z. B. „Ashwagandha Extrakt 10:1"), `rows` = Identität/Beschaffenheit (Beschaffenheit+DEV, Form, CAS, lat. Name, Synonym, EC, botanische Quelle, Herkunft, Allergene, Vegan/GVO/Bestrahlt/TSE, Zertifikate, Zusätze, Haltbarkeit, Lagerbedingungen) + relevante Kennwerte, `wirkstoffe` = Name + Gehalt %.

**Bewusst NICHT enthalten:** Preise, Lieferanten, interne Artikelnummern. Nur was der Lieferant zum Vergleichen/Kalkulieren braucht.

**Popup:** `lp_rohstoff_popup()` in `portal_layout.php` gibt Dialog + JS aus (bindet an `a.lp-roh[data-iid]`, holt dieses JSON). Eingebunden in `anfrage.php` und `rezeptur_ansicht.php`, wo die Zutaten mit `item_id` als Link gerendert werden.

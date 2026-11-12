# core/item_link.php – Link auf einen Artikel

**Anlass (Nico 12.11.2026):** „Ich kann nicht auf das Material klicken, um zum Material zu kommen.“

- `item_url($item_id, $kategorie = null, $tab = 'lager')` – richtige Seite je Warenart: Verpackung/Etikett → `?p=verpackung`, Betriebsmittel → `?p=betriebsmittel`, alles andere (Rohstoff, Bulk, Fertigware) → `?p=rohstoff`. Standard ist der Reiter **Lager** (Bestand, Chargen, Ort/Blinker, Bestand korrigieren).
- `item_link_html($item_id, $name)` – der Name als Link (escaped). Ohne Artikel-ID nur Text.

**Wo benutzt:** Produktionsauftrag (`module/produktion/detail.php`): Material-Tabellen und Fehlermeldung „Teilmenge nicht gebucht“.

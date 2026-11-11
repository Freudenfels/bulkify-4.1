# Fertigprodukte gezielt anfragen (`core/fertig_anfrage.php`)

Stand 11.11.2026, Nico. Anlass: Mingyan hatte im Portal 10 Rezepturen bepreist, die sie selbst aus ALLEN Kapsel- und Tabletten-Rezepturen ausgewählt hatte.
- Die Auswahl lief blind nach dem Namen, ohne Zusammensetzung.
- Darunter waren auch Kundenrezepturen.
- Wir hatten nichts angefragt.

**Neu:** Wir legen fest, welche Rezepturen ein Lieferant bepreist.

- `fertig_rezeptur_kandidaten($lieferant_id)`: Rezepturen der Formen des Lieferanten (`fertig_formen`, sonst Kapsel, Tablette, Softgel, Stick). Ohne abgelehnte und leere. Je Rezeptur Kunde, offene Aufträge, angefragt und bepreist. Rezepturen aus offenen Aufträgen stehen zuerst.
- `fertig_anfragen_stellen($lieferant_id, $rezeptur_ids, $notiz)`: Je Rezeptur entsteht eine Fremdfertigungs-Anfrage (`lieferant_anfrage`, art `fertigprodukt`, Form, Kapselgröße der Rezeptur, CoA gewünscht), wie „Fertigprodukt anfragen“ an der Rezeptur. Danach geht eine Sammel-Mail an den Lieferanten. Schon offen angefragte Rezepturen werden übersprungen.
- `fertig_zusammensetzung_html($rezeptur_id, $sprache)`: Zusammensetzung je Einheit (Rohstoff, mg), Füllgewicht und Kapselgröße, für das Portal auf DE, EN und ZH.

**Wo:**
- Lieferantenseite, Reiter Preise, Block „Fertigprodukte anfragen“ mit Suche und Häkchen.
- Portal „Fertigprodukt-Preise“, Block „Bitte bepreisen“ mit aufklappbarer Zusammensetzung und „Angebot abgeben“. Dieser Knopf führt zur Anfrage: Preis, Staffel, CoA/Spec je Rohstoff, Preis gültig bis.

# kontingent/detail.php — Jahresvertrag-Detail

Route `?p=kontingent&id=` (Whitelist in `public/index.php`; ohne Rollen-Eintrag = Admin-Default wie die Liste).
Zeigt zu einem Kontingent (Jahresvertrag):

- **Kopf-Kacheln:** Kunde, Produkt (Link), Status, VK je Packung, vereinbart/abgerufen/Rest, Laufzeit.
- **Zusammensetzung:** Rezeptur (+ Darreichungsform), Stück je Packung, Verpackung (Gebinde), Verschluss,
  Etikett, Umkarton — aus den Slots des verknüpften Produkts. Leere Slots werden als „nicht hinterlegt"
  markiert. Bearbeitet wird die Zusammensetzung über **das Produkt** (`?p=produkt&id=`), nicht hier
  (dort liegen die Gebinde-/Etikett-Slots) — Button „Produkt bearbeiten".
- **Rechnungs-Vorschau (je Packung):** `kontingent_abruf_positionen($k, 1, $ustP)` — zeigt exakt, wie ein
  Abruf die Rechnung aufschlüsselt (Produkt + Glas + Etikett, skaliert auf den Festpreis je Packung).
  Gibt es keine aufgeschlüsselten Einzelpreise (kein Angebot mit Positionen / Option nicht eindeutig),
  erscheint der Hinweis, dass die Abruf-Rechnung eine Packungszeile zum Festpreis zeigt.
- **Abrufe:** alle Aufträge mit `kontingent_id = id`, je mit Menge, Netto, Status und Rechnungsnummer
  (Link zum Auftrag).

Erreichbar aus `kontingent/liste.php` über den Produktnamen und den Button „Details".
Siehe auch `core/schema.php`: `kontingent_abruf_positionen()` (Aufschlüsselung) und `kontingent_abruf()`.

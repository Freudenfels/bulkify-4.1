# module/einkauf/bestellung_pop.php – Pop-up an einer Bestellung (Paket 13.11.2026, Teil 6.4)

Route `?p=bestellung_pop&id=…` (Rolle einkauf). In „Bestellt“ öffnet ein Klick auf die Zeile diese Seite als Pop-up über der Liste (`bx_table` `rowPop`, `bx-ui.js`). Gespeichert wird ohne Seitenwechsel, danach aktualisiert sich die Zeile. Gedacht für Lieferanten ohne Portal-Zugang: Nico trägt ein, was per Mail kommt.

- **Dokumente:** Upload direkt im Pop-up – CoA (mit Charge), Spec, Rechnung, Lieferschein (`dokument_upload('bestellung', id)`, mit Bezug zur Position). Die CoA mit KI-Prüfung und Spec-Vergleich bleibt im Block „Chargen und Dokumente“ an der Bestellung.
- **Versand:** Versanddienst, voraussichtliches Lieferdatum (`eta_geplant`), Tracking-Nummern. Mehrere Nummern gehen eine je Zeile in die Tabelle `lieferung_paket`, die erste auch in `bestellung.tracking` (`bestellung_tracking_setzen()`).
- **Sendung:** Art (Paket, Palette, Container), Anzahl, Palettenart (Euro, Einweg, Tausch/Wechselpalette), Kisten/Kartons, Gewicht gesamt, Maße, Stellplätze. Die Spalten liegen an `bestellung` (`bestellung_sendung_schema()`).
- **Notiz:** intern.

**Vorbelegung ohne Tausenderpunkt (Fable-4 T2.4, 13.11.2026):** Eingabefelder mit `zahl_feld()` vorbelegt (Komma, kein Tausenderpunkt).
Gewicht gesamt wird mit `gewicht_lesen()` gelesen (vorher Preis-Lesart: Vorbelegung „1.250“ kg wurde beim Speichern 1,25 kg).

**Ware ist da (Sofortfix 13.11.2026):** oben je offener Position „Ware ist da - einbuchen“ -> Wareneingang, vorbelegt.

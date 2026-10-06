# module/dienstleistung/detail.php – Dienstleistung anlegen & bearbeiten

Route `?p=dienstleistung&id=neu` (anlegen) bzw. `?p=dienstleistung&id=<ID>` (bearbeiten).

## Felder (gruppiert)
- **Stammdaten:** Name (Pflicht), Kategorie, Verkaufsart (Add-on/eigenständig/beides), Abrechnung (einmalig/monatlich), Beschreibung (kundensichtbar).
- **Preis:** Preismodell, Einheit (nur bei pro Einheit/Stunde/monatlich), VK netto (€), EK intern (€), MwSt (0/7/19).
- **Verknüpfung & intern:** vorhandener Baustein (zeigt auf bestehende Service-Logik, keine Duplizierung), Status aktiv, interne Notiz.

## Verhalten
- Nummer wird beim Anlegen automatisch vergeben (`DL-…`).
- Euro-Eingaben werden über `dienstleistung_cent()` in Cent gespeichert.
- Bei Preismodell „auf Anfrage" wird **kein** VK gespeichert (Preis je Fall im Angebot). Kleines JS blendet VK/Einheit passend zum Preismodell ein/aus.
- MwSt-Werte werden auf 0/7/19 begrenzt.
- „Löschen" entfernt den Katalog-Eintrag (POST `aktion=loeschen`, mit Rückfrage).

Speichern → POST `aktion=save` → Redirect mit `&gespeichert=1`. Logik aus `core/dienstleistung.php`.

## Ablauf & Ergebnis (Workflow)
Panel „Ablauf & Ergebnis": **Schritte** (ein Schritt je Zeile = Fortschritts-Punkte der Aufträge dieser DL; leer = Baustein-Vorlage), Schalter **Endergebnis-Upload** und **Upload schließt ab** (nur aktiv, wenn Upload erlaubt). JS füllt bei Baustein-Wechsel die Schritte-Vorlage ein, solange das Feld leer ist. Speichern schreibt `dienstleistung_schritt` via `dl_katalog_schritte_setzen()` + die zwei Flags.

## Kundenpreise + „ohne Fortschritt"
- **Kundenpreise** (optional): Panel unter Preis – je Kunde ein abweichender VK (dienstleistung_kundenpreis). Standard = dienstleistung.vk_cent. dl_position_add nimmt automatisch den Kundenpreis (dl_kundenpreis) des Angebots-Kunden, sonst Standard.
- **„Kein Fortschritt – nur Abrechnung"** (ohne_fortschritt, z. B. Fulfillment/Lagerung): Schalter im Ablauf-Block. Dann werden keine Schritte angelegt (dl_auftrag_schritte_anlegen überspringt), der DL-Auftrag hat keinen Fortschritt – nur Status + Rechnung. Portal zeigt bei leerem Track keinen Balken (portal_auftrag_track fällt für DL NIE auf Produkt-Phasen zurück).

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

# Inventur-API (`?p=inventur_api&id=&a=`)

JSON für die Zählseite, seit 11.11.2026. **Keine Antwort enthält eine Soll-Menge.**

Aktionen:
- `scan`
- `start`: Bereich
- `zaehlen`
- `klaerfall`: multipart mit Foto
- `scans`: Ist-Werte des Platzes
- `fertig`
- `artikel`: Artikelliste und Gebindetypen
- `nachzaehlen`
- `kf_etikett`: Klärfall-Etikett als PDF

Zugriff nur, solange die Inventur zählbar ist und der Nutzer zählen darf (`inv_darf_zaehlen`).

**Blinker (Sofortfix 13.11.2026):** neue Aktionen `auswahl`, `leuchten`, `aus`; `start` und Scan einer Kiste schalten den Blinker an (`licht`), `fertig` schaltet ihn aus (`aus`) und liefert `naechster` (blinkt schon) und die neue `liste`.

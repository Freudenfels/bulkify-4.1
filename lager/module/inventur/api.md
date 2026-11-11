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

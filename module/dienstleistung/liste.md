# module/dienstleistung/liste.php – Dienstleistungs-Katalog (Liste)

Route `?p=dienstleistungen` (Nav: Vertrieb → Dienstleistungen). Zeigt alle Katalog-Einträge mit Nummer, Name, Kategorie, Preismodell, VK, Verkaufsart und Status.

- Zeile anklicken → `?p=dienstleistung&id=…` (bearbeiten).
- Button „+ Neue Dienstleistung" → `?p=dienstleistung&id=neu`.
- Je Zeile „aktivieren/deaktivieren" (POST `aktion=toggle`).
- Ist der Katalog leer: Button „Start-Dienstleistungen anlegen" (POST `aktion=startseed`) – legt Laboranalyse, Abfüllung, Beratung, Rezepturbewertung an (idempotent).

Logik/Preise kommen aus `core/dienstleistung.php`. Rechte: `sales`, `finance` (admin immer) – siehe `route_rollen_map()` in `core/auth.php`.

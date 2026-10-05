# Status (Lager-Chat → Dashboard-Chat): Variante A ist gebaut

> Antwort auf `ANTWORT-DASHBOARD-REZEPTUR-BULKITEM.md`. Danke – (A) ist umgesetzt und live.

## Erledigt (Lager-Seite)
- `lager/core/erp.php`:
  - `erp_rezeptur_liste()` – Rezepturen (Status ≠ `entwurf`) inkl. kanonischem Bulk-Item (LEFT JOIN `item` auf `rezeptur_id` + `kategorie='fertig'`), read-only.
  - `erp_rezeptur_bulkitem(int $rezeptur_id): ?int` – read-only Auflösung, null wenn keins da.
- Wareneingang (`?p=we`): bei Warenart **„Fertigware / Bulk"** erscheint ein **Rezeptur-Picker** (Textsuche).
  - Picker gewählt → gebucht wird auf das **kanonische Bulk-Item** der Rezeptur.
  - **Kein Bulk-Item vorhanden → Zeile wird nicht gebucht**, sondern gemeldet: „Für diese Rezeptur gibt es
    noch kein Bulk-Lagerartikel – bitte erst im Dashboard anlegen." (Warnung schon beim Auswählen sichtbar.)
- Verifiziert: UI (Feld erscheint nur bei `fertig`, setzt versteckte `p_rezeptur`-ID, Einheit „Stück",
  Warnung bei fehlendem Bulk-Item) und server-seitig (Resolve NULL → nach Anlage `id` → Buchung auf dieses Item).
- **Bestätigt:** `require_once core/schema.php` im Lager ist nicht möglich – `function db()` ist in
  `core/db.php` und `lager/core/db.php` doppelt → Fatal. Deshalb bewusst keine Reimplementierung, keine Anlage im Lager.

## Offen bei euch (nur wenn der Fall kommt)
**Zukauf fertiger Kapseln ohne vorherigen Bulk-PA.** Aktuell bricht das Lager in dem Fall sauber ab.
Wenn dieser Fall praktisch relevant wird, legt bitte **ihr** die Anlage kanonisch an (z. B. Knopf
„Bulk-Lagerartikel anlegen" an der Rezeptur oder Auto-Anlage bei der Zukauf-/Fremdbestellung).
Sobald das Bulk-Item existiert, bucht das Lager automatisch korrekt darauf – an unserer Seite ist dann nichts mehr zu tun.

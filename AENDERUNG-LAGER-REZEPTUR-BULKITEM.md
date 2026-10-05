# Änderung: Jede Rezeptur hat jetzt einen koppelbaren Lager-Artikel

**Stand:** 05.10.2026 · betrifft **v4** (Dashboard + Lager-App) · Thema: Wareneingang /
Zuordnung Rezeptur ↔ Lager

## Das Problem (vorher)

Wenn ein Kunde bestellt hat und die Ware (z. B. fertige Kapseln einer Rezeptur) im Lager
ankam, konnte man sie **nicht der Rezeptur zuordnen**, weil es zu den meisten Rezepturen
gar keinen Lager-Artikel gab. Beim Einbuchen kam dann die Meldung „Für diese Rezeptur gibt
es noch kein Bulk-Lagerartikel". Man sah zwar die Bestellung, konnte die Ware aber nicht
sauber verbuchen.

## Was jetzt gilt

**Jede Rezeptur hat genau einen eigenen Lager-Artikel** – das sogenannte **Bulk-Item**
(Kategorie „Fertigware/Bulk"). Das gilt automatisch für:

- **alle bereits vorhandenen Rezepturen** (einmaliger Nachtrag beim nächsten Server-Update –
  es wurden 178 Rezepturen abgedeckt);
- **jede neu angelegte Rezeptur** (das Bulk-Item entsteht sofort beim Speichern), auch bei
  „Neue Version".

Das passiert **unabhängig davon**, ob der Kunde die Rezeptur je kauft oder ob sie je in
Produktion geht. So ist immer ein Lager-Artikel da, auf den man einlagern kann.

### Wie das Bulk-Item aussieht

- **Artikelnummer:** `BULK-xxxx` (automatisch).
- **Name:** `<Rezepturname> – Bulk` (z. B. „Ashwagandha KSM-66 – Bulk").
- **Einheit:** Stück (Kapsel/Tablette/Softgel), `g` (Pulver) oder `ml` (flüssig/Gel) – je nach
  Darreichungsform der Rezeptur.
- Fest mit der Rezeptur verknüpft (über `item.rezeptur_id`).

## Was das fürs Lager bedeutet

Beim **Wareneingang** kann die ankommende Ware jetzt der Rezeptur zugeordnet werden:
- Warenart **„Fertigware / Bulk"** wählen und die **Rezeptur** auswählen → die Buchung läuft
  automatisch auf das Bulk-Item dieser Rezeptur. Keine Fehlermeldung mehr.
- Es muss **kein** Artikel mehr von Hand im Dashboard angelegt werden.

## Was sich NICHT geändert hat

- Es werden **keine** Produktionsaufträge, Bestellungen oder Preise erzeugt – nur der
  Lager-Artikel (Katalogeintrag) entsteht.
- Bestehende Bulk-Items/Chargen bleiben unverändert; es entstehen keine Dubletten
  (pro Rezeptur genau ein Bulk-Item).

---

### Technischer Hinweis (für die Entwicklung)

Geänderte Dateien (v4-Repo `bulkify-4.1`):
- `core/schema.php` – in `init_schema()` ein idempotenter Backfill: legt für jede Rezeptur
  ohne Bulk-Item eines an (`rezeptur_bulkitem()`); läuft einmal je Deploy.
- `module/rezeptur/detail.php` – beim Speichern einer Rezeptur (neu + „Neue Version") wird
  `rezeptur_bulkitem()` aufgerufen, damit das Bulk-Item sofort existiert.

Modell: Bulk-Item = `item` mit `kategorie='fertig'` und `rezeptur_id = <Rezeptur>`. Die
Lager-Naht `erp_rezeptur_bulkitem()` (read-only) findet es dadurch immer; die
Sub-App-Isolation bleibt unangetastet.

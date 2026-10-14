# lief_anfrage_detail.php – Lieferanten-Anfrage im Detail (Team)

Detailseite EINER Lieferanten-Anfrage (RFQ, Nummernkreis „LA"). Route: `?p=lief_anfrage_detail&id=<id>`
(auch `&nr=LA-123`). Zweck: an EINER Stelle sehen, **was** bei **welchem Lieferanten** angefragt wurde,
für **welche Rezeptur/welchen Rohstoff** und **welchen Kunden**, das **abgegebene Angebot** (Preis, Staffel,
MOQ, Lieferzeit, Lieferbedingung) und die **Rückfragen** (Chat Team↔Lieferant) zu genau diesem Vorgang.

- Worum geht es: Rezeptur (→ `rezeptur_detail`) + Kunde (`rezeptur.kunde_id`), sonst Rohstoff (→ `rohstoff`), sonst Betreff.
- Angebot: `lieferant_angebot` (+ `lieferant_angebot_staffel`); „Preise übernehmen" ruft `lieferant_angebot_annehmen()` (Rolle admin/einkauf).
- Rückfragen: `nachricht_panel(..., 'lieferant_anfrage', id)` – zeigt + beantwortet den Chat zu dieser Anfrage; markiert Team-Nachrichten als gelesen.

**Verlinkt von:** globaler Suche (Gruppe „Lieferanten-Anfragen", Nummernkreis LA), der Liste
`lief_anfragen` (Spalte „Nr.") und dem Rückfragen-Chat (`core/nachricht.php`, Team-Link bei bezug `lieferant_anfrage`).
Vorher führten diese Stellen ins Leere – es gab team-seitig keine Einzelansicht einer Anfrage.

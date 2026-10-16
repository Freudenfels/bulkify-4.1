# einkauf/preise.php – Einkauf → Preise (alle Einkaufspreise)

Eine Seite für **alle** Einkaufspreise, schlanker Tabellen-Stil + Klick-Popup. Route `?p=einkauf_preise`,
Menü „Einkauf → Preise". Rolle: production/einkauf/labor (+ Admin). Reiter (`?tab=`):

- **fremd** – Fremdfertigung (Kapsel/Fertigprodukt): **festgesetzte Rezeptur-Preise** aus `rezeptur_lief_angebot`
  (v3 + alle über Preisanfragen abgegebenen Preise, die dorthin gespiegelt werden) **plus** reine **Freitext**-
  Fertigprodukt-Anfragen ohne Rezeptur (`lieferant_anfrage.art='fertigprodukt' AND rezeptur_id IS NULL` JOIN
  `lieferant_angebot`). Rezeptur-verknüpfte LA-Angebote erscheinen NICHT mehr doppelt – sie leben als Festpreis
  in `rezeptur_lief_angebot` (gespiegelt per Bridge in `lieferant_angebot_speichern` + Einmal-Migration
  `fix_la_rez_spiegel_v1`). Spalten: Rezepturnr., Anfrage (LA-Nr.), Rezeptur-Name, Form, Kapselgröße, Lieferant,
  **Festpreis**, **Freigabe**.
  - **Festpreis + Freigabe:** `rezeptur_lief_angebot.preis` ist der FREIGEGEBENE Preis (nur der zählt in der
    Kalkulation, Quelle A). Ändert der Lieferant einen bereits festgesetzten Preis (über „Rezeptur-Preise" oder
    eine neue Preisanfrage), landet das in `preis_vorschlag` → **wartet auf Freigabe**; bis dahin rechnet alles mit
    dem alten Preis weiter. Team gibt hier per **Freigeben**/**Ablehnen** frei (`rezeptur_lief_preis_freigeben()` /
    `..._ablehnen()`); Banner zeigt die Zahl offener Vorschläge (`rezeptur_lief_vorschlag_count()`). „unbestätigt
    seit X T" markiert überfällige Preise (4-Wochen-Regel; wir rechnen weiter, nur mit Hinweis).
  - Die frühere **Quelle C** (roher `lieferant_angebot`-Preis) in `rezeptur_fremd_ek_pro_einheit()` ist dadurch
    entfallen – sonst würde ein noch nicht freigegebener Preis die Freigabe umgehen.
- **rohstoff** – `lieferant_preis` (verknüpft, mit Staffel/Einheit) + darunter die flache **EK-Preisliste**
  (`lieferant_preisliste`, v3-Referenz) eingeblendet. Klick → Rohstoff-Popup.
- **verpackung** – EK-Staffeln je Behälter (`pack_ek_staffel`). Klick → Artikel-Popup.
- **zukauf** – Fertigprodukt-Zukauf (`produkt_lieferant_preis`). Klick → Produkt-Popup.

Suche je Reiter (Artikel/Rezeptur oder Lieferant). Das Popup ist für alle Reiter dasselbe Overlay
(Link-Klasse `bx-rezpop`, href = Detailseite; Esc/Klick-außen schließt).

**Abgelöst:** Die früheren Einzelseiten leiten hierher weiter:
`rezept_preise` → `&tab=fremd`, `lieferant_preise` → `&tab=rohstoff` (bzw. `fertigprodukt`→`zukauf`),
`lief_preisliste` → `&tab=rohstoff`. Menüeinträge entsprechend zusammengefasst.

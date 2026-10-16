# einkauf/preise.php – Einkauf → Preise (alle Einkaufspreise)

Eine Seite für **alle** Einkaufspreise, schlanker Tabellen-Stil + Klick-Popup. Route `?p=einkauf_preise`,
Menü „Einkauf → Preise". Rolle: production/einkauf/labor (+ Admin). Reiter (`?tab=`):

- **fremd** – Fremdfertigung (Kapsel/Fertigprodukt): `rezeptur_lief_angebot` (v3) + **alle beantworteten
  v4-Fertigprodukt-Angebote** (`lieferant_anfrage.art='fertigprodukt'` JOIN `lieferant_angebot` + Staffeln) –
  **auch ohne Rezeptur-Verknüpfung** (Freitext-Anfrage); dann dient `lieferant_anfrage.betreff`/`.nummer` als
  Bezeichnung, damit das abgegebene Angebot sicher erscheint. Der Reiter-Zähler zählt beide Quellen.
  Spalten: Rezepturnr., Rezeptur-Name, Form, Kapselgröße, Lieferant, Preis. Klick auf die Rezeptur → Popup
  (iframe auf `rezeptur_popup` – schlanke read-only Ansicht; KEIN Dashboard-Menü).
- **rohstoff** – `lieferant_preis` (verknüpft, mit Staffel/Einheit) + darunter die flache **EK-Preisliste**
  (`lieferant_preisliste`, v3-Referenz) eingeblendet. Klick → Rohstoff-Popup.
- **verpackung** – EK-Staffeln je Behälter (`pack_ek_staffel`). Klick → Artikel-Popup.
- **zukauf** – Fertigprodukt-Zukauf (`produkt_lieferant_preis`). Klick → Produkt-Popup.

Suche je Reiter (Artikel/Rezeptur oder Lieferant). Das Popup ist für alle Reiter dasselbe Overlay
(Link-Klasse `bx-rezpop`, href = Detailseite; Esc/Klick-außen schließt).

**Abgelöst:** Die früheren Einzelseiten leiten hierher weiter:
`rezept_preise` → `&tab=fremd`, `lieferant_preise` → `&tab=rohstoff` (bzw. `fertigprodukt`→`zukauf`),
`lief_preisliste` → `&tab=rohstoff`. Menüeinträge entsprechend zusammengefasst.

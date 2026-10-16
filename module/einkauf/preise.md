# einkauf/preise.php – Einkauf → Preise (alle Einkaufspreise)

Eine Seite für **alle** Einkaufspreise, schlanker Tabellen-Stil + Klick-Popup. Route `?p=einkauf_preise`,
Menü „Einkauf → Preise". Rolle: production/einkauf/labor (+ Admin). Reiter (`?tab=`):

- **fremd** – Fremdfertigung (Kapsel/Fertigprodukt): `rezeptur_lief_angebot` (v3) + **alle beantworteten
  v4-Fertigprodukt-Angebote** (`lieferant_anfrage.art='fertigprodukt'` JOIN `lieferant_angebot` + Staffeln) –
  **auch ohne Rezeptur-Verknüpfung** (Freitext-Anfrage); dann dient `lieferant_anfrage.betreff`/`.nummer` als
  Bezeichnung, damit das abgegebene Angebot sicher erscheint. Der Reiter-Zähler zählt beide Quellen.
  **Fremdfertigung = `art='fertigprodukt'` ODER `rezeptur_id` gesetzt** (eine rezeptur-verknüpfte Anfrage ist
  immer Fremdfertigung, auch wenn die Art versehentlich 'sonstiges' war – Einmal-Migration `fix_la_art_fertig_v1`
  korrigiert solche Altfälle dauerhaft auf 'fertigprodukt').
  Spalten: Rezepturnr., **Anfrage (LA-Nr.)**, Rezeptur-Name, Form, Kapselgröße, Lieferant, Preis. Die LA-Nr.
  macht nachvollziehbar, aus welcher Preisanfrage ein Preis stammt. Klick auf die Rezeptur → Popup
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

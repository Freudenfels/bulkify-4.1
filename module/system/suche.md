# module/system/suche.php – Globale Suche (Admin)

Seite unter `?p=suche`. Ein Feld sucht über alle wichtigen Bereiche und zeigt die
Treffer gruppiert und anklickbar. Nur für Admins (`has_role('admin')`; unbekannte
Route ist ohnehin Admin-only).

## Durchsuchte Bereiche
- **Kunden** – Firma, Ansprechpartner, E-Mail, Kundennummer, Ort, Marke (kunde_marke)
- **Lieferanten** – Firma, Ansprechpartner, E-Mail, Nummer, Ort, Webseite
- **Rohstoffe** – nur `kategorie` rohstoff/leerkapsel (Name, Name en/lat, Synonym, Artikelnummer); steht vor den übrigen Artikeln, damit der Original-Rohstoff sofort zu finden ist (08.11.2026)
- **Artikel (Verpackung, Etiketten, Bulk, Fertigware)** – alle anderen Lagerartikel, gleiche Felder; Verpackung/Etiketten öffnen `?p=verpackung`, der Rest `?p=rohstoff`
- **Produkte** – Name, Kundenname, Nummer
- **Rezepturen** – Name, Nummer
- **Angebote** – Angebotsnummer, Kundenfirma
- **Aufträge** – Auftragsnummer, Kundenfirma

Je Bereich `LIKE '%q%'`, `LIMIT 30`; angezeigt werden max. 12, der Rest als
„und N weitere – Suche verfeinern". Ab 2 Zeichen.

## Einstieg
Ein Suchfeld liegt zusätzlich oben in der Seitenleiste (nur Admin, siehe
`core/layout.php` → `.bx-sidesuche`) und schickt an `?p=suche`.

## Aufträge/Angebote per Produktname (Stand 2026-10-23)
Die Auftrags- und Angebots-Suche matcht zusätzlich den **Produktnamen** (`auftrag.produkt_bezeichnung`-Snapshot bzw. `produkt.name`/`kundenname` über `produkt_id`), nicht mehr nur Nummer + Kundenfirma. So findet z. B. „Kreatin" auch die zugehörigen Aufträge/Angebote. Die Trefferzeile zeigt Kunde · Produkt · Status.

## Warenart-Labels (Teil 3 Block K)
Das Kategorie-Label kommt jetzt aus `kategorie_labels()` (`core/schema.php`) statt aus einer lokalen Liste – `fertig`→Bulkware, `verkaufsfertig`→Fertigprodukt, einheitlich mit dem Rest des Dashboards.

## Inventarnummer (08.11.2026)
7–8 Ziffern im Suchfeld finden zusätzlich Etikettenrollen/Gebinde über `charge_gebinde.inventar_nr` (Altdaten: erste 7 Ziffern). Treffer: Nummer · Artikel, darunter Charge, Menge, Status, Auftrag; Klick öffnet den Artikel.

**Gesperrt/inaktiv (13.11.2026, Fable-3 T4.3):** Lieferanten-Anfragen gesperrter Lieferanten nur mit Schalter „auch inaktive“.

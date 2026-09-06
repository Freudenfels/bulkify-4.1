# module/system/suche.php – Globale Suche (Admin)

Seite unter `?p=suche`. Ein Feld sucht über alle wichtigen Bereiche und zeigt die
Treffer gruppiert und anklickbar. Nur für Admins (`has_role('admin')`; unbekannte
Route ist ohnehin Admin-only).

## Durchsuchte Bereiche
- **Kunden** – Firma, Ansprechpartner, E-Mail, Kundennummer, Ort, Marke (kunde_marke)
- **Lieferanten** – Firma, Ansprechpartner, E-Mail, Nummer, Ort, Webseite
- **Rohstoffe / Artikel** – Name, Name en/lat, Synonym, Artikelnummer (alle Kategorien)
- **Produkte** – Name, Kundenname, Nummer
- **Rezepturen** – Name, Nummer
- **Angebote** – Angebotsnummer, Kundenfirma
- **Aufträge** – Auftragsnummer, Kundenfirma

Je Bereich `LIKE '%q%'`, `LIMIT 30`; angezeigt werden max. 12, der Rest als
„und N weitere – Suche verfeinern". Ab 2 Zeichen.

## Einstieg
Ein Suchfeld liegt zusätzlich oben in der Seitenleiste (nur Admin, siehe
`core/layout.php` → `.bx-sidesuche`) und schickt an `?p=suche`.

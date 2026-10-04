# core/rohstoff_public.php – Öffentliche Rohstoffdaten (Website/SEO)

Liefert die Daten für die **öffentliche Rohstoff-Datenbank** auf bulkify.pro. Nur freigegebene Rohstoffe
(`item.website_sichtbar=1`) und nur **sichere** Felder – nie Preise/Lieferanten/Bestand/Originaldokumente.

## Funktionen
- `rohstoff_web_slug($name, $id)` – URL-Slug aus dem Namen (Umlaute/Sonderzeichen raus); bei Kollision
  wird die id angehängt (Eindeutigkeit).
- `rohstoff_web_slug_sicherstellen($item_id)` – setzt `item.web_slug` einmalig (beim Freigeben).
- `rohstoff_public_liste($slug=null)` – Array der freigegebenen Rohstoffe (oder einer per Slug):
  slug, name, name_en, name_lat, cas, bot_quelle, kategorie, form, beschreibung, **kennwerte** (gefiltert
  über `item_kennwerte_relevant`), **wirkstoffe** (Name + gehalt_prozent).

## Endpoint
`public/rohstoffe_public.php` → öffentliches JSON (kein Login, 1 h cachebar, CORS `*`).
`GET /rohstoffe_public.php` bzw. `?slug=<slug>`. Vertrag: `WEBSEITEBULKIFY/bulkifyv2/ROHSTOFF-DATENBANK.md`.

## Freigabe
Im Rohstoff-Detail (Reiter Spezifikation): Haken „Für die öffentliche Rohstoff-Datenbank freigeben"
(`item.website_sichtbar`) + optionale neutrale „Öffentliche Beschreibung" (`item.web_beschreibung`,
claims-sicher, EU-VO 1924/2006). Es geht nichts automatisch online – Freigabe je Rohstoff.

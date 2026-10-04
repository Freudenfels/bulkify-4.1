# module/lager/rohstoff_website.php – Website-Freigabe (Massen)

Route `?p=rohstoff_website` (Rollen production/einkauf/labor, admin). Werkzeug, um Rohstoffe für die
**öffentliche Rohstoff-Datenbank** auf bulkify.pro freizugeben (`item.website_sichtbar`). Nichts geht
automatisch online – hier entscheidet das Team.

## Ansicht
Reiter **Bereit** (Name + mind. ein Kennwert/Wirkstoff, noch nicht online) / **Freigegeben** / **Nicht
freigegeben** / **Alle**, mit Suche. Tabelle: Nr., Name (Link zum Detail), Form, #Kennwerte, #Wirkstoffe,
Website-Status.

## Aktionen
- Checkboxen + „Ausgewählte freigeben" / „Ausgewählte entfernen".
- „Alle N ‚bereiten' freigeben" – gibt alle bereiten in einem Schritt frei (mit Bestätigung).
- Beim Freigeben wird der `web_slug` gesetzt (`rohstoff_web_slug_sicherstellen`).

## Zusammenhang
Freigegebene Rohstoffe liefert `public/rohstoffe_public.php` (nur sichere Felder, Kennwerte gefiltert) an
die Website. Siehe `core/rohstoff_public.php` und das Website-Briefing
`WEBSEITEBULKIFY/bulkifyv2/ROHSTOFF-DATENBANK.md`. Einzel-Freigabe + Beschreibung: im Rohstoff-Detail
(Reiter Spezifikation).

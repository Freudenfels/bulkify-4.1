# produktion/vorbereitung.php – Vor-Produktion / Freigabe (PreProduktionsauftrag)

Dashboard-Seite, auf der ein Kundenauftrag **final für die Produktion freigegeben** wird.
Route `?p=produktion_vorbereitung`, Menü „Produktion → Vor-Produktion". **Rolle: nur Admin**
(Eigen/Fremd + Freigabe sind Backend-Entscheidung, nicht Sache der Produktion).

## Ablauf (die Weiche)
`Angebot bestätigt → Auftrag → **Vor-Produktionsauftrag** (hier) → echter Produktionsauftrag → Produktionsmodul`

Neue Kundenaufträge entstehen im Produktionsauftrag-Status **`vorbereitung`** (alle fünf PA-Erzeuger in
`core/schema.php`: `auftrag_aus_angebot`, `auftrag_aus_positionen`, `auftrag_aus_zelle`, `kontingent_abruf`,
`produktionsauftrag_aus_auftrag`). Sie erscheinen hier als Karten mit **Checkliste**:
- Verpackung/Glas gewählt (wichtig) – sonst Link „Auftrag öffnen" zum Festlegen.
- Etikett freigegeben (wichtig, nur wenn das Produkt ein Etikett braucht).
- Material: Rohstoffe/Bulk angekommen, Gläser/Verpackung, Kartons, Etikett, Beipack vorrätig (aus der Stückliste).

## Freigabe (`aktion=freigeben`)
Pro Karte: **Eigen/Fremd** wählen, optional **Produktionsmenge in Einheiten** (höher als der Auftragsbedarf =
Überproduktion; der Überschuss wird als Bulk der Rezeptur verrechnet – `menge_produktion`). Button ruft
`produktionsauftrag_freigeben($pa_id,$art,$menge_produktion,$wer)`:
- erzeugt die Schritte passend zu Eigen/Fremd (setzt Status intern auf `offen`),
- setzt `produktionsart`, `art_festgelegt_am`, `freigegeben_am/_von`, ggf. `menge_produktion`,
- **harte Weiche:** der Admin kann **immer** freigeben – rote Checks sind nur Warnung.

Danach ist es ein echter, **startbarer** Produktionsauftrag. Im Produktionsmodul war er vorher sichtbar,
aber gesperrt (`produktion_schritt_erledigen` blockt Status `vorbereitung`).

## Verträge / Querbezüge
- `vorbereitung_liste()`, `pa_vorbereitung_checks()`, `produktionsauftrag_freigeben()` in `core/schema.php`.
- Produktions-Naht `produktion/core/erp.php`: `erp_produktionsauftraege` zeigt `vorbereitung` mit an (gesperrt).
- Einmaliger Backfill in `init_schema()` (`pa_vorbereitung_backfill`, Kill-Schalter `pa_vorbereitung_backfill_off`).

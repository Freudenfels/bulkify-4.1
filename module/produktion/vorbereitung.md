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

## Info-Block (Detailansicht)
Über der Checkliste steht ein **Info-Raster mit allen Auftrags-/Produktdaten** auf einen Blick: Kunde, Auftrag, Rezeptur-Nr., Darreichung, **Menge (Packungen)**, **Stück je Packung (VPE)**, **Gesamtstückzahl**, Kapselgröße (gesetzte Größe oder bei Kapsel/Softgel die **Automatik-Empfehlung** „… (automatisch)" aus `rezeptur_kapselgroesse()`), Verpackung/Glas, Herstellung (Eigen/Fremd) sowie die **systemseitig geplante Charge** (`charge_naechste_nr`) + **geplantes MHD** (`mhd_standard`, +18 Monate). So muss die Produktion nicht erst in den Auftrag wechseln.

Direkt darunter ein **Rezeptur-Block mit der Zusammensetzung** (Zutaten aus `rezeptur_zutat`: Bezeichnung + mg je Einheit + Summe) und einem Link „Rezeptur ansehen" (`?p=rezeptur_detail`). Damit kann die Produktion am PR **entscheiden, ob wir selbst herstellen oder zukaufen (Eigen/Fremd)**, ohne die Rezeptur erst woanders zu suchen.

## Alles hier anpassen (Edit-Oberfläche)
Je Karte wird direkt bearbeitet (jeweils eigenes POST):
- **Glas/Behälter** (`glas_setzen`): Auswahl aller Primär-Verpackungen; fehlt das Glas, ist die **Auto-Empfehlung**
  (`verpackung_empfehlung_fuer_pa` → kleinstes passendes aus `pack_kapazitaet` für Kapselgröße×Stück) vorausgewählt.
  Scope „Produkt-Standard" (Default, setzt zusätzlich produkt.verpackung_id) oder „nur dieser Auftrag" (nur auftrag.verpackung_id). Radios + „Glas speichern"-Button (dezent, btn-ghost) stehen in einer Zeile, Button rechts (`margin-left:auto`).
- **Form-spezifische Eigenschaft** (statt fest „Kapselgröße"): je Darreichungsform das passende Feld, über `form_attribut($form)`:
  - **Kapsel/Softgel → Kapselgröße** (`kapsel_setzen` → rezeptur.kapselgroesse_id). Ohne manuelle Wahl („– automatisch –") steht über dem Dropdown die **Automatik-Empfehlung** „Automatik: Größe … (passend zum Füllgewicht)" aus `rezeptur_kapselgroesse()` (passt keine → „bitte manuell wählen"); auch in der automatisch-Option.
  - **Tablette → Tablettenform** (`tablettenform_setzen` → rezeptur.tabletten_form): Rund mit/ohne Brechkante, Oval, Länglich, Sonstige (`tabletten_formen()`).
  - **Gummi/Stick/Pulver/Flüssig → Füll-/Stückmenge** (`gewicht_setzen` → rezeptur.einheit_fuellmenge): Gummi „Gewicht pro Stück (g)", Stick „… pro Stick (g)", Pulver „… pro Bag (g)", Flüssig „Füllmenge pro Flasche (ml)" (`form_gewicht_feld()`). Eingabe mit Komma (z. B. 2,5), geparst via `zahl_lesen`.
  Das Info-Raster oben zeigt entsprechend „Kapselgröße" / „Tablettenform" / „Gewicht pro … (g/ml)" statt immer „Kapselgröße". Dieselben Werte speisen `produktion_groesse_label()` (Kachel auf der Auftragsseite).
- **Etikett**: hochladen/ersetzen (`etikett_upload`) und Freigabe im Namen des Kunden (`etikett_freigeben`, Akteur 'team').
- **„Alle offenen Aufträge holen"** (`alle_vorbereitung` → `vorbereitung_alle_holen()`): setzt alle noch nicht
  gestarteten Kunden-PAs auf Status `vorbereitung`, damit man fehlende Gläser etc. sammeln nachziehen kann.

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

## Teilproduktions-Rechner (2026-10-06)
Panel „Teilproduktion – was ist jetzt machbar?" (unter der Bereitschaft). Helfer `produktion_teilmenge_machbar($pa_id)` (core/schema.php) liefert ZWEI Mengen in Packungen (schon Produziertes abgezogen):
- **vor_etikett** = produzieren/abfüllen bis VOR dem Etikettieren – begrenzt durch den knappsten Baustein OHNE Etikett (Kapseln/Bulk, Glas, Deckel, Karton, Beipack).
- **komplett** = komplett fertig inkl. Etikett – zusätzlich begrenzt durch Etikettenbestand UND Kundenfreigabe (ohne Freigabe = 0).

Je Baustein `machbar = floor(verfügbar / (benoetigt/menge))` aus `auftrag_bedarf_cached`. Beispiel: 1.400 Gläser + 500 Etiketten → vor_etikett 1.400, komplett 500 (500 komplett fertig, 900 bis vor Etikettieren, Rest wartet auf Material). Rechnet automatisch neu bei Bestandsänderung (neue Gläser/Etiketten). `fertig_moeglich` = komplett deckt den ganzen offenen Rest. Siehe [[bulkify-teilproduktion-rechner]].

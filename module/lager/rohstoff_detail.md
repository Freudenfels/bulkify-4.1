# lager/rohstoff_detail.php – Rohstoff / Item anlegen & bearbeiten

**Zweck:** Detailseite eines Warenlager-Artikels (Rohstoff usw.) mit leichtem Cockpit. Diese Items sind die Bausteine, aus denen später Rezepturen zusammengesetzt werden.

**Was passiert hier:**
- **Speichern (POST):** Pflichtfeld Name, schreibt alle Felder in `item` (neu = INSERT + Verlaufseintrag, sonst UPDATE). Haupt-Lieferant wird als ID gespeichert (oder leer).
- **Anzeige (GET):** lädt das Item, die Lieferantenliste (für die Auswahl) und den Verlauf (`verlauf_fuer('item', id)`).

**Die Reiter:**
- **Stammdaten** – Artikelnummer, Name (de/en/lat.), **CAS-Nummer** (z. B. Ascorbinsäure 50-81-7), Kategorie, **Form (Auswahl!)** – Pulver/Granulat/Flüssig/Öl/Paste/Kristallin/**Kapselhülle (leer)**, Basiseinheit, Sperr-Schalter, Notiz.
  - **Leerkapseln** = Rohstoff mit Form **Kapselhülle**. Dann erscheinen (per JS eingeblendet) eigene Felder: **Kapselgröße** (aus `kapselgroesse`), **Material** (HPMC/Gelatine/Pullulan – reuse `item.material`), **Farbe** (reuse `item.farbe`), **Leergewicht (mg)** (`item.leergewicht_mg`). Einheit/Bezug werden dabei auf „Stück" vorbelegt. Eine Leerkapsel ist operativ ein Rohstoff (Wareneingang/Bestand/FEFO), aber in der Liste eigene Sicht „Leerkapseln". Kein Wirkstoff/NRV nötig; Gelatine → Allergen. Anlegen direkt als Kapsel via `?p=rohstoff&id=neu&form=kapselhuelle`.
- **Wirkstoff & Qualität** – **Wirkstoffe** als Mehrfach-Liste: pro Zeile ein Wirkstoff (Auswahl aus der Nährstoff-Liste per Datalist **oder** neuen Namen eintippen → wird automatisch angelegt) + **Gehalt-Wert** + **Einheit**. „+" fügt weitere hinzu (z. B. D3 + K2 getrennt). Gespeichert in `item_wirkstoff` (`gehalt_wert` + `gehalt_einheit`; das alte `gehalt_prozent` bleibt für Rückwärtskompat. gefüllt, wenn Einheit `%`). Dazu Dichte (g/ml, Kapselfüllung), Standard-Overage/Verlust (%), Herkunft, Allergene. Diese Werte machen die Rezeptur später rechenbar (Deklaration „davon … mg = % NRV", Aggregation gleicher Nährstoffe über mehrere Rohstoffe).
  - **Gehalt-Einheiten (zukunftssicher):** `% (m/m)` (Standard, z. B. Magnesiumcitrat = 16 % Magnesium), `mg/g`, `µg/g` und **potenzbasiert** `I.E./g` / `I.E./kg` für Vitamine, die in Internationalen Einheiten geliefert werden (z. B. Vitamin D3 100.000 I.E./g). Die Umrechnung I.E. → Masse läuft über den am Nährstoff hinterlegten Faktor `naehrstoff.ie_mg` (mg je 1 I.E.). Alles wird von der zentralen Funktion `wirkstoff_mg_je_mg()` (core/schema.php) auf **mg Nährstoff je mg Rohstoff** normalisiert – die EINZIGE Rechenwahrheit für Rezeptur-Live-Rechnung, Produkt-Deklaration, PIB und Portal.
- **Spezifikation** – die kundenrelevante/unterscheidende Spec: **Identität** (Synonym/RM-Nr, EC-Nr, botanische Quelle, Herkunftsland), **charakteristische Kennwerte** (freie Parameter·Wert-Liste in `item_kennwert` – z. B. Fett 10–12 %, pH 7,5), **Grenzwerte (Reinheit/Mikrobiologie)** (Parameter·Grenzwert in `item_grenzwert` – Schwermetalle/Keime/Mykotoxine; aus CoA/Spec vorbelegt, jede neue Charge prüfbar), **Deklaration & Status**, **Handling**, **Spec-Dokument** (PDF-Upload/-Download). Zusätzlich **bulkify-Spezifikation – Kundenfreigabe**: eingebettete Vorschau des generierten Kundendokuments (`?p=spec_bulkify`, iframe) plus separater Knopf **„Für den Kunden freigeben"** / „Freigabe zurückziehen". Flags am Item: `spec_freigegeben` + `spec_freigabe_am`/`_von` (Handler `aktion=spec_freigabe`). Der Kunde sieht die Spezifikation im Portal **erst nach Freigabe** (Gate in `module/portal/kunde.php`, sowohl Download-Route als auch Link). Der Freigabe-Block liegt in der zweiten `data-panel="spec"`-Sektion **außerhalb** des Haupt-Formulars (eigene Forms).
- **Einkauf** – EK-Preis + Bezug, Haupt-Lieferant, **Lieferantenpreise (Staffel)**: Tabelle aller Lieferanten-Angebote je Menge (ab-Menge + Preis + **Incoterm** + **Versandart**), nach Preis sortiert, **günstigster markiert**; Preise hinzufügen/löschen. Incoterm (EXW/FOB/CIF/DAP/DDP) und Versandart (Luft/See/Bahn/…) machen Preise vergleichbar – ein EXW-See-Preis ist nicht dasselbe wie DDP-Luft. EK-Import setzt die Versandart automatisch aus AIR/SEA/TRAIN im Namen. Basis für den günstigsten Einkauf. (Die Preis-Formulare liegen technisch in einer eigenen `data-panel="ek"`-Sektion **außerhalb** des Haupt-Formulars, um verschachtelte Forms zu vermeiden.)
- **Lager** – Bestand (frei) + Quarantäne + **Reserviert** des Rohstoffs und seine **Chargen** (verfügbare Menge, MHD, Lieferant, Status). Button „Wareneingang buchen". Je Charge eine Spalte **CoA-Freigabe**: Status-Badge + Knopf **freigeben/zurückziehen** (Handler `aktion=coa_freigabe`; Flags `charge.coa_freigegeben` + `coa_freigabe_am`/`_von`).
  - **Reservierungen** (eigenes Panel im Reiter Lager): freien Bestand gezielt einem **Auftrag** zuteilen. Das Auftrags-Dropdown zeigt **nur Aufträge, die diesen Artikel brauchen** – der Bedarf entsteht **mit dem Auftrag aus der Rezeptur**, unabhängig von Eigen/Fremd oder Produktionsstatus. Zwei Fälle, je nachdem was der Artikel ist:
- **Bulk/Fertigware** (`item.rezeptur_id` gesetzt, Kategorie `fertig`): Kandidaten = offene Aufträge, deren `COALESCE(auftrag.rezeptur_id, produkt.rezeptur_id)` **gleich dieser Bulk-Rezeptur** ist; Benötigt = Einheiten = `auftrag.menge × Stück je Packung` (`COALESCE(auftrag.stueck, produkt.einheiten_pro_packung, 1)`).
- **Rohstoff** (kein `rezeptur_id`): Kandidaten = offene Aufträge, deren Rezeptur diesen Rohstoff als `rezeptur_zutat` enthält; Benötigt via `produktion_materialbedarf(pa_id)` (reine Rezeptur-Rechnung, auch Pulver nach Gewicht).

`$needJeAuftrag` = benötigte Menge je Auftrag, größter Bedarf zuerst, Option „· benötigt N Einheit". Beim Auswählen wird die Mengenangabe **vorbelegt** (JS, solange nicht manuell geändert). Braucht kein offener Auftrag diesen Artikel, Fallback = alle Produktaufträge. Die Liste hat eine Spalte **Benötigt** (Soll je Auftrag) neben **Reserviert**. DL-/DB-Aufträge (ohne Produkt) sind ausgeschlossen. Tabelle der aktiven Reservierungen (Auftrag/Kunde/Menge/seit/Herkunft) + Formular „Reservieren" (Auftrag-Dropdown `rscombo` aus laufenden Aufträgen + Menge). **Manuelle** Reservierungen (`pa_id` NULL) sind hier editierbar: Menge direkt in der Zeile ändern (`aktion=res_setzen`, eine Zeile je Auftrag – ein zweites Setzen ändert die Menge; 0 oder „aufheben" = `aktion=res_storno`). Reservierungen **aus der Produktion** (`pa_id` gesetzt) stehen read-only mit Badge „aus Produktion PR-…". Reservieren über den Bestand hinaus ist erlaubt (gegen erwartete Lieferung); die Karte „Reserviert" färbt dann warn. Wirkt über `item_reserviert_gesamt()`/`item_reservierungen_liste()`/`item_reservierung_setzen()`/`item_reservierung_storno()` in `core/schema.php`; reduziert die Netto-Verfügbarkeit für andere Aufträge (`item_verfuegbar_fuer()` → `item_reserviert_andere()`), `bedarf_bump()` rechnet Bedarf/Einkaufsliste neu.
- **Bestand korrigieren (nur Admin):** je Charge ein aufklappbares Mini-Formular (Menge + Einheit, vorbelegt mit aktueller Menge bzw. der Artikel-Einheit) → `aktion=charge_bestand_korr`. Setzt `charge.menge` + `menge_verfuegbar` + `einheit`, passt den Status an (0 → `leer`, aus `leer` zurück auf `frei`), ruft `bedarf_bump()` (Einkaufsliste/Bedarf rechnet neu) und protokolliert. Nützlich z. B. um eine fälschlich in „g" gebuchte Charge auf den echten kg-Wert zu bringen (sonst zählt `item_bestand` die Zahl als kg → Phantom-Bestand, der den Rohstoff-Bedarf blockiert). Neue Wareneingänge werden bereits beim Buchen in die Artikel-Einheit umgerechnet (Lager-App), diese Korrektur ist für Altlasten. Nur ein freigegebenes bulkify-CoA ist im Kundenportal abrufbar (Route `v=coa_pdf&cid=`, Gate `coa_freigegeben=1`); auf der Rohstoff-Detailseite des Kunden werden die freigegebenen CoAs je Charge verlinkt. Der interne Knopf „CoA ansehen" (`?p=coa_bulkify`) bleibt jederzeit möglich.
- **Verwendung** – in welchen Rezepturen der Rohstoff steckt (Gerüst; kommt mit dem Rezeptur-Modul).
- **Verlauf** – Chat (`bx_chat`), z. B. Preisänderungen.

**Warum Form als Auswahl:** damit die Rezeptur-Zutatenauswahl später nach Form filtern kann (flüssiges Rezept → flüssige/öllösliche Rohstoffe zuerst).

**Kennzahlen-Kacheln:** Status · Kategorie · EK-Preis · Verwendet in (folgt mit dem Rezeptur-Modul).

**Hinweis Preise:** Sub-Cent-Preise werden mit 4 Nachkommastellen angezeigt (wichtig für kleine Wirkstoffmengen).

**Reiter „Dokumente (CoA/Spec)"** – generische Dokumentenablage (`core/dokument_ui.php`, Tabelle `dokument`, objekt_typ=item): mehrere **CoA / Spezifikation / Laboranalyse / Sonstiges** hochladen, je Dokument optional mit **Lieferant** verknüpft (Nachweise sind mit dem Anbieter verknüpft). Liste mit Download (Route `dokument`) + Löschen. Dateien in `data/uploads`. Aktionen dok_upload/dok_del; Reiter via `?tab=dok`. (Ergänzt die einzelne Spec-PDF im Reiter Spezifikation.) Dasselbe Panel gibt es beim Produkt.

Die **Lieferanten-Staffelpreise** (Reiter Einkauf, `lieferant_preis`: je Lieferant menge_ab + Preis) decken „Staffelpreise von verschiedenen Lieferanten" bereits ab – günstigster wird markiert.

**VK-Aufschlag (%)** (Reiter EK-Preise): nur für den **Weiterverkauf des Rohstoffs an Kunden** (Rohstoffanfragen). Leer = globaler Aufschlag aus Einstellungen → Preise (`aufschlag_rohstoff`). Gefüllt = überschreibt global für genau diesen Rohstoff (`item.vk_aufschlag_prozent`). VK = günstigster gestaffelter Lieferanten-EK × (1 + Aufschlag); Berechnung siehe `intern/portal_anfrage_detail.php`.

**Verkauf-Sicht (Rollen):** Das Feld **VK-Aufschlag (%)** im Reiter Einkauf ist nur sichtbar, wenn `darf_verkauf()` (core/auth.php) wahr ist – Produktionsmitarbeiter sehen es NICHT. Beim Speichern durch eine Produktionsrolle bleibt der vorhandene VK-Aufschlag erhalten (wird nicht genullt).

## Unterlage auslesen (KI)
Im Reiter **Spezifikation** liest die KI eine hinterlegte Lieferantenunterlage (Spezifikation oder CoA, auch eingescannt) und schlägt die Felder vor – `core/spec_ki.php`, Aktionen `ki_lesen`, `ki_zeigen`, `ki_uebernehmen`, `ki_werte`.

**Nichts wird ungefragt gespeichert.** Die Tabelle stellt „bisher" und „Vorschlag" nebeneinander; abweichende Felder sind vorangehakt, gleiche Werte sind ausgegraut. Übernommen wird nur, was angehakt ist. Erkennt die KI Analysenwerte, lassen sie sich zusätzlich an eine Charge speichern (die bisherigen Werte dieser Charge werden dabei ersetzt).

Ein frisch hochgeladenes Dokument (Reiter **Dokumente**) wird gleich ausgelesen; der Vorschlag wartet dann im Reiter Spezifikation. Dasselbe passiert, wenn der **Lieferant** die Datei in seinem Portal hochlädt.

Daneben steht der Knopf **bulkify-Spezifikation ansehen** (`?p=spec_bulkify&id=`) – unser eigenes Papier aus genau diesen Feldern.

## Rohstoff aus einer Spezifikation anlegen
Auf der Anlegeseite (`?p=rohstoff&id=neu`) steht oben **Rohstoff aus einer Spezifikation anlegen**: Datei hochladen (PDF oder Bild, auch Scan), die KI liest sie aus und **füllt das Formular darunter**. Nichts wird dabei gespeichert – du prüfst die Felder und drückst wie sonst auf Speichern.

Ablauf im Code: `aktion=spec_neu` legt die Datei in `data/uploads` und merkt Vorschlag plus Dateiname in der Session; beim Rendern wird der Vorschlag über die Vorgabewerte gelegt (`$neuDefault`); beim Speichern entsteht der Rohstoff wie immer, danach wird die Datei als Dokument an ihn gehängt und der Vorschlag daran gemerkt. `aktion=spec_neu_weg` verwirft alles wieder und löscht die Datei.

So hat jeder Rohstoff vom ersten Tag an seine Unterlage am Datensatz – und über **bulkify-Spezifikation ansehen** sofort ein eigenes Papier.

## Warum manche Abschnitte außerhalb des Formulars stehen
Die Stammdaten liegen in einem großen `<form>`. Abschnitte mit **eigenen** Formularen dürfen nicht darin stehen – verschachtelte `<form>`-Tags verwirft jeder Browser, und die Knöpfe würden dann das Stammdaten-Formular abschicken (bei Pflichtfeldern passiert schlicht nichts). Deshalb stehen außerhalb:

- **Rohstoff aus einer Spezifikation anlegen** – vor dem Formular, damit es beim Anlegen sofort sichtbar ist.
- **Unterlage auslesen (KI)** – dahinter, als eigener `<section data-panel="spec">`.
- **Lager / Analysenwerte je Charge** – dahinter, als eigener `<section data-panel="lager">`.

Die Reiter-Logik blendet jedes `[data-panel]` im Dokument, nicht nur die innerhalb des Formulars – die Abschnitte erscheinen also weiterhin im richtigen Reiter.

## Fremdlager (Kundenware)
Panel "Fremdlager" auf jeder Artikel-Seite: Ware, die einem KUNDEN gehört (Fulfillment), liegt physisch bei uns,
zählt aber NICHT zu unserem Bestand. Umgesetzt über `charge.fremd_kunde_id` (NULL = Warenlager/uns, gesetzt = Kunde).
`item_bestand()` schließt Fremd-Chargen aus → sie gehen NICHT in Berechnungen/Angebote/Produktion/Reservierung/Versand ein.
Button "Ins Fremdlager umbuchen" (`fremdlager_umbuchen()`): reduziert unsere freien Chargen FEFO und legt eine
Fremd-Charge (dem Kunden gehörend) an. Anzeige je Kunde via `item_fremdbestand_je_kunde()`.

## Spec/CoA gehören in den Reiter „Dokumente"
Das „Spec-PDF" aus dem Reiter *Spezifikation* wird beim Speichern **zusätzlich als Dokument** (typ `spec`,
intern) angelegt, sodass Spec/CoA im Reiter **Dokumente** stehen (dort ist ihr Platz). Diese
Lieferanten-Originale sind **immer intern** – der Kunde bekommt nur das generierte **bulkify-Specsheet**
(`?p=spec_bulkify&id=`) bzw. bulkify-CoA. Siehe `core/dokument_ui.md`.

## bulkify-Dokumente im Dokumente-Reiter
Das **bulkify-CoA** wird je **Charge** aus den Analysenwerten erzeugt (`?p=coa_bulkify&id=<charge>`),
nicht als Datei gespeichert. Es erscheint nicht nur im Reiter „Chargen" (Spalte „⇩ CoA"), sondern **auch
im Reiter „Dokumente"**: Block „Unser CoA (bulkify-Layout)" listet je Charge **mit Analysenwerten**
(`charge_analyse` vorhanden) Ansehen + Download sowie den Kundenfreigabe-Status. Hat eine Charge noch keine
Analysenwerte (z. B. CoA war ein Scan), erst im Reiter „Chargen" → „Analysenwerte je Charge" eintragen,
dann erscheint das bulkify-CoA. Original des Lieferanten bleibt intern.

**Update:** Der Block heißt „Unsere Dokumente (bulkify-Layout)" und zeigt IMMER die **Spezifikation**
(`?p=spec_bulkify&id=<item>`, allgemein, kundentauglich) – plus das **CoA je Charge** mit Analysewerten
(`coa_bulkify`). Ohne Analyse-Charge erscheint ein Hinweis statt leerer Liste. So ist auch bei Rohstoffen
ohne CoA-Charge mindestens die bulkify-Spezifikation da.

## Spec/CoA-Upload ist KI-gestützt – aber Stammdaten brauchen „Übernehmen"
Beim Hochladen eines Dokuments (Reiter Dokumente, `dok_upload`) wird eine Datei mit Typ `spec`/`coa`/`analyse`
sofort per KI ausgelesen (`spec_ki_nach_upload`), sofern die KI aktiv ist (`ki_bereit()`). Dabei werden
**Kennwerte, Wirkstoffe und Grenzwerte additiv ergänzt** und bei einer CoA eine Vorab-Charge angelegt
(`spec_ki_anwenden`). Die **Stammdaten** (Name, CAS, Herkunft, Allergene, vegan/GVO …) werden NICHT
automatisch geändert – sie landen als **Vorschlag** und müssen im Reiter Spezifikation per **„Übernehmen"**
(`ki_uebernehmen`) bestätigt werden. Nach dem Upload leitet die Seite bei vorhandenem Vorschlag direkt in den
Reiter `spec` (`&kiauto=1&kidok=<id>`) und zeigt den Vorschlag ohne Extra-Klick (GET-Variante von `ki_zeigen`).
Konnte die KI nicht lesen (nicht aktiv oder Scan ohne Textebene) → Hinweis `&kioff=1`. Darum wirkt ein Upload
„ohne Änderung", wenn Kennwerte/Wirkstoffe schon vorhanden waren und der Stammdaten-Vorschlag nicht übernommen
wurde.

## Feld „Art“ (Stoffklasse)
Select `art` (Optionen `rohstoff_art_optionen()`), im Stamm-Reiter vor „Form“; in der Feld-Whitelist gespeichert. Dient dem Filter in Liste + Kundenportal.

## KI-Info-Karte (Stand 2026-10-08)
Karte „KI-Info" (nur Rohstoffe, nicht beim Anlegen) unter dem Novel-Food-Status: zeigt den gespeicherten Text + Erzeug-Datum. Buttons: „KI-Info erzeugen"/„neu erzeugen" (POST aktion=ki_info → item_ki_info_erzeugen) und „löschen" (aktion=ki_info_del → ki_info/ki_info_am = NULL). Flash kiok/kidel/kierr. Hinweis: wird dem Kunden im Portal gezeigt → vor Freigabe kurz prüfen (keine Health-Claims).

## buxtrade-Nr. (Stand 2026-10-08)
Read-only-Feld „buxtrade-Nr." (nur bei bestehenden Rohstoffen) neben der Artikelnummer = Artikelnummer + „BX" (`rohstoff_bx_nummer()`). Handels-Kennung für buxtrade; die interne R-Nummer bleibt unverändert.

## Beschaffenheit + Extraktverhältnis (DEV) (Stand 2026-10-08)
Zwei neue Stammdatenfelder am Rohstoff: **Beschaffenheit** (`item.beschaffenheit`: Extrakt / reines Pulver / Isolat / Konzentrat / Flüssigextrakt / Öl / Sonstiges) und **Extraktverhältnis (DEV)** (`item.dev`, z. B. 1:10). Getrennt von der physischen `form` und der `spezifikation` (Standardisierung). Daraus baut `rohstoff_anzeige_name($item)` (core/schema.php) den **Anzeigenamen**, z. B. „Ashwagandha Extrakt 10:1" – doppeltes „Extrakt" bei Alt-Namen wird vermieden. Der Anzeigename wird im Detail als read-only Feld gezeigt und ist genutzt in: Angebots-Rohstoff-Picker + gespeicherte Rohstoff-Position (`angebot_rohstoff_zeile()`), Kundenportal-Rohstoffkatalog (Liste + Detail), öffentliche Rohstoff-DB (`core/rohstoff_public.php`) und Spezifikations-PDF (`core/pdf_spec.php`, inkl. Zeile „Beschaffenheit"). Helfer: `rohstoff_beschaffenheit_optionen()/_label()`, `rohstoff_beschaffenheit_namenswort()`, `rohstoff_anzeige_name()`.

## CoA/Spec-KI füllt Beschaffenheit + DEV (Stand 2026-10-09)
Die Spezifikations-/CoA-Auslesung (`spec_ki_felder()` in `core/spec_ki.php`) erkennt jetzt auch **beschaffenheit** (extrakt/pulver_rein/isolat/konzentrat/fluessigextrakt/oel) und **dev** (Extraktverhältnis, z. B. 10:1). Erscheinen im KI-Vorschlag (Reiter Spezifikation, „Auslesen") zum Anhaken/Übernehmen. `spec_ki_beschaffenheit_norm()` mappt KI-Freitext („Extrakt"/„Trockenextrakt" …) auf den gültigen Enum-Wert. Hinweis: Vor diesem Stand gelesene Dokumente müssen einmal neu „Auslesen", damit 10:1 & Co. erkannt werden.

## Einheitenbewusster Bestand + Verlauf/Verwendung (A1/A2, 2026-10-28)
**Bestand je Einheit:** Die Kacheln „Bestand (frei)" und „Quarantäne" vermischen Stück und Kilo nicht mehr. Bei gemischten Einheiten (`charge_bestand_je_einheit($id, ['frei'|'quarantaene'])`) steht `12 kg + 1.080.000 Stk` statt einer falschen Summe. `item_bestand()` (eine Zahl) bleibt für die interne Reservierungs-/Bedarfsrechnung; die Anzeige nutzt die Einheiten-Aufschlüsselung.

**Fremdlager-Einheit aus der Charge:** Die Kachel „Fremdlager (Kunden)" und die Fremdlager-Tabelle nehmen die Einheit jetzt aus den Fremd-Chargen, nicht mehr aus `item.einheit`. Ein als Fremdprodukt gebuchtes Fertigprodukt (z. B. in Stk abgefüllt) wird so korrekt als `384 Stk` angezeigt, auch wenn der Artikel intern noch `einheit='kg'` trägt. Helfer: `item_fremdbestand_je_einheit($id)` (Summe je Einheit) + `item_fremdbestand_je_kunde($id)` (jetzt mit Spalte `einheit`).

**Verlauf** (Reiter): echte Bewegungen aus `item_bewegungen($id)` – Wareneingänge (+, mit Charge/Lieferant/Bestellung) und Produktionsverbrauch (−, mit PA/Auftrag) als datumsortierte Tabelle. Vorher war der Reiter leer (Aktivitäts-Log ohne Einträge).

**Verwendung** (Reiter): aus `item_verwendung($id)` – in welchen Produktionsaufträgen/Aufträgen der Artikel verbraucht wurde (Quelle `produktion_verbrauch`), verlinkt auf PA und Auftrag. Vorher nur Platzhalter.

## Warenart „Fremdprodukt" (Kundenware, Lager 2)
In den Stammdaten gibt es den Schalter **Fremdprodukt** + **Besitzer-Kunde**. Gedacht für fertige Produkte, die von außerhalb kommen und dem Kunden gehören – bulkify lagert & versendet nur. Beim Speichern:
- Kategorie wird auf **verkaufsfertig** (Fertigprodukt) gesetzt; `item.fremdprodukt=1`, `item.fremd_besitzer_id=<Kunde>`.
- Der vorhandene eigene Bestand des Artikels wird dem Kunden ins **Fremdlager (Lager 2)** zugeordnet (`fremdprodukt_bestand_zuordnen()`: `charge.fremd_kunde_id` + `standort='lager2'`). Damit zählt er nicht mehr zu unserem Bestand und nicht für Produktion/Angebote/Einkauf (die filtern `fremd_kunde_id IS NULL`).
Kopf/Übersicht zeigen die Warenart über `item_warenart_label()` als „Fremdprodukt". Besitzer-Pflichtfeld wird beim Speichern geprüft. Der physische Standort kann abweichen – verbucht wird es als Lager 2 des Kunden.

Offen/Folgeschritt: der Wareneingang (`lager/`) soll Fremdprodukte künftig automatisch auf das Fremdlager des Besitzers buchen (heute über die L1/L2-Zielwahl bzw. „Ins Fremdlager umbuchen").

## Admin-Bearbeitung im Warenlager (2026-10-30, Teil 2)
Auf der Artikel-Detailseite (`?p=rohstoff&id=`) kann der **Admin** (nur `has_role('admin')`) alles korrigieren. Vorhandenes wurde erweitert, nichts doppelt gebaut:
- **Stammdaten** (bestand schon): Name, Artikelnummer, **Kategorie**, **Form**, Einheit (s. u.) und alle weiteren Felder – Kategorie wechselt nur den Reiter, Chargen/Verlauf bleiben.
- **Bestand je Charge korrigieren** (`charge_bestand_korr`, erweitert): jetzt **Pflichtfeld Grund** (z. B. Zählfehler, Bruch, Falschbuchung). Im Verlauf des Artikels landet „alte → neue Menge + Grund" (`log_aktivitaet`). Die Einheit wird hier NICHT mehr geändert.
- **Charge-Daten korrigieren** (`charge_daten_korr`, neu): Chargennummer, MHD, Lagerort (`standort`: Lager 1 / Fremdlager / Produktion).
- **Einheit ändern** (`einheit_aendern`, neu): eigenes Panel, gilt für **Artikel UND alle Chargen** (nie vermischen). Zwei Modi: „Nur Bezeichnung war falsch" (Mengen bleiben) oder „Umrechnen" (Menge × Faktor; Chargen-Mengen + Reservierungen werden umgerechnet). Das Stammdaten-Einheit-Feld ist bei bestehenden Artikeln deaktiviert und verweist hierher.
- **Artikel zusammenführen** (`artikel_merge`, neu): losen Doppel-Artikel in den richtigen Artikel überführen – Chargen (`charge.item_id`) und Reservierungen (`reservierung.item_id`) wandern aufs Ziel, der Doppel-Artikel wird **deaktiviert** (`gesperrt=1` + neue Spalte `item.zusammengefuehrt_in`), nicht gelöscht. Blockiert bei unterschiedlicher Einheit (erst angleichen). Historie/alte Dokumente bleiben.

Rechte: alle vier neuen/erweiterten Aktionen sind admin-only; die Lager-App/Workstation (`lager/`) bekommt davon nichts (dort nur Einbuchen/Entnehmen/Pick-to-Light). Nichts wird hart gelöscht.

## Schwund-Übersicht (2026-10-30, Teil 4 – Grundlage)
Neuer read-only-Panel im Reiter Lager: **Schwund-Übersicht** (`item_schwund_liste()` / `item_schwund_summe()`), Tabelle Datum · Charge · Auftrag · rechnerisch · gewogen · Differenz (+ = mehr gewogen, warnfarben; − = Schwund) · % · Mitarbeiter, plus Summe. Datenquelle: neue Tabelle `schwund`. Für Inventur/Steuer; der Lagerbestand bleibt immer der gewogene Wert.

Geschrieben wird `schwund` über `schwund_erfassen()` (core/schema.php): protokolliert die Differenz und benachrichtigt – **blind** – Nico nach Plausibilität (gewogen > rechnerisch → immer Hinweis; Schwund > `schwund_grenze_kg` → Hinweis; darunter stiller Eintrag; immer gebucht). Hinweis läuft über die zentrale Admin-Benachrichtigung (Art „wiegen", wichtig) + `mail_team()`. **Noch nicht verdrahtet** in den Werk-Rückgabe-Ablauf (Sub-App) – siehe docs/bericht-2026-10-30.md.

Außerdem neu (Grundlage): Tabelle `charge_gebinde` + `charge_gebinde_liste()` (Gebinde je Charge; Alt-Chargen ohne Gebinde = ein virtuelles Gebinde mit aktueller Menge).

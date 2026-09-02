# lager/rohstoff_detail.php – Rohstoff / Item anlegen & bearbeiten

**Zweck:** Detailseite eines Warenlager-Artikels (Rohstoff usw.) mit leichtem Cockpit. Diese Items sind die Bausteine, aus denen später Rezepturen zusammengesetzt werden.

**Was passiert hier:**
- **Speichern (POST):** Pflichtfeld Name, schreibt alle Felder in `item` (neu = INSERT + Verlaufseintrag, sonst UPDATE). Haupt-Lieferant wird als ID gespeichert (oder leer).
- **Anzeige (GET):** lädt das Item, die Lieferantenliste (für die Auswahl) und den Verlauf (`verlauf_fuer('item', id)`).

**Die Reiter:**
- **Stammdaten** – Artikelnummer, Name (de/en/lat.), **CAS-Nummer** (z. B. Ascorbinsäure 50-81-7), Kategorie, **Form (Auswahl!)** – Pulver/Granulat/Flüssig/Öl/Paste/Kristallin/**Kapselhülle (leer)**, Basiseinheit, Sperr-Schalter, Notiz.
  - **Leerkapseln** = Rohstoff mit Form **Kapselhülle**. Dann erscheinen (per JS eingeblendet) eigene Felder: **Kapselgröße** (aus `kapselgroesse`), **Material** (HPMC/Gelatine/Pullulan – reuse `item.material`), **Farbe** (reuse `item.farbe`), **Leergewicht (mg)** (`item.leergewicht_mg`). Einheit/Bezug werden dabei auf „Stück" vorbelegt. Eine Leerkapsel ist operativ ein Rohstoff (Wareneingang/Bestand/FEFO), aber in der Liste eigene Sicht „Leerkapseln". Kein Wirkstoff/NRV nötig; Gelatine → Allergen. Anlegen direkt als Kapsel via `?p=rohstoff&id=neu&form=kapselhuelle`.
- **Wirkstoff & Qualität** – **Wirkstoffe** als Mehrfach-Liste: pro Zeile ein Wirkstoff (Auswahl aus der Nährstoff-Liste per Datalist **oder** neuen Namen eintippen → wird automatisch angelegt) + **Gehalt (%)**. „+" fügt weitere hinzu (z. B. D3 + K2 getrennt). Gespeichert in `item_wirkstoff` (verweist auf `naehrstoff`). Dazu Dichte (g/ml, Kapselfüllung), Standard-Overage/Verlust (%), Herkunft, Allergene. Diese Werte machen die Rezeptur später rechenbar (Deklaration „davon … mg = % NRV", Aggregation gleicher Nährstoffe über mehrere Rohstoffe).
- **Spezifikation** – die kundenrelevante/unterscheidende Spec (nicht die Reinheits-Grenzwerte, die bleiben im PDF): **Identität** (Synonym/RM-Nr, EC-Nr, botanische Quelle, Herkunftsland), **charakteristische Kennwerte** (freie Parameter·Wert-Liste in `item_kennwert` – z. B. Fett 10–12 %, pH 7,5), **Deklaration & Status** (vegan/GVO/bestrahlt/TSE-BSE als ja/nein/unbekannt, Zertifikate, Zusätze/E-Nummern), **Handling** (Haltbarkeit, Lagerbedingungen), **Spec-Dokument** (Spez-Nr/Version/gültig ab + **PDF-Upload/-Download**). PDF liegt in `data/uploads` (außerhalb public), Auslieferung über die geschützte Route `spec_pdf`. Vorbereitet für späteren KI-Import (PDF → Felder).
- **Einkauf** – EK-Preis + Bezug, Haupt-Lieferant, **Lieferantenpreise (Staffel)**: Tabelle aller Lieferanten-Angebote je Menge (ab-Menge + Preis), nach Preis sortiert, **günstigster markiert**; Preise hinzufügen/löschen. Basis für den günstigsten Einkauf. (Die Preis-Formulare liegen technisch in einer eigenen `data-panel="ek"`-Sektion **außerhalb** des Haupt-Formulars, um verschachtelte Forms zu vermeiden.)
- **Lager** – Bestand (frei) + Quarantäne des Rohstoffs und seine **Chargen** (verfügbare Menge, MHD, Lieferant, Status). Button „Wareneingang buchen".
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

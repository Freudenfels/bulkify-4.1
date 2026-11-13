# produktion/liste.php – Produktions-Liste

**Zweck:** Übersicht aller Produktionsaufträge (PR-). Entstehen normalerweise **automatisch** mit dem Auftrag; zusätzlich lassen sich jetzt **Produktionsaufträge ohne Kundenbezug** von Hand anlegen (Lager-/Vorratsproduktion).

## Neuer Produktionsauftrag (Lagerproduktion)
Button oben rechts **„+ Neuer Produktionsauftrag"** (`?p=produktion&neu=1`) blendet ein Formular mit **zwei Modi** ein (Umschalter oben):

**a) Fertiges Produkt (mit Verpackung).** **Produkt** (tippbares Feld mit Live-Filter; verstecktes `produkt_id`), **Menge (Packungen)**, **Produktionsart** (Eigen/Fremd, Standard Eigen), **Priorität**. Die Stückzahl je Packung steckt bereits im Produkt (`einheiten_pro_packung`) und steht **direkt im Auswahl-Label** („Name · Nummer · 60 Kapseln"). Unter dem Mengenfeld zeigt eine Live-Zeile „X je Packung · Gesamt: Y" die tatsächlich produzierte Menge und warnt, wenn am Produkt keine Einheiten je Packung gepflegt sind. Legt über `produktionsauftrag_lager_erstellen()` an.

**b) Nur Kapseln (Bulk, ohne Verpackung).** **Rezeptur** (tippbar) + **Stückzahl (Kapseln)**. Legt über `produktionsauftrag_bulk_erstellen()` einen PR **ohne Produkt** an (`produkt_id` NULL, `rezeptur_id` gesetzt, `menge` = Stück). Schritte **ohne Verpacken/Etikettieren** (`Rohstoffe bereitstellen · Mischen · Verkapselung · Qualitätsprüfung · Einlagern (Bulk)`), Materialbedarf **nur Rohstoffe + Leerkapseln** (keine Verpackung), Fertigware geht als **Bulk-Charge** auf ein `fertig`-Lageritem der Rezeptur (`rezeptur_bulkitem`) – kann später zu einem Produkt verpackt werden. In der Liste erscheint der Rezepturname mit Zusatz „· Bulk". `aktion=neu` legt über `produktionsauftrag_lager_erstellen()` (core/schema.php) einen PR **ohne Kunde/Auftrag** an (`kunde_id`/`auftrag_id` = NULL), erzeugt die passenden Stationen (`produktionsschritte_fuer` je Darreichungsform + Weg) und leitet zum PR-Detail. Der Materialbedarf skaliert wie bei Kundenaufträgen über die Einheiten je Packung des Produkts; die Kunde-Spalte zeigt „–". Die fertige Ware geht als eigener Lagerbestand ein.

**Was passiert hier:**
- Liest alle Produktionsaufträge inkl. Kunde, Produkt (Joins) und – per Unterabfrage – **Fortschritt** (erledigte / gesamte Stationen) und **nächste offene Station**.
- **Suche** nach Nummer, Kunde, Produkt. **Sortierung** Standard = neueste zuerst.
- Tabelle (bewusst schmal gehalten, läuft auch auf kleinen Bildschirmen): **Prio · Bereit · Nummer · Kunde · Produkt · Kapsel/Tablette · Art · Menge · Fortschritt**. **Prio** ist nur ein farbiger Punkt (rot=Hoch, blau=Normal, grau=Niedrig; Klartext im Tooltip), weiter sortierbar. Die früheren Spalten **„Nächste Station"** und **„Status"** sind entfernt (redundant zu „Bereit"/Fortschritt bzw. zum Reiter); der Reiter sagt bereits offen/läuft/abgeschlossen.
- Klick öffnet den Produktionsauftrag (`?p=produktionsauftrag&id=...`).

## Sammel-Umstellung Eigen-/Fremdproduktion
Checkbox-Spalte + Spalte „Art" (Eigen/Fremd). Unten „alle markieren" und zwei Buttons
„Markierte auf Eigenproduktion / auf Fremdproduktion (Zukauf)" (`aktion=art_bulk`).
Ruft je Auftrag `produktionsauftrag_art_setzen()` (setzt produktionsart + regeneriert die
Schritte: eigen=voller Weg, fremd=verkürzter Zukauf-Weg). Bereits begonnene Aufträge
(ein Schritt erledigt) werden übersprungen und gemeldet. Nötig, weil importierte
Produktionsaufträge per Default auf „fremd" stehen.

## Spalte „Kapsel/Tablette"
Zeigt je Auftrag direkt die Größe (`produktion_groesse_label()` in `core/schema.php`): bei
Kapsel/Softgel die gepflegte Kapselgröße, sonst die **kleinste passende** aus dem Füllgewicht
der Rezeptur berechnet (Zusatz „(berechnet)"); bei Tablette das Füllgewicht in mg. Bei
Pulver/Stick/Flüssig steht „–".

## Umbau 2026-10-08: echter Stand + Status getrennt
Die Seite war unübersichtlich – „Produktionsbereit" enthielt auch schon LAUFENDE Aufträge. Jetzt: KPI-Übersicht oben (anklickbar: In Vorbereitung, In Produktion, Produktionsbereit, Wartet auf Material, Abgeschlossen) + **4 Reiter**: **In Produktion** (laufend, mind. 1 Schritt erledigt → Zeile führt in den Produktions-Run), **Produktionsbereit** (Material da, noch NICHT begonnen), **Wartet auf Material** (inkl. Eigen/Fremd offen), **Abgeschlossen**. Neue Spalte **„Aktuelle Station"** (naechste_station: begonnen = fett die laufende Station, sonst „als Nächstes: …"). Default-Reiter = In Produktion. Eigen/Fremd-Sammelumstellung + Auswahl-Checkbox nur in bereit/wartet.

## Block D – Produktionsübersicht & UI (2026-10-28)
- **D1 produzierte Menge:** Neue Spalte **„Produziert"** je Zeile zeigt `gebucht / gesamt` (Packungen) – gebucht = Summe der Fertigware-Chargen am PA (`SUM(charge.menge) WHERE pa_id`), in EINER Sammelabfrage (`$prodMap`, kein N+1). Grün, sobald die Zielmenge erreicht ist.
- **D2 ein Klick:** Klick auf eine Zeile führt **immer** auf die Produktionsauftrag-Übersicht (`?p=produktionsauftrag`) mit schnellem Überblick (Status, produzierte Menge, Charge, fehlendes Material, Fortschritt). Die geführte Produktion (`?p=produktion_run`) ist von dort per Button „Geführt produzieren" erreichbar – nicht mehr die erste Ansicht.
- **D3 UI:** Status-Badges lesbarer (`public/assets/app.css`): `font-size` fs-sm statt fs-xs, `font-weight:500`, mehr Padding, kräftigere Textfarben für besseren Kontrast. Die Status-Kacheln oben sind ein responsives `bx-cards`-Grid (gleich groß). God-Mode bleibt.

## Material komplett (Paket Mails)
Beim Öffnen läuft `material_komplett_pruefen()` gedrosselt (höchstens alle 5 Min., nach der Antwort).

## Storno und „Material bestellt“ (03.11.2026)
- Stornierte Produktionsaufträge und Produktionsaufträge eines stornierten Auftrags zählen hier nicht mehr (`pa_aktiv_sql()`, 03.11.2026). Anlass: Leberkomplex 10.000, ein in ein Kontingent umgewandelter Auftrag, dessen Produktionsauftrag weiterlief.
- Neuer Reiter und neue Kachel **„Material bestellt“**: Produktionsaufträge, deren fehlendes Material komplett bestellt ist, mit Badge und Termin („bis TT.MM.JJJJ“). „Wartet auf Material“ zeigt nur noch, was wirklich fehlt und nicht bestellt ist. Ist nur ein Teil bestellt, bleibt der Auftrag unter „Wartet auf Material“.

Kachel und Hinweis „Wartet auf Freigabe“ führen seit 03.11.2026 zur Produktionsfreigabe (`?p=produktion_vorbereitung`).

Vorladen `fw:` je Auftrag enthält auch `quar` (Bulk-Chargen in Quarantäne, 04.11.2026).

Kachel/Hinweis „Beschaffungsweg offen“ statt „Wartet auf Freigabe“ (04.11.2026).

## 10.11.2026 (Teil 6)
Die Sammel-Umstellung „auf Eigenproduktion / auf Fremdproduktion (Zukauf)“ für markierte Aufträge entfällt (Aktion `art_bulk`, Auswahlspalte). Der Weg wird je Auftrag im Pop-up „Weg festlegen“ bzw. im Reiter Beschaffungsweg festgelegt.

## Ein Weg (11.11.2026, plan-produktion-gefuehrt.md)
Die Liste hat nur noch drei Reiter:
- **Plan:** alles, was noch nicht begonnen hat (startklar, wartet auf Material, Material bestellt).
- **Läuft**
- **Fertig**

Die alten Reiter `bereit`, `wartet` und `bestellt` zeigen auf Plan. Ein Klick öffnet immer die PA-Seite.

Die alten Seiten leiten weiter (`public/index.php`), nichts ist gelöscht. Ihre Menüpunkte sind entfernt.

| Alte Seite | geht auf |
|---|---|
| `produktion_neu` | `produktion` |
| `produktion_planung` | Reiter Plan |
| `kalender` | Reiter Plan |
| `produktion_run` | PA-Seite bzw. Reiter Läuft |
| `easy_production` | PA-Seite „Teilmenge nachtragen“ bzw. Reiter Läuft |

**Plan (Teil 2, 11.11.2026):** Der Reiter Plan zeigt `_plan.php`: Drag-Liste mit Datum, Linie und Mitarbeiter, dazu „machbar je Schritt“ mit Grund (`core/produktion_plan.php`).

**Noch offen (13.11.2026, Werk-App Teil 2):** Reiter „Läuft“: unter dem Produkt „Noch offen: Schritte · Menge“ (gleiche Zeile wie Werk-App und Plan).

**Produktion per KI melden (13.11.2026, Werk-App Teil 4):** Feld „Produktion melden (KI)“ oben auf der Seite (`_ki_melden.php`).

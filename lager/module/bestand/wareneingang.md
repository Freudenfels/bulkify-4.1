# bestand/wareneingang.php – Vollwertiger Wareneingang (Route `we`)

Ein Bereich für alles, was reinkommt. Ablauf:
1. **Ziel**: Lager 1 (eigener Bestand) oder Lager 2 (Kundenware → Kunde). Lieferant optional.
2. **Lieferschein scannen** (optional): Webcam-Foto(s) oder Datei/PDF → `POST aktion=scan` →
   `lg_lieferschein_lesen()` ([../../core/ki.md](../../core/ki.md)) → KI liest Lieferant + Positionen.
   Positionen werden per `erp_position_zuordnen()` einem bestehenden Artikel zugeordnet (exakter
   Name = sichere Zuordnung, sonst Vorschlag + Warenart-Vermutung). Antwort als JSON, Tabelle füllt sich.
3. **Positionen prüfen**: je Zeile Artikel (bestehend/neu), Warenart, Menge, Einheit, Charge, MHD,
   **Blinker (Pflicht – außer Etikett)**, Pakete, **Freigegeben-Haken**. Pflichtfelder je Warenart aus
   `erp_warenart_regeln()`: Rohstoff/Fertig/Kapsel → MHD+Charge Pflicht; Verpackung/Verbrauch → frei.

   **Etikett (Rolle):** Warenart „Etikett (Rolle)" (Item = `kategorie='verpackung'` + `verpackung_rolle='etikett'`,
   erkannt per `erp_item_warenart()`). Hier wird **kein Blinker** verlangt. Eingabe: nur **Gesamtmenge (Stk)** +
   **Anzahl Rollen**. Begründung (Nico): Beim Wareneingang sieht man nicht, wie viele Etiketten auf welcher Rolle
   sind – deshalb **keine Menge je Rolle und keine Summenprüfung** mehr. Das System **verteilt die Gesamtmenge
   gleichmäßig** auf die Rollen (Rest auf die letzte Rolle; z. B. 1.100 auf 2 = 550+550). Je Rolle lässt sich
   **optional** ein Maniso-Inventaraufkleber (8 Ziffern) scannen (Enter springt weiter); **leer = Nummer wird
   automatisch vergeben** (reserviertes Band 90000000–99999999, kollidiert nicht mit physischen Aufklebern).
   Blinker/Charge/MHD/Pakete sind ausgeblendet. POST-Felder `p_rollen_zahl[<key>]` (Anzahl) + optional
   `p_rolle_nr[<key>][]` (gescannte Nummern), zugeordnet über `p_key[]`. Beim Buchen: eine Charge (Verpackung,
   frei) + je Rolle ein `charge_gebinde`-Datensatz mit `inventar_nr` und berechneter Menge
   (`erp_etikett_rollen_verteilen` → `erp_etikett_rollen_anlegen`), `pakete = Rollenzahl`. Auto-Nummer via
   `erp_inventar_naechste()`, Format/Eindeutigkeit via `erp_inventar_norm()` / `erp_inventar_frei()`.

   **Fertige Kapseln (Warenart „Fertigware / Bulk"):** Dann erscheint zusätzlich ein **Rezeptur-Picker**
   (Textsuche, `erp_rezeptur_liste()`, nur Rezepturen ≠ Entwurf). Beim Buchen wird die Position aufs
   **kanonische Bulk-Item der Rezeptur** gebucht (`item.rezeptur_id` + `kategorie='fertig'`), aufgelöst per
   `erp_rezeptur_bulkitem()` (read-only). Gibt es noch kein Bulk-Item, wird die Zeile **nicht** gebucht,
   sondern gemeldet („bitte erst im Dashboard anlegen") – die Anlage bleibt kanonisch im Dashboard
   (`rezeptur_bulkitem()`); siehe `ANTWORT-DASHBOARD-REZEPTUR-BULKITEM.md`, Variante A.
4. **Alle buchen** (`POST aktion=buchen`): je Position eine Charge (`erp_wareneingang_buchen` für L1,
   `erp_wareneingang_buchen_fremd` für L2), Blinker anhängen (grün), Pakete + Bewegungslog. Unbekannte
   Artikel werden via `erp_item_anlegen` angelegt. Ungültige Zeilen werden übersprungen und gemeldet
   (Teil-Buchung, kein Totalverlust). Danach Erfolgspanel mit **Sammel-Etikett** (`?p=etikett&ids=…`).

Ersetzt im Menü die alten getrennten Seiten `eingang.php` (L1) und `l2_eingang.php` (L2); deren Routen
bleiben für Altlinks (z. B. Erwartete Lieferungen → Einbuchen) bestehen.

**Rezepturnummer-Aufkleber scannen (Spec 5.2):** fünfte Start-Kachel „Rezepturnummer-Aufkleber". Ein
Feld nimmt den gescannten R-Code auf (Enter) → `POST aktion=rsticker` → `erp_rezeptur_per_nummer()` →
die erkannte Rezeptur wird als **Fertigware/Bulk-Position** eingefügt (Rezeptur + koppelbares Bulk-Item
vorausgewählt), Fokus springt auf den Blinker. Kein Tippen von Nummern = keine Fehlzuordnung durch Tippfehler.

**Kiste (optional):** Oben lässt sich eine **Kiste** (`kiste_id`) wählen (aus `kiste_alle()`, Name oder
Barcode). Ist eine Kiste gewählt, wird jede gebuchte Charge per `kiste_charge_zuordnen()` hineingelegt und der
**Blinker je Position ist optional** (die Kiste blinkt beim Finden). Ohne Kiste bleibt der Blinker Pflicht.
Hat die Kiste bereits einen **Blinker** (`kiste_alle().blinker`), wird dessen Code beim Wählen der Kiste
**automatisch in die Blinker-Felder eingetragen** (auch in neu hinzugefügte Zeilen); manuell getippte Blinker
bleiben erhalten, beim Entfernen der Kiste wird nur der Kisten-Code wieder gelöscht.

**Lieferschein-Scan** erfasst zusätzlich Lieferanten-**Art.-Nr.** je Position und die **Auftragsnummer**
(beides in die Charge-Notiz), legt den **Lieferanten** an/verknüpft ihn und erfindet **keine Charge** mehr. Das Lieferant-Feld ist eine
Eingabe mit Vorschlagsliste (datalist): bestehende wählen ODER **neuen Namen tippen** → wird beim Buchen über
`erp_lieferant_finden_oder_anlegen()` gefunden (exakt/fuzzy) oder **neu angelegt** (mit Lieferantennummer).
**Sendungs-/Paketnummer** (Tracking) wird je Charge gespeichert (`lg_tracking_set`).

**Paketnummern je Position** (`p_paketnummern[]`): Die **Anzahl der Scan-Felder folgt dem Feld „Pakete"** –
tippt man z. B. 11, erscheinen sofort 11 nummerierte Felder (Paket 1…11), kein „+ weiteres Paket"-Klicken,
kein Zählen. Vorhandene Scans bleiben beim Ändern der Anzahl erhalten; Scan+Enter springt ins nächste Feld,
und ein Scan im letzten Feld lässt die Paketzahl mitwachsen. Die gefüllten Nummern landen zeilenweise im
versteckten `p_paketnummern[]`; beim Buchen gilt `pakete = max(Pakete-Zahl, Anzahl Nummern)` und die Nummern
werden je Charge als Tracking gespeichert.

**Status je Position** über den **Freigegeben-Haken** (`p_frei[]`): angehakt (Standard) = `frei`,
nicht angehakt = `quarantaene`. So lassen sich in einer Lieferung Produkte mischen, ohne getrennt
einzubuchen. Wird an `erp_wareneingang_buchen(..., $status)` übergeben. „Gesperrt" gibt es hier nicht –
der volle Status (inkl. Gesperrt) ist nachträglich auf der Charge-Detailseite änderbar
(`?p=charge`, Aktion `status` → `erp_charge_status_setzen()`).

**Einheit als Auswahlfeld** (`p_einheit[]`): Je Position ein festes Dropdown mit **Stk, g, kg, ml, L**
statt Freitext. Die Einheit eines bestehenden Artikels (bzw. der Rezeptur) wird per `einhCanon()`
auf eine dieser fünf abgebildet (z. B. „Stück"→Stk, „Liter"→L, „t"→kg); unbekannt → Stk.
Leerkapseln werden immer auf Stk gesetzt. Serverseitig unverändert (`$_POST['p_einheit'][$i]`).

**Aus Liste wählen: Artikel der Bestellposition bleibt (S-21)**: Hat eine Bestellposition einen eigenen
Artikel (Verpackung, Etikett, Rohstoff ...), kommt sie mit genau diesem Artikel und seiner Warenart in die
Eingangskarte – sie wird **nie** auf die Rezeptur bzw. den Bulk-Artikel gemappt, auch wenn die Position an einem
Auftrag hängt. Nur Bulk-Artikel (`fertig` + Rezeptur) und Freitext-Positionen ohne Artikel laufen über die
Rezeptur-Ableitung. Beim Buchen lehnt der Server außerdem jede Zeile ab, die einen Bulk-/Rezeptur-Artikel als
Etikett/Verpackung/Rohstoff einbuchen will – der Artikel wird dadurch nicht mehr umgewidmet.

**Vorgewählte Lieferung per URL (S-06):** `?p=we&lieferung=<id>` öffnet „Aus Liste wählen" und lädt die Lieferung sofort (Link „Einbuchen" aus „Erwartete Lieferungen"). Das Buchen markiert die Bestellung als angekommen und `geliefert`.

**Status ist Regel, keine Wahl (St-12):** Der Haken „Freigegeben" je Position ist nur noch Anzeige (deaktiviert). Rohstoffe, Bulk und verkaufsfertige Ware gehen immer in Quarantäne (Freigabe unter „Quarantäne"), Verpackung und Etiketten sind sofort frei – wie im Dashboard.

## Teillieferung (Nacht 1c)
`bestellung_position.geliefert_menge` (neu, additiv) zählt, was je Position schon eingebucht ist. Der Wareneingang mit Bestellbezug ruft `bestellung_lieferung_erfassen($bestellung_id, $charge_ids)` (Lager-App: `erp_bestellung_lieferung_erfassen`): die Chargen werden auf die Positionen verteilt (gleicher Artikel, sonst Freitext-Position; g/kg und ml/l werden umgerechnet). **Geschlossen** (angekommen_am, Status geliefert) wird die Bestellung erst, wenn alle Positionen zu mind. 98 % geliefert sind. Vorher bleibt sie offen, und „unterwegs" zählt nur den Rest (`bp_rest_sql()`, auch in `bulk_position_basis_sql()`), es entsteht also kein doppelter Bedarf. Der Knopf „liefern" (`bestellung_wareneingang`) setzt alle Positionen voll.

## Etiketten einbuchen (03.11.2026)
Eigene Kachel **„Etiketten einbuchen“** (`data-weg="etikett"`), direkt erreichbar auch per `?p=we&weg=etikett` (Knopf auf der Startseite neben „Einbuchen“).
1. **Produkt wählen** per Textsuche (datalist aus `erp_etikett_produkte()`: Produkte mit Kundenetikett oder Etikett-Slot).
2. AJAX `aktion=etikett_produkt` → `erp_etikett_zu_produkt()`: je Kunde die aktuelle, nicht gesperrte Version (dazu der Etikett-Slot des Produkts, falls er kein Kundenetikett ist). Mehrere → Auswahl Kunde/Version, eines → sofort gezeigt. Die Antwort trägt zusätzlich `glas` = das **maßgebliche Endformat aus dem Behälter** (`erp_produkt_etikettmass()`).
3. **Prüfen:** Das freigegebene Design (`item.etikett_dokument_id`, über `?p=dok`) erscheint als Vorschau (`/assets/etikett_vorschau.js`: PDF Seite 1 per pdf.js als Bild, damit es auch am Handy/PWA sichtbar ist). Daneben groß die **Größe – maßgeblich das Endformat AUS DEM GLAS/BEHÄLTER des Auftrags/Produkts** (`erp_produkt_etikettmass()` = Behälter `item.etikett_final`; Behälter = jüngster Auftrags-Behälter, sonst Produkt-Behälter), mit Hinweis „(aus dem Glas im Auftrag)". Dazu eine Zeile **Glas / Behälter** (Name + Endformat), Material, Version, Kunde und Artikel. **Wichtig:** Die am Etikett-Artikel gespeicherte Größe (`breite_mm/hoehe_mm`, sonst Maß-Vorlage `etikett_vorlage_id`) wird NICHT mehr als Wahrheit angezeigt – weicht sie vom Glas-Endformat ab (Toleranz 2 mm), erscheint nur ein **neutraler grauer Hinweis** (`#weEtMassHinweis`): „Hinweis: Etikett … mm, Glas-Endformat … mm – Maße weichen ab. Etiketten sind oft etwas größer, einbuchen ist möglich." **Keine Sperre, keine Rückfrage, keine Quarantäne, keine Aufgabe** (Nico 08.11.2026, Soll-Handbuch 4.5: Etiketten sind oft 2–5 mm größer = Sicherheitsmaß). Gibt es kein Glas am Auftrag/Produkt, fällt die Größe auf das am Etikett hinterlegte Maß zurück (mit Quellenhinweis). Ohne Design steht weiterhin: „Kein freigegebenes Etikett-Design hinterlegt“.
4. Erst nach **„Menge erfassen“** (seit 08.11.2026, vorher „Design und Größe stimmen – Menge erfassen“) erscheinen Ziel/Lieferant und die Position. Der Etikett-Artikel ist vorbelegt, die Warenart steht auf Etikett (Rolle), sichtbar sind nur Gesamtmenge und Anzahl Rollen. Gebucht wird unverändert (`erp_etikett_rollen_verteilen`).

Nebenbei behoben: Die Start-Kacheln blieben nach der Wahl eines Wegs sichtbar (`display:grid` schlug `hidden`).

**Nachtrag 03.11.2026 (Nico):**
- Das Dropdown zeigt den **Kundennamen** („P-3218 · Spermidin Kapsel · Kunde GmbH“), je Produkt + Kunde ein Eintrag, und man kann auch nach dem Kunden suchen. Ist ein Eintrag mit Kunde gewählt, erscheint nur dessen Etikett. Etiketten ohne Produkt stehen als eigene Einträge drin (AJAX `etikett_produkt` mit `item_id`).
- **„Anderes Etikett anlegen“**: Kunde (Textsuche), Produkt (Textsuche), beides optional; dazu Bezeichnung, Breite/Höhe (mm) und Material. AJAX `aktion=etikett_anlegen` → `erp_etikett_anlegen()`, danach geht es direkt zur Sichtprüfung und Menge. Für Produkt + Kunde mit vorhandenem Etikett wird das vorhandene genommen. Die Felder haben keine name-Attribute (sie liegen im Buchungsformular `weForm`), gesendet wird per fetch. Das Design lädt das Büro am Produkt hoch.

## Leerkapseln (04.11.2026)
- Warenart „Leerkapseln“: Pflichtfeld **Kapselgröße** je Position (`p_kapselgroesse[]`), vorbelegt aus dem Artikel bzw. dem Namen. Wird am Artikel gespeichert (`item.kapselgroesse_id`), fehlt sie, bricht die Zeile mit „Kapselgröße fehlt“ ab.
- Neuer Artikelname, der nach Leerkapseln aussieht (z. B. „Leerkapseln HPMC Gr. 0“), stellt die Warenart von Rohstoff auf Leerkapseln und die Einheit auf Stk.
- Haken „Freigegeben“ folgt dem Schalter Quarantäne (`WEQ`, Standard aus = frei); vorher zeigte er fest „Quarantäne“.

Soll-Kette 2a (04.11.2026): Haken **„Freigegeben“** je Position ist jetzt bedienbar und standardmäßig gesetzt. Abgehakt = Problem → Quarantäne (`p_frei[]=0`). Die Vorbelegung ändert sich nur beim Wechsel der Warenart.

Soll-Kette 2c (04.11.2026): Bulkware nur auf den Rezeptur-Bulk-Artikel. Ein gewählter loser Bulk-Artikel (ohne Rezeptur) wird über den Auftrag der Position auf den Rezeptur-Artikel umgelenkt, ohne Auftrag abgelehnt („bitte Rezeptur wählen“).

Soll-Kette 2d (04.11.2026): Bulkware bekommt die Kapselgröße automatisch. Nur wenn sie nicht ableitbar ist (Kapsel-Rezeptur in mehreren Größen, kein Auftrag), erscheint bei der Position das Pflichtfeld „Kapselgröße“; die Wahl geht an die Charge.

Soll-Kette 2b (04.11.2026): Standard = vorhandener Artikel (auch per Name gefunden). Ein unbekannter Name zeigt „Nicht in der Artikelliste“ und den Knopf **„Neuer Artikel“**; ohne Knopf wird nicht gebucht. Neuer Rohstoff braucht die **Beschaffenheit** (Pulver, Granulat …). Bulk geht nur über die Rezeptur, Etiketten über ihren eigenen Weg. Jede Neuanlage meldet sich beim Team.

Gewicht & Bestand pro Gebinde (04.11.2026): Bei mehr als einem Paket erscheint je Position „Menge je Karton“ – eine Zeile je Karton, gleichmäßig vorbelegt (Stk ganzzahlig, sonst 3 Stellen, Rest auf den letzten); Summe und Differenz laufen mit. Passt die Summe nicht oder fehlt ein Karton, ist das Buchen gesperrt (Browser und Server, `p_geb[<key>][]`). Beim Buchen wird je Karton ein `charge_gebinde` mit seiner Menge angelegt (gewogen=1; g-Eingaben werden mit der Charge umgerechnet). Der frühere Haken „Aufteilen“ entfällt. Etikett-Rollen bleiben bei der Gleichverteilung.

## CoA aus der Bestellung (08.11.2026)
Nach dem Buchen gegen eine gewählte Lieferung setzt `erp_bestellung_lieferung_erfassen()` an jeder Charge die `bestellung_position_id` (bevorzugt die Position mit derselben gemeldeten Chargennummer). Fehlt zu einer Charge das CoA an der Bestellung, erscheint nur ein Hinweis. Die vom Lieferanten gemeldete Charge wird hier bewusst noch nicht angezeigt oder vorbelegt (kommt mit dem blinden Abgleich, Schritt 6).

## „Aus Liste wählen“ kompakt (08.11.2026)
Die ankommenden Sendungen stehen als **kompakte Liste** (eine Zeile je Lieferung: Artikel · Lieferant/Bestellnr. · Termin), mit **Suchfeld** und begrenzter Höhe (eigener Scroll, max. ca. halbe Bildschirmhöhe). Ein Klick **klappt die Liste auf die gewählte Lieferung zusammen** – Lieferant und Positionen stehen direkt darunter, kein Scrollen ans Seitenende. Der Knopf oben heißt dann **„← Zurück zur Liste“** und öffnet die ganze Liste wieder (geladene Positionen werden geleert); ohne gewählte Lieferung bleibt er „← Andere Methode“. Auch der Direkteinstieg `?p=we&lieferung=ID` (aus „Erwartete Lieferungen“) zeigt nur die gewählte Lieferung. Termine am Bestelltag oder davor werden nicht angezeigt.

## Kacheln (08.11.2026)
Reihenfolge: Lieferschein scannen · Sendungsnummer · Aus Liste wählen (Zähler ankommende Sendungen) · Rezepturnummer-Aufkleber · Etiketten einbuchen · **Kundenware (Lager 2)** (Link auf `l2_eingang`) · **Manuell** (bisher „Regulär“, als Ausweichweg zuletzt). Nur Kacheln mit `data-weg` öffnen einen Weg auf dieser Seite.

## Immer gegen eine Bestellung (10.11.2026, Einkauf gerade ziehen Teil 1/2)
- Erste Kachel ist **„Bestellung wählen“**: erwartete Lieferungen mit Suche nach BE-Nr., Lieferant und Artikel. Sendungsnummer-Scan lädt dieselbe Bestellung. Lieferschein-Scan, Rezeptur-Aufkleber und Etiketten suchen die offene Bestellposition zum Artikel selbst.
- Jede Zeile trägt `p_bp` (Bestellposition). Ist sie gesetzt, sind Artikel, Warenart und Einheit fest. Darunter steht „Bestellung BE-… · offen … · Kapselgröße“, dazu der Knopf „Nicht aus dieser Bestellung“.
- **Ohne Bestellung** (Kachel statt „Manuell“, oder eine gelöste Zeile): Pflicht-Grund im Kopf, nämlich alte Bestellung (mit Lieferant: nachträgliche Bestellung wird angelegt und verknüpft), Muster oder Sonstiges (Notiz Pflicht). Lager 2 gilt als Kundenware. Ohne Grund wird die Zeile nicht gebucht.
- Nach dem Buchen je Bestellung: Meldung „vollständig eingegangen und geschlossen“, „Teillieferung … der Rest zählt weiter als unterwegs“ oder „Mehrlieferung“.
- Die Artikelauswahl zeigt Warenart, Kapselgröße/Füllgewicht und Synonyme.

- Der Ähnlichkeits-Hinweis bei neuen Namen vergleicht auch die Synonyme vorhandener Artikel (Teil 5).

## Paket 12.11.2026 Teil 5 (Gebindeaufkleber)
Kachel „Lieferanten-Aufkleber scannen“ (AJAX la_scan/la_abschluss), Stand „x von y erfasst“, Abschluss auch unvollständig; Sendungsnummer- und R-Code-Feld erkennen BX-Codes.

## Feste Felder im Dark Mode (12.11.2026)
Felder aus der Bestellposition, die nicht änderbar sind (Klasse `we-fest`: Artikel, Warenart, Einheit), hatten ein festes Hellgrau (`#f2f4f0`). Im Dark Mode waren sie dadurch weiß mit heller Schrift. Jetzt nutzen sie die Theme-Farben `var(--line-2)` und `var(--muted)`.

**Vorbelegung der Menge (Fable-4 T1.5, 13.11.2026):** Menge aus Bestellung („Lieferung wählen“) oder Lieferschein-Scan steht deutsch mit Komma und ohne Tausenderpunkt im Feld („12,375“ statt „12.375“, „1500“) – `erp_menge_parse()` las „12.375“ sonst als 12375.

**Geschlossen ohne Einbuchen (Sofortfix 13.11.2026):** „Ankommende Sendung wählen“ zeigt zusätzlich Bestellungen mit Positionen, die ohne Ware geschlossen wurden (`erp_ohne_einbuchen_lieferungen()`); `erp_lieferung_positionen()` liefert diese Positionen (`zu` = 1) und belegt Charge/MHD aus „Chargen & Dokumente“ vor.

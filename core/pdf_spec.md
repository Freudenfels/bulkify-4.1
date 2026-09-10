# pdf_spec.php – Spezifikation und CoA im bulkify-Layout

## Warum
Die Unterlagen der Vorlieferanten kommen auf **deren** Briefpapier. Die geben wir nicht an den Kunden weiter – er soll nicht sehen, wer uns beliefert. Stattdessen stellen wir **eigene** Dokumente aus: die Spezifikation aus unseren Artikel-Stammdaten, das Analysenzertifikat aus den Analysewerten der Charge. Die Lieferantenunterlagen bleiben intern die Quelle und der Nachweis.

## Funktionen
- `build_spec_pdf(int $item_id): ?string` – **Spezifikation** eines Rohstoffs, als saubere Abschnittstabellen (Helfer `spec_tabelle()`, Optik wie die CoA-Tabelle): **Produktidentität** (Bezeichnung, Synonyme, botanische Quelle, CAS/EC, Herkunft, Zusätze, Spezifikations-Nr. + Version, gültig ab), **Gehalt (Assay)** aus `item_wirkstoff`, **Charakteristische Kennwerte** aus `item_kennwert` (Sensorik/physikalisch-chemisch), **Reinheit & Grenzwerte** aus `item_grenzwert` (Schwermetalle, Mikrobiologie, Mykotoxine …), **Deklarationen** (vegan, GVO-frei, nicht bestrahlt, TSE/BSE-frei, Allergene, Zertifikate) und **Lagerung & Haltbarkeit**. Wichtig: früher fehlten `item_kennwert` und `item_grenzwert` – dadurch war das PDF fast leer, obwohl im Spec-Tab alles gepflegt war; jetzt kommt der volle Datenbestand aufs Blatt. Leere Abschnitte entfallen. Bricht bei Bedarf auf Folgeseiten um.
- `build_coa_pdf(int $charge_id): ?string` – **Analysenzertifikat** zur Charge: Rohstoff, Chargennummer, Menge, Wareneingang, MHD, Herkunft, Spezifikations-Nr. und die Tabelle **Parameter · Spezifikation · Ergebnis · Methode** aus `charge_analyse`. Am Ende steht, ob die Charge freigegeben ist oder noch in Quarantäne.
- Bausteine des Layouts: `spec_jn()`, `spec_kopf()` (Logo links + Maniso-Firmenblock rechts + zentrierter Titel), `spec_grid()` (Label/Wert-Gitter mit grauen Label-Zellen), `spec_h()` (Abschnittstitel), `spec_table()` (Tabelle mit **Charcoal-Kopf** `#232323`, weißer Kopfschrift, Zeilenschattierung, Rahmen), `spec_release()` (QS-Freigabe mit Signatur + Stempel, „Tabea Albers · Qualitätssicherung"), `spec_fuss()` (rechtssichere Firmen-Fußzeile).

**Design = identisch zur Skill-/v3-Vorlage:** zentrierter Titel („PRODUKTSPEZIFIKATION" / „ANALYSENZERTIFIKAT"), Charcoal-Tabellenköpfe, graue Label-Gitter, Signatur + Stempel. Branding-Assets: `assets/bulkify-logo.jpg`, `assets/bulkify-signature.jpg`, `assets/bulkify-stamp.jpg` (Stempel/Signatur einmal aus dem Skill nach JPEG konvertiert, weil MiniPDF nur JPEG einbettet). Firmendaten (Adresse, USt-IdNr., EORI, Bank) kommen aus `beleg_firma()` mit den echten Maniso-Defaults.

**Nicht erklärt ist nicht „nein":** Felder ohne Wert stehen als „–" im Dokument, nicht als Verneinung.

## Wo es auftaucht
- Intern: `?p=spec_bulkify&id=<item_id>` und `?p=coa_bulkify&id=<charge_id>` (Rollen production, einkauf, labor, admin). Der CoA-Knopf steht in der Chargen-Tabelle des Rohstoffs.
- Kundenportal: `?p=portal&token=…&v=spec_pdf&rid=<item_id>` – Panel **Spezifikation** am Rohstoff. Steht immer zur Verfügung, unabhängig von der Dokumentenfreigabe.

## Werte aus dem Lieferanten-PDF
Im Panel **Analysenwerte je Charge** gibt es **Werte vorschlagen**: Das hochgeladene Lieferanten-PDF wird gelesen und die gefundenen Parameter ins Formular geschrieben (`core/coa_lesen.md`). Gespeichert wird erst nach Prüfung. Bei einem **Scan** kommt kein Text heraus – dann sagt die Oberfläche das und man trägt von Hand ein.

## Verwandt
- `core/dokument_ui.php` – die Ablage der **Lieferantenunterlagen** (intern; die Freigabe fürs Portal gibt das Original weiter und ist nur für Ausnahmen gedacht).
- `module/lager/spec_bulkify.md` – die interne Route.

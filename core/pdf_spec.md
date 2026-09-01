# pdf_spec.php – Spezifikation und CoA im bulkify-Layout

## Warum
Die Unterlagen der Vorlieferanten kommen auf **deren** Briefpapier. Die geben wir nicht an den Kunden weiter – er soll nicht sehen, wer uns beliefert. Stattdessen stellen wir **eigene** Dokumente aus: die Spezifikation aus unseren Artikel-Stammdaten, das Analysenzertifikat aus den Analysewerten der Charge. Die Lieferantenunterlagen bleiben intern die Quelle und der Nachweis.

## Funktionen
- `build_spec_pdf(int $item_id): ?string` – **Spezifikation** eines Rohstoffs: Bezeichnung, Synonyme, botanische Quelle, CAS/EC, Spezifikations-Nr. + Version + gültig ab, Herkunft, Zusätze, Haltbarkeit, Lagerung, Allergene, **Gehalt** (aus `item_wirkstoff`) und die Erklärungen (vegan, GVO-frei, nicht bestrahlt, TSE/BSE-frei, Zertifikate).
- `build_coa_pdf(int $charge_id): ?string` – **Analysenzertifikat** zur Charge: Rohstoff, Chargennummer, Menge, Wareneingang, MHD, Herkunft, Spezifikations-Nr. und die Tabelle **Parameter · Spezifikation · Ergebnis · Methode** aus `charge_analyse`. Am Ende steht, ob die Charge freigegeben ist oder noch in Quarantäne.
- `spec_jn()`, `spec_kopf()`, `spec_zeile()`, `spec_fuss()` – Bausteine des Layouts (Logo, Absender, Fußzeile wie bei den übrigen bulkify-Dokumenten).

**Nicht erklärt ist nicht „nein":** Felder ohne Wert stehen als „–" im Dokument, nicht als Verneinung.

## Wo es auftaucht
- Intern: `?p=spec_bulkify&id=<item_id>` und `?p=coa_bulkify&id=<charge_id>` (Rollen production, einkauf, labor, admin). Der CoA-Knopf steht in der Chargen-Tabelle des Rohstoffs.
- Kundenportal: `?p=portal&token=…&v=spec_pdf&rid=<item_id>` – Panel **Spezifikation** am Rohstoff. Steht immer zur Verfügung, unabhängig von der Dokumentenfreigabe.

## Verwandt
- `core/dokument_ui.php` – die Ablage der **Lieferantenunterlagen** (intern; die Freigabe fürs Portal gibt das Original weiter und ist nur für Ausnahmen gedacht).
- `module/lager/spec_bulkify.md` – die interne Route.

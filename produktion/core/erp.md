# produktion/core/erp.php — DIE NAHT
Einzige Datei mit Zugriff auf Dashboard-Tabellen. Nie `core/schema.php` des Dashboards einbinden (siehe [PRODUKTION.md](../PRODUKTION.md)).

## Lesen
- Auth: `erp_benutzer_per_mail/token`, `erp_benutzer`, `erp_dashboard_url()`.
- Produktion: `erp_produktionsauftraege($status)` (`''`=aktive `offen`/`laufend`, `'alle'`=alle, sonst genau dieser Status; liefert auch `auftrag_eingang`), `erp_pa($id)` (mit Rezeptur, Kapselgröße, VPE, Verpackung, Kunde, Eingang), `erp_pa_schritte($pa_id)`.
- Übersicht: `erp_pa_zutaten($pa_id)` (Rezeptur-Zutaten je Einheit), `erp_pa_charge_info($pa_id)` (Charge/MHD gebucht oder geplant), `erp_pa_bereitschaft($pa_id[,status,fertig])` → `bereit`/`wartet`/`laeuft`/`fertig`, `erp_pa_fehlbedarf($pa_id)` (fehlende Rohstoffe + Leerkapseln + Verpackung).

## Schreiben: `erp_schritt_abschliessen($schritt_id, $akteur)`
Schließt den jeweils **ersten offenen** Schritt eines Auftrags ab (feste Reihenfolge):
1. Reihenfolge-Guard (nur der nächste offene Schritt).
2. FEFO-Materialentnahme je Station mit Mangel-Guard (`erp_station_entnahme`):
   `Rohstoffe bereitstellen` → Rohstoffe, `Verkapselung` → Leerkapseln, `Fertigware bereitstellen` → zugekaufte Bulkware, `Verpacken` → Gebinde. Reicht der Bestand nicht, wird **nicht** abgeschlossen und `fehler='mangel'` mit `fehlt`-Liste zurückgegeben. Entnahme ist idempotent (prüft `produktion_verbrauch`).
3. Schritt `erledigt=1` + `erledigt_von/at`, Reservierungen abgleichen.
4. Auftragsstatus neu: `offen` / `laufend` / `erledigt` (gleiche Werte wie das Dashboard).
5. Letzter Schritt: Rest-Reservierungen frei, **an das Lager zum Einlagern übergeben** (`erp_an_lager_uebergeben` legt eine Lager-Aufgabe `ref_typ='einlagern'` an, idempotent je PA; das **Lager** bucht die Fertigware ein, nicht die Produktion), Auftrag auf `erledigt`, Aktivität protokolliert.

## Lager-Übergabe am Abschluss
`erp_einlager_ziel($pa_id)` – Fulfillment-Kunde → Lager 2 (Fremdlager), sonst Lager 1 (Warenlager); spiegelt `einlager_ziel_fuer_pa()` im Dashboard. `erp_an_lager_uebergeben($pa_id)` legt die Lager-Aufgabe „Einlagern: <Produkt> → <Lager 1/2>" (prio 2, `ref_typ='einlagern'`, `ref_id=pa`) an, **idempotent** (keine zweite, solange eine offen ist). Das Lager bucht beim Einlagern die Charge (`.A/.B/.C`, MHD +18 M) und schließt die Aufgabe. `erp_fertigware_einbuchen` bleibt als Baustein (Teilmengen/Sonderfälle), wird am normalen Abschluss aber **nicht** mehr direkt aufgerufen.

Rückgabe: `['ok','fehler'(null|nicht_gefunden|reihenfolge|mangel),'msg','fertig','station','fehlt']`.

## Produktionsweg (Ausbaustufen) je Auftrag
`erp_weg_lesen($pa_id)` (Grundweg zukauf/eigen/bulk + Schalter abfuellen/etikettieren/beipack/karton + aenderbar), `erp_weg_stationen($pa_id,$f)`, `erp_weg_anwenden($pa_id,$f)` – erzeugt die `produktion_schritt`-Zeilen neu (nur solange kein Schritt erledigt; Bulk hat festen Weg). Der Weg ist in den Schritten abgebildet, keine Extra-Tabelle. Optionale Stationen: `Verpacken`, `Etikettieren`, `Beipackzettel beilegen`, `Umkarton`.

## Teilmengen produzieren
`erp_teilmenge_bedarf($pa_id,$m)` (Rohstoff-+Leerkapsel-Bedarf anteilig für M Einheiten), `erp_teilmenge_produzieren($pa_id,$m,$akteur)` – verbraucht Rohstoffe (+Leerkapseln) **anteilig** nach FEFO (Mangel-Guard für M) und bucht M als Fertigware-Charge (`erp_teilmenge_einbuchen`); Auftrag bleibt offen (`laufend`) bis `erp_produktion_gebucht` = Menge. Teilmengen buchen also weiterhin direkt ein (schnelle Ware für den Kunden); nur der **Abschluss ohne vorab gebuchte Teilmenge** geht über die Lager-Übergabe, und der Rest, der nach Teilmengen noch offen ist, bucht das Lager beim Einlagern.
**Abschluss-Schutz:** Wurden Teilmengen gebucht und ist noch Rest offen, lässt `erp_schritt_abschliessen()` den letzten Schritt nicht zu (`fehler='teilmenge'`). Die Schritt-Entnahme ist idempotent (prüft `produktion_verbrauch`), daher kein Doppelverbrauch nach Teilmengen; `erp_schritt_material()` liefert je Zeile `entnommen`, damit „Erledigt" trotz Teil-Bestand nicht fälschlich sperrt.

## Schreiben: `erp_schritt_status_setzen($schritt_id, $erledigt, $akteur)` (Admin-Override)
Setzt einen Schritt direkt auf erledigt/offen – **auch außer der Reihe**. REINE Statuskorrektur: markiert `erledigt` + `erledigt_von/at` (bzw. löscht sie) und rechnet den Auftragsstatus neu. **Keine** FEFO-Entnahme, **keine** Fertigware-Einbuchung (dafür ist `erp_schritt_abschliessen()` da). Nur für Admins aufrufen (Guard in der Seite).

## BEWUSSTE DOPPELUNG (wichtig)
Die Lager-/Chargen-Logik spiegelt das Dashboard (`core/schema.php`: `produktion_schritt_erledigen()` + Helfer `produktion_materialbedarf`, `produktion_*_entnehmen`, `produktion_fertigware_einbuchen`, `produktion_an_lager_uebergeben`/`einlager_ziel_fuer_pa`, `charge_naechste_nr`, `reservierung_abgleichen` …). Beide Programme schreiben in **dieselben** Tabellen: `charge`, `produktion_verbrauch`, `produktion_schritt`, `produktionsauftrag`, `reservierung`, `aktivitaet`, `nummernkreis`, `app_meta`, `item`.
**Ändert sich im Dashboard eine Regel (FEFO-Reihenfolge, Mangel-Schwelle `0.0001`, Chargennummer, MHD, Fertigware-Einbuchung, Lager-Übergabe/Einlager-Ziel), MUSS sie hier mitgezogen werden.** Aktuell: der PA-Abschluss bucht **nicht** mehr selbst ein, sondern übergibt per `erp_an_lager_uebergeben` an das Lager (gespiegelt vom Dashboard-Umbau, Commit 816573f). Begründung der Doppelung statt gemeinsamer Bibliothek: die Naht verbietet das Einbinden von `core/schema.php` (zieht die zweite `core/db.php` + das ganze Dashboard herein). Eine gemeinsame Library wäre der Alternativweg, berührt aber `core/schema.php` → nur abgestimmt umsetzen.

## Produktionscharge CH/CHE (Spec 7.5 + 16) – Raw-SQL-Naht zu `prod_charge`
Die Dashboard-Tabellen `prod_charge` / `prod_charge_rohstoff` + `charge.standort` gehören dem Dashboard (Paket A, `core/schema.php`), werden hier aber **nur per Raw-SQL** erreicht (nie `core/schema.php` einbinden). Diese Entität ist **zusätzlich** zur Fertigprodukt-Charge (`charge.charge_nr` `.A/.B` bleibt). `CH…` = intern gemischt, `CHE…` = extern zugekauft; Nummernkreis identisch (`erp_naechste_nummer('CH'|'CHE')`).
- `erp_prod_charge_fuer_pa($pa_id)` – jüngste Hauptcharge (ohne parent) eines Auftrags.
- `erp_prod_charge_anlegen($d)` / `erp_prod_charge_sub_anlegen($parent_id,$d)` – Haupt-/Untercharge (Untercharge je Gebinde/Tag/Mitarbeiter, `sub_kennung` A/B/C…).
- `erp_prod_charge_maschine($pc_id,$maschine_id)` – Maschine an der Charge festhalten (Spec 9.1).
- `erp_prod_charge_rohstoff_verknuepfen($pc_id,$d)` – einen Rohstoff-Batch verknüpfen (`batch_nr` = Hersteller-Batch = `charge.charge_nr`).
- `erp_prod_charge_rohstoffe_aus_verbrauch($pa_id,$pc_id,$akteur)` – **das Wichtigste (Spec 7.5):** verknüpft ALLE für den Auftrag verbrauchten Rohstoff-Chargen (`produktion_verbrauch`) als Batches. Idempotent (bereits verknüpfte Lager-Chargen werden übersprungen).
- `erp_prod_charge_fuer_station($pa_id,$station,$maschine_id,$menge,$akteur)` – Orchestrierung je Schritt: `Mischen` → interne CH-Charge anlegen/finden (+ Mischmenge, Maschine) und Rohstoffe verknüpfen; `Fertigware bereitstellen` → externe CHE-Charge; spätere Steps → nur Maschine anhängen. Wird aus `run.php` nach erfolgreichem Schritt-Abschluss aufgerufen.

### Einzige bewusste Abweichung
`erp_produkt_leerkapsel_id()` nutzt die **manuell gepflegte** `produkt.leerkapsel_id` bzw. die eindeutige Leerkapsel über die **gepflegte** `rezeptur.kapselgroesse_id`. Die gewichtsbasierte Auto-Berechnung der Kapselgröße (Dashboard: `rezeptur_kapselgroesse()` via Füllgewicht/Dichte) ist hier **nicht** nachgebaut. Ist die Kapselgröße nicht gepflegt, wird – wie im Dashboard bei Uneindeutigkeit – nichts abgebucht.

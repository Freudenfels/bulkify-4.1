# produktion/core/erp.php — DIE NAHT
Einzige Datei mit Zugriff auf Dashboard-Tabellen. Nie `core/schema.php` des Dashboards einbinden (siehe [PRODUKTION.md](../PRODUKTION.md)).

## Lesen
- Auth: `erp_benutzer_per_mail/token`, `erp_benutzer`, `erp_dashboard_url()`.
- Produktion: `erp_produktionsauftraege($status)` (`''`=aktive `offen`/`laufend`, `'alle'`=alle, sonst genau dieser Status; liefert auch `auftrag_eingang`), `erp_pa($id)` (mit Rezeptur, Kapselgröße, VPE, Verpackung, Kunde, Eingang), `erp_pa_schritte($pa_id)`.
- Übersicht: `erp_pa_zutaten($pa_id)` (Rezeptur-Zutaten je Einheit), `erp_pa_charge_info($pa_id)` (Charge/MHD gebucht oder geplant), `erp_pa_bereitschaft($pa_id[,status,fertig])` → `bereit`/`wartet`/`laeuft`/`fertig`, `erp_pa_fehlbedarf($pa_id)` (fehlende Rohstoffe + Leerkapseln + Verpackung; Behälter PA -> Auftrag -> Produkt, damit der bedruckte Kundenbeutel zählt, Blocker 1). `erp_pa()` löst den Behälter ebenso PA -> Auftrag -> Produkt auf.

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

## Mischer-Kapazität & Umrechnung (Spec 7.7)
- `erp_mischer_plan($pa_id,$cap)` – reine Berechnung (kein DB-Schreiben): Gesamt-Mischmasse in kg (Summe `menge_mg` × Einheiten / 1e6) und, mit `cap` (kg je Gebinde), die Aufteilung in Gebinde (volle à `cap`, letztes mit Rest) inkl. anteiliger Rezeptur je Gebinde.
- `erp_mischer_unterchargen_anlegen($pa_id,$cap,$akteur)` – legt je Gebinde eine Untercharge unter der internen CH-Hauptcharge an (ein Etikett je Gebinde, Spec 7.7/7.8). Idempotent (nur wenn noch keine Unterchargen existieren).

### Einzige bewusste Abweichung
`erp_produkt_leerkapsel_id()` nutzt die **manuell gepflegte** `produkt.leerkapsel_id` bzw. die eindeutige Leerkapsel über die **gepflegte** `rezeptur.kapselgroesse_id`. Die gewichtsbasierte Auto-Berechnung der Kapselgröße (Dashboard: `rezeptur_kapselgroesse()` via Füllgewicht/Dichte) ist hier **nicht** nachgebaut. Ist die Kapselgröße nicht gepflegt, wird – wie im Dashboard bei Uneindeutigkeit – nichts abgebucht.

## Teil 4: Gebinde + Schwund (Raw-SQL über die Naht)
- `erp_charge_gebinde_liste($charge_id)` – Gebinde einer Charge (geteilte Tabelle `charge_gebinde`).
- `erp_gebinde_scan_nach_produktion($gebinde_id)` – Schritt 2: Gebinde beim Scannen auf `standort='produktion'` umbuchen (NUR Ortswechsel, keine Abbuchung); gibt die `charge_id` zurück (fürs Scan-Gate).
- `erp_schwund_erfassen(item,charge,pa,rechnerisch,gewogen,einheit,von,gebinde?)` – Schritt 3: Differenz still in `schwund` protokollieren + Benachrichtigung „nach oben" (mehr IMMER, Schwund ab `app_meta['schwund_grenze_kg']`, Default 1 kg). Spiegelt `schwund_erfassen()` des Dashboards. `erp_rohstoff_rueckgabe_speichern()` ruft es je Charge auf (rechnerisch = gebuchter Bestand nach FEFO, gewogen = eingegebener Rest) – blind, der Mitarbeiter sieht nichts.

## Teil 4 (Schritt 5): Restmischung einbuchen
`erp_restmischung_einbuchen($pa_id, $menge_kg, $von)` – legt die übrige gemischte Bulkware als `charge` des Rezeptur-Bulk-Artikels (`erp_rezeptur_bulkitem`) an: Menge in kg (`einheit='kg'`), `charge_nr` = Mischcharge-Nr (`erp_prod_charge_fuer_pa`, sonst PR-Charge-Nr), MHD = `erp_mhd_standard()`, Status 'frei', Standort Lager 1, `pa_id` gesetzt. Rückgabe ['ok','charge_nr','mhd',…].

## Zugekaufte Bulkware in g/ml statt Stk (St-13)
`erp_fertigware_einheit($pa_id)` liefert die Einheit der zugekauften Bulkware nach Darreichungsform der Rezeptur: Pulver/Granulat `g`, Flüssig/Gel `ml`, sonst `Stk`. `erp_schritt_material()` (Station „Fertigware bereitstellen"), `erp_pa_fehlbedarf()` und `erp_fertigware_entnehmen()` nutzen sie statt des festen „Stk"; der Verbrauch (`produktion_verbrauch.einheit`) bekommt die Einheit der Charge. `erp_fertigware_chargen()` liefert dafür zusätzlich `c.einheit`. Spiegel im Dashboard: `produktion_fertigware_einheit()` in `core/schema.php`.

## Zukauf-Art „fertig" (St-24)
`erp_zukauf_fertig()` / `erp_zukauf_fertig_chargen()`: Bei fertiger Ware zeigt „Fertigware bereitstellen" die verkaufsfertigen Packungen am Auftrag (Soll in Pkg), `erp_fertigware_entnehmen()` prüft nur (kein Verbrauch), `erp_pa_fehlbedarf()` meldet Packungen, „Verpacken" bucht keine Verpackung ab. Eingelagert/zugeordnet wird im Dashboard (Lieferanten-MHD bleibt). Spiegel von `produktion_zukauf_fertig_chargen()`.

## Fehlbestand melden (Paket Mails)
`erp_fehlbestand_melden($pa,$station,$msg)`: scheitert ein Schritt in Werk-/Prod-App an Material, entsteht eine Team-Benachrichtigung im Dashboard (gleiche Meldung binnen 10 Min. nur einmal). Mails verschickt nur das Dashboard.

## QM-Gate Fertigware (St-11d)
Eigene Fertigware (Einlagern aus der Produktion: `produktion_teilmenge_einbuchen()`, Produktions-App `erp_teilmenge_einbuchen()`, Easy Production außer „schon geliefert") kommt in **Quarantäne** statt frei. Versand, Fremdlager-Übergabe und Weiterverarbeitung nehmen nur freie Chargen, also erst nach der QM-Freigabe (Lager-App „Quarantäne" / Dashboard-Wareneingang, nur QM/Admin). Die Versand-/Fremdlager-Meldung nennt die wartende Menge (`fertigware_qm_hinweis()`), die Bulk-Reservierung einer nicht freien Charge warnt nur, und die Lager-App lässt Fertigware in Quarantäne nicht entnehmen. Zukauf „fertig" behält den Status vom Wareneingang.
## Etikett des Auftrags (Paket Etiketten)
`erp_etikett_item_id($pa_id)` liefert das Kundenetikett DIESES Auftrags: 1) die gebundene Version (`auftrag.etikett_item_id`), 2) das aktuelle Kundenetikett des Kunden (`item.kunde_id`), 3) der Produkt-Slot nur, wenn er ein generisches Etikett ist (nie das Kundenetikett eines anderen Kunden). `erp_etikett_status()` und der Werk-Schritt "Etikettieren" nutzen es. Vorher wurde `produkt.etikett_id` gelesen: bei einem Katalogprodukt mit mehreren Kunden etikettierten alle mit dem Design/Bestand des zuletzt freigebenden Kunden.

## Abbuchung Etikett + Deckel (Paket Etiketten)
`erp_station_entnahme()` kennt jetzt `Verpacken` (Behaelter + Deckel, `erp_verpackung_entnehmen`) und `Etikettieren` (Kundenetikett, `erp_etikett_entnehmen`). Gemeinsamer Helfer `erp_posten_entnehmen()`: alles-oder-nichts, idempotent je (PA, Artikel), FEFO (`erp_fefo_abbuchen`), optional Ersatzartikel (generisches Massetikett, `erp_generisches_etikett_fuer_behaelter()`), Verlaufseintrag am Auftrag. `erp_rollen_abbuchen()` fuehrt die Etikettenrollen (`charge_gebinde`) mit. Spiegel der Dashboard-Funktionen `produktion_*` (Sub-App darf `core/schema.php` nicht laden). Der Behaelter wird PA -> Auftrag -> Produkt aufgeloest.

**Restbuchung (S-13):** `erp_posten_entnehmen`, `erp_rohstoffe_/kapseln_/fertigware_/verpackung_/etikett_entnehmen` spiegeln die Dashboard-Funktionen: gebucht wird „Soll bis `$bis` Packungen − bereits für dieses PA verbraucht" (alles-oder-nichts, `$trocken` = nur prüfen). Nach einer Teilmenge bucht der Schritt „Rohstoffe bereitstellen" den Rest, statt abzubrechen.

**Teilmenge + Etikett-Sperre (S-12):** `erp_teilmenge_produzieren()` spiegelt `produktion_teilmenge_produzieren()` (Etikett-Freigabe, Trockenlauf aller Stationen, anteilige Restbuchung, dann Charge). `erp_etikett_sperre()` sperrt auch `erp_schritt_abschliessen()` (Prod-App und Werk-App) ohne Kundenfreigabe. `erp_teilmenge_machbar()` = Rechner „jetzt machbar" (komplett fertig / bis vor Etikettieren, knappstes Material) per Binärsuche über den Trockenlauf – exakt dieselbe Prüfung wie beim Buchen. `erp_teilmenge_bedarf()` entfällt.

**Restbedarf, Pulver nach Gewicht (St-08):** `erp_materialbedarf()` ist jetzt ein exakter Spiegel von `produktion_materialbedarf()` (Pulver/Granulat nach Füllgewicht/Massenanteil, als Bulk reservierter Teil abgezogen, `entnommen`/`offen`, Rest = Kleineres aus Restpackungen und benötigt−verbraucht). `erp_pa_fehlbedarf()` und `erp_schritt_material()` rechnen mit dem Rest (je Zeile „Menge" = noch abzubuchen, `menge_gesamt` = Soll), `erp_mischer_plan()` mischt nur den Rest (Pulver nach Füllgewicht). Hilfsfunktionen `erp_rest_faktor()`, `erp_auftrag_bulk_reserviert()`.

`erp_teilmenge_einbuchen()` bindet die Fertigcharge zusätzlich an `auftrag_id` (S-09, Spiegel von `produktion_teilmenge_einbuchen`).

## Zukauf „fertig" – kurzer Weg (Nacht 2a)
Bei Zukauf-Art „fertig" hat der Produktionsauftrag nur noch **Fertigware bereitstellen → Qualitätsprüfung → Produktions-Freigabe → Versand-Freigabe** (kein Verpacken/Etikettieren/Beipack/Umkarton, die Ware ist fertig). `produktion_schritte_regenerieren()` beachtet `zukauf_art`, `produktion_zukauf_weg_anpassen()` passt beim Umstellen der Zukauf-Art die Schritte an (noch nichts erledigt: neu erzeugen, Status bleibt; sonst nur offene Ausbau-Schritte entfernen). Prod-App: `erp_weg_stationen()` ebenso.

## QM-Gate Schritte (St-11a)
Die Schritte **Qualitätsprüfung, Produktions-Freigabe, Versand-Freigabe** schließt nur QM (Rolle `labor`) oder Admin ab: Dashboard `produktion_schritt_erledigen()` (`produktion_qm_stationen()`, `darf_qm_freigeben()`), Prod-App/Werk-App `erp_schritt_abschliessen($id, $akteur, $uid)` (`erp_darf_qm()`). Die Produktion bekommt „… schließt QM ab (Vier-Augen-Prinzip)", in der Werk-App ist der Knopf gesperrt. Auch die QS-Freigabe in der Prod-App (`?p=qs`) und ihr Zurückziehen sind nur für QM/Admin. Admin-Overrides/God-Mode bleiben. Noch nicht gebaut: echtes Personen-Vier-Augen (b).

**Vollbedruckt (03.11.2026):** `erp_vollbedruckt($pa_id)` (Behälter PA -> Auftrag -> Produkt bedruckt) – dann liefert
`erp_etikett_item_id()` 0 (keine Etikett-Entnahme/-Anzeige) und `erp_etikett_sperre()` sperrt nicht. Spiegel von `auftrag_vollbedruckt()`.

`erp_produktionsauftraege()` (03.11.2026): Ohne `'alle'` werden Produktionsaufträge stornierter Aufträge ausgeblendet (NOT EXISTS auf `auftrag.status='storniert'`, eigene Kopie der Regel `pa_aktiv_sql()`, weil die Sub-App core/schema.php nicht lädt).

Soll-Kette 2c (04.11.2026): `erp_rezeptur_stamm()`; `erp_rezeptur_bulkitem()` sucht und legt nur an der Stammrezeptur an (vorher je Variante ein zweiter Bulk-Artikel), `erp_fertigware_chargen()` und die Bulk-Reservierung ebenso.

Soll-Kette 2e (04.11.2026): Schrittfolge der Werk-App ohne „Produktions-Freigabe“ (Spiegel von `produktionsschritte_fuer()`).

Soll-Kette 1 (04.11.2026): `erp_materialbedarf()` nimmt bei Weg „premix“ den Premix-Artikel (kg) statt der Zutaten – „Rohstoffe bereitstellen“ bucht dann den Premix FEFO ab.

## Soll-Kette 4a: Schritt-Logik (04.11.2026)
- `erp_stufen_machbar($pa)`: je Stufe machbare Packungen (Trockenlauf der Stationsentnahmen, Binärsuche mit Abkürzungen): `herstellung` (Rohstoffe/Premix, Leerkapseln), `vor_etikett` (+ Dosen/Deckel), `komplett` (+ Etikett, nur mit Etikett-Freigabe), `vorproduziert`, knappstes Material je Stufe. `erp_machbar_hinweis()` macht daraus den Hinweis („keine Etiketten – nur bis vor dem Etikettieren: 100 Pkg“, „nur 100 von 500 Pkg komplett machbar“, „… = 9.000 Stk“).
- `erp_teilmenge_vor_etikett()`: Teilmenge bis vor dem Etikettieren, bucht Material aller Stationen außer Etikettieren (Restbuchung) und merkt `produktionsauftrag.menge_vor_etikett`. Die Fertigware entsteht erst mit `erp_teilmenge_produzieren()`.
- Etikett-Freigabe sperrt nur noch den Schritt **Etikettieren** (vorher alle Schritte).

Soll-Kette 4b (04.11.2026): `erp_teilmenge_anweisungen()` (offene Anweisungen vom Büro), `erp_teilmenge_anweisung_verrechnen()` – aufgerufen von `erp_teilmenge_produzieren()` und `erp_teilmenge_vor_etikett()`.

Soll-Kette 5 (04.11.2026): `erp_teilmenge_einbuchen()` bucht Fertigware frei und meldet sie per `erp_fertigware_dokumente_melden()` (Loopback `?p=api_qm_freigabe`, Token wie Einlagern) – dort entstehen CoA und, bei voller Menge, die Bericht-Freigabe.

Soll-Kette 6 (04.11.2026): `erp_karton_vorschlag()` (Pkg je Karton, Packungen, Charge, MHD) und `erp_karton_drucken()` – speichert die Werte am PA und stößt den Druck über die Lager-Brücke an (`api_druck&typ=karton`).

Gewicht & Bestand pro Gebinde (04.11.2026): `erp_rollen_abbuchen()` bucht alle Gebinde ab (gescannte in der Produktion zuerst). Neu `erp_rueckgabe_gebinde($charge_id)` (Kartons/Säcke einer Charge mit Standort Produktion, ohne Etikettenrollen) und `erp_gebinde_abgleich()` (Spiegel von `charge_gebinde_abgleich`). `erp_rohstoff_rueckgabe_speichern($pa, $gewichte, $von, $gebinde)` nimmt zusätzlich `[gebinde_id => gewogen]`: jedes Gebinde steht dann auf seinem gewogenen Wert (zurück in Lager 1, gewogen=1), die Charge auf der Summe der Gebinde; Schwund wie bisher still. Ohne Gebinde-Werte je Charge, die Gebinde werden nachgezogen.

## Freie Bulkware (05.11.2026)
`erp_fertigware_chargen()` liefert zuerst die eigenen Chargen des Auftrags, dann freie Rezeptur-Bulkware **ohne Auftrag**. Vorher kamen auch Chargen anderer Aufträge mit. `menge_verfuegbar` ist dabei die nutzbare Menge: abzüglich der Reservierungen anderer Aufträge, gedeckelt auf den Netto-Pool wie `auftrag_bulk_lager_frei()` im Dashboard. `menge_ist` ist der echte Bestand. `erp_fertigware_entnehmen()` bucht mit `menge_ist − Entnahme`. Das ist der Spiegel zu `produktion_fertigware_entnehmen()`.
- 08.11.2026 (Handbuch 4.9): Meldetexte von `erp_etikett_sperre()` wie im Dashboard: „Etikett nicht freigegeben – Produktion nur bis vor dem Etikettieren". Sperre unverändert nur beim Schritt Etikettieren.

**Eimerplanung (11.11.2026):** `erp_mischer_plan($pa, 0)` liefert den Plan des Systems aus `core/pa_eimer.php` (Buchstabe, Charge je Rohstoff, Grund), festgeschrieben ab dem 1. Eimer. `erp_mischer_eimer_abschliessen()` braucht keine Behältergröße mehr. Die FEFO-Funktionen bevorzugen `$GLOBALS['pa_bevorzugt']` (gescannte Chargen) und schreiben `teilmenge_id`.

**Zahlen (Block D, 11.11.2026):** `pr_menge_parse()` – deutsche Zahlen-Eingabe (wie `erp_menge_parse` der Lager-App), für alle Mengenfelder der Produktions-App.

**Kein Etikett-Ersatz mehr (Block E, 11.11.2026):** `erp_etikett_entnehmen()` hat keinen 'alternativ'-Ersatz mehr. Fehlt Kundenetikett-Bestand, ist Etikettieren blockiert, Werk-App und Dashboard rechnen gleich. Neutrale Produkte (`erp_etikett_item_id` = generisch) bleiben unverändert. Früher über den Ersatz gebuchte generische Etiketten zählen weiter.

**Gewichte (13.11.2026, Fable-3 T1.2):** Gewichts- und Wiegefelder lesen über `gewicht_lesen()` (core/gewicht.php): Punkt und Komma sind Dezimaltrenner („25.125“ kg = 25,125; „6.482“ g = 6,482; „25,000“ = 25), nur wenn beide vorkommen, gilt das letzte. Vorbelegungen ohne Tausenderpunkt (`gewicht_feld()`). Stückzahlen bleiben bei der Mengen-Lesart („12.000“ Stk = 12000). Neu: `pr_menge_einheit_lesen($s, $einheit)`, `erp_charge_einheit()`, `erp_gebinde_einheit()`.

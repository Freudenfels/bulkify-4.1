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
5. Letzter Schritt: Rest-Reservierungen frei, **Fertigware als Charge einbuchen** (`.A/.B/.C`, MHD heute+18 M), Auftrag auf `erledigt`, Aktivität protokolliert.

Rückgabe: `['ok','fehler'(null|nicht_gefunden|reihenfolge|mangel),'msg','fertig','station','fehlt']`.

## Produktionsweg (Ausbaustufen) je Auftrag
`erp_weg_lesen($pa_id)` (Grundweg zukauf/eigen/bulk + Schalter abfuellen/etikettieren/beipack/karton + aenderbar), `erp_weg_stationen($pa_id,$f)`, `erp_weg_anwenden($pa_id,$f)` – erzeugt die `produktion_schritt`-Zeilen neu (nur solange kein Schritt erledigt; Bulk hat festen Weg). Der Weg ist in den Schritten abgebildet, keine Extra-Tabelle. Optionale Stationen: `Verpacken`, `Etikettieren`, `Beipackzettel beilegen`, `Umkarton`.

## Schreiben: `erp_schritt_status_setzen($schritt_id, $erledigt, $akteur)` (Admin-Override)
Setzt einen Schritt direkt auf erledigt/offen – **auch außer der Reihe**. REINE Statuskorrektur: markiert `erledigt` + `erledigt_von/at` (bzw. löscht sie) und rechnet den Auftragsstatus neu. **Keine** FEFO-Entnahme, **keine** Fertigware-Einbuchung (dafür ist `erp_schritt_abschliessen()` da). Nur für Admins aufrufen (Guard in der Seite).

## BEWUSSTE DOPPELUNG (wichtig)
Die Lager-/Chargen-Logik spiegelt das Dashboard (`core/schema.php`: `produktion_schritt_erledigen()` + Helfer `produktion_materialbedarf`, `produktion_*_entnehmen`, `produktion_fertigware_einbuchen`, `charge_naechste_nr`, `reservierung_abgleichen` …). Beide Programme schreiben in **dieselben** Tabellen: `charge`, `produktion_verbrauch`, `produktion_schritt`, `produktionsauftrag`, `reservierung`, `aktivitaet`, `nummernkreis`, `app_meta`, `item`.
**Ändert sich im Dashboard eine Regel (FEFO-Reihenfolge, Mangel-Schwelle `0.0001`, Chargennummer, MHD, Fertigware-Einbuchung), MUSS sie hier mitgezogen werden.** Begründung der Doppelung statt gemeinsamer Bibliothek: die Naht verbietet das Einbinden von `core/schema.php` (zieht die zweite `core/db.php` + das ganze Dashboard herein). Eine gemeinsame Library wäre der Alternativweg, berührt aber `core/schema.php` → nur abgestimmt umsetzen.

### Einzige bewusste Abweichung
`erp_produkt_leerkapsel_id()` nutzt die **manuell gepflegte** `produkt.leerkapsel_id` bzw. die eindeutige Leerkapsel über die **gepflegte** `rezeptur.kapselgroesse_id`. Die gewichtsbasierte Auto-Berechnung der Kapselgröße (Dashboard: `rezeptur_kapselgroesse()` via Füllgewicht/Dichte) ist hier **nicht** nachgebaut. Ist die Kapselgröße nicht gepflegt, wird – wie im Dashboard bei Uneindeutigkeit – nichts abgebucht.

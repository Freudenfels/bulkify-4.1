# system/easy_production.php – Easy Production (schnelle Produktionserfassung)

Route `?p=easy_production` (Menü: Produktion · Easy Production; Rollen admin + production). Gehört zu **Teil 3 Block J**: die Eingabemaske, mit der eine real erfolgte Produktion in wenigen Feldern erfasst wird. Digitalisiert das Papier-Produktionsbuch (auch Altaufträge) und erfasst laufende Produktion schnell – beides erzeugt endlich korrekten Bestand.

## Ablauf
1. **Maske** (Felder): Auftrag (Suchdropdown `rscombo`), Produzierte Menge (Stk, Tausenderpunkt), Produktionsdatum (Default heute), Chargennummer (aus Auftrag vorbelegt, überschreibbar), MHD, Gefäß (Glas/Tüte/…), Mitarbeiter (Suchdropdown über `benutzer`), Rückstellmuster (Stk, Default 0), **Bulkware (optional)**, „Schon geliefert" (Haken), Besonderheiten (Freitext).
   - **Bulkware (optional):** Suchdropdown über die fertigen Bulk-Zwischenprodukte (`item.kategorie='fertig'`, mit Bestand). Zu wählen, wenn aus vorhandener/zugekaufter Bulkware abgefüllt wurde. Die gewählte Bulkware wird in der Vorschau als **editierbare Materialzeile** (Rolle „Bulkware") oben an die Abgangs-Tabelle gestellt und beim Buchen wie jede andere Position FEFO ausgebucht – **kein eigener Buchungspfad**. Menge ist zunächst leer (lt. Produktionsbuch eintragen). Ist die Bulkware bereits in der Stückliste enthalten, wird sie nicht doppelt eingefügt. Ohne Auswahl bleibt alles beim Material aus der Stückliste.
2. **Vorschau** (Pflicht, `aktion=vorschau`): zeigt vor dem Buchen, **was** gebucht wird – nichts wird dabei an Bestand geschrieben. Materialabgang aus der Stückliste (`auftrag_bedarf()` des PA, skaliert auf die produzierte Menge) mit benötigter/verfügbarer Menge und „reicht/Fehlbestand"; Fertigware-Zugang (Kundenmenge = produziert − Rückstellmuster; Rückstellmuster separat). **Alle Material-Mengen sind editierbar** (Abweichungen aus dem Buch). Zeigt außerdem bereits am Auftrag gebuchte Menge + Anzahl früherer Erfassungen (Doppelbuchungs-Hinweis).
3. **Buchen** (`aktion=buchen`) in dieser Reihenfolge:
   - **Material raus:** je Position FEFO-Charge-Reduktion (`charge.menge_verfuegbar` ↓, Status `leer` bei 0) + `produktion_verbrauch`-Eintrag. Reicht der Bestand nicht, wird nur die verfügbare Menge gebucht (warnen, nicht blockieren – Altbestand unvollständig).
   - **Fertig rein:** Kundenmenge als `charge` auf dem Lagerartikel `produkt_lageritem()` (Kategorie **`verkaufsfertig`** = Fertigprodukte) mit der eingetragenen Chargennummer + MHD. Rückstellmuster als eigene, **gesperrte** Charge (`charge_nr`+`-RM`, Status `gesperrt`) – nicht im verkäuflichen Bestand.
   - **(falls „schon geliefert"):** die eben gebuchte Kundencharge wird sofort auf 0/`leer` gesetzt und ein **Lieferschein** (`beleg typ='lieferschein'`, Nr. `LS-…`) angelegt – kein Phantom-Fertigbestand.
   - **Datensatz** in `easy_production` (Auftrag, Mengen, Datum, Charge, MHD, Gefäß, Mitarbeiter, geliefert, Besonderheiten, menschenlesbares Buchungsprotokoll, wer/wann) + `log_aktivitaet` am Auftrag + `bedarf_bump()`.

## Anbindung an die Aufträge (Teil 3 H6/D)
- Immer an einen Auftrag gebunden. Die Fertigware-Charge trägt `pa_id`, daher zeigt der Auftrag die produzierte Menge/Charge automatisch (`auftrag_mengenfortschritt()`/`produktion_gebucht()`).
- Für Altaufträge: dieselbe Maske, Haken „schon geliefert". Für laufende Produktion: schnelle Direkterfassung. „Zuletzt erfasst"-Liste unten; in der Vorschau wird die schon gebuchte Menge angezeigt (Doppelbuchungs-Schutz als Hinweis, kein harter Stopp).

## Verwendete Bestandsfunktionen / Abweichungen
- **Material-Abgang:** FEFO-Charge-Reduktion + `produktion_verbrauch` (gleiches Muster wie `produktion_kapseln_entnehmen`/`produktion_fertigware_entnehmen`).
- **Fertigware-Zugang:** direkter `charge`-Insert nach dem Muster von `produktion_teilmenge_einbuchen()`. **Nicht** `einlager_buchen()` selbst, weil das keine explizite Menge/Charge/MHD und keine getrennte Rückstellmuster-Buchung erlaubt (es bucht immer den PA-Rest in eine Auto-Charge).
- **Abgang bei „schon geliefert":** Charge-Reduktion + Lieferschein-`beleg` (wie `auftrag_versenden_menge()`). `erp_charge_entnehmen` existiert nur in der **Lager-Sub-App** (`lager/core/erp.php`), nicht dashboard-seitig – daher der dashboard-eigene Lieferschein-Weg.
- **Block K (Fertigprodukte-Kategorie):** nicht nötig – Fertigware geht bereits in die eigene Kategorie `verkaufsfertig` (abgefüllt/etikettiert), getrennt von `fertig` (Bulk). Die Kategorie-Umbenennung aus Block K ist davon unabhängig.

## Mengen-Interpretation
„Produzierte Menge" = gesamte hergestellte Einheiten; **Kundenmenge = produziert − Rückstellmuster**; Material skaliert über die Stückliste mit der produzierten Menge (die Rückstellmuster sind darin enthalten). Da die Vorschau-Mengen editierbar sind, lässt sich jede Abweichung vor dem Buchen korrigieren.

## Bulk-Charge-Auswahl je Bulk-Position (2026-10-30)
In der Vorschau wird je **Bulk-Position** (Materialzeile mit `item.kategorie='fertig'`) die Charge angezeigt, aus der abgebucht wird:
- genau eine Charge mit Bestand → automatisch (nur Anzeige, kein Eingriff),
- mehrere Chargen → Pflichtauswahl (`mat_charge[<i>]`), Vorbelegung **älteste Charge zuerst (FIFO)**, je Charge Bestand + MHD sichtbar,
- keine Charge → Hinweis „keine Charge im Lager" (Buchung warnt, blockiert nicht).

Regel: Eine Kundencharge stammt aus **genau einer** Bulk-Charge (keine Mischung) → je Bulk-Position nur **eine** Charge wählbar. Beim Buchen wird exakt aus dieser Charge abgebucht (`ep_material_abbuchen_charge()` – kein FEFO-Split), Rohstoff/Verpackung laufen weiter FEFO (`ep_material_abbuchen()`). Reicht der Bestand der gewählten Charge nicht, wird nur die verfügbare Menge gebucht (nur warnen).

Verknüpfung Bulk-Charge ↔ Kundencharge beidseitig sichtbar über `produktion_verbrauch` (`charge_id`=Bulk, `pa_id`=PA) und die gemeinsame `pa_id` von Verbrauch und Fertigware-Charge:
- Kundencharge-Seite (`?p=chargen&id=`): „Zusammensetzung (eingesetzte Chargen)" listet die Bulk-Charge; zusätzlich steht die Bulk-Charge in der Chargen-Notiz („… aus Bulk-Charge …").
- Bulk-Charge-Seite: „Verwendet in Produktionen" zeigt jetzt zusätzlich die Spalte **Kundencharge** (entstandene Fertigware-Charge des PA) mit Menge und Kunde.

Die Felder der Vorschau nutzen explizite Indizes (`mat_item[<i>]`, `mat_menge[<i>]`, `mat_charge[<i>]` …), damit Charge und Position sicher zusammenpassen.

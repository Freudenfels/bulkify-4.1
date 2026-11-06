# produktion/bericht.php – Produktionsbericht (Herstellprotokoll)

**Zweck:** Druckbare Gesamtübersicht eines Produktionsauftrags – als Beleg. Route `?p=produktion_bericht&id=<pa_id>`. Button **„Produktionsbericht"** auf der Auftragsdetailseite.

## Zwei Ansichten aus einer Quelle
Die Daten sammelt `produktion_bericht_daten($pa_id)` (core/schema.php), gerendert wird über den gemeinsamen Include **`_bericht_inhalt.php`**. Denselben Include nutzt das **Kundenportal** (`module/portal/kunde.php`, View `produktionsbericht`), damit intern und beim Kunden alles identisch aussieht.

- **Intern** (`?p=produktion_bericht&id=…`): ALLES – Produktionsauftrag-Nr, Kunde (intern), **Produktionsart (Eigen/Fremd)**, alle Schritte mit **Zeit + Bearbeiter (`erledigt_von`) + gescannter Charge**, **entnommene Materialien chargengenau mit Lieferant + Datum**, **zugeordnete Chargen** (mit Lieferant), eingebuchte Fertigware.
- **Kundenansicht** (`?fuer=kunde`, Team-Vorschau; im Portal automatisch): **bereinigt** – ohne Lieferanten, ohne Mitarbeiternamen, ohne Rohstoff-/zugeordnete Chargen, **ohne Produktionsart**. Schritt-Bezeichnungen sind **kundenneutral** gemappt (`Fertigware bereitstellen` → „Material bereitgestellt", `Verkapselung` → „Herstellung" …), damit **nie ein Zukauf erkennbar** ist ([[kunde-kein-zukauf-verraten]]). Der Kunde sieht Produkt, Zusammensetzung, Herstellung & Prüfung mit Datum, seine Charge + MHD und die Bemerkung.

## Bemerkung + Freigabe (nur intern)
Ein Team-Panel (nicht im Druck/Kundenansicht):
- **Bemerkung für den Kunden** (`produktionsauftrag.bericht_notiz`, editierbar) – erscheint als Abschnitt „Bemerkung" im Bericht.
- **Für Kunden freigeben** (`aktion=bericht_freigeben`) setzt `bericht_freigegeben_am/_von`; erst dann erscheint der Bericht (Kundenansicht) im **Kundenportal** bei der Bestellung. „Freigabe zurücknehmen" macht ihn wieder unsichtbar. Freigeben ist erst möglich, wenn der Auftrag **abgeschlossen** ist. Es wird ein Verlaufseintrag am Kunden geschrieben.

## Produktionschargen + Proben (Spec 7.5/16/8)
`produktion_bericht_daten()` liefert zusätzlich `prodChargen` (`prod_charge` inkl. Unterchargen, über `pa_id`),
`pcRohstoffe` (`prod_charge_rohstoff` je Produktionscharge = Rohstoff-Batch-Rückverfolgung) und `proben`
(`prod_probe`, nach Ebene rohstoff/gebinde/endprodukt/labor). `_bericht_inhalt.php` rendert daraus:
- **Produktionschargen (CH/CHE)** – nur interne Ansicht, mit Rohstoff-Batches je Charge.
- **Proben & Rückstellmuster** – Kunde sieht eine Zusammenfassung (Nachweis der Probenziehung), intern mit Details.
Beide Sektionen erscheinen nur, wenn Daten vorhanden sind. Maschinenname noch nicht gezeigt (Maschinen liegen in
der Produktions-Sub-App; folgt mit dem Maschinen→Dashboard-Umzug).

## Druck
Button „Drucken / PDF" (`window.print()`); `@media print` blendet Seitenleiste, Mobilbar und die `.no-print`-Bereiche (Aktionen, Freigabe-Panel, Hinweise) aus.

## Bearbeiter-Erfassung
`produktion_schritt.erledigt_von` wird in `produktion_schritt_erledigen()` beim Abschluss mit `current_user()['name']` gefüllt (geführte Produktion + Detailseite). Vor Einführung abgeschlossene Schritte zeigen „–".

## Rechte/Route
`production`, `labor`, `fulfillment` (core/auth.php); Route in `public/index.php`. Die **Kundenansicht im Portal** ist über den Magic-Link/Login des Kunden erreichbar, aber nur für den eigenen Auftrag UND nur wenn freigegeben (Prüfung in der Portal-View). Funktioniert für Produkt- und Bulk-Aufträge (`pa_ist_bulk()`).

## Pulver/Granulat und Flüssig (Füllmengen-Formen, St-13)
`produktion_bericht_daten()` liefert zusätzlich `fuell_einheit` (`g`/`ml`/leer) und `einheiten_gesamt`. Bei Pulver zeigt der Abschnitt Menge „Füllmenge je Packung 300 g · Füllmenge gesamt 600 kg · Portionen gesamt 120.000" statt „Stk je Packung 300 · Stk gesamt 600.000"; die Zutaten-Gesamtmengen rechnen mit den Portionen (Füllgewicht / mg je Portion) – vorher 5-fach zu hoch (g als Portionen). Bei ml ist das Portionsvolumen unbekannt, dann entfällt die Gesamtspalte. Die Darreichungsform kommt jetzt auch aus der Rezeptur des Produkts (vorher „–").

**Auto-Freigabe (03.11.2026):** Der Bericht wird bei der QM-Freigabe der Fertigware automatisch für den Kunden freigegeben.
„Zurückhalten“ (früher „Freigabe zurücknehmen“) setzt `bericht_zurueckgehalten=1` – dann bleibt er auch nach weiteren QM-Freigaben
unsichtbar; „Für Kunden freigeben“ setzt das wieder zurück.

**Pflicht-Hinweis (06.11.2026):** Am Ende von `_bericht_inhalt.php` steht klein „Dieses Dokument wurde maschinell erstellt und ist ohne Unterschrift gültig. Die Angaben beruhen auf den uns bereitgestellten Daten.“ – intern, im Druck und im Kundenportal.

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

## Druck
Button „Drucken / PDF" (`window.print()`); `@media print` blendet Seitenleiste, Mobilbar und die `.no-print`-Bereiche (Aktionen, Freigabe-Panel, Hinweise) aus.

## Bearbeiter-Erfassung
`produktion_schritt.erledigt_von` wird in `produktion_schritt_erledigen()` beim Abschluss mit `current_user()['name']` gefüllt (geführte Produktion + Detailseite). Vor Einführung abgeschlossene Schritte zeigen „–".

## Rechte/Route
`production`, `labor`, `fulfillment` (core/auth.php); Route in `public/index.php`. Die **Kundenansicht im Portal** ist über den Magic-Link/Login des Kunden erreichbar, aber nur für den eigenen Auftrag UND nur wenn freigegeben (Prüfung in der Portal-View). Funktioniert für Produkt- und Bulk-Aufträge (`pa_ist_bulk()`).

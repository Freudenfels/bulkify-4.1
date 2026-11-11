# core/pa_protokoll.php – Chargenprotokoll je Unter-Charge

Seit 11.11.2026, Teil 7 von `plan-produktion-gefuehrt.md`. Gemeinsam für Dashboard und Werk-App.

- **`pa_protokoll_pdf($uc)`** erzeugt das PDF mit:
  - Kopf: Produkt, Kunde/Auftrag, PA, Kunden-Charge (Vorgabe/Abweichung mit Grund), MHD, Bulk-Charge bzw. Eimer, Linie, Menge, Status
  - **verwendete Material-Chargen** je Teilmenge (`produktion_verbrauch.teilmenge_id`) mit Lieferant und MHD, dazu die Scans
  - **Kapselgewichte** (Proben, Ø, Kapseln aus Sackgewicht)
  - **Teilmengen** mit Zeit, Mitarbeiter, Rückstellmuster, nachgetragen, schon geliefert
  - Kartons
  - Rückstellmuster
  - Start, Wiederanlauf, Chargenwechsel, **Abweichungen und Sperren** (rot), Abschluss
  - **Fotos** (Linie leer, Stempel, Ware); JPEG wird eingebettet
  - Pflicht-Hinweis „maschinell erstellt …“
- **`pa_protokoll_ablegen($uc)`**: Beim Schließen der Unter-Charge (Chargenwechsel oder Abschluss) wird das PDF als Dokument `chargenprotokoll` an der **Fertigware-Charge** und am **PA** abgelegt (`kunde_sichtbar=0`). Es dient als Nachweis für QS/Behörde und für die Prüfliste vor Versand.
- **Route** `?p=chargenprotokoll&uc=` (`module/produktion/chargenprotokoll.php`): abgelegte Fassung, bei laufender Unter-Charge der aktuelle Stand.

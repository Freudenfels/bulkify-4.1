# PA-Seite: geführter Ablauf (`module/produktion/_ablauf.php`)

Seit 11.11.2026, Teil 1/5/6/7 von `plan-produktion-gefuehrt.md`. Wird in `detail.php` eingebunden.

- **Kopf:** Kunde, Auftrag, Soll, produziert/offen, aktuelle Unter-Charge, MHD, Status.
- **Unter-Chargen und Teilmengen:** je Unter-Charge
  - Charge (mit Link ins Lager), Bulk/Eimer, MHD, Menge
  - Teilmengen (Datum, Menge, vor Etikett, nachgetragen, Mitarbeiter, schon geliefert)
  - Kartons, Status, Chargenprotokoll (PDF)
- **Teilmenge nachtragen** (ersetzt Easy Production): Datum, Menge, Bulk-Charge, Charge (Vorschlag), Grund bei Abweichung, MHD, Mitarbeiter, Rückstellmuster, Foto optional, „schon geliefert“.
  - Gleicher Bulk ergibt dieselbe Unter-Charge; eine andere Charge-Nr. gibt es nur mit anderem Bulk.
  - Neuer Bulk ergibt den nächsten Buchstaben (`pa_starten` mit `nachtrag`, ohne Fotos).
  - Gebucht wird über `pa_teilmenge_buchen`, wie in der Werk-App.
  - „Schon geliefert“ bucht die Menge sofort per Lieferschein wieder aus (wie früher Easy Production).

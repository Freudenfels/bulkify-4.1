# PA-Seite: geführter Ablauf (`module/produktion/_ablauf.php`)

Seit 11.11.2026, Teil 1/5/6/7 von `plan-produktion-gefuehrt.md`. Wird in `detail.php` eingebunden.

- **Kopf:** Kunde, Auftrag, Soll, produziert/offen, aktuelle Unter-Charge, MHD, Status.
- **Unter-Chargen und Teilmengen:** je Unter-Charge
  - Charge (mit Link ins Lager), Bulk/Eimer, MHD, Menge
  - Teilmengen (Datum, Menge, vor Etikett, nachgetragen, Mitarbeiter, schon geliefert)
  - Kartons, Status, Chargenprotokoll (PDF)
- **Teilmenge nachtragen** (ersetzt Easy Production): Datum, Menge, Bulk-Charge, Charge (Vorschlag), Grund bei Abweichung, MHD, Mitarbeiter, Rückstellmuster, Foto optional, „schon geliefert“.
  - Gleicher Bulk **am selben Produktionstag** ergibt dieselbe Unter-Charge. Ein anderer Bulk oder ein anderer Tag (Produktionsdatum) ergibt den nächsten Buchstaben (Nico 12.11.2026).
  - Das Feld Charge ist mit dem Vorschlag für heute vorbelegt (`charge_vorschlag`). Bleibt es unverändert, vergibt das System den richtigen Buchstaben auch dann, wenn das Datum geändert wird.
  - Neuer Bulk ergibt den nächsten Buchstaben (`pa_starten` mit `nachtrag`, ohne Fotos).
  - Gebucht wird über `pa_teilmenge_buchen`, wie in der Werk-App.
  - „Schon geliefert“ bucht die Menge sofort per Lieferschein wieder aus (wie früher Easy Production).

## Knopf abschließen / wieder öffnen (12.11.2026)
In der Spalte „Status“ steht je Unter-Charge ein Knopf, solange der PA nicht erledigt ist:
- **abschließen** (nach Rückfrage): `pa_unter_charge_schliessen`, wie Chargenwechsel oder Ende in der Werk-App.
- **wieder öffnen**: nur, wenn keine andere Unter-Charge läuft.

Die Verarbeitung (`uc_schliessen`, `uc_oeffnen`) steht in `detail.php`.

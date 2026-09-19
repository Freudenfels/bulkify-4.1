# lager/naehrstoff_detail.php – Nährstoff anlegen & bearbeiten

**Zweck:** Einen einzelnen Nährstoff der NRV-Referenz pflegen.

**Felder:**
- **Name** – z. B. Magnesium, Vitamin C, Curcumin.
- **Kategorie** – Vitamin / Mineralstoff / Sonstige.
- **NRV / Tag** – der gesetzliche Nährstoffbezugswert (z. B. Magnesium 375 mg). Leer, wenn es keine NRV gibt.
- **Einheit** – mg / µg (die Rechen-Basiseinheit; NRV steht in dieser Einheit).
- **I.E.-Umrechnung (mg je 1 I.E.)** – `naehrstoff.ie_mg`. Nur für Nährstoffe, die in Internationalen Einheiten geliefert/deklariert werden (Vitamin D/A/E). Beispiel Vitamin D: 1 I.E. = 0,025 µg = **0,000025 mg**. Damit rechnet das System einen `I.E./g`-Rohstoffgehalt automatisch in µg/mg um und weist umgekehrt in der Deklaration zusätzlich die I.E. aus. Leer = keine I.E.-Umrechnung.
- **Anzeige-Einheit (Etikett, optional)** – `naehrstoff.einheit_anzeige`. Abweichende Etiketteinheit für die Deklaration, z. B. „µg RE" (Vitamin A / Retinol-Äquivalent), „mg α-TE" (Vitamin E), „mg NE" (Niacin). Ändert **nur die Anzeige**, nicht die Rechnung (die läuft weiter in mg/µg). Leer = wie Einheit.
- **Offizieller NRV-Nährstoff** – Haken, wenn eine gesetzliche NRV existiert.

**Wozu:** Der NRV-Wert ist die Basis für die spätere Deklaration „… mg = X % NRV". Deshalb liegt er zentral hier und nicht am einzelnen Rohstoff (Magnesiumcitrat und -bisglycinat teilen sich denselben NRV für „Magnesium"). Ebenso liegen I.E.-Faktor und Anzeige-Einheit hier zentral – jeder Rohstoff mit diesem Nährstoff erbt sie automatisch.

**Standard-Faktoren** werden bei der Migration geseedet (`seed_naehrstoff_ie_faktoren`, idempotent, überschreibt keine Team-Eingaben): Vitamin A `ie_mg=0,0003` / „µg RE", Vitamin D `ie_mg=0,000025", Vitamin E `ie_mg=0,67` / „mg α-TE" (natürl. RRR-α-Tocopherol; synthetisch abweichend → hier überschreiben), Niacin „mg NE".

## Health Claims (EU 432/2012)
Je Nährstoff pflegbar (Tabelle health_claim): einzeln hinzufügen oder mehrere per „Mehrere importieren"
(je Zeile ein Claim, optional „Claim | Bedingung"). Wortlaut MUSS der offiziellen EU-Liste entsprechen –
das Team pflegt/verifiziert die Texte. Ein kleiner Standardsatz wird einmalig geseedet (seed_health_claims_if_empty).
Die Claims der in einem Produkt enthaltenen Nährstoffe erscheinen automatisch im PIB (health_claims_fuer_rezeptur).

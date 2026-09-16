# lager/naehrstoff_detail.php – Nährstoff anlegen & bearbeiten

**Zweck:** Einen einzelnen Nährstoff der NRV-Referenz pflegen.

**Felder:**
- **Name** – z. B. Magnesium, Vitamin C, Curcumin.
- **Kategorie** – Vitamin / Mineralstoff / Sonstige.
- **NRV / Tag** – der gesetzliche Nährstoffbezugswert (z. B. Magnesium 375 mg). Leer, wenn es keine NRV gibt.
- **Einheit** – mg / µg.
- **Offizieller NRV-Nährstoff** – Haken, wenn eine gesetzliche NRV existiert.

**Wozu:** Der NRV-Wert ist die Basis für die spätere Deklaration „… mg = X % NRV". Deshalb liegt er zentral hier und nicht am einzelnen Rohstoff (Magnesiumcitrat und -bisglycinat teilen sich denselben NRV für „Magnesium").

## Health Claims (EU 432/2012)
Je Nährstoff pflegbar (Tabelle health_claim): einzeln hinzufügen oder mehrere per „Mehrere importieren"
(je Zeile ein Claim, optional „Claim | Bedingung"). Wortlaut MUSS der offiziellen EU-Liste entsprechen –
das Team pflegt/verifiziert die Texte. Ein kleiner Standardsatz wird einmalig geseedet (seed_health_claims_if_empty).
Die Claims der in einem Produkt enthaltenen Nährstoffe erscheinen automatisch im PIB (health_claims_fuer_rezeptur).

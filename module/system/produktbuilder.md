# system/produktbuilder.php – KI-Produktbuilder (Wunsch → Rezeptur)

Route `?p=produktbuilder` (Rollen production/sales/labor, Admin). Erzeugt aus einem Freitext-Wunsch einen
Rezepturvorschlag. **Trennung: KI schlägt vor, der Rechner rechnet exakt.** Die eigentliche Dosis-Mathe macht
NIE die KI (LLMs verrechnen sich bei IE/µg/mg), sondern `core/produktbuilder.php`.

**Ablauf:** Wunsch + Darreichungsform + Bezug (z. B. „1 Tropfen") → `pb_ki_vorschlag()` (ki_json, strukturiert:
Zutaten mit Zieldosis/Einheit/Gehalt, Verzehrempfehlung, Verpackung, Hinweise) → `pb_vorschlag_rechnen()`:
je Zutat `pb_rohstoff_match()` (bestehender Lager-Rohstoff → echter Gehalt + ie_mg schlagen die KI-Schätzung),
`pb_ie_mg()` (IE-Faktor aus `naehrstoff.ie_mg`) und `pb_zutat_rechnen()` → **mg Rohstoff je Bezug**
(= Zieldosis in reine mg via IE/µg/mg, dann / Gehalt über `wirkstoff_mg_je_mg`). Editierbare Tabelle,
„Neu berechnen" (recalc, ohne KI), „Als Rezeptur (Entwurf) anlegen" (`pb_anlegen` → `rezeptur` + Zutaten +
`rezeptur_bulkitem`, Sprung in die Rezeptur).

**D3/K2-Tropfen** (der eigentliche Grund): 1000 IE D3 → 0,025 mg rein → bei 100.000 IE/g-Öl = 10 mg Rohstoff;
20 µg K2 (MK-7) → 0,02 mg → bei 2000 µg/g = 10 mg. Trägeröl (MCT) als Zutat „traeger" füllt den Rest.

Einstieg: Button „Produktbuilder (KI)" im Kopf der Rezeptur-Liste. v1 – Produkt/Verpackung folgt aus der
Rezeptur über den bestehenden Weg; Verpackungsvorschlag steht als Textfeld im Vorschlag.

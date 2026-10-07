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

## Flüssig/Tropfen + Ansatz/Charge (für Produktion & Einkauf)
Bei Form **Flüssig** rechnet `pb_liquid_rechnen($v,$ml,$tropfen_pro_ml,$dichte)`:
- mg↔ml über die **Dichte** (MCT ≈ 0,95 g/ml), **Tropfen/ml** (dropperabhängig, Standard 25), **Flaschenvolumen**.
- Der **Träger (MCT)** wird automatisch aufgefüllt: `menge_mg = Tropfenmasse − Σ Wirkstoffe` je Tropfen.
- Zusammenfassung: mg je Tropfen, Tropfen je Flasche, Füllgewicht je Flasche.

**IE-Umrechnung verlässlich:** `pb_ie_faktor()` liefert feste Konstanten (D3/D2 = 25 ng/IE, E = 0,667 mg/IE,
A = 0,0003 mg/IE) – unabhängig vom Nährstoffstamm; `pb_ie_mg()` nimmt zuerst den exakten Stammwert, sonst die
Konstante.

**Ansatz/Charge** (`pb_charge($v,$einheiten)`): wie viel von JEDEM Rohstoff für eine Charge – die Misch- und
Bestellmenge. Flüssig: Einheiten = Flaschen × Tropfen/Flasche; sonst direkt Anzahl Einheiten. Gesamt je
Rohstoff = mg je Bezug × Einheiten (g/kg). Beispiel 100× 30-ml-Flaschen D3/K2: 750 g D3-Öl + 750 g K2-Öl +
1350 g MCT = 2850 g (= 30 ml × 0,95 × 100). Gegengeprüft.

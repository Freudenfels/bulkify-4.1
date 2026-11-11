# lager/core/inventur_buchen.php – Differenzen auflösen

Seit 11.11.2026. **Nichts wird automatisch gebucht.** Nico wählt je Abweichung eine Ursache, erst dann wird gebucht (`inv_entscheiden`).

**Gebucht wird die Differenz zum Scan-Zeitpunkt** (Ist − Soll beim Scan) auf den aktuellen Bestand. Entnahmen während der Inventur bleiben dadurch richtig.

| Ursache | Aktion |
|---|---|
| Zählfehler | Nachzählen anstoßen. Die zweite Person zählt; bei „nicht gefunden“ am gebuchten Platz. |
| Schwund / Bruch / Verderb | Ist übernehmen (Korrekturbuchung) |
| Einheitenfehler | Faktor und Einheit der Charge korrigieren, Rest-Differenz gleich mit buchen |
| Doppelbuchung | Charge ausbuchen, mit Verweis auf die andere Charge |
| Falscher Artikel / Dublette | auf den richtigen Artikel umbuchen (Sperren wie beim Zusammenführen), Mengen-Differenz mit übernehmen |
| Gefunden, nicht gebucht | Klärfall: Charge anlegen (Grund „Inventur“, Gebinde mit Inventarnummer, Platz). Sonst: Ist übernehmen. |
| Falscher Platz | Umlagerung auf den gezählten Platz bzw. die Kiste |
| Gebinde leer | ausbuchen |
| Offen lassen | nur mit Notiz, nichts gebucht |

- **Bewegungen:** Jede Korrektur ist eine Bewegung in `lg_bewegung`.
  - Typ `inventur`, mit `inventur_id`, `ursache`, Nutzer und Zeit.
  - Grund „Inventur <Name> <Datum> · <Ursache> …“.
  - Sichtbar unter Bewegungen und an der Charge.
- **Lager 2:** Bei Korrekturen an Kundenware geht zusätzlich eine Benachrichtigung an Nico.
- **Sammel-Knopf:** `inv_kleine_differenzen()` und `inv_kleine_uebernehmen()`.
  - Erfasst nur Mengenabweichungen unter `klein_eur` € **und** unter `klein_prozent` %.
  - Der Server prüft jede Zeile erneut und bucht sie als Schwund.

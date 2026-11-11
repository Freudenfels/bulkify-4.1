# Eigene MOQ je Rohstoff (`core/rohstoff_moq.php`)

Stand 11.11.2026, Auftrag von Nico. Die MOQ ist unsere Mindestmenge für den Verkauf eines Rohstoffs an Kunden, zum Beispiel 25 kg wegen des Gebindes. Sie ist unabhängig von der Mindestmenge des Lieferanten, die am Lieferantenpreis bzw. Lieferantenangebot bleibt.

## Daten
Am Artikel (`item`) stehen drei Felder:
- `moq_menge`: die Menge, leer bedeutet keine MOQ
- `moq_einheit`: kg, g, t, L oder Stk
- `moq_notiz`: optional, zum Beispiel „wegen Gebinde 25 kg“

Gepflegt wird das in den Stammdaten der Rohstoffseite.

## Regeln
- **Intern im Dashboard:** nur ein Hinweis „MOQ 25 kg“ bzw. „unter MOQ“. Nichts wird gesperrt, Nico entscheidet.
- **Kundenportal:** Die MOQ wird angezeigt und sperrt. Eine Rohstoffanfrage unter der MOQ oder ohne Menge wird nicht gespeichert. Der Kunde sieht eine Fehlermeldung mit der MOQ, die Menge ist auf die MOQ vorbelegt.
- **Ohne MOQ:** keine Sperre.
- **Vergleich der Einheiten:** g, kg und t werden ineinander umgerechnet, alle anderen Einheiten nur gegen dieselbe Einheit. Ist die Einheit nicht vergleichbar, zählt das im Portal als nicht erfüllt.

## Funktionen
- `rohstoff_moq($item_id)`: liefert `['menge','einheit','notiz']` oder null.
- `moq_umrechnen($menge, $von, $nach)`: rechnet um, null bei nicht vergleichbaren Einheiten.
- `moq_pruefen($item_id, $menge, $einheit)`: liefert `unter`, `vergleichbar` und `menge_moq`.
- `moq_hinweis_html(...)`: Badges „MOQ 25 kg“ und „unter MOQ“ für interne Seiten.
- `moq_wert()` und `moq_zahl()`: Anzeige im Format „25 kg“ bzw. „1.000,5“.
- `angebot_moq_pruefung($angebot_id)`: liefert je Rohstoff mit MOQ:
  - Rohstoff-Positionen: Artikel über die Artikelnummer, sonst über die Kundenanfrage
  - Rezeptur-Positionen: benötigte Menge für die Auftragsmenge der Option, also Rezeptur-Einheiten mal mg je Einheit, über `rezeptur_einheiten_gesamt()`
  - kleinste und größte Option; „unter MOQ“, wenn die kleinste darunter liegt

## Wo angezeigt
- **Rohstoffseite:** Felder in den Stammdaten und die Kachel „MOQ (unsere)“
- **Rohstoff-Liste:** Spalte MOQ, mit der Notiz als Tooltip
- **Kunden-Rohstoffanfrage intern:** Badge bei „Gewünschte Menge“ und Hinweis-Panel in „Rohstoff-Preis berechnen“
- **Angebot:** Panel „Mindestmengen (MOQ) je Rohstoff“ über den Rohstoffkosten
- **Kundenportal:**
  - Rohstoff-Detailseite: „Mindestmenge 25 kg“, Mengenfeld vorbelegt
  - Formular „Rohstoff anfragen“: Mindestmenge in der Info, Menge vorbelegt
  - Sperre im Browser und zusätzlich auf dem Server

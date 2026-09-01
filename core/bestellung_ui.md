# bestellung_ui.php – Ablauf einer Bestellung (ein Baustein, zwei Seiten)

## Wozu
`bestellung_ablauf_panel($b, $wer, $en)` rendert Termin, Stationen und Versanddaten einer Bestellung. **Dasselbe Panel** nutzen der interne Einkauf und das Lieferantenportal – so gibt es keine zwei Wahrheiten darüber, wie weit eine Bestellung ist. `$en = true` schaltet die Beschriftungen auf Englisch.

## Der Ablauf
1. **Bestätigen mit Termin** – ohne das geht nichts weiter. Der Lieferant nennt den geplanten Liefertermin und seinen Namen (`bestellung_bestaetigen()`); die Bestellung springt intern von `offen` auf `bestellt` und die erste Station wird gesetzt.
2. **Stationen** – Auftrag angenommen · in Produktion · Qualitätsprüfung · versandbereit · versendet. Kumulativ: erreichte Schritte stehen als Häkchen, der nächste ist ein Knopf (`bestellung_station_setzen()`).
3. **Versanddaten** – Produktionstermin, Versandanbieter, Versandart (Luft/See/Kurier/Spedition/Post), Sendungsnummer. **„versendet" lässt sich erst setzen, wenn Anbieter, Versandart und Sendungsnummer da sind** – das erzwingt die Funktion, nicht nur die Oberfläche.

## Verwandt
- `core/schema.php` – die Spalten an `bestellung` und die Funktionen `bestellung_stationen()`, `bestellung_station_index()`, `versandarten()`.

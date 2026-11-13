# bestellung_ui.php – Ablauf einer Bestellung (ein Baustein, zwei Seiten)

## Wozu
`bestellung_ablauf_panel($b, $wer, $en)` rendert Termin, Stationen und Versanddaten einer Bestellung. **Dasselbe Panel** nutzen der interne Einkauf und das Lieferantenportal – so gibt es keine zwei Wahrheiten darüber, wie weit eine Bestellung ist. `$en = true` schaltet die Beschriftungen auf Englisch.

## Der Ablauf
1. **Bestätigen mit Termin** – ohne das geht nichts weiter. Der Lieferant nennt den geplanten Liefertermin und seinen Namen (`bestellung_bestaetigen()`); die Bestellung springt intern von `offen` auf `bestellt` und die erste Station wird gesetzt.
2. **Stationen** – Auftrag angenommen · in Produktion · Qualitätsprüfung · versandbereit · versendet. Kumulativ: erreichte Schritte stehen als Häkchen, der nächste ist ein Knopf (`bestellung_station_setzen()`).
3. **Versanddaten** – Produktionstermin, Versandanbieter, Versandart, Sendungsnummer. Die Versandart kommt aus der **gemeinsamen** Liste `versandart_liste()` (Luft/See/Bahn/LKW/Express/Standard) – dieselbe wie im Anfrage-/Angebotsfluss – und ist **mehrsprachig** beschriftet (`versandart_label($key, $sprache)`, de/en/zh). Ein alter, in einer früheren Bestellung gespeicherter Wert (kurier/spedition/post) bleibt als Option erhalten und wird lesbar beschriftet. **„versendet" lässt sich erst setzen, wenn Anbieter, Versandart und Sendungsnummer da sind** – das erzwingt die Funktion, nicht nur die Oberfläche.

## Verwandt
- `core/schema.php` – die Spalten an `bestellung` und die Funktionen `bestellung_stationen()`, `bestellung_station_index()`, `versandart_liste()`/`versandart_label()`. (`versandarten()` ist die alte, nur-deutsche Liste und wird nicht mehr verwendet.)

## Wareneingang → Portal-Status (Nacht 4b)
Neue letzte Station **„Wareneingang bei bulkify"** (`eingegangen`, de/en/zh). Sie setzt nur der Wareneingang: beim vollständigen Einbuchen einer Lieferanten-Bestellung (Dashboard, Lager-App, Knopf „liefern"), nicht der Lieferant per Knopf. Bei einer Teillieferung steht im Ablauf „Teillieferung eingegangen: Datum" (`bestellung.teilgeliefert_am`). **Keine Mail an den Lieferanten.** Das Team bekommt eine Dashboard-Benachrichtigung „Lieferung eingebucht: BE-… (Firma)", bei einer Teillieferung mit Anzahl offener Positionen (`bestellung_eingang_melden()`).

## 08.11.2026
Bei „direkt bestellt“ heißt der Termin „Erwartet am“; das Team (`$wer='team'`) sieht daneben ein Datumsfeld „Termin ändern“ (Aktion `eta_setzen`, bis zum Wareneingang).

## Paket 12.11.2026 Teil 5 (Gebindeaufkleber)
Versandformular: Anzahl Pakete (Vorschlag = nicht stornierte Gebindeaufkleber) und Hinweis bei starker Gewichtsabweichung.

**Bestätigen mit Unterlagen-Sperre, Portal ohne Entwürfe (13.11.2026, Fable-3 T2.2):** Das Bestätigen-Formular (Team) zeigt bei einem Entwurf mit fehlenden Unterlagen dem Admin das Feld „Grund (Unterlagen fehlen, nur Admin)“, anderen den Grund mit gesperrtem Knopf.

## Sofortfix 13.11.2026: Unterlagen-Sperre wird Quarantäne-Sperre
Regel Nico: Unterlagen fehlen = Ware bleibt in Quarantäne. Bestellen und Einbuchen gehen immer. Artikel ohne Unterlagenpflicht: „kein Dokument nötig“ (Details `core/lieferant_unterlagen.md`).

Das Team bestätigt einen Entwurf ohne Admin-Grund – das Feld „Grund (Unterlagen fehlen, nur Admin)“ ist entfallen.

## Schritt-Leiste (13.11.2026, Fable-5 D23)
Stationen als einheitliche Leiste `ol.bx-schritte`: erledigt (grüner Haken), aktuell (hervorgehoben), offen (grau); keine Station sieht wie ein Knopf aus. Darunter ein Knopf „Nächster Schritt: …“. „Wareneingang bei bulkify“ setzt weiter nur der Wareneingang. Am Handy senkrecht.

## Stationen nur bei Portal-Lieferanten (Bestellseite 13.11.2026)
Im Team (`$wer = 'team'`) zeigt `bestellung_ablauf_panel()` die Stationen-Leiste und „Nächster Schritt“ nur, wenn `bestellung_ist_portal($b)`; bei „direkt bestellt“ nur Termin und Versanddaten. Überschrift im Team „Termin und Versand“. Im Lieferantenportal unverändert.

# leiste/finden.php – Finden im großen Lager (`?p=finden`)

Startseite des Lager-Programms. Man sucht nach Rohstoff, Artikelnummer oder Chargennummer. Zu jedem Treffer:

- **Hängt schon eine Blinker dran:** Knopf **Finden** lässt sie klingeln (grün, 40 s, mit Piepton), **Aus** schaltet sie ab, **Lösen** macht die Blinker wieder frei.
- **Noch keine Blinker:** kleines Feld zum **Scannen** einer Blinker und **Binden**. Nach dem Binden leuchtet die Blinker kurz grün zur Bestätigung.

Die Suche liest nur eigenen Bestand (kein Fremdlager, keine leeren Chargen).

## Sprache
Der Knopf **Sprache** startet die Sprachbedienung (`public/lager/assets/voice.js`): Rohstoff sagen, ein Popup zeigt die Treffer, dann per Sprache steuern – „blinke/finden/leuchte“ (aktive Blinker klingeln), „aus“, „weiter“, „zurück“, „schließen“. Braucht Chrome (Android/Desktop) und HTTPS. Die Treffer holt es über `?p=suche` (JSON), das Klingeln über `?p=klingeln`.


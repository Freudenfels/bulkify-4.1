# leiste/finden.php – Finden im großen Lager (`?p=finden`)

Startseite des Lager-Programms. Man sucht nach Rohstoff, Artikelnummer oder Chargennummer. Zu jedem Treffer:

- **Hängt schon eine Leiste dran:** Knopf **Finden** lässt sie klingeln (grün, 40 s, mit Piepton), **Aus** schaltet sie ab, **Lösen** macht die Leiste wieder frei.
- **Noch keine Leiste:** kleines Feld zum **Scannen** einer Leiste und **Binden**. Nach dem Binden leuchtet die Leiste kurz grün zur Bestätigung.

Die Suche liest nur eigenen Bestand (kein Fremdlager, keine leeren Chargen).

## Sprache
Der Knopf **Sprache** startet die Sprachbedienung (`public/lager/assets/voice.js`): Rohstoff sagen, ein Popup zeigt die Treffer, dann per Sprache steuern – „blinke/finden/leuchte“ (aktive Leiste klingeln), „aus“, „weiter“, „zurück“, „schließen“. Braucht Chrome (Android/Desktop) und HTTPS. Die Treffer holt es über `?p=suche` (JSON), das Klingeln über `?p=klingeln`.


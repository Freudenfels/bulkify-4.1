# leiste/finden.php – Finden im großen Lager (`?p=finden`)

Schnell eine Palette finden. Zwei Knöpfe öffnen dasselbe **Suchfenster** (Popup):

- **Suchen** – tippen. Das Popup geht auf, im Feld tippst du Rohstoff, Artikelnummer oder Charge, die Treffer erscheinen live. Ein Treffer angetippt lässt den Blinker grün blinken, nochmal antippen schaltet ihn aus. Das X schließt und leert alles.
- **Sprache** (auch Strg+D) – dasselbe Popup, zusätzlich mit Mikrofon: Rohstoff sagen, dann Befehle „blinke/aus/weiter/zurück/schließen“. Braucht Chrome und HTTPS.

Das Popup und die ganze Logik stecken in `public/lager/assets/voice.js`; die Treffer kommen als JSON über `?p=suche`, das Blinken über `?p=klingeln`.

Wird die Seite mit `?q=` aufgerufen (Deep-Link), zeigt sie zusätzlich eine server-gerenderte Trefferliste mit **Binden/Lösen** – zum Anhängen eines Blinkers an eine Charge, die noch keinen hat (sonst passiert das im Bestand-Detail).

Die Suche liest nur eigenen Bestand (kein Fremdlager, keine leeren Chargen).

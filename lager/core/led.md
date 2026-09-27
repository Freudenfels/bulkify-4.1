# lager/core/led.php – LED-Treiber (Pick-to-Light)

Alles, was die Blinker betrifft, steht hier. Die Hardware kommt von Jinzhishi (金之识), die Hersteller-Doku ist chinesisch.

## Der Befehl
Er hat 16 Zeichen: `FD10` + Blinker (6) + Farbe (1) + Dauer (1) + `50DF`.

- **Blinker:** Barcode auf der Blinker ohne die Endung `XD` (`D73CE3XD` wird zu `D73CE3`). Das übernimmt `led_leiste_normalisieren()`.
- **Farbe:** grün, rot, blau, gelb, pink, cyan, weiß, jeweils mit oder ohne Piepton (`led_farben()`).
- **Dauer:** 3, 6, 20, 40, 60, 90, 120 oder 180 Sekunden (`led_dauern()`). Andere Werte werden abgerundet.
- **Aus:** Farbe und Dauer beide `0`.

Der Sender nimmt den Befehl im Lager-Netz so entgegen: `GET http://{ip}/light?code=...`

## Drei Wege zum Sender (je Sender einstellbar)
- **bruecke:** Der Befehl landet in `lg_befehl` und wird von der Brücke im Lager abgeholt (`public/lager/bruecke.php`). Das ist der Normalfall, weil der Server nicht an eine IP im Lager kommt. Meldet sich die Brücke seit 15 Sekunden nicht, gibt es sofort eine Fehlermeldung, und der Befehl wird verworfen.
- **direkt:** Der Server ruft die IP selbst auf. Das geht nur, wenn bulkify im selben Netz läuft.
- **cloud:** Open-API des Herstellers (`turnOn`/`turnOff`, Signatur per MD5). Der Zugang steht in der `secrets.php`. **Noch nicht an echter Hardware getestet**, die Adresse der API fehlt in der Doku.

## Funktionen
`led_platz_an(platz, farbe, sek, piep)`, `led_platz_aus(platz)` und als Kern `led_befehl()`. Jeder Befehl wird protokolliert.

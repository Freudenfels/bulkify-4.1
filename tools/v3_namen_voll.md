# tools/v3_namen_voll.php – volle v3-Rohstoffnamen nachziehen

Der v3-Import kappt `item.name` auf 190 Zeichen. Die längsten v3-Namen (mehrere
Varianten in einem Feld, teils >1000 Zeichen) sind dadurch abgeschnitten – das Ende
fehlt. Für das Aufschlüsseln (`?p=rohstoff_split`) braucht die KI/der Mensch aber den
kompletten Namen.

Dieses Tool liest den ungekürzten `rohstoffe.name_de` aus der v3-Quelle und schreibt ihn
nach `item.name_v3` – aber nur dort, wo der v3-Name länger ist als der gekappte v4-Name.

## Aufruf
```
php tools/v3_namen_voll.php dbs15879489            # Trockenlauf
php tools/v3_namen_voll.php dbs15879489 --write     # schreiben
```
(oder ein `board.sqlite`-Pfad als Quelle)

Lokal: 3 Rohstoffe hatten längere v3-Namen (R-12417 229, R-12635 263, R-12612 1282 Zeichen).
Braucht Zugriff auf die v3-Quelle; danach `name_v3` per DB-Dump auf beta bringen.

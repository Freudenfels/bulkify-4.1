# lieferant/einladung.php – Zugang einrichten

Route: `?p=lieferant_einladung&token=…` (öffentlich)

Der Lieferant löst die Einladung ein und legt seinen Zugang **selbst** an: Name, E-Mail, Passwort (mindestens 8 Zeichen). Der Token gilt **einmal** (`lieferant_einladung.eingeloest`); danach führt der Link ins Leere und die Seite verweist auf den Login. Erzeugt wird der Link vom Team auf der Lieferantenseite.

Die Seite spricht die Sprache des Lieferanten (`lieferanten.sprache`).

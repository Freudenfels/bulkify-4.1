# lager/core/platz.php – Lagerplätze

Ein Platz besteht aus Bereich, Regal, Ebene und Fach, in Kurzform zum Beispiel `A-01-2-03` (`platz_code()`).

- `platz_raster_anlegen()` legt ein ganzes Regal auf einmal an. Was es schon gibt, bleibt unverändert.
- `platz_leiste_setzen()` ordnet einem Platz eine Leiste zu. Hängt die Leiste schon woanders, gibt es eine Fehlermeldung, sie wird nicht still umgehängt.
- `platz_naechster_ohne_leiste()` liefert den nächsten Platz für das Zuordnen und fängt am Ende wieder vorn an.

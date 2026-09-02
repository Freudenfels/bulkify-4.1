# lieferant/logo.php – Firmenlogo ausliefern

Route: `?p=lieferant_logo` (der angemeldete Lieferant sieht sein eigenes) bzw. `?p=lieferant_logo&id=<ID>` (das Team sieht das eines Lieferanten).

Die Datei liegt in `data/uploads`, also außerhalb von `public` – nur über diese Route kommt man daran. Der Content-Type folgt der Dateiendung; ohne hinterlegtes Logo kommt 404.

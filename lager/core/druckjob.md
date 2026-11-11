# druckjob.php – PDF zu einem Druckauftrag

`lg_druckjob_pdf($job)` erzeugt das PDF eines Eintrags aus `lg_druckjob` (Etikett, Lieferschein, Label, Zoll, Probe,
Gebinde, Palette, Platz, Inventur, Kartons) und nennt den passenden Drucker. Eine Stelle für die Brücke
(`public/lager/bruecke.php`, druckt in Barßel) und die Druckvorschau der Testumgebung (`?p=druck_vorschau`).

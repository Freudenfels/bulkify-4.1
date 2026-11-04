# bestand/umlagern.php – Nach Lager 2 (Umlagern)

Route `?p=umlagern`, Menü Lager 1 → „Nach Lager 2“. Fürs Handy gebaut (Karten, große Haken und Felder, Knopf unten fest).

Zeigt alle **freien, fertig produzierten** Chargen in Lager 1 (Artikel verkaufsfertig, Status frei, eigener Bestand – keine
Quarantäne, keine Kundenware). Je Karte: Produkt (kundenspezifischer Name, wenn vorhanden), Kunde + Auftrag, Charge und MHD
(nur zur Prüfung, nicht eintippbar), Menge in Lager 1, Eingabe der Menge (vorbelegt mit allem, Teilmenge möglich, höchstens
verfügbar) und Haken. „Umlagern“ bucht je angehakter Karte über `erp_umlagern_lager2()`: Lager 1 sinkt, die Menge liegt danach
nur in Lager 2 beim Kunden (`fremd_kunde_id`, `standort='lager2'`, gleiche Charge/MHD/Auftrag), Bewegung Ausgang + Eingang.

Besitzer = Kunde des Auftrags (auch über den Produktionsauftrag), sonst der Kunde am Produkt. Gibt es keinen, fragt die Karte nach
dem Fulfillment-Kunden (nie raten). Kunden ohne Fulfillment sind gesperrt (Hinweis). Etiketten und anderes Material laufen
unverändert über „Kundenware einbuchen“ (`?p=l2_eingang`).

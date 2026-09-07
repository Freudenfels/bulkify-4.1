# lager/lief_preisliste.php – EK-Preisliste (Referenz)

Route `?p=lief_preisliste`. Nachschlage-Liste der Rohstoff-Einkaufspreise aus v3
(`lieferant_preisliste`: rohstoff_name · lieferant · eur_kg · stand). Reine Referenz –
Freitextnamen, nur Rohstoffe. Suche über Rohstoff/Lieferant.

## Verlinkung + Anfrage
Einträge, deren **normierter Name** einem v4-Rohstoff (`item`, kategorie rohstoff)
entspricht, sind **anklickbar** (öffnen den Rohstoff mit allen Infos + Lieferantenpreisen)
und haben einen **„anfragen"**-Knopf, der das Preisanfrage-Popup (`anfrage_modal`) für
genau diesen Rohstoff öffnet – Angebot bei weiteren Lieferanten einholen (inkl.
Standard-Incoterm/Versandart). Nicht zuordenbare v3-Freitextnamen bekommen **„im Lager
suchen"** (führt in die Rohstoff-Suche).

Nur ~30 von 624 Namen matchen exakt (v3-Freitext mit Mengen/Verpackung im Namen); der
Rest ist über die Suche erreichbar.

# leiste/finden.php – Finden (einfach, handyfreundlich)

Ein großes Suchfeld: **tippen ODER sprechen** (Web Speech API, Knopf „Sprechen"). Treffer erscheinen
**live** beim Tippen (entprellt, über `?p=suche` JSON). Jeder Treffer ist eine große Kachel
(Name groß, darunter Charge · Menge · Ort/Blinker). **Antippen löst sofort das Klingeln aus**
(`data-klingeln` → `?p=klingeln` via assets/lager.js, grün 40 s). Treffer ohne Blinker werden grau
„kein Blinker" gezeigt.

Bewusst reduziert: keine Tabelle, kein Binden/Lösen hier – Blinker werden beim **Einbuchen** vergeben,
Verwalten geht auf der **Charge-Seite** ([../bestand/charge.md](../bestand/charge.md)). Suche über
`erp_chargen_suche` (eigener Bestand / Lager 1); Auflösung Blinker/Kiste über `blinker_fuer_charge`.
Lager 2 hat sein eigenes Finden ([../bestand/l2_finden.md](../bestand/l2_finden.md)).

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

Scannt/tippt man einen **8-stelligen Inventaraufkleber** einer Etikettenrolle, liefert `?p=suche` einen Treffer
mit `href` – der wird als Link gerendert und öffnet die Rolle (`?p=gebinde&inv=<nr>`) statt einen Blinker klingeln zu lassen.

## Eine Suche für Lager 1 und Lager 2 (08.11.2026)
Die Suche findet Ware in **Lager 1 und Lager 2** (Artikel, Charge, Barcode-Scan, Blinker-Code wie bisher). Jeder Treffer zeigt, wo die Ware liegt: „Lager 1“ bzw. „Lager 2 · Kunde“. Reiter **Alle / Lager 1 / Lager 2** filtern die Treffer (im Browser, ohne neue Suche); `?lager=2` öffnet direkt den Reiter Lager 2. Blinker für Lager-2-Ware blinkt blau, Lager 1 grün. Der Weg zum Blinker bleibt unverändert.

**Knopf „Infos“ (Nico 13.11.2026):** Jeder Treffer hat rechts den Knopf „Infos“ zur Detailseite der Charge (`?p=charge&id=`: Artikel, Warenart, Charge, MHD, Lieferant, Bestand, Produkt). Im Treffer steht zusätzlich, was es ist (z. B. „Rohstoff · R-2694“). Antippen des Treffers selbst lässt weiter den Blinker blinken.

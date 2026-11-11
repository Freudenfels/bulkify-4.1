# Inventur auswerten (`?p=inventur_auswerten&id=`, nur Admin)

Seit 11.11.2026. Die Seite zeigt alle Abweichungen. Offene stehen zuerst, sortiert nach Wert, der größte oben. Reiter je Art filtern die Liste.

Je Zeile:
- Artikel, Charge/Gebinde
- bei Klärfall: Inventarnummer, abgetippte Charge, MHD, Foto
- wie gezählt und von wem
- Soll, Ist, Differenz (%), Wert in €
- gebuchter und gezählter Ort

Entscheidung: Ursache wählen (nur die passenden). Je nach Ursache kommen Felder dazu:
- Einheitenfehler: Faktor und Einheit
- Doppelbuchung: die andere Charge
- Dublette: der richtige Artikel; gesperrte Artikel sind ausgegraut, mit Grund

Mit „Buchen“ wird die Entscheidung gebucht.

Darüber:
- Zähler: offen, entschieden, passt
- Knopf „Kleine Differenzen übernehmen“ mit Bestätigungsliste

Logik: `lager/core/inventur_buchen.php`.

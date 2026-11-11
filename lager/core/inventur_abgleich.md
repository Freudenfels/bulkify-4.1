# lager/core/inventur_abgleich.php – Abgleich Soll/Ist

Seit 11.11.2026. Sichtbar nur für den Admin. Der Zähler sieht Differenzen nie.

- **Wann:**
  - Bei „Platz fertig“ (`inv_abgleich_bereich`) entsteht je Scan eine Zeile in `lg_inventur_diff`.
  - Bei „Zählung beenden“ (`inv_zaehlung_beenden`) kommt „nicht gefunden“ dazu: alles, was im Umfang gebucht ist, aber nirgends gescannt wurde. Beim ganzen Lager gilt das für alles, bei einzelnen Plätzen nur für die Ware auf diesen Plätzen. Beenden geht nur, wenn alle Plätze fertig sind.
- **Soll** = Buchbestand **zum Scan-Zeitpunkt** (eingefroren am Scan). Eine Entnahme nach dem Scan ändert die Zählung nicht.
- **Arten (`inv_diff_bewerten`):**
  - passt: innerhalb der Toleranz (gewogen `tol_gewogen` %, Stück `tol_stk` %)
  - Menge weicht ab
  - nicht gefunden
  - gefunden ohne Buchung: Klärfall oder Gebinde ohne Bestand
  - falscher Platz: gebucht an anderem Platz/Kiste, eigene Zeile
  - Einheit verdächtig: Soll in anderer Einheit als der Artikel oder Faktor ~1.000; der Wert in € bleibt dann leer
  - Gebinde leer
- **Wert** = Differenz × EK (`erp_inv_wert`).
- **Nachzählen:** Liegt die Abweichung über `nachzaehl_prozent` (nur Menge/Einheit, Durchgang 1), steht die Zeile auf `nachzaehlen`.
  - Die Aufgabe erscheint beim Zählen für eine **zweite Person**.
  - Deren Wert gilt (`inv_nachzaehlung_uebernehmen`). Das Soll bleibt das vom ersten Scan, eine weitere Nachzählung gibt es nicht.
- **Neu rechnen:** Ein Platz kann nach „wieder öffnen“ erneut abgeglichen werden. Entschiedene Zeilen (gebucht / bewusst offen) bleiben dabei unverändert.

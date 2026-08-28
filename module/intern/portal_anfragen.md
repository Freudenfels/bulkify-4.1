# intern/portal_anfragen.php – Portal-Anfragen (intern)

**Zweck:** Eingangsliste aller Kundenanfragen aus dem Portal – **Produkt / Rohstoff / Dienstleistung** (die Rezepturanfrage läuft separat über `anfrage`). Rollen sales/production/einkauf.

**Anzeige:** Filter nach Typ; Tabelle Nr. · Kunde · Typ · Anfrage · Status. Bei Produktanfragen wird der Text aus Produkt + Stück/Packung + Verpackung + Menge gebaut, sonst aus Betreff/Notiz. Neue Anfragen stehen oben.

**Aktion:** Die ganze Zeile ist anklickbar (auch die Nr. und der „öffnen"-Button) und führt zur Detailseite `?p=portal_anfrage&id=…` (`portal_anfrage_detail.php`). Dort sieht man den genauen Wunsch und kann **Preise zurücksenden** (Angebot abgeben). Der Status-Badge zeigt „Angebot abgegeben", sobald ein Angebot draußen ist.

Hinweis: Der frühere Inline-Status-Dropdown in der Liste ist entfallen – Status wird jetzt auf der Detailseite gesetzt (der POST-Handler `aktion=status` bleibt zur Sicherheit erhalten).

**Quelle:** Tabelle `portal_anfrage` (befüllt vom Kundenportal). Nummernkreis PAF-.

# core/weg_festlegen.php – Weg festlegen (eine Oberfläche)

**Stand 10.11.2026 (Einkauf gerade ziehen, Teil 6).** Der Weg eines Auftrags wird an **einer** Stelle festgelegt. Der Reiter Beschaffungsweg am Auftrag und das Pop-up „Weg festlegen“ nutzen dieselbe Funktion, es gibt keinen Nachbau.

- `beschaffungsweg_karten($auftrag_id, $zurueck, $popup)`: die Karten mit Kosten für genau diesen Auftrag, günstigstem Lieferanten und Restmenge:
  1. **Haben wir schon im Lager**: bereits zugeordnet, frei, unterwegs, Rest. Dazu passende freie Bulk-/Premix-Chargen mit Kapselgröße und dem Hinweis „andere Kapselgröße“, Knopf „Im Lager suchen und zuordnen“ (Reiter Material, Suche über den ganzen Bestand).
  2. **Zukauf fertige Kapsel/Tablette** (bei Pulver: fertige Mischung)
  3. **Premix** (fertige Mischung je kg + Leerkapseln)
  4. **Einzelrohstoffe** (Eigenproduktion)

  Knöpfe: „Diesen Weg festlegen“, „Bestellen“ / „Direkt bestellt“, „An Bedarfsliste senden“. Die Formulare posten an `?p=auftrag&id=…`. Mit `$zurueck` (internes Ziel) geht es danach auf die aufrufende Seite zurück, mit `bwok` bzw. `bwfehler`. Im Pop-up öffnen alle Links in einem neuen Tab.
- `weg_festlegen_kopf($auftrag_id)`: Kopf des Pop-ups.
  - Auftrag, Angebot, Kunde und Produkt als Links (neuer Tab).
  - Menge, Kapselgröße/Füllung, Glas, „Letzte Festlegung“ desselben Produkts.
  - Die **Rezeptur** für diesen Auftrag: je Rohstoff mg je Einheit, Menge für den Auftrag (kg), frei, unterwegs, Rest (`material_deckung()`), Leerkapseln in Stk, Link „Rezeptur öffnen“.
- `weg_festlegen_dialog()`: Dialog + `bxWegFestlegen(auftragId)` (lädt `?p=weg_festlegen&auftrag=…&zurueck=…`).

**Eingesetzt:** Reiter Beschaffungsweg (Auftrag), Bestellen-Seite (Block „warten auf Festlegung des Wegs“), „Beschaffungsweg offen“, PA-Seite (vor Produktionsbeginn), alte Seite `?p=bedarf`. Später kommt „Zu erledigen“ dazu.

- Karte Premix ohne Preis: „Premix anfragen“ öffnet direkt die Premix-Anfrage auf der Rezepturseite (`&premix_anfrage=1`). Karte Zukauf fertig: „Angebot anfragen“ (`&fertig_anfrage=1`).

## Abgelaufene Preise (11.11.2026)
Einzelrohstoffe mit nur abgelaufenem Preis zeigen „Preis abgelaufen seit … - neu anfragen“ (Link zum Reiter Einkauf des Rohstoffs). Die Summe zeigt dann „(unsicher: Preis abgelaufen)“.

## Paket 12.11.2026 Teil 4 (Unterlagen-Regel)
Kennzeichen „Unterlagen fehlen“ an Lieferantenpreisen für Bulk/Premix.

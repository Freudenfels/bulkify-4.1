# core/bestellung_doppelt.php – Warnung bei Doppelbestellung

Sofortfix 11.11.2026 (Fable-Prüfung 3.6, Zusatzfrage B). Live waren am 09.11. drei doppelte Bestellungen entstanden (z. B. AB-3227 je 300.000 in BE-2890 und BE-2933), weil kein Bestellweg gewarnt hat.

**Was passiert:** Bevor eine Bestellung angelegt wird, schaut das System nach offenen Bestellungen für denselben Artikel (bei Bulk/Premix: denselben Auftrag bzw. dasselbe Produkt). Gibt es eine, kommt ein Pop-up „Es gibt schon eine offene Bestellung“ mit Link, Menge und offener Menge. Weiter nur mit „Trotzdem bestellen“, das steht dann im Verlauf der neuen Bestellung. Keine harte Sperre.

**Offen heißt:** Position mit Menge, Rest nicht storniert, weniger als 98 % geliefert, Bestellung Entwurf/gesendet/bestellt – oder eingegangen, aber erst teilweise (an der Bestellung hängt schon eine Charge). Alte „geliefert“-Bestellungen ohne verknüpfte Charge zählen nicht (das ist Altbestand, siehe Datenprüfung).

**Funktionen**
- `bestellung_doppelt_treffer($items, $bulkProdukte, $auftragId)` – offene Positionen.
- `bestellung_doppelt_stopp($treffer, $zurueck)` – merkt das Formular in der Sitzung, zurück zur Seite.
- `bestellung_doppelt_dialog()` – Pop-up (aus `core/layout.php` direkt nach `<main>`), schickt das gemerkte Formular mit `doppelt_ok=1` erneut.
- `bestellung_doppelt_vermerken($neueIds, $treffer)` – Verlaufseintrag „Trotz offener Bestellung BE-… angelegt – bestätigt von …“.

**Eingebaut in:** Einkaufsliste (Vorsorglich und Bestellen in allen drei Modi: Portal, Direkt, Online), Beschaffungsweg am Auftrag (`auftrag_beschaffung_bestellen`, legt bei Rest 0 serverseitig nichts an), Produktionsauftrag „Bedarf bestellen“.

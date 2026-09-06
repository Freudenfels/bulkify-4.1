# dublette.php – gibt es den schon?

## Wozu
Beim Erfassen soll auffallen, wenn dieselbe Firma bereits als Kontakt oder als Kunde im Dashboard steht. Sonst liegt derselbe Interessent nach drei Messen dreimal im System, und niemand weiß, welcher Eintrag der richtige ist.

## Bewusst ohne KI
Namen vergleichen ist Rechenarbeit, keine Denkarbeit. Das ist schneller, kostet nichts und ist nachvollziehbar.

## Wie verglichen wird
`dublette_kern()` macht Firmennamen vergleichbar: Kleinschreibung, Umlaute aufgelöst, **Rechtsform weg** (GmbH, AG, UG, KG, Ltd …), dann nur Buchstaben und Zahlen. Aus „Sportnahrung Weber GmbH & Co. KG“ wird `sportnahrungweber`.

Telefonnummern verlieren alles außer Ziffern, dazu führende Null und Ländervorwahl – `+49 170 1234567` und `0170 1234567` sind damit dieselbe Nummer.

Geprüft in dieser Reihenfolge: gleiche E-Mail, gleiche Telefonnummer, gleiche Firma, ähnliche Firma, gleicher Name.

## Warum der Grund angezeigt wird
Neben jedem Treffer steht, **warum** wir ihn für dieselbe Sache halten. Ohne Begründung wirkt so ein Hinweis wie Zauberei, und man klickt ihn weg, ohne hinzusehen.

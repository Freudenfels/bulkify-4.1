# sammelanfrage.php – einem Lieferanten alles auf einmal anfragen

## Wozu
Ein Lieferant ist neu dabei. Statt fünfzig Preisanfragen von Hand zu tippen, stellt man ihm mit einem Klick alles, was in unseren Rezepturen steckt – und zieht damit die Preise nach.

## Zwei Richtungen
| Auswahl | Für wen | Was entsteht |
|---|---|---|
| **Rohstoffe** | Rohstoffhändler | je Rohstoff aus einer Rezeptur eine Anfrage, Einheit aus dem Artikel (kg, L …) |
| **Rezepturen** | Lohnhersteller | je Rezeptur eine Anfrage als **Fertigprodukt**, Einheit aus der Darreichungsform (je Kapsel, je Tablette, kg …), `rezeptur_id` und Kapselgröße hängen dran |

Beides läuft über `lieferant_anfrage_stellen()` – also genau denselben Weg wie eine einzeln getippte Anfrage. Der Lieferant sieht keinen Unterschied.

## Keine Dubletten
Übersprungen wird alles, was bei **diesem** Lieferanten schon einmal angefragt wurde (`lieferant_anfrage` mit derselben `item_id` bzw. `rezeptur_id`) – unabhängig davon, ob er geantwortet hat. Ein zweiter Klick legt deshalb nichts doppelt an, sondern meldet „nichts Neues".

Berücksichtigt werden nur Rezepturen mit Status `freigegeben`, `eingefroren` oder `aktiv` – Entwürfe und Abgelehntes bleiben außen vor (`sammel_rezeptur_status()`).

## Bezugsmenge
Optional. Leer heißt: der Lieferant nennt Preis, Mindestmenge und Staffel selbst – für eine Erstanfrage meist das Ehrlichere. Wird eine Menge gesetzt, gilt sie für **alle** Anfragen des Laufs; die Einheit bleibt trotzdem je Position richtig.

## Wo
Reiter **Katalog** am Lieferanten (`module/lieferant/detail.php`), Kasten „Alles auf einmal anfragen". Vor dem Absenden steht die Zahl im Knopf, und unter „Was genau angefragt würde" stehen die Namen – man klickt also nicht blind.

## Grenzen
- Verpackung und Verbrauchsmaterial sind nicht dabei: die hängen am Produkt, nicht an der Rezeptur.
- Es geht kein Mailversand raus. Der Lieferant sieht die Anfragen in seinem Portal; wer mailen will, nutzt den Weg an der einzelnen Anfrage.

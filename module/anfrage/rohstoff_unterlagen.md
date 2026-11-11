# Rohstoff-Unterlagen (`?p=rohstoff_unterlagen`)

Große Übersicht über alle Rohstoffe, die gerade in einer Anfrage stecken. Menü: **Anfragen → Rohstoff-Unterlagen** (im Menü „Einfach“ direkt unter Anfragen). Rollen wie bei Anfragen: Vertrieb und Produktion; Admin darf alles.

## Woher die Rohstoffe kommen
- **Rezeptur-Anfragen** (Wunschzeilen mit zugeordnetem Rohstoff), solange nicht abgelehnt und die Rezeptur noch Entwurf oder Vorschlag ist
- **Rezepturen im Entwurf oder Vorschlag**, also Kapsel, Stick, Pulver usw. Die Form steht in der Spalte „Wo angefragt“
- **Rohstoff-Anfragen** aus dem Portal (`portal_anfrage.rohstoff_id`)
- **Produkt-Anfragen** aus dem Portal, und zwar die Zutaten der verknüpften Rezeptur

Leerkapseln und Premix-Artikel zählen nicht dazu.

## Spalten
- **Wo angefragt**: Links auf die Anfragen und Rezepturen, jeweils mit Form, Titel und Kunde (höchstens 4, danach „+n weitere“)
- **Preis**: vorhanden, wenn es einen Lieferantenpreis über 0 oder einen EK am Rohstoff gibt
- **Dokument**: Spezifikation, CoA oder Analyse des Lieferanten am Rohstoff. Die bulkify-Spezifikation zählt nicht
- **Wirkstoff / NRV**: die eingetragenen Wirkstoffgehalte. „NRV nachtragen“ erscheint, wenn der Name nach Mineralstoff oder Vitamin klingt (z. B. Citrat, Glycinat, Kalium, Zink, Vitamin) und keine Wirkstoffdaten da sind. Ohne diese Daten fehlen die elementaren Werte und die % NRV in Rezeptur, PIB und Kundenportal
- **Beim Lieferanten**: Stand der Lieferanten-Anfragen (angefragt, beantwortet, Anzahl, Datum)

## Bedienung
- Reiter: Etwas fehlt (Standard), Dokument fehlt, Preis fehlt, NRV nachtragen, Vollständig, Alle. Dazu eine Suche über Rohstoff, Anfrage, Rezeptur und Kunde
- Sortierung: Rohstoffe, denen am meisten fehlt, zuerst; danach die mit den meisten Vorgängen
- Rohstoffe anhaken und auf **„Ausgewählte beim Lieferanten anfragen“** klicken. Das öffnet das bekannte Anfrage-Fenster im Modus Preis; Spezifikation/CoA sind bei der Preisabgabe Pflicht

Die Seite ändert keine Daten. Die Kästen auf der Anfrage- und Rezepturseite (`core/rohstoff_unterlagen.php`) und die Prüfungen in der Datenprüfung bleiben unverändert.

## Ergänzung 10.11.2026
- Jede Zeile hat den Knopf **„Preis / Unterlage“**. Er öffnet das Fenster für Preis ab Menge und CoA/Spec-Upload; die KI füllt die Infos (siehe `rohstoff_erfassen.md`).
- Vorgeschlagene **Alternativen** zu Rohstoff-Anfragen zählen als Quelle mit („PAF-… · Alternative“), solange der Kunde noch nicht entschieden hat.

## Reiter „Aktuelle Anfragen“ (11.11.2026)
Nico wollte alle offenen Anfragen (damals 42) an einer Stelle sortiert haben, damit sich die Infos leicht hochladen lassen. Dafür gibt es den ersten Reiter `f=anfragen`.
- **Welche Anfragen:** dieselbe Regel wie die Zahl am Menü „Anfragen“ (`bx_anfrage_counts()` in core/layout.php):
  - Rezeptur-Anfragen ohne echten Vorschlag
  - Portal-Anfragen (Produkt, Rohstoff, Dienstleistung) ohne sichtbares, freigegebenes Angebot
  - jeweils nicht abgelehnt
- **Sortierung:** älteste zuerst, also wer am längsten wartet.
- **Je Anfrage ein Block:**
  - Kopf: Nummer (Link), Art, Kunde, Titel bzw. Menge, Eingangsdatum und Badge „n mit Lücke“ bzw. „Unterlagen vollständig“.
  - Darunter die Rohstoffe mit Preis, Dokument, Wirkstoff/NRV und Stand beim Lieferanten. Der Knopf „Preis / Unterlage“ öffnet das bekannte Pop-up (`?p=rohstoff_erfassen`: Preis ab Menge, CoA/Spec, KI liest aus).
- **Woher die Rohstoffe kommen:**
  - Rezeptur-Anfrage: Wunschzeilen und die Zutaten einer verknüpften Rezeptur. Ist eine Wunschzeile ohne Zuordnung, steht dort „noch keinem Rohstoff zugeordnet“ mit Knopf „Zuordnen“.
  - Rohstoff-Anfrage: der angefragte Rohstoff und vorgeschlagene Alternativen.
  - Produkt-Anfrage: die Zutaten der Rezeptur bzw. der Produkt-Rezeptur.
- Leerkapseln und Premix werden hier nicht gezeigt, wie in der übrigen Liste.
- **Suche:** wirkt auch hier, auf Nummer, Kunde, Titel und Rohstoff.
- **Code:** Die Stand-Abfrage je Rohstoff ist jetzt die Funktion `$statusFuer()` und wird von beiden Ansichten genutzt.


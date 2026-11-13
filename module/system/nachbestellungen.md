# system/nachbestellungen.php – Eingang Portal-Nachbestellungen

Route `?p=nachbestellungen` (Rollen admin/sales/finance; Nav: Vertrieb · Nachbestellungen). Zeigt die vom Kunden im Portal **verbindlich** ausgelösten Nachbestellungen zur kurzen **Preisprüfung**.

## Inhalt
- **Zu prüfen** (`status` `pruefung`/`preis_neu`): Kunde, Produkt (+ Stück/Pkg), Menge, zuletzt vereinbarter Preis/Pkg, Gesamt netto.
  - **Preis bestätigen & freigeben** → `nachbestellung_bestaetigen()`: erzeugt Auftrag + Rechnung + Produktionsauftrag (über `auftrag_aus_angebot()`), übernimmt die Etikett-Freigabe aus dem Vorauftrag. Danach Link zum Auftrag.
  - **Preis anpassen** (aufklappbar) → `nachbestellung_preis_anpassen()`: neuer Preis/Pkg + optionaler Hinweis; geht als Freigabe an den Kunden (`preis_neu`). Danach erscheint die Zeile als „wartet auf Kundenfreigabe".
- **Zuletzt freigegeben**: die letzten bestätigten Nachbestellungen mit Link zum erzeugten Auftrag.

Logik liegt vollständig in [../../core/nachbestellung.php](../../core/nachbestellung.php); diese Seite ist nur die Team-Oberfläche. Kein eigener Buchungs-/Preis-Code hier.
- Kunde in Tabelle zeigt internes Kürzel (kunde_link/kunde_anzeige), 08.11.2026

## Ohne Preis (09.11.2026)

Steht der Preis auf 0 (Vorauftrag ohne Preis), zeigt die Zeile „fehlt“ und darunter den Kundenpreis-Vorschlag. Knopf **„Kundenpreis x € übernehmen & freigeben“** erzeugt den Auftrag zu diesem Preis. Gibt es keinen Kundenpreis, ist „Preis anpassen“ offen (geht zur Freigabe an den Kunden) und ein Link führt zu den Kundenpreisen. „Preis bestätigen & freigeben“ gibt es nur mit Preis.

## Entscheidungsgrundlage je Nachbestellung (09.11.2026)
Unter jeder offenen Nachbestellung steht ein Info-Block, damit der Preis ohne Suchen entschieden werden kann:
- **Konfiguration:** Packungsgröße, Behälter/Deckel/Etikett des Vorauftrags (je mit Link zur Verpackung), Vorauftrag (Link), ob die Etikett-Freigabe übernommen wird. Das Produkt in der Zeile ist ein Link zur Produktseite.
- **Preis-Herkunft je Pkg:** Preise aus früheren Angeboten (Staffel), Preis des Vorauftrags, zuletzt berechnet laut Rechnung, Kundenpreis (Herstellung + Verpackung), EK ca. (Füllung + Verpackung wie in der Preismatrix) mit Marge; Marge unter dem Mindestwert rot.
- **Frühere Aufträge:** die letzten 6 Aufträge des Kunden für dieses Produkt mit Menge und Preis/Pkg; Packungsgröße/Behälter nur, wenn sie abweichen.
Daten kommen aus `nachbestellung_entscheidung()` (core/nachbestellung.php), nur lesend.

**Zahlen-Lesart (13.11.2026, Fable-3 T2.9):** Betragsfelder lesen mit `betrag_lesen()`, Preise/Prozente/Kurse mit `zahl_lesen()`, Mengen mit `zahl_lesen(…, true)` – alle aus core/zahl_lesen.php (eine Regel mit dem Dashboard). „21.687,75“, „21687,75“ und „21687.75“ ergeben 21687,75.

**Vorbelegung ohne Tausenderpunkt (Fable-4 T2.4, 13.11.2026):** Eingabefelder mit `zahl_feld()` vorbelegt (Komma, kein Tausenderpunkt).

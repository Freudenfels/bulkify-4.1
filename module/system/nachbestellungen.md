# system/nachbestellungen.php – Eingang Portal-Nachbestellungen

Route `?p=nachbestellungen` (Rollen admin/sales/finance; Nav: Vertrieb · Nachbestellungen). Zeigt die vom Kunden im Portal **verbindlich** ausgelösten Nachbestellungen zur kurzen **Preisprüfung**.

## Inhalt
- **Zu prüfen** (`status` `pruefung`/`preis_neu`): Kunde, Produkt (+ Stück/Pkg), Menge, zuletzt vereinbarter Preis/Pkg, Gesamt netto.
  - **Preis bestätigen & freigeben** → `nachbestellung_bestaetigen()`: erzeugt Auftrag + Rechnung + Produktionsauftrag (über `auftrag_aus_angebot()`), übernimmt die Etikett-Freigabe aus dem Vorauftrag. Danach Link zum Auftrag.
  - **Preis anpassen** (aufklappbar) → `nachbestellung_preis_anpassen()`: neuer Preis/Pkg + optionaler Hinweis; geht als Freigabe an den Kunden (`preis_neu`). Danach erscheint die Zeile als „wartet auf Kundenfreigabe".
- **Zuletzt freigegeben**: die letzten bestätigten Nachbestellungen mit Link zum erzeugten Auftrag.

Logik liegt vollständig in [../../core/nachbestellung.php](../../core/nachbestellung.php); diese Seite ist nur die Team-Oberfläche. Kein eigener Buchungs-/Preis-Code hier.

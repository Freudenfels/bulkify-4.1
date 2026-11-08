# anfrage/liste.php – Anfragen (eine Liste für alle Arten)

**Zweck:** EIN Eingangsfach für alle Anfragen (Soll-Handbuch Abschnitt 3, Schritt 2 vom 08.11.2026). Vorher gab es vier Menüpunkte
(Rezeptur-, Produkt-, Rohstoff-, Dienstleistungsanfragen) auf zwei Seiten.

**Quellen:**
- **Rezepturanfragen** (`rezeptur_anfrage`) – Detail `?p=anfrage&id=…`.
- **Portal-Anfragen** Produkt / Rohstoff / Dienstleistung (`portal_anfrage`) – Detail `?p=portal_anfrage&id=…`. Erscheinen nur, wenn der
  Nutzer `?p=portal_anfragen` sehen darf (Rechte unverändert).

**Bedienung:**
- **Art-Reiter** (`?art=`): Alle Arten · Rezeptur · Produkt · Rohstoff · Dienstleistung, mit Zähler (innerhalb der gewählten Ansicht).
  Alte Links mit `?typ=produkt` usw. werden verstanden.
- **Ansicht-Reiter** (`?ansicht=`): Offen (Standard) · Beantwortet · Abgelehnt · Alle, mit Zähler (innerhalb der gewählten Art).
- **Suche** über Nummer, Kunde und Anfragetext – über alle Status.
- Spalten: **Nummer** (mit Markierung „neu") · **Art** · **Kunde** (internes Kürzel über `kunde_link`) · **Anfrage** · **Status** · **Angefragt**.
  Sortierbar über die Spaltenköpfe; „überarbeiten" und neue Anfragen stehen immer oben. Klick öffnet die jeweilige Detailseite.

**Offen / Beantwortet (wie bisher je Seite):**
- Rezeptur: beantwortet = verknüpfte Rezeptur ist `vorschlag`/`freigegeben`/`eingefroren` und die Anfrage nicht `ueberarbeiten`
  (der v3-Import hat Anfragen pauschal auf `beantwortet` gesetzt – deshalb zählt der echte Rezeptur-Status).
- Portal: beantwortet = es gibt ein sichtbares, preislich freigegebenes Angebot beim Kunden (wie im Kundenportal und im Menü-Zähler).
- Abgelehnt = Status `abgelehnt` (Archiv). Alles andere ist offen.
- „neu" = Status `neu` und noch offen.

**Menü:** Punkt „Anfragen" (`core/layout.php`). Zähler = offene Anfragen aller Arten, die der Nutzer sehen darf. Wer nur Portal-Anfragen
sehen darf (Einkauf), wird vom Menüpunkt auf `?p=portal_anfragen` geführt. Die Seite `?p=portal_anfragen` bleibt erreichbar.

# anfrage/liste.php – Rezepturanfragen (Liste)

**Zweck:** Übersicht aller Rezepturanfragen (Kundenwünsche, die wir prüfen und in Rezepturen übersetzen).

**Aufbau wie die Portal-Anfragen** (Eingangsfach mit Archiv): **Ansicht-Tabs** (`.settabs`, `?ansicht=`) mit Zählern – **Offen** (Standard) · **Beantwortet** · **Abgelehnt** · **Alle**.

**Bucket aus Anfrage + Rezeptur (wichtig):** Die Einordnung (`$bucketOf`) richtet sich NICHT nur nach `rezeptur_anfrage.status`, sondern auch nach dem Status der verknüpften Rezeptur. Hintergrund: der v3-Import hat viele Anfragen pauschal auf `beantwortet` gesetzt, obwohl gar kein Vorschlag draußen ist – die wären im Arbeitsfach „Offen" sonst unsichtbar, während der Kunde sie als „in Prüfung" sieht.
- **Beantwortet** = es liegt WIRKLICH ein Vorschlag beim Kunden (verknüpfte Rezeptur `vorschlag`/`freigegeben`/`eingefroren`) und die Anfrage ist nicht `ueberarbeiten`.
- **Abgelehnt** = `rezeptur_anfrage.status='abgelehnt'` (Archiv).
- **Offen** = alles andere: kein Vorschlag, nur `entwurf`, `neu`/`in_bearbeitung`, oder `ueberarbeiten` (Kunde hat abgelehnt → zuoberst). Das ist das Arbeitsfach „wir müssen ran" und deckt sich mit der Kundensicht „in Prüfung".

Der **Status-Badge** wird ebenfalls aus beiden Status abgeleitet (z. B. „offen – kein Vorschlag", „Entwurf – nicht gesendet", „Vorschlag gesendet", „angenommen").

**Was passiert hier:**
1. `seed_anfrage_if_empty()` – legt lokal eine Demo-Anfrage an.
2. Liest alle Anfragen inkl. Kunde, Anzahl Wünsche und – falls schon bearbeitet – die erzeugte Rezeptur-Nummer. Zähler je Ansicht werden einmal über alle Zeilen gebildet.
3. **Suche** (Nummer, Kunde, Wunsch-Produkt, Rezeptur) sucht über **alle** Status (die Ansicht zählt dann nicht). Ohne Suche wird nach Ansicht gefiltert. **Sortierung** über die Spaltenköpfe (`bx_table`), Standard = neueste zuerst; „überarbeiten"-Anfragen stehen immer zuoberst.
4. Tabelle: **Nummer · Rezeptur/Wunsch-Produkt · Kunde · Form · Wünsche · Rezeptur · Status · Angefragt**.
   - Klick öffnet die Anfrage-Bearbeitung (`?p=anfrage&id=...`).
5. Button „Neue Anfrage".

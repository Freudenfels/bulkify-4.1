# auftrag/liste.php – Auftrags-Liste

**Zweck:** Übersicht aller Auftragsbestätigungen (AB-). Aufträge entstehen **automatisch** aus bestätigten Angeboten – hier legt man sie nicht von Hand an.

**Was passiert hier:**
- Liest alle Aufträge inkl. Kunde, Produkt (Joins) und der zugehörigen Rechnungsnummer (Unterabfrage).
- **Reiter Offen / Wartet auf Freigabe / Abgeschlossen / Storniert** (`?tab=`): „Abgeschlossen" = Status `versendet`, „Storniert" = Status `storniert`, „Offen" = alles andere (offen, in Produktion, versandbereit) – stornierte sind also **nicht mehr** unter „Offen". Die Reiter „Wartet auf Freigabe" und „Storniert" erscheinen nur, wenn es solche Aufträge gibt. Die Zahl je Reiter zählt den ganzen Bestand; Suche + Sortierung wirken innerhalb des aktiven Reiters. Standard-Reiter = Offen (deckt sich mit dem Menü-Badge „noch nicht fertig").
- **Reiter „Wartet auf Freigabe"** (Block I2, `?tab=freigabe`): zeigt die Aufträge, die einen Produktionsauftrag im Status `vorbereitung` haben (`$wartetIds` aus `SELECT DISTINCT auftrag_id FROM produktionsauftrag WHERE status='vorbereitung'`, `art_festgelegt_am IS NULL`). Das sind genau die Aufträge, die früher auf der eigenen **Vor-Produktions-Seite** hingen. Klick führt direkt auf die Auftragsseite, wo **Quelle festlegen** (Eigen/Fremd/Lager) + **Zur Produktion freigeben** warten. Teilmenge von „Offen". Ersetzt die aus der Navigation entfernte Vor-Produktions-Liste (die Per-PA-Seite `?p=produktion_vorbereitung&id=` für Glas/Verpackung bleibt).
- **Suche** nach Nummer, Kunde, Produkt. **Sortierung** Standard = neueste zuerst. **Jede** Spalte ist per Klick auf den Kopf sortierbar (Pfeil zeigt die Richtung): Nummer, Kunde, Produkt, Art, Menge, Netto, Rechnung, Fortschritt, Bestellt am, Dauer/Stand. Für die berechneten Spalten **Art** und **Dauer** werden vor dem Sortieren Sortierwerte in die Zeilen geschrieben (`$artSort`-Rang bzw. Sekunden bis fertig/laufend); die Render-Closures lesen diese Keys nicht, daher kein Konflikt. `fertigMap`/`jetztUtc` werden dafür schon **vor** dem Sortieren berechnet.
- Tabelle: **Nummer · Kunde · Produkt · Art · Menge · Netto · Rechnung · Fortschritt · Bestellt am · Dauer / Stand**.
- **Art** (Erstauftrag/Neue Rezeptur/Nachbestellung aus `auftrag_art_meta()`): wird als **schlichter, grauer Text** gezeigt (nicht als farbige Kachel), damit die Spalte nicht vom Fortschritt ablenkt; der Stil aus `auftrag_art_meta()` wird hier bewusst ignoriert, der Erklärtext steht im Tooltip (2026-10-27).
- **Menge** zeigt bei laufenden Aufträgen zusätzlich den **Produktionsfortschritt** als kleine Zeile „X/Y produziert" (`auftrag_mengenfortschritt()` = gebuchte Fertigware je Produktionsauftrag) und bei **Teilversand** „X/Y versendet" (grün, `auftrag.versendet_menge`). Nur solange der Auftrag nicht komplett versendet/storniert ist – vollständig versendet/abgeschlossen zeigt der Fortschritt bzw. die Dauer-Spalte (2026-10-27).
- **Fortschritt** (früher „Status"): zeigt die **tatsächliche aktuelle Phase** statt des groben Sammelstatus – abgeleitet aus `kunde_auftrag_phase($r)` + `auftrag_phasen($ff)` (Bestätigt · Rohstoff bestellt · Rohstoff angekommen · In Produktion · Etikettiert · Qualitätsprüfung · Versandbereit/Bereit zur Einlagerung · Eingelagert · Versendet). Farbe je Stufe (`$phaseStil`): frühe/neutrale Stufen `info`, Produktionsstufen `warn`, fertig (eingelagert/versendet) `ok`, storniert `err`. `versendet` und `storniert` werden direkt gelabelt (keine Phasen-Berechnung); alle anderen Zeilen lösen die Phase live auf (je Zeile einige Abfragen auf bestellung/charge/produktion_schritt – vertretbar für den Offen-Bestand).
- **Bestellt am** = `auftrag.angelegt`. **Dauer / Stand** zeigt laufende Produktionen: ist die Produktion fertig (letzter erledigter `produktion_schritt.erledigt_at` eines erledigten `produktionsauftrag`, in einer Bulk-Abfrage je gelisteten Auftrag), steht die Dauer **bestellt → fertig** (Tooltip mit Fertig-Zeitpunkt); sonst das Alter seit Bestellung, bei versendet „abgeschlossen". Format seit 08.11.2026 **nur ganze Tage** über lokalen `$dauerFmt`: „heute" (unter 1 Tag; bei „bis fertig" „unter 1 Tag"), „1 Tag", „27 Tage" – ohne „läuft seit" und ohne Stunden. Sortiert wird nach der echten Dauer in Sekunden (`$rr['dauer']`), nicht nach dem Text. Die gesamte Kundenreise (Rezepturanfrage → fertig) steht im Durchlaufzeiten-Tracker (`?p=durchlaufzeiten`).
- Klick öffnet den Auftrag (`?p=auftrag&id=...`).

## Auftrags-Art je Kunde (Aufräumen 9)
„Nachbestellung" heißt: **derselbe Kunde** hat das Produkt schon bestellt. Hat nur ein anderer Kunde es bestellt, steht „Neues Produkt" (für diesen Kunden neu). Gleiche Regel in der Liste (`$erstProdKunde`) und am Auftrag (`auftrag_art()`).

Neben „Aufträge ohne Preis“: „Preise nachtragen“ -> `?p=kundenpreise&ohne=1`.

**Etikett-Status + Spaltenreihenfolge (03.11.2026):** Neue Spalte „Etikett“ als kleiner Punkt – rot = kein Design hochgeladen, gelb = hochgeladen (auch aus Vorbestellung), noch nicht freigegeben, grün = freigegeben; „–“ = braucht kein Etikett (z. B. Bulk) bzw. storniert. Gilt auch für das Beutel-Design (vollbedruckt), Tooltip nennt es. Sortierbar (rot zuerst). Spalte „Art“ steht jetzt ganz hinten.

Reiter „wartet auf Freigabe“: ohne Produktionsaufträge stornierter Aufträge (`pa_aktiv_sql()`, 03.11.2026).

Reiter „Beschaffungsweg offen“ statt „Wartet auf Freigabe“ (04.11.2026).

## Spalte Stand (08.11.2026)
Zeigt den aktuellen Schritt der neuen Fortschrittskette (`auftrag_fortschritt_stand()`): rot zuerst (z. B. „Etiketten Daten fehlen“), sonst laufend/teilweise, sonst zuletzt erledigt. DL-Aufträge wie bisher.

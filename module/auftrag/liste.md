# auftrag/liste.php – Auftrags-Liste

**Zweck:** Übersicht aller Auftragsbestätigungen (AB-). Aufträge entstehen **automatisch** aus bestätigten Angeboten – hier legt man sie nicht von Hand an.

**Was passiert hier:**
- Liest alle Aufträge inkl. Kunde, Produkt (Joins) und der zugehörigen Rechnungsnummer (Unterabfrage).
- **Reiter Offen / Abgeschlossen** (`?tab=`): „Abgeschlossen" = Status `versendet`, „Offen" = alles andere (offen, in Produktion, versandbereit). Die Zahl je Reiter zählt den ganzen Bestand; Suche + Sortierung wirken innerhalb des aktiven Reiters. Standard-Reiter = Offen (deckt sich mit dem Menü-Badge „noch nicht fertig").
- **Suche** nach Nummer, Kunde, Produkt. **Sortierung** Standard = neueste zuerst.
- Tabelle: **Nummer · Kunde · Produkt · Art · Menge · Netto · Rechnung · Status · Bestellt am · Dauer / Stand**.
- **Bestellt am** = `auftrag.angelegt`. **Dauer / Stand** zeigt laufende Produktionen: ist die Produktion fertig (letzter erledigter `produktion_schritt.erledigt_at` eines erledigten `produktionsauftrag`, in einer Bulk-Abfrage je gelisteten Auftrag), steht die Dauer **bestellt → fertig** (Tooltip mit Fertig-Zeitpunkt); sonst **„läuft seit …"** (Alter seit Bestellung), bei versendet „abgeschlossen". Format T/Std/Min über lokalen `$dauerFmt`. Die gesamte Kundenreise (Rezepturanfrage → fertig) steht im Durchlaufzeiten-Tracker (`?p=durchlaufzeiten`).
- Klick öffnet den Auftrag (`?p=auftrag&id=...`).

# lager/rohstoffe_ohne_papiere.php – Rohstoffe ohne Papiere

**Zweck:** Zeigt Rohstoffe aus **dem Kunden angebotenen** Rezepturen (`rezeptur.status IN ('vorschlag','freigegeben','eingefroren')`), für die **kein einziges Dokument** hinterlegt ist. Hintergrund: Der Kunde bekommt unsere ausgefüllten Infos (Spezifikation/Deklaration), nicht die Lieferanten-CoA – dafür müssen überhaupt erst Unterlagen da sein. Fehlen sie, hier direkt beim Lieferanten anfragen. Route: `?p=rohstoffe_papiere` (Menü Lager → „Rohstoffe ohne Papiere").

**„Papiere vorhanden"** = mindestens ein Eintrag in `dokument` (`objekt_typ='item'`, `objekt_id=item.id`, beliebiger `typ`). Fehlt jedes Dokument → der Rohstoff steht hier. (Bewusst locker gewählt: irgendein Dokument reicht.)

**Anzeige:**
- Tabelle der Rohstoffe ohne Unterlagen: Name (→ Rohstoff, Reiter Dokumente), Artikelnr., **verwendet in** (angebotene Rezepturen + Kunde), Status (bulk: `angefragt`, wenn eine offene `lieferant_anfrage` existiert – eine Abfrage für alle, nicht pro Zeile wegen Remote-DB-Last), und **„Beim Lieferanten anfragen"** (Popup `bxAnfrageOeffnen(item_id)` aus `core/anfrage_ui.php`, `anfrage_modal(lieferanten_fuer_anfrage('rohstoff'), …)`; CoA/Spez wird mitangefragt, `coa_gewuenscht=1`).
- **„Alle anfragen (N)"** (Button oben rechts im Panel): öffnet dasselbe Popup im **Bulk-Modus** (`bxAnfrageBulkOeffnen([item_ids], label)`, CoA/Spezifikation im Fokus). Beim Absenden stellt `preis_anfragen.php` für **jeden** gelisteten Rohstoff an **jeden gewählten Lieferanten** (Mehrfachauswahl im Popup) eine Anfrage – also m Rohstoffe × n Lieferanten. Rückmeldung `&angefragt=N` wie gehabt.
- Zweite Tabelle **„Zutaten ohne Rohstoff-Zuordnung"**: Freitext-Zutaten (`rezeptur_zutat.item_id IS NULL`) in angebotenen Rezepturen – erst im Rezeptur-Editor einem Rohstoff zuordnen, dann lassen sich Papiere erfassen/anfragen.

**Verwandt:** `module/lager/rohstoffe_liste.php` (Lücken-Filter über ALLE Rohstoffe), `module/lager/freigaben.php` (Freigabe der bulkify-Spec/CoA an Kunden – anderes Thema), `module/rezeptur/detail.php` (Rohstoffpreise-Panel je Rezeptur).

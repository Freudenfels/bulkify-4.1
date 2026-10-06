# einkauf/einkaufsliste.php – Bedarf (zentrale Einkaufsseite)

**Stand 2026-10-05:** Dies ist jetzt DIE eine Bedarfs-/Bestellseite (Menü „Bedarf"). Zeigt **alle** offenen Bedarfe (`bedarf_aggregiert(false)` / `bedarf_bulk(false)`) – kein „Melden"-Schritt mehr. Route `?p=einkaufsliste` (Rolle einkauf, admin). Die frühere „Einkaufsbedarf"-Seite (`?p=bedarf`, Melden + Eigen/Fremd) ist aus dem Menü entfernt; Eigen/Fremd wird im Produktionsauftrag entschieden.

Zwei Aktionen auf derselben Auswahl: **„Beim Lieferanten bestellen"** (je Lieferant eine Bestellung, wie bisher) ODER **„Habe ich extern bestellt"** (`modus=extern`): legt EINE Bestellung ohne Lieferant an (Notiz „Extern bestellt (z. B. Amazon)", Datum = heute). In beiden Fällen nettet die Position aus dem Bedarf (via `bestellt`-Summe) und erscheint unter „Bestellt" (`?p=einkauf`).

**Typ-Reiter** (`.settabs`, `?typ=`): Alle · Etiketten · Verpackung · Rohstoffe · Fertige Produkte · **Nachbestellung** · Betriebsmittel-Kategorien. Tabelle mit Auswahl-Checkbox je Artikel + Σ benötigt / auf Lager / offen bestellt / zu bestellen / Aufträge. Quelle `bedarf_aggregiert(true)` (nur gemeldet + Eigenproduktion).

**Etikett-Sperre:** Etiketten-Positionen sind erst bestellbar, wenn der Kunde das Etikett des Auftrags **freigegeben** hat (`etikett_ok = etikett_freigegeben($auftrag_id)`). Ohne Freigabe: Schloss-Symbol + Badge „wartet auf Kunden-Freigabe", keine Auswahl-Checkbox.

**Nachbestellung (Meldebestand):** `meldebestand_bedarf()` listet Lagerartikel, deren **freier Bestand + offen Bestelltes** unter den gepflegten **Meldebestand** (`item.mindestbestand`) gefallen ist. Zeilen-Key `nach:<item_id>`, editierbare Menge (Vorschlag = Meldebestand − (Lager+offen)), Lieferant vorbelegt mit Hauptlieferant. Beim Bestellen als normale **Lagerposition ohne Auftragsbezug** (`bestellung_erstellen`, `auftrag_id` NULL). Von Mitarbeitern gemeldete freie Artikel (`freibedarf`, u. a. über `?p=bedarf`) erscheinen im passenden Typ-Reiter und zeigen „gemeldet von …".

**Bestellen:** Artikel auswählen, **Lieferant + Bestelldatum** wählen → `bestellung_aus_positionen($mengen,$lieferant,$datum)`: EINE gebündelte Bestellung (mit Datum = Status „bestellt", ohne = Entwurf), in jeden betroffenen Auftrag wird der Bestellvermerk geschrieben (`log_aktivitaet`).

**Menge anheben:** Die Spalte „zu bestellen" ist je Zeile ein **Eingabefeld** (`menge[<key>]`), vorbelegt mit dem Bedarf – der Einkauf darf **mehr** bestellen als gebraucht (z. B. 1000 benötigt → 2000 auf Lager). Darunter steht der reine Bedarf als Referenz. Parsing deutsch (Punkt = Tausender, Komma = Dezimal), leer/0 = Bedarf. Bei **Rohstoff/Verpackung** ersetzt der Wert die Positionsmenge direkt; bei **Bulk (Fertigprodukt)** bleibt die auftragsgebundene Aufteilung erhalten und der Überschuss (Wunsch − Bedarf) kommt als auftragsloser Posten „Bulk: … (Puffer/Lager)" dazu (`bestellung_erstellen(..., $bulkMenge)`). Gesperrte Etikett-Zeilen (fehlendes Design) bleiben nicht editierbar.

**Fremdproduktion = Reiter „Fertige Produkte":** der Bulk-Zukauf je Fremd-Auftrag (`bedarf_bulk()`) erscheint IM Reiter „Fertige Produkte" (keine eigene Sektion), auswählbar + bestellbar über `bestellung_bulk_anlegen()` (Freitext-Position item_id NULL + `bezeichnung`, auftrag_id gesetzt, Vermerk im Auftrag). Verpackung/Etiketten der Fremd-Aufträge laufen in den normalen Reitern. Melden zeigt eine Bestätigung.

**Ablauf:** Einkaufsbedarf (melden, eigen/fremd) → **Einkaufsliste** (hier bestellen) → Bestellungen (`?p=einkauf`, nur Historie).
## E-Mail an den Lieferanten
Wird mit Bestelldatum bestellt, entsteht die Bestellung direkt als „bestellt" – dann geht je Lieferant die Bestell-Mail raus (`mail_lieferant_bestellung()`), falls der Versand eingerichtet ist.

## Vorsorglich bestellen (vorhandener Artikel)
Block unter „Neuen Bedarf eintragen": einen **bereits vorhandenen** Lagerartikel (item kategorie rohstoff/verpackung/verbrauch/fertig) auf Vorrat bestellen – **ohne aktuellen Bedarf und ohne neuen Namen**. Aktion `vorsorglich` legt via `bestellung_erstellen([{item_id,menge,auftrag_id:0}], …, datum=heute)` eine sofort als „bestellt" markierte Bestellung ohne Auftragsbezug an (Notiz „Vorsorglich …") und leitet auf `?p=einkauf&vorsorglich=1` (Bestellt-Liste), damit man sieht, dass sie gelandet ist. Zwei Buttons: „Beim Lieferanten bestellen" (modus=lieferant, optional Lieferant + Mail) / „Habe ich extern bestellt" (modus=extern, ohne Lieferant).

## Dark Mode
Das Hinweis-Panel „… warten auf die Festlegung" nutzt `border-color/border-left:var(--warn)` statt eines harten hellen Hintergrunds (`#fff8f7`) – sonst heller Text auf hellem Grund im Dark Mode.

## Zugang-Regel beim Bestellen (2026-10-06)
Titel ist jetzt **„Bestellen"**. Beim Bestellen entscheidet der Lieferanten-**Portal-Zugang** (`lieferant_hat_zugang`, aktiver `benutzer.lieferant_id`): Lieferant **mit Zugang** → Bestellung geht in seinen Account, Status `gesendet` + Benachrichtigung → wartet auf seine Bestätigung. Lieferant **ohne Zugang** oder „extern" → nur erfasst (Status `bestellt`). Dropdowns markieren jeden Lieferanten mit „· Portal" bzw. „· extern".

# beleg/rechnung_neu.php – Rechnung aus Auftrag (Vorschau + Erstellen)

Erstellt eine Rechnung aus einem **bestehenden Auftrag** – mit **Vorschau und Eingaben VOR** dem Anlegen. Route `?p=rechnung_neu&auftrag=<id>` (Rolle `finance`/Admin). Aufgerufen über den Knopf **„Rechnung erstellen"** in der Auftrags-Detailzeile „Rechnung" (nur wenn es noch keine gibt).

**GET (Vorschau):** zeigt Kunde, Auftrag, die eine Positionszeile (Produkt × Menge × VK = Netto) und die Summen. Eingabefelder mit sinnvollen Vorgaben:
- **Rechnungsdatum** (heute), **Leistungsdatum** (heute),
- **Zahlungsziel (Tage)** – Vorgabe aus `kunden.zahlungsziel_tage`, sonst 14; daraus wird live die **Fälligkeit** berechnet,
- **USt-Satz (%)** – Vorgabe automatisch (Kleinunternehmer/EU-Ausland 0 %, sonst `ust_inland`), überschreibbar (z. B. 7 %, Reverse-Charge 0 %),
- **Rechnungstext/Hinweis** (optional).
Ein kleines JS rechnet USt/Brutto/Fälligkeit live mit.

**POST (`aktion=erstellen`):** ruft `rechnung_aus_auftrag($auftrag_id, $opt)` (in `core/schema.php`) mit den Eingaben auf und springt zur fertigen Rechnung (`?p=rechnung&id=…&erstellt=1`). Idempotent: existiert schon eine nicht stornierte Rechnung zum Auftrag, wird direkt dorthin geleitet (kein Duplikat). Ohne Preis am Auftrag: Hinweis, kein Beleg.

**Gespeichert** werden am Beleg zusätzlich: `datum`, `zahlungsziel_tage`, `faellig`, `leistung_datum`, `text` (neue Spalten) + eine `beleg_position` (passt die Zeilensumme nicht exakt zum Netto, z. B. Sub-Cent-Preise, wird eine Pauschal-Zeile Menge 1 = Netto gesetzt). Anzeige dieser Felder in `beleg/detail.php`.

Hinweis: Regel-Rechnungen entstehen weiterhin automatisch mit dem Auftrag (`auftrag_aus_angebot`); diese Seite ist für Auftraege **ohne** automatische Rechnung (v3-Importe, Hand-Aufträge) bzw. wenn man Datum/Zahlungsziel bewusst setzen will.

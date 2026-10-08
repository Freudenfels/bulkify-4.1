# system/angebot_import.php – Angebots-Import (KI)

**Zweck:** Ein altes/fremdes **Angebots-PDF** per KI einlesen und entweder einem **bestehenden Angebot zuordnen** (anreichern) ODER ein **neues Angebot (Entwurf)** daraus anlegen. Route `?p=angebot_import`, Menü **Import → Angebots-Import**. Rolle admin/sales.

## Ablauf
1. **Upload** (`aktion=scan`): PDF/Bild hochladen → `angebotsscan_ki()` (core/schema.php, nutzt core/ki.php) erkennt Produkt/Rezeptur, Kunde (+Nr), Datum, Stück je Packung, Preis-Staffeln. Ergebnis in Session, Redirect `&schritt=match`.
2. **Prüfen & Zuordnen** (`schritt=match`): KI-Ergebnis editierbar; Kunde-Match (Nr/Firma) + Dropdown (inkl. „neu anlegen"); **Rezeptur-Match** per Name; **Glas-Vorschlag** aus Kapselgröße × Stück (`verpackung_empfehlung`), überschreibbar; Preis-Staffeln editierbar. Entscheidung: **zuordnen** zu einem bestehenden Angebot des Kunden ODER **neu anlegen**.
3. **Übernehmen** (`aktion=anwenden`):
   - **zuordnen**: `angebot.rezeptur_id` setzen (falls leer), Glas auf passende Positionen ohne Glas setzen, Import-Notiz anhängen, PDF als `dokument` (typ `angebot_original`) anhängen, Preis in `rezeptur_kundenpreis` erfassen → zum Angebot.
   - **neu**: Entwurfs-Angebot (`status 'offen'`) mit `rezeptur_id` + je Staffel eine Position (rezeptur, Menge, VK, Glas, Stück, USt aus `ust_inland`) anlegen, PDF anhängen, Kundenpreis erfassen → zum neuen Angebot.

**Baut auf vorhandenem auf:** `angebotsscan_ki` (Extraktion), `verpackung_empfehlung` (Glas), `rezeptur_kundenpreis` (Preishistorie), `kunde_finden_oder_anlegen`. Braucht KI live (`ki_bereit`, Anthropic-Key; beta/live). Unterschied zum `angebotsscan` (kundenunabhängig Rezepturen/Preise erfassen): hier geht es um die **Zuordnung zu konkreten Angeboten**.

## Original + gelesene Zutaten beim Pruefen
Match-Schritt zeigt oben das **Original** (PDF/Bild, Stream `?p=angebot_import&schritt=datei`) und die **gelesenen Zutaten read-only** (nur Info). Hinweis dort: angebot_import ordnet die Rezeptur nur ueber den Namen zu und legt **keine** Rezeptur mit Zutaten an - dafuer ist `angebotsscan`.

## Mehrere Produkte je Angebot (Stand 2026-10-09)
`angebotsscan_ki()` liefert jetzt ein **`produkte`-Array** (jedes Produkt mit eigenem Namen, Darreichungsform, Stück/Packung, Verpackung, Staffeln, Zutaten, Preisen). Kunde/Kundennr/Datum sind Angebotsebene. Die Top-Level-Felder bleiben = **erstes Produkt** (Rückwärtskompatibilität für `angebotsscan.php`). Erkennt die KI nur ein Produkt oder liefert sie das Altformat, wird genau ein Block erzeugt.

Der Match-Schritt zeigt **je Produkt einen Block** (Name/Rezeptur-Match, Stück, Glas-Vorschlag, Staffeln, Zutaten als ausklappbare Info). Beim Übernehmen entsteht **ein** Angebot mit Positionen je Produkt (Gruppen A, B, … über `angebot_position.gruppe`); je Produkt wird – falls die Rezeptur per Name gefunden wird – eine `rezeptur_kundenpreis`-Zeile geschrieben. **Zuordnen zu einem bestehenden Angebot** ist nur bei **genau einem** Produkt möglich; bei mehreren wird immer neu angelegt. Formularfelder sind je Produkt indiziert: `produkt_name[pi]`, `stueck[pi]`, `verpackung_id[pi]`, `st_menge[pi][j]`, `st_vk[pi][j]`.

## Beschreibung, Kapselgröße, Einheit/Bulk (Stand 2026-10-09)
`angebotsscan_ki()` liest je Produkt zusätzlich **beschreibung** (die wortgetreuen Zusatzzeilen unter der Bezeichnung: Wirkstoff-Aufschlüsselung mg, Kapselgröße, Füllgewicht, Stückzahl), **kapselgroesse** (z. B. „#2") und **einheit** (Mengenspalte, z. B. „Stk." = lose/Bulk, „kg", „Packung"). Der Match-Schritt zeigt je Produkt ein **Beschreibung**-Feld (vorbefüllt; fehlt die KI-Beschreibung, wird sie aus den Zutaten + Kapselgröße gebaut) und ein **Einheit**-Feld (Datalist Stk./Packung/kg/g/L/Beutel – so lässt sich **Bulk-Ware** wählen). Beim Übernehmen landen Beschreibung + Einheit an der `angebot_position` (statt vorher fix „Packung" und leerer Beschreibung); Glas leer = Bulk/ohne Verpackung. Behebt: Import übernahm keine Kapselgröße/Beschreibung und keine Bulk-Einheit.

## Bulk-gerechte Beschriftung (Stand 2026-10-09)
Im Match-Schritt richten sich die Staffel-Spalten nach der **Einheit** je Produkt (JS): „Menge (Stk.)" / „VK je Stk. (netto)" statt fix „Packungen"/„je Packung". Das Feld **„Stück je Packung"** erscheint nur, wenn eine **Verpackung** gewählt ist – bei Bulk (keine Verpackung) ist es ausgeblendet und wird beim Übernehmen als 0 gespeichert (lose Ware hat keinen Packungsinhalt).

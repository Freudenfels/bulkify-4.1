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

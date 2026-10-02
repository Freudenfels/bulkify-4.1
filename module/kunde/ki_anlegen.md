# kunde/ki_anlegen.php – Neuen Kunden aus Text (KI)

**Zweck:** Einen neuen Kunden schnell aus einem **eingefügten Text** (Adressblock, Impressum,
E-Mail-Signatur) anlegen. In der Kundenliste öffnet der Knopf **„Kunde aus Text (KI)"** ein Popup
(`<dialog>`), in das man den Text einfügt. Die KI zerlegt ihn in Stammdaten-Felder; danach landet man
im normalen Neuanlage-Formular (`?p=kunde&id=neu`) mit **vorbefüllten Feldern** und prüft/speichert
dort. Die Kontrolle (und das eigentliche Anlegen) bleibt beim Team – die KI füllt nur vor.

## Ablauf
1. **Popup** (in [liste.php](liste.md)): Textarea → Knopf **„Analysieren & übernehmen"** postet an
   `?p=kunde_ki` (`aktion=ki_kunde`).
2. **`ki_anlegen.php`** schickt den Text an Claude (`core/ki.php`, `ki_json()`) mit einem System-Prompt,
   der genau diese Felder verlangt: `firma, ansprechpartner, email, telefon, strasse, hausnummer, plz,
   ort, land (ISO-2), ust_id, notiz`. Regeln: bei DE/AT Hausnummer abtrennen, sonst ganze Straßen-/
   Gebäudezeile in `strasse`; `land` als ISO-2 (DE, HK, GB …); `ust_id` nur für echte USt-/VAT-IDs –
   andere Registernummern (BRN, Company No., HRB) kommen in `notiz`; nichts erfinden.
3. Das Ergebnis wird in `$_SESSION['kunde_ki']` gelegt und auf `?p=kunde&id=neu&ki=1` weitergeleitet.
   **Fehlt der KI-Schlüssel** oder schlägt die Analyse fehl, wird der Rohtext als `notiz` übergeben
   (mit Hinweis) – man füllt dann von Hand aus.
4. **[detail.php](detail.md)** übernimmt bei `id=neu&ki=1` die Session-Felder **einmalig** in `$k`
   (Session wird danach geleert), zeigt einen Hinweis-Banner und rendert das normale Formular
   vorbefüllt. Gespeichert wird über den üblichen Speichern-Weg (INSERT in `kunden`).

## Route & Rechte
`?p=kunde_ki` → `public/index.php`; Rollen **sales, finance** (`core/auth.php`), wie die Kundenseiten.
Legt selbst **keinen** Kunden an – nur Vorbefüllung; das Anlegen passiert erst beim Speichern in
`detail.php`.

# beleg/auftrag_import.php – Auftrag aus Angebot/AB importieren (KI)

**Zweck:** Ein (älteres) **Angebot bzw. eine Auftragsbestätigung** als PDF/Bild hochladen (optional
zusätzlich die **Rechnung**); die KI liest **Produkt, Rezeptur (Zutaten/mg), Form, Verpackung, Menge,
Stück je Packung und Preis** aus. Nach einer **Vorschau mit Dedup-Prüfung** werden – falls noch nicht
vorhanden – **Rezeptur + Produkt** angelegt und ein **Auftrag** erstellt (landet unter den Bestellungen
des Kunden). Angebot/Rechnung werden als Dokumente angehängt; die Rechnung optional als **bezahlter
Beleg** mit dem Auftrag verknüpft.

## Ablauf (zweistufig)
1. **Hochladen & auslesen** (`aktion=lesen`): Kunde wählen + Angebot/AB (Pflicht) + Rechnung (optional).
   Dateien landen in `data/uploads`; `auftrag_import_ki()` liest das Angebot, `rechnung_import_ki()` die
   Rechnung. Ergebnis + Dateinamen in `$_SESSION['auftrag_import']`, Weiterleitung zur Vorschau.
2. **Vorschau** (`?schritt=vorschau`): zeigt die gelesenen Werte (Produkt/Rezeptur/Verpackung/Menge/
   VK/Gesamt, editierbar) + **Dedup**: existiert die Rezeptur (Name), ein Auftrag zur AB-Nummer
   (`auftrag.import_ref`)? Verpackung wird über `verpackung_finden()` (Volumen/Typ) gematcht. **Status
   wählbar** (Standard „Abgeschlossen/erledigt", auch „In Produktion"/„Offen").
3. **Anlegen** (`aktion=anlegen`): `auftrag_aus_import()` legt Rezeptur (`rezeptur_finden_oder_anlegen`,
   Status eingefroren) + Produkt (`produkt_aus_rezeptur`) + Auftrag an (idempotent über `import_ref`).
   Danach Dokumente anhängen (`dokument` objekt_typ='auftrag', typ ab/rechnung, kunde_sichtbar=1) und –
   wenn gewünscht – die Rechnung als bezahlten Beleg verknüpfen (`rechnung_alt_anlegen(..., $auftrag_id)`).
   Weiterleitung auf den Auftrag (`?p=auftrag&id=…&importiert=1`).

## Funktionen (`core/schema.php`)
`auftrag_import_ki($pfad)`, `verpackung_finden($text)`, `rezeptur_finden_oder_anlegen($name,$form,$zutaten,$kunde_id)`,
`auftrag_aus_import($kunde_id,$daten,$status)`; Spalte `auftrag.import_ref` (Dedup).

## Hinweise
- Für importierte Alt-Aufträge wird **kein Produktionsauftrag** angelegt (historisch). Bei
  „Offen/In Produktion" kann die Produktion danach im Auftrag gestartet werden.
- KI-Werte immer gegenprüfen (Vorschau ist dafür da). Braucht die KI (Einstellungen → KI).

## Route & Rechte
`?p=auftrag_import` → `public/index.php`; Rollen **finance, sales**. Verlinkt aus der Rechnungen-Liste.
Gegenstück für reine Rechnungen ohne Auftrag: [rechnung_import.md](rechnung_import.md).

## Reparatur 2026-10-06
Die Erzeugungs-/KI-Funktionen (auftrag_import_ki, auftrag_aus_import, verpackung_finden,
rezeptur_finden_oder_anlegen, produkt_aus_rezeptur) waren beim Ausgliedern in /buchhaltung/ nicht mitgezogen
worden – der Import lief ins Leere (undefinierte Funktion). Sie sind jetzt in core/erp.php (Naht) portiert.
Einschränkung: importierte Produkte bekommen (noch) KEINE Preismatrix; diese ist im Dashboard nacherzeugbar.
Mehrfach-Upload (bis 5 PDFs) ist offen (Entscheidung „erst nur reparieren").

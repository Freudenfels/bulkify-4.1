# core/pdf_formular.php – beschreibbare PDF-Formulare befüllen

Füllt AcroForm-Felder einer PDF-Vorlage aus PHP, ohne Zusatzprogramm (kein pdftk/qpdf auf dem Server nötig).

## So funktioniert es
- **Inkrementelles Update:** Die Vorlage bleibt Byte für Byte erhalten. Geänderte Feld-Objekte, neue Darstellungen und eine neue Querverweistabelle (mit `/Prev` auf die alte) werden hinten angehängt. PDF-Leser nehmen immer die letzte Fassung eines Objekts.
- **Textfelder:** Der Wert wird als `/V` (UTF-16) gesetzt. Dazu kommt eine eigene Darstellung (Appearance-Stream, Helvetica WinAnsi, also mit Umlauten). Mehrzeilige Felder (Ff-Bit 4096) werden umgebrochen, die Schrift wird bis 5,5 pt verkleinert, bis alles passt. So stimmt der Ausdruck in jedem Viewer.
- **Kontrollkästchen:** `true` setzt `/V` und `/AS` auf den „an“-Zustand der Vorlage (bei GBA `/Ja`), `false` setzt beides auf `/Off`.
- **Rückfall:** Im AcroForm wird zusätzlich `/NeedAppearances true` gesetzt.
- **Feldnamen:** UTF-8, so wie im PDF, z. B. `Kontrollkästchen1`, `Text5`, `37`. Felder mit Widget-Kindern (`/Kids`) werden erkannt.

## Funktionen
- `pdf_formular_pruefen($pdf)` liefert `null`, wenn die Vorlage geeignet ist, sonst den Grund. Nicht unterstützt sind verschlüsselte PDFs, Objekt-Streams und PDFs ohne Formularfelder.
- `pdf_formular_fuellen($pdf, $werte)` gibt die befüllten PDF-Bytes zurück.
- `pdf_formular_felder($pdf)` listet alle Feldnamen auf, zum Prüfen einer Zuordnung.

## Wer nutzt das
`core/labor_auftrag.php`: das Auftragsformular des Labors bei „Ans Labor senden“.

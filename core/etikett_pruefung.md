# core/etikett_pruefung.php – Etikett hochladen, prüfen und freigeben in einem Schritt

Seit 13.11.2026. Eine Stelle für Kundenportal und Dashboard. Die Oberfläche baut `public/assets/bx-etikett.js`.

## Ablauf im Pop-up
1. **Hochladen:** nur PDF (Endung und Dateikopf `%PDF-`), sonst „Bitte die Druckdatei als PDF hochladen.“ Darunter das Soll-Format. Gesperrt, sobald die Etiketten bestellt sind (`etikett_bestellt_fuer_auftrag`).
2. **Prüfen:**
   - Vorschau mit pdf.js, Rahmen: Endformat (TrimBox) durchgezogen, Beschnitt rot gestrichelt (BleedBox, sonst MediaBox, sonst Endformat + Beschnitt), Sicherheitsabstand blau gestrichelt. Zoom per Klick.
   - **Maß-Ampel** (`etp_mass_ampel`): Ist-Maß aus der vorhandenen `pdf_etikett_mass()` (TrimBox → ArtBox → MediaBox). Grün bis 1 mm, gelb bis 3 mm, rot darüber (Freigabe nur mit „trotzdem verwenden“). Seite ohne TrimBox, die genau Endformat + Beschnitt groß ist: gelb „Format passt nur mit Beschnitt gerechnet“.
   - **Druck-Check** (`etp_technik_zeilen`): Beschnitt, Bildauflösung (< 300 dpi gelb, < 150 dpi rot, mit Bild-Nummer, Größe und Lage), Farben (RGB gelb, CMYK/Sonderfarben grün), Schriften (nicht eingebettet rot), mehrere Seiten (Hinweis). Der PHP-Leser `etp_pdf_lesen()` liest Boxen, Bilder, Farbräume, Schriften und Seitenzahl (auch komprimierte Objekt-Streams, Flate/ASCII85/Hex). Nur Schriften, die wirklich Text zeigen, zählen. Bildauflösung und Textlage misst der Browser (pdf.js) und schickt sie mit (`etp_client_speichern`).
   - **KI-Etikettencheck** (`etp_ki_pruefen`): Der Browser rendert die Druckdatei (je Fläche, lange Flächen in Teilen, gedrehte Flächen richtig herum, 1568 px) und schickt die Bilder; die KI bekommt dazu das PIB des Auftrags (PDF) und die Rezepturdaten. 11 Prüfpunkte (`etp_ki_punkte`), Ampel + Begründung, Zusammenfassung „x von 11 Punkten passen · n Hinweise · n Abweichungen“. Gesundheitsaussagen höchstens gelb. Ausfall oder > 75 s: „Prüfung nicht möglich“, Freigabe trotzdem möglich. Rote Punkte → eine Aufgabe je Auftrag „Etikett AB-…: KI meldet Abweichung zum PIB“ (normal).
   - Testkopie ohne KI-Schlüssel: eingespielte Antwort aus `data/etp_ki_test.json` (nur dort, nie im Repo); die gesendeten Bilder liegen dann in `data/etp_ki_bilder/`.
3. **Freigeben** (`etp_freigeben`): Haken (Text siehe `etp_texte`, Haftungshinweis aufklappbar), Name vorbelegt mit dem Portal-Nutzer. Ruft die vorhandene `etikett_freigabe_setzen()` (Kundenetikett-Version, Mails wie bisher). Team: Freigabe im Namen des Kunden nur mit Grund. „Später freigeben“: Datei bleibt, Band zeigt „bitte noch freigeben“.

## Daten
- Tabelle `etikett_pruefung` (selbst angelegt, `etp_schema`): je hochgeladener Version eine Zeile mit Datei, Soll-/Ist-Maß, Maß-Ampel, Technik-JSON (PDF-Leser + Browser-Messung), KI-JSON + Zusammenfassung, Freigabe (Name, Zeit, Nutzer, IP, Grund, Akteur, „trotzdem“), Version. Neue Version = neue Freigabe; ältere bleiben im Verlauf (Status `ersetzt` bzw. `freigegeben`).
- `etp_aktuell()` legt die Prüfung für ältere Dateien (vor dem Pop-up) oder geerbte Designs beim ersten Öffnen nach.

## Band im Portal
`etp_band_liste($kunde_id)` = offene Aufträge mit Etikett-Bedarf, Zustand `fehlt` oder `wartet` (Regeln Fable-4: geerbtes freigegebenes Design gilt, fehlende Datei → Team-Aufgabe). `etp_band_html()` baut das rote Band (wie `.bx-dringend`, ohne X). Daraus kommen auch der Menü-Zähler „Etiketten“ und das To-do auf der Übersicht.

## Beutel (vollbedruckt)
Titel „Druckdatei Verpackung“. Vorlage je Beutel-Typ in `item.beutel_vorlage` (JSON, `etp_vorlage_lesen`): Gesamtformat, Beschnitt, Sicherheitsabstand, Flächen mit Lage und Drehung, Siegelnähte/Zipper, optional Stanzkontur-PDF. Pflege an der Verpackung (`etp_vorlage_aus_post`). Ohne Vorlage: nur Gesamtformat + Aufgabe „Vorlage für … fehlt“ (einmal je Beutel).

## Einbinden
- Portal: `?p=portal&v=etp&a=…` → `etp_ajax(['wer'=>'kunde', …])`; Datei je Version `v=etikett_datei&aid=…&pid=…`.
- Dashboard: Auftragsseite `?p=auftrag&id=…&etp=1&a=…` → `etp_ajax(['wer'=>'team', …])`.
- `etp_einbinden($basis, $auto, $sprache)` lädt `bx-etikett.js`; `&etikett=AID` in der Adresse öffnet das Pop-up direkt (Etikett-Erinnerung, To-do).
- Sprache: Deutsch für DE/AT/CH/LI/LU, sonst Englisch (`etp_sprache`).

## Sofortfix 13.11.2026: Format mit Beschnitt, Tagesdosis des Kunden, Erneut prüfen
- **Maß-Ampel** (`etp_mass_ampel($soll, $ist, $sp, $pdf)`): nutzt die Seitenboxen aus `etp_pdf_lesen()`. Eigene TrimBox (kleiner als die Seite) -> TrimBox gegen das Endformat, Text „Format passt (188 × 67 mm + 3 mm Beschnitt)“ mit dem gemessenen Beschnitt. Ohne eigene TrimBox -> Seite (BleedBox bzw. MediaBox): passt sie direkt oder ist sie Endformat + 2 × b mit b zwischen Soll-Beschnitt und 5 mm (±1 mm), ist das Format grün; kein Gelb nur wegen fehlender TrimBox. Größerer Beschnitt als das Soll ist grün, kleinerer bleibt gelb. Gilt für Etikett, Beutel-Vorlage, Status und die Freigabe-Sperre („trotzdem verwenden“ rechnet mit der aktuellen Regel, nicht dem gespeicherten Wert).
- **KI-Tagesdosis**: Die KI bekommt die PIB-Werte je 1 Einheit und die PIB-Standard-Tagesdosis als Vergleich (`etp_pib_je_einheit`, `etp_rezeptur_text`) und liefert zusätzlich `tagesdosis` (Verzehrempfehlung, Tabellenkopf) und `naehrwerte` vom Etikett. `etp_ki_nachpruefen()` rechnet im Server nach: PIB je Einheit × Tagesdosis des Etiketts, Toleranz Rundung wie gezeigt, Mengen ±5 %, %NRV ±2 Prozentpunkte. Verzehrempfehlung grün bei eigener Tagesdosis (Zusatz „Tagesdosis laut Etikett …, Werte umgerechnet und geprüft“), rot nur bei Widerspruch zwischen Text und Tabelle. Höchstmengen/UL sind nicht hinterlegt – dieser Punkt entfällt. Füllmenge weiter gegen den Auftrag.
- **Erneut prüfen**: Knopf im Etikett-Pop-up (Kunde und Team, `a=neu_pruefen`, `etp_neu_pruefen()`): PDF neu lesen, Maß-Ampel neu, Browser-Messung und KI-Check starten neu – ohne neuen Upload. Gespeicherte Ergebnisse werden sonst nicht automatisch neu berechnet.

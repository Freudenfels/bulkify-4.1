# public/assets/bx-etikett.js – Etikett-Pop-up (13.11.2026)

Oberfläche zu `core/etikett_pruefung.php`, im Kundenportal und auf der Auftragsseite im Dashboard.

- Start: `window.bxEtikettStart = {basis, auto, txt}` (von `etp_einbinden()`), dann diese Datei.
- Öffnen: `bxEtikett.oeffnen(aid)`, Klick auf `[data-etp-oeffnen="AID"]`, im Band `[data-etp-liste]` (Auswahl bei mehreren Aufträgen).
- Pop-up-Baustein `dialog.bx-dialog` (bx-ui.js): Kopf mit X, Knopfzeile unten („Später freigeben“, Hauptknopf lime rechts), am Handy fast vollflächig, Vorschau oben.
- Schritte 1 Hochladen · 2 Prüfen · 3 Freigeben. Ablagefläche (Ziehen oder Datei wählen), nur PDF.
- Vorschau: pdf.js 3.11.174 von cdnjs, Seite 1 auf Canvas mit Rand für den Beschnitt-Rahmen; Rahmen Endformat/Beschnitt/Sicherheitsabstand, bei Beuteln Flächen beschriftet und Siegel/Zipper schraffiert („hier kein Text“). Klick = Zoom.
- Messung: Operatorliste (Bild-Platzierungen → dpi in Druckgröße), Textinhalt (Text in Siegel-/Zipper-Zonen) → `a=technik`.
- KI: Ausschnitte je Fläche (gedrehte Flächen gedreht), lange Flächen in Teilen, JPEG 1568 px → `a=ki`; Abbruch nach 95 s → „Prüfung nicht möglich“, „Erneut prüfen“.
- Daten gehen als Formularfeld `daten` (JSON) – ein reiner JSON-Body würde vom Server als „Upload zu groß“ umgeleitet.
- Nach der Freigabe: grüner Haken „Freigegeben – danke!“, Pop-up schließt, Band wird neu geladen (`a=band`); Seiten mit `[data-etp-neuladen]` laden neu, das Team-Dashboard lädt immer neu.
- `&etikett=AID` öffnet das Pop-up beim Laden und wird danach aus der Adresse entfernt.

Sofortfix 13.11.2026: Knopf „Erneut prüfen“ neben „Druck-Check“ (Kunde und Team) – startet Druck-Check und KI-Check für die aktuelle Datei neu.

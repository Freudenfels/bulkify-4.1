# bx-gewicht.js – Rückfrage bei mehrdeutigen Gewichten

Seit 13.11.2026 (Fable-4 T1.3). Ein Gewichtsfeld trägt `data-gewicht="kg"` (oder `"g"`). Steht darin genau „1-3 Ziffern, Punkt, 3 Ziffern“ ohne Einheit (z. B. „1.250“), ist unklar, ob 1,25 kg oder 1.250 kg gemeint ist. Dann wird das Formular **nicht** abgeschickt. Es erscheint ein Pop-up „1.250 - gemeint 1,25 kg oder 1.250 kg?“ mit zwei großen Knöpfen. Die Antwort schreibt den Wert eindeutig ins Feld („1,25“ bzw. „1250“) und schickt ab. „Abbrechen“ lässt das Feld stehen.

- Gleiches Muster wie `gewicht_mehrdeutig()` in `core/gewicht.php`.
- Geladen von `bx-ui.js` (alle Layouts, Portale) und direkt in der Werk-App (`produktion/module/produktion/werk.php`, Texte DE/EN/UK über `window.bxGewichtTexte`).
- Für Formulare ohne Absenden (Inventur per Skript): `bxGewichtPruefen(element, weiter)` – `true` = eindeutig, sonst fragt es und ruft danach `weiter()`.
- Farben folgen der Seite (Hintergrund und Schrift vom `<body>`).
- Markierte Felder: Werk-App kg je Gebinde, Restmischung, Sack brutto; Lager-App Versand (Gewicht gesamt, Packstück, Zoll-Gewicht in g), Versand erstellen, Inventur Brutto, Ist-Gewicht beim Nachwiegen/Rückgabe, Tara; Gebindeaufkleber Netto/Brutto; Bestell-Pop-up Gewicht; Einstellungen Schwund-Grenze; Auftrag „Material zuordnen“ (bei kg/g) und Bulk reservieren in kg.
- Nicht markiert: Kapselprobe (Gewicht von 10 Kapseln in g – über 1.000 g ist unmöglich, „6.482“ g ist der Normalfall).

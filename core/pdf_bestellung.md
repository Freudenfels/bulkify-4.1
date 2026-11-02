# pdf_bestellung.php – Bestellung als PDF

## Wozu
Der Einkaufsbeleg, den der Lieferant per Mail bekommt – gleiche Vorlage wie Angebot und Rechnung (`core/pdf_beleg.php`), Empfänger ist hier der **Lieferant**. Es wird keine Datei gespeichert; das PDF entsteht bei jedem Aufruf neu.

## Funktionen
- `bestellung_pdf_bauen(int $id): ?string` – das PDF als String; `null`, wenn die Bestellung fehlt **oder kein Lieferant hinterlegt ist**.
- `bestellung_pdf_ausliefern(int $id, string $nummer): bool` – setzt die Header und gibt es aus (Dateiname `Bestellung_<Nr>.pdf`).

## Sprache
Steht am Lieferanten (`lieferanten.sprache`). Alles außer `de` ergibt einen **englischen** Beleg: Titel „Purchase Order", englischer Begleittext und englische Beschriftungen (`beleg_labels()` in `core/pdf_beleg.php`). Für die asiatischen Lieferanten der eigentliche Punkt.

## Inhalt
Positionen aus `bestellung_position` – Lagerartikel mit Artikelnummer und Namen, Freitextzeilen (Bulk-Zukauf) mit ihrer Bezeichnung. **Ohne Umsatzsteuer**: die stellt der Lieferant selbst. Kopftext bittet um Bestätigung der Bestellung und des geplanten Liefertermins; die Notiz der Bestellung hängt darunter.

## Verwandt
- `module/einkauf/pdf.php` – die Route `?p=bestellung_pdf&id=<ID>`.

**Einkaufsbeleg statt Verkaufsbeleg-Kopie (St-21):** Das Bestell-PDF an den Lieferanten hat kein „§ 19 UStG"-Hinweis (stattdessen „Alle Preise netto, USt laut Ihrer Rechnung"), keine eigene Bankverbindung, keinen „Hinweis zur Herstellung" (Kundenhinweis), keine interne Notiz und keine Standardzeile „Sofort zahlbar / Zahlungsart". Der Kopftext fragt vor der Bestätigung um Bestätigung und nennt danach „Bestätigung + geplanter Liefertermin" (kein „wartet auf Bestätigung" mehr). Eine Zahlungsbedingung steht nur, wenn der Lieferant ein Zahlungsziel hat.

# core/pdf_rechnung.php – Rechnungs-PDF (intern)

**Zweck:** Erzeugt die **Rechnungs-PDF fürs Team** aus einem Beleg (`typ='rechnung'`) und dessen
eigenen `beleg_position`-Zeilen. Funktioniert für **freie Rechnungen** ([rechnung_frei](../module/beleg/rechnung_frei.md))
genauso wie für **aus einem Auftrag** erzeugte Rechnungen – beide haben echte Positionszeilen.

**Funktionen:**
- `rechnung_pdf_bauen(int $beleg_id): ?string` – lädt Beleg + Positionen (`beleg_positionen()`) + Kunde,
  baut den Beleg-Kopf (Empfänger = Rechnungsadresse, sonst Hauptadresse; Datum, Fälligkeit aus
  `faellig` bzw. `datum + zahlungsziel_tage`; Bezug = „Auftrag …" falls verknüpft; Kopftext „Wir
  berechnen Ihnen wie folgt" + Leistungsdatum + optionaler Rechnungstext; Zahlungsbedingung aus dem
  Zahlungsziel; **Bearbeiter** = Name + E-Mail aus `beleg.bearbeiter_id` (Benutzer), Fallback = Ersteller
  aus dem Beleg-Verlauf `beleg_status_log`; USt-Befreiung bei Ausland/Kleinunternehmer) und ruft den gemeinsamen Renderer
  `build_beleg_pdf()` aus [pdf_beleg.php](pdf_beleg.md) auf. Gibt die PDF-Bytes zurück oder `null`
  (Beleg fehlt oder hat keine Positionen).
- `rechnung_pdf_ausliefern(int $beleg_id, string $nummer): bool` – setzt die PDF-Header und gibt die
  Datei inline aus (`Rechnung_<Nummer>.pdf`).

**Verwendung:** Route `?p=rechnung_pdf&id=<ID>` → [rechnung_pdf.php](../module/beleg/rechnung_pdf.php).
Der Knopf **„PDF ansehen"** steht auf jeder Rechnung ([beleg/detail.md](../module/beleg/detail.md)).
Gegenstück für Gutschriften: [pdf_gutschrift.php](pdf_gutschrift.md).

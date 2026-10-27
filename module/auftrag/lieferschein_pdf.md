# auftrag/lieferschein_pdf.php – Lieferschein-PDF (Route)

**Route:** `?p=lieferschein_pdf&id=<BELEG-ID>` (die **Beleg**-ID des Lieferscheins, nicht die Auftrags-ID).

**Zweck:** Dünner Wrapper, der den Lieferschein-Beleg (`typ='lieferschein'`) prüft und das PDF über `lieferschein_pdf_ausliefern()` (`core/pdf_lieferschein.php`) inline ausliefert. 404 wenn kein Lieferschein mit dieser ID existiert, 409 wenn kein Kunde hinterlegt ist.

**Verlinkt aus:**
- **Versand** (`?p=versand`) – die LS-Nummer(n) in der Spalte „Lieferschein" sind Download-Links (bei Teilversand mehrere).
- **Auftrag-Detail** (`?p=auftrag&id=…`) – Reiter „Dokumente & Downloads", je Lieferschein ein Button.

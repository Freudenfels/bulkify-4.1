# beleg/rechnung_pdf.php – Rechnung als PDF (intern)

**Zweck:** Liefert die **Rechnungs-PDF** fürs Team aus: `?p=rechnung_pdf&id=<ID>`. Öffnet die PDF
inline im Browser (ansehen/herunterladen/drucken).

**Ablauf:** Lädt den Beleg (`typ='rechnung'`), ruft `rechnung_pdf_ausliefern()` aus
[core/pdf_rechnung.php](../../core/pdf_rechnung.md). Kein Beleg → 404; keine Positionen → 409.

**Rechte:** Rolle **finance** (`core/auth.php`). Verlinkt über den Knopf „PDF ansehen" auf der
Rechnungs-Detailseite ([detail.md](detail.md)). Gegenstück: [gutschrift_pdf.md](gutschrift_pdf.md).

# lieferant/bestellung_pdf.php – Bestell-PDF für den Lieferanten

Route: `?p=lieferant_bestellung_pdf&id=<ID>`

Derselbe Beleg wie intern (`core/pdf_bestellung.md`), aber nur für die **eigenen** Bestellungen des angemeldeten Lieferanten; sonst 404.

**Bestätigen mit Unterlagen-Sperre, Portal ohne Entwürfe (13.11.2026, Fable-3 T2.2):** Kein PDF für Entwürfe (`bestellung_portal_sichtbar_sql()`).

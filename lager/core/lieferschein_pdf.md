# core/lieferschein_pdf.php – Lieferschein als PDF (zentral)

`lg_lieferschein_pdf(int $versand_id): ?string` baut den Lieferschein (A4 hoch) einer Sendung
(`lg_versand` + `lg_versand_pos`) und gibt die PDF-Bytes zurück (oder null). Design wie die Etiketten
(MiniPDF/Arial, Charcoal). Inhalt: Kopf (bulkify + „Lieferschein"), **Absender** (aus Einstellungen,
`versand_absender`), **Empfänger** (Snapshot der Sendung, Land ausgeschrieben via `lg_land_name()` –
weltweit), Meta (Nummer/Datum/Versandart/Pakete/Sendungsnr.), Positionstabelle (Pos · Bezeichnung ·
Charge · Menge), Notiz. Phase 1: eine Seite.

Helfer: `lg_land_name($code)` (gängige ISO-Codes → Klarname, sonst Code), `lg_versand_absender()`
(Absenderzeilen aus `lg_meta['versand_absender']`, sonst Standard). Braucht MiniPDF (`core/lib/minipdf.php`)
und die `lg_versand*`-Helfer aus `core/schema.php`.

Genutzt von [../module/versand/lieferschein.md](../module/versand/lieferschein.md).

## Erweiterung (Generator)
Der Lieferschein zeigt jetzt zusätzlich **Gewicht** (gesamt + kg/Paket), **Warenwert (vers.)** aus
`lg_versand.versicherung_cent` und in der Positionsspalte **Kartons × Menge/Karton** (statt Charge, wenn über den
Generator erfasst).

Versandart „Selbstabholung“ im Kopf (Soll-Kette 7, 04.11.2026).

Packstücke (Soll-Kette 7b, 04.11.2026): Unter den Positionen ein Block „Packstücke“ – je Palette „Palette i von n“ mit Kartonzahl, Summe und Gewicht, darunter die Kartons (Nr., Produkt, Charge, Menge); Kartons/Pakete ohne Palette folgen gesammelt. Neu `lg_paletten_etikett_pdf($versand_id)`: ein Etikett 100×150 mm je Palette (Empfänger, Inhalt nach Produkt/Charge gruppiert, Gewicht, Code128 `<Sendungsnr>-P01`).

**Pflicht-Hinweis (06.11.2026):** Über „Erstellt am …“ steht `pdf_hinweis_maschinell()` (aus core/lib/minipdf.php). Paletten-Etiketten und Lager-Etiketten tragen ihn nicht (Etikett, kein Dokument).

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

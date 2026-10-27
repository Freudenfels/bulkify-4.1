# pdf_lieferschein.php – Lieferschein als PDF

**Zweck:** Macht einen Lieferschein-Beleg (`beleg.typ='lieferschein'`, Nummer `LS-`) druckbar. Der Beleg selbst entsteht **automatisch beim Versand** (`auftrag_versenden_menge()`); hier wird nur das PDF daraus gebaut.

**Funktionen:**
- `lieferschein_pdf_bauen(int $beleg_id): ?string` – lädt den Lieferschein-Beleg, den zugehörigen Auftrag (Produkt, Stück je Packung, Verpackung) und den Kunden; baut das PDF über `build_beleg_pdf()` im **Lieferschein-Modus** (`'lieferschein' => true`). Gibt die PDF-Bytes zurück oder `null` (kein Beleg / kein Kunde).
- `lieferschein_pdf_ausliefern(int $beleg_id, string $nummer): bool` – liefert das PDF inline aus (`Content-Disposition: inline`).

**Inhalt des Lieferscheins:**
- **Keine Preise/Summen/Zahlung/Bank** – ein Lieferschein zeigt nur, *was* und *wie viel* geliefert wird. Preise stehen auf der Rechnung.
- **Menge = `beleg.menge`** – also die Menge **dieser** (Teil-)Lieferung. Bei Teilversand hat jede Teillieferung einen eigenen Lieferschein mit der jeweiligen Menge. Fällt `beleg.menge` auf 0 (Alt-Belege), greift die Auftragsmenge.
- **Teillieferungs-Hinweis** im Kopftext, solange der Auftrag noch nicht komplett versendet ist („Teillieferung: X von Y Packungen …").
- **Charge/MHD** als Hinweis (aus `charge` des Auftrags).
- **Bezug:** Auftrag (+ Angebot), Kunden-Nr., USt-Id. Lieferadresse = Kundenadresse (wie beim AB-PDF).

**Route:** `?p=lieferschein_pdf&id=<BELEG-ID>` → `module/auftrag/lieferschein_pdf.php`.

**Einordnung:** Gegenstück zum Auftragsbestätigungs-PDF (`pdf_auftrag.php`), nutzt denselben Beleg-Baukasten `build_beleg_pdf()` (`core/pdf_beleg.php`).

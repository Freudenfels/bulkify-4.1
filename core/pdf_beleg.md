# Beleg-PDF (core/pdf_beleg.php)

## Wozu
Erzeugt Belege (aktuell **Angebot**) als echtes PDF im **bulkify-Layout** – 1:1 aus dem
v3-Dashboard (`bulkify-dashboard-v3/beleg_build.php`) portiert. Kein Composer/keine
Extension nötig (nutzt [core/lib/minipdf.php](lib/minipdf.md)).

Ersetzt die frühere Matrix-Druckansicht (`pdf_angebot.php`, entfernt) – die sah nicht wie
ein echtes bulkify-Angebot aus (Nutzer-Feedback: Vorlage `Angebot_Artischocke_AN-0048.pdf`).

## Aufbau des Dokuments (wie das Original)
1. **Briefkopf**: bulkify-Logo oben rechts (aus `assets/bulkify-logo.jpg`, per GD aus
   `bulkify-logo-dark.png` erzeugt), darunter Firmenblock (Maniso GmbH …). Links die
   Absenderzeile + Empfängeradresse.
2. **Titel** „Angebot AN-…".
3. **Meta-Grid** (2 Spalten): Datum, Kunden-Nr., Version, Bezug | Gültig bis, Bearbeiter,
   E-Mail, USt-Id Kunde.
4. **Begleittext** (kopf_text).
5. **Positionstabelle**: Pos. · Art.-Nr. · Bezeichnung (+ Unterzeile) · Menge · Einh. ·
   Preis € · Gesamt €. Dünne Linien, fette Kopfzeile (kein dunkler Balken).
6. **Summen** rechts: Positionen netto · USt je Satz · **Endsumme**.
7. **Preis je fertiges Produkt** (optional): je Produkt eine Staffel
   (ab Menge · Stückpreis · Preis/Packung) – so zeigt das Produkt-Angebot die Mengenpreise.
8. **Zahlungsbedingung / Zahlungsart**, **Hinweis zur Herstellung**.
9. **Kontoverbindungen** (zwei Spalten Deutschland / International) – nur wenn eine IBAN
   hinterlegt ist. Quelle: app_meta `bank_de_name/iban/bic` + `bank_int_name/iban/bic`,
   pflegbar in Einstellungen → Firma.
10. **Rechts-Fußzeile** (Marke/Firma/USt-Id/Eori).

## Funktion
`build_beleg_pdf(array $b, array $positionen, array $produktStaffel = []): string`
- `$b`: Belegkopf (belegart_label, nummer, empfaenger, adresse, datum, gueltig_bis,
  kundennummer, version, bezug, bearbeiter, bearbeiter_email, ust_id, kopf_text,
  zahlungsbedingung, zahlungsart_label, hinweis, kleinunternehmer).
- `$positionen`: je Position artikelnr, bezeichnung, beschreibung, menge, einheit,
  **preis_cent**, gesamt_cent, **mwst_satz** (Summen werden je Satz daraus berechnet).
- `$produktStaffel`: `[ ['name'=>, 'mpp'=>Stück/Pkg, 'rows'=>[['ab'=>,'stueck_cent'=>,'pack_cent'=>]]] ]`.

Firmendaten kommen aus `beleg_firma()` (app_meta: firma_name/strasse/hausnr/plz/ort/land/
email/ustid/eori). Zahlungsbedingung/Hinweis-Defaults aus `bh_zahlungsbedingung` /
`bh_hinweis_herstellung`.

## Aufruf
`module/portal/kunde.php`, Zweig `?v=angebot_pdf&aid=<ID>`: die Positionen kommen zentral aus
`angebot_positionen($aid)` (gespeicherte Overrides aus dem Editor haben Vorrang, sonst
automatisch). Automatisch sind das:
- **Position 1 = Herstellung** des angefragten Produkts (nur Füllung + Leerkapsel; Menge =
  angefragte Packungen, Preis/Pkg aus der Matrix inkl. `marge_override` + Kundenrabatt).
- **Weitere Positionen = Verpackung**: Dose/Deckel/Etikett kommen **extra** (je eigene
  Position, VK = EK-Staffel × Verpackungs-Aufschlag; `produkt_verpackung_items()` +
  `verpackung_vk_bei_menge()`).
Dazu die **Staffel „Preis je fertiges Produkt (inkl. Verpackung)"** über die Bestellmengen
(All-in, belegkonform je Position auf Cent gerundet – `verpackung_cent_je_pack()`).
USt: Inland = `ust_inland`, sonst 0 % (EU-/Export). Liefert `application/pdf` inline.

**Konsistenz:** Bildschirm-Angebotskarte, dieses PDF und die Rechnung (`auftrag_aus_zelle`
→ `angebot_zelle_netto_cent()`) rechnen die Netto-Summe identisch (jede Position einzeln auf
Cent gerundet × Menge).

## Offen / später
- Bearbeiter + E-Mail aus dem eingeloggten Mitarbeiter füllen.
- Rohstoff-Angebot ebenfalls über diesen Generator ausgeben (eine Position, wie das
  Muster `AN-0048`), sobald „Rohstoff-Angebot senden" gebaut ist.
- Bankverbindungen / Kundenportal-QR (im v3-Original vorhanden) bei Bedarf ergänzen.

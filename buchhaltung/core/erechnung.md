# `core/erechnung.php` – E-Rechnung (CII/EN16931-XML)

`erechnung_cii_xml(int $beleg_id): string` baut aus einem Beleg (Rechnung/Gutschrift) ein
EN16931-konformes CII-XML (UN/CEFACT Cross Industry Invoice, Profil `urn:cen.eu:en16931:2017`,
ZUGFeRD-2.x-„Comfort").

## Inhalt
- Belegkopf: Nummer, TypeCode (380 Rechnung / 381 Gutschrift), Ausstellungsdatum, optionaler Hinweistext.
- Positionen: aus `beleg_position`, sonst eine Sammelposition aus dem Belegkopf (`netto`).
- Verkäufer aus `beleg_firma()` (Name, Adresse, USt-IdNr. als `schemeID="VA"`), Käufer aus `kunden`
  (abweichende Rechnungsadresse hat Vorrang), USt-IdNr. des Kunden falls vorhanden.
- Steuer: Gruppen je Satz. Kategorie `S` (Normalsatz), `K` (innergem. Lieferung: 0 % + EU + USt-IdNr.),
  `E` (sonst steuerbefreit, z. B. Kleinunternehmer).
- Summen aus den gespeicherten Belegwerten (`netto`/`ust_betrag`/`brutto`) + bereits gezahlter Betrag
  (`TotalPrepaidAmount`) und offener Rest (`DuePayableAmount`), Fälligkeit aus `beleg.faellig`.
- Einheiten werden auf UN/ECE-Codes gemappt (Stück → C62, kg → KGM …).

## Grenzen / Ausbau
- Liefert reines XML (Download/Weitergabe). Die Einbettung als **Factur-X/ZUGFeRD in ein PDF/A-3**
  ist noch nicht gebaut – dafür braucht es eine PDF/A-3-fähige Lib (die aktuelle MiniPDF kann das nicht).
- Keine Schema-Validierung gegen die offizielle XSD/Schematron im Code (XML wird strukturell korrekt,
  EN16931-konform aufgebaut). Vor dem produktiven Versand empfiehlt sich ein Validator-Check.

**Einheiten-Codes (St-22):** `$einheitCode` vergleicht exakt (nicht mehr „enthält“): Stk/Stück -> C62, Pkg./Packung -> XPK (UN/ECE Rec. 21), Pauschale -> LS, kg -> KGM, g -> GRM, l -> LTR, ml -> MLT, Karton -> XBX; unbekannt -> C62. Vorher wurde „Pkg." wegen „kg" als Kilogramm (KGM) übertragen.

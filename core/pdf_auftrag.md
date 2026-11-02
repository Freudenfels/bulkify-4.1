# core/pdf_auftrag.php – Auftragsbestätigung (AB) als PDF

`auftrag_pdf_bauen($auftrag_id)` baut die Auftragsbestätigung über den gemeinsamen Beleg-Baukasten
(`core/pdf_beleg.php`, `build_beleg_pdf()`, `belegart_label='Auftragsbestätigung'`). Inhalt: Kunde +
Adresse, Produkt, Menge (Pkg.), Stück je Packung + Verpackung (als Positions-Beschreibung), Preis je
Packung + Summen (USt. je Inland/Ausland), Bezug aufs verknüpfte Angebot, Charge(n)+MHD im Hinweis,
Status im Begleittext. `auftrag_pdf_ausliefern()` gibt es inline aus (Content-Disposition inline →
Ansehen/Drucken). Ohne Kunde am Auftrag: kein PDF (false).

Aufruf: Route `?p=auftrag_pdf&id=<ID>` (`module/auftrag/pdf.php`). Knopf „PDF / Drucken" im Kopf der
Auftragsseite (`module/auftrag/detail.php`). Rollen: sales/finance/production/fulfillment.

Charge-Hinweis = Fertigware-Charge des Auftrags (`auftrag_fertigware_chargen`), keine Lieferanten-Lotnummer (St-06).

**Positionen (S-04 intern):** Die interne AB baut ihre Positionen aus den vollständigen Angebotspositionen des Auftrags (`beleg_positionen_aus_auftrag`: Herstellung + Glas + Deckel + Etikett …), wie die Kunden-AB und die Rechnung. Ohne exakte Aufschlüsselung steht eine Sammelposition mit dem Gesamtpreis je Packung (`auftrag_vk_gesamt`).

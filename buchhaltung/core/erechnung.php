<?php
// E-Rechnung: erzeugt aus einem Beleg ein EN16931-konformes CII-XML (UN/CEFACT Cross Industry
// Invoice, Profil EN16931 / ZUGFeRD 2.x "Comfort"). Reine Lese-/Aufbereitungslogik.
// Gedacht als XML zum Download/Weitergeben; die Einbettung in ein PDF/A-3 (Factur-X) ist bewusst
// noch NICHT Teil davon (braucht eine PDF-Lib mit PDF/A-3) – siehe .md.
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/pdf_beleg.php'; // beleg_firma()

function erx_x($v): string { return htmlspecialchars((string)$v, ENT_XML1 | ENT_QUOTES, 'UTF-8'); }
function erx_dec($v): string { return number_format((float)$v, 2, '.', ''); }
function erx_date102(?string $d): string { return $d ? date('Ymd', strtotime($d)) : date('Ymd'); }

// Steuer-Kategorie nach EN16931 (UNTDID 5305):
//   S = Normalsatz (>0), K = innergem. Lieferung (0%, EU + USt-IdNr), E = steuerbefreit (sonst 0%).
function erx_tax_kategorie(float $satz, string $land, string $kunde_ustid): string {
    if ($satz > 0) return 'S';
    if ($land !== 'DE' && strlen($kunde_ustid) > 3) return 'K';
    return 'E';
}

// Baut das CII-XML für einen Beleg (Rechnung/Gutschrift). Gibt den XML-String zurück.
function erechnung_cii_xml(int $beleg_id): string {
    $b = one("SELECT b.*, k.firma, k.strasse, k.hausnummer, k.plz, k.ort, k.land, k.ust_id AS kunde_ustid,
                     k.email AS kunde_email, k.kundennummer,
                     k.rechnung_firma, k.rechnung_strasse, k.rechnung_hausnummer, k.rechnung_plz, k.rechnung_ort, k.rechnung_land
                FROM beleg b LEFT JOIN kunden k ON k.id=b.kunde_id WHERE b.id=?", [$beleg_id]);
    if (!$b) return '';
    $firma = beleg_firma();

    // Käufer-Adresse: abweichende Rechnungsadresse hat Vorrang
    $kFirma   = trim((string)($b['rechnung_firma'] ?: $b['firma']));
    $kStrasse = trim((string)(($b['rechnung_strasse'] ?: $b['strasse']) . ' ' . ($b['rechnung_hausnummer'] ?: $b['hausnummer'])));
    $kPlz     = (string)($b['rechnung_plz'] ?: $b['plz']);
    $kOrt     = (string)($b['rechnung_ort'] ?: $b['ort']);
    $kLand    = strtoupper((string)($b['rechnung_land'] ?: $b['land'] ?: 'DE'));
    $kUstId   = trim((string)$b['kunde_ustid']);

    $typeCode = ($b['typ'] === 'gutschrift') ? '381' : '380'; // 381 = Gutschrift (Credit note)
    $nummer   = (string)$b['nummer'];

    // Positionen: vorhandene beleg_position bevorzugen, sonst eine Sammelposition aus dem Kopf.
    $pos = beleg_positionen($beleg_id);
    $satzKopf = (float)$b['ust_prozent'];
    $lines = [];
    if ($pos) {
        $i = 0;
        foreach ($pos as $p) {
            $i++;
            $menge = (float)$p['menge'] ?: 1;
            $einzel = ((int)$p['preis_cent']) / 100;
            $netto = round($menge * $einzel, 2);
            $lines[] = [
                'nr' => $i,
                'name' => (string)$p['bezeichnung'],
                'menge' => $menge,
                'einheit' => (string)($p['einheit'] ?: 'C62'),
                'einzel' => $einzel,
                'netto' => $netto,
                'satz' => (float)$p['mwst_satz'],
            ];
        }
    } else {
        $lines[] = [
            'nr' => 1,
            'name' => 'Leistung laut Rechnung ' . $nummer,
            'menge' => 1.0,
            'einheit' => 'C62',
            'einzel' => (float)$b['netto'],
            'netto' => (float)$b['netto'],
            'satz' => $satzKopf,
        ];
    }

    // Steuer-Gruppen je Satz (für ApplicableTradeTax)
    $steuer = [];
    foreach ($lines as $l) {
        $satz = $l['satz'];
        $key = number_format($satz, 2, '.', '');
        if (!isset($steuer[$key])) $steuer[$key] = ['satz' => $satz, 'basis' => 0.0];
        $steuer[$key]['basis'] += $l['netto'];
    }

    // Einheit normalisieren auf UN/ECE-Code (Stück -> C62)
    $einheitCode = function (string $e): string {
        $e = mb_strtolower(trim($e));
        return match (true) {
            $e === '' || str_contains($e, 'stück') || str_contains($e, 'stk') || $e === 'c62' => 'C62',
            str_contains($e, 'kg') => 'KGM',
            $e === 'g'            => 'GRM',
            str_contains($e, 'liter') || $e === 'l' => 'LTR',
            default => 'C62',
        };
    };

    $lineTotal = 0.0; foreach ($lines as $l) $lineTotal += $l['netto'];
    $netto  = (float)$b['netto'];
    $ust    = (float)$b['ust_betrag'];
    $brutto = (float)$b['brutto'];
    $bezahlt = (float) scalar("SELECT COALESCE(SUM(betrag),0) FROM zahlung WHERE beleg_id=?", [$beleg_id]);
    $faellig = max(0, round($brutto - $bezahlt, 2));

    // --- XML zusammensetzen ---
    $x  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $x .= '<rsm:CrossIndustryInvoice'
        . ' xmlns:rsm="urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100"'
        . ' xmlns:ram="urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100"'
        . ' xmlns:udt="urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100">' . "\n";

    // Kontext / Profil
    $x .= '  <rsm:ExchangedDocumentContext>' . "\n";
    $x .= '    <ram:GuidelineSpecifiedDocumentContextParameter><ram:ID>urn:cen.eu:en16931:2017</ram:ID></ram:GuidelineSpecifiedDocumentContextParameter>' . "\n";
    $x .= '  </rsm:ExchangedDocumentContext>' . "\n";

    // Belegkopf
    $x .= '  <rsm:ExchangedDocument>' . "\n";
    $x .= '    <ram:ID>' . erx_x($nummer) . '</ram:ID>' . "\n";
    $x .= '    <ram:TypeCode>' . $typeCode . '</ram:TypeCode>' . "\n";
    $x .= '    <ram:IssueDateTime><udt:DateTimeString format="102">' . erx_date102($b['datum']) . '</udt:DateTimeString></ram:IssueDateTime>' . "\n";
    if (!empty($b['text'])) {
        $x .= '    <ram:IncludedNote><ram:Content>' . erx_x($b['text']) . '</ram:Content></ram:IncludedNote>' . "\n";
    }
    $x .= '  </rsm:ExchangedDocument>' . "\n";

    $x .= '  <rsm:SupplyChainTradeTransaction>' . "\n";

    // Positionen
    foreach ($lines as $l) {
        $x .= '    <ram:IncludedSupplyChainTradeLineItem>' . "\n";
        $x .= '      <ram:AssociatedDocumentLineDocument><ram:LineID>' . (int)$l['nr'] . '</ram:LineID></ram:AssociatedDocumentLineDocument>' . "\n";
        $x .= '      <ram:SpecifiedTradeProduct><ram:Name>' . erx_x($l['name'] ?: 'Position') . '</ram:Name></ram:SpecifiedTradeProduct>' . "\n";
        $x .= '      <ram:SpecifiedLineTradeAgreement><ram:NetPriceProductTradePrice><ram:ChargeAmount>' . erx_dec($l['einzel']) . '</ram:ChargeAmount></ram:NetPriceProductTradePrice></ram:SpecifiedLineTradeAgreement>' . "\n";
        $x .= '      <ram:SpecifiedLineTradeDelivery><ram:BilledQuantity unitCode="' . erx_x($einheitCode($l['einheit'])) . '">' . erx_dec($l['menge']) . '</ram:BilledQuantity></ram:SpecifiedLineTradeDelivery>' . "\n";
        $kat = erx_tax_kategorie((float)$l['satz'], $kLand, $kUstId);
        $x .= '      <ram:SpecifiedLineTradeSettlement>' . "\n";
        $x .= '        <ram:ApplicableTradeTax><ram:TypeCode>VAT</ram:TypeCode><ram:CategoryCode>' . $kat . '</ram:CategoryCode><ram:RateApplicablePercent>' . erx_dec($l['satz']) . '</ram:RateApplicablePercent></ram:ApplicableTradeTax>' . "\n";
        $x .= '        <ram:SpecifiedTradeSettlementLineMonetarySummation><ram:LineTotalAmount>' . erx_dec($l['netto']) . '</ram:LineTotalAmount></ram:SpecifiedTradeSettlementLineMonetarySummation>' . "\n";
        $x .= '      </ram:SpecifiedLineTradeSettlement>' . "\n";
        $x .= '    </ram:IncludedSupplyChainTradeLineItem>' . "\n";
    }

    // Verkäufer / Käufer
    $x .= '    <ram:ApplicableHeaderTradeAgreement>' . "\n";
    if (!empty($b['kundennummer'])) $x .= '      <ram:BuyerReference>' . erx_x($b['kundennummer']) . '</ram:BuyerReference>' . "\n";
    $x .= '      <ram:SellerTradeParty>' . "\n";
    $x .= '        <ram:Name>' . erx_x($firma['name']) . '</ram:Name>' . "\n";
    $x .= '        <ram:PostalTradeAddress>' . "\n";
    $plzOrt = explode(' ', trim($firma['plz_ort']), 2);
    $x .= '          <ram:PostcodeCode>' . erx_x($plzOrt[0] ?? '') . '</ram:PostcodeCode>' . "\n";
    $x .= '          <ram:LineOne>' . erx_x($firma['strasse']) . '</ram:LineOne>' . "\n";
    $x .= '          <ram:CityName>' . erx_x($plzOrt[1] ?? '') . '</ram:CityName>' . "\n";
    $x .= '          <ram:CountryID>DE</ram:CountryID>' . "\n";
    $x .= '        </ram:PostalTradeAddress>' . "\n";
    if (!empty($firma['email'])) $x .= '        <ram:URIUniversalCommunication><ram:URIID schemeID="EM">' . erx_x($firma['email']) . '</ram:URIID></ram:URIUniversalCommunication>' . "\n";
    if (!empty($firma['ust_id'])) $x .= '        <ram:SpecifiedTaxRegistration><ram:ID schemeID="VA">' . erx_x($firma['ust_id']) . '</ram:ID></ram:SpecifiedTaxRegistration>' . "\n";
    $x .= '      </ram:SellerTradeParty>' . "\n";
    $x .= '      <ram:BuyerTradeParty>' . "\n";
    $x .= '        <ram:Name>' . erx_x($kFirma ?: 'Kunde') . '</ram:Name>' . "\n";
    $x .= '        <ram:PostalTradeAddress>' . "\n";
    $x .= '          <ram:PostcodeCode>' . erx_x($kPlz) . '</ram:PostcodeCode>' . "\n";
    $x .= '          <ram:LineOne>' . erx_x($kStrasse) . '</ram:LineOne>' . "\n";
    $x .= '          <ram:CityName>' . erx_x($kOrt) . '</ram:CityName>' . "\n";
    $x .= '          <ram:CountryID>' . erx_x($kLand) . '</ram:CountryID>' . "\n";
    $x .= '        </ram:PostalTradeAddress>' . "\n";
    if (!empty($b['kunde_email'])) $x .= '        <ram:URIUniversalCommunication><ram:URIID schemeID="EM">' . erx_x($b['kunde_email']) . '</ram:URIID></ram:URIUniversalCommunication>' . "\n";
    if ($kUstId !== '') $x .= '        <ram:SpecifiedTaxRegistration><ram:ID schemeID="VA">' . erx_x($kUstId) . '</ram:ID></ram:SpecifiedTaxRegistration>' . "\n";
    $x .= '      </ram:BuyerTradeParty>' . "\n";
    $x .= '    </ram:ApplicableHeaderTradeAgreement>' . "\n";

    // Lieferung
    $x .= '    <ram:ApplicableHeaderTradeDelivery>' . "\n";
    if (!empty($b['leistung_datum'])) {
        $x .= '      <ram:ActualDeliverySupplyChainEvent><ram:OccurrenceDateTime><udt:DateTimeString format="102">' . erx_date102($b['leistung_datum']) . '</udt:DateTimeString></ram:OccurrenceDateTime></ram:ActualDeliverySupplyChainEvent>' . "\n";
    }
    $x .= '    </ram:ApplicableHeaderTradeDelivery>' . "\n";

    // Abrechnung
    $x .= '    <ram:ApplicableHeaderTradeSettlement>' . "\n";
    $x .= '      <ram:InvoiceCurrencyCode>EUR</ram:InvoiceCurrencyCode>' . "\n";
    foreach ($steuer as $s) {
        $kat = erx_tax_kategorie((float)$s['satz'], $kLand, $kUstId);
        $steuerBetrag = round($s['basis'] * $s['satz'] / 100, 2);
        $x .= '      <ram:ApplicableTradeTax>' . "\n";
        $x .= '        <ram:CalculatedAmount>' . erx_dec($steuerBetrag) . '</ram:CalculatedAmount>' . "\n";
        $x .= '        <ram:TypeCode>VAT</ram:TypeCode>' . "\n";
        if ($kat === 'K') $x .= '        <ram:ExemptionReason>Innergemeinschaftliche Lieferung</ram:ExemptionReason>' . "\n";
        elseif ($kat === 'E') $x .= '        <ram:ExemptionReason>Steuerbefreit</ram:ExemptionReason>' . "\n";
        $x .= '        <ram:BasisAmount>' . erx_dec($s['basis']) . '</ram:BasisAmount>' . "\n";
        $x .= '        <ram:CategoryCode>' . $kat . '</ram:CategoryCode>' . "\n";
        $x .= '        <ram:RateApplicablePercent>' . erx_dec($s['satz']) . '</ram:RateApplicablePercent>' . "\n";
        $x .= '      </ram:ApplicableTradeTax>' . "\n";
    }
    if (!empty($b['faellig'])) {
        $x .= '      <ram:SpecifiedTradePaymentTerms><ram:DueDateDateTime><udt:DateTimeString format="102">' . erx_date102($b['faellig']) . '</udt:DateTimeString></ram:DueDateDateTime></ram:SpecifiedTradePaymentTerms>' . "\n";
    }
    $x .= '      <ram:SpecifiedTradeSettlementHeaderMonetarySummation>' . "\n";
    $x .= '        <ram:LineTotalAmount>' . erx_dec($lineTotal) . '</ram:LineTotalAmount>' . "\n";
    $x .= '        <ram:TaxBasisTotalAmount>' . erx_dec($netto) . '</ram:TaxBasisTotalAmount>' . "\n";
    $x .= '        <ram:TaxTotalAmount currencyID="EUR">' . erx_dec($ust) . '</ram:TaxTotalAmount>' . "\n";
    $x .= '        <ram:GrandTotalAmount>' . erx_dec($brutto) . '</ram:GrandTotalAmount>' . "\n";
    $x .= '        <ram:TotalPrepaidAmount>' . erx_dec($bezahlt) . '</ram:TotalPrepaidAmount>' . "\n";
    $x .= '        <ram:DuePayableAmount>' . erx_dec($faellig) . '</ram:DuePayableAmount>' . "\n";
    $x .= '      </ram:SpecifiedTradeSettlementHeaderMonetarySummation>' . "\n";
    $x .= '    </ram:ApplicableHeaderTradeSettlement>' . "\n";

    $x .= '  </rsm:SupplyChainTradeTransaction>' . "\n";
    $x .= '</rsm:CrossIndustryInvoice>' . "\n";
    return $x;
}

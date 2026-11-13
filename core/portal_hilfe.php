<?php
// Anleitung im Kundenportal (13.11.2026, Fable-Prüfung 5 Anhang E): „Info & Tools“ und der geführte Rundgang in der Sprache des
// Kunden (Deutsch für DE/AT/CH/LI/LU, sonst Englisch – gleiche Regel wie das Etikett-Pop-up, etp_sprache()).
// Je Ablauf eine Karte mit Icon (bx_nav_icon) und 2-4 kurzen Sätzen, in der Reihenfolge des echten Ablaufs.

function portal_hilfe_sprache(int $kunde_id): string {
    return function_exists('etp_sprache') ? etp_sprache($kunde_id) : 'de';
}

// Karten „So funktioniert bulkify“. $L = Menüpunkte des Kunden (Mein Lager nur bei Fulfillment, Nachbestellen nur wenn vorhanden).
function portal_hilfe_ablauf(string $sp, array $L): array {
    $en = $sp === 'en';
    $k = [
        ['plus_kreis', 'Anfrage stellen', 'Make a request',
         'Über „Neue Anfrage“ fragen Sie eine Rezeptur, ein Produkt, einen Rohstoff oder eine Dienstleistung an. Ein paar Angaben genügen – wir melden uns.',
         'Use “New request” to ask for a formula, a product, a raw material or a service. A few details are enough – we will get back to you.'],
        ['rezeptur', 'Rezeptur-Vorschlag annehmen oder ablehnen', 'Accept or reject a formula proposal',
         'Unseren Vorschlag finden Sie unter „Angebote & Anfragen → Zu bestätigen“. Mit „Rezeptur verbindlich annehmen“ legen Sie ihn fest. Passt etwas nicht, lehnen Sie ab und nennen kurz den Grund – wir überarbeiten ihn.',
         'You find our proposal under “Offers & requests → To confirm”. “Accept formula” makes it binding. If something does not fit, reject it and give a short reason – we will revise it.'],
        ['rohstoffe', 'Spezifikation ansehen', 'View the specification',
         'Zu jedem Rohstoff gibt es unsere Spezifikation im Rohstoff-Infoblatt. Sie zeigt Qualität und Grenzwerte.',
         'For every raw material you find our specification in the raw material info sheet. It shows quality and limits.'],
        ['angebote', 'Angebot annehmen oder ablehnen', 'Accept or reject an offer',
         'Unter „Angebote & Anfragen → Zu bestätigen“ wählen Sie die Menge, setzen den Haken und tragen Ihren Namen ein – dann ist die Bestellung verbindlich. Angebote gelten bis zum genannten Datum. Passt es nicht, lehnen Sie mit Grund ab oder nutzen „Menge ändern“.',
         'Under “Offers & requests → To confirm” choose the quantity, tick the box and enter your name – then the order is binding. Offers are valid until the date shown. If it does not fit, reject it with a reason or use “Change quantity”.'],
        ['ordner', 'Produktinformationsblatt (PIB)', 'Product information sheet (PIB)',
         'Das PIB prüfen wir, bevor Sie es bekommen. Danach laden Sie es an der Bestellung herunter. Es ist die Grundlage für Ihr Etikett.',
         'We check the PIB before you receive it. Then you can download it from the order. It is the basis for your label.'],
        ['anhaenger', 'Etikett hochladen und freigeben', 'Upload and approve your label',
         'Laden Sie Ihre Druckdatei (PDF) an der Bestellung hoch. Wir prüfen Format und Druckdaten sofort, eine KI vergleicht die Angaben mit dem PIB. Danach geben Sie mit Ihrem Namen verbindlich frei. Fehlt ein Etikett, zeigt ein rotes Band oben im Portal den Auftrag.',
         'Upload your print file (PDF) at the order. We check size and print data right away, an AI compares the text with the PIB. Then you approve it with your name. If a label is missing, a red bar at the top of the portal shows the order.'],
        ['auftraege', 'Fortschritt', 'Progress',
         'An der Bestellung sehen Sie die wichtigsten Schritte: bestätigt, Material da, produziert, Labor, versendet. Angezeigt wird Ihre Charge (z. B. 2775).',
         'At the order you see the main steps: confirmed, material in stock, produced, laboratory, shipped. Your batch number is shown (e.g. 2775).'],
        ['rezeptur', 'Labor', 'Laboratory',
         'Eine Laboranalyse können Sie zur Bestellung dazubuchen. Das Ergebnis steht nach unserer Freigabe unter „Dokumente“ – je Charge ein Dokument.',
         'You can add a laboratory analysis to your order. After our approval the result is under “Documents” – one document per batch.'],
        ['einkauf', 'Lieferung und Teillieferung', 'Delivery and partial delivery',
         'An der Bestellung finden Sie den Lieferschein (PDF) und die Sendungsnummer mit „Sendung verfolgen“. Bei Teillieferungen gibt es je Lieferung einen eigenen Lieferschein mit Menge.',
         'At the order you find the delivery note (PDF) and the tracking number with “Track shipment”. For partial deliveries there is a separate delivery note with the quantity for each delivery.'],
        ['buchhaltung', 'Rechnungen', 'Invoices',
         'Unter „Rechnungen“ sehen Sie je Rechnung den offenen Betrag und den Status: offen, teilbezahlt oder bezahlt. Die Rechnung gibt es als PDF.',
         'Under “Invoices” you see the open amount and the status of each invoice: open, partly paid or paid. Each invoice is available as a PDF.'],
    ];
    if (isset($L['nachbestellen']))
        $k[] = ['kreispfeil', 'Nachbestellen', 'Reorder',
                'Unter „Nachbestellen“ steht alles, was Sie schon bestellt haben. Menge prüfen und „Verbindlich nachbestellen“ – Verpackung und Etikett bleiben gleich. Hat sich der Preis geändert, geben Sie zuerst den neuen Preis frei.',
                'Under “Reorder” you find everything you have ordered before. Check the quantity and click “Reorder”. Packaging and label stay the same. If the price has changed, approve the new price first.'];
    if (isset($L['fremdprodukte']))
        $k[] = ['lager', 'Mein Lager', 'My warehouse',
                'Hier sehen Sie den Bestand Ihrer bei uns gelagerten Produkte und melden neue Produkte an.',
                'Here you see the stock of your products stored with us and register new products.'];
    return array_map(fn($x) => ['icon' => $x[0], 'titel' => $en ? $x[2] : $x[1], 'text' => $en ? $x[4] : $x[3]], $k);
}

// Feste Texte der Seite „Info & Tools“ und des Rundgangs.
function portal_hilfe_texte(string $sp): array {
    $en = $sp === 'en';
    $t = fn($de, $e) => $en ? $e : $de;
    return [
        'titel' => $t('Info & Tools', 'Info & tools'),
        'sub' => $t('Alles für den Einstieg – so läuft die Zusammenarbeit, und hier finden Sie Ihre Unterlagen.', 'Everything to get started – how we work together, and where to find your documents.'),
        'so' => $t('So funktioniert bulkify', 'How bulkify works'),
        'rundgang' => $t('Geführter Rundgang', 'Guided tour'),
        'schritt' => $t('Schritt', 'Step'),
        'schnell' => $t('Schnell zu Ihren Unterlagen', 'Quick access to your documents'),
        'oeffnen' => $t('Öffnen', 'Open'), 'lesen' => $t('Lesen', 'Read'),
        'k_dok' => [$t('Dokumente', 'Documents'), $t('Alle Unterlagen je Bestellung: Auftragsbestätigung, Rechnung, Lieferschein, PIB, Produktionsbericht, Laboranalyse.', 'All documents per order: order confirmation, invoice, delivery note, PIB, production report, laboratory analysis.')],
        'k_et' => [$t('Etiketten', 'Labels'), $t('Ihre Etiketten je Bestellung hochladen, prüfen und freigeben.', 'Upload, check and approve your labels per order.')],
        'k_ang' => [$t('Angebote & Anfragen', 'Offers & requests'), $t('Angebote prüfen, Menge wählen und verbindlich annehmen – oder mit Grund ablehnen.', 'Check offers, choose the quantity and accept bindingly – or reject with a reason.')],
        'k_best' => [$t('Bestellungen', 'Orders'), $t('Die wichtigsten Schritte von Produktion und Versand Ihrer Aufträge.', 'The main steps of production and shipping of your orders.')],
        'k_konto' => [$t('Mein Konto', 'My account'), $t('Stammdaten, Adressen, Zugang und Mitarbeiter-Zugänge pflegen.', 'Maintain master data, addresses, access and staff logins.')],
        'k_agb' => [$t('AGB', 'Terms'), $t('Unsere Allgemeinen Geschäftsbedingungen nachlesen.', 'Read our general terms and conditions.')],
        'zert' => $t('Herstellerzertifikate', 'Manufacturer certificates'),
        'zert_sub' => $t('Unsere Zertifikate und Nachweise als Hersteller – z. B. für Ihren Händler oder Marktplatz.', 'Our certificates as a manufacturer – e.g. for your retailer or marketplace.'),
        'gueltig' => $t('gültig bis', 'valid until'),
        'fragen' => $t('Noch Fragen? Wir sind für Sie da.', 'Questions? We are here for you.'),
        'fragen_sub' => $t('Schreiben Sie uns jederzeit – wir helfen gern weiter.', 'Write to us at any time – we are happy to help.'),
        'mail' => $t('E-Mail', 'Email'), 'tel' => $t('Telefon', 'Phone'),
        'tour' => [
            ['sel' => null, 'title' => $t('Willkommen in Ihrem Portal', 'Welcome to your portal'), 'text' => $t('Ein kurzer Rundgang (ca. 30 Sekunden) zeigt Ihnen die wichtigsten Bereiche. Sie können jederzeit überspringen.', 'A short tour (about 30 seconds) shows you the main areas. You can skip it at any time.')],
            ['sel' => '.bx-sidesuche', 'title' => $t('Alles schnell finden', 'Find everything quickly'), 'text' => $t('Über die Suche finden Sie Rezepturen, Produkte, Bestellungen und Dokumente.', 'Use the search to find formulas, products, orders and documents.')],
            ['sel' => '#obChecklist', 'title' => $t('Erste Schritte', 'First steps'), 'text' => $t('Diese Checkliste führt Sie vom Konto bis zur ersten Bestellung – Schritt für Schritt.', 'This checklist guides you from your account to your first order – step by step.')],
            ['sel' => 'a[href*="v=meine_anfragen"], a[href*="v=angebote"]', 'title' => $t('Angebote & Anfragen', 'Offers & requests'), 'text' => $t('Hier sehen Sie Ihre Anfragen und Angebote. Unter „Zu bestätigen“ wählen Sie die Menge und nehmen verbindlich an.', 'Here you see your requests and offers. Under “To confirm” you choose the quantity and accept bindingly.')],
            ['sel' => 'a[href*="v=bestellungen"]', 'title' => $t('Bestellungen verfolgen', 'Track your orders'), 'text' => $t('Hier sehen Sie die wichtigsten Schritte von Produktion und Versand, Lieferschein und Sendungsnummer.', 'Here you see the main steps of production and shipping, the delivery note and the tracking number.')],
            ['sel' => 'a[href*="v=etiketten"]', 'title' => $t('Etiketten', 'Labels'), 'text' => $t('Hier laden Sie Ihre Etiketten hoch, wir prüfen sie, und Sie geben sie frei.', 'Here you upload your labels, we check them, and you approve them.')],
            ['sel' => 'a[href*="v=rechnungen"]', 'title' => $t('Rechnungen', 'Invoices'), 'text' => $t('Offene Beträge und Status Ihrer Rechnungen, jede als PDF.', 'Open amounts and status of your invoices, each as a PDF.')],
            ['sel' => 'a[href*="v=nachbestellen"]', 'title' => $t('Nachbestellen', 'Reorder'), 'text' => $t('Bereits bestellte Produkte mit einem Klick erneut bestellen.', 'Reorder products you have ordered before.')],
            ['sel' => 'a[href*="v=konto"]', 'title' => $t('Mein Konto', 'My account'), 'text' => $t('Stammdaten, Adressen und Zugang pflegen Sie jederzeit selbst.', 'You maintain master data, addresses and access yourself.')],
            ['sel' => 'a[href*="v=info"]', 'title' => $t('Info & Tools', 'Info & tools'), 'text' => $t('Hier finden Sie diese Anleitung, Ihre Unterlagen und können den Rundgang jederzeit erneut starten.', 'Here you find this guide, your documents, and you can restart the tour at any time.')],
        ],
        'tour_btn' => ['skip' => $t('Überspringen', 'Skip'), 'prev' => $t('Zurück', 'Back'), 'next' => $t('Weiter', 'Next'), 'done' => $t('Fertig', 'Done'), 'von' => $t('von', 'of')],
    ];
}

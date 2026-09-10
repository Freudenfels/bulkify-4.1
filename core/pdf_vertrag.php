<?php
// Jahresabnahmevertrag (Rahmenvertrag) im bulkify-Design – gefüllt aus dem Angebot + Kundendaten.
// Nutzt die Bausteine aus pdf_spec.php (Kopf, Label-Gitter, Fußzeile, Charcoal-Optik).
require_once __DIR__ . '/pdf_spec.php';

// PDF zu einem Jahresvertrags-Angebot. null = Angebot fehlt / ist kein Jahresvertrag.
function build_jahresvertrag_pdf(int $angebot_id): ?string {
    $a = one("SELECT a.*, COALESCE(NULLIF(p.kundenname,''), p.name) AS produkt_name,
                     p.einheiten_pro_packung, p.rezeptur_id, r.darreichungsform
              FROM angebot a LEFT JOIN produkt p ON p.id=a.produkt_id
              LEFT JOIN rezeptur r ON r.id=p.rezeptur_id WHERE a.id=?", [$angebot_id]);
    if (!$a) return null;
    $k = $a['kunde_id'] ? one("SELECT * FROM kunden WHERE id=?", [(int)$a['kunde_id']]) : null;
    if (!$k) return null;
    $fa = beleg_firma();
    $L = 40; $R = 555;
    $menge = (int)($a['jahresmenge'] ?? 0);
    $vk    = (float)($a['jahres_vk'] ?? 0);
    $mon   = (int)($a['jahres_laufzeit_monate'] ?? 12) ?: 12;
    $gesamt = $menge * $vk;
    $nfMenge = fn($x) => number_format((int)$x, 0, ',', '.');
    $eur = fn($x) => number_format((float)$x, 2, ',', '.') . ' €';
    $vonD = date('d.m.Y');
    $bisD = date('d.m.Y', strtotime('+' . $mon . ' months'));

    $p = new MiniPDF();
    $y = spec_kopf($p, 'JAHRESABNAHMEVERTRAG', 'Rahmenvertrag · ' . (string)$a['produkt_name']);

    // Vertragsparteien
    $kAdr = trim((string)($k['strasse'] ?? '') . ' ' . (string)($k['hausnummer'] ?? ''));
    $kOrt = trim((string)($k['plz'] ?? '') . ' ' . (string)($k['ort'] ?? ''));
    $bestellerAdr = trim(($k['firma'] ?? '') . ($kAdr !== '' ? ', ' . $kAdr : '') . ($kOrt !== '' ? ', ' . $kOrt : '')
                    . (!empty($k['land']) ? ', ' . $k['land'] : ''));
    $y = spec_grid($p, $y, [
        ['Auftragnehmer', $fa['name'] . ', ' . $fa['strasse'] . ', ' . $fa['plz_ort']],
        ['Besteller', $bestellerAdr ?: (string)($k['firma'] ?? '')],
        ['Kundennummer', (string)($k['kundennummer'] ?? '–')],
    ]);

    // Vertragsgegenstand – die hier festgeschriebenen Angaben sind verbindlich und nicht mehr änderbar.
    $einh = (int)($a['einheiten_pro_packung'] ?? 0);
    $form = (string)($a['darreichungsform'] ?? '');
    $stkWort = in_array($form, ['kapsel', 'softgel'], true) ? 'Kapseln' : ($form === 'tablette' ? 'Tabletten' : 'Stück');
    $groesse = function_exists('produktion_groesse_label') && !empty($a['produkt_id'])
        ? produktion_groesse_label((int)$a['produkt_id']) : '';
    $vgz = [
        ['Vertrags-Nr.', (string)$a['nummer']],
        ['Datum', $vonD],
        ['Laufzeit', $vonD . ' – ' . $bisD . ' (' . $mon . ' Monate)'],
        ['Produkt', (string)$a['produkt_name']],
    ];
    if ($groesse !== '') $vgz[] = ['Kapsel-/Tablettengröße', $groesse];
    if ($einh > 0)       $vgz[] = [$stkWort . ' je Packung', $nfMenge($einh)];
    $vgz[] = ['Gesamt-Abnahmemenge', $nfMenge($menge) . ' Packungen'
        . ($einh > 0 ? ' (= ' . $nfMenge($menge * $einh) . ' ' . $stkWort . ')' : '')];
    $vgz[] = ['Festpreis', $eur($vk) . ' / Packung (fest über die gesamte Laufzeit)'];
    $vgz[] = ['Gesamtwert (netto)', $eur($gesamt)];
    $y = spec_h($p, $y, 'Vertragsgegenstand');
    $y = spec_grid($p, $y, $vgz);

    // Inhaltsstoffe / Rezeptur – verbindlich festgeschrieben, danach nicht mehr änderbar.
    $zut = !empty($a['rezeptur_id'])
        ? all("SELECT bezeichnung, menge_mg FROM rezeptur_zutat WHERE rezeptur_id=? ORDER BY sort, id", [(int)$a['rezeptur_id']])
        : [];
    if ($zut) {
        $mgFmt = fn($mg) => (float)$mg >= 1000
            ? rtrim(rtrim(number_format((float)$mg / 1000, 3, ',', '.'), '0'), ',') . ' g'
            : rtrim(rtrim(number_format((float)$mg, 2, ',', '.'), '0'), ',') . ' mg';
        $zrows = array_map(fn($z) => [(string)$z['bezeichnung'], $mgFmt($z['menge_mg'])], $zut);
        $einhWort = in_array($form, ['kapsel', 'softgel'], true) ? 'Kapsel' : ($form === 'tablette' ? 'Tablette' : ($form === 'pulver' ? 'Portion' : 'Einheit'));
        $y = spec_h($p, $y, 'Inhaltsstoffe / Rezeptur (verbindlich festgeschrieben)');
        $y = spec_table($p, $y, [[$L, 'Zutat'], [360, 'Menge je ' . $einhWort]], $zrows);
    }

    // Bedingungen
    $y = spec_h($p, $y, 'Vereinbarung');
    $punkte = [
        'Der Besteller verpflichtet sich, innerhalb der Laufzeit die vereinbarte Gesamt-Abnahmemenge von '
            . $nfMenge($menge) . ' Packungen des genannten Produkts abzunehmen.',
        'Der Preis von ' . $eur($vk) . ' je Packung ist über die gesamte Laufzeit fest und unabhängig von der je Abruf bestellten Menge.',
        'Die Abrufe erfolgen nach Bedarf des Bestellers über das bulkify-Kundenportal (Bereich „Jahresverträge"). '
            . 'Jeder Abruf löst eine verbindliche Bestellung über die abgerufene Teilmenge zum Festpreis aus.',
        'Das Portal weist jederzeit die bereits abgerufene Menge und die verbleibende Restmenge aus.',
        'Nicht abgerufene Restmengen am Laufzeitende werden einvernehmlich abgerechnet oder verlängert; Details nach Absprache.',
        'Es gelten ergänzend die Allgemeinen Geschäftsbedingungen der ' . $fa['name'] . '.',
    ];
    foreach ($punkte as $i => $txt) {
        foreach ($p->wrap(($i + 1) . '.  ' . $txt, $R - $L - 10, 9, false) as $j => $wl) {
            if ($y > 690) { $p->addPage(); $y = 48; }
            $p->text($L + ($j === 0 ? 0 : 14), $y + 10, $wl, 9, false, SPEC_INK);
            $y += 12;
        }
        $y += 3;
    }

    // Unterschriften: links Besteller, rechts bulkify (Signatur + Stempel)
    if ($y > 640) { $p->addPage(); $y = 48; }
    $y += 26;
    $colR = 320; $sy = $y;
    $stp = BX_ROOT . '/assets/bulkify-stamp.jpg'; $sig = BX_ROOT . '/assets/bulkify-signature.jpg';
    if (is_file($sig)) { $d = @file_get_contents($sig); $s = @getimagesize($sig); if ($d && $s) { $id = $p->registerJpeg($d, $s[0], $s[1]); $iw = 92; $ih = $iw * $s[1] / max(1, $s[0]); $p->drawImage($id, $colR, $sy, (int)$iw, (int)$ih); } }
    if (is_file($stp)) { $d = @file_get_contents($stp); $s = @getimagesize($stp); if ($d && $s) { $id = $p->registerJpeg($d, $s[0], $s[1]); $iw = 108; $ih = $iw * $s[1] / max(1, $s[0]); $p->drawImage($id, $colR + 108, $sy + 8, (int)$iw, (int)$ih); } }
    $ly = $sy + 58;
    $p->line($L, $ly, $colR - 30, $ly, 0.5, SPEC_GRAU);
    $p->line($colR, $ly, $R, $ly, 0.5, SPEC_GRAU);
    $p->text($L, $ly + 11, 'Ort, Datum, Unterschrift Besteller (' . (string)($k['firma'] ?? '') . ')', 8.5, false, SPEC_GRAU);
    $p->text($colR, $ly + 11, $fa['name'] . ' · Tabea Albers, Qualitätssicherung · ' . $vonD, 8.5, false, SPEC_GRAU);

    spec_fuss($p, $ly + 30, false);   // kein „ohne Unterschrift gültig" – der Vertrag wird ja unterschrieben
    return $p->output();
}

<?php
// Ablauf einer Bestellung beim Lieferanten – EIN Baustein für zwei Seiten:
// intern (Einkauf) und im Lieferantenportal. So sehen beide dasselbe und es gibt
// keine zwei Wahrheiten darüber, wie weit eine Bestellung ist.

// Panel „Termin, Stationen, Versand".
//   $b       – Zeile aus bestellung
//   $wer     – 'lieferant' oder 'team' (steuert nur die Anrede und den Absender-Namen)
//   $sprache – de | en | zh (Beschriftungen); alles ausser 'de' nutzt die englischen Hilfstexte
//   $aktion  – Prefix der POST-Aktionen (intern 'lief_', im Portal ebenfalls)
function bestellung_ablauf_panel(array $b, string $wer = 'team', string $sprache = 'de'): string {
    $en        = $sprache !== 'de';
    $stationen = bestellung_stationen_fuer($sprache);
    $keys      = array_keys(bestellung_stationen());
    $ist       = bestellung_station_index($b['station'] ?? '');
    $bestaetigt= (int)($b['bestaetigt'] ?? 0) === 1;
    $fertig    = (string)($b['status'] ?? '') === 'geliefert';
    $t = function (string $de, string $enT, string $zh = '') use ($sprache) {
        if ($sprache === 'de') return $de;
        return ($sprache === 'zh' && $zh !== '') ? $zh : $enT;
    };
    $h = fn($x) => htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8');

    $o  = '<div class="bx-panel"><h2 style="margin-top:0">' . ($wer === 'team' ? 'Termin und Versand' : $t('Ablauf', 'Progress', '进度')) . '</h2>';

    // 1) Bestätigung mit Termin – ohne sie geht nichts weiter.
    // 13.11.2026: fehlende Unterlagen sperren das Bestätigen nicht mehr (Ware bleibt nach dem Eingang in Quarantäne).
    $luFeld = ''; $luAus = '';
    if (!$bestaetigt) {
        $o .= '<p class="muted" style="margin-top:0">'
            . $t('Bitte die Bestellung bestätigen und den geplanten Liefertermin nennen. Danach lassen sich die Stationen pflegen.',
                 'Please confirm this order and state the planned delivery date. The progress steps unlock afterwards.',
                 '请确认此订单并填写计划交货日期。之后即可维护各个进度步骤。') . '</p>';
        $o .= '<form method="post" class="bx-row" style="gap:10px;align-items:flex-end;flex-wrap:wrap">'
            . '<input type="hidden" name="aktion" value="lief_bestaetigen">'
            . '<div class="bx-field" style="margin:0;min-width:220px"><label>' . $t('Geplanter Liefertermin', 'Planned delivery date', '计划交货日期') . '</label>'
            . '<input type="date" name="eta_geplant" required></div>'
            . '<div class="bx-field" style="margin:0;min-width:220px"><label>' . $t('Ihr Name', 'Your name', '您的姓名') . '</label>'
            . '<input type="text" name="wer" required placeholder="' . $t('Vor- und Nachname', 'First and last name', '姓名') . '"></div>'
            . $luFeld
            . '<button class="btn btn-primary" type="submit"' . $luAus . '>' . $t('Bestellung bestätigen', 'Confirm order', '确认订单') . '</button>'
            . '</form>';
    } else {
        $direkt = (string)($b['bestellart'] ?? '') === 'direkt';
        // Termin am Bestelltag oder davor = Eingabefehler (BE-2924) -> nicht anzeigen, über „Termin ändern" korrigierbar.
        if (!empty($b['eta_geplant']) && !empty($b['bestelldatum']) && (string)$b['eta_geplant'] <= (string)$b['bestelldatum']) $b['eta_geplant'] = null;
        $o .= '<div class="bx-row" style="gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:12px">'
            . '<span>' . ($direkt ? $t('Erwartet am', 'Expected on', '预计到货') : $t('Zugesagter Termin', 'Confirmed date', '确认交货日期')) . ': <strong>'
            . ($b['eta_geplant'] ? $h(date('d.m.Y', strtotime((string)$b['eta_geplant']))) : '–') . '</strong></span>'
            . ($wer === 'team' && empty($b['angekommen_am'])
                ? '<form method="post" class="bx-row" style="gap:6px;align-items:center;margin:0"><input type="hidden" name="aktion" value="eta_setzen">'
                  . '<input type="date" name="eta" value="' . $h((string)($b['eta_geplant'] ?? '')) . '" style="max-width:160px"><button class="btn btn-ghost btn-sm" type="submit">' . $t('Termin ändern', 'Change date', '修改日期') . '</button></form>' : '')
            . ($b['bestaetigt_von'] ? '<span class="muted" style="font-size:13px">' . $t('bestätigt durch', 'confirmed by', '确认人') . ' ' . $h($b['bestaetigt_von']) . '</span>' : '')
            . ($b['angekommen_am'] ? '<span class="muted" style="font-size:13px">' . $t('Wareneingang', 'Goods received', '收货') . ': ' . $h(date('d.m.Y', strtotime((string)$b['angekommen_am']))) . '</span>'
               : (!empty($b['teilgeliefert_am']) ? '<span class="muted" style="font-size:13px">' . $t('Teillieferung eingegangen', 'Partial delivery received', '部分到货') . ': ' . $h(date('d.m.Y', strtotime((string)$b['teilgeliefert_am']))) . '</span>' : ''))
            . '</div>';

        // 2) Stationen (Design 13.11.2026, Fable-5 D23): einheitliche Schritt-Leiste – erledigt (Haken), aktuell (hervorgehoben),
        //    offen (grau); keine Station sieht wie ein Knopf aus. Darunter genau EIN Knopf für den nächsten Schritt.
        //    „Wareneingang bei bulkify“ setzt nur der Wareneingang (Nacht 4b).
        // Bestellseite 13.11.2026: im Team nur bei Portal-Lieferanten (bei „direkt bestellt“ nur Termin und Tracking).
        $mitStationen = $wer !== 'team' || bestellung_ist_portal($b);
        if ($mitStationen) {
        $o .= '<ol class="bx-schritte">';
        foreach ($keys as $i => $key) {
            $zust = $i <= $ist ? ($i === $ist ? 'jetzt' : 'fertig') : 'offen';
            $o .= '<li class="' . $zust . '"><span class="bx-schritt-p">' . ($i < $ist || ($i === $ist && $fertig) ? '<svg viewBox="0 0 16 16" aria-hidden="true"><path d="M3.5 8.5l3 3 6-7" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>' : ($i + 1)) . '</span><span class="bx-schritt-t">' . $h($stationen[$key]) . '</span></li>';
        }
        $o .= '</ol>';
        $naechster = $keys[$ist + 1] ?? null;
        if (!$fertig && $naechster === 'versendet' && $wer !== 'team')   // Lieferantenportal 13.11.2026: „versendet“ meldet der Bereich Versand
            $o .= '<a class="btn btn-primary btn-sm" href="#versand" style="margin-top:4px">' . $h($t('Nächster Schritt', 'Next step', '下一步')) . ': ' . $h($stationen['versendet']) . ' – ' . $h($t('Versand unten eintragen', 'enter shipping below', '在下方填写发货信息')) . '</a>';
        elseif (!$fertig && $naechster !== null && $naechster !== 'eingegangen')
            $o .= '<form method="post" style="margin:4px 0 0"><input type="hidden" name="aktion" value="lief_station"><input type="hidden" name="station" value="' . $h($naechster) . '">'
                . '<button class="btn btn-primary btn-sm" type="submit">' . $h($t('Nächster Schritt', 'Next step', '下一步')) . ': ' . $h($stationen[$naechster]) . '</button></form>';
        }

        // 3) Lieferantenportal (13.11.2026): hier nur „Produktion geplant“; Versanddaten, Pakete und „versendet“ stehen im Bereich
        //    Versand der Bestellseite (nach Positionen und Dokumenten), damit nichts doppelt abgefragt wird.
        if (!$fertig && $wer !== 'team') {
            $o .= '<form method="post" class="bx-row" style="gap:10px;align-items:flex-end;flex-wrap:wrap;margin-top:16px"><input type="hidden" name="aktion" value="lief_produktion">'
                . '<div class="bx-field" style="margin:0"><label>' . $t('Produktion geplant', 'Production planned', '计划生产日期') . '</label>'
                . '<input type="date" name="produktion_geplant" value="' . $h($b['produktion_geplant'] ?? '') . '"></div>'
                . '<button class="btn btn-ghost btn-sm" type="submit">' . $t('Speichern', 'Save', '保存') . '</button></form>';
        }
        // 3) Versanddaten – Pflicht, bevor „versendet" gesetzt werden kann.
        elseif (!$fertig) {
            // Eine gemeinsame Versandart-Liste für Anfrage/Angebot UND Bestellung – mehrsprachig.
            // Ein in einem Angebot gewählter Alt-Wert bleibt als Option erhalten und wird lesbar beschriftet.
            $va = array_keys(versandart_liste());
            $cur = (string)($b['versandart'] ?? '');
            if ($cur !== '' && !in_array($cur, $va, true)) $va[] = $cur;
            $o .= '<form method="post" style="margin-top:16px"><input type="hidden" name="aktion" value="lief_versand">'
                . '<div class="bx-grid">'
                . '<div class="bx-field"><label>' . $t('Produktion geplant', 'Production planned', '计划生产日期') . '</label>'
                . '<input type="date" name="produktion_geplant" value="' . $h($b['produktion_geplant'] ?? '') . '"></div>'
                . '<div class="bx-field"><label>' . $t('Versandanbieter', 'Carrier', '承运商') . '</label>'
                . '<input type="text" name="versandanbieter" maxlength="60" value="' . $h($b['versandanbieter'] ?? '') . '" placeholder="DHL, Maersk, …"></div>'
                . '<div class="bx-field"><label>' . $t('Versandart', 'Shipping method', '运输方式') . '</label><select name="versandart"><option value="">–</option>';
            foreach ($va as $vk) $o .= '<option value="' . $h($vk) . '"' . ($cur === $vk ? ' selected' : '') . '>' . $h(versandart_label($vk, $sprache)) . '</option>';
            $o .= '</select></div>'
                . '<div class="bx-field"><label>' . $t('Sendungsnummer', 'Tracking number', '物流单号') . '</label>'
                . '<input type="text" name="tracking" maxlength="120" value="' . $h($b['tracking'] ?? '') . '"></div>'
                . (function () use ($b, $t, $h, $sprache) {   // Paket 12.11.2026 Teil 5: Anzahl Pakete aus den Gebindeaufklebern vorschlagen
                    require_once __DIR__ . '/lief_aufkleber.php';
                    $laN = la_stand((int)$b['id'])['gesamt'];
                    $wert = (int)($b['pakete_angekuendigt'] ?? 0) ?: $laN;
                    $hin = la_versand_hinweis((int)$b['id'], $sprache);
                    return '<div class="bx-field"><label>' . $t('Anzahl Pakete', 'Number of packages', '包裹数量') . '</label>'
                         . '<input type="number" min="0" name="pakete_angekuendigt" value="' . ($wert ?: '') . '" style="max-width:140px">'
                         . ($hin !== '' ? '<div class="muted" style="font-size:12px;margin-top:4px">' . $h($hin) . '</div>' : '') . '</div>';
                })()
                . '</div><button class="btn btn-ghost" type="submit">' . $t('Versanddaten speichern', 'Save shipping details', '保存物流信息') . '</button>'
                . '<div class="muted" style="font-size:12px;margin-top:6px">'
                . $t('„versendet" lässt sich erst setzen, wenn Anbieter, Versandart und Sendungsnummer eingetragen sind.',
                     '"Shipped" can only be set once carrier, shipping method and tracking number are filled in.',
                     '只有填写承运商、运输方式和物流单号后，才能设置为“已发货”。')
                . '</div></form>';
        }
    }
    return $o . '</div>';
}

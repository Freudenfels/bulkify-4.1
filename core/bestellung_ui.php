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

    $o  = '<div class="bx-panel"><h2 style="margin-top:0">' . $t('Ablauf', 'Progress', '进度') . '</h2>';

    // 1) Bestätigung mit Termin – ohne sie geht nichts weiter.
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
            . '<button class="btn btn-primary" type="submit">' . $t('Bestellung bestätigen', 'Confirm order', '确认订单') . '</button>'
            . '</form>';
    } else {
        $o .= '<div class="bx-row" style="gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:12px">'
            . '<span>' . $t('Zugesagter Termin', 'Confirmed date', '确认交货日期') . ': <strong>'
            . ($b['eta_geplant'] ? $h(date('d.m.Y', strtotime((string)$b['eta_geplant']))) : '–') . '</strong></span>'
            . ($b['bestaetigt_von'] ? '<span class="muted" style="font-size:13px">' . $t('bestätigt durch', 'confirmed by', '确认人') . ' ' . $h($b['bestaetigt_von']) . '</span>' : '')
            . ($b['angekommen_am'] ? '<span class="muted" style="font-size:13px">' . $t('Wareneingang', 'Goods received', '收货') . ': ' . $h(date('d.m.Y', strtotime((string)$b['angekommen_am']))) . '</span>' : '')
            . '</div>';

        // 2) Stationen – kumulativ, ein Knopf je Schritt.
        $o .= '<div class="bx-row" style="gap:8px;flex-wrap:wrap">';
        foreach ($keys as $i => $key) {
            $erreicht = $i <= $ist;
            if ($fertig || $erreicht) {
                $o .= '<span class="bx-badge ' . ($erreicht ? 'badge-ok' : '') . '" style="padding:6px 12px;border-radius:999px">'
                    . ($erreicht ? '&#10003; ' : '') . $h($stationen[$key]) . '</span>';
            } else {
                $o .= '<form method="post" style="margin:0"><input type="hidden" name="aktion" value="lief_station">'
                    . '<input type="hidden" name="station" value="' . $h($key) . '">'
                    . '<button class="btn btn-ghost btn-sm" type="submit">' . $h($stationen[$key]) . '</button></form>';
            }
        }
        $o .= '</div>';

        // 3) Versanddaten – Pflicht, bevor „versendet" gesetzt werden kann.
        if (!$fertig) {
            $va = versandarten();
            $o .= '<form method="post" style="margin-top:16px"><input type="hidden" name="aktion" value="lief_versand">'
                . '<div class="bx-grid">'
                . '<div class="bx-field"><label>' . $t('Produktion geplant', 'Production planned', '计划生产日期') . '</label>'
                . '<input type="date" name="produktion_geplant" value="' . $h($b['produktion_geplant'] ?? '') . '"></div>'
                . '<div class="bx-field"><label>' . $t('Versandanbieter', 'Carrier', '承运商') . '</label>'
                . '<input type="text" name="versandanbieter" maxlength="60" value="' . $h($b['versandanbieter'] ?? '') . '" placeholder="DHL, Maersk, …"></div>'
                . '<div class="bx-field"><label>' . $t('Versandart', 'Shipping method', '运输方式') . '</label><select name="versandart"><option value="">–</option>';
            foreach ($va as $vk => $vl) $o .= '<option value="' . $h($vk) . '"' . (((string)($b['versandart'] ?? '')) === $vk ? ' selected' : '') . '>' . $h($vl) . '</option>';
            $o .= '</select></div>'
                . '<div class="bx-field"><label>' . $t('Sendungsnummer', 'Tracking number', '物流单号') . '</label>'
                . '<input type="text" name="tracking" maxlength="120" value="' . $h($b['tracking'] ?? '') . '"></div>'
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

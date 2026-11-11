<?php
// Fertigprodukte (Fremdfertigung) gezielt beim Lieferanten anfragen (Nico 11.11.2026).
// Vorher konnte ein Lieferant im Portal („Fertigprodukt-Preise") aus ALLEN Rezepturen seiner freigeschalteten Formen frei
// wählen - blind nach Namen, ohne die Zusammensetzung zu sehen, und er sah dabei auch Kundenrezepturen. Jetzt legen WIR fest,
// welche Rezepturen er bepreisen soll: je Rezeptur eine Fremdfertigungs-Anfrage (lieferant_anfrage, art fertigprodukt).
// Der Lieferant sieht im Portal nur diese (plus schon bepreiste) - mit der Zusammensetzung direkt in der Zeile.
require_once __DIR__ . '/schema.php';

// Rezepturen, die für diesen Lieferanten in Frage kommen: Formen laut Stammdaten (fertig_formen), sonst Kapsel/Tablette/
// Softgel/Stick. Keine abgelehnten, keine leeren. Je Rezeptur: Kunde, offene Aufträge, schon angefragt / schon bepreist.
// Sortierung: Rezepturen aus offenen Aufträgen zuerst, dann nach Name.
function fertig_rezeptur_kandidaten(int $lieferant_id): array {
    $formen = array_values(array_filter(array_map('trim', explode(',', (string) scalar("SELECT fertig_formen FROM lieferanten WHERE id=?", [$lieferant_id])))));
    if (!$formen) $formen = ['kapsel', 'tablette', 'softgel', 'stick'];
    $ph = implode(',', array_fill(0, count($formen), '?'));
    $rows = all("SELECT r.id, r.nummer, r.name, r.darreichungsform AS form, r.status, k.firma AS kunde,
                        (SELECT COUNT(*) FROM auftrag a LEFT JOIN produkt p ON p.id=a.produkt_id
                          WHERE COALESCE(a.rezeptur_id, p.rezeptur_id)=r.id AND a.status IN ('offen','in_produktion')) AS auftraege,
                        (SELECT COUNT(*) FROM lieferant_anfrage la WHERE la.lieferant_id=? AND la.rezeptur_id=r.id AND la.art='fertigprodukt'
                           AND la.status NOT IN ('zurueckgezogen')) AS angefragt,
                        (SELECT COUNT(*) FROM rezeptur_lief_angebot rla WHERE rla.lieferant_id=? AND rla.rezeptur_id=r.id) AS bepreist,
                        (SELECT COUNT(*) FROM rezeptur_zutat z WHERE z.rezeptur_id=r.id) AS zutaten
                 FROM rezeptur r LEFT JOIN kunden k ON k.id=r.kunde_id
                 WHERE r.darreichungsform IN ($ph) AND r.status NOT IN ('abgelehnt')", array_merge([$lieferant_id, $lieferant_id], $formen));
    $rows = array_values(array_filter($rows, fn($r) => (int)$r['zutaten'] > 0));
    usort($rows, fn($a, $b) => [(int)$b['auftraege'] > 0, mb_strtolower((string)$a['name'])] <=> [(int)$a['auftraege'] > 0, mb_strtolower((string)$b['name'])]);
    return $rows;
}

// Je Rezeptur eine Fremdfertigungs-Anfrage an den Lieferanten (wie „Fertigprodukt anfragen" an der Rezeptur), danach EINE
// Sammel-Mail. Schon offen angefragte Rezepturen werden übersprungen. Rückgabe: Liste der neuen Anfrage-IDs.
function fertig_anfragen_stellen(int $lieferant_id, array $rezeptur_ids, string $notiz = ''): array {
    $neu = [];
    foreach (array_values(array_unique(array_filter(array_map('intval', $rezeptur_ids)))) as $rid) {
        $r = one("SELECT id, name, darreichungsform FROM rezeptur WHERE id=?", [$rid]);
        if (!$r) continue;
        if (scalar("SELECT id FROM lieferant_anfrage WHERE lieferant_id=? AND rezeptur_id=? AND art='fertigprodukt' AND status IN ('offen','beantwortet')", [$lieferant_id, $rid])) continue;
        $form = (string)($r['darreichungsform'] ?: 'kapsel');
        $kg = in_array($form, ['kapsel', 'softgel'], true) && function_exists('rezeptur_kapselgroesse') ? rezeptur_kapselgroesse($rid) : null;
        $neu[] = lieferant_anfrage_stellen($lieferant_id, null, 'Fertigprodukt (Bulk): ' . (string)$r['name'], null,
            function_exists('anfrage_einheit_fuer_form') ? anfrage_einheit_fuer_form($form) : '', $notiz, true,
            ['art' => 'fertigprodukt', 'form' => $form, 'rezeptur_id' => $rid, 'kapselgroesse_id' => $kg ? (int)$kg['id'] : 0]);
    }
    if ($neu && function_exists('mail_bereit') && mail_bereit() && function_exists('mail_lieferant_anfrage_sammel')) {
        $ids = $neu;
        if (function_exists('nach_antwort')) nach_antwort(fn() => mail_lieferant_anfrage_sammel($lieferant_id, $ids));
        else mail_lieferant_anfrage_sammel($lieferant_id, $ids);
    }
    return $neu;
}

// Zusammensetzung einer Rezeptur als kleine Tabelle (für das Lieferantenportal, direkt in der Zeile aufklappbar).
function fertig_zusammensetzung_html(int $rezeptur_id, string $sprache = 'de'): string {
    $T = ['zut' => ['de' => 'Zusammensetzung je Einheit', 'en' => 'Composition per unit', 'zh' => '每单位成分'],
          'sum' => ['de' => 'Füllgewicht', 'en' => 'Fill weight', 'zh' => '填充重量'],
          'kap' => ['de' => 'Kapselgröße', 'en' => 'Capsule size', 'zh' => '胶囊尺寸']];
    $s = in_array($sprache, ['de', 'en', 'zh'], true) ? $sprache : 'en';
    $zut = all("SELECT COALESCE(NULLIF(z.bezeichnung,''), i.name) AS name, z.menge_mg FROM rezeptur_zutat z LEFT JOIN item i ON i.id=z.item_id
                WHERE z.rezeptur_id=? ORDER BY z.sort, z.id", [$rezeptur_id]);
    if (!$zut) return '';
    $mg = fn($x) => function_exists('lp_num') ? lp_num($x, 3) : rtrim(rtrim(number_format((float)$x, 3, ',', '.'), '0'), ',');
    $sum = array_sum(array_map(fn($z) => (float)$z['menge_mg'], $zut));
    $kg = function_exists('rezeptur_kapselgroesse') ? rezeptur_kapselgroesse($rezeptur_id) : null;
    $o = '<div style="font-size:12px;margin-top:6px"><div class="muted">' . h($T['zut'][$s]) . '</div><table style="border-collapse:collapse;margin-top:2px">';
    foreach ($zut as $z) $o .= '<tr><td style="padding:1px 14px 1px 0">' . h((string)$z['name']) . '</td><td style="text-align:right;white-space:nowrap">' . h($mg($z['menge_mg'])) . ' mg</td></tr>';
    $o .= '<tr><td style="padding:3px 14px 1px 0" class="muted">' . h($T['sum'][$s]) . '</td><td style="text-align:right;white-space:nowrap" class="muted">' . h($mg($sum)) . ' mg</td></tr>';
    if ($kg) $o .= '<tr><td style="padding:1px 14px 1px 0" class="muted">' . h($T['kap'][$s]) . '</td><td style="text-align:right" class="muted">' . h((string)$kg['name']) . '</td></tr>';
    return $o . '</table></div>';
}

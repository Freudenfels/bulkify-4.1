<?php
// Bereich „Labels / Aufkleber“ an der Bestellung – EIN Baustein für Lieferantenportal und Dashboard (Paket 12.11.2026 Teil 5).
// Je Position und Charge (aus „Chargen & Dokumente“): Charge, Herstelldatum, MHD; Knopf „Aufkleber drucken“ mit Netto,
// Brutto (optional), Stück je Gebinde (optional), Anzahl und Format; Liste der gedruckten Aufkleber mit Nachdruck und Storno.
require_once __DIR__ . '/lief_aufkleber.php';
require_once __DIR__ . '/lief_aufkleber_pdf.php';

function la_t(string $sprache): callable {
    return fn(string $de, string $en, string $zh = '') => $sprache === 'de' ? $de : (($sprache === 'zh' && $zh !== '') ? $zh : $en);
}
function la_sichtbar(array $b): bool {
    return (int)($b['bestaetigt'] ?? 0) === 1 && !in_array((string)($b['status'] ?? ''), ['storniert'], true);
}
// Zahl aus dem Aufkleber-Formular (Nachbesserung 13.11.2026): Mengenfeld-Lesart in der Sprache des Lieferanten (zahl_lesen(..., true)).
// $stueck: Stück je Gebinde sind ganze Zahlen - „12.000“ und „12,000“ sind dann beide 12000 (Tausendertrenner, egal welcher).
function la_zahl(string $s, string $sprache = 'de', bool $stueck = false): ?float {
    $s = trim(str_replace(' ', '', $s));
    if ($s === '') return null;
    if ($stueck && preg_match('/^\d{1,3}([.,]\d{3})+$/', $s)) $s = str_replace(['.', ','], '', $s);
    if (function_exists('zahl_lesen')) { $v = zahl_lesen($s, true, $sprache); return $v > 0 ? (float)$v : null; }
    $s = preg_match('/,\d{1,3}$/', $s) ? str_replace(['.', ','], ['', '.'], $s) : str_replace(',', '', $s);
    return is_numeric($s) && (float)$s > 0 ? (float)$s : null;
}

// GET: PDF ausliefern (ids müssen zu dieser Bestellung gehören). true = ausgeliefert (Aufrufer beendet).
function la_pdf_get(array $b): bool {
    if (!isset($_GET['la_pdf'])) return false;
    $ids = array_values(array_filter(array_map('intval', explode(',', (string)$_GET['la_pdf']))));
    $erlaubt = array_map('intval', array_column(all("SELECT id FROM lief_aufkleber WHERE bestellung_id=? AND status<>'storniert'", [(int)$b['id']]), 'id'));
    $ids = array_values(array_intersect($ids, $erlaubt));
    $fmt = (string)($_GET['la_fmt'] ?? '100x150');
    if (!isset(la_pdf_formate()[$fmt])) $fmt = '100x150';
    $pdf = $ids ? la_pdf($ids, $fmt) : null;
    while (ob_get_level() > 0) ob_end_clean();
    if ($pdf === null) { http_response_code(404); echo 'Not found.'; return true; }
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="Labels_' . preg_replace('/[^A-Za-z0-9-]/', '', (string)$b['nummer']) . '.pdf"');
    header('Content-Length: ' . strlen($pdf));
    echo $pdf;
    return true;
}

// POST verarbeiten (aktion la_*). Rückgabe ['ok'=>bool, 'msg'=>string, 'pdf'=>'ids'|''] oder null, wenn nicht zuständig.
function la_post(array $b, string $von, string $sprache): ?array {
    $aktion = (string)($_POST['aktion'] ?? '');
    if (strncmp($aktion, 'la_', 3) !== 0) return null;
    $t = la_t($sprache);
    $bcId = (int)($_POST['bc_id'] ?? 0);
    $gehoert = $bcId > 0 && (int) scalar("SELECT COUNT(*) FROM bestellung_charge bc JOIN bestellung_position bp ON bp.id=bc.bestellung_position_id WHERE bc.id=? AND bp.bestellung_id=?", [$bcId, (int)$b['id']]) > 0;
    if ($aktion === 'la_herstell' && $gehoert) {
        $d = trim((string)($_POST['herstell_datum'] ?? ''));
        q("UPDATE bestellung_charge SET herstell_datum=? WHERE id=?", [preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? $d : null, $bcId]);
        return ['ok' => true, 'msg' => $t('Herstelldatum gespeichert.', 'Production date saved.', '生产日期已保存。'), 'pdf' => ''];
    }
    if ($aktion === 'la_drucken' && $gehoert) {
        $fehler = '';
        $ids = la_anlegen($bcId, la_zahl((string)($_POST['netto'] ?? ''), $sprache), la_zahl((string)($_POST['brutto'] ?? ''), $sprache), la_zahl((string)($_POST['stueck'] ?? ''), $sprache, true),
                          (int)($_POST['anzahl'] ?? 0), $von, $fehler);
        if (!$ids) return ['ok' => false, 'msg' => ['netto' => $t('Bitte das Nettogewicht je Gebinde angeben.', 'Please enter the net weight per package.', '请填写每件净重。'),
                                                     'anzahl' => $t('Anzahl 1 bis 200.', 'Quantity 1 to 200.', '数量 1 至 200。')][$fehler] ?? $t('Nicht gespeichert.', 'Not saved.', '未保存。'), 'pdf' => ''];
        return ['ok' => true, 'msg' => $t('Aufkleber erstellt.', 'Labels created.', '标签已生成。'), 'pdf' => implode(',', $ids)];
    }
    if ($aktion === 'la_storno') {
        $ok = la_stornieren((int)($_POST['la_id'] ?? 0), (int)$b['id'], $von);
        return ['ok' => $ok, 'msg' => $ok ? $t('Aufkleber storniert.', 'Label cancelled.', '标签已作废。') : $t('Nicht möglich (schon erfasst oder storniert).', 'Not possible (already scanned or cancelled).', '无法作废（已扫描或已作废）。'), 'pdf' => ''];
    }
    return ['ok' => false, 'msg' => $t('Unbekannte Aktion.', 'Unknown action.', '未知操作。'), 'pdf' => ''];
}

// Panel. $ziel = Seite (für Formulare und PDF-Links, z. B. „?p=lieferant_bestellung&id=5“).
function la_panel(array $b, string $ziel, string $von, string $sprache): string {
    if (!la_sichtbar($b)) return '';
    $t = la_t($sprache);
    $pos = la_positionen((int)$b['id']);
    $hatCharge = (bool) array_filter($pos, fn($p) => $p['chargen']);
    $fmtOpt = '';
    foreach (la_pdf_formate() as $k => $lbl)
        $fmtOpt .= '<option value="' . h($k) . '">' . h($sprache === 'de' ? $lbl : ['a4_1' => 'A4, 1 label per page', 'a4_2' => 'A4, 2 labels per page', '100x150' => '100 x 150 mm (label printer)'][$k]) . '</option>';
    $o = '<div class="bx-panel" id="aufkleber"><h2 style="margin-top:0">' . h($t('Labels / Aufkleber', 'Labels', '标签')) . '</h2>'
       . '<p class="muted" style="margin-top:0">' . h($t('Je Gebinde (Sack, Fass, Karton) einen Aufkleber ausdrucken und aufkleben. Unser Lager scannt ihn beim Wareneingang. Der QR enthält nur einen Code, keine Daten.',
            'Print one label per package (bag, drum, carton) and attach it. Our warehouse scans it at goods receipt. The QR contains only a code, no data.',
            '每个包装（袋、桶、箱）打印并粘贴一张标签。我们的仓库在收货时扫描。二维码只包含编码，不含其他数据。')) . '</p>';
    if (!$hatCharge) {
        return $o . '<div class="muted">' . h($t('Bitte zuerst unter „Chargen & Dokumente“ die Charge (mit MHD) eintragen.', 'Please first enter the batch (with expiry) under “Batches & documents”.', '请先在“批次与文件”中填写批号（含有效期）。')) . '</div></div>';
    }
    foreach ($pos as $p) {
        if (!$p['chargen']) continue;
        $o .= '<div style="font-weight:600;margin:12px 0 6px">' . h((string)$p['name']) . ' <span class="muted" style="font-weight:400">· ' . h(la_num((float)$p['menge'])) . ' ' . h((string)$p['einheit']) . '</span></div>';
        foreach ($p['chargen'] as $c) {
            $aktiv = array_filter($c['aufkleber'], fn($x) => $x['status'] !== 'storniert');
            $o .= '<div style="border:1px solid var(--line);border-radius:10px;padding:10px 12px;margin-bottom:10px">'
                . '<div class="bx-row" style="gap:18px;flex-wrap:wrap;align-items:center">'
                . '<div><span class="muted" style="font-size:12px">' . h($t('Charge', 'Batch', '批号')) . '</span><div style="font-weight:600">' . h((string)$c['charge_nr']) . '</div></div>'
                . '<div><span class="muted" style="font-size:12px">' . h($t('MHD', 'Expiry', '有效期')) . '</span><div>' . h($c['mhd'] ? date('d.m.Y', strtotime((string)$c['mhd'])) : '–') . '</div></div>'
                . '<form method="post" action="' . h($ziel) . '#aufkleber" class="bx-row" style="gap:6px;align-items:flex-end;margin:0">'
                . '<input type="hidden" name="aktion" value="la_herstell"><input type="hidden" name="bc_id" value="' . (int)$c['id'] . '">'
                . '<div><span class="muted" style="font-size:12px">' . h($t('Herstelldatum', 'Production date', '生产日期')) . '</span><input type="date" name="herstell_datum" value="' . h((string)($c['herstell_datum'] ?? '')) . '" style="width:auto"></div>'
                . '<button class="btn btn-ghost btn-sm" type="submit">' . h($t('Speichern', 'Save', '保存')) . '</button></form>'
                . '</div>'
                . '<form method="post" action="' . h($ziel) . '" target="_blank" class="bx-row la-druck" style="gap:8px;flex-wrap:wrap;align-items:flex-end;margin:10px 0 0">'
                . '<input type="hidden" name="aktion" value="la_drucken"><input type="hidden" name="bc_id" value="' . (int)$c['id'] . '">'
                . '<div><span class="muted" style="font-size:12px">' . h($t('Netto je Gebinde (kg)', 'Net per package (kg)', '每件净重 (kg)')) . '</span><input type="text" inputmode="decimal" name="netto" required style="width:110px"></div>'
                . '<div><span class="muted" style="font-size:12px">' . h($t('Brutto (kg, optional)', 'Gross (kg, optional)', '毛重 (kg, 可选)')) . '</span><input type="text" inputmode="decimal" name="brutto" style="width:110px"></div>'
                . '<div><span class="muted" style="font-size:12px">' . h($t('Stück je Gebinde (optional)', 'Pieces per package (optional)', '每件数量 (可选)')) . '</span><input type="text" inputmode="numeric" name="stueck" style="width:120px"></div>'
                . '<div><span class="muted" style="font-size:12px">' . h($t('Anzahl', 'Quantity', '数量')) . '</span><input type="number" min="1" max="200" name="anzahl" value="1" required style="width:80px"></div>'
                . '<div><span class="muted" style="font-size:12px">' . h($t('Format', 'Format', '格式')) . '</span><select name="la_fmt">' . $fmtOpt . '</select></div>'
                . '<button class="btn btn-primary btn-sm" type="submit">' . h($t('Aufkleber drucken', 'Print labels', '打印标签')) . '</button></form>';
            if ($c['aufkleber']) {
                $o .= '<div class="bx-tablewrap" style="margin-top:10px"><table class="bx-table" style="font-size:13px"><thead><tr><th>Code</th><th class="bx-num">' . h($t('Netto', 'Net', '净重')) . '</th><th class="bx-num">' . h($t('Brutto', 'Gross', '毛重')) . '</th><th class="bx-num">' . h($t('Stück', 'Pieces', '数量')) . '</th><th>' . h($t('Status', 'Status', '状态')) . '</th><th></th></tr></thead><tbody>';
                foreach ($c['aufkleber'] as $a) {
                    $st = ['aktiv' => $t('gedruckt', 'printed', '已打印'), 'erfasst' => $t('im Lager erfasst', 'received', '已入库'), 'storniert' => $t('storniert', 'cancelled', '已作废')][$a['status']] ?? $a['status'];
                    $o .= '<tr' . ($a['status'] === 'storniert' ? ' class="muted"' : '') . '><td>' . h(la_code((int)$a['id'])) . '</td><td class="bx-num">' . h(la_num((float)$a['netto_kg'])) . ' kg</td>'
                        . '<td class="bx-num">' . ($a['brutto_kg'] !== null ? h(la_num((float)$a['brutto_kg'])) . ' kg' : '–') . '</td><td class="bx-num">' . ($a['stueck'] !== null ? h(number_format((float)$a['stueck'], 0, ',', '.')) : '–') . '</td>'
                        . '<td>' . h($st) . '</td><td style="text-align:right;white-space:nowrap">';
                    if ($a['status'] !== 'storniert')
                        $o .= '<a class="btn btn-ghost btn-sm" target="_blank" href="' . h($ziel . '&la_pdf=' . (int)$a['id'] . '&la_fmt=100x150') . '">' . h($t('Nachdruck', 'Reprint', '重印')) . '</a> ';
                    if ($a['status'] === 'aktiv')
                        $o .= '<form method="post" action="' . h($ziel) . '#aufkleber" style="display:inline;margin:0" onsubmit="return confirm(&#39;' . h($t('Aufkleber stornieren?', 'Cancel label?', '作废此标签？')) . '&#39;);"><input type="hidden" name="aktion" value="la_storno"><input type="hidden" name="la_id" value="' . (int)$a['id'] . '"><button class="btn btn-ghost btn-sm" type="submit">' . h($t('Storno', 'Cancel', '作废')) . '</button></form>';
                    $o .= '</td></tr>';
                }
                $o .= '</tbody></table></div>';
                if (count($aktiv) > 1) $o .= '<div style="margin-top:6px"><a class="btn btn-ghost btn-sm" target="_blank" href="' . h($ziel . '&la_pdf=' . implode(',', array_map(fn($x) => (int)$x['id'], $aktiv)) . '&la_fmt=a4_2') . '">' . h($t('Alle nochmal drucken (A4)', 'Print all again (A4)', '全部重新打印 (A4)')) . '</a></div>';
            }
            $o .= '</div>';
        }
    }
    // Nach dem Drucken (neuer Tab) die Liste neu laden.
    $o .= '<script>document.querySelectorAll(".la-druck").forEach(function(f){f.addEventListener("submit",function(){setTimeout(function(){location.reload();},1500);});});</script>';
    return $o . '</div>';
}

// POST-Ergebnis mit PDF: neue Aufkleber direkt als PDF in den neuen Tab ausgeben (Formular target=_blank).
function la_post_ausgeben(array $b, array $r, string $ziel): void {
    if (!empty($r['pdf'])) {
        $fmt = (string)($_POST['la_fmt'] ?? '100x150');
        header('Location: ' . $ziel . '&la_pdf=' . $r['pdf'] . '&la_fmt=' . (isset(la_pdf_formate()[$fmt]) ? $fmt : '100x150'));
        exit;
    }
    header('Location: ' . $ziel . ($r['ok'] ? '&ok=1' : '&fehler=' . urlencode($r['msg'])) . '#aufkleber');
    exit;
}

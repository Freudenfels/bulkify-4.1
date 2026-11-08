<?php
// Chargen & Dokumente an der Bestellposition (08.11.2026, Soll-Handbuch 4.4) – EIN Baustein für zwei Oberflächen:
// Bestellseite im Dashboard (Team) und Bestellung im Lieferantenportal (Lieferant). Beide sehen und pflegen dasselbe.
//  - Charge, MHD, Menge des Lieferanten je Position: Tabelle bestellung_charge (mehrere Zeilen je Position).
//  - CoA je Charge und Spezifikation je Position: bestehende Ablage `dokument` (objekt_typ='bestellung', objekt_id=Bestellung,
//    bestellung_position_id, charge_nr) – dieselbe Ablage, in die der Lieferant schon heute zur Bestellung hochlädt.
//  - Wareneingang gegen die Bestellung: die Charge bekommt bestellung_position_id; ihre Dokumente ergeben sich daraus
//    (charge_bestell_dokumente) – auch ein später hochgeladenes CoA „wandert" so automatisch an die Charge.
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/lieferant_dateien.php';

function bch_t(string $sprache): callable {
    return fn(string $de, string $en, string $zh = '') => $sprache === 'de' ? $de : (($sprache === 'zh' && $zh !== '') ? $zh : $en);
}
function bch_positionen(int $bestellung_id): array {
    return all("SELECT bp.id, bp.item_id, bp.menge, bp.einheit, COALESCE(NULLIF(bp.bezeichnung,''), i.name, '–') AS name, i.kategorie
                FROM bestellung_position bp LEFT JOIN item i ON i.id=bp.item_id
                WHERE bp.bestellung_id=? ORDER BY bp.sort, bp.id", [$bestellung_id]);
}
function bch_zeilen(int $bp_id): array {
    if (!table_exists('bestellung_charge')) return [];
    return all("SELECT * FROM bestellung_charge WHERE bestellung_position_id=? ORDER BY id", [$bp_id]);
}
// Dokumente einer Position (CoA/Spezifikation), neueste zuerst.
function bch_dokumente(int $bestellung_id, int $bp_id): array {
    return all("SELECT * FROM dokument WHERE objekt_typ='bestellung' AND objekt_id=? AND bestellung_position_id=? ORDER BY id DESC", [$bestellung_id, $bp_id]);
}
// Gültige Spezifikation am Rohstoff (dann nur anzeigen, nicht neu hochladen).
function bch_item_spec(int $item_id): ?array {
    if ($item_id <= 0) return null;
    return one("SELECT id, datei_orig, titel, lieferant_id FROM dokument WHERE objekt_typ='item' AND objekt_id=? AND typ IN ('spec','beides') ORDER BY id DESC LIMIT 1", [$item_id]) ?: null;
}
// Schon eingebuchte Chargen zu dieser Position (Wareneingang gegen die Bestellung).
function bch_eingebucht(int $bp_id): array {
    return all("SELECT id, charge_nr, menge, einheit, wareneingang FROM charge WHERE bestellung_position_id=? ORDER BY id", [$bp_id]);
}
// Dokumente einer eingebuchten Charge aus ihrer Bestellposition: Spezifikation(en) der Position und das CoA mit gleicher
// Chargennummer (tolerant, charge_norm) bzw. ein CoA ohne Chargennummer. Wird nichts gespeichert – die Verbindung ergibt
// sich aus charge.bestellung_position_id, damit auch nachträglich hochgeladene Unterlagen sofort an der Charge hängen.
function charge_bestell_dokumente(int $charge_id): array {
    $c = one("SELECT charge_nr, bestellung_position_id FROM charge WHERE id=?", [$charge_id]);
    if (!$c || empty($c['bestellung_position_id'])) return [];
    $docs = all("SELECT d.* FROM dokument d JOIN bestellung_position bp ON bp.id=d.bestellung_position_id
                 WHERE d.objekt_typ='bestellung' AND d.objekt_id=bp.bestellung_id AND d.bestellung_position_id=? ORDER BY d.id DESC",
                [(int)$c['bestellung_position_id']]);
    return array_values(array_filter($docs, fn($d) => (string)$d['typ'] !== 'coa' || trim((string)($d['charge_nr'] ?? '')) === ''
        || charge_gleich((string)$d['charge_nr'], (string)($c['charge_nr'] ?? ''))));
}
// Beim Wareneingang: welche Position der Bestellung gehört zu dieser Charge? Bevorzugt die Position, an der der Lieferant
// genau diese Chargennummer gemeldet hat; sonst die (erste) Position, auf die die Menge gebucht wurde.
function bch_position_fuer_charge(int $charge_id, array $kandidaten): int {
    $kandidaten = array_values(array_unique(array_filter(array_map('intval', $kandidaten))));
    if (!$kandidaten) return 0;
    $nr = (string) scalar("SELECT charge_nr FROM charge WHERE id=?", [$charge_id]);
    if ($nr !== '' && table_exists('bestellung_charge')) {
        $in = implode(',', $kandidaten);
        foreach (all("SELECT bestellung_position_id, charge_nr FROM bestellung_charge WHERE bestellung_position_id IN ($in) ORDER BY id") as $z)
            if (charge_gleich((string)$z['charge_nr'], $nr)) return (int)$z['bestellung_position_id'];
    }
    return $kandidaten[0];
}
// Fehlt zu einer eingebuchten Charge noch das CoA? (nur Hinweis – blockiert nichts, Handbuch 4.4)
function charge_coa_fehlt(int $charge_id): bool {
    foreach (charge_bestell_dokumente($charge_id) as $d) if ((string)$d['typ'] === 'coa') return false;
    return true;
}

function bch_menge_lesen(string $s): ?float {
    $s = trim($s);
    if ($s === '') return null;
    $f = function_exists('zahl_lesen') ? zahl_lesen($s, true) : (float) str_replace(',', '.', str_replace('.', '', $s));
    return $f > 0 ? $f : null;
}
// Datei aus $_FILES[$feld] als Bestell-Dokument ablegen. Rückgabe [dok_id, fehler].
function bch_datei_speichern(string $feld, array $b, string $typ, int $bp_id, ?string $charge_nr, string $von, callable $t): array {
    if (empty($_FILES[$feld]['name']) || ($_FILES[$feld]['error'] ?? 1) !== UPLOAD_ERR_OK) return [0, ''];
    $orig = (string)$_FILES[$feld]['name'];
    $ext  = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', pathinfo($orig, PATHINFO_EXTENSION)));
    if (!in_array($ext, ['pdf', 'png', 'jpg', 'jpeg', 'webp', 'gif'], true)) return [0, $t('Bitte PDF oder Bild hochladen.', 'Please upload a PDF or an image.', '请上传 PDF 或图片。')];
    if ((int)$_FILES[$feld]['size'] > lieferant_datei_max_mb() * 1024 * 1024) return [0, $t('Die Datei ist zu groß.', 'The file is too large.', '文件过大。')];
    if (!is_dir(BX_UPLOADS)) @mkdir(BX_UPLOADS, 0775, true);
    $fn = 'bestellung_' . (int)$b['id'] . '_' . $typ . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    if (!move_uploaded_file($_FILES[$feld]['tmp_name'], BX_UPLOADS . '/' . $fn)) return [0, $t('Die Datei konnte nicht gespeichert werden.', 'The file could not be saved.', '文件无法保存。')];
    q("INSERT INTO dokument (objekt_typ,objekt_id,typ,lieferant_id,titel,datei,datei_orig,kunde_sichtbar,hochgeladen_von,bestellung_position_id,charge_nr)
       VALUES ('bestellung',?,?,?,?,?,?,0,?,?,?)",
      [(int)$b['id'], $typ, !empty($b['lieferant_id']) ? (int)$b['lieferant_id'] : null,
       ($typ === 'coa' ? 'CoA' : 'Spezifikation') . ($charge_nr ? ' · Charge ' . $charge_nr : ''), $fn, mb_substr($orig, 0, 255),
       $von === 'lieferant' ? 'lieferant' : 'team', $bp_id, $charge_nr ?: null]);
    $did = (int) insert_id();
    log_aktivitaet('bestellung', (int)$b['id'], $von === 'lieferant' ? 'lieferant' : 'team',
        ($typ === 'coa' ? 'CoA' : 'Spezifikation') . ' zur Bestellung hochgeladen' . ($charge_nr ? ' (Charge ' . $charge_nr . ')' : '') . '.', 'dokument', 'dokument', $did);
    return [$did, ''];
}

// POST-Aktionen des Blocks (aktion = bch_*). $von = 'team' | 'lieferant'. Rückgabe null = nicht zuständig, '' = ok, sonst Fehlertext.
function bestellung_chargen_post(array $b, string $von, string $sprache = 'de'): ?string {
    $akt = (string)($_POST['aktion'] ?? '');
    if (strncmp($akt, 'bch_', 4) !== 0) return null;
    $t = bch_t($sprache);
    $bid = (int)$b['id'];
    $bp = (int)($_POST['bp_id'] ?? 0);
    $posOk = fn(int $bp) => $bp > 0 && (int) scalar("SELECT 1 FROM bestellung_position WHERE id=? AND bestellung_id=?", [$bp, $bid]) === 1;
    $wer = $von === 'lieferant' ? 'lieferant' : 'team';

    if ($akt === 'bch_add') {                         // Charge (+ MHD, Menge, optional CoA) an einer Position eintragen
        if (!$posOk($bp)) return $t('Position nicht gefunden.', 'Item not found.', '未找到该项目。');
        $nr = mb_substr(trim((string)($_POST['charge_nr'] ?? '')), 0, 80);
        if ($nr === '') return $t('Bitte die Chargennummer des Lieferanten eintragen.', 'Please enter the batch number.', '请输入批号。');
        $mhd = trim((string)($_POST['mhd'] ?? ''));
        $mhd = preg_match('/^\d{4}-\d{2}-\d{2}$/', $mhd) ? $mhd : null;
        q("INSERT INTO bestellung_charge (bestellung_position_id, charge_nr, mhd, menge, von, angelegt) VALUES (?,?,?,?,?,?)",
          [$bp, $nr, $mhd, bch_menge_lesen((string)($_POST['menge'] ?? '')), $wer, gmdate('Y-m-d H:i:s')]);
        log_aktivitaet('bestellung', $bid, $wer, 'Charge ' . $nr . ($mhd ? ' (MHD ' . date('d.m.Y', strtotime($mhd)) . ')' : '') . ' zur Bestellung eingetragen.', 'bestellung', 'bestellung', $bid);
        [, $f] = bch_datei_speichern('bch_coa', $b, 'coa', $bp, $nr, $von, $t);
        return $f;
    }
    if ($akt === 'bch_coa') {                         // CoA zu einer vorhandenen Charge-Zeile nachreichen
        $z = one("SELECT bc.* FROM bestellung_charge bc JOIN bestellung_position bp ON bp.id=bc.bestellung_position_id WHERE bc.id=? AND bp.bestellung_id=?", [(int)($_POST['zeile_id'] ?? 0), $bid]);
        if (!$z) return $t('Charge nicht gefunden.', 'Batch not found.', '未找到批次。');
        [$did, $f] = bch_datei_speichern('bch_coa', $b, 'coa', (int)$z['bestellung_position_id'], (string)$z['charge_nr'], $von, $t);
        return $f !== '' ? $f : ($did ? '' : $t('Bitte eine Datei auswählen.', 'Please choose a file.', '请选择文件。'));
    }
    if ($akt === 'bch_doc') {                         // CoA ohne Charge-Zeile oder Spezifikation an der Position
        if (!$posOk($bp)) return $t('Position nicht gefunden.', 'Item not found.', '未找到该项目。');
        $typ = (string)($_POST['dok_typ'] ?? '') === 'spec' ? 'spec' : 'coa';
        [$did, $f] = bch_datei_speichern('bch_datei', $b, $typ, $bp, null, $von, $t);
        return $f !== '' ? $f : ($did ? '' : $t('Bitte eine Datei auswählen.', 'Please choose a file.', '请选择文件。'));
    }
    if ($akt === 'bch_del') {                         // Charge-Zeile entfernen (Lieferant nur eigene)
        $z = one("SELECT bc.* FROM bestellung_charge bc JOIN bestellung_position bp ON bp.id=bc.bestellung_position_id WHERE bc.id=? AND bp.bestellung_id=?", [(int)($_POST['zeile_id'] ?? 0), $bid]);
        if (!$z) return '';
        if ($wer === 'lieferant' && (string)$z['von'] !== 'lieferant') return $t('Nur selbst eingetragene Chargen lassen sich entfernen.', 'You can only remove batches you entered yourself.', '只能删除您自己录入的批次。');
        q("DELETE FROM bestellung_charge WHERE id=?", [(int)$z['id']]);
        log_aktivitaet('bestellung', $bid, $wer, 'Charge ' . $z['charge_nr'] . ' aus der Bestellung entfernt.', 'bestellung', 'bestellung', $bid);
        return '';
    }
    if ($akt === 'bch_dok_del') {                     // Dokument entfernen (Lieferant nur eigene)
        $d = one("SELECT * FROM dokument WHERE id=? AND objekt_typ='bestellung' AND objekt_id=? AND bestellung_position_id IS NOT NULL", [(int)($_POST['dok_id'] ?? 0), $bid]);
        if (!$d) return '';
        if ($wer === 'lieferant' && (string)$d['hochgeladen_von'] !== 'lieferant') return $t('Nur selbst hochgeladene Dateien lassen sich entfernen.', 'Only files you uploaded yourself can be removed.', '只能删除您自己上传的文件。');
        @unlink(BX_UPLOADS . '/' . basename((string)$d['datei']));
        q("DELETE FROM dokument WHERE id=?", [(int)$d['id']]);
        return '';
    }
    if ($akt === 'bch_ki' && $wer === 'team') {      // KI liest Charge + MHD aus einem hochgeladenen CoA (Vorschlag, Team bestätigt)
        require_once __DIR__ . '/ki.php'; require_once __DIR__ . '/spec_ki.php';
        if (!ki_bereit()) return 'Die KI ist nicht eingerichtet.';
        $d = one("SELECT * FROM dokument WHERE id=? AND objekt_typ='bestellung' AND objekt_id=? AND typ='coa'", [(int)($_POST['dok_id'] ?? 0), $bid]);
        if (!$d) return 'CoA nicht gefunden.';
        $r = spec_ki_lesen(BX_UPLOADS . '/' . basename((string)$d['datei']));
        if (empty($r['ok'])) return 'KI: ' . ($r['fehler'] ?? 'Dokument nicht lesbar.');
        spec_ki_merken((int)$d['id'], $r);
        return '';
    }
    if ($akt === 'bch_ki_ok' && $wer === 'team') {   // KI-Vorschlag übernehmen -> Charge-Zeile + CoA bekommt die Chargennummer
        $d = one("SELECT * FROM dokument WHERE id=? AND objekt_typ='bestellung' AND objekt_id=? AND typ='coa'", [(int)($_POST['dok_id'] ?? 0), $bid]);
        if (!$d || empty($d['bestellung_position_id'])) return 'CoA nicht gefunden.';
        $nr = mb_substr(trim((string)($_POST['charge_nr'] ?? '')), 0, 80);
        if ($nr === '') return 'Bitte die Chargennummer bestätigen.';
        $mhd = trim((string)($_POST['mhd'] ?? ''));
        $mhd = preg_match('/^\d{4}-\d{2}-\d{2}$/', $mhd) ? $mhd : null;
        q("INSERT INTO bestellung_charge (bestellung_position_id, charge_nr, mhd, menge, von, angelegt) VALUES (?,?,?,?,'team',?)",
          [(int)$d['bestellung_position_id'], $nr, $mhd, bch_menge_lesen((string)($_POST['menge'] ?? '')), gmdate('Y-m-d H:i:s')]);
        q("UPDATE dokument SET charge_nr=?, titel=? WHERE id=?", [$nr, 'CoA · Charge ' . $nr, (int)$d['id']]);
        log_aktivitaet('bestellung', $bid, 'team', 'Charge ' . $nr . ' aus dem CoA übernommen (KI-Vorschlag bestätigt).', 'bestellung', 'bestellung', $bid);
        return '';
    }
    return null;
}

// Der Block „Chargen & Dokumente". $von = 'team' | 'lieferant' (Download-Route, Löschrechte, KI nur Team).
function bestellung_chargen_panel(array $b, string $von = 'team', string $sprache = 'de'): string {
    $t = bch_t($sprache);
    $h = fn($x) => htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8');
    $bid = (int)$b['id'];
    $pos = bch_positionen($bid);
    if (!$pos) return '';
    $dl  = $von === 'lieferant' ? '?p=lieferant_dokument&id=' : '?p=dokument&id=';
    $mz  = fn($x) => rtrim(rtrim(number_format((float)$x, 3, ',', '.'), '0'), ',');
    $dat = fn($d) => $d ? date('d.m.Y', strtotime((string)$d)) : '–';
    $ki  = $von === 'team' && function_exists('ki_bereit') && ki_bereit();
    $docLink = fn(array $d) => '<a href="' . $dl . (int)$d['id'] . '" target="_blank" rel="noopener" style="white-space:nowrap">' . $h($d['datei_orig'] ?: ($d['titel'] ?: 'Dokument')) . '</a>';
    $delDoc = function (array $d) use ($von, $t, $h) {
        if ($von === 'lieferant' && (string)$d['hochgeladen_von'] !== 'lieferant') return '';
        return '<form method="post" style="display:inline;margin:0" onsubmit="return confirm(' . $h(json_encode($t('Datei entfernen?', 'Remove file?', '删除文件？'), JSON_UNESCAPED_UNICODE)) . ');">'
             . '<input type="hidden" name="aktion" value="bch_dok_del"><input type="hidden" name="dok_id" value="' . (int)$d['id'] . '">'
             . '<button class="btn btn-ghost btn-sm" type="submit" title="' . $h($t('entfernen', 'remove', '删除')) . '">×</button></form>';
    };
    $wer = fn(string $v) => $v === 'lieferant' ? $t('Lieferant', 'Supplier', '供应商') : 'bulkify';

    $o  = '<div class="bx-panel" id="chargen"><h2 style="margin-top:0">' . $t('Chargen & Dokumente', 'Batches & documents', '批次与文件') . '</h2>';
    $o .= '<p class="muted" style="margin:0 0 12px;font-size:13px">' . $t(
          'Charge, MHD und CoA je Position (bei Teillieferung oder zwei Chargen eine Zeile je Charge), dazu die Spezifikation. Team und Lieferant pflegen dieselbe Liste. Beim Wareneingang gegen diese Bestellung hängen die Unterlagen automatisch an der Charge.',
          'Batch number, best-before date and CoA per item (one line per batch for partial deliveries or two batches), plus the specification. bulkify and you maintain the same list.',
          '每个项目的批号、保质期和 COA（分批交货或两个批次时每个批次一行），以及规格书。bulkify 和您维护同一列表。') . '</p>';
    foreach ($pos as $p) {
        $bp = (int)$p['id'];
        $zeilen = bch_zeilen($bp);
        $docs = bch_dokumente($bid, $bp);
        $einH = bch_eingebucht($bp);
        $o .= '<div style="border:1px solid var(--line);border-radius:10px;padding:12px 14px;margin-bottom:12px">';
        $o .= '<div style="font-weight:600;margin-bottom:8px">' . $h($p['name']) . ' <span class="muted" style="font-weight:400">· ' . $mz($p['menge']) . ' ' . $h($p['einheit']) . '</span></div>';
        // Charge-Zeilen
        if ($zeilen) {
            $o .= '<div class="bx-tablewrap"><table class="bx-table"><thead><tr><th>' . $t('Charge', 'Batch', '批号') . '</th><th>' . $t('MHD', 'Best before', '保质期') . '</th><th class="bx-num">' . $t('Menge', 'Quantity', '数量') . '</th><th>CoA</th><th>' . $t('Eingebucht', 'Received', '已入库') . '</th><th></th></tr></thead><tbody>';
            foreach ($zeilen as $z) {
                $coas = array_values(array_filter($docs, fn($d) => (string)$d['typ'] === 'coa' && charge_gleich((string)($d['charge_nr'] ?? ''), (string)$z['charge_nr'])));
                $coaHtml = '';
                foreach ($coas as $d) $coaHtml .= $docLink($d) . ' ' . $delDoc($d) . ' ';
                if (!$coas) $coaHtml = '<form method="post" enctype="multipart/form-data" class="bx-row" style="gap:6px;margin:0;flex-wrap:nowrap"><input type="hidden" name="aktion" value="bch_coa"><input type="hidden" name="zeile_id" value="' . (int)$z['id'] . '">'
                    . '<input type="file" name="bch_coa" required accept=".pdf,.png,.jpg,.jpeg,.webp" style="max-width:190px"><button class="btn btn-ghost btn-sm" type="submit">' . $t('CoA hochladen', 'Upload CoA', '上传 COA') . '</button></form>';
                $ein = array_values(array_filter($einH, fn($c) => charge_gleich((string)$c['charge_nr'], (string)$z['charge_nr'])));
                $einHtml = $ein ? $t('ja', 'yes', '是') . ', ' . $dat($ein[0]['wareneingang']) : '<span class="muted">–</span>';
                $del = ($von === 'team' || (string)$z['von'] === 'lieferant')
                    ? '<form method="post" style="margin:0" onsubmit="return confirm(' . $h(json_encode($t('Charge entfernen?', 'Remove batch?', '删除批次？'), JSON_UNESCAPED_UNICODE)) . ');"><input type="hidden" name="aktion" value="bch_del"><input type="hidden" name="zeile_id" value="' . (int)$z['id'] . '"><button class="btn btn-ghost btn-sm" type="submit">' . $t('entfernen', 'remove', '删除') . '</button></form>' : '';
                $o .= '<tr><td><strong>' . $h($z['charge_nr']) . '</strong><div class="muted" style="font-size:11px">' . $h($wer((string)$z['von'])) . '</div></td><td>' . $dat($z['mhd']) . '</td>'
                    . '<td class="bx-num">' . ($z['menge'] !== null ? $mz($z['menge']) . ' ' . $h($p['einheit']) : '<span class="muted">–</span>') . '</td>'
                    . '<td style="white-space:normal">' . $coaHtml . '</td><td>' . $einHtml . '</td><td style="text-align:right">' . $del . '</td></tr>';
            }
            $o .= '</tbody></table></div>';
        }
        // Neue Charge eintragen
        $o .= '<form method="post" enctype="multipart/form-data" class="bx-row" style="gap:10px;align-items:flex-end;flex-wrap:wrap;margin-top:8px">'
            . '<input type="hidden" name="aktion" value="bch_add"><input type="hidden" name="bp_id" value="' . $bp . '">'
            . '<div class="bx-field" style="margin:0;min-width:150px"><label>' . $t('Charge des Lieferanten', 'Batch number', '批号') . '</label><input type="text" name="charge_nr" required maxlength="80"></div>'
            . '<div class="bx-field" style="margin:0;max-width:160px"><label>' . $t('MHD', 'Best before', '保质期') . '</label><input type="date" name="mhd"></div>'
            . '<div class="bx-field" style="margin:0;max-width:130px"><label>' . $t('Menge (optional)', 'Quantity (optional)', '数量（可选）') . '</label><input type="text" name="menge" inputmode="decimal" placeholder="' . $h($p['einheit']) . '"></div>'
            . '<div class="bx-field" style="margin:0;min-width:200px"><label>' . $t('CoA (optional)', 'CoA (optional)', 'COA（可选）') . '</label><input type="file" name="bch_coa" accept=".pdf,.png,.jpg,.jpeg,.webp"></div>'
            . '<button class="btn btn-primary btn-sm" type="submit">' . ($zeilen ? $t('Weitere Charge eintragen', 'Add another batch', '添加批次') : $t('Charge eintragen', 'Add batch', '添加批次')) . '</button></form>';
        // CoA ohne Charge-Zeile (z. B. erst die Datei, Charge später / per KI) + Spezifikation
        $lose = array_values(array_filter($docs, fn($d) => (string)$d['typ'] === 'coa' && trim((string)($d['charge_nr'] ?? '')) === ''));
        $specs = array_values(array_filter($docs, fn($d) => (string)$d['typ'] === 'spec'));
        foreach ($lose as $d) {
            $o .= '<div style="margin-top:10px;font-size:13px">CoA ' . $t('ohne Chargennummer', 'without batch number', '无批号') . ': ' . $docLink($d) . ' ' . $delDoc($d);
            if ($von === 'team') {
                $kd = json_decode((string)($d['ki_daten'] ?? ''), true);
                $vor = is_array($kd) ? ($kd['charge'] ?? []) : [];
                if (!empty($vor['charge_nr']) || !empty($vor['mhd'])) {
                    $o .= '<form method="post" class="bx-row" style="gap:8px;align-items:flex-end;flex-wrap:wrap;margin:6px 0 0"><input type="hidden" name="aktion" value="bch_ki_ok"><input type="hidden" name="dok_id" value="' . (int)$d['id'] . '">'
                        . '<span class="muted">Vorschlag aus dem CoA (KI):</span>'
                        . '<div class="bx-field" style="margin:0;min-width:140px"><label>Charge</label><input type="text" name="charge_nr" value="' . $h($vor['charge_nr'] ?? '') . '" required></div>'
                        . '<div class="bx-field" style="margin:0;max-width:160px"><label>MHD</label><input type="date" name="mhd" value="' . $h($vor['mhd'] ?? '') . '"></div>'
                        . '<div class="bx-field" style="margin:0;max-width:130px"><label>Menge</label><input type="text" name="menge" value="' . $h($vor['menge'] ?? '') . '"></div>'
                        . '<button class="btn btn-primary btn-sm" type="submit">Übernehmen</button></form>';
                } elseif ($ki) {
                    $o .= ' <form method="post" style="display:inline;margin:0"><input type="hidden" name="aktion" value="bch_ki"><input type="hidden" name="dok_id" value="' . (int)$d['id'] . '"><button class="btn btn-ghost btn-sm" type="submit" data-busy="KI liest …">Charge + MHD per KI lesen</button></form>';
                }
            }
            $o .= '</div>';
        }
        $itemSpec = bch_item_spec((int)($p['item_id'] ?? 0));
        $o .= '<div class="bx-row" style="gap:10px;align-items:center;flex-wrap:wrap;margin-top:10px;font-size:13px"><span class="muted">' . $t('Spezifikation', 'Specification', '规格书') . ':</span> ';
        foreach ($specs as $d) $o .= $docLink($d) . ' ' . $delDoc($d) . ' ';
        if (!$specs && $itemSpec) $o .= ($von === 'team' ? '<a href="?p=dokument&id=' . (int)$itemSpec['id'] . '" target="_blank">' . $h($itemSpec['datei_orig'] ?: 'Spezifikation') . '</a>' : $h($itemSpec['datei_orig'] ?: '')) . ' <span class="muted">(' . $t('liegt am Rohstoff vor', 'already on file', '已存档') . ')</span>';
        $o .= '</div>';
        {   // Weiteres Dokument: Spezifikation (nur solange keine vorliegt) oder ein CoA, dessen Charge später eingetragen wird
            $o .= '<form method="post" enctype="multipart/form-data" class="bx-row" style="gap:8px;align-items:flex-end;flex-wrap:wrap;margin-top:8px"><input type="hidden" name="aktion" value="bch_doc"><input type="hidden" name="bp_id" value="' . $bp . '">'
                . '<div class="bx-field" style="margin:0"><label>' . $t('Weiteres Dokument', 'Other document', '其他文件') . '</label><select name="dok_typ">'
                . ((!$specs && !$itemSpec) ? '<option value="spec">' . $t('Spezifikation', 'Specification', '规格书') . '</option>' : '')
                . '<option value="coa">' . $t('CoA (Charge später eintragen)', 'CoA (batch number later)', 'COA（稍后填写批号）') . '</option></select></div>'
                . '<div class="bx-field" style="margin:0;min-width:200px"><label>' . $t('Datei', 'File', '文件') . '</label><input type="file" name="bch_datei" required accept=".pdf,.png,.jpg,.jpeg,.webp"></div>'
                . '<button class="btn btn-ghost btn-sm" type="submit">' . $t('Hochladen', 'Upload', '上传') . '</button></form>';
        }
        $o .= '</div>';
    }
    return $o . '</div>';
}

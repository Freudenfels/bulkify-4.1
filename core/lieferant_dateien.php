<?php
// Dateiablage je Lieferant – EIN Baustein für zwei Seiten: intern (Lieferantenkonto, Reiter
// „Dokumente") und im Lieferantenportal (Menüpunkt „Dateien"). Beide legen Dateien ab und sehen
// dieselbe Liste. Gespeichert wird in der vorhandenen Tabelle `dokument` (objekt_typ='lieferant'),
// die Dateien liegen außerhalb von public in BX_UPLOADS.
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/dokument_ui.php';

function lieferant_datei_endungen(): array {
    return ['pdf', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'doc', 'docx', 'xls', 'xlsx', 'xlsm', 'csv', 'tsv', 'txt', 'zip'];
}
function lieferant_datei_max_mb(): int { return 15; }

// Typ-Beschriftung je Sprache (die deutschen kommen aus dokument_typen()).
function lieferant_datei_typ_label(string $typ, string $sprache = 'de'): string {
    if ($sprache === 'de') return dokument_typen()[$typ] ?? $typ;
    $en = ['coa'=>'CoA (certificate of analysis)', 'spec'=>'Specification', 'analyse'=>'Lab analysis', 'zertifikat'=>'Certificate', 'sonstiges'=>'Other'];
    $zh = ['coa'=>'CoA（分析证书）', 'spec'=>'规格书', 'analyse'=>'检测报告', 'zertifikat'=>'证书', 'sonstiges'=>'其他'];
    return ($sprache === 'zh' ? $zh : $en)[$typ] ?? $typ;
}

// Upload aus $_FILES['dok'] (+ POST dok_typ, dok_titel). $von = 'team' | 'lieferant'. Rückgabe '' oder Fehlertext.
function lieferant_datei_upload(int $lieferant_id, string $von, string $sprache = 'de'): string {
    $t = fn(string $de, string $en, string $zh = '') => $sprache === 'de' ? $de : (($sprache === 'zh' && $zh !== '') ? $zh : $en);
    if ($lieferant_id <= 0) return 'Lieferant fehlt.';
    if (empty($_FILES['dok']['name']) || ($_FILES['dok']['error'] ?? 1) !== UPLOAD_ERR_OK)
        return $t('Bitte eine Datei auswählen.', 'Please choose a file.', '请选择文件。');
    $orig = (string)$_FILES['dok']['name'];
    $ext  = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', pathinfo($orig, PATHINFO_EXTENSION)));
    if (!in_array($ext, lieferant_datei_endungen(), true))
        return $t('Dateityp nicht erlaubt (PDF, Bilder, Office, CSV, TXT, ZIP).', 'File type not allowed (PDF, images, Office, CSV, TXT, ZIP).', '不支持的文件类型（PDF、图片、Office、CSV、TXT、ZIP）。');
    if ((int)$_FILES['dok']['size'] > lieferant_datei_max_mb() * 1024 * 1024)
        return $t('Die Datei ist größer als ' . lieferant_datei_max_mb() . ' MB.', 'The file is larger than ' . lieferant_datei_max_mb() . ' MB.', '文件超过 ' . lieferant_datei_max_mb() . ' MB。');
    if (!is_dir(BX_UPLOADS)) @mkdir(BX_UPLOADS, 0775, true);
    $fn = 'lieferant_' . $lieferant_id . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    if (!move_uploaded_file($_FILES['dok']['tmp_name'], BX_UPLOADS . '/' . $fn))
        return $t('Die Datei konnte nicht gespeichert werden.', 'The file could not be saved.', '文件无法保存。');
    $typ   = array_key_exists((string)($_POST['dok_typ'] ?? ''), dokument_typen()) ? (string)$_POST['dok_typ'] : 'sonstiges';
    $titel = mb_substr(trim((string)($_POST['dok_titel'] ?? '')), 0, 190);
    $von   = $von === 'lieferant' ? 'lieferant' : 'team';
    q("INSERT INTO dokument (objekt_typ,objekt_id,typ,lieferant_id,titel,datei,datei_orig,kunde_sichtbar,hochgeladen_von) VALUES ('lieferant',?,?,?,?,?,?,0,?)",
      [$lieferant_id, $typ, $lieferant_id, $titel !== '' ? $titel : null, $fn, mb_substr($orig, 0, 255), $von]);
    $dokId = (int) insert_id();
    log_aktivitaet('lieferant', $lieferant_id, $von, 'Datei abgelegt: ' . ($titel !== '' ? $titel : $orig), 'dokument', 'dokument', $dokId);
    return '';
}

// Alle Dateien eines Lieferanten: die Ablage selbst plus CoA/Spezifikationen, die er an Artikeln
// abgelegt hat (Preisanfragen). Neueste zuerst.
function lieferant_dateien(int $lieferant_id): array {
    return all("SELECT d.*, i.name AS item_name
                FROM dokument d LEFT JOIN item i ON (d.objekt_typ='item' AND i.id=d.objekt_id)
                WHERE (d.objekt_typ='lieferant' AND d.objekt_id=?) OR (d.objekt_typ='item' AND d.lieferant_id=?)
                ORDER BY d.id DESC", [$lieferant_id, $lieferant_id]);
}

// Darf dieser Lieferant die Datei sehen? Liefert die Zeile oder null. Die Prüfung hängt an der
// Abfrage, nicht an der Oberfläche – für die Download-Route im Portal.
function lieferant_darf_datei(int $lieferant_id, int $dok_id): ?array {
    if ($lieferant_id <= 0 || $dok_id <= 0) return null;
    return one("SELECT * FROM dokument WHERE id=? AND ((objekt_typ='lieferant' AND objekt_id=?) OR (objekt_typ='item' AND lieferant_id=?))",
               [$dok_id, $lieferant_id, $lieferant_id]);
}

// Löschen: das Team alles aus der Ablage, der Lieferant nur, was er selbst hochgeladen hat.
function lieferant_datei_loeschen(int $lieferant_id, int $dok_id, string $von, string $sprache = 'de'): string {
    $t = fn(string $de, string $en, string $zh = '') => $sprache === 'de' ? $de : (($sprache === 'zh' && $zh !== '') ? $zh : $en);
    $d = one("SELECT * FROM dokument WHERE id=? AND objekt_typ='lieferant' AND objekt_id=?", [$dok_id, $lieferant_id]);
    if (!$d) return $t('Datei nicht gefunden.', 'File not found.', '未找到文件。');
    if ($von === 'lieferant' && (string)($d['hochgeladen_von'] ?? 'team') !== 'lieferant')
        return $t('Nur selbst hochgeladene Dateien lassen sich löschen.', 'Only files you uploaded yourself can be deleted.', '只能删除您自己上传的文件。');
    @unlink(BX_UPLOADS . '/' . basename((string)$d['datei']));
    q("DELETE FROM dokument WHERE id=?", [$dok_id]);
    return '';
}

// Panel „Dateien": Liste + Upload-Formular. Gibt HTML zurück.
//   $wer = 'team' | 'lieferant' (steuert Download-Route und Löschrechte), $sprache = de | en | zh
function lieferant_dateien_panel(int $lieferant_id, string $wer = 'team', string $sprache = 'de'): string {
    $t = fn(string $de, string $en, string $zh = '') => $sprache === 'de' ? $de : (($sprache === 'zh' && $zh !== '') ? $zh : $en);
    $h = fn($x) => htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8');
    $firma = (string) scalar("SELECT firma FROM lieferanten WHERE id=?", [$lieferant_id]);
    $docs  = lieferant_dateien($lieferant_id);
    $dl    = $wer === 'lieferant' ? '?p=lieferant_dokument&id=' : '?p=dokument&id=';

    $o  = '<div class="bx-panel"><h2 style="margin-top:0">' . $t('Dateien', 'Files', '文件') . '</h2>';
    $o .= '<p class="muted" style="margin-top:0">' . $t(
            'Zertifikate, Spezifikationen, CoA und andere Unterlagen – beide Seiten legen hier ab und sehen dieselbe Liste. Dazu kommen die CoA und Spezifikationen, die ' . $firma . ' an Preisanfragen hochgeladen hat.',
            'Certificates, specifications, CoA and other documents – both sides upload here and see the same list. The CoA and specifications uploaded with price requests appear here too.',
            '证书、规格书、CoA 及其他文件——双方在此上传并看到相同的列表。随询价上传的 CoA 和规格书也会显示在这里。') . '</p>';
    if (!$docs) {
        $o .= '<div class="muted" style="margin-bottom:12px">' . $t('Noch keine Dateien.', 'No files yet.', '暂无文件。') . '</div>';
    } else {
        $o .= '<div class="bx-tablewrap"><table class="bx-table"><thead><tr>'
            . '<th>' . $t('Art', 'Type', '类型') . '</th><th>' . $t('Titel / Datei', 'Title / file', '标题 / 文件') . '</th>'
            . '<th>' . $t('Bezug', 'Context', '关联') . '</th><th>' . $t('Von', 'From', '来自') . '</th>'
            . '<th>' . $t('Datum', 'Date', '日期') . '</th><th></th></tr></thead><tbody>';
        foreach ($docs as $d) {
            $ablage = $d['objekt_typ'] === 'lieferant';
            $von    = $ablage ? ((string)($d['hochgeladen_von'] ?? 'team') === 'lieferant' ? $firma : 'bulkify') : $firma;
            $bezug  = $ablage ? $t('Ablage', 'Storage', '文件柜') : ($t('Artikel', 'Item', '物料') . ': ' . (string)($d['item_name'] ?? ''));
            $kannLoeschen = $ablage && ($wer === 'team' || (string)($d['hochgeladen_von'] ?? 'team') === 'lieferant');
            $o .= '<tr><td>' . $h(lieferant_datei_typ_label((string)$d['typ'], $sprache)) . '</td>'
                . '<td><a href="' . $dl . (int)$d['id'] . '" target="_blank">' . $h($d['titel'] ?: ($d['datei_orig'] ?: 'Dokument')) . '</a>'
                . ($d['titel'] && $d['datei_orig'] ? '<div class="muted" style="font-size:12px">' . $h($d['datei_orig']) . '</div>' : '') . '</td>'
                . '<td>' . $h($bezug) . '</td><td>' . $h($von) . '</td>'
                . '<td class="muted">' . $h(fmt_zeit($d['angelegt'], 'd.m.Y')) . '</td>'
                . '<td style="text-align:right">' . ($kannLoeschen
                    ? '<form method="post" style="margin:0" onsubmit="return confirm(\'' . $t('Datei löschen?', 'Delete file?', '删除文件？') . '\');">'
                      . '<input type="hidden" name="aktion" value="dok_del"><input type="hidden" name="dok_id" value="' . (int)$d['id'] . '">'
                      . '<button class="btn btn-ghost btn-sm" type="submit">' . $t('Löschen', 'Delete', '删除') . '</button></form>'
                    : '') . '</td></tr>';
        }
        $o .= '</tbody></table></div>';
    }
    $o .= '<form method="post" enctype="multipart/form-data" style="margin-top:14px">'
        . '<input type="hidden" name="aktion" value="dok_upload">'
        . '<div class="bx-grid">'
        . '<div class="bx-field"><label>' . $t('Art', 'Type', '类型') . '</label><select name="dok_typ">';
    foreach (array_keys(dokument_typen()) as $key)
        $o .= '<option value="' . $h($key) . '"' . ($key === 'sonstiges' ? ' selected' : '') . '>' . $h(lieferant_datei_typ_label($key, $sprache)) . '</option>';
    $o .= '</select></div>'
        . '<div class="bx-field"><label>' . $t('Titel (optional)', 'Title (optional)', '标题（可选）') . '</label><input type="text" name="dok_titel" maxlength="190"></div>'
        . '<div class="bx-field"><label>' . $t('Datei', 'File', '文件') . ' <span class="muted" style="font-weight:normal">(' . $t('max.', 'max.', '最大') . ' ' . lieferant_datei_max_mb() . ' MB)</span></label>'
        . '<input type="file" name="dok" required accept=".' . implode(',.', lieferant_datei_endungen()) . '"></div>'
        . '</div>'
        . '<div class="bx-row" style="margin-top:var(--sp-4)"><button class="btn btn-primary" type="submit">' . $t('Datei hochladen', 'Upload file', '上传文件') . '</button></div>'
        . '</form></div>';
    return $o;
}

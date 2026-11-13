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

// Eine Unterlage (CoA/Spezifikation) aus einem bestimmten Datei-Feld ablegen – für den Upload direkt
// im Preisangebot. Ziel ist der ROHSTOFF (objekt_typ='item'), wenn die Anfrage an einem Artikel hängt;
// sonst (z. B. Fertigprodukt-Anfrage) wird sie am LIEFERANTEN abgelegt, mit $titel als Bezug zur Anfrage.
// Gibt die neue Dokument-ID zurück (0 = nichts/Fehler). Die KI-Auswertung ruft der Aufrufer danach auf.
function lieferant_unterlage_speichern(int $lieferant_id, string $file_key, string $typ, string $objekt_typ = 'lieferant', int $objekt_id = 0, string $titel = ''): int {
    if ($lieferant_id <= 0) return 0;
    if (empty($_FILES[$file_key]['name']) || ($_FILES[$file_key]['error'] ?? 1) !== UPLOAD_ERR_OK) return 0;
    $orig = (string)$_FILES[$file_key]['name'];
    $ext  = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', pathinfo($orig, PATHINFO_EXTENSION)));
    if (!in_array($ext, lieferant_datei_endungen(), true)) return 0;
    if ((int)$_FILES[$file_key]['size'] > lieferant_datei_max_mb() * 1024 * 1024) return 0;
    if (!is_dir(BX_UPLOADS)) @mkdir(BX_UPLOADS, 0775, true);
    $fn = 'lief_' . $lieferant_id . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    if (!move_uploaded_file($_FILES[$file_key]['tmp_name'], BX_UPLOADS . '/' . $fn)) return 0;
    $typ = in_array($typ, ['coa', 'spec'], true) ? $typ : 'spec';
    $objekt_typ = in_array($objekt_typ, ['item', 'rezeptur'], true) ? $objekt_typ : 'lieferant';   // rezeptur: Spezifikation des Gesamtprodukts (Paket 12.11.2026)
    $oid = $objekt_typ !== 'lieferant' ? $objekt_id : $lieferant_id;
    q("INSERT INTO dokument (objekt_typ,objekt_id,typ,lieferant_id,titel,datei,datei_orig,kunde_sichtbar,hochgeladen_von) VALUES (?,?,?,?,?,?,?,0,'lieferant')",
      [$objekt_typ, $oid, $typ, $lieferant_id, ($titel !== '' ? mb_substr($titel, 0, 190) : null), $fn, mb_substr($orig, 0, 255)]);
    $did = (int) insert_id();
    log_aktivitaet($objekt_typ === 'item' ? 'item' : ($objekt_typ === 'rezeptur' ? 'rezeptur' : 'lieferant'), $oid, 'lieferant', strtoupper($typ) . ' vom Lieferanten mit dem Preisangebot hochgeladen.', 'dokument', 'dokument', $did);
    return $did;
}

// Alle Dateien eines Lieferanten: die Ablage selbst plus CoA/Spezifikationen, die er an Artikeln
// abgelegt hat (Preisanfragen). Neueste zuerst.
function lieferant_dateien(int $lieferant_id): array {
    return all("SELECT d.*, i.name AS item_name
                FROM dokument d LEFT JOIN item i ON (d.objekt_typ='item' AND i.id=d.objekt_id)
                WHERE (d.objekt_typ='lieferant' AND d.objekt_id=?) OR (d.objekt_typ IN ('item','rezeptur') AND d.lieferant_id=?)
                ORDER BY d.id DESC", [$lieferant_id, $lieferant_id]);
}

// Darf dieser Lieferant die Datei sehen? Liefert die Zeile oder null. Die Prüfung hängt an der
// Abfrage, nicht an der Oberfläche – für die Download-Route im Portal.
function lieferant_darf_datei(int $lieferant_id, int $dok_id): ?array {
    if ($lieferant_id <= 0 || $dok_id <= 0) return null;
    return one("SELECT * FROM dokument WHERE id=? AND (
                   (objekt_typ='lieferant' AND objekt_id=?)
                OR (objekt_typ IN ('item','rezeptur') AND lieferant_id=?)
                OR (objekt_typ='nachricht' AND lieferant_id=?)
                OR (objekt_typ='bestellung' AND lieferant_id=? AND objekt_id IN (SELECT id FROM bestellung WHERE lieferant_id=?)))",
               [$dok_id, $lieferant_id, $lieferant_id, $lieferant_id, $lieferant_id, $lieferant_id]);
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

// Datei direkt an eine BESTELLUNG hängen (CoA, Rechnung, Sonstiges) – vom Lieferanten. So ist das Dokument
// mit genau dieser Bestellung verknüpft (dokument.objekt_typ='bestellung'). Rückgabe: '' ok, sonst Fehlertext.
function bestell_dok_upload(int $bestellung_id, int $lieferant_id, string $typ, string $sprache = 'de', int $ersatz_fuer = 0): string {
    $t = fn(string $de, string $en, string $zh = '') => $sprache === 'de' ? $de : (($sprache === 'zh' && $zh !== '') ? $zh : $en);
    if ($bestellung_id <= 0 || $lieferant_id <= 0) return 'Bestellung/Lieferant fehlt.';
    // Gehört die Bestellung dem Lieferanten?
    if (!scalar("SELECT id FROM bestellung WHERE id=? AND lieferant_id=?", [$bestellung_id, $lieferant_id]))
        return $t('Bestellung nicht gefunden.', 'Order not found.', '未找到订单。');
    if (empty($_FILES['dok']['name']) || ($_FILES['dok']['error'] ?? 1) !== UPLOAD_ERR_OK)
        return $t('Bitte eine Datei auswählen.', 'Please choose a file.', '请选择文件。');
    $orig = (string)$_FILES['dok']['name'];
    $ext  = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', pathinfo($orig, PATHINFO_EXTENSION)));
    if (!in_array($ext, lieferant_datei_endungen(), true))
        return $t('Dateityp nicht erlaubt.', 'File type not allowed.', '不支持的文件类型。');
    if ((int)$_FILES['dok']['size'] > lieferant_datei_max_mb() * 1024 * 1024)
        return $t('Die Datei ist zu groß.', 'The file is too large.', '文件过大。');
    if (!is_dir(BX_UPLOADS)) @mkdir(BX_UPLOADS, 0775, true);
    $fn = 'bestellung_' . $bestellung_id . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    if (!move_uploaded_file($_FILES['dok']['tmp_name'], BX_UPLOADS . '/' . $fn))
        return $t('Die Datei konnte nicht gespeichert werden.', 'The file could not be saved.', '文件无法保存。');
    $typ = in_array($typ, ['coa', 'rechnung', 'lieferschein', 'spec', 'analyse', 'sonstiges'], true) ? $typ : 'sonstiges';
    // Nur EINE Rechnung je Bestellung – hat das Team sie schon (z. B. per Mail erhalten) hochgeladen, keine zweite.
    if ($ersatz_fuer > 0 && !scalar("SELECT id FROM dokument WHERE id=? AND objekt_typ='bestellung' AND objekt_id=? AND typ='rechnung'", [$ersatz_fuer, $bestellung_id])) $ersatz_fuer = 0;
    if ($typ === 'rechnung' && $ersatz_fuer <= 0 && (int) scalar("SELECT COUNT(*) FROM dokument WHERE objekt_typ='bestellung' AND objekt_id=? AND typ='rechnung'", [$bestellung_id]) > 0) {
        @unlink(BX_UPLOADS . '/' . $fn);
        return $t('Zu dieser Bestellung liegt bereits eine Rechnung vor.', 'An invoice for this order is already on file.', '此订单已有发票。');
    }
    q("INSERT INTO dokument (objekt_typ,objekt_id,typ,lieferant_id,titel,datei,datei_orig,kunde_sichtbar,hochgeladen_von) VALUES ('bestellung',?,?,?,?,?,?,0,'lieferant')",
      [$bestellung_id, $typ, $lieferant_id, mb_substr(trim((string)($_POST['dok_titel'] ?? '')), 0, 190) ?: null, $fn, mb_substr($orig, 0, 255)]);
    $dokId = (int) insert_id();
    if ($ersatz_fuer > 0) {   // Fable-6 T4.2: Ersatz – die alte Rechnung bleibt, die neue verweist darauf
        if (function_exists('er_schema')) er_schema();
        try { q("UPDATE dokument SET ersatz_fuer=?, titel=COALESCE(titel, ?) WHERE id=?", [$ersatz_fuer, 'Ersatz-Rechnung', $dokId]); } catch (\Throwable $e) {}
    }
    log_aktivitaet('lieferant', $lieferant_id, 'lieferant', 'Datei zur Bestellung ' . $bestellung_id . ' hochgeladen (' . $typ . ($ersatz_fuer > 0 ? ', Ersatz' : '') . ').', 'dokument', 'dokument', $dokId);
    // 13.11.2026: Rechnung -> Entwurf einer Eingangsrechnung in der Buchhaltung (KI liest nach der Antwort aus, core/eingangsrechnung.php)
    if ($typ === 'rechnung' && function_exists('er_entwurf_aus_dokument')) {
        if (function_exists('nach_antwort')) nach_antwort(fn() => er_entwurf_aus_dokument($dokId, 'portal')); else er_entwurf_aus_dokument($dokId, 'portal');
    }
    return '';
}
// Dokumente einer Bestellung (Lieferant im Portal bzw. Team). $nur_allgemein: ohne die je Position abgelegten CoA/Spezifikationen
// (die zeigt der Block „Chargen & Dokumente", core/bestellung_chargen.php) – damit nichts doppelt erscheint.
function bestell_dokumente(int $bestellung_id, bool $nur_allgemein = false): array {
    $w = $nur_allgemein && column_exists('dokument', 'bestellung_position_id') ? " AND bestellung_position_id IS NULL" : "";
    return all("SELECT id, typ, titel, datei, datei_orig, angelegt, hochgeladen_von FROM dokument WHERE objekt_typ='bestellung' AND objekt_id=?$w ORDER BY id DESC", [$bestellung_id]);
}
// Lieferantenrechnung durch das Team (Bestellseite, Block „Zahlung"): Datei + Rechnungsnummer, Datum, Betrag. Gleicher Platz wie
// der Portal-Upload (dokument typ='rechnung' an der Bestellung); liegt schon eine Rechnung vor, wird keine zweite angelegt.
function bestellung_team_rechnung_hochladen(int $bestellung_id): string {
    $b = one("SELECT id, lieferant_id, nummer FROM bestellung WHERE id=?", [$bestellung_id]);
    if (!$b) return 'Bestellung nicht gefunden.';
    if ((int) scalar("SELECT COUNT(*) FROM dokument WHERE objekt_typ='bestellung' AND objekt_id=? AND typ='rechnung'", [$bestellung_id]) > 0)
        return 'Zu dieser Bestellung liegt bereits eine Rechnung vor.';
    if (empty($_FILES['re_datei']['name']) || ($_FILES['re_datei']['error'] ?? 1) !== UPLOAD_ERR_OK) return 'Bitte die Rechnung (PDF) auswählen.';
    $orig = (string)$_FILES['re_datei']['name'];
    $ext  = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', pathinfo($orig, PATHINFO_EXTENSION)));
    if (!in_array($ext, ['pdf', 'png', 'jpg', 'jpeg', 'webp'], true)) return 'Bitte PDF oder Bild hochladen.';
    if ((int)$_FILES['re_datei']['size'] > lieferant_datei_max_mb() * 1024 * 1024) return 'Die Datei ist zu groß.';
    if (!is_dir(BX_UPLOADS)) @mkdir(BX_UPLOADS, 0775, true);
    $fn = 'bestellung_' . $bestellung_id . '_rechnung_' . bin2hex(random_bytes(6)) . '.' . $ext;
    if (!move_uploaded_file($_FILES['re_datei']['tmp_name'], BX_UPLOADS . '/' . $fn)) return 'Die Datei konnte nicht gespeichert werden.';
    $nr  = mb_substr(trim((string)($_POST['re_nr'] ?? '')), 0, 80);
    $dat = trim((string)($_POST['re_datum'] ?? ''));
    $dat = preg_match('/^\d{4}-\d{2}-\d{2}$/', $dat) ? $dat : null;
    $btrRoh = trim((string)($_POST['re_betrag'] ?? ''));
    $btr = $btrRoh !== '' ? (function_exists('zahl_lesen') ? zahl_lesen($btrRoh) : (float) str_replace(',', '.', str_replace('.', '', $btrRoh))) : null;
    $wae = strtoupper(preg_replace('/[^A-Za-z]/', '', (string)($_POST['re_waehrung'] ?? 'EUR'))) ?: 'EUR';
    q("INSERT INTO dokument (objekt_typ,objekt_id,typ,lieferant_id,titel,datei,datei_orig,kunde_sichtbar,hochgeladen_von,rechnung_nr,dok_datum,betrag,waehrung)
       VALUES ('bestellung',?,'rechnung',?,?,?,?,0,'team',?,?,?,?)",
      [$bestellung_id, !empty($b['lieferant_id']) ? (int)$b['lieferant_id'] : null, 'Rechnung' . ($nr !== '' ? ' ' . $nr : ''), $fn, mb_substr($orig, 0, 255),
       $nr !== '' ? $nr : null, $dat, $btr, $wae]);
    $did = (int) insert_id();
    log_aktivitaet('bestellung', $bestellung_id, 'team', 'Lieferantenrechnung' . ($nr !== '' ? ' ' . $nr : '') . ' vom Team hochgeladen.', 'dokument', 'dokument', $did);
    // 13.11.2026: Entwurf einer Eingangsrechnung in der Buchhaltung (eingetippte Nummer/Datum/Betrag haben Vorrang vor der KI)
    if (function_exists('er_entwurf_aus_dokument')) {
        if (function_exists('nach_antwort')) nach_antwort(fn() => er_entwurf_aus_dokument($did, 'team')); else er_entwurf_aus_dokument($did, 'team');
    }
    return '';
}

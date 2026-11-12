<?php
// Unterlagen-Regel Lieferanten (Paket 12.11.2026 Teil 4, Entscheidung Nico 11.11.2026):
//  - Preis abgeben geht auch OHNE Unterlagen; der Preis trägt dann das Kennzeichen „Unterlagen fehlen“.
//  - Kalkulieren und Angebot erstellen geht weiter (mit Hinweis).
//  - BESTELLEN bei diesem Lieferanten für diesen Artikel geht erst, wenn alle Unterlagen da sind (Admin-Ausnahme mit Grund).
//  - „versendet“ meldet der Lieferant erst mit der CoA je Charge (siehe bestellung_versand_coa_fehlt in bestellung_chargen.php).
// Was gebraucht wird:
//  - je eingesetztem Rohstoff (Katalog-Artikel) eine CoA ODER Spezifikation – liegt sie am Rohstoff schon vor, zählt sie;
//  - bei Fremdfertigung, Premix und Bulk zusätzlich eine Spezifikation des Gesamtprodukts VON DIESEM LIEFERANTEN
//    (dokument objekt_typ='rezeptur', objekt_id=Rezeptur, lieferant_id=Lieferant, typ spec).
// Der Stand wird immer frisch berechnet – lädt der Lieferant nach, verschwindet der Punkt sofort. Siehe lieferant_unterlagen.md.

// Liegt am Rohstoff eine CoA oder Spezifikation vor (egal von wem hochgeladen)?
function lu_rohstoff_hat_dok(int $item_id): bool {
    static $c = [];
    if (!isset($c[$item_id]))
        $c[$item_id] = (int) scalar("SELECT COUNT(*) FROM dokument WHERE objekt_typ='item' AND objekt_id=? AND typ IN ('spec','coa','beides','analyse')", [$item_id]) > 0;
    return $c[$item_id];
}
// Hat der Lieferant eine Spezifikation des Gesamtprodukts (Rezeptur) abgegeben?
function lu_produkt_spec(int $lieferant_id, int $rezeptur_id): bool {
    if ($lieferant_id <= 0 || $rezeptur_id <= 0) return true;
    return (int) scalar("SELECT COUNT(*) FROM dokument WHERE objekt_typ='rezeptur' AND objekt_id=? AND lieferant_id=? AND typ IN ('spec','beides')",
                        [$rezeptur_id, $lieferant_id]) > 0;
}
// Rezeptur zu einem Artikel: Bulk-Artikel (kategorie fertig) und Premix-Artikel hängen an rezeptur_id.
function lu_item_rezeptur(int $item_id): int {
    if ($item_id <= 0) return 0;
    $it = one("SELECT rezeptur_id, kategorie, form FROM item WHERE id=?", [$item_id]);
    if (!$it || empty($it['rezeptur_id'])) return 0;
    if ((string)$it['kategorie'] === 'fertig' || (string)$it['form'] === 'premix') return (int)$it['rezeptur_id'];
    return 0;
}
// Braucht dieser Artikel überhaupt Unterlagen? Verpackung, Etiketten, Verbrauchsmaterial nicht.
function lu_item_pflichtig(int $item_id): bool {
    $k = (string) scalar("SELECT kategorie FROM item WHERE id=?", [$item_id]);
    return in_array($k, ['rohstoff', 'fertig'], true);
}

// Fehlende Unterlagen für Lieferant + (Artikel ODER Rezeptur). Rückgabe: Liste von
// ['art'=>'roh'|'produkt', 'item_id'=>int, 'rezeptur_id'=>int, 'name'=>string, 'braucht'=>'coa_spec'|'spec']
function lu_fehlt(int $lieferant_id, int $item_id = 0, int $rezeptur_id = 0): array {
    if ($lieferant_id <= 0) return [];
    if ($rezeptur_id <= 0 && $item_id > 0) {
        if (!lu_item_pflichtig($item_id)) return [];
        $rezeptur_id = lu_item_rezeptur($item_id);
    }
    $out = [];
    if ($rezeptur_id > 0) {
        foreach (all("SELECT DISTINCT z.item_id, i.name FROM rezeptur_zutat z JOIN item i ON i.id=z.item_id
                      WHERE z.rezeptur_id=? AND z.item_id>0 AND i.kategorie='rohstoff' ORDER BY i.name", [$rezeptur_id]) as $z)
            if (!lu_rohstoff_hat_dok((int)$z['item_id']))
                $out[] = ['art' => 'roh', 'item_id' => (int)$z['item_id'], 'rezeptur_id' => $rezeptur_id, 'name' => (string)$z['name'], 'braucht' => 'coa_spec'];
        if (!lu_produkt_spec($lieferant_id, $rezeptur_id)) {
            $rn = one("SELECT nummer, name FROM rezeptur WHERE id=?", [$rezeptur_id]);
            $out[] = ['art' => 'produkt', 'item_id' => 0, 'rezeptur_id' => $rezeptur_id,
                      'name' => $rn ? trim((string)$rn['name'] . ' (' . (string)$rn['nummer'] . ')') : 'Rezeptur ' . $rezeptur_id, 'braucht' => 'spec'];
        }
        return $out;
    }
    if ($item_id > 0 && !lu_rohstoff_hat_dok($item_id))
        $out[] = ['art' => 'roh', 'item_id' => $item_id, 'rezeptur_id' => 0, 'name' => (string) scalar("SELECT name FROM item WHERE id=?", [$item_id]), 'braucht' => 'coa_spec'];
    return $out;
}

// Was braucht eine Bestellposition? Artikel, sonst (Bulk-Zukauf ohne Artikel) die Rezeptur des Auftrags.
function lu_position_fehlt(int $lieferant_id, array $bp): array {
    $iid = (int)($bp['item_id'] ?? 0);
    if ($iid > 0) return lu_fehlt($lieferant_id, $iid);
    $aid = (int)($bp['auftrag_id'] ?? 0);
    if ($aid > 0 && preg_match('/^(Bulk|Premix):/u', (string)($bp['bezeichnung'] ?? ''))) {
        $a = one("SELECT a.rezeptur_id, p.rezeptur_id AS p_rez FROM auftrag a LEFT JOIN produkt p ON p.id=a.produkt_id WHERE a.id=?", [$aid]);
        $rid = (int)($a['rezeptur_id'] ?? 0) ?: (int)($a['p_rez'] ?? 0);
        if ($rid > 0) return lu_fehlt($lieferant_id, 0, $rid);
    }
    return [];
}

// Bestellung: fehlende Unterlagen über alle Positionen (eindeutig). Online-Bestellungen/ohne Lieferant: nichts.
function lu_bestellung_fehlt(int $bestellung_id): array {
    $b = one("SELECT lieferant_id, bestellart FROM bestellung WHERE id=?", [$bestellung_id]);
    $lid = (int)($b['lieferant_id'] ?? 0);
    if ($lid <= 0 || (string)($b['bestellart'] ?? '') === 'online') return [];
    $out = [];
    foreach (all("SELECT item_id, auftrag_id, bezeichnung FROM bestellung_position WHERE bestellung_id=? AND menge>0", [$bestellung_id]) as $bp)
        foreach (lu_position_fehlt($lid, $bp) as $f) $out[$f['art'] . ':' . $f['item_id'] . ':' . $f['rezeptur_id']] = $f;
    return array_values($out);
}

// Wurde für diese Bestellung schon eine Admin-Ausnahme (mit Grund) erteilt?
function lu_ausnahme(int $bestellung_id): string {
    if (!function_exists('column_exists') || !column_exists('bestellung', 'unterlagen_ausnahme')) return '';
    return (string) scalar("SELECT unterlagen_ausnahme FROM bestellung WHERE id=?", [$bestellung_id]);
}
function lu_ausnahme_setzen(int $bestellung_id, string $grund, string $wer): void {
    q("UPDATE bestellung SET unterlagen_ausnahme=? WHERE id=?", [mb_substr(trim($grund) . ' (' . $wer . ', ' . gmdate('d.m.Y') . ')', 0, 250), $bestellung_id]);
    log_aktivitaet('bestellung', $bestellung_id, 'team', 'Bestellen trotz fehlender Unterlagen (Admin-Ausnahme): ' . trim($grund), 'status');
}

// Sperre fürs Senden/Bestellen: '' = frei, sonst Grund (mit Liste). Admin-Ausnahme gibt frei.
function lu_bestellen_sperre(int $bestellung_id): string {
    $f = lu_bestellung_fehlt($bestellung_id);
    if (!$f || lu_ausnahme($bestellung_id) !== '') return '';
    return 'Bestellen erst, wenn die Unterlagen des Lieferanten da sind. Es fehlen: ' . lu_liste_text($f) . '.';
}

function lu_braucht_text(string $b, string $sprache = 'de'): string {
    $t = ['coa_spec' => ['de' => 'CoA oder Spezifikation', 'en' => 'CoA or specification', 'zh' => 'CoA 或规格书'],
          'spec'     => ['de' => 'Spezifikation des Gesamtprodukts', 'en' => 'specification of the finished product', 'zh' => '成品规格书']];
    return $t[$b][$sprache] ?? $t[$b]['en'];
}
function lu_liste_text(array $f, string $sprache = 'de'): string {
    return implode(', ', array_map(fn($x) => $x['name'] . ' (' . lu_braucht_text($x['braucht'], $sprache) . ')', $f));
}

// Kennzeichen für Preis-Anzeigen: kleines Badge „Unterlagen fehlen“ mit Liste im Tooltip ('' = vollständig).
function lu_badge(int $lieferant_id, int $item_id = 0, int $rezeptur_id = 0, string $sprache = 'de'): string {
    $f = lu_fehlt($lieferant_id, $item_id, $rezeptur_id);
    if (!$f) return '';
    $txt = ['de' => 'Unterlagen fehlen', 'en' => 'Documents missing', 'zh' => '缺少文件'][$sprache] ?? 'Documents missing';
    return ' <span class="badge badge-warn" title="' . h(lu_liste_text($f, $sprache)) . '">' . h($txt) . '</span>';
}

// Alle offenen Punkte eines Lieferanten (Aufgabenliste im Portal): aus seinen Anfragen, Preisen und Fertigprodukt-Preisen.
// Rückgabe: Liste wie lu_fehlt, je Eintrag zusätzlich 'quelle' (Anfrage-Nummer bzw. Preis) – eindeutig je Unterlage.
function lu_offen_lieferant(int $lieferant_id): array {
    if ($lieferant_id <= 0) return [];
    $quellen = [];
    if (table_exists('lieferant_anfrage'))
        foreach (all("SELECT id, nummer, item_id, rezeptur_id FROM lieferant_anfrage WHERE lieferant_id=? AND COALESCE(status,'') NOT IN ('zurueckgezogen','abgelehnt','storniert') ORDER BY id DESC LIMIT 200", [$lieferant_id]) as $a)
            $quellen[] = [(int)$a['item_id'], (int)$a['rezeptur_id'], 'Anfrage ' . (string)($a['nummer'] ?: $a['id']), '?p=lieferant_anfrage&id=' . (int)$a['id']];
    if (table_exists('lieferant_preis'))
        foreach (all("SELECT DISTINCT item_id FROM lieferant_preis WHERE lieferant_id=? AND item_id IS NOT NULL", [$lieferant_id]) as $p)
            $quellen[] = [(int)$p['item_id'], 0, 'Preis', '?p=lieferant_preisliste'];
    if (table_exists('rezeptur_lief_angebot'))
        foreach (all("SELECT DISTINCT rezeptur_id FROM rezeptur_lief_angebot WHERE lieferant_id=? AND rezeptur_id IS NOT NULL", [$lieferant_id]) as $p)
            $quellen[] = [0, (int)$p['rezeptur_id'], 'Fertigprodukt-Preis', '?p=lieferant_rezepturpreise'];
    $out = [];
    foreach ($quellen as [$iid, $rid, $q, $link])
        foreach (lu_fehlt($lieferant_id, $iid, $rid) as $f) {
            $k = $f['art'] . ':' . $f['item_id'] . ':' . $f['rezeptur_id'];
            if (!isset($out[$k])) $out[$k] = $f + ['quellen' => []];
            $out[$k]['quellen'][$q] = $link;
        }
    return array_values($out);
}

// Upload aus der Aufgabenliste (Portal oder intern): Rohstoff -> am Rohstoff (CoA/Spec), Gesamtprodukt -> Spec an der Rezeptur.
// Rückgabe Dokument-ID (0 = nichts).
function lu_upload(int $lieferant_id, string $feld, string $art, int $item_id, int $rezeptur_id, string $typ, string $von = 'lieferant'): int {
    require_once __DIR__ . '/lieferant_dateien.php';
    if ($art === 'produkt') {
        $did = lieferant_unterlage_speichern($lieferant_id, $feld, 'spec', 'rezeptur', $rezeptur_id, 'Spezifikation Gesamtprodukt');
    } else {
        $did = lieferant_unterlage_speichern($lieferant_id, $feld, in_array($typ, ['coa', 'spec'], true) ? $typ : 'spec', 'item', $item_id);
    }
    if ($did && $von === 'team') q("UPDATE dokument SET hochgeladen_von='team' WHERE id=?", [$did]);
    if ($did && function_exists('bedarf_bump')) bedarf_bump();
    return $did;
}

// Aufgabenliste „Diese Unterlagen fehlen noch“ (Portal und intern), mit Upload direkt in der Zeile.
// $nur: optional Filter auf eine Quelle (z. B. ['item_id'=>…, 'rezeptur_id'=>…] einer Anfrage). $sprache de/en/zh.
// Das Formular postet aktion=lu_upload an $ziel (Seite verarbeitet über lu_upload_post()).
function lu_panel(int $lieferant_id, string $ziel, string $sprache = 'de', ?array $nur = null, bool $team = false): string {
    $t = fn(string $de, string $en, string $zh) => $sprache === 'de' ? $de : ($sprache === 'zh' ? $zh : $en);
    $liste = isset($nur['liste']) ? (array)$nur['liste'] : ($nur ? lu_fehlt($lieferant_id, (int)($nur['item_id'] ?? 0), (int)($nur['rezeptur_id'] ?? 0)) : lu_offen_lieferant($lieferant_id));
    if (!$liste) return '';
    $o = '<div class="bx-panel" style="margin:4px 0 14px;padding:12px 14px;border-color:#e7c27a">'
       . '<div style="font-weight:600;margin-bottom:2px">' . h($t('Diese Unterlagen fehlen noch', 'These documents are still missing', '以下文件仍缺失')) . '</div>'
       . '<div class="muted" style="font-size:12px;margin-bottom:10px">' . h($team ? 'Unterlage hier im Namen des Lieferanten nachreichen (z. B. per Mail erhalten) – sie zählt sofort.' : $t(
            'Ihr Preis ist gespeichert und trägt das Kennzeichen „Unterlagen fehlen“. Bestellen können wir erst, wenn alles da ist. Je Rohstoff genügt eine CoA oder Spezifikation; für das Gesamtprodukt brauchen wir Ihre Spezifikation.',
            'Your price is saved and marked “documents missing”. We can only order once everything is here. One CoA or specification per raw material is enough; for the finished product we need your specification.',
            '您的报价已保存，并标记为“缺少文件”。文件齐全后我们才能下单。每种原料提供一份 CoA 或规格书即可；成品需要您的规格书。')) . '</div>'
       . '<div class="bx-tablewrap"><table class="bx-table"><thead><tr><th>' . h($t('Unterlage', 'Document', '文件')) . '</th><th>' . h($t('für', 'for', '用于')) . '</th><th></th></tr></thead><tbody>';
    foreach ($liste as $i => $f) {
        $quellen = '';
        foreach (($f['quellen'] ?? []) as $q => $link) $quellen .= ($quellen ? ', ' : '') . '<a href="' . h($link) . '">' . h($q) . '</a>';
        $o .= '<tr><td style="min-width:200px">' . h($f['name']) . '<div class="muted" style="font-size:12px">' . h(lu_braucht_text($f['braucht'], $sprache)) . '</div></td>'
            . '<td class="muted" style="font-size:12px">' . ($quellen ?: '–') . '</td><td>'
            . '<form method="post" action="' . h($ziel) . '" enctype="multipart/form-data" class="bx-row" style="gap:8px;flex-wrap:nowrap;margin:0">'
            . '<input type="hidden" name="aktion" value="lu_upload"><input type="hidden" name="lu_art" value="' . h($f['art']) . '">'
            . '<input type="hidden" name="lu_item" value="' . (int)$f['item_id'] . '"><input type="hidden" name="lu_rez" value="' . (int)$f['rezeptur_id'] . '">'
            . ($f['art'] === 'roh'
                ? '<select name="lu_typ"><option value="coa">CoA</option><option value="spec">' . h($t('Spezifikation', 'Specification', '规格书')) . '</option></select>'
                : '<input type="hidden" name="lu_typ" value="spec">')
            . '<input type="file" name="lu_datei" accept="application/pdf,image/*" required>'
            . '<button class="bx-btn" type="submit">' . h($t('Hochladen', 'Upload', '上传')) . '</button></form></td></tr>';
    }
    return $o . '</tbody></table></div></div>';
}

// POST aus lu_panel verarbeiten. Rückgabe ['ok'=>bool, 'did'=>int]. KI-Auswertung danach (wie beim Preisangebot).
// Darf dieser Lieferant zu diesem Artikel / dieser Rezeptur eine Unterlage hochladen? (Nachbesserung 13.11.2026)
// Nur, was in SEINER offenen Unterlagen-Liste steht: Aufgabenliste (Anfragen, Preise, Fertigprodukt-Preise) plus die fehlenden
// Unterlagen seiner laufenden Bestellungen. Sonst könnte ein Lieferant über lu_item/lu_rez Dateien an fremde Artikel hängen.
function lu_upload_erlaubt(int $lieferant_id, string $art, int $item_id, int $rezeptur_id): bool {
    if ($lieferant_id <= 0) return false;
    $liste = lu_offen_lieferant($lieferant_id);
    foreach (all("SELECT id FROM bestellung WHERE lieferant_id=? AND status NOT IN ('geliefert','storniert') ORDER BY id DESC LIMIT 200", [$lieferant_id]) as $b)
        foreach (lu_bestellung_fehlt((int)$b['id']) as $f) $liste[] = $f;
    foreach ($liste as $f) {
        if ($art === 'roh' && $f['art'] === 'roh' && (int)$f['item_id'] === $item_id) return true;
        if ($art === 'produkt' && $f['art'] === 'produkt' && (int)$f['rezeptur_id'] === $rezeptur_id) return true;
    }
    return false;
}
function lu_upload_post(int $lieferant_id, string $von = 'lieferant'): array {
    $art = (string)($_POST['lu_art'] ?? '') === 'produkt' ? 'produkt' : 'roh';
    $iid = (int)($_POST['lu_item'] ?? 0); $rid = (int)($_POST['lu_rez'] ?? 0);
    if ($art === 'roh' && $iid <= 0) return ['ok' => false, 'did' => 0];
    if ($art === 'produkt' && $rid <= 0) return ['ok' => false, 'did' => 0];
    if ($von !== 'team' && !lu_upload_erlaubt($lieferant_id, $art, $iid, $rid)) {   // Team (intern) darf weiter frei ablegen
        if (function_exists('log_aktivitaet')) {
            try { log_aktivitaet('lieferant', $lieferant_id, 'lieferant',
                                 'Unterlagen-Upload abgelehnt: ' . ($art === 'roh' ? 'Artikel #' . $iid : 'Rezeptur #' . $rid) . ' steht nicht in der offenen Unterlagen-Liste dieses Lieferanten', 'status'); } catch (\Throwable $e) {}
        }
        return ['ok' => false, 'did' => 0, 'abgelehnt' => true];
    }
    $did = lu_upload($lieferant_id, 'lu_datei', $art, $iid, $rid, (string)($_POST['lu_typ'] ?? 'spec'), $von);
    return ['ok' => $did > 0, 'did' => $did];
}
// Nach der Antwort an den Browser: KI liest die neue Unterlage (bulkify-Format), Rohstoff-Entwurf wird aktiv.
function lu_upload_nachlauf(int $did): void {
    if ($did <= 0) return;
    if (function_exists('ki_antwort_abschliessen')) ki_antwort_abschliessen();
    try {
        require_once __DIR__ . '/spec_ki.php';
        $d = one("SELECT objekt_typ, objekt_id FROM dokument WHERE id=?", [$did]);
        if ($d && $d['objekt_typ'] === 'item') {   // Spezifikation des Gesamtprodukts (rezeptur) wird nur abgelegt, nicht in Stammdaten gelesen
            spec_ki_nach_upload($did);
            if (function_exists('rohstoff_entwurf_aktivieren')) rohstoff_entwurf_aktivieren((int)$d['objekt_id']);
        }
    } catch (\Throwable $e) {}
}

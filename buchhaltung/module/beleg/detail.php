<?php
// Rechnung (Beleg) – Ansicht + Status (offen/bezahlt)
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';
require_once BX_ROOT . '/core/importer.php';   // verknüpftes (archiviertes) Angebot + Positionen
require_once BX_ROOT . '/core/mahnung.php';     // Mahnhistorie zum Beleg

$id = (int)($_GET['id'] ?? 0);

// Hochgeladene Original-Rechnung (Alt-Import) im Team-Bereich ansehen.
if ($id && isset($_GET['original'])) {
    $bo = one("SELECT original_datei, original_orig FROM beleg WHERE id=?", [$id]);
    $pf = $bo && !empty($bo['original_datei']) ? BX_UPLOADS . '/' . basename((string)$bo['original_datei']) : '';
    if (!$pf || !is_file($pf)) { http_response_code(404); echo 'Keine Original-Datei.'; exit; }
    $ext = strtolower(pathinfo($pf, PATHINFO_EXTENSION));
    $mime = ['pdf'=>'application/pdf','png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','webp'=>'image/webp','gif'=>'image/gif'][$ext] ?? 'application/octet-stream';
    header('Content-Type: ' . $mime);
    header('Content-Disposition: inline; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', (string)($bo['original_orig'] ?: 'Rechnung')) . '"');
    header('Content-Length: ' . filesize($pf));
    readfile($pf); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $id) {
    $aktion = $_POST['aktion'] ?? 'status';
    $akteur = (function_exists('current_user') && ($u = current_user())) ? $u['name'] : 'team';

    // Fuer den Kunden freigeben / Freigabe zurueckziehen (wie bei Angeboten).
    if ($aktion === 'freigeben' || $aktion === 'zurueckziehen') {
        $frei = $aktion === 'freigeben' ? 1 : 0;
        q("UPDATE beleg SET kunde_sichtbar=? WHERE id=?", [$frei, $id]);
        $bx = one("SELECT kunde_id, nummer, status FROM beleg WHERE id=?", [$id]);
        beleg_status_log_add($id, (string)($bx['status'] ?? 'offen'), $frei ? 'Für Kunde freigegeben' : 'Freigabe zurückgezogen', $akteur);
        if ($bx && $bx['kunde_id']) log_aktivitaet('kunde', (int)$bx['kunde_id'], 'team', 'Rechnung ' . $bx['nummer'] . ($frei ? ' für den Kunden freigegeben.' : ' – Freigabe zurückgezogen.'), 'beleg', 'beleg', $id);
        header('Location: ?p=rechnung&id=' . $id . '&freigabe=' . $frei); exit;
    }

    // Rechnungskopf nachträglich anpassen (Datum, Leistungsdatum, Zahlungsziel, Bearbeiter, Text).
    // Nur für Rechnungen und solange nicht storniert – Beträge/Positionen bleiben unberührt (dafür: Storno + neu).
    if ($aktion === 'kopf_speichern') {
        $bk = one("SELECT typ, status, datum FROM beleg WHERE id=?", [$id]);
        if ($bk && $bk['typ'] === 'rechnung' && $bk['status'] !== 'storniert') {
            $gilt  = fn($d) => (is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) ? $d : null;
            $datum = $gilt(trim($_POST['datum'] ?? '')) ?? ($bk['datum'] ?: gmdate('Y-m-d'));
            $leist = $gilt(trim($_POST['leistung_datum'] ?? ''));
            $zielR = trim($_POST['zahlungsziel_tage'] ?? '');
            $ziel  = ($zielR !== '') ? max(0, (int)$zielR) : null;
            $faellig = ($ziel !== null) ? date('Y-m-d', strtotime($datum . ' +' . $ziel . ' days')) : null;
            $bearb = (int)($_POST['bearbeiter_id'] ?? 0) ?: null;
            $text  = trim($_POST['text'] ?? '') ?: null;
            q("UPDATE beleg SET datum=?, leistung_datum=?, zahlungsziel_tage=?, faellig=?, bearbeiter_id=?, text=? WHERE id=?",
              [$datum, $leist, $ziel, $faellig, $bearb, $text, $id]);
            beleg_status_log_add($id, (string)$bk['status'], 'Rechnungskopf angepasst (Datum/Zahlungsziel/Bearbeiter/Text)', $akteur);
        }
        header('Location: ?p=rechnung&id=' . $id . '&kopf=1'); exit;
    }

    if ($aktion === 'zahlung') {
        $betrag = be_betrag_lesen((string)($_POST['betrag'] ?? '0'));   // robust: Tausenderpunkt + Dezimalkomma
        if ($betrag > 0) {
            zahlung_erfassen($id, $betrag, trim($_POST['datum'] ?? '') ?: null,
                             trim($_POST['konto'] ?? '') ?: null, trim($_POST['art'] ?? '') ?: null,
                             trim($_POST['zahl_notiz'] ?? ''), $akteur);
        }
        header('Location: ?p=rechnung&id=' . $id . '&gespeichert=1'); exit;
    }
    // Einzelne (Fehl-)Zahlung loeschen, dann Status neu ziehen. Nur admin/finance.
    if ($aktion === 'zahlung_loeschen' && (has_role('admin') || has_role('finance'))) {
        zahlung_loeschen((int)($_POST['zahlung_id'] ?? 0), $id, $akteur);
        header('Location: ?p=rechnung&id=' . $id . '&zahlweg=1'); exit;
    }

    // Rezeptur nachträglich an die Rechnung hängen (direkter Override beleg.rezeptur_id).
    // Zugriff auf die geteilte rezeptur/produkt-Tabelle nur über erp.php. Nur admin/finance.
    if ($aktion === 'rezeptur_verknuepfen' && (has_role('admin') || has_role('finance'))) {
        $rid = (int)($_POST['rezeptur_id'] ?? 0);
        q("UPDATE beleg SET rezeptur_id=? WHERE id=?", [$rid ?: null, $id]);
        $bx = one("SELECT auftrag_id FROM beleg WHERE id=?", [$id]);
        $pset = false;
        if ($rid && !empty($_POST['auch_produkt']) && !empty($bx['auftrag_id']))
            $pset = erp_auftrag_produkt_rezeptur_setzen((int)$bx['auftrag_id'], $rid);
        $st = (string)(scalar("SELECT status FROM beleg WHERE id=?", [$id]) ?: 'offen');
        beleg_status_log_add($id, $st, $rid ? ('Rezeptur verknüpft (ID ' . $rid . ')' . ($pset ? ', auch am Produkt des Auftrags hinterlegt' : '')) : 'Rezeptur-Verknüpfung entfernt', $akteur);
        header('Location: ?p=rechnung&id=' . $id . '&rezeptur=' . ($rid ?: '0') . ($pset ? '&produkt=1' : '')); exit;
    }

    // Rechnung stornieren -> Gutschrift (negativ) erzeugen + Original auf 'storniert'
    if ($aktion === 'storno') {
        $grund = trim($_POST['grund'] ?? '');
        $gid = gutschrift_aus_rechnung($id, $grund, $akteur);
        header('Location: ?p=rechnung&id=' . ($gid ?: $id) . '&storniert=1'); exit;
    }

    // Positionen aus dem verknüpften Angebot übernehmen (aufgeschlüsselt).
    if ($aktion === 'pos_aus_angebot') {
        $res = beleg_positionen_aus_angebot($id);
        if ($res['ok']) beleg_status_log_add($id, (string)(scalar("SELECT status FROM beleg WHERE id=?", [$id]) ?: 'offen'), 'Positionen aus Auftrag übernommen (' . (int)$res['anzahl'] . ')', $akteur);
        $url = '?p=rechnung&id=' . $id . '&pos=' . ($res['ok'] ? (int)$res['anzahl'] : '0');
        if (!$res['ok']) $url .= '&posgrund=' . urlencode($res['grund']);
        header('Location: ' . $url); exit;
    }
    // Positionen manuell setzen (ersetzt alle).
    if ($aktion === 'pos_speichern') {
        $zeilen = [];
        foreach (($_POST['p_bez'] ?? []) as $i => $bez) {
            $zeilen[] = ['artikelnr'=>$_POST['p_art'][$i] ?? '', 'bezeichnung'=>$bez, 'beschreibung'=>$_POST['p_besch'][$i] ?? '',
                         'menge'=>$_POST['p_menge'][$i] ?? '1', 'einheit'=>$_POST['p_einheit'][$i] ?? '',
                         'preis'=>$_POST['p_preis'][$i] ?? '0', 'ust'=>$_POST['p_ust'][$i] ?? '0'];
        }
        $n = beleg_positionen_manuell_setzen($id, $zeilen);
        beleg_status_log_add($id, (string)(scalar("SELECT status FROM beleg WHERE id=?", [$id]) ?: 'offen'), 'Positionen manuell gesetzt (' . $n . ')', $akteur);
        header('Location: ?p=rechnung&id=' . $id . '&posman=' . $n); exit;
    }

    // Rechnungsbetrag aus den Positionen übernehmen – NUR für Entwürfe (offen, nicht freigegeben, unbezahlt).
    // Eine festgeschriebene/gesendete/bezahlte Rechnung wird NICHT geändert (GoBD → Storno + neu).
    if ($aktion === 'betraege_aus_positionen') {
        $bx = one("SELECT typ, status, kunde_sichtbar FROM beleg WHERE id=?", [$id]);
        $bezahlt = (float) scalar("SELECT COALESCE(SUM(betrag),0) FROM zahlung WHERE beleg_id=?", [$id]);
        $istRechnung = $bx && $bx['typ'] === 'rechnung' && $bx['status'] !== 'storniert';
        // GoBD aus (Aufbauphase): jede nicht-stornierte Rechnung. GoBD scharf: nur Entwürfe.
        $entwurf = $istRechnung && (!gobd_scharf() || ($bx['status'] === 'offen' && (int)$bx['kunde_sichtbar'] === 0 && $bezahlt <= 0.005));
        if ($entwurf) {
            $pos = beleg_positionen($id);
            if ($pos) {
                $s = beleg_summen_aus_positionen($pos);   // netto/ust/brutto aus den Positionen
                $ustP = 0.0; foreach ($pos as $p) if ((float)($p['mwst_satz'] ?? 0) > 0) { $ustP = (float)$p['mwst_satz']; break; }
                if ($ustP <= 0 && $s['netto'] > 0) $ustP = round($s['ust'] / $s['netto'] * 100, 2);
                q("UPDATE beleg SET netto=?, ust_prozent=?, ust_betrag=?, brutto=? WHERE id=?",
                  [$s['netto'], $ustP, $s['ust'], $s['brutto'], $id]);
                beleg_status_log_add($id, 'offen', 'Rechnungsbetrag aus Positionen übernommen: netto ' . number_format($s['netto'],2,',','.') . ' €, brutto ' . number_format($s['brutto'],2,',','.') . ' €', $akteur);
                header('Location: ?p=rechnung&id=' . $id . '&betrag=1'); exit;
            }
        }
        header('Location: ?p=rechnung&id=' . $id . '&betragfehler=1'); exit;
    }

    // Guthaben auf diese Rechnung anrechnen (verrechnen)
    if ($aktion === 'guthaben_anrechnen') {
        $wunsch = be_betrag_lesen((string)($_POST['betrag'] ?? '0'));
        $an = guthaben_anrechnen($id, $wunsch, $akteur);
        header('Location: ?p=rechnung&id=' . $id . '&angerechnet=' . number_format($an, 2, '.', '')); exit;
    }
    // Guthaben (dieses Gutschrift-Kunden) auszahlen
    if ($aktion === 'guthaben_auszahlen') {
        $kid    = (int)($_POST['kunde_id'] ?? 0);
        $wunsch = be_betrag_lesen((string)($_POST['betrag'] ?? '0'));
        $aus = guthaben_auszahlen($kid, $wunsch, trim($_POST['notiz'] ?? ''), $akteur);
        header('Location: ?p=rechnung&id=' . $id . '&ausgezahlt=' . number_format($aus, 2, '.', '')); exit;
    }

    // Status manuell setzen (Override, z. B. storniert)
    $status = trim($_POST['status'] ?? 'offen');
    $notiz  = trim($_POST['status_notiz'] ?? '');
    $b = one("SELECT kunde_id,nummer,status FROM beleg WHERE id=?", [$id]);
    $alt = $b['status'] ?? '';
    if ($b && $status !== $alt) {
        q("UPDATE beleg SET status=? WHERE id=?", [$status, $id]);
        beleg_status_verlauf($id);   // sichert Backfill des „erstellt"-Eintrags vor dem neuen Eintrag
        beleg_status_log_add($id, $status, $notiz ?: 'manuell gesetzt', $akteur);
        if ($status === 'bezahlt' && $b['kunde_id']) log_aktivitaet('kunde', (int)$b['kunde_id'], 'team', 'Rechnung ' . $b['nummer'] . ' als bezahlt markiert.', 'beleg', 'beleg', $id);
    } elseif ($b && $notiz !== '') {
        beleg_status_log_add($id, $status, $notiz, $akteur);
    }
    header('Location: ?p=rechnung&id=' . $id . '&gespeichert=1'); exit;
}

$b = $id ? one("SELECT b.*, k.firma AS kunde_firma, a.nummer AS auftrag_nr
                FROM beleg b LEFT JOIN kunden k ON k.id=b.kunde_id LEFT JOIN auftrag a ON a.id=b.auftrag_id
                WHERE b.id=?", [$id]) : null;
if (!$b) { render_header('rechnungen','Rechnung'); bx_head('Rechnung nicht gefunden','', bx_btn('Zurück','?p=rechnungen','ghost')); render_footer(); exit; }

$istGut     = ($b['typ'] === 'gutschrift');
$guthaben   = $b['kunde_id'] ? kunde_guthaben((int)$b['kunde_id']) : 0.0;   // verfügbares Kunden-Guthaben
$positionen = all("SELECT * FROM beleg_position WHERE beleg_id=? ORDER BY sort, id", [$id]);
$stornoVon  = !empty($b['storno_von_id']) ? one("SELECT id,nummer FROM beleg WHERE id=?", [(int)$b['storno_von_id']]) : null;   // diese Gutschrift storniert ...
$stornoDurch= one("SELECT id,nummer FROM beleg WHERE storno_von_id=? AND typ='gutschrift'", [$id]);                            // ... diese Rechnung wurde storniert durch

$eur = fn($x) => number_format((float)$x, 2, ',', '.') . ' €';
$zBadge = fn($s) => match ($s) {
    'bezahlt'     => bx_badge('bezahlt','ok'),
    'teilbezahlt' => bx_badge('teilbezahlt','info'),
    'offen'       => bx_badge('offen','warn'),
    'storniert'   => bx_badge('storniert','err'),
    'erstellt'    => bx_badge('erstellt','info'),
    default       => bx_badge($s),
};
$zs = beleg_zahlstatus($b);   // abgeleiteter Zahlstatus + bezahlt/rest

$istFrei = (int)($b['kunde_sichtbar'] ?? 0) === 1;
// Betrag aus Positionen übernehmbar? GoBD aus (Aufbauphase): jede nicht-stornierte Rechnung.
// GoBD scharf: nur Entwürfe (offen, nicht freigegeben, keine Zahlung).
$istEntwurf = !$istGut && ($b['status'] ?? '') !== 'storniert'
    && (!gobd_scharf() || (($b['status'] ?? '') === 'offen' && !$istFrei && $zs['bezahlt'] <= 0.005));
// Freigeben/Zurueckziehen (wie bei Angeboten) – nur fuer Rechnungen, nicht bei Storno.
$freiBtn = '';
if (!$istGut && $b['status'] !== 'storniert') {
    $freiBtn = $istFrei
        ? '<form method="post" style="display:inline;margin:0"><input type="hidden" name="aktion" value="zurueckziehen"><button class="btn btn-ghost" type="submit">Freigabe zurückziehen</button></form> '
        : '<form method="post" style="display:inline;margin:0"><input type="hidden" name="aktion" value="freigeben"><button class="btn btn-primary" type="submit">Für Kunde freigeben</button></form> ';
}
render_header('rechnungen', $b['nummer']);
// Bei importierter Alt-Rechnung das hochgeladene Original zeigen, sonst die bulkify-PDF.
$pdfBtn = !empty($b['original_datei'])
    ? bx_btn('Original-Rechnung', '?p=rechnung&id=' . $id . '&original=1', 'ghost')
    : bx_btn('PDF ansehen', '?p=' . ($istGut ? 'gutschrift_pdf' : 'rechnung_pdf') . '&id=' . $id, 'ghost');
$xmlBtn = bx_btn('E-Rechnung (XML)', '?p=rechnung_xml&id=' . $id, 'ghost');
bx_head($b['nummer'], ($istGut ? 'Storno-Rechnung / Gutschrift' : 'Rechnung') . ($b['datum'] ? ' vom ' . date('d.m.Y', strtotime($b['datum'])) : ''),
        $freiBtn . $pdfBtn . ' ' . $xmlBtn . ' ' . bx_btn('Zurück zur Liste', '?p=rechnungen', 'ghost'));
if (isset($_GET['freigabe'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">' . ($_GET['freigabe'] === '1' ? 'Rechnung für den Kunden freigegeben – jetzt im Portal sichtbar.' : 'Freigabe zurückgezogen – nicht mehr im Kundenportal sichtbar.') . '</div>';
if (isset($_GET['erstellt'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Rechnung aus dem Auftrag erstellt. Beträge/USt stammen aus dem Auftrag – bei Bedarf unten Zahlungen erfassen oder stornieren.</div>';
if (isset($_GET['gespeichert'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Gespeichert.</div>';
if (isset($_GET['kopf'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Rechnungskopf aktualisiert.</div>';
if (isset($_GET['storniert'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Rechnung storniert – Gutschrift wurde erstellt.</div>';
if (isset($_GET['angerechnet'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">' . ((float)$_GET['angerechnet'] > 0 ? $eur((float)$_GET['angerechnet']) . ' Guthaben angerechnet.' : 'Kein Guthaben angerechnet (nichts verfügbar/offen).') . '</div>';
if (isset($_GET['ausgezahlt'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">' . ((float)$_GET['ausgezahlt'] > 0 ? $eur((float)$_GET['ausgezahlt']) . ' Guthaben als ausgezahlt verbucht.' : 'Kein Guthaben ausgezahlt.') . '</div>';
if (isset($_GET['betrag'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Rechnungsbetrag aus den Positionen übernommen.</div>';
if (isset($_GET['betragfehler'])) echo '<div class="bx-panel" style="padding:12px 16px;border-color:#e6c4c0">Betrag konnte nicht übernommen werden (nur bei Entwürfen: offen, nicht freigegeben, unbezahlt – und es müssen Positionen vorhanden sein).</div>';
if (isset($_GET['zahlweg'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Zahlung gelöscht – der Zahlstatus wurde neu berechnet.</div>';
if (isset($_GET['pos'])) echo '<div class="bx-panel ' . ((int)$_GET['pos'] > 0 ? 'badge-ok' : '') . '" style="padding:12px 16px' . ((int)$_GET['pos'] > 0 ? '' : ';border-color:#e6c4c0') . '">' . ((int)$_GET['pos'] > 0 ? (int)$_GET['pos'] . ' Position(en) aus dem Angebot übernommen.' : 'Positionen konnten nicht aus dem Angebot übernommen werden: ' . h((string)($_GET['posgrund'] ?? ''))) . '</div>';
if (isset($_GET['posman'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Positionen gespeichert (' . (int)$_GET['posman'] . ').</div>';
if (isset($_GET['rezeptur'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">' . ((int)$_GET['rezeptur'] > 0 ? 'Rezeptur verknüpft.' . (isset($_GET['produkt']) ? ' Sie wurde auch am Produkt des Auftrags hinterlegt.' : '') : 'Rezeptur-Verknüpfung entfernt.') . '</div>';
// Bezug-Hinweise
if ($stornoVon)  echo '<div class="bx-panel" style="padding:10px 14px">Storno zu Rechnung <a href="?p=rechnung&id=' . (int)$stornoVon['id'] . '">' . h($stornoVon['nummer']) . '</a>.</div>';
if ($stornoDurch) echo '<div class="bx-panel" style="padding:10px 14px;border-color:#e6c4c0">Diese Rechnung wurde storniert – Gutschrift <a href="?p=rechnung&id=' . (int)$stornoDurch['id'] . '">' . h($stornoDurch['nummer']) . '</a>.</div>';

echo '<div class="bx-cards">';
echo '<div class="bx-card"><div class="k">Status</div><div class="v">' . $zBadge($zs['status']) . '</div></div>';
echo '<div class="bx-card"><div class="k">Brutto</div><div class="v">' . $eur($b['brutto']) . '</div></div>';
echo '<div class="bx-card"><div class="k">Bezahlt</div><div class="v">' . $eur($zs['bezahlt']) . '</div></div>';
echo '<div class="bx-card"><div class="k">Offener Rest</div><div class="v">' . ($zs['rest'] > 0.005 ? '<strong>' . $eur($zs['rest']) . '</strong>' : $eur(0)) . '</div></div>';
if (!$istGut) echo '<div class="bx-card"><div class="k">Kundenportal</div><div class="v">' . ($istFrei ? bx_badge('freigegeben', 'ok') : bx_badge('nicht freigegeben', 'warn')) . '</div></div>';
echo '</div>';
?>
<div class="bx-panel">
  <h2>Details</h2>
  <div class="bx-grid">
    <div><div class="k muted">Kunde</div><div><?= kunde_link($b['kunde_id'] ?? null, $b['kunde_firma']) ?></div></div>
    <div><div class="k muted">Zu Auftrag</div><div><?php if ($b['auftrag_id']): ?><a href="/?p=auftrag&id=<?= (int)$b['auftrag_id'] ?>" target="_blank" title="Auftrag im Dashboard öffnen"><?= h($b['auftrag_nr']) ?></a><?php else: ?>–<?php endif; ?></div></div>
    <div><div class="k muted">Art</div><div><?= h(ucfirst($b['typ'])) ?></div></div>
    <div><div class="k muted">Netto</div><div><?= $eur($b['netto']) ?></div></div>
    <div><div class="k muted">USt (<?= rtrim(rtrim(number_format((float)$b['ust_prozent'],2,',','.'),'0'),',') ?> %)</div><div><?= $eur($b['ust_betrag']) ?></div></div>
    <?php if (!empty($b['leistung_datum'])): ?><div><div class="k muted">Leistungsdatum</div><div><?= h(date('d.m.Y', strtotime((string)$b['leistung_datum']))) ?></div></div><?php endif; ?>
    <?php if (!empty($b['faellig'])): ?><div><div class="k muted">Fällig bis</div><div><?= h(date('d.m.Y', strtotime((string)$b['faellig']))) ?><?php if (!empty($b['zahlungsziel_tage'])): ?> <span class="muted" style="font-size:12px">(<?= (int)$b['zahlungsziel_tage'] ?> Tage)</span><?php endif; ?></div></div><?php endif; ?>
  </div>
  <?php if (!empty($b['text'])): ?><div class="muted" style="margin-top:10px;white-space:pre-line;font-size:13px"><?= h((string)$b['text']) ?></div><?php endif; ?>
</div>

<?php
// Rezeptur verknüpfen: direkter Override (beleg.rezeptur_id), sonst über Auftrag → Produkt aufgelöst.
$rezId = (int)($b['rezeptur_id'] ?? 0);
$rezQuelle = $rezId ? 'direkt' : '';
if (!$rezId && !empty($b['auftrag_id'])) { $rezId = erp_auftrag_rezeptur_id((int)$b['auftrag_id']); if ($rezId) $rezQuelle = 'produkt'; }
$rez = $rezId ? erp_rezeptur($rezId) : null;
$rezDarf = has_role('admin') || has_role('finance');
?>
<div class="bx-panel">
  <h2>Rezeptur</h2>
  <?php if ($rez): ?>
    <div class="bx-row" style="align-items:baseline;gap:10px;flex-wrap:wrap">
      <a href="/?p=rezeptur_detail&id=<?= (int)$rez['id'] ?>" target="_blank" title="Rezeptur im Dashboard öffnen"><strong><?= h((string)$rez['nummer']) ?></strong><?= $rez['name'] ? ' – ' . h((string)$rez['name']) : '' ?></a>
      <span class="muted" style="font-size:12px"><?= $rezQuelle === 'direkt' ? 'direkt an der Rechnung hinterlegt' : 'über das Produkt des Auftrags' ?></span>
    </div>
  <?php else: ?>
    <p class="muted" style="margin:0 0 <?= $rezDarf ? '12px' : '0' ?>">Dieser Rechnung ist noch keine Rezeptur zugeordnet<?= !empty($b['auftrag_id']) ? ' (auch nicht über das Produkt des Auftrags)' : '' ?>.</p>
  <?php endif; ?>
  <?php if ($rezDarf): $rezListe = erp_rezepturen(); ?>
    <details class="bx-form" style="margin-top:<?= $rez ? '12px' : '0' ?>"<?= isset($_GET['rezeptur']) ? ' open' : '' ?>>
      <summary style="cursor:pointer;color:var(--muted);font-size:13px"><?= $rez ? 'Andere Rezeptur zuordnen / entfernen' : 'Rezeptur zuordnen' ?></summary>
      <form method="post" style="margin-top:10px">
        <input type="hidden" name="aktion" value="rezeptur_verknuepfen">
        <div class="bx-field"><label>Rezeptur</label>
          <select name="rezeptur_id" class="rscombo" style="max-width:520px">
            <option value="0">– keine / Verknüpfung entfernen –</option>
            <?php foreach ($rezListe as $r): ?>
              <option value="<?= (int)$r['id'] ?>" <?= (int)($b['rezeptur_id'] ?? 0) === (int)$r['id'] ? 'selected' : '' ?>><?= h((string)$r['nummer']) ?><?= $r['name'] ? ' – ' . h((string)$r['name']) : '' ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php if (!empty($b['auftrag_id'])): ?>
        <label class="bx-check" style="display:flex;align-items:center;gap:8px;margin:8px 0 0"><input type="checkbox" name="auch_produkt" value="1" checked> Auch am Produkt des Auftrags hinterlegen, falls dort noch keine Rezeptur steht</label>
        <?php endif; ?>
        <div class="bx-row" style="margin-top:12px"><button class="btn btn-primary btn-sm" type="submit">Speichern</button></div>
        <p class="muted" style="margin:8px 0 0;font-size:12px">Direkter Override an der Rechnung. Nützlich für importierte/Freitext-Rechnungen ohne eigenen Auftrag.</p>
      </form>
    </details>
  <?php endif; ?>
</div>

<?php
// Mahnhistorie (nur Debitoren-Rechnungen). Nur anzeigen, wenn es Mahnungen gibt.
$mahnungen = (!$istGut && ($b['typ'] ?? '') === 'rechnung') ? mahn_fuer_beleg($id) : [];
if ($mahnungen):
?>
<div class="bx-panel">
  <h2>Mahnungen</h2>
  <div class="bx-tablewrap"><table class="bx-table">
    <thead><tr><th>Mahnung</th><th>Stufe</th><th>Datum</th><th class="bx-num">Offen</th><th class="bx-num">Gebühr</th><th class="bx-num">Summe</th><th>Neue Frist</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($mahnungen as $m): ?>
      <tr>
        <td><strong><?= h((string)$m['nummer']) ?></strong></td>
        <td><?= h(mahn_stufe_label((int)$m['stufe'])) ?></td>
        <td><?= $m['datum'] ? h(date('d.m.Y', strtotime((string)$m['datum']))) : '' ?></td>
        <td class="bx-num"><?= $eur($m['offen']) ?></td>
        <td class="bx-num"><?= $eur($m['gebuehr']) ?></td>
        <td class="bx-num"><strong><?= $eur($m['summe']) ?></strong></td>
        <td><?= $m['faellig_neu'] ? h(date('d.m.Y', strtotime((string)$m['faellig_neu']))) : '' ?></td>
        <td><a class="btn btn-ghost btn-sm" href="?p=mahnung&id=<?= (int)$m['id'] ?>" target="_blank">Mahnbrief</a></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
  <p class="muted" style="margin:8px 0 0;font-size:12px">Neue Mahnungen entstehen über den <a href="?p=mahnlauf">Mahnlauf</a>.</p>
</div>
<?php endif; ?>

<?php if (!$istGut && $b['status'] !== 'storniert'):
    $benutzer = all("SELECT id, name FROM benutzer WHERE aktiv=1 ORDER BY name");
    $curBearb = (int)($b['bearbeiter_id'] ?? 0);
?>
<details class="bx-panel"<?= isset($_GET['kopf']) ? ' open' : '' ?>>
  <summary style="cursor:pointer;font-weight:600">Rechnungskopf bearbeiten</summary>
  <p class="muted" style="margin:8px 0 0">Datum, Zahlungsziel, Bearbeiter und Rechnungstext lassen sich nachträglich korrigieren. <strong>Beträge/Positionen</strong> bleiben unberührt – falsche Beträge über „Stornieren" rückgängig machen und neu erstellen.</p>
  <form method="post" style="margin-top:12px">
    <input type="hidden" name="aktion" value="kopf_speichern">
    <div class="bx-grid">
      <div class="bx-field"><label>Rechnungsdatum</label><input type="date" name="datum" value="<?= h($b['datum'] ? date('Y-m-d', strtotime((string)$b['datum'])) : date('Y-m-d')) ?>"></div>
      <div class="bx-field"><label>Leistungs-/Lieferdatum</label><input type="date" name="leistung_datum" value="<?= h($b['leistung_datum'] ? date('Y-m-d', strtotime((string)$b['leistung_datum'])) : '') ?>"></div>
      <div class="bx-field"><label>Zahlungsziel (Tage)</label><input type="text" inputmode="numeric" name="zahlungsziel_tage" value="<?= h((string)($b['zahlungsziel_tage'] ?? '')) ?>" placeholder="z. B. 14" style="max-width:140px"></div>
      <div class="bx-field"><label>Bearbeiter</label>
        <select name="bearbeiter_id">
          <option value="">– keiner –</option>
          <?php foreach ($benutzer as $bu): ?><option value="<?= (int)$bu['id'] ?>" <?= $curBearb===(int)$bu['id']?'selected':'' ?>><?= h($bu['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="bx-field"><label>Rechnungstext / Hinweis</label><textarea name="text" rows="2" style="width:100%;box-sizing:border-box"><?= h((string)($b['text'] ?? '')) ?></textarea></div>
    <div class="bx-row" style="margin-top:var(--sp-3)"><button class="btn btn-primary" type="submit">Kopf speichern</button></div>
  </form>
</details>
<?php endif; ?>

<div class="bx-panel">
  <h2>Positionen<?= $positionen ? ' <span class="muted" style="font-weight:400;font-size:14px">(' . count($positionen) . ')</span>' : '' ?></h2>
  <?php if (!$istGut && $b['status'] !== 'storniert'): ?>
  <div class="bx-row" style="margin:0 0 12px;gap:8px;flex-wrap:wrap">
    <?php if ($b['auftrag_id']): ?>
    <form method="post" style="margin:0">
      <input type="hidden" name="aktion" value="pos_aus_angebot">
      <button class="btn btn-ghost btn-sm" type="submit" data-busy="Übernehme …" <?= $positionen ? "onclick=\"return confirm('Positionen neu aus dem Auftrag übernehmen? Vorhandene Positionen werden ersetzt.');\"" : '' ?>>Positionen aus Auftrag übernehmen</button>
    </form>
    <?php endif; ?>
    <button class="btn btn-ghost btn-sm" type="button" onclick="document.getElementById('posEdit').open=true;document.getElementById('posEdit').scrollIntoView({behavior:'smooth'})">Positionen manuell bearbeiten</button>
  </div>
  <?php endif; ?>
  <?php if ($positionen): $sumNetto = 0.0; ?>
  <div class="bx-tablewrap"><table class="bx-table">
    <thead><tr><th>Pos.</th><th>Artikel-Nr.</th><th>Bezeichnung</th><th class="bx-num">Menge</th><th>Einheit</th><th class="bx-num">Einzelpreis</th><th class="bx-num">USt</th><th class="bx-num">Gesamt</th></tr></thead>
    <tbody>
      <?php $i=0; foreach ($positionen as $p): $ep=(int)$p['preis_cent']/100; $ge=$ep*(float)$p['menge']; $sumNetto+=$ge; $i++; ?>
      <tr>
        <td><?= $i ?></td>
        <td><?= h($p['artikelnr'] ?: '–') ?></td>
        <td><?= h($p['bezeichnung']) ?><?php if ($p['beschreibung']): ?><div class="muted" style="font-size:12px;white-space:pre-line"><?= h($p['beschreibung']) ?></div><?php endif; ?></td>
        <td class="bx-num"><?= rtrim(rtrim(number_format((float)$p['menge'],2,',','.'),'0'),',') ?></td>
        <td><?= h($p['einheit'] ?: '') ?></td>
        <td class="bx-num"><?= $eur($ep) ?></td>
        <td class="bx-num"><?= number_format((float)$p['mwst_satz'],0) ?> %</td>
        <td class="bx-num"><?= $eur($ge) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr><td colspan="7" class="bx-num">Summe Positionen (netto)</td><td class="bx-num"><strong><?= $eur($sumNetto) ?></strong></td></tr>
      <tr><td colspan="7" class="bx-num">zzgl. USt (<?= number_format((float)$b['ust_prozent'],0) ?> %)</td><td class="bx-num"><?= $eur($b['ust_betrag']) ?></td></tr>
      <tr><td colspan="7" class="bx-num"><strong>Brutto</strong></td><td class="bx-num"><strong><?= $eur($b['brutto']) ?></strong></td></tr>
    </tfoot>
  </table></div>
  <?php if (abs($sumNetto - (float)$b['netto']) > 0.01): ?>
    <div class="bx-row" style="margin:8px 0 0;align-items:center;gap:10px;flex-wrap:wrap">
      <p style="margin:0;color:var(--warn)">Hinweis: Summe der Positionen (<?= $eur($sumNetto) ?>) weicht vom Beleg-Netto (<?= $eur($b['netto']) ?>) ab.</p>
      <?php if ($istEntwurf): ?>
      <form method="post" style="margin:0" onsubmit="return confirm('Rechnungsbetrag auf die Positionssumme (<?= h($eur($sumNetto)) ?> netto) setzen? Das ändert Netto/USt/Brutto dieser Entwurfs-Rechnung.');">
        <input type="hidden" name="aktion" value="betraege_aus_positionen">
        <button class="btn btn-primary btn-sm" type="submit">Rechnungsbetrag aus Positionen übernehmen</button>
      </form>
      <?php else: ?><span class="muted" style="font-size:12px">Betrag nur bei Entwürfen änderbar – sonst Stornieren und neu.</span><?php endif; ?>
    </div>
  <?php endif; ?>
  <?php else: ?>
  <p class="muted" style="margin:0 0 8px">Keine Einzelpositionen hinterlegt – diese Rechnung trägt nur einen Gesamtbetrag (z. B. Alt-Import oder Sammelposition aus dem Auftrag).</p>
  <div class="bx-tablewrap"><table class="bx-table"><tbody>
    <tr><td>Netto</td><td class="bx-num"><?= $eur($b['netto']) ?></td></tr>
    <tr><td>USt (<?= number_format((float)$b['ust_prozent'],0) ?> %)</td><td class="bx-num"><?= $eur($b['ust_betrag']) ?></td></tr>
    <tr><td><strong>Brutto</strong></td><td class="bx-num"><strong><?= $eur($b['brutto']) ?></strong></td></tr>
  </tbody></table></div>
  <?php endif; ?>

  <?php if (!$istGut && $b['status'] !== 'storniert'): ?>
  <details id="posEdit" class="bx-panel" style="margin-top:16px"<?= isset($_GET['posman']) ? ' open' : '' ?>>
    <summary class="btn btn-ghost btn-sm" style="list-style:none">Positionen manuell bearbeiten</summary>
    <p class="muted" style="margin:10px 0 0">Positionen sind die Aufschlüsselung des Rechnungsbetrags. Das Speichern ändert <strong>nicht</strong> die Beleg-Summen (Netto/USt/Brutto) – stimmen Positionen und Betrag nicht überein, erscheint oben ein Hinweis. Für eine Betragsänderung: Stornieren und neu erstellen.</p>
    <form method="post" style="margin-top:10px">
      <input type="hidden" name="aktion" value="pos_speichern">
      <div class="bx-tablewrap"><table class="bx-table" id="posTab">
        <thead><tr><th>Artikel-Nr.</th><th>Bezeichnung</th><th class="bx-num" style="width:90px">Menge</th><th style="width:90px">Einheit</th><th class="bx-num" style="width:120px">Einzelpreis €</th><th class="bx-num" style="width:80px">USt %</th><th style="width:40px"></th></tr></thead>
        <tbody>
          <?php
          $editRows = $positionen ?: [['artikelnr'=>'','bezeichnung'=>'','menge'=>1,'einheit'=>'Stk.','preis_cent'=>0,'mwst_satz'=>(float)$b['ust_prozent']]];
          foreach ($editRows as $p): ?>
          <tr>
            <td><input type="text" name="p_art[]" value="<?= h((string)($p['artikelnr'] ?? '')) ?>"></td>
            <td><input type="text" name="p_bez[]" value="<?= h((string)($p['bezeichnung'] ?? '')) ?>" style="width:100%"><input type="hidden" name="p_besch[]" value="<?= h((string)($p['beschreibung'] ?? '')) ?>"></td>
            <td class="bx-num"><input type="text" inputmode="decimal" name="p_menge[]" value="<?= h(rtrim(rtrim(number_format((float)($p['menge'] ?? 1),2,',',''),'0'),',')) ?>" style="width:80px;text-align:right"></td>
            <td><input type="text" name="p_einheit[]" value="<?= h((string)($p['einheit'] ?? '')) ?>" style="width:80px"></td>
            <td class="bx-num"><input type="text" inputmode="decimal" name="p_preis[]" value="<?= h(number_format((int)($p['preis_cent'] ?? 0)/100,2,',','')) ?>" style="width:110px;text-align:right"></td>
            <td class="bx-num"><input type="text" inputmode="decimal" name="p_ust[]" value="<?= h(number_format((float)($p['mwst_satz'] ?? $b['ust_prozent']),0)) ?>" style="width:70px;text-align:right"></td>
            <td><button type="button" class="btn btn-ghost btn-sm" onclick="this.closest('tr').remove()" title="Zeile entfernen">×</button></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table></div>
      <div class="bx-row" style="margin-top:10px;gap:8px">
        <button type="button" class="btn btn-ghost btn-sm" onclick="posAddRow()">+ Zeile</button>
        <span style="flex:1"></span>
        <button class="btn btn-primary" type="submit">Positionen speichern</button>
      </div>
    </form>
    <script>
    function posAddRow(){var t=document.querySelector('#posTab tbody');var tr=t.rows[0];var n=tr?tr.cloneNode(true):null;if(!n)return;n.querySelectorAll('input').forEach(function(i){if(i.name==='p_menge[]')i.value='1';else if(i.name==='p_ust[]'){}else i.value='';});t.appendChild(n);}
    </script>
  </details>
  <?php endif; ?>
</div>

<?php
// Verknüpftes (archiviertes) Angebot aus dem Bulk-Import – Leistungsumfang je Produkt.
$impAng = !empty($b['imp_angebot_id']) ? imp_angebot((int)$b['imp_angebot_id']) : null;
if ($impAng): $impPos = imp_angebot_pos((int)$impAng['id']);
$typLbl = ['produkt'=>'Produkt','verpackung'=>'Verpackung','etikett'=>'Etikett'];
?>
<div class="bx-panel">
  <h2>Verknüpftes Angebot<?= $impAng['nummer'] ? ' · ' . h($impAng['nummer']) : '' ?></h2>
  <?php if (!$impPos): ?><p class="muted" style="margin:0">Keine Positionen im archivierten Angebot.</p><?php else: ?>
  <div class="bx-tablewrap"><table class="bx-table">
    <thead><tr><th>Produkt</th><th>Art</th><th>Position</th><th class="bx-num">Menge</th><th>Einheit</th><th class="bx-num">Preis</th></tr></thead>
    <tbody>
      <?php $gPrev = null; foreach ($impPos as $p): $g=(int)$p['gruppe']; ?>
      <tr<?= $g !== $gPrev ? ' style="border-top:2px solid var(--line)"' : '' ?>>
        <td><?= $g !== $gPrev ? '<strong>#' . $g . '</strong>' : '' ?></td>
        <td><?= h($typLbl[$p['typ']] ?? $p['typ']) ?></td>
        <td><?= h((string)$p['bezeichnung']) ?></td>
        <td class="bx-num"><?= $p['menge'] !== null ? h(rtrim(rtrim(number_format((float)$p['menge'],2,',','.'),'0'),',')) : '' ?></td>
        <td><?= h((string)$p['einheit']) ?></td>
        <td class="bx-num"><?= $p['preis'] !== null && (float)$p['preis'] != 0 ? $eur($p['preis']) : '' ?></td>
      </tr>
      <?php $gPrev=$g; endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
  <p class="muted" style="margin:8px 0 0">Aus dem Bulk-Import archiviert (nur hinterlegt). Diese Positionen sieht auch der Kunde im Portal.</p>
</div>
<?php endif; ?>

<?php if ($b['typ'] === 'rechnung' && $b['status'] !== 'storniert' && !$stornoDurch): ?>
<details class="bx-form">
  <summary class="btn btn-ghost btn-sm" style="list-style:none">Rechnung stornieren</summary>
  <form method="post" style="margin-top:12px" onsubmit="return confirm('Rechnung <?= h($b['nummer']) ?> stornieren? Es wird eine Gutschrift erzeugt und die Rechnung auf „storniert“ gesetzt.');">
    <input type="hidden" name="aktion" value="storno">
    <div class="bx-panel">
      <p class="muted" style="margin-top:0">Erzeugt eine <strong>Storno-Rechnung (Gutschrift)</strong> mit negativen Beträgen als Ausgleich zu dieser Rechnung.<?php if ($zs['bezahlt'] > 0.005): ?> <strong>Achtung:</strong> Es wurden bereits <?= $eur($zs['bezahlt']) ?> gezahlt – ggf. Rückzahlung/Verrechnung beachten.<?php endif; ?></p>
      <div class="bx-field"><label>Grund (optional)</label><input type="text" name="grund" placeholder="z. B. falsche Menge, Kunde storniert"></div>
    </div>
    <button class="btn btn-primary" type="submit">Storno-Rechnung erstellen</button>
  </form>
</details>
<?php endif; ?>

<?php
$zahlungen = zahlungen_fuer($id);
$konten = bank_konten();
$artLbl = ['ueberweisung'=>'Überweisung','lastschrift'=>'Lastschrift','paypal'=>'PayPal','bar'=>'Bar','sonstiges'=>'Sonstiges'];
?>
<!-- Guthaben anrechnen (Rechnung) -->
<?php if (!$istGut && $b['status'] !== 'storniert' && $guthaben > 0.005 && $zs['rest'] > 0.005): $anrMax = min($guthaben, $zs['rest']); ?>
<form method="post" class="bx-form">
  <input type="hidden" name="aktion" value="guthaben_anrechnen">
  <div class="bx-panel" style="border-color:var(--gruen)">
    <h2 style="margin-top:0">Guthaben anrechnen</h2>
    <p class="muted" style="margin-top:0">Verfügbares Guthaben von <?= h($b['kunde_firma'] ?: 'Kunde') ?>: <strong><?= $eur($guthaben) ?></strong> · offener Rest dieser Rechnung: <strong><?= $eur($zs['rest']) ?></strong>.</p>
    <div class="bx-grid">
      <div class="bx-field"><label>Betrag anrechnen</label><input type="text" inputmode="decimal" name="betrag" value="<?= number_format($anrMax, 2, ',', '') ?>"></div>
    </div>
    <button class="btn btn-primary" type="submit">Guthaben anrechnen</button>
  </div>
</form>
<?php endif; ?>

<!-- Guthaben auszahlen (Gutschrift) -->
<?php if ($istGut && $guthaben > 0.005): ?>
<form method="post" class="bx-form" onsubmit="return confirm('Guthaben auszahlen und als Erstattung verbuchen?');">
  <input type="hidden" name="aktion" value="guthaben_auszahlen">
  <input type="hidden" name="kunde_id" value="<?= (int)$b['kunde_id'] ?>">
  <div class="bx-panel">
    <h2 style="margin-top:0">Guthaben auszahlen</h2>
    <p class="muted" style="margin-top:0">Verfügbares Guthaben von <?= h($b['kunde_firma'] ?: 'Kunde') ?>: <strong><?= $eur($guthaben) ?></strong>. Die Auszahlung wird als Verbrauch (Erstattung) verbucht.</p>
    <div class="bx-grid">
      <div class="bx-field"><label>Betrag auszahlen</label><input type="text" inputmode="decimal" name="betrag" value="<?= number_format($guthaben, 2, ',', '') ?>"></div>
      <div class="bx-field"><label>Notiz (optional)</label><input type="text" name="notiz" placeholder="z. B. Überweisung an Kunde"></div>
    </div>
    <button class="btn btn-primary" type="submit">Guthaben auszahlen</button>
  </div>
</form>
<?php endif; ?>

<!-- Zahlung erfassen -->
<?php if ($b['typ'] === 'rechnung' && $b['status'] !== 'storniert'): ?>
<form method="post" class="bx-form">
  <input type="hidden" name="aktion" value="zahlung">
  <div class="bx-panel">
    <h2 style="margin-top:0">Zahlung erfassen</h2>
    <div class="bx-grid">
      <div class="bx-field"><label>Betrag</label><input type="text" inputmode="decimal" name="betrag" value="<?= $zs['rest'] > 0.005 ? number_format($zs['rest'],2,',','') : '' ?>" placeholder="0,00"></div>
      <div class="bx-field"><label>Überweisungsdatum (Valuta)</label><input type="date" name="datum"></div>
      <div class="bx-field"><label>Konto</label>
        <?php if ($konten): ?>
        <select name="konto">
          <?php foreach ($konten as $kt): ?><option value="<?= h($kt['key']) ?>"><?= h($kt['label']) ?></option><?php endforeach; ?>
        </select>
        <?php else: ?>
        <input type="text" name="konto" placeholder="Konto (in Einstellungen hinterlegen)">
        <?php endif; ?>
      </div>
      <div class="bx-field"><label>Art</label>
        <select name="art">
          <?php foreach ($artLbl as $key=>$lbl): ?><option value="<?= $key ?>"><?= $lbl ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="bx-field"><label>Anmerkung (optional)</label><input type="text" name="zahl_notiz" placeholder="z. B. Teilzahlung 1. Rate"></div>
    </div>
  </div>
  <button class="btn btn-primary" type="submit">Zahlung buchen</button>
</form>
<?php endif; ?>

<!-- Zahlungseingänge -->
<div class="bx-panel">
  <h2>Zahlungseingänge</h2>
  <?php $darfZahlWeg = has_role('admin') || has_role('finance'); ?>
  <div class="bx-tablewrap"><table class="bx-table">
    <thead><tr><th>Überweisungsdatum</th><th class="bx-num">Betrag</th><th>Konto</th><th>Art</th><th>Anmerkung</th><th>Erfasst</th><?php if ($darfZahlWeg): ?><th></th><?php endif; ?></tr></thead>
    <tbody>
      <?php if (!$zahlungen): ?><tr><td colspan="<?= $darfZahlWeg ? 7 : 6 ?>" class="muted">Noch keine Zahlungseingänge erfasst.</td></tr><?php endif; ?>
      <?php foreach ($zahlungen as $z): ?>
        <tr>
          <td><?= $z['datum'] ? h(date('d.m.Y', strtotime($z['datum']))) : '<span class="muted">–</span>' ?></td>
          <td class="bx-num"><?= $eur($z['betrag']) ?></td>
          <td><?= $z['konto'] ? h(bank_konto_label($z['konto'])) : '<span class="muted">–</span>' ?></td>
          <td><?= $z['art'] ? h($artLbl[$z['art']] ?? $z['art']) : '<span class="muted">–</span>' ?></td>
          <td><?= $z['notiz'] ? h($z['notiz']) : '<span class="muted">–</span>' ?></td>
          <td class="muted"><?= h(fmt_zeit($z['angelegt'], 'd.m.Y H:i')) ?><?= $z['akteur'] ? ' · ' . h($z['akteur']) : '' ?></td>
          <?php if ($darfZahlWeg): ?><td style="text-align:right"><form method="post" style="margin:0" onsubmit="return confirm('Diese Zahlung (<?= h($eur($z['betrag'])) ?>) wirklich löschen? Der Status wird neu berechnet.');"><input type="hidden" name="aktion" value="zahlung_loeschen"><input type="hidden" name="zahlung_id" value="<?= (int)$z['id'] ?>"><button class="btn btn-ghost btn-sm" type="submit" title="Fehlbuchung löschen">&times;</button></form></td><?php endif; ?>
        </tr>
      <?php endforeach; ?>
      <?php if ($zahlungen): ?>
        <tr><td class="muted">Summe</td><td class="bx-num"><strong><?= $eur($zs['bezahlt']) ?></strong></td><td colspan="<?= $darfZahlWeg ? 5 : 4 ?>" class="muted"><?= $zs['rest'] > 0.005 ? 'Offener Rest ' . $eur($zs['rest']) : 'Vollständig bezahlt' ?></td></tr>
      <?php endif; ?>
    </tbody>
  </table></div>
</div>

<!-- Status manuell (Override) -->
<details class="bx-form">
  <summary class="btn btn-ghost btn-sm" style="list-style:none">Status manuell setzen</summary>
  <form method="post" style="margin-top:12px">
    <input type="hidden" name="aktion" value="status">
    <div class="bx-panel"><div class="bx-grid">
      <div class="bx-field"><label>Status</label>
        <select name="status">
          <?php foreach (['offen'=>'offen','teilbezahlt'=>'teilbezahlt','bezahlt'=>'bezahlt','storniert'=>'storniert'] as $key=>$lbl): ?>
            <option value="<?= $key ?>" <?= $b['status']===$key?'selected':'' ?>><?= $lbl ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="bx-field"><label>Anmerkung (optional)</label><input type="text" name="status_notiz" placeholder="Grund der manuellen Änderung"></div>
    </div></div>
    <button class="btn btn-primary" type="submit">Status speichern</button>
  </form>
</details>

<?php $verlauf = beleg_status_verlauf($id); ?>
<div class="bx-panel">
  <h2>Statusverlauf</h2>
  <div class="bx-tablewrap"><table class="bx-table">
    <thead><tr><th>Wann</th><th>Status</th><th>Wer</th><th>Anmerkung</th></tr></thead>
    <tbody>
      <?php if (!$verlauf): ?><tr><td colspan="4" class="muted">Noch keine Statusänderungen.</td></tr><?php endif; ?>
      <?php foreach (array_reverse($verlauf) as $v): ?>
        <tr>
          <td><?= h(fmt_zeit($v['angelegt'], 'd.m.Y H:i')) ?></td>
          <td><?= $zBadge($v['status']) ?></td>
          <td><?= $v['akteur'] ? h($v['akteur']) : '<span class="muted">System</span>' ?></td>
          <td><?= $v['notiz'] ? h($v['notiz']) : '<span class="muted">–</span>' ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php render_footer(); ?>

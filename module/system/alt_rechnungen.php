<?php
// TEMPORÄRES Werkzeug: originale Rechnungen/Dokumente zu ALTEN (v3-importierten) Aufträgen nachtragen,
// damit sie im Kundenportal angezeigt werden. Wird später wieder aus dem System genommen (siehe Memory
// "alt-rechnungen-reiter-temporaer"). Ablage in `dokument` (objekt_typ='auftrag', typ=rechnung/angebot/ab/
// sonstiges, kunde_sichtbar=1). Anzeige im Portal: Bestell-Detail, Panel "Dokumente".
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

$TYPEN = ['rechnung' => 'Rechnung', 'angebot' => 'Angebot', 'ab' => 'Auftragsbestätigung', 'sonstiges' => 'Sonstiges'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aktion = (string)($_POST['aktion'] ?? '');
    $aid = (int)($_POST['auftrag_id'] ?? 0);

    if ($aktion === 're_upload' && $aid) {
        $typ = array_key_exists($_POST['typ'] ?? '', $TYPEN) ? $_POST['typ'] : 'rechnung';
        $titel = trim((string)($_POST['titel'] ?? '')) ?: null;
        $datum = trim((string)($_POST['datum'] ?? '')); if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $datum)) $datum = null;
        if (!is_dir(BX_UPLOADS)) @mkdir(BX_UPLOADS, 0775, true);
        // Mehrere Dateien auf einmal erlaubt.
        $files = $_FILES['dok'] ?? null; $anz = 0;
        if ($files && is_array($files['name'])) {
            for ($i = 0; $i < count($files['name']); $i++) {
                if ((int)($files['error'][$i] ?? 1) !== UPLOAD_ERR_OK || $files['name'][$i] === '') continue;
                $orig = (string)$files['name'][$i];
                $ext  = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', pathinfo($orig, PATHINFO_EXTENSION)));
                $fn   = 'auftragdok_' . $aid . '_' . bin2hex(random_bytes(5)) . ($ext ? '.' . $ext : '');
                if (move_uploaded_file($files['tmp_name'][$i], BX_UPLOADS . '/' . $fn)) {
                    q("INSERT INTO dokument (objekt_typ,objekt_id,typ,titel,datei,datei_orig,dok_datum,kunde_sichtbar,hochgeladen_von)
                       VALUES ('auftrag',?,?,?,?,?,?,1,'team')", [$aid, $typ, $titel, $fn, $orig, $datum]);
                    $anz++;
                }
            }
        }
        header('Location: ?p=alt_rechnungen&aid=' . $aid . ($anz ? '&ok=' . $anz : '&fehler=1')); exit;
    }
    if ($aktion === 're_del' && ($did = (int)($_POST['dok_id'] ?? 0))) {
        $d = one("SELECT datei, objekt_id FROM dokument WHERE id=? AND objekt_typ='auftrag'", [$did]);
        if ($d) { @unlink(BX_UPLOADS . '/' . basename((string)$d['datei'])); q("DELETE FROM dokument WHERE id=?", [$did]); }
        header('Location: ?p=alt_rechnungen&aid=' . (int)($d['objekt_id'] ?? 0)); exit;
    }
}

$suche = trim((string)($_GET['q'] ?? ''));
$like = '%' . $suche . '%';
$qk = mb_strtolower(preg_replace('/[^a-z0-9]+/i', '', $suche));
$auftraege = all("SELECT a.id, a.nummer, a.status, a.angelegt, a.menge, a.v3_id,
                        COALESCE(NULLIF(p.kundenname,''), p.name, a.produkt_bezeichnung) AS produkt,
                        k.firma AS kunde,
                        (SELECT COUNT(*) FROM dokument d WHERE d.objekt_typ='auftrag' AND d.objekt_id=a.id AND d.typ IN ('rechnung','angebot','ab','sonstiges')) AS docs
                 FROM auftrag a LEFT JOIN produkt p ON p.id=a.produkt_id LEFT JOIN kunden k ON k.id=a.kunde_id
                 WHERE (? = '' OR a.nummer LIKE ? OR k.firma LIKE ? OR p.name LIKE ? OR p.kundenname LIKE ?
                        OR (?<>'' AND REPLACE(REPLACE(LOWER(a.nummer),'-',''),' ','') LIKE ?))
                 ORDER BY (a.v3_id IS NOT NULL) DESC, a.id DESC
                 LIMIT 500", [$suche, $like, $like, $like, $like, $qk, '%' . $qk . '%']);

// Detail (Popup) fuer den gewaehlten Auftrag.
$aid = (int)($_GET['aid'] ?? 0);
$info = $aid ? one("SELECT a.*, COALESCE(NULLIF(p.kundenname,''), p.name, a.produkt_bezeichnung) AS produkt,
                          k.firma AS kunde, k.id AS kunde_id, ang.nummer AS angebot_nr
                   FROM auftrag a LEFT JOIN produkt p ON p.id=a.produkt_id LEFT JOIN kunden k ON k.id=a.kunde_id
                   LEFT JOIN angebot ang ON ang.id=a.angebot_id WHERE a.id=?", [$aid]) : null;
$infoDocs = $info ? all("SELECT id, typ, titel, datei_orig, dok_datum, angelegt FROM dokument
                         WHERE objekt_typ='auftrag' AND objekt_id=? AND typ IN ('rechnung','angebot','ab','sonstiges')
                         ORDER BY id DESC", [$aid]) : [];

render_header('alt_rechnungen', 'Rechnungen nachtragen');
bx_head('Rechnungen nachtragen', 'Temporär: originale Rechnungen/Dokumente zu (alten) Aufträgen hochladen – erscheinen im Kundenportal bei der Bestellung.');

if (($n = (int)($_GET['ok'] ?? 0)) > 0) echo '<div class="bx-panel badge-ok" style="padding:10px 14px">' . $n . ' Dokument(e) hochgeladen und für den Kunden sichtbar.</div>';
if (isset($_GET['fehler'])) echo '<div class="bx-panel" style="padding:10px 14px;border-color:#e6c4c0;color:#8f231b">Es wurde keine Datei hochgeladen.</div>';
?>
<div class="bx-panel">
  <form method="get" style="margin:0"><input type="hidden" name="p" value="alt_rechnungen">
    <input type="text" name="q" value="<?= h($suche) ?>" placeholder="Auftrag, Kunde, Produkt … (z. B. ab3192)" oninput="arFilter(this.value)" style="min-width:260px">
  </form>
  <div class="bx-tablewrap" style="margin-top:12px"><table class="bx-table" id="arTab">
    <thead><tr><th>Auftrag</th><th>Kunde</th><th>Produkt</th><th>Datum</th><th>Alt</th><th>Dokumente</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($auftraege as $a): $d = $a['angelegt']; ?>
      <tr>
        <td><strong><?= h($a['nummer']) ?></strong></td>
        <td><?= h($a['kunde'] ?: '–') ?></td>
        <td><?= h($a['produkt'] ?: '–') ?></td>
        <td><?= $d ? h(fmt_zeit($d, 'd.m.Y')) : '<span class="muted">–</span>' ?></td>
        <td><?= $a['v3_id'] ? bx_badge('alt (v3)', 'info') : '<span class="muted">neu</span>' ?></td>
        <td><?= (int)$a['docs'] > 0 ? bx_badge((int)$a['docs'] . ' Dok.', 'ok') : '<span class="muted">–</span>' ?></td>
        <td style="text-align:right"><a class="btn btn-ghost btn-sm" href="?p=alt_rechnungen&aid=<?= (int)$a['id'] ?><?= $suche !== '' ? '&q=' . urlencode($suche) : '' ?>">Dokumente…</a></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
</div>

<?php if ($info): ?>
<dialog id="arDlg" open style="max-width:640px;width:calc(100% - 32px);border:1px solid var(--line);border-radius:14px;padding:0">
  <div style="padding:18px 20px">
    <div class="bx-row" style="justify-content:space-between;align-items:flex-start;gap:10px">
      <h2 style="margin:0;font-size:17px">Auftrag <?= h($info['nummer']) ?></h2>
      <a class="btn btn-ghost btn-sm" href="?p=alt_rechnungen<?= $suche !== '' ? '&q=' . urlencode($suche) : '' ?>">Schließen</a>
    </div>
    <div class="bx-tablewrap" style="margin-top:12px"><table class="bx-table"><tbody>
      <tr><td class="muted" style="width:160px">Kunde</td><td><?= $info['kunde_id'] ? '<a href="?p=kunde&id=' . (int)$info['kunde_id'] . '">' . h($info['kunde'] ?: '–') . '</a>' : h($info['kunde'] ?: '–') ?></td></tr>
      <tr><td class="muted">Produkt</td><td><?= h($info['produkt'] ?: '–') ?></td></tr>
      <tr><td class="muted">Menge</td><td><?= number_format((float)$info['menge'], 0, ',', '.') ?> Packungen</td></tr>
      <tr><td class="muted">Auftragsdatum</td><td><?= $info['angelegt'] ? h(fmt_zeit($info['angelegt'], 'd.m.Y')) : '–' ?></td></tr>
      <tr><td class="muted">Status</td><td><?= h(status_text((string)$info['status'])) ?></td></tr>
      <?php if ($info['angebot_nr']): ?><tr><td class="muted">Angebot</td><td><?= h($info['angebot_nr']) ?></td></tr><?php endif; ?>
      <tr><td class="muted">Herkunft</td><td><?= $info['v3_id'] ? bx_badge('alt (v3-Import)', 'info') : '<span class="muted">neu (im v4 angelegt)</span>' ?></td></tr>
    </tbody></table></div>

    <h3 style="margin:16px 0 6px;font-size:15px">Hochgeladene Dokumente</h3>
    <?php if ($infoDocs): ?>
      <div class="bx-tablewrap"><table class="bx-table">
        <thead><tr><th>Typ</th><th>Datum</th><th>Datei</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($infoDocs as $dd): ?>
          <tr>
            <td><?= h($TYPEN[$dd['typ']] ?? $dd['typ']) ?></td>
            <td><?= $dd['dok_datum'] ? h(fmt_zeit($dd['dok_datum'] . ' 00:00:00', 'd.m.Y')) : h(fmt_zeit($dd['angelegt'], 'd.m.Y')) ?></td>
            <td><a href="?p=dokument&id=<?= (int)$dd['id'] ?>" target="_blank"><?= h($dd['titel'] ?: ($dd['datei_orig'] ?: 'Dokument')) ?></a></td>
            <td style="text-align:right"><form method="post" style="margin:0" onsubmit="return confirm('Dokument löschen?');"><input type="hidden" name="aktion" value="re_del"><input type="hidden" name="dok_id" value="<?= (int)$dd['id'] ?>"><button class="btn btn-ghost btn-sm" type="submit">Löschen</button></form></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table></div>
    <?php else: ?>
      <div class="muted" style="margin-bottom:8px">Noch keine Dokumente zu diesem Auftrag.</div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data" style="margin-top:14px" data-busy="Lade hoch…">
      <input type="hidden" name="aktion" value="re_upload">
      <input type="hidden" name="auftrag_id" value="<?= (int)$info['id'] ?>">
      <div class="bx-grid">
        <div class="bx-field"><label>Typ</label>
          <select name="typ"><?php foreach ($TYPEN as $tk => $tl): ?><option value="<?= $tk ?>"><?= h($tl) ?></option><?php endforeach; ?></select>
        </div>
        <div class="bx-field"><label>Datum (optional)</label><input type="date" name="datum"></div>
        <div class="bx-field"><label>Titel (optional)</label><input type="text" name="titel" placeholder="z. B. Rechnung 2024-118"></div>
        <div class="bx-field"><label>Datei(en)</label><input type="file" name="dok[]" multiple required accept="application/pdf,image/*"></div>
      </div>
      <div class="muted" style="font-size:12px;margin-top:4px">Wird dem Kunden im Portal bei dieser Bestellung angezeigt.</div>
      <div class="bx-row" style="margin-top:var(--sp-3)"><button class="btn btn-primary" type="submit">Hochladen</button></div>
    </form>
  </div>
</dialog>
<?php endif; ?>
<script>
function arFilter(v){ v=(v||'').toLowerCase().replace(/[^a-z0-9]+/g,''); document.querySelectorAll('#arTab tbody tr').forEach(function(tr){ var t=tr.textContent.toLowerCase().replace(/[^a-z0-9]+/g,''); tr.style.display = t.indexOf(v)>=0 ? '' : 'none'; }); }
</script>
<?php render_footer(); ?>

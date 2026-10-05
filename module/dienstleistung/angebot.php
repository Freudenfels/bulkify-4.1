<?php
// DL-Angebot bearbeiten: Positionen aus dem Dienstleistungs-Katalog, Status, Auftrag erzeugen.
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';
require_once BX_ROOT . '/core/dienstleistung.php';

$id = (int)($_GET['id'] ?? 0);
$a  = $id ? dl_angebot_laden($id) : null;
if (!$a) { header('Location: ?p=dl_angebote'); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aktion = $_POST['aktion'] ?? '';
    if ($aktion === 'kopf_save') {
        q("UPDATE angebot SET kunde_id=?, gueltig_bis=?, notiz=? WHERE id=? AND kategorie='dienstleistung'",
          [(int)($_POST['kunde_id'] ?? 0) ?: null, trim($_POST['gueltig_bis'] ?? '') ?: null, trim($_POST['notiz'] ?? '') ?: null, $id]);
        header('Location: ?p=dl_angebot&id=' . $id . '&ok=1'); exit;
    }
    if ($aktion === 'pos_add') {
        $dlid = (int)($_POST['dienstleistung_id'] ?? 0);
        $menge = (float) str_replace(',', '.', (string)($_POST['menge'] ?? '1'));
        if ($dlid > 0) dl_position_add($id, $dlid, $menge);
        header('Location: ?p=dl_angebot&id=' . $id . '&ok=1'); exit;
    }
    if ($aktion === 'pos_save') {
        foreach (($_POST['menge'] ?? []) as $pid => $m) {
            $pid = (int)$pid;
            $menge = (float) str_replace(',', '.', (string)$m);
            $preis = dienstleistung_cent((string)($_POST['preis'][$pid] ?? '0'));
            dl_position_update($pid, $menge, $preis);
        }
        header('Location: ?p=dl_angebot&id=' . $id . '&ok=1'); exit;
    }
    if ($aktion === 'pos_del') { dl_position_del((int)($_POST['pos_id'] ?? 0)); header('Location: ?p=dl_angebot&id=' . $id); exit; }
    if ($aktion === 'status')  { dl_angebot_status($id, (string)($_POST['status'] ?? '')); header('Location: ?p=dl_angebot&id=' . $id . '&ok=1'); exit; }
    if ($aktion === 'auftrag') {
        dl_angebot_status($id, 'bestaetigt');
        $aid = dl_auftrag_aus_angebot($id);
        header('Location: ' . ($aid ? '?p=dl_auftrag&id=' . $aid : '?p=dl_angebot&id=' . $id)); exit;
    }
}

$pos    = dl_positionen($id);
$sum    = dl_angebot_summe($id);
$kunden = all("SELECT id, firma FROM kunden WHERE COALESCE(gesperrt,0)=0 ORDER BY firma");
$katalog = dienstleistungen_alle(true);
$auftragId = (int) scalar("SELECT id FROM auftrag WHERE angebot_id=? LIMIT 1", [$id]);
$statusBadge = fn($s) => match ($s) {
    'offen'=>bx_badge('offen','info'), 'gesendet'=>bx_badge('gesendet'),
    'bestaetigt'=>bx_badge('bestätigt','ok'), 'abgelehnt'=>bx_badge('abgelehnt','err'), default=>bx_badge(status_text($s)) };

render_header('dienstleistungen', (string)$a['nummer']);
bx_head('DL-Angebot ' . h((string)$a['nummer']), '', bx_btn('Zurück zur Liste', '?p=dl_angebote', 'ghost'));
dl_subtabs('dl_angebote');
if (isset($_GET['ok'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Gespeichert.</div>';
?>
<div class="bx-cards">
  <div class="bx-card"><div class="k">Status</div><div class="v"><?= $statusBadge($a['status']) ?></div></div>
  <div class="bx-card"><div class="k">Netto</div><div class="v"><?= number_format((float)$sum['netto'],2,',','.') ?> €</div></div>
  <div class="bx-card"><div class="k">Brutto</div><div class="v"><?= number_format((float)$sum['brutto'],2,',','.') ?> €</div></div>
  <div class="bx-card"><div class="k">Auftrag</div><div class="v"><?= $auftragId ? '<a href="?p=dl_auftrag&id=' . $auftragId . '">vorhanden</a>' : '–' ?></div></div>
</div>

<div class="bx-panel">
  <h2 style="margin-top:0">Kopf</h2>
  <form method="post"><input type="hidden" name="aktion" value="kopf_save">
    <div class="bx-grid">
      <div class="bx-field"><label>Kunde</label>
        <select name="kunde_id"><option value="">– ohne Kunde –</option>
          <?php foreach ($kunden as $k): ?><option value="<?= (int)$k['id'] ?>"<?= (int)($a['kunde_id']??0)===(int)$k['id']?' selected':'' ?>><?= h($k['firma']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="bx-field" style="max-width:180px"><label>Gültig bis</label><input type="date" name="gueltig_bis" value="<?= h((string)($a['gueltig_bis'] ?? '')) ?>"></div>
      <div class="bx-field"><label>Notiz</label><input type="text" name="notiz" value="<?= h((string)($a['notiz'] ?? '')) ?>"></div>
    </div>
    <div class="bx-row" style="margin-top:var(--sp-4)"><button class="btn btn-ghost" type="submit">Kopf speichern</button></div>
  </form>
</div>

<div class="bx-panel">
  <h2 style="margin-top:0">Positionen</h2>
  <?php if ($pos): ?>
  <form method="post"><input type="hidden" name="aktion" value="pos_save">
    <div class="bx-tablewrap"><table class="bx-table">
      <thead><tr><th>Leistung</th><th class="bx-num">Menge</th><th>Einheit</th><th class="bx-num">Preis/Einheit (€)</th><th class="bx-num">MwSt</th><th class="bx-num">Zeile netto</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($pos as $p): $zeile = (float)$p['menge'] * (int)$p['preis_cent'] / 100; ?>
        <tr>
          <td><?= h($p['bezeichnung']) ?><?php if (!empty($p['artikelnr'])): ?><div class="muted" style="font-size:12px"><?= h($p['artikelnr']) ?></div><?php endif; ?></td>
          <td class="bx-num"><input type="text" name="menge[<?= (int)$p['id'] ?>]" value="<?= h(rtrim(rtrim(number_format((float)$p['menge'],3,',',''),'0'),',')) ?>" style="max-width:90px;text-align:right"></td>
          <td><?= h($p['einheit'] ?: '–') ?></td>
          <td class="bx-num"><input type="text" name="preis[<?= (int)$p['id'] ?>]" value="<?= dienstleistung_eur((int)$p['preis_cent']) ?>" style="max-width:120px;text-align:right"></td>
          <td class="bx-num"><?= rtrim(rtrim(number_format((float)$p['mwst_satz'],2,',','.'),'0'),',') ?> %</td>
          <td class="bx-num"><?= number_format($zeile,2,',','.') ?> €</td>
          <td class="bx-num" onclick="event.stopPropagation()">
            <button class="btn btn-ghost btn-sm" type="submit" form="del<?= (int)$p['id'] ?>">löschen</button>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <div class="bx-row" style="margin-top:var(--sp-4)"><button class="btn btn-primary" type="submit">Mengen/Preise speichern</button></div>
  </form>
  <?php foreach ($pos as $p): ?><form id="del<?= (int)$p['id'] ?>" method="post" style="display:none"><input type="hidden" name="aktion" value="pos_del"><input type="hidden" name="pos_id" value="<?= (int)$p['id'] ?>"></form><?php endforeach; ?>
  <?php else: ?><div class="muted" style="margin-bottom:12px">Noch keine Positionen.</div><?php endif; ?>

  <form method="post" class="bx-row" style="align-items:flex-end;gap:12px;margin-top:16px;border-top:1px solid var(--line,#e5e5e5);padding-top:16px">
    <input type="hidden" name="aktion" value="pos_add">
    <div class="bx-field" style="min-width:320px;margin-bottom:0"><label>Dienstleistung hinzufügen</label>
      <select name="dienstleistung_id" required><option value="">– aus dem Katalog wählen –</option>
        <?php foreach ($katalog as $d): ?><option value="<?= (int)$d['id'] ?>"><?= h($d['name']) ?> (<?= h(dienstleistung_preis_text($d)) ?>)</option><?php endforeach; ?>
      </select>
    </div>
    <div class="bx-field" style="max-width:100px;margin-bottom:0"><label>Menge</label><input type="text" name="menge" value="1" style="text-align:right"></div>
    <button class="btn btn-ghost" type="submit">+ Position</button>
  </form>
  <?php if (!$katalog): ?><p class="muted" style="font-size:12px;margin-top:8px">Kein aktiver Katalog-Eintrag. Lege zuerst unter „Katalog" Dienstleistungen an.</p><?php endif; ?>
</div>

<div class="bx-panel">
  <h2 style="margin-top:0">Status &amp; Auftrag</h2>
  <div class="bx-row" style="gap:8px;flex-wrap:wrap">
    <form method="post" style="margin:0"><input type="hidden" name="aktion" value="status"><input type="hidden" name="status" value="gesendet"><button class="btn btn-ghost btn-sm" type="submit">als gesendet markieren</button></form>
    <form method="post" style="margin:0"><input type="hidden" name="aktion" value="status"><input type="hidden" name="status" value="abgelehnt"><button class="btn btn-ghost btn-sm" type="submit">abgelehnt</button></form>
    <span style="flex:1"></span>
    <?php if (!$auftragId && $pos): ?>
      <form method="post" style="margin:0"><input type="hidden" name="aktion" value="auftrag"><button class="btn btn-primary" type="submit">Angebot bestätigen &rarr; DL-Auftrag anlegen</button></form>
    <?php elseif ($auftragId): ?>
      <a class="btn btn-primary" href="?p=dl_auftrag&id=<?= $auftragId ?>">Zum DL-Auftrag</a>
    <?php endif; ?>
  </div>
</div>
<?php render_footer();

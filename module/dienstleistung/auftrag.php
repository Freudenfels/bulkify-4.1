<?php
// DL-Auftrag (Detail): Positionen, Status, DL-Rechnung (DR-) erstellen.
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';
require_once BX_ROOT . '/core/dienstleistung.php';

$id = (int)($_GET['id'] ?? 0);
$a  = $id ? dl_auftrag_laden($id) : null;
if (!$a) { header('Location: ?p=dl_auftraege'); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aktion = $_POST['aktion'] ?? '';
    if ($aktion === 'status') {
        $s = $_POST['status'] ?? '';
        if (in_array($s, ['offen','in_arbeit','erledigt'], true)) q("UPDATE auftrag SET status=? WHERE id=? AND kategorie='dienstleistung'", [$s, $id]);
        header('Location: ?p=dl_auftrag&id=' . $id . '&ok=1'); exit;
    }
    if ($aktion === 'rechnung') {
        $opt = [
            'zahlungsziel_tage' => $_POST['zahlungsziel_tage'] ?? '',
            'freigeben'         => isset($_POST['freigeben']) ? 1 : 0,
            'ersteller'         => current_user()['name'] ?? 'team',
        ];
        $bid = dl_rechnung_aus_auftrag($id, $opt);
        header('Location: ' . ($bid ? '/buchhaltung/?p=rechnung&id=' . $bid : '?p=dl_auftrag&id=' . $id . '&fehler=1')); exit;
    }
}

$pos = dl_positionen((int)$a['angebot_id']);
$rechnung = one("SELECT id, nummer, brutto, status FROM beleg WHERE auftrag_id=? AND typ='rechnung' AND status<>'storniert' LIMIT 1", [$id]);
$statusBadge = fn($s) => match ($s) {
    'offen'=>bx_badge('offen','info'), 'in_arbeit'=>bx_badge('in Arbeit','warn'),
    'erledigt'=>bx_badge('erledigt','ok'), default=>bx_badge(status_text($s)) };

render_header('dienstleistungen', (string)$a['nummer']);
bx_head('DL-Auftrag ' . h((string)$a['nummer']), $a['angebot_nummer'] ? 'aus Angebot ' . h((string)$a['angebot_nummer']) : '', bx_btn('Zurück zur Liste', '?p=dl_auftraege', 'ghost'));
dl_subtabs('dl_auftraege');
if (isset($_GET['ok']))     echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Gespeichert.</div>';
if (isset($_GET['fehler'])) echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:12px 16px">Rechnung konnte nicht erstellt werden (keine Positionen/kein Betrag).</div>';
?>
<div class="bx-cards">
  <div class="bx-card"><div class="k">Kunde</div><div class="v"><?= kunde_link($a['kunde_id'] ?? null, $a['kunde_firma']) ?></div></div>
  <div class="bx-card"><div class="k">Status</div><div class="v"><?= $statusBadge($a['status']) ?></div></div>
  <div class="bx-card"><div class="k">Netto</div><div class="v"><?= number_format((float)$a['gesamt_netto'],2,',','.') ?> €</div></div>
  <div class="bx-card"><div class="k">Rechnung</div><div class="v"><?= $rechnung ? '<a href="/buchhaltung/?p=rechnung&id=' . (int)$rechnung['id'] . '">' . h($rechnung['nummer']) . '</a>' : '–' ?></div></div>
</div>

<div class="bx-panel">
  <h2 style="margin-top:0">Positionen</h2>
  <?php if ($pos): ?>
  <div class="bx-tablewrap"><table class="bx-table">
    <thead><tr><th>Leistung</th><th class="bx-num">Menge</th><th>Einheit</th><th class="bx-num">Preis/Einheit</th><th class="bx-num">Zeile netto</th></tr></thead>
    <tbody>
    <?php foreach ($pos as $p): $zeile=(float)$p['menge']*(int)$p['preis_cent']/100; ?>
      <tr><td><?= h($p['bezeichnung']) ?></td><td class="bx-num"><?= h(rtrim(rtrim(number_format((float)$p['menge'],3,',',''),'0'),',')) ?></td><td><?= h($p['einheit'] ?: '–') ?></td><td class="bx-num"><?= dienstleistung_eur((int)$p['preis_cent']) ?> €</td><td class="bx-num"><?= number_format($zeile,2,',','.') ?> €</td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php else: ?><div class="muted">Keine Positionen am zugehörigen Angebot.</div><?php endif; ?>
</div>

<div class="bx-panel">
  <h2 style="margin-top:0">Status</h2>
  <div class="bx-row" style="gap:8px">
    <form method="post" style="margin:0"><input type="hidden" name="aktion" value="status"><input type="hidden" name="status" value="in_arbeit"><button class="btn btn-ghost btn-sm" type="submit">in Arbeit</button></form>
    <form method="post" style="margin:0"><input type="hidden" name="aktion" value="status"><input type="hidden" name="status" value="erledigt"><button class="btn btn-ghost btn-sm" type="submit">erledigt</button></form>
  </div>
</div>

<div class="bx-panel">
  <h2 style="margin-top:0">DL-Rechnung (DR-)</h2>
  <?php if ($rechnung): ?>
    <p>Rechnung <a href="/buchhaltung/?p=rechnung&id=<?= (int)$rechnung['id'] ?>"><strong><?= h($rechnung['nummer']) ?></strong></a> · <?= number_format((float)$rechnung['brutto'],2,',','.') ?> € · <?= h(status_text($rechnung['status'])) ?>. Zahlungen werden in der Buchhaltung erfasst.</p>
  <?php else: ?>
    <form method="post" class="bx-row" style="align-items:flex-end;gap:12px">
      <input type="hidden" name="aktion" value="rechnung">
      <div class="bx-field" style="max-width:160px;margin-bottom:0"><label>Zahlungsziel (Tage)</label><input type="number" name="zahlungsziel_tage" min="0" placeholder="z. B. 14"></div>
      <div class="bx-field" style="margin-bottom:0"><label>Im Portal freigeben</label><div class="bx-check" style="padding-top:8px"><input type="checkbox" name="freigeben" id="f_frei" value="1"><label for="f_frei" style="margin:0">Kunde sieht die Rechnung</label></div></div>
      <button class="btn btn-primary" type="submit">DL-Rechnung erstellen</button>
    </form>
    <p class="muted" style="font-size:12px;margin-top:8px">Erzeugt eine Rechnung mit eigenem Nummernkreis DR-… und übernimmt die Positionen. Sie erscheint danach auch im zentralen Kassenbuch der Buchhaltung.</p>
  <?php endif; ?>
</div>
<?php render_footer();

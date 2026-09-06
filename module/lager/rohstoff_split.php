<?php
// Rohstoffe aufschlüsseln: zu lange Namen (mehrere Varianten in einem Feld) in einzelne Rohstoffe
// zerlegen. KI schlägt die Varianten vor (auf beta), der Mensch prüft/editiert und übernimmt.
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';
require_once BX_ROOT . '/core/rohstoff_split.php';

// --- Aktionen (PRG) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $akt = $_POST['aktion'] ?? '';
    $ret = '?p=rohstoff_split' . (isset($_POST['ret']) ? $_POST['ret'] : '');
    if ($akt === 'ki_batch') {
        @set_time_limit(300);
        $r = rohstoff_split_ki_batch(max(1, min(40, (int)($_POST['limit'] ?? 10))));
        $_SESSION['rs_flash'] = !empty($r['meldung']) ? $r['meldung'] : ('KI: ' . $r['verarbeitet'] . ' Vorschlag/Vorschläge erzeugt.');
        header('Location: ' . $ret); exit;
    }
    if ($akt === 'anwenden') {
        $zeilen = preg_split('/\r\n|\r|\n/', (string)($_POST['varianten'] ?? ''));
        $r = rohstoff_split_anwenden((int)($_POST['item_id'] ?? 0), $zeilen);
        $_SESSION['rs_flash'] = $r['ok'] ? ('Aufgeschlüsselt: ' . $r['gesamt'] . ' Varianten (' . $r['neu'] . ' neu angelegt).') : ('Nicht übernommen: ' . ($r['fehler'] ?? ''));
        header('Location: ' . $ret); exit;
    }
    if ($akt === 'verwerfen') { rohstoff_split_verwerfen((int)($_POST['item_id'] ?? 0)); $_SESSION['rs_flash'] = 'Übersprungen.'; header('Location: ' . $ret); exit; }
}

$statusF = $_GET['status'] ?? 'todo';   // todo | uebernommen | verworfen
$rows = all("SELECT i.id, i.name, i.artikelnummer, CHAR_LENGTH(i.name) AS len, v.varianten_json, v.status AS vstatus, v.basis
             FROM item i LEFT JOIN rohstoff_variante_vorschlag v ON v.item_id=i.id
             WHERE i.kategorie='rohstoff' AND CHAR_LENGTH(i.name) > ?
             " . ($statusF === 'uebernommen' ? "AND v.status='uebernommen'"
                : ($statusF === 'verworfen' ? "AND v.status='verworfen'"
                : "AND (v.status IS NULL OR v.status='offen')")) . "
             ORDER BY i.name LIMIT 400", [ROHSTOFF_NAME_LANG]);

$offenN = (int) scalar("SELECT COUNT(*) FROM item i LEFT JOIN rohstoff_variante_vorschlag v ON v.item_id=i.id
                        WHERE i.kategorie='rohstoff' AND CHAR_LENGTH(i.name) > ? AND (v.status IS NULL OR v.status='offen')", [ROHSTOFF_NAME_LANG]);
$mitVorschlag = (int) scalar("SELECT COUNT(*) FROM rohstoff_variante_vorschlag WHERE status='offen' AND varianten_json IS NOT NULL");
$fertigN = (int) scalar("SELECT COUNT(*) FROM rohstoff_variante_vorschlag WHERE status='uebernommen'");

$flash = $_SESSION['rs_flash'] ?? null; unset($_SESSION['rs_flash']);
$kiDa = ki_bereit();

render_header('rohstoffe', 'Rohstoffe aufschlüsseln');
bx_head('Rohstoffe aufschlüsseln', 'zu lange Namen (mehrere Varianten) in einzelne Rohstoffe zerlegen', bx_btn('Zurück', '?p=rohstoffe', 'ghost'));
if ($flash) echo '<div class="bx-panel badge-ok" style="padding:10px 14px">' . h($flash) . '</div>';
?>
<div class="bx-panel" style="display:flex;flex-wrap:wrap;gap:12px;align-items:center;justify-content:space-between">
  <div>
    <?php foreach (['todo'=>'offen ('.$offenN.')','uebernommen'=>'übernommen ('.$fertigN.')','verworfen'=>'übersprungen'] as $k=>$lbl): ?>
      <a class="btn btn-sm <?= $statusF===$k?'btn-primary':'btn-ghost' ?>" href="?p=rohstoff_split&status=<?= $k ?>"><?= $lbl ?></a>
    <?php endforeach; ?>
  </div>
  <form method="post" style="display:flex;gap:8px;align-items:center;margin:0">
    <input type="hidden" name="aktion" value="ki_batch"><input type="hidden" name="ret" value="&status=<?= h($statusF) ?>">
    <label class="muted" style="font-size:13px">KI-Vorschläge für nächste
      <input type="number" name="limit" value="10" min="1" max="40" style="width:60px"> ohne Vorschlag</label>
    <button class="btn btn-primary btn-sm" type="submit" data-busy="KI schlägt vor…" <?= (!$kiDa || $offenN===0) ? 'disabled' : '' ?>>KI-Vorschläge erzeugen</button>
  </form>
</div>
<?php if (!$kiDa): ?><div class="muted" style="font-size:12px;margin:-6px 2px 10px">KI-Vorschläge nur auf beta. Du kannst die Varianten aber überall von Hand eintragen (eine pro Zeile) und übernehmen.</div><?php endif; ?>

<?php if (!$rows): ?>
  <div class="bx-panel muted"><?= $statusF==='todo' ? 'Keine zu langen Rohstoffnamen offen. 🎉' : 'Nichts in dieser Ansicht.' ?></div>
<?php else: foreach ($rows as $r):
    $vorschlag = $r['varianten_json'] ? json_decode($r['varianten_json'], true) : null;
    $prefill = is_array($vorschlag) && $vorschlag ? implode("\n", $vorschlag) : (string)$r['name'];
    $zeilen = max(2, min(8, is_array($vorschlag) ? count($vorschlag) : 2));
?>
  <div class="bx-panel">
    <div class="bx-row" style="justify-content:space-between;align-items:baseline;gap:12px;flex-wrap:wrap">
      <div><a class="kundenlink" href="?p=rohstoff&id=<?= (int)$r['id'] ?>"><?= h($r['artikelnummer']) ?></a> · <span class="muted" style="font-size:12px"><?= (int)$r['len'] ?> Zeichen</span></div>
      <?php if ($statusF==='uebernommen'): ?><?= bx_badge('übernommen','ok') ?><?php endif; ?>
    </div>
    <div style="font-size:13px;line-height:1.4;margin:6px 0 10px"><?= h($r['name']) ?></div>
    <?php if ($statusF==='todo'): ?>
      <form method="post" style="margin:0">
        <input type="hidden" name="aktion" value="anwenden"><input type="hidden" name="item_id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="ret" value="&status=todo">
        <label class="muted" style="font-size:12px">Varianten – eine pro Zeile (erste Zeile bleibt am Original-Datensatz):</label>
        <textarea name="varianten" rows="<?= $zeilen ?>" style="width:100%;font-size:13px"><?= h($prefill) ?></textarea>
        <div class="bx-row" style="gap:8px;margin-top:8px">
          <button class="btn btn-primary btn-sm" type="submit" data-busy="…">Aufschlüsseln übernehmen</button>
          <button class="btn btn-ghost btn-sm" type="submit" formnovalidate name="aktion" value="verwerfen">Überspringen</button>
          <?php if (!$vorschlag): ?><span class="muted" style="font-size:12px;align-self:center">noch kein KI-Vorschlag – Zeilen selbst setzen</span><?php endif; ?>
        </div>
      </form>
    <?php endif; ?>
  </div>
<?php endforeach; endif; ?>
<?php
render_footer();

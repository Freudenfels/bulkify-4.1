<?php
// EK-Preise (Import): eingelesene EK-Preislisten (Tabelle ek_import) ansehen UND den v4-Rohstoffen/
// Produkten zuordnen – per KI-Vorschlag (läuft auf beta) oder von Hand. Bestätigte Rohstoff-Zeilen
// werden als lieferant_preis übernommen (füllt „Preis ab"/Lieferant). Fertigprodukt-Preise sind
// INTERN (Zukauf – nie Kundensicht).
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';
require_once BX_ROOT . '/core/ek_ki.php';

$typ = ($_GET['typ'] ?? 'rohstoff') === 'fertigprodukt' ? 'fertigprodukt' : 'rohstoff';

// --- Aktionen (PRG-Muster) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $akt = $_POST['aktion'] ?? '';
    $ret = '?p=ek_import&typ=' . $typ . (isset($_POST['ret']) ? $_POST['ret'] : '');
    if ($akt === 'ki_batch') {
        @set_time_limit(300);
        $r = ek_ki_batch($typ, max(1, min(50, (int)($_POST['limit'] ?? 15))));
        $_SESSION['ek_flash'] = $r['fehler'] ?? false
            ? null : ('KI: ' . $r['vorschlag'] . ' Vorschlag/Vorschläge, ' . $r['kein_treffer'] . ' ohne Treffer' . ($r['fehler'] ? ', ' . $r['fehler'] . ' Fehler' : '') . '.');
        if (!empty($r['meldung'])) $_SESSION['ek_flash'] = $r['meldung'];
        header('Location: ' . $ret); exit;
    }
    if ($akt === 'links_bereinigen') { $n = ek_links_bereinigen(); $_SESSION['ek_flash'] = $n . ' Marktplatz-Link(s) in die Notiz verschoben (Lieferant = Plattform).'; header('Location: ' . $ret); exit; }
    if ($akt === 'alias_add') {
        lieferant_alias_speichern((string)($_POST['alias'] ?? ''), (string)($_POST['firma'] ?? ''), (string)($_POST['kontakt'] ?? ''));
        $n = lieferant_alias_anwenden();
        $_SESSION['ek_flash'] = 'Alias gespeichert und auf ' . $n . ' Zeile(n) angewendet.';
        header('Location: ' . $ret); exit;
    }
    if ($akt === 'alias_del') { lieferant_alias_loeschen((int)($_POST['id'] ?? 0)); header('Location: ' . $ret); exit; }
    if ($akt === 'bestaetigen') { ek_bestaetigen((int)($_POST['id'] ?? 0)); header('Location: ' . $ret); exit; }
    if ($akt === 'verwerfen')   { ek_verwerfen((int)($_POST['id'] ?? 0));   header('Location: ' . $ret); exit; }
    if ($akt === 'neu_anlegen') {
        $r = ek_neu_anlegen((int)($_POST['id'] ?? 0));
        $_SESSION['ek_flash'] = $r['ok']
            ? (($r['typ'] === 'produkt' ? 'Produkt' : 'Rohstoff') . ' „' . h($r['name']) . '" neu angelegt und zugeordnet.')
            : ('Nicht angelegt: ' . ($r['fehler'] ?? ''));
        header('Location: ' . $ret); exit;
    }
    if ($akt === 'manuell')     { $ok = ek_manuell_zuordnen((int)($_POST['id'] ?? 0), (string)($_POST['eingabe'] ?? ''), true);
        $_SESSION['ek_flash'] = $ok ? 'Manuell zugeordnet und bestätigt.' : 'Kein passender Eintrag zur Eingabe gefunden.';
        header('Location: ' . $ret); exit; }
}

// Lieferant-Aliase (Kontaktname -> Firma) beim Laden anwenden – idempotent (nach dem Setzen
// matcht der Lieferant den Alias nicht mehr). So wirkt z. B. Maggi = Wellgreen sofort.
lieferant_alias_anwenden();
$aliase = all("SELECT * FROM lieferant_alias ORDER BY firma, alias");

$q        = trim((string)($_GET['q'] ?? ''));
$statusF  = $_GET['status'] ?? '';
$STATUS   = ['offen'=>'offen','vorschlag'=>'KI-Vorschlag','bestaetigt'=>'bestätigt','kein_treffer'=>'kein Treffer','verworfen'=>'verworfen'];

// Spalten mit e. qualifizieren – durch die JOINs sind name/status/lieferant sonst mehrdeutig.
$where = "WHERE e.typ=?"; $args = [$typ];
if ($q !== '')  { $where .= " AND (e.name LIKE ? OR e.lieferant LIKE ? OR e.formulierung LIKE ? OR e.notiz LIKE ?)"; array_push($args, "%$q%", "%$q%", "%$q%", "%$q%"); }
if (isset($STATUS[$statusF])) { $where .= " AND e.status=?"; $args[] = $statusF; }

$rows = all("SELECT e.*, i.name AS item_name, i.artikelnummer, p.name AS produkt_name, p.nummer AS produkt_nr
             FROM ek_import e LEFT JOIN item i ON i.id=e.item_id LEFT JOIN produkt p ON p.id=e.produkt_id
             $where ORDER BY FIELD(e.status,'vorschlag','offen','kein_treffer','bestaetigt','verworfen'), e.name LIMIT 800", $args);

// Zähler je Status (für Reiter/Info)
$zaehler = [];
foreach (all("SELECT status, COUNT(*) c FROM ek_import WHERE typ=? GROUP BY status", [$typ]) as $z) $zaehler[$z['status']] = (int)$z['c'];
$anzRoh    = (int) scalar("SELECT COUNT(*) FROM ek_import WHERE typ='rohstoff'");
$anzFertig = (int) scalar("SELECT COUNT(*) FROM ek_import WHERE typ='fertigprodukt'");
// Nur echte URLs zählen (Pfad-Slash, Protokoll, www., Domain-Endung) – nicht den Plattformnamen „Alibaba".
$linkN     = (int) scalar("SELECT COUNT(*) FROM ek_import WHERE lieferant REGEXP 'https?://|www[.]|[.](com|cn|net|de|org|html)|/'");
$offenN    = $zaehler['offen'] ?? 0;
$retQuery  = ($q !== '' ? '&q=' . urlencode($q) : '') . (isset($STATUS[$statusF]) ? '&status=' . $statusF : '');

$preisFmt = function ($e) {
    $p = (float)$e['preis'];
    $eh = $e['einheit'] === 'kg' ? '/kg' : ($e['einheit'] === 'kapsel' ? '/Kapsel' : '/' . $e['einheit']);
    return number_format($p, $p < 1 ? 4 : 2, ',', '.') . ' €' . $eh;
};

$flash = $_SESSION['ek_flash'] ?? null; unset($_SESSION['ek_flash']);
$kiDa  = ki_bereit();

render_header('ek_import', 'EK-Preise (Import)');
bx_head('EK-Preise (Import)', 'EK-Preise zuordnen – KI-Vorschlag oder von Hand', bx_btn('Zurück', '?p=lief_preisliste', 'ghost'));
if ($flash) echo '<div class="bx-panel badge-ok" style="padding:10px 14px">' . h($flash) . '</div>';
?>
<form method="get" class="bx-listbar">
  <input type="hidden" name="p" value="ek_import">
  <select name="typ" onchange="this.form.submit()">
    <option value="rohstoff" <?= $typ==='rohstoff'?'selected':'' ?>>Rohstoffe / Bulk (<?= $anzRoh ?>)</option>
    <option value="fertigprodukt" <?= $typ==='fertigprodukt'?'selected':'' ?>>Fertigprodukte – intern (<?= $anzFertig ?>)</option>
  </select>
  <select name="status" onchange="this.form.submit()">
    <option value="">alle Status</option>
    <?php foreach ($STATUS as $k=>$lbl): ?><option value="<?= $k ?>" <?= $statusF===$k?'selected':'' ?>><?= $lbl ?> (<?= $zaehler[$k] ?? 0 ?>)</option><?php endforeach; ?>
  </select>
  <input class="bx-search" type="text" name="q" value="<?= h($q) ?>" placeholder="Name, Lieferant, Formulierung …">
  <button class="btn btn-ghost btn-sm" type="submit">Suchen</button>
  <?php if ($q!==''||$statusF!==''): ?><a class="btn btn-ghost btn-sm" href="?p=ek_import&typ=<?= $typ ?>">zurücksetzen</a><?php endif; ?>
</form>

<div class="bx-panel" style="display:flex;flex-wrap:wrap;gap:12px;align-items:center;justify-content:space-between">
  <div class="muted" style="font-size:13px">
    <?= ($zaehler['bestaetigt'] ?? 0) ?> zugeordnet · <?= $offenN ?> offen · <?= ($zaehler['vorschlag'] ?? 0) ?> KI-Vorschlag<?= $typ==='fertigprodukt' ? ' · interne Zukauf-Preise (nie Kundensicht)' : '' ?>
  </div>
  <form method="post" style="display:flex;gap:8px;align-items:center;margin:0">
    <input type="hidden" name="aktion" value="ki_batch">
    <input type="hidden" name="ret" value="<?= h($retQuery) ?>">
    <label class="muted" style="font-size:13px">KI-Zuordnung für die nächsten
      <input type="number" name="limit" value="15" min="1" max="50" style="width:64px"> offenen</label>
    <button class="btn btn-primary btn-sm" type="submit" data-busy="KI ordnet zu…" <?= ($offenN===0 || !$kiDa) ? 'disabled' : '' ?>>KI-Zuordnung starten</button>
  </form>
</div>
<?php if (!$kiDa): ?><div class="muted" style="font-size:12px;margin:-6px 2px 10px">Die KI-Zuordnung läuft nur auf beta (Schlüssel serverseitig). Manuelle Zuordnung geht überall.</div><?php endif; ?>
<?php if ($linkN > 0): ?>
<div class="bx-panel" style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:space-between;border-color:#e6c4c0;background:#fdf7f2">
  <div class="muted" style="font-size:13px"><strong style="font-weight:600"><?= $linkN ?></strong> Zeile(n) haben einen <strong>Marktplatz-Link</strong> (z. B. Alibaba) als „Lieferant". Verschieben in die Notiz – Lieferant wird zur Plattform (Alibaba/AliExpress). So legen wir keine Lieferanten mit Link an.</div>
  <form method="post" style="margin:0"><input type="hidden" name="aktion" value="links_bereinigen"><input type="hidden" name="ret" value="<?= h($retQuery) ?>">
    <button class="btn btn-primary btn-sm" type="submit" data-busy="…">Links in Notiz verschieben</button></form>
</div>
<?php endif; ?>

<details class="bx-panel" <?= $aliase ? '' : 'open' ?>>
  <summary style="cursor:pointer;font-weight:600">Lieferant-Aliase <span class="muted" style="font-weight:400">(Kontaktname → Firma) · <?= count($aliase) ?></span></summary>
  <p class="muted" style="font-size:13px;margin:8px 0">Viele „Lieferanten" in den Listen sind Kontakt-/Agenten-Namen (Maggi, Diane, Amy …). Trag hier ein, zu welcher <strong>Firma</strong> sie gehören – dann steht überall die echte Firma, der Kontakt wandert in die Notiz. Wirkt sofort auf alle passenden Zeilen.</p>
  <?php if ($aliase): ?>
    <div class="bx-tablewrap" style="margin-bottom:10px"><table class="bx-table">
      <thead><tr><th>Kontakt/Alias</th><th>Firma</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($aliase as $a): ?>
        <tr>
          <td><?= h($a['alias']) ?></td>
          <td><?= h($a['firma']) ?><?php if ($a['kontakt']): ?> <span class="muted" style="font-size:12px">· Kontakt <?= h($a['kontakt']) ?></span><?php endif; ?></td>
          <td><form method="post" style="margin:0"><input type="hidden" name="aktion" value="alias_del"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>"><input type="hidden" name="ret" value="<?= h($retQuery) ?>"><button class="btn btn-ghost btn-sm" type="submit">entfernen</button></form></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>
  <form method="post" class="bx-row" style="gap:8px;align-items:flex-end;flex-wrap:wrap">
    <input type="hidden" name="aktion" value="alias_add"><input type="hidden" name="ret" value="<?= h($retQuery) ?>">
    <div class="bx-field" style="margin:0"><label style="font-size:12px">Kontakt/Alias</label><input type="text" name="alias" placeholder="z. B. Maggi" required></div>
    <div class="bx-field" style="margin:0"><label style="font-size:12px">Firma</label><input type="text" name="firma" placeholder="z. B. Wellgreen" required></div>
    <div class="bx-field" style="margin:0"><label style="font-size:12px">Kontakt (optional)</label><input type="text" name="kontakt" placeholder="z. B. Maggi"></div>
    <button class="btn btn-primary btn-sm" type="submit" data-busy="…">Alias speichern &amp; anwenden</button>
  </form>
</details>

<div class="bx-panel">
<?php if (!$rows): ?>
  <div class="muted"><?= ($q!==''||$statusF!=='') ? 'Keine Treffer.' : 'Noch nichts importiert.' ?></div>
<?php else: ?>
  <div class="bx-tablewrap"><table class="bx-table">
    <thead><tr>
      <th><?= $typ==='rohstoff'?'Name (CSV)':'Produkt (CSV)' ?></th>
      <?php if ($typ==='fertigprodukt'): ?><th>Größe</th><?php endif; ?>
      <th>Lieferant</th><th class="bx-num">EK</th><th>Zuordnung</th><th>Aktion</th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $e):
        $ziel   = $typ==='rohstoff' ? $e['item_name'] : $e['produkt_name'];
        $zielId = $typ==='rohstoff' ? (int)$e['item_id'] : (int)$e['produkt_id'];
        $zielHref = $typ==='rohstoff' ? '?p=rohstoff&id='.$zielId : '?p=produkt&id='.$zielId;
        $st = $e['status'];
    ?>
      <tr>
        <td style="max-width:300px">
          <div style="white-space:normal"><?= h($e['name']) ?></div>
          <?php if ($typ==='fertigprodukt' && trim((string)$e['formulierung'])!==''): ?><div class="muted" style="font-size:11px;white-space:normal;overflow-wrap:anywhere"><?= h(mb_substr(trim((string)$e['formulierung']),0,110)) ?><?= mb_strlen(trim((string)$e['formulierung']))>110?'…':'' ?></div><?php endif; ?>
          <?php if (trim((string)$e['notiz'])!==''): ?><div class="muted" style="font-size:11px;white-space:normal;overflow-wrap:anywhere"><?= h($e['notiz']) ?></div><?php endif; ?>
        </td>
        <?php if ($typ==='fertigprodukt'): ?><td class="muted"><?= $e['groesse']?h($e['groesse']):'–' ?></td><?php endif; ?>
        <td style="white-space:nowrap"><?= $e['lieferant']?h($e['lieferant']):'<span class="muted">–</span>' ?></td>
        <td class="bx-num"><?= $preisFmt($e) ?></td>
        <td style="white-space:normal;max-width:340px;overflow-wrap:anywhere">
          <?php if ($ziel && in_array($st,['vorschlag','bestaetigt'],true)): ?>
            <a class="kundenlink" href="<?= h($zielHref) ?>"><?= h($ziel) ?></a>
            <?php if ($e['ki_score']!==null): ?><span class="muted" style="font-size:11px"> · <?= (int)$e['ki_score'] ?>%</span><?php endif; ?>
            <?php if ($st==='bestaetigt'): ?><?= bx_badge('übernommen','ok') ?><?php endif; ?>
            <?php if ($e['ki_hinweis']): ?><div class="muted" style="font-size:11px;line-height:1.35;margin-top:2px"><?= h($e['ki_hinweis']) ?></div><?php endif; ?>
          <?php elseif ($st==='kein_treffer'): ?>
            <span class="muted">kein KI-Treffer</span>
          <?php elseif ($st==='verworfen'): ?>
            <span class="muted">verworfen</span>
          <?php else: ?>
            <span class="muted">– offen –</span>
          <?php endif; ?>
        </td>
        <td>
          <?php
            $neuLabel = $typ==='rohstoff' ? '+ Neuer Rohstoff' : '+ Neues Produkt';
            $neuForm = '<form method="post" style="margin:0"><input type="hidden" name="aktion" value="neu_anlegen"><input type="hidden" name="id" value="'.(int)$e['id'].'"><input type="hidden" name="ret" value="'.h($retQuery).'"><button class="btn btn-ghost btn-sm" type="submit" data-busy="…" title="'.($typ==='rohstoff'?'Rohstoff':'Produkt').' aus dieser Zeile neu anlegen und zuordnen">'.$neuLabel.'</button></form>';
          ?>
          <?php if ($st==='vorschlag'): ?>
            <div style="display:flex;gap:6px;flex-wrap:wrap">
              <form method="post" style="margin:0"><input type="hidden" name="aktion" value="bestaetigen"><input type="hidden" name="id" value="<?= (int)$e['id'] ?>"><input type="hidden" name="ret" value="<?= h($retQuery) ?>"><button class="btn btn-primary btn-sm" type="submit" data-busy="…">Bestätigen</button></form>
              <form method="post" style="margin:0"><input type="hidden" name="aktion" value="verwerfen"><input type="hidden" name="id" value="<?= (int)$e['id'] ?>"><input type="hidden" name="ret" value="<?= h($retQuery) ?>"><button class="btn btn-ghost btn-sm" type="submit">Verwerfen</button></form>
              <?= $neuForm ?>
            </div>
          <?php elseif ($st!=='bestaetigt'): ?>
            <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center">
              <form method="post" style="margin:0;display:flex;gap:6px">
                <input type="hidden" name="aktion" value="manuell"><input type="hidden" name="id" value="<?= (int)$e['id'] ?>"><input type="hidden" name="ret" value="<?= h($retQuery) ?>">
                <input type="text" name="eingabe" placeholder="<?= $typ==='rohstoff'?'Rohstoff-Name/Art.-Nr.':'Produkt-Name/Nr.' ?>" style="min-width:180px" list="<?= $typ==='rohstoff'?'roh_dl':'prod_dl' ?>">
                <button class="btn btn-ghost btn-sm" type="submit" data-busy="…">zuordnen</button>
              </form>
              <span class="muted" style="font-size:12px">oder</span>
              <?= $neuForm ?>
            </div>
          <?php else: ?>
            <span class="muted" style="font-size:12px">fertig</span>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php if (count($rows) >= 800): ?><p class="muted" style="font-size:12px;margin-top:8px">Nur die ersten 800 – Suche/Status eingrenzen.</p><?php endif; ?>
<?php endif; ?>
</div>
<?php
// Datalists für die manuelle Zuordnung (Autovervollständigung). Bewusst begrenzt gehalten.
if ($rows) {
    if ($typ === 'rohstoff') {
        echo '<datalist id="roh_dl">';
        foreach (all("SELECT name FROM item WHERE kategorie='rohstoff' ORDER BY name LIMIT 1500") as $r) echo '<option value="' . h($r['name']) . '">';
        echo '</datalist>';
    } else {
        echo '<datalist id="prod_dl">';
        foreach (all("SELECT name FROM produkt ORDER BY name") as $r) echo '<option value="' . h($r['name']) . '">';
        echo '</datalist>';
    }
}
render_footer();

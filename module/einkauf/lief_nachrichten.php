<?php
// Lieferanten-Nachrichten (Team-Übersicht): alle Chat-Verläufe mit Lieferanten an einem Ort.
// Links die Verläufe (ungelesene zuerst), rechts der ausgewählte Verlauf mit Antwortfeld + Anhängen.
// Filter: Lieferant + Produkt. Volltextsuche über Nachrichtentext, Lieferant (Name/Nummer),
// Produkt/Rohstoff und Anfrage-/Bestellnummer (LIKE ESCAPE '='). Treffer werden hervorgehoben,
// der Thread-Link springt direkt zur gefundenen Nachricht. Route: ?p=lief_nachrichten
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/nachricht.php';

$u = function_exists('current_user') ? current_user() : null;
if (!$u) { header('Location: ?p=login'); exit; }

// --- Auswahl + Filter aus der URL ---
$selLief = (int)($_GET['lief'] ?? 0);
$selBt   = in_array(($_GET['bt'] ?? ''), ['bestellung', 'lieferant_anfrage'], true) ? (string)$_GET['bt'] : '';
$selBi   = (int)($_GET['bi'] ?? 0);
$suche   = trim((string)($_GET['q'] ?? ''));
$fLief   = (int)($_GET['flief'] ?? 0);
$fProd   = trim((string)($_GET['produkt'] ?? ''));   // Produkt-Filter (Name) – auch von der Produktseite aus aufrufbar

// URL-Baukasten (hält Filter/Suche; einzelne Werte per $ov überschreibbar, '' entfernt).
$mkUrl = function (array $ov = []) use ($selLief, $selBt, $selBi, $suche, $fLief, $fProd) {
    $p = ['lief' => $selLief ?: '', 'bt' => $selBt, 'bi' => $selBi ?: '', 'q' => $suche, 'flief' => $fLief ?: '', 'produkt' => $fProd];
    foreach ($ov as $k => $v) $p[$k] = $v;
    $qs = '?p=lief_nachrichten';
    foreach ($p as $k => $v) if ((string)$v !== '') $qs .= '&' . $k . '=' . urlencode((string)$v);
    return $qs;
};

// --- Antwort senden (Team) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'nachricht') {
    $f = '';
    if ($selLief > 0) $f = nachricht_post_verarbeiten($selLief, 'team', (string)($u['name'] ?? 'Team'), $selBt ?: null, $selBt ? $selBi : null);
    header('Location: ' . $mkUrl($f === '' ? ['ok' => 1] : ['fehler' => $f]) . '#chat');
    exit;
}

// --- Threads (gruppiert nach Lieferant + Vorgang) ---
$gruppen = all("SELECT lieferant_id, bezug_typ, bezug_id,
                   MAX(id) AS last_id, MAX(erstellt) AS last_zeit,
                   SUM(CASE WHEN akteur='lieferant' AND gelesen_team=0 THEN 1 ELSE 0 END) AS ungelesen,
                   COUNT(*) AS anzahl
                FROM nachricht
                GROUP BY lieferant_id, bezug_typ, bezug_id");

// Stammdaten vorladen (ein Zugriff statt je Thread).
$liefMap = [];
foreach (all("SELECT id, firma, lieferantennummer FROM lieferanten") as $l) $liefMap[(int)$l['id']] = $l;
$lastIds = array_values(array_filter(array_map(fn($g) => (int)$g['last_id'], $gruppen)));
$lastMap = [];
if ($lastIds) {
    $ph = implode(',', array_fill(0, count($lastIds), '?'));
    foreach (all("SELECT id, text, akteur FROM nachricht WHERE id IN ($ph)", $lastIds) as $m) $lastMap[(int)$m['id']] = $m;
}

// Text-Treffer je Thread (nur bei aktiver Suche): threadKey => erste Treffer-Nachricht-ID.
$textTreffer = [];
if ($suche !== '') {
    $like = '%' . str_replace(['=', '%', '_'], ['==', '=%', '=_'], $suche) . '%';
    foreach (all("SELECT id, lieferant_id, bezug_typ, bezug_id FROM nachricht WHERE text LIKE ? ESCAPE '=' ORDER BY id", [$like]) as $m) {
        $k = (int)$m['lieferant_id'] . '|' . (string)($m['bezug_typ'] ?? '') . '|' . (int)$m['bezug_id'];
        if (!isset($textTreffer[$k])) $textTreffer[$k] = (int)$m['id'];
    }
}

// Threads anreichern.
$rows = [];
foreach ($gruppen as $g) {
    $lid = (int)$g['lieferant_id'];
    $bt  = (string)($g['bezug_typ'] ?? '');
    $bi  = (int)$g['bezug_id'];
    $key = $lid . '|' . $bt . '|' . $bi;
    $l   = $liefMap[$lid] ?? ['firma' => 'Lieferant #' . $lid, 'lieferantennummer' => ''];
    $firma  = trim((string)($l['firma'] ?? ''));
    $nummer = trim((string)($l['lieferantennummer'] ?? ''));
    $produkt = nachricht_bezug_produkt($bt ?: null, $bt ? $bi : null);
    $bezugLbl = nachricht_bezug_label($bt ?: null, $bt ? $bi : null);
    $bezugNr  = '';
    if ($bt === 'lieferant_anfrage') $bezugNr = (string) scalar("SELECT nummer FROM lieferant_anfrage WHERE id=?", [$bi]);
    elseif ($bt === 'bestellung')    $bezugNr = (string) scalar("SELECT nummer FROM bestellung WHERE id=?", [$bi]);
    $last = $lastMap[(int)$g['last_id']] ?? ['text' => '', 'akteur' => 'team'];
    $rows[] = [
        'lid' => $lid, 'bt' => $bt, 'bi' => $bi, 'key' => $key,
        'firma' => $firma, 'nummer' => $nummer, 'produkt' => $produkt,
        'bezug_lbl' => $bezugLbl, 'bezug_nr' => $bezugNr,
        'ungelesen' => (int)$g['ungelesen'], 'anzahl' => (int)$g['anzahl'],
        'last_zeit' => (string)$g['last_zeit'], 'last_text' => (string)$last['text'], 'last_akteur' => (string)$last['akteur'],
        'treffer_id' => $textTreffer[$key] ?? 0,
    ];
}

// Dropdown-Optionen aus den vorhandenen Verläufen.
$liefOpt = []; $prodOpt = [];
foreach ($rows as $r) {
    if (!isset($liefOpt[$r['lid']])) $liefOpt[$r['lid']] = $r['firma'] . ($r['nummer'] !== '' ? ' (' . $r['nummer'] . ')' : '');
    if ($r['produkt'] !== '') $prodOpt[$r['produkt']] = true;
}
asort($liefOpt, SORT_NATURAL | SORT_FLAG_CASE);
$prodOpt = array_keys($prodOpt); sort($prodOpt, SORT_NATURAL | SORT_FLAG_CASE);

// Filtern.
$sl = mb_strtolower($suche); $pl = mb_strtolower($fProd);
$rows = array_values(array_filter($rows, function ($r) use ($fLief, $fProd, $pl, $suche, $sl) {
    if ($fLief > 0 && $r['lid'] !== $fLief) return false;
    if ($fProd !== '' && mb_strpos(mb_strtolower($r['produkt']), $pl) === false) return false;
    if ($suche === '') return true;
    if ($r['treffer_id'] > 0) return true;   // Text-Treffer
    $heu = mb_strtolower($r['firma'] . ' ' . $r['nummer'] . ' ' . $r['produkt'] . ' ' . $r['bezug_lbl'] . ' ' . $r['bezug_nr']);
    return mb_strpos($heu, $sl) !== false;    // Feld-Treffer
}));

// Sortierung: ungelesene zuerst, dann neueste Aktivität.
usort($rows, function ($a, $b) {
    $au = $a['ungelesen'] > 0 ? 1 : 0; $bu = $b['ungelesen'] > 0 ? 1 : 0;
    if ($au !== $bu) return $bu - $au;
    return strcmp($b['last_zeit'], $a['last_zeit']);
});

// Ausgewählten Thread als gelesen markieren (vor dem Header, damit die Zähler stimmen).
if ($selLief > 0) {
    nachrichten_thread_gelesen($selLief, $selBt ?: null, $selBt ? $selBi : null);
    foreach ($rows as &$r) { if ($r['lid'] === $selLief && $r['bt'] === $selBt && $r['bi'] === ($selBt ? $selBi : 0)) $r['ungelesen'] = 0; }
    unset($r);
}

$gesamtOffen = nachrichten_ungelesen_gesamt();

render_header('lief_nachrichten', 'Lieferanten-Nachrichten');
bx_head('Lieferanten-Nachrichten', $gesamtOffen > 0 ? $gesamtOffen . ' ungelesen' : 'Alles gelesen');

$hl = function (string $htmlText) use ($suche): string {
    if ($suche === '') return $htmlText;
    $needle = htmlspecialchars($suche, ENT_QUOTES, 'UTF-8');
    return preg_replace('/(' . preg_quote($needle, '/') . ')/iu', '<mark>$1</mark>', $htmlText) ?? $htmlText;
};
if (isset($_GET['ok']))     echo '<div class="bx-panel badge-ok" style="padding:12px 16px;margin-bottom:12px">Nachricht gesendet.</div>';
if (isset($_GET['fehler'])) echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:12px 16px;margin-bottom:12px">' . h((string)$_GET['fehler']) . '</div>';
?>
<form class="bx-listbar" method="get">
  <input type="hidden" name="p" value="lief_nachrichten">
  <input class="bx-search" type="text" name="q" value="<?= h($suche) ?>" placeholder="Suchen: Text, Lieferant, Nummer, Produkt, Anfrage-/Bestellnummer …">
  <select name="flief" onchange="this.form.submit()">
    <option value="">Alle Lieferanten</option>
    <?php foreach ($liefOpt as $lid => $lbl): ?><option value="<?= (int)$lid ?>" <?= $fLief === (int)$lid ? 'selected' : '' ?>><?= h($lbl) ?></option><?php endforeach; ?>
  </select>
  <select name="produkt" onchange="this.form.submit()">
    <option value="">Alle Produkte</option>
    <?php foreach ($prodOpt as $pn): ?><option value="<?= h($pn) ?>" <?= $fProd === $pn ? 'selected' : '' ?>><?= h($pn) ?></option><?php endforeach; ?>
  </select>
  <button class="btn btn-ghost btn-sm" type="submit">Suchen</button>
  <?php if ($suche !== '' || $fLief > 0 || $fProd !== ''): ?><a class="btn btn-ghost btn-sm" href="?p=lief_nachrichten">zurücksetzen</a><?php endif; ?>
</form>
<?php if ($suche !== ''): ?>
<div class="muted" style="margin:8px 0 4px;font-size:13px"><?= count($rows) ?> Verläufe mit Treffer für „<?= h($suche) ?>"</div>
<?php endif; ?>

<div class="bx-row" style="gap:16px;align-items:flex-start;flex-wrap:wrap">
  <div class="bx-panel" style="flex:1 1 320px;min-width:300px;max-width:440px">
    <?php if (!$rows): ?>
      <div class="muted"><?= $suche !== '' || $fLief > 0 || $fProd !== '' ? 'Kein Verlauf passt zur Suche.' : 'Noch keine Lieferanten-Nachrichten.' ?></div>
    <?php else: foreach ($rows as $r):
        $aktiv = $r['lid'] === $selLief && $r['bt'] === $selBt && $r['bi'] === ($selBt ? $selBi : 0);
        $anchor = $r['treffer_id'] > 0 ? '#m' . $r['treffer_id'] : '#chat';
        $url = $mkUrl(['lief' => $r['lid'], 'bt' => $r['bt'], 'bi' => $r['bi'] ?: '']) . $anchor;
        $titel = $r['firma'] . ($r['nummer'] !== '' ? ' (' . $r['nummer'] . ')' : '');
        $vorgang = $r['bezug_lbl'] !== '' ? $r['bezug_lbl'] : 'Allgemein';
        if ($r['produkt'] !== '') $vorgang .= ' · ' . $r['produkt'];
        $snippet = mb_substr(trim($r['last_text']), 0, 90); ?>
      <a href="<?= h($url) ?>" style="display:block;text-decoration:none;color:inherit;padding:10px 8px;border-bottom:1px solid var(--line);<?= $aktiv ? 'background:rgba(29,158,117,.08);border-left:3px solid var(--gruen,#1D9E75);padding-left:9px' : '' ?>">
        <div style="display:flex;gap:8px;align-items:center">
          <span style="font-weight:<?= $r['ungelesen'] > 0 ? '600' : '500' ?>;flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= $hl(h($titel)) ?></span>
          <?php if ($r['ungelesen'] > 0): ?><span class="bx-navbadge"><?= (int)$r['ungelesen'] ?></span><?php endif; ?>
        </div>
        <div class="muted" style="font-size:12px;margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= $hl(h($vorgang)) ?></div>
        <?php if ($snippet !== ''): ?><div class="muted" style="font-size:12px;margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= $r['last_akteur'] === 'lieferant' ? '' : 'bulkify: ' ?><?= $hl(h($snippet)) ?></div><?php endif; ?>
        <div class="muted" style="font-size:11px;margin-top:2px"><?= h(fmt_zeit($r['last_zeit'], 'd.m.Y H:i')) ?> Uhr</div>
      </a>
    <?php endforeach; endif; ?>
  </div>

  <div style="flex:2 1 460px;min-width:320px">
  <?php if ($selLief <= 0): ?>
    <div class="bx-panel muted">Bitte links einen Verlauf auswählen.</div>
  <?php else:
      $firma = (string)($liefMap[$selLief]['firma'] ?? ('Lieferant #' . $selLief));
      $verlauf = nachrichten_thread($selLief, $selBt ?: null, $selBt ? $selBi : null);
      $bezugLblSel = nachricht_bezug_label($selBt ?: null, $selBt ? $selBi : null);
      $dl = '?p=dokument&id=';
  ?>
    <div class="bx-panel" id="chat">
      <h2 style="margin-top:0"><?= h($firma) ?></h2>
      <p class="muted" style="margin-top:0">
        <?= $bezugLblSel !== '' ? h($bezugLblSel) : 'Allgemeines Gespräch' ?>
        <?php
          $detailUrl = $selBt === 'bestellung' ? '?p=bestellung&id=' . $selBi
                     : ($selBt === 'lieferant_anfrage' ? '?p=lief_anfrage_detail&id=' . $selBi : '?p=lieferant&id=' . $selLief);
        ?>
        · <a href="<?= h($detailUrl) ?>">zum Vorgang</a>
      </p>
      <?php if (!$verlauf): ?>
        <div class="muted" style="margin-bottom:12px">Noch keine Nachrichten in diesem Verlauf.</div>
      <?php else: ?>
        <div class="bx-chat" style="margin-bottom:14px">
          <?php foreach ($verlauf as $e): $eigen = $e['akteur'] === 'team';
                $who = $eigen ? 'bulkify' : $firma; if (trim((string)$e['autor']) !== '') $who .= ' · ' . $e['autor']; ?>
            <div class="bx-msg <?= $eigen ? 'team' : 'gegen' ?>" id="m<?= (int)$e['id'] ?>">
              <div class="who"><?= h($who) ?></div>
              <div class="bubble"><?= $hl(nl2br(h($e['text']))) ?>
                <?php foreach (nachricht_anhaenge((int)$e['id']) as $an): ?>
                  <div style="margin-top:6px"><a href="<?= $dl . (int)$an['id'] ?>" target="_blank" rel="noopener"><?= h($an['titel'] ?: ($an['datei_orig'] ?: ('Datei #' . (int)$an['id']))) ?></a></div>
                <?php endforeach; ?>
                <div class="meta"><?= h(fmt_zeit($e['erstellt'])) ?></div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
      <?php $maxMb = function_exists('lieferant_datei_max_mb') ? lieferant_datei_max_mb() : 15;
            $acc = function_exists('lieferant_datei_endungen') ? ('.' . implode(',.', lieferant_datei_endungen())) : ''; ?>
      <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="aktion" value="nachricht">
        <div class="bx-field" style="margin-bottom:10px"><label>Antwort an <?= h($firma) ?></label>
          <textarea name="text" rows="3" maxlength="4000" placeholder="Ihre Antwort …"></textarea></div>
        <div class="bx-field" style="margin-bottom:10px"><label>Anhang (optional) <span class="muted" style="font-weight:normal">(CoA, Spezifikation, Fotos …, max. <?= (int)$maxMb ?> MB)</span></label>
          <input type="file" name="anhang[]" multiple<?= $acc !== '' ? ' accept="' . h($acc) . '"' : '' ?>></div>
        <button class="btn btn-primary" type="submit">Senden</button>
      </form>
    </div>
  <?php endif; ?>
  </div>
</div>
<?php render_footer();

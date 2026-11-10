<?php
// Artikel zusammenführen (Einkauf gerade ziehen, Teil 5, 10.11.2026) – nur Admin.
// Gruppen ähnlicher Artikelnamen (Normalform mit Synonymen, core/artikel_dubletten.php) mit Vergleich: Spec/Gehalt, Bio,
// Kapselgröße/Füllgewicht/Material, Lieferanten, Bestand, Verwendung in Rezepturen/Bestellungen/Produkten.
// Nico wählt je Gruppe den Hauptartikel und hakt an, was zusammengeführt wird. Gesperrt (nicht anwählbar): andere Kapselgröße,
// Füllgewicht, Material, Einheit, Warenart, Gehalt, Bio/konventionell, Bulk/Premix verschiedener Rezepturen, Rezeptur mit beiden.
// Nichts wird gelöscht: der alte Artikel wird gesperrt (zusammengefuehrt_in) und sein Name bleibt als Synonym am Hauptartikel.
// Route: ?p=artikel_zusammenfuehren · JSON für „Meintest du …?": ?p=artikel_zusammenfuehren&treffer=<Name>&kat=<kategorie>
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';
require_once BX_ROOT . '/core/artikel_dubletten.php';

if (isset($_GET['treffer'])) {   // Dublettenwarnung beim Anlegen (für alle, die Artikel anlegen dürfen)
    header('Content-Type: application/json; charset=utf-8');
    $t = artikel_dubletten_treffer((string)$_GET['treffer'], (string)($_GET['kat'] ?? ''), 8);
    echo json_encode(['treffer' => array_map(fn($r) => ['id' => (int)$r['id'], 'name' => (string)$r['name'], 'kat' => kategorie_label((string)$r['kategorie']),
                                                        'einheit' => (string)$r['einheit'], 'kapsel' => (string)$r['kapsel']], $t)], JSON_UNESCAPED_UNICODE);
    exit;
}
if (!has_role('admin')) {
    render_header('artikel_zusammenfuehren', 'Artikel zusammenführen');
    bx_head('Kein Zugriff', 'Nur für Admins.', bx_btn('Zurück', '?p=dashboard', 'ghost'));
    render_footer(); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'zusammenfuehren') {
    $haupt = (int)($_POST['haupt'] ?? 0);
    $quellen = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['quelle'] ?? [])), fn($x) => $x > 0 && $x !== $haupt)));
    $wer = (string)(current_user()['name'] ?? '');
    $ok = []; $fehler = [];
    foreach ($quellen as $qid) {
        $r = artikel_zusammenfuehren_voll($qid, $haupt, $wer);
        $nm = (string) scalar("SELECT name FROM item WHERE id=?", [$qid]);
        if ($r['ok']) $ok[] = $nm . ' (' . implode(', ', array_map(fn($k, $v) => $k . ' ' . $v, array_keys($r['zahlen']), $r['zahlen'])) . ')';
        else $fehler[] = $nm . ': ' . $r['fehler'];
    }
    $hn = (string) scalar("SELECT name FROM item WHERE id=?", [$haupt]);
    header('Location: ?p=artikel_zusammenfuehren' . ($ok ? '&ok=' . rawurlencode(count($ok) . ' Artikel in „' . $hn . '“ zusammengeführt: ' . implode(' · ', $ok)) : '')
           . ($fehler ? '&err=' . rawurlencode(implode(' · ', $fehler)) : '') . '#g' . $haupt); exit;
}

$such = trim((string)($_GET['q'] ?? ''));
$kat  = (string)($_GET['kat'] ?? 'rohstoff');
$katListe = ['rohstoff' => 'Rohstoffe', 'fertig' => 'Bulkware', 'verkaufsfertig' => 'Fertigprodukte', 'verpackung' => 'Verpackung', 'alle' => 'Alle'];
if (!isset($katListe[$kat])) $kat = 'rohstoff';
$gruppen = [];
foreach (artikel_dubletten_gruppen() as $key => $ids) {
    $infos = array_values(array_filter(array_map('artikel_info', $ids)));
    if ($kat !== 'alle' && !in_array($kat, array_column($infos, 'kategorie'), true)) continue;
    if ($such !== '' && !array_filter($infos, fn($i) => mb_stripos((string)$i['name'], $such) !== false)) continue;
    // Vorschlag Hauptartikel: meiste Verwendung (Rezepturen + Bestellungen + Chargen), dann ältester.
    usort($infos, fn($a, $b) => [($b['n_rezepturen'] + $b['n_bestellungen'] + $b['n_chargen'] + $b['n_produkte']), -$b['id']] <=> [($a['n_rezepturen'] + $a['n_bestellungen'] + $a['n_chargen'] + $a['n_produkte']), -$a['id']]);
    $hWahl = (int)($_GET['haupt'] ?? 0);   // gewählter Hauptartikel nach vorn (Sperren gelten gegen ihn)
    foreach ($infos as $ix => $inf) if ((int)$inf['id'] === $hWahl) { array_unshift($infos, $inf); unset($infos[$ix + 1]); $infos = array_values($infos); break; }
    $gruppen[$key] = $infos;
}
ksort($gruppen);
$mz = fn($x) => rtrim(rtrim(number_format((float)$x, 3, ',', '.'), '0'), ',');

render_header('artikel_zusammenfuehren', 'Artikel zusammenführen');
bx_head('Artikel zusammenführen',
        count($gruppen) . ' Gruppe(n) ähnlicher Artikel. Gleiche Qualität von verschiedenen Lieferanten = ein Artikel mit mehreren Lieferantenpreisen; andere Spezifikation, Kapselgröße, Füllgewicht oder Kapselmaterial = eigener Artikel. Zusammengeführt wird nur auf Klick, nichts wird gelöscht.',
        bx_btn('Datenprüfung', '?p=datenpruefung', 'ghost'));
if (isset($_GET['ok']))  echo '<div class="bx-panel badge-ok" style="padding:12px 16px">' . h((string)$_GET['ok']) . '</div>';
if (isset($_GET['err'])) echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:12px 16px">' . h((string)$_GET['err']) . '</div>';
?>
<form method="get" class="bx-panel bx-row" style="gap:10px;align-items:center;flex-wrap:wrap;padding:12px 16px">
  <input type="hidden" name="p" value="artikel_zusammenfuehren">
  <?php foreach ($katListe as $k => $l): ?><a class="btn btn-sm <?= $kat === $k ? 'btn-primary' : 'btn-ghost' ?>" href="?p=artikel_zusammenfuehren&kat=<?= h($k) ?><?= $such !== '' ? '&q=' . rawurlencode($such) : '' ?>"><?= h($l) ?></a><?php endforeach; ?>
  <input type="hidden" name="kat" value="<?= h($kat) ?>">
  <input type="search" name="q" value="<?= h($such) ?>" placeholder="Gruppe suchen, z. B. Magnesium" style="min-width:240px;flex:1 1 240px">
  <button class="btn btn-ghost btn-sm" type="submit">Suchen</button>
</form>
<?php if (!$gruppen): ?><div class="bx-panel"><div class="muted">Keine Gruppen ähnlicher Artikel gefunden.</div></div><?php endif; ?>
<?php foreach ($gruppen as $key => $infos): $h = $infos[0]; ?>
<form method="post" class="bx-panel" id="g<?= (int)$h['id'] ?>" onsubmit="var n=this.querySelectorAll('input[name=&quot;quelle[]&quot;]:checked').length; if(!n){alert('Bitte mindestens einen Artikel zum Zusammenführen anhaken.');return false;} var hn=this.querySelector('input[name=haupt]:checked'); return confirm(n+' Artikel in den Hauptartikel „'+(hn?hn.getAttribute('data-name'):'')+'“ zusammenführen? Chargen, Bestellpositionen, Rezepturzeilen, Lieferantenpreise und Dokumente ziehen um. Die alten Artikel werden gesperrt (nicht gelöscht), ihre Namen bleiben als Synonym.');">
  <input type="hidden" name="aktion" value="zusammenfuehren">
  <div class="bx-row" style="justify-content:space-between;align-items:baseline;flex-wrap:wrap;gap:8px">
    <h2 style="margin:0;font-size:16px"><?= h((string)$h['name']) ?> <span class="muted" style="font-size:13px;font-weight:400">· <?= count($infos) ?> ähnliche Artikel</span></h2>
  </div>
  <div class="bx-tablewrap" style="margin-top:8px"><table class="bx-table" style="font-size:13px">
    <thead><tr><th>Haupt</th><th>Zusammenführen</th><th>Artikel</th><th>Warenart</th><th>Gehalt</th><th>Bio</th><th>Kapselgröße / Füllgewicht</th><th>Material</th><th>Lieferanten</th><th class="bx-num">Bestand</th><th class="bx-num">Rezepturen</th><th class="bx-num">Bestellungen</th><th class="bx-num">Produkte</th></tr></thead>
    <tbody>
    <?php foreach ($infos as $i => $it): $sp = $i === 0 ? [] : artikel_merge_sperren($h, $it); ?>
      <tr data-id="<?= (int)$it['id'] ?>">
        <td><input type="radio" name="haupt" value="<?= (int)$it['id'] ?>" data-name="<?= h((string)$it['name']) ?>" <?= $i === 0 ? 'checked' : '' ?> onchange="bxDubHaupt(this)"></td>
        <td class="dub-q"><?php if ($i > 0 && !$sp): ?><input type="checkbox" name="quelle[]" value="<?= (int)$it['id'] ?>"><?php elseif ($i > 0): ?><span style="color:#8f231b;font-size:12px" title="<?= h(implode(', ', $sp)) ?>">gesperrt: <?= h(implode(', ', $sp)) ?></span><?php else: ?><span class="muted" style="font-size:12px">Hauptartikel</span><?php endif; ?></td>
        <td style="white-space:normal;min-width:200px"><a href="?p=rohstoff&id=<?= (int)$it['id'] ?>" target="_blank" rel="noopener"><?= h((string)$it['name']) ?></a><?= $it['artikelnummer'] ? ' <span class="muted" style="font-size:11px">' . h((string)$it['artikelnummer']) . '</span>' : '' ?></td>
        <td><?= h(kategorie_label((string)$it['kategorie'])) ?> <span class="muted" style="font-size:11px"><?= h((string)$it['einheit']) ?></span></td>
        <td><?= $it['gehalt'] !== '' ? h((string)$it['gehalt']) : '<span class="muted">–</span>' ?></td>
        <td><?= $it['bio'] ? 'Bio' : '<span class="muted">–</span>' ?></td>
        <td><?= $it['kapsel'] !== '' ? '<span style="font-weight:600">' . h((string)$it['kapsel']) . '</span>' : '<span class="muted">–</span>' ?></td>
        <td><?= trim((string)$it['material']) !== '' ? h((string)$it['material']) : '<span class="muted">–</span>' ?></td>
        <td style="white-space:normal;min-width:200px"><?= $it['lieferanten'] ? h(implode(', ', $it['lieferanten'])) : '<span class="muted">–</span>' ?></td>
        <td class="bx-num"><?= $mz($it['bestand']) ?> <?= h((string)$it['einheit']) ?></td>
        <td class="bx-num"><?= (int)$it['n_rezepturen'] ?></td>
        <td class="bx-num"><?= (int)$it['n_bestellungen'] ?></td>
        <td class="bx-num"><?= (int)$it['n_produkte'] ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <div class="bx-row" style="gap:10px;align-items:center;margin-top:10px;flex-wrap:wrap">
    <button class="btn btn-primary btn-sm" type="submit">Angehakte in den Hauptartikel zusammenführen</button>
    <span class="muted" style="font-size:12px">Anderen Hauptartikel wählen: Radioknopf links – die Sperren werden dann neu geprüft (Seite lädt neu).</span>
  </div>
</form>
<?php endforeach; ?>
<script>
// Hauptartikel wechseln: Sperren hängen vom Hauptartikel ab -> mit ?haupt=… neu laden (Server prüft).
function bxDubHaupt(r){ var u=new URL(location.href); u.searchParams.set('haupt', r.value); u.hash='g'+r.value; location.href=u.toString(); }
</script>
<?php render_footer(); ?>

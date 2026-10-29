<?php
// Doppelte Rezepturen (Teil 3) – reiner READ-ONLY-Report: inhaltlich identische Rezepturen (gleiche Zutaten mit
// mg je Einheit, gleiche Kapselgröße, Füllmenge und Darreichungsform). Nichts wird automatisch zusammengelegt –
// die Liste dient Nico zur Entscheidung. Zusammenlegen geschieht je Rezeptur über das Panel „Interne
// Stammrezeptur" (rezeptur/detail.php): dort zeigt eine Rezeptur auf eine gemeinsame Stammrezeptur und teilt
// damit EINEN Bulk-Artikel (rezeptur_bulkitem löst über stamm_rezeptur_id auf).
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

// Alle Rezepturen mit Kunde + Stamm-Verknüpfung. „Vorschlag" (noch nicht angenommen) bleibt mit drin,
// wird aber als solcher markiert, damit man es sieht.
$rez = all("SELECT r.id, r.nummer, r.name, r.status, r.kunde_id, r.stamm_rezeptur_id,
                   r.darreichungsform, r.kapselgroesse_id, r.einheit_fuellmenge,
                   k.firma AS kunde_firma
            FROM rezeptur r LEFT JOIN kunden k ON k.id=r.kunde_id
            ORDER BY r.id");

// Nach Fingerabdruck gruppieren (leere Rezepturen liefern '' und fallen raus).
$gruppen = [];
foreach ($rez as $r) {
    $fp = rezeptur_fingerprint((int)$r['id']);
    if ($fp === '') continue;
    $gruppen[$fp][] = $r;
}
// Nur echte Dubletten (>= 2 Rezepturen). Nach Gruppengröße absteigend.
$dubletten = array_filter($gruppen, fn($g) => count($g) >= 2);
uasort($dubletten, fn($a, $b) => count($b) <=> count($a));

// Je Gruppe: schon vollständig über EINE Stammrezeptur verknüpft? (dann nur zur Info, kein Handlungsbedarf)
$stammVon = function(array $g): int {
    // „Anker" = die Stammrezeptur, auf die alle zeigen – oder eine Rezeptur der Gruppe, die selbst Stamm ist.
    $ids = array_map(fn($x) => (int)$x['id'], $g);
    $stamms = [];
    foreach ($g as $x) {
        $s = (int)($x['stamm_rezeptur_id'] ?? 0);
        $stamms[] = $s > 0 ? $s : (int)$x['id'];
    }
    $stamms = array_values(array_unique($stamms));
    return count($stamms) === 1 ? $stamms[0] : 0;   // 0 = noch nicht (vollständig) verknüpft
};

$dformLbl = ['kapsel'=>'Kapsel','tablette'=>'Tablette','softgel'=>'Softgel','stick'=>'Stick','pulver'=>'Pulver','fluessig'=>'Flüssig','gel'=>'Gel'];

render_header('rezeptur_dubletten', 'Doppelte Rezepturen');
bx_head('Doppelte Rezepturen', count($dubletten) . ' Gruppe(n) inhaltlich identischer Rezepturen',
    bx_btn('Zu den Rezepturen', '?p=rezeptur', 'ghost'));
?>
<div class="bx-panel">
  <p class="muted" style="margin-top:0">Hier stehen Rezepturen, die <strong>inhaltlich identisch</strong> sind: gleiche Zutaten mit mg je Einheit, gleiche Kapselgröße, Füllmenge und Darreichungsform. <strong>Es wird nichts automatisch zusammengelegt.</strong> Zum Zusammenlegen eine Rezeptur öffnen und im Panel <em>„Interne Stammrezeptur"</em> auf eine gemeinsame Stammrezeptur zeigen lassen – dann teilen alle <strong>einen</strong> Bulk-Bestand. Der jeweilige Kundenname bleibt an der Kundenrezeptur/am Produkt erhalten.</p>
</div>

<?php if (!$dubletten): ?>
  <div class="bx-panel"><div class="muted">Keine inhaltlich identischen Rezepturen gefunden.</div></div>
<?php else: ?>
  <?php $gi = 0; foreach ($dubletten as $fp => $g): $gi++; $stamm = $stammVon($g); $verknuepft = $stamm > 0; ?>
  <div class="bx-panel">
    <div class="bx-row" style="justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
      <h2 style="margin:0">Gruppe <?= $gi ?> <span class="muted" style="font-weight:400;font-size:13px">(<?= count($g) ?> Rezepturen · <?= h($dformLbl[$g[0]['darreichungsform']] ?? (string)$g[0]['darreichungsform']) ?>)</span></h2>
      <?php if ($verknuepft): ?><span class="badge badge-ok">bereits über eine Stammrezeptur verknüpft</span>
      <?php else: ?><span class="badge badge-warn">noch nicht verknüpft</span><?php endif; ?>
    </div>
    <div class="bx-tablewrap" style="margin-top:10px"><table class="bx-table">
      <thead><tr><th>Rezeptur</th><th>Kunde</th><th>Status</th><th>Stammrezeptur</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($g as $r): $istStamm = (int)($r['stamm_rezeptur_id'] ?? 0) === 0;
            $stammId = (int)($r['stamm_rezeptur_id'] ?? 0); ?>
        <tr>
          <td><a href="?p=rezeptur_detail&id=<?= (int)$r['id'] ?>"><?= h(trim(((string)$r['nummer'] !== '' ? $r['nummer'] . ' · ' : '') . $r['name'])) ?></a></td>
          <td><?= $r['kunde_firma'] ? h((string)$r['kunde_firma']) : '<span class="muted">– (kundenübergreifend)</span>' ?></td>
          <td><?= h((string)$r['status']) ?></td>
          <td><?= $stammId > 0 ? '→ <a href="?p=rezeptur_detail&id=' . $stammId . '">#' . $stammId . '</a>' : '<span class="muted">eigenständig</span>' ?></td>
          <td class="bx-num"><a class="btn btn-ghost btn-sm" href="?p=rezeptur_detail&id=<?= (int)$r['id'] ?>#stamm">Stammrezeptur setzen</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
  <?php endforeach; ?>
<?php endif; ?>
<?php render_footer();

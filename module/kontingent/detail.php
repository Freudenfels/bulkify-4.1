<?php
// Kontingent-/Jahresvertrag-Detail: zeigt die Zusammensetzung (Produkt + Gebinde + Etikett), die
// Rechnungs-Vorschau (wie ein Abruf aufgeschlüsselt wird) und die bisherigen Abrufe. Bearbeiten der
// Zusammensetzung läuft über das Produkt (dort liegen Gebinde-/Etikett-Slots).
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

$id = (int)($_GET['id'] ?? 0);
$k  = $id ? one("SELECT * FROM kontingent WHERE id=?", [$id]) : null;
if (!$k) { render_header('kontingente', 'Jahresvertrag'); bx_head('Kontingent nicht gefunden', '', bx_btn('Zurück', '?p=kontingente', 'ghost')); render_footer(); exit; }

$kunde   = one("SELECT id, firma FROM kunden WHERE id=?", [(int)$k['kunde_id']]);
$pid     = (int)$k['produkt_id'];
$prod    = $pid ? one("SELECT * FROM produkt WHERE id=?", [$pid]) : null;
$angId   = (int)($k['angebot_id'] ?? 0);
$ang     = $angId ? one("SELECT id, nummer, status FROM angebot WHERE id=?", [$angId]) : null;
$rest    = (int)$k['gesamt_menge'] - (int)$k['abgerufen'];
$ustP    = produkt_ust_satz($pid, (int)$k['kunde_id']);

$itemName = fn(?int $iid) => $iid ? (string) scalar("SELECT name FROM item WHERE id=?", [$iid]) : '';
$eurP = function ($x) { $x = (float)$x; $dec = (abs($x * 100 - round($x * 100)) < 1e-6) ? 2 : 4; return number_format($x, $dec, ',', '.') . ' €'; };
$statusBadge = fn($s) => match ($s) {
    'aktiv' => bx_badge('aktiv', 'ok'), 'wartet_vertrag' => bx_badge('wartet auf Vertrag', 'info'),
    'wartet_freigabe' => bx_badge('Vertrag prüfen', 'warn'), 'beendet' => bx_badge('beendet', 'info'),
    default => bx_badge((string)$s),
};

// Rezeptur + Darreichungsform des Produkts
$rez = ($prod && !empty($prod['rezeptur_id'])) ? one("SELECT id, nummer, name, darreichungsform FROM rezeptur WHERE id=?", [(int)$prod['rezeptur_id']]) : null;

render_header('kontingente', 'Jahresvertrag');
bx_head('Jahresvertrag', ($kunde['firma'] ?? '–') . ' · ' . (($prod['kundenname'] ?? '') ?: ($prod['name'] ?? '–')),
    bx_btn('Zurück zur Liste', '?p=kontingente', 'ghost'));
?>
<div class="bx-cards">
  <div class="bx-card"><div class="k">Kunde</div><div class="v" style="font-size:16px"><?= kunde_link($k['kunde_id'] ?? null, $kunde['firma'] ?? '–') ?></div></div>
  <div class="bx-card"><div class="k">Produkt</div><div class="v" style="font-size:16px"><?php if ($pid): ?><a href="?p=produkt&id=<?= $pid ?>"><?= h(($prod['kundenname'] ?? '') ?: ($prod['name'] ?? '–')) ?></a><?php else: ?>–<?php endif; ?></div></div>
  <div class="bx-card"><div class="k">Status</div><div class="v"><?= $statusBadge($k['status']) ?></div></div>
  <div class="bx-card"><div class="k">VK je Packung</div><div class="v" style="font-size:16px"><?= $eurP($k['vk_stueck']) ?></div></div>
  <div class="bx-card"><div class="k">vereinbart</div><div class="v" style="font-size:16px"><?= number_format((int)$k['gesamt_menge'], 0, ',', '.') ?> Pkg.</div></div>
  <div class="bx-card"><div class="k">abgerufen</div><div class="v" style="font-size:16px"><?= number_format((int)$k['abgerufen'], 0, ',', '.') ?> Pkg.</div></div>
  <div class="bx-card" style="border-color:var(--gruen)"><div class="k">Rest</div><div class="v" style="font-size:16px"><strong><?= number_format($rest, 0, ',', '.') ?> Pkg.</strong></div></div>
  <div class="bx-card"><div class="k">Laufzeit</div><div class="v" style="font-size:15px"><?= $k['gueltig_von'] ? h(date('d.m.Y', strtotime((string)$k['gueltig_von']))) : '–' ?> – <?= $k['gueltig_bis'] ? h(date('d.m.Y', strtotime((string)$k['gueltig_bis']))) : '–' ?></div></div>
</div>

<div class="bx-panel">
  <div class="bx-row" style="justify-content:space-between;align-items:center">
    <h2 style="margin:0">Zusammensetzung</h2>
    <?php if ($pid): ?><a class="btn btn-ghost btn-sm" href="?p=produkt&id=<?= $pid ?>">Produkt bearbeiten</a><?php endif; ?>
  </div>
  <p class="muted" style="margin:4px 0 10px">Was zum vereinbarten Festpreis je Packung geliefert wird. Gebinde, Verschluss und Etikett werden am <strong>Produkt</strong> gepflegt – dort ergänzt du z. B. Glas oder Etikett.</p>
  <?php if (!$prod): ?>
    <div class="muted">Kein Produkt verknüpft.</div>
  <?php else:
      $zeilen = [
          ['Rezeptur', $rez ? h(($rez['nummer'] ?? '') . ' · ' . ($rez['name'] ?? '')) . ' <a href="?p=rezeptur_detail&id=' . (int)$rez['id'] . '" class="muted" style="font-size:12px">ansehen</a>' : '<span class="muted">–</span>'],
          ['Darreichungsform', $rez && $rez['darreichungsform'] ? h(ucfirst((string)$rez['darreichungsform'])) : '<span class="muted">–</span>'],
          ['Stück je Packung', (int)($prod['einheiten_pro_packung'] ?? 0) > 0 ? (int)$prod['einheiten_pro_packung'] . ' Stück' : '<span class="muted">–</span>'],
          ['Verpackung (Gebinde)', $itemName($prod['verpackung_id'] ?? null) !== '' ? h($itemName((int)$prod['verpackung_id'])) : '<span class="muted">– nicht hinterlegt</span>'],
          ['Verschluss', $itemName($prod['verschluss_id'] ?? null) !== '' ? h($itemName((int)$prod['verschluss_id'])) : '<span class="muted">–</span>'],
          ['Etikett', $itemName($prod['etikett_id'] ?? null) !== '' ? h($itemName((int)$prod['etikett_id'])) : '<span class="muted">– nicht hinterlegt</span>'],
          ['Umkarton', $itemName($prod['karton_id'] ?? null) !== '' ? h($itemName((int)$prod['karton_id'])) : '<span class="muted">–</span>'],
      ];
  ?>
  <div class="bx-tablewrap"><table class="bx-table"><tbody>
    <?php foreach ($zeilen as $z): ?><tr><td style="width:220px"><?= $z[0] ?></td><td><?= $z[1] ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
  <?php endif; ?>
</div>

<?php
// Rechnungs-Vorschau: so schlüsselt ein Abruf die Rechnung auf (je Packung). Dieselbe Logik wie beim echten Abruf.
$prev = kontingent_abruf_positionen($k, 1, $ustP);   // menge=1 -> Preise je Packung
?>
<div class="bx-panel">
  <h2 style="margin-top:0">Rechnungs-Vorschau (je Packung)</h2>
  <?php if ($prev): $summe = 0; ?>
  <p class="muted" style="margin-top:0">So erscheinen die Positionen auf jeder Abruf-Rechnung – aufgeschlüsselt auf den Festpreis je Packung.</p>
  <div class="bx-tablewrap"><table class="bx-table">
    <thead><tr><th>Artikel-Nr.</th><th>Bezeichnung</th><th class="bx-num">Preis je Packung</th></tr></thead>
    <tbody>
    <?php foreach ($prev as $p): $summe += (int)$p['preis_cent']; ?>
      <tr><td><?= h((string)($p['artikelnr'] ?? '')) ?></td>
          <td><?= h((string)$p['bezeichnung']) ?><?php if (!empty($p['beschreibung'])): ?><div class="muted" style="font-size:12px;white-space:pre-line"><?= h((string)$p['beschreibung']) ?></div><?php endif; ?></td>
          <td class="bx-num"><?= $eurP($p['preis_cent'] / 100) ?></td></tr>
    <?php endforeach; ?>
      <tr><td></td><td style="text-align:right"><strong>Summe je Packung</strong></td><td class="bx-num"><strong><?= $eurP($summe / 100) ?></strong></td></tr>
    </tbody>
  </table></div>
  <?php else: ?>
  <p class="muted" style="margin-top:0">Für diesen Vertrag gibt es keine aufgeschlüsselten Einzelpreise (kein Angebot mit Positionen hinterlegt oder Option nicht eindeutig). Die Abruf-Rechnung zeigt dann <strong>eine Packungszeile</strong> zum Festpreis <strong><?= $eurP($k['vk_stueck']) ?></strong> (inkl. Gebinde &amp; Etikett). Für eine Aufschlüsselung muss das verknüpfte Angebot die Einzelpositionen enthalten.</p>
  <?php endif; ?>
  <?php if ($ang): ?><div style="margin-top:8px"><a class="btn btn-ghost btn-sm" href="?p=angebot&id=<?= (int)$ang['id'] ?>">Quell-Angebot <?= h((string)$ang['nummer']) ?> ansehen</a></div><?php endif; ?>
</div>

<?php
// Abrufe: Aufträge, die aus diesem Kontingent entstanden sind, mit Rechnung.
$abrufe = all("SELECT a.id, a.nummer, a.menge, a.gesamt_netto, a.status, a.angelegt,
                      (SELECT b.id FROM beleg b WHERE b.auftrag_id=a.id AND b.typ='rechnung' ORDER BY b.id DESC LIMIT 1) AS rechnung_id,
                      (SELECT b.nummer FROM beleg b WHERE b.auftrag_id=a.id AND b.typ='rechnung' ORDER BY b.id DESC LIMIT 1) AS rechnung_nr
               FROM auftrag a WHERE a.kontingent_id=? ORDER BY a.id DESC", [$id]);
?>
<div class="bx-panel">
  <h2 style="margin-top:0">Abrufe (<?= count($abrufe) ?>)</h2>
  <?php if (!$abrufe): ?><div class="muted">Noch keine Abrufe.</div>
  <?php else: ?>
  <div class="bx-tablewrap"><table class="bx-table">
    <thead><tr><th>Auftrag</th><th class="bx-num">Menge</th><th class="bx-num">Netto</th><th>Status</th><th>Rechnung</th><th>Datum</th></tr></thead>
    <tbody>
    <?php foreach ($abrufe as $a): ?>
      <tr>
        <td><a href="?p=auftrag&id=<?= (int)$a['id'] ?>"><?= h((string)$a['nummer']) ?></a></td>
        <td class="bx-num"><?= number_format((int)$a['menge'], 0, ',', '.') ?></td>
        <td class="bx-num"><?= $eurP($a['gesamt_netto']) ?></td>
        <td><?= h(status_text((string)$a['status'])) ?></td>
        <td><?= $a['rechnung_nr'] ? h((string)$a['rechnung_nr']) : '<span class="muted">–</span>' ?></td>
        <td><?= $a['angelegt'] ? h(fmt_zeit($a['angelegt'])) : '' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>

<?php if (!empty($k['notiz'])): ?>
<div class="bx-panel"><h2 style="margin-top:0">Notiz</h2><p style="margin:0;white-space:pre-line"><?= h((string)$k['notiz']) ?></p></div>
<?php endif; ?>
<?php render_footer();

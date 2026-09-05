<?php
// Werk-Cockpit – Startseite für Produktionsmitarbeiter. Nur Produktion/Warenwirtschaft/Entwicklung, kein Verkauf.
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

$u = function_exists('current_user') ? current_user() : null;
$uid = $u ? (int)$u['id'] : 0;

// Aufgabe direkt vom Cockpit abhaken
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'aufgabe_erledigt') {
    aufgabe_erledigen((int)$_POST['id'], $uid ?: null);
    header('Location: ?p=werk'); exit;
}

$meineAufgaben = $uid ? aufgaben_fuer_benutzer($uid) : [];

// Kennzahlen
$paOffen   = (int) scalar("SELECT COUNT(*) FROM produktionsauftrag WHERE status IN ('offen','laufend')");
$paLaufend = (int) scalar("SELECT COUNT(*) FROM produktionsauftrag WHERE status='laufend'");
$quar      = (int) scalar("SELECT COUNT(*) FROM charge WHERE status='quarantaene'");
$rezOffen  = (int) scalar("SELECT COUNT(*) FROM rezeptur_anfrage WHERE status NOT IN ('beantwortet','abgelehnt')");
// Produktionsbereit: Material komplett da
$paBereitAnz = 0;
foreach (all("SELECT id FROM produktionsauftrag WHERE status IN ('offen','laufend')") as $x)
    if (produktion_bereitschaft((int)$x['id'])['status'] === 'bereit') $paBereitAnz++;

// Als Nächstes zu produzieren (offene/laufende PA, nächste Station)
$paListe = all("SELECT pa.*, k.firma AS kunde_firma, p.name AS produkt_name,
                (SELECT COUNT(*) FROM produktion_schritt s WHERE s.pa_id=pa.id) AS n_total,
                (SELECT COUNT(*) FROM produktion_schritt s WHERE s.pa_id=pa.id AND s.erledigt=1) AS n_done,
                (SELECT station FROM produktion_schritt s WHERE s.pa_id=pa.id AND s.erledigt=0 ORDER BY s.sort LIMIT 1) AS naechste_station
                FROM produktionsauftrag pa
                LEFT JOIN kunden k ON k.id=pa.kunde_id LEFT JOIN produkt p ON p.id=pa.produkt_id
                WHERE pa.status IN ('offen','laufend')
                ORDER BY pa.prio ASC, (pa.status='laufend') DESC, pa.angelegt ASC LIMIT 12");

// Letzte Wareneingänge
$wareneingaenge = all("SELECT c.*, i.name AS item_name FROM charge c
                       LEFT JOIN item i ON i.id=c.item_id
                       ORDER BY c.angelegt DESC LIMIT 8");

// Baustein 2: heute eingeplante Produktionen
$heutePlan = all("SELECT pa.*, p.name AS produkt_name, k.firma AS kunde
                  FROM produktionsauftrag pa LEFT JOIN produkt p ON p.id=pa.produkt_id LEFT JOIN kunden k ON k.id=pa.kunde_id
                  WHERE pa.geplant_am = ? AND pa.status <> 'erledigt' ORDER BY pa.prio, pa.angelegt", [date('Y-m-d')]);

// Baustein 4: Wareneingänge, die gezielt für einen Auftrag gekommen sind
$wareFuerAuftrag = all("SELECT c.*, i.name AS item_name, i.kategorie AS kategorie, i.form AS form,
                               a.nummer AS auftrag_nr, ak.firma AS kunde
                        FROM charge c LEFT JOIN item i ON i.id=c.item_id
                        JOIN auftrag a ON a.id=c.auftrag_id LEFT JOIN kunden ak ON ak.id=a.kunde_id
                        WHERE a.status <> 'versendet'
                        ORDER BY c.angelegt DESC LIMIT 6");

$paBadge = fn($s) => match ($s) {
    'laufend'  => bx_badge('läuft','warn'),
    'offen'    => bx_badge('offen','info'),
    'erledigt' => bx_badge('fertig','ok'),
    default    => bx_badge($s),
};
$chBadge = fn($s) => match ($s) {
    'frei'        => bx_badge('frei','ok'),
    'quarantaene' => bx_badge('Quarantäne','warn'),
    'leer'        => bx_badge('leer'),
    default       => bx_badge($s),
};

render_header('werk', 'Cockpit');
bx_head('Werk-Cockpit', $u ? ('Hallo ' . h($u['name']) . ' – dein Überblick für Produktion & Warenwirtschaft.') : 'Produktion & Warenwirtschaft');

echo '<div class="bx-cards">';
echo '<a class="bx-card" href="?p=aufgaben" style="text-decoration:none;color:inherit"><div class="k">Meine offenen Aufgaben</div><div class="v">' . (count($meineAufgaben) > 0 ? '<strong>' . count($meineAufgaben) . '</strong>' : '0') . '</div></a>';
echo '<a class="bx-card" href="?p=produktion&bereit=1" style="text-decoration:none;color:inherit"><div class="k">Produktionsbereit</div><div class="v">' . ($paBereitAnz > 0 ? '<strong>' . $paBereitAnz . '</strong>' : '0') . '</div></a>';
echo '<a class="bx-card" href="?p=produktion" style="text-decoration:none;color:inherit"><div class="k">Offene Produktionsaufträge</div><div class="v">' . $paOffen . '</div></a>';
echo '<a class="bx-card" href="?p=wareneingang" style="text-decoration:none;color:inherit"><div class="k">Chargen in Quarantäne</div><div class="v">' . ($quar > 0 ? '<strong>' . $quar . '</strong>' : '0') . '</div></a>';
echo '</div>';
?>

<?php if ($meineAufgaben): ?>
<div class="bx-panel">
  <div class="bx-row" style="justify-content:space-between;align-items:baseline">
    <h2 style="margin:0">Meine Aufgaben</h2>
    <a class="btn btn-ghost btn-sm" href="?p=aufgaben">Alle Aufgaben</a>
  </div>
  <div class="bx-tablewrap" style="margin-top:12px"><table class="bx-table">
    <thead><tr><th>Prio</th><th>Aufgabe</th><th>Zugewiesen</th><th>Fällig</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($meineAufgaben as $a): $ueberf = $a['faellig'] && $a['faellig'] < gmdate('Y-m-d'); ?>
        <tr>
          <td><?= prio_badge((int)$a['prio']) ?></td>
          <td><strong><?= h($a['titel']) ?></strong><?php if ($a['beschreibung']): ?><div class="muted" style="font-size:12px;white-space:pre-line"><?= h($a['beschreibung']) ?></div><?php endif; ?></td>
          <td><?= $a['zugewiesen_an'] ? h($a['zuw_name']) : bx_badge('Team','info') ?></td>
          <td><?= $a['faellig'] ? '<span'.($ueberf?' class="bx-err"':'').'>'.h(date('d.m.Y', strtotime($a['faellig']))).'</span>' : '<span class="muted">–</span>' ?></td>
          <td class="bx-num"><form method="post" style="margin:0"><input type="hidden" name="aktion" value="aufgabe_erledigt"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>"><button class="btn btn-primary btn-sm" type="submit">Erledigt</button></form></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php endif; ?>

<?php if ($heutePlan): ?>
<div class="bx-panel">
  <div class="bx-row" style="justify-content:space-between;align-items:baseline">
    <h2 style="margin:0">Heute eingeplant</h2>
    <a class="btn btn-ghost btn-sm" href="?p=kalender">Kalender</a>
  </div>
  <div class="bx-tablewrap" style="margin-top:12px"><table class="bx-table">
    <thead><tr><th>Prio</th><th>Bereit</th><th>Auftrag</th><th>Produkt</th><th>Kunde</th></tr></thead>
    <tbody>
      <?php foreach ($heutePlan as $r): $ber = produktion_bereitschaft((int)$r['id']); ?>
        <tr onclick="location.href='?p=produktionsauftrag&id=<?= (int)$r['id'] ?>'" style="cursor:pointer">
          <td><?= prio_badge((int)($r['prio'] ?? 2)) ?></td>
          <td><?= bereitschaft_badge($ber['status']) ?></td>
          <td><?= h($r['nummer'] ?: ('#'.$r['id'])) ?></td>
          <td><?= h($r['produkt_name'] ?: '–') ?></td>
          <td><?= $r['kunde'] ? h($r['kunde']) : '<span class="muted">–</span>' ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php endif; ?>

<?php if ($wareFuerAuftrag):
    $katLbl = ['rohstoff'=>'Rohstoff','verpackung'=>'Verpackung','fertig'=>'Fertigware (Bulk)','verkaufsfertig'=>'Fertigware']; ?>
<div class="bx-panel" style="border-color:var(--gruen)">
  <h2 style="margin-top:0">Neu eingetroffen – für deine Aufträge</h2>
  <div class="bx-tablewrap"><table class="bx-table">
    <thead><tr><th>Charge</th><th>Artikel</th><th>Art</th><th class="bx-num">Menge</th><th>Für Auftrag</th><th>Status</th></tr></thead>
    <tbody>
      <?php foreach ($wareFuerAuftrag as $c): ?>
        <tr onclick="location.href='?p=chargen&id=<?= (int)$c['id'] ?>'" style="cursor:pointer">
          <td><?= h($c['charge_nr'] ?: ('#'.$c['id'])) ?></td>
          <td><?= h($c['item_name'] ?: '–') ?></td>
          <td><?= h($katLbl[$c['kategorie']] ?? $c['kategorie']) ?><?= $c['form'] ? ' · ' . h($c['form']) : '' ?></td>
          <td class="bx-num"><?= rtrim(rtrim(number_format((float)$c['menge_verfuegbar'],3,',','.'),'0'),',') ?> <?= h($c['einheit']) ?></td>
          <td><?= h($c['auftrag_nr']) ?><?= $c['kunde'] ? ' · ' . h($c['kunde']) : '' ?></td>
          <td><?= $chBadge($c['status']) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(420px,1fr));gap:16px;align-items:start">
  <div class="bx-panel" style="margin:0">
    <div class="bx-row" style="justify-content:space-between;align-items:baseline">
      <h2 style="margin:0">Als Nächstes zu produzieren</h2>
      <a class="btn btn-ghost btn-sm" href="?p=produktion">Alle</a>
    </div>
    <div class="bx-tablewrap" style="margin-top:12px"><table class="bx-table">
      <thead><tr><th>Prio</th><th>Bereit</th><th>Auftrag</th><th>Kunde</th><th>Produkt</th><th class="bx-num">Menge</th><th>Nächster Schritt</th></tr></thead>
      <tbody>
        <?php if (!$paListe): ?><tr><td colspan="7" class="muted">Keine offenen Produktionsaufträge.</td></tr><?php endif; ?>
        <?php foreach ($paListe as $r): $ber = produktion_bereitschaft((int)$r['id']); ?>
          <tr style="cursor:pointer" onclick="location.href='?p=produktionsauftrag&id=<?= (int)$r['id'] ?>'">
            <td><?= prio_badge((int)($r['prio'] ?? 2)) ?></td>
            <td><?= bereitschaft_badge($ber['status']) ?></td>
            <td><?= h($r['nummer'] ?: ('#' . $r['id'])) ?></td>
            <td><?= kunde_link($r['kunde_id'] ?? null, $r['kunde_firma']) ?></td>
            <td><?= $r['produkt_name'] ? h($r['produkt_name']) : '<span class="muted">–</span>' ?></td>
            <td class="bx-num"><?= (int)$r['menge'] ?></td>
            <td><?= $r['naechste_station'] ? h($r['naechste_station']) : '<span class="muted">–</span>' ?> <span class="muted">(<?= (int)$r['n_done'] ?>/<?= (int)$r['n_total'] ?>)</span></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>

  <div class="bx-panel" style="margin:0">
    <div class="bx-row" style="justify-content:space-between;align-items:baseline">
      <h2 style="margin:0">Letzte Wareneingänge</h2>
      <a class="btn btn-ghost btn-sm" href="?p=wareneingang">Wareneingang buchen</a>
    </div>
    <div class="bx-tablewrap" style="margin-top:12px"><table class="bx-table">
      <thead><tr><th>Charge</th><th>Artikel</th><th class="bx-num">Menge</th><th>MHD</th><th>Status</th></tr></thead>
      <tbody>
        <?php if (!$wareneingaenge): ?><tr><td colspan="5" class="muted">Noch keine Wareneingänge.</td></tr><?php endif; ?>
        <?php foreach ($wareneingaenge as $c): ?>
          <tr>
            <td><?= h($c['charge_nr'] ?: ('#' . $c['id'])) ?></td>
            <td><?= $c['item_name'] ? h($c['item_name']) : '<span class="muted">–</span>' ?></td>
            <td class="bx-num"><?= rtrim(rtrim(number_format((float)$c['menge_verfuegbar'],3,',','.'),'0'),',') ?> <?= h($c['einheit']) ?></td>
            <td><?= $c['mhd'] ? h(date('d.m.Y', strtotime($c['mhd']))) : '<span class="muted">–</span>' ?></td>
            <td><?= $chBadge($c['status']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
</div>
<?php render_footer(); ?>

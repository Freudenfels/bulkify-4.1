<?php
// Start-Dashboard (intern) – Team-Cockpit mit echten Zahlen
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

// Damit auf einer frischen DB Zahlen da sind
seed_anfrage_if_empty(); seed_angebot_if_empty(); seed_charge_if_empty();

$anfragen_neu   = (int) scalar("SELECT COUNT(*) FROM rezeptur_anfrage WHERE status='neu'");
$angebote_offen = (int) scalar("SELECT COUNT(*) FROM angebot WHERE status IN ('offen','gesendet')");
$prod_offen     = (int) scalar("SELECT COUNT(*) FROM produktionsauftrag WHERE status IN ('offen','laufend')");
$versandbereit  = (int) scalar("SELECT COUNT(*) FROM auftrag WHERE status='erledigt'");
$offene_posten  = (float) scalar("SELECT COALESCE(SUM(brutto),0) FROM beleg WHERE typ='rechnung' AND status='offen'");
$rohstoff_leer  = (int) scalar("SELECT COUNT(*) FROM item i WHERE i.kategorie='rohstoff' AND i.gesperrt=0
                                AND (SELECT COALESCE(SUM(menge_verfuegbar),0) FROM charge c WHERE c.item_id=i.id AND c.status='frei') <= 0");

$eur = fn($x) => number_format((float)$x, 2, ',', '.') . ' €';

$neue_anfragen = all("SELECT a.*, k.firma FROM rezeptur_anfrage a LEFT JOIN kunden k ON k.id=a.kunde_id WHERE a.status='neu' ORDER BY a.angelegt DESC LIMIT 6");
$versand_liste = all("SELECT a.*, k.firma, p.name AS produkt FROM auftrag a LEFT JOIN kunden k ON k.id=a.kunde_id LEFT JOIN produkt p ON p.id=a.produkt_id WHERE a.status='erledigt' ORDER BY a.angelegt DESC LIMIT 6");
$offene_rechn  = all("SELECT b.*, k.firma FROM beleg b LEFT JOIN kunden k ON k.id=b.kunde_id WHERE b.typ='rechnung' AND b.status='offen' ORDER BY b.datum DESC LIMIT 6");

render_header('dashboard', 'Dashboard');
bx_head('Dashboard', 'Überblick über den laufenden Betrieb');

// Kennzahlen
function kachel(string $k, $v, string $href, string $farbe = ''): void {
    echo '<a class="bx-card" style="text-decoration:none;min-width:150px" href="' . h($href) . '">'
       . '<div class="k">' . h($k) . '</div><div class="v" style="' . $farbe . '">' . $v . '</div></a>';
}
echo '<div class="bx-cards">';
kachel('Neue Anfragen', $anfragen_neu ?: '<span class="muted">0</span>', '?p=anfragen', $anfragen_neu ? 'color:var(--info)' : '');
kachel('Offene Angebote', $angebote_offen ?: '<span class="muted">0</span>', '?p=angebote');
kachel('In Produktion', $prod_offen ?: '<span class="muted">0</span>', '?p=produktion');
kachel('Versandbereit', $versandbereit ?: '<span class="muted">0</span>', '?p=versand', $versandbereit ? 'color:var(--gruen)' : '');
kachel('Offene Posten', $offene_posten > 0 ? number_format($offene_posten,2,',','.').' €' : '<span class="muted">0 €</span>', '?p=rechnungen', $offene_posten > 0 ? 'color:var(--warn)' : '');
kachel('Rohstoffe leer', $rohstoff_leer ?: '<span class="muted">0</span>', '?p=lager', $rohstoff_leer ? 'color:var(--err)' : '');
echo '</div>';
?>
<div class="bx-cards" style="align-items:flex-start">
  <div class="bx-panel" style="flex:1;min-width:320px">
    <h2>Neue Rezepturanfragen</h2>
    <div class="bx-tablewrap"><table class="bx-table"><tbody>
      <?php if (!$neue_anfragen): ?><tr><td class="muted">Keine offenen Anfragen.</td></tr><?php endif; ?>
      <?php foreach ($neue_anfragen as $a): ?>
        <tr style="cursor:pointer" onclick="location.href='?p=anfrage&id=<?= (int)$a['id'] ?>'">
          <td><strong><?= h($a['nummer']) ?></strong></td><td><?= kunde_link($a['kunde_id'] ?? null, $a['firma']) ?></td><td><?= $a['darreichungsform'] ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody></table></div>
  </div>
  <div class="bx-panel" style="flex:1;min-width:320px">
    <h2>Versandbereit</h2>
    <div class="bx-tablewrap"><table class="bx-table"><tbody>
      <?php if (!$versand_liste): ?><tr><td class="muted">Nichts versandbereit.</td></tr><?php endif; ?>
      <?php foreach ($versand_liste as $a): ?>
        <tr style="cursor:pointer" onclick="location.href='?p=versand'">
          <td><strong><?= h($a['nummer']) ?></strong></td><td><?= kunde_link($a['kunde_id'] ?? null, $a['firma']) ?></td><td class="bx-num"><?= (int)$a['menge'] ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody></table></div>
  </div>
  <div class="bx-panel" style="flex:1;min-width:320px">
    <h2>Offene Rechnungen · <?= $eur($offene_posten) ?></h2>
    <div class="bx-tablewrap"><table class="bx-table"><tbody>
      <?php if (!$offene_rechn): ?><tr><td class="muted">Keine offenen Rechnungen.</td></tr><?php endif; ?>
      <?php foreach ($offene_rechn as $b): ?>
        <tr style="cursor:pointer" onclick="location.href='?p=rechnung&id=<?= (int)$b['id'] ?>'">
          <td><strong><?= h($b['nummer']) ?></strong></td><td><?= kunde_link($b['kunde_id'] ?? null, $b['firma']) ?></td><td class="bx-num"><?= $eur($b['brutto']) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody></table></div>
  </div>
</div>
<?php
render_footer();

<?php
// Start-Dashboard (intern) – Team-Cockpit mit echten Zahlen
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';
require_once BX_ROOT . '/core/preis_gueltig.php';
require_once BX_ROOT . '/core/offene_posten.php';   // op_rechnungen(): offene Posten wie in der Buchhaltung (13.11.2026)
if (function_exists('pg_bald_pruefen')) { try { pg_bald_pruefen(); } catch (Throwable $e) {} }   // einmal am Tag: bald ablaufende Preise in offenen Angeboten melden

// Damit auf einer frischen DB Zahlen da sind
seed_anfrage_if_empty(); seed_angebot_if_empty(); seed_charge_if_empty();

// Automatische Hinweise (MHD-Ablauf, überfällige Bestellungen) – gedrosselt auf einmal je Tag (best-effort).
if (function_exists('benachrichtigungen_auto_pruefen')) benachrichtigungen_auto_pruefen();

$anfragen_neu   = (int) scalar("SELECT COUNT(*) FROM rezeptur_anfrage WHERE status='neu'");
// 13.11.2026 (Paket Teil 8): Zähler nur für offene Vorgänge – abgelaufene Angebote zählen nicht, Produktionsaufträge versendeter/
// stornierter Aufträge auch nicht, „Rohstoffe leer“ nur, wenn ein offener Auftrag den Rohstoff braucht.
$angebote_offen = (int) scalar("SELECT COUNT(*) FROM angebot WHERE status IN ('offen','gesendet') AND (gueltig_bis IS NULL OR gueltig_bis >= CURDATE())");
$prod_offen     = (int) scalar("SELECT COUNT(*) FROM produktionsauftrag pa LEFT JOIN auftrag au ON au.id=pa.auftrag_id WHERE pa.status IN ('offen','laufend') AND " . pa_aktiv_sql() . " AND (au.id IS NULL OR au.status NOT IN ('versendet','storniert'))");
// Versandbereit nur, wenn der Fortschritt wirklich fertig ist (11.11.2026): Labor da, alle Mengen da - sonst nicht mitzählen.
$versandAlle    = all("SELECT a.*, k.firma, p.name AS produkt FROM auftrag a LEFT JOIN kunden k ON k.id=a.kunde_id LEFT JOIN produkt p ON p.id=a.produkt_id WHERE a.status='erledigt' ORDER BY a.angelegt DESC");
$versandAlle    = array_values(array_filter($versandAlle, fn($a) => ($a['kategorie'] ?? '') === 'dienstleistung' || auftrag_fortschritt_fertig($a)));
$versandbereit  = count($versandAlle);
// Offene Posten (13.11.2026): offen + teilbezahlt, je Rechnung Brutto minus Zahlungen – eine Rechnung mit der Buchhaltung (core/offene_posten.php).
$opRows         = op_rechnungen();
$offene_posten  = round(array_sum(array_map(fn($r) => (float)$r['rest'], $opRows)), 2);
$rohstoff_leer  = (int) scalar("SELECT COUNT(*) FROM item i WHERE i.kategorie='rohstoff' AND i.gesperrt=0
                                AND (SELECT COALESCE(SUM(menge_verfuegbar),0) FROM charge c WHERE c.item_id=i.id AND c.status='frei') <= 0
                                AND EXISTS (SELECT 1 FROM produktionsauftrag pa LEFT JOIN produkt pp ON pp.id=pa.produkt_id
                                            JOIN rezeptur_zutat z ON z.rezeptur_id=COALESCE(pa.rezeptur_id, pp.rezeptur_id)
                                            LEFT JOIN auftrag au ON au.id=pa.auftrag_id
                                            WHERE z.item_id=i.id AND pa.status IN ('offen','laufend','vorbereitung') AND (au.id IS NULL OR au.status NOT IN ('versendet','storniert')))");

$eur = fn($x) => number_format((float)$x, 2, ',', '.') . ' €';

$neue_anfragen = all("SELECT a.*, k.firma FROM rezeptur_anfrage a LEFT JOIN kunden k ON k.id=a.kunde_id WHERE a.status='neu' ORDER BY a.angelegt DESC LIMIT 6");
$versand_liste = array_slice($versandAlle, 0, 6);
// Liste: die 6 neuesten offenen Rechnungen mit offenem Rest (nicht Brutto).
$offene_rechn  = $opRows;
usort($offene_rechn, fn($x, $y) => [(string)$y['datum'], (int)$y['id']] <=> [(string)$x['datum'], (int)$x['id']]);
$offene_rechn  = array_slice($offene_rechn, 0, 6);

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
kachel('Offene Posten', $offene_posten > 0 ? number_format($offene_posten,2,',','.').' €' : '<span class="muted">0 €</span>', '/buchhaltung/?p=op_debitoren', $offene_posten > 0 ? 'color:var(--warn)' : '');
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
          <td><strong><?= h($a['nummer']) ?></strong></td><td><?= kunde_link($a['kunde_id'] ?? null, $a['firma']) ?></td><td class="bx-num"><?= number_format((int)$a['menge'], 0, ',', '.') ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody></table></div>
  </div>
  <div class="bx-panel" style="flex:1;min-width:320px">
    <h2>Offene Rechnungen · <?= $eur($offene_posten) ?></h2>
    <div class="bx-tablewrap"><table class="bx-table"><tbody>
      <?php if (!$offene_rechn): ?><tr><td class="muted">Keine offenen Rechnungen.</td></tr><?php endif; ?>
      <?php foreach ($offene_rechn as $b): ?>
        <tr style="cursor:pointer" onclick="location.href='/buchhaltung/?p=rechnung&id=<?= (int)$b['id'] ?>'">
          <td><strong><?= h($b['nummer']) ?></strong></td><td><?= kunde_link($b['kunde_id'] ?? null, $b['kunde_firma']) ?></td><td class="bx-num"><?= $eur($b['rest']) ?><?= $b['status'] === 'teilbezahlt' ? '<div class="muted" style="font-size:11px">teilbezahlt</div>' : '' ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody></table></div>
  </div>
</div>
<?php
render_footer();

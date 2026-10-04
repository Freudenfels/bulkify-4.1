<?php
// Buchhaltung – Finanz-Hub / Übersicht (Route: buchhaltung, Rolle finance)
// Landeseite des Buchhaltungs-Bereichs: Kennzahlen (offene Posten, überfällig, Umsatz),
// überfällige Rechnungen + zuletzt bezahlt, dazu Schnelleinstiege.
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

$eur = fn($x) => number_format((float)$x, 2, ',', '.') . ' €';

// --- Kennzahlen -----------------------------------------------------------
// Offene Posten = Brutto minus gezahlte Beträge, über alle nicht stornierten Rechnungen
// (deckt auch Teilzahlungen korrekt ab).
$op = (float) scalar(
    "SELECT COALESCE(SUM(b.brutto - COALESCE(z.bez,0)),0)
       FROM beleg b
       LEFT JOIN (SELECT beleg_id, SUM(betrag) bez FROM zahlung GROUP BY beleg_id) z ON z.beleg_id=b.id
      WHERE b.typ='rechnung' AND b.status IN ('offen','teilbezahlt')");

$op_ueberfaellig = (float) scalar(
    "SELECT COALESCE(SUM(b.brutto - COALESCE(z.bez,0)),0)
       FROM beleg b
       LEFT JOIN (SELECT beleg_id, SUM(betrag) bez FROM zahlung GROUP BY beleg_id) z ON z.beleg_id=b.id
      WHERE b.typ='rechnung' AND b.status IN ('offen','teilbezahlt')
        AND b.faellig IS NOT NULL AND b.faellig < CURDATE()");

$anz_offen = (int) scalar("SELECT COUNT(*) FROM beleg WHERE typ='rechnung' AND status IN ('offen','teilbezahlt')");

// Umsatz (netto) aus gestellten Rechnungen abzüglich Gutschriften, ohne Storno
$umsatz_jahr = (float) scalar(
    "SELECT COALESCE(SUM(CASE WHEN typ='gutschrift' THEN -netto ELSE netto END),0)
       FROM beleg WHERE typ IN ('rechnung','gutschrift') AND status<>'storniert'
        AND datum IS NOT NULL AND YEAR(datum)=YEAR(CURDATE())");
$umsatz_monat = (float) scalar(
    "SELECT COALESCE(SUM(CASE WHEN typ='gutschrift' THEN -netto ELSE netto END),0)
       FROM beleg WHERE typ IN ('rechnung','gutschrift') AND status<>'storniert'
        AND datum IS NOT NULL AND YEAR(datum)=YEAR(CURDATE()) AND MONTH(datum)=MONTH(CURDATE())");

$anz_gutschrift = (int) scalar("SELECT COUNT(*) FROM beleg WHERE typ='gutschrift'");

// --- Listen ---------------------------------------------------------------
$ueberfaellig = all(
    "SELECT b.*, k.firma, COALESCE(z.bez,0) AS bezahlt,
            (b.brutto - COALESCE(z.bez,0)) AS rest,
            DATEDIFF(CURDATE(), b.faellig) AS tage
       FROM beleg b
       LEFT JOIN kunden k ON k.id=b.kunde_id
       LEFT JOIN (SELECT beleg_id, SUM(betrag) bez FROM zahlung GROUP BY beleg_id) z ON z.beleg_id=b.id
      WHERE b.typ='rechnung' AND b.status IN ('offen','teilbezahlt')
        AND b.faellig IS NOT NULL AND b.faellig < CURDATE()
      ORDER BY b.faellig ASC LIMIT 8");

$zuletzt_bezahlt = all(
    "SELECT b.*, k.firma
       FROM beleg b LEFT JOIN kunden k ON k.id=b.kunde_id
      WHERE b.typ='rechnung' AND b.status='bezahlt'
      ORDER BY b.datum DESC, b.id DESC LIMIT 8");

$jahr = (int) date('Y');

render_header('buchhaltung', 'Buchhaltung');
bx_head('Buchhaltung', 'Finanzübersicht · offene Posten, Umsatz und Belege');

// Kennzahl-Kacheln
function fkachel(string $k, $v, string $href, string $farbe = ''): void {
    echo '<a class="bx-card" style="text-decoration:none;min-width:170px" href="' . h($href) . '">'
       . '<div class="k">' . h($k) . '</div><div class="v" style="' . $farbe . '">' . $v . '</div></a>';
}
echo '<div class="bx-cards">';
fkachel('Offene Posten', $op > 0 ? $eur($op) : '<span class="muted">0 €</span>', '?p=rechnungen', $op > 0 ? 'color:var(--warn)' : '');
fkachel('Davon überfällig', $op_ueberfaellig > 0 ? $eur($op_ueberfaellig) : '<span class="muted">0 €</span>', '?p=rechnungen', $op_ueberfaellig > 0 ? 'color:var(--err)' : '');
fkachel('Offene Rechnungen', $anz_offen ?: '<span class="muted">0</span>', '?p=rechnungen');
fkachel('Umsatz ' . $jahr, $eur($umsatz_jahr), '?p=rechnungen', $umsatz_jahr > 0 ? 'color:var(--gruen)' : '');
fkachel('Umsatz ' . date('M'), $eur($umsatz_monat), '?p=rechnungen');
fkachel('Gutschriften', $anz_gutschrift ?: '<span class="muted">0</span>', '?p=rechnungen');
echo '</div>';
?>
<form class="bx-listbar" method="get">
  <span class="muted" style="align-self:center">Schnellaktionen</span>
  <span style="flex:1"></span>
  <a class="btn btn-ghost btn-sm" href="?p=rechnungen">Alle Rechnungen</a>
  <a class="btn btn-ghost btn-sm" href="?p=gutschrift_neu">Storno-Rechnung</a>
  <a class="btn btn-ghost btn-sm" href="?p=rechnung_import">Alt-Rechnungen importieren</a>
  <a class="btn btn-ghost btn-sm" href="?p=auftrag_import">Auftrag aus Angebot importieren</a>
  <a class="btn btn-primary btn-sm" href="?p=rechnung_frei">+ Rechnung erstellen</a>
</form>
<div class="bx-cards" style="align-items:flex-start">
  <div class="bx-panel" style="flex:1;min-width:360px">
    <h2>Überfällige Rechnungen<?= $op_ueberfaellig > 0 ? ' · ' . h($eur($op_ueberfaellig)) : '' ?></h2>
    <div class="bx-tablewrap"><table class="bx-table">
      <thead><tr><th>Nummer</th><th>Kunde</th><th>Fällig</th><th class="bx-num">Offen</th></tr></thead>
      <tbody>
        <?php if (!$ueberfaellig): ?><tr><td colspan="4" class="muted">Keine überfälligen Rechnungen.</td></tr><?php endif; ?>
        <?php foreach ($ueberfaellig as $b): ?>
          <tr style="cursor:pointer" onclick="location.href='?p=rechnung&id=<?= (int)$b['id'] ?>'">
            <td><strong><?= h($b['nummer']) ?></strong></td>
            <td><?= kunde_link($b['kunde_id'] ?? null, $b['firma']) ?></td>
            <td><?= h(date('d.m.Y', strtotime($b['faellig']))) ?> <span class="muted">(<?= (int)$b['tage'] ?> T)</span></td>
            <td class="bx-num"><?= $eur($b['rest']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
  <div class="bx-panel" style="flex:1;min-width:360px">
    <h2>Zuletzt bezahlt</h2>
    <div class="bx-tablewrap"><table class="bx-table">
      <thead><tr><th>Nummer</th><th>Kunde</th><th>Datum</th><th class="bx-num">Brutto</th></tr></thead>
      <tbody>
        <?php if (!$zuletzt_bezahlt): ?><tr><td colspan="4" class="muted">Noch keine bezahlten Rechnungen.</td></tr><?php endif; ?>
        <?php foreach ($zuletzt_bezahlt as $b): ?>
          <tr style="cursor:pointer" onclick="location.href='?p=rechnung&id=<?= (int)$b['id'] ?>'">
            <td><strong><?= h($b['nummer']) ?></strong></td>
            <td><?= kunde_link($b['kunde_id'] ?? null, $b['firma']) ?></td>
            <td><?= $b['datum'] ? h(date('d.m.Y', strtotime($b['datum']))) : '' ?></td>
            <td class="bx-num"><?= $eur($b['brutto']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
</div>
<?php
render_footer();

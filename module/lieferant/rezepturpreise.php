<?php
// Lieferantenportal – „Rezeptur-Preise" (Fremdfertigung). Route: ?p=lieferant_rezepturpreise
// Der Lieferant traegt je Rezeptur seinen Fremdfertigungspreis (z. B. je Kapsel) ein. KEIN „Annehmen" –
// mehrere Lieferanten stehen nebeneinander und unterbieten sich; das Team sieht alle und nimmt den guenstigsten.
// 4-Wochen-Regel: sind die Preise aelter als das Intervall, erscheint ein Hinweis + „Alle als aktuell bestaetigen".
require_once BX_ROOT . '/module/lieferant/portal_layout.php';
require_once BX_ROOT . '/core/schema.php';
require_once BX_ROOT . '/core/preis_gueltig.php';   // „gültig bis" je Preis (11.11.2026)
require_once BX_ROOT . '/core/lieferant_unterlagen.php';   // Kennzeichen „Unterlagen fehlen“ (Paket 12.11.2026 Teil 4)
require_once BX_ROOT . '/core/fertig_anfrage.php';  // Zusammensetzung in der Zeile; nur angefragte Rezepturen (11.11.2026)
if (!ist_lieferant()) { header('Location: ?p=lieferant_login'); exit; }

$lid  = aktueller_lieferant_id();
$spr  = lp_sprache();
$ziel = '?p=lieferant_rezepturpreise';

// Einheit-Label je Darreichungsform (der Preis gilt je Stueck/Kapsel …).
$formEinheit = fn($f) => in_array($f, ['kapsel','softgel'], true) ? 'Kapsel' : ($f === 'tablette' ? 'Tablette' : ($f === 'stick' ? 'Stick' : 'Stk'));
// Welche Darreichungsformen hat das Team fuer diesen Lieferanten freigeschaltet? Nur diese darf er bepreisen.
$fertigFormen = array_values(array_filter(array_map('trim', explode(',', (string) scalar("SELECT fertig_formen FROM lieferanten WHERE id=?", [$lid])))));
$formLabel = ['kapsel'=>'Kapseln','tablette'=>'Tabletten','softgel'=>'Softgels','stick'=>'Sticks','pulver'=>'Pulver','fluessig'=>'Flüssig','gummi'=>'Fruchtgummi','gel'=>'Gel'];

// Einen Preis speichern. Rückgabe 'ok' (gilt sofort), 'vorschlag' (wartet auf Freigabe) oder '' (nichts getan).
$preisSpeichern = function (int $aid, float $preis) use ($lid): string {
    $cur = one("SELECT preis FROM rezeptur_lief_angebot WHERE id=? AND lieferant_id=?", [$aid, $lid]);
    if (!$cur) return '';
    $alt = ($cur['preis'] !== null && (float)$cur['preis'] > 0) ? (float)$cur['preis'] : null;
    if ($alt === null) {
        // Noch kein festgesetzter Preis -> gilt sofort (Erstangebot).
        q("UPDATE rezeptur_lief_angebot SET preis=?, stand=CURDATE(), preis_vorschlag=NULL, vorschlag_am=NULL WHERE id=? AND lieferant_id=?", [$preis > 0 ? $preis : null, $aid, $lid]);
        rezeptur_produkt_matrizen_auffrischen((int) scalar("SELECT rezeptur_id FROM rezeptur_lief_angebot WHERE id=?", [$aid]));
        return 'ok';
    }
    if ($preis > 0 && abs($preis - $alt) < 0.00005) {
        // Gleicher Preis -> als aktuell bestätigt behandeln (4-Wochen-Regel), Vorschlag weg.
        q("UPDATE rezeptur_lief_angebot SET stand=CURDATE(), preis_vorschlag=NULL, vorschlag_am=NULL WHERE id=? AND lieferant_id=?", [$aid, $lid]);
        return 'ok';
    }
    // Änderung eines festgesetzten Preises -> nur VORSCHLAG; der alte Preis bleibt gültig, bis bulkify freigibt.
    q("UPDATE rezeptur_lief_angebot SET preis_vorschlag=?, vorschlag_am=NOW() WHERE id=? AND lieferant_id=?", [$preis > 0 ? $preis : null, $aid, $lid]);
    return 'vorschlag';
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aktion = (string)($_POST['aktion'] ?? '');
    if ($aktion === 'preis_save') {
        $r = $preisSpeichern((int)($_POST['id'] ?? 0), zahl_lesen((string)($_POST['preis'] ?? ''), false, $spr));
        if ($r === 'vorschlag' && function_exists('benachrichtigung_add')) {
            $lfirma = (string) scalar("SELECT firma FROM lieferanten WHERE id=?", [$lid]);
            benachrichtigung_add('Preis-Vorschlag (Fremdfertigung): ' . ($lfirma ?: ('Lieferant #' . $lid)),
                'Ein Lieferant möchte einen festgesetzten Fremdfertigungspreis ändern – wartet auf Freigabe.', '?p=einkauf_preise&tab=fremd');
        }
        header('Location: ' . $ziel . ($r === 'vorschlag' ? '&vorschlag=1' : ($r === 'ok' ? '&ok=1' : ''))); exit;
    }
    // Alle Zeilen auf einmal (Nico 11.11.2026: Lieferantin trug alle Preise ein und klickte einmal – nur eine Zeile kam an).
    // Jede Zeile schickt ihren Preis mit; gespeichert werden nur Zeilen, deren Eingabe sich geändert hat.
    if ($aktion === 'preis_save_alle') {
        $n = 0; $nv = 0; $falsch = 0;
        foreach ((array)($_POST['preis'] ?? []) as $aid => $roh) {
            $roh = trim((string)$roh); $vorher = trim((string)($_POST['vorher'][$aid] ?? ''));
            $gNeu = pg_lesen((string)($_POST['gueltig'][$aid] ?? '')); $gAlt = trim((string)($_POST['gueltig_vorher'][$aid] ?? ''));
            if ((string)$gNeu !== $gAlt) { q("UPDATE rezeptur_lief_angebot SET gueltig_bis=?, stand=CURDATE() WHERE id=? AND lieferant_id=?", [$gNeu, (int)$aid, $lid]); if ($roh === $vorher) $n++; }
            if ($roh === $vorher) continue;
            $preis = zahl_lesen($roh, false, $spr);
            if ($roh !== '' && $preis <= 0) { $falsch++; continue; }   // nicht lesbar -> nichts überschreiben
            $r = $preisSpeichern((int)$aid, $preis);
            if ($r === 'ok') $n++; elseif ($r === 'vorschlag') $nv++;
        }
        if ($nv > 0 && function_exists('benachrichtigung_add')) {
            $lfirma = (string) scalar("SELECT firma FROM lieferanten WHERE id=?", [$lid]);
            benachrichtigung_add('Preis-Vorschlag (Fremdfertigung): ' . ($lfirma ?: ('Lieferant #' . $lid)),
                'Ein Lieferant möchte festgesetzte Fremdfertigungspreise ändern (' . $nv . ') – wartet auf Freigabe.', '?p=einkauf_preise&tab=fremd');
        }
        if ($n > 0 && function_exists('benachrichtigung_add')) {
            $lfirma = (string) scalar("SELECT firma FROM lieferanten WHERE id=?", [$lid]);
            benachrichtigung_add('Neue Fremdfertigungspreise: ' . ($lfirma ?: ('Lieferant #' . $lid)),
                $n . ' Preis(e) eingetragen.', '?p=lieferant&id=' . $lid);
        }
        header('Location: ' . $ziel . '&gesp=' . $n . '&vors=' . $nv . '&falsch=' . $falsch); exit;
    }
    if ($aktion === 'preis_add') {
        $rid   = (int)($_POST['rezeptur_id'] ?? 0);
        $preis = zahl_lesen((string)($_POST['preis'] ?? ''), false, $spr);
        $form  = (string) scalar("SELECT darreichungsform FROM rezeptur WHERE id=?", [$rid]);
        // Nur freigeschaltete Formen + keine Dublette.
        $angefragtR = (bool) scalar("SELECT id FROM lieferant_anfrage WHERE lieferant_id=? AND rezeptur_id=? AND art='fertigprodukt' AND status NOT IN ('zurueckgezogen','geschlossen')", [$lid, $rid]);
        if ($rid > 0 && $angefragtR && !scalar("SELECT id FROM rezeptur_lief_angebot WHERE rezeptur_id=? AND lieferant_id=?", [$rid, $lid])) {
            q("INSERT INTO rezeptur_lief_angebot (rezeptur_id,lieferant_id,preis,einheit,status,stand,angelegt,gueltig_bis) VALUES (?,?,?,?, 'angeboten', CURDATE(), NOW(), ?)",
              [$rid, $lid, $preis > 0 ? $preis : null, $formEinheit($form), pg_lesen((string)($_POST['gueltig_bis'] ?? ''))]);
            rezeptur_produkt_matrizen_auffrischen($rid);   // Matrix auffrischen -> Fertigpreis automatisch im Angebot
        }
        header('Location: ' . $ziel . '&ok=1'); exit;
    }
    if ($aktion === 'preis_del') {
        $rid = (int) scalar("SELECT rezeptur_id FROM rezeptur_lief_angebot WHERE id=? AND lieferant_id=?", [(int)($_POST['id'] ?? 0), $lid]);
        q("DELETE FROM rezeptur_lief_angebot WHERE id=? AND lieferant_id=?", [(int)($_POST['id'] ?? 0), $lid]);
        rezeptur_produkt_matrizen_auffrischen($rid);   // ohne diesen Preis neu rechnen (ggf. anderer Lieferant/EK)
        header('Location: ' . $ziel . '&ok=1'); exit;
    }
    if ($aktion === 'alle_bestaetigen') {
        q("UPDATE rezeptur_lief_angebot SET stand=CURDATE() WHERE lieferant_id=?", [$lid]);
        header('Location: ' . $ziel . '&best=1'); exit;
    }
    header('Location: ' . $ziel); exit;
}

$rows = all("SELECT la.id, la.preis, la.preis_vorschlag, la.einheit, la.stand, la.gueltig_bis, r.id AS rezeptur_id, r.nummer, r.name, r.darreichungsform AS form
             FROM rezeptur_lief_angebot la JOIN rezeptur r ON r.id=la.rezeptur_id
             WHERE la.lieferant_id=? ORDER BY r.name", [$lid]);
// Angefragte Rezepturen ohne Preis (Nico 11.11.2026): Der Lieferant wählt NICHT mehr frei aus allen Rezepturen seiner Formen
// (blind nach Namen, inkl. Kundenrezepturen), sondern sieht nur, was wir ihm als Fremdfertigung angefragt haben.
$offen = all("SELECT la.id AS anfrage_id, la.nummer AS anfrage_nr, r.id, r.nummer, r.name, r.darreichungsform AS form
              FROM lieferant_anfrage la JOIN rezeptur r ON r.id=la.rezeptur_id
              WHERE la.lieferant_id=? AND la.art='fertigprodukt' AND la.status='offen'
                AND NOT EXISTS (SELECT 1 FROM lieferant_angebot ag WHERE ag.anfrage_id=la.id)
                AND r.id NOT IN (SELECT rezeptur_id FROM rezeptur_lief_angebot WHERE lieferant_id=?)
              ORDER BY la.angelegt, r.name", [$lid, $lid]);
$intervall = lieferant_preis_intervall($lid);
$standMax  = null; foreach ($rows as $r) if ($r['stand'] && (!$standMax || $r['stand'] > $standMax)) $standMax = $r['stand'];
$alter     = $standMax ? (int) floor((time() - strtotime((string)$standMax)) / 86400) : null;
$veraltet  = $rows && ($alter === null || $alter > $intervall);
$num = fn($x) => $x === null || $x === '' ? '' : rtrim(rtrim(number_format((float)$x, 6, ',', '.'), '0'), ',');   // 13.11.2026: 6 Stellen (Sub-Cent bleibt beim Speichern erhalten)

lp_head('bulkify – ' . lp_t('rez_preise_menu'));
lp_shell_start('lieferant_rezepturpreise');
if (isset($_GET['ok']))   echo '<div class="bx-panel badge-ok" style="padding:12px 16px">' . h(lp_t('gespeichert')) . '</div>';
if (isset($_GET['best'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">' . h(lp_t('preise_bestaetigt')) . '</div>';
if (isset($_GET['gesp'])) {
    $gN = (int)$_GET['gesp']; $gV = (int)($_GET['vors'] ?? 0); $gF = (int)($_GET['falsch'] ?? 0);
    if ($gN > 0 || ($gV === 0 && $gF === 0)) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">' . h(lp_t('gespeichert')) . ($gN > 0 ? ' (' . $gN . ')' : '') . '</div>';
    if ($gV > 0) echo '<div class="bx-panel" style="padding:12px 16px;border-color:var(--gruen);background:var(--panel-2)">' . h(lp_t('preis_vorschlag_info')) . ' (' . $gV . ')</div>';
    if ($gF > 0) echo '<div class="bx-panel" style="padding:12px 16px;border-color:#e6c4c0;color:#8f231b">' . h(lp_t('preis_ungueltig')) . ' (' . $gF . ')</div>';
}
if (isset($_GET['vorschlag'])) echo '<div class="bx-panel" style="padding:12px 16px;border-color:var(--gruen);background:var(--panel-2)">' . h(lp_t('preis_vorschlag_info')) . '</div>';
?>
<h1 style="margin-bottom:4px"><?= h(lp_t('rez_preise_titel')) ?></h1>
<p class="bx-sub"><?= h(lp_t('rez_preise_sub')) ?></p>
<?php if ($fertigFormen): ?>
  <p class="muted" style="font-size:13px;margin:-6px 0 12px"><?= h(lp_t('freigeschaltet_formen')) ?>: <strong><?= h(implode(', ', array_map(fn($f) => anfrage_art_label('', $f, $spr) ?: ($formLabel[$f] ?? $f), $fertigFormen))) ?></strong></p>
<?php endif; ?>
<?php if ($offen): ?>
<div class="bx-panel" style="border-left:3px solid var(--gruen)">
  <h2 style="margin-top:0"><?= h(['de' => 'Bitte bepreisen', 'en' => 'Please quote', 'zh' => '请报价'][$spr] ?? 'Please quote') ?> <span class="muted" style="font-weight:normal">(<?= count($offen) ?>)</span></h2>
  <p class="muted" style="margin-top:0;font-size:13px"><?= h(['de' => 'Diese Rezepturen haben wir bei Ihnen angefragt. Zusammensetzung aufklappen, dann „Angebot abgeben" (Preis, Staffel, CoA/Spezifikation je Rohstoff).', 'en' => 'We have requested these formulas from you. Open the composition, then "Submit offer" (price, tiers, CoA/specification per raw material).', 'zh' => '我们已向您询价以下配方。展开成分，然后点击“提交报价”（价格、阶梯价、每种原料的CoA/规格）。'][$spr] ?? '') ?></p>
  <div class="bx-tablewrap"><table class="bx-table">
    <thead><tr><th><?= h(lp_t('rezeptur') ?: 'Rezeptur') ?></th><th><?= h(lp_t('form_lbl')) ?></th><th></th></tr></thead>
    <tbody>
    <?php foreach ($offen as $o): ?>
      <tr>
        <td style="white-space:normal"><details><summary style="cursor:pointer"><?= h((string)$o['name']) ?> <span class="muted" style="font-size:12px"><?= h((string)$o['nummer']) ?> · <?= h((string)$o['anfrage_nr']) ?></span></summary><?= fertig_zusammensetzung_html((int)$o['id'], $spr) ?></details></td>
        <td><?= h(anfrage_art_label('', (string)$o['form'], $spr)) ?></td>
        <td class="bx-num"><a class="btn btn-primary btn-sm" href="?p=lieferant_anfrage&id=<?= (int)$o['anfrage_id'] ?>"><?= h(lp_t('angebot_abgeben')) ?></a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php elseif (!$rows): ?>
  <div class="bx-panel muted" style="padding:12px 16px"><?= h(['de' => 'Zurzeit keine Rezepturen zum Bepreisen. Sobald wir Ihnen eine Rezeptur anfragen, erscheint sie hier mit Zusammensetzung.', 'en' => 'No formulas to quote at the moment. As soon as we request one, it appears here with its composition.', 'zh' => '目前没有需要报价的配方。我们向您询价后，配方及成分会显示在这里。'][$spr] ?? '') ?></div>
<?php endif; ?>

<?php if ($veraltet): ?>
<div class="bx-panel" style="border-color:#e6c4c0;background:rgba(230,196,192,.12)">
  <div class="bx-row" style="justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
    <div><strong style="color:#8f231b"><?= h(lp_t('preis_veraltet')) ?></strong>
      <div class="muted" style="font-size:13px"><?= h(lp_t('preis_intervall_hint')) ?> <?= (int)$intervall ?> <?= h(lp_t('tage')) ?><?= $alter !== null ? ' · ' . h(lp_t('zuletzt_vor')) . ' ' . (int)$alter . ' ' . h(lp_t('tage')) : '' ?>.</div>
    </div>
    <form method="post" style="margin:0"><input type="hidden" name="aktion" value="alle_bestaetigen">
      <button class="btn btn-primary" type="submit"><?= h(lp_t('alle_aktuell')) ?></button></form>
  </div>
</div>
<?php elseif ($rows): ?>
<div class="bx-panel badge-ok" style="padding:10px 14px"><?= h(lp_t('preis_aktuell')) ?><?= $alter !== null ? ' · ' . h(lp_t('zuletzt_vor')) . ' ' . (int)$alter . ' ' . h(lp_t('tage')) : '' ?></div>
<?php endif; ?>

<div class="bx-panel">
  <h2 style="margin-top:0"><?= h(lp_t('rez_preise_titel')) ?> <span class="muted" style="font-weight:normal">(<?= count($rows) ?>)</span></h2>
  <?php if (!$rows): ?>
    <div class="muted"><?= h(lp_t('rez_preise_leer')) ?></div>
  <?php else: ?>
  <form method="post" id="rpForm" style="margin:0"><input type="hidden" name="aktion" value="preis_save_alle"></form>
  <div class="bx-tablewrap"><table class="bx-table">
    <thead><tr><th><?= h(lp_t('rezeptur') ?: 'Rezeptur') ?></th><th><?= h(lp_t('form_lbl')) ?></th><th class="bx-num" style="width:300px;white-space:nowrap"><?= h(lp_t('ihr_preis')) ?></th><th style="width:170px"><?= h(['de' => 'Gültig bis', 'en' => 'Valid until', 'zh' => '有效期至'][$spr] ?? 'Valid until') ?></th><th style="width:120px"><?= h(lp_t('stand')) ?></th><th style="width:50px"></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $alt = $r['stand'] ? (int) floor((time() - strtotime((string)$r['stand'])) / 86400) : null; ?>
      <tr>
        <td style="white-space:normal"><details><summary style="cursor:pointer"><?= h($r['name']) ?> <span class="muted" style="font-size:12px"><?= h($r['nummer']) ?></span></summary><?= fertig_zusammensetzung_html((int)$r['rezeptur_id'], $spr) ?></details></td>
        <td><?= h(anfrage_art_label('', (string)$r['form'], $spr) ?: lp_einheit($formEinheit($r['form']), 2)) ?></td>
        <td class="bx-num">
          <div class="bx-row" style="gap:6px;justify-content:flex-end;align-items:center;flex-wrap:nowrap;margin:0">
            <input type="hidden" form="rpForm" name="vorher[<?= (int)$r['id'] ?>]" value="<?= h(zahl_feld($r['preis'], 6)) ?>">
            <input type="text" form="rpForm" class="rp-preis" name="preis[<?= (int)$r['id'] ?>]" value="<?= h(zahl_feld($r['preis'], 6)) ?>" style="width:90px;text-align:right" inputmode="decimal">
            <span class="muted">€ / <?= h(lp_einheit($r['einheit'] ?: $formEinheit($r['form']), 1)) ?></span>
            <button class="btn btn-ghost btn-sm" type="submit" form="rpForm"><?= h(lp_t('aktualisieren')) ?></button>
          </div>
          <?php if (($r['preis_vorschlag'] ?? null) !== null && (float)$r['preis_vorschlag'] > 0): ?>
            <div class="muted" style="font-size:11px;margin-top:4px;color:var(--gruen)"><?= h(lp_t('vorschlag_offen')) ?>: <?= h($num($r['preis_vorschlag'])) ?> €</div>
          <?php endif; ?>
          <?php if (($r['preis'] ?? null) !== null && ($luB = lu_badge($lid, 0, (int)$r['rezeptur_id'], $spr)) !== ''): ?><div style="margin-top:4px"><?= $luB ?> <a href="?p=lieferant_unterlagen" style="font-size:12px"><?= h(lp_t('unterlagen_menu')) ?></a></div><?php endif; ?>
        </td>
        <td><input type="hidden" form="rpForm" name="gueltig_vorher[<?= (int)$r['id'] ?>]" value="<?= h((string)($r['gueltig_bis'] ?? '')) ?>">
            <input type="date" form="rpForm" class="rp-preis" name="gueltig[<?= (int)$r['id'] ?>]" value="<?= h((string)($r['gueltig_bis'] ?? '')) ?>" style="width:auto">
            <div><?= pg_anzeige($r['gueltig_bis'] ?? null, $spr) ?></div></td>
        <td><?= $r['stand'] ? h(date('d.m.Y', strtotime((string)$r['stand']))) . ($alt !== null && $alt > $intervall ? ' <span style="color:#8f231b">!</span>' : '') : '<span class="muted">–</span>' ?></td>
        <td><form method="post" style="margin:0" onsubmit="return confirm('<?= h(lp_t('loeschen')) ?>?');"><input type="hidden" name="aktion" value="preis_del"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn-ghost btn-sm" type="submit">&times;</button></form></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <div class="bx-row" style="justify-content:flex-end;margin-top:10px"><button class="btn btn-primary" type="submit" form="rpForm"><?= h(lp_t('alle_speichern')) ?></button></div>
  <?php endif; ?>

  <?php // Freie Auswahl aus allen Rezepturen entfernt (11.11.2026) - angefragte Rezepturen stehen oben unter „Bitte bepreisen". ?>

</div>
<script>
(function(){
  var f = document.getElementById('rpForm'), offen = false;
  document.querySelectorAll('.rp-preis').forEach(function(i){ i.addEventListener('input', function(){ offen = true; }); });
  if (f) f.addEventListener('submit', function(){ offen = false; });
  window.addEventListener('beforeunload', function(e){ if (offen) { e.preventDefault(); e.returnValue = ''; } });
})();

</script>
<?php lp_shell_ende(); lp_foot();

<?php
// Werk-App, geführter Ablauf – Ansicht (Nico 11.11.2026, Teil 4). Eingebunden in werk.php (Schritt-Ansicht), wenn der nächste
// offene Schritt Abfüllen/Verpacken/Etikettieren ist. Erwartet: $id (PA), $pa, $T, $werkName, $istAdminW.
// Ablauf: Start (Bulk scannen -> Charge/MHD groß -> Linie leer + Stempel, je Pflichtfoto) -> Material scannen -> Kapselprobe/Sack ->
// Kartons drucken -> „Teilmenge fertig" -> Chargenwechsel (Bulk leer) -> Abschluss. Wiederanlauf am nächsten Tag: erneut Fotos.
require_once BX_ROOT . '/core/erp_ablauf.php';
$uc = pa_aktuelle_unter_charge($id);
$heute = (new DateTime('now', new DateTimeZone('Europe/Berlin')))->format('Y-m-d');
$letzterStart = $uc ? (string) scalar("SELECT MAX(zeit) FROM pa_ereignis WHERE unter_charge_id=? AND typ IN ('start','wiederanlauf','chargenwechsel')", [(int)$uc['id']]) : '';
$letzterTag = $letzterStart !== '' ? (new DateTime($letzterStart, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Europe/Berlin'))->format('Y-m-d') : '';
$wiederanlauf = $uc && $letzterTag !== '' && $letzterTag < $heute;
$wechsel = isset($_GET['wechsel']);
$zeigeStart = !$uc || $wechsel || $wiederanlauf;
$machbar = erp_wa_machbar($id);
$gebucht = (float) erp_produktion_gebucht($id); $rest = max(0, (float)$pa['menge'] - $gebucht);
$mz = fn($x) => menge_txt($x);
$fdate = fn($d) => $d ? date('d.m.Y', strtotime((string)$d)) : '–';
$kap = erp_wa_kapsel_info($id);
?>
<style>
  .wa-gross{font-size:34px;font-weight:800;letter-spacing:.5px;color:var(--lime);line-height:1.2}
  .wa-panel input[type=text],.wa-panel select{font-size:22px;padding:12px 14px;border-radius:12px;border:1px solid var(--line);background:var(--panel2);color:var(--text);min-height:56px}
  .wa-panel input[type=file]{font-size:16px;color:var(--text)}
  .wa-zeile{display:flex;gap:12px;flex-wrap:wrap;align-items:center;margin:10px 0}
  .wa-pflicht{border:1px dashed var(--lime);border-radius:14px;padding:12px 14px;margin:8px 0}
  .wa-liste div{padding:8px 0;border-bottom:1px solid var(--line);display:flex;justify-content:space-between;gap:10px;font-size:17px}
  .wa-sperre{background:rgba(228,88,78,.16);border:1px solid var(--err);border-radius:14px;padding:12px 16px;margin-bottom:12px;font-weight:700}
</style>
<?php if ($machbar['etikett_gesperrt']): ?>
  <div class="wa-sperre">Etikettieren gesperrt: <?= h($machbar['grund'] ?: 'Etikett fehlt bzw. nicht freigegeben') ?> – Abfüllen geht, Teilmengen werden bis vor dem Etikettieren gebucht.</div>
<?php endif; ?>

<?php if ($zeigeStart):
    $bulkCode = (string)($_GET['bulk'] ?? '');
    $s = $bulkCode !== '' ? erp_wa_scan($bulkCode) : null;
    $v = $wiederanlauf && !$wechsel ? ['charge_nr' => $uc['charge_nr'], 'mhd' => $uc['mhd'], 'neu' => false] : ($s ? pa_vorgabe($id, (int)$s['charge_id'], (int)$s['prod_charge_id']) : null);
    if ($wiederanlauf && !$wechsel && !$s) {   // Wiederanlauf: Bulk der laufenden Unter-Charge vorbelegen
        $bulkCode = $uc['bulk_charge_id'] ? '?id=' . (int)$uc['bulk_charge_id'] : (string) scalar("SELECT CONCAT(nummer, COALESCE(sub_kennung,'')) FROM prod_charge WHERE id=?", [(int)$uc['prod_charge_id']]);
        $s = erp_wa_scan($bulkCode);
    }
    $linien = array_values(array_filter(array_map('trim', explode(',', (string) (scalar("SELECT v FROM app_meta WHERE k='produktion_linien'") ?: 'Linie 1,Linie 2')))));
    $linieVor = (string) scalar("SELECT linie FROM produktionsauftrag WHERE id=?", [$id]);
?>
  <div class="panel wa-panel">
    <div class="step-sub"><?= $wiederanlauf && !$wechsel ? 'Wiederanlauf (neuer Tag) – gleiche Charge, erneut Linie und Stempel bestätigen' : ($uc ? 'Chargenwechsel – neuen Bulk scannen' : 'Start') ?></div>
    <?php if (!$s): ?>
      <div class="step-h">1. Bulk scannen</div>
      <div class="muted" style="font-size:17px">Sack, Eimer oder Karton mit dem Bulk scannen. Der gescannte Bulk bestimmt die Charge.</div>
      <form method="get" class="wa-zeile"><input type="hidden" name="p" value="werk"><input type="hidden" name="id" value="<?= (int)$id ?>"><?php if ($wechsel): ?><input type="hidden" name="wechsel" value="1"><?php endif; ?>
        <input type="text" name="bulk" autofocus placeholder="Scannen …" style="flex:1;min-width:240px"><button class="btn btn-primary" type="submit">Weiter</button></form>
    <?php else: ?>
      <div class="muted" style="font-size:16px">Bulk: <?= h($s['name']) ?> · <?= h($s['charge_nr']) ?><?= $s['mhd'] ? ' · MHD ' . h($fdate($s['mhd'])) : '' ?></div>
      <div style="margin:14px 0 6px" class="muted">Heute:</div>
      <div class="wa-gross">Charge <?= h((string)$v['charge_nr']) ?> · MHD <?= h($fdate($v['mhd'])) ?></div>
      <div style="font-size:19px;margin:6px 0 14px">Stempel genau so einstellen.</div>
      <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="aktion" value="wa_start"><input type="hidden" name="pa_id" value="<?= (int)$id ?>"><input type="hidden" name="bulk_code" value="<?= h($bulkCode) ?>">
        <div class="wa-zeile"><span>Linie</span><select name="linie"><?php foreach ($linien as $l): ?><option<?= $l === $linieVor ? ' selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></div>
        <div class="wa-pflicht">2. Linie ist leer (keine Reste der vorigen Charge) – <b>Foto</b> <input type="file" name="foto_linie" accept="image/*" capture="environment" required></div>
        <div class="wa-pflicht">3. Stempel steht auf Charge <?= h((string)$v['charge_nr']) ?> und MHD <?= h($fdate($v['mhd'])) ?> – <b>Foto vom Stempelbild</b> <input type="file" name="foto_stempel" accept="image/*" capture="environment" required></div>
        <?php if (!empty($v['neu'])): ?>
        <details style="margin:10px 0"><summary class="muted" style="cursor:pointer">Charge weicht ab? (nur mit Grund – geht als Aufgabe ans Büro)</summary>
          <div class="wa-zeile"><input type="text" name="charge_nr" placeholder="<?= h((string)$v['charge_nr']) ?>"><input type="text" name="grund" placeholder="Grund" style="flex:1"></div></details>
        <?php endif; ?>
        <?php if (isset($_GET['bulk']) && erp_wa_aeltere_bulk((int)$s['item_id'], (int)$s['charge_id'], (string)$s['mhd']) !== '' && $istAdminW): ?>
          <label class="wa-zeile"><input type="checkbox" name="aelter_ok" value="1"> Admin: jüngere Charge trotzdem verwenden</label>
        <?php endif; ?>
        <button class="btn btn-lime btn-lg" type="submit"><?= $wiederanlauf && !$wechsel ? 'Weiter produzieren' : 'Start' ?></button>
      </form>
      <div style="margin-top:10px"><a class="back" href="?p=werk&id=<?= (int)$id ?><?= $wechsel ? '&wechsel=1' : '' ?>">Anderen Bulk scannen</a><?php if ($wechsel): ?> <a class="back" href="?p=werk&id=<?= (int)$id ?>">Abbrechen</a><?php endif; ?></div>
    <?php endif; ?>
  </div>

<?php else:
    $scans = all("SELECT s.*, i.name, COALESCE(c.charge_nr, CONCAT('#', c.id)) AS cnr FROM pa_scan s LEFT JOIN item i ON i.id=s.item_id LEFT JOIN charge c ON c.id=s.charge_id WHERE s.unter_charge_id=? ORDER BY s.id DESC", [(int)$uc['id']]);
    $kartons = pa_kartons($id, (int)$uc['id']);
    $offenK = array_filter($kartons, fn($k) => empty($k['teilmenge_id']));
    $vorK = array_sum(array_map(fn($k) => (float)$k['menge'], $offenK));
    $tms = all("SELECT * FROM pa_teilmenge WHERE unter_charge_id=? ORDER BY id DESC", [(int)$uc['id']]);
    $kVor = pa_karton_vorschlag($id);
?>
  <div class="panel wa-panel" style="border-color:var(--lime)">
    <div class="muted">Heute läuft:</div>
    <div class="wa-gross">Charge <?= h((string)$uc['charge_nr']) ?> · MHD <?= h($fdate($uc['mhd'])) ?></div>
    <div style="font-size:18px;margin-top:6px">Stempel so eingestellt lassen. Produziert gesamt <?= $mz($gebucht) ?> von <?= $mz($pa['menge']) ?> Stk · offen <?= $mz($rest) ?> · diese Charge <?= $mz(pa_uc_menge((int)$uc['id'])) ?> Stk</div>
  </div>

  <div class="panel wa-panel">
    <div class="step-sub">Material scannen</div>
    <div class="muted" style="font-size:16px">Bulk, Gläser, Deckel, Etiketten scannen. Falsches Material oder ein Bulk einer anderen Charge wird gesperrt.</div>
    <form method="post" class="wa-zeile"><input type="hidden" name="aktion" value="wa_scan"><input type="hidden" name="pa_id" value="<?= (int)$id ?>">
      <input type="text" name="code" placeholder="Scannen …" style="flex:1;min-width:240px"><button class="btn btn-primary" type="submit">OK</button></form>
    <div class="wa-liste"><?php foreach (array_slice($scans, 0, 8) as $sc): ?><div><span><?= h(['bulk' => 'Bulk', 'eimer' => 'Eimer/Sack', 'glas' => 'Glas/Dose', 'deckel' => 'Deckel', 'etikett' => 'Etikett', 'leerkapsel' => 'Leerkapsel', 'karton' => 'Karton', 'beipack' => 'Beipack'][$sc['rolle']] ?? $sc['rolle']) ?> · <?= h((string)($sc['name'] ?? $sc['code'])) ?></span><span class="muted"><?= h((string)($sc['cnr'] ?? '')) ?></span></div><?php endforeach; ?></div>
  </div>

  <?php if ($kap['kapsel'] && function_exists('pa_kapselprobe')):
      $proben = all("SELECT * FROM pa_ereignis WHERE unter_charge_id=? AND typ='kapselprobe' ORDER BY id", [(int)$uc['id']]);
      $saecke = all("SELECT * FROM pa_ereignis WHERE unter_charge_id=? AND typ='sackgewicht' ORDER BY id", [(int)$uc['id']]);
      $tara = erp_wa_tara(); ?>
  <div class="panel wa-panel">
    <div class="step-sub">Kapselgewicht (Stichproben je 10 Kapseln)</div>
    <form method="post" class="wa-zeile"><input type="hidden" name="aktion" value="wa_kapselprobe"><input type="hidden" name="pa_id" value="<?= (int)$id ?>"><input type="hidden" name="anzahl" value="10">
      <input type="text" inputmode="decimal" name="gramm" placeholder="Gewicht 10 Kapseln (g)" style="width:280px"><button class="btn btn-ghost" type="submit">Probe speichern</button></form>
    <div class="muted" style="font-size:16px"><?= count($proben) ?> Probe(n)<?= $uc['kapsel_mg'] ? ' · Ø ' . h($mz($uc['kapsel_mg'])) . ' mg je Kapsel' : '' ?></div>
    <?php if ($uc['kapsel_mg']): ?>
    <div class="step-sub" style="margin-top:14px">Sack wiegen – Kapseln = (Sack − Tara) ÷ Ø-Gewicht</div>
    <form method="post" class="wa-zeile"><input type="hidden" name="aktion" value="wa_sack"><input type="hidden" name="pa_id" value="<?= (int)$id ?>">
      <input type="text" inputmode="decimal" name="brutto" placeholder="Sack brutto (kg)" style="width:220px">
      <select name="tara" onchange="this.form.tara_name.value=this.options[this.selectedIndex].text"><?php foreach ($tara as $t): ?><option value="<?= h((string)$t['tara_kg']) ?>"><?= h((string)$t['name']) ?> (<?= h($mz($t['tara_kg'])) ?> kg)</option><?php endforeach; ?></select>
      <input type="hidden" name="tara_name" value="<?= h((string)($tara[0]['name'] ?? '')) ?>"><button class="btn btn-ghost" type="submit">Sack speichern</button></form>
    <div class="muted" style="font-size:16px"><?= count($saecke) ?> Sack/Säcke · Kapseln dieser Charge aus Gewicht: <?= $mz($uc['kapseln_bulk'] ?? 0) ?> Stk<?= $kap['je_packung'] ? ' (reicht für ca. ' . $mz(floor((float)($uc['kapseln_bulk'] ?? 0) / max(1, $kap['je_packung']))) . ' Gläser à ' . $kap['je_packung'] . ')' : '' ?></div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <div class="panel wa-panel">
    <div class="step-sub">Kartons – je vollem Karton ein Etikett (Charge <?= h((string)$uc['charge_nr']) ?>)</div>
    <form method="post" class="wa-zeile"><input type="hidden" name="aktion" value="wa_karton"><input type="hidden" name="pa_id" value="<?= (int)$id ?>">
      <input type="text" inputmode="numeric" name="menge" value="<?= $kVor > 0 ? (int)$kVor : '' ?>" placeholder="Stk im Karton" style="width:200px"><span>Stk</span><button class="btn btn-primary" type="submit">Karton drucken</button></form>
    <div class="wa-liste"><?php foreach (array_reverse(array_slice(array_reverse($kartons), 0, 8)) as $k): ?><div><span>Karton <?= (int)$k['nummer'] ?> · <?= $mz($k['menge']) ?> Stk</span><span class="muted"><?= $k['teilmenge_id'] ? 'gebucht' : 'noch nicht gebucht' ?></span></div><?php endforeach; ?></div>
  </div>

  <div class="panel wa-panel">
    <div class="step-sub">Teilmenge fertig</div>
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="aktion" value="wa_teilmenge"><input type="hidden" name="pa_id" value="<?= (int)$id ?>">
      <div class="wa-zeile"><input type="text" inputmode="numeric" name="menge" value="<?= $vorK > 0 ? (int)$vorK : '' ?>" placeholder="Menge" style="width:200px"><span>Stk (Gläser/Packungen)<?= $vorK > 0 ? ' – aus ' . count($offenK) . ' Karton(s)' : '' ?></span></div>
      <div class="wa-zeile"><input type="text" inputmode="numeric" name="rueckstell" value="5" style="width:120px"><span>Rückstellmuster</span></div>
      <div class="wa-zeile">Charge <b><?= h((string)$uc['charge_nr']) ?></b> · MHD <b><?= h($fdate($uc['mhd'])) ?></b> (vorgegeben)</div>
      <div class="wa-pflicht">Foto der fertigen Ware <input type="file" name="foto_ware" accept="image/*" capture="environment" required></div>
      <button class="btn btn-lime btn-lg" type="submit">Teilmenge buchen</button>
    </form>
    <?php if ($tms): ?><div class="wa-liste" style="margin-top:10px"><?php foreach ($tms as $t): ?><div><span><?= h(fmt_zeit((string)$t['angelegt'])) ?> · <?= h((string)$t['mitarbeiter']) ?></span><span><?= $mz($t['menge']) ?> Stk<?= $t['umfang'] === 'vor_etikett' ? ' (vor Etikett)' : '' ?></span></div><?php endforeach; ?></div><?php endif; ?>
  </div>

  <div class="panel wa-panel">
    <div class="wa-zeile">
      <a class="btn btn-ghost" href="?p=werk&id=<?= (int)$id ?>&wechsel=1">Bulk leer – Chargenwechsel</a>
    </div>
    <form method="post" class="wa-zeile" onsubmit="return confirm('Produktion abschließen?')">
      <input type="hidden" name="aktion" value="wa_abschluss"><input type="hidden" name="pa_id" value="<?= (int)$id ?>">
      <?php if ($rest > 0): ?>
        <span>Noch <?= $mz($rest) ?> Stk offen:</span>
        <select name="rest"><option value="kommt">Rest kommt noch (Auftrag bleibt offen)</option><option value="storniert">Rest storniert</option></select>
      <?php else: ?><input type="hidden" name="rest" value="voll"><?php endif; ?>
      <button class="btn btn-primary" type="submit">Produktion abschließen</button>
    </form>
  </div>
<?php endif; ?>

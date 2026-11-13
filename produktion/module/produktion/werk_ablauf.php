<?php
// Werk-App, geführter Ablauf – Ansicht (Nico 11.11.2026, Teil 4). Eingebunden in werk.php (Schritt-Ansicht), wenn der nächste
// offene Schritt Abfüllen/Verpacken/Etikettieren ist. Erwartet: $id (PA), $pa, $T, $werkName, $istAdminW.
// Ablauf: Start (Bulk scannen -> Charge/MHD groß -> Haken „Linie leer“ + Pflichtfoto Stempelbild) -> Material scannen -> Kapselprobe/Sack ->
// Kartons drucken -> „Teilmenge fertig" -> Chargenwechsel (Bulk leer) -> Abschluss. Wiederanlauf: erneut Haken und Stempelfoto.
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
  <div class="wa-sperre"><?= h(sprintf($T['wa_label_blocked'], werk_msg((string)($machbar['grund'] ?: $T['wa_label_blocked_default']), $lang))) ?></div>
<?php endif; ?>

<?php if ($zeigeStart):
    $bulkCode = (string)($_GET['bulk'] ?? '');
    $s = $bulkCode !== '' ? erp_wa_scan($bulkCode) : null;
    // Wiederanlauf an einem neuen Tag: gleicher Bulk, aber nächster Buchstabe (Nico 12.11.2026) – pa_vorgabe prüft den Produktionstag.
    $v = $wiederanlauf && !$wechsel ? pa_vorgabe($id, (int)$uc['bulk_charge_id'], (int)$uc['prod_charge_id']) : ($s ? pa_vorgabe($id, (int)$s['charge_id'], (int)$s['prod_charge_id']) : null);
    if ($wiederanlauf && !$wechsel && !$s) {   // Wiederanlauf: Bulk der laufenden Unter-Charge vorbelegen
        $bulkCode = $uc['bulk_charge_id'] ? '?id=' . (int)$uc['bulk_charge_id'] : (string) scalar("SELECT CONCAT(nummer, COALESCE(sub_kennung,'')) FROM prod_charge WHERE id=?", [(int)$uc['prod_charge_id']]);
        $s = erp_wa_scan($bulkCode);
    }
    $linien = array_values(array_filter(array_map('trim', explode(',', (string) (scalar("SELECT v FROM app_meta WHERE k='produktion_linien'") ?: 'Linie 1,Linie 2')))));
    $linieVor = (string) scalar("SELECT linie FROM produktionsauftrag WHERE id=?", [$id]);
?>
  <div class="panel wa-panel">
    <div class="step-sub"><?= h($wiederanlauf && !$wechsel ? $T['wa_restart'] : ($uc ? $T['wa_change'] : $T['wa_start'])) ?></div>
    <?php if (!$s): ?>
      <div class="step-h"><?= h($T['wa_scan_bulk']) ?></div>
      <div class="muted" style="font-size:17px"><?= h($T['wa_scan_bulk_hint']) ?></div>
      <form method="get" class="wa-zeile"><input type="hidden" name="p" value="werk"><input type="hidden" name="id" value="<?= (int)$id ?>"><?php if ($wechsel): ?><input type="hidden" name="wechsel" value="1"><?php endif; ?>
        <input type="text" name="bulk" autofocus placeholder="<?= h($T['wa_scan_ph']) ?>" style="flex:1;min-width:240px"><button class="btn btn-primary" type="submit"><?= h($T['wa_next']) ?></button></form>
    <?php else: ?>
      <div class="muted" style="font-size:16px">Bulk: <?= h($s['name']) ?> · <?= h($s['charge_nr']) ?><?= $s['mhd'] ? ' · ' . h($T['wa_bbd']) . ' ' . h($fdate($s['mhd'])) : '' ?></div>
      <div style="margin:14px 0 6px" class="muted"><?= h($T['wa_today']) ?></div>
      <div class="wa-gross"><?= h($T['wa_batch']) ?> <?= h((string)$v['charge_nr']) ?> · <?= h($T['wa_bbd']) ?> <?= h($fdate($v['mhd'])) ?></div>
      <div style="font-size:19px;margin:6px 0 14px"><?= h($T['wa_stamp_set']) ?></div>
      <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="aktion" value="wa_start"><input type="hidden" name="pa_id" value="<?= (int)$id ?>"><input type="hidden" name="bulk_code" value="<?= h($bulkCode) ?>">
        <div class="wa-zeile"><span><?= h($T['wa_line']) ?></span><select name="linie"><?php foreach ($linien as $l): ?><option<?= $l === $linieVor ? ' selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></div>
        <div class="wa-pflicht"><label style="display:flex;gap:12px;align-items:center;cursor:pointer"><input type="checkbox" name="linie_leer" value="1" required style="width:28px;height:28px"> <?= h($T['wa_line_empty']) ?></label></div>
        <div class="wa-pflicht"><?= h(sprintf($T['wa_stamp_check'], (string)$v['charge_nr'], $fdate($v['mhd']))) ?> – <b><?= h($T['wa_photo_stamp']) ?></b> <input type="file" name="foto_stempel" accept="image/*" capture="environment" required></div>
        <?php if (!empty($v['neu'])): ?>
        <details style="margin:10px 0"><summary class="muted" style="cursor:pointer"><?= h($T['wa_deviate']) ?></summary>
          <div class="wa-zeile"><input type="text" name="charge_nr" placeholder="<?= h((string)$v['charge_nr']) ?>"></div>
          <div class="wa-zeile pa-grund" data-vorgabe="<?= h((string)$v['charge_nr']) ?>"><input type="text" name="grund" placeholder="<?= h($T['wa_reason']) ?>" style="flex:1"></div></details>
        <?= pa_grund_js() /* 13.11.2026: Grund erst, wenn die Charge geändert wurde */ ?>
        <?php endif; ?>
        <?php if (isset($_GET['bulk']) && erp_wa_aeltere_bulk((int)$s['item_id'], (int)$s['charge_id'], (string)$s['mhd']) !== '' && $istAdminW): ?>
          <label class="wa-zeile"><input type="checkbox" name="aelter_ok" value="1"> <?= h($T['wa_admin_younger']) ?></label>
          <div class="wa-zeile"><input type="text" name="aelter_grund" placeholder="<?= h($T['wa_reason']) ?>" style="flex:1"></div>
        <?php endif; ?>
        <button class="btn btn-lime btn-lg" type="submit"><?= h($wiederanlauf && !$wechsel ? $T['wa_continue'] : $T['wa_start']) ?></button>
      </form>
      <div style="margin-top:10px"><a class="back" href="?p=werk&id=<?= (int)$id ?><?= $wechsel ? '&wechsel=1' : '' ?>"><?= h($T['wa_other_bulk']) ?></a><?php if ($wechsel): ?> <a class="back" href="?p=werk&id=<?= (int)$id ?>"><?= h($T['wa_cancel']) ?></a><?php endif; ?></div>
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
    <div class="muted"><?= h($T['wa_running']) ?></div>
    <div class="wa-gross"><?= h($T['wa_batch']) ?> <?= h((string)$uc['charge_nr']) ?> · <?= h($T['wa_bbd']) ?> <?= h($fdate($uc['mhd'])) ?></div>
    <div style="font-size:18px;margin-top:6px"><?= h(sprintf($T['wa_running_info'], $mz($gebucht), $mz($pa['menge']), $mz($rest), $mz(pa_uc_menge((int)$uc['id'])))) ?></div>
  </div>

  <div class="panel wa-panel">
    <div class="step-sub"><?= h($T['wa_mat_scan']) ?></div>
    <div class="muted" style="font-size:16px"><?= h($T['wa_mat_hint']) ?></div>
    <form method="post" class="wa-zeile"><input type="hidden" name="aktion" value="wa_scan"><input type="hidden" name="pa_id" value="<?= (int)$id ?>">
      <input type="text" name="code" placeholder="<?= h($T['wa_scan_ph']) ?>" style="flex:1;min-width:240px"><button class="btn btn-primary" type="submit">OK</button></form>
    <div class="wa-liste"><?php foreach (array_slice($scans, 0, 8) as $sc): ?><div><span><?= h($T['wa_r_' . $sc['rolle']] ?? $sc['rolle']) ?> · <?= h((string)($sc['name'] ?? $sc['code'])) ?></span><span class="muted"><?= h((string)($sc['cnr'] ?? '')) ?></span></div><?php endforeach; ?></div>
  </div>

  <?php if ($kap['kapsel'] && function_exists('pa_kapselprobe')):
      $proben = all("SELECT * FROM pa_ereignis WHERE unter_charge_id=? AND typ='kapselprobe' ORDER BY id", [(int)$uc['id']]);
      $saecke = all("SELECT * FROM pa_ereignis WHERE unter_charge_id=? AND typ='sackgewicht' ORDER BY id", [(int)$uc['id']]);
      $tara = erp_wa_tara(); ?>
  <div class="panel wa-panel">
    <div class="step-sub"><?= h($T['wa_capw']) ?></div>
    <form method="post" class="wa-zeile"><input type="hidden" name="aktion" value="wa_kapselprobe"><input type="hidden" name="pa_id" value="<?= (int)$id ?>"><input type="hidden" name="anzahl" value="10">
      <input type="text" inputmode="decimal" name="gramm" placeholder="<?= h($T['wa_capw_ph']) ?>" style="width:280px"><button class="btn btn-ghost" type="submit"><?= h($T['wa_save_sample']) ?></button></form>
    <div class="muted" style="font-size:16px"><?= h(sprintf($T['wa_samples'], count($proben))) ?><?= $uc['kapsel_mg'] ? h(sprintf($T['wa_avg'], $mz($uc['kapsel_mg']))) : '' ?></div>
    <?php if ($uc['kapsel_mg']): ?>
    <div class="step-sub" style="margin-top:14px"><?= h($T['wa_bag_h']) ?></div>
    <form method="post" class="wa-zeile"><input type="hidden" name="aktion" value="wa_sack"><input type="hidden" name="pa_id" value="<?= (int)$id ?>">
      <input type="text" inputmode="decimal" data-gewicht="kg" name="brutto" placeholder="<?= h($T['wa_bag_ph']) ?>" style="width:220px">
      <select name="tara_id"><?php foreach ($tara as $t): ?><option value="<?= (int)$t['id'] ?>"><?= h((string)$t['name']) ?> (<?= h($mz($t['tara_kg'])) ?> kg)</option><?php endforeach; ?><option value="ohne"><?= h($T['wa_tara_ohne']) ?></option></select>
      <button class="btn btn-ghost" type="submit"><?= h($T['wa_save_bag']) ?></button></form>
    <div class="muted" style="font-size:16px"><?= h(sprintf($T['wa_bags_info'], count($saecke), $mz($uc['kapseln_bulk'] ?? 0))) ?><?= $kap['je_packung'] ? h(sprintf($T['wa_bags_jars'], $mz(floor((float)($uc['kapseln_bulk'] ?? 0) / max(1, $kap['je_packung']))), $kap['je_packung'])) : '' ?></div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <div class="panel wa-panel">
    <div class="step-sub"><?= h(sprintf($T['wa_cartons_h'], (string)$uc['charge_nr'])) ?></div>
    <form method="post" class="wa-zeile"><input type="hidden" name="aktion" value="wa_karton"><input type="hidden" name="pa_id" value="<?= (int)$id ?>">
      <input type="text" inputmode="numeric" name="menge" value="<?= $kVor > 0 ? (int)$kVor : '' ?>" placeholder="<?= h($T['wa_pcs_in_carton']) ?>" style="width:200px"><span><?= h($T['wa_pcs']) ?></span><button class="btn btn-primary" type="submit"><?= h($T['wa_print_carton']) ?></button></form>
    <div class="wa-liste"><?php foreach (array_reverse(array_slice(array_reverse($kartons), 0, 8)) as $k): ?><div><span><?= h($T['wa_carton']) ?> <?= (int)$k['nummer'] ?> · <?= $mz($k['menge']) ?> <?= h($T['wa_pcs']) ?></span><span class="muted"><?= h($k['teilmenge_id'] ? $T['wa_booked'] : $T['wa_not_booked']) ?></span></div><?php endforeach; ?></div>
  </div>

  <div class="panel wa-panel">
    <div class="step-sub"><?= h($T['wa_partial_done']) ?></div>
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="aktion" value="wa_teilmenge"><input type="hidden" name="pa_id" value="<?= (int)$id ?>">
      <div class="wa-zeile"><input type="text" inputmode="numeric" name="menge" value="<?= $vorK > 0 ? (int)$vorK : '' ?>" placeholder="<?= h($T['wa_qty']) ?>" style="width:200px"><span><?= h($T['wa_pcs_jars']) ?><?= $vorK > 0 ? h(sprintf($T['wa_from_cartons'], count($offenK))) : '' ?></span></div>
      <div class="wa-zeile"><input type="text" inputmode="numeric" name="rueckstell" value="5" style="width:120px"><span><?= h($T['wa_retain']) ?></span></div>
      <div class="wa-zeile"><?= h($T['wa_batch']) ?> <b><?= h((string)$uc['charge_nr']) ?></b> · <?= h($T['wa_bbd']) ?> <b><?= h($fdate($uc['mhd'])) ?></b> <?= h($T['wa_given']) ?></div>
      <div class="wa-pflicht"><?= h($T['wa_photo_goods']) ?> <input type="file" name="foto_ware" accept="image/*" capture="environment" required></div>
      <button class="btn btn-lime btn-lg" type="submit"><?= h($T['wa_book_partial']) ?></button>
    </form>
    <?php if ($tms): ?><div class="wa-liste" style="margin-top:10px"><?php foreach ($tms as $t): ?><div><span><?= h(fmt_zeit((string)$t['angelegt'])) ?> · <?= h((string)$t['mitarbeiter']) ?></span><span><?= $mz($t['menge']) ?> <?= h($T['wa_pcs']) ?><?= $t['umfang'] === 'vor_etikett' ? h($T['wa_before_label']) : '' ?></span></div><?php endforeach; ?></div><?php endif; ?>
  </div>

  <div class="panel wa-panel">
    <div class="wa-zeile">
      <a class="btn btn-ghost" href="?p=werk&id=<?= (int)$id ?>&wechsel=1"><?= h($T['wa_bulk_empty']) ?></a>
    </div>
    <form method="post" class="wa-zeile" onsubmit="return confirm(<?= h(json_encode($T['wa_confirm_close'], JSON_UNESCAPED_UNICODE)) ?>)">
      <input type="hidden" name="aktion" value="wa_abschluss"><input type="hidden" name="pa_id" value="<?= (int)$id ?>">
      <?php if ($rest > 0): ?>
        <span><?= h(sprintf($T['wa_still_open'], $mz($rest))) ?></span>
        <select name="rest" data-norscombo><option value="kommt"><?= h($T['wa_rest_comes']) ?></option><option value="storniert"><?= h($T['wa_rest_cancel']) ?></option></select>
      <?php else: ?><input type="hidden" name="rest" value="voll"><?php endif; ?>
      <button class="btn btn-primary" type="submit"><?= h($T['wa_close']) ?></button>
    </form>
  </div>
<?php endif; ?>

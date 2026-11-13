<?php
// „Produktion melden (KI)“ (Nico 13.11.2026, Werk-App Teil 4) – Eingabe (Text/Diktat), Auswahl bei mehreren PAs, Bestätigungs-Karte.
// Eingebunden auf ?p=produktion (pki_ui_post(0, …) vor der Ausgabe, pki_ui_render(0) oben) und auf jeder PA-Seite (mit PA-Vorgabe).
// Gebucht wird nur über das Formular „Teilmenge nachtragen“ der PA-Seite (aktion=nachtragen -> pa_nachtrag_buchen); hier nie.
require_once BX_ROOT . '/core/produktion_ki.php';

function pki_ui_post(int $pa_vorgabe, string $zurueck): void {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !pki_darf()) return;
    $a = (string)($_POST['aktion'] ?? '');
    if (!in_array($a, ['ki_melden', 'ki_wahl', 'ki_verwerfen'], true)) return;
    $k = 'pki_' . $pa_vorgabe;
    if ($a === 'ki_verwerfen') { unset($_SESSION[$k]); header('Location: ' . $zurueck . '#kimelden'); exit; }
    if ($a === 'ki_melden') {
        $_SESSION[$k] = pki_meldung((string)($_POST['ki_text'] ?? ''), $pa_vorgabe);
    } else {   // ki_wahl: Mensch wählt einen der passenden PAs -> Karte für genau diesen
        $alt = $_SESSION[$k] ?? null;
        if (is_array($alt) && !empty($alt['ok'])) {
            $wahl = (int)($_POST['pa_id'] ?? 0);
            $kand = array_values(array_filter((array)$alt['kandidaten'], fn($r) => (int)$r['id'] === $wahl));
            if ($kand) { $alt['kandidaten'] = $kand; $alt['karte'] = pki_karte((array)$alt['felder'], $kand[0]); $alt['info'] = []; $_SESSION[$k] = $alt; }
        }
    }
    header('Location: ' . $zurueck . '#kimelden'); exit;
}

function pki_ui_render(int $pa_vorgabe): void {
    if (!pki_darf()) return;
    $r = $_SESSION['pki_' . $pa_vorgabe] ?? null;
    $nf = fn($x) => number_format((float)$x, 0, ',', '.');
    $nachtragLink = $pa_vorgabe > 0 ? '?p=produktionsauftrag&id=' . $pa_vorgabe . '&nachtragen=1#nachtragen' : '?p=produktion&tab=laufend';
    ?>
<div class="bx-panel" id="kimelden">
  <h2 style="margin-top:0">Produktion melden (KI)</h2>
  <form method="post" class="pki-form" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-start">
    <input type="hidden" name="aktion" value="ki_melden">
    <textarea name="ki_text" id="pkiText" rows="2" required style="flex:1;min-width:260px" placeholder="z. B. „Ashwagandha 43.092 Stück, Charge 2766.B, mit Etikett“"><?= h((string)($r['text'] ?? '')) ?></textarea>
    <div class="bx-row" style="gap:8px">
      <button type="button" class="btn btn-ghost" id="pkiMic" hidden title="Diktieren (Browser)">Diktieren</button>
      <button class="btn btn-primary" type="submit">Auswerten</button>
    </div>
  </form>
  <p class="muted" style="font-size:12px;margin:6px 0 0">Die KI schlägt nur vor. Gebucht wird erst, wenn Sie die Karte geprüft und „Buchen“ geklickt haben.</p>
  <?php if (is_array($r)): ?>
  <div style="margin-top:14px">
    <?php if (empty($r['ok'])): ?>
      <div class="badge-warn" style="padding:10px 12px;border-radius:8px"><?= h((string)$r['fehler']) ?> Bitte direkt über <a href="<?= h($nachtragLink) ?>">„Teilmenge nachtragen“</a> buchen.</div>
    <?php elseif (!$r['kandidaten'] && !empty($r['info']['pa_nicht_offen'])): // Fable-5 M5: genannte Nummer ohne offenen Treffer ?>
      <div style="color:var(--err);padding:10px 0"><?= h((string)$r['info']['pa_nicht_offen']) ?> ist nicht offen (erledigt, storniert, voll produziert oder nicht vorhanden) – es wird nichts automatisch gewählt. Bitte im <a href="?p=produktion">Plan</a> prüfen.</div>
    <?php elseif (!$r['kandidaten'] && !empty($r['info']['pa_anders'])): ?>
      <div style="color:var(--err);padding:10px 0">Der Text nennt <?= h((string)$r['info']['pa_anders']) ?>, diese Seite gehört aber zu einem anderen Produktionsauftrag – bitte auf der richtigen Seite melden.</div>
    <?php elseif (!$r['kandidaten']): ?>
      <div class="badge-warn" style="padding:10px 12px;border-radius:8px">Kein offener Produktionsauftrag passt zu „<?= h((string)($r['felder']['produkt'] ?: $r['felder']['pa_nummer'] ?: $r['text'])) ?>“. Bitte im <a href="?p=produktion">Plan</a> prüfen oder am Auftrag nachtragen.</div>
    <?php elseif (empty($r['karte'])): ?>
      <div style="color:var(--err);margin-bottom:8px"><?= !empty($r['info']['ohne_zahl']) ? 'Kein Produkt heißt genau „' . h((string)$r['felder']['produkt']) . '“ – meinten Sie einen dieser Aufträge? Bitte den richtigen wählen:' : 'Mehrere Produktionsaufträge passen – bitte den richtigen wählen:' ?></div>
      <form method="post"><input type="hidden" name="aktion" value="ki_wahl">
        <?php foreach ($r['kandidaten'] as $kd): ?>
        <label style="display:flex;gap:10px;align-items:center;padding:8px 0;border-top:1px solid var(--line);cursor:pointer">
          <input type="radio" name="pa_id" value="<?= (int)$kd['id'] ?>" required style="width:auto">
          <span><b style="font-weight:600"><?= h((string)$kd['nummer']) ?></b> · <?= h((string)$kd['produkt']) ?><?= $kd['kunde'] ? ' · ' . h((string)$kd['kunde']) : '' ?> · offen <?= $nf($kd['rest']) ?> Stk</span>
        </label>
        <?php endforeach; ?>
        <div class="bx-row" style="gap:8px;margin-top:10px"><button class="btn btn-primary" type="submit">Diesen Auftrag nehmen</button></div>
      </form>
    <?php else: $c = $r['karte']; $pa = $c['pa']; $f = $r['felder']; ?>
      <div class="muted" style="font-size:13px;margin-bottom:8px">Erkannt: <?= h(implode(' · ', array_filter([
          $f['produkt'] ?: null, $f['kunde'] ?: null, $f['menge'] !== null ? $nf($f['menge']) . ($f['einheit'] ? ' ' . ['packung' => 'Packungen', 'kapsel' => 'Kapseln', 'kg' => 'kg'][$f['einheit']] : ' (Einheit offen)') : null,
          $f['charge'] ? 'Charge ' . $f['charge'] : null, $f['bulk_charge'] ? 'Bulk ' . $f['bulk_charge'] : null,
          $f['etikettiert'] === true ? 'mit Etikett' : ($f['etikettiert'] === false ? 'ohne Etikett' : null), $f['datum'] ?: null]))) ?></div>
      <div style="margin-bottom:8px"><b style="font-weight:600"><?= h((string)$pa['nummer']) ?></b> · <?= h((string)$pa['produkt']) ?><?= $pa['kunde'] ? ' · ' . h((string)$pa['kunde']) : '' ?> · offen <?= $nf($c['rest']) ?> Stk</div>
      <?php foreach ($c['fragen'] as $q): ?><div style="color:var(--err);margin:4px 0"><?= h($q) ?></div><?php endforeach; ?>
      <?php foreach ($c['hinweise'] as $q): ?><div class="muted" style="margin:4px 0;color:#b7791f"><?= h($q) ?></div><?php endforeach; ?>
      <form method="post" action="?p=produktionsauftrag&id=<?= (int)$pa['id'] ?>" style="display:flex;flex-direction:column;gap:10px;margin-top:10px">
        <input type="hidden" name="aktion" value="nachtragen">
        <input type="hidden" name="ki_karte" value="<?= h((string)($c['kid'] ?? '')) ?>"><?php /* Fable-5 T1.1: Text, Melder und Rückfragen liegen in der Sitzung */ ?>
        <?php if (!empty($c['lesart_pflicht'])): ?>
          <div><?php foreach ($c['lesarten'] as $l): ?>
            <label style="display:flex;gap:8px;align-items:center;cursor:pointer;<?= $l['zu_viel'] ? 'color:var(--err)' : '' ?>"><input type="radio" name="ki_lesart" value="<?= (int)$l['wert'] ?>" required style="width:auto" onchange="this.form.menge.value=this.value"> <?= h($l['text']) ?><?= $l['zu_viel'] ? ' – mehr als der offene Rest' : '' ?></label>
          <?php endforeach; ?></div>
        <?php endif; ?>
        <div class="bx-row" style="gap:12px;flex-wrap:wrap">
          <div class="bx-field" style="width:160px"><label>Produktionsdatum</label><input type="date" name="datum" value="<?= h($c['datum']) ?>" required></div>
          <div class="bx-field" style="width:150px"><label>Menge (Stk)</label><input type="text" inputmode="numeric" name="menge" required value="<?= count($c['lesarten']) === 1 && empty($c['lesart_pflicht']) && ($c['menge_vorbelegen'] ?? true) ? h(zahl_feld($c['lesarten'][0]['wert'], 0)) : '' ?>" placeholder="max. <?= $nf($c['rest']) ?>"></div>
          <div class="bx-field" style="flex:1;min-width:220px"><label>Bulk-Charge</label>
            <select name="bulk_charge_id"<?= !empty($c['bulk_frage']) ? ' style="border-color:var(--err)"' : '' ?>><option value="0"<?= (int)$c['bulk_id'] === 0 ? ' selected' : '' ?>>– bitte wählen –</option><?php foreach ($c['bulk_wahl'] as $b): ?><option value="<?= (int)$b['id'] ?>"<?= (int)$b['id'] === (int)$c['bulk_id'] ? ' selected' : '' ?>><?= h(($b['charge_nr'] ?: '#' . $b['id']) . ' · ' . $nf($b['menge_verfuegbar']) . ' ' . $b['einheit'] . ($b['mhd'] ? ' · MHD ' . date('d.m.Y', strtotime((string)$b['mhd'])) : '')) ?></option><?php endforeach; ?></select></div>
        </div>
        <div class="bx-row" style="gap:12px;flex-wrap:wrap">
          <div class="bx-field" style="width:170px"><label>Charge</label><input type="text" name="charge_nr" value="<?= h($c['charge']) ?>"><input type="hidden" name="charge_vorschlag" value="<?= h($c['charge_vorschlag']) ?>"></div>
          <div class="bx-field pa-grund" data-vorgabe="<?= h($c['charge_vorschlag']) ?>" style="flex:1;min-width:220px"><label>Grund für die andere Charge</label><input type="text" name="grund" style="border-color:var(--err)" placeholder="Pflicht: Charge weicht vom Vorschlag ab"></div>
          <div class="bx-field" style="width:170px"><label>MHD</label><input type="date" name="mhd" value="<?= h($c['mhd']) ?>"></div>
        </div>
        <label style="display:flex;gap:8px;align-items:center;cursor:pointer<?= !empty($c['bulk_frage']) ? ';color:var(--err)' : '' ?>"><input type="checkbox" name="ohne_bulk" value="1" style="width:auto"<?= !empty($c['ohne_bulk']) ? ' checked' : '' ?>> ohne Bulk buchen (kein Bulk-Bezug) – nur wenn wirklich kein Bulk dazugehört</label>
        <?= pa_grund_js() ?>
        <div class="bx-row" style="gap:12px;flex-wrap:wrap;align-items:flex-end">
          <div class="bx-field" style="flex:1;min-width:200px"><label>Mitarbeiter</label><input type="text" name="mitarbeiter" value="<?= h($c['mitarbeiter']) ?>"></div>
          <div class="bx-field" style="width:240px"><label>Umfang</label><select name="umfang">
            <?php if ($c['etikett_sperre'] === ''): ?><option value="komplett"<?= !$c['vor_etikett'] ? ' selected' : '' ?>>komplett (mit Etikett)</option><?php endif; ?>
            <option value="vor_etikett"<?= $c['vor_etikett'] ? ' selected' : '' ?>>bis vor dem Etikettieren</option></select></div>
          <div class="bx-field" style="width:140px"><label>Rückstellmuster</label><input type="text" inputmode="numeric" name="rueckstell" value="0"></div>
        </div>
        <?php $kiRot = !empty($c['fragen']); $kiHart = (string)($c['charge_fremd'] ?? '') !== ''; ?>
        <?php if ($kiRot && !$kiHart): ?>
        <label style="display:flex;gap:8px;align-items:center;cursor:pointer;color:var(--err)"><input type="checkbox" name="fragen_geklaert" value="1" style="width:auto" onchange="this.form.querySelector('.pki-buchen').disabled=!this.checked"> Rote Rückfragen geprüft und geklärt</label>
        <?php endif; ?>
        <div class="bx-row" style="gap:8px">
          <button class="btn btn-primary pki-buchen" type="submit"<?= $kiRot ? ' disabled' : '' ?><?= $kiHart ? ' title="Charge gehört zu einem anderen Auftrag – so wird nicht gebucht."' : '' ?> onclick="return confirm('Jetzt buchen? Gebucht wird wie bei „Teilmenge nachtragen“.');">Buchen</button>
        </div>
      </form>
      <form method="post" style="margin-top:6px"><input type="hidden" name="aktion" value="ki_verwerfen"><button class="btn btn-ghost btn-sm" type="submit">Verwerfen</button></form>
    <?php endif; ?>
  </div>
  <?php endif; ?>
  <script>
  (function(){
    var SR = window.SpeechRecognition || window.webkitSpeechRecognition, b = document.getElementById('pkiMic'), t = document.getElementById('pkiText');
    if (!SR || !b || !t) return;
    b.hidden = false;
    b.addEventListener('click', function(){
      var r = new SR(); r.lang = 'de-DE'; r.interimResults = false; r.maxAlternatives = 1;
      b.textContent = 'Hört zu …';
      r.onresult = function(e){ t.value = (t.value ? t.value + ' ' : '') + e.results[0][0].transcript; };
      r.onend = function(){ b.textContent = 'Diktieren'; };
      r.start();
    });
  })();
  </script>
</div>
<?php
}

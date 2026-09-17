<?php
// CRM-Demo – eigenständige App (Route ?p=crmdemo&m=<modul>). Nur crmdemo_*-Tabellen (isoliert).
// Module: dashboard | kunden | produktentwickler | angebote | rechnungen | produktion | finanzen.
require_once BX_ROOT . '/core/crmdemo.php';

crmdemo_schema();
crmdemo_seed();   // beim ersten Aufruf Beispieldaten (nur wenn leer)

$m   = preg_replace('/[^a-z_]/', '', (string)($_GET['m'] ?? 'dashboard')) ?: 'dashboard';
$akt = $_POST['aktion'] ?? '';
$eur = fn($c) => number_format((int)$c / 100, 2, ',', '.') . ' €';
$cent = fn($s) => (int) round((float) str_replace(',', '.', (string)$s) * 100);

// ---------------- POST-Handler (vor jeder Ausgabe) ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($akt === 'kunde_save') {
        q("INSERT INTO crmdemo_kunde (firma,ansprechpartner,email,telefon,land,notiz) VALUES (?,?,?,?,?,?)",
          [trim($_POST['firma'] ?? '') ?: '(ohne Namen)', trim($_POST['ansprechpartner'] ?? '') ?: null,
           trim($_POST['email'] ?? '') ?: null, trim($_POST['telefon'] ?? '') ?: null, trim($_POST['land'] ?? '') ?: null, trim($_POST['notiz'] ?? '') ?: null]);
        header('Location: ' . cd_url('kunden')); exit;
    }
    if ($akt === 'kunde_del') { q("DELETE FROM crmdemo_kunde WHERE id=?", [(int)($_POST['id'] ?? 0)]); header('Location: ' . cd_url('kunden')); exit; }

    if ($akt === 'produkt_ki') {
        require_once BX_ROOT . '/core/ki.php';
        $name = trim($_POST['name'] ?? ''); $form = trim($_POST['form'] ?? 'kapsel'); $idee = trim($_POST['idee'] ?? '');
        $konzept = null;
        if (function_exists('ki_bereit') && ki_bereit()) {
            $r = ki_json('Produktidee: ' . $idee . '. Darreichungsform: ' . $form . '. Erzeuge ein kompaktes Produktkonzept für ein Nahrungsergänzungsmittel.',
                ['system' => 'Du bist Produktentwickler für Nahrungsergänzungsmittel. Antworte auf ' . (cd_lang() === 'zh' ? 'Chinesisch' : 'Deutsch') . '. Gib NUR JSON: {"name":"","kurzbeschreibung":"","zutaten":[{"name":"","menge_mg":0}],"hinweise":""}. Erfinde keine unzulässigen Heilaussagen.']);
            if (!empty($r['ok']) && !empty($r['daten'])) $konzept = json_encode($r['daten'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        }
        q("INSERT INTO crmdemo_produkt (name,form,idee,konzept) VALUES (?,?,?,?)", [$name ?: ($idee !== '' ? mb_substr($idee, 0, 60) : '(Idee)'), $form, $idee ?: null, $konzept]);
        header('Location: ' . cd_url('produktentwickler')); exit;
    }
    if ($akt === 'produkt_del') { q("DELETE FROM crmdemo_produkt WHERE id=?", [(int)($_POST['id'] ?? 0)]); header('Location: ' . cd_url('produktentwickler')); exit; }

    if ($akt === 'angebot_save') {
        $netto = 0; $bez = (array)($_POST['p_bez'] ?? []); $mng = (array)($_POST['p_menge'] ?? []); $pr = (array)($_POST['p_preis'] ?? []);
        q("INSERT INTO crmdemo_angebot (nummer,kunde_id,titel,netto_cent,status) VALUES (?,?,?,0,'offen')",
          [cd_nummer('AN'), (int)($_POST['kunde_id'] ?? 0) ?: null, trim($_POST['titel'] ?? '') ?: 'Angebot']);
        $aid = insert_id(); $sort = 0;
        foreach ($bez as $i => $b) {
            $b = trim((string)$b); if ($b === '') continue;
            $mn = (float) str_replace(',', '.', (string)($mng[$i] ?? '1')) ?: 1; $pc = $cent($pr[$i] ?? '0');
            q("INSERT INTO crmdemo_angebot_pos (angebot_id,bezeichnung,menge,einheit,preis_cent,sort) VALUES (?,?,?,?,?,?)", [$aid, $b, $mn, 'Stk.', $pc, $sort++]);
            $netto += (int) round($mn * $pc);
        }
        q("UPDATE crmdemo_angebot SET netto_cent=? WHERE id=?", [$netto, $aid]);
        header('Location: ' . cd_url('angebote', ['id' => $aid])); exit;
    }
    if ($akt === 'angebot_del') { $id=(int)($_POST['id']??0); q("DELETE FROM crmdemo_angebot_pos WHERE angebot_id=?", [$id]); q("DELETE FROM crmdemo_angebot WHERE id=?", [$id]); header('Location: ' . cd_url('angebote')); exit; }

    if ($akt === 'rechnung_aus_angebot') {
        $a = one("SELECT * FROM crmdemo_angebot WHERE id=?", [(int)($_POST['angebot_id'] ?? 0)]);
        if ($a) {
            $ust = 19.0; $netto = (int)$a['netto_cent']; $brutto = (int) round($netto * (1 + $ust / 100));
            q("INSERT INTO crmdemo_rechnung (nummer,kunde_id,angebot_id,netto_cent,ust_prozent,brutto_cent,status,datum) VALUES (?,?,?,?,?,?, 'offen', CURDATE())",
              [cd_nummer('RE'), $a['kunde_id'], $a['id'], $netto, $ust, $brutto]);
            q("UPDATE crmdemo_angebot SET status='angenommen' WHERE id=?", [(int)$a['id']]);
        }
        header('Location: ' . cd_url('rechnungen')); exit;
    }
    if ($akt === 'rechnung_status') { q("UPDATE crmdemo_rechnung SET status=? WHERE id=?", [($_POST['status'] ?? 'offen') === 'bezahlt' ? 'bezahlt' : 'offen', (int)($_POST['id'] ?? 0)]); header('Location: ' . cd_url('rechnungen')); exit; }

    if ($akt === 'produktion_save') {
        q("INSERT INTO crmdemo_produktion (kunde_id,produkt_id,titel,menge,status) VALUES (?,?,?,?, 'geplant')",
          [(int)($_POST['kunde_id'] ?? 0) ?: null, (int)($_POST['produkt_id'] ?? 0) ?: null, trim($_POST['titel'] ?? '') ?: 'Charge', (int)($_POST['menge'] ?? 0)]);
        header('Location: ' . cd_url('produktion')); exit;
    }
    if ($akt === 'produktion_status') { $s=$_POST['status']??'geplant'; q("UPDATE crmdemo_produktion SET status=? WHERE id=?", [in_array($s,['geplant','in_produktion','fertig'],true)?$s:'geplant', (int)($_POST['id']??0)]); header('Location: ' . cd_url('produktion')); exit; }
}

// ---------------- Render ----------------
cd_head(cd_t($m));
cd_shell_start($m);
$badge = fn($s) => bx_badge($s === 'bezahlt' || $s === 'fertig' ? cd_t($s === 'bezahlt' ? 'bezahlt' : 'fertig') : ($s === 'in_produktion' ? cd_t('in_produktion') : ($s === 'angenommen' ? 'angenommen' : cd_t($s === 'geplant' ? 'geplant' : 'offen'))), $s === 'bezahlt' || $s === 'fertig' ? 'ok' : ($s === 'in_produktion' || $s === 'angenommen' ? 'info' : 'warn'));

if ($m === 'dashboard'):
    $z = crmdemo_kennzahlen(); ?>
    <h1 style="margin-bottom:4px"><?= h(cd_t('willkommen')) ?></h1>
    <p class="bx-sub"><?= h(cd_t('demo_hinweis')) ?></p>
    <div class="bx-cards">
      <div class="bx-card"><div class="k"><?= h(cd_t('anzahl_kunden')) ?></div><div class="v"><?= (int)$z['kunden'] ?></div></div>
      <div class="bx-card"><div class="k"><?= h(cd_t('anzahl_angebote')) ?></div><div class="v"><?= (int)$z['angebote'] ?></div></div>
      <div class="bx-card"><div class="k"><?= h(cd_t('umsatz_offen')) ?></div><div class="v"><?= $eur($z['offen']) ?></div></div>
      <div class="bx-card"><div class="k"><?= h(cd_t('umsatz_bezahlt')) ?></div><div class="v"><?= $eur($z['bezahlt']) ?></div></div>
      <div class="bx-card"><div class="k"><?= h(cd_t('produktion')) ?></div><div class="v"><?= (int)$z['produktion'] ?></div></div>
    </div>
    <div class="bx-panel" style="margin-top:16px">
      <div class="bx-row" style="gap:10px;flex-wrap:wrap">
        <a class="btn btn-primary" href="<?= h(cd_url('kunden')) ?>"><?= h(cd_t('kunden')) ?></a>
        <a class="btn btn-ghost" href="<?= h(cd_url('produktentwickler')) ?>"><?= h(cd_t('produktentwickler')) ?></a>
        <a class="btn btn-ghost" href="<?= h(cd_url('angebote')) ?>"><?= h(cd_t('angebote')) ?></a>
        <a class="btn btn-ghost" href="<?= h(cd_url('rechnungen')) ?>"><?= h(cd_t('rechnungen')) ?></a>
      </div>
    </div>

<?php elseif ($m === 'kunden'): ?>
    <h1 style="margin-bottom:12px"><?= h(cd_t('kunden')) ?></h1>
    <div class="bx-panel">
      <div class="bx-tablewrap"><table class="bx-table">
        <thead><tr><th><?= h(cd_t('firma')) ?></th><th><?= h(cd_t('ansprechpartner')) ?></th><th><?= h(cd_t('email')) ?></th><th><?= h(cd_t('land')) ?></th><th></th></tr></thead>
        <tbody>
        <?php $ks = all("SELECT * FROM crmdemo_kunde ORDER BY firma"); if (!$ks): ?><tr><td colspan="5" class="muted"><?= h(cd_t('keine_daten')) ?></td></tr><?php endif;
        foreach ($ks as $k): ?>
          <tr><td><?= h($k['firma']) ?><?= $k['notiz'] ? '<div class="muted" style="font-size:12px">' . h($k['notiz']) . '</div>' : '' ?></td>
            <td><?= h((string)$k['ansprechpartner']) ?></td><td><?= h((string)$k['email']) ?></td><td><?= h((string)$k['land']) ?></td>
            <td style="text-align:right"><form method="post" style="margin:0" onsubmit="return confirm('?')"><input type="hidden" name="aktion" value="kunde_del"><input type="hidden" name="id" value="<?= (int)$k['id'] ?>"><button class="btn btn-ghost btn-sm" type="submit">×</button></form></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    </div>
    <div class="bx-panel">
      <h2 style="margin-top:0"><?= h(cd_t('neu')) ?></h2>
      <form method="post"><input type="hidden" name="aktion" value="kunde_save">
        <div class="bx-grid">
          <div class="bx-field"><label><?= h(cd_t('firma')) ?></label><input type="text" name="firma" required></div>
          <div class="bx-field"><label><?= h(cd_t('ansprechpartner')) ?></label><input type="text" name="ansprechpartner"></div>
          <div class="bx-field"><label><?= h(cd_t('email')) ?></label><input type="email" name="email"></div>
          <div class="bx-field"><label><?= h(cd_t('telefon')) ?></label><input type="text" name="telefon"></div>
          <div class="bx-field"><label><?= h(cd_t('land')) ?></label><input type="text" name="land" placeholder="DE"></div>
          <div class="bx-field"><label><?= h(cd_t('notiz')) ?></label><input type="text" name="notiz"></div>
        </div>
        <div class="bx-row" style="margin-top:10px"><button class="btn btn-primary" type="submit"><?= h(cd_t('anlegen')) ?></button></div>
      </form>
    </div>

<?php elseif ($m === 'produktentwickler'):
    require_once BX_ROOT . '/core/ki.php';
    $kiBereit = function_exists('ki_bereit') && ki_bereit(); ?>
    <h1 style="margin-bottom:4px"><?= h(cd_t('produktentwickler')) ?></h1>
    <?php if (!$kiBereit): ?><div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:10px 14px"><?= h(cd_t('ki_nicht_bereit')) ?></div><?php endif; ?>
    <div class="bx-panel">
      <h2 style="margin-top:0"><?= h(cd_t('neu')) ?></h2>
      <form method="post"><input type="hidden" name="aktion" value="produkt_ki">
        <div class="bx-grid">
          <div class="bx-field"><label><?= h(cd_t('name')) ?></label><input type="text" name="name" placeholder="z. B. Immun Boost"></div>
          <div class="bx-field"><label><?= h(cd_t('form')) ?></label>
            <select name="form"><?php foreach (['kapsel'=>'Kapsel','tablette'=>'Tablette','pulver'=>'Pulver','fluessig'=>'Flüssig'] as $k=>$v): ?><option value="<?= $k ?>"><?= h($v) ?></option><?php endforeach; ?></select></div>
        </div>
        <div class="bx-field"><label><?= h(cd_t('idee')) ?></label><textarea name="idee" rows="2" placeholder="Ziel, Wirkung, Zielgruppe …"></textarea></div>
        <div class="bx-row" style="margin-top:10px"><button class="btn btn-primary" type="submit" data-busy="…"><?= h(cd_t('entwickeln')) ?></button></div>
      </form>
    </div>
    <?php foreach (all("SELECT * FROM crmdemo_produkt ORDER BY id DESC") as $pr):
        $kz = $pr['konzept'] ? json_decode((string)$pr['konzept'], true) : null; ?>
    <div class="bx-panel">
      <div class="bx-row" style="justify-content:space-between;align-items:center">
        <h2 style="margin:0"><?= h($pr['name']) ?> <span class="muted" style="font-weight:400;font-size:13px"><?= h((string)$pr['form']) ?></span></h2>
        <form method="post" style="margin:0" onsubmit="return confirm('?')"><input type="hidden" name="aktion" value="produkt_del"><input type="hidden" name="id" value="<?= (int)$pr['id'] ?>"><button class="btn btn-ghost btn-sm" type="submit">×</button></form>
      </div>
      <?php if ($pr['idee']): ?><p class="muted" style="margin:4px 0"><?= h($pr['idee']) ?></p><?php endif; ?>
      <?php if (is_array($kz)): ?>
        <?php if (!empty($kz['kurzbeschreibung'])): ?><p style="margin:6px 0"><?= h($kz['kurzbeschreibung']) ?></p><?php endif; ?>
        <?php if (!empty($kz['zutaten']) && is_array($kz['zutaten'])): ?>
        <table class="bx-table"><thead><tr><th><?= h(cd_t('name')) ?></th><th class="bx-num">mg</th></tr></thead><tbody>
          <?php foreach ($kz['zutaten'] as $z): ?><tr><td><?= h((string)($z['name'] ?? '')) ?></td><td class="bx-num"><?= h((string)($z['menge_mg'] ?? '')) ?></td></tr><?php endforeach; ?>
        </tbody></table><?php endif; ?>
        <?php if (!empty($kz['hinweise'])): ?><p class="muted" style="font-size:12px;margin-top:6px"><?= h($kz['hinweise']) ?></p><?php endif; ?>
      <?php elseif ($pr['konzept']): ?><pre style="white-space:pre-wrap;font-size:12px"><?= h((string)$pr['konzept']) ?></pre><?php endif; ?>
    </div>
    <?php endforeach; ?>

<?php elseif ($m === 'angebote'):
    $detail = (int)($_GET['id'] ?? 0);
    if ($detail && ($a = one("SELECT a.*, k.firma FROM crmdemo_angebot a LEFT JOIN crmdemo_kunde k ON k.id=a.kunde_id WHERE a.id=?", [$detail]))): ?>
      <div class="bx-row" style="justify-content:space-between;align-items:center">
        <h1 style="margin:0"><?= h($a['titel']) ?> <span class="muted" style="font-size:14px"><?= h((string)$a['nummer']) ?></span></h1>
        <a class="btn btn-ghost btn-sm" href="<?= h(cd_url('angebote')) ?>">←</a>
      </div>
      <p class="bx-sub"><?= h((string)($a['firma'] ?? '')) ?> · <?= $badge($a['status']) ?></p>
      <div class="bx-panel"><div class="bx-tablewrap"><table class="bx-table">
        <thead><tr><th><?= h(cd_t('position')) ?></th><th class="bx-num"><?= h(cd_t('menge')) ?></th><th class="bx-num"><?= h(cd_t('preis')) ?></th><th class="bx-num"><?= h(cd_t('summe')) ?></th></tr></thead>
        <tbody>
        <?php foreach (all("SELECT * FROM crmdemo_angebot_pos WHERE angebot_id=? ORDER BY sort,id", [$detail]) as $p): ?>
          <tr><td><?= h($p['bezeichnung']) ?></td><td class="bx-num"><?= rtrim(rtrim(number_format((float)$p['menge'],2,',','.'),'0'),',') ?></td><td class="bx-num"><?= $eur($p['preis_cent']) ?></td><td class="bx-num"><?= $eur((int) round((float)$p['menge'] * (int)$p['preis_cent'])) ?></td></tr>
        <?php endforeach; ?>
          <tr style="font-weight:600"><td colspan="3"><?= h(cd_t('netto')) ?></td><td class="bx-num"><?= $eur($a['netto_cent']) ?></td></tr>
        </tbody></table></div>
        <?php if ($a['status'] !== 'angenommen'): ?>
        <form method="post" style="margin-top:10px"><input type="hidden" name="aktion" value="rechnung_aus_angebot"><input type="hidden" name="angebot_id" value="<?= (int)$a['id'] ?>">
          <button class="btn btn-primary" type="submit"><?= h(cd_t('rechnung_aus')) ?></button></form>
        <?php endif; ?>
      </div>
    <?php else: ?>
    <h1 style="margin-bottom:12px"><?= h(cd_t('angebote')) ?></h1>
    <div class="bx-panel"><div class="bx-tablewrap"><table class="bx-table">
      <thead><tr><th><?= h(cd_t('nummer')) ?></th><th><?= h(cd_t('kunde')) ?></th><th><?= h(cd_t('titel')) ?></th><th class="bx-num"><?= h(cd_t('netto')) ?></th><th><?= h(cd_t('status')) ?></th><th></th></tr></thead>
      <tbody>
      <?php $as = all("SELECT a.*, k.firma FROM crmdemo_angebot a LEFT JOIN crmdemo_kunde k ON k.id=a.kunde_id ORDER BY a.id DESC"); if (!$as): ?><tr><td colspan="6" class="muted"><?= h(cd_t('keine_daten')) ?></td></tr><?php endif;
      foreach ($as as $a): ?>
        <tr><td><a href="<?= h(cd_url('angebote', ['id'=>(int)$a['id']])) ?>"><?= h((string)$a['nummer']) ?></a></td><td><?= h((string)($a['firma'] ?? '')) ?></td><td><?= h((string)$a['titel']) ?></td><td class="bx-num"><?= $eur($a['netto_cent']) ?></td><td><?= $badge($a['status']) ?></td>
          <td style="text-align:right"><form method="post" style="margin:0" onsubmit="return confirm('?')"><input type="hidden" name="aktion" value="angebot_del"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>"><button class="btn btn-ghost btn-sm" type="submit">×</button></form></td></tr>
      <?php endforeach; ?>
      </tbody></table></div></div>
    <div class="bx-panel">
      <h2 style="margin-top:0"><?= h(cd_t('neu')) ?></h2>
      <form method="post"><input type="hidden" name="aktion" value="angebot_save">
        <div class="bx-grid">
          <div class="bx-field"><label><?= h(cd_t('kunde')) ?></label><select name="kunde_id"><option value="">–</option>
            <?php foreach (all("SELECT id,firma FROM crmdemo_kunde ORDER BY firma") as $k): ?><option value="<?= (int)$k['id'] ?>"><?= h($k['firma']) ?></option><?php endforeach; ?></select></div>
          <div class="bx-field"><label><?= h(cd_t('titel')) ?></label><input type="text" name="titel" placeholder="Angebot"></div>
        </div>
        <label style="display:block;margin:10px 0 6px"><?= h(cd_t('position')) ?></label>
        <div class="bx-tablewrap"><table class="bx-table" id="postab"><thead><tr><th><?= h(cd_t('position')) ?></th><th style="width:120px"><?= h(cd_t('menge')) ?></th><th style="width:140px"><?= h(cd_t('preis')) ?> (€)</th></tr></thead>
          <tbody id="posrows">
          <?php for ($i=0;$i<3;$i++): ?><tr><td><input type="text" name="p_bez[]"></td><td><input type="number" step="0.01" name="p_menge[]" value="1"></td><td><input type="number" step="0.01" name="p_preis[]"></td></tr><?php endfor; ?>
          </tbody></table></div>
        <button type="button" class="btn btn-ghost btn-sm" id="addrow"><?= h(cd_t('position_hinzu')) ?></button>
        <div class="bx-row" style="margin-top:10px"><button class="btn btn-primary" type="submit"><?= h(cd_t('anlegen')) ?></button></div>
      </form>
      <script>document.getElementById('addrow').addEventListener('click',function(){var tb=document.getElementById('posrows');var tr=document.createElement('tr');tr.innerHTML='<td><input type="text" name="p_bez[]"></td><td><input type="number" step="0.01" name="p_menge[]" value="1"></td><td><input type="number" step="0.01" name="p_preis[]"></td>';tb.appendChild(tr);});</script>
    </div>
    <?php endif; ?>

<?php elseif ($m === 'rechnungen'): ?>
    <h1 style="margin-bottom:12px"><?= h(cd_t('rechnungen')) ?></h1>
    <div class="bx-panel"><div class="bx-tablewrap"><table class="bx-table">
      <thead><tr><th><?= h(cd_t('nummer')) ?></th><th><?= h(cd_t('kunde')) ?></th><th><?= h(cd_t('datum')) ?></th><th class="bx-num"><?= h(cd_t('netto')) ?></th><th class="bx-num"><?= h(cd_t('brutto')) ?></th><th><?= h(cd_t('status')) ?></th><th></th></tr></thead>
      <tbody>
      <?php $rs = all("SELECT r.*, k.firma FROM crmdemo_rechnung r LEFT JOIN crmdemo_kunde k ON k.id=r.kunde_id ORDER BY r.id DESC"); if (!$rs): ?><tr><td colspan="7" class="muted"><?= h(cd_t('keine_daten')) ?></td></tr><?php endif;
      foreach ($rs as $r): ?>
        <tr><td><?= h((string)$r['nummer']) ?></td><td><?= h((string)($r['firma'] ?? '')) ?></td><td><?= h((string)$r['datum']) ?></td><td class="bx-num"><?= $eur($r['netto_cent']) ?></td><td class="bx-num"><?= $eur($r['brutto_cent']) ?></td><td><?= $badge($r['status']) ?></td>
          <td style="text-align:right"><form method="post" style="margin:0"><input type="hidden" name="aktion" value="rechnung_status"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="status" value="<?= $r['status']==='bezahlt'?'offen':'bezahlt' ?>"><button class="btn btn-ghost btn-sm" type="submit"><?= $r['status']==='bezahlt'?h(cd_t('offen')):h(cd_t('bezahlt')) ?></button></form></td></tr>
      <?php endforeach; ?>
      </tbody></table></div></div>

<?php elseif ($m === 'produktion'): ?>
    <h1 style="margin-bottom:12px"><?= h(cd_t('produktion')) ?></h1>
    <div class="bx-panel"><div class="bx-tablewrap"><table class="bx-table">
      <thead><tr><th><?= h(cd_t('titel')) ?></th><th><?= h(cd_t('kunde')) ?></th><th class="bx-num"><?= h(cd_t('menge')) ?></th><th><?= h(cd_t('status')) ?></th><th></th></tr></thead>
      <tbody>
      <?php $ps = all("SELECT pr.*, k.firma FROM crmdemo_produktion pr LEFT JOIN crmdemo_kunde k ON k.id=pr.kunde_id ORDER BY pr.id DESC"); if (!$ps): ?><tr><td colspan="5" class="muted"><?= h(cd_t('keine_daten')) ?></td></tr><?php endif;
      foreach ($ps as $pr): $next = ['geplant'=>'in_produktion','in_produktion'=>'fertig','fertig'=>'geplant'][$pr['status']] ?? 'geplant'; ?>
        <tr><td><?= h((string)$pr['titel']) ?></td><td><?= h((string)($pr['firma'] ?? '')) ?></td><td class="bx-num"><?= (int)$pr['menge'] ?></td><td><?= $badge($pr['status']) ?></td>
          <td style="text-align:right"><form method="post" style="margin:0"><input type="hidden" name="aktion" value="produktion_status"><input type="hidden" name="id" value="<?= (int)$pr['id'] ?>"><input type="hidden" name="status" value="<?= $next ?>"><button class="btn btn-ghost btn-sm" type="submit">→ <?= h(cd_t($next==='in_produktion'?'in_produktion':($next==='fertig'?'fertig':'geplant'))) ?></button></form></td></tr>
      <?php endforeach; ?>
      </tbody></table></div></div>
    <div class="bx-panel">
      <h2 style="margin-top:0"><?= h(cd_t('neu')) ?></h2>
      <form method="post"><input type="hidden" name="aktion" value="produktion_save">
        <div class="bx-grid">
          <div class="bx-field"><label><?= h(cd_t('titel')) ?></label><input type="text" name="titel" placeholder="Charge / Produkt"></div>
          <div class="bx-field"><label><?= h(cd_t('kunde')) ?></label><select name="kunde_id"><option value="">–</option><?php foreach (all("SELECT id,firma FROM crmdemo_kunde ORDER BY firma") as $k): ?><option value="<?= (int)$k['id'] ?>"><?= h($k['firma']) ?></option><?php endforeach; ?></select></div>
          <div class="bx-field"><label><?= h(cd_t('menge')) ?></label><input type="number" name="menge" value="1000"></div>
        </div>
        <div class="bx-row" style="margin-top:10px"><button class="btn btn-primary" type="submit"><?= h(cd_t('anlegen')) ?></button></div>
      </form>
    </div>

<?php elseif ($m === 'finanzen'):
    $z = crmdemo_kennzahlen(); ?>
    <h1 style="margin-bottom:12px"><?= h(cd_t('finanzen')) ?></h1>
    <div class="bx-cards">
      <div class="bx-card"><div class="k"><?= h(cd_t('umsatz_offen')) ?></div><div class="v"><?= $eur($z['offen']) ?></div></div>
      <div class="bx-card"><div class="k"><?= h(cd_t('umsatz_bezahlt')) ?></div><div class="v"><?= $eur($z['bezahlt']) ?></div></div>
      <div class="bx-card"><div class="k"><?= h(cd_t('summe')) ?></div><div class="v"><?= $eur($z['offen'] + $z['bezahlt']) ?></div></div>
    </div>
    <div class="bx-panel" style="margin-top:16px"><div class="bx-tablewrap"><table class="bx-table">
      <thead><tr><th><?= h(cd_t('nummer')) ?></th><th><?= h(cd_t('kunde')) ?></th><th class="bx-num"><?= h(cd_t('brutto')) ?></th><th><?= h(cd_t('status')) ?></th></tr></thead>
      <tbody>
      <?php foreach (all("SELECT r.*, k.firma FROM crmdemo_rechnung r LEFT JOIN crmdemo_kunde k ON k.id=r.kunde_id ORDER BY r.status, r.id DESC") as $r): ?>
        <tr><td><?= h((string)$r['nummer']) ?></td><td><?= h((string)($r['firma'] ?? '')) ?></td><td class="bx-num"><?= $eur($r['brutto_cent']) ?></td><td><?= $badge($r['status']) ?></td></tr>
      <?php endforeach; ?>
      </tbody></table></div></div>

<?php endif;
cd_shell_ende();

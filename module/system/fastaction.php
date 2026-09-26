<?php
// Fastaction – KI-gestuetzter Schnell-Posteingang. Kurze, wichtige Nachricht (oft Kundenanfrage) + optional
// Datei/Bild reinwerfen. Das System (1) legt automatisch eine Aufgabe an, (2) schlaegt konkrete naechste
// Schritte vor und (3) speichert alles als persistente Fastaction-Notiz mit abhakbaren ToDo-Items (Notepad),
// damit nach dem Auswerten nichts verloren geht. Erkennt es eine unbekannte Rezeptur, kann man sie als
// Entwurf anlegen.
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';
require_once BX_ROOT . '/core/fastaction.php';

$uid = (function_exists('current_user') && ($cu = current_user())) ? (int)$cu['id'] : null;

// --- ToDo-/Notepad-Aktionen (Post-Redirect-Get) --------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'item_toggle' && ($iid = (int)($_POST['item_id'] ?? 0))) {
    $cur = (int) scalar("SELECT erledigt FROM fastaction_item WHERE id=?", [$iid]);
    q("UPDATE fastaction_item SET erledigt=?, erledigt_am=? WHERE id=?", [$cur ? 0 : 1, $cur ? null : gmdate('Y-m-d H:i:s'), $iid]);
    // Notiz automatisch auf erledigt, wenn alle Items erledigt sind (und mind. eins existiert).
    $nid = (int) scalar("SELECT notiz_id FROM fastaction_item WHERE id=?", [$iid]);
    if ($nid) {
        $offen = (int) scalar("SELECT COUNT(*) FROM fastaction_item WHERE notiz_id=? AND erledigt=0", [$nid]);
        $ges   = (int) scalar("SELECT COUNT(*) FROM fastaction_item WHERE notiz_id=?", [$nid]);
        q("UPDATE fastaction_notiz SET status=?, erledigt_am=? WHERE id=?",
          [($ges > 0 && $offen === 0) ? 'erledigt' : 'offen', ($ges > 0 && $offen === 0) ? gmdate('Y-m-d H:i:s') : null, $nid]);
    }
    header('Location: ?p=fastaction#n' . (int)$nid); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'item_add' && ($nid = (int)($_POST['notiz_id'] ?? 0))) {
    $t = trim((string)($_POST['text'] ?? ''));
    if ($t !== '') {
        $s = (int) scalar("SELECT COALESCE(MAX(sort),0)+1 FROM fastaction_item WHERE notiz_id=?", [$nid]);
        q("INSERT INTO fastaction_item (notiz_id,typ,text,sort) VALUES (?,?,?,?)", [$nid, 'sonstiges', mb_substr($t, 0, 500), $s]);
        q("UPDATE fastaction_notiz SET status='offen', erledigt_am=NULL WHERE id=?", [$nid]);
    }
    header('Location: ?p=fastaction#n' . (int)$nid); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'notiz_status' && ($nid = (int)($_POST['notiz_id'] ?? 0))) {
    $neu = ((string)($_POST['status'] ?? '') === 'erledigt') ? 'erledigt' : 'offen';
    q("UPDATE fastaction_notiz SET status=?, erledigt_am=? WHERE id=?", [$neu, $neu === 'erledigt' ? gmdate('Y-m-d H:i:s') : null, $nid]);
    header('Location: ?p=fastaction' . ($neu === 'erledigt' ? '' : '#n' . (int)$nid)); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'notiz_del' && ($nid = (int)($_POST['notiz_id'] ?? 0))) {
    q("DELETE FROM fastaction_item WHERE notiz_id=?", [$nid]);
    q("DELETE FROM fastaction_notiz WHERE id=?", [$nid]);
    header('Location: ?p=fastaction'); exit;
}
// Unbekannte Rezeptur als Entwurf anlegen (mit vorausgefuellten Zutaten-Zeilen) und direkt oeffnen.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'rezeptur_entwurf') {
    $zut = json_decode((string)($_POST['zutaten_json'] ?? '[]'), true);
    if (!is_array($zut)) $zut = [];
    $r = fastaction_rezeptur_entwurf((string)($_POST['name'] ?? ''), (string)($_POST['form'] ?? 'kapsel'), $zut, (string)($_POST['zutaten_text'] ?? ''));
    $rid = (int)$r['rezeptur_id'];
    // Optional an die Notiz haengen, wenn noch keine Rezeptur verknuepft ist.
    if (($nid = (int)($_POST['notiz_id'] ?? 0)) && $rid) q("UPDATE fastaction_notiz SET rezeptur_id=COALESCE(rezeptur_id,?) WHERE id=?", [$rid, $nid]);
    header('Location: ?p=rezeptur_detail&id=' . $rid . '&neu=1&fa_match=' . (int)$r['gematcht'] . '&fa_ges=' . (int)$r['gesamt']); exit;
}

$res = null; $auf = null; $eingabe = ''; $notizId = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'analysieren') {
    $eingabe = trim((string)($_POST['text'] ?? ''));
    $pfad = null; $origName = '';
    if (!empty($_FILES['datei']['name']) && (int)($_FILES['datei']['error'] ?? 1) === UPLOAD_ERR_OK) {
        if (!is_dir(BX_UPLOADS)) @mkdir(BX_UPLOADS, 0775, true);
        $ext = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', pathinfo((string)$_FILES['datei']['name'], PATHINFO_EXTENSION)));
        $fn  = 'fastaction_' . gmdate('Ymd_His') . '_' . bin2hex(random_bytes(4)) . ($ext ? '.' . $ext : '');
        if (move_uploaded_file($_FILES['datei']['tmp_name'], BX_UPLOADS . '/' . $fn)) { $pfad = BX_UPLOADS . '/' . $fn; $origName = (string)$_FILES['datei']['name']; }
    }

    if ($eingabe === '' && $pfad === null) {
        $res = ['ok' => false, 'fehler' => 'Bitte einen Text eingeben oder eine Datei hochladen.'];
    } else {
        $res = fastaction_analyse($eingabe, $pfad);
        $d = $res['ok'] ? $res['daten'] : [];
        // (1) Aufgabe automatisch anlegen – auch wenn die KI nicht verfuegbar war (Text nicht verlieren).
        $titel = trim((string)($d['aufgabe'] ?? '')) ?: ('Fastaction: ' . mb_substr($eingabe !== '' ? $eingabe : ($origName ?: 'Datei'), 0, 70));
        $prio  = $res['ok'] ? fastaction_prio((string)($d['dringlichkeit'] ?? 'mittel')) : 2;
        $besch = ($eingabe !== '' ? "Eingang:\n" . $eingabe . "\n\n" : '')
               . ($res['ok'] ? ('Zusammenfassung: ' . (string)($d['zusammenfassung'] ?? '') . "\n") : ('Hinweis: KI-Auswertung nicht verfuegbar (' . (string)($res['fehler'] ?? '') . ")\n"))
               . ($origName !== '' ? "Datei: " . $origName . "\n" : '');
        $aufgabeId = aufgabe_neu(mb_substr($titel, 0, 190), $besch, $prio, null, null, $uid, 'fastaction', 0);
        if ($pfad !== null && $aufgabeId) {
            q("INSERT INTO dokument (objekt_typ,objekt_id,typ,titel,datei,datei_orig) VALUES ('aufgabe',?,?,?,?,?)",
              [$aufgabeId, 'sonstiges', 'Fastaction-Anhang', basename($pfad), $origName ?: basename($pfad)]);
        }
        $auf = one("SELECT id, titel, prio FROM aufgabe WHERE id=?", [$aufgabeId]);
        // (3) Persistente Fastaction-Notiz + ToDo-Items (Notepad) – nur bei erfolgreicher Auswertung.
        if ($res['ok']) {
            $auf_e = fastaction_aufloesen((array)($d['erkannt'] ?? []));
            $notizId = fastaction_notiz_anlegen($d, $auf_e, $eingabe, $pfad, $origName, $uid);
        }
    }
}

// --- Notepad laden -------------------------------------------------------------------------------
$alle = isset($_GET['alle']);
$notizen = all("SELECT n.*, k.firma AS kunde, r.name AS rezeptur, p.name AS produkt
                FROM fastaction_notiz n
                LEFT JOIN kunden k ON k.id=n.kunde_id
                LEFT JOIN rezeptur r ON r.id=n.rezeptur_id
                LEFT JOIN produkt p ON p.id=n.produkt_id
                " . ($alle ? '' : "WHERE n.status='offen'") . "
                ORDER BY (n.status='offen') DESC, n.angelegt DESC LIMIT 100");
$itemsByNotiz = [];
if ($notizen) {
    $ids = implode(',', array_map(fn($n) => (int)$n['id'], $notizen));
    foreach (all("SELECT * FROM fastaction_item WHERE notiz_id IN ($ids) ORDER BY sort, id") as $it)
        $itemsByNotiz[(int)$it['notiz_id']][] = $it;
}
$offenGes = (int) scalar("SELECT COUNT(*) FROM fastaction_notiz WHERE status='offen'");

render_header('fastaction', 'Fastaction');
bx_head('Fastaction', 'Kurze, wichtige Nachricht reinwerfen – die KI fasst zusammen, legt eine Aufgabe an und schlägt die nächsten Schritte vor.');

$kiBereit = ki_bereit();
if (!$kiBereit) echo '<div class="bx-panel" style="border-color:#e6c4c0;padding:10px 14px;font-size:13px;color:#8f231b">Hinweis: Die <strong>KI-Auswertung läuft nur auf beta</strong> (Schlüssel serverseitig). Lokal wird die Nachricht nur als Aufgabe erfasst, ohne Vorschläge.</div>';
?>
<form method="post" enctype="multipart/form-data" class="bx-panel" data-busy="Nachricht wird ausgewertet …">
  <input type="hidden" name="aktion" value="analysieren">
  <div class="bx-field"><label>Nachricht / Notiz</label>
    <textarea name="text" rows="4" placeholder="z. B. „Kunde Pure Health will 1.000 Dosen von der Rezeptur XY nachbestellen – bitte Angebot."><?= h($eingabe) ?></textarea>
  </div>
  <div class="bx-field"><label>Datei oder Bild (optional)</label>
    <input type="file" name="datei" accept="application/pdf,image/*,.txt,.csv,.xlsx">
    <div class="muted" style="font-size:12px;margin-top:4px">PDF, Bild, Text oder Tabelle – z. B. ein Screenshot der Kundenmail.</div>
  </div>
  <div class="bx-row" style="margin-top:var(--sp-2)"><button class="btn btn-primary" type="submit">Auswerten</button></div>
</form>

<?php if ($res !== null): ?>
  <?php if ($auf): ?>
    <div class="bx-panel badge-ok" style="padding:12px 16px">
      <strong>Aufgabe angelegt:</strong> „<?= h($auf['titel']) ?>" <?= prio_badge((int)$auf['prio']) ?>
      &nbsp;<a href="?p=aufgaben">zu den Aufgaben</a><?php if ($notizId): ?> &middot; <a href="#n<?= (int)$notizId ?>">im Notepad ansehen</a><?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if (!$res['ok']): ?>
    <div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:12px 16px"><?= h((string)($res['fehler'] ?? 'Auswertung fehlgeschlagen.')) ?></div>
  <?php else: $d = $res['daten'];
    $auf_e = fastaction_aufloesen((array)($d['erkannt'] ?? []));
    $dr = strtolower((string)($d['dringlichkeit'] ?? 'mittel'));
    $drBadge = $dr === 'hoch' ? bx_badge('dringend', 'err') : ($dr === 'niedrig' ? bx_badge('niedrig', '') : bx_badge('mittel', 'warn'));
  ?>
    <div class="bx-panel">
      <h2 style="margin-top:0;font-size:16px">Auswertung <?= $drBadge ?></h2>
      <p style="margin:0 0 6px"><strong>Worum es geht:</strong> <?= h((string)($d['zusammenfassung'] ?? '–')) ?></p>
      <p style="margin:0"><strong>Zu tun:</strong> <?= h((string)($d['aufgabe'] ?? '–')) ?></p>

      <?php $er = (array)($d['erkannt'] ?? []); if (array_filter($er)): ?>
      <div class="bx-row" style="gap:16px;flex-wrap:wrap;margin-top:12px">
        <?php if (!empty($er['kunde'])): ?><div><div class="k muted" style="font-size:12px">Kunde</div><div><?php if ($auf_e['kunde']): ?><a href="?p=kunde&id=<?= (int)$auf_e['kunde']['id'] ?>"><?= h($auf_e['kunde']['firma']) ?></a><?php else: ?><?= h((string)$er['kunde']) ?> <span class="muted" style="font-size:12px">(nicht gefunden)</span><?php endif; ?></div></div><?php endif; ?>
        <?php if (!empty($er['rezeptur'])): ?><div><div class="k muted" style="font-size:12px">Rezeptur</div><div><?php if ($auf_e['rezeptur']): ?><a href="?p=rezeptur_detail&id=<?= (int)$auf_e['rezeptur']['id'] ?>"><?= h($auf_e['rezeptur']['name']) ?></a><?php else: ?><?= h((string)$er['rezeptur']) ?> <span class="muted" style="font-size:12px">(nicht gefunden)</span><?php endif; ?></div></div><?php endif; ?>
        <?php if (!empty($er['produkt'])): ?><div><div class="k muted" style="font-size:12px">Produkt</div><div><?php if ($auf_e['produkt']): ?><a href="?p=produkt&id=<?= (int)$auf_e['produkt']['id'] ?>"><?= h($auf_e['produkt']['name']) ?></a><?php else: ?><?= h((string)$er['produkt']) ?><?php endif; ?></div></div><?php endif; ?>
        <?php if (!empty($er['menge'])): ?><div><div class="k muted" style="font-size:12px">Menge</div><div><?= h((string)$er['menge']) ?> <?= h((string)($er['einheit'] ?? '')) ?></div></div><?php endif; ?>
      </div>
      <?php endif; ?>
    </div>

    <?php // Unbekannte Rezeptur(en) aus der Vorlage -> als Entwurf anlegen anbieten.
      $rezK = (array)($d['rezepturen'] ?? []);
      $rezNeu = [];
      foreach ($rezK as $rk) {
        $nm = trim((string)($rk['name'] ?? '')); if ($nm === '') continue;
        if (one("SELECT id FROM rezeptur WHERE name LIKE ? LIMIT 1", ['%' . $nm . '%'])) continue;   // schon vorhanden
        $rezNeu[] = $rk;
      }
      if ($rezNeu): ?>
    <div class="bx-panel" style="border-color:var(--gruen)">
      <h2 style="margin-top:0;font-size:16px">Rezeptur anlegen</h2>
      <p class="muted" style="margin-top:0">In der Vorlage erkannte Rezeptur(en), die es im System noch nicht gibt. Als Entwurf anlegen – die Zusammensetzung steht dann in der Notiz der Rezeptur zum Fertigbauen.</p>
      <?php foreach ($rezNeu as $rk): ?>
        <div class="bx-row" style="justify-content:space-between;align-items:flex-start;gap:10px;flex-wrap:wrap;padding:8px 0;border-top:1px solid var(--line)">
          <?php $zut = (array)($rk['zutaten'] ?? []); ?>
          <div style="flex:1 1 320px">
            <strong><?= h((string)$rk['name']) ?></strong> <span class="muted" style="font-size:12px"><?= h((string)($rk['darreichungsform'] ?? 'kapsel')) ?><?= $zut ? ' · ' . count($zut) . ' Zutat(en)' : '' ?></span>
            <?php if (!empty($rk['zutaten_text'])): ?><div class="muted" style="font-size:13px;margin-top:2px"><?= h((string)$rk['zutaten_text']) ?></div><?php endif; ?>
          </div>
          <form method="post" style="margin:0" data-busy="Lege an…">
            <input type="hidden" name="aktion" value="rezeptur_entwurf">
            <input type="hidden" name="name" value="<?= h((string)$rk['name']) ?>">
            <input type="hidden" name="form" value="<?= h((string)($rk['darreichungsform'] ?? 'kapsel')) ?>">
            <input type="hidden" name="zutaten_json" value="<?= h(json_encode($zut, JSON_UNESCAPED_UNICODE)) ?>">
            <input type="hidden" name="zutaten_text" value="<?= h((string)($rk['zutaten_text'] ?? '')) ?>">
            <?php if ($notizId): ?><input type="hidden" name="notiz_id" value="<?= (int)$notizId ?>"><?php endif; ?>
            <button class="btn btn-primary btn-sm" type="submit">Als Entwurf anlegen</button>
          </form>
        </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php $vs = (array)($d['vorschlaege'] ?? []); if ($vs): ?>
    <h2 style="margin:18px 0 8px;font-size:16px">Vorschläge</h2>
    <?php foreach ($vs as $v): $typ = (string)($v['typ'] ?? 'sonstiges'); ?>
      <div class="bx-panel" style="margin-bottom:10px">
        <div class="bx-row" style="justify-content:space-between;align-items:flex-start;gap:10px;flex-wrap:wrap">
          <div style="flex:1 1 300px"><?= bx_badge($typ, 'info') ?> <span style="margin-left:6px"><?= h((string)($v['text'] ?? '')) ?></span></div>
          <div class="bx-row" style="gap:8px">
            <?php if ($auf_e['kunde']): ?><a class="btn btn-ghost btn-sm" href="?p=kunde&id=<?= (int)$auf_e['kunde']['id'] ?>">Kunde öffnen</a><?php endif; ?>
            <?php if (in_array($typ, ['angebot','anfrage'], true) && $auf_e['rezeptur']): ?><a class="btn btn-ghost btn-sm" href="?p=rezeptur_detail&id=<?= (int)$auf_e['rezeptur']['id'] ?>">Rezeptur öffnen</a><?php endif; ?>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
    <?php endif; ?>
  <?php endif; ?>
<?php endif; ?>

<!-- Notepad: persistente ToDo-Listen je Fastaction-Anfrage -->
<div class="bx-row" style="justify-content:space-between;align-items:baseline;margin:22px 0 8px;flex-wrap:wrap;gap:8px">
  <h2 style="margin:0;font-size:16px">Notepad <span class="muted" style="font-weight:normal">(<?= $offenGes ?> offen)</span></h2>
  <a class="muted" style="font-size:13px" href="?p=fastaction<?= $alle ? '' : '&alle=1' ?>"><?= $alle ? 'nur offene zeigen' : 'auch erledigte zeigen' ?></a>
</div>
<?php if (!$notizen): ?>
  <div class="bx-panel"><div class="muted">Noch keine Fastaction-Notizen. Wertest du oben eine Nachricht aus, entsteht hier eine ToDo-Liste, die erhalten bleibt.</div></div>
<?php else: foreach ($notizen as $n): $items = $itemsByNotiz[(int)$n['id']] ?? [];
  $offen = 0; foreach ($items as $it) if (!(int)$it['erledigt']) $offen++;
  $erle = (string)$n['status'] === 'erledigt';
  $dn = strtolower((string)$n['dringlichkeit']);
  $dnB = $dn === 'hoch' ? bx_badge('dringend','err') : ($dn === 'niedrig' ? bx_badge('niedrig','') : bx_badge('mittel','warn'));
?>
  <div class="bx-panel" id="n<?= (int)$n['id'] ?>" style="margin-bottom:10px<?= $erle ? ';opacity:.6' : '' ?>">
    <div class="bx-row" style="justify-content:space-between;align-items:flex-start;gap:10px;flex-wrap:wrap">
      <div style="flex:1 1 320px">
        <div><?= $dnB ?> <strong style="margin-left:6px"><?= h($n['zusammenfassung'] ?: ($n['aufgabe_text'] ?: 'Fastaction')) ?></strong> <?= $erle ? bx_badge('erledigt','ok') : '' ?></div>
        <div class="muted" style="font-size:12px;margin-top:3px">
          <?= h(fmt_zeit($n['angelegt'], 'd.m.Y H:i')) ?>
          <?php if ($n['kunde']): ?> · Kunde: <a href="?p=kunde&id=<?= (int)$n['kunde_id'] ?>"><?= h($n['kunde']) ?></a><?php endif; ?>
          <?php if ($n['rezeptur']): ?> · Rezeptur: <a href="?p=rezeptur_detail&id=<?= (int)$n['rezeptur_id'] ?>"><?= h($n['rezeptur']) ?></a><?php endif; ?>
          <?php if ($n['produkt']): ?> · Produkt: <?= h($n['produkt']) ?><?php endif; ?>
          <?php if ($n['datei']): ?> · <a href="?p=dokument&id=0" onclick="return false" title="Anhang"><?= h($n['datei_orig'] ?: 'Anhang') ?></a><?php endif; ?>
        </div>
      </div>
      <div class="bx-row" style="gap:6px">
        <form method="post" style="margin:0"><input type="hidden" name="aktion" value="notiz_status"><input type="hidden" name="notiz_id" value="<?= (int)$n['id'] ?>"><input type="hidden" name="status" value="<?= $erle ? 'offen' : 'erledigt' ?>"><button class="btn btn-ghost btn-sm" type="submit"><?= $erle ? 'wieder offen' : 'alles erledigt' ?></button></form>
        <form method="post" style="margin:0" onsubmit="return confirm('Diese Fastaction-Notiz löschen?');"><input type="hidden" name="aktion" value="notiz_del"><input type="hidden" name="notiz_id" value="<?= (int)$n['id'] ?>"><button class="btn btn-ghost btn-sm" type="submit">Löschen</button></form>
      </div>
    </div>
    <div style="margin-top:10px;display:flex;flex-direction:column;gap:6px">
      <?php foreach ($items as $it): ?>
        <form method="post" style="margin:0;display:flex;gap:8px;align-items:flex-start">
          <input type="hidden" name="aktion" value="item_toggle"><input type="hidden" name="item_id" value="<?= (int)$it['id'] ?>">
          <button type="submit" title="Abhaken" style="border:1px solid var(--line);background:<?= (int)$it['erledigt'] ? 'var(--gruen,#2f6f4f)' : 'transparent' ?>;color:#fff;width:22px;height:22px;border-radius:6px;flex:none;cursor:pointer;line-height:1"><?= (int)$it['erledigt'] ? '✓' : '&nbsp;' ?></button>
          <span style="<?= (int)$it['erledigt'] ? 'text-decoration:line-through;color:var(--muted)' : '' ?>"><?= (int)$it['erledigt'] ? '' : bx_badge((string)$it['typ'], 'info') . ' ' ?><?= h((string)$it['text']) ?></span>
        </form>
      <?php endforeach; ?>
      <?php if (!$items): ?><div class="muted" style="font-size:13px">Keine Punkte – unten einen hinzufügen.</div><?php endif; ?>
    </div>
    <form method="post" class="bx-row" style="gap:6px;margin-top:8px;align-items:center">
      <input type="hidden" name="aktion" value="item_add"><input type="hidden" name="notiz_id" value="<?= (int)$n['id'] ?>">
      <input type="text" name="text" placeholder="ToDo hinzufügen…" style="flex:1;min-width:200px;padding:5px 8px">
      <button class="btn btn-ghost btn-sm" type="submit">+ Punkt</button>
    </form>
  </div>
<?php endforeach; endif; ?>
<?php render_footer(); ?>

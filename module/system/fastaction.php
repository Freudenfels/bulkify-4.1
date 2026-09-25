<?php
// Fastaction – KI-gestuetzter Schnell-Posteingang. Kurze, wichtige Nachricht (oft Kundenanfrage) + optional
// Datei/Bild reinwerfen. Das System (1) legt automatisch eine Aufgabe an (damit nichts verloren geht) und
// (2) schlaegt konkrete naechste Schritte im ERP vor (mit erkanntem Kunde/Rezeptur/Produkt/Menge).
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';
require_once BX_ROOT . '/core/fastaction.php';

$res = null; $aufgabeId = 0; $auf = null; $eingabe = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'analysieren') {
    $eingabe = trim((string)($_POST['text'] ?? ''));
    // Optionale Datei speichern (fuer die KI und als Anhang der Aufgabe).
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
        $uid = (function_exists('current_user') && ($cu = current_user())) ? (int)$cu['id'] : null;
        $aufgabeId = aufgabe_neu(mb_substr($titel, 0, 190), $besch, $prio, null, null, $uid, 'fastaction', 0);
        // Datei an die Aufgabe haengen (nachvollziehbar).
        if ($pfad !== null && $aufgabeId) {
            q("INSERT INTO dokument (objekt_typ,objekt_id,typ,titel,datei,datei_orig) VALUES ('aufgabe',?,?,?,?,?)",
              [$aufgabeId, 'sonstiges', 'Fastaction-Anhang', basename($pfad), $origName ?: basename($pfad)]);
        }
        $auf = one("SELECT id, titel, prio FROM aufgabe WHERE id=?", [$aufgabeId]);
    }
}

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
      &nbsp;<a href="?p=aufgaben">zu den Aufgaben</a>
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

    <?php $vs = (array)($d['vorschlaege'] ?? []); if ($vs): ?>
    <h2 style="margin:18px 0 8px;font-size:16px">Vorschläge</h2>
    <?php foreach ($vs as $v): $typ = (string)($v['typ'] ?? 'sonstiges'); ?>
      <div class="bx-panel" style="margin-bottom:10px">
        <div class="bx-row" style="justify-content:space-between;align-items:flex-start;gap:10px;flex-wrap:wrap">
          <div style="flex:1 1 300px"><?= bx_badge($typ, 'info') ?> <span style="margin-left:6px"><?= h((string)($v['text'] ?? '')) ?></span></div>
          <div class="bx-row" style="gap:8px">
            <?php // Hilfreiche Sprungziele je nach erkannter Entität – der Mensch entscheidet und legt an. ?>
            <?php if ($auf_e['kunde']): ?><a class="btn btn-ghost btn-sm" href="?p=kunde&id=<?= (int)$auf_e['kunde']['id'] ?>">Kunde öffnen</a><?php endif; ?>
            <?php if (in_array($typ, ['angebot','anfrage'], true) && $auf_e['rezeptur']): ?><a class="btn btn-ghost btn-sm" href="?p=rezeptur_detail&id=<?= (int)$auf_e['rezeptur']['id'] ?>">Rezeptur öffnen</a><?php endif; ?>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
    <?php endif; ?>
  <?php endif; ?>
<?php endif; ?>
<?php render_footer(); ?>

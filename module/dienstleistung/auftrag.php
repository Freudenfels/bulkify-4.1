<?php
// DL-Auftrag (Detail): Positionen, Status, DL-Rechnung (DR-) erstellen.
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';
require_once BX_ROOT . '/core/dienstleistung.php';

$id = (int)($_GET['id'] ?? 0);
$a  = $id ? dl_auftrag_laden($id) : null;
if (!$a) { header('Location: ?p=dl_auftraege'); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aktion = $_POST['aktion'] ?? '';
    if ($aktion === 'status') {
        $s = $_POST['status'] ?? '';
        if (in_array($s, ['offen','in_arbeit','erledigt'], true)) q("UPDATE auftrag SET status=? WHERE id=? AND kategorie='dienstleistung'", [$s, $id]);
        header('Location: ?p=dl_auftrag&id=' . $id . '&ok=1'); exit;
    }
    // DL-Auftrag stornieren (z. B. faelschlich angelegt). Setzt den Auftrag auf 'storniert' -> beim Kunden
    // ausgeblendet. Eine evtl. schon erstellte DL-Rechnung (DR-) muss separat in der Buchhaltung storniert
    // werden (Beleg gehoert der Buchhaltung; das Dashboard schreibt sie nicht).
    if ($aktion === 'storno') {
        q("UPDATE auftrag SET status='storniert' WHERE id=? AND kategorie='dienstleistung'", [$id]);
        log_aktivitaet('kunde', (int)($a['kunde_id'] ?? 0), 'team', 'DL-Auftrag ' . (string)$a['nummer'] . ' storniert.', 'auftrag', 'auftrag', $id);
        header('Location: ?p=dl_auftrag&id=' . $id . '&storniert=1'); exit;
    }
    if ($aktion === 'schritt') {               // aktuellen Fortschritts-Schritt setzen
        dl_auftrag_schritt_setzen($id, (int)($_POST['schritt_id'] ?? 0));
        header('Location: ?p=dl_auftrag&id=' . $id . '&ok=1'); exit;
    }
    // F3: einzelnen Schritt abhaken (toggle) – flexibler als „Stand setzen".
    if ($aktion === 'schritt_toggle') {
        dl_schritt_toggle($id, (int)($_POST['schritt_id'] ?? 0));
        header('Location: ?p=dl_auftrag&id=' . $id . '&ok=1#schritte'); exit;
    }
    // F3/F4: Schritt hinzufügen (nachträglich) mit optionalen internen Kosten je Stück.
    if ($aktion === 'schritt_add') {
        $kosten = trim((string)($_POST['kosten'] ?? '')) !== '' ? (float) str_replace(',', '.', str_replace('.', '', (string)$_POST['kosten'])) : null;
        dl_schritt_add($id, (string)($_POST['name'] ?? ''), $kosten, (string)(current_user()['name'] ?? ''));
        header('Location: ?p=dl_auftrag&id=' . $id . '&ok=1#schritte'); exit;
    }
    // F3/F4: Schritt umbenennen + interne Kosten bearbeiten.
    if ($aktion === 'schritt_edit') {
        $kosten = trim((string)($_POST['kosten'] ?? '')) !== '' ? (float) str_replace(',', '.', str_replace('.', '', (string)$_POST['kosten'])) : null;
        dl_schritt_edit((int)($_POST['schritt_id'] ?? 0), $id, (string)($_POST['name'] ?? ''), $kosten);
        header('Location: ?p=dl_auftrag&id=' . $id . '&ok=1#schritte'); exit;
    }
    // F3: Schritt entfernen.
    if ($aktion === 'schritt_remove') {
        dl_schritt_remove((int)($_POST['schritt_id'] ?? 0), $id);
        header('Location: ?p=dl_auftrag&id=' . $id . '&ok=1#schritte'); exit;
    }
    if ($aktion === 'schritte_sync') {         // Schritte (neu) aus dem Service-Katalog übernehmen
        $n = dl_auftrag_schritte_resync($id);
        header('Location: ?p=dl_auftrag&id=' . $id . ($n > 0 ? '&syncok=' . $n : '&syncleer=1')); exit;
    }
    if ($aktion === 'ergebnis_upload') {       // Endergebnis-Dokument hochladen (ggf. Abschluss + Kunden-Mail)
        $r = dl_ergebnis_upload($id);
        header('Location: ?p=dl_auftrag&id=' . $id . ($r['ok'] ? ('&ok=1' . (!empty($r['abgeschlossen']) ? '&done=1' : '')) : '&uperr=' . urlencode($r['msg']))); exit;
    }
    if ($aktion === 'rechnung') {
        $opt = [
            'zahlungsziel_tage' => $_POST['zahlungsziel_tage'] ?? '',
            'freigeben'         => isset($_POST['freigeben']) ? 1 : 0,
            'ersteller'         => current_user()['name'] ?? 'team',
        ];
        $bid = dl_rechnung_aus_auftrag($id, $opt);
        header('Location: ' . ($bid ? '/buchhaltung/?p=rechnung&id=' . $bid : '?p=dl_auftrag&id=' . $id . '&fehler=1')); exit;
    }
}

$pos = dl_positionen((int)$a['angebot_id']);
// Altbestand selbstheilen: fehlende Service-Verknüpfung nachtragen + Schritte aus dem Katalog anlegen (idempotent).
if (($a['status'] ?? '') !== 'storniert') { dl_auftrag_heilen($id); $a = dl_auftrag_laden($id) ?: $a; }
$track  = dl_auftrag_track($id);
$dlRow  = !empty($a['dienstleistung_id']) ? dienstleistung_laden((int)$a['dienstleistung_id']) : null;
$ergDok = dl_ergebnis_dateien($id);
$zeigeUpload = ($dlRow && (int)($dlRow['ergebnis_upload'] ?? 0) === 1) || !empty($ergDok);
$rechnung = one("SELECT id, nummer, brutto, status FROM beleg WHERE auftrag_id=? AND typ='rechnung' AND status<>'storniert' LIMIT 1", [$id]);
$statusBadge = fn($s) => match ($s) {
    'offen'=>bx_badge('offen','info'), 'in_arbeit'=>bx_badge('in Arbeit','warn'),
    'erledigt'=>bx_badge('erledigt','ok'), default=>bx_badge(status_text($s)) };

$kopfSub = trim(($dlRow ? (string)$dlRow['name'] : '') . ($a['angebot_nummer'] ? ($dlRow ? ' · ' : '') . 'aus Angebot ' . (string)$a['angebot_nummer'] : ''));
render_header('dienstleistungen', (string)$a['nummer']);
bx_head('DL-Auftrag ' . h((string)$a['nummer']), h($kopfSub), bx_btn('Zurück zur Liste', '?p=dl_auftraege', 'ghost'));
dl_subtabs('dl_auftraege');
if (isset($_GET['done']))   echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Endergebnis hochgeladen – Auftrag abgeschlossen, Kunde benachrichtigt.</div>';
elseif (isset($_GET['ok'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Gespeichert.</div>';
if (isset($_GET['uperr']))  echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:12px 16px">Upload: ' . h((string)$_GET['uperr']) . '</div>';
if (isset($_GET['fehler'])) echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:12px 16px">Rechnung konnte nicht erstellt werden (keine Positionen/kein Betrag).</div>';
if (isset($_GET['storniert'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Auftrag storniert – beim Kunden ausgeblendet. Eine evtl. erstellte DR-Rechnung bitte noch in der Buchhaltung stornieren.</div>';
if (isset($_GET['syncok']))    echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Schritte aus dem Service übernommen (' . (int)$_GET['syncok'] . ' Schritte). Der Kunde sieht den Ablauf im Portal.</div>';
if (isset($_GET['syncleer']))  echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:12px 16px">Für diesen Auftrag ließ sich kein Service mit Schritten ermitteln (reine Material-/Abrechnungsposition oder „ohne Fortschritt").</div>';
?>
<div class="bx-cards">
  <div class="bx-card"><div class="k">Kunde</div><div class="v"><?= kunde_link($a['kunde_id'] ?? null, $a['kunde_firma']) ?></div></div>
  <div class="bx-card"><div class="k">Status</div><div class="v"><?= $statusBadge($a['status']) ?></div></div>
  <div class="bx-card"><div class="k">Netto</div><div class="v"><?= number_format((float)$a['gesamt_netto'],2,',','.') ?> €</div></div>
  <div class="bx-card"><div class="k">Rechnung</div><div class="v"><?= $rechnung ? '<a href="/buchhaltung/?p=rechnung&id=' . (int)$rechnung['id'] . '">' . h($rechnung['nummer']) . '</a>' : '–' ?></div></div>
</div>

<div class="bx-panel">
  <h2 style="margin-top:0">Positionen</h2>
  <?php if ($pos): ?>
  <div class="bx-tablewrap"><table class="bx-table">
    <thead><tr><th>Leistung</th><th class="bx-num">Menge</th><th>Einheit</th><th class="bx-num">Preis/Einheit</th><th class="bx-num">Zeile netto</th></tr></thead>
    <tbody>
    <?php foreach ($pos as $p): $zeile=(float)$p['menge']*(int)$p['preis_cent']/100; ?>
      <tr><td><?= h($p['bezeichnung']) ?></td><td class="bx-num"><?= h(rtrim(rtrim(number_format((float)$p['menge'],3,',',''),'0'),',')) ?></td><td><?= h($p['einheit'] ?: '–') ?></td><td class="bx-num"><?= dienstleistung_eur((int)$p['preis_cent']) ?> €</td><td class="bx-num"><?= number_format($zeile,2,',','.') ?> €</td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php else: ?><div class="muted">Keine Positionen am zugehörigen Angebot.</div><?php endif; ?>
</div>

<div class="bx-panel" id="schritte">
  <h2 style="margin-top:0">Arbeitsschritte</h2>
  <?php if ($track): $nf4 = fn($x) => $x === null || $x === '' ? '' : rtrim(rtrim(number_format((float)$x, 4, ',', '.'), '0'), ','); ?>
    <div class="bx-tablewrap"><table class="bx-table">
      <thead><tr><th style="width:40px"></th><th>Schritt</th><th class="bx-num" style="width:150px">Interne Kosten/Stk <?= bx_hint('Nur intern – keine Kundenberechnung. Für spätere Kalkulation.') ?></th><th style="width:150px"></th></tr></thead>
      <tbody>
      <?php foreach ($track as $s): $done = (int)$s['erledigt'] === 1; ?>
        <tr>
          <td><form method="post" style="margin:0"><input type="hidden" name="aktion" value="schritt_toggle"><input type="hidden" name="schritt_id" value="<?= (int)$s['id'] ?>"><button class="btn btn-sm <?= $done ? 'btn-primary' : 'btn-ghost' ?>" type="submit" title="<?= $done ? 'Haken entfernen' : 'Als erledigt abhaken' ?>"><?= $done ? '✓' : '○' ?></button></form></td>
          <td>
            <form method="post" class="bx-row" style="gap:6px;align-items:center;margin:0">
              <input type="hidden" name="aktion" value="schritt_edit"><input type="hidden" name="schritt_id" value="<?= (int)$s['id'] ?>">
              <input type="text" name="name" value="<?= h((string)$s['name']) ?>" style="flex:1;min-width:160px" maxlength="120">
              <input type="text" name="kosten" value="<?= h($nf4($s['interne_kosten_stk'] ?? null)) ?>" placeholder="–" style="width:90px;text-align:right" inputmode="decimal" title="Interne Kosten je Stück">
              <button class="btn btn-ghost btn-sm" type="submit" title="Umbenennen / Kosten speichern">speichern</button>
            </form>
          </td>
          <td class="bx-num muted" style="font-size:12px"><?= $done && !empty($s['erledigt_at']) ? h(fmt_zeit((string)$s['erledigt_at'], 'd.m.Y')) : '' ?></td>
          <td style="text-align:right"><form method="post" style="margin:0" onsubmit="return confirm('Schritt entfernen?');"><input type="hidden" name="aktion" value="schritt_remove"><input type="hidden" name="schritt_id" value="<?= (int)$s['id'] ?>"><button class="btn btn-ghost btn-sm" type="submit" style="color:#8f231b">entfernen</button></form></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <p class="muted" style="font-size:12px;margin:8px 0 0">Schritte einzeln abhaken, umbenennen, interne Kosten/Stk eintragen oder entfernen – auch nachträglich bei laufendem Auftrag (im Verlauf vermerkt). Der Kunde sieht den Fortschritt im Portal; eine Schrittänderung braucht keine erneute Kundenbestätigung (individueller Festpreis).</p>
    <form method="post" class="bx-row" style="gap:8px;align-items:flex-end;margin:10px 0 0;flex-wrap:wrap">
      <input type="hidden" name="aktion" value="schritt_add">
      <div class="bx-field" style="margin:0;min-width:220px"><label>Schritt hinzufügen</label><input type="text" name="name" placeholder="z. B. Etikett aufbringen" maxlength="120"></div>
      <div class="bx-field" style="margin:0;max-width:130px"><label>Interne Kosten/Stk</label><input type="text" name="kosten" inputmode="decimal" placeholder="optional"></div>
      <button class="btn btn-ghost btn-sm" type="submit">+ Schritt</button>
    </form>
    <form method="post" style="margin:8px 0 0" onsubmit="return confirm('Schritte neu aus dem Service-Katalog übernehmen? Geänderte Abläufe werden angewendet, der bisherige Haken-Stand bleibt je Schrittname erhalten.');">
      <input type="hidden" name="aktion" value="schritte_sync">
      <button class="btn btn-ghost btn-sm" type="submit" title="Schritte aus dem aktuellen Service-Katalog neu übernehmen (für nachträglich geänderte Abläufe)">Schritte neu aus Service übernehmen</button>
    </form>
  <?php else: ?>
    <div class="bx-row" style="gap:8px">
      <form method="post" style="margin:0"><input type="hidden" name="aktion" value="status"><input type="hidden" name="status" value="in_arbeit"><button class="btn btn-ghost btn-sm" type="submit">in Arbeit</button></form>
      <form method="post" style="margin:0"><input type="hidden" name="aktion" value="status"><input type="hidden" name="status" value="erledigt"><button class="btn btn-ghost btn-sm" type="submit">erledigt</button></form>
    </div>
    <p class="muted" style="font-size:12px;margin:6px 0 0"><?= (int)($dlRow['ohne_fortschritt'] ?? 0) === 1
        ? 'Diese Dienstleistung läuft <strong>ohne Fortschritt</strong> – nur Abrechnung (z.&nbsp;B. Fulfillment/Lagerung). Status hier setzen, Rechnung unten.'
        : 'Dieser Auftrag hat keine definierten Schritte. Schritte legst du am <a href="?p=dienstleistungen">Service im Katalog</a> fest – dann hier „aus Service übernehmen".' ?></p>
    <?php if ((int)($dlRow['ohne_fortschritt'] ?? 0) !== 1): ?>
    <form method="post" style="margin:8px 0 0">
      <input type="hidden" name="aktion" value="schritte_sync">
      <button class="btn btn-ghost btn-sm" type="submit" title="Schritte aus dem Service-Katalog übernehmen">Schritte aus dem Service übernehmen</button>
    </form>
    <?php endif; ?>
    <?php // F3: Schritte auch ganz ohne Service-Katalog von Hand aufbauen (z. B. Konfektionierung je Auftrag). ?>
    <form method="post" class="bx-row" style="gap:8px;align-items:flex-end;margin:10px 0 0;flex-wrap:wrap">
      <input type="hidden" name="aktion" value="schritt_add">
      <div class="bx-field" style="margin:0;min-width:220px"><label>Eigenen Arbeitsschritt hinzufügen</label><input type="text" name="name" placeholder="z. B. In Tüten abfüllen" maxlength="120"></div>
      <div class="bx-field" style="margin:0;max-width:130px"><label>Interne Kosten/Stk</label><input type="text" name="kosten" inputmode="decimal" placeholder="optional"></div>
      <button class="btn btn-ghost btn-sm" type="submit">+ Schritt</button>
    </form>
  <?php endif; ?>
  <?php if (($a['status'] ?? '') !== 'storniert'): ?>
  <div class="bx-row" style="gap:8px;margin-top:12px;border-top:1px solid var(--line);padding-top:12px">
    <form method="post" style="margin:0" onsubmit="return confirm('Diesen DL-Auftrag stornieren? Er wird beim Kunden ausgeblendet. Eine bereits erstellte DR-Rechnung bitte zusätzlich in der Buchhaltung stornieren.');">
      <input type="hidden" name="aktion" value="storno">
      <button class="btn btn-ghost btn-sm" type="submit" style="color:#8f231b">Auftrag stornieren</button>
    </form>
    <?php if ($rechnung): ?><a class="btn btn-ghost btn-sm" href="/buchhaltung/?p=rechnung&id=<?= (int)$rechnung['id'] ?>" target="_blank">Rechnung <?= h((string)$rechnung['nummer']) ?> stornieren →</a><?php endif; ?>
  </div>
  <?php else: ?>
  <p class="muted" style="font-size:12px;margin:10px 0 0;color:#8f231b">Dieser Auftrag ist storniert und wird dem Kunden nicht angezeigt.</p>
  <?php endif; ?>
</div>

<?php if ($zeigeUpload): ?>
<div class="bx-panel">
  <h2 style="margin-top:0">Endergebnis</h2>
  <?php if ($ergDok): ?>
    <div style="margin-bottom:10px">
      <?php foreach ($ergDok as $ed): ?>
        <div class="bx-row" style="gap:8px;align-items:center;margin-bottom:4px">
          <a href="?p=dokument&id=<?= (int)$ed['id'] ?>" target="_blank" rel="noopener"><strong><?= h((string)($ed['datei_orig'] ?: 'Endergebnis')) ?></strong></a>
          <span class="muted" style="font-size:12px"><?= $ed['angelegt'] ? h(fmt_zeit((string)$ed['angelegt'], 'd.m.Y H:i')) : '' ?></span>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
  <form method="post" enctype="multipart/form-data" class="bx-row" style="gap:8px;align-items:center">
    <input type="hidden" name="aktion" value="ergebnis_upload">
    <input type="file" name="ergebnis" required accept="application/pdf,image/*,.doc,.docx,.xlsx,.csv,.zip">
    <button class="btn btn-primary btn-sm" type="submit"><?= $ergDok ? 'Weiteres Ergebnis hochladen' : 'Endergebnis hochladen' ?></button>
  </form>
  <?php if ($dlRow && (int)($dlRow['upload_schliesst_ab'] ?? 0) === 1): ?>
    <p class="muted" style="font-size:12px;margin-top:8px">Mit dem Upload wird der Auftrag auf „erledigt" gesetzt und der Kunde benachrichtigt.</p>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="bx-panel">
  <h2 style="margin-top:0">DL-Rechnung (DR-)</h2>
  <?php if ($rechnung): ?>
    <p>Rechnung <a href="/buchhaltung/?p=rechnung&id=<?= (int)$rechnung['id'] ?>"><strong><?= h($rechnung['nummer']) ?></strong></a> · <?= number_format((float)$rechnung['brutto'],2,',','.') ?> € · <?= h(status_text($rechnung['status'])) ?>. Zahlungen werden in der Buchhaltung erfasst.</p>
  <?php else: ?>
    <form method="post" class="bx-row" style="align-items:flex-end;gap:12px">
      <input type="hidden" name="aktion" value="rechnung">
      <div class="bx-field" style="max-width:160px;margin-bottom:0"><label>Zahlungsziel (Tage)</label><input type="number" name="zahlungsziel_tage" min="0" placeholder="z. B. 14"></div>
      <div class="bx-field" style="margin-bottom:0"><label>Im Portal freigeben</label><div class="bx-check" style="padding-top:8px"><input type="checkbox" name="freigeben" id="f_frei" value="1"><label for="f_frei" style="margin:0">Kunde sieht die Rechnung</label></div></div>
      <button class="btn btn-primary" type="submit">DL-Rechnung erstellen</button>
    </form>
    <p class="muted" style="font-size:12px;margin-top:8px">Erzeugt eine Rechnung mit eigenem Nummernkreis DR-… und übernimmt die Positionen. Sie erscheint danach auch im zentralen Kassenbuch der Buchhaltung.</p>
  <?php endif; ?>
</div>
<?php render_footer();

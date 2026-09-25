<?php
// Laboranalysen (Admin). Zentraler Reiter zum Hochladen von Laborberichten / Analysenzertifikaten (CoA)
// fertiger Produkte und zum Verknuepfen mit einem Produkt (KI schlaegt Produkt + Datum vor, Mensch bestaetigt).
// Ablage in `dokument` (typ='analyse'). Mit kunde_sichtbar=1 erscheint die Analyse im Kundenportal-Reiter „Labortest".
// Die Analyse EINER einzelnen Bestellung (Charge) laedt man direkt im Auftrag hoch (module/auftrag/detail.php).
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';
require_once BX_ROOT . '/core/laboranalyse.php';
require_once BX_ROOT . '/core/ki.php';

$suche   = trim((string)($_GET['q'] ?? ''));
$vorschlag = null;   // nach dem Analysieren: Vorschlagsformular anzeigen
$hinweis = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aktion = (string)($_POST['aktion'] ?? '');

    // 1) Datei hochladen + von der KI auswerten lassen (noch NICHT speichern).
    if ($aktion === 'analysieren') {
        if (!empty($_FILES['dok']['name']) && (int)($_FILES['dok']['error'] ?? 1) === UPLOAD_ERR_OK) {
            if (!is_dir(BX_UPLOADS)) @mkdir(BX_UPLOADS, 0775, true);
            $orig = (string)$_FILES['dok']['name'];
            $ext  = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', pathinfo($orig, PATHINFO_EXTENSION)));
            $fn   = 'analyse_' . gmdate('Ymd_His') . '_' . bin2hex(random_bytes(5)) . ($ext ? '.' . $ext : '');
            if (move_uploaded_file($_FILES['dok']['tmp_name'], BX_UPLOADS . '/' . $fn)) {
                $ki = laboranalyse_ki_vorschlag(BX_UPLOADS . '/' . $fn);
                $vorschlag = ['datei' => $fn, 'orig' => $orig,
                              'produkt_id' => $ki['ok'] ? ($ki['produkt_id'] ?? null) : null,
                              'produkt_name' => $ki['ok'] ? ($ki['produkt_name'] ?? '') : '',
                              'datum' => $ki['ok'] ? ($ki['datum'] ?? '') : '',
                              'charge' => $ki['ok'] ? ($ki['charge'] ?? '') : '',
                              'ki_ok' => $ki['ok'], 'ki_fehler' => $ki['ok'] ? '' : (string)($ki['fehler'] ?? ''),
                              // Abgleich Name/Charge mit dem System -> Hinweise bei Abweichung.
                              'hinweise' => $ki['ok'] ? laboranalyse_hinweise($ki) : []];
            } else {
                $hinweis = ['err', 'Datei konnte nicht gespeichert werden.'];
            }
        } else {
            $hinweis = ['err', 'Bitte eine Datei auswählen.'];
        }
    }

    // 2) Vorschlag bestaetigen -> dokument-Zeile anlegen. Quelle: eigenes Produkt oder extern (Fremdlager).
    if ($aktion === 'speichern') {
        $fn   = basename((string)($_POST['datei'] ?? ''));
        $orig = (string)($_POST['orig'] ?? '');
        $quelle = ($_POST['quelle'] ?? 'eigen') === 'extern' ? 'extern' : 'eigen';
        $pid  = $quelle === 'extern' ? (int)($_POST['produkt_extern'] ?? 0) : (int)($_POST['produkt_id'] ?? 0);
        $datum = trim((string)($_POST['datum'] ?? ''));
        $titel = trim((string)($_POST['titel'] ?? '')) ?: null;
        $charge = trim((string)($_POST['charge_nr'] ?? '')) ?: null;
        $sicht = isset($_POST['kunde_sichtbar']) ? 1 : 0;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $datum)) $datum = null;
        if ($fn && $pid && is_file(BX_UPLOADS . '/' . $fn)) {
            q("INSERT INTO dokument (objekt_typ,objekt_id,typ,titel,datei,datei_orig,dok_datum,charge_nr,kunde_sichtbar,hochgeladen_von)
               VALUES ('produkt',?,'analyse',?,?,?,?,?,?,'team')", [$pid, $titel, $fn, $orig ?: $fn, $datum, $charge, $sicht]);
            $hinweis = ['ok', 'Laboranalyse gespeichert und mit dem Produkt verknüpft' . ($charge ? ' (Charge ' . $charge . ')' : '') . '.'];
        } else {
            @unlink(BX_UPLOADS . '/' . $fn);
            $hinweis = ['err', $quelle === 'extern' ? 'Bitte ein Fremdlager-Produkt auswählen.' : 'Bitte ein Produkt auswählen.'];
        }
    }

    // Sichtbarkeit umschalten / loeschen.
    if ($aktion === 'toggle' && ($did = (int)($_POST['dok_id'] ?? 0))) {
        q("UPDATE dokument SET kunde_sichtbar = 1 - kunde_sichtbar WHERE id=? AND typ='analyse'", [$did]);
        $hinweis = ['ok', 'Sichtbarkeit geändert.'];
    }
    if ($aktion === 'del' && ($did = (int)($_POST['dok_id'] ?? 0))) {
        $d = one("SELECT datei FROM dokument WHERE id=? AND typ='analyse'", [$did]);
        if ($d) { @unlink(BX_UPLOADS . '/' . basename((string)$d['datei'])); q("DELETE FROM dokument WHERE id=?", [$did]); }
        $hinweis = ['ok', 'Laboranalyse gelöscht.'];
    }
}

$produkte = all("SELECT p.id, COALESCE(NULLIF(p.kundenname,''), p.name) AS name, k.firma
                 FROM produkt p LEFT JOIN kunden k ON k.id=p.kunde_id
                 ORDER BY name");
// Fremdlager-/Fulfillment-Ware (extern): Produkte, deren Fertigware im Fremdlager liegt (auch Drittanbieter-Ware).
$ffProdukte = lager2_produkte();
$liste = laboranalysen_alle($suche);
$kiBereit = ki_bereit();

render_header('laboranalysen', 'Laboranalysen');
bx_head('Laboranalysen', 'Laborberichte / Analysenzertifikate hochladen und mit einem Produkt verknüpfen. Freigegebene erscheinen im Kundenportal-Reiter „Labortest".');

if ($hinweis) echo '<div class="bx-panel ' . ($hinweis[0] === 'ok' ? 'badge-ok' : '') . '" style="padding:10px 14px' . ($hinweis[0] === 'err' ? ';border-color:#e6c4c0;color:#8f231b' : '') . '">' . h($hinweis[1]) . '</div>';
if (!$kiBereit) echo '<div class="bx-panel" style="border-color:#e6c4c0;padding:10px 14px;font-size:13px;color:#8f231b">Hinweis: Der <strong>KI-Produktvorschlag läuft nur auf beta</strong>. Lokal bitte das Produkt selbst auswählen.</div>';
?>

<?php if ($vorschlag): // Schritt 2: Vorschlag pruefen und speichern ?>
  <div class="bx-panel">
    <h2 style="margin-top:0;font-size:16px">Vorschlag prüfen</h2>
    <p class="muted" style="margin-top:0">Datei: <strong><?= h($vorschlag['orig']) ?></strong>
      <?php if ($vorschlag['ki_ok']): ?>· KI-Vorschlag<?php if ($vorschlag['charge']): ?>, erkannte Charge: <?= h($vorschlag['charge']) ?><?php endif; ?>
      <?php else: ?>· <span style="color:#8f231b">KI nicht verfügbar (<?= h($vorschlag['ki_fehler']) ?>) – bitte manuell wählen</span><?php endif; ?>
    </p>
    <?php if (!empty($vorschlag['hinweise'])): ?>
      <div class="bx-panel" style="border-color:#e6c4c0;background:#fbeae7;color:#8f231b;padding:10px 14px;margin:0 0 12px">
        <strong>Bitte prüfen – Abweichung:</strong>
        <ul style="margin:6px 0 0;padding-left:18px"><?php foreach ($vorschlag['hinweise'] as $hw): ?><li><?= h($hw) ?></li><?php endforeach; ?></ul>
      </div>
    <?php endif; ?>
    <form method="post" data-busy="Speichere Laboranalyse …">
      <input type="hidden" name="aktion" value="speichern">
      <input type="hidden" name="datei" value="<?= h($vorschlag['datei']) ?>">
      <input type="hidden" name="orig" value="<?= h($vorschlag['orig']) ?>">
      <div class="bx-row" style="gap:18px;flex-wrap:wrap;margin-bottom:10px">
        <label style="display:flex;gap:6px;align-items:center;margin:0"><input type="radio" name="quelle" value="eigen" checked onclick="labQuelle('eigen')"> eigenes Produkt</label>
        <label style="display:flex;gap:6px;align-items:center;margin:0"><input type="radio" name="quelle" value="extern" onclick="labQuelle('extern')"> extern (Fremdlager / Drittanbieter)</label>
      </div>
      <div class="bx-grid">
        <div class="bx-field" id="labFeldEigen"><label>Produkt <?= bx_hint('Zu welchem Produkt gehört diese Analyse?') ?></label>
          <select name="produkt_id">
            <option value="">– Produkt wählen –</option>
            <?php foreach ($produkte as $p): ?>
              <option value="<?= (int)$p['id'] ?>" <?= ((int)$p['id'] === (int)$vorschlag['produkt_id']) ? 'selected' : '' ?>>
                <?= h($p['name']) ?><?= $p['firma'] ? ' — ' . h($p['firma']) : '' ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="bx-field" id="labFeldExtern" style="display:none"><label>Fremdlager-Produkt <?= bx_hint('Kundenware von Drittanbietern, die im Fremdlager liegt.') ?></label>
          <select name="produkt_extern">
            <option value="">– Fremdlager-Produkt wählen –</option>
            <?php foreach ($ffProdukte as $p): ?>
              <option value="<?= (int)$p['produkt_id'] ?>"><?= h($p['anzeigename']) ?> — <?= h($p['kunde']) ?><?= $p['produkt_nr'] ? ' (' . h($p['produkt_nr']) . ')' : '' ?></option>
            <?php endforeach; ?>
          </select>
          <?php if (!$ffProdukte): ?><div class="muted" style="font-size:12px;margin-top:4px">Noch keine Fremdlager-Ware eingebucht. Einbuchen unter <a href="?p=lager2">Fremdlager</a>.</div><?php endif; ?>
        </div>
        <div class="bx-field"><label>Chargennummer <?= bx_hint('Steht auf dem Laborbericht; wird im System hinterlegt.') ?></label><input type="text" name="charge_nr" value="<?= h($vorschlag['charge']) ?>" placeholder="z. B. JN26P8"></div>
        <div class="bx-field"><label>Analysendatum</label><input type="date" name="datum" value="<?= h($vorschlag['datum']) ?>"></div>
        <div class="bx-field"><label>Titel (optional)</label><input type="text" name="titel" placeholder="z. B. Laboranalyse Charge 2026-04"></div>
      </div>
      <script>
      function labQuelle(q){
        document.getElementById('labFeldEigen').style.display  = q==='extern' ? 'none' : '';
        document.getElementById('labFeldExtern').style.display = q==='extern' ? '' : 'none';
      }
      </script>
      <div class="bx-row" style="gap:8px;align-items:center;margin-top:var(--sp-3)">
        <input type="checkbox" name="kunde_sichtbar" id="ks" value="1" checked>
        <label for="ks" style="margin:0">im Kundenportal sichtbar (Reiter „Labortest")</label>
      </div>
      <div class="bx-row" style="margin-top:var(--sp-4);gap:8px">
        <button class="btn btn-primary" type="submit">Speichern &amp; verknüpfen</button>
      </div>
    </form>
  </div>
<?php else: // Schritt 1: hochladen ?>
  <form method="post" enctype="multipart/form-data" class="bx-panel" data-busy="Bericht wird ausgewertet …">
    <input type="hidden" name="aktion" value="analysieren">
    <div class="bx-field"><label>Laborbericht / Analysenzertifikat</label>
      <input type="file" name="dok" required accept="application/pdf,image/*">
      <div class="muted" style="font-size:12px;margin-top:4px">PDF oder Bild. Die KI schlägt Produkt und Analysendatum vor – du bestätigst.</div>
    </div>
    <div class="bx-row" style="margin-top:var(--sp-3)"><button class="btn btn-primary" type="submit">Hochladen &amp; auswerten</button></div>
  </form>
<?php endif; ?>

<div class="bx-panel">
  <div class="bx-row" style="justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
    <h2 style="margin:0;font-size:16px">Hinterlegte Laboranalysen <span class="muted" style="font-weight:normal">(<?= count($liste) ?>)</span></h2>
    <form method="get" style="margin:0"><input type="hidden" name="p" value="laboranalysen">
      <input type="text" name="q" value="<?= h($suche) ?>" placeholder="Produkt, Auftrag, Datei …" oninput="bxLabFilter(this.value)" style="min-width:220px">
    </form>
  </div>
  <?php if ($liste): ?>
  <div class="bx-tablewrap" style="margin-top:12px"><table class="bx-table" id="labTab">
    <thead><tr><th>Datum</th><th>Produkt</th><th>Kunde</th><th>Charge</th><th>Bezug</th><th>Datei</th><th>Kundenportal</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($liste as $d): ?>
      <tr>
        <td><?= $d['datum'] ? h(fmt_zeit($d['datum'] . ' 00:00:00', 'd.m.Y')) : '<span class="muted">–</span>' ?></td>
        <td><?= h($d['produkt'] ?: '–') ?></td>
        <td><?= $d['kunde'] ? h($d['kunde']) : '<span class="muted">–</span>' ?></td>
        <td><?= $d['charge_nr'] ? h($d['charge_nr']) : '<span class="muted">–</span>' ?></td>
        <td><?= $d['auftrag_nr'] ? h($d['auftrag_nr']) . ' <span class="muted">(Bestellung)</span>' : '<span class="muted">Produkt</span>' ?></td>
        <td><a href="?p=dokument&id=<?= (int)$d['id'] ?>" target="_blank"><?= h($d['datei_orig'] ?: 'Datei') ?></a></td>
        <td>
          <form method="post" style="margin:0"><input type="hidden" name="aktion" value="toggle"><input type="hidden" name="dok_id" value="<?= (int)$d['id'] ?>">
            <button class="btn btn-ghost btn-sm" type="submit" title="Sichtbarkeit umschalten"><?= (int)$d['kunde_sichtbar'] === 1 ? bx_badge('freigegeben','ok') : bx_badge('intern') ?></button>
          </form>
        </td>
        <td style="text-align:right"><form method="post" style="margin:0" onsubmit="return confirm('Laboranalyse löschen?');"><input type="hidden" name="aktion" value="del"><input type="hidden" name="dok_id" value="<?= (int)$d['id'] ?>"><button class="btn btn-ghost btn-sm" type="submit">Löschen</button></form></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php else: ?>
  <div class="muted" style="margin-top:12px">Noch keine Laboranalysen hinterlegt.</div>
  <?php endif; ?>
</div>
<script>
// Live-Filter der Tabelle (Muster wie andere v4-Listen: beim Tippen sofort filtern).
function bxLabFilter(v){ v=(v||'').toLowerCase(); document.querySelectorAll('#labTab tbody tr').forEach(function(tr){ tr.style.display = tr.textContent.toLowerCase().indexOf(v)>=0 ? '' : 'none'; }); }
</script>
<?php render_footer(); ?>

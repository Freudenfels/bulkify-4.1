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
                              'befund' => $ki['ok'] ? ($ki['befund'] ?? 'unklar') : 'unklar',
                              'ki_ok' => $ki['ok'], 'ki_fehler' => $ki['ok'] ? '' : (string)($ki['fehler'] ?? ''),
                              // Abgleich Name/Charge mit dem System -> Hinweise bei Abweichung.
                              'hinweise' => $ki['ok'] ? laboranalyse_hinweise($ki) : [],
                              // Charge -> konkrete Bestellung(en): direkt an die richtige Bestellung koppeln.
                              'auftraege' => $ki['ok'] ? laboranalyse_auftraege_zu_charge((string)($ki['charge'] ?? '')) : []];
            } else {
                $hinweis = ['err', 'Datei konnte nicht gespeichert werden.'];
            }
        } else {
            $hinweis = ['err', 'Bitte eine Datei auswählen.'];
        }
    }

    // 2) Vorschlag bestaetigen -> dokument-Zeile anlegen. Ziel: konkrete Bestellung (aus Charge),
    //    eigenes Produkt (alle Bestellungen) oder extern (Fremdlager).
    if ($aktion === 'speichern') {
        $fn   = basename((string)($_POST['datei'] ?? ''));
        $orig = (string)($_POST['orig'] ?? '');
        $quelle = (string)($_POST['quelle'] ?? 'eigen');
        $datum = trim((string)($_POST['datum'] ?? ''));
        $titel = trim((string)($_POST['titel'] ?? '')) ?: null;
        $charge = trim((string)($_POST['charge_nr'] ?? '')) ?: null;
        $befund = in_array($_POST['befund'] ?? '', ['bestanden','auffaellig','unklar'], true) ? $_POST['befund'] : 'unklar';
        $sicht = isset($_POST['kunde_sichtbar']) ? 1 : 0;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $datum)) $datum = null;
        // Zielobjekt bestimmen. Normalfall: Produkt gewählt, dann Auftrag (Bestellung) -> an DIE Bestellung
        // koppeln; ohne Auftrag (= „alle Bestellungen") ans Produkt. Extern: Fremdlager-Produkt.
        $objTyp = 'produkt'; $objId = 0; $bezug = '';
        if ($quelle === 'extern') {
            $objId = (int)($_POST['produkt_extern'] ?? 0); $bezug = 'dem Fremdlager-Produkt';
        } else {
            $aid = (int)($_POST['auftrag_id'] ?? 0);
            if ($aid > 0) {
                $objTyp = 'auftrag'; $objId = $aid;
                $bezug = 'der Bestellung ' . (string) scalar("SELECT nummer FROM auftrag WHERE id=?", [$aid]);
            } else {
                $objId = (int)($_POST['produkt_id'] ?? 0); $bezug = 'dem Produkt (alle Bestellungen)';
            }
        }
        if ($fn && $objId && is_file(BX_UPLOADS . '/' . $fn)) {
            q("INSERT INTO dokument (objekt_typ,objekt_id,typ,titel,datei,datei_orig,dok_datum,charge_nr,befund,kunde_sichtbar,hochgeladen_von)
               VALUES (?,?,'analyse',?,?,?,?,?,?,?,'team')", [$objTyp, $objId, $titel, $fn, $orig ?: $fn, $datum, $charge, $befund, $sicht]);
            $hinweis = ['ok', 'Laboranalyse gespeichert und mit ' . $bezug . ' verknüpft' . ($charge ? ' (Charge ' . $charge . ')' : '') . '.'];
        } else {
            @unlink(BX_UPLOADS . '/' . $fn);
            $hinweis = ['err', $quelle === 'bestellung' ? 'Bitte eine Bestellung auswählen.' : ($quelle === 'extern' ? 'Bitte ein Fremdlager-Produkt auswählen.' : 'Bitte ein Produkt auswählen.')];
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

$produkte = all("SELECT p.id, COALESCE(NULLIF(p.kundenname,''), p.name) AS name, k.firma,
                        p.einheiten_pro_packung, r.darreichungsform AS form
                 FROM produkt p LEFT JOIN kunden k ON k.id=p.kunde_id LEFT JOIN rezeptur r ON r.id=p.rezeptur_id
                 ORDER BY name");
// Menge je Packung fuer die Dropdown-Beschriftung (nur wenn nicht schon im Namen als Variante enthalten).
$labWort = fn($f) => in_array($f, ['kapsel','softgel'], true) ? 'Kapseln' : ($f === 'tablette' ? 'Tabletten' : ($f === 'stick' ? 'Sticks' : ($f === 'pulver' || $f === 'fluessig' ? '' : 'Stück')));
$labMenge = function (array $p) use ($labWort): string {
    if (mb_strpos((string)$p['name'], ' · ') !== false) return '';   // Variante hat die Menge schon im Namen
    $stk = (int)($p['einheiten_pro_packung'] ?? 0); $w = $labWort((string)($p['form'] ?? ''));
    return ($stk > 0 && $w !== '') ? ' · ' . $stk . ' ' . $w : '';
};
// Fremdlager-/Fulfillment-Ware (extern): Produkte, deren Fertigware im Fremdlager liegt (auch Drittanbieter-Ware).
$ffProdukte = lager2_produkte();
$liste = laboranalysen_alle($suche);
$kiBereit = ki_bereit();

// Kaskade Produkt -> Auftrag -> Charge (clientseitig gefiltert, ohne Nachladen).
$ordersByProduct = [];
foreach (all("SELECT a.id, a.produkt_id, a.nummer, COALESCE(k.firma,'') AS kunde
              FROM auftrag a LEFT JOIN kunden k ON k.id=a.kunde_id
              WHERE a.produkt_id IS NOT NULL AND a.status<>'storniert' ORDER BY a.id DESC") as $a)
    $ordersByProduct[(int)$a['produkt_id']][] = ['id' => (int)$a['id'], 'nummer' => (string)$a['nummer'], 'kunde' => (string)$a['kunde']];
$chargesByOrder = [];
foreach (all("SELECT pa.auftrag_id, c.charge_nr FROM charge c JOIN produktionsauftrag pa ON pa.id=c.pa_id
              WHERE c.charge_nr IS NOT NULL AND c.charge_nr<>'' AND pa.auftrag_id IS NOT NULL ORDER BY c.id") as $c)
    $chargesByOrder[(int)$c['auftrag_id']][] = (string)$c['charge_nr'];
$kiAuftragId = (!empty($vorschlag['auftraege']) ? (int)$vorschlag['auftraege'][0]['id'] : 0);

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
      <div class="bx-grid" id="labBlockEigen">
        <div class="bx-field"><label>1. Produkt <?= bx_hint('Zu welchem Produkt gehört diese Analyse?') ?></label>
          <select name="produkt_id" id="labProdukt" onchange="labFillAuftraege()">
            <option value="">– Produkt wählen –</option>
            <?php foreach ($produkte as $p): ?>
              <option value="<?= (int)$p['id'] ?>" <?= ((int)$p['id'] === (int)$vorschlag['produkt_id']) ? 'selected' : '' ?>>
                <?= h($p['name'] . $labMenge($p)) ?><?= $p['firma'] ? ' — ' . h($p['firma']) : '' ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="bx-field"><label>2. Bestellung <?= bx_hint('Bestellungen dieses Produkts. „alle Bestellungen" = am Produkt hinterlegen (für alle sichtbar).') ?></label>
          <select name="auftrag_id" id="labAuftrag" onchange="labFillChargen()">
            <option value="0">alle Bestellungen (am Produkt)</option>
          </select>
        </div>
        <div class="bx-field"><label>3. Chargennummer <?= bx_hint('Chargen der gewählten Bestellung; die vom Bericht erkannte Charge ist vorausgewählt. Freitext möglich.') ?></label>
          <input type="text" name="charge_nr" id="labCharge" list="labChargeList" value="<?= h($vorschlag['charge']) ?>" placeholder="z. B. JN26P8">
          <datalist id="labChargeList"></datalist>
        </div>
      </div>
      <div class="bx-grid" id="labBlockExtern" style="display:none">
        <div class="bx-field"><label>Fremdlager-Produkt <?= bx_hint('Kundenware von Drittanbietern, die im Fremdlager liegt.') ?></label>
          <select name="produkt_extern">
            <option value="">– Fremdlager-Produkt wählen –</option>
            <?php foreach ($ffProdukte as $p): ?>
              <option value="<?= (int)$p['produkt_id'] ?>"><?= h($p['anzeigename']) ?> — <?= h($p['kunde']) ?><?= $p['produkt_nr'] ? ' (' . h($p['produkt_nr']) . ')' : '' ?></option>
            <?php endforeach; ?>
          </select>
          <?php if (!$ffProdukte): ?><div class="muted" style="font-size:12px;margin-top:4px">Noch keine Fremdlager-Ware eingebucht. Einbuchen unter <a href="?p=lager2">Fremdlager</a>.</div><?php endif; ?>
        </div>
      </div>
      <div class="bx-grid" style="margin-top:12px">
        <div class="bx-field"><label>Befund <?= bx_hint('Ergebnis der im Bericht geprüften Parameter. KI-Vorschlag, bitte prüfen.') ?></label>
          <select name="befund">
            <?php foreach (['bestanden'=>'bestanden (alle geprüften Werte in Spezifikation)','auffaellig'=>'auffällig (Wert außerhalb Spezifikation)','unklar'=>'unklar / kein Befund'] as $bk=>$bl): ?>
              <option value="<?= $bk ?>" <?= ($vorschlag['befund'] === $bk) ? 'selected' : '' ?>><?= h($bl) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="bx-field"><label>Analysendatum</label><input type="date" name="datum" value="<?= h($vorschlag['datum']) ?>"></div>
        <div class="bx-field"><label>Titel (optional)</label><input type="text" name="titel" placeholder="z. B. Laboranalyse Charge 2026-04"></div>
      </div>
      <script>
      var LAB_ORDERS  = <?= json_encode($ordersByProduct, JSON_UNESCAPED_UNICODE) ?>;
      var LAB_CHARGES = <?= json_encode($chargesByOrder, JSON_UNESCAPED_UNICODE) ?>;
      var LAB_KI = {auftrag: <?= (int)$kiAuftragId ?>, charge: <?= json_encode((string)$vorschlag['charge']) ?>};
      function labQuelle(q){
        document.getElementById('labBlockEigen').style.display  = q==='extern' ? 'none' : '';
        document.getElementById('labBlockExtern').style.display = q==='extern' ? '' : 'none';
      }
      function labFillAuftraege(){
        var pid = document.getElementById('labProdukt').value;
        var sel = document.getElementById('labAuftrag');
        sel.innerHTML = '<option value="0">alle Bestellungen (am Produkt)</option>';
        (LAB_ORDERS[pid]||[]).forEach(function(o){
          var opt=document.createElement('option'); opt.value=o.id;
          opt.textContent = o.nummer + (o.kunde? ' — '+o.kunde : '');
          if (o.id === LAB_KI.auftrag) opt.selected = true;
          sel.appendChild(opt);
        });
        labFillChargen();
      }
      function labFillChargen(){
        var aid = document.getElementById('labAuftrag').value;
        var dl = document.getElementById('labChargeList');
        dl.innerHTML = '';
        (LAB_CHARGES[aid]||[]).forEach(function(c){ var o=document.createElement('option'); o.value=c; dl.appendChild(o); });
        // Charge der Bestellung vorschlagen, wenn Feld leer oder KI keine Charge hatte
        var cf = document.getElementById('labCharge');
        if (aid !== '0' && (LAB_CHARGES[aid]||[]).length && (!cf.value || cf.value===LAB_KI.charge))
          cf.value = LAB_KI.charge && (LAB_CHARGES[aid].indexOf(LAB_KI.charge)>=0) ? LAB_KI.charge : LAB_CHARGES[aid][0];
      }
      labFillAuftraege();   // initial: Aufträge des (KI-)Produkts laden + KI-Bestellung/Charge vorwählen
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
    <thead><tr><th>Datum</th><th>Produkt</th><th>Kunde</th><th>Charge</th><th>Befund</th><th>Bezug</th><th>Datei</th><th>Kundenportal</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($liste as $d): $bf = laboranalyse_befund_label($d['befund'] ?? null); ?>
      <tr>
        <td><?= $d['datum'] ? h(fmt_zeit($d['datum'] . ' 00:00:00', 'd.m.Y')) : '<span class="muted">–</span>' ?></td>
        <td><?= h($d['produkt'] ?: '–') ?></td>
        <td><?= $d['kunde'] ? h($d['kunde']) : '<span class="muted">–</span>' ?></td>
        <td><?= $d['charge_nr'] ? h($d['charge_nr']) : '<span class="muted">–</span>' ?></td>
        <td><?= $bf[0] !== '' ? bx_badge($bf[0], $bf[1]) : '<span class="muted">–</span>' ?></td>
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

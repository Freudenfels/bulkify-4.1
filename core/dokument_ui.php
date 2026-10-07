<?php
// Generische Dokumentenablage (COA/Spec/Analyse) für Rohstoffe (item) und Produkte (produkt),
// mit optionaler Lieferantenzuordnung. Wird von rohstoff_detail.php und produkt_detail.php genutzt.
require_once __DIR__ . '/schema.php';

function dokument_typen(): array {
    return ['coa'=>'CoA (Analysenzertifikat)', 'spec'=>'Spezifikation', 'analyse'=>'Laboranalyse', 'zertifikat'=>'Zertifikat (ISO, HACCP, Bio …)', 'sonstiges'=>'Sonstiges'];
}

// Upload verarbeiten (erwartet Datei-Feld „dok", dok_typ, dok_lieferant, dok_titel).
// Rückgabe: die id der neuen Zeile in `dokument`, sonst 0. Wer die Datei gleich weiterverarbeiten
// will (z. B. mit der KI auslesen), braucht sie – die bisherigen Aufrufer ignorieren sie einfach.
function dokument_upload(string $objekt_typ, int $objekt_id): int {
    $typ = array_key_exists($_POST['dok_typ'] ?? '', dokument_typen()) ? $_POST['dok_typ'] : 'coa';
    $lid = ($_POST['dok_lieferant'] ?? '') !== '' ? (int) $_POST['dok_lieferant'] : null;
    if (!empty($_FILES['dok']['name']) && ($_FILES['dok']['error'] ?? 1) === UPLOAD_ERR_OK) {
        if (!is_dir(BX_UPLOADS)) @mkdir(BX_UPLOADS, 0775, true);
        $orig = $_FILES['dok']['name'];
        $ext  = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', pathinfo($orig, PATHINFO_EXTENSION)));
        $fn   = $objekt_typ . '_' . $objekt_id . '_' . bin2hex(random_bytes(6)) . ($ext ? '.' . $ext : '');
        if (move_uploaded_file($_FILES['dok']['tmp_name'], BX_UPLOADS . '/' . $fn)) {
            q("INSERT INTO dokument (objekt_typ,objekt_id,typ,lieferant_id,titel,datei,datei_orig,kunde_sichtbar) VALUES (?,?,?,?,?,?,?,?)",
              [$objekt_typ, $objekt_id, $typ, $lid, trim($_POST['dok_titel'] ?? '') ?: null, $fn, $orig,
               isset($_POST['dok_kunde']) ? 1 : 0]);
            return (int) insert_id();
        }
    }
    return 0;
}

// Freigabe fürs Kundenportal umschalten. ACHTUNG: freigegeben wird das ORIGINAL des Lieferanten
// (auf dessen Briefpapier). Fuer den Kunden gibt es die eigene Spezifikation und das eigene CoA
// im bulkify-Layout (core/pdf_spec.php) – die Freigabe hier ist nur fuer Ausnahmefaelle gedacht.
// am Rohstoff hängen auch Lieferanten-Unterlagen, die nicht weitergegeben werden dürfen.
// Original-Unterlagen des Lieferanten (Spec/CoA/Analyse an einem Rohstoff = item) dürfen NIE an den
// Kunden – der Kunde bekommt immer nur UNSER bulkify-Dokument (build_spec_pdf/build_coa_pdf). Diese
// Dokumente lassen sich daher nicht für den Kunden freigeben und erscheinen nie in der Kundensicht.
function dokument_ist_lieferant_original(string $objekt_typ, string $typ): bool {
    return $objekt_typ === 'item' && in_array($typ, ['spec', 'coa', 'analyse'], true);
}
function dokument_freigabe_toggle(string $objekt_typ, int $objekt_id, int $dok_id): void {
    $typ = (string) scalar("SELECT typ FROM dokument WHERE id=? AND objekt_typ=? AND objekt_id=?", [$dok_id, $objekt_typ, $objekt_id]);
    if (dokument_ist_lieferant_original($objekt_typ, $typ)) {
        // Sicherheitshalber erzwingen: bleibt intern.
        q("UPDATE dokument SET kunde_sichtbar=0 WHERE id=? AND objekt_typ=? AND objekt_id=?", [$dok_id, $objekt_typ, $objekt_id]);
        return;
    }
    q("UPDATE dokument SET kunde_sichtbar = 1 - kunde_sichtbar WHERE id=? AND objekt_typ=? AND objekt_id=?",
      [$dok_id, $objekt_typ, $objekt_id]);
}
// Für Kunden freigegebene Dokumente eines Objekts (Portal). Lieferanten-Originale (item spec/coa/analyse)
// sind hier IMMER ausgeschlossen – egal wie kunde_sichtbar steht.
function dokumente_fuer_kunde(string $objekt_typ, int $objekt_id): array {
    return all("SELECT id, typ, titel, datei_orig FROM dokument
                WHERE objekt_typ=? AND objekt_id=? AND kunde_sichtbar=1
                  AND NOT (objekt_typ='item' AND typ IN ('spec','coa','analyse'))
                ORDER BY typ, id DESC", [$objekt_typ, $objekt_id]);
}

function dokument_delete(string $objekt_typ, int $objekt_id, int $dok_id): void {
    $d = one("SELECT datei FROM dokument WHERE id=? AND objekt_typ=? AND objekt_id=?", [$dok_id, $objekt_typ, $objekt_id]);
    if ($d) { @unlink(BX_UPLOADS . '/' . basename((string) $d['datei'])); q("DELETE FROM dokument WHERE id=?", [$dok_id]); }
}

function dokumente_fuer(string $objekt_typ, int $objekt_id): array {
    return all("SELECT d.*, l.firma AS lieferant_firma FROM dokument d LEFT JOIN lieferanten l ON l.id=d.lieferant_id
                WHERE d.objekt_typ=? AND d.objekt_id=? ORDER BY d.typ, d.id DESC", [$objekt_typ, $objekt_id]);
}

// Rendert Liste + Upload-Formular (eigenes Multipart-Formular). $rueck = Ziel-Tab für den Redirect.
function dokument_panel(string $objekt_typ, int $objekt_id, array $lieferanten): void {
    $TYP = dokument_typen();
    $docs = dokumente_fuer($objekt_typ, $objekt_id);
    ?>
    <div class="bx-panel">
      <h2 style="margin-top:0">Dokumente (CoA &amp; Spezifikation)</h2>
      <p class="muted" style="margin-top:0">Analysenzertifikate (CoA), Spezifikationen und Laboranalysen – je Dokument optional mit Lieferant verknüpft. Werden sicher außerhalb des Web-Ordners gespeichert.</p>
      <?php if ($docs): ?>
      <div class="bx-tablewrap"><table class="bx-table">
        <thead><tr><th>Typ</th><th>Titel / Datei</th><th>Lieferant</th><th>Im Kundenportal</th><th>Hochgeladen</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($docs as $d): ?>
          <tr>
            <td><?= h($TYP[$d['typ']] ?? $d['typ']) ?></td>
            <td><a href="?p=dokument&id=<?= (int)$d['id'] ?>" target="_blank"><?= h($d['titel'] ?: ($d['datei_orig'] ?: 'Dokument')) ?></a><?php if ($d['titel'] && $d['datei_orig']): ?><div class="muted" style="font-size:12px"><?= h($d['datei_orig']) ?></div><?php endif; ?></td>
            <td><?= $d['lieferant_firma'] ? h($d['lieferant_firma']) : '<span class="muted">–</span>' ?></td>
            <td>
              <?php if (dokument_ist_lieferant_original($objekt_typ, (string)$d['typ'])): ?>
                <?= bx_badge('intern · nie an Kunde','warn') ?>
                <div class="muted" style="font-size:11px">Kunde bekommt das bulkify-Dokument</div>
              <?php else: ?>
              <form method="post" style="margin:0">
                <input type="hidden" name="aktion" value="dok_frei"><input type="hidden" name="dok_id" value="<?= (int)$d['id'] ?>">
                <button class="btn btn-ghost btn-sm" type="submit" title="Sichtbarkeit im Kundenportal umschalten">
                  <?= (int)($d['kunde_sichtbar'] ?? 0) === 1 ? bx_badge('freigegeben','ok') : bx_badge('intern') ?>
                </button>
              </form>
              <?php endif; ?>
            </td>
            <td class="muted"><?= h(fmt_zeit($d['angelegt'], 'd.m.Y')) ?></td>
            <td style="text-align:right"><form method="post" style="margin:0" onsubmit="return confirm('Dokument löschen?');"><input type="hidden" name="aktion" value="dok_del"><input type="hidden" name="dok_id" value="<?= (int)$d['id'] ?>"><button class="btn btn-ghost btn-sm" type="submit">Löschen</button></form></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php else: ?>
      <div class="muted" style="margin-bottom:12px">Noch keine Dokumente hinterlegt.</div>
      <?php endif; ?>
      <form method="post" enctype="multipart/form-data" style="margin-top:14px">
        <input type="hidden" name="aktion" value="dok_upload">
        <div class="bx-grid">
          <div class="bx-field"><label>Typ</label><select name="dok_typ"><?php foreach ($TYP as $key=>$lbl): ?><option value="<?= $key ?>"><?= h($lbl) ?></option><?php endforeach; ?></select></div>
          <div class="bx-field"><label>Lieferant <?= bx_hint('von welchem Anbieter stammt der Nachweis?') ?></label>
            <select name="dok_lieferant"><option value="">– keiner –</option><?php foreach ($lieferanten as $lf): ?><option value="<?= (int)$lf['id'] ?>"><?= h($lf['firma']) ?></option><?php endforeach; ?></select>
          </div>
          <div class="bx-field"><label>Titel (optional)</label><input type="text" name="dok_titel" placeholder="z. B. CoA Charge 2026-04"></div>
          <div class="bx-field"><label>Datei</label><input type="file" name="dok" required accept="application/pdf,image/*"></div>
        </div>
        <div class="bx-row" id="dokKundeRow" style="gap:8px;align-items:center;margin-top:var(--sp-3)">
          <input type="checkbox" name="dok_kunde" id="dok_kunde" value="1">
          <label for="dok_kunde" style="margin:0">im Kundenportal sichtbar <?= bx_hint('Standard ist intern. Nur anhaken, was der Kunde sehen darf – Lieferanten-Spezifikationen in der Regel nicht.') ?></label>
        </div>
        <div id="dokKundeNote" class="muted" style="display:none;margin-top:var(--sp-3);font-size:13px">
          Lieferanten-Original – bleibt intern. Der Kunde bekommt automatisch das <strong>bulkify-Dokument</strong> (Spezifikation/CoA im bulkify-Layout), nicht diese Datei.
        </div>
        <div class="bx-row" style="margin-top:var(--sp-4)"><button class="btn btn-primary" type="submit">Dokument hochladen</button></div>
      </form>
    </div>
    <?php if ($objekt_typ === 'item'): ?>
    <script>
    (function(){
      // Bei Rohstoff-Unterlagen sind Spec/CoA/Analyse immer Lieferanten-Originale -> nie an den Kunden.
      // Darum die „im Kundenportal sichtbar"-Frage nur für Typen zeigen, die kundensichtbar sein dürfen.
      var sel = document.querySelector('select[name="dok_typ"]');
      var row = document.getElementById('dokKundeRow');
      var note = document.getElementById('dokKundeNote');
      if (!sel || !row) return;
      function upd(){
        var orig = ['coa','spec','analyse'].indexOf(sel.value) !== -1;
        row.style.display = orig ? 'none' : '';
        if (note) note.style.display = orig ? '' : 'none';
        if (orig) { var cb = document.getElementById('dok_kunde'); if (cb) cb.checked = false; }
      }
      sel.addEventListener('change', upd); upd();
    })();
    </script>
    <?php endif; ?>
    <?php
}

<?php
// Arbeitsliste „Aufträge ohne Preis": alle Aufträge mit 0,00 € Netto auf EINER Seite, je Zeile ein
// Upload. Hochgeladene Rechnung/AB wird per KI in EINZELPOSITIONEN gelesen (Herstellung/Kapseln, Dose/
// Glas, Etiketten …), daraus eine verknüpfte Rechnung mit Positionen angelegt und der Auftragspreis
// (Netto + VK/Stück) gefüllt. So liegen die Preise aufgeschlüsselt beim Kunden.
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

$fehler = ''; $ergebnis = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'upload') {
    $aid = (int)($_POST['auftrag_id'] ?? 0);
    $a = $aid ? one("SELECT id FROM auftrag WHERE id=?", [$aid]) : null;
    if (!$a) {
        $fehler = 'Auftrag nicht gefunden.';
    } elseif (empty($_FILES['dok']['name']) || (int)($_FILES['dok']['error'] ?? 1) !== UPLOAD_ERR_OK) {
        $fehler = 'Bitte eine Rechnung (PDF oder Bild) hochladen.';
    } else {
        @set_time_limit(300);
        $orig = (string)$_FILES['dok']['name'];
        $ext  = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', pathinfo($orig, PATHINFO_EXTENSION)));
        if (!in_array($ext, ['pdf', 'jpg', 'jpeg', 'png', 'webp'], true)) {
            $fehler = 'Dateityp nicht erlaubt (PDF/JPG/PNG).';
        } else {
            if (!is_dir(BX_UPLOADS)) @mkdir(BX_UPLOADS, 0775, true);
            $fn = 'auftragre_' . $aid . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
            if (!move_uploaded_file($_FILES['dok']['tmp_name'], BX_UPLOADS . '/' . $fn)) {
                $fehler = 'Datei konnte nicht gespeichert werden.';
            } else {
                $ki = rechnung_import_positionen_ki(BX_UPLOADS . '/' . $fn);
                if (empty($ki['ok']) || !$ki['positionen']) {
                    $fehler = 'Positionen konnten nicht gelesen werden: ' . ($ki['fehler'] ?? 'keine Positionen erkannt') . ' – Datei bleibt gespeichert, Preis bitte im Auftrag von Hand eintragen.';
                    // Trotzdem als Original am Auftrag ablegen, damit nichts verloren geht.
                    q("INSERT INTO dokument (objekt_typ,objekt_id,typ,titel,datei,datei_orig,kunde_sichtbar,hochgeladen_von) VALUES ('auftrag',?, 'rechnung','Rechnung (Altsystem)',?,?,1,'team')", [$aid, $fn, mb_substr($orig, 0, 255)]);
                } else {
                    if (!empty($_POST['bezahlt'])) $ki['bezahlt'] = true;
                    $r = auftrag_rechnung_aus_positionen($aid, $ki['positionen'],
                        ['nummer' => $ki['nummer'], 'datum' => $ki['datum'], 'bezahlt' => $ki['bezahlt']], $fn, $orig);
                    if (empty($r['ok'])) $fehler = (string)($r['fehler'] ?? 'Anlegen fehlgeschlagen.');
                    else header('Location: ?p=auftrag_preise&ok=' . (int)$aid . '&netto=' . rawurlencode(number_format($r['netto'], 2, ',', '.'))) ;
                    if (empty($fehler)) exit;
                }
            }
        }
    }
}

// Aufträge ohne Preis (0,00 € Netto), nicht storniert.
$offene = all("SELECT a.id, a.nummer, a.menge, a.stueck, a.status, a.angelegt,
                      COALESCE(NULLIF(p.kundenname,''), p.name, a.produkt_bezeichnung) AS produkt,
                      k.firma AS kunde
               FROM auftrag a LEFT JOIN produkt p ON p.id=a.produkt_id LEFT JOIN kunden k ON k.id=a.kunde_id
               WHERE a.status<>'storniert' AND COALESCE(a.gesamt_netto,0) <= 0
               ORDER BY k.firma, a.id DESC");

render_header('auftraege', 'Aufträge ohne Preis');
bx_head('Aufträge ohne Preis', count($offene) . ' ' . (count($offene) === 1 ? 'Auftrag' : 'Aufträge') . ' ohne hinterlegten Preis – Rechnung je Zeile hochladen, Preis wird aufgeschlüsselt übernommen', bx_btn('Zurück zu Aufträgen', '?p=auftraege', 'ghost'));
if ($fehler) echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:12px 16px">' . h($fehler) . '</div>';
if (isset($_GET['ok'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Preis übernommen' . (isset($_GET['netto']) ? ' (' . h((string)$_GET['netto']) . ' € netto)' : '') . ' – Auftrag <a href="?p=auftrag&id=' . (int)$_GET['ok'] . '">öffnen</a>. Positionen (Etikett/Glas/Kapsel) sind als Rechnung hinterlegt.</div>';
$kiBereit = (function(){ require_once BX_ROOT . '/core/ki.php'; return ki_bereit(); })();
if (!$kiBereit) echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:12px 16px">KI ist nicht eingerichtet – ohne KI kann der Betrag nicht ausgelesen werden (Preis dann im Auftrag von Hand eintragen).</div>';
?>
<?php if (!$offene): ?>
  <div class="bx-panel"><div class="muted">Alle Aufträge haben einen Preis. 👍</div></div>
<?php else: ?>
<div class="bx-tablewrap"><table class="bx-table">
  <thead><tr><th>Nummer</th><th>Kunde</th><th>Produkt</th><th class="bx-num">Menge</th><th>Rechnung hochladen (Preis wird übernommen)</th></tr></thead>
  <tbody>
    <?php foreach ($offene as $a): ?>
    <tr>
      <td><strong><?= h((string)$a['nummer']) ?></strong></td>
      <td><?= h((string)($a['kunde'] ?: '–')) ?></td>
      <td><?= h((string)($a['produkt'] ?: '–')) ?></td>
      <td class="bx-num"><?= number_format((int)$a['menge'], 0, ',', '.') ?></td>
      <td>
        <form method="post" enctype="multipart/form-data" class="bx-row" style="gap:8px;align-items:center;margin:0;flex-wrap:wrap" data-busy="Liest Rechnung…">
          <input type="hidden" name="aktion" value="upload">
          <input type="hidden" name="auftrag_id" value="<?= (int)$a['id'] ?>">
          <input type="file" name="dok" required accept="application/pdf,image/*">
          <label style="display:flex;gap:6px;align-items:center;font-size:12px;white-space:nowrap"><input type="checkbox" name="bezahlt" value="1"> bezahlt</label>
          <button class="btn btn-primary btn-sm" type="submit">Übernehmen</button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table></div>
<p class="muted" style="font-size:12px;margin-top:10px">Die KI liest die Positionszeilen (Herstellung/Kapseln, Dose/Glas, Etiketten …) und legt daraus eine verknüpfte Rechnung an; der Auftragspreis (Netto + VK/Stück) wird gefüllt. Beträge bitte stichprobenartig gegenprüfen.</p>
<?php endif; ?>
<?php render_footer();

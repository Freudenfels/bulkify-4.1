<?php
// Bestellung anlegen & bearbeiten – Positionen + Aktionen (bestellen / Wareneingang)
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';
require_once BX_ROOT . '/core/ki.php';

$id  = $_GET['id'] ?? 'neu';
$neu = ($id === 'neu' || !is_numeric($id));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aktion = $_POST['aktion'] ?? '';
    $lief = ($_POST['lieferant_id'] ?? '') !== '' ? (int)$_POST['lieferant_id'] : null;
    if ($aktion === 'liefern' && !$neu) { bestellung_wareneingang((int)$id); header('Location: ?p=bestellung&id=' . $id . '&geliefert=1'); exit; }
    // Ablauf beim Lieferanten – dieselben Aktionen nutzt spaeter das Lieferantenportal.
    if ($aktion === 'lief_bestaetigen' && !$neu) {
        $f = bestellung_bestaetigen((int)$id, (string)($_POST['eta_geplant'] ?? ''), trim((string)($_POST['wer'] ?? 'Team')));
        header('Location: ?p=bestellung&id=' . $id . ($f === '' ? '&ok=1' : '&fehler=' . urlencode($f))); exit;
    }
    if ($aktion === 'lief_station' && !$neu) {
        $f = bestellung_station_setzen((int)$id, (string)($_POST['station'] ?? ''), null);
        header('Location: ?p=bestellung&id=' . $id . ($f === '' ? '&ok=1' : '&fehler=' . urlencode($f))); exit;
    }
    if ($aktion === 'lief_versand' && !$neu) {
        q("UPDATE bestellung SET produktion_geplant=?, versandanbieter=?, versandart=?, tracking=? WHERE id=?", [
            trim((string)($_POST['produktion_geplant'] ?? '')) ?: null,
            mb_substr(trim((string)($_POST['versandanbieter'] ?? '')), 0, 60) ?: null,
            array_key_exists((string)($_POST['versandart'] ?? ''), versandart_liste()) ? $_POST['versandart'] : null,
            mb_substr(trim((string)($_POST['tracking'] ?? '')), 0, 120) ?: null, (int)$id]);
        header('Location: ?p=bestellung&id=' . $id . '&ok=1'); exit;
    }
    if ($aktion === 'bestellt' && !$neu) {
        q("UPDATE bestellung SET status='bestellt' WHERE id=?", [(int)$id]);
        // Der Lieferant erfährt es per Mail – wenn der Versand eingerichtet ist. Ein Fehler stoppt nichts.
        $f = mail_bereit() ? mail_lieferant_bestellung((int)$id) : '';
        header('Location: ?p=bestellung&id=' . $id . ($f === '' ? '&ok=1' : '&fehler=' . urlencode('Bestellung gespeichert, aber E-Mail nicht verschickt: ' . $f))); exit;
    }
    // Rückfrage an den Lieferanten – hängt an dieser Bestellung, der Lieferant sieht sie im Portal.
    if ($aktion === 'nachricht' && !$neu) {
        require_once BX_ROOT . '/core/nachricht.php';
        $lidB = (int) scalar("SELECT lieferant_id FROM bestellung WHERE id=?", [(int)$id]);
        $f = $lidB ? nachricht_post_verarbeiten($lidB, 'team', (string)(current_user()['name'] ?? 'Team'), 'bestellung', (int)$id) : 'Die Bestellung hat noch keinen Lieferanten.';
        header('Location: ?p=bestellung&id=' . $id . ($f === '' ? '&ok=1' : '&fehler=' . urlencode($f))); exit;
    }
    // Entwurf zurück in den Einkaufsbedarf: Entwurf löschen -> Bedarf erscheint wieder (kein Netting mehr).
    // Zusätzlich: eine KOMPLETT LEERE Bestellung (0 Positionen) darf immer gelöscht werden (egal welcher Status) –
    // sonst bleibt ein Geist-Datensatz im Lager als „Keine Positionen hinterlegt" stehen.
    if ($aktion === 'zurueck_bedarf' && !$neu) {
        $row = one("SELECT status, angekommen_am FROM bestellung WHERE id=?", [(int)$id]);
        $st = (string)($row['status'] ?? '');
        $posAnz = (int) scalar("SELECT COUNT(*) FROM bestellung_position WHERE bestellung_id=?", [(int)$id]);
        // Loeschbar: Entwurf ('offen'), komplett leere Bestellung (egal welcher Status) ODER eine bereits
        // "bestellte", die noch NICHT angekommen ist (zuruecknehmen -> Bedarf erscheint wieder in "Zu bestellen").
        $loeschbar = $st === 'offen' || $posAnz === 0 || ($st === 'bestellt' && empty($row['angekommen_am']));
        if ($loeschbar) {
            q("DELETE FROM bestellung_position WHERE bestellung_id=?", [(int)$id]);
            q("DELETE FROM bestellung WHERE id=?", [(int)$id]);
            bedarf_bump();   // offener Bedarf aendert sich -> Zu-bestellen-Liste sofort aktuell
            header('Location: ?p=einkauf&geloescht=1'); exit;
        }
        header('Location: ?p=bestellung&id=' . $id); exit;
    }
    // Bestellung in Einzelbestellungen je Produkt aufteilen (Alt-Bündel aufräumen). Lieferant wird informiert.
    if ($aktion === 'aufteilen' && !$neu) {
        $wer = (function_exists('current_user') && ($cu = current_user())) ? (string)($cu['name'] ?? '') : '';
        $res = bestellung_aufteilen((int)$id, $wer);
        if (!empty($res['ok']) && !empty($res['neu'])) {
            // Auf die erste neue Bestellung springen; alte ist storniert.
            $erste = (int) scalar("SELECT id FROM bestellung WHERE nummer=?", [(string)$res['neu'][0]]);
            header('Location: ?p=bestellung&id=' . ($erste ?: $id) . '&aufg=' . rawurlencode($res['msg'])); exit;
        }
        header('Location: ?p=bestellung&id=' . $id . '&fehler=' . urlencode($res['msg'] ?? 'Aufteilen nicht möglich.')); exit;
    }
    // B4: Erinnerung an den Portal-Lieferanten (unbestätigte Bestellung).
    if ($aktion === 'erinnern' && !$neu) {
        $f = bestellung_erinnerung_senden((int)$id);
        header('Location: ?p=bestellung&id=' . $id . ($f === '' ? '&ok=1' : '&fehler=' . urlencode($f))); exit;
    }
    // B4: Bestellung stornieren.
    if ($aktion === 'storno' && !$neu) {
        $wer = (function_exists('current_user') && ($cu = current_user())) ? (string)($cu['name'] ?? '') : 'Team';
        $res = bestellung_stornieren((int)$id, $wer ?: 'Team', trim((string)($_POST['grund'] ?? '')));
        header('Location: ?p=bestellung&id=' . $id . ($res['ok'] ? '&aufg=' . rawurlencode($res['msg']) : '&fehler=' . urlencode($res['msg']))); exit;
    }
    // KI-Erfassung: Lieferanten-Dokument (PDF/Foto/Tabelle) auslesen -> Positionen vorbelegen.
    if ($aktion === 'ki_lesen' && $neu) {
        if (!ki_bereit()) { header('Location: ?p=bestellung&id=neu&fehler=' . urlencode('KI ist nicht eingerichtet (nur auf beta).')); exit; }
        if (empty($_FILES['ki_datei']['tmp_name']) || !is_uploaded_file($_FILES['ki_datei']['tmp_name'])) {
            header('Location: ?p=bestellung&id=neu&fehler=' . urlencode('Bitte eine Datei auswählen.')); exit;
        }
        $ext = preg_replace('/[^a-z0-9]/', '', strtolower(pathinfo((string)$_FILES['ki_datei']['name'], PATHINFO_EXTENSION)));
        $tmp = BX_UPLOADS . '/ki_best_' . bin2hex(random_bytes(6)) . '.' . $ext;
        if (!move_uploaded_file($_FILES['ki_datei']['tmp_name'], $tmp)) { header('Location: ?p=bestellung&id=neu&fehler=' . urlencode('Datei konnte nicht gespeichert werden.')); exit; }
        $anw = "Dies ist ein Lieferanten-Dokument (Bestellung, Auftragsbestätigung, Angebot oder Rechnung) für einen "
             . "Nahrungsergänzungs-Lohnhersteller. Lies ALLE Positionen aus. Antworte NUR mit JSON in dieser Form:\n"
             . '{"lieferant":"<Firmenname oder leer>","waehrung":"EUR","positionen":[{"bezeichnung":"<Artikel>","menge":<Zahl>,"einheit":"kg/Stück/…","ek_preis":<Preis je Einheit oder null>}]}'
             . "\nMengen und Preise als Zahl mit Punkt als Dezimaltrennzeichen, ohne Tausenderpunkte. Preis je EINHEIT (nicht Positionssumme). Unbekannter Preis: null.";
        $r = ki_datei_frage($tmp, $anw, ['json' => true, 'zweck' => 'Bestellung erfassen', 'max_tokens' => 3000]);
        @unlink($tmp);
        if (empty($r['ok'])) { header('Location: ?p=bestellung&id=neu&fehler=' . urlencode('KI: ' . ($r['fehler'] ?? 'Dokument nicht lesbar.'))); exit; }
        $_SESSION['ki_best'] = $r['daten'] ?? [];
        header('Location: ?p=bestellung&id=neu&ki=1'); exit;
    }
    // Leere Bestellung verhindern: ohne Position sieht der Lieferant im Portal nichts (BE-xxxx „–").
    // Zählt eingehende Lagerartikel-Positionen + (beim Bearbeiten) erhaltene Bulk-/Freitext-Positionen.
    $neuePosEin = 0; foreach (($_POST['p_item'] ?? []) as $iid) if ((int)$iid > 0) $neuePosEin++;
    $bulkDa = (!$neu && (int)$id > 0) ? (int) scalar("SELECT COUNT(*) FROM bestellung_position WHERE bestellung_id=? AND item_id IS NULL", [(int)$id]) : 0;
    if ($neuePosEin + $bulkDa === 0) {
        header('Location: ?p=bestellung&id=' . ($neu ? 'neu' : (int)$id) . '&fehler=' . urlencode('Bitte mindestens eine Position (Artikel + Menge) hinzufügen, bevor die Bestellung gespeichert wird.')); exit;
    }
    if ($neu) {
        q("INSERT INTO bestellung (nummer,lieferant_id,status,notiz) VALUES (?,?,?,?)",
          [naechste_nummer('BE'), $lief, 'offen', trim($_POST['notiz'] ?? '')]);
        $id = insert_id();
    } else {
        q("UPDATE bestellung SET lieferant_id=?,notiz=? WHERE id=?", [$lief, trim($_POST['notiz'] ?? ''), (int)$id]);
    }
    // Positionen synchronisieren – NUR Lagerartikel-Positionen; Bulk-Freitext (item_id NULL) bleibt erhalten
    q("DELETE FROM bestellung_position WHERE bestellung_id=? AND item_id IS NOT NULL", [(int)$id]);
    $pi = $_POST['p_item'] ?? []; $pm = $_POST['p_menge'] ?? []; $pe = $_POST['p_ek'] ?? []; $pa = $_POST['p_auftrag'] ?? [];
    foreach ($pi as $i => $iid) {
        $iid = (int)$iid; if ($iid <= 0) continue;
        $menge = (float)str_replace(',', '.', $pm[$i] ?? '0');
        $ek = (float)str_replace(',', '.', $pe[$i] ?? '0');
        $einh = scalar("SELECT einheit FROM item WHERE id=?", [$iid]);
        $aid = ($pa[$i] ?? '') !== '' ? (int)$pa[$i] : null;
        q("INSERT INTO bestellung_position (bestellung_id,item_id,menge,ek_preis,einheit,auftrag_id,sort) VALUES (?,?,?,?,?,?,?)",
          [(int)$id, $iid, $menge, $ek, $einh, $aid, $i]);
    }
    header('Location: ?p=bestellung&id=' . $id . '&ok=1'); exit;
}

$b = $neu ? ['status'=>'offen'] : one("SELECT * FROM bestellung WHERE id=?", [(int)$id]);
if (!$b) { $neu = true; $b = ['status'=>'offen']; }
$v = fn($k) => h((string)($b[$k] ?? ''));
$geliefert = ($b['status'] ?? '') === 'geliefert';

$lieferanten = all("SELECT id, firma FROM lieferanten ORDER BY firma");
$items = all("SELECT id, name, einheit, ek_preis, kategorie FROM item WHERE kategorie IN ('rohstoff','verpackung','verbrauch') AND gesperrt=0 ORDER BY name");
$positionen = $neu ? [] : all("SELECT * FROM bestellung_position WHERE bestellung_id=? AND item_id IS NOT NULL ORDER BY sort,id", [(int)$id]);
$posGesamt  = $neu ? 0 : (int) scalar("SELECT COUNT(*) FROM bestellung_position WHERE bestellung_id=?", [(int)$id]);   // inkl. Bulk-/Freitext-Positionen (item_id NULL)
$bulkPositionen = $neu ? [] : all("SELECT * FROM bestellung_position WHERE bestellung_id=? AND item_id IS NULL ORDER BY sort,id", [(int)$id]);
$EK = []; foreach ($items as $it) $EK[$it['id']] = (float)$it['ek_preis'];

// KI-Vorbelegung: Ergebnis der Dokument-Auslesung auf Lieferant + Lagerartikel abbilden.
$kiRaw = null; $kiTreffer = [];
if ($neu && isset($_GET['ki']) && !empty($_SESSION['ki_best'])) {
    $kiRaw = (array) $_SESSION['ki_best']; unset($_SESSION['ki_best']);
    $norm = fn($s) => preg_replace('/[^a-z0-9]/', '', mb_strtolower(trim((string)$s)));
    // Lieferant zuordnen (exakt normiert, sonst enthält-Treffer)
    $ln = $norm($kiRaw['lieferant'] ?? '');
    if ($ln !== '') foreach ($lieferanten as $l) {
        $fn = $norm($l['firma']); if ($fn === '') continue;
        if ($fn === $ln || str_contains($ln, $fn) || str_contains($fn, $ln)) { $b['lieferant_id'] = (int)$l['id']; break; }
    }
    // Positionen auf Artikel abbilden; längster Namensüberlapp gewinnt (sonst passt „Zink" auf alles)
    $kiPos = [];
    foreach ((array)($kiRaw['positionen'] ?? []) as $p) {
        $bez = trim((string)($p['bezeichnung'] ?? '')); if ($bez === '') continue;
        $bn = $norm($bez); $mid = 0; $best = 0;
        foreach ($items as $it) {
            $inn = $norm($it['name']); if ($inn === '') continue;
            if ($inn === $bn) { $mid = (int)$it['id']; break; }
            if (str_contains($bn, $inn) || str_contains($inn, $bn)) { $len = min(strlen($inn), strlen($bn)); if ($len > $best) { $best = $len; $mid = (int)$it['id']; } }
        }
        $menge = (float) str_replace(',', '.', (string)($p['menge'] ?? 0));
        $ek    = ($p['ek_preis'] ?? null) !== null && $p['ek_preis'] !== '' ? (float) str_replace(',', '.', (string)$p['ek_preis']) : '';
        $kiPos[]     = ['item_id' => $mid, 'menge' => $menge > 0 ? $menge : '', 'ek_preis' => $ek, 'auftrag_id' => ''];
        $kiTreffer[] = ['bez' => $bez, 'item_id' => $mid, 'einheit' => trim((string)($p['einheit'] ?? '')), 'menge' => $menge, 'ek' => $ek];
    }
    if ($kiPos) $positionen = $kiPos;   // Formular unten mit den erkannten Zeilen vorbelegen
}

function pos_options(array $items, $sel): string {
    $s = '<option value="">– Artikel wählen –</option>';
    foreach ($items as $it) $s .= '<option value="' . (int)$it['id'] . '"' . ((int)$sel === (int)$it['id'] ? ' selected' : '') . '>' . h($it['name']) . ' (' . h($it['einheit']) . ')</option>';
    return $s;
}
// Offene Kundenaufträge, damit eine Bestellposition „für Auftrag X" markiert werden kann (Baustein 4)
$auftraege = all("SELECT a.id, a.nummer, COALESCE(NULLIF(p.kundenname,''), p.name) AS produkt, k.firma
                  FROM auftrag a LEFT JOIN produkt p ON p.id=a.produkt_id LEFT JOIN kunden k ON k.id=a.kunde_id
                  WHERE a.status <> 'versendet' ORDER BY a.angelegt DESC");
function auftrag_options(array $auftraege, $sel): string {
    $s = '<option value="">Lager / allgemein</option>';
    foreach ($auftraege as $a) {
        $lbl = $a['nummer'] . ($a['produkt'] ? ' · ' . $a['produkt'] : '') . ($a['firma'] ? ' · ' . $a['firma'] : '');
        $s .= '<option value="' . (int)$a['id'] . '"' . ((int)$sel === (int)$a['id'] ? ' selected' : '') . '>' . h($lbl) . '</option>';
    }
    return $s;
}

render_header('einkauf', $neu ? 'Neue Bestellung' : ($b['nummer'] ?? 'Bestellung'));
bx_head($neu ? 'Neue Bestellung' : $v('nummer'), $neu ? 'Beim Lieferanten bestellen' : 'Bestellung bearbeiten',
        ($neu ? '' : '<a class="btn btn-ghost" style="margin-right:8px" target="_blank" title="Bestellung als PDF – das schickst du dem Lieferanten" href="?p=bestellung_pdf&id=' . (int)$id . '">&#8681; PDF</a>')
        . bx_btn('Zurück zur Liste', '?p=einkauf', 'ghost'));
if (isset($_GET['ok']))        echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Gespeichert.</div>';
if (isset($_GET['geliefert'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Als geliefert verbucht – Chargen im Wareneingang (Quarantäne) angelegt.</div>';
if (isset($_GET['fehler']))  echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:12px 16px">' . h((string)$_GET['fehler']) . '</div>';
if (isset($_GET['aufg']))      echo '<div class="bx-panel badge-ok" style="padding:12px 16px">' . h((string)$_GET['aufg']) . '</div>';
?>
<?php if ($neu && ki_bereit()): ?>
<div class="bx-panel" style="border-color:var(--gruen)">
  <h2 style="margin-top:0">KI-Erfassung aus Dokument</h2>
  <p class="muted" style="margin-top:0">Bestellbestätigung, Angebot oder Rechnung des Lieferanten hochladen (PDF, Foto, CSV/Excel). Die KI liest Lieferant und alle Positionen aus und füllt das Formular unten – zum Prüfen, nicht blind speichern.</p>
  <form method="post" enctype="multipart/form-data" class="bx-row" style="gap:10px;align-items:flex-end;flex-wrap:wrap">
    <input type="hidden" name="aktion" value="ki_lesen">
    <div class="bx-field" style="margin:0"><label>Dokument</label>
      <input type="file" name="ki_datei" required accept=".pdf,.png,.jpg,.jpeg,.webp,.csv,.tsv,.txt,.xlsx"></div>
    <button class="btn btn-primary" type="submit" data-busy="Liest Dokument…">Auslesen</button>
    <span class="muted" style="font-size:12px;align-self:center">dauert 10–60 Sekunden</span>
  </form>
</div>
<?php endif; ?>
<?php if ($kiTreffer): ?>
<div class="bx-panel badge-ok" style="padding:14px 16px">
  <strong><?= count($kiTreffer) ?> Position(en) erkannt</strong> – bitte prüfen, Zuordnung/Preise korrigieren und unten speichern.
  <div class="bx-tablewrap" style="margin-top:10px"><table class="bx-table">
    <thead><tr><th>Erkannt (Dokument)</th><th>Zugeordnet</th><th class="bx-num">Menge</th><th class="bx-num">EK</th></tr></thead>
    <tbody>
    <?php foreach ($kiTreffer as $t): $itName = $t['item_id'] ? (string) scalar("SELECT name FROM item WHERE id=?", [$t['item_id']]) : ''; ?>
      <tr><td><?= h($t['bez']) ?><?= $t['einheit'] !== '' ? ' <span class="muted">(' . h($t['einheit']) . ')</span>' : '' ?></td>
          <td><?= $itName !== '' ? h($itName) : '<span style="color:#8f231b">nicht gefunden – unten Artikel wählen</span>' ?></td>
          <td class="bx-num"><?= $t['menge'] > 0 ? h(rtrim(rtrim(number_format($t['menge'],3,',','.'),'0'),',')) : '–' ?></td>
          <td class="bx-num"><?= $t['ek'] !== '' ? h(number_format((float)$t['ek'],4,',','.')) . ' &euro;' : '–' ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php endif; ?>
<?php
if (!$neu) {
    // B6: Status GROSS ganz oben, darunter Lieferant + Bestelldatum + zugesagter Termin.
    $stufe = bestellung_stufe($b);
    $liefName = (string) scalar("SELECT firma FROM lieferanten WHERE id=?", [(int)($b['lieferant_id'] ?? 0)]);
    $stufeFarbe = ['ok'=>'var(--gruen,#1D9E75)','warn'=>'var(--warn,#8a5a12)','err'=>'#8f231b','info'=>'inherit',''=>'inherit'][$stufe['kind']] ?? 'inherit';
    echo '<div class="bx-panel" style="padding:18px 20px">'
       . '<div style="font-size:13px;color:var(--muted)">Bestellung ' . h((string)$b['nummer']) . '</div>'
       . '<div style="font-size:24px;font-weight:700;margin:2px 0 6px;color:' . $stufeFarbe . '">' . h($stufe['label']) . '</div>'
       . '<div class="muted" style="font-size:14px">Lieferant: <strong>' . ($liefName !== '' ? h($liefName) : (($b['bestellart'] ?? '') === 'online' ? 'Online-Einkauf (ohne Lieferant)' : '–')) . '</strong>'
       . (!empty($b['bestelldatum']) ? ' · bestellt am ' . h(date('d.m.Y', strtotime((string)$b['bestelldatum']))) : '')
       . (!empty($b['eta_geplant']) ? ' · zugesagter Termin ' . h(date('d.m.Y', strtotime((string)$b['eta_geplant']))) : '')
       . (!empty($b['angekommen_am']) ? ' · angekommen ' . h(date('d.m.Y', strtotime((string)$b['angekommen_am']))) : '')
       . '</div></div>';
    // B6: Aktionen erst sammeln (Output-Buffer) und GANZ UNTEN ausgeben.
    ob_start();
    echo '<div class="bx-panel"><div class="bx-row" style="justify-content:space-between;align-items:center">';
    echo '<div>Status: ' . (match($b['status']){'offen'=>bx_badge('offen','info'),'bestellt'=>bx_badge('bestellt','warn'),'geliefert'=>bx_badge('geliefert','ok'),default=>bx_badge(status_text($b['status']))}) . '</div><div class="bx-row">';
    if ($b['status'] === 'offen')    echo '<form method="post" style="display:inline"><input type="hidden" name="aktion" value="zurueck_bedarf"><button class="btn btn-ghost btn-sm" type="submit" title="Diesen Entwurf verwerfen – der Bedarf erscheint wieder im Einkaufsbedarf">Zurück in den Einkaufsbedarf</button></form> ';
    if ($b['status'] !== 'offen' && $posGesamt === 0) echo '<form method="post" style="display:inline" onsubmit="return confirm(\'Diese leere Bestellung löschen?\');"><input type="hidden" name="aktion" value="zurueck_bedarf"><button class="btn btn-ghost btn-sm" type="submit" style="color:#8f231b" title="Diese Bestellung hat keine Positionen – löschen">Leere Bestellung löschen</button></form> ';
    if ($b['status'] === 'bestellt' && $posGesamt > 0 && empty($b['angekommen_am'])) echo '<form method="post" style="display:inline" onsubmit="return confirm(\'Bestellung zurückziehen und löschen? Der Bedarf erscheint wieder unter „Zu bestellen“.\');"><input type="hidden" name="aktion" value="zurueck_bedarf"><button class="btn btn-ghost btn-sm" type="submit" style="color:#8f231b" title="Bestellung ist noch nicht angekommen – zurückziehen und löschen (Bedarf kommt zurück)">Bestellung zurückziehen</button></form> ';
    if ($b['status'] === 'offen')    echo '<form method="post" style="display:inline"><input type="hidden" name="aktion" value="bestellt"><button class="btn btn-ghost btn-sm" type="submit">als bestellt markieren</button></form>';
    if ($b['status'] === 'bestellt') echo '<form method="post" style="display:inline"><input type="hidden" name="aktion" value="liefern"><button class="btn btn-primary btn-sm" type="submit">Wareneingang buchen</button></form>';
    // Aufteilen: nur bei >= 2 verschiedenen Bulk-Produkten und solange nicht geliefert/storniert.
    $bulkProdGrp = (int) scalar("SELECT COUNT(DISTINCT TRIM(SUBSTRING_INDEX(SUBSTRING(bezeichnung,7),' (',1)))
                                 FROM bestellung_position WHERE bestellung_id=? AND item_id IS NULL AND bezeichnung LIKE 'Bulk%'", [(int)$id]);
    if ($bulkProdGrp >= 2 && !in_array((string)$b['status'], ['geliefert','storniert'], true) && empty($b['angekommen_am']))
        echo '<form method="post" style="display:inline" onsubmit="return confirm(\'Diese Bestellung in ' . (int)$bulkProdGrp . ' Einzelbestellungen je Produkt aufteilen? Ein bereits bestätigter Stand (Annahme/Termin) wird übernommen. Die alte wird storniert und der Lieferant informiert.\');"><input type="hidden" name="aktion" value="aufteilen"><button class="btn btn-ghost btn-sm" type="submit" title="Je Produkt eine eigene Bestellung – ein schon zugesagter Stand wird übernommen, sonst kann der Lieferant jedes Produkt einzeln annehmen/ablehnen">In Einzelbestellungen aufteilen</button></form>';
    // B4: Erinnern (Portal-Lieferant, noch nicht bestätigt) + Stornieren (solange nicht geliefert/angekommen).
    if (bestellung_ist_portal($b) && (int)($b['bestaetigt'] ?? 0) !== 1 && !in_array((string)$b['status'], ['geliefert','storniert','offen'], true)) {
        $ueberf = bestellung_ueberfaellig($b);
        echo '<form method="post" style="display:inline"><input type="hidden" name="aktion" value="erinnern"><button class="btn ' . ($ueberf ? 'btn-primary' : 'btn-ghost') . ' btn-sm" type="submit" title="Erinnerungsmail an den Lieferanten senden">Erinnern' . ($ueberf ? ' (überfällig · T+' . bestellung_tage_offen($b) . ')' : (!empty($b['erinnert_am']) ? ' (erneut)' : '')) . '</button></form>';
    }
    if (!in_array((string)$b['status'], ['geliefert','storniert'], true) && empty($b['angekommen_am']))
        echo '<form method="post" style="display:inline" onsubmit="return confirm(\'Diese Bestellung stornieren?' . (bestellung_ist_portal($b) ? ' Der Lieferant wird informiert.' : '') . '\');"><input type="hidden" name="aktion" value="storno"><button class="btn btn-ghost btn-sm" type="submit" style="color:#8f231b" title="Bestellung stornieren">Stornieren</button></form>';
    echo '</div></div></div>';
    $aktionenHtml = ob_get_clean();   // B6: unten ausgeben

    // B6: Positionen-Überblick (bestellt / geliefert) mit Auftragsbezug – read-only, ganz oben.
    $posUeb = all("SELECT bp.id, bp.menge, bp.einheit, bp.item_id, bp.auftrag_id,
                          COALESCE(NULLIF(bp.bezeichnung,''), i.name, '–') AS pname, a.nummer AS auftrag_nr,
                          (SELECT COALESCE(SUM(c.menge),0) FROM charge c WHERE c.bestellung_position_id=bp.id) AS geliefert
                   FROM bestellung_position bp
                   LEFT JOIN item i ON i.id=bp.item_id
                   LEFT JOIN auftrag a ON a.id=bp.auftrag_id
                   WHERE bp.bestellung_id=? ORDER BY bp.sort, bp.id", [(int)$id]);
    if ($posUeb) {
        // Bulk-Positionen desselben Produkts zu einer Zeile bündeln (bestellt + geliefert summieren).
        $basis = fn(string $p) => trim(preg_replace('/\s*\(.*$/', '', preg_replace('/^Bulk:\s*/', '', $p)));
        $zeilen = [];
        foreach ($posUeb as $p) {
            $istBulk = empty($p['item_id']) && strncmp((string)$p['pname'], 'Bulk', 4) === 0;
            $name = $istBulk ? $basis((string)$p['pname']) : (string)$p['pname'];
            $key = $istBulk ? 'b:' . mb_strtolower($name) : 'p:' . (int)$p['id'];
            if (!isset($zeilen[$key])) $zeilen[$key] = ['name'=>$name, 'einheit'=>(string)$p['einheit'], 'bestellt'=>0.0, 'geliefert'=>0.0, 'auftraege'=>[]];
            $zeilen[$key]['bestellt']  += (float)$p['menge'];
            $zeilen[$key]['geliefert'] += (float)$p['geliefert'];
            if (!empty($p['auftrag_nr'])) $zeilen[$key]['auftraege'][(string)$p['auftrag_nr']] = (int)$p['auftrag_id'];
        }
        $mz = fn($x) => rtrim(rtrim(number_format((float)$x, 3, ',', '.'), '0'), ',');
        echo '<div class="bx-panel"><h2 style="margin-top:0">Positionen</h2>';
        echo '<div class="bx-tablewrap"><table class="bx-table"><thead><tr><th>Position</th><th>Für Auftrag</th><th class="bx-num">bestellt</th><th class="bx-num">geliefert</th></tr></thead><tbody>';
        foreach ($zeilen as $z) {
            $auf = '<span class="muted">Lager / allgemein</span>';
            if ($z['auftraege']) { $parts = []; foreach ($z['auftraege'] as $nr => $aid) $parts[] = '<a href="?p=auftrag&id=' . (int)$aid . '">' . h((string)$nr) . '</a>'; $auf = implode(', ', $parts); }
            $gel = (float)$z['geliefert'];
            $gelHtml = $gel > 0.0001 ? $mz($gel) . ' ' . h($z['einheit']) : '<span class="muted">–</span>';
            if ($gel + 0.0001 >= (float)$z['bestellt'] && (float)$z['bestellt'] > 0) $gelHtml = '<span class="bx-ok">' . $mz($gel) . ' ' . h($z['einheit']) . '</span>';
            echo '<tr><td>' . h($z['name']) . '</td><td style="font-size:13px">' . $auf . '</td>'
               . '<td class="bx-num">' . $mz($z['bestellt']) . ' ' . h($z['einheit']) . '</td>'
               . '<td class="bx-num">' . $gelHtml . '</td></tr>';
        }
        echo '</tbody></table></div><p class="muted" style="font-size:12px;margin:10px 0 0">Bestellte vs. bereits gelieferte Menge je Position (geliefert = eingebuchte Chargen). Bearbeiten unten im Formular.</p></div>';
    }

    // Online-Einkauf (Mitarbeiter hat selbst online gekauft): Händler/Zahlart/Preis – fürs Gegenrechnen.
    if (($b['bestellart'] ?? '') === 'online') {
        $zl = bestellung_zahlarten();
        $za = $zl[(string)($b['online_zahlart'] ?? '')] ?? '';
        $pr = ($b['online_preis'] ?? null) !== null ? number_format((float)$b['online_preis'], 2, ',', '.') . ' ' . h((string)($b['online_waehrung'] ?: 'EUR')) : '–';
        // B3: zur Bestellung abgelegte Rechnung(en) (dokument typ='rechnung').
        $rechn = all("SELECT id, titel, datei_orig FROM dokument WHERE objekt_typ='bestellung' AND objekt_id=? AND typ='rechnung' ORDER BY id DESC", [(int)$id]);
        $rechnHtml = '';
        foreach ($rechn as $rd) $rechnHtml .= '<a href="?p=dokument&id=' . (int)$rd['id'] . '" target="_blank" style="white-space:nowrap;text-decoration:none;margin-right:12px">&#11015;&#65039; ' . h((string)($rd['datei_orig'] ?: $rd['titel'] ?: 'Rechnung')) . '</a>';
        echo '<div class="bx-panel"><h2 style="margin-top:0">Online-Einkauf</h2><div class="bx-cards">'
           . '<div class="bx-card"><div class="k">Händler / Quelle</div><div class="v">' . (((string)($b['online_haendler'] ?? '')) !== '' ? h((string)$b['online_haendler']) : '<span class="muted">–</span>') . '</div></div>'
           . '<div class="bx-card"><div class="k">Zahlungsart</div><div class="v">' . ($za !== '' ? h($za) : '<span class="muted">–</span>') . '</div></div>'
           . '<div class="bx-card"><div class="k">Preis (gesamt)</div><div class="v">' . $pr . '</div></div>'
           . '<div class="bx-card"><div class="k">Rechnung</div><div class="v" style="font-size:14px">' . ($rechnHtml !== '' ? $rechnHtml : '<span class="muted">keine hochgeladen</span>') . '</div></div>'
           . '</div><p class="muted" style="font-size:12px;margin:8px 0 0">Selbst online gekauft – diese Angaben helfen der Buchhaltung beim Gegenrechnen von Einnahmen und Ausgaben.</p></div>';
    }

    // Ablauf beim Lieferanten – dasselbe Panel sieht der Lieferant in seinem Portal.
    require_once BX_ROOT . '/core/bestellung_ui.php';
    echo bestellung_ablauf_panel($b, 'team', 'de');
    // Rückfragen zu dieser Bestellung – dasselbe Gespräch sieht der Lieferant im Portal.
    if ($b['lieferant_id']) { require_once BX_ROOT . '/core/nachricht.php'; echo nachricht_panel((int)$b['lieferant_id'], 'team', 'de', 'bestellung', (int)$id); }

    // Kontext: für welche(n) Auftrag/Produkt/Kunde wird bestellt? Damit die Dringlichkeit einschätzbar ist.
    $kontext = all("SELECT DISTINCT a.id, a.nummer, a.menge, a.stueck,
                           COALESCE(NULLIF(p.kundenname,''), p.name) AS produkt, k.firma AS kunde,
                           pa.id AS pa_id, pa.prio, pa.geplant_am
                    FROM bestellung_position bp
                    JOIN auftrag a ON a.id=bp.auftrag_id
                    LEFT JOIN produkt p ON p.id=a.produkt_id
                    LEFT JOIN kunden k ON k.id=a.kunde_id
                    LEFT JOIN produktionsauftrag pa ON pa.auftrag_id=a.id
                    WHERE bp.bestellung_id=? ORDER BY pa.prio, a.nummer", [(int)$id]);
    if ($kontext) {
        echo '<div class="bx-panel"><h2 style="margin-top:0">Wofür wird bestellt</h2>';
        echo '<div class="bx-tablewrap"><table class="bx-table"><thead><tr><th>Auftrag</th><th>Produkt</th><th>Kunde</th><th class="bx-num">Menge</th><th>Priorität</th><th>Geplant am</th><th>Bereitschaft</th></tr></thead><tbody>';
        foreach ($kontext as $c) {
            $mengeTxt = (int)$c['menge'] . ' Pkg' . ((int)$c['stueck'] ? ' &times; ' . (int)$c['stueck'] : '');
            $ber  = $c['pa_id'] ? bereitschaft_badge(produktion_bereitschaft((int)$c['pa_id'])['status']) : '<span class="muted">–</span>';
            $ziel = $c['pa_id'] ? '?p=produktionsauftrag&id=' . (int)$c['pa_id'] : '?p=auftrag&id=' . (int)$c['id'];
            echo '<tr onclick="location.href=\'' . $ziel . '\'" style="cursor:pointer">';
            echo '<td>' . h($c['nummer']) . '</td>';
            echo '<td>' . h($c['produkt'] ?: '–') . '</td>';
            echo '<td>' . ($c['kunde'] ? h($c['kunde']) : '<span class="muted">–</span>') . '</td>';
            echo '<td class="bx-num">' . $mengeTxt . '</td>';
            echo '<td>' . prio_badge((int)($c['prio'] ?? 2)) . '</td>';
            echo '<td>' . ($c['geplant_am'] ? h(date('d.m.Y', strtotime($c['geplant_am']))) : '<span class="muted">–</span>') . '</td>';
            echo '<td>' . $ber . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
        echo '<p class="muted" style="font-size:12px;margin:10px 0 0">Priorität und geplantes Produktionsdatum zeigen, wie dringend diese Bestellung ist. Zeile öffnet den Produktionsauftrag.</p>';
        echo '</div>';
    }
    // (Bulk-Positionen sind oben in der Positionen-Übersicht enthalten – inkl. bestellt/geliefert je Produkt.)
    // Etiketten dieser Bestellung: Druckdatei zum Herunterladen (für den Lieferanten/die Druckerei) + Maße + Produkt.
    $etikettPos = all("SELECT bp.auftrag_id, i.name FROM bestellung_position bp
                       JOIN item i ON i.id=bp.item_id
                       WHERE bp.bestellung_id=? AND i.verpackung_rolle='etikett' AND bp.auftrag_id IS NOT NULL
                       GROUP BY bp.auftrag_id, i.name", [(int)$id]);
    if ($etikettPos) {
        echo '<div class="bx-panel"><h2 style="margin-top:0">Etiketten (Druckdateien)</h2>';
        echo '<div class="bx-tablewrap"><table class="bx-table"><thead><tr><th>Etikett</th><th>Produkt</th><th>Maße</th><th>Druckdatei</th></tr></thead><tbody>';
        foreach ($etikettPos as $ep) {
            $ei = etikett_info((int)$ep['auftrag_id']);
            $dl = !empty($ei['dok'])
                ? '<a href="?p=dokument&id=' . (int)$ei['dok']['id'] . '" target="_blank" style="white-space:nowrap;text-decoration:none">&#11015;&#65039; ' . h($ei['dok']['datei_orig'] ?: 'Etikett') . '</a>'
                : bx_badge('kein Design hochgeladen', 'warn');
            echo '<tr><td>' . h($ep['name']) . '</td>'
               . '<td>' . ($ei['produkt'] ? h($ei['produkt']) : '<span class="muted">–</span>') . '</td>'
               . '<td>' . ($ei['masse'] ? h($ei['masse']['label']) : '<span class="muted">–</span>') . '</td>'
               . '<td>' . $dl . '</td></tr>';
        }
        echo '</tbody></table></div><p class="muted" style="font-size:12px;margin:10px 0 0">Kundenspezifische Etiketten. Druckdatei herunterladen und an die Druckerei/den Lieferanten weitergeben.</p></div>';
    }
}
?>
<form method="post" class="bx-form">
  <fieldset <?= $geliefert ? 'disabled' : '' ?> style="border:0;padding:0;margin:0;min-width:0">
  <div class="bx-panel"><div class="bx-grid">
    <div class="bx-field"><label>Lieferant</label>
      <select name="lieferant_id"><option value="">– keiner –</option>
        <?php foreach ($lieferanten as $l): ?><option value="<?= $l['id'] ?>" <?= (int)($b['lieferant_id']??0)===(int)$l['id']?'selected':'' ?>><?= h($l['firma']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="bx-field"><label>Notiz</label><input type="text" name="notiz" value="<?= $v('notiz') ?>"></div>
  </div></div>

  <div class="bx-panel">
    <h2>Positionen</h2>
    <table class="bx-table">
      <thead><tr><th style="width:34%">Artikel</th><th>Für Auftrag <?= bx_hint('Wofür wird das bestellt? Beim Wareneingang wird die Charge automatisch diesem Auftrag zugeordnet. „Lager / allgemein" = Vorrat.') ?></th><th style="width:120px">Menge</th><th style="width:120px">EK / Einheit</th><th class="bx-num">Summe</th><th></th></tr></thead>
      <tbody id="prows">
        <?php $pr = $positionen ?: [['item_id'=>'','menge'=>'','ek_preis'=>'','auftrag_id'=>'']]; foreach ($pr as $p): ?>
        <tr class="prow">
          <td><select name="p_item[]" class="pitem"><?= pos_options($items, $p['item_id']) ?></select></td>
          <td><select name="p_auftrag[]" class="pauf"><?= auftrag_options($auftraege, $p['auftrag_id'] ?? '') ?></select></td>
          <td><input type="number" step="0.001" class="pmenge" name="p_menge[]" value="<?= h($p['menge']!==''&&$p['menge']!==null ? rtrim(rtrim(number_format((float)$p['menge'],3,'.',''),'0'),'.') : '') ?>"></td>
          <td><input type="number" step="0.0001" class="pek" name="p_ek[]" value="<?= h($p['ek_preis']!==''&&$p['ek_preis']!==null ? rtrim(rtrim(number_format((float)$p['ek_preis'],4,'.',''),'0'),'.') : '') ?>"></td>
          <td class="bx-num psum">–</td>
          <td><button type="button" class="btn btn-ghost btn-sm" onclick="this.closest('.prow').remove();psum()">×</button></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot><tr><th colspan="4" style="text-align:right">Gesamt</th><th class="bx-num" id="pgesamt">–</th><th></th></tr></tfoot>
    </table>
    <button type="button" class="btn btn-ghost btn-sm" id="addP">+ Position</button>
  </div>
  </fieldset>
  <div class="bx-row" style="margin-top:var(--sp-4)">
    <?php if (!$geliefert): ?><button class="btn btn-primary" type="submit">Speichern</button><?php endif; ?>
    <a class="btn btn-ghost" href="?p=einkauf">Zurück</a>
  </div>
</form>

<script>
var EK = <?= json_encode($EK, JSON_UNESCAPED_UNICODE) ?>;
var OPT = <?= json_encode(pos_options($items, 0), JSON_UNESCAPED_UNICODE) ?>;
var OPTA = <?= json_encode(auftrag_options($auftraege, 0), JSON_UNESCAPED_UNICODE) ?>;
function eur(x){ return x.toLocaleString('de-DE',{minimumFractionDigits:2,maximumFractionDigits:2})+' €'; }
function psum(){
  var g=0;
  document.querySelectorAll('.prow').forEach(function(r){
    var m=parseFloat((r.querySelector('.pmenge').value||'').replace(',','.'))||0;
    var e=parseFloat((r.querySelector('.pek').value||'').replace(',','.'))||0;
    var s=m*e; g+=s;
    r.querySelector('.psum').textContent = (m&&e)?eur(s):'–';
  });
  document.getElementById('pgesamt').textContent = g?eur(g):'–';
}
(function(){
  document.getElementById('addP').addEventListener('click',function(){
    var tr=document.createElement('tr'); tr.className='prow';
    tr.innerHTML='<td><select name="p_item[]" class="pitem">'+OPT+'</select></td>'
      +'<td><select name="p_auftrag[]" class="pauf">'+OPTA+'</select></td>'
      +'<td><input type="number" step="0.001" class="pmenge" name="p_menge[]"></td>'
      +'<td><input type="number" step="0.0001" class="pek" name="p_ek[]"></td>'
      +'<td class="bx-num psum">–</td><td><button type="button" class="btn btn-ghost btn-sm">×</button></td>';
    tr.querySelector('button').addEventListener('click',function(){tr.remove();psum();});
    wire(tr); document.getElementById('prows').appendChild(tr);
  });
  function wire(row){
    row.querySelector('.pitem').addEventListener('change',function(){ var ek=EK[this.value]; if(ek!==undefined && !row.querySelector('.pek').value) row.querySelector('.pek').value=ek; psum(); });
    row.querySelector('.pmenge').addEventListener('input',psum);
    row.querySelector('.pek').addEventListener('input',psum);
  }
  document.querySelectorAll('.prow').forEach(wire);
  psum();
})();
</script>
<?php // Vom Lieferanten zu dieser Bestellung hochgeladene Dokumente (CoA, Rechnung zum Bezahlen …).
if (!$neu):
  require_once BX_ROOT . '/core/lieferant_dateien.php';
  $bdoks = bestell_dokumente((int)$id);
  $BDTYP = ['coa'=>'CoA / Analysenzertifikat','rechnung'=>'Rechnung','spec'=>'Spezifikation','analyse'=>'Laboranalyse','sonstiges'=>'Sonstiges'];
  if ($bdoks): ?>
  <div class="bx-panel">
    <h2 style="margin-top:0">Dokumente vom Lieferanten</h2>
    <div class="bx-tablewrap"><table class="bx-table">
      <thead><tr><th>Typ</th><th>Datei</th><th>Datum</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($bdoks as $d): ?>
        <tr><td><?= h($BDTYP[$d['typ']] ?? $d['typ']) ?><?= $d['typ']==='rechnung' ? ' ' . bx_badge('Rechnung','warn') : '' ?></td>
            <td><?= h($d['titel'] ?: ($d['datei_orig'] ?: 'Dokument')) ?></td>
            <td class="muted"><?= h(date('d.m.Y', strtotime((string)$d['angelegt']))) ?></td>
            <td style="text-align:right"><a class="btn btn-ghost btn-sm" target="_blank" href="?p=dokument&id=<?= (int)$d['id'] ?>">öffnen</a></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
  <?php endif; endif; ?>
<?php
// B6: Aktionen GANZ UNTEN (oben nur der große Status-Kopf). Panel wurde oben in $aktionenHtml gepuffert.
if (!$neu && isset($aktionenHtml)) {
    echo '<h2 style="margin:18px 0 8px">Aktionen</h2>';
    echo $aktionenHtml;
    // Verlauf dieser Bestellung (Aktivitätslog).
    $vb = function_exists('verlauf_fuer') ? verlauf_fuer('bestellung', (int)$id) : [];
    if ($vb) { echo '<div class="bx-panel"><h2 style="margin-top:0">Verlauf</h2>'; bx_chat($vb, (string)$b['nummer']); echo '</div>'; }
}
render_footer(); ?>

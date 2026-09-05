<?php
// Angebot bearbeiten (Hybrid): Kopf (Kunde/Produkt/Status/Marge/Produktionszeit/Notiz)
// + Positionen automatisch erzeugt & überschreibbar, mit interner Marge (nur intern).
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';
require_once BX_ROOT . '/core/anfrage_ui.php';   // Preisanfrage-Popup + Status-Badges

$id  = $_GET['id'] ?? 'neu';
$neu = ($id === 'neu' || !is_numeric($id));

$fehler = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aktion = $_POST['aktion'] ?? 'kopf_save';
    $f = fn($k) => trim($_POST[$k] ?? '');

    if ($aktion === 'kopf_save') {
        $kunde_id   = ($_POST['kunde_id'] ?? '') !== '' ? (int)$_POST['kunde_id'] : null;
        $produkt_id = ($_POST['produkt_id'] ?? '') !== '' ? (int)$_POST['produkt_id'] : null;
        // Ein Produkt im Kopf ist OPTIONAL: Es dient nur der Preismatrix, aus der der Kunde im Portal eine
        // Zelle wählt. Ein Angebot kann genauso aus Positionen bestehen (Rezeptur, Rohstoff, Dienstleistung) –
        // gerade bei einer Rezeptur-Anfrage gibt es noch gar kein Produkt, es entsteht erst aus dem Angebot.
        {
            // Der Status wird NICHT über die Kopfdaten gesetzt – dafür gibt es die Knöpfe „An Kunden senden"
            // und „Zurückziehen". Speichern darf niemals versehentlich etwas beim Kunden sichtbar machen.
            $status  = $neu ? 'offen' : (string) (scalar("SELECT status FROM angebot WHERE id=?", [(int)$id]) ?: 'offen');
            $gueltig = $f('gueltig_bis') !== '' ? $f('gueltig_bis') : null;
            $marge   = $f('marge') !== '' ? (float)str_replace(',', '.', $f('marge')) : null;
            $pz      = $f('produktionszeit') !== '' ? (float)str_replace(',', '.', $f('produktionszeit')) : null;
            if ($produkt_id && (int) scalar("SELECT COUNT(*) FROM produkt_preis WHERE produkt_id=?", [$produkt_id]) === 0) produkt_matrix_generieren($produkt_id);
            if ($neu) {
                q("INSERT INTO angebot (nummer,kunde_id,produkt_id,status,gueltig_bis,notiz,marge_override,produktionszeit_wochen) VALUES (?,?,?,?,?,?,?,?)",
                  [naechste_nummer('AN'), $kunde_id, $produkt_id, $status, $gueltig, $f('notiz'), $marge, $pz]);
                $id = insert_id();
                if ($kunde_id) log_aktivitaet('kunde', $kunde_id, 'team', 'Angebot erstellt.', 'angebot', 'angebot', (int)$id);
            } else {
                q("UPDATE angebot SET kunde_id=?,produkt_id=?,status=?,gueltig_bis=?,notiz=?,marge_override=?,produktionszeit_wochen=? WHERE id=?",
                  [$kunde_id, $produkt_id, $status, $gueltig, $f('notiz'), $marge, $pz, (int)$id]);
            }
            header('Location: ?p=angebot&id=' . $id . '&gespeichert=1'); exit;
        }
    } elseif ($aktion === 'pos_save' && !$neu) {
        // Alles oder nichts: Speichern loescht erst alle Positionen und schreibt sie neu.
        // Bricht eine Zeile ab, stuende das Angebot sonst halb leer da.
        db()->beginTransaction();
        try {
        q("DELETE FROM angebot_position WHERE angebot_id=?", [(int)$id]);
        $bez = $_POST['p_bez'] ?? []; $art = $_POST['p_art'] ?? []; $mng = $_POST['p_menge'] ?? [];
        $einh = $_POST['p_einheit'] ?? []; $preis = $_POST['p_preis'] ?? []; $mwst = $_POST['p_mwst'] ?? [];
        $ek = $_POST['p_ek'] ?? []; $besch = $_POST['p_besch'] ?? []; $quelle = $_POST['p_quelle'] ?? []; $grp = $_POST['p_gruppe'] ?? [];
        $prez = $_POST['p_rez'] ?? []; $pstk = $_POST['p_stk'] ?? []; $pvid = $_POST['p_vid'] ?? [];
        $sort = 0;
        foreach ($bez as $i => $b) {
            $b = trim($b); if ($b === '') continue;
            $gv = strtoupper(trim($grp[$i] ?? '')); $gv = ($gv !== '' && ctype_alpha($gv)) ? substr($gv, 0, 2) : null;
            q("INSERT INTO angebot_position (angebot_id,sort,artikelnr,bezeichnung,beschreibung,menge,einheit,preis_cent,ek_cent,mwst_satz,quelle,gruppe,rezeptur_id,stueck,verpackung_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
              [(int)$id, $sort++, trim($art[$i] ?? ''), $b, trim($besch[$i] ?? ''),
               (float)str_replace(',', '.', $mng[$i] ?? '0'), trim($einh[$i] ?? ''),
               (int) round((float)str_replace(',', '.', $preis[$i] ?? '0') * 100),
               (int) round((float)str_replace(',', '.', $ek[$i] ?? '0') * 100),
               (float)str_replace(',', '.', $mwst[$i] ?? '0'), in_array($quelle[$i] ?? '', ['herstellung','verpackung','manuell'], true) ? $quelle[$i] : 'manuell', $gv,
               (int)($prez[$i] ?? 0) ?: null, (int)($pstk[$i] ?? 0) ?: null, (int)($pvid[$i] ?? 0) ?: null]);
        }
        } catch (Throwable $e) { db()->rollBack(); throw $e; }
        db()->commit();
        header('Location: ?p=angebot&id=' . $id . '&gespeichert=1#positionen'); exit;
    } elseif ($aktion === 'senden' && !$neu) {
        // Der einzige Weg, ein Angebot beim Kunden sichtbar zu machen. Leere Angebote gehen nicht raus.
        $st  = (string) scalar("SELECT status FROM angebot WHERE id=?", [(int)$id]);
        $anz = (int) scalar("SELECT COUNT(*) FROM angebot_position WHERE angebot_id=?", [(int)$id]);
        if ($st !== 'offen')  { header('Location: ?p=angebot&id=' . $id . '&sendfehler=status'); exit; }
        if ($anz === 0)       { header('Location: ?p=angebot&id=' . $id . '&sendfehler=leer'); exit; }
        q("UPDATE angebot SET status='gesendet' WHERE id=?", [(int)$id]);
        // Gültigkeit zählt ab dem Tag, an dem das Angebot beim Kunden landet – ein alter Entwurf
        // wäre sonst schon abgelaufen. Ein selbst gesetztes Datum in der Zukunft bleibt stehen.
        q("UPDATE angebot SET gueltig_bis=? WHERE id=? AND (gueltig_bis IS NULL OR gueltig_bis < CURDATE())",
          [angebot_gueltig_bis_default(), (int)$id]);
        // Jetzt – und erst jetzt – gelten die angebotenen Konfigurationen als eigene Produkte
        // (Rezeptur x Menge + Verpackung), und der Kunde darf deren Preise im Portal sehen.
        angebot_produkte_sichern((int)$id);
        $kd = (int) scalar("SELECT kunde_id FROM angebot WHERE id=?", [(int)$id]);
        if ($kd) {
            q("UPDATE portal_anfrage SET status='beantwortet' WHERE id=(SELECT anfrage_id FROM angebot WHERE id=?) AND status<>'beantwortet'", [(int)$id]);
            log_aktivitaet('kunde', $kd, 'team', 'Angebot ' . scalar("SELECT nummer FROM angebot WHERE id=?", [(int)$id]) . ' an den Kunden gesendet.', 'angebot', 'angebot', (int)$id);
        }
        // Der Kunde bekommt den Portal-Link per Mail – wenn der Versand eingerichtet ist.
        $f = ($kd && mail_bereit()) ? mail_kunde_angebot((int)$id) : '';
        header('Location: ?p=angebot&id=' . $id . '&gesendet=1' . ($f !== '' ? '&mailfehler=' . urlencode($f) : '')); exit;
    } elseif ($aktion === 'zurueckziehen' && !$neu) {
        // Zurückziehen = zurück in den ENTWURF ('offen'). Beim Kunden verschwindet es damit sofort
        // (das Portal zeigt nur 'gesendet'), hier bleibt dasselbe Angebot bearbeitbar.
        $st = (string) scalar("SELECT status FROM angebot WHERE id=?", [(int)$id]);
        if ($st === 'gesendet') {
            q("UPDATE angebot SET status='offen' WHERE id=?", [(int)$id]);
            $kd = (int) scalar("SELECT kunde_id FROM angebot WHERE id=?", [(int)$id]);
            if ($kd) log_aktivitaet('kunde', $kd, 'team', 'Angebot ' . scalar("SELECT nummer FROM angebot WHERE id=?", [(int)$id]) . ' zurückgezogen.', 'angebot', 'angebot', (int)$id);
            header('Location: ?p=angebot&id=' . $id . '&zurueckgezogen=1'); exit;
        }
        header('Location: ?p=angebot&id=' . $id . '&zzfehler=1'); exit;
    } elseif ($aktion === 'angebot_hard_loeschen' && !$neu) {
        // TEMPORÄR (Aufräumen fehlerhafter v3-Importe): löscht GENAU dieses Angebot samt Staffeln/Positionen
        // und der verknüpften Kunden-Anfrage (portal_anfrage). Ein evtl. verknüpfter Auftrag wird nur GELÖST,
        // nicht gelöscht. Ausschließlich per exakter ID – kein pauschales DELETE.
        $aid = (int)$id;
        $anfrageId = (int) scalar("SELECT anfrage_id FROM angebot WHERE id=?", [$aid]);
        $kd = (int) scalar("SELECT kunde_id FROM angebot WHERE id=?", [$aid]);
        $nr = (string) scalar("SELECT nummer FROM angebot WHERE id=?", [$aid]);
        q("UPDATE auftrag SET angebot_id=NULL WHERE angebot_id=?", [$aid]);
        q("DELETE FROM angebot_staffel WHERE angebot_id=?", [$aid]);
        q("DELETE FROM angebot_position WHERE angebot_id=?", [$aid]);
        q("DELETE FROM angebot WHERE id=?", [$aid]);
        if ($anfrageId > 0) {
            try { q("DELETE FROM portal_anfrage_pos WHERE anfrage_id=?", [$anfrageId]); } catch (Throwable $e) {}
            q("DELETE FROM portal_anfrage WHERE id=?", [$anfrageId]);
        }
        if ($kd) log_aktivitaet('kunde', $kd, 'team', 'Angebot ' . $nr . ' inkl. Anfrage gelöscht (Import-Aufräumen).', 'angebot');
        header('Location: ?p=angebote&geloescht=1'); exit;
    } elseif ($aktion === 'pos_reset' && !$neu) {
        q("DELETE FROM angebot_position WHERE angebot_id=?", [(int)$id]);
        header('Location: ?p=angebot&id=' . $id . '&zurueckgesetzt=1'); exit;
    } elseif (in_array($aktion, ['add_rezeptur','add_rohstoff','add_dienstleistung'], true) && !$neu) {
        $aRow = one("SELECT kunde_id, marge_override FROM angebot WHERE id=?", [(int)$id]);
        $kid = (int)($aRow['kunde_id'] ?? 0) ?: null;
        $mo = ($aRow['marge_override'] ?? '') !== '' && $aRow['marge_override'] !== null ? (float)$aRow['marge_override'] : null;
        if ($aktion === 'add_rezeptur') {
            $rid = (int)($_POST['add_rezeptur_id'] ?? 0);
            $stk = (int)($_POST['add_stueck'] ?? 0) ?: 1;
            // Mehrere Bestellmengen auf einmal („1000, 2500, 5000"): je Menge eine eigene Gruppe. So sieht der
            // Kunde eine Staffel, und der Preis je Packung sinkt mit der Menge (Rohstoff-Staffeln greifen).
            $mengen = array_values(array_unique(array_filter(array_map('intval', preg_split('/[\s,;]+/', (string)($_POST['add_menge'] ?? ''))), fn($m) => $m > 0)));
            sort($mengen);
            if (!$mengen) $mengen = [1];
            $verps = array_values(array_filter([(int)($_POST['add_verp_id'] ?? 0), (int)($_POST['add_deckel_id'] ?? 0), (int)($_POST['add_etikett_id'] ?? 0)]));
            if ($rid) foreach ($mengen as $menge) angebot_gruppe_anhaengen((int)$id, angebot_rezeptur_zeilen($rid, $stk, $verps, $menge, $mo, $kid));
            $_SESSION['ang_add'][(int)$id] = ['rezeptur_id'=>$rid, 'stueck'=>$stk, 'menge'=>implode(', ', $mengen),
                'verp_id'=>(int)($_POST['add_verp_id'] ?? 0), 'deckel_id'=>(int)($_POST['add_deckel_id'] ?? 0), 'etikett_id'=>(int)($_POST['add_etikett_id'] ?? 0)];
        } elseif ($aktion === 'add_rohstoff') {
            $iid = (int)($_POST['add_rohstoff_id'] ?? 0);
            $mng = (float)str_replace(',', '.', $_POST['add_menge'] ?? '0');
            if ($iid) angebot_gruppe_anhaengen((int)$id, angebot_rohstoff_zeile($iid, $mng, trim($_POST['add_einheit'] ?? ''), $kid));
        } else { // add_dienstleistung
            $bez = trim($_POST['add_bez'] ?? '');
            if ($bez !== '') {
                $preis = (float)str_replace(',', '.', $_POST['add_preis'] ?? '0');
                $mng = (float)str_replace(',', '.', $_POST['add_menge'] ?? '1') ?: 1;
                $mwst = ($_POST['add_mwst'] ?? '') !== '' ? (float)str_replace(',', '.', $_POST['add_mwst']) : angebot_ust_satz($kid);
                angebot_gruppe_anhaengen((int)$id, [['artikelnr'=>'', 'bezeichnung'=>$bez, 'beschreibung'=>trim($_POST['add_besch'] ?? ''), 'menge'=>$mng, 'einheit'=>trim($_POST['add_einheit'] ?? ''), 'preis_cent'=>(int)round($preis*100), 'ek_cent'=>0, 'mwst_satz'=>$mwst, 'quelle'=>'manuell']]);
            }
        }
        header('Location: ?p=angebot&id=' . $id . '&gespeichert=1#positionen'); exit;
    }
}

$a = $neu ? ['status'=>'offen'] : one("SELECT * FROM angebot WHERE id=?", [(int)$id]);
if (!$a) { $neu = true; $a = ['status'=>'offen']; }
$v = fn($k) => h((string)($a[$k] ?? ''));

$kunden   = all("SELECT id, firma, portal_token FROM kunden ORDER BY firma");
$produkte = all("SELECT id, name FROM produkt ORDER BY name");
// Kataloge für „Position hinzufügen" (Typ zuerst)
$rezepturKatalog = all("SELECT id, name, darreichungsform FROM rezeptur WHERE status IN ('entwurf','vorschlag','eingefroren','freigegeben') ORDER BY name");
$rohstoffKatalog = all("SELECT id, name, artikelnummer, preis_bezug FROM item WHERE kategorie='rohstoff' AND gesperrt=0 ORDER BY name");
$verpPrim   = all("SELECT id, name FROM item WHERE kategorie='verpackung' AND COALESCE(verpackung_rolle,'primaer')='primaer' AND gesperrt=0 ORDER BY name");
$verpDeckel = all("SELECT id, name FROM item WHERE kategorie='verpackung' AND verpackung_rolle='verschluss' AND gesperrt=0 ORDER BY name");
$verpEtik   = all("SELECT id, name FROM item WHERE kategorie='verpackung' AND verpackung_rolle='etikett' AND gesperrt=0 ORDER BY name");

$pid  = (int)($a['produkt_id'] ?? 0);
$kid  = (int)($a['kunde_id'] ?? 0);
$form = $pid ? (string) scalar("SELECT r.darreichungsform FROM produkt p LEFT JOIN rezeptur r ON r.id=p.rezeptur_id WHERE p.id=?", [$pid]) : 'kapsel';
$defMarge = max(marge_typ_prozent($form ?: 'kapsel'), marge_min_prozent());
$defPz    = (float) meta_get('produktionszeit_wochen', 7);

render_header('angebote', $neu ? 'Neues Angebot' : ($a['nummer'] ?? 'Angebot'));
// Senden und Zurückziehen sind die einzigen Wege, die Sichtbarkeit beim Kunden zu ändern.
$st          = (string)($a['status'] ?? 'offen');
$kannSenden  = !$neu && $st === 'offen';
$kannZurueck = !$neu && $st === 'gesendet';
$kopfBtn = bx_btn('Zurück zur Liste', '?p=angebote', 'ghost');
if (!$neu) $kopfBtn = '<a class="btn btn-ghost" style="margin-right:8px" target="_blank" title="Angebot als PDF ansehen – genau das, was der Kunde bekommt" href="?p=angebot_pdf&id=' . (int)$id . '">&#8681; PDF</a>' . $kopfBtn;
// TEMPORÄR: gezieltes Löschen fehlerhafter Import-Angebote (dieses Angebot + verknüpfte Anfrage). Später wieder entfernen.
if (!$neu) $kopfBtn = '<form method="post" style="display:inline;margin-right:8px" onsubmit="return confirm(\'Dieses Angebot inkl. verknüpfter Anfrage endgültig löschen? (Ein verknüpfter Auftrag bleibt erhalten, nur die Verknüpfung wird gelöst.)\');">'
    . '<input type="hidden" name="aktion" value="angebot_hard_loeschen">'
    . '<button class="btn btn-danger" type="submit" title="Fehlerhaften Import löschen (temporär)">Löschen (temporär)</button></form>' . $kopfBtn;
if ($kannZurueck) $kopfBtn = '<form method="post" style="display:inline;margin-right:8px" onsubmit="return confirm(\'Angebot zurückziehen? Es verschwindet beim Kunden und ist hier wieder bearbeitbar.\');">'
    . '<input type="hidden" name="aktion" value="zurueckziehen">'
    . '<button class="btn btn-ghost" type="submit">Zurückziehen</button></form>' . $kopfBtn;
if ($kannSenden) $kopfBtn = '<form method="post" style="display:inline;margin-right:8px" onsubmit="return confirm(\'Angebot jetzt an den Kunden senden?\');">'
    . '<input type="hidden" name="aktion" value="senden">'
    . '<button class="btn btn-primary" type="submit">An Kunden senden</button></form>' . $kopfBtn;
bx_head($neu ? 'Neues Angebot' : $v('nummer'),
        $neu ? 'Positionen' : 'Angebot bearbeiten',
        $kopfBtn);
if (isset($_GET['angefragt']))     echo '<div class="bx-panel badge-ok" style="padding:12px 16px">' . (int)$_GET['angefragt'] . ' Preisanfrage(n) verschickt' . (isset($_GET['gemailt']) && (int)$_GET['gemailt'] > 0 ? ', davon ' . (int)$_GET['gemailt'] . ' per E-Mail' : '') . '. Sobald ein Lieferant antwortet, steht der Preis hier.</div>';
if (isset($_GET['gespeichert']))   echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Gespeichert.</div>';
if (isset($_GET['gesendet']))      echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Angebot an den Kunden gesendet – er sieht es jetzt im Portal.</div>';
if (isset($_GET['mailfehler']))    echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:12px 16px">E-Mail an den Kunden nicht verschickt: ' . h((string)$_GET['mailfehler']) . '</div>';
if (isset($_GET['zurueckgezogen'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Angebot zurückgezogen – beim Kunden verschwunden, hier wieder als Entwurf bearbeitbar.</div>';
if (isset($_GET['zzfehler']))      echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:12px 16px">Zurückziehen nicht mehr möglich – das Angebot ist bereits bestätigt oder abgelehnt.</div>';
if (($_GET['sendfehler'] ?? '') === 'leer')   echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:12px 16px">Noch keine Positionen – ein leeres Angebot wird nicht gesendet. Füge unten mindestens eine Position hinzu.</div>';
if (($_GET['sendfehler'] ?? '') === 'status') echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:12px 16px">Das Angebot ist nicht mehr im Entwurf – es wurde bereits gesendet oder beantwortet.</div>';
if (isset($_GET['zurueckgesetzt'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Positionen auf die automatische Berechnung zurückgesetzt.</div>';
if ($fehler) echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b">' . h($fehler) . '</div>';
?>
<form method="post" class="bx-form">
  <input type="hidden" name="aktion" value="kopf_save">
  <details class="bx-panel" <?= $neu ? 'open' : '' ?>><summary style="cursor:pointer">Kopfdaten<span class="muted" style="font-size:13px"> · Kunde, Gültigkeit, Marge, Produktionszeit, Notiz</span></summary><div class="bx-grid" style="margin-top:12px">
    <div class="bx-field"><label>Kunde</label>
      <select name="kunde_id"><option value="">– keiner –</option>
        <?php foreach ($kunden as $k): ?><option value="<?= $k['id'] ?>" <?= $kid===(int)$k['id']?'selected':'' ?>><?= h($k['firma']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <?php // Das Produkt gehört nicht in die Kopfdaten – es ergibt sich aus den Positionen (Rezeptur × Menge +
          // Verpackung). Gesetzt wird es nur automatisch, wenn das Angebot aus einer Produktanfrage entsteht;
          // damit dieser Bezug beim Speichern nicht verlorengeht, reisen wir ihn unsichtbar mit. ?>
    <input type="hidden" name="produkt_id" value="<?= $pid ?: '' ?>">
    <?php if ($pid): ?>
    <div class="bx-field"><label>Produkt <?= bx_hint('kommt aus der Produktanfrage – der Kunde wählt im Portal aus der Preismatrix dieses Produkts.') ?></label>
      <div style="padding:8px 0"><?= h((string) scalar("SELECT COALESCE(NULLIF(kundenname,''), name) FROM produkt WHERE id=?", [$pid]) ?: '–') ?></div>
    </div>
    <?php endif; ?>
    <?php // Status ist eine ANZEIGE – geändert wird er nur über „An Kunden senden" / „Zurückziehen"
          // bzw. durch den Kunden selbst (bestätigt/abgelehnt im Portal). ?>
    <div class="bx-field"><label>Status <?= bx_hint('Entwurf sieht nur das Team. Erst „An Kunden senden" macht das Angebot im Portal sichtbar.') ?></label>
      <div style="padding:8px 0">
        <?= match ($st) {
              'offen'      => bx_badge('Entwurf – noch nicht beim Kunden', 'info'),
              'gesendet'   => bx_badge('beim Kunden', 'ok'),
              'bestaetigt' => bx_badge('vom Kunden bestätigt', 'ok'),
              'abgelehnt'  => bx_badge('vom Kunden abgelehnt', 'err'),
              default      => bx_badge($st),
            } ?>
      </div>
      <?php if (!empty($a['freigabe_name'])): ?>
      <div class="muted" style="font-size:12px">Verbindlich bestätigt durch <strong><?= h($a['freigabe_name']) ?></strong><?= $a['freigabe_am'] ? ' am ' . h(fmt_zeit($a['freigabe_am'])) . ' Uhr' : '' ?></div>
      <?php endif; ?>
    </div>
    <div class="bx-field"><label>Gültig bis</label><input type="date" name="gueltig_bis" value="<?= h($v('gueltig_bis') !== '' ? $v('gueltig_bis') : angebot_gueltig_bis_default()) ?>"></div>
    <div class="bx-field"><label>Marge (%) <?= bx_hint('wirkt auf die automatischen Positionen. Leer = Marge je Form ('.rtrim(rtrim(number_format($defMarge,2,',','.'),'0'),',').' %).') ?></label><input type="number" step="0.1" name="marge" value="<?= ($a['marge_override'] ?? '') !== '' && $a['marge_override'] !== null ? h(rtrim(rtrim(number_format((float)$a['marge_override'],2,'.',''),'0'),'.')) : '' ?>" placeholder="<?= h(rtrim(rtrim(number_format($defMarge,2,',','.'),'0'),',')) ?> (Standard)"></div>
    <div class="bx-field"><label>Produktionszeit (Wochen)</label><input type="number" step="0.5" name="produktionszeit" value="<?= ($a['produktionszeit_wochen'] ?? '') !== '' && $a['produktionszeit_wochen'] !== null ? h(rtrim(rtrim(number_format((float)$a['produktionszeit_wochen'],1,'.',''),'0'),'.')) : '' ?>" placeholder="<?= h(rtrim(rtrim(number_format($defPz,1,',','.'),'0'),',')) ?> (Standard)"></div>
  </div>
  <div class="bx-field"><label>Notiz</label><textarea name="notiz"><?= $v('notiz') ?></textarea></div>
  <div class="bx-row"><button class="btn btn-primary" type="submit"><?= $neu ? 'Angebot anlegen' : 'Kopfdaten speichern' ?></button><a class="btn btn-ghost" href="?p=angebote">Abbrechen</a></div>
  </details>
</form>

<?php
// Positionen, Summen und PDF gibt es für JEDES gespeicherte Angebot – auch ohne Produkt im Kopf.
// (Vorher hing der ganze Bereich an $pid: Bei einer Rezeptur-Anfrage ohne Produkt blieb nur der Kopf stehen.)
if (!$neu):
    $pos = angebot_positionen((int)$id);
    $ueberschrieben = angebot_hat_positionen((int)$id);
    $eur = fn($c) => number_format($c/100, 2, ',', '.') . ' €';
    // interne Summen
    $sumVk = 0; $sumEk = 0;
    foreach ($pos as $pp) { $sumVk += $pp['menge'] * $pp['preis_cent']; $sumEk += $pp['menge'] * $pp['ek_cent']; }
    $marge = $sumVk - $sumEk; $margePct = $sumVk > 0 ? $marge / $sumVk * 100 : 0;
    $ktok = ''; foreach ($kunden as $k) if ((int)$k['id'] === $kid) { $ktok = $k['portal_token']; break; }
    // Wunsch aus der verknüpften Portal-Anfrage übernehmen: Rezeptur, Menge je Packung, Anzahl Packungen
    // und – aus dem Wunsch-Verpackungstyp – ein passender Behälter. Sonst müsste das Team alles abtippen.
    $wunsch = ['rezeptur_id'=>0, 'stueck'=>'', 'menge'=>'', 'verp_id'=>0, 'typ_label'=>''];
    $letzte = $_SESSION['ang_add'][(int)$id] ?? null;   // was zuletzt angehaengt wurde
    if (!empty($a['anfrage_id'])) {
        $wa = one("SELECT rezeptur_id, produkt_id, stueck, fuellmenge_g, verpackung_typ, menge FROM portal_anfrage WHERE id=?", [(int)$a['anfrage_id']]);
        if ($wa) {
            $wunsch['rezeptur_id'] = (int)($wa['rezeptur_id'] ?: scalar("SELECT rezeptur_id FROM produkt WHERE id=?", [(int)$wa['produkt_id']]));
            $wunsch['stueck'] = (float)$wa['fuellmenge_g'] > 0 ? (int) round((float)$wa['fuellmenge_g']) : (int)$wa['stueck'];
            $wunsch['menge']  = (int)$wa['menge'];
            $wunsch['typ_label'] = (string)($wa['verpackung_typ'] ?? '');
            // Behälter zum Wunschtyp vorschlagen, der die Menge auch fasst
            if ($wunsch['rezeptur_id'] && $wunsch['stueck'] > 0) {
                $wform = (string) scalar("SELECT darreichungsform FROM rezeptur WHERE id=?", [$wunsch['rezeptur_id']]) ?: 'kapsel';
                foreach (passende_behaelter_fuer($wunsch['rezeptur_id'], $wform, (int)$wunsch['stueck']) as $cand)
                    if (verpackung_passt_zu_typ((int)$cand, $wunsch['typ_label'] ?: null)) { $wunsch['verp_id'] = (int)$cand; break; }
            }
        }
    }
?>
<div class="bx-panel">
  <h2 id="positionen" style="margin-top:0;scroll-margin-top:16px">Position hinzufügen</h2>
  <p class="muted" style="margin-top:0">Erst den Typ wählen – dann kommt der passende Katalog. Jede Position wird als eigene Gruppe (A, B, C …) angehängt.</p>
  <?php if ($wunsch['rezeptur_id'] || $wunsch['stueck'] || $wunsch['menge']): ?>
    <div class="bx-panel" style="padding:10px 14px;margin-bottom:12px;background:var(--panel-2)">
      <strong>Aus der Anfrage übernommen:</strong>
      <?= $wunsch['stueck'] ? h($wunsch['stueck']) . ' je Packung · ' : '' ?>
      <?= $wunsch['menge'] ? number_format((int)$wunsch['menge'], 0, ',', '.') . ' Packungen' : '' ?>
      <?= $wunsch['typ_label'] ? ' · Wunsch: ' . h(['glas'=>'Glas','pet'=>'PET-Dose','pla'=>'PLA-Becher','beutel'=>'Standbodenbeutel','stick'=>'Stick','blister'=>'Blister'][$wunsch['typ_label']] ?? $wunsch['typ_label']) : '' ?>
      <div class="muted" style="font-size:12px">Die Felder unten sind vorbelegt – Werte prüfen und bei Bedarf ändern.</div>
    </div>
  <?php endif; ?>
  <div class="bx-field" style="max-width:280px"><label>Typ</label>
    <select id="addTyp">
      <option value="rezeptur">Rezeptur (Lohnherstellung)</option>
      <option value="rohstoff">Rohstoff</option>
      <option value="dienstleistung">Dienstleistung</option>
    </select>
  </div>

  <form method="post" data-add="rezeptur">
    <input type="hidden" name="aktion" value="add_rezeptur">
    <div class="bx-grid">
      <div class="bx-field"><label>Rezeptur</label>
        <select name="add_rezeptur_id" required><option value="">– wählen –</option>
          <?php foreach ($rezepturKatalog as $rz): ?><option value="<?= (int)$rz['id'] ?>" <?= $wunsch['rezeptur_id'] === (int)$rz['id'] ? 'selected' : '' ?>><?= h($rz['name']) ?><?= $rz['darreichungsform'] ? ' · '.h($rz['darreichungsform']) : '' ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="bx-field"><label>Stückzahl / Füllmenge je Packung</label><input type="number" name="add_stueck" placeholder="z. B. 30" value="<?= $letzte['stueck'] ?? ($wunsch['stueck'] !== '' ? (int)$wunsch['stueck'] : '') ?>" required></div>
      <div class="bx-field"><label>Anzahl Packungen <?= bx_hint('Mehrere Mengen mit Komma, z. B. 1000, 2500, 5000 – je Menge entsteht eine wählbare Option mit eigenem Preis je Packung.') ?></label><input type="text" inputmode="numeric" name="add_menge" placeholder="z. B. 1000, 2500, 5000" value="<?= h((string)($letzte['menge'] ?? ($wunsch['menge'] !== '' ? (int)$wunsch['menge'] : ''))) ?>" required></div>
      <div class="bx-field"><label>Verpackung (Primär)</label><select name="add_verp_id"><option value="">– keine –</option><?php foreach ($verpPrim as $vp): ?><option value="<?= (int)$vp['id'] ?>" <?= ((int)($letzte['verp_id'] ?? $wunsch['verp_id']) === (int)$vp['id']) ? 'selected' : '' ?>><?= h($vp['name']) ?></option><?php endforeach; ?></select></div>
      <div class="bx-field"><label>Deckel (optional)</label><select name="add_deckel_id"><option value="">– keiner –</option><?php foreach ($verpDeckel as $vp): ?><option value="<?= (int)$vp['id'] ?>" <?= ((int)($letzte['deckel_id'] ?? 0) === (int)$vp['id']) ? 'selected' : '' ?>><?= h($vp['name']) ?></option><?php endforeach; ?></select></div>
      <div class="bx-field"><label>Etikett (optional)</label>
        <select name="add_etikett_id" id="add_etikett_id"><option value="">– keins –</option><?php foreach ($verpEtik as $vp): ?><option value="<?= (int)$vp['id'] ?>"><?= h($vp['name']) ?></option><?php endforeach; ?></select>
        <div class="muted" style="font-size:12px;margin-top:4px" id="etikHinweis"></div>
      </div>
    </div>
    <div class="bx-row" style="margin-top:10px"><button class="btn btn-primary" type="submit">Hinzufügen</button></div>
  </form>
  <?php // Etiketten haengen am Behaelter: gezeigt wird nur, was auf das am Behaelter hinterlegte
        // Endformat (item.etikett_final) passt. Fehlt das Format oder gibt es kein passendes
        // Etikett, sagt der Hinweis genau das - statt still eine leere Auswahl anzubieten. ?>
  <script>(function(){
    var map = <?= json_encode(etikett_zuordnung()) ?>;
    var hatEtiketten = <?= $verpEtik ? 'true' : 'false' ?>;
    var verp = document.querySelector('select[name="add_verp_id"]');
    var etik = document.getElementById('add_etikett_id');
    var hint = document.getElementById('etikHinweis');
    if (!verp || !etik) return;
    function upd(){
      var vid = verp.value, erlaubt = vid ? (map[vid] || []) : null;
      var sichtbar = 0;
      Array.prototype.forEach.call(etik.options, function(o){
        if (!o.value) return;
        var ok = !erlaubt || erlaubt.indexOf(parseInt(o.value,10)) >= 0;
        o.hidden = !ok; o.disabled = !ok; if (ok) sichtbar++;
      });
      if (etik.selectedOptions[0] && etik.selectedOptions[0].disabled) etik.value = '';
      if (!hatEtiketten) hint.textContent = 'Es sind noch keine Etiketten im Katalog angelegt (Lager > Verpackungen, Rolle "Etikett").';
      else if (!vid) hint.textContent = 'Erst die Verpackung wählen – dann stehen nur die dazu passenden Etiketten zur Auswahl.';
      else if (sichtbar === 0) hint.textContent = 'Für diese Verpackung ist kein passendes Etikett hinterlegt. Am Behälter fehlt das Etiketten-Endformat (B x H) oder es gibt noch kein Etikett in dieser Groesse.';
      else hint.textContent = sichtbar + (sichtbar === 1 ? ' passendes Etikett' : ' passende Etiketten') + ' zu dieser Verpackung.';
    }
    verp.addEventListener('change', upd); upd();
  })();</script>

  <form method="post" data-add="rohstoff" style="display:none">
    <input type="hidden" name="aktion" value="add_rohstoff">
    <div class="bx-grid">
      <div class="bx-field"><label>Rohstoff</label>
        <select name="add_rohstoff_id" required><option value="">– wählen –</option>
          <?php foreach ($rohstoffKatalog as $rs): ?><option value="<?= (int)$rs['id'] ?>"><?= h($rs['name']) ?><?= $rs['artikelnummer'] ? ' · '.h($rs['artikelnummer']) : '' ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="bx-field"><label>Menge</label><input type="number" step="0.001" name="add_menge" placeholder="z. B. 25" required></div>
      <div class="bx-field"><label>Einheit</label><select name="add_einheit"><?php foreach (['kg','g','Stück','L'] as $e): ?><option value="<?= $e ?>"><?= $e ?></option><?php endforeach; ?></select></div>
    </div>
    <div class="bx-row" style="margin-top:10px"><button class="btn btn-primary" type="submit">Hinzufügen</button></div>
  </form>

  <form method="post" data-add="dienstleistung" style="display:none">
    <input type="hidden" name="aktion" value="add_dienstleistung">
    <div class="bx-grid">
      <div class="bx-field"><label>Bezeichnung</label><input type="text" name="add_bez" placeholder="z. B. Laboranalyse" required></div>
      <div class="bx-field"><label>Menge</label><input type="number" step="0.01" name="add_menge" value="1"></div>
      <div class="bx-field"><label>Einheit</label><input type="text" name="add_einheit" placeholder="Stück / Pauschal"></div>
      <div class="bx-field"><label>Preis je Einheit (€)</label><input type="number" step="0.01" name="add_preis" placeholder="0,00"></div>
    </div>
    <div class="bx-row" style="margin-top:10px"><button class="btn btn-primary" type="submit">Hinzufügen</button></div>
  </form>
</div>

<div class="bx-panel">
  <div class="bx-row" style="justify-content:space-between;align-items:center">
    <h2 style="margin:0">Positionen <span class="muted" style="font-weight:400;font-size:13px"><?= $ueberschrieben ? 'manuell überschrieben' : 'automatisch berechnet' ?></span></h2>
    <div class="bx-row" style="gap:8px">
      <a class="btn btn-ghost btn-sm" target="_blank" href="?p=angebot_pdf&id=<?= (int)$id ?>">&#8681; PDF ansehen</a>
      <?php if ($ueberschrieben): ?>
        <form method="post" style="margin:0" onsubmit="return confirm('Positionen auf die automatische Berechnung zurücksetzen? Manuelle Änderungen gehen verloren.');"><input type="hidden" name="aktion" value="pos_reset"><button class="btn btn-ghost btn-sm" type="submit">Automatik wiederherstellen</button></form>
      <?php endif; ?>
    </div>
  </div>
  <p class="muted" style="margin-top:4px;font-size:13px">Automatisch erzeugt aus Produkt + Preismatrix + Verpackung (Dose/Deckel/Etikett kommen extra). Du kannst Menge, Preis, MwSt anpassen oder Positionen hinzufügen/entfernen. <strong>Speichern friert die Positionen ein</strong> (überschreibt die Automatik).</p>

  <style>
    #postab{table-layout:fixed;width:100%}
    #postab th,#postab td{padding:6px 8px;vertical-align:middle;overflow:hidden}
    #postab input,#postab textarea{box-sizing:border-box;width:100%;display:block;min-width:0}
    #postab .p_besch{margin-top:4px;font-size:14px;color:var(--muted);resize:vertical;line-height:1.35}
    #postab .bx-num{white-space:nowrap;text-align:right}
  </style>
  <form method="post">
    <input type="hidden" name="aktion" value="pos_save">
    <table class="bx-table" id="postab">
      <colgroup>
        <col style="width:400px"><col style="width:92px"><col style="width:72px"><col style="width:92px"><col style="width:78px">
        <col style="width:84px"><col style="width:92px"><col style="width:96px"><col style="width:40px"><col>
      </colgroup>
      <thead><tr>
        <th>Bezeichnung</th><th class="bx-num">Menge</th><th>Einheit</th>
        <th class="bx-num">Preis/Einh €</th><th class="bx-num">MwSt %</th>
        <th class="bx-num">EK/Einh</th><th class="bx-num">Marge</th><th class="bx-num">Gesamt</th><th></th><th></th>
      </tr></thead>
      <tbody id="posrows">
        <?php foreach ($pos as $i => $pp): ?>
        <tr class="posrow">
          <td>
            <input type="text" name="p_bez[]" value="<?= h($pp['bezeichnung']) ?>">
            <textarea name="p_besch[]" class="p_besch" rows="<?= max(2, substr_count((string)($pp['beschreibung'] ?? ''), "\n") + 1) ?>" placeholder="Beschreibung / Rezeptur (optional)"><?= h($pp['beschreibung'] ?? '') ?></textarea>
            <input type="hidden" name="p_art[]" value="<?= h($pp['artikelnr'] ?? '') ?>">
            <input type="hidden" name="p_quelle[]" value="<?= h($pp['quelle'] ?? 'manuell') ?>">
            <input type="hidden" name="p_gruppe[]" value="<?= h($pp['gruppe'] ?? '') ?>">
            <input type="hidden" name="p_rez[]" value="<?= (int)($pp['rezeptur_id'] ?? 0) ?: '' ?>">
            <input type="hidden" name="p_stk[]" value="<?= (int)($pp['stueck'] ?? 0) ?: '' ?>">
            <input type="hidden" name="p_vid[]" value="<?= (int)($pp['verpackung_id'] ?? 0) ?: '' ?>">
            <input type="hidden" name="p_ek[]" class="p_ek" value="<?= h(number_format($pp['ek_cent']/100,4,'.','')) ?>">
          </td>
          <td><input type="number" step="0.001" name="p_menge[]" class="p_menge" value="<?= h(rtrim(rtrim(number_format($pp['menge'],3,'.',''),'0'),'.')) ?>" style="width:100%"></td>
          <td><input type="text" name="p_einheit[]" value="<?= h($pp['einheit'] ?? '') ?>" style="width:100%"></td>
          <td><input type="number" step="0.0001" name="p_preis[]" class="p_preis" value="<?= h(rtrim(rtrim(number_format($pp['preis_cent']/100,4,'.',''),'0'),'.')) ?>" style="width:100%"></td>
          <td><input type="number" step="0.1" name="p_mwst[]" value="<?= h(rtrim(rtrim(number_format($pp['mwst_satz'],2,'.',''),'0'),'.')) ?>" style="width:100%"></td>
          <td class="bx-num c_ek">–</td><td class="bx-num c_marge">–</td><td class="bx-num c_ges">–</td>
          <td><button type="button" class="btn btn-ghost btn-sm" title="Position löschen" onclick="var f=this.closest('form');this.closest('.posrow').remove();posRecalc();f.submit()">×</button></td>
          <td></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <div class="bx-row" style="justify-content:space-between;align-items:flex-start;margin-top:8px">
      <button type="button" class="btn btn-ghost btn-sm" id="addPos">+ freie Position</button>
      <span class="muted" style="font-size:12px;margin-left:8px">für Zuschläge/Dienstleistungen – Produkte bitte oben aus dem Katalog hinzufügen</span>
      <div style="text-align:right;font-size:14px">
        <div>Netto: <strong id="sumNetto"><?= $eur($sumVk) ?></strong></div>
        <div class="muted" style="font-size:12px" id="intMarge">Intern: EK <?= $eur($sumEk) ?> · Marge <?= $eur($marge) ?> (<?= number_format($margePct,0,',','.') ?> %)</div>
      </div>
    </div>
    <div class="bx-row" style="margin-top:10px"><button class="btn btn-primary" type="submit">Positionen speichern</button></div>
  </form>

  <?php // Preis je Packung – genau die Zeilen, aus denen der Kunde im Portal auswaehlt.
        // In den Positionen stehen Herstellung und Verpackung getrennt; hier zusammengerechnet.
        $optE = angebot_optionen($id); ?>
  <?php if ($optE['optionen']): ?>
  <div class="bx-panel" style="margin-top:16px">
    <div style="margin-bottom:4px">Preis je Packung – so sieht es der Kunde</div>
    <div class="muted" style="font-size:12px;margin-bottom:10px">Stand nach dem letzten Speichern. Jede Gruppe ist im Kundenportal eine Zeile mit eigenem Knopf.</div>
    <div class="bx-tablewrap"><table class="bx-table">
      <thead><tr><th>Variante</th><th class="bx-num">Packungen</th><th class="bx-num">Preis / Packung</th><th class="bx-num">Preis / Stück</th><th class="bx-num">Gesamt netto</th></tr></thead>
      <tbody>
      <?php foreach ($optE['optionen'] as $o): ?>
        <tr>
          <td><?= h(trim(($o['groesse'] !== '' ? $o['groesse'] : $o['titel']) . ($o['verpackung'] !== '' ? ' · ' . $o['verpackung'] : ''))) ?></td>
          <td class="bx-num"><?= number_format($o['pakete'], 0, ',', '.') ?></td>
          <td class="bx-num"><strong><?= $eur($o['pro_pkg'] * 100) ?></strong></td>
          <td class="bx-num"><?= (!$o['ist_fuell'] && $o['stueck'] > 0) ? (fn($v) => number_format($v, $v < 0.1 ? 4 : 2, ',', '.') . ' €')($o['pro_pkg'] / $o['stueck']) : '–' ?></td>
          <td class="bx-num"><?= $eur($o['netto'] * 100) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php foreach ($optE['extra'] as $x): ?>
        <tr><td colspan="4"><?= h($x['bezeichnung']) ?><span class="muted" style="font-size:12px"> · wird zusätzlich berechnet</span></td>
            <td class="bx-num"><?= $eur((float)$x['menge'] * (int)$x['preis_cent']) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
  <?php endif; ?>

  <?php // Rohstoffkosten VOR dem Preis: was kostet uns die Rezeptur beim Lieferanten?
        // Zeigt je Zutat den guenstigsten Lieferanten-EK bei der groessten Bestellmenge und wo noch
        // kein Preis bekannt ist - dann steht dort "Preis anfragen".
        $rk = angebot_rohstoffkosten($id); if ($rk['zeilen']): $nfrk = fn($v,$d=2) => number_format($v, $d, ',', '.'); ?>
  <div class="bx-panel" style="margin-top:16px">
    <div style="margin-bottom:4px">Rohstoffkosten je Lieferant <span class="muted" style="font-size:12px">&ndash; Grundlage f&uuml;r deinen Preis</span></div>
    <div class="muted" style="font-size:12px;margin-bottom:10px">G&uuml;nstigster Lieferant bei der gr&ouml;&szlig;ten angebotenen Menge. Wo kein Preis steht, erst beim Lieferanten anfragen.</div>
    <?php // Alternative: statt der Einzel-Rohstoffe das ganze Produkt fremdfertigen lassen.
          $angRez = all("SELECT DISTINCT r.id, r.name, r.darreichungsform FROM angebot_position ap
                         JOIN rezeptur r ON r.id=ap.rezeptur_id WHERE ap.angebot_id=? AND ap.rezeptur_id IS NOT NULL AND ap.rezeptur_id>0 ORDER BY r.name", [(int)$id]);
          if ($angRez): ?>
    <div style="border:1px solid var(--line);border-radius:8px;padding:10px 12px;margin-bottom:12px">
      <div style="margin-bottom:6px">Oder das ganze Produkt fremdfertigen lassen (Bulk):</div>
      <?php foreach ($angRez as $ar): $arf = anfrage_formen()[$ar['darreichungsform']] ?? $ar['darreichungsform']; ?>
        <div class="bx-row" style="justify-content:space-between;align-items:center;gap:12px;padding:4px 0">
          <div><?= h($ar['name']) ?> <span class="muted" style="font-size:12px">· <?= h($arf) ?></span></div>
          <div style="display:flex;gap:8px;align-items:center">
            <?= anfrage_produkt_badge((int)$ar['id']) ?>
            <?= anfrage_produkt_button((int)$ar['id'], (string)$ar['name'], (string)$arf, 'Fertigprodukt anfragen') ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <div class="bx-tablewrap"><table class="bx-table">
      <thead><tr><th>Rohstoff</th><th class="bx-num">Ben&ouml;tigt</th><th class="bx-num">EK / Einheit</th><th>Lieferant</th><th class="bx-num">Kosten</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rk['zeilen'] as $z): ?>
        <tr>
          <td><a href="?p=rohstoff&id=<?= (int)$z['item_id'] ?>"><?= h($z['name']) ?></a></td>
          <td class="bx-num"><?= h($nfrk($z['bedarf'], $z['bedarf'] < 1 ? 3 : 1)) ?> <?= h($z['bezug']) ?></td>
          <td class="bx-num"><?= $z['ek'] !== null ? h($nfrk($z['ek'], 4)) . ' &euro;' : '<span style="color:#8f231b">&ndash;</span>' ?></td>
          <td><?= $z['lieferant'] !== '' ? h($z['lieferant']) : '<span class="muted">&ndash;</span>' ?></td>
          <td class="bx-num"><?= $z['kosten'] !== null ? h($nfrk($z['kosten'])) . ' &euro;' : '<span class="muted">?</span>' ?></td>
          <td><?= anfrage_badge((int)$z['item_id']) ?></td>
          <td style="text-align:right"><button type="button" class="btn btn-ghost btn-sm" data-name="<?= h($z['name']) ?>" onclick="bxAnfrageOeffnen(<?= (int)$z['item_id'] ?>,this)">Preis anfragen</button></td>
        </tr>
      <?php endforeach; ?>
        <tr style="font-weight:600"><td colspan="4">Rohstoffkosten gesamt (gr&ouml;&szlig;te Menge)</td><td class="bx-num"><?= h($nfrk($rk['summe'])) ?> &euro;</td><td colspan="2"></td></tr>
      </tbody>
    </table></div>
    <?php if ($rk['ohne_preis'] > 0): ?>
      <div style="margin-top:10px;color:#8f231b;font-size:13px"><?= (int)$rk['ohne_preis'] ?> Rohstoff(e) ohne Lieferantenpreis &ndash; bitte zuerst anfragen, sonst ist die Kalkulation unvollst&auml;ndig.</div>
    <?php endif; ?>
  </div>
  <?php anfrage_modal(all("SELECT id, firma, land FROM lieferanten WHERE gesperrt=0 ORDER BY firma"), '?p=angebot&id=' . (int)$id); ?>
  <?php endif; ?>
</div>

<script>
(function(){
  var sel = document.getElementById('addTyp'); if (!sel) return;
  function upd(){
    document.querySelectorAll('[data-add]').forEach(function(f){ f.style.display = (f.getAttribute('data-add') === sel.value) ? '' : 'none'; });
  }
  sel.addEventListener('change', upd); upd();
})();
function nf(x,d){ return x.toLocaleString('de-DE',{minimumFractionDigits:d,maximumFractionDigits:d}); }
function posRecalc(){
  var netto=0, ekges=0;
  document.querySelectorAll('.posrow').forEach(function(r){
    var m=parseFloat((r.querySelector('.p_menge').value||'').replace(',','.'))||0;
    var p=parseFloat((r.querySelector('.p_preis').value||'').replace(',','.'))||0;
    var ek=parseFloat((r.querySelector('.p_ek').value||'').replace(',','.'))||0;
    var g=m*p, mg=(p-ek)*m;
    r.querySelector('.c_ek').textContent = ek?nf(ek,2)+' €':'–';
    r.querySelector('.c_marge').textContent = (p&&ek)?nf(mg,2)+' €':'–';
    r.querySelector('.c_ges').textContent = g?nf(g,2)+' €':'–';
    netto+=g; ekges+=m*ek;
  });
  document.getElementById('sumNetto').textContent=nf(netto,2)+' €';
  var marge=netto-ekges, pct=netto>0?marge/netto*100:0;
  document.getElementById('intMarge').textContent='Intern: EK '+nf(ekges,2)+' € · Marge '+nf(marge,2)+' € ('+nf(pct,0)+' %)';
}
(function(){
  document.getElementById('addPos').addEventListener('click',function(){
    var tr=document.createElement('tr'); tr.className='posrow';
    tr.innerHTML='<td><input type="text" name="p_bez[]">'
      +'<textarea name="p_besch[]" class="p_besch" rows="2" placeholder="Beschreibung / Rezeptur (optional)"></textarea>'
      +'<input type="hidden" name="p_art[]" value=""><input type="hidden" name="p_quelle[]" value="manuell"><input type="hidden" name="p_gruppe[]" value=""><input type="hidden" name="p_ek[]" class="p_ek" value="0">'
      +'<input type="hidden" name="p_rez[]" value=""><input type="hidden" name="p_stk[]" value=""><input type="hidden" name="p_vid[]" value=""></td>'
      +'<td><input type="number" step="0.001" name="p_menge[]" class="p_menge"></td>'
      +'<td><input type="text" name="p_einheit[]" value="Stück"></td>'
      +'<td><input type="number" step="0.0001" name="p_preis[]" class="p_preis"></td>'
      +'<td><input type="number" step="0.1" name="p_mwst[]" value="0"></td>'
      +'<td class="bx-num c_ek">–</td><td class="bx-num c_marge">–</td><td class="bx-num c_ges">–</td>'
      +'<td><button type="button" class="btn btn-ghost btn-sm">×</button></td><td></td>';
    tr.querySelector('button').addEventListener('click',function(){tr.remove();posRecalc();});
    tr.querySelectorAll('input').forEach(function(i){i.addEventListener('input',posRecalc);});
    document.getElementById('posrows').appendChild(tr);
  });
  document.querySelectorAll('#postab input').forEach(function(i){i.addEventListener('input',posRecalc);});
  posRecalc();
})();
</script>
<?php endif;
render_footer();

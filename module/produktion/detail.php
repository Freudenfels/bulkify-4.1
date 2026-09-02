<?php
// Produktionsauftrag – Stationen/Gates der Reihe nach abarbeiten
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

$id = (int)($_GET['id'] ?? 0);

// Nächste offene Station als erledigt markieren
if ($id && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'erledigen') {
    $sid = (int)($_POST['schritt'] ?? 0);
    $firstOpen = one("SELECT id, station FROM produktion_schritt WHERE pa_id=? AND erledigt=0 ORDER BY sort LIMIT 1", [$id]);
    if ($firstOpen && (int)$firstOpen['id'] === $sid) {
        // Baustein 6: Scan-Pflicht je Station prüfen
        $anl = station_anleitung($firstOpen['station']);
        $scanWert = trim($_POST['scan'] ?? '');
        if ($anl['scan']) {
            $chk = produktion_scan_pruefen($scanWert, $anl['kat']);
            if (!$chk['ok']) { header('Location: ?p=produktionsauftrag&id=' . $id . '&scanfehler=' . urlencode($chk['msg'])); exit; }
            q("UPDATE produktion_schritt SET scan_charge=? WHERE id=?", [$scanWert, $sid]);
        }
        // Rohstoffe bereitstellen -> Bestand nach FEFO entnehmen; blockiert bei zu wenig Bestand
        if ($firstOpen['station'] === 'Rohstoffe bereitstellen') {
            $res = produktion_rohstoffe_entnehmen($id);
            if (!$res['ok']) { header('Location: ?p=produktionsauftrag&id=' . $id . '&mangel=1'); exit; }
        }
        if ($firstOpen['station'] === 'Verkapselung') {
            $res = produktion_kapseln_entnehmen($id);
            if (!$res['ok']) { header('Location: ?p=produktionsauftrag&id=' . $id . '&mangel=1'); exit; }
        }
        if ($firstOpen['station'] === 'Fertigware bereitstellen') {
            $res = produktion_fertigware_entnehmen($id);
            if (!$res['ok']) { header('Location: ?p=produktionsauftrag&id=' . $id . '&mangel=1'); exit; }
        }
        if ($firstOpen['station'] === 'Verpacken') {
            $res = produktion_verpackung_entnehmen($id);
            if (!$res['ok']) { header('Location: ?p=produktionsauftrag&id=' . $id . '&mangel=1'); exit; }
        }
        q("UPDATE produktion_schritt SET erledigt=1, erledigt_at=? WHERE id=?", [gmdate('Y-m-d H:i:s'), $sid]);
        reservierung_abgleichen($id);   // entnommene Items: Reservierung schließen
        $total = (int) scalar("SELECT COUNT(*) FROM produktion_schritt WHERE pa_id=?", [$id]);
        $done  = (int) scalar("SELECT COUNT(*) FROM produktion_schritt WHERE pa_id=? AND erledigt=1", [$id]);
        $status = $done === 0 ? 'offen' : ($done >= $total ? 'erledigt' : 'laufend');
        q("UPDATE produktionsauftrag SET status=? WHERE id=?", [$status, $id]);
        $pa = one("SELECT auftrag_id, kunde_id, nummer FROM produktionsauftrag WHERE id=?", [$id]);
        if ($status === 'erledigt') {
            auftrag_reservierung_freigeben($id);     // etwaige Rest-Reservierungen freigeben
            produktion_fertigware_einbuchen($id);   // Fertigware als Bestand einbuchen
            if ($pa && $pa['auftrag_id']) {
                q("UPDATE auftrag SET status='erledigt' WHERE id=?", [$pa['auftrag_id']]);
                if ($pa['kunde_id']) log_aktivitaet('kunde', (int)$pa['kunde_id'], 'team', 'Produktion ' . $pa['nummer'] . ' abgeschlossen, Fertigware eingebucht, versandfrei.', 'auftrag', 'auftrag', (int)$pa['auftrag_id']);
            }
        }
    }
    header('Location: ?p=produktionsauftrag&id=' . $id . '&ok=1'); exit;
}

// Teilmenge Fertigware einbuchen (Teilproduktion .A/.B/.C) – der Abschluss bucht später nur noch den Rest
if ($id && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'teilmenge') {
    $r = produktion_teilmenge_einbuchen($id, (float) str_replace(',', '.', (string)($_POST['menge'] ?? '0')),
        trim((string)($_POST['mhd'] ?? '')) ?: null, (string)($_POST['notiz'] ?? ''));
    if ($r['ok']) {
        $paT = one("SELECT nummer, kunde_id, auftrag_id FROM produktionsauftrag WHERE id=?", [$id]);
        if ($paT && $paT['kunde_id']) log_aktivitaet('kunde', (int)$paT['kunde_id'], 'team', 'Produktion ' . $paT['nummer'] . ': Teilmenge ' . number_format((float)$_POST['menge'], 0, ',', '.') . ' Stück als Charge ' . $r['charge_nr'] . ' eingebucht.', 'auftrag', 'auftrag', (int)$paT['auftrag_id']);
        header('Location: ?p=produktionsauftrag&id=' . $id . '&teil=' . urlencode($r['charge_nr'])); exit;
    }
    header('Location: ?p=produktionsauftrag&id=' . $id . '&teilfehler=' . urlencode($r['msg'])); exit;
}

// Priorität setzen
if ($id && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'prio') {
    q("UPDATE produktionsauftrag SET prio=? WHERE id=?", [max(1, min(3, (int)($_POST['prio'] ?? 2))), $id]);
    header('Location: ?p=produktionsauftrag&id=' . $id); exit;
}
// Bestand für diesen Auftrag reservieren / freigeben
if ($id && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'reservieren') {
    $n = auftrag_reservieren($id);
    header('Location: ?p=produktionsauftrag&id=' . $id . '&reserviert=' . $n); exit;
}
if ($id && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'reservierung_frei') {
    auftrag_reservierung_freigeben($id);
    header('Location: ?p=produktionsauftrag&id=' . $id . '&resfrei=1'); exit;
}
// Etikett-Design hochladen / löschen (je Auftrag)
if ($id && $_SERVER['REQUEST_METHOD'] === 'POST' && in_array(($_POST['aktion'] ?? ''), ['etikett_upload', 'etikett_del'], true)) {
    $aid = (int) scalar("SELECT auftrag_id FROM produktionsauftrag WHERE id=?", [$id]);
    if ($aid) {
        if ($_POST['aktion'] === 'etikett_upload') etikett_upload($aid);
        else etikett_del($aid);
    }
    header('Location: ?p=produktionsauftrag&id=' . $id . '&etikett=1'); exit;
}
// 1-Klick: Bestellungen aus dem Fehlbedarf anlegen
if ($id && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'bedarf_bestellen') {
    $neu = bestellung_aus_bedarf($id);
    header('Location: ?p=produktionsauftrag&id=' . $id . '&bestellt=' . count($neu)); exit;
}
// Geplantes Produktionsdatum setzen
if ($id && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'geplant') {
    q("UPDATE produktionsauftrag SET geplant_am=? WHERE id=?", [trim($_POST['geplant_am'] ?? '') ?: null, $id]);
    header('Location: ?p=produktionsauftrag&id=' . $id); exit;
}
// Produktionsweg umstellen (verkürzt bei Zukauf / voll)
if ($id && $_SERVER['REQUEST_METHOD'] === 'POST' && in_array(($_POST['aktion'] ?? ''), ['weg_zukauf', 'weg_voll'], true)) {
    $fremd = ($_POST['aktion'] === 'weg_zukauf');
    $ok = produktion_schritte_regenerieren($id, $fremd);
    if ($ok) q("UPDATE produktionsauftrag SET produktionsart=? WHERE id=?", [$fremd ? 'fremd' : 'eigen', $id]);
    header('Location: ?p=produktionsauftrag&id=' . $id . ($ok ? '&weg=1' : '&wegfehler=1')); exit;
}

$pa = $id ? one("SELECT pa.*, k.firma AS kunde_firma, p.name AS produkt_name, a.nummer AS auftrag_nr
                 FROM produktionsauftrag pa
                 LEFT JOIN kunden k ON k.id=pa.kunde_id LEFT JOIN produkt p ON p.id=pa.produkt_id
                 LEFT JOIN auftrag a ON a.id=pa.auftrag_id WHERE pa.id=?", [$id]) : null;
if (!$pa) { render_header('produktion','Produktion'); bx_head('Produktionsauftrag nicht gefunden','', bx_btn('Zurück','?p=produktion','ghost')); render_footer(); exit; }

$schritte = all("SELECT * FROM produktion_schritt WHERE pa_id=? ORDER BY sort, id", [$id]);
$total = count($schritte);
$done  = count(array_filter($schritte, fn($s)=> (int)$s['erledigt'] === 1));
$firstOpenId = null;
// Baustein 3: Produktionsbereitschaft (Material komplett da?)
$ber = produktion_bereitschaft($id);
// Baustein 5: adaptiver Weg – fertige Bulkware für den Auftrag zugekauft?
$istZukauf = produktion_ist_zukauf((int)$pa['auftrag_id']);
$stationen = array_map(fn($s)=> $s['station'], $schritte);
$istVollerWeg = in_array('Mischen', $stationen, true);   // enthält Herstellungsschritte
$wegUmstellbar = $done === 0;                            // nur solange nichts erledigt
foreach ($schritte as $s) if ((int)$s['erledigt'] === 0) { $firstOpenId = (int)$s['id']; break; }

$bedarf = produktion_materialbedarf($id);
// Leerkapsel-Bedarf (nur Kapselprodukte) – separat, damit er nicht doppelt (bereitstellen + verkapseln) abgebucht wird
$kapId = produkt_leerkapsel_id((int)$pa['produkt_id']);
$kapNeed = null;
if ($kapId) {
    $einhK = (int) scalar("SELECT einheiten_pro_packung FROM produkt WHERE id=?", [$pa['produkt_id']]);
    $needK = (float)$pa['menge'] * $einhK;
    $verfK = item_bestand($kapId, true);
    $kapNeed = ['name'=>scalar("SELECT name FROM item WHERE id=?", [$kapId]), 'benoetigt'=>$needK, 'verfuegbar'=>$verfK, 'fehlt'=>max(0.0, $needK - $verfK)];
}
$verbrauch = all("SELECT v.*, c.charge_nr, i.name AS item_name FROM produktion_verbrauch v
                  LEFT JOIN charge c ON c.id=v.charge_id LEFT JOIN item i ON i.id=v.item_id
                  WHERE v.pa_id=? ORDER BY v.id", [$id]);
$mng = fn($x,$e) => rtrim(rtrim(number_format((float)$x,3,',','.'),'0'),',') . ' ' . h($e ?: '');
// Alle Fertigwaren-Chargen zu diesem Auftrag (.A, .B, .C …) – bei Teilproduktion mehrere.
$fwChargen = all("SELECT c.id, c.charge_nr, c.menge, c.menge_verfuegbar, c.mhd, c.status, c.wareneingang, c.item_id, i.artikelnummer, i.name
                   FROM charge c JOIN item i ON i.id=c.item_id WHERE c.pa_id=? ORDER BY c.id", [$id]);
$fertigware = $fwChargen[0] ?? null;
$fwGebucht  = produktion_gebucht($id);
$fwRest     = max(0.0, (float)$pa['menge'] - $fwGebucht);
// Standard-Chargennummer (PR-Nr + .A/.B/.C) und MHD (18 Monate) – für die Geräte-Eingabe, schon vor der Buchung sichtbar.
$fCharge   = one("SELECT charge_nr, mhd FROM charge WHERE pa_id=? ORDER BY id LIMIT 1", [$id]);
$chargeGeb = (bool)$fCharge;
$chargeNr  = $fCharge ? $fCharge['charge_nr'] : charge_naechste_nr($id);
$chargeMhd = ($fCharge && $fCharge['mhd']) ? $fCharge['mhd'] : mhd_standard();

$statusBadge = match ($pa['status']) {
    'offen'    => bx_badge('offen','info'),
    'laufend'  => bx_badge('läuft','warn'),
    'erledigt' => bx_badge('fertig','ok'),
    default    => bx_badge(status_text($pa['status'])),
};

render_header('produktion', $pa['nummer']);
bx_head($pa['nummer'], 'Produktionsauftrag', bx_btn('Zurück zur Liste', '?p=produktion', 'ghost'));
if (isset($_GET['ok'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Station abgeschlossen.</div>';
if (isset($_GET['mangel'])) echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:12px 16px">Nicht genug Bestand für die Produktion – siehe Materialbedarf unten. Bitte erst Wareneingang buchen.</div>';
if (isset($_GET['weg']))    echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Produktionsweg umgestellt.</div>';
if (isset($_GET['wegfehler'])) echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:12px 16px">Weg kann nicht mehr geändert werden – es wurde bereits ein Schritt erledigt.</div>';
if (isset($_GET['scanfehler'])) echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:12px 16px">Scan abgelehnt: ' . h($_GET['scanfehler']) . '</div>';
if (isset($_GET['bestellt'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">' . ((int)$_GET['bestellt'] > 0 ? (int)$_GET['bestellt'] . ' Bestellung(en) als Entwurf angelegt – im Einkauf prüfen und absenden.' : 'Kein offener Fehlbedarf – nichts zu bestellen.') . '</div>';
if (isset($_GET['etikett'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Etikett-Design aktualisiert.</div>';
if (isset($_GET['reserviert'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">' . ((int)$_GET['reserviert'] > 0 ? 'Bestand für ' . (int)$_GET['reserviert'] . ' Komponente(n) reserviert.' : 'Nichts zu reservieren (kein freier Bestand verfügbar).') . '</div>';
if (isset($_GET['resfrei'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Reservierungen freigegeben.</div>';
if (isset($_GET['teil'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Teilmenge als Charge ' . h((string)$_GET['teil']) . ' eingebucht.</div>';
if (isset($_GET['teilfehler'])) echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:12px 16px">Teilmenge nicht gebucht: ' . h((string)$_GET['teilfehler']) . '</div>';

echo '<div class="bx-cards">';
echo '<div class="bx-card"><div class="k">Status</div><div class="v">' . $statusBadge . '</div></div>';
$prioSel = '<form method="post" style="margin:0"><input type="hidden" name="aktion" value="prio"><select name="prio" onchange="this.form.submit()">';
foreach (prio_liste() as $pk => $pl) $prioSel .= '<option value="' . $pk . '"' . ((int)$pa['prio'] === $pk ? ' selected' : '') . '>' . $pl . '</option>';
$prioSel .= '</select></form>';
echo '<div class="bx-card"><div class="k">Priorität</div><div class="v">' . $prioSel . '</div></div>';
$geplantForm = '<form method="post" style="margin:0"><input type="hidden" name="aktion" value="geplant"><input type="date" name="geplant_am" value="' . h((string)($pa['geplant_am'] ?? '')) . '" onchange="this.form.submit()"></form>';
echo '<div class="bx-card"><div class="k">Geplant am</div><div class="v">' . $geplantForm . '</div></div>';
echo '<div class="bx-card"><div class="k">Bereitschaft</div><div class="v">' . bereitschaft_badge($ber['status']) . '</div></div>';
echo '<div class="bx-card"><div class="k">Fortschritt</div><div class="v">' . $done . ' / ' . $total . '</div></div>';
echo '<div class="bx-card"><div class="k">Menge</div><div class="v">' . (int)$pa['menge'] . '</div></div>';
echo '<div class="bx-card"><div class="k">Charge' . ($chargeGeb ? (count($fwChargen) > 1 ? 'n' : '') : ' (geplant)') . '</div><div class="v">' . h($chargeNr) . (count($fwChargen) > 1 ? ' <span class="muted" style="font-size:13px">+' . (count($fwChargen) - 1) . '</span>' : '') . '</div></div>';
echo '<div class="bx-card"><div class="k">MHD' . ($chargeGeb ? '' : ' (+18 Mon.)') . '</div><div class="v">' . h(date('d.m.Y', strtotime($chargeMhd))) . '</div></div>';
echo '<div class="bx-card"><div class="k">Art</div><div class="v">' . (($pa['produktionsart'] ?? 'eigen') === 'fremd' ? bx_badge('Fremdproduktion','info') : bx_badge('Eigenproduktion','ok')) . '</div></div>';
echo '<div class="bx-card"><div class="k">Produkt</div><div class="v" style="font-size:15px">' . h($pa['produkt_name'] ?: '–') . '</div></div>';
echo '</div>';
if (!$chargeGeb) echo '<div class="bx-panel" style="padding:10px 14px;font-size:13px;color:var(--muted)">Chargennummer <strong>' . h($chargeNr) . '</strong> und MHD <strong>' . h(date('d.m.Y', strtotime($chargeMhd))) . '</strong> in die Produktionsgeräte eintragen. Teilproduktionen an weiteren Tagen erhalten dieselbe Basis mit <strong>.B</strong>, <strong>.C</strong> …</div>';

if ($ber['status'] === 'wartet' && $ber['fehlend']):
    $mfmt = fn($x) => rtrim(rtrim(number_format((float)$x, 3, ',', '.'), '0'), ',');
?>
<div class="bx-panel" style="border-color:#e6c4c0">
  <h2 style="margin-top:0">Wartet auf Material</h2>
  <p class="muted" style="margin-top:0">Für die Produktion fehlt noch Bestand. Sobald alles da ist, wird der Auftrag „produktionsbereit".</p>
  <div class="bx-tablewrap"><table class="bx-table">
    <thead><tr><th>Material</th><th class="bx-num">Benötigt</th><th class="bx-num">Verfügbar</th><th class="bx-num">Fehlt</th></tr></thead>
    <tbody>
      <?php foreach ($ber['fehlend'] as $f): $fehlt = (float)$f['benoetigt'] - (float)$f['verfuegbar']; ?>
        <tr>
          <td><?= h($f['name']) ?></td>
          <td class="bx-num"><?= $mfmt($f['benoetigt']) ?> <?= h($f['einheit']) ?></td>
          <td class="bx-num"><?= $mfmt($f['verfuegbar']) ?> <?= h($f['einheit']) ?></td>
          <td class="bx-num" style="color:#8f231b"><?= $mfmt(max(0,$fehlt)) ?> <?= h($f['einheit']) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
  <div style="margin-top:12px"><a class="btn btn-ghost btn-sm" href="?p=wareneingang">Zum Wareneingang</a></div>
</div>
<?php endif; ?>
<?php

// Baustein 4: Wareneingänge, die diesem Auftrag zugeordnet wurden (Beschaffungskette)
$zugeChargen = $pa['auftrag_id'] ? all(
    "SELECT c.*, i.name AS item_name, i.kategorie AS kategorie, i.form AS form
     FROM charge c LEFT JOIN item i ON i.id=c.item_id
     WHERE c.auftrag_id=? ORDER BY c.angelegt DESC", [(int)$pa['auftrag_id']]) : [];
if ($zugeChargen):
    $katLbl = ['rohstoff'=>'Rohstoff','verpackung'=>'Verpackung','fertig'=>'Fertigware (Bulk)','verkaufsfertig'=>'Fertigware'];
?>
<div class="bx-panel" style="border-color:var(--gruen)">
  <h2 style="margin-top:0">Wareneingänge für diesen Auftrag</h2>
  <p class="muted" style="margin-top:0">Diese Lieferungen wurden gezielt für diesen Auftrag bestellt/eingebucht.</p>
  <div class="bx-tablewrap"><table class="bx-table">
    <thead><tr><th>Charge</th><th>Artikel</th><th>Art</th><th class="bx-num">Menge</th><th>MHD</th><th>Status</th></tr></thead>
    <tbody>
      <?php foreach ($zugeChargen as $c): ?>
        <tr onclick="location.href='?p=chargen&id=<?= (int)$c['id'] ?>'" style="cursor:pointer">
          <td><?= h($c['charge_nr'] ?: ('#'.$c['id'])) ?></td>
          <td><?= h($c['item_name'] ?: '–') ?></td>
          <td><?= h($katLbl[$c['kategorie']] ?? $c['kategorie']) ?><?= $c['form'] ? ' · ' . h($c['form']) : '' ?></td>
          <td class="bx-num"><?= $mng($c['menge_verfuegbar'], $c['einheit']) ?></td>
          <td><?= $c['mhd'] ? h(date('d.m.Y', strtotime($c['mhd']))) : '–' ?></td>
          <td><?= match($c['status']){'frei'=>bx_badge('frei','ok'),'quarantaene'=>bx_badge('Quarantäne','warn'),'leer'=>bx_badge('leer'),default=>bx_badge(status_text($c['status']))} ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php endif; ?>

<?php if ($istZukauf && $istVollerWeg && $wegUmstellbar): ?>
<div class="bx-panel" style="border-color:var(--gruen);background:rgba(29,158,117,.06)">
  <h2 style="margin-top:0">Fertige Bulkware erkannt – verkürzter Weg möglich</h2>
  <p style="margin-top:0">Für diesen Auftrag ist bereits <strong>fertige Bulkware</strong> (z. B. fertige Kapseln vom Lieferanten) eingegangen. Damit entfallen <strong>Rohstoffe bereitstellen, Mischen und Verkapseln</strong> – es bleibt nur Bereitstellen, Verpacken, Etikettieren und die Prüfung/Freigabe.</p>
  <form method="post" style="margin:0"><input type="hidden" name="aktion" value="weg_zukauf"><button class="btn btn-primary" type="submit">Verkürzten Weg anwenden</button></form>
</div>
<?php elseif (!$istVollerWeg && $wegUmstellbar): ?>
<div class="bx-panel" style="padding:12px 16px">
  <div class="bx-row" style="justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
    <span class="muted">Dieser Auftrag läuft auf dem <strong>verkürzten Weg</strong> (zugekaufte Bulkware – ohne Mischen/Verkapseln).</span>
    <form method="post" style="margin:0"><input type="hidden" name="aktion" value="weg_voll"><button class="btn btn-ghost btn-sm" type="submit">Auf vollen Produktionsweg umstellen</button></form>
  </div>
</div>
<?php endif; ?>

<?php
$etHatSlot = (int) scalar("SELECT etikett_id FROM produkt WHERE id=?", [(int)$pa['produkt_id']]) > 0;
$etDok = $pa['auftrag_id'] ? etikett_datei((int)$pa['auftrag_id']) : null;
if ($etHatSlot):
?>
<div class="bx-panel"<?= $etDok ? '' : ' style="border-color:#e6c4c0"' ?>>
  <h2 style="margin-top:0">Etikett-Design <?= $etDok ? bx_badge('vorhanden','ok') : bx_badge('fehlt – Etikett nicht bestellbar','warn') ?></h2>
  <?php if ($etDok): ?>
    <div class="bx-row" style="justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
      <div><a href="?p=dokument&id=<?= (int)$etDok['id'] ?>" target="_blank"><?= h($etDok['datei_orig'] ?: 'Etikett-Design') ?></a> <span class="muted">· hochgeladen <?= h(fmt_zeit($etDok['angelegt'], 'd.m.Y')) ?></span></div>
      <div class="bx-row" style="gap:8px">
        <form method="post" enctype="multipart/form-data" class="bx-row" style="gap:6px;margin:0"><input type="hidden" name="aktion" value="etikett_upload"><input type="file" name="etikett" required accept="application/pdf,image/*"><button class="btn btn-ghost btn-sm" type="submit">Ersetzen</button></form>
        <form method="post" style="margin:0" onsubmit="return confirm('Etikett-Design löschen?');"><input type="hidden" name="aktion" value="etikett_del"><button class="btn btn-ghost btn-sm" type="submit">Löschen</button></form>
      </div>
    </div>
  <?php else: ?>
    <p class="muted" style="margin-top:0">Das Etikett ist kundenspezifisch und kann erst bestellt werden, wenn das Design vorliegt. Der Kunde kann es im Portal hochladen – oder lade die vom Kunden erhaltene Datei hier hoch.</p>
    <form method="post" enctype="multipart/form-data" class="bx-row" style="gap:8px;align-items:center;margin:0"><input type="hidden" name="aktion" value="etikett_upload"><input type="file" name="etikett" required accept="application/pdf,image/*"><button class="btn btn-primary btn-sm" type="submit">Etikett-Design hochladen</button></form>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php
// Einkaufsbedarf (Stückliste vs. Bestand) – was muss bestellt werden?
$bedarfListe = auftrag_bedarf($id);
$fehlListe   = auftrag_fehlbedarf($id);
$mfmt = fn($x) => rtrim(rtrim(number_format((float)$x, 3, ',', '.'), '0'), ',');
if ($bedarfListe):
    $rolleBadge = fn($r) => match ($r) {
        'Rohstoff'=>bx_badge('Rohstoff'), 'Leerkapsel'=>bx_badge('Leerkapsel'), 'Fertigware'=>bx_badge('Fertigware','info'),
        'Verpackung'=>bx_badge('Verpackung'), 'Deckel'=>bx_badge('Deckel'), 'Etikett'=>bx_badge('Etikett'),
        'Karton'=>bx_badge('Karton'), 'Beipackzettel'=>bx_badge('Beipack'), default=>bx_badge($r) };
?>
<div class="bx-panel"<?= $fehlListe ? ' style="border-color:#e6c4c0"' : '' ?>>
  <?php $hatReserviert = false; foreach ($bedarfListe as $rr) if (($rr['reserviert_eigen'] ?? 0) > 1e-6) { $hatReserviert = true; break; } ?>
  <div class="bx-row" style="justify-content:space-between;align-items:baseline;flex-wrap:wrap;gap:8px">
    <h2 style="margin:0">Einkaufsbedarf <?= $fehlListe ? bx_badge(count($fehlListe) . ' zu bestellen','warn') : bx_badge('alles auf Lager','ok') ?></h2>
    <div class="bx-row" style="gap:8px">
      <?php if ($pa['status'] !== 'erledigt'): ?>
        <form method="post" style="margin:0"><input type="hidden" name="aktion" value="reservieren">
          <button class="btn btn-ghost btn-sm" type="submit">Bestand reservieren</button></form>
        <?php if ($hatReserviert): ?>
          <form method="post" style="margin:0"><input type="hidden" name="aktion" value="reservierung_frei">
            <button class="btn btn-ghost btn-sm" type="submit">Reservierung freigeben</button></form>
        <?php endif; ?>
      <?php endif; ?>
      <?php if ($fehlListe): ?>
        <form method="post" style="margin:0"><input type="hidden" name="aktion" value="bedarf_bestellen">
          <button class="btn btn-primary btn-sm" type="submit">Bestellung(en) anlegen</button></form>
      <?php endif; ?>
    </div>
  </div>
  <div class="bx-tablewrap" style="margin-top:12px"><table class="bx-table">
    <thead><tr><th>Komponente</th><th></th><th class="bx-num">Benötigt</th><th class="bx-num">Verfügbar (netto)</th><th class="bx-num">Reserviert</th><th class="bx-num">Fehlt</th></tr></thead>
    <tbody>
      <?php foreach ($bedarfListe as $r): $fehlt = (float)$r['fehlt']; $res = (float)($r['reserviert_eigen'] ?? 0); ?>
        <tr>
          <td><?= h($r['name']) ?></td>
          <td><?= $rolleBadge($r['rolle']) ?></td>
          <td class="bx-num"><?= $mfmt($r['benoetigt']) ?> <?= h($r['einheit']) ?></td>
          <td class="bx-num"><?= $mfmt($r['verfuegbar']) ?> <?= h($r['einheit']) ?></td>
          <td class="bx-num"><?= $res > 1e-6 ? '<span class="bx-ok">' . $mfmt($res) . ' ' . h($r['einheit']) . '</span>' : '<span class="muted">–</span>' ?></td>
          <td class="bx-num"><?= $fehlt > 1e-6 ? '<strong style="color:#8f231b">' . $mfmt($fehlt) . ' ' . h($r['einheit']) . '</strong>' : '<span class="bx-ok">✓</span>' ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
  <p class="muted" style="font-size:12px;margin:10px 0 0">„Verfügbar (netto)" = freier Bestand minus Reservierungen anderer Aufträge. „Bestand reservieren" sichert den aktuell freien Bestand für diesen Auftrag – andere Aufträge sehen ihn dann nicht mehr als verfügbar.<?= $fehlListe ? ' „Bestellung(en) anlegen" erzeugt je Hauptlieferant einen Entwurf (mit Auftragsbezug, Netting gegen offene Bestellungen).' : '' ?></p>
</div>
<?php endif; ?>

<?php
// Bestellungen, die für diesen Auftrag angelegt wurden (Rückverfolgung / „wohin ist der Bedarf gewandert")
$auftBestellungen = $pa['auftrag_id'] ? all(
    "SELECT DISTINCT b.id, b.nummer, b.status, l.firma AS lieferant
     FROM bestellung b JOIN bestellung_position bp ON bp.bestellung_id=b.id
     LEFT JOIN lieferanten l ON l.id=b.lieferant_id
     WHERE bp.auftrag_id=? ORDER BY b.id DESC", [(int)$pa['auftrag_id']]) : [];
if ($auftBestellungen):
    $darfEinkauf = function_exists('route_erlaubt') ? route_erlaubt('bestellung') : true;
    $beStatus = fn($s) => match ($s) { 'offen'=>bx_badge('Entwurf','info'),'bestellt'=>bx_badge('bestellt','warn'),'geliefert'=>bx_badge('geliefert','ok'),default=>bx_badge($s) };
?>
<div class="bx-panel">
  <h2 style="margin-top:0">Bestellungen für diesen Auftrag</h2>
  <div class="bx-tablewrap"><table class="bx-table">
    <thead><tr><th>Bestellung</th><th>Lieferant</th><th>Status</th></tr></thead>
    <tbody>
      <?php foreach ($auftBestellungen as $b): ?>
        <tr<?= $darfEinkauf ? ' onclick="location.href=\'?p=bestellung&id=' . (int)$b['id'] . '\'" style="cursor:pointer"' : '' ?>>
          <td><?= $darfEinkauf ? '<a href="?p=bestellung&id=' . (int)$b['id'] . '">' . h($b['nummer']) . '</a>' : h($b['nummer']) ?></td>
          <td><?= $b['lieferant'] ? h($b['lieferant']) : '<span class="muted">– noch offen</span>' ?></td>
          <td><?= $beStatus($b['status']) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
  <p class="muted" style="font-size:12px;margin:10px 0 0">Diese Bestellungen liegen im Bereich <strong>Einkauf → Bestellungen</strong>. Dort Lieferant setzen und absenden; der Wareneingang bucht die Ware dann automatisch auf diesen Auftrag.</p>
</div>
<?php endif; ?>

<?php if ($firstOpenId && $pa['status'] !== 'erledigt'):
    $curStep = null; foreach ($schritte as $s) if ((int)$s['id'] === $firstOpenId) { $curStep = $s; break; }
    $anl = station_anleitung($curStep['station']);
    $isGate = str_contains($curStep['station'], 'Freigabe');
?>
<div class="bx-panel" style="border-color:var(--gruen);background:rgba(29,158,117,.05)">
  <div class="muted">Jetzt dran – Schritt <?= $done + 1 ?> von <?= $total ?></div>
  <h2 style="margin:4px 0 8px"><?= h($curStep['station']) ?> <?= $isGate ? bx_badge('Gate','info') : '' ?></h2>
  <p style="margin:0 0 12px;font-size:15px"><?= h($anl['text']) ?></p>
  <form method="post" class="bx-row" style="gap:10px;align-items:flex-end;flex-wrap:wrap">
    <input type="hidden" name="aktion" value="erledigen">
    <input type="hidden" name="schritt" value="<?= (int)$firstOpenId ?>">
    <?php if ($anl['scan']): ?>
      <div class="bx-field" style="margin:0;max-width:300px">
        <label>Charge scannen oder eingeben</label>
        <input type="text" name="scan" autofocus autocomplete="off" placeholder="Charge-Nr. scannen …">
      </div>
    <?php endif; ?>
    <button class="btn btn-primary" type="submit"><?= $isGate ? 'Freigeben' : ($anl['scan'] ? 'Scannen &amp; erledigen' : 'Erledigt') ?></button>
  </form>
</div>
<?php endif; ?>

<?php
// Rohstoffbedarf nur auf dem VOLLEN Weg zeigen. Bei Fremdproduktion (zugekaufte Bulkware) werden die
// Rezeptur-Rohstoffe nie angefasst – sie hier als „fehlt" auszuweisen führt zu unnötigen Bestellungen.
// Bereits entnommene Chargen bleiben immer sichtbar (Rückverfolgung), auch wenn der Weg später umgestellt wurde.
if ($verbrauch || ($istVollerWeg && ($bedarf || $kapNeed))): ?>
<div class="bx-panel">
  <?php if ($verbrauch): ?>
    <h2>Entnommene Materialien (FEFO)</h2>
    <div class="bx-tablewrap"><table class="bx-table">
      <thead><tr><th>Rohstoff</th><th>Charge</th><th class="bx-num">Entnommen</th></tr></thead>
      <tbody>
      <?php foreach ($verbrauch as $vb): ?>
        <tr><td><?= h($vb['item_name'] ?: '–') ?></td><td><?= h($vb['charge_nr'] ?: '–') ?></td><td class="bx-num"><?= $mng($vb['menge'], $vb['einheit']) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <div class="muted" style="margin-top:8px">Bestand wurde beim Bereitstellen abgebucht.</div>
  <?php else: ?>
    <h2>Materialbedarf</h2>
    <div class="bx-tablewrap"><table class="bx-table">
      <thead><tr><th>Rohstoff</th><th class="bx-num">Benötigt</th><th class="bx-num">Verfügbar</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach ($bedarf as $b): $ok = $b['fehlt'] <= 0.0001; ?>
        <tr>
          <td><?= h($b['name']) ?></td>
          <td class="bx-num"><?= $mng($b['benoetigt'], $b['einheit']) ?></td>
          <td class="bx-num"><?= $mng($b['verfuegbar'], $b['einheit']) ?></td>
          <td><?= $ok ? bx_badge('genug','ok') : bx_badge('fehlt ' . $mng($b['fehlt'], $b['einheit']), 'err') ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if ($kapNeed): $okK = $kapNeed['fehlt'] <= 0.0001; ?>
        <tr>
          <td><?= h($kapNeed['name']) ?> <?= bx_badge('Kapselhülle','info') ?></td>
          <td class="bx-num"><?= number_format($kapNeed['benoetigt'],0,',','.') ?> Stück</td>
          <td class="bx-num"><?= number_format($kapNeed['verfuegbar'],0,',','.') ?> Stück</td>
          <td><?= $okK ? bx_badge('genug','ok') : bx_badge('fehlt ' . number_format($kapNeed['fehlt'],0,',','.') . ' Stück','err') ?></td>
        </tr>
      <?php endif; ?>
      </tbody>
    </table></div>
    <div class="muted" style="margin-top:8px">Beim Abschluss von „Rohstoffe bereitstellen" werden die Chargen nach FEFO (älteste MHD zuerst) abgebucht.</div>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="bx-panel">
  <h2>Stationen &amp; Freigaben</h2>
  <div class="bx-tablewrap"><table class="bx-table">
    <tbody>
    <?php foreach ($schritte as $s):
        $isDone = (int)$s['erledigt'] === 1;
        $isNext = (int)$s['id'] === $firstOpenId;
        $isGate = str_contains($s['station'], 'Freigabe');
    ?>
      <tr<?= $isNext ? ' style="background:var(--panel-2)"' : '' ?>>
        <td style="width:44px;text-align:center;font-size:18px">
          <?= $isDone ? '<span class="bx-ok">&#10003;</span>' : ($isNext ? '&#9654;' : '<span class="muted">&#9675;</span>') ?>
        </td>
        <td>
          <strong<?= $isDone ? '' : ($isNext ? '' : ' class="muted"') ?>><?= h($s['station']) ?></strong>
          <?= $isGate ? ' ' . bx_badge('Gate','info') : '' ?>
        </td>
        <td class="muted"><?= $isDone && $s['erledigt_at'] ? h(fmt_zeit($s['erledigt_at'])) : '' ?><?= $isDone && !empty($s['scan_charge']) ? ' · Charge ' . h($s['scan_charge']) : '' ?></td>
        <td style="width:160px;text-align:right">
          <?php if ($isNext): ?>
            <span class="badge badge-info">jetzt dran</span>
          <?php elseif ($isDone): ?>
            <span class="badge badge-ok">erledigt</span>
          <?php else: ?>
            <span class="muted">wartet</span>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>

<?php if ($fertigware): ?>
<div class="bx-panel" style="border-color:var(--gruen);background:var(--panel-2)">
  <h2>Fertigware eingebucht</h2>
  <div class="bx-row" style="justify-content:space-between;margin-bottom:10px">
    <div><strong><?= h($fertigware['name']) ?></strong> · <?= h($fertigware['artikelnummer']) ?></div>
    <div><?= bx_badge(number_format($fwGebucht,0,',','.') . ' von ' . number_format((float)$pa['menge'],0,',','.') . ' Stück gebucht', $fwRest > 0 ? 'warn' : 'ok') ?> <a class="btn btn-ghost btn-sm" href="?p=lager&kat=verkaufsfertig">zum Lager</a></div>
  </div>
  <div style="overflow-x:auto"><table class="bx-table">
    <thead><tr><th>Charge</th><th>Gebucht</th><th>Im Lager</th><th>MHD</th><th>Eingebucht am</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach ($fwChargen as $c): ?>
      <tr>
        <td><a href="?p=chargen&id=<?= (int)$c['id'] ?>"><?= h($c['charge_nr']) ?></a></td>
        <td><?= number_format((float)$c['menge'],0,',','.') ?></td>
        <td><?= number_format((float)$c['menge_verfuegbar'],0,',','.') ?></td>
        <td><?= $c['mhd'] ? h(date('d.m.Y', strtotime($c['mhd']))) : '–' ?></td>
        <td><?= $c['wareneingang'] ? h(date('d.m.Y', strtotime($c['wareneingang']))) : '–' ?></td>
        <td><?= h(status_text($c['status'])) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php endif; ?>

<?php if ($pa['produkt_id'] && $fwRest > 0): ?>
<div class="bx-panel">
  <h2>Teilmenge einbuchen</h2>
  <p class="muted" style="margin-top:0">Wird an mehreren Tagen produziert, kann jede fertige Teilmenge sofort als eigene Charge ins Lager
    (<?= h(charge_naechste_nr($id)) ?> ist die nächste Nummer). Beim Abschluss des Auftrags wird nur noch der Rest gebucht.
    Noch offen: <strong><?= number_format($fwRest,0,',','.') ?> Stück</strong>.</p>
  <form method="post" class="bx-row" style="gap:10px;align-items:flex-end;flex-wrap:wrap">
    <input type="hidden" name="aktion" value="teilmenge">
    <div class="bx-field" style="margin:0;max-width:160px"><label>Menge (Stück)</label>
      <input type="number" name="menge" min="1" max="<?= (int)$fwRest ?>" step="1" required></div>
    <div class="bx-field" style="margin:0;max-width:180px"><label>MHD <?= bx_hint('Leer = heute + Standardmonate aus den Einstellungen.') ?></label>
      <input type="date" name="mhd" value="<?= h(mhd_standard()) ?>"></div>
    <div class="bx-field" style="margin:0;flex:1;min-width:200px"><label>Notiz (optional)</label>
      <input type="text" name="notiz" maxlength="200" placeholder="z. B. Tag 1 von 3"></div>
    <button class="btn btn-primary" type="submit">Teilmenge einbuchen</button>
  </form>
</div>
<?php endif; ?>

<div class="bx-panel">
  <h2>Details</h2>
  <div class="bx-grid">
    <div><div class="k muted">Kunde</div><div><?= $pa['kunde_firma'] ? h($pa['kunde_firma']) : '–' ?></div></div>
    <div><div class="k muted">Aus Auftrag</div><div><?php if ($pa['auftrag_id']): ?><a href="?p=auftrag&id=<?= (int)$pa['auftrag_id'] ?>"><?= h($pa['auftrag_nr']) ?></a><?php else: ?>–<?php endif; ?></div></div>
  </div>
</div>
<?php render_footer(); ?>

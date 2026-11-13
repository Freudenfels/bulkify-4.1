<?php
// Lieferantenportal – Bestellungen. Route: ?p=lieferant_bestellung[&id=<ID>]
// Ohne id die Liste, mit id die einzelne Bestellung samt Ablauf-Panel.
require_once BX_ROOT . '/module/lieferant/portal_layout.php';
require_once BX_ROOT . '/core/bestellung_ui.php';
if (!ist_lieferant()) { header('Location: ?p=lieferant_login'); exit; }

$lid = aktueller_lieferant_id();
$id  = (int)($_GET['id'] ?? 0);
// Nur eigene Bestellungen – die Pruefung haengt an jeder Abfrage, nicht an der Oberflaeche.
$b   = $id ? one("SELECT * FROM bestellung WHERE id=? AND lieferant_id=? AND " . bestellung_portal_sichtbar_sql(), [$id, $lid]) : null;   // Entwürfe nie (Fable-3 T2.2)

// Paket 12.11.2026 Teil 5: Gebindeaufkleber (PDF, Drucken, Storno, Herstelldatum) – Baustein core/lief_aufkleber_ui.php
if ($b) {
    require_once BX_ROOT . '/core/lief_aufkleber_ui.php';
    if (la_pdf_get($b)) exit;
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($laR = la_post($b, 'lieferant', lp_sprache())) !== null) la_post_ausgeben($b, $laR, '?p=lieferant_bestellung&id=' . $id);
}
if ($b && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $aktion = (string)($_POST['aktion'] ?? '');
    $wer    = (string)(current_user()['name'] ?? 'Lieferant');
    $fehler = '';
    if ($aktion === 'lief_bestaetigen') {
        $fehler = bestellung_bestaetigen($id, (string)($_POST['eta_geplant'] ?? ''), trim((string)($_POST['wer'] ?? '')) ?: $wer, lp_sprache());
        if ($fehler === '' && (int)$b['bestaetigt'] !== 1 && mail_bereit()) mail_team_bestellung($id, 'bestätigt');
    } elseif ($aktion === 'lief_station') {
        $fehler = bestellung_station_setzen($id, (string)($_POST['station'] ?? ''), $wer, lp_sprache());
        // Mail nur bei „versendet", andere Stationen nur als Benachrichtigung (Mailflut, Paket Mails).
        $stK = (string)($_POST['station'] ?? '');
        if ($fehler === '') mail_team_bestellung($id, bestellung_stationen()[$stK] ?? $stK, $stK === 'versendet' && mail_bereit());
    } elseif ($aktion === 'nachricht') {
        $fehler = nachricht_post_verarbeiten($lid, 'lieferant', $wer, 'bestellung', $id, lp_sprache());
    } elseif ($aktion === 'lief_versand') {
        q("UPDATE bestellung SET produktion_geplant=?, versandanbieter=?, versandart=?, tracking=? WHERE id=? AND lieferant_id=?", [
            trim((string)($_POST['produktion_geplant'] ?? '')) ?: null,
            mb_substr(trim((string)($_POST['versandanbieter'] ?? '')), 0, 60) ?: null,
            array_key_exists((string)($_POST['versandart'] ?? ''), versandart_liste()) ? $_POST['versandart'] : null,
            mb_substr(trim((string)($_POST['tracking'] ?? '')), 0, 120) ?: null, $id, $lid]);
        if (isset($_POST['pakete_angekuendigt']) && ctype_digit(trim((string)$_POST['pakete_angekuendigt'])))
            q("UPDATE bestellung SET pakete_angekuendigt=? WHERE id=? AND lieferant_id=?", [(int)$_POST['pakete_angekuendigt'], $id, $lid]);
    } elseif ($aktion === 'lief_produktion') {
        $pg = trim((string)($_POST['produktion_geplant'] ?? ''));
        q("UPDATE bestellung SET produktion_geplant=? WHERE id=? AND lieferant_id=?", [$pg !== '' && strtotime($pg) ? date('Y-m-d', strtotime($pg)) : null, $id, $lid]);
    } elseif ($aktion === 'lief_versand_neu') {
        // Bereich Versand (13.11.2026): Anbieter, Versandart, Sendung, Anzahl Pakete + je Paket die Sendungsnummer; optional gleich „versendet“.
        if (!empty($_POST['paket_del'])) {
            lieferung_paket_loeschen((int)$_POST['paket_del'], $id);
        } else {
            $anb = mb_substr(trim((string)($_POST['versandanbieter'] ?? '')), 0, 60);
            $vaw = (string)($_POST['versandart'] ?? '');
            $vaOk = $vaw !== '' && (array_key_exists($vaw, versandart_liste()) || $vaw === (string)($b['versandart'] ?? ''));
            $tr  = mb_substr(preg_replace('/\s+/', '', trim((string)($_POST['tracking'] ?? ''))), 0, 120);
            $anz = trim((string)($_POST['pakete_angekuendigt'] ?? ''));
            q("UPDATE bestellung SET versandanbieter=?, versandart=?, tracking=?, pakete_angekuendigt=? WHERE id=? AND lieferant_id=?",
              [$anb !== '' ? $anb : null, $vaOk ? $vaw : null, $tr !== '' ? $tr : null, ctype_digit($anz) ? (int)$anz : null, $id, $lid]);
            $rP = lieferung_pakete_hinzufuegen($id, implode("\n", array_map('strval', (array)($_POST['tracking_neu'] ?? []))), $anb !== '' ? $anb : null);
            if ($tr === '') {   // ohne Sammel-Sendungsnummer zählt die erste Paket-Nummer (Pflicht für „versendet“)
                $erste = scalar("SELECT tracking FROM lieferung_paket WHERE bestellung_id=? ORDER BY id LIMIT 1", [$id]);
                if ($erste) q("UPDATE bestellung SET tracking=? WHERE id=? AND lieferant_id=?", [$erste, $id, $lid]);
            }
            if (($rP['neu'] ?? 0) > 0) mail_team_bestellung($id, 'Versand-Pakete gemeldet', false);   // nur Benachrichtigung
            if (($rP['doppelt'] ?? 0) > 0) $fehler = (['de' => 'Sendungsnummer schon vorhanden, übersprungen: ', 'en' => 'Tracking number already exists, skipped: ', 'zh' => '物流单号已存在，已跳过：'][lp_sprache()] ?? 'Skipped: ') . (int)$rP['doppelt'];
            if (!empty($_POST['versendet'])) {
                $fV = bestellung_station_setzen($id, 'versendet', $wer, lp_sprache());
                if ($fV === '') mail_team_bestellung($id, bestellung_stationen()['versendet'], mail_bereit());
                else $fehler = $fV;
            }
        }
        header('Location: ?p=lieferant_bestellung&id=' . $id . ($fehler === '' ? '&ok=1' : '&fehler=' . urlencode($fehler)) . '#versand'); exit;
    } elseif ($aktion === 'pakete_add') {
        // Mehrere Tracking-Nummern (eine pro Zeile) + optional Anzahl Pakete (falls noch keine Nummern).
        $r = lieferung_pakete_hinzufuegen($id, (string)($_POST['trackings'] ?? ''), (string)($_POST['spediteur'] ?? ''));
        $anz = trim((string)($_POST['pakete_angekuendigt'] ?? ''));
        if ($anz !== '' && ctype_digit($anz)) q("UPDATE bestellung SET pakete_angekuendigt=? WHERE id=? AND lieferant_id=?", [(int)$anz, $id, $lid]);
        if (($r['neu'] ?? 0) > 0) mail_team_bestellung($id, 'Versand-Pakete gemeldet', false);   // nur Benachrichtigung
    } elseif ($aktion === 'paket_del') {
        lieferung_paket_loeschen((int)($_POST['paket_id'] ?? 0), $id);
    } elseif ($aktion === 'bestell_dok_upload') {
        require_once BX_ROOT . '/core/lieferant_dateien.php';
        $fehler = bestell_dok_upload($id, $lid, (string)($_POST['bd_typ'] ?? 'sonstiges'), lp_sprache());
    } elseif (strncmp($aktion, 'bch_', 4) === 0) {
        // Chargen & Dokumente je Position – derselbe Baustein und dieselbe Ablage wie auf der Bestellseite des Teams.
        require_once BX_ROOT . '/core/bestellung_chargen.php';
        $fehler = bestellung_chargen_post($b, 'lieferant', lp_sprache()) ?? '';
    } elseif ($aktion === 'bestell_dok_del') {
        $dd = (int)($_POST['dok_id'] ?? 0);
        if ($dd) { $row = one("SELECT datei, typ FROM dokument WHERE id=? AND objekt_typ='bestellung' AND objekt_id=? AND lieferant_id=?", [$dd, $id, $lid]);
            // Fable-6 T4.2: Rechnung nur löschen, solange sie Entwurf ist; danach nur Ersatz-Upload (die alte Datei bleibt).
            $erRow = ($row && (string)$row['typ'] === 'rechnung' && table_exists('lieferant_rechnung')) ? one("SELECT id, status FROM lieferant_rechnung WHERE dokument_id=? AND status<>'storniert' ORDER BY id DESC LIMIT 1", [$dd]) : null;
            if ($erRow && (string)$erRow['status'] !== 'entwurf') {
                $fehler = ['de' => 'Die Rechnung ist schon erfasst und kann nicht mehr gelöscht werden. Bitte „Ersatz hochladen“ nutzen.', 'en' => 'The invoice has already been recorded and can no longer be deleted. Please use “Upload replacement”.', 'zh' => '发票已登记，无法删除。请使用“上传替换文件”。'][lp_sprache()] ?? 'Not possible.';
            } elseif ($row) {
                if ($erRow) q("UPDATE lieferant_rechnung SET status='storniert', notiz=TRIM(CONCAT(COALESCE(notiz,''), ' | vom Lieferanten zurückgezogen (Entwurf)')) WHERE id=? AND status='entwurf'", [(int)$erRow['id']]);
                if ((string)$row['typ'] !== 'rechnung') @unlink(BX_UPLOADS . '/' . basename((string)$row['datei']));   // Rechnungs-PDF bleibt auf dem Server
                q("DELETE FROM dokument WHERE id=?", [$dd]);
            } }
    } elseif ($aktion === 'bestell_dok_ersatz') {   // Fable-6 T4.2/T4.3: Ersatz-Rechnung – hängt sich an die vorhandene Eingangsrechnung
        $fehler = bestell_dok_upload($id, $lid, 'rechnung', lp_sprache(), (int)($_POST['dok_id'] ?? 0));
    }
    header('Location: ?p=lieferant_bestellung&id=' . $id . ($fehler === '' ? '&ok=1' : '&fehler=' . urlencode($fehler))); exit;
}

lp_head('bulkify – ' . lp_t('bestellungen'));
lp_shell_start('lieferant_bestellung');

if (isset($_GET['ok']))     echo '<div class="bx-panel badge-ok" style="padding:12px 16px">' . h(lp_t('gespeichert')) . '</div>';
if (isset($_GET['fehler'])) echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:12px 16px">' . h((string)$_GET['fehler']) . '</div>';

if (!$b):
    $liste = all("SELECT b.*,
                    (SELECT COALESCE(NULLIF(i.name,''), bp.bezeichnung)
                       FROM bestellung_position bp LEFT JOIN item i ON i.id=bp.item_id
                       WHERE bp.bestellung_id=b.id ORDER BY bp.sort, bp.id LIMIT 1) AS produkt,
                    (SELECT COALESCE(i.rezeptur_id, au.rezeptur_id, pr.rezeptur_id)
                       FROM bestellung_position bp LEFT JOIN item i ON i.id=bp.item_id LEFT JOIN auftrag au ON au.id=bp.auftrag_id
                       LEFT JOIN produkt pr ON pr.id=au.produkt_id
                       WHERE bp.bestellung_id=b.id ORDER BY bp.sort, bp.id LIMIT 1) AS rezeptur_id,
                    (SELECT COUNT(*) FROM bestellung_position bp WHERE bp.bestellung_id=b.id) AS n_pos
                  FROM bestellung b WHERE b.lieferant_id=? AND " . bestellung_portal_sichtbar_sql('b') . " ORDER BY (b.status='geliefert'), b.angelegt DESC", [$lid]);
?>
  <h1 style="margin-bottom:4px"><?= h(lp_t('bestellungen')) ?></h1>
  <div class="bx-panel">
    <?php if (!$liste): ?><div class="muted"><?= h(lp_t('keine_best')) ?></div><?php else: ?>
    <div class="bx-tablewrap"><table class="bx-table">
      <thead><tr><th><?= h(lp_t('nummer')) ?></th><th><?= h(lp_t('artikel')) ?></th><th><?= h(lp_t('datum')) ?></th><th><?= h(lp_t('termin')) ?></th><th><?= h(lp_t('status')) ?></th><th></th></tr></thead>
      <tbody><?php foreach ($liste as $r):
          $st = (string)$r['station'];
          $lbl = $st === '' ? lp_t('best_neu_lbl') : bestellung_stationen_fuer(lp_sprache())[$st]; ?>
        <tr><td><?= h($r['nummer']) ?></td>
            <td><?= ($r['produkt'] ?? '') === '' ? '<span class="muted">–</span>'
                    : (!empty($r['rezeptur_id']) ? '<a href="#" class="lp-rez" data-rid="' . (int)$r['rezeptur_id'] . '" title="' . h(lp_t('rez_ansehen')) . '">' . h($r['produkt']) . '</a>' : h($r['produkt'])) ?><?= (int)$r['n_pos'] > 1 ? ' <span class="muted" style="font-size:12px">+' . ((int)$r['n_pos'] - 1) . '</span>' : '' ?></td>
            <td><?= h(date('d.m.Y', strtotime((string)$r['angelegt']))) ?></td>
            <td><?= $r['eta_geplant'] ? h(date('d.m.Y', strtotime((string)$r['eta_geplant']))) : '<span class="muted">–</span>' ?></td>
            <td><?= h($lbl) ?></td>
            <td class="bx-num"><a class="btn btn-ghost btn-sm" href="?p=lieferant_bestellung&id=<?= (int)$r['id'] ?>"><?= h(lp_t('zur_bestellung')) ?></a></td></tr>
      <?php endforeach; ?></tbody>
    </table></div>
    <?php endif; ?>
  </div>
<?php else:
    // Rezeptur auflösen: entweder über das Bulk-Item (item.rezeptur_id) ODER – bei Fremdfertigungs-/Zukauf-
    // Bestellungen ohne Bulk-Item – über den verknüpften Auftrag/das Produkt. So sieht der Lieferant auch
    // bei Fertigprodukt-Zukauf, welche Rezeptur er herstellen soll.
    $pos = all("SELECT bp.*, i.name AS item_name, i.artikelnummer,
                       COALESCE(i.rezeptur_id, au.rezeptur_id, pr.rezeptur_id) AS rezeptur_id,
                       COALESCE(rz.nummer, rz2.nummer, rz3.nummer) AS rez_nr,
                       COALESCE(rz.name, rz2.name, rz3.name) AS rez_name
                FROM bestellung_position bp
                LEFT JOIN item i      ON i.id = bp.item_id
                LEFT JOIN auftrag au  ON au.id = bp.auftrag_id
                LEFT JOIN produkt pr  ON pr.id = au.produkt_id
                LEFT JOIN rezeptur rz  ON rz.id = i.rezeptur_id
                LEFT JOIN rezeptur rz2 ON rz2.id = au.rezeptur_id
                LEFT JOIN rezeptur rz3 ON rz3.id = pr.rezeptur_id
                WHERE bp.bestellung_id=? ORDER BY bp.sort, bp.id", [$id]);
    // Je Produkt EINE Zeile: Bulk-Positionen desselben Produkts („Bulk: X (AB-..)" + alte Puffer-Zeile „Bulk: X")
    // zur Gesamtmenge zusammenfassen – der Lieferant braucht nur Produkt + Gesamtmenge, nicht den internen
    // Auftrags-Split. Normale Artikel-Positionen (item_id gesetzt) bleiben einzeln.
    $posView = [];
    foreach ($pos as $p) {
        $istBulk = empty($p['item_id']) && strncmp((string)($p['bezeichnung'] ?? ''), 'Bulk', 4) === 0;
        if ($istBulk) {
            $name = trim(preg_replace('/\s*\(.*$/', '', preg_replace('/^Bulk:\s*/', '', (string)$p['bezeichnung'])));
            if ($name === '') $name = 'Bulk';
            $key = 'bulk:' . mb_strtolower($name);
            if (!isset($posView[$key])) { $posView[$key] = $p; $posView[$key]['bezeichnung'] = $name; }
            else $posView[$key]['menge'] = (float)$posView[$key]['menge'] + (float)$p['menge'];
        } else {
            $posView['pos:' . (int)$p['id']] = $p;
        }
    }
    $posView = array_values($posView);
    // Preise in der Währung DES LIEFERANTEN (USD/EUR/CNY) – Stückpreis mit 4 Nachkommastellen (Sub-Cent).
    $waehrung = (string) scalar("SELECT waehrung FROM lieferanten WHERE id=?", [$lid]) ?: 'EUR';
    $sym = ['EUR'=>'€', 'USD'=>'$', 'CNY'=>'¥'][$waehrung] ?? $waehrung;
    $money = fn($x, $dec = 2) => number_format((float)$x, $dec, ',', '.') . ' ' . $sym;
    $summe = 0.0; foreach ($pos as $p) $summe += (float)$p['menge'] * bestellposition_ek($p, (int)$lid);
?>
  <h1 style="margin-bottom:4px"><?= h($b['nummer']) ?></h1>
  <p class="bx-sub"><a href="?p=lieferant_bestellung">&larr; <?= h(lp_t('bestellungen')) ?></a></p>

  <?= bestellung_ablauf_panel($b, 'lieferant', lp_sprache()) ?>

  <div class="bx-panel">
    <div class="bx-row" style="justify-content:space-between;align-items:center">
      <h2 style="margin:0"><?= h(lp_t('positionen')) ?></h2>
      <a class="btn btn-ghost btn-sm" target="_blank" href="?p=lieferant_bestellung_pdf&id=<?= (int)$b['id'] ?>">&#8681; <?= h(lp_t('pdf')) ?></a>
    </div>
    <?php if (!$pos): ?>
    <div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:12px 16px;margin-top:12px"><?= h(lp_t('keine_positionen')) ?></div>
    <?php else: ?>
    <div class="bx-tablewrap" style="margin-top:12px"><table class="bx-table">
      <thead><tr><th><?= h(lp_t('artikel')) ?></th><th class="bx-num"><?= h(lp_t('menge')) ?></th><th><?= h(lp_t('einheit')) ?></th><th class="bx-num"><?= h(lp_t('preis')) ?></th><th class="bx-num"><?= h(lp_t('summe')) ?></th></tr></thead>
      <tbody><?php foreach ($posView as $p): ?>
        <tr><td><?= h(($p['item_name'] ?? '') !== '' ? $p['item_name'] : ($p['bezeichnung'] ?? '–')) ?>
              <?= $p['artikelnummer'] ? '<div class="muted" style="font-size:12px">' . h($p['artikelnummer']) . '</div>' : '' ?>
              <?php if (!empty($p['rezeptur_id'])): $rzT = trim((string)($p['rez_nr'] ?? '') . ' ' . (string)($p['rez_name'] ?? '')) ?: ('#' . (int)$p['rezeptur_id']); ?><div style="margin-top:4px;font-size:13px"><?= h(lp_t('rezeptur') ?: 'Rezeptur') ?>: <a href="?p=lieferant_rezeptur&id=<?= (int)$p['rezeptur_id'] ?>" class="lp-rez" data-rid="<?= (int)$p['rezeptur_id'] ?>" data-titel="<?= h($rzT) ?>" title="<?= h(lp_t('rez_ansehen')) ?>"><?= h($rzT) ?></a></div><?php endif; ?></td>
            <td class="bx-num"><?= rtrim(rtrim(number_format((float)$p['menge'], 3, ',', '.'), '0'), ',') ?></td>
            <td><?= h(lp_einheit($p['einheit'] ?? '', (float)$p['menge'])) ?></td>
            <?php $ekEff = bestellposition_ek($p, (int)$lid); ?>
            <td class="bx-num"><?= h(preis_anzeige($ekEff, $waehrung, lp_einheit($p['einheit'] ?? '', 1) ?: 'Stk')) ?></td>
            <td class="bx-num"><?= $money((float)$p['menge'] * $ekEff, 2) ?></td></tr>
      <?php endforeach; ?>
        <tr style="font-weight:600"><td colspan="4"><?= h(lp_t('summe')) ?></td><td class="bx-num"><?= $money($summe, 2) ?></td></tr>
      </tbody>
    </table></div>
    <?php endif; ?>
    <?php // Interne Notiz (bestellung.notiz) erscheint NIE im Portal – nur der bewusst gefüllte Hinweis an den Lieferanten (Sofortfix 11.11.2026). ?>
    <?php if (trim((string)($b['hinweis_lieferant'] ?? '')) !== ''): ?><div style="margin-top:10px;white-space:pre-line"><span class="muted"><?= h(lp_t('hinweis_bulkify')) ?>:</span> <?= h($b['hinweis_lieferant']) ?></div><?php endif; ?>
  </div>

  <?php // Dokumente & Rechnung: der Lieferant lädt CoA, Rechnung o. Ä. direkt zu DIESER Bestellung hoch.
  require_once BX_ROOT . '/core/lieferant_dateien.php';
  $bdoks = bestell_dokumente($id, true);   // je Position abgelegte CoA/Spezifikationen stehen im Block „Chargen & Dokumente"
  // 13.11.2026: fehlende Unterlagen zu dieser Bestellung – Hinweis mit Link auf „Fehlende Unterlagen“ (kein Bestell-Stopp, Ware bleibt gesperrt).
  require_once BX_ROOT . '/core/lieferant_unterlagen.php';
  $luFB = lu_bestellung_fehlt((int)$id);
  if ($luFB) {
      $spB = lp_sprache();
      echo '<div class="bx-panel" style="border-left:3px solid #C9A227"><div style="font-weight:600;margin-bottom:4px">' . h($spB === 'de' ? 'Unterlagen fehlen' : ($spB === 'zh' ? '缺少文件' : 'Documents missing')) . '</div>'
         . '<p style="margin:0 0 8px">' . h($spB === 'de' ? 'Bitte fehlende Unterlagen hochladen, sonst bleibt die Ware bei uns gesperrt.' : ($spB === 'zh' ? '请上传缺少的文件，否则货物将在我方被冻结。' : 'Please upload missing documents, otherwise the goods remain blocked at our site.')) . '</p>'
         . '<div class="muted" style="font-size:13px;margin-bottom:8px">' . h(lu_liste_text($luFB, $spB)) . '</div>'
         . '<a class="btn btn-primary btn-sm" href="?p=lieferant_unterlagen">' . h($spB === 'de' ? 'Fehlende Unterlagen hochladen' : ($spB === 'zh' ? '上传缺少的文件' : 'Upload missing documents')) . '</a></div>';
  }
  require_once BX_ROOT . '/core/bestellung_chargen.php';
  echo bestellung_chargen_panel($b, 'lieferant', lp_sprache());
  echo la_panel($b, '?p=lieferant_bestellung&id=' . (int)$id, 'lieferant', lp_sprache());   // Paket 12.11.2026 Teil 5: Labels / Aufkleber
  $BDTYP = ['coa'=>lp_t('bd_coa'), 'rechnung'=>lp_t('bd_rechnung'), 'lieferschein'=>lp_t('bd_lieferschein'), 'spec'=>lp_t('spezifikation'), 'analyse'=>lp_t('bd_analyse'), 'zahlungsbeleg'=>lp_t('bd_zahlungsbeleg'), 'sonstiges'=>lp_t('sonstiges')];
  // Zahlstatus je Rechnung schon hier (Dokumente stehen jetzt vor Versand und Zahlung, 13.11.2026)
  $erSt = function_exists('er_status_bestellung') ? er_status_bestellung((int)$id) : []; $erMap = [];
  foreach ($erSt as $er) { if ($er['dokument_id']) $erMap[$er['dokument_id']] = $er; } ?>
  <div class="bx-panel">
    <h2 style="margin:0 0 4px"><?= h(lp_t('dok_titel')) ?></h2>
    <p class="muted" style="margin:0 0 12px"><?= h(lp_t('dok_sub')) ?></p>
    <?php if ($bdoks): ?>
    <div class="bx-tablewrap" style="margin-bottom:12px"><table class="bx-table bx-karten-handy">
      <thead><tr><th><?= h(lp_t('dok_typ')) ?></th><th><?= h(lp_t('datei')) ?></th><th><?= h(lp_t('datum')) ?></th><th></th></tr></thead>
      <tbody>
      <?php foreach ($bdoks as $d): ?>
        <tr><td><?= h($BDTYP[$d['typ']] ?? $d['typ']) ?></td>
            <td><a href="?p=lieferant_dokument&id=<?= (int)$d['id'] ?>" target="_blank" rel="noopener"><?= h($d['titel'] ?: ($d['datei_orig'] ?: lp_t('dok_dokument'))) ?></a></td>
            <td class="muted"><?= h(date('d.m.Y', strtotime((string)$d['angelegt']))) ?><?php if (isset($erMap[(int)$d['id']])): ?><div style="font-size:12px;color:var(--text)"><?= h(er_status_text($erMap[(int)$d['id']], lp_sprache())) ?></div><?php endif; ?></td>
            <td style="text-align:right"><?php $erD = $erMap[(int)$d['id']] ?? null; $reFest = ($d['typ'] ?? '') === 'rechnung' && $erD && $erD['status'] !== 'entwurf';
              if ($reFest): // Fable-6 T4.2: erfasste Rechnung nicht löschen – nur Ersatz hochladen ?>
              <form method="post" enctype="multipart/form-data" style="margin:0;display:flex;gap:6px;justify-content:flex-end;align-items:center;flex-wrap:wrap"><input type="hidden" name="aktion" value="bestell_dok_ersatz"><input type="hidden" name="dok_id" value="<?= (int)$d['id'] ?>"><input type="file" name="dok" accept=".pdf,.jpg,.jpeg,.png" required style="max-width:200px"><button class="btn btn-ghost btn-sm" type="submit"><?= h(['de' => 'Ersatz hochladen', 'en' => 'Upload replacement', 'zh' => '上传替换文件'][lp_sprache()] ?? 'Upload replacement') ?></button></form>
              <?php elseif (($d['typ'] ?? '') !== 'zahlungsbeleg'): ?><form method="post" style="margin:0" onsubmit="return confirm(<?= h(json_encode(lp_t('dok_del_q'), JSON_UNESCAPED_UNICODE)) ?>);"><input type="hidden" name="aktion" value="bestell_dok_del"><input type="hidden" name="dok_id" value="<?= (int)$d['id'] ?>"><button class="btn btn-ghost btn-sm" type="submit"><?= h(lp_t('entfernen')) ?></button></form><?php endif; ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="aktion" value="bestell_dok_upload">
      <div class="bx-grid">
        <div class="bx-field"><label><?= h(lp_t('dok_typ')) ?></label><select name="bd_typ"><?php foreach ($BDTYP as $k=>$l): ?><option value="<?= $k ?>"<?= $k==='coa'?' selected':'' ?>><?= h($l) ?></option><?php endforeach; ?></select></div>
        <div class="bx-field"><label><?= h(lp_t('dok_titel_opt')) ?></label><input type="text" name="dok_titel" maxlength="190"></div>
        <div class="bx-field"><label><?= h(lp_t('datei')) ?> <span class="muted" style="font-weight:400">(<?= h(lp_t('dok_max')) ?> <?= lieferant_datei_max_mb() ?> MB)</span></label><input type="file" name="dok" required accept=".<?= implode(',.', lieferant_datei_endungen()) ?>"></div>
      </div>
      <div class="bx-row" style="margin-top:12px"><button class="btn btn-primary btn-sm" type="submit"><?= h(lp_t('dok_hochladen')) ?></button></div>
    </form>
  </div>

  <?php // Versand (13.11.2026): nach Positionen und Dokumenten. Anbieter, Versandart, Sendung, „Wie viele Pakete?“ -> je Paket eine Zeile
        // mit Sendungsnummer (JS legt die Zeilen passend zur Anzahl an). Ab „versandbereit“ meldet „Speichern und versendet melden“ den Versand.
    require_once BX_ROOT . '/core/lief_aufkleber.php';
    $spV = lp_sprache();
    $tV = fn($de, $en, $zh) => $spV === 'de' ? $de : ($spV === 'zh' ? $zh : $en);
    $pakete = lieferung_pakete($id);
    $angekuendigt = (int)($b['pakete_angekuendigt'] ?? 0);
    $laN = (int)(la_stand($id)['gesamt'] ?? 0);
    $vpAnz = max($angekuendigt ?: $laN, count($pakete));
    $vpFertig = in_array((string)$b['status'], ['geliefert', 'storniert'], true);
    $vpVersendet = in_array((string)$b['station'], ['versendet', 'eingegangen'], true);
    $vpBereit = (string)$b['station'] === 'versand';
    $coaFehltV = (int)$b['bestaetigt'] === 1 ? bestellung_versand_coa_fehlt($id, $spV === 'de' ? 'de' : ($spV === 'zh' ? 'zh' : 'en')) : [];
    $vaL = array_keys(versandart_liste()); $vaCur = (string)($b['versandart'] ?? ''); if ($vaCur !== '' && !in_array($vaCur, $vaL, true)) $vaL[] = $vaCur;
    $laHin = la_versand_hinweis($id, $spV);
    $dis = $vpFertig ? ' disabled' : '';
  ?>
  <style>.lp-vp-hinweis{padding:8px 14px;margin:0 0 12px;border-left:3px solid #C9A227;background:rgba(201,162,39,.12);border-radius:8px;font-size:14px}</style>
  <div class="bx-panel" id="versand">
    <h2 style="margin:0 0 12px"><?= h($tV('Versand', 'Shipping', '发货')) ?></h2>
    <?php if ((int)$b['bestaetigt'] !== 1): ?>
      <p class="muted" style="margin:0"><?= h($tV('Erst nach der Bestätigung oben.', 'Only after confirming the order above.', '请先在上方确认订单。')) ?></p>
    <?php else: ?>
    <?php if ($coaFehltV && !$vpVersendet): ?>
      <div class="lp-vp-hinweis"><?= h($tV('Vor dem Versand bitte die CoA der Charge unter „Chargen & Dokumente“ hochladen', 'Before shipping, please upload the CoA of the batch under “Batches & documents”', '发货前，请在“批次与文件”中上传批次的 CoA')) ?>: <?= h(implode('; ', $coaFehltV)) ?></div>
    <?php endif; ?>
    <?php if (!empty($luFB) && !$vpVersendet): ?>
      <div class="lp-vp-hinweis"><?= h($tV('Es fehlen noch Unterlagen (siehe oben). Ohne sie bleibt die Ware bei uns gesperrt.', 'Documents are still missing (see above). Without them the goods remain blocked at our site.', '仍缺少文件（见上方）。缺少文件时货物将在我方被冻结。')) ?></div>
    <?php endif; ?>
    <form method="post" style="margin:0" id="lpVpForm">
      <input type="hidden" name="aktion" value="lief_versand_neu">
      <div class="bx-grid">
        <div class="bx-field"><label><?= h($tV('Versandanbieter', 'Carrier', '承运商')) ?></label><input type="text" name="versandanbieter" maxlength="60" value="<?= h((string)($b['versandanbieter'] ?? '')) ?>" placeholder="DHL, UPS, Maersk …"<?= $dis ?>></div>
        <div class="bx-field"><label><?= h($tV('Versandart', 'Shipping method', '运输方式')) ?></label><select name="versandart"<?= $dis ?>><option value="">–</option><?php foreach ($vaL as $vk): ?><option value="<?= h($vk) ?>"<?= $vaCur === $vk ? ' selected' : '' ?>><?= h(versandart_label($vk, $spV)) ?></option><?php endforeach; ?></select></div>
        <div class="bx-field"><label><?= h($tV('Wie viele Pakete?', 'How many parcels?', '共几个包裹？')) ?></label><input type="number" min="0" max="200" name="pakete_angekuendigt" id="lpVpAnz" value="<?= $vpAnz ?: '' ?>" style="max-width:140px"<?= $dis ?>>
          <?php if ($laHin !== ''): ?><div class="muted" style="font-size:12px;margin-top:4px"><?= h($laHin) ?></div><?php endif; ?></div>
        <div class="bx-field"><label><?= h($tV('Sendungsnummer der ganzen Sendung', 'Tracking number of the whole shipment', '整批货物运单号')) ?> <span class="muted" style="font-weight:400">(<?= h($tV('optional, z. B. Frachtbrief', 'optional, e.g. bill of lading', '可选，如提单号')) ?>)</span></label><input type="text" name="tracking" maxlength="120" value="<?= h((string)($b['tracking'] ?? '')) ?>"<?= $dis ?>></div>
      </div>
      <div class="bx-tablewrap" id="lpVpWrap" style="margin-top:12px<?= ($vpAnz > 0 || $pakete) ? '' : ';display:none' ?>"><table class="bx-table bx-karten-handy">
        <thead><tr><th><?= h($tV('Paket', 'Parcel', '包裹')) ?></th><th><?= h(lp_t('vp_tracking_nr')) ?></th><th><?= h(lp_t('status')) ?></th><th></th></tr></thead>
        <tbody id="lpVpRows">
          <?php $i = 0; foreach ($pakete as $pk): $i++; ?>
          <tr data-alt="1"><td><?= $i ?></td><td><?= h((string)$pk['tracking']) ?><?= !empty($pk['spediteur']) ? ' <span class="muted" style="font-size:12px">· ' . h((string)$pk['spediteur']) . '</span>' : '' ?></td>
            <td><?= (int)$pk['angekommen'] === 1 ? '<span class="bx-ok">' . h(lp_t('vp_angekommen')) . '</span>' . (!empty($pk['angekommen_am']) ? ' <span class="muted" style="font-size:12px">· ' . h(fmt_zeit($pk['angekommen_am'], 'd.m.Y')) . '</span>' : '') : '<span class="muted">' . h(lp_t('vp_unterwegs')) . '</span>' ?></td>
            <td data-label="" style="text-align:right"><?php if ((int)$pk['angekommen'] !== 1 && !$vpFertig): ?><button class="btn btn-ghost btn-sm" type="submit" name="paket_del" value="<?= (int)$pk['id'] ?>" formnovalidate onclick="return confirm(<?= h(json_encode(lp_t('vp_track_del_q'), JSON_UNESCAPED_UNICODE)) ?>)"><?= h(lp_t('entfernen')) ?></button><?php endif; ?></td></tr>
          <?php endforeach; ?>
          <?php if (!$vpFertig) for ($j = $i + 1; $j <= $vpAnz; $j++): ?>
          <tr data-neu="1"><td><?= $j ?></td><td><input type="text" name="tracking_neu[]" maxlength="80" placeholder="<?= h($tV('Sendungsnummer', 'Tracking number', '运单号')) ?>"></td><td class="muted"><?= h($tV('neu', 'new', '新')) ?></td><td data-label=""></td></tr>
          <?php endfor; ?>
        </tbody>
      </table></div>
      <?php if (!$vpFertig): ?>
      <div class="bx-row" style="margin-top:12px;gap:8px;flex-wrap:wrap">
        <?php if ($vpBereit): ?><button class="btn btn-primary" type="submit" name="versendet" value="1"<?= $coaFehltV ? ' disabled title="' . h($tV('Erst die CoA hochladen', 'Upload the CoA first', '请先上传 CoA')) . '"' : '' ?>><?= h($tV('Speichern und versendet melden', 'Save and report shipped', '保存并报告已发货')) ?></button><?php endif; ?>
        <button class="btn <?= $vpBereit ? 'btn-ghost' : 'btn-primary' ?>" type="submit"><?= h($tV('Versanddaten speichern', 'Save shipping details', '保存物流信息')) ?></button>
      </div>
      <?php if (!$vpVersendet): ?><div class="muted" style="font-size:12px;margin-top:6px"><?= h($tV('„versendet“ geht ab „versandbereit“, wenn Anbieter, Versandart und mindestens eine Sendungsnummer eingetragen sind.', '“Shipped” is possible from “ready to ship” once carrier, shipping method and at least one tracking number are filled in.', '到“待发货”后，填写承运商、运输方式和至少一个运单号即可报告“已发货”。')) ?></div><?php endif; ?>
      <?php endif; ?>
    </form>
    <script>(function(){var a=document.getElementById('lpVpAnz'),tb=document.getElementById('lpVpRows'),w=document.getElementById('lpVpWrap');if(!a||!tb||!w)return;
      var ph=<?= json_encode($tV('Sendungsnummer', 'Tracking number', '运单号'), JSON_UNESCAPED_UNICODE) ?>,neu=<?= json_encode($tV('neu', 'new', '新'), JSON_UNESCAPED_UNICODE) ?>;
      var kopf=[].map.call(w.querySelectorAll('thead th'),function(t){return t.textContent.trim();});
      function bau(){var n=Math.max(0,Math.min(200,parseInt(a.value,10)||0)),alt=tb.querySelectorAll('tr[data-alt]').length,soll=Math.max(0,n-alt),rows=tb.querySelectorAll('tr[data-neu]');
        for(var i=rows.length;i>soll;i--){var r=rows[i-1];if(r.querySelector('input').value.trim()==='')r.parentNode.removeChild(r);}
        rows=tb.querySelectorAll('tr[data-neu]');
        for(var j=rows.length;j<soll;j++){var tr=document.createElement('tr');tr.setAttribute('data-neu','1');
          tr.innerHTML='<td data-label="'+kopf[0]+'">'+(alt+j+1)+'</td><td data-label="'+kopf[1]+'"><input type="text" name="tracking_neu[]" maxlength="80" placeholder="'+ph+'"></td><td class="muted" data-label="'+kopf[2]+'">'+neu+'</td><td data-label=""></td>';tb.appendChild(tr);}
        w.style.display=(alt+tb.querySelectorAll('tr[data-neu]').length)>0?'':'none';}
      a.addEventListener('input',bau);})();</script>
    <?php endif; ?>
  </div>
  <?php // E8: Zahlungsstatus (vom Team gesetzt) – der Lieferant sieht „bezahlt" samt Beleg.
    // Fable-6 T4.1 (13.11.2026): „bezahlt am“ zeigt der Lieferant NUR aus der Buchhaltung (Eingangsrechnung), nie aus bestellung.bezahlt_am.
    if (false): ?>
  <?php endif; ?>
  <?php // 13.11.2026: Zahlstatus der Rechnung(en) aus der Buchhaltung (lieferant_rechnung) – „bezahlt am“, teilbezahlt mit Rest
    $erSt = function_exists('er_status_bestellung') ? er_status_bestellung((int)$id) : []; $erMap = [];
    foreach ($erSt as $er) { if ($er['dokument_id']) $erMap[$er['dokument_id']] = $er; }
    if ($erSt): ?>
    <div class="bx-panel<?= $erSt[0]['status'] === 'bezahlt' ? ' badge-ok' : '' ?>" style="padding:10px 14px;margin-bottom:12px<?= $erSt[0]['status'] === 'bezahlt' ? '' : ';border-left:3px solid var(--gruen)' ?>">
      <?php foreach ($erSt as $er): ?><div><?= h(lp_t('bd_rechnung')) ?><?= $er['lief_nummer'] !== '' ? ' ' . h($er['lief_nummer']) : '' ?>: <strong style="font-weight:600"><?= h(er_status_text($er, lp_sprache())) ?></strong></div><?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?= nachricht_panel($lid, 'lieferant', lp_sprache(), 'bestellung', $id) ?>
<?php endif;
lp_rezeptur_popup();   // Schnellansicht Rezeptur + Rohstoffe (13.11.2026)
lp_shell_ende(); lp_foot();

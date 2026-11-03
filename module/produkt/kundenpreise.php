<?php
// Zentrale Kundenpreis-Liste (?p=kundenpreise): je Kunde x Produkt x Menge je Packung den HERSTELLUNGSPREIS je Packung
// (produkt_kundenpreis.preis, „VK je Packung OHNE Verpackung") direkt eintippen – ohne jeden Auftrag/jedes Produkt einzeln
// zu öffnen. Zeilen = vorhandene Kundenpreise + bestellte Kombinationen aus auftrag ohne Kundenpreis (Altfälle).
// Verpackung + Deckel + Etikett rechnet das System dazu (verpackung_cent_je_pack(): Staffel bei der Bestellmenge,
// Kundenrabatt, je Position auf Cent gerundet – wie Angebot/Beleg). Vollbedruckter Beutel: ohne Etikett.
// Optional (Schalter app_meta kundenpreise_auftrag_fuellen, Standard AUS bis zur Freigabe): unbepreiste Aufträge dieser
// Kombination bekommen vk_stueck/gesamt_netto. Belege werden dabei NICHT angelegt oder geändert.
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

$fuellen = (string) meta_get('kundenpreise_auftrag_fuellen', '0') === '1';

// --- Zeilen aufbauen -------------------------------------------------------------------------------------------
// Eine Zeile je Kunde x Produkt x Menge je Packung x BESTELLMENGE (Staffel, 03.11.2026): jede Bestellmenge hat ihren eigenen
// Herstellungspreis, Verpackung/VK/Gesamt werden bei genau dieser Menge gerechnet. Ein Kundenpreis ohne Bestellmenge
// (anzahl_vpe leer) gilt für alle Mengen – Aufträge ohne eigene Staffel hängen dann daran.
function kp_zeilen(): array {
    $z = [];
    $key = fn(int $p, int $k, int $s, int $m = 0) => $p . ':' . $k . ':' . $s . ':' . $m;
    foreach (all("SELECT id, produkt_id, kunde_id, menge_pro_vpe, anzahl_vpe, preis, notiz FROM produkt_kundenpreis
                  WHERE COALESCE(menge_pro_vpe,0) > 0 ORDER BY id") as $r) {
        $k = $key((int)$r['produkt_id'], (int)$r['kunde_id'], (int)$r['menge_pro_vpe'], (int)($r['anzahl_vpe'] ?? 0));
        $z[$k] = ['pid' => (int)$r['produkt_id'], 'kid' => (int)$r['kunde_id'], 'stk' => (int)$r['menge_pro_vpe'], 'bm' => (int)($r['anzahl_vpe'] ?? 0),
                  'kp_id' => (int)$r['id'], 'preis' => $r['preis'] !== null ? (float)$r['preis'] : null, 'anzahl' => (int)($r['anzahl_vpe'] ?? 0),
                  'notiz' => (string)($r['notiz'] ?? ''), 'auftraege' => []];
    }
    foreach (all("SELECT a.id, a.nummer, a.produkt_id, a.kunde_id, a.menge, a.verpackung_id, a.gesamt_netto, a.status,
                         COALESCE(NULLIF(a.stueck,0), p.einheiten_pro_packung, 0) AS stk
                  FROM auftrag a JOIN produkt p ON p.id = a.produkt_id
                  WHERE a.status <> 'storniert' AND a.kunde_id IS NOT NULL ORDER BY a.id DESC") as $a) {
        if ((int)$a['stk'] <= 0) continue;
        $k = $key((int)$a['produkt_id'], (int)$a['kunde_id'], (int)$a['stk'], (int)$a['menge']);
        $kAlle = $key((int)$a['produkt_id'], (int)$a['kunde_id'], (int)$a['stk']);
        if (!isset($z[$k]) && isset($z[$kAlle])) $k = $kAlle;   // Kundenpreis ohne Staffel gilt für alle Bestellmengen
        if (!isset($z[$k])) $z[$k] = ['pid' => (int)$a['produkt_id'], 'kid' => (int)$a['kunde_id'], 'stk' => (int)$a['stk'], 'bm' => (int)$a['menge'],
                                      'kp_id' => 0, 'preis' => null, 'anzahl' => 0, 'notiz' => '', 'auftraege' => []];
        $z[$k]['auftraege'][] = $a;
    }
    // Stammdaten + Berechnung je Zeile
    foreach ($z as $k => &$r) {
        $p = produkt_row_cached($r['pid']) ?: [];
        $r['produkt'] = (string)($p['name'] ?? ('Produkt ' . $r['pid']));
        $r['produkt_nr'] = (string)($p['nummer'] ?? '');
        $rz = !empty($p['rezeptur_id']) ? one("SELECT nummer, name, darreichungsform FROM rezeptur WHERE id=?", [(int)$p['rezeptur_id']]) : null;
        $r['rezeptur'] = (string)($rz['name'] ?? '');
        $r['rid'] = (int)($p['rezeptur_id'] ?? 0);
        $r['form'] = (string)($rz['darreichungsform'] ?? 'kapsel');
        $r['firma'] = (string) scalar("SELECT firma FROM kunden WHERE id=?", [$r['kid']]);
        $ohne = array_values(array_filter($r['auftraege'], fn($a) => (float)($a['gesamt_netto'] ?? 0) <= 0));
        $r['ohne_preis_auftraege'] = $ohne;
        $neuester = $r['auftraege'][0] ?? null;
        // Bestellmenge für Verpackung/Gesamt: die Staffel der Zeile; ohne Staffel offener unbepreister Auftrag, sonst letzter Auftrag.
        $r['menge'] = $r['bm'] ?: ((int)($ohne[0]['menge'] ?? 0) ?: (int)($neuester['menge'] ?? 0)) ?: 1;
        $r['verp_override'] = (int)($ohne[0]['verpackung_id'] ?? 0) ?: ((int)($neuester['verpackung_id'] ?? 0) ?: null);
        $r['verp_cent'] = verpackung_cent_je_pack($r['pid'], $r['menge'], $r['kid'], $r['verp_override']);
        $vp = $r['verp_override'] ?: (int)($p['verpackung_id'] ?? 0);
        $neutral = $vp ? (verpackung_neutrale_basis($vp) ?: $vp) : 0;
        $r['verpackung'] = $neutral ? (string) scalar("SELECT name FROM item WHERE id=?", [$neutral]) : '';
        $r['vollbedruckt'] = $vp ? verpackung_ist_bedruckt($vp) : false;
        // Behälter braucht ein Etikett, aber es gibt kein Maß-Etikett mit Preis -> Etikett fehlt im Preis (sichtbar machen).
        $rollen = array_column(produkt_verpackung_items($r['pid'], $r['verp_override']), 'rolle');
        $r['etikett_fehlt'] = $vp && !$r['vollbedruckt'] && !in_array('Etikett', $rollen, true);
        $r['mengen'] = array_values(array_unique(array_map(fn($a) => (int)$a['menge'], $r['auftraege'])));
    }
    unset($r);
    uasort($z, fn($a, $b) => [mb_strtolower($a['firma']), mb_strtolower($a['produkt']), $a['stk'], $a['bm']] <=> [mb_strtolower($b['firma']), mb_strtolower($b['produkt']), $b['stk'], $b['bm']]);
    return $z;
}

// Unbepreiste Aufträge einer Kombination füllen: vk_stueck = Herstellung + Verpackung/Deckel/Etikett je Pkg (je Position auf Cent,
// Staffel bei der Auftragsmenge, Behälter DES Auftrags), gesamt_netto = Menge x VK. Nur Aufträge mit 0,00 € Netto. Keine Belege.
function kp_auftraege_fuellen(array $r, float $preis): int {
    $n = 0;
    foreach ($r['ohne_preis_auftraege'] as $a) {
        $menge = (int)$a['menge']; if ($menge <= 0) continue;
        $cent  = (int) round($preis * 100) + verpackung_cent_je_pack($r['pid'], $menge, $r['kid'], (int)$a['verpackung_id'] ?: null);
        $vor = one("SELECT vk_stueck, gesamt_netto FROM auftrag WHERE id=?", [(int)$a['id']]);
        q("UPDATE auftrag SET vk_stueck=?, gesamt_netto=? WHERE id=? AND COALESCE(gesamt_netto,0) <= 0", [round($cent / 100, 4), round($cent * $menge / 100, 2), (int)$a['id']]);
        $nach = one("SELECT vk_stueck, gesamt_netto FROM auftrag WHERE id=?", [(int)$a['id']]);
        if ($vor && $nach && aenderung_protokollieren('auftrag', (int)$a['id'], $vor, $nach, 'kundenpreise') > 0) $n++;
    }
    return $n;
}

// --- Speichern ----------------------------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'speichern') {
    $alle = kp_zeilen();
    $nur  = (string)($_POST['nur'] ?? '');
    $gesp = 0; $gefuellt = 0; $mengeGes = 0; $aufKorr = 0; $fehler = [];
    $mengen = (array)($_POST['stk'] ?? []);
    foreach (array_unique(array_merge(array_keys((array)($_POST['preis'] ?? [])), array_keys($mengen))) as $k) {
        $k = (string)$k;
        if ($nur !== '' && $k !== $nur) continue;
        if (!isset($alle[$k])) continue;   // nur Zeilen aus der aktuellen Liste
        $r = $alle[$k];
        // Menge je Packung ändern (bei Pulver g je Packung): DIESELBE Kundenpreis-Zeile umstellen, keine neue anlegen.
        // Gibt es die Zielgröße für Produkt + Kunde schon als eigene Zeile, wird nichts zusammengelegt (Hinweis statt Doppelzeile).
        // Pulver-Altdaten (v3): war die alte Menge eine PORTIONSZAHL (31 statt 275 g), ziehen die Aufträge dieser Zeile und ihre
        // PA mit – sonst stünde der Auftrag mit 31 g als eigene Zeile da und Bedarf/Einkauf rechneten mit 31 g.
        $neuStk = trim((string)($mengen[$k] ?? '')) !== '' ? (int) preg_replace('/\D+/', '', (string)$mengen[$k]) : $r['stk'];
        if ($neuStk !== $r['stk']) {
            $wer = $r['firma'] . ' / ' . $r['produkt'] . ': ';
            if ($neuStk <= 0) { $fehler[] = $wer . 'Menge je Packung muss größer 0 sein'; continue; }
            $portion = form_ist_tagesdosis($r['form']) && pulver_ist_portionszahl($r['rid'], $r['stk'], $neuStk);
            if (!$r['kp_id'] && !$portion) { $fehler[] = $wer . 'die Menge je Packung eines Auftrags wird am Auftrag geändert'; continue; }
            if ($r['kp_id']) {
                $dup = (int) scalar("SELECT id FROM produkt_kundenpreis WHERE produkt_id=? AND kunde_id=? AND menge_pro_vpe=? AND COALESCE(anzahl_vpe,0)=? AND id<>? LIMIT 1", [$r['pid'], $r['kid'], $neuStk, $r['bm'], $r['kp_id']]);
                if ($dup) { $fehler[] = $wer . form_groessen_label($r['form'], $neuStk) . ' gibt es schon als eigene Zeile – Menge nicht geändert'; continue; }
                $vorM = one("SELECT * FROM produkt_kundenpreis WHERE id=?", [$r['kp_id']]) ?: [];
                q("UPDATE produkt_kundenpreis SET menge_pro_vpe=? WHERE id=? AND menge_pro_vpe=?", [$neuStk, $r['kp_id'], $r['stk']]);
                $nachM = one("SELECT * FROM produkt_kundenpreis WHERE id=?", [$r['kp_id']]) ?: [];
                if ($vorM && aenderung_protokollieren('produkt_kundenpreis', $r['kp_id'], $vorM, $nachM, 'kundenpreise') > 0) $mengeGes++;
            }
            if ($portion) foreach ($r['auftraege'] as $a) $aufKorr += pulver_auftrag_fuellmenge_korrigieren((int)$a['id'], $r['stk'], $neuStk, 'kundenpreise');
            $r['stk'] = $neuStk;
        }
        $roh = (string)($_POST['preis'][$k] ?? '');
        if (trim($roh) === '') continue;
        $preis = zahl_lesen($roh);
        if ($preis <= 0) { $fehler[] = $r['firma'] . ' / ' . $r['produkt'] . ': Preis muss größer 0 sein'; continue; }
        if ($r['preis'] !== null && abs($r['preis'] - round($preis, 4)) < 1e-6) continue;   // unverändert
        $vor = $r['kp_id'] ? (one("SELECT * FROM produkt_kundenpreis WHERE id=?", [$r['kp_id']]) ?: []) : [];
        kundenpreis_setzen($r['pid'], $r['kid'], $r['stk'], $r['bm'] ?: null, $preis, 'manuell: Preisliste');
        $id = (int) scalar("SELECT id FROM produkt_kundenpreis WHERE produkt_id=? AND kunde_id=? AND menge_pro_vpe=? AND COALESCE(anzahl_vpe,0)=? LIMIT 1", [$r['pid'], $r['kid'], $r['stk'], $r['bm']]);
        q("UPDATE produkt_kundenpreis SET notiz=? WHERE id=?", ['manuell: Preisliste', $id]);
        $nach = one("SELECT * FROM produkt_kundenpreis WHERE id=?", [$id]) ?: [];
        // Neue Zeile: nur die fachlichen Felder protokollieren (nicht id/angelegt).
        $felder = array_intersect_key($nach, array_flip(['menge_pro_vpe', 'anzahl_vpe', 'preis', 'notiz']));
        aenderung_protokollieren('produkt_kundenpreis', $id, $vor ?: array_map(fn() => null, $felder), $vor ? $nach : $felder, 'kundenpreise');
        $gesp++;
        if ($fuellen) $gefuellt += kp_auftraege_fuellen($r, $preis);
    }
    $msg = $gesp . ' Kundenpreis(e) gespeichert' . ($mengeGes ? ', ' . $mengeGes . ' Menge(n) je Packung geändert' : '') . ($aufKorr ? ', ' . $aufKorr . ' Auftrag/PA von Portionen auf g umgestellt' : '') . ($fuellen ? ', ' . $gefuellt . ' Auftrag/Aufträge mit Preis gefüllt' : '') . '.';
    $qs = http_build_query(array_filter(['ohne' => $_POST['f_ohne'] ?? '', 'kunde' => $_POST['f_kunde'] ?? '', 'produkt' => $_POST['f_produkt'] ?? '', 'q' => $_POST['f_q'] ?? '']));
    header('Location: ?p=kundenpreise' . ($qs !== '' ? '&' . $qs : '') . '&ok=' . rawurlencode($msg) . ($fehler ? '&err=' . rawurlencode(implode('; ', $fehler)) : '')); exit;
}

// --- Anzeige ------------------------------------------------------------------------------------------------
$zeilen = kp_zeilen();
$fOhne = !empty($_GET['ohne']); $fKunde = (int)($_GET['kunde'] ?? 0); $fProd = (int)($_GET['produkt'] ?? 0); $fQ = trim((string)($_GET['q'] ?? ''));
$kundenOpt = []; $prodOpt = [];
foreach ($zeilen as $r) { $kundenOpt[$r['kid']] = $r['firma']; $prodOpt[$r['pid']] = $r['produkt']; }
asort($kundenOpt, SORT_NATURAL | SORT_FLAG_CASE); asort($prodOpt, SORT_NATURAL | SORT_FLAG_CASE);
$ohnePreisN = count(array_filter($zeilen, fn($r) => $r['preis'] === null));
$sicht = array_filter($zeilen, function ($r) use ($fOhne, $fKunde, $fProd, $fQ) {
    if ($fOhne && $r['preis'] !== null) return false;
    if ($fKunde && $r['kid'] !== $fKunde) return false;
    if ($fProd && $r['pid'] !== $fProd) return false;
    if ($fQ !== '') {
        $hay = mb_strtolower($r['firma'] . ' ' . $r['produkt'] . ' ' . $r['produkt_nr'] . ' ' . $r['rezeptur'] . ' ' . $r['verpackung'] . ' '
             . implode(' ', array_map(fn($a) => $a['nummer'], $r['auftraege'])));
        if (mb_strpos($hay, mb_strtolower($fQ)) === false) return false;
    }
    return true;
});
$eur = fn(float $v, int $d = 2) => number_format($v, $d, ',', '.');
$preisTxt = fn(?float $v) => $v === null ? '' : rtrim(rtrim(number_format($v, 4, ',', ''), '0'), ',');

render_header('kundenpreise', 'Kundenpreise');
$info = 'Herstellungspreis je Packung eintragen (nur Herstellung/Kapseln, ohne Verpackung). Verpackung, Deckel und Etikett rechnet das System bei der Bestellmenge dazu (inkl. Kundenrabatt); ein vollbedruckter Beutel ohne Etikett. '
      . ($fuellen ? 'Beim Speichern bekommen unbepreiste Aufträge dieser Kombination den Preis (VK je Packung, Gesamt netto); Belege werden nicht angelegt oder geändert.'
                  : 'Unbepreiste Aufträge werden dabei noch nicht automatisch gefüllt (erst nach Freigabe).');
bx_head('Kundenpreise', count($zeilen) . ' Kombinationen · ' . $ohnePreisN . ' ohne Preis', bx_btn('Aufträge ohne Preis', '?p=auftrag_preise', 'ghost'));
if (isset($_GET['ok']))  echo '<div class="bx-panel badge-ok" style="padding:12px 16px">' . h((string)$_GET['ok']) . '</div>';
if (isset($_GET['err'])) echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:12px 16px">' . h((string)$_GET['err']) . '</div>';
?>

<p class="muted" style="margin:0 0 10px">Herstellungspreis je Packung eintragen, Verpackung und Etikett rechnet das System dazu. <?= bx_hint($info) ?></p>
<form class="bx-listbar" method="get" style="flex-wrap:wrap;gap:8px">
  <input type="hidden" name="p" value="kundenpreise">
  <label style="display:inline-flex;align-items:center;gap:6px"><input type="checkbox" name="ohne" value="1" <?= $fOhne ? 'checked' : '' ?> onchange="this.form.submit()"> nur ohne Preis</label>
  <select name="kunde" class="rscombo" data-ph="Kunde wählen …" style="min-width:220px" onchange="this.form.submit()"><option value="">Alle Kunden</option>
    <?php foreach ($kundenOpt as $id => $n): ?><option value="<?= (int)$id ?>" <?= $fKunde === (int)$id ? 'selected' : '' ?>><?= h($n) ?></option><?php endforeach; ?></select>
  <select name="produkt" class="rscombo" data-ph="Produkt wählen …" style="min-width:260px" onchange="this.form.submit()"><option value="">Alle Produkte</option>
    <?php foreach ($prodOpt as $id => $n): ?><option value="<?= (int)$id ?>" <?= $fProd === (int)$id ? 'selected' : '' ?>><?= h($n) ?></option><?php endforeach; ?></select>
  <input class="bx-search" type="text" name="q" value="<?= h($fQ) ?>" placeholder="Suchen: Kunde, Produkt, Rezeptur, Auftrag …">
  <button class="btn btn-ghost btn-sm" type="submit">Suchen</button>
  <?php if ($fOhne || $fKunde || $fProd || $fQ !== ''): ?><a class="btn btn-ghost btn-sm" href="?p=kundenpreise">zurücksetzen</a><?php endif; ?>
</form>

<form method="post" id="kpform">
  <input type="hidden" name="aktion" value="speichern">
  <button type="submit" tabindex="-1" aria-hidden="true" style="position:absolute;left:-9999px;width:1px;height:1px">Alle speichern</button><?php // Enter = alle speichern, nicht die erste Zeile ?>
  <input type="hidden" name="f_ohne" value="<?= $fOhne ? '1' : '' ?>"><input type="hidden" name="f_kunde" value="<?= $fKunde ?: '' ?>">
  <input type="hidden" name="f_produkt" value="<?= $fProd ?: '' ?>"><input type="hidden" name="f_q" value="<?= h($fQ) ?>">
  <style>.kp-tab td{font-size:14px}.kp-tab .kp-txt{white-space:normal;max-width:210px}.kp-tab .bx-num{white-space:nowrap}</style>
  <div class="bx-tablewrap"><table class="bx-table kp-tab">
    <thead><tr>
      <th>Kunde</th><th>Produkt</th><th class="bx-num">Menge je Pkg</th><th>Verpackung</th><th>Bestellmenge (Staffel)</th>
      <th class="bx-num">Herstellung je Pkg (€)</th><th class="bx-num">Verp. + Etikett je Pkg</th><th class="bx-num">VK je Pkg</th><th class="bx-num">Gesamt netto</th><th></th>
    </tr></thead>
    <tbody>
    <?php if (!$sicht): ?><tr><td colspan="10" class="muted">Keine Einträge.</td></tr><?php endif; ?>
    <?php foreach ($sicht as $k => $r):
        $verp = $r['verp_cent'] / 100; $vk = $r['preis'] !== null ? (round($r['preis'] * 100) + $r['verp_cent']) / 100 : null; ?>
      <tr class="kp-row" data-verp="<?= (int)$r['verp_cent'] ?>" data-menge="<?= (int)$r['menge'] ?>">
        <td class="kp-txt"><?= kunde_link($r['kid'], $r['firma']) ?></td>
        <td class="kp-txt"><a href="?p=produkt&id=<?= $r['pid'] ?>"><?= h($r['rezeptur'] !== '' ? $r['rezeptur'] : $r['produkt']) ?></a>
            <div class="muted" style="font-size:12px"><?= h($r['produkt_nr']) ?><?= $r['preis'] === null ? ' · ' . bx_badge('ohne Preis', 'warn') : '' ?></div></td>
        <td class="bx-num" style="white-space:nowrap"><?php if ($r['kp_id']): $einhS = form_groessen_einheit($r['form']) ?: form_plural($r['form']); ?>
            <input type="text" inputmode="numeric" data-tausender name="stk[<?= h($k) ?>]" value="<?= number_format($r['stk'], 0, ',', '.') ?>" class="kp-stk" style="width:72px;text-align:right" title="Menge je Packung<?= form_groessen_einheit($r['form']) === 'g' ? ' in g (Füllmenge, nicht Portionen)' : '' ?>"> <?= h($einhS) ?>
          <?php elseif (form_ist_tagesdosis($r['form'])): // Pulver-Auftrag: Portionszahl (v3) hier auf g korrigierbar ?>
            <input type="text" inputmode="numeric" data-tausender name="stk[<?= h($k) ?>]" value="<?= number_format($r['stk'], 0, ',', '.') ?>" class="kp-stk" style="width:72px;text-align:right" title="Steht hier eine Portionszahl statt g (Altdaten), die Füllmenge in g eintragen – Auftrag und PA werden mit umgestellt."> g
            <div class="muted" style="font-size:12px">aus Auftrag</div>
          <?php else: ?><?= h(form_groessen_label($r['form'], (float)$r['stk'])) ?><div class="muted" style="font-size:12px">aus Auftrag</div><?php endif; ?></td>
        <td class="kp-txt"><?= $r['verpackung'] !== '' ? h($r['verpackung']) : '<span class="muted">–</span>' ?><?= $r['vollbedruckt'] ? '<div class="muted" style="font-size:12px">vollbedruckt</div>' : '' ?></td>
        <td class="kp-txt" style="font-size:13px"><?= $r['bm'] ? h(number_format($r['bm'], 0, ',', '.')) . ' Stk' : 'alle Mengen' . ($r['mengen'] ? '<div class="muted" style="font-size:12px">Aufträge: ' . h(implode(', ', array_map(fn($m) => number_format($m, 0, ',', '.'), $r['mengen']))) . ' Stk</div>' : '') ?>
            <?php // Bepreiste Aufträge dieser Staffel als Info (VK je Pkg inkl. Verpackung) – hilft beim Eintragen des Herstellungspreises.
                  foreach ($r['auftraege'] as $a): if ((float)($a['gesamt_netto'] ?? 0) <= 0 || (int)$a['menge'] <= 0) continue; ?>
              <div class="muted" style="font-size:12px"><a href="?p=auftrag&id=<?= (int)$a['id'] ?>"><?= h($a['nummer']) ?></a>: <?= $eur((float)$a['gesamt_netto'] / (int)$a['menge']) ?> € je Pkg</div>
            <?php endforeach; ?>
            <?php if ($r['ohne_preis_auftraege']): ?><div style="font-size:12px;color:#8f231b"><?= count($r['ohne_preis_auftraege']) ?> Auftrag/Aufträge ohne Preis: <?php foreach ($r['ohne_preis_auftraege'] as $i => $a): ?><?= $i ? ', ' : '' ?><a href="?p=auftrag&id=<?= (int)$a['id'] ?>"><?= h($a['nummer']) ?></a><?php endforeach; ?></div><?php endif; ?></td>
        <td class="bx-num"><input type="text" inputmode="decimal" name="preis[<?= h($k) ?>]" value="<?= h($preisTxt($r['preis'])) ?>" placeholder="0,00" class="kp-preis" style="width:96px;text-align:right"></td>
        <td class="bx-num"><?= $eur($verp) ?> €<div class="muted" style="font-size:12px">bei <?= number_format($r['menge'], 0, ',', '.') ?> Stk</div>
            <?php if ($r['etikett_fehlt']): ?><div style="font-size:12px;color:#8f231b" title="Am Behälter ist kein Etikett-Endformat bzw. kein passendes Maß-Etikett mit Preis hinterlegt – das Etikett ist in diesem Preis NICHT enthalten.">ohne Etikett-Preis</div><?php endif; ?></td>
        <td class="bx-num kp-vk"><?= $vk !== null ? $eur($vk) . ' €' : '<span class="muted">–</span>' ?></td>
        <td class="bx-num kp-ges"><?= $vk !== null ? $eur($vk * $r['menge']) . ' €' : '<span class="muted">–</span>' ?></td>
        <td><button class="btn btn-ghost btn-sm" type="submit" name="nur" value="<?= h($k) ?>">Speichern</button></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php if ($sicht): ?><div style="margin-top:14px"><button class="btn btn-primary" type="submit">Alle speichern</button></div><?php endif; ?>
</form>
<script>
// Live-Rechnung beim Tippen (maßgeblich ist der Server beim Speichern). Deutsch: „4,50" = 4,5; „1.234,5" = 1234,5; „4.50" = 4,5.
(function(){
  function zahl(s){ s=(s||'').replace(/[\s ']/g,''); if(s==='') return NaN;
    if(s.indexOf(',')>=0){ s=s.replace(/\./g,'').replace(',','.'); } return parseFloat(s); }
  function eur(v){ return v.toLocaleString('de-DE',{minimumFractionDigits:2,maximumFractionDigits:2})+' €'; }
  document.querySelectorAll('input[data-tausender]').forEach(function(el){ var g=function(){ var d=(el.value||'').replace(/\D/g,''); el.value=d?d.replace(/\B(?=(\d{3})+(?!\d))/g,'.'):''; }; g(); el.addEventListener('blur',g); });
  document.querySelectorAll('.kp-row').forEach(function(tr){
    var inp=tr.querySelector('.kp-preis'); if(!inp) return;
    inp.addEventListener('input',function(){
      var h=zahl(inp.value), verp=parseInt(tr.dataset.verp,10)||0, m=parseInt(tr.dataset.menge,10)||0;
      var vkEl=tr.querySelector('.kp-vk'), gEl=tr.querySelector('.kp-ges');
      if(isNaN(h)||h<=0){ vkEl.innerHTML='<span class="muted">–</span>'; gEl.innerHTML='<span class="muted">–</span>'; return; }
      var cent=Math.round(h*100)+verp; vkEl.textContent=eur(cent/100); gEl.textContent=eur(cent*m/100);
    });
  });
})();
</script>
<?php render_footer();

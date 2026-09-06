<?php
// Novel-Food-Schnellsuche: einen (oder mehrere) Stoffnamen eintippen und sofort sehen,
// ob es sich um Novel Food handelt. Sucht im importierten EU-Novel-Food-Katalog
// (novelfood_katalog) und bewertet mit derselben Ampel wie die Produktprüfung.
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

// Statuscode -> [Ampel, Klartext]. Gleiche Semantik wie produkt_novelfood_pruefen().
function nf_ampel(string $code): array {
    switch ($code) {
        case 'NOT_YET_AUTHORISED_NOVEL_FOOD':     return ['rot',   'Novel Food – nicht ohne Zulassung verkehrsfähig'];
        case 'AUTHORISED_NOVEL_FOOD':             return ['gelb',  'zugelassenes Novel Food – nur unter den zugelassenen Bedingungen'];
        case 'SUBJECT_TO_A_CONSULTATION_REQUEST': return ['gelb',  'Konsultationsverfahren – Status prüfen'];
        case 'NOT_NOVEL_IN_FOOD':                 return ['gruen', 'kein Novel Food in Lebensmitteln'];
        case 'NOT_NOVEL_IN_FOOD_SUPPLEMENTS':     return ['gruen', 'kein Novel Food in Nahrungsergänzung'];
        default:                                  return ['grau',  'im Katalog ohne eindeutigen Status – bitte prüfen'];
    }
}
function nf_farbe(string $ampel): string {
    return ['rot'=>'var(--err)','gelb'=>'var(--warn)','gruen'=>'var(--gruen)','grau'=>'var(--muted-fg, #888)'][$ampel] ?? '#888';
}

$norm = function ($s) {
    $s = mb_strtolower(trim((string)$s));
    $s = preg_replace('/\([^)]*\)/u', ' ', $s);          // Klammerzusätze weg
    $s = preg_replace('/[^a-z0-9äöüß ]+/u', ' ', $s);
    return trim(preg_replace('/\s+/', ' ', $s));
};

$eingabe = trim((string)($_GET['q'] ?? ''));
$zeilen  = array_values(array_filter(array_map('trim', preg_split('/[\r\n]+/', $eingabe)), fn($z) => $z !== ''));
$anzKatalog = (int) scalar("SELECT COUNT(*) FROM novelfood_katalog");

// Zu generische Einzelwörter (Mineralien/Vitamine/Allerweltsbegriffe): wenn die GANZE Eingabe
// nur so ein Wort ist, zählt ein reiner Teilwort-Treffer NICHT als direkter Treffer – sonst
// würde „Magnesium" über „Magnesium-L-Threonat" fälschlich als Novel Food gewertet. Gleiche
// Liste wie in produkt_novelfood_pruefen().
$stop = ['magnesium'=>1,'calcium'=>1,'kalzium'=>1,'natrium'=>1,'sodium'=>1,'kalium'=>1,'potassium'=>1,'zink'=>1,'zinc'=>1,'eisen'=>1,'iron'=>1,'kupfer'=>1,'copper'=>1,'mangan'=>1,'selen'=>1,'selenium'=>1,'jod'=>1,'iodine'=>1,'chrom'=>1,'chromium'=>1,'vitamin'=>1,'wasser'=>1,'water'=>1,'salz'=>1,'salts'=>1,'salt'=>1,'extrakt'=>1,'extract'=>1,'pulver'=>1,'powder'=>1,'saeure'=>1,'acid'=>1];

// Katalog einmal laden und in Suchbegriffe zerlegen (Name + Trivial + Synonyme).
// Hier BEWUSST ohne die Stopwort-Liste der Auto-Prüfung: wer „Magnesium" eintippt, will die
// Magnesium-Einträge sehen. Manuelle Suche = zeig alles, was passt.
$begriffe = [];   // normierter Begriff => Katalogzeile
if ($zeilen) {
    foreach (all("SELECT name, trivial, syn, status, status_code, teil, beschreibung_de FROM novelfood_katalog") as $c) {
        $terms = [$c['name']];
        foreach (['trivial', 'syn'] as $f) foreach (preg_split('/[,;]/', (string)$c[$f]) as $t) {
            $t = preg_replace('/\([^)]*\)/u', '', (string)$t);
            if (trim($t) !== '') $terms[] = $t;
        }
        foreach ($terms as $t) { $nt = $norm($t); if (mb_strlen($nt) >= 3) { $begriffe[$nt][] = $c; } }
    }
}

// Je Eingabezeile die Treffer + Gesamtbewertung bestimmen.
// „stark" = die Eingabe entspricht dem Katalogbegriff (identisch) oder der Katalogbegriff
// steht als ganzes Wort in der Eingabe. „schwach" = die Eingabe ist nur ein Teilwort eines
// längeren Katalognamens (z. B. „Magnesium" in „Magnesium-L-Threonat"). Nur STARKE Treffer
// bestimmen die Gesamt-Ampel – sonst würde die Suche nach „Magnesium" fälschlich rot.
$ergebnisse = [];
foreach ($zeilen as $roh) {
    $nz = $norm($roh);
    $hits = [];   // name => ['c'=>row, 'stark'=>bool]
    if ($nz !== '' && mb_strlen($nz) >= 3) {
        $generisch = isset($stop[$nz]);   // Eingabe ist EIN generisches Wort (z. B. „magnesium")
        foreach ($begriffe as $bt => $rows) {
            // stark: identisch oder der Katalogbegriff steht ganz in der Eingabe; ODER die Eingabe
            // steht als ganzes Wort im (längeren) Katalogbegriff – Letzteres aber nur, wenn die
            // Eingabe kein einzelnes Allerweltswort ist.
            $stark = ($nz === $bt)
                  || (bool) preg_match('/\b' . preg_quote($bt, '/') . '\b/u', $nz)
                  || (!$generisch && (bool) preg_match('/\b' . preg_quote($nz, '/') . '\b/u', $bt));
            $schwach = !$stark && (bool) preg_match('/\b' . preg_quote($nz, '/') . '\b/u', $bt);
            if (!$stark && !$schwach) continue;
            foreach ($rows as $c) {
                $nm = (string)$c['name'];
                if (!isset($hits[$nm])) $hits[$nm] = ['c' => $c, 'stark' => $stark];
                elseif ($stark) $hits[$nm]['stark'] = true;
            }
        }
    }
    // in stark/schwach aufteilen, jeweils mit Ampel
    $stark = []; $schwach = [];
    foreach ($hits as $hnm => $hv) {
        [$amp, $txt] = nf_ampel((string)$hv['c']['status_code']);
        $eintrag = ['c' => $hv['c'], 'ampel' => $amp, 'ampeltext' => $txt];
        if ($hv['stark']) $stark[] = $eintrag; else $schwach[] = $eintrag;
    }
    $rang = ['rot'=>3,'gelb'=>2,'grau'=>2,'gruen'=>1];
    $sort = function (&$arr) use ($rang) { usort($arr, fn($a, $b) => ($rang[$b['ampel']] <=> $rang[$a['ampel']]) ?: strcmp($a['c']['name'], $b['c']['name'])); };
    $sort($stark); $sort($schwach);

    // Gesamt-Ampel nur aus starken Treffern.
    $gesamt = 'gruen'; $gesamttext = 'kein Novel Food – konform';
    if (!$stark) {
        if ($schwach) { $gesamt = 'grau'; $gesamttext = 'kein direkter Treffer – ähnliche Einträge unten prüfen'; }
        else { $gesamt = 'keine'; $gesamttext = 'nicht im Katalog gefunden'; }
    } else {
        $codes = array_map(fn($t) => (string)$t['c']['status_code'], $stark);
        if (in_array('NOT_YET_AUTHORISED_NOVEL_FOOD', $codes, true)) { $gesamt = 'rot'; $gesamttext = 'enthält Novel Food – Zulassung nötig'; }
        elseif (array_intersect($codes, ['AUTHORISED_NOVEL_FOOD','SUBJECT_TO_A_CONSULTATION_REQUEST']) || in_array('', $codes, true)) { $gesamt = 'gelb'; $gesamttext = 'prüfen – zugelassenes/offenes Novel Food'; }
    }
    $ergebnisse[] = ['begriff' => $roh, 'gesamt' => $gesamt, 'gesamttext' => $gesamttext, 'treffer' => $stark, 'aehnlich' => $schwach];
}

render_header('novelfood', 'Novel Food');
bx_head('Novel Food – Schnellsuche', $anzKatalog . ' Einträge im EU-Katalog', bx_btn('Zurück zum Dashboard', '?p=dashboard', 'ghost'));

if ($anzKatalog === 0) {
    echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:14px 16px">Der Novel-Food-Katalog ist leer. Zuerst importieren: <code>php tools/novelfood_import.php "PFAD/novelfood.json"</code>.</div>';
    render_footer();
    return;
}
?>
<form method="get" class="bx-form">
  <input type="hidden" name="p" value="novelfood">
  <div class="bx-panel">
    <div class="bx-field">
      <label>Stoff eingeben <?= bx_hint('Substanz- oder Zutatenname eintippen. Mehrere gehen: einen pro Zeile (z. B. eine ganze Zutatenliste einfügen). Gesucht wird im EU-Novel-Food-Katalog nach Name, Trivialname und Synonymen.') ?></label>
      <textarea name="q" rows="3" autofocus placeholder="z. B. Spermidin&#10;Ashwagandha&#10;Nicotinamid-Mononukleotid"><?= h($eingabe) ?></textarea>
    </div>
    <div class="bx-row" style="margin-top:var(--sp-3)">
      <button class="btn btn-primary" type="submit" data-busy="Suche läuft…">Prüfen</button>
      <?php if ($eingabe !== ''): ?><a class="btn btn-ghost" href="?p=novelfood">Leeren</a><?php endif; ?>
    </div>
  </div>
</form>

<?php if ($zeilen): ?>
  <?php foreach ($ergebnisse as $e): $amp = $e['gesamt']; $farbe = $amp === 'keine' ? '#888' : nf_farbe($amp); ?>
    <div class="bx-panel" style="border-left:4px solid <?= $farbe ?>">
      <div class="bx-row" style="justify-content:space-between;align-items:baseline;gap:12px;flex-wrap:wrap">
        <div style="font-size:17px"><?= h($e['begriff']) ?></div>
        <div style="color:<?= $farbe ?>;font-weight:600"><?= h($e['gesamttext']) ?></div>
      </div>
      <?php if (!$e['treffer'] && !$e['aehnlich']): ?>
        <p class="muted" style="margin:8px 0 0">Kein Eintrag im Katalog gefunden. Das ist meist unkritisch (etablierte Lebensmittelzutat), ersetzt aber keine eigene Prüfung – Schreibweise/Synonym ggf. anders eintippen.</p>
      <?php elseif ($e['treffer']): ?>
        <div class="bx-tablewrap" style="margin-top:12px"><table class="bx-table">
          <thead><tr><th>Katalog-Eintrag</th><th>Pflanzenteil / Form</th><th>Status</th></tr></thead>
          <tbody>
          <?php foreach ($e['treffer'] as $t): $c = $t['c']; $fb = nf_farbe($t['ampel']); ?>
            <tr>
              <td>
                <?= h($c['name']) ?>
                <?php $extra = trim((string)$c['beschreibung_de']); if ($extra !== ''): ?>
                  <div class="muted" style="font-size:12px;margin-top:2px"><?= h(mb_strlen($extra) > 200 ? mb_substr($extra, 0, 200) . '…' : $extra) ?></div>
                <?php endif; ?>
              </td>
              <td class="muted" style="font-size:13px"><?= $c['teil'] ? h($c['teil']) : '–' ?></td>
              <td>
                <span style="display:inline-flex;align-items:center;gap:6px">
                  <span style="width:9px;height:9px;border-radius:50%;background:<?= $fb ?>;flex:0 0 auto"></span>
                  <span style="color:<?= $fb ?>"><?= h($t['ampeltext']) ?></span>
                </span>
                <?php if ($c['status'] && $norm($c['status']) !== $norm($t['ampeltext'])): ?>
                  <div class="muted" style="font-size:11px;margin-top:2px"><?= h($c['status']) ?></div>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
      <?php endif; ?>

      <?php if ($e['aehnlich']): ?>
        <details style="margin-top:<?= $e['treffer'] ? '10px' : '8px' ?>">
          <summary class="muted" style="cursor:pointer;font-size:13px"><?= count($e['aehnlich']) ?> ähnliche(r) Katalog-Eintrag/-Einträge (Teilwort von „<?= h($e['begriff']) ?>") – nicht als direkter Treffer gewertet</summary>
          <div class="bx-tablewrap" style="margin-top:8px"><table class="bx-table">
            <tbody>
            <?php foreach ($e['aehnlich'] as $t): $c = $t['c']; $fb = nf_farbe($t['ampel']); ?>
              <tr>
                <td><?= h($c['name']) ?><?php if ($c['teil']): ?> <span class="muted" style="font-size:12px">· <?= h($c['teil']) ?></span><?php endif; ?></td>
                <td>
                  <span style="display:inline-flex;align-items:center;gap:6px">
                    <span style="width:9px;height:9px;border-radius:50%;background:<?= $fb ?>;flex:0 0 auto"></span>
                    <span style="color:<?= $fb ?>;font-size:13px"><?= h($t['ampeltext']) ?></span>
                  </span>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table></div>
        </details>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
  <p class="muted" style="font-size:12px">Quelle: EU-Novel-Food-Katalog (importierter Stand). Die Ampel ist eine Orientierung, keine Rechtsberatung – im Zweifel den Einzelfall prüfen. Rot = im Katalog als Novel Food ohne Zulassung; Gelb = zugelassenes oder offenes Novel Food (Bedingungen prüfen); Grün = kein Novel Food.</p>
<?php else: ?>
  <div class="bx-panel">
    <div style="font-weight:600;margin-bottom:6px">So funktioniert's</div>
    <p class="muted" style="margin:0;line-height:1.7">
      Stoffnamen oben eintippen und „Prüfen" klicken. Die Suche gleicht mit dem EU-Novel-Food-Katalog ab und zeigt sofort:
      <span style="color:var(--err)">rot</span> = Novel Food ohne Zulassung (nicht verkehrsfähig),
      <span style="color:var(--warn)">gelb</span> = zugelassenes/offenes Novel Food (Bedingungen prüfen),
      <span style="color:var(--gruen)">grün</span> = kein Novel Food. Mehrere Stoffe: einen pro Zeile.
    </p>
  </div>
<?php endif; ?>
<?php
render_footer();

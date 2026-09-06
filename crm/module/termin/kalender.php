<?php
// Kalender: Termine und Wiedervorlagen im Monat. Route: ?p=kalender
//
// Aufbau und Klassen wie der Produktions-Kalender des Dashboards - damit beide gleich aussehen und
// man sich nicht umgewoehnen muss.
//
// Zwei Sorten Eintraege, bewusst unterschieden:
//   Termin        - hat eine Uhrzeit, da muss man irgendwo sein.
//   Wiedervorlage - hat nur einen Tag, da muss man an jemanden denken.
require_once BX_ROOT . '/core/termin.php';
require_once BX_ROOT . '/core/kontakt.php';
require_once BX_ROOT . '/core/wartet.php';   // wartet_bezug_link(): wohin eine Wiedervorlage fuehrt

$monat = (string)($_GET['monat'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $monat)) $monat = date('Y-m');
$erster = $monat . '-01';
$ts     = strtotime($erster);
$tageImMonat    = (int) date('t', $ts);
$startWochentag = (int) date('N', $ts);        // 1 = Montag
$vor    = date('Y-m', strtotime($erster . ' -1 month'));
$zurueck= date('Y-m', strtotime($erster . ' +1 month'));
$heute  = in_tagen(0);                          // Berliner Datum, nicht UTC
$letzter = date('Y-m-t', $ts);

$MON = ['01'=>'Januar','02'=>'Februar','03'=>'März','04'=>'April','05'=>'Mai','06'=>'Juni',
        '07'=>'Juli','08'=>'August','09'=>'September','10'=>'Oktober','11'=>'November','12'=>'Dezember'];
$titel = $MON[substr($monat, 5, 2)] . ' ' . substr($monat, 0, 4);

// --- Eintraege des Monats einsammeln, je Tag ---------------------------------------------------
$proTag = [];

// Termine: gespeichert in UTC, angezeigt am Berliner Tag. Deshalb grosszuegig laden und dann
// nach dem umgerechneten Datum einsortieren - sonst faellt ein Termin um 00:30 auf den Vortag.
foreach (all("SELECT t.*, k.name AS kontakt_name, k.firma AS kontakt_firma
              FROM crm_termin t
              LEFT JOIN crm_kontakt k ON (t.bezug_typ='kontakt' AND k.id = t.bezug_id)
              WHERE t.start_at BETWEEN ? AND ?
              ORDER BY t.start_at", [$erster . ' 00:00:00', $letzter . ' 23:59:59']) as $t) {
    $tag = (int) fmt_zeit((string)$t['start_at'], 'j');
    if (fmt_zeit((string)$t['start_at'], 'Y-m') !== $monat) continue;
    $wer = trim((string)($t['kontakt_firma'] ?: $t['kontakt_name'] ?? ''));
    $proTag[$tag][] = [
        'art'   => 'termin',
        'zeit'  => fmt_zeit((string)$t['start_at'], 'H:i'),
        'text'  => (string)$t['titel'],
        'unter' => trim(((string)($t['ort'] ?? '')) . ($wer !== '' ? ' · ' . $wer : ''), ' ·'),
        'link'  => ($t['bezug_typ'] === 'kontakt' && $t['bezug_id']) ? '?p=kontakt&id=' . (int)$t['bezug_id'] : '?p=termine',
        'erledigt' => (int)$t['erledigt'] === 1,
    ];
}

// Wiedervorlagen: haben nur ein Datum.
foreach (all("SELECT w.*, k.name AS kontakt_name, k.firma AS kontakt_firma
              FROM crm_wiedervorlage w
              LEFT JOIN crm_kontakt k ON (w.bezug_typ='kontakt' AND k.id = w.bezug_id)
              WHERE w.faellig BETWEEN ? AND ?
              ORDER BY w.faellig", [$erster, $letzter]) as $w) {
    $tag = (int) date('j', strtotime((string)$w['faellig']));
    $proTag[$tag][] = [
        'art'   => 'wiedervorlage',
        'zeit'  => '',
        'text'  => (string)$w['titel'],
        'unter' => trim((string)($w['notiz'] ?? '')),
        'link'  => wartet_bezug_link((string)($w['bezug_typ'] ?? ''), (int)($w['bezug_id'] ?? 0)) ?: '?p=wartet',
        'erledigt' => $w['erledigt_am'] !== null,
    ];
}

kopf('Kalender', 'kalender');
seitenkopf('Kalender', 'Termine und Wiedervorlagen im Überblick.',
    '<a class="btn btn-ghost" href="?p=termine">Termin eintragen</a>');
?>

<div class="bx-row" style="justify-content:space-between;align-items:center;margin-bottom:12px">
  <a class="btn btn-ghost btn-sm" href="?p=kalender&monat=<?= h($vor) ?>">&#8592; <?= h($MON[substr($vor, 5, 2)]) ?></a>
  <h2 style="margin:0"><?= h($titel) ?></h2>
  <a class="btn btn-ghost btn-sm" href="?p=kalender&monat=<?= h($zurueck) ?>"><?= h($MON[substr($zurueck, 5, 2)]) ?> &#8594;</a>
</div>

<div class="karte"><div class="rumpf">
  <div class="bx-cal-head">
    <?php foreach (['Mo','Di','Mi','Do','Fr','Sa','So'] as $wt): ?><div><?= $wt ?></div><?php endforeach; ?>
  </div>
  <div class="bx-cal">
    <?php for ($i = 1; $i < $startWochentag; $i++): ?><div class="bx-cal-cell bx-cal-empty"></div><?php endfor; ?>
    <?php for ($tag = 1; $tag <= $tageImMonat; $tag++):
      $datum = $monat . '-' . str_pad((string)$tag, 2, '0', STR_PAD_LEFT);
      $istHeute = $datum === $heute; ?>
      <div class="bx-cal-cell<?= $istHeute ? ' bx-cal-today' : '' ?>">
        <div class="bx-cal-day"><?= $tag ?></div>
        <?php
          // Mehr als vier Eintraege sprengen die Zelle - der Rest steht als Zahl darunter.
          // Das passiert schnell: die automatischen Wiedervorlagen fuer gesendete Angebote
          // werden alle am selben Tag faellig.
          $alle = $proTag[$tag] ?? [];
          $zeigen = array_slice($alle, 0, 4);
          $rest = count($alle) - count($zeigen);
        ?>
        <?php foreach ($zeigen as $e):
          // Punktfarbe: offene Wiedervorlage in der Vergangenheit faellt auf, Termine sind gruen.
          $prio = $e['erledigt'] ? 3 : ($e['art'] === 'termin' ? 2 : ($datum < $heute ? 1 : 3)); ?>
          <a class="bx-cal-item" href="<?= h($e['link']) ?>"
             title="<?= h(trim(($e['zeit'] !== '' ? $e['zeit'] . ' · ' : '') . $e['text'] . ($e['unter'] !== '' ? ' · ' . $e['unter'] : ''))) ?>"
             style="<?= $e['erledigt'] ? 'opacity:.55' : '' ?>">
            <span class="bx-cal-prio bx-cal-prio-<?= $prio ?>"></span><?= $e['zeit'] !== '' ? h($e['zeit']) . ' ' : '' ?><?= h($e['text']) ?>
          </a>
        <?php endforeach; ?>
        <?php if ($rest > 0): ?>
          <a class="bx-cal-item" href="?p=wartet" style="justify-content:center;color:var(--muted)"
             title="<?= $rest ?> weitere an diesem Tag">+<?= $rest ?> weitere</a>
        <?php endif; ?>
      </div>
    <?php endfor; ?>
  </div>

  <p class="muted" style="margin:14px 0 0;font-size:var(--fs-sm)">
    <span class="bx-cal-prio bx-cal-prio-2" style="display:inline-block;vertical-align:middle"></span> Termin
    &nbsp;&nbsp;<span class="bx-cal-prio bx-cal-prio-3" style="display:inline-block;vertical-align:middle"></span> Wiedervorlage
    &nbsp;&nbsp;<span class="bx-cal-prio bx-cal-prio-1" style="display:inline-block;vertical-align:middle"></span> überfällig
  </p>
</div></div>
<?php fuss('kalender');

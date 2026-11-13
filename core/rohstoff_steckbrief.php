<?php
// KI-Steckbrief am Rohstoff (Nico 11.11.2026) - NUR intern.
// Ausführliche, sachliche Übersicht für das Team: was ist das, wofür, übliche Dosis, Besonderheiten, Regulatorik.
// Unterschied zur „KI-Info" (item.ki_info): die ist kurz und wird dem Kunden im Portal gezeigt. Der Steckbrief
// erscheint NIE im Kunden- oder Lieferantenportal - nur auf der Rohstoffseite und in den Rohstoff-Anfragen im Dashboard.
// Gespeichert am Rohstoff (item.steckbrief + Datum + wer), damit niemand erneut drücken muss. Per Stift bearbeitbar.
// KI-Anbindung: ki_frage() aus core/ki.php (dieselbe wie „KI liest aus").
require_once __DIR__ . '/schema.php';

// Spalten anlegen (best effort, die Spalten stehen auch in core/schema.php).
function steckbrief_schema(): void {
    static $fertig = false; if ($fertig) return; $fertig = true;
    try {
        ensure_column('item', 'steckbrief', "TEXT NULL");
        ensure_column('item', 'steckbrief_am', "DATETIME NULL");          // UTC, wann von der KI erzeugt
        ensure_column('item', 'steckbrief_von', "VARCHAR(120) NULL");     // wer erzeugt hat
        ensure_column('item', 'steckbrief_bearb_am', "DATETIME NULL");    // UTC, letzte eigene Änderung
        ensure_column('item', 'steckbrief_bearb_von', "VARCHAR(120) NULL");
    } catch (Throwable $e) {}
}

// Name der angemeldeten Person (für „erstellt von").
function steckbrief_wer(): string {
    $u = function_exists('current_user') ? current_user() : null;
    return trim((string)($u['name'] ?? '')) ?: 'Team';
}

// Kontext für die KI aus den Stammdaten: Name, Synonyme, Wirkstoffe/Gehalt, Spezifikation, Novel-Food-Status,
// hinterlegte EU-Health-Claims (nur wenn in der Datenbank vorhanden).
function steckbrief_kontext(int $item_id): ?string {
    $it = one("SELECT * FROM item WHERE id=?", [$item_id]);
    if (!$it) return null;
    $z = [];
    $z[] = 'Rohstoff: ' . (string)$it['name'];
    foreach (['name_en' => 'Englisch', 'name_lat' => 'Lateinisch', 'synonym' => 'Synonym', 'synonyme' => 'Synonyme', 'cas' => 'CAS',
              'beschaffenheit' => 'Beschaffenheit', 'dev' => 'Verhältnis (DEV)', 'form' => 'Form', 'bot_quelle' => 'Pflanzenteil/Quelle',
              'herkunft' => 'Herkunft', 'herkunftsland' => 'Herkunftsland', 'zusaetze' => 'Zusätze/Trägerstoffe', 'allergene' => 'Allergene',
              'haltbarkeit' => 'Haltbarkeit', 'lagerbedingungen' => 'Lagerung'] as $f => $lbl) {
        $w = trim((string)($it[$f] ?? ''));
        if ($w !== '') $z[] = $lbl . ': ' . $w;
    }
    foreach (['vegan' => 'vegan', 'gvo_frei' => 'GVO-frei', 'bestrahlt' => 'bestrahlt'] as $f => $lbl)
        if (($it[$f] ?? null) !== null && $it[$f] !== '') $z[] = $lbl . ': ' . ((int)$it[$f] ? 'ja' : 'nein');
    $wirk = [];
    try {
        foreach (all("SELECT n.id, n.name, n.einheit, iw.gehalt_wert, iw.gehalt_prozent FROM item_wirkstoff iw
                      JOIN naehrstoff n ON n.id=iw.naehrstoff_id WHERE iw.item_id=? ORDER BY iw.sort", [$item_id]) as $w) {
            $g = $w['gehalt_prozent'] !== null && $w['gehalt_prozent'] !== '' ? rtrim(rtrim(number_format((float)$w['gehalt_prozent'], 3, '.', ''), '0'), '.') . ' %'
               : ($w['gehalt_wert'] !== null && $w['gehalt_wert'] !== '' ? rtrim(rtrim(number_format((float)$w['gehalt_wert'], 3, '.', ''), '0'), '.') . ' ' . (string)$w['einheit'] : '');
            $wirk[(int)$w['id']] = trim((string)$w['name'] . ($g !== '' ? ' (Gehalt ' . $g . ')' : ''));
        }
    } catch (Throwable $e) {}
    if ($wirk) $z[] = 'Wirkstoffe laut Spezifikation: ' . implode(', ', $wirk);
    $nf = trim((string)($it['novelfood_status'] ?? ''));
    if ($nf !== '') $z[] = 'Novel-Food-Status laut unserem Abgleich mit dem EU-Katalog: ' . $nf;
    // Zugelassene Health Claims aus unserer EU-Liste (health_claim), falls zu den Wirkstoffen vorhanden.
    $claims = [];
    if ($wirk) {
        try {
            $ph = implode(',', array_fill(0, count($wirk), '?'));
            foreach (all("SELECT stoff, claim, bedingung FROM health_claim WHERE aktiv=1 AND naehrstoff_id IN ($ph) ORDER BY sort, id LIMIT 30", array_keys($wirk)) as $c)
                $claims[] = trim((string)$c['stoff'] . ': ' . (string)$c['claim'] . ((string)$c['bedingung'] !== '' ? ' (Bedingung: ' . (string)$c['bedingung'] . ')' : ''));
        } catch (Throwable $e) {}
    }
    if ($claims) $z[] = "Zugelassene EU-Health-Claims aus unserer Datenbank:\n- " . implode("\n- ", $claims);
    else         $z[] = 'In unserer Datenbank sind zu diesem Rohstoff keine EU-Health-Claims hinterlegt.';
    return implode("\n", $z);
}

// Steckbrief von der KI erzeugen und speichern. Rückgabe ['ok'=>true,'text'=>…] oder ['ok'=>false,'fehler'=>…].
function rohstoff_steckbrief_erzeugen(int $item_id): array {
    steckbrief_schema();
    require_once __DIR__ . '/ki.php';
    if (!ki_bereit()) return ['ok' => false, 'fehler' => 'Die KI ist nicht eingerichtet (ANTHROPIC_API_KEY in secrets.php).'];
    $ctx = steckbrief_kontext($item_id);
    if ($ctx === null) return ['ok' => false, 'fehler' => 'Rohstoff nicht gefunden.'];
    $system = 'Du bist Fachreferent für Rohstoffe in Nahrungsergänzungsmitteln bei einem deutschen Lohnhersteller. '
        . 'Schreibe einen internen Rohstoff-Steckbrief auf DEUTSCH für das Team (Einkauf, Vertrieb, Rezeptur). Kurz, sachlich, ohne Werbung. '
        . "Gliedere genau in diese Abschnitte, jeder beginnt mit seiner Überschrift in einer eigenen Zeile, darunter kurze Zeilen mit Bindestrich:\n"
        . "Was ist das\nEinsatz in Nahrungsergänzungsmitteln\nDosierung und Darreichung\nBesonderheiten in der Verarbeitung\nRegulatorische Hinweise\n\n"
        . 'Inhalt: Herkunft, Form, übliche Gehalte bzw. Standardisierung; typische Einsatzgebiete und Kombinationen; übliche Tagesdosis in NEM '
        . '(als Marktüblichkeit, keine Empfehlung), geeignete Darreichung (Kapsel, Tablette, Pulver); Besonderheiten wie Geschmack, Geruch, '
        . 'Fließfähigkeit, Schüttdichte, Hygroskopie, Stabilität; regulatorisch: Novel-Food-Status, Höchstmengen bzw. Empfehlungen (z. B. BfR), '
        . 'nötige Warnhinweise. EU-Health-Claims nur nennen, wenn es für diesen Stoff einen zugelassenen Claim gibt; sonst schreiben: '
        . '„Kein zugelassener Health Claim bekannt.“ Sind dir Angaben unsicher, schreibe das ausdrücklich dazu (z. B. „bitte prüfen“). '
        . 'WICHTIG: keine Werbe- und keine Heilversprechen, keine krankheitsbezogenen Aussagen. Kein Markdown (keine Sternchen, keine Rauten), '
        . 'normale Bindestriche, keine Emojis. Höchstens etwa 350 Wörter.';
    $r = ki_frage("Stammdaten aus unserem System:\n" . $ctx, ['system' => $system, 'aufwand' => 'medium', 'max_tokens' => 2500,
                  'timeout' => 120, 'budget' => 180, 'zweck' => 'rohstoff-steckbrief']);
    if (!($r['ok'] ?? false)) return ['ok' => false, 'fehler' => $r['fehler'] ?? 'Die KI konnte nicht antworten.'];
    $text = steckbrief_saeubern((string)($r['text'] ?? ''));
    if ($text === '') return ['ok' => false, 'fehler' => 'Die KI hat keinen Text geliefert.'];
    $wer = steckbrief_wer();
    q("UPDATE item SET steckbrief=?, steckbrief_am=?, steckbrief_von=?, steckbrief_bearb_am=NULL, steckbrief_bearb_von=NULL WHERE id=?",
      [$text, gmdate('Y-m-d H:i:s'), $wer, $item_id]);
    if (function_exists('log_aktivitaet')) log_aktivitaet('item', $item_id, 'team', 'KI-Steckbrief erstellt.', 'ki');
    return ['ok' => true, 'text' => $text];
}

// Markdown-Reste entfernen, falls die KI doch welche liefert.
function steckbrief_saeubern(string $t): string {
    $t = str_replace(["\r\n", "\r"], "\n", $t);
    $t = preg_replace('/^\s{0,3}#{1,6}\s*/m', '', $t);          // Überschriften-Rauten
    $t = str_replace(['**', '__'], '', $t);                        // Fettdruck
    $t = preg_replace('/^\s*[\*•]\s+/m', '- ', $t);                // Aufzählungszeichen -> Bindestrich
    $t = str_replace(['–', '—'], '-', $t);                         // normale Bindestriche
    $t = preg_replace("/\n{3,}/", "\n\n", $t);
    return trim((string)$t);
}

// Eigene Änderung speichern (Stift).
function rohstoff_steckbrief_speichern(int $item_id, string $text): void {
    steckbrief_schema();
    $text = trim(str_replace(["\r\n", "\r"], "\n", $text));
    q("UPDATE item SET steckbrief=?, steckbrief_bearb_am=?, steckbrief_bearb_von=? WHERE id=?",
      [$text !== '' ? $text : null, gmdate('Y-m-d H:i:s'), steckbrief_wer(), $item_id]);
    if (function_exists('log_aktivitaet')) log_aktivitaet('item', $item_id, 'team', 'KI-Steckbrief bearbeitet.', 'notiz');
}

// Block „Steckbrief" (nur intern einbinden!). $back = Seite, auf die nach dem Speichern zurückgeleitet wird.
function rohstoff_steckbrief_block(int $item_id, string $back): void {
    steckbrief_schema();
    $it = one("SELECT id, name, steckbrief, steckbrief_am, steckbrief_von, steckbrief_bearb_am, steckbrief_bearb_von FROM item WHERE id=?", [$item_id]);
    if (!$it) return;
    require_once __DIR__ . '/ki.php';
    $text = trim((string)($it['steckbrief'] ?? ''));
    $dlg  = 'sbDlg' . $item_id;
    $hier = (int)($_GET['sb'] ?? 0) === $item_id;
    if ($hier && isset($_GET['sbok']))  echo '<div class="bx-panel badge-ok" style="padding:10px 14px">Steckbrief gespeichert.</div>';
    if ($hier && isset($_GET['sberr'])) echo '<div class="bx-panel" style="border-left:3px solid var(--warn);padding:10px 14px">Steckbrief nicht möglich: ' . h((string)$_GET['sberr']) . '</div>';
    $stiftIco = '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>';
    ?>
<div class="bx-panel" id="steckbrief-<?= $item_id ?>">
  <div class="bx-row" style="justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
    <div class="bx-row" style="gap:8px;align-items:center;margin:0">
      <h2 style="margin:0">Steckbrief</h2>
      <?= bx_badge('KI-generiert, intern, nicht geprüft', 'warn') ?>
      <?php if ($text !== ''): ?><button type="button" class="bx-stift" title="Bearbeiten" onclick="document.getElementById('<?= $dlg ?>').showModal()"><?= $stiftIco ?></button><?php endif; ?>
    </div>
    <form method="post" action="?p=rohstoff_steckbrief" style="margin:0"<?= $text !== '' ? ' onsubmit="return confirm(\'Steckbrief neu erstellen? Der bisherige Text samt eigenen Ergänzungen wird überschrieben.\');"' : '' ?>>
      <input type="hidden" name="aktion" value="erstellen"><input type="hidden" name="item_id" value="<?= $item_id ?>"><input type="hidden" name="back" value="<?= h($back) ?>">
      <button class="btn <?= $text === '' ? 'btn-primary' : 'btn-ghost' ?> btn-sm" type="submit" data-busy="KI schreibt&#8230;"<?= ki_bereit() ? '' : ' disabled title="KI nicht eingerichtet"' ?>><?= $text === '' ? 'KI-Steckbrief erstellen' : 'neu erstellen' ?></button>
    </form>
  </div>
  <?php if ($text !== ''): ?>
    <div style="margin-top:10px;white-space:pre-line;line-height:1.5"><?= h($text) ?></div>
    <div class="muted" style="font-size:12px;margin-top:8px">
      <?= $it['steckbrief_am'] ? 'Erstellt am ' . h(fmt_zeit($it['steckbrief_am'], 'd.m.Y H:i')) . ($it['steckbrief_von'] ? ' von ' . h((string)$it['steckbrief_von']) : '') : 'Von Hand angelegt' ?>
      <?= $it['steckbrief_bearb_am'] ? ' · bearbeitet am ' . h(fmt_zeit($it['steckbrief_bearb_am'], 'd.m.Y H:i')) . ($it['steckbrief_bearb_von'] ? ' von ' . h((string)$it['steckbrief_bearb_von']) : '') : '' ?>
      · nur intern, nicht im Kunden- oder Lieferantenportal
    </div>
    <dialog id="<?= $dlg ?>" class="bx-dialog" data-titel="Steckbrief bearbeiten" style="max-width:760px">
      <form method="post" action="?p=rohstoff_steckbrief" style="margin:0">
        <input type="hidden" name="aktion" value="speichern"><input type="hidden" name="item_id" value="<?= $item_id ?>"><input type="hidden" name="back" value="<?= h($back) ?>">
        <div style="margin-bottom:8px">Steckbrief bearbeiten <span class="muted" style="font-size:12px">- eigene Ergänzungen, z. B. Erfahrungen aus der Produktion</span></div>
        <textarea name="steckbrief" rows="18" style="width:100%;font-family:inherit;line-height:1.45"><?= h($text) ?></textarea>
        <div class="bx-row" style="justify-content:flex-end;gap:8px;margin-top:10px">
          <button type="button" class="btn btn-ghost" onclick="this.closest('dialog').close()">Abbrechen</button>
          <button type="submit" class="btn btn-primary">Speichern</button>
        </div>
      </form>
    </dialog>
  <?php elseif (!ki_bereit()): ?>
    <div class="muted" style="margin-top:8px">Die KI ist nicht eingerichtet (Einstellungen - KI).</div>
  <?php else: ?>
    <div class="muted" style="margin-top:8px">Noch kein Steckbrief. Die KI fasst zusammen: was ist das, Einsatz in NEM, übliche Dosis und Darreichung, Besonderheiten, Regulatorik (Novel Food, Health Claims, Höchstmengen). Nur für das Team, wird gespeichert.</div>
  <?php endif; ?>
</div>
<?php
}

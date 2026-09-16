<?php
// Fragenkatalog fürs Erstgespräch - übernommen aus dem v3-CRM.
//
// Aus einer eingegangenen Kundenanfrage entsteht die Vorbereitung für den Erstkontakt: was das
// Produkt ist, wo der fachliche Knackpunkt liegt, was wir den Kunden fragen müssen, was wir ihm
// von uns aus sagen müssen, und wer danach was zu tun hat.
//
// Das Wissen steckt NICHT hier im Code, sondern in `crm/prompts/fragenkatalog.md`. Diese Datei ist
// von Hand pflegbar - wer eine Regel ändern will (kein Preis im Erstgespräch, nicht nach vegan
// fragen), ändert dort einen Satz und muss keinen Code anfassen. Genau so war es in v3, und das
// ist der Grund, warum das Werkzeug gut ist.
require_once __DIR__ . '/ki.php';
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/ui.php';

function fragenkatalog_prompt(): string {
    $p = BX_ROOT . '/prompts/fragenkatalog.md';
    return is_file($p) ? (string) file_get_contents($p) : '';
}

// Erzeugen. $angaben ist alles, was wir über die Anfrage wissen - je mehr, desto besser.
// Rückgabe: ['ok'=>bool, 'text'=>string (Markdown), 'fehler'=>string]
function fragenkatalog_erzeugen(string $wer, array $angaben, string $verlauf = ''): array {
    if (!ki_bereit()) return ['ok' => false, 'text' => '', 'fehler' => 'Die KI ist nicht eingerichtet.'];
    $system = fragenkatalog_prompt();
    if (trim($system) === '')
        return ['ok' => false, 'text' => '', 'fehler' => 'Die Prompt-Datei crm/prompts/fragenkatalog.md fehlt.'];

    $felder = '';
    foreach ($angaben as $name => $wert) {
        $wert = trim((string)$wert);
        if ($wert !== '') $felder .= '- ' . $name . ': ' . $wert . "\n";
    }
    if ($felder === '') $felder = "- (keine strukturierten Felder vorhanden)\n";

    $nachricht = "Anfrage von: " . $wer . "\n\n"
               . "Bekannte Angaben:\n" . $felder
               . ($verlauf !== '' ? "\nBisheriger Verlauf:\n" . mb_substr($verlauf, 0, 4000) . "\n" : '')
               . "\nHinweis: Nicht alle Formularfelder sind hier gefüllt - wir haben oft nur eine formlose\n"
               . "Nachricht. Arbeite mit dem, was dasteht, und frag den Rest im Block 3 ab.";

    // Hier lohnt das grosse Modell: Es rechnet die Dosis durch und soll den Knackpunkt wirklich
    // finden. Das ist die Arbeit eines erfahrenen Kollegen, keine Formatierungsaufgabe.
    $r = ki_frage($nachricht, [
        'zweck'      => 'fragenkatalog',
        'system'     => $system,
        'denken'     => true,
        'aufwand'    => 'high',
        'max_tokens' => 6000,
        'timeout'    => 240,
        'budget'     => 300,
    ]);
    if (empty($r['ok'])) return ['ok' => false, 'text' => '', 'fehler' => (string)($r['fehler'] ?? 'Fehler')];

    $text = trim((string)($r['text'] ?? ''));
    if ($text === '') return ['ok' => false, 'text' => '', 'fehler' => 'Die Antwort war leer.'];
    return ['ok' => true, 'text' => $text, 'fehler' => ''];
}

// --- Merken --------------------------------------------------------------------------------
// Liegt in einer eigenen Tabelle statt in einer Spalte am Kontakt: So haengt derselbe Katalog
// wahlweise an einem Kontakt ODER an einem Kunden des Dashboards - und an dessen Tabelle fassen
// wir nichts an.
function fragenkatalog_merken(string $typ, int $id, string $text): void {
    q("INSERT INTO crm_briefing (bezug_typ, bezug_id, inhalt, modell, stand) VALUES (?,?,?,?,?)
       ON DUPLICATE KEY UPDATE inhalt=VALUES(inhalt), modell=VALUES(modell), stand=VALUES(stand)",
      [$typ, $id, $text, KI_MODELL, gmdate('Y-m-d H:i:s')]);
}
function fragenkatalog(string $typ, int $id): ?array {
    return one("SELECT * FROM crm_briefing WHERE bezug_typ=? AND bezug_id=?", [$typ, $id]);
}
function fragenkatalog_loeschen(string $typ, int $id): void {
    q("DELETE FROM crm_briefing WHERE bezug_typ=? AND bezug_id=?", [$typ, $id]);
}

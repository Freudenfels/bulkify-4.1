<?php
// Etikett fehlt: Kunde wird täglich erinnert (Paket 13.11.2026, Teil 4). Eine Stelle für Cron, Auftragsseite und erste Mail.
// - Die erste Bitte steht in der Mail nach der PIB-Freigabe (pib_mail_kunde) und zählt als 1. Erinnerung.
// - Danach höchstens eine Mail je Kunde und Tag (mehrere Aufträge in einer Mail), über den täglichen Cron (public/einkauf_cron.php).
// - Nie für stornierte, versendete oder fertige Aufträge, nie für Altaufträge ohne erste Mail (pib_mail_am), nicht wenn pausiert.
// - Hört auf, sobald ein Etikett hochgeladen ist (etikett_vorhanden).

function etikett_erinnerung_schema(): void {
    static $ok = false; if ($ok) return; $ok = true;
    try {
        ensure_column('auftrag', 'etikett_erinnert_anzahl', "INT NOT NULL DEFAULT 0");
        ensure_column('auftrag', 'etikett_erinnert_am', "DATETIME NULL");          // UTC, letzte Erinnerung
        ensure_column('auftrag', 'etikett_erinnerung_pause', "TINYINT(1) NOT NULL DEFAULT 0");
    } catch (Throwable $e) {}
}

// Erinnerung zählen (auch die Bitte in der ersten Mail).
function etikett_erinnerung_vermerken(int $aid, bool $ersteMail = false): void {
    etikett_erinnerung_schema();
    q("UPDATE auftrag SET etikett_erinnert_anzahl=etikett_erinnert_anzahl+1, etikett_erinnert_am=? WHERE id=?", [gmdate('Y-m-d H:i:s'), $aid]);
}

// Stand für die Auftragsseite: ['anzahl', 'zuletzt' (UTC), 'pause', 'aktiv' (würde heute erinnert werden)].
function etikett_erinnerung_stand(int $aid): array {
    etikett_erinnerung_schema();
    $a = one("SELECT etikett_erinnert_anzahl, etikett_erinnert_am, etikett_erinnerung_pause FROM auftrag WHERE id=?", [$aid]) ?: [];
    return ['anzahl' => (int)($a['etikett_erinnert_anzahl'] ?? 0), 'zuletzt' => (string)($a['etikett_erinnert_am'] ?? ''), 'pause' => !empty($a['etikett_erinnerung_pause'])];
}
function etikett_erinnerung_pause(int $aid, bool $pause, string $wer = ''): void {
    etikett_erinnerung_schema();
    q("UPDATE auftrag SET etikett_erinnerung_pause=? WHERE id=?", [$pause ? 1 : 0, $aid]);
    if (function_exists('log_aktivitaet')) log_aktivitaet('auftrag', $aid, 'team', 'Etikett-Erinnerung ' . ($pause ? 'pausiert' : 'wieder aktiv') . ($wer !== '' ? ' (' . $wer . ')' : ''), 'etikett');
}

// Aufträge, die heute eine Erinnerung bekommen. $heute = Ortsdatum (Y-m-d).
function etikett_erinnerung_faellig(string $heute): array {
    etikett_erinnerung_schema();
    if (function_exists('pib_schema')) pib_schema();
    // Fable-6 P2: gesperrte Kunden und schon freigegebene Etiketten nie erinnern.
    $rows = all("SELECT a.id, a.nummer, a.kunde_id, a.produkt_id, a.etikett_erinnert_am, COALESCE(NULLIF(a.produkt_bezeichnung,''), p.name) AS produkt
                 FROM auftrag a LEFT JOIN produkt p ON p.id=a.produkt_id JOIN kunden k ON k.id=a.kunde_id
                 WHERE a.status NOT IN ('versendet','storniert','erledigt') AND a.kunde_id IS NOT NULL AND a.pib_mail_am IS NOT NULL
                   AND COALESCE(k.gesperrt,0)=0 AND COALESCE(a.etikett_freigegeben,0)=0
                   AND COALESCE(a.etikett_erinnerung_pause,0)=0 AND COALESCE(a.kategorie,'produkt') <> 'dienstleistung'
                 ORDER BY a.kunde_id, a.id");
    $out = [];
    foreach ($rows as $r) {
        $aid = (int)$r['id'];
        if (!function_exists('auftrag_braucht_etikett') || !auftrag_braucht_etikett($aid) || etikett_vorhanden($aid)) continue;
        // Nachbestellung mit übernommenem, schon freigegebenem Design (geerbt) braucht keine Erinnerung (ohne Seiteneffekt geprüft).
        if (function_exists('etikett_geerbt_freigabe_text') && etikett_geerbt_freigabe_text($aid) !== '') continue;
        $letzt = (string)$r['etikett_erinnert_am'];
        if ($letzt !== '' && (function_exists('fmt_zeit') ? fmt_zeit($letzt, 'Y-m-d') : substr($letzt, 0, 10)) >= $heute) continue;   // heute schon erinnert
        $out[] = $r;
    }
    return $out;
}

// Täglicher Lauf (Cron): je Kunde eine Mail mit allen fälligen Aufträgen. Rückgabe ['kunden'=>…, 'auftraege'=>…, 'fehler'=>[]].
function etikett_erinnerungen_cron(): array {
    if (!function_exists('mail_senden')) require_once __DIR__ . '/mail.php';
    $heute = function_exists('heute_ort') ? heute_ort() : date('Y-m-d');
    $jeKunde = [];
    foreach (etikett_erinnerung_faellig($heute) as $r) $jeKunde[(int)$r['kunde_id']][] = $r;
    $n = 0; $na = 0; $fehler = [];
    foreach ($jeKunde as $kid => $auftraege) {
        $r = etikett_erinnerung_mail($kid, $auftraege);
        if ($r !== '') { $fehler[] = 'Kunde ' . $kid . ': ' . $r; continue; }
        $n++;
        foreach ($auftraege as $a) {
            etikett_erinnerung_vermerken((int)$a['id']);
            $na++;
            if (function_exists('log_aktivitaet')) log_aktivitaet('auftrag', (int)$a['id'], 'system', 'Etikett-Erinnerung an den Kunden gesendet', 'etikett');
        }
    }
    return ['kunden' => $n, 'auftraege' => $na, 'fehler' => $fehler];
}

// Die Mail (freundlich, aber klar). DE/EN nach dem Land des Kunden (pib_kunde_sprache). Rückgabe '' = verschickt.
function etikett_erinnerung_mail(int $kunde_id, array $auftraege): string {
    $k = one("SELECT id, firma, ansprechpartner, email FROM kunden WHERE id=?", [$kunde_id]);
    if (!$k) return 'Kunde fehlt.';
    if (function_exists('kunde_mails_aus') && kunde_mails_aus($kunde_id)) return 'Benachrichtigungen ausgeschaltet.';
    if (trim((string)$k['email']) === '') return 'Keine E-Mail-Adresse.';
    $en = function_exists('pib_kunde_sprache') && pib_kunde_sprache($kunde_id) === 'en';
    if (!function_exists('beleg_firma')) require_once __DIR__ . '/pdf_beleg.php';
    $fa = beleg_firma();
    $anrede = function_exists('mail_kunde_anrede') ? mail_kunde_anrede($k) : (string)$k['firma'];
    $zeilen = [];
    foreach ($auftraege as $a) {
        $name = function_exists('produkt_kundenname') && $a['produkt_id'] ? produkt_kundenname((int)$a['produkt_id'], $kunde_id) : '';
        $name = $name !== '' ? $name : (string)$a['produkt'];
        $zeilen[] = '- ' . $a['nummer'] . ($name !== '' ? ' (' . $name . ')' : '') . ': ' . (function_exists('mail_link_kundenportal') ? mail_link_kundenportal($kunde_id, 'bestellung&aid=' . (int)$a['id'] . '&etikett=' . (int)$a['id']) : '');
    }
    $nr = count($auftraege) === 1 ? (string)$auftraege[0]['nummer'] : '';
    if ($en) {
        $betreff = $nr !== '' ? 'Label for your order ' . $nr . ' is still missing' : 'Labels for ' . count($auftraege) . ' of your orders are still missing';
        $text = 'Hello ' . $anrede . ",\n\n" . ($nr !== '' ? 'we have not yet received a label for your order ' . $nr . '.' : 'we have not yet received labels for the following orders:')
              . " Without a label we cannot complete production. Please upload your label here:\n\n" . implode("\n", $zeilen) . "\n\nThank you very much!\n\nBest regards\n" . $fa['name'];
    } else {
        $betreff = $nr !== '' ? 'Etikett für Ihren Auftrag ' . $nr . ' fehlt noch' : 'Etiketten für ' . count($auftraege) . ' Ihrer Aufträge fehlen noch';
        $text = 'Guten Tag ' . $anrede . ",\n\n" . ($nr !== '' ? 'für Ihren Auftrag ' . $nr . ' liegt uns noch kein Etikett vor.' : 'für die folgenden Aufträge liegt uns noch kein Etikett vor.')
              . " Ohne Etikett können wir die Produktion nicht abschließen. Bitte laden Sie Ihr Etikett hier hoch:\n\n" . implode("\n", $zeilen) . "\n\nVielen Dank!\n\nViele Grüße\n" . $fa['name'];
    }
    return mail_senden((string)$k['email'], $betreff, $text);
}

// Merker „letzter Lauf“ des täglichen Crons (app_meta cron_letzter_lauf = UTC, cron_letzter_lauf_info = Kurzinfo).
function cron_lauf_merken(string $info): void {
    try {
        q("INSERT INTO app_meta (k, v) VALUES ('cron_letzter_lauf', ?) ON DUPLICATE KEY UPDATE v=VALUES(v)", [gmdate('Y-m-d H:i:s')]);
        q("INSERT INTO app_meta (k, v) VALUES ('cron_letzter_lauf_info', ?) ON DUPLICATE KEY UPDATE v=VALUES(v)", [mb_substr($info, 0, 500)]);
    } catch (Throwable $e) {}
}
// Anzeige „Täglicher Lauf: zuletzt 13.11.2026, 06:00 Uhr“ bzw. Warnung, wenn nie oder vor mehr als 36 h gelaufen.
function cron_lauf_hinweis(): string {
    $t = ''; $info = '';
    try { $t = (string) scalar("SELECT v FROM app_meta WHERE k='cron_letzter_lauf'"); $info = (string) scalar("SELECT v FROM app_meta WHERE k='cron_letzter_lauf_info'"); } catch (Throwable $e) {}
    if ($t === '') return '<span style="color:var(--err)">Täglicher Lauf (Cron): noch nie gelaufen – ohne ihn gehen keine Erinnerungen raus.</span>';
    $alt = (time() - strtotime($t . ' UTC')) > 36 * 3600;
    return '<span' . ($alt ? ' style="color:var(--err)"' : '') . '>Täglicher Lauf (Cron): zuletzt ' . h(function_exists('fmt_zeit') ? fmt_zeit($t, 'd.m.Y, H:i') : $t) . ' Uhr'
         . ($alt ? ' – länger als 36 Stunden her, bitte im Hosting prüfen' : '') . '</span>' . ($info !== '' ? ' <span class="muted" style="font-size:12px">(' . h($info) . ')</span>' : '');
}

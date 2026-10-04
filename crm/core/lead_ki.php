<?php
// Eingehende Anfrage von der KI auswerten lassen.
//
// Der Fall: Ueber das Website-Formular (public/crm/lead_intake.php) kommt eine Anfrage als
// Kontakt herein - roher Freitext in der Notiz. Statt dass jemand das liest und von Hand
// einordnet, laesst die KI eine kurze Auswertung erstellen: Zusammenfassung, Produktform,
// Menge, grober Wert, Dringlichkeit und der naechste Schritt.
//
// Was damit passiert (alles in crm_-Tabellen, nie im Dashboard):
//   - eine lesbare "KI-Auswertung" als Verlaufseintrag am Kontakt
//   - der geschaetzte Wert wird gesetzt, FALLS am Kontakt noch keiner steht
//   - eine Wiedervorlage, damit die Anfrage in "Wer wartet auf mich" auftaucht
//   - ein Zeitstempel crm_kontakt.ki_ausgewertet, damit nichts doppelt laeuft
//
// Gespeichert wird hier bewusst DIREKT (anders als beim Formular-Ausfuellen) - es ist eine reine
// interne Einordnung einer echten Anfrage, kein an den Kunden gehender Text. Stammdaten wie Name
// oder E-Mail werden NICHT ueberschrieben; die KI fasst nur zusammen und schlaegt einen Schritt vor.
require_once __DIR__ . '/ki.php';
require_once __DIR__ . '/kontakt.php';

// Standard-Nachfassfrist, wenn die Anfrage keine eigene Frist nennt (Tage).
if (!defined('CRM_LEAD_NACHFASSEN')) define('CRM_LEAD_NACHFASSEN', 2);

// Den pflegbaren Prompt aus crm/prompts/lead_auswertung.md lesen (wie beim Fragenkatalog).
// Faellt die Datei weg, greift ein knapper Ersatztext, damit die Funktion nie leer laeuft.
function lead_ki_prompt(): string {
    $pfad = BX_ROOT . '/prompts/lead_auswertung.md';
    $txt = is_file($pfad) ? (string)@file_get_contents($pfad) : '';
    if (trim($txt) !== '') return $txt;
    return "Werte die folgende Kundenanfrage an einen Lohnhersteller fuer Nahrungsergaenzung aus. "
         . "Antworte NUR mit JSON: {\"zusammenfassung\":\"\",\"produktform\":\"\",\"wirkstoffe\":\"\","
         . "\"menge\":\"\",\"wert_eur\":null,\"dringlichkeit\":\"mittel\",\"naechster_schritt\":\"\","
         . "\"frist_tage\":null,\"offene_punkte\":\"\"}. Nichts erfinden, alles auf Deutsch.";
}

// Die Auswertung durchfuehren. Wirft nie - ein Fehler darf den Eingang nie stoeren.
// Rueckgabe: ['ok'=>bool, 'daten'=>array, 'fehler'=>string]
function lead_ki_auswerten(int $kontakt_id, int $uid = 0, string $text = ''): array {
    try {
        $k = kontakt($kontakt_id);
        if (!$k) return ['ok' => false, 'daten' => [], 'fehler' => 'Kontakt nicht gefunden.'];

        $text = trim($text) !== '' ? trim($text) : trim((string)($k['notiz'] ?? ''));
        if ($text === '') return ['ok' => false, 'daten' => [], 'fehler' => 'Kein Anfragetext da.'];
        if (!ki_bereit())  return ['ok' => false, 'daten' => [], 'fehler' => 'Die KI ist nicht eingerichtet.'];

        $r = ki_json(mb_substr($text, 0, 12000), [
            'system'     => lead_ki_prompt(),
            'zweck'      => 'lead/auswertung',
            'modell'     => KI_MODELL_SCHNELL,   // Einordnung einer Anfrage - das schnelle Modell reicht
            'max_tokens' => 1500,
            'timeout'    => 60,
            'budget'     => 120,
        ]);
        if (empty($r['ok']))                 return ['ok' => false, 'daten' => [], 'fehler' => (string)($r['fehler'] ?? 'Fehler')];
        if (!is_array($r['daten'] ?? null))  return ['ok' => false, 'daten' => [], 'fehler' => 'Die Antwort war nicht lesbar.'];

        $d = lead_ki_saeubern($r['daten']);

        // 1) Lesbare Auswertung in den Verlauf.
        kontakt_verlauf($kontakt_id, 'notiz', lead_ki_notiz($d), $uid);

        // 2) Geschaetzten Wert setzen - nur, wenn noch keiner am Kontakt steht (nichts ueberschreiben).
        if ($d['wert_eur'] !== null && ($k['wert_eur'] === null || (float)$k['wert_eur'] <= 0)) {
            q("UPDATE crm_kontakt SET wert_eur=?, aktualisiert=? WHERE id=?",
              [$d['wert_eur'], gmdate('Y-m-d H:i:s'), $kontakt_id]);
        }

        // 2b) Strukturierte Anfrage-Felder fuellen - nur leere, damit manuelle Eintraege erhalten bleiben.
        $setze = [];
        if ($d['produkt'] !== ''     && trim((string)($k['anfrage_rezeptur'] ?? '')) === '') $setze['anfrage_rezeptur'] = $d['produkt'];
        if ($d['produktform'] !== ''  && trim((string)($k['anfrage_form'] ?? '')) === '')     $setze['anfrage_form'] = $d['produktform'];
        if ($d['menge'] !== ''        && trim((string)($k['anfrage_inhalt'] ?? '')) === '')    $setze['anfrage_inhalt'] = $d['menge'];
        if ($d['vorhaben'] !== ''     && trim((string)($k['anfrage_vorhaben'] ?? '')) === '')  $setze['anfrage_vorhaben'] = $d['vorhaben'];
        if ($setze) {
            $sql = 'UPDATE crm_kontakt SET ' . implode(', ', array_map(fn($f) => "$f=?", array_keys($setze))) . ', aktualisiert=? WHERE id=?';
            q($sql, array_merge(array_values($setze), [gmdate('Y-m-d H:i:s'), $kontakt_id]));
        }

        // 3) Wiedervorlage setzen, damit die Anfrage in "Wer wartet" auftaucht - aber nur, wenn noch
        //    keine offene Wiedervorlage haengt (sonst entstehen beim erneuten Auswerten Dubletten).
        if (!kontakt_wiedervorlagen($kontakt_id)) {
            $tage  = $d['frist_tage'] !== null ? $d['frist_tage'] : CRM_LEAD_NACHFASSEN;
            $titel = 'Anfrage nachfassen: ' . (string)$k['name'];
            kontakt_wiedervorlage($kontakt_id, $titel, $tage, $uid, (string)$d['naechster_schritt']);
        }

        // 4) Stempel, damit die Auswertung nicht doppelt automatisch laeuft.
        q("UPDATE crm_kontakt SET ki_ausgewertet=? WHERE id=?", [gmdate('Y-m-d H:i:s'), $kontakt_id]);

        return ['ok' => true, 'daten' => $d, 'fehler' => ''];
    } catch (Throwable $e) {
        return ['ok' => false, 'daten' => [], 'fehler' => 'Interner Fehler bei der Auswertung.'];
    }
}

// Aus der KI-Antwort verlaessliche Felder machen: kappen, pruefen, Unsinn aussortieren.
function lead_ki_saeubern(array $d): array {
    $dring = (string)($d['dringlichkeit'] ?? 'mittel');
    if (!in_array($dring, ['niedrig', 'mittel', 'hoch'], true)) $dring = 'mittel';
    return [
        'zusammenfassung'   => trim((string)($d['zusammenfassung'] ?? '')),
        'produkt'           => mb_substr(trim((string)($d['produkt'] ?? '')), 0, 255),
        'produktform'       => mb_substr(trim((string)($d['produktform'] ?? '')), 0, 60),
        'wirkstoffe'        => trim((string)($d['wirkstoffe'] ?? '')),
        'menge'             => mb_substr(trim((string)($d['menge'] ?? '')), 0, 120),
        'vorhaben'          => mb_substr(trim((string)($d['vorhaben'] ?? '')), 0, 190),
        'wert_eur'          => is_numeric($d['wert_eur'] ?? null) ? (float)$d['wert_eur'] : null,
        'dringlichkeit'     => $dring,
        'naechster_schritt' => trim((string)($d['naechster_schritt'] ?? '')),
        'frist_tage'        => is_numeric($d['frist_tage'] ?? null) ? max(0, min(90, (int)$d['frist_tage'])) : null,
        'offene_punkte'     => trim((string)($d['offene_punkte'] ?? '')),
    ];
}

// Die lesbare Verlaufsnotiz zusammenbauen - nur Zeilen, die auch etwas enthalten.
function lead_ki_notiz(array $d): string {
    $dring = ['niedrig' => 'niedrig', 'mittel' => 'mittel', 'hoch' => 'hoch'][$d['dringlichkeit']] ?? 'mittel';
    $zeilen = ['KI-Auswertung der Anfrage'];
    if ($d['zusammenfassung'] !== '')   $zeilen[] = $d['zusammenfassung'];
    $zeilen[] = '';
    if ($d['produktform'] !== '')       $zeilen[] = 'Produktform: ' . $d['produktform'];
    if ($d['wirkstoffe'] !== '')        $zeilen[] = 'Wirkstoffe: ' . $d['wirkstoffe'];
    if ($d['menge'] !== '')             $zeilen[] = 'Menge: ' . $d['menge'];
    if ($d['wert_eur'] !== null)        $zeilen[] = 'Geschaetzter Wert: ' . number_format($d['wert_eur'], 2, ',', '.') . ' EUR';
    $zeilen[] = 'Dringlichkeit: ' . $dring;
    if ($d['naechster_schritt'] !== '') $zeilen[] = 'Naechster Schritt: ' . $d['naechster_schritt'];
    if ($d['offene_punkte'] !== '')     $zeilen[] = 'Offene Punkte: ' . $d['offene_punkte'];
    return trim(implode("\n", $zeilen));
}

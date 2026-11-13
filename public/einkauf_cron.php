<?php
// Täglicher Lauf (gemeinsamer Cron-Einstieg): Erinnerungen an überfällige, unbestätigte Portal-Bestellungen (B4),
// bald ablaufende Preise und seit 13.11.2026 die Etikett-Erinnerung an Kunden. Merker „letzter Lauf“: app_meta cron_letzter_lauf.
// KEIN Login, token-gesichert. Aufruf per Server-Cron, z. B. 1x täglich:
//   <host>/einkauf_cron.php?token=<einkauf_cron_token>
// Den Token zeigt die Einkauf-Liste (Admin) an bzw. legt ihn beim ersten Aufruf von einkauf_cron_token() an.
// Regeln (Fable-6, 13.11.2026): eine Mail je Lieferant und Tag, nur offener Rest, höchstens 3 je Bestellung, gesperrte raus.
// Läuft über GitHub Actions (.github/workflows/cron-taeglich.yml, 07:00 UTC). Antwort JSON; ok:false nur bei Sperre/Token.
require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/schema.php';
require_once __DIR__ . '/../core/mail.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function ec_out(int $code, array $a): void { http_response_code($code); echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }

if (function_exists('bx_testumgebung') && bx_testumgebung()) ec_out(200, ['ok' => true, 'testumgebung' => true, 'meldung' => 'Testumgebung: Cron aus, keine Erinnerungen.']);
init_schema();

$given = (string)($_GET['token'] ?? ($_SERVER['HTTP_X_CRON_TOKEN'] ?? ''));
$token = einkauf_cron_token();
if ($token === '' || $given === '' || !hash_equals($token, $given)) {
    ec_out(403, ['ok' => false, 'fehler' => 'Ungültiger oder fehlender Token.']);
}

// Fable-6 P1/P2 (13.11.2026): Belege-Helfer laden (beleg_firma), Sperre gegen Doppellauf, jede Mail-Art in eigenem try/catch.
require_once __DIR__ . '/../core/pdf_beleg.php';
require_once __DIR__ . '/../core/bestellung_erinnerung.php';
if (!cron_sperre_holen(15)) ec_out(409, ['ok' => false, 'fehler' => 'Der tägliche Lauf läuft bereits (Sperre jünger als 15 Minuten).']);
register_shutdown_function('cron_sperre_freigeben');

$tage = max(1, min(30, (int)($_GET['tage'] ?? 3)));          // ab wie vielen Tagen überfällig
$fehler = []; $abbruch = [];   // fehler = einzelne Mails gingen nicht (Hinweis); abbruch = eine Mail-Art ist abgestürzt -> ok:false
$r = ['gesendet' => 0, 'bestellungen' => 0, 'geprueft' => 0, 'aufgaben' => 0, 'fehler' => []];
try { $r = einkauf_erinnerungen_cron($tage, 24); } catch (\Throwable $e) { $abbruch[] = 'Bestell-Erinnerungen: ' . $e->getMessage(); }
foreach ($r['fehler'] ?? [] as $f) $fehler[] = 'Bestell-Erinnerungen: ' . $f;
// Block D (11.11.2026): täglich auch bald ablaufende Preise (Rohstoff, Zukauf, Fremdfertigung) und Auto-EK aus abgelaufenen Preisen.
$bald = 0; $ekAbg = 0;
try { require_once __DIR__ . '/../core/preis_gueltig.php'; $bald = pg_bald_pruefen(); } catch (\Throwable $e) { $abbruch[] = 'Preise bald: ' . $e->getMessage(); }
try { $ekAbg = pg_ek_abgelaufen_pruefen(); } catch (\Throwable $e) { $abbruch[] = 'EK abgelaufen: ' . $e->getMessage(); }
// 13.11.2026 (Paket Teil 4): Etikett fehlt -> Kunde täglich erinnern (höchstens eine Mail je Kunde und Tag).
$et = ['kunden' => 0, 'auftraege' => 0, 'fehler' => []];
try { $et = etikett_erinnerungen_cron(); } catch (\Throwable $e) { $abbruch[] = 'Etikett-Erinnerungen: ' . $e->getMessage(); }
foreach ($et['fehler'] ?? [] as $f) $fehler[] = 'Etikett-Erinnerungen: ' . $f;
// Mail-Bremse: was in der Warteschlange liegt, jetzt abarbeiten (so weit die Bremse es zulässt).
$wsl = 0;
try { if (function_exists('mail_warteschlange_abarbeiten')) $wsl = mail_warteschlange_abarbeiten(); } catch (\Throwable $e) { $abbruch[] = 'Warteschlange: ' . $e->getMessage(); }
cron_lauf_merken('Bestell-Erinnerungen ' . (int)$r['gesendet'] . ' Mails/' . (int)$r['bestellungen'] . ' Bestellungen, Etikett-Erinnerungen ' . (int)$et['kunden'] . ' Kunden/' . (int)$et['auftraege'] . ' Aufträge' . ($fehler ? ', Hinweise: ' . count($fehler) : '') . ($abbruch ? ', ABBRUCH: ' . implode(' | ', $abbruch) : ''));
ec_out(200, ['ok' => !$abbruch, 'abbruch' => $abbruch, 'gesendet' => (int)$r['gesendet'], 'bestellungen' => (int)$r['bestellungen'], 'geprueft' => (int)$r['geprueft'], 'aufgaben_lieferant_reagiert_nicht' => (int)$r['aufgaben'],
             'preise_bald' => $bald, 'ek_abgelaufen' => $ekAbg,
             'etikett_erinnerung_kunden' => (int)$et['kunden'], 'etikett_erinnerung_auftraege' => (int)$et['auftraege'],
             'warteschlange_gesendet' => $wsl, 'fehler' => $fehler]);

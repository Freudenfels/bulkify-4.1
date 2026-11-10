<?php
// Pop-up „Weg festlegen“ (Einkauf gerade ziehen, Teil 6, 10.11.2026): liefert den Inhalt (ohne Seitenrahmen) für den Dialog aus
// core/weg_festlegen.php – Kopf mit Links, Rezeptur für den Auftrag und die Karten des Reiters Beschaffungsweg.
// Route: ?p=weg_festlegen&auftrag=ID&zurueck=<interner Rücksprung>. Nur Admin (wie der Reiter Beschaffungsweg).
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';
require_once BX_ROOT . '/core/weg_festlegen.php';
header('Content-Type: text/html; charset=utf-8');
if (!has_role('admin')) { echo '<div style="color:#8f231b">Nur Admins.</div>'; exit; }
$aid = (int)($_GET['auftrag'] ?? 0);
$zur = (string)($_GET['zurueck'] ?? '');
if ($zur !== '' && $zur[0] !== '?') $zur = '?' . ltrim($zur, '&');
if (!preg_match('/^\?p=[a-z_]+(&[A-Za-z0-9_=%.-]*)*$/', $zur)) $zur = '';
if ($aid <= 0 || !auftrag_aktiver_pa($aid)) { echo '<div class="muted">Zu diesem Auftrag gibt es keinen offenen Produktionsauftrag.</div>'; exit; }
echo weg_festlegen_kopf($aid);
echo '<h3 style="margin:16px 0 8px;font-size:15px">Wege für diesen Auftrag</h3>';
echo beschaffungsweg_karten($aid, $zur, true);

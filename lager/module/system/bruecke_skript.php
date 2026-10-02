<?php
// Liefert das Brueckenprogramm zum Herunterladen - als .bat zum DOPPELKLICKEN.
// Adresse und Schluessel sind schon eingetragen. Vorlage: bruecke/bruecke.ps1.
//
// Warum eine .bat statt direkt .ps1: Ein Doppelklick auf eine .ps1 oeffnet nur den Editor
// (laeuft nicht), und "Mit PowerShell ausfuehren" wird oft von der Ausfuehrungsrichtlinie bzw.
// der "aus-dem-Internet"-Sperre blockiert. Die .bat schreibt das Skript nach %TEMP% und startet
// es mit -ExecutionPolicy Bypass - laeuft per Doppelklick zuverlaessig.
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
      || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
$basis = ($https ? 'https' : 'http') . '://' . (string)($_SERVER['HTTP_HOST'] ?? 'localhost') . '/lager/bruecke.php';

$vorlage = (string)file_get_contents(BX_ROOT . '/bruecke/bruecke.ps1');
$skript  = strtr($vorlage, ['{{URL}}' => $basis, '{{TOKEN}}' => lg_bruecke_token()]);
$skript  = str_replace(["\r\n", "\n"], ["\n", "\r\n"], $skript);   // saubere Windows-Zeilenenden
$b64     = base64_encode($skript);                                 // UTF-8-Bytes des PS1

// .bat: PS1 nach %TEMP% schreiben, dann mit Bypass starten. ($env:TEMP ist LITERAL -> einfache Anfuehrungszeichen.)
$zeilen = [
    '@echo off',
    'title bulkify Lager-Bruecke',
    'echo bulkify Lager-Bruecke wird gestartet - dieses Fenster bitte offen lassen.',
    'echo.',
    'powershell -NoProfile -Command "[IO.File]::WriteAllBytes(' . '$env:TEMP' . "+'\\bulkify-lager-bruecke.ps1',[Convert]::FromBase64String('" . $b64 . "'))\"",
    'powershell -NoProfile -ExecutionPolicy Bypass -File "%TEMP%\\bulkify-lager-bruecke.ps1"',
    'echo.',
    'echo Bruecke beendet. Taste druecken zum Schliessen.',
    'pause >nul',
];
$bat = implode("\r\n", $zeilen) . "\r\n";

header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="bulkify-lager-bruecke.bat"');
header('Cache-Control: no-store');
echo $bat;
exit;

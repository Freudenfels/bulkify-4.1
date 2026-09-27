<?php
// Liefert das Brueckenprogramm (PowerShell) zum Herunterladen - Adresse und Schluessel sind
// schon eingetragen. Vorlage: lager/bruecke/bruecke.ps1.
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
      || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
$basis = ($https ? 'https' : 'http') . '://' . (string)($_SERVER['HTTP_HOST'] ?? 'localhost') . '/lager/bruecke.php';

$vorlage = (string)file_get_contents(BX_ROOT . '/bruecke/bruecke.ps1');
$skript = strtr($vorlage, ['{{URL}}' => $basis, '{{TOKEN}}' => lg_bruecke_token()]);

header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="bulkify-lager-bruecke.ps1"');
header('Cache-Control: no-store');
// Windows-Zeilenenden, damit Notepad und PowerShell 5.1 sie sauber lesen.
echo str_replace(["\r\n", "\n"], ["\n", "\r\n"], $skript);
exit;

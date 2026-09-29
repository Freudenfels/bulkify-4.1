@echo off
title bulkify stoppen
echo Beende den lokalen bulkify-Server ...
REM Das Server-Fenster hat den Titel "bulkify-server" (aus start-bulkify.bat).
taskkill /FI "WINDOWTITLE eq bulkify-server*" /T /F >nul 2>&1
echo.
echo Fertig. Falls noch ein Fenster "bulkify-server" offen ist, einfach schliessen.
echo (MariaDB laeuft als Windows-Dienst weiter - das ist normal.)
echo.
pause

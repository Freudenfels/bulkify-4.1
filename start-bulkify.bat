@echo off
title bulkify - lokaler Offline-Server
cd /d "%~dp0"

echo ============================================================
echo   bulkify 4.1 - lokaler Server (funktioniert OHNE Internet)
echo ============================================================
echo.

REM MariaDB laeuft normalerweise als Windows-Dienst automatisch.
REM Falls nicht, versuchen wir ihn zu starten (Fehler werden ignoriert).
net start MariaDB >nul 2>&1

echo Starte den Server auf http://127.0.0.1:8741 ...
start "bulkify-server" /min "C:\php\php.exe" -S 127.0.0.1:8741 -t public

REM Kurz warten, bis der Server oben ist (ca. 2 Sekunden).
ping 127.0.0.1 -n 3 >nul

REM Browser mit Admin-Autologin oeffnen (nur auf localhost erlaubt).
start "" "http://127.0.0.1:8741/?p=autologin&token=355928ed0d5fd5409651ed50f9d6357a"

echo.
echo bulkify laeuft jetzt lokal.  Adresse: http://127.0.0.1:8741
echo.
echo   - Der Server-Prozess laeuft im Fenster "bulkify-server" (minimiert).
echo   - Falls der Autologin nicht greift: Login  admin@bulkify.local  /  admin
echo   - Die KI-Funktionen sind offline AUS (brauchen Internet) - alles andere geht.
echo.
echo   Beenden:  stop-bulkify.bat  ausfuehren (oder das Fenster "bulkify-server" schliessen).
echo.
pause

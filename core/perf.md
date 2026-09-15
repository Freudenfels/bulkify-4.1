# perf.php – Performance-Diagnose

## Wozu
Macht sichtbar, **wie schnell** das System ist und **woran** es liegt. Datenquelle für den Reiter **Einstellungen → Diagnose**.

## Zwei Teile
1. **Zähler pro Request** (in `core/db.php`): Jede DB-Abfrage erhöht `bx_q_n` (Anzahl) und `bx_q_ms` (aufsummierte DB-Zeit); der Verbindungsaufbau steht in `bx_db_connect_ms`. Abrufbar über `db_stats()`. Vernachlässigbarer Aufwand, deshalb immer an.
2. **Aufzeichnung** (optional, `app_meta.perf_log=1`): `perf_aufzeichnen()` wird per `register_shutdown_function` in `public/index.php` am Ende jedes Requests aufgerufen und schreibt EINE Zeile nach `data/perf.log` (Zeit, Route `p:v`, Dauer ms, Anzahl Abfragen, DB-Zeit ms, Connect ms, Spitzen-RAM). Die Datei wird bei > 200 KB auf die letzten 400 Zeilen gekürzt. `data/` ist nicht im Git.

## Funktionen
- `perf_aktiv()` – ist die Aufzeichnung eingeschaltet?
- `perf_aufzeichnen($startT)` – Messzeile schreiben (nur wenn aktiv). `$startT` = `BX_T0` aus `index.php`.
- `perf_letzte($n)` – die letzten Aufrufe (neueste zuerst).
- `perf_je_route()` – Kennzahlen je Route (Ø/max Dauer, Ø Abfragen), langsamste zuerst.
- `perf_logdatei_leeren()` – Protokoll leeren.
- `perf_selftest()` – **Live-Messung**: Latenz je Abfrage (50× `SELECT 1`), eine `information_schema`-Abfrage, Verbindungszeit, plus Umgebung: PHP-Version, **OPcache** an/aus (+ Trefferquote), Speicher-Limit, DB-Version/-Host (lokal/entfernt), EMULATE_PREPARES, Schema-Schnellpfad gesetzt.

## So liest man die Werte
- **Zeit je DB-Abfrage** ist der Schlüssel: Seiten-Dauer ≈ Anzahl Abfragen × diese Latenz. Lokal < 1 ms, auf einer entfernten/ausgelasteten DB schnell 10–30 ms → dann sind viele Abfragen teuer.
- **OPcache AUS** heißt: PHP kompiliert jede Datei bei jedem Aufruf neu → beim Hoster/in der php.ini einschalten.
- **DB „entfernt"** heißt: DB nicht auf demselben Server wie PHP → Netzwerk-Latenz je Abfrage.

## Hinweise
- Die Diagnose-Seite misst bei jedem Öffnen neu (die 50 Test-Abfragen), dauert also auf einer langsamen DB selbst kurz.
- Die Aufzeichnung ist bewusst opt-in: eingeschaltet kostet sie einen kleinen Datei-Schreibvorgang je Request. Zum Finden langsamer Seiten kurz einschalten, durchklicken, wieder ausschalten.

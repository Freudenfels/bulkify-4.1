# Aufgabe (Lager-Chat): Kisten beim Einbuchen wählen + Kisten-Ansicht im Bestand

> Diese Anweisung ist für den **Lager-Chat** (Arbeitsbereich: nur `lager/` und `public/lager/`).
> Vom Produktions-Chat vorbereitet. Bitte zuerst `lager/LAGER.md` + `CLAUDE.md` lesen.

## Regeln (wie immer)
- Nur in `lager/` und `public/lager/` arbeiten. Dashboard-Zugriffe **ausschließlich** über `lager/core/erp.php` (die Naht). Eigene Tabellen bleiben `lg_`.
- Zu jeder `.php` die co-located `.md` mitpflegen. Keine Emojis in der UI. Zeit in UTC speichern, Anzeige über `fmt_zeit()`.
- Git: vor jedem Push `git pull --rebase origin main`, dann `git push`. Gezielt stagen (`git add lager public/lager`), nie `git add -A`. Niemals `--force`.
- Verifizieren: `php -l` + Dashboard-Server `php -S 127.0.0.1:8741 -t public`, dann `/lager/?p=...` (lokal `?p=autologin&token=<benutzer.login_token>`).

## Das ist schon da (nutzen, nicht neu bauen)
- Tabellen `lg_kiste`, `lg_kiste_inhalt` (Charge↔Kiste, optional `fach`).
- Helfer in `lager/core/kiste.php`:
  - `kiste_alle()` – alle Kisten.
  - `kiste_inhalt(int $kiste_id)` – Chargen in einer Kiste.
  - `kiste_charge_zuordnen(int $kiste_id, int $charge_id, string $fach='')` – Charge in Kiste legen (gibt Fehlertext oder '' zurück).
  - `kiste_fuer_charge(int $charge_id)`, `kiste_blinker(int $kiste_id)`.
- `?p=charge` kann eine Charge bereits per Aktion `in_kiste`/`aus_kiste` zuordnen/entfernen.
- `bestand/liste.php` zeigt je Charge bereits den Ort (`Kiste X` bzw. Blinker-Code), Variable `$bf['kiste']`.

## Aufgabe 1 – Beim Einbuchen die Kiste wählen
Ziel: Beim Wareneingang direkt sagen können „das kommt in Kiste …" (optional mit Fach).

- Im **Wareneingang** (die aktuell genutzte Maske – prüfen: `bestand/wareneingang.php` für Route `we`, ggf. auch `bestand/eingang.php`) ein **Auswahlfeld „Kiste"** ergänzen:
  - `<select name="kiste_id">` mit einer Leer-Option („— keine Kiste —") + allen aus `kiste_alle()`. Optional Textfeld `fach`.
- **Nach dem Buchen**, wenn die neue `charge_id` feststeht (dort, wo heute schon der Blinker gebunden wird), zusätzlich:
  ```php
  $kiste_id = (int)($_POST['kiste_id'] ?? 0);
  if ($kiste_id > 0) kiste_charge_zuordnen($kiste_id, (int)$charge_id, trim((string)($_POST['fach'] ?? '')));
  ```
- Hinweis: Blinker-Pflicht wie bisher lassen. Kiste ist optional. Hat die Kiste einen Blinker (`kiste_blinker`), ist nichts weiter nötig – die Kiste blinkt beim Suchen.

## Aufgabe 2 – Kisten-Ansicht im Bestand (`?p=bestand`)
Ziel: Kisten sehen, **ausklappen** und den Inhalt sehen; oben ein **Filter „nur Kisten"**.

- Oben in `bestand/liste.php` eine **Checkbox/Umschalter „Nur Kisten anzeigen"** (z. B. Link/Query `?p=bestand&nur_kisten=1`, Zustand merken).
- Ist der Filter aktiv (oder generell als eigener Abschnitt oben): je Kiste aus `kiste_alle()` einen **ausklappbaren Block** rendern:
  ```php
  foreach (kiste_alle() as $k):
      $inhalt = kiste_inhalt((int)$k['id']);
  ?>
    <details class="…">
      <summary><?= h($k['name']) ?> · <?= count($inhalt) ?> Posten
        <?php if ($bl = kiste_blinker((int)$k['id'])): ?>
          <button type="button" class="btn btn-ghost btn-sm" data-klingeln="<?= (int)$bl['id'] ?>">Kiste finden</button>
        <?php endif; ?>
      </summary>
      <!-- Tabelle: Rohstoff/Produkt · Charge · MHD · Menge · (Fach), je Zeile Link ?p=charge&id=… -->
    </details>
  <?php endforeach; ?>
  ```
  - Für „Kiste finden" das bestehende `data-klingeln`-Muster + `assets/lager.js` nutzen (wie in `charge.php`).
  - Ist der Filter aus, bleibt die normale Bestandsliste wie bisher (nur den Kisten-Abschnitt davor/als Umschaltung zeigen).
- Design wie der Rest (`bx-*`-Klassen, `<details>` ggf. wie `.bx-sek` im Dashboard). Keine Emojis.

## Verifikation
- `php -l` auf geänderte Dateien.
- Einbuchen mit Kiste → Charge taucht in `?p=charge` als „in Kiste X" auf und im Bestand im Kisten-Block.
- `?p=bestand&nur_kisten=1` → nur Kisten, ausklappbar, Inhalt korrekt; „Kiste finden" löst den Blinker aus.
- Co-located `.md` (`wareneingang.md`/`eingang.md`, `liste.md`) aktualisieren.
- Nach jedem fertigen, getesteten Schritt: `git pull --rebase` + `git push` (nur `lager/ public/lager/`).

## Nicht verwechseln
`lager/module/led/api_blink.php` (interner Blink-Endpunkt) und die MHD-Korrektur auf `?p=charge` wurden vom Produktions-Chat ergänzt – bitte vorher einmal `git pull`, damit du auf dem aktuellen Stand baust.

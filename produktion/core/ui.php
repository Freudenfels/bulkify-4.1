<?php
// Kleine Anzeige-Helfer – dieselben Namen wie im Dashboard (h, fmt_zeit), damit nichts umzudenken ist.
function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function fmt_zeit(?string $utc, string $fmt = 'd.m.Y H:i'): string {
    if (!$utc) return '';
    try {
        $dt = new DateTime($utc, new DateTimeZone('UTC'));
        $dt->setTimezone(new DateTimeZone('Europe/Berlin'));
        return $dt->format($fmt);
    } catch (Exception $e) { return $utc; }
}
function menge_txt($m): string {
    if ($m === null || $m === '') return '';
    return rtrim(rtrim(number_format((float)$m, 3, ',', '.'), '0'), ',');
}
// Menge MIT Einheit, Schüttgut lesbar: g -> kg/t, ml -> L (600.000 g -> „600 kg"); Stück & Co. wie menge_txt + Einheit.
function menge_einh_txt($m, ?string $e): string {
    $e = (string)$e; $v = (float)$m;
    if ($e === 'g'  && abs($v) >= 1000000) return menge_txt($v / 1000000) . ' t';
    if ($e === 'g'  && abs($v) >= 1000)    return menge_txt($v / 1000) . ' kg';
    if ($e === 'ml' && abs($v) >= 1000)    return menge_txt($v / 1000) . ' L';
    return trim(menge_txt($m) . ' ' . $e);
}
function weiter(string $ziel): never { header('Location: ' . $ziel); exit; }
// Eine Dauer in Sekunden menschenlesbar machen (Sek/Min/Std/Tage). null -> „–".
function dauer_txt(?float $sek): string {
    if ($sek === null) return '–';
    $nz = fn(float $x) => rtrim(rtrim(number_format($x, 1, ',', '.'), '0'), ',');
    if ($sek < 60)        return round($sek) . ' Sek';
    if ($sek < 3600)      return $nz($sek / 60) . ' Min';
    if ($sek < 48 * 3600) return $nz($sek / 3600) . ' Std';
    return $nz($sek / 86400) . ' Tage';
}
function json_antwort(array $daten, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($daten, JSON_UNESCAPED_UNICODE); exit;
}

// Kurze Rückmeldung, überlebt genau eine Weiterleitung.
function flash(string $text, string $art = 'ok'): void { $_SESSION['pr_flash'] = [$text, $art]; }
function flash_zeigen(): void {
    if (empty($_SESSION['pr_flash'])) return;
    [$text, $art] = $_SESSION['pr_flash']; unset($_SESSION['pr_flash']);
    hinweis($text, $art);
}
function hinweis(string $text, string $art = 'ok'): void {
    echo '<div class="bx-panel ' . ($art === 'warn' ? 'warn' : 'badge-ok') . '" style="padding:12px 16px">' . h($text) . '</div>';
}
function seitenkopf(string $titel, string $unter = '', string $aktion = ''): void {
    echo '<div class="bx-head"><div><h1>' . h($titel) . '</h1>';
    if ($unter !== '') echo '<p class="bx-sub">' . h($unter) . '</p>';
    echo '</div>';
    if ($aktion !== '') echo '<div class="bx-row">' . $aktion . '</div>';
    echo '</div>';
}
// Kurze Arbeitsanweisung je Station (für den Produktionsmodus). Scan-Hinweise bewusst weggelassen –
// es gibt noch keine Etiketten/Barcodes; Material wird beim Abschließen nach FEFO abgebucht.
function station_anleitung_text(string $station): string {
    return match ($station) {
        'Rohstoffe bereitstellen'  => 'Benötigte Rohstoffe nach FEFO aus dem Lager holen und bereitstellen.',
        'Fertigware bereitstellen' => 'Zugekaufte fertige Bulkware bereitstellen.',
        'Mischen'                  => 'Rohstoffe gemäß Rezeptur gründlich und homogen mischen.',
        'Verkapselung'             => 'Kapseln befüllen.',
        'Tablettierung'            => 'Tabletten gemäß Vorgabe pressen.',
        'Softgel-Herstellung'      => 'Softgels herstellen.',
        'Stick-Abfüllung'          => 'Sticks abfüllen.',
        'Pulver-Abfüllung'         => 'Pulver abfüllen.',
        'Abfüllung'                => 'Produkt abfüllen.',
        'Verpacken'                => 'Produkt in die Verpackung abfüllen.',
        'Etikettieren'             => 'Alle Gebinde korrekt etikettieren (Charge, MHD, Kennzeichnung).',
        'Beipackzettel beilegen'   => 'Beipackzettel/Booklet beilegen.',
        'Umkarton'                 => 'Produkt in den Karton/die Umverpackung legen.',
        'Zwischenkontrolle'        => 'Zwischenkontrolle durchführen und das Kontrollgewicht erfassen.',
        'Rückstellmuster ziehen'   => 'Rückstellmuster und Labormuster ziehen und die Mengen erfassen.',
        'Qualitätsprüfung'         => 'Aussehen, Füllmenge, Dichtigkeit und Kennzeichnung prüfen.',
        'Produktions-Freigabe'     => 'Produktion kontrollieren und freigeben.',
        'Versand-Freigabe'         => 'Auftrag zum Versand freigeben.',
        default                    => '',
    };
}

// Welche Werte werden an einer Station erfasst? (Eigenproduktion-Datenerfassung.)
// Rückgabe: Liste [['feld','label','einheit'], …].
function pr_station_felder(string $station): array {
    return match ($station) {
        'Mischen'                => [['feld'=>'mischmenge', 'label'=>'Gemischte Menge', 'einheit'=>'kg']],
        'Zwischenkontrolle'      => [['feld'=>'kontrolle_gewicht', 'label'=>'Kontrollgewicht', 'einheit'=>'g']],
        // Rückstellmuster/Laborprobe werden auf der QS-Seite (?p=qs) erfasst, nicht als Schnellfeld.
        default                  => [],
    };
}

// Produktionsbereitschaft als Badge: ist der Auftrag produzierbar? (aus erp_pa_bereitschaft()['status']).
// Produkt-Zelle der schmalen Tabellen (Nico 08.11.2026): Produkt groß, darunter klein PA-Nr · Auftrag · Kunde.
// Spart die Spalten Nr. und Kunde – die Tabellen passen so ohne Querscrollen auf Tablet und Handy.
function pr_produkt_zelle(array $pa): string {
    $name = (string)($pa['produkt_name'] ?? '') ?: '–';
    $form = !empty($pa['form']) ? ' <span class="muted" style="font-size:12px;font-weight:400">· ' . h((string)$pa['form']) . '</span>' : '';
    $unter = array_filter([(string)($pa['nummer'] ?? ''), (string)($pa['auftrag_nr'] ?? ''), (string)($pa['kunde'] ?? '')]);
    return '<div class="pr-prod"><strong>' . h($name) . '</strong>' . $form
         . ($unter ? '<div class="muted pr-sub">' . h(implode(' · ', $unter)) . '</div>' : '') . '</div>';
}

function bereit_badge(string $s): string {
    return match ($s) {
        'bereit' => '<span class="badge badge-ok">produzierbar</span>',
        'wartet' => '<span class="badge badge-warn">wartet auf Material</span>',
        'laeuft' => '<span class="badge badge-info">in Produktion</span>',
        'fertig' => '<span class="badge badge-ok">abgeschlossen</span>',
        'vorbereitung' => '<span class="badge badge-err" title="Noch nicht zur Produktion freigegeben (Vor-Produktion im Dashboard)">gesperrt</span>',
        default  => '<span class="badge">' . h($s) . '</span>',
    };
}

// Status eines Produktionsauftrags als Badge. Werte wie im Dashboard: offen/laufend/erledigt
// (ältere Varianten in_arbeit/fertig bleiben der Robustheit halber abgedeckt).
function pa_badge(?string $s): string {
    return match ((string)$s) {
        'vorbereitung'        => '<span class="badge badge-err">in Vorbereitung</span>',
        'offen'               => '<span class="badge badge-warn">offen</span>',
        'laufend', 'in_arbeit' => '<span class="badge badge-info">in Arbeit</span>',
        'erledigt', 'fertig'   => '<span class="badge badge-ok">erledigt</span>',
        'abgebrochen'         => '<span class="badge badge-err">abgebrochen</span>',
        default               => '<span class="badge">' . h((string)$s) . '</span>',
    };
}
// ---------------------------------------------------------------------------------------------------------------------------
// Pflichtschritte der Werk-App (13.11.2026): EIN Baustein für Start, Chargenwechsel, Wiederanlauf, Teilmenge, Abschluss, Rückgabe.
// Jeder Pflichtschritt ist eine große Kachel über die volle Breite: Nummer links, Text, rechts der Zustand (grauer Kreis / grüner Haken).
//   $art 'bestaetigen': die ganze Kachel ist die Tippfläche (Tippen = erledigt, nochmal = zurück), sendet $name=1.
//   $art 'foto':        großer Knopf „Foto aufnehmen“ (öffnet die Kamera), Datei-Feld unsichtbar, danach Vorschaubild + „Neu aufnehmen“.
//   $art 'feld':        Kachel mit eigenem Feld ($o['feld'] = HTML, z. B. Auswahl Linie oder Menge); erledigt, sobald das Feld einen Wert hat.
// $o['zusatz'] = HTML unter dem Text (z. B. Anzahl Rückstellmuster). Der Hauptknopf des Formulars bekommt data-wp-haupt: er bleibt grau,
// bis alle Kacheln erledigt sind, darunter „Noch offen: 2, 3“. Der Server prüft unabhängig davon selbst.
function werk_pflicht(int $nr, string $art, string $name, string $text, array $T, array $o = []): string {
    $zustand = '<span class="wp-zustand" aria-hidden="true"><svg viewBox="0 0 32 32" class="wp-offen"><circle cx="16" cy="16" r="13" fill="none" stroke="currentColor" stroke-width="3"/></svg>'
             . '<svg viewBox="0 0 32 32" class="wp-ok"><circle cx="16" cy="16" r="15" fill="currentColor"/><path d="M9 16.5l5 5 9-10" fill="none" stroke="#fff" stroke-width="3.4" stroke-linecap="round" stroke-linejoin="round"/></svg></span>';
    $nrHtml = '<span class="wp-nr">' . $nr . '</span>';
    $zus = isset($o['zusatz']) ? '<span class="wp-zusatz">' . $o['zusatz'] . '</span>' : '';
    if ($art === 'bestaetigen') {
        return '<label class="wp-kachel" data-wp="' . $nr . '"><input type="checkbox" class="wp-in" name="' . h($name) . '" value="1">'
             . $nrHtml . '<span class="wp-text">' . h($text) . $zus . '</span>' . $zustand . '</label>';
    }
    if ($art === 'foto') {
        $kamera = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 8.5A1.5 1.5 0 015.5 7h2.2l1.4-2h5.8l1.4 2h2.2A1.5 1.5 0 0120 8.5v9a1.5 1.5 0 01-1.5 1.5h-13A1.5 1.5 0 014 17.5z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><circle cx="12" cy="13" r="3.6" fill="none" stroke="currentColor" stroke-width="1.8"/></svg>';
        return '<div class="wp-kachel wp-foto" data-wp="' . $nr . '">' . $nrHtml . '<span class="wp-text">' . h($text) . $zus
             . '<span class="wp-fotozeile"><img class="wp-bild" alt="" hidden>'
             . '<label class="btn wp-knopf">' . $kamera . '<span class="wp-knopf-t" data-neu="' . h($T['wp_photo_again'] ?? 'Neu aufnehmen') . '">' . h($T['wp_photo'] ?? 'Foto aufnehmen') . '</span>'
             . '<input type="file" class="wp-in" name="' . h($name) . '" accept="image/*" capture="environment" data-roh="1"></label></span></span>' . $zustand . '</div>';
    }
    return '<div class="wp-kachel wp-feld" data-wp="' . $nr . '">' . $nrHtml . '<span class="wp-text">' . h($text) . '<span class="wp-feldzeile">' . ($o['feld'] ?? '') . '</span>' . $zus . '</span>' . $zustand . '</div>';
}
// CSS + Skript des Bausteins – einmal je Seite ausgeben.
function werk_pflicht_assets(array $T): string {
    static $da = false; if ($da) return ''; $da = true;
    $offen = json_encode($T['wp_open'] ?? 'Noch offen: %s', JSON_UNESCAPED_UNICODE);
    return '<style>
  .wp-kachel{position:relative;display:flex;align-items:center;gap:16px;width:100%;box-sizing:border-box;min-height:76px;margin:10px 0;padding:14px 16px;border:2px solid var(--line);border-radius:16px;background:var(--panel2,rgba(255,255,255,.03));font-size:20px;line-height:1.35;color:var(--text);user-select:none}
  label.wp-kachel{cursor:pointer}
  .wp-kachel .wp-in{position:absolute;opacity:0;width:1px;height:1px;overflow:hidden;pointer-events:none}
  .wp-knopf{position:relative;white-space:normal;overflow-wrap:normal;word-break:normal;text-align:left;max-width:100%;box-sizing:border-box}
  .wp-nr{flex:none;width:44px;height:44px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:22px;font-weight:800;background:var(--line);color:var(--text)}
  .wp-text{flex:1;min-width:0;overflow-wrap:anywhere}
  .wp-zusatz{display:block;margin-top:8px;font-size:17px}
  .wp-zusatz input,.wp-feldzeile input,.wp-feldzeile select{font-size:22px;min-height:56px;padding:10px 14px;border-radius:12px;border:1px solid var(--line);background:var(--panel2);color:var(--text);max-width:100%;box-sizing:border-box}
  .wp-feldzeile{display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin-top:10px}
  .wp-zustand{flex:none;width:40px;height:40px;color:#8a9387}
  .wp-zustand svg{width:100%;height:100%;display:block}
  .wp-zustand .wp-ok{display:none;color:#1D9E75}
  .wp-kachel.wp-erledigt{border-color:#1D9E75;background:rgba(29,158,117,.12)}
  .wp-kachel.wp-erledigt .wp-nr{background:#1D9E75;color:#fff}
  .wp-kachel.wp-erledigt .wp-offen{display:none}
  .wp-kachel.wp-erledigt .wp-ok{display:block}
  .wp-fotozeile{display:flex;flex-wrap:wrap;gap:12px;align-items:center;margin-top:10px}
  .wp-knopf{display:inline-flex;align-items:center;justify-content:center;gap:10px;min-height:60px;padding:12px 22px;font-size:21px;font-weight:700;border-radius:14px;background:var(--lime,#C0F24E);color:#15230a;border:0;cursor:pointer}
  .wp-kachel.wp-erledigt .wp-knopf{background:transparent;color:var(--text);border:2px solid var(--line)}
  .wp-knopf svg{width:30px;height:30px;flex:none}
  .wp-bild{width:72px;height:72px;object-fit:cover;border-radius:10px;border:2px solid #1D9E75}
  .btn[data-wp-haupt].wp-gesperrt{background:#59615a !important;color:#d4d8d2 !important;opacity:1;cursor:not-allowed}
  .wp-offen-hinweis{font-size:17px;margin-top:8px;color:var(--muted,#9aa39a)}
  @media (max-width:520px){ .wp-kachel{gap:12px;padding:12px;font-size:18px} .wp-nr{width:38px;height:38px;font-size:19px} .wp-zustand{width:34px;height:34px} .wp-knopf{width:100%;padding:12px 14px;font-size:19px;gap:8px} .wp-knopf svg{width:26px;height:26px} }
</style>
<script>
(function(){
  var OFFEN = ' . $offen . ';
  function erledigt(k){
    var i = k.querySelector(".wp-in");
    if (i) return i.type === "checkbox" ? i.checked : !!(i.files && i.files.length);
    var f = k.querySelectorAll(".wp-feldzeile input:not([type=hidden]),.wp-feldzeile select");
    if (!f.length) return true;
    return Array.prototype.every.call(f, function(e){ return String(e.value || "").trim() !== ""; });
  }
  function pruefen(form){
    var offen = [];
    Array.prototype.forEach.call(form.querySelectorAll(".wp-kachel"), function(k){ var ok = erledigt(k); k.classList.toggle("wp-erledigt", ok); if (!ok) offen.push(k.getAttribute("data-wp")); });
    var b = form.querySelector("[data-wp-haupt]"); if (!b) return;
    b.disabled = offen.length > 0; b.classList.toggle("wp-gesperrt", offen.length > 0);
    var h = form.querySelector(".wp-offen-hinweis");
    if (!h) { h = document.createElement("div"); h.className = "wp-offen-hinweis"; b.parentNode.insertBefore(h, b.nextSibling); }
    h.textContent = offen.length ? OFFEN.replace("%s", offen.join(", ")) : ""; h.hidden = !offen.length;
  }
  function start(){
    Array.prototype.forEach.call(document.querySelectorAll("form"), function(form){
      if (!form.querySelector(".wp-kachel")) return;
      form.addEventListener("change", function(e){
        var i = e.target;
        if (i.classList && i.classList.contains("wp-in") && i.type === "file" && i.files && i.files[0]) {
          var k = i.closest(".wp-kachel"), img = k.querySelector(".wp-bild"), t = k.querySelector(".wp-knopf-t");
          var r = new FileReader(); r.onload = function(){ img.src = r.result; img.hidden = false; }; r.readAsDataURL(i.files[0]);
          if (t && t.getAttribute("data-neu")) t.textContent = t.getAttribute("data-neu");
        }
        pruefen(form);
      });
      form.addEventListener("input", function(){ pruefen(form); });
      form.addEventListener("submit", function(e){ pruefen(form); var b = form.querySelector("[data-wp-haupt]"); if (b && b.disabled) e.preventDefault(); });
      pruefen(form);
    });
  }
  if (document.readyState !== "loading") start(); else document.addEventListener("DOMContentLoaded", start);
})();
</script>';
}

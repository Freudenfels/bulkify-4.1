<?php
// Sender und Bruecke (nur Admin): Sender anlegen/aendern, Status der Bruecke, Protokoll.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aktion = (string)($_POST['aktion'] ?? '');
    $id = (int)($_POST['id'] ?? 0);

    if ($aktion === 'speichern') {
        $name = trim((string)($_POST['name'] ?? ''));
        $weg = (string)($_POST['weg'] ?? 'bruecke');
        if (!isset(led_wege()[$weg])) $weg = 'bruecke';
        $ip = trim((string)($_POST['ip'] ?? '')) ?: null;
        $sn = trim((string)($_POST['sn'] ?? '')) ?: null;
        $aktiv = isset($_POST['aktiv']) ? 1 : 0;
        if ($name === '') {
            flash('Bitte einen Namen angeben.', 'warn');
        } elseif ($ip !== null && !preg_match('/^[0-9A-Za-z.\-]+(:\d+)?$/', $ip)) {
            flash('Die IP-Adresse sieht nicht richtig aus (z. B. 192.168.110.9).', 'warn');
        } elseif ($id) {
            q("UPDATE lg_sender SET name=?, weg=?, ip=?, sn=?, aktiv=? WHERE id=?", [$name, $weg, $ip, $sn, $aktiv, $id]);
            flash('Sender gespeichert.');
        } else {
            q("INSERT INTO lg_sender (name, weg, ip, sn, aktiv, angelegt) VALUES (?,?,?,?,?,?)",
              [$name, $weg, $ip, $sn, $aktiv, jetzt_utc()]);
            flash('Sender angelegt.');
        }
        weiter('?p=sender');
    }

    if ($aktion === 'loeschen' && $id) {
        $n = (int)scalar("SELECT COUNT(*) FROM lg_platz WHERE sender_id=?", [$id]);
        if ($n > 0) {
            flash('Dieser Sender ist noch an ' . $n . ' Plätzen eingetragen. Bitte dort zuerst umstellen.', 'warn');
        } else {
            q("DELETE FROM lg_sender WHERE id=? LIMIT 1", [$id]);
            flash('Sender gelöscht.');
        }
        weiter('?p=sender');
    }

    if ($aktion === 'token_neu') {
        lg_bruecke_token(true);
        flash('Neuer Schlüssel erzeugt. Das Brückenprogramm im Lager muss neu heruntergeladen werden.');
        weiter('?p=sender');
    }
}

$sender = led_sender_alle();
$bearbeiten = isset($_GET['id']) ? led_sender((int)$_GET['id']) : null;
$zuletzt = lg_meta_lesen('bruecke_zuletzt', '');
$wach = $zuletzt !== '' && (time() - strtotime($zuletzt . ' UTC')) < 15;
$braucht_bruecke = (bool)array_filter($sender, fn($s) => $s['weg'] === 'bruecke');
$protokoll = all("SELECT b.*, p.bereich, p.regal, p.ebene, p.fach FROM lg_befehl b
                  LEFT JOIN lg_platz p ON p.id = b.platz_id ORDER BY b.id DESC LIMIT 30");

kopf('Sender und Brücke', 'sender');
seitenkopf('Sender und Brücke', 'Wie die Leuchtbefehle zu den Leisten kommen');
flash_zeigen();
?>

<?php if ($sender): ?>
<div class="bx-tablewrap" style="margin-bottom:var(--sp-5)">
  <table class="bx-table">
    <thead><tr><th>Name</th><th>Weg</th><th>IP im Lager</th><th>Seriennummer</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($sender as $s): ?>
      <tr>
        <td><?= h((string)$s['name']) ?></td>
        <td><?= h(led_wege()[$s['weg']] ?? $s['weg']) ?></td>
        <td class="lg-code"><?= h((string)$s['ip']) ?></td>
        <td class="lg-code"><?= h((string)$s['sn']) ?></td>
        <td><?= (int)$s['aktiv'] ? '<span class="badge badge-ok">aktiv</span>' : '<span class="badge">aus</span>' ?></td>
        <td style="text-align:right"><a class="btn btn-ghost btn-sm" href="?p=sender&id=<?= (int)$s['id'] ?>">Bearbeiten</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<form method="post" class="bx-panel">
  <input type="hidden" name="aktion" value="speichern">
  <input type="hidden" name="id" value="<?= (int)($bearbeiten['id'] ?? 0) ?>">
  <h2><?= $bearbeiten ? 'Sender bearbeiten' : 'Sender anlegen' ?></h2>
  <div class="bx-grid">
    <div class="bx-field"><label>Name</label><input name="name" value="<?= h((string)($bearbeiten['name'] ?? ($sender ? '' : 'Sender Lager'))) ?>" required></div>
    <div class="bx-field"><label>Weg</label>
      <select name="weg">
        <?php foreach (led_wege() as $k => $t): ?>
          <option value="<?= h($k) ?>" <?= ($bearbeiten['weg'] ?? 'bruecke') === $k ? 'selected' : '' ?>><?= h($t) ?></option>
        <?php endforeach; ?>
      </select></div>
    <div class="bx-field"><label>IP im Lager-Netz</label><input name="ip" class="lg-code" value="<?= h((string)($bearbeiten['ip'] ?? '')) ?>" placeholder="192.168.110.9"></div>
    <div class="bx-field"><label>Seriennummer (nur Cloud)</label><input name="sn" class="lg-code" value="<?= h((string)($bearbeiten['sn'] ?? '')) ?>"></div>
  </div>
  <label class="bx-check" style="margin-bottom:var(--sp-4)"><input type="checkbox" name="aktiv" <?= !$bearbeiten || (int)$bearbeiten['aktiv'] ? 'checked' : '' ?>> aktiv</label>
  <div class="bx-row" style="gap:var(--sp-3)">
    <button class="btn btn-primary" type="submit">Speichern</button>
    <?php if ($bearbeiten): ?><a class="btn btn-ghost" href="?p=sender">Abbrechen</a><?php endif; ?>
  </div>
</form>
<?php if ($bearbeiten): ?>
<form method="post" onsubmit="return confirm('Sender wirklich löschen?')" style="margin:-8px 0 var(--sp-5)">
  <input type="hidden" name="aktion" value="loeschen"><input type="hidden" name="id" value="<?= (int)$bearbeiten['id'] ?>">
  <button class="btn btn-danger btn-sm" type="submit">Sender löschen</button>
</form>
<?php endif; ?>

<div class="bx-panel">
  <h2>Brücke im Lager</h2>
  <p>
    Status:
    <?php if ($wach): ?><span class="badge badge-ok">läuft</span> <span class="muted">zuletzt gemeldet <?= h(vor_wann($zuletzt)) ?></span>
    <?php else: ?><span class="badge badge-warn">meldet sich nicht</span> <span class="muted">zuletzt <?= h(vor_wann($zuletzt ?: null)) ?></span><?php endif; ?>
  </p>
  <p class="muted">Der Server kann den Sender im Lager nicht direkt erreichen. Die Brücke ist ein kleines Programm auf einem
    Windows-PC im Lager, der im selben Netz wie der Sender hängt. Sie fragt jede Sekunde hier nach, ob etwas leuchten soll,
    und gibt es an den Sender weiter.<?= $braucht_bruecke ? '' : ' Wird nur für Sender mit dem Weg „Über die Brücke“ gebraucht.' ?></p>
  <ol class="lg-schritte">
    <li>Sender per USB-Stick auf eine feste IP stellen (Datei <code>network.conf</code>, siehe Hersteller-Doku) und die IP oben eintragen.</li>
    <li>Auf dem Lager-PC das Brückenprogramm herunterladen. Schlüssel und Adresse sind schon eingetragen.</li>
    <li>Rechtsklick auf die Datei, „Mit PowerShell ausführen“. Das Fenster offen lassen.</li>
    <li>Damit sie nach einem Neustart von selbst läuft: eine Verknüpfung in den Autostart-Ordner legen (<code>shell:startup</code>).</li>
  </ol>
  <div class="bx-row" style="gap:var(--sp-3);margin-top:var(--sp-4)">
    <a class="btn btn-primary" href="?p=bruecke_skript">Brückenprogramm herunterladen</a>
    <form method="post" onsubmit="return confirm('Neuen Schlüssel erzeugen? Die laufende Brücke hört dann auf zu arbeiten, bis sie neu heruntergeladen ist.')">
      <input type="hidden" name="aktion" value="token_neu">
      <button class="btn btn-ghost" type="submit">Neuen Schlüssel erzeugen</button>
    </form>
  </div>
</div>

<div class="bx-panel">
  <h2>Hersteller-Cloud</h2>
  <p><?= led_cloud_bereit()
        ? '<span class="badge badge-ok">Zugang eingetragen</span>'
        : '<span class="badge">nicht eingerichtet</span>' ?></p>
  <p class="muted">Nur nötig, wenn ein Sender über die Cloud (4G) laufen soll. Der Zugang steht in der
    <code>secrets.php</code> auf dem Server: <code>LG_CLOUD_URL</code>, <code>LG_CLOUD_APP_ID</code>, <code>LG_CLOUD_SECRET</code>.
    Adresse und Zugang kommen vom Hersteller.</p>
</div>

<?php if ($protokoll): ?>
<h2>Protokoll</h2>
<div class="bx-tablewrap" style="margin-bottom:var(--sp-6)">
  <table class="bx-table">
    <thead><tr><th>Zeit</th><th>Platz</th><th>Leiste</th><th>Farbe</th><th>Status</th><th>Antwort</th></tr></thead>
    <tbody>
    <?php foreach ($protokoll as $b): $f = led_farben()[$b['farbe']] ?? null; ?>
      <tr>
        <td><?= h(fmt_zeit((string)$b['angelegt'], 'd.m. H:i:s')) ?></td>
        <td class="lg-code"><?= $b['bereich'] !== null ? h(platz_code($b)) : '' ?></td>
        <td class="lg-code"><?= h((string)$b['leiste']) ?></td>
        <td><?= $f ? '<span class="lg-punkt" style="background:' . h($f[3]) . '"></span>' . h($f[2]) : 'aus' ?></td>
        <td><?= h((string)$b['status']) ?></td>
        <td class="muted" style="white-space:normal;max-width:360px"><?= h(mb_substr((string)$b['antwort'], 0, 120)) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>
<?php
fuss();

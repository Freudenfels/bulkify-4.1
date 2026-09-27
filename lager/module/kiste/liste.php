<?php
// Kisten: Behaelter mit einem Blinker, in denen viele Chargen liegen.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'anlegen') {
    $name = trim((string)($_POST['name'] ?? ''));
    if ($name === '') { flash('Bitte einen Namen für die Kiste angeben.', 'warn'); weiter('?p=kisten'); }
    $id = kiste_anlegen($name, trim((string)($_POST['notiz'] ?? '')));
    flash('Kiste angelegt.');
    weiter('?p=kiste&id=' . $id);
}

$kisten = kiste_alle();

kopf('Kisten', 'kisten');
seitenkopf('Kisten', count($kisten) . ' Kisten', '');
flash_zeigen();
?>
<p class="muted" style="margin-top:-6px">Eine Kiste bekommt einen Blinker und fasst viele verschiedene Chargen. Du suchst ein Produkt, die Kiste blinkt – so muss nicht an jedes Kleinteil ein Blinker.</p>

<?php if ($kisten): ?>
<div class="bx-tablewrap" style="margin-bottom:var(--sp-5)">
  <table class="bx-table">
    <thead><tr><th>Kiste</th><th>Blinker</th><th>Inhalt</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($kisten as $k): ?>
      <tr onclick="location.href='?p=kiste&id=<?= (int)$k['id'] ?>'" style="cursor:pointer">
        <td><strong><?= h((string)$k['name']) ?></strong><?= $k['notiz'] ? ' <span class="muted">' . h((string)$k['notiz']) . '</span>' : '' ?></td>
        <td class="lg-code"><?= $k['blinker'] ? h((string)$k['blinker']) : '<span class="muted">keiner</span>' ?></td>
        <td><?= (int)$k['anzahl'] ?> <?= (int)$k['anzahl'] === 1 ? 'Charge' : 'Chargen' ?></td>
        <td style="text-align:right"><?= $k['leiste_id'] ? '<button type="button" class="btn btn-ghost btn-sm" data-klingeln="' . (int)$k['leiste_id'] . '" onclick="event.stopPropagation()">Finden</button>' : '' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<details class="bx-panel" <?= $kisten ? '' : 'open' ?>>
  <summary style="cursor:pointer"><span style="font-size:var(--fs-md)">Kiste anlegen</span></summary>
  <form method="post" style="margin-top:var(--sp-4)">
    <input type="hidden" name="aktion" value="anlegen">
    <div class="bx-grid">
      <div class="bx-field"><label>Name</label><input name="name" placeholder="z. B. Kiste 3 oder Kleinteile A" required></div>
      <div class="bx-field"><label>Notiz</label><input name="notiz" placeholder="optional"></div>
    </div>
    <button class="btn btn-primary" type="submit">Anlegen</button>
  </form>
</details>
<?php
fuss();

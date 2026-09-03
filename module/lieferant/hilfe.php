<?php
// Lieferantenportal – „Anleitung". Route: ?p=lieferant_hilfe
// Erklaert einem neuen Lieferanten in seiner Sprache, was er hier tun kann. Kein Handbuch:
// jeder Abschnitt ist genau der Reihe nach das, was er im Menue daneben findet.
// Die Texte stehen hier direkt (nicht in lp_t), weil sie nur auf dieser Seite vorkommen.
require_once BX_ROOT . '/module/lieferant/portal_layout.php';
if (!ist_lieferant()) { header('Location: ?p=lieferant_login'); exit; }

$spr = lp_sprache();
$basis = rtrim((string)(function_exists('mail_basis_url') ? mail_basis_url() : ''), '/');

// [Ueberschrift, Einleitung, [Schritte]] je Abschnitt.
$T = [
'de' => [
  'titel' => 'Anleitung',
  'sub'   => 'So arbeiten Sie mit dem Lieferantenportal – in fünf Minuten erklärt.',
  'abschnitte' => [
    ['Anmelden', 'Sie haben von uns eine Einladung per E-Mail bekommen und dabei ein eigenes Passwort gesetzt.', [
      'Adresse: ' . ($basis !== '' ? $basis . '/?p=lieferant_login' : '?p=lieferant_login'),
      'Passwort vergessen? Schreiben Sie uns – wir schicken eine neue Einladung.',
      'Ganz unten links stellen Sie die Sprache um: Deutsch, English, 中文.',
    ]],
    ['Übersicht', 'Die Startseite zeigt, was gerade auf Sie wartet: offene Bestellungen, noch nicht bestätigte Bestellungen und offene Anfragen.', []],
    ['Anfragen beantworten', 'Wir fragen einen Preis an – Sie tragen ihn ein. Das ist der wichtigste Teil.', [
      'Anfrage öffnen. Oben steht, was wir brauchen: Artikel, Menge und in welcher Einheit wir den Preis wollen.',
      'Preis eintragen – in genau der Einheit, die dort steht (z. B. je Kapsel oder je kg).',
      'Mindestmenge (MOQ) und Lieferzeit in Tagen ergänzen.',
      'Andere Mengen, anderer Preis? Auf „+ Staffel" tippen und weitere Zeilen anlegen. Freiwillig – ohne Staffel gilt Ihr Preis für die angefragte Menge.',
      'Spezifikation oder CoA können Sie direkt an der Anfrage hochladen. Das hilft uns sehr und spart eine Rückfrage.',
      'Absenden. Ihr Preis liegt danach bei uns – Sie sehen ihn in der Anfrage weiterhin.',
    ]],
    ['Bestellungen', 'Aus einem Preis wird irgendwann eine Bestellung.', [
      'Bestellung öffnen und mit einem Klick bestätigen. Damit wissen wir, dass sie angekommen ist.',
      'Den Fortschritt pflegen: in Produktion, versandbereit, versendet. So müssen wir nicht nachfragen.',
      'Voraussichtliches Lieferdatum eintragen, sobald Sie es kennen. Ändert sich etwas, hier ändern.',
      'Die Bestellung können Sie sich als PDF herunterladen.',
    ]],
    ['Mein Katalog', 'Zeigen Sie uns, was Sie sonst noch anbieten – dann fragen wir Sie von selbst an.', [
      'Preisliste hochladen: PDF, Excel oder CSV. Wir lesen sie automatisch aus und machen Zeilen daraus.',
      'Oder einzelne Positionen von Hand eintragen: Name, Form, Spezifikation, Preis, ab welcher Menge.',
      'Wichtig: Daraus entsteht bei uns nicht automatisch ein Artikel. Unser Team schaut jede Zeile an.',
    ]],
    ['Dateien', 'Ihre Unterlagen an einem Ort – Spezifikationen, Analysenzertifikate, Zertifikate, Preislisten.', [
      'Hochladen, benennen, fertig. Wir sehen dieselben Dateien.',
      'Zu einem Rohstoff gehörende Spezifikationen laden Sie am besten direkt an der Anfrage hoch.',
    ]],
    ['Rückfragen', 'Kurze Fragen ohne E-Mail-Verkehr.', [
      'Schreiben Sie uns hier – mit Bezug zur Bestellung oder Anfrage, dann ist sofort klar, worum es geht.',
      'Neue Antworten stehen als Zahl im Menü.',
    ]],
    ['Meine Daten', 'Adresse, Ansprechpartner, Sprache, Zahlungsbedingungen.', [
      'Bitte aktuell halten – wir nutzen genau diese Angaben für Bestellungen und Rechnungen.',
    ]],
    ['Aufs Handy legen', 'Das Portal läuft auch als App auf dem Startbildschirm – ohne Play Store.', [
      'Android/Chrome: Menü (drei Punkte) → „App installieren".',
      'iPhone/Safari: Teilen-Symbol → „Zum Home-Bildschirm".',
      'Danach eigenes Icon, keine Browserleiste. Es sind dieselben Daten wie am Rechner.',
    ]],
  ],
  'frage' => 'Etwas unklar? Schreiben Sie uns unter „Rückfragen" – wir antworten dort.',
],
'en' => [
  'titel' => 'Guide',
  'sub'   => 'How to work with the supplier portal – explained in five minutes.',
  'abschnitte' => [
    ['Signing in', 'You received an invitation by e-mail and set your own password.', [
      'Address: ' . ($basis !== '' ? $basis . '/?p=lieferant_login' : '?p=lieferant_login'),
      'Forgot your password? Just tell us – we will send a new invitation.',
      'Bottom left you switch the language: Deutsch, English, 中文.',
    ]],
    ['Overview', 'The start page shows what is waiting for you: open orders, orders not yet confirmed, open requests.', []],
    ['Answering requests', 'We ask for a price – you enter it. This is the important part.', [
      'Open the request. At the top you see what we need: item, quantity and the unit we want the price in.',
      'Enter the price – in exactly that unit (for example per capsule or per kg).',
      'Add your minimum order quantity (MOQ) and the lead time in days.',
      'Different quantity, different price? Tap “+ Tier” and add more rows. Optional – without tiers your price applies to the quantity we asked for.',
      'You can upload a specification or CoA right there. That helps us a lot and saves a follow-up question.',
      'Submit. Your price is then with us – and stays visible to you in the request.',
    ]],
    ['Orders', 'Sooner or later a price becomes an order.', [
      'Open the order and confirm it with one click. That tells us it arrived.',
      'Keep the progress up to date: in production, ready to ship, shipped. Then we do not have to ask.',
      'Enter the expected delivery date as soon as you know it, and change it here if it moves.',
      'You can download the order as a PDF.',
    ]],
    ['My catalogue', 'Show us what else you offer – then we come to you on our own.', [
      'Upload a price list: PDF, Excel or CSV. We read it automatically and turn it into rows.',
      'Or enter single items by hand: name, form, specification, price, minimum quantity.',
      'Important: this does not create an article on our side automatically. Our team reviews every row.',
    ]],
    ['Files', 'Your documents in one place – specifications, certificates of analysis, certificates, price lists.', [
      'Upload, name it, done. We see the same files.',
      'Specifications belonging to one raw material are best uploaded directly in the request.',
    ]],
    ['Questions and answers', 'Short questions without e-mail ping-pong.', [
      'Write to us here – linked to an order or request, so it is clear what it is about.',
      'New answers show up as a number in the menu.',
    ]],
    ['My details', 'Address, contact person, language, payment terms.', [
      'Please keep this current – we use exactly these details for orders and invoices.',
    ]],
    ['Put it on your phone', 'The portal also runs as an app on your home screen – no app store needed.', [
      'Android/Chrome: menu (three dots) → “Install app”.',
      'iPhone/Safari: share icon → “Add to Home Screen”.',
      'You then get its own icon and no browser bar. Same data as on the computer.',
    ]],
  ],
  'frage' => 'Anything unclear? Write to us under “Questions and answers” – we reply there.',
],
'zh' => [
  'titel' => '使用说明',
  'sub'   => '五分钟了解供应商门户的使用方法。',
  'abschnitte' => [
    ['登录', '您已收到我们的邮件邀请，并设置了自己的密码。', [
      '网址：' . ($basis !== '' ? $basis . '/?p=lieferant_login' : '?p=lieferant_login'),
      '忘记密码？请联系我们，我们会重新发送邀请。',
      '左下角可切换语言：Deutsch、English、中文。',
    ]],
    ['概览', '首页显示需要您处理的事项：未完成订单、待确认订单、待处理询价。', []],
    ['答复询价', '我们询价，您填写价格。这是最重要的部分。', [
      '打开询价单。顶部写明我们需要的内容：物料、数量，以及我们希望的报价单位。',
      '按照写明的单位填写价格（例如每粒胶囊或每公斤）。',
      '填写最小起订量（MOQ）和交货周期（天）。',
      '不同数量价格不同？点击“+ 阶梯价”添加更多行。此项可选；不填时，您的价格适用于我们询问的数量。',
      '您可以直接在询价单中上传规格书或分析证书（CoA）。这对我们帮助很大，也省去一次追问。',
      '提交后价格即传送给我们，您在询价单中仍可看到。',
    ]],
    ['订单', '价格确认后就会变成订单。', [
      '打开订单并一键确认，我们即知道订单已送达。',
      '及时更新进度：生产中、待发货、已发货。这样我们就不必追问。',
      '知道预计交货日期后请填写；如有变动，也在此修改。',
      '订单可下载为 PDF。',
    ]],
    ['我的产品目录', '告诉我们您还能提供什么，我们会主动向您询价。', [
      '上传价格表：PDF、Excel 或 CSV。我们会自动读取并整理成条目。',
      '也可以手工填写单条：名称、形态、规格、价格、起订量。',
      '请注意：这不会自动在我们系统中生成物料，我们的团队会逐条审核。',
    ]],
    ['文件', '您的资料集中存放：规格书、分析证书、认证、价格表。', [
      '上传并命名即可，我们看到的是同一批文件。',
      '与某个原料相关的规格书，最好直接在对应的询价单中上传。',
    ]],
    ['留言与答复', '简短问题，无需往返邮件。', [
      '请在此处留言，并关联订单或询价单，事由一目了然。',
      '新的答复会以数字显示在菜单中。',
    ]],
    ['我的资料', '地址、联系人、语言、付款条件。', [
      '请保持最新：订单和发票都使用这些信息。',
    ]],
    ['添加到手机桌面', '本门户也可作为应用放在手机桌面，无需应用商店。', [
      'Android/Chrome：菜单（三个点）→“安装应用”。',
      'iPhone/Safari：分享图标 →“添加到主屏幕”。',
      '之后会有独立图标、无浏览器地址栏，数据与电脑端完全一致。',
    ]],
  ],
  'frage' => '还有不清楚的地方？请在“留言与答复”中告诉我们，我们会在那里回复。',
],
];
$t = $T[$spr] ?? $T['de'];

lp_head('bulkify – ' . $t['titel']);
lp_shell_start('lieferant_hilfe');
?>
<h1 style="margin-bottom:4px"><?= h($t['titel']) ?></h1>
<p class="muted" style="margin-top:0"><?= h($t['sub']) ?></p>

<?php $nr = 0; foreach ($t['abschnitte'] as [$titel, $einleitung, $schritte]): $nr++; ?>
  <div class="bx-panel">
    <h2 style="margin-top:0"><?= $nr ?>. <?= h($titel) ?></h2>
    <p class="muted" style="margin:0 0 <?= $schritte ? '10px' : '0' ?>"><?= h($einleitung) ?></p>
    <?php if ($schritte): ?>
      <ul style="margin:0;padding-left:20px;line-height:1.9"><?php foreach ($schritte as $s): ?><li><?= h($s) ?></li><?php endforeach; ?></ul>
    <?php endif; ?>
  </div>
<?php endforeach; ?>

<div class="bx-panel" style="border-color:var(--gruen)">
  <?= h($t['frage']) ?>
</div>
<?php
lp_shell_ende();
lp_foot();

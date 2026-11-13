<?php
// Lieferantenportal – „Anleitung". Route: ?p=lieferant_hilfe
// Erklaert einem neuen Lieferanten in seiner Sprache, was er hier tun kann. Kein Handbuch:
// jeder Abschnitt ist genau der Reihe nach das, was er im Menue daneben findet.
// Die Texte stehen hier direkt (nicht in lp_t), weil sie nur auf dieser Seite vorkommen.
require_once BX_ROOT . '/module/lieferant/portal_layout.php';
if (!ist_lieferant()) { header('Location: ?p=lieferant_login'); exit; }

$spr = lp_sprache();
$basis = rtrim((string)(function_exists('mail_basis_url') ? mail_basis_url() : ''), '/');

// [Icon, Überschrift, Einleitung, [Schritte]] je Abschnitt. Stand 13.11.2026 (Fable-Prüfung 5, Anhang E): Fehlende Unterlagen,
// Bestellung bestätigen mit Termin und Name, Chargen und CoA je Charge, Aufkleber, Versanddaten, Rechnung und Zahlungsstatus,
// Bankdaten, „Preis gültig bis“, Rohstoff- und Fertigprodukt-Preise. Icons aus bx_nav_icon().
$login = $basis !== '' ? $basis . '/?p=lieferant_login' : '?p=lieferant_login';
$T = [
'de' => [
  'titel' => 'Anleitung',
  'sub'   => 'So arbeiten Sie mit dem Lieferantenportal – Schritt für Schritt.',
  'abschnitte' => [
    ['person', 'Anmelden und Sprache', 'Sie haben von uns eine Einladung per E-Mail bekommen und dabei ein eigenes Passwort gesetzt.', [
      'Adresse: ' . $login,
      'Passwort vergessen? Schreiben Sie uns – wir schicken eine neue Einladung.',
      'Ganz unten links stellen Sie die Sprache um: DE, EN, 中文.',
    ]],
    ['apps', 'Übersicht', 'Die Startseite zeigt, was auf Sie wartet: offene und noch nicht bestätigte Bestellungen, offene Anfragen, neue Rückfragen – und einen gelben Hinweis, wenn Unterlagen fehlen. Zahlen am Menü zeigen, wo etwas offen ist.', []],
    ['rohstoff_unterlagen', 'Fehlende Unterlagen', 'Zu jedem Rohstoff brauchen wir eine CoA oder eine Spezifikation, bei Fertigprodukten zusätzlich die Spezifikation des Gesamtprodukts.', [
      'Unter „Fehlende Unterlagen“ steht alles, was noch fehlt – gruppiert nach Artikel. Die Zahl im Menü zeigt, wie viele es sind.',
      '„Hochladen“ tippen, Datei wählen, bei Bedarf Ablaufdatum und Bemerkung eintragen. Erledigte Punkte verschwinden.',
      'Solange Unterlagen fehlen, können wir nicht bestellen bzw. bleibt gelieferte Ware bei uns gesperrt.',
    ]],
    ['anfragen', 'Anfragen beantworten', 'Wir fragen einen Preis an – Sie tragen ihn ein.', [
      'Anfrage öffnen. Oben steht, was wir brauchen: Artikel, Menge und die Einheit für den Preis (z. B. je kg).',
      'Preis in genau dieser Einheit eintragen, dazu Mindestmenge (MOQ) und Lieferzeit in Tagen.',
      'Ihre genaue Variante eintragen, z. B. „Magnesiumbisglycinat, 13 % Magnesium“.',
      'Andere Mengen, anderer Preis? „+ Staffel“ tippen und weitere Zeilen anlegen.',
      '„Preis gültig bis“ eintragen. Bei Rohstoffen mit schwankendem Preis ist das Datum Pflicht; leer heißt unbegrenzt.',
      'CoA oder Spezifikation direkt an der Anfrage hochladen. Ohne Unterlagen können Sie den Preis abgeben, wir können aber nicht bestellen.',
      'Mit „Angebot abgeben“ senden. Bis wir annehmen, ändern Sie über „Angebot anpassen“.',
    ]],
    ['auftraege', 'Bestellung bestätigen', 'Aus Ihrem Preis wird eine Bestellung. Die Zahl am Menü zeigt, wie viele noch zu bestätigen sind.', [
      'Bestellung öffnen, den geplanten Liefertermin und Ihren Namen eintragen, „Bestellung bestätigen“.',
      'Verschiebt sich der Termin später, schreiben Sie uns an der Bestellung eine Rückfrage – ändern kann ihn dann nur unser Team.',
      'Die Bestellung gibt es als PDF.',
    ]],
    ['produktion', 'Fortschritt pflegen', 'Bitte die Stationen der Reihe nach setzen, dann müssen wir nicht nachfragen.', [
      'In Produktion → Qualitätsprüfung → versandbereit → versendet. „Auftrag angenommen“ kommt mit der Bestätigung, den Wareneingang setzen wir.',
      'Die Leiste oben an der Bestellung zeigt: erledigt (grün), aktuell (hervorgehoben), offen (grau).',
    ]],
    ['angebote', 'Chargen und Dokumente', 'Je Position tragen Sie die Charge ein, bevor die Ware das Haus verlässt.', [
      'Unter „Chargen & Dokumente“ je Position Charge, MHD und Menge eintragen – oder die CoA hochladen, dann lesen wir die Charge aus.',
      'Je Charge eine CoA. Ohne CoA lässt sich „versendet“ nicht setzen (gilt für Rohstoffe, Fertigware und Bulk, nicht für Verpackung und Etiketten).',
    ]],
    ['anhaenger', 'Aufkleber (Labels) drucken', 'Jedes Gebinde bekommt unseren Aufkleber – unser Lager scannt ihn beim Wareneingang.', [
      'Erst die Charge eintragen, dann Nettogewicht je Gebinde und Anzahl angeben, „Aufkleber drucken“.',
      'Je Sack, Fass oder Karton einen Aufkleber aufkleben.',
      'Auf dem Aufkleber steht Ihre Lieferantennummer (Supplier no.), nicht Ihr Firmenname.',
    ]],
    ['einkauf', 'Versanddaten', 'Vor „versendet“ brauchen wir die Versanddaten.', [
      'Versandanbieter, Versandart und Sendungsnummer speichern – erst dann geht „versendet“.',
      'Unter „Versand / Pakete“ die Tracking-Nummern eintragen (eine pro Zeile), bei Paletten die Anzahl. Unser Lager gleicht die Pakete damit ab.',
    ]],
    ['buchhaltung', 'Rechnung und Zahlung', 'Ihre Rechnung landet direkt in unserer Buchhaltung.', [
      'Unter „Dokumente & Rechnung“ die Rechnung hochladen (Typ „Rechnung“).',
      'Wir prüfen sie und gleichen sie mit der Bestellung ab. Gleiche Rechnungsnummer zweimal wird erkannt.',
      'Sobald wir bezahlt haben, steht an der Bestellung „bezahlt am TT.MM.JJJJ“, bei Teilzahlung der offene Rest.',
    ]],
    ['rohstoffe', 'Rohstoff-Preise und Fertigprodukt-Preise', 'Ihre laufenden Preise für uns, mit Staffel und Gültigkeit.', [
      'Rohstoff-Preise: Preise mit Staffel und „gültig bis“. Bitte regelmäßig aktualisieren oder mit „Alle als aktuell bestätigen“ bestätigen.',
      'Fertigprodukt-Preise (nur Lohnhersteller): Preis je Rezeptur eintragen. Änderungen gelten erst nach unserer Freigabe.',
    ]],
    ['produkte', 'Mein Katalog', 'Zeigen Sie uns, was Sie sonst noch anbieten – dann fragen wir Sie von selbst an.', [
      '„Katalog hochladen“ (PDF, Excel oder CSV) – wir lesen ihn aus. Oder „Manuell hinzufügen“ bzw. „CoA/Spec hochladen“.',
      'Unter „Von bulkify bei Ihnen geführt“ schlagen Sie einen neuen Preis vor; er gilt erst nach unserer Prüfung.',
    ]],
    ['ordner', 'Dateien', 'Ihre Unterlagen an einem Ort – Spezifikationen, CoA, Zertifikate, Preislisten.', [
      'Hochladen, benennen, fertig. Wir sehen dieselben Dateien.',
    ]],
    ['sprechblase', 'Rückfragen', 'Kurze Fragen ohne E-Mail-Verkehr.', [
      'Schreiben Sie uns an der Bestellung oder Anfrage, dann ist sofort klar, worum es geht.',
      'Neue Antworten stehen als Zahl im Menü.',
    ]],
    ['person', 'Meine Daten und Bankverbindung', 'Adresse, Ansprechpartner, Sprache, WeChat/WhatsApp, Bankverbindung und Logo.', [
      'Bankverbindung: IBAN oder – ohne IBAN – SWIFT/BIC und Kontonummer. Dorthin zahlen wir.',
      'Ändert sich Ihre Bankverbindung, bitte hier ändern und uns kurz Bescheid geben.',
      'Konditionen und Preise pflegen wir.',
    ]],
    ['apps', 'Aufs Handy legen', 'Das Portal läuft auch als App auf dem Startbildschirm – ohne Play Store.', [
      'Android/Chrome: Menü (drei Punkte) → „App installieren“.',
      'iPhone/Safari: Teilen-Symbol → „Zum Home-Bildschirm“.',
    ]],
  ],
  'frage' => 'Etwas unklar? Schreiben Sie uns unter „Rückfragen“ – wir antworten dort.',
],
'en' => [
  'titel' => 'Guide',
  'sub'   => 'How to work with the supplier portal – step by step.',
  'abschnitte' => [
    ['person', 'Log in and language', 'You received an invitation by email from us and set your own password.', [
      'Address: ' . $login,
      'Forgot your password? Write to us – we will send a new invitation.',
      'At the bottom left you switch the language: DE, EN, 中文.',
    ]],
    ['apps', 'Overview', 'The start page shows what is waiting for you: open and unconfirmed orders, open requests, new questions – and a yellow note if documents are missing. Numbers in the menu show where something is open.', []],
    ['rohstoff_unterlagen', 'Missing documents', 'For every raw material we need a CoA or a specification; for finished products also the specification of the whole product.', [
      '“Missing documents” lists everything that is still missing – grouped by item. The number in the menu shows how many.',
      'Tap “Upload”, choose the file, add an expiry date and a note if needed. Completed items disappear.',
      'As long as documents are missing, we cannot order, and delivered goods stay blocked with us.',
    ]],
    ['anfragen', 'Answer requests', 'We ask for a price – you enter it.', [
      'Open the request. At the top you see what we need: item, quantity and the unit for the price (e.g. per kg).',
      'Enter the price in exactly this unit, plus minimum order quantity (MOQ) and lead time in days.',
      'Enter your exact variant, e.g. “magnesium bisglycinate, 13 % magnesium”.',
      'Other quantities, other prices? Tap “+ Tier” and add more lines.',
      'Enter “Price valid until”. For raw materials with fluctuating prices the date is mandatory; empty means unlimited.',
      'Upload a CoA or specification directly at the request. Without documents you can submit the price, but we cannot order.',
      'Send with “Submit quote”. Until we accept it you can change it with “Adjust quote”.',
    ]],
    ['auftraege', 'Confirm an order', 'Your price becomes an order. The number in the menu shows how many are still to confirm.', [
      'Open the order, enter the planned delivery date and your name, then “Confirm order”.',
      'If the date changes later, send us a question at the order – after confirmation only our team can change it.',
      'The order is available as a PDF.',
    ]],
    ['produktion', 'Update the progress', 'Please set the stations in order, so we do not have to ask.', [
      'In production → quality check → ready to ship → shipped. “Order accepted” comes with the confirmation; we set the goods receipt.',
      'The bar at the top of the order shows: done (green), current (highlighted), open (grey).',
    ]],
    ['angebote', 'Batches and documents', 'For each position enter the batch before the goods leave your site.', [
      'Under “Batches & documents” enter batch, best-before date and quantity per position – or upload the CoA and we read the batch from it.',
      'One CoA per batch. Without a CoA “shipped” cannot be set (raw materials, finished goods and bulk; not packaging and labels).',
    ]],
    ['anhaenger', 'Print labels', 'Every container gets our label – our warehouse scans it at goods receipt.', [
      'Enter the batch first, then net weight per container and the number of labels, “Print labels”.',
      'Put one label on every bag, drum or carton.',
      'The label shows your supplier number (Supplier no.), not your company name.',
    ]],
    ['einkauf', 'Shipping data', 'Before “shipped” we need the shipping data.', [
      'Save carrier, shipping method and tracking number – only then “shipped” is possible.',
      'Under “Shipping / parcels” enter the tracking numbers (one per line), for pallets the number of pallets. Our warehouse checks the parcels with them.',
    ]],
    ['buchhaltung', 'Invoice and payment', 'Your invoice goes directly to our accounting.', [
      'Under “Documents & invoice” upload your invoice (type “Invoice”).',
      'We check it against the order. The same invoice number twice is detected.',
      'As soon as we have paid, the order shows “paid on DD.MM.YYYY”; for a partial payment the open rest.',
    ]],
    ['rohstoffe', 'Raw-material prices and finished-product prices', 'Your current prices for us, with tiers and validity.', [
      'Raw-material prices: prices with tiers and “valid until”. Please update them regularly or confirm them with “Confirm all as current”.',
      'Finished-product prices (contract manufacturers only): price per formula. Changes apply only after our approval.',
    ]],
    ['produkte', 'My catalogue', 'Show us what else you offer – then we will ask you directly.', [
      '“Upload catalogue” (PDF, Excel or CSV) – we read it. Or “Add manually” or “Upload CoA/spec”.',
      'Under “Listed by bulkify for you” you can propose a new price; it applies only after our check.',
    ]],
    ['ordner', 'Files', 'Your documents in one place – specifications, CoA, certificates, price lists.', [
      'Upload, name, done. We see the same files.',
    ]],
    ['sprechblase', 'Questions and answers', 'Short questions without email.', [
      'Write to us at the order or request, so it is clear right away what it is about.',
      'New answers show as a number in the menu.',
    ]],
    ['person', 'My details and bank account', 'Address, contact person, language, WeChat/WhatsApp, bank account and logo.', [
      'Bank account: IBAN or – without IBAN – SWIFT/BIC and account number. We pay to this account.',
      'If your bank account changes, please change it here and let us know.',
      'Terms and prices are maintained by us.',
    ]],
    ['apps', 'Put it on your phone', 'The portal also runs as an app on your home screen – no app store needed.', [
      'Android/Chrome: menu (three dots) → “Install app”.',
      'iPhone/Safari: share icon → “Add to Home Screen”.',
    ]],
  ],
  'frage' => 'Anything unclear? Write to us under “Questions and answers” – we reply there.',
],
'zh' => [
  'titel' => '使用说明',
  'sub'   => '如何使用供应商门户 – 一步一步说明。',
  'abschnitte' => [
    ['person', '登录与语言', '您已通过电子邮件收到我们的邀请，并设置了自己的密码。', [
      '网址：' . $login,
      '忘记密码？请联系我们，我们会重新发送邀请。',
      '在左下角可切换语言：DE、EN、中文。',
    ]],
    ['apps', '概览', '首页显示等待您处理的事项：未完成和未确认的订单、待报价的询价、新的留言；如有缺失文件，会显示黄色提示。菜单上的数字表示待处理的数量。', []],
    ['rohstoff_unterlagen', '缺失文件', '每种原料都需要分析证书（CoA）或规格书；成品还需要整个产品的规格书。', [
      '“缺失文件”列出所有尚缺的文件，按物料分组。菜单上的数字表示数量。',
      '点击“上传”，选择文件，如需要可填写到期日期和备注。完成的项目会自动消失。',
      '只要文件缺失，我们就无法下单，已交付的货物也会被我们冻结。',
    ]],
    ['anfragen', '回复询价', '我们询价，您填写价格。', [
      '打开询价。上方写明我们需要的物料、数量以及计价单位（例如每公斤）。',
      '按此单位填写价格，并填写最小起订量（MOQ）和交货周期（天）。',
      '填写您的具体规格，例如“甘氨酸镁，镁含量 13 %”。',
      '不同数量不同价格？点击“+ 阶梯价”添加更多行。',
      '填写“价格有效期至”。价格波动的原料必须填写日期；留空表示不限期。',
      '直接在询价中上传分析证书（CoA）或规格书。没有文件也可以报价，但我们无法下单。',
      '点击“提交报价”发送。在我们采纳之前，可通过“修改报价”进行更改。',
    ]],
    ['auftraege', '确认订单', '报价被采纳后会生成订单。菜单上的数字表示待确认的订单数量。', [
      '打开订单，填写计划交货日期和您的姓名，点击“确认订单”。这样我们就知道您已收到订单。',
      '如果之后交货日期有变，请在订单中给我们留言 – 确认后只有我们的团队可以修改日期。',
      '订单可下载为 PDF。',
    ]],
    ['produktion', '更新进度', '请按顺序设置各个节点，这样我们无需再询问。', [
      '生产中 → 质检 → 待发货 → 已发货。“已接单”随确认自动完成，“到货”由我们设置。',
      '订单上方的进度条显示：已完成（绿色）、当前（突出显示）、未完成（灰色）。',
    ]],
    ['angebote', '批次与文件', '货物发出前，请为每个订单行填写批次。', [
      '在“批次与文件”中为每行填写批号、保质期和数量 – 或上传分析证书（CoA），我们会自动识别批号。',
      '每个批次一份分析证书（CoA）。没有 CoA 无法设置“已发货”（适用于原料、成品和散装料，不适用于包装和标签）。',
    ]],
    ['anhaenger', '打印标签', '每件包装都要贴我们的标签 – 我们的仓库在收货时扫描。', [
      '先填写批次，再填写每件净重和标签张数，点击“打印标签”。',
      '每袋、每桶或每箱贴一张标签。',
      '标签上显示的是您的供应商编号（Supplier no.），而不是公司名称。',
    ]],
    ['einkauf', '发货信息', '设置“已发货”之前，我们需要发货信息。', [
      '保存承运商、运输方式和物流单号 – 之后才能设置“已发货”。',
      '在“发货 / 包裹”中填写物流单号（每行一个），托盘发货请填写托盘数量。我们的仓库据此核对包裹。',
    ]],
    ['buchhaltung', '发票与付款', '您的发票会直接进入我们的财务系统。', [
      '在“文件与发票”中上传发票（类型“发票”）。',
      '我们会核对发票与订单。同一发票号重复上传会被识别。',
      '付款后，订单上会显示“已于 日.月.年 付款”；部分付款时显示剩余金额。',
    ]],
    ['rohstoffe', '原料价格与成品价格', '您给我们的当前价格，包括阶梯价和有效期。', [
      '原料价格：带阶梯价和“有效期至”的价格。请定期更新，或点击“全部确认为最新”。',
      '成品价格（仅限代工厂）：按配方填写价格。修改需经我们审核后才生效。',
    ]],
    ['produkte', '我的产品目录', '告诉我们您还提供哪些产品 – 我们会主动向您询价。', [
      '“上传目录”（PDF、Excel 或 CSV），我们会自动读取。也可以“手动添加”或“上传 CoA/规格书”。',
      '在“bulkify 在您处采购的产品”中可以提出新价格；经我们审核后才生效。',
    ]],
    ['ordner', '文件', '您的文件集中在一处 – 规格书、分析证书（CoA）、资质证书、价格表。', [
      '上传、命名，完成。我们看到的是同样的文件。',
    ]],
    ['sprechblase', '留言与答复', '简短问题，无需发邮件。', [
      '请在相关订单或询价中给我们留言，这样我们马上知道是什么事。',
      '新回复会在菜单上显示数字。',
    ]],
    ['person', '我的资料与银行账户', '地址、联系人、语言、微信/WhatsApp、银行账户和标志。', [
      '银行账户：IBAN，或没有 IBAN 时填写 SWIFT/BIC 和账号。我们会付款到该账户。',
      '如果银行账户有变，请在此修改并告知我们。',
      '付款条件和价格由我们维护。',
    ]],
    ['apps', '添加到手机', '门户也可以作为应用放在手机主屏幕上 – 无需应用商店。', [
      'Android/Chrome：菜单（三个点）→“安装应用”。',
      'iPhone/Safari：分享图标 →“添加到主屏幕”。',
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

<style>.lh-kopf{display:flex;align-items:center;gap:12px;margin:0 0 6px}.lh-ico{flex:none;width:36px;height:36px;border-radius:10px;display:inline-flex;align-items:center;justify-content:center;background:rgba(29,158,117,.12);color:var(--gruen)}.lh-ico .bx-navico{width:20px;height:20px}.lh-kopf h2{margin:0}</style>
<?php $nr = 0; foreach ($t['abschnitte'] as [$ico, $titel, $einleitung, $schritte]): $nr++; ?>
  <div class="bx-panel">
    <div class="lh-kopf"><span class="lh-ico"><?= bx_nav_icon($ico) ?></span><h2><?= $nr ?>. <?= h($titel) ?></h2></div>
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

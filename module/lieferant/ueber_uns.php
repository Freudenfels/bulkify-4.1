<?php
// Lieferantenportal – „Über uns". Route: ?p=lieferant_ueber
// Positioniert bulkify als Hersteller UND Rohstoffhändler mit einer neuen, volldigitalen Plattform –
// damit der Lieferant versteht, dass hier vieles anders läuft als in der Branche üblich. Mehrsprachig (de/en/zh).
require_once BX_ROOT . '/module/lieferant/portal_layout.php';
if (!ist_lieferant()) { header('Location: ?p=lieferant_login'); exit; }

$spr = lp_sprache();

// Inhalt je Sprache. Fällt auf Deutsch zurück, wenn eine Sprache fehlt.
$C = [
  'de' => [
    'titel' => 'Über bulkify',
    'sub'   => 'Hersteller, Rohstoffhändler – und eine neue, volldigitale Plattform für den Zugang zu Rohstoffen.',
    'bloecke' => [
      ['Wer wir sind',
       'bulkify ist Hersteller von Nahrungsergänzungsmitteln und zugleich Rohstoffhändler – und wir bringen beides auf einer neuen, volldigitalen Plattform zusammen. Wir entwickeln und produzieren hochwertige Produkte (Kapseln, Tabletten, Pulver, Flüssigkeiten und mehr) und machen gleichzeitig Rohstoffe direkt zugänglich: online, transparent und schnell.'],
      ['Nicht wie üblich',
       'In unserer Branche läuft vieles noch über E-Mails, PDFs und langes Hin und Her. Bei uns nicht. Kunden – ob Brand Owner, andere Hersteller oder Einkäufer – finden Rohstoffe bei uns direkt, fragen sie an und kaufen sie: alles digital, an einem Ort, ohne E-Mail-Pingpong. Das ist kein kleiner Unterschied, sondern ein anderer Weg, diesen Markt zu bedienen.'],
      ['Für wen wir da sind',
       'Marken, die ihr eigenes Produkt herstellen lassen wollen. Hersteller, die verlässliche Rohstoffe brauchen. Einkäufer, die schnell vergleichen und beschaffen möchten. Sie alle finden bei bulkify Rohstoffe und Fertigung an einer Stelle – nachvollziehbar, transparent und ohne Umwege.'],
      ['Unsere Vision',
       'Wir bauen bulkify europaweit aus. Unser Ziel ist ein deutlich besserer, offenerer Zugang zu Rohstoffen – schneller, transparenter und fairer, als es heute üblich ist. Wir wollen den Weg vom Rohstoff zum fertigen Produkt so einfach machen, dass Qualität und Tempo kein Widerspruch mehr sind.'],
      ['Was das für Sie als Lieferant bedeutet',
       'Sie werden Teil eines wachsenden, digitalen Netzwerks: Ihre Rohstoffe erreichen mehr Kunden in ganz Europa – ohne Kaltakquise, ohne E-Mail-Flut. Über dieses Portal arbeiten wir direkt und transparent zusammen – Preise, Anfragen, Bestellungen und Dokumente an einem Ort, in Echtzeit. Je besser Ihre Angaben (Preise, Spezifikationen, Analysenzertifikate), desto sichtbarer und gefragter werden Ihre Rohstoffe. Danke, dass Sie Teil davon sind.'],
    ],
  ],
  'en' => [
    'titel' => 'About bulkify',
    'sub'   => 'Manufacturer, raw-material trader – and a new, fully digital platform for access to ingredients.',
    'bloecke' => [
      ['Who we are',
       'bulkify is a manufacturer of food supplements and, at the same time, a raw-material trader – and we bring both together on a new, fully digital platform. We develop and produce high-quality products (capsules, tablets, powders, liquids and more) and, in parallel, make raw materials directly accessible: online, transparent and fast.'],
      ['Not business as usual',
       'In our industry, much still runs on emails, PDFs and endless back-and-forth. Not with us. Customers – whether brand owners, other manufacturers or buyers – find raw materials with us directly, request them and buy them: all digital, in one place, without email ping-pong. This is not a small difference, but a different way of serving this market.'],
      ['Who we are here for',
       'Brands that want their own product manufactured. Manufacturers who need reliable raw materials. Buyers who want to compare and source quickly. All of them find raw materials and manufacturing at bulkify in one place – traceable, transparent and without detours.'],
      ['Our vision',
       'We are expanding bulkify across Europe. Our goal is markedly better, more open access to raw materials – faster, more transparent and fairer than is common today. We want to make the path from raw material to finished product so simple that quality and speed are no longer a contradiction.'],
      ['What this means for you as a supplier',
       'You become part of a growing, digital network: your raw materials reach more customers across Europe – without cold calling, without a flood of emails. Through this portal we work together directly and transparently – prices, enquiries, orders and documents in one place, in real time. The better your information (prices, specifications, certificates of analysis), the more visible and sought-after your raw materials become. Thank you for being part of it.'],
    ],
  ],
  'zh' => [
    'titel' => '关于 bulkify',
    'sub'   => '制造商、原料贸易商——以及一个全新的、全数字化的原料获取平台。',
    'bloecke' => [
      ['我们是谁',
       'bulkify 既是膳食补充剂制造商，也是原料贸易商——并把两者整合到一个全新的、全数字化的平台上。我们开发并生产高品质产品（胶囊、片剂、粉剂、液体等），同时让原料可以直接获取：在线、透明、快速。'],
      ['与众不同之处',
       '在我们这个行业，许多事情仍依赖邮件、PDF 和反复沟通。在我们这里不是这样。客户——无论是品牌方、其他制造商还是采购方——都能在我们平台上直接找到原料、发起询价并完成采购：全程数字化、集中于一处，无需邮件往返。这不是细微的差别，而是服务这个市场的另一种方式。'],
      ['我们服务于谁',
       '希望代工生产自有产品的品牌；需要可靠原料的制造商；希望快速比价与采购的采购方。他们都能在 bulkify 一站式找到原料与生产——可追溯、透明、不绕路。'],
      ['我们的愿景',
       '我们正将 bulkify 拓展至整个欧洲。我们的目标，是让原料的获取明显更好、更开放——比当下行业惯例更快、更透明、更公道。我们希望让从原料到成品的路径足够简单，使品质与速度不再相互矛盾。'],
      ['这对作为供应商的您意味着什么',
       '您将成为一个不断壮大的数字网络的一员：您的原料将触达全欧洲更多的客户——无需陌生拜访，无需海量邮件。通过本门户，我们直接且透明地协作——价格、询价、订单与文件集中于一处，实时同步。您的信息越完整（价格、规格书、分析证书 CoA），您的原料就越容易被看到、越受欢迎。感谢您成为其中的一员。'],
    ],
  ],
];
$c = $C[$spr] ?? $C['de'];

lp_head('bulkify – ' . $c['titel']);
lp_shell_start('lieferant_ueber');
?>
<h1 style="margin-bottom:4px"><?= h($c['titel']) ?></h1>
<p class="bx-sub"><?= h($c['sub']) ?></p>

<?php foreach ($c['bloecke'] as $b): ?>
<div class="bx-panel">
  <h2 style="margin-top:0"><?= h($b[0]) ?></h2>
  <p style="margin:0;line-height:1.6"><?= h($b[1]) ?></p>
</div>
<?php endforeach; ?>

<?php lp_shell_ende(); lp_foot();

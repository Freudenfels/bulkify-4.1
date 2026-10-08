<?php
// Lieferantenportal – „Über uns". Route: ?p=lieferant_ueber
// Erklärt dem Lieferanten, wer bulkify ist, was wir tun und unsere Vision – mehrsprachig (de/en/zh).
require_once BX_ROOT . '/module/lieferant/portal_layout.php';
if (!ist_lieferant()) { header('Location: ?p=lieferant_login'); exit; }

$spr = lp_sprache();

// Inhalt je Sprache. Fällt auf Deutsch zurück, wenn eine Sprache fehlt.
$C = [
  'de' => [
    'titel' => 'Über bulkify',
    'sub'   => 'Wer wir sind, was wir tun – und wohin wir wollen.',
    'bloecke' => [
      ['Wer wir sind',
       'bulkify ist ein Lohnhersteller für Nahrungsergänzungsmittel. Wir entwickeln und produzieren hochwertige Produkte – Kapseln, Tabletten, Pulver, Flüssigkeiten und mehr – für Marken und Unternehmen, die ihre eigenen Nahrungsergänzungsmittel auf den Markt bringen möchten.'],
      ['Was wir tun',
       'Wir begleiten den gesamten Weg: von der Rezepturentwicklung über die Beschaffung der Rohstoffe, die Herstellung und Qualitätssicherung bis zur fertig etikettierten, versandfertigen Ware. Dazu gehören Einkauf, Produktion, Lagerung, Etikettierung sowie Versand und Fulfillment – alles aus einer Hand.'],
      ['Unsere Vision',
       'Wir wollen die Herstellung von Nahrungsergänzungsmitteln einfach, transparent und verlässlich machen. Mit durchgängig digitalen Prozessen – vom ersten Angebot bis zur Auslieferung – schaffen wir Tempo, Nachvollziehbarkeit und gleichbleibende Qualität. Unser Ziel ist, der Partner zu sein, auf den sich Marken bei Qualität, Geschwindigkeit und Ehrlichkeit verlassen können.'],
      ['Was uns in der Zusammenarbeit wichtig ist',
       'Verlässliche Qualität und vollständige Unterlagen (Spezifikationen, Analysenzertifikate), faire Preise und offene Kommunikation. Wir setzen auf langfristige, partnerschaftliche Zusammenarbeit. Über dieses Portal arbeiten wir mit Ihnen transparent und effizient zusammen – Preise, Anfragen, Bestellungen und Dokumente an einem Ort. Danke, dass Sie Teil davon sind.'],
    ],
  ],
  'en' => [
    'titel' => 'About bulkify',
    'sub'   => 'Who we are, what we do – and where we are headed.',
    'bloecke' => [
      ['Who we are',
       'bulkify is a contract manufacturer for food supplements. We develop and produce high-quality products – capsules, tablets, powders, liquids and more – for brands and companies that want to bring their own supplements to market.'],
      ['What we do',
       'We cover the whole journey: from recipe development and raw-material sourcing to manufacturing, quality assurance and fully labelled, ready-to-ship goods. That includes purchasing, production, warehousing, labelling as well as shipping and fulfilment – all from a single source.'],
      ['Our vision',
       'We want to make supplement manufacturing simple, transparent and reliable. With end-to-end digital processes – from the first quote to delivery – we create speed, traceability and consistent quality. Our goal is to be the partner brands can rely on for quality, speed and honesty.'],
      ['What matters to us in working together',
       'Reliable quality and complete documentation (specifications, certificates of analysis), fair prices and open communication. We believe in long-term, partnership-based cooperation. Through this portal we work with you transparently and efficiently – prices, enquiries, orders and documents in one place. Thank you for being part of it.'],
    ],
  ],
  'zh' => [
    'titel' => '关于 bulkify',
    'sub'   => '我们是谁、我们做什么，以及我们的方向。',
    'bloecke' => [
      ['我们是谁',
       'bulkify 是一家膳食补充剂的代工生产商（OEM/ODM）。我们为希望推出自有膳食补充剂的品牌与企业，开发并生产高品质产品——胶囊、片剂、粉剂、液体等。'],
      ['我们做什么',
       '我们覆盖全流程：从配方开发、原料采购，到生产制造、质量把控，直至贴标完成、可直接发货的成品。涵盖采购、生产、仓储、贴标以及发货与履约——一站式服务。'],
      ['我们的愿景',
       '我们希望让膳食补充剂的生产变得简单、透明且可靠。通过从首次报价到交付的全程数字化流程，实现高效率、可追溯与稳定的品质。我们的目标，是成为品牌在品质、速度与诚信上都能信赖的合作伙伴。'],
      ['合作中我们看重什么',
       '稳定的品质与完整的资料（规格书、分析证书 CoA）、公道的价格与开放的沟通。我们重视长期的伙伴式合作。通过本门户，我们与您透明、高效地协作——价格、询价、订单与文件集中于一处。感谢您成为其中的一员。'],
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

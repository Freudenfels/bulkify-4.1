<?php
// Alt-Route: Lieferanten-Preise sind jetzt Teil von „Einkauf → Preise".
$tab = (($_GET['tab'] ?? '') === 'fertigprodukt') ? 'zukauf' : 'rohstoff';
header('Location: ?p=einkauf_preise&tab=' . $tab . (!empty($_GET['q']) ? '&q=' . urlencode((string)$_GET['q']) : ''));
exit;

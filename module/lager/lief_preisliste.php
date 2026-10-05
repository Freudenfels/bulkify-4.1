<?php
// Alt-Route: die EK-Preisliste (v3-Referenz) ist jetzt im Reiter „Rohstoff" von „Einkauf → Preise" eingeblendet.
header('Location: ?p=einkauf_preise&tab=rohstoff' . (!empty($_GET['q']) ? '&q=' . urlencode((string)$_GET['q']) : ''));
exit;

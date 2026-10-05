<?php
// Alt-Route: Rezeptur-Preise sind jetzt Teil von „Einkauf → Preise" (Reiter Fremdfertigung).
header('Location: ?p=einkauf_preise&tab=fremd' . (!empty($_GET['q']) ? '&q=' . urlencode((string)$_GET['q']) : ''));
exit;

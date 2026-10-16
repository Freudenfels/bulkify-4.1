<?php
// DL-Angebote werden jetzt in der REGULÄREN Angebotsmaske (module/angebot/detail.php) bearbeitet,
// damit man Dienstleistung + Produkte (Gläser, Etikett …) in EINEM regulären Angebot zusammenführen kann.
// Die Route ?p=dl_angebot zeigt in public/index.php bereits direkt dorthin; dieser Stub fängt nur noch
// alte Direkt-/Bookmark-Aufrufe ab und leitet auf dieselbe Maske um.
$id = (int)($_GET['id'] ?? 0);
header('Location: ' . ($id > 0 ? '?p=angebot&id=' . $id : '?p=dl_angebote'));
exit;

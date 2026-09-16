<?php
// Uebersetzt die (englischen) importierten Health-Claims ins Deutsche – nach dem standardisierten
// EU-Muster "<Naehrstoff> traegt zu ... bei". Deckt die 77 Standard-Funktionsphrasen ab.
// WICHTIG: Es sind Uebersetzungen im amtlichen Stil – vor dem Druck gegen die offizielle deutsche
// EU-Fassung pruefen. Nicht abgedeckte Claims bleiben unveraendert (englisch) und werden gemeldet.
//
// Aufruf:  php tools/translate_health_claims.php           (Testlauf)
//          php tools/translate_health_claims.php --write   (schreibt die deutschen Fassungen)
require_once dirname(__DIR__) . '/core/config.php';
require_once BX_ROOT . '/core/schema.php';

$write = in_array('--write', $argv, true);

// Naehrstoff-/Subjekt-Woerter EN -> DE.
$subMap = [
    'vitamin a' => 'Vitamin A', 'vitamin c' => 'Vitamin C', 'vitamin d' => 'Vitamin D', 'vitamin e' => 'Vitamin E',
    'vitamin k' => 'Vitamin K', 'vitamin b6' => 'Vitamin B6', 'vitamin b12' => 'Vitamin B12', 'vitamin b2' => 'Vitamin B2',
    'thiamine' => 'Thiamin', 'riboflavin' => 'Riboflavin', 'niacin' => 'Niacin', 'folate' => 'Folat', 'folic acid' => 'Folsäure',
    'biotin' => 'Biotin', 'pantothenic acid' => 'Pantothensäure', 'iron' => 'Eisen', 'zinc' => 'Zink', 'copper' => 'Kupfer',
    'iodine' => 'Jod', 'selenium' => 'Selen', 'calcium' => 'Calcium', 'magnesium' => 'Magnesium', 'manganese' => 'Mangan',
    'molybdenum' => 'Molybdän', 'chromium' => 'Chrom', 'potassium' => 'Kalium', 'phosphorus' => 'Phosphor', 'chloride' => 'Chlorid',
    'choline' => 'Cholin', 'protein' => 'Eiweiß', 'proteins' => 'Eiweiß', 'phosphorus and vitamin d' => 'Phosphor und Vitamin D',
];
// Funktions-Praedikate (verb + rest, lowercase) -> vollstaendige deutsche Praedikate (Singular "traegt ... bei").
$predMap = [
    'contributes to normal energy-yielding metabolism' => 'trägt zu einem normalen Energiestoffwechsel bei',
    'contributes to the normal function of the immune system' => 'trägt zu einer normalen Funktion des Immunsystems bei',
    'contributes to the normal function of the immune system in children.' => 'trägt zu einer normalen Funktion des Immunsystems bei Kindern bei',
    'contributes to maintain the normal function of the immune system during and after intense physical exercise' => 'trägt dazu bei, die normale Funktion des Immunsystems während und nach intensiver körperlicher Betätigung aufrechtzuerhalten',
    'contributes to normal functioning of the nervous system' => 'trägt zu einer normalen Funktion des Nervensystems bei',
    'contributes to normal function of cell membranes' => 'trägt zu einer normalen Funktion der Zellmembranen bei',
    'contributes to the reduction of tiredness and fatigue' => 'trägt zur Verringerung von Müdigkeit und Ermüdung bei',
    'contributes to a reduction of tiredness and fatigue' => 'trägt zur Verringerung von Müdigkeit und Ermüdung bei',
    'contributes to the protection of cells from oxidative stress' => 'trägt dazu bei, die Zellen vor oxidativem Stress zu schützen',
    'contributes to normal psychological function' => 'trägt zu einer normalen psychischen Funktion bei',
    'contributes to normal muscle function' => 'trägt zu einer normalen Muskelfunktion bei',
    'contributes to the maintenance of normal muscle function' => 'trägt zur Erhaltung einer normalen Muskelfunktion bei',
    'contributes to the maintenance of normal bones' => 'trägt zur Erhaltung normaler Knochen bei',
    'contributes to the maintenance of normal teeth' => 'trägt zur Erhaltung normaler Zähne bei',
    'contributes to the maintenance of normal hair' => 'trägt zur Erhaltung normaler Haare bei',
    'contributes to the maintenance of normal skin' => 'trägt zur Erhaltung normaler Haut bei',
    'contributes to the maintenance of normal mucous membranes' => 'trägt zur Erhaltung normaler Schleimhäute bei',
    'contributes to the maintenance of normal vision' => 'trägt zur Erhaltung normaler Sehkraft bei',
    'contributes to the maintenance of normal blood pressure' => 'trägt zur Erhaltung eines normalen Blutdrucks bei',
    'contributes to the maintenance of normal blood glucose levels' => 'trägt zur Erhaltung eines normalen Blutzuckerspiegels bei',
    'contributes to the maintenance of tooth mineralisation' => 'trägt zur Erhaltung der Zahnmineralisierung bei',
    'contributes to the maintenance of normal testosterone levels in the blood' => 'trägt zur Erhaltung eines normalen Testosteronspiegels im Blut bei',
    'contributes to normal blood clotting' => 'trägt zu einer normalen Blutgerinnung bei',
    'contributes to normal cognitive function' => 'trägt zu einer normalen kognitiven Funktion bei',
    'contributes to normal cognitive development of children' => 'trägt zur normalen kognitiven Entwicklung von Kindern bei',
    'contributes to normal macronutrient metabolism' => 'trägt zu einem normalen Stoffwechsel der Makronährstoffe bei',
    'contributes to normal carbohydrate metabolism' => 'trägt zu einem normalen Kohlenhydratstoffwechsel bei',
    'contributes to normal protein and glycogen metabolism' => 'trägt zu einem normalen Eiweiß- und Glykogenstoffwechsel bei',
    'contributes to normal protein synthesis' => 'trägt zu einer normalen Eiweißsynthese bei',
    'contributes to normal amino acid synthesis' => 'trägt zu einer normalen Synthese von Aminosäuren bei',
    'contributes to normal cysteine synthesis' => 'trägt zu einer normalen Cysteinsynthese bei',
    'contributes to normal homocysteine metabolism' => 'trägt zu einem normalen Homocystein-Stoffwechsel bei',
    'contributes to normal iron metabolism' => 'trägt zu einem normalen Eisenstoffwechsel bei',
    'contributes to normal iron transport in the body' => 'trägt zu einem normalen Eisentransport im Körper bei',
    'contributes to normal oxygen transport in the body' => 'trägt zu einem normalen Sauerstofftransport im Körper bei',
    'contributes to normal red blood cell formation' => 'trägt zur normalen Bildung roter Blutkörperchen bei',
    'contributes to normal formation of red blood cells and haemoglobin' => 'trägt zur normalen Bildung von roten Blutkörperchen und Hämoglobin bei',
    'contributes to normal blood formation' => 'trägt zur normalen Blutbildung bei',
    'contributes to normal metabolism of fatty acids' => 'trägt zu einem normalen Fettsäurestoffwechsel bei',
    'contributes to normal metabolism of vitamin a' => 'trägt zu einem normalen Vitamin-A-Stoffwechsel bei',
    'contributes to normal acid-base metabolism' => 'trägt zu einem normalen Säure-Basen-Stoffwechsel bei',
    'contributes to normal sulphur amino acid metabolism' => 'trägt zu einem normalen Stoffwechsel schwefelhaltiger Aminosäuren bei',
    'contributes to normal mental performance' => 'trägt zu einer normalen geistigen Leistung bei',
    'contributes to normal neurotransmission' => 'trägt zu einer normalen Neurotransmission bei',
    'contributes to normal fertility and reproduction' => 'trägt zu einer normalen Fruchtbarkeit und Fortpflanzung bei',
    'contributes to normal spermatogenesis' => 'trägt zu einer normalen Spermabildung bei',
    'contributes to normal hair pigmentation' => 'trägt zu einer normalen Pigmentierung der Haare bei',
    'contributes to normal digestion by production of hydrochloric acid in the stomach' => 'trägt zu einer normalen Verdauung bei, indem es die Bildung von Magensäure fördert',
    'contributes to the normal function of digestive enzymes' => 'trägt zur normalen Funktion der Verdauungsenzyme bei',
    'contributes to the normal formation of connective tissue' => 'trägt zur normalen Bildung von Bindegewebe bei',
    'contributes to maintenance of normal connective tissues' => 'trägt zur Erhaltung normaler Bindegewebe bei',
    'contributes to normal collagen formation for the normal function of blood vessels' => 'trägt zu einer normalen Kollagenbildung für eine normale Funktion der Blutgefäße bei',
    'contributes to normal collagen formation for the normal function of bones' => 'trägt zu einer normalen Kollagenbildung für eine normale Knochenfunktion bei',
    'contributes to normal collagen formation for the normal function of gums' => 'trägt zu einer normalen Kollagenbildung für eine normale Funktion des Zahnfleisches bei',
    'contributes to normal collagen formation for the normal function of skin' => 'trägt zu einer normalen Kollagenbildung für eine normale Funktion der Haut bei',
    'contributes to normal absorption/utilisation of calcium and phosphorus' => 'trägt zu einer normalen Aufnahme/Verwertung von Calcium und Phosphor bei',
    'contributes to electrolyte balance' => 'trägt zum Elektrolytgleichgewicht bei',
    'contributes to the regulation of hormonal activity' => 'trägt zur Regulierung der Hormontätigkeit bei',
    'contributes to the maintenance of normal synthesis and metabolism of steroid hormones, vitamin d and some neurotransmitters' => 'trägt zu einer normalen Synthese und einem normalen Stoffwechsel von Steroidhormonen, Vitamin D und einigen Neurotransmittern bei',
    'contributes to normal synthesis and metabolism of steroid hormones, vitamin d and some neurotransmitters' => 'trägt zu einer normalen Synthese und einem normalen Stoffwechsel von Steroidhormonen, Vitamin D und einigen Neurotransmittern bei',
    'contributes to the normal production of thyroid hormones and normal thyroid function' => 'trägt zu einer normalen Produktion von Schilddrüsenhormonen und einer normalen Schilddrüsenfunktion bei',
    'contributes to the normal thyroid function' => 'trägt zu einer normalen Schilddrüsenfunktion bei',
    'contributes to the normal growth of children' => 'trägt zum normalen Wachstum von Kindern bei',
    'contributes to maternal tissue growth during pregnancy' => 'trägt zum Wachstum des mütterlichen Gewebes während der Schwangerschaft bei',
    'contributes to the regeneration of the reduced form of vitamin e' => 'trägt zur Regeneration der reduzierten Form von Vitamin E bei',
    'has a role in the process of cell division' => 'hat eine Funktion bei der Zellteilung',
    'has a role in the process of cell division and specialisation' => 'hat eine Funktion bei der Zellteilung und -spezialisierung',
    'has a role in the process of cell specialisation' => 'hat eine Funktion bei der Zellspezialisierung',
    'increases iron absorption' => 'erhöht die Eisenaufnahme',
    'increases maternal folate status. low maternal folate status is a risk factor in the development of neural tube defects in the developing foetus.' => 'erhöht den Folatstatus der Mutter. Ein niedriger mütterlicher Folatstatus ist ein Risikofaktor für die Entstehung von Neuralrohrdefekten beim sich entwickelnden Fötus.',
    'is needed for the maintenance of normal bones' => 'wird für die Erhaltung normaler Knochen benötigt',
    'is needed for the maintenance of normal teeth' => 'wird für die Erhaltung normaler Zähne benötigt',
    'is needed for the normal growth and development of bone in children' => 'wird für das normale Wachstum und die normale Entwicklung der Knochen bei Kindern benötigt',
    'is needed for normal growth and development of bone in children.' => 'wird für das normale Wachstum und die normale Entwicklung der Knochen bei Kindern benötigt',
    'are needed for normal growth and development of bone in children' => 'werden für das normale Wachstum und die normale Entwicklung der Knochen bei Kindern benötigt',
    'help to reduce the loss of bone mineral in post-menopausal women. low bone mineral density is a risk factor for osteoporotic bone fractures' => 'tragen dazu bei, den Verlust an Knochenmineral bei Frauen nach der Menopause zu verringern. Eine geringe Knochenmineraldichte ist ein Risikofaktor für osteoporosebedingte Knochenbrüche.',
    'helps to reduce the loss of bone mineral in post-menopausal women. low bone mineral density is a risk factor for osteoporotic bone fractures' => 'trägt dazu bei, den Verlust an Knochenmineral bei Frauen nach der Menopause zu verringern. Eine geringe Knochenmineraldichte ist ein Risikofaktor für osteoporosebedingte Knochenbrüche.',
    'helps to reduce the risk of falling associated with postural instability and muscle weakness. falling is a risk factor for bone fractures among men and women 60 years of age and older.' => 'trägt dazu bei, das mit Haltungsinstabilität und Muskelschwäche verbundene Sturzrisiko zu verringern. Stürze sind ein Risikofaktor für Knochenbrüche bei Männern und Frauen ab 60 Jahren.',
];

// Subjekt uebersetzen ("Calcium and vitamin D" -> "Calcium und Vitamin D").
$transSub = function (string $s) use ($subMap): ?string {
    $s = trim($s);
    if ($s === '') return null;
    $low = mb_strtolower($s);
    if (isset($subMap[$low])) return $subMap[$low];
    $parts = preg_split('/\s+and\s+/i', $s);
    $out = [];
    foreach ($parts as $p) {
        $pl = mb_strtolower(trim($p));
        if (!isset($subMap[$pl])) return null;   // unbekanntes Subjekt -> nicht uebersetzen
        $out[] = $subMap[$pl];
    }
    return implode(' und ', $out);
};

$rows = all("SELECT id, claim FROM health_claim");
$n = 0; $ok = 0; $miss = [];
foreach ($rows as $r) {
    $n++;
    $c = trim((string)$r['claim']);
    if (!preg_match('/^(.*?)\b(contributes to|contribute to|is needed for|are needed for|has a role in|have a role in|helps to|help to|increases)\b\s*(.*)$/is', $c, $m)) { $miss[] = $c; continue; }
    $subDE = $transSub(trim($m[1]));
    $predKey = mb_strtolower(trim($m[2]) . ' ' . trim($m[3]));
    $predDE = $predMap[$predKey] ?? null;
    if ($subDE === null || $predDE === null) { $miss[] = $c; continue; }
    $de = $subDE . ' ' . $predDE;
    if (mb_substr($de, -1) !== '.') $de .= '.';
    $ok++;
    if ($write) q("UPDATE health_claim SET claim=?, quelle=CONCAT(COALESCE(NULLIF(quelle,''),'EU 432/2012'),' · DE-Übersetzung (zu prüfen)') WHERE id=?", [$de, (int)$r['id']]);
}
echo "Claims=$n · uebersetzbar=$ok · nicht abgedeckt=" . count($miss) . ($write ? " (geschrieben)" : " (Testlauf)") . "\n";
foreach (array_slice(array_unique($miss), 0, 20) as $x) echo "  [offen] $x\n";

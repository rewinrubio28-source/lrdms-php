<?php
require_once __DIR__ . '/includes/search_language.php';
$cases = [
    ['matanda', 'senior citizens'],
    ['MATANDA', 'senior citizens'],
    ['ayuda para sa matanda', 'financial assistance para sa senior citizens'],
    ['matandang ORD-MATANDA-2024', 'senior citizen ORD-MATANDA-2024'],
    ['mga nakatatandang mamamayan', 'senior citizens'],
    ['nakatatanda', 'senior citizens'],
    ['tulong pinansiyal', 'financial assistance'],
    ['pampublikong pagdinig', 'public hearing'],
    ['buwanang ayuda para sa matatanda sa Maynila', 'monthly financial assistance para sa senior citizens sa Maynila'],
    ['tulong pangkalusugan para sa kabataan', 'health assistance para sa youth'],
    ['pagkilala sa natatanging opisyal ng barangay', 'recognition sa outstanding barangay officials'],
    ['mga nanalo sa parangal ng pista ng pelikula', 'mga winners sa awards ng film festival'],
    ["ISKOLAR\tSA NUTRISYON", 'nutrition scholar'],
    ['(AYUDA), health assistance', '(financial assistance), health assistance'],
    ['financial assistance for senior citizens', 'financial assistance for senior citizens'],
    ['ORD-9047 RES-108-S2024', 'ORD-9047 RES-108-S2024'],
    ['ORD-AYUDA-2024', 'ORD-AYUDA-2024'],
    ['kasakit sakitín ayuda_reference', 'kasakit sakitín ayuda_reference'],
    ['', ''],
    ['Roxas Boulevard ORD-9047 paglalakad', 'Roxas Boulevard ORD-9047 walking'],
];
foreach ($cases as [$input, $expected]) {
    if (search_semantic_query($input) !== $expected) throw new RuntimeException('Vocabulary regression: ' . $input);
}
echo 'PASS: ' . count($cases) . " vocabulary checks\n";

<?php
require '/Users/vitorepf/develop/Atlas/atlas-server/vendor/autoload.php';
use App\Services\Ai\EngineeringKernel\Adapters\AtlasDevGateAdapter;
use App\Services\Ai\EngineeringKernel\SovereignHonestyFloor;
use App\Services\Ai\EngineeringKernel\AcceptanceBundle;

$cases = [
    'A. todas as ferramentas AUSENTES (a realidade desta maquina)' => [
        'status' => 'passed',
        'tools' => [
            ['slug' => 'gitleaks', 'category' => 'security', 'status' => 'skipped', 'reason' => 'tool_missing'],
            ['slug' => 'semgrep',  'category' => 'security', 'status' => 'skipped', 'reason' => 'tool_missing'],
        ],
        'findings' => [],
    ],
    'B. gitleaks RODOU e passou' => [
        'status' => 'passed',
        'tools' => [['slug' => 'gitleaks', 'category' => 'security', 'status' => 'passed']],
        'findings' => [],
    ],
    'C. gitleaks RODOU e achou segredo' => [
        'status' => 'failed',
        'tools' => [['slug' => 'gitleaks', 'category' => 'security', 'status' => 'failed']],
        'findings' => [['category' => 'security', 'tool' => 'gitleaks', 'rule_id' => 'aws-secret', 'blocks_resolved' => true]],
    ],
];
foreach ($cases as $label => $scan) {
    $sec = AtlasDevGateAdapter::securityFromScan($scan);
    printf("%-58s ran=%-5s secret_free=%-5s\n", $label, var_export($sec['ran'], true), var_export($sec['secret_free'], true));
}

echo "\n=== o piso soberano bloqueia de fato? ===\n";
$floor = new SovereignHonestyFloor;
$rf = new ReflectionMethod($floor, 'securityFree');
$rf->setAccessible(true);
foreach ($cases as $label => $scan) {
    $bundle = AcceptanceBundle::fromArray([
        'criteria_hash' => 'x', 'frozen_hash' => 'x', 'changed_files' => ['a.php'],
        'changed_public_symbols' => [], 'execution' => [], 'mutation_report' => [],
        'security_scan' => AtlasDevGateAdapter::securityFromScan($scan),
        'judges' => [], 'context_sufficiency' => 0, 'non_functional' => [], 'repair' => [],
    ]);
    $v = $rf->invoke($floor, $bundle);
    printf("%-58s -> %s\n", substr($label, 0, 3), json_encode($v));
}

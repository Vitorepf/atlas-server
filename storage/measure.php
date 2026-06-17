<?php
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$an = new \App\Services\Ai\AutonomousEvolution\Verify\AtlasLoopSignalAnalyzer();
$base = $an->aggregateComplexity([
    'app/Services/Ai/VentureFoundry/VentureIdeationService.php',
]);
$files = ['app/Services/Ai/VentureFoundry/VentureIdeationService.php'];
if (is_file(__DIR__.'/../app/Services/Ai/VentureFoundry/VentureIdeationServiceSupport.php')) {
    $files[] = 'app/Services/Ai/VentureFoundry/VentureIdeationServiceSupport.php';
}
$cand = $an->aggregateComplexity($files);
echo "BASELINE: " . json_encode($base) . "\n";
echo "CANDIDATE: " . json_encode($cand) . "\n";
echo "decisionsBase = " . ($base['total']-$base['methods']) . "\n";
echo "decisionsCand = " . ($cand['total']-$cand['methods']) . "\n";
echo "reduced (decisions-gate, per-file-gate, new-file-lock-on) = ";
var_export(\App\Services\Ai\AutonomousEvolution\Verify\AtlasLoopSignalAnalyzer::complexityReduced($base,$cand,true,true));
echo "\n";
echo "reduced (decisions-gate, per-file-gate, new-file-lock-OFF) = ";
config(['atlas.loop.complexity_new_file_fail_closed' => false]);
var_export(\App\Services\Ai\AutonomousEvolution\Verify\AtlasLoopSignalAnalyzer::complexityReduced($base,$cand,true,true));
echo "\n";
echo "structural = ";
var_export(\App\Services\Ai\AutonomousEvolution\Verify\AtlasLoopSignalAnalyzer::structuralComplexityReduced($base,$cand));
echo "\n";
echo "BASE per_method = " . json_encode($base['per_method'] ?? []) . "\n";
echo "CAND per_method = " . json_encode($cand['per_method'] ?? []) . "\n";

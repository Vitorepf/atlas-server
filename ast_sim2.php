<?php
// Simulate the verdict via artisan tinker.
namespace App\Services\Ai\AutonomousEvolution;
use App\Services\Ai\AutonomousEvolution\Verify\AtlasLoopSignalAnalyzer;
use Symfony\Component\Process\Process;

require __DIR__.'/vendor/autoload.php';

$file = '/Users/vitorepf/develop/Atlas/atlas-server/app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/FinalDeliveryQualityGateService.php';
$analyzer = new AtlasLoopSignalAnalyzer;

$candidate = $analyzer->aggregateComplexity([$file]);

$stash = new Process(['git', 'stash', 'push', '--include-untracked', '--quiet', '--', $file], dirname($file));
$stash->run();
$baseline = $analyzer->aggregateComplexity([$file]);
$pop = new Process(['git', 'stash', 'pop', '--quiet'], dirname($file));
$pop->run();

echo "CANDIDATE max: {$candidate['max_per_method']}  total: {$candidate['total']}  methods: {$candidate['methods']}\n";
echo "BASELINE  max: {$baseline['max_per_method']}  total: {$baseline['total']}  methods: {$baseline['methods']}\n";
echo "\nBASELINE per_method:\n";
foreach ($baseline['per_method'] as $k => $v) echo "  $k = $v\n";
echo "\nCANDIDATE per_method:\n";
foreach ($candidate['per_method'] as $k => $v) echo "  $k = $v\n";

// Apply the same logic the judge does (decisions gate, per-file gate).
$decisionsGate = (bool) (config('atlas.loop.complexity_decisions_gate') ?? true);
$perFileGate = (bool) (config('atlas.loop.complexity_per_file_max_gate') ?? true);
$methodIdentityGate = (bool) (config('atlas.loop.complexity_method_identity_gate') ?? false);

$candidateAgg = $decisionsGate ? ($candidate['total'] - $candidate['methods']) : $candidate['total'];
$baselineAgg = $decisionsGate ? ($baseline['total'] - $baseline['methods']) : $baseline['total'];
echo "\ndecisions gate: ".var_export($decisionsGate, true)."\n";
echo "per-file gate:  ".var_export($perFileGate, true)."\n";
echo "method-identity gate:  ".var_export($methodIdentityGate, true)."\n";
echo "candidateAgg=$candidateAgg  baselineAgg=$baselineAgg\n";

$reducedLegacy = AtlasLoopSignalAnalyzer::complexityReduced($baseline, $candidate, $decisionsGate, $perFileGate);
$reducedStructural = AtlasLoopSignalAnalyzer::structuralComplexityReduced($baseline, $candidate);
echo "\nLEGACY reduced: ".var_export($reducedLegacy, true)."\n";
echo "STRUCTURAL reduced: ".var_export($reducedStructural, true)."\n";

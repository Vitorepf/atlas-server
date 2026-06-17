<?php
// Simulate exactly what AtlasEvolutionFrozenJudge::complexityEarned does
// for the current workspace state (with/without the candidate diff).
require __DIR__.'/vendor/autoload.php';

use App\Services\Ai\AutonomousEvolution\AtlasEvolutionFrozenJudge;
use App\Services\Ai\AutonomousEvolution\Verify\AtlasLoopSignalAnalyzer;

$file = '/Users/vitorepf/develop/Atlas/atlas-server/app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/FinalDeliveryQualityGateService.php';
$analyzer = new AtlasLoopSignalAnalyzer;

// CANDIDATE: the file in working tree.
$candidate = $analyzer->aggregateComplexity([$file]);
echo "CANDIDATE (working tree):\n";
echo json_encode($candidate, JSON_PRETTY_PRINT), "\n\n";

// BASELINE: the file at HEAD (stashed).
$stash = new \Symfony\Component\Process\Process(['git', 'stash', 'push', '--include-untracked', '--quiet', '--', $file], dirname($file));
$stash->run();
$baseline = $analyzer->aggregateComplexity([$file]);

// Restore candidate
$pop = new \Symfony\Component\Process\Process(['git', 'stash', 'pop', '--quiet'], dirname($file));
$pop->run();

echo "BASELINE (HEAD):\n";
echo json_encode($baseline, JSON_PRETTY_PRINT), "\n\n";

$config = [
    'atlas.loop.complexity_decisions_gate' => true,
    'atlas.loop.complexity_per_file_max_gate' => true,
    'atlas.loop.complexity_new_file_fail_closed' => true,
    'atlas.loop.complexity_method_identity_gate' => true,
];
foreach ($config as $k => $v) { config([$k => $v]); }

$reducedLegacy = AtlasLoopSignalAnalyzer::complexityReduced($baseline, $candidate, true, true);
$reducedStructural = AtlasLoopSignalAnalyzer::structuralComplexityReduced($baseline, $candidate);

echo "LEGACY (complexityReduced):    ", var_export($reducedLegacy, true), "\n";
echo "STRUCTURAL (structural...):    ", var_export($reducedStructural, true), "\n";
echo "\n";
echo "candidate_max:   {$candidate['max_per_method']}\n";
echo "baseline_max:    {$baseline['max_per_method']}\n";
echo "candidate_dec:   ", ($candidate['total'] - $candidate['methods']), "\n";
echo "baseline_dec:    ", ($baseline['total'] - $baseline['methods']), "\n";

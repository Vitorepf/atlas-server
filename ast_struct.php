<?php
// Standalone simulation of AtlasLoopSignalAnalyzer::structuralComplexityReduced
// so we can prove the verdict without bootstrapping the full Laravel app.
require __DIR__.'/vendor/autoload.php';

use App\Services\Ai\AutonomousEvolution\Verify\AtlasLoopSignalAnalyzer;

$file = $argv[1] ?? '/Users/vitorepf/develop/Atlas/atlas-server/app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/FinalDeliveryQualityGateService.php';
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

$reducedLegacy = AtlasLoopSignalAnalyzer::complexityReduced($baseline, $candidate, true, true);
$reducedStructural = AtlasLoopSignalAnalyzer::structuralComplexityReduced($baseline, $candidate);

echo "LEGACY (complexityReduced):       ", var_export($reducedLegacy, true), "\n";
echo "STRUCTURAL (structural...):       ", var_export($reducedStructural, true), "\n";
echo "\n";
echo "candidate_max:   {$candidate['max_per_method']}\n";
echo "baseline_max:    {$baseline['max_per_method']}\n";
echo "candidate_dec:   ", ($candidate['total'] - $candidate['methods']), "\n";
echo "baseline_dec:    ", ($baseline['total'] - $baseline['methods']), "\n";
echo "candidate_total: {$candidate['total']}\n";
echo "baseline_total:  {$baseline['total']}\n";

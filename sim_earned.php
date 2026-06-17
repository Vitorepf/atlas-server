<?php
require __DIR__.'/vendor/autoload.php';

use App\Services\Ai\AutonomousEvolution\Verify\AtlasLoopSignalAnalyzer;

$file = $argv[1];
$abs = realpath($file);
$analyzer = new AtlasLoopSignalAnalyzer;

// CANDIDATE (working tree)
$candidate = $analyzer->aggregateComplexity([$abs]);
echo "CANDIDATE (working tree, host only):\n";
echo json_encode($candidate, JSON_PRETTY_PRINT), "\n\n";

$supportPath = realpath(dirname($file) . '/PlanDeliveryCertificationServiceSupport.php');
$candSupport = is_file($supportPath) ? $analyzer->aggregateComplexity([$supportPath]) : null;
echo "CANDIDATE Support: ", json_encode($candSupport, JSON_PRETTY_PRINT), "\n\n";

chdir(dirname($abs));
$stash = new \Symfony\Component\Process\Process(['git', 'stash', 'push', '--include-untracked', '--quiet'], dirname($abs));
$stash->run();
echo "Stash OK: ".var_export($stash->isSuccessful(), true)."\n\n";

try {
    $baseline = $analyzer->aggregateComplexity([$abs]);
    echo "BASELINE (after stash, host only):\n";
    echo json_encode($baseline, JSON_PRETTY_PRINT), "\n\n";

    $baseSupport = is_file($supportPath) ? $analyzer->aggregateComplexity([$supportPath]) : null;
    echo "BASELINE Support exists: ", var_export(is_file($supportPath), true), "\n\n";
} finally {
    $pop = new \Symfony\Component\Process\Process(['git', 'stash', 'pop', '--quiet'], dirname($abs));
    $pop->run();
    echo "Pop OK: ".var_export($pop->isSuccessful(), true)."\n";
}

echo "\n";
echo "BASELINE worst method: ".($baseline['worst_method'] ?? 'none')." with score ".$baseline['max_per_method']."\n";
echo "CANDIDATE worst method: ".($candidate['worst_method'] ?? 'none')." with score ".$candidate['max_per_method']."\n";
echo "Candidate decisions: ".($candidate['total'] - $candidate['methods'])."\n";
echo "Baseline decisions: ".($baseline['total'] - $baseline['methods'])."\n";

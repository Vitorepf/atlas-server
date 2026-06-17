<?php
require __DIR__.'/vendor/autoload.php';

use App\Services\Ai\AutonomousEvolution\Verify\AtlasLoopSignalAnalyzer;

$host = realpath($argv[1]);
$support = realpath(dirname($host) . '/PlanDeliveryCertificationServiceSupport.php');
$analyzer = new AtlasLoopSignalAnalyzer;

echo "=== CANDIDATE (host + support, working tree) ===\n";
$cand = $analyzer->aggregateComplexity([$host, $support]);
echo json_encode($cand, JSON_PRETTY_PRINT), "\n\n";

chdir(dirname($host));
$stash = new \Symfony\Component\Process\Process(['git', 'stash', 'push', '--include-untracked', '--quiet'], dirname($host));
$stash->run();
echo "Stash OK: ".var_export($stash->isSuccessful(), true)."\n\n";

try {
    echo "=== BASELINE (host only, no support, after stash) ===\n";
    $base = $analyzer->aggregateComplexity([$host]);
    echo json_encode($base, JSON_PRETTY_PRINT), "\n\n";

    echo "=== JURADO STRUCTURAL VERDICT ===\n";
    $verdict = AtlasLoopSignalAnalyzer::structuralComplexityReduced($base, $cand);
    echo "STRUCTURAL REDUCED: ".var_export($verdict, true)."\n\n";

    $baseAgg = $base['total'] - $base['methods'];
    $candAgg = $cand['total'] - $cand['methods'];
    echo "Baseline decisions (total - methods): {$base['total']} - {$base['methods']} = $baseAgg\n";
    echo "Candidate decisions (total - methods): {$cand['total']} - {$cand['methods']} = $candAgg\n";
    echo "Aggregate gate (candAgg <= baseAgg): ".var_export($candAgg <= $baseAgg, true)."\n\n";

    $baseWorst = $base['max_per_method'];
    echo "Baseline max per method: $baseWorst\n";
    echo "Candidate identities >= baselineWorst? ";
    $violating = [];
    foreach ($cand['per_method'] as $id => $score) {
        if (!array_key_exists($id, $base['per_method']) && (int)$score >= $baseWorst) {
            $violating[] = "$id ($score)";
        }
    }
    echo $violating ? "YES: " . implode(", ", $violating) : "no\n";

    echo "\nKept identities dropped? ";
    $keptDropped = [];
    foreach ($cand['per_method'] as $id => $score) {
        if (array_key_exists($id, $base['per_method']) && (int)$score < (int)$base['per_method'][$id]) {
            $keptDropped[] = "$id: {$base['per_method'][$id]} -> $score";
        }
    }
    echo $keptDropped ? "YES: " . implode("; ", $keptDropped) : "no\n";

    echo "\nKept identities regressed? ";
    $regressed = [];
    foreach ($cand['per_method'] as $id => $score) {
        if (array_key_exists($id, $base['per_method']) && (int)$score > (int)$base['per_method'][$id]) {
            $regressed[] = "$id: {$base['per_method'][$id]} -> $score";
        }
    }
    echo $regressed ? "YES: " . implode("; ", $regressed) : "no\n";
} finally {
    $pop = new \Symfony\Component\Process\Process(['git', 'stash', 'pop', '--quiet'], dirname($host));
    $pop->run();
}

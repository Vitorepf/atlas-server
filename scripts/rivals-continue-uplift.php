#!/usr/bin/env php
<?php

/**
 * Continue remaining uplift families from known solid run IDs without re-prepare.
 * Used by overnight supervisor when the battery process dies mid-flight.
 */

use App\Services\Ai\Rivals\Core\FaseABatteryOrchestrator;
use App\Services\Ai\Rivals\Core\RunPaths;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$families = [
    ['run_id' => '20260712_195649_c5ec8a98', 'suite_id' => 'hal_harness'],
    ['run_id' => '20260712_195649_f134c20d', 'suite_id' => 'swe_bench_live'],
    ['run_id' => '20260712_195649_dacc30c9', 'suite_id' => 'terminal_bench'],
    ['run_id' => '20260712_195649_89170b86', 'suite_id' => 'bfcl'],
    ['run_id' => '20260712_195649_cf15a760', 'suite_id' => 'aider_polyglot'],
];

$orch = app(FaseABatteryOrchestrator::class);
$ref = new ReflectionClass($orch);
$exec = $ref->getMethod('executePreparedSuite');
$exec->setAccessible(true);
$finish = $ref->getMethod('finishRunPipeline');
$finish->setAccessible(true);

$results = [];
foreach ($families as $row) {
    $runId = $row['run_id'];
    $suiteId = $row['suite_id'];
    $upliftPath = RunPaths::runDir($runId).'/uplift.json';
    if (is_file($upliftPath)) {
        $results[] = ['suite_id' => $suiteId, 'status' => 'already_done'];
        echo "skip $suiteId already uplift\n";

        continue;
    }
    $manifest = RunPaths::nativeManifestPath($runId);
    if (! is_file($manifest)) {
        $results[] = ['suite_id' => $suiteId, 'status' => 'manifest_missing'];
        echo "ERR $suiteId manifest missing\n";

        continue;
    }

    $entries = json_decode((string) file_get_contents($manifest), true)['entries'] ?? [];
    $unitsDir = RunPaths::runDir($runId).'/external_results/units';
    $unitCount = is_dir($unitsDir) ? count(glob($unitsDir.'/*.json') ?: []) : 0;
    if ($unitCount >= count($entries) && count($entries) > 0) {
        echo "finish-only $suiteId units=$unitCount/".count($entries)."\n";
        try {
            $finish->invoke($orch, $runId, $suiteId);
            $results[] = ['suite_id' => $suiteId, 'status' => 'finished'];
        } catch (Throwable $e) {
            $results[] = ['suite_id' => $suiteId, 'status' => 'finish_error', 'error' => $e->getMessage()];
            echo 'ERR finish '.$suiteId.': '.$e->getMessage()."\n";
        }

        continue;
    }

    echo "execute $suiteId run=$runId\n";
    try {
        $out = $exec->invoke($orch, $row + [
            'suite_id' => $suiteId,
            'run_id' => $runId,
        ], false);
        $results[] = ['suite_id' => $suiteId, 'status' => $out['status'] ?? 'ok'];
        echo "ok $suiteId\n";
    } catch (Throwable $e) {
        // Still try finish if units landed
        $unitCount = is_dir($unitsDir) ? count(glob($unitsDir.'/*.json') ?: []) : 0;
        echo 'ERR execute '.$suiteId.': '.$e->getMessage()." (units=$unitCount)\n";
        if ($unitCount >= count($entries) && count($entries) > 0) {
            try {
                $finish->invoke($orch, $runId, $suiteId);
                $results[] = ['suite_id' => $suiteId, 'status' => 'finished_after_error'];
                echo "finished after error $suiteId\n";
            } catch (Throwable $e2) {
                $results[] = ['suite_id' => $suiteId, 'status' => 'error', 'error' => $e->getMessage(), 'finish_error' => $e2->getMessage()];
            }
        } else {
            $results[] = ['suite_id' => $suiteId, 'status' => 'error', 'error' => $e->getMessage()];
        }
    }
}

file_put_contents(
    storage_path('atlas/rivals/logs/continue_uplift_'.date('Ymd_His').'.json'),
    json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
);
echo "done\n";

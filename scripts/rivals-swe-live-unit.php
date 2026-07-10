#!/usr/bin/env php
<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$options = getopt('', [
    'case-file:',
    'instance:',
    'model:',
    'scratch:',
    'timeout::',
]);
$caseFile = realpath((string) ($options['case-file'] ?? ''));
$instanceId = trim((string) ($options['instance'] ?? ''));
$model = trim((string) ($options['model'] ?? ''));
$scratch = (string) ($options['scratch'] ?? '');
$timeout = max(300, min(7200, (int) ($options['timeout'] ?? 3600)));
if ($caseFile === false || $instanceId === '' || $model === '' || $scratch === '') {
    fwrite(STDERR, "rivals_swe_live_invalid_arguments\n");
    exit(2);
}
$case = json_decode((string) file_get_contents($caseFile), true);
if (! is_array($case)
    || ($case['case_id'] ?? null) !== $instanceId
    || ! is_string($case['repo'] ?? null)
    || ! is_string($case['base_commit'] ?? null)
    || ! is_string($case['problem_statement'] ?? null)) {
    fwrite(STDERR, "rivals_swe_live_case_contract_invalid\n");
    exit(2);
}
File::ensureDirectoryExists($scratch);
$workspace = $scratch.'/workspace';
if (! is_dir($workspace.'/.git')) {
    $clone = new Process([
        'git', 'clone', '--filter=blob:none', '--no-checkout',
        'https://github.com/'.$case['repo'].'.git',
        $workspace,
    ]);
    $clone->setTimeout((float) $timeout);
    $clone->run();
    if (! $clone->isSuccessful()) {
        fwrite(STDERR, $clone->getErrorOutput());
        exit(1);
    }
    $fetch = new Process([
        'git', 'fetch', 'origin', $case['base_commit'], '--depth', '1',
    ], $workspace);
    $fetch->setTimeout((float) $timeout);
    $fetch->run();
    if (! $fetch->isSuccessful()) {
        fwrite(STDERR, $fetch->getErrorOutput());
        exit(1);
    }
    $checkout = new Process(['git', 'checkout', '--detach', 'FETCH_HEAD'], $workspace);
    $checkout->run();
    if (! $checkout->isSuccessful()) {
        fwrite(STDERR, $checkout->getErrorOutput());
        exit(1);
    }
}

$prompt = "Solve this issue in the checked-out repository. Make the smallest production-quality change, run focused tests, and do not alter tests merely to pass them.\n\n"
    .$case['problem_statement'];
$promptFile = $workspace.'/.rivals_task.md';
file_put_contents($promptFile, $prompt);
$solver = new Process([
    PHP_BINARY,
    base_path('scripts/rivals-hermes-bare.php'),
    '--workspace='.$workspace,
    '--prompt-file='.$promptFile,
    '--model='.$model,
    '--timeout='.$timeout,
], base_path());
$solver->setTimeout((float) $timeout);
$solver->run();
if (! $solver->isSuccessful()) {
    fwrite(STDERR, $solver->getErrorOutput());
    exit(1);
}
$diff = new Process(['git', 'diff', '--binary'], $workspace);
$diff->run();
$predictions = [$instanceId => ['model_patch' => $diff->getOutput()]];
$predictionsPath = $scratch.'/predictions.json';
file_put_contents(
    $predictionsPath,
    json_encode($predictions, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
);
$providerReceipt = json_decode(
    (string) file_get_contents($workspace.'/.rivals_bare_provider.json'),
    true,
) ?? [];
file_put_contents($scratch.'/predictions.usage.json', json_encode([
    'duration_sec' => ((float) ($providerReceipt['wall_ms'] ?? 0)) / 1000,
    'usage' => $providerReceipt['usage'] ?? null,
    'started_at' => $providerReceipt['started_at'] ?? null,
    'finished_at' => $providerReceipt['finished_at'] ?? null,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

$evaluation = new Process([
    'python',
    '-m', 'evaluation.evaluation',
    '--dataset', 'SWE-bench-Live/SWE-bench-Live',
    '--platform', 'linux',
    '--patch_dir', $predictionsPath,
    '--output_dir', $scratch,
    '--workers', '1',
    '--overwrite', '1',
    '--instance_ids', $instanceId,
], getcwd());
$evaluation->setTimeout((float) $timeout);
$evaluation->run();
echo $solver->getOutput().$evaluation->getOutput();
if (! $evaluation->isSuccessful()) {
    fwrite(STDERR, $evaluation->getErrorOutput());
    exit(1);
}
exit(0);

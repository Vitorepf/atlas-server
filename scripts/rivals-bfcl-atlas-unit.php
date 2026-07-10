#!/usr/bin/env php
<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$options = getopt('', ['case-file:', 'model:', 'registry-model:', 'scratch:', 'timeout::']);
$caseFile = realpath((string) ($options['case-file'] ?? ''));
$model = trim((string) ($options['model'] ?? ''));
$registryModel = trim((string) ($options['registry-model'] ?? ''));
$scratch = (string) ($options['scratch'] ?? '');
$timeout = max(60, min(7200, (int) ($options['timeout'] ?? 3600)));
$case = $caseFile !== false
    ? json_decode((string) file_get_contents($caseFile), true)
    : null;
if (! is_array($case)
    || ! is_string($case['native_category'] ?? null)
    || ! is_string($case['native_test_id'] ?? null)
    || $model === ''
    || $registryModel === ''
    || $scratch === '') {
    fwrite(STDERR, "rivals_bfcl_atlas_invalid_arguments\n");
    exit(2);
}
$dataset = getcwd().'/berkeley-function-call-leaderboard/bfcl_eval/data/BFCL_v4_'
    .$case['native_category'].'.json';
$nativeCase = null;
foreach (file($dataset, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    $candidate = json_decode($line, true);
    if (is_array($candidate) && ($candidate['id'] ?? null) === $case['native_test_id']) {
        $nativeCase = $candidate;
        break;
    }
}
if (! is_array($nativeCase)) {
    fwrite(STDERR, "rivals_bfcl_native_case_missing\n");
    exit(2);
}
$workspace = $scratch.'/workspace';
File::ensureDirectoryExists($workspace);
file_put_contents($workspace.'/task.json', json_encode($nativeCase, JSON_PRETTY_PRINT));
file_put_contents($workspace.'/answer.json', "[]\n");
$gitCommands = [
    ['git', 'init', '-q'],
    ['git', 'config', 'user.email', 'rivals@atlas.local'],
    ['git', 'config', 'user.name', 'Atlas Rivals'],
    ['git', 'add', '.'],
    ['git', 'commit', '-qm', 'BFCL task baseline'],
];
foreach ($gitCommands as $argv) {
    $git = new Process($argv, $workspace);
    $git->run();
    if (! $git->isSuccessful()) {
        fwrite(STDERR, $git->getErrorOutput());
        exit(1);
    }
}
$promptFile = $workspace.'/.rivals_task.md';
file_put_contents($promptFile, <<<'PROMPT'
Read task.json. Determine the exact native function call(s) that answer the user question using only the declared functions. Edit answer.json to a JSON array. Each item must be an object with exactly one key: the declared function name; its value must be a JSON-encoded string containing that function's argument object. Do not edit task.json.
PROMPT);
$solver = new Process([
    PHP_BINARY,
    base_path('scripts/rivals-atlas-dev-bridge.php'),
    '--workspace='.$workspace,
    '--prompt-file='.$promptFile,
    '--model='.$model,
    '--timeout='.$timeout,
], base_path());
$solver->setTimeout((float) $timeout);
$solver->run();
$proofPath = $workspace.'/.rivals_atlas_dev_bridge.json';
$proof = is_file($proofPath)
    ? (json_decode((string) file_get_contents($proofPath), true) ?? [])
    : [];
$answer = json_decode((string) file_get_contents($workspace.'/answer.json'), true);
if (! $solver->isSuccessful()
    || ($proof['real_provider'] ?? false) !== true
    || ! is_array($answer)) {
    fwrite(STDERR, $solver->getErrorOutput());
    exit(1);
}
$usage = (array) ($proof['usage'] ?? []);
$resultDir = $scratch.'/result/'.$registryModel.'/non_live';
File::ensureDirectoryExists($resultDir);
file_put_contents(
    $resultDir.'/BFCL_v4_'.$case['native_category'].'_result.json',
    json_encode([
        'id' => $case['native_test_id'],
        'result' => $answer,
        'input_token_count' => (int) ($usage['input_tokens'] ?? 0),
        'output_token_count' => (int) ($usage['output_tokens'] ?? 0),
        'latency' => ((float) ($proof['wall_ms'] ?? 0)) / 1000,
    ], JSON_UNESCAPED_SLASHES).PHP_EOL,
);
echo $solver->getOutput();
exit(0);

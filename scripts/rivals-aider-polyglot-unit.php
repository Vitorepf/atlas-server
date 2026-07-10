#!/usr/bin/env php
<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$options = getopt('', ['case-file:', 'model:', 'scratch:', 'timeout::']);
$caseFile = realpath((string) ($options['case-file'] ?? ''));
$model = trim((string) ($options['model'] ?? ''));
$scratch = (string) ($options['scratch'] ?? '');
$timeout = max(60, min(7200, (int) ($options['timeout'] ?? 3600)));
$case = $caseFile !== false
    ? json_decode((string) file_get_contents($caseFile), true)
    : null;
if (! is_array($case)
    || ($case['language'] ?? null) !== 'rust'
    || ! is_string($case['native_task_id'] ?? null)
    || $model === ''
    || $scratch === '') {
    fwrite(STDERR, "rivals_aider_polyglot_invalid_arguments\n");
    exit(2);
}
$source = getcwd().'/tmp.benchmarks/polyglot-benchmark/'
    .$case['language'].'/exercises/practice/'.$case['native_task_id'];
$workspace = $scratch.'/workspace';
if (! is_dir($source) || (is_dir($workspace) && ! File::deleteDirectory($workspace))) {
    fwrite(STDERR, "rivals_aider_polyglot_source_unavailable\n");
    exit(2);
}
File::ensureDirectoryExists($scratch);
if (! File::copyDirectory($source, $workspace)) {
    fwrite(STDERR, "rivals_aider_polyglot_copy_failed\n");
    exit(1);
}
$git = fn (array $argv): Process => new Process($argv, $workspace);
foreach ([
    ['git', 'init', '-q'],
    ['git', 'config', 'user.email', 'rivals@atlas.local'],
    ['git', 'config', 'user.name', 'Atlas Rivals'],
    ['git', 'add', '.'],
    ['git', 'commit', '-qm', 'exercise baseline'],
] as $argv) {
    $process = $git($argv);
    $process->run();
    if (! $process->isSuccessful()) {
        fwrite(STDERR, $process->getErrorOutput());
        exit(1);
    }
}
$instructions = $workspace.'/.docs/instructions.md';
$promptFile = $workspace.'/.rivals_task.md';
file_put_contents(
    $promptFile,
    "Implement this Rust exercise completely. Edit only the exercise implementation, then run cargo test.\n\n"
    .(is_file($instructions) ? file_get_contents($instructions) : (string) $case['title']),
);
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
if (! $solver->isSuccessful() || ($proof['real_provider'] ?? false) !== true) {
    fwrite(STDERR, $solver->getErrorOutput());
    exit(1);
}
$test = new Process(['cargo', 'test', '--quiet'], $workspace);
$test->setTimeout(600);
$test->run();
$usage = (array) ($proof['usage'] ?? []);
$result = [
    'testdir' => $workspace,
    'testcase' => $case['native_task_id'],
    'model' => $model,
    'edit_format' => 'atlas_dev',
    'tests_outcomes' => [$test->isSuccessful()],
    'cost' => (float) ($usage['cost_usd'] ?? 0.0),
    'duration' => ((float) ($proof['wall_ms'] ?? 0)) / 1000,
    'test_timeouts' => 0,
    'num_error_outputs' => $solver->isSuccessful() ? 0 : 1,
    'prompt_tokens' => (int) ($usage['input_tokens'] ?? 0),
    'completion_tokens' => (int) ($usage['output_tokens'] ?? 0),
];
file_put_contents(
    $workspace.'/.aider.results.json',
    json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
);
echo $solver->getOutput().$test->getOutput();
exit(0);

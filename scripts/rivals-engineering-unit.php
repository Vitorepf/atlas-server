#!/usr/bin/env php
<?php

use App\Services\Ai\Rivals\Adapters\External\EngineeringNativeSuiteAdapter;
use App\Services\Ai\Rivals\Support\RunPaths;
use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\Process\Process;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$options = getopt('', [
    'suite:',
    'case-file:',
    'model:',
    'registry-model:',
    'runtime:',
    'scratch:',
    'rep:',
    'timeout::',
    'plan',
]);
$suite = trim((string) ($options['suite'] ?? ''));
$caseFile = realpath((string) ($options['case-file'] ?? ''));
$model = trim((string) ($options['model'] ?? ''));
$registryModel = trim((string) ($options['registry-model'] ?? ''));
$runtime = trim((string) ($options['runtime'] ?? ''));
$scratchInput = rtrim((string) ($options['scratch'] ?? ''), '/');
$runRoot = realpath(RunPaths::root());
if ($scratchInput !== ''
    && str_starts_with($scratchInput, '/')
    && $runRoot !== false
    && str_starts_with($scratchInput, $runRoot.'/runs/')) {
    RunPaths::ensureDir($scratchInput);
}
$scratch = $scratchInput !== '' ? realpath($scratchInput) : false;
$repetition = (int) ($options['rep'] ?? 0);
$timeout = max(60, min(7200, (int) ($options['timeout'] ?? 3600)));
$case = $caseFile !== false
    ? json_decode((string) file_get_contents($caseFile), true)
    : null;
if (! in_array($suite, EngineeringNativeSuiteAdapter::SUITE_IDS, true)
    || ! is_array($case)
    || ($case['suite_id'] ?? null) !== $suite
    || $model === ''
    || $registryModel === ''
    || $scratch === false
    || $repetition < 1) {
    fwrite(STDERR, "rivals_engineering_unit_invalid_arguments\n");
    exit(2);
}
if (! in_array($runtime, ['bare', 'atlas_dev'], true)) {
    fwrite(STDERR, "rivals_engineering_unit_invalid_runtime:{$runtime}\n");
    exit(2);
}

$workspace = $scratch.'/workspace';
$promptFile = $workspace.'/.rivals_task.md';
$solverScript = $runtime === 'bare'
    ? base_path('scripts/rivals-hermes-bare.php')
    : base_path('scripts/rivals-atlas-dev-bridge.php');
$solverArgv = [
    PHP_BINARY,
    $solverScript,
    '--workspace='.$workspace,
    '--prompt-file='.$promptFile,
    '--model='.$model,
    '--timeout='.$timeout,
];
if (array_key_exists('plan', $options)) {
    echo json_encode([
        'schema_version' => 'atlas.rivals2.engineering_native_unit_plan.v1',
        'suite_id' => $suite,
        'case_id' => $case['case_id'],
        'runtime' => $runtime,
        'model' => $model,
        'registry_model' => $registryModel,
        'repetition' => $repetition,
        'workspace' => $workspace,
        'solver_argv' => $solverArgv,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit(0);
}

$pythonCandidates = [
    getcwd().'/.venv/bin/python',
    getcwd().'/library_based_code_generation/.venv/bin/python',
    getcwd().'/.atlas-venv/bin/python',
    '/opt/homebrew/bin/python3',
    '/usr/bin/python3',
];
$python = collect($pythonCandidates)->first(
    static fn (string $candidate): bool => is_executable($candidate),
);
if (! is_string($python)) {
    fwrite(STDERR, "{$suite}_engineering_native_python_missing\n");
    exit(1);
}

$driver = base_path('scripts/rivals_engineering_driver.py');
$prepare = new Process([
    $python,
    $driver,
    'prepare',
    '--suite='.$suite,
    '--case-file='.$caseFile,
    '--repo-root='.getcwd(),
    '--workspace='.$workspace,
], getcwd());
$prepare->setTimeout((float) $timeout);
$prepare->run();
if (! $prepare->isSuccessful() || ! is_file($promptFile)) {
    fwrite(STDERR, trim($prepare->getErrorOutput()."\n".$prepare->getOutput())."\n");
    exit(1);
}
$prepared = json_decode(trim($prepare->getOutput()), true);
$artifactTarget = is_array($prepared)
    ? (string) ($prepared['artifact_target'] ?? '')
    : '';
$workspacePrefix = rtrim($workspace, '/').'/';
if ($artifactTarget === '' || ! str_starts_with($artifactTarget, $workspacePrefix)) {
    fwrite(STDERR, "{$suite}_engineering_native_artifact_target_invalid\n");
    exit(1);
}
$artifactTarget = substr($artifactTarget, strlen($workspacePrefix));
if ($artifactTarget === ''
    || str_starts_with($artifactTarget, '/')
    || str_contains($artifactTarget, '..')
    || str_contains($artifactTarget, ',')
    || preg_match('/\A[A-Za-z0-9._\/-]{1,512}\z/', $artifactTarget) !== 1) {
    fwrite(STDERR, "{$suite}_engineering_native_artifact_target_invalid\n");
    exit(1);
}
if ($runtime === 'atlas_dev') {
    $solverArgv[] = '--artifact-target='.$artifactTarget;
}

foreach ([
    ['git', 'init', '-q'],
    ['git', 'config', 'user.email', 'rivals@atlas.local'],
    ['git', 'config', 'user.name', 'Atlas Rivals'],
    ['git', 'add', '.'],
    ['git', 'commit', '-qm', 'Engineering native task baseline'],
] as $argv) {
    $git = new Process($argv, $workspace);
    $git->run();
    if (! $git->isSuccessful()) {
        fwrite(STDERR, $git->getErrorOutput());
        exit(1);
    }
}

$startedAt = now();
$solver = new Process($solverArgv, base_path());
$solver->setTimeout((float) $timeout);
$solverException = null;
try {
    $solver->run();
} catch (Throwable $exception) {
    $solverException = $exception;
}
$finishedAt = now();
$proofPath = $workspace.'/'.($runtime === 'bare'
    ? '.rivals_bare_provider.json'
    : '.rivals_atlas_dev_bridge.json');
$proof = is_file($proofPath)
    ? (json_decode((string) file_get_contents($proofPath), true) ?? [])
    : [];
$usage = (array) ($proof['usage'] ?? []);
$tokensIn = is_numeric($usage['input_tokens'] ?? null) ? (int) $usage['input_tokens'] : 0;
$tokensOut = is_numeric($usage['output_tokens'] ?? null) ? (int) $usage['output_tokens'] : 0;
$proofValid = $runtime === 'bare'
    ? (($proof['status'] ?? null) === 'passed'
        && ($proof['provider'] ?? null) === 'verboo'
        && ($proof['runtime'] ?? null) === 'bare')
    : (($proof['real_provider'] ?? false) === true
        && ($proof['atlas_runtime'] ?? false) === true
        && ($proof['execution'] ?? null) === 'atlas_cli_dev_efficient');
$proofValid = $proofValid && $tokensIn > 0 && $tokensOut > 0;
if (! $proofValid) {
    $reason = $solverException instanceof Throwable
        ? $solverException::class.':'.$solverException->getMessage()
        : trim($solver->getErrorOutput());
    fwrite(STDERR, "engineering_native_runtime_proof_invalid:{$runtime}:"
        .mb_substr(preg_replace('/\s+/', ' ', $reason) ?: 'missing_or_incomplete', 0, 800)."\n");
    exit(1);
}

$artifactPath = $scratch.'/native_artifact.json';
$evaluate = new Process([
    $python,
    $driver,
    'evaluate',
    '--suite='.$suite,
    '--case-file='.$caseFile,
    '--repo-root='.getcwd(),
    '--workspace='.$workspace,
    '--artifact='.$artifactPath,
], getcwd());
$evaluate->setTimeout((float) $timeout);
$evaluate->run();
$evaluation = json_decode(trim($evaluate->getOutput()), true);
if (! $evaluate->isSuccessful()
    || ! is_array($evaluation)
    || ! is_file($artifactPath)) {
    fwrite(STDERR, trim($evaluate->getErrorOutput()."\n".$evaluate->getOutput())."\n");
    exit(1);
}
$valid = ($evaluation['valid_result'] ?? false) === true;
$measurementType = (string) ($evaluation['measurement_type'] ?? 'binary');
$benchmarkPass = ($evaluation['benchmark_pass'] ?? $valid) === true;
$measuredStatus = $valid && ($measurementType === 'continuous' || $benchmarkPass)
    ? 'success'
    : 'failure';
$failureReason = $measuredStatus === 'success'
    ? null
    : trim((string) ($evaluation['failure_reason']
        ?? ($valid ? 'native_benchmark_failed' : 'native_evaluator_rejected_result')));
$payload = [
    'schema_version' => 'atlas.rivals2.engineering_native_unit.v1',
    'suite_id' => $suite,
    'model' => $registryModel,
    'results' => [[
        'case_id' => (string) $case['case_id'],
        'repetition' => $repetition,
        'task_type' => (string) $case['task_type'],
        'status' => $measuredStatus,
        'failure_class' => $measuredStatus === 'success' ? null : 'model_failure',
        'failure_reason' => $failureReason,
        'measurement_type' => $measurementType,
        'score_metric' => (string) ($evaluation['score_metric'] ?? 'success'),
        'score' => $evaluation['score'] ?? null,
        'native_metrics' => (array) ($evaluation['native_metrics'] ?? []),
        'wall_ms' => (int) $startedAt->diffInRealMilliseconds($finishedAt),
        'tokens_in' => $tokensIn,
        'tokens_out' => $tokensOut,
        'cost_usd' => 0.0,
        'started_at' => $startedAt->toIso8601String(),
        'finished_at' => $finishedAt->toIso8601String(),
        'native_artifact' => [
            'path' => $artifactPath,
            'sha256' => hash_file('sha256', $artifactPath),
        ],
    ]],
];
file_put_contents(
    $scratch.'/native_result.json',
    json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION),
);
echo json_encode([
    'schema_version' => 'atlas.rivals2.engineering_native_unit_execution.v1',
    'suite_id' => $suite,
    'case_id' => $case['case_id'],
    'runtime' => $runtime,
    'status' => $valid ? 'measured' : 'invalid_result',
    'benchmark_status' => $measuredStatus,
    'score' => $evaluation['score'] ?? null,
    'tokens_in' => $tokensIn,
    'tokens_out' => $tokensOut,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
exit(0);

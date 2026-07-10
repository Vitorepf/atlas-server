#!/usr/bin/env php
<?php

use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\Process\Process;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$options = getopt('', [
    'workspace:',
    'prompt-file:',
    'model:',
    'timeout::',
    'dry-run',
]);
$workspace = realpath((string) ($options['workspace'] ?? ''));
$promptFile = realpath((string) ($options['prompt-file'] ?? ''));
$model = trim((string) ($options['model'] ?? ''));
$timeout = max(60, min(7200, (int) ($options['timeout'] ?? 3600)));
if ($workspace === false || ! is_dir($workspace)
    || $promptFile === false || ! is_file($promptFile)
    || $model === '') {
    fwrite(STDERR, "usage: php scripts/rivals-atlas-dev-bridge.php --workspace=<isolated-worktree> --prompt-file=<file> --model=<model> [--timeout=3600] [--dry-run]\n");
    exit(2);
}
if (! str_starts_with($promptFile, $workspace.'/')) {
    fwrite(STDERR, "rivals_atlas_dev_prompt_outside_workspace\n");
    exit(2);
}

$registry = (array) config('atlas_rivals.models', []);
$modelSpec = collect($registry)->first(
    fn (array $spec, string $id): bool => $id === $model
        || ($spec['cli_model'] ?? null) === $model
) ?? [];
$provider = match ($modelSpec['provider'] ?? null) {
    'anthropic' => 'claude_cli',
    'openai' => 'codex_cli',
    'cursor' => 'cursor_cli',
    default => null,
};
$ai = ($modelSpec['provider'] ?? null) === 'hermes' ? 'hermes' : null;
$argv = [
    PHP_BINARY,
    base_path('artisan'),
    'atlas:cli:dev',
    trim((string) file_get_contents($promptFile)),
    '--workspace='.$workspace,
    '--model='.$model,
    '--single-provider',
    '--no-decide',
    '--fallback-disabled',
    '--allow-write',
    '--operator',
    '--sandbox=worktree',
    '--provider-runtime=host',
    '--auto-test',
    '--efficient',
    '--yes',
    '--json',
    '--no-notify',
    '--timeout='.$timeout,
];
if ($provider !== null) {
    $argv[] = '--provider='.$provider;
}
if ($ai !== null) {
    $argv[] = '--ai='.$ai;
}

if (array_key_exists('dry-run', $options)) {
    echo json_encode([
        'schema_version' => 'atlas.rivals2.atlas_dev_bridge_plan.v1',
        'workspace' => $workspace,
        'model' => $model,
        'provider' => $provider,
        'ai' => $ai,
        'fair_mode' => [
            'single_provider' => true,
            'decide_disabled' => true,
            'fallback_disabled' => true,
            'deterministic_fast_path_disabled' => true,
        ],
        'argv' => $argv,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit(0);
}

$startedAt = now();
$process = new Process($argv, base_path(), [
    'ATLAS_DEV_DETERMINISTIC_FAST_PATH_ENABLED' => 'false',
    'ATLAS_DEV_HERMES_EXECUTION_TRANSPORT' => 'cli',
    'ATLAS_DEV_PROVIDER_CONSULT_DECIDE' => 'false',
    'ATLAS_AI_HERMES_MODEL' => $model,
    'ATLAS_AI_HERMES_MODEL_IDENTITY' => $model,
    'ATLAS_AI_HERMES_PROVIDER' => 'verboo',
    'ATLAS_AI_HERMES_TIMEOUT_SECONDS' => (string) $timeout,
]);
$process->setTimeout((float) $timeout);
$process->run();
$finishedAt = now();
$outputPayload = json_decode(trim($process->getOutput()), true);
$providerCall = is_array($outputPayload)
    ? (array) data_get($outputPayload, 'run.provider_call', [])
    : [];
$actualProvider = (string) ($providerCall['provider'] ?? '');
$actualModel = (string) ($providerCall['model_family'] ?? '');
$providerCalls = (int) ($providerCall['provider_calls'] ?? 0);
$providerExitCode = is_numeric($providerCall['exit_code'] ?? null)
    ? (int) $providerCall['exit_code']
    : null;
$providerErrors = array_values(array_filter((array) ($providerCall['error_codes'] ?? [])));
$providerLockVerified = $actualProvider === 'hermes_cli'
    && $actualModel === $model
    && $providerCalls > 0
    && $providerExitCode === 0
    && $providerErrors === [];
$inputTokens = is_numeric($providerCall['tokens_in'] ?? null)
    ? (int) $providerCall['tokens_in']
    : null;
$outputTokens = is_numeric($providerCall['tokens_out'] ?? null)
    ? (int) $providerCall['tokens_out']
    : null;
$costUsd = is_numeric($providerCall['estimated_cost_usd'] ?? null)
    ? (float) $providerCall['estimated_cost_usd']
    : null;
$receipt = [
    'schema_version' => 'atlas.rivals2.atlas_dev_bridge_receipt.v1',
    'status' => $providerLockVerified ? 'passed' : 'failed',
    'real_provider' => $providerLockVerified,
    'workspace' => $workspace,
    'model' => $actualModel !== '' ? $actualModel : $model,
    'expected_model' => $model,
    'provider' => $actualProvider !== '' ? $actualProvider : $provider,
    'ai' => $ai,
    'fair_mode' => [
        'single_provider' => $providerLockVerified,
        'decide_disabled' => $providerLockVerified,
        'fallback_disabled' => $providerLockVerified,
        'deterministic_fast_path_disabled' => true,
    ],
    'provider_call' => [
        'provider_calls' => $providerCalls,
        'exit_code' => $providerExitCode,
        'duration_ms' => $providerCall['duration_ms'] ?? null,
        'error_codes' => $providerErrors,
    ],
    'argv_hash' => hash('sha256', json_encode($argv, JSON_UNESCAPED_SLASHES)),
    'exit_code' => $process->getExitCode(),
    'started_at' => $startedAt->toIso8601String(),
    'finished_at' => $finishedAt->toIso8601String(),
    'wall_ms' => (int) $startedAt->diffInRealMilliseconds($finishedAt),
    'usage' => [
        'input_tokens' => $inputTokens,
        'output_tokens' => $outputTokens,
        'cost_usd' => $costUsd,
        'present' => $inputTokens !== null && $outputTokens !== null && $costUsd !== null,
    ],
    'stdout_sha256' => hash('sha256', $process->getOutput()),
    'stderr_sha256' => hash('sha256', $process->getErrorOutput()),
];
file_put_contents(
    $workspace.'/.rivals_atlas_dev_bridge.json',
    json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
);
echo $process->getOutput();
if (! $providerLockVerified) {
    fwrite(STDERR, $process->getErrorOutput());
    exit(1);
}
exit(0);

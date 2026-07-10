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
]);
$process->setTimeout((float) $timeout);
$process->run();
$finishedAt = now();
$outputPayload = json_decode(trim($process->getOutput()), true);
$inputTokens = is_array($outputPayload)
    ? firstNumericValue($outputPayload, ['input_tokens', 'tokens_in', 'prompt_tokens'])
    : null;
$outputTokens = is_array($outputPayload)
    ? firstNumericValue($outputPayload, ['output_tokens', 'tokens_out', 'completion_tokens'])
    : null;
$costUsd = is_array($outputPayload)
    ? firstNumericValue($outputPayload, ['cost_usd', 'total_cost_usd'])
    : null;
$receipt = [
    'schema_version' => 'atlas.rivals2.atlas_dev_bridge_receipt.v1',
    'status' => $process->isSuccessful() ? 'passed' : 'failed',
    'real_provider' => true,
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
if (! $process->isSuccessful()) {
    fwrite(STDERR, $process->getErrorOutput());
    exit(1);
}
exit(0);

function firstNumericValue(array $payload, array $keys): int|float|null
{
    foreach ($keys as $key) {
        if (array_key_exists($key, $payload) && is_numeric($payload[$key])) {
            return $payload[$key] + 0;
        }
    }
    foreach ($payload as $value) {
        if (! is_array($value)) {
            continue;
        }
        $found = firstNumericValue($value, $keys);
        if ($found !== null) {
            return $found;
        }
    }

    return null;
}

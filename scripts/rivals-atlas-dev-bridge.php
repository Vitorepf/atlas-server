#!/usr/bin/env php
<?php

/**
 * Rivals atlas_dev arm bridge.
 *
 * For Hermes/Verboo models (Fase A primary), invoke Atlas-governed one-shot
 * `hermes -z` on the isolated worktree. The full `atlas:cli:dev --efficient`
 * path currently burns the wall-clock budget on a read-only `hermes chat`
 * patch_plan stage and never reaches a real edit on disposable SWE/HAL trees —
 * that blocked every atlas_dev uplift receipt.
 *
 * Non-Hermes providers still route through atlas:cli:dev (fair-mode flags).
 */

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
$useHermesOnesHot = $ai === 'hermes';

$prompt = trim((string) file_get_contents($promptFile));
$atlasPrompt = "# Atlas Dev (rivals atlas_dev arm)\n"
    ."You are executing inside an Atlas-isolated worktree. Apply a production-quality\n"
    ."patch for the task below. Edit files in-place; do not ask clarifying questions.\n\n"
    .$prompt;

$usagePath = $workspace.'/.rivals_hermes_usage.json';
$hermesArgv = [
    'hermes',
    '-z', $atlasPrompt,
    '--provider', 'verboo',
    '-m', $model,
    '--usage-file', $usagePath,
    '--yolo',
];

$cliDevArgv = [
    PHP_BINARY,
    base_path('artisan'),
    'atlas:cli:dev',
    $prompt,
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
    $cliDevArgv[] = '--provider='.$provider;
}
if ($ai !== null) {
    $cliDevArgv[] = '--ai='.$ai;
}

$argv = $useHermesOnesHot ? $hermesArgv : $cliDevArgv;

if (array_key_exists('dry-run', $options)) {
    echo json_encode([
        'schema_version' => 'atlas.rivals2.atlas_dev_bridge_plan.v1',
        'workspace' => $workspace,
        'model' => $model,
        'provider' => $provider,
        'ai' => $ai,
        'execution' => $useHermesOnesHot ? 'hermes_cli_oneshot' : 'atlas_cli_dev_efficient',
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
$path = getenv('PATH') ?: ($_ENV['PATH'] ?? '/usr/bin:/bin');
$localBin = getenv('HOME') ? rtrim((string) getenv('HOME'), '/').'/.local/bin' : '';
if ($localBin !== '' && is_dir($localBin) && ! str_contains($path, $localBin)) {
    $path = $localBin.':'.$path;
}

if ($useHermesOnesHot) {
    $process = new Process($hermesArgv, $workspace, [
        'PATH' => $path,
        'ATLAS_RIVALS_RUNTIME_EXECUTION' => 'true',
        'ATLAS_AI_HERMES_PROVIDER' => 'verboo',
        'ATLAS_AI_HERMES_MODEL' => $model,
    ]);
    $process->setTimeout((float) $timeout);
    $process->run();
    $finishedAt = now();
    $usage = is_file($usagePath)
        ? (json_decode((string) file_get_contents($usagePath), true) ?? [])
        : [];
    $inputTokens = is_numeric($usage['input_tokens'] ?? null) ? (int) $usage['input_tokens'] : null;
    $outputTokens = is_numeric($usage['output_tokens'] ?? null) ? (int) $usage['output_tokens'] : null;
    $providerLockVerified = $process->isSuccessful()
        && (($usage['completed'] ?? false) === true || ($inputTokens !== null && $outputTokens !== null));
    $receipt = [
        'schema_version' => 'atlas.rivals2.atlas_dev_bridge_receipt.v1',
        'status' => $providerLockVerified ? 'passed' : 'failed',
        'real_provider' => $providerLockVerified,
        'workspace' => $workspace,
        'model' => $model,
        'expected_model' => $model,
        'provider' => 'hermes_cli',
        'ai' => 'hermes',
        'execution' => 'hermes_cli_oneshot',
        // ⚠️ O ATLAS NÃO RODA NESTE CAMINHO. `hermes -z` é o Hermes CLI puro:
        // nenhum artisan, nenhuma memória do Atlas, nenhum Decide, nenhuma
        // governança. O "Atlas" aqui é um worktree isolado mais um prefixo de
        // prompt. Declarar false é o ponto: o uplift EXIGE atlas_runtime===true,
        // então este braço vira "não medido" em vez de virar um delta rotulado
        // "com Atlas" que na verdade compara harness de agente.
        //
        // Os campos fair_mode abaixo descrevem comportamento do ATLAS (Decide
        // desligado, sem fallback). Com o Atlas fora do laço eles não têm sujeito
        // — eram hardcoded `true` e o portão do uplift conferia esse `true` fixo,
        // escrito pelo próprio medido sobre si. Um gate que lê a autodeclaração
        // do medido não é gate.
        'atlas_runtime' => false,
        'fair_mode' => [
            'single_provider' => $providerLockVerified,
            'decide_disabled' => false,
            'fallback_disabled' => false,
            'deterministic_fast_path_disabled' => false,
        ],
        'provider_call' => [
            'provider' => 'hermes_cli',
            'model_family' => $model,
            'provider_calls' => $providerLockVerified ? 1 : 0,
            'exit_code' => $process->getExitCode(),
            'duration_ms' => (int) $startedAt->diffInRealMilliseconds($finishedAt),
            'error_codes' => $providerLockVerified ? [] : ['hermes_oneshot_failed'],
            'tokens_in' => $inputTokens,
            'tokens_out' => $outputTokens,
            'estimated_cost_usd' => 0.0,
        ],
        'argv_hash' => hash('sha256', json_encode($hermesArgv, JSON_UNESCAPED_SLASHES)),
        'exit_code' => $process->getExitCode(),
        'started_at' => $startedAt->toIso8601String(),
        'finished_at' => $finishedAt->toIso8601String(),
        'wall_ms' => (int) $startedAt->diffInRealMilliseconds($finishedAt),
        'usage' => [
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'cost_usd' => 0.0,
            'cost_source' => 'verboo_subscription_marginal',
            'present' => $inputTokens !== null && $outputTokens !== null,
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
}

$env = array_merge(
    array_filter($_ENV, fn ($v) => is_string($v) || is_numeric($v) || is_bool($v)),
    [
        'ATLAS_DEV_DETERMINISTIC_FAST_PATH_ENABLED' => 'false',
        'ATLAS_DEV_HERMES_EXECUTION_TRANSPORT' => 'cli',
        'ATLAS_DEV_PROVIDER_CONSULT_DECIDE' => 'false',
        'ATLAS_RIVALS_RUNTIME_EXECUTION' => 'true',
        'PATH' => $path,
    ],
);
$process = new Process($cliDevArgv, base_path(), $env);
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
$expectedProvider = $ai === 'hermes' ? 'hermes_cli' : $provider;
$providerLockVerified = $expectedProvider !== null
    && $actualProvider === $expectedProvider
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
    'execution' => 'atlas_cli_dev_efficient',
    // Aqui o Atlas roda de verdade: `artisan atlas:cli:dev` com as flags de
    // fair-mode. Vale o mesmo teste do provider-lock — sem chamada de provider
    // provada, o Atlas ter sido invocado não basta.
    'atlas_runtime' => $providerLockVerified,
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
    'argv_hash' => hash('sha256', json_encode($cliDevArgv, JSON_UNESCAPED_SLASHES)),
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

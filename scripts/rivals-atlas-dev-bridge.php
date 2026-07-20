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
    'artifact-target::',
    'timeout::',
    'dry-run',
]);
$workspace = realpath((string) ($options['workspace'] ?? ''));
$promptFile = realpath((string) ($options['prompt-file'] ?? ''));
$model = trim((string) ($options['model'] ?? ''));
$artifactTarget = trim((string) ($options['artifact-target'] ?? ''));
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
if ($artifactTarget !== ''
    && (str_starts_with($artifactTarget, '/')
        || str_contains($artifactTarget, '..')
        || str_contains($artifactTarget, ',')
        || str_contains($artifactTarget, "\0")
        || preg_match('/\A[A-Za-z0-9._\/-]{1,512}\z/', $artifactTarget) !== 1)) {
    fwrite(STDERR, "rivals_atlas_dev_artifact_target_invalid\n");
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

// ⚠️ ERA `$useHermesOnesHot = $ai === 'hermes'`, e o modelo primário do relatório
// (verboo_kimi_k2_7) TEM provider=hermes — então o braço "com Atlas" caía sempre
// no atalho `hermes -z`: Hermes CLI puro, sem artisan, sem Atlas no laço.
// 107 recibos com execution=hermes_cli_oneshot e ZERO com atlas_cli_dev_efficient
// em toda a história de runs: a coluna "com Atlas" nunca mediu o Atlas.
//
// Rodar COM ATLAS = rodar o Atlas Dev (`atlas:cli:dev`), que é o que o
// $cliDevArgv abaixo sempre fez e nunca foi escolhido. O roteador do Dev
// executa em worktree de benchmark: tem bypass próprio para este bridge
// (RoutingDecisionEngine::allowsRivalsIsolatedRuntimeExecution, ligado por
// ATLAS_RIVALS_RUNTIME_EXECUTION que este script já exporta) — verificado ao
// vivo: routing=atlas_dev_fast_path, is_executable=true.
//
// Mantido só como escape explícito do operador, nunca por dedução do provider:
// foi a dedução silenciosa que trocou a medição sem ninguém ver.
$useHermesOnesHot = filter_var(
    getenv('ATLAS_RIVALS_BRIDGE_HERMES_ONESHOT') ?: false,
    FILTER_VALIDATE_BOOLEAN,
);

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
$atlasRuntimeEnv = [
    // The outer bridge timeout is the suite contract. Without forwarding it,
    // PipelineRunExecutor silently fell back to the global 600s Hermes ceiling
    // and turned valid long SWE tasks into provider_failure:timeout.
    'ATLAS_AI_HERMES_TIMEOUT_SECONDS' => (string) $timeout,
];
if ($artifactTarget !== '') {
    $atlasRuntimeEnv['ATLAS_RIVALS_ARTIFACT_TARGET'] = $artifactTarget;
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
        'provider_timeout_seconds' => $timeout,
        'runtime_env' => $useHermesOnesHot ? [] : $atlasRuntimeEnv,
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
    $oneShotException = null;
    try {
        $process->run();
    } catch (Throwable $exception) {
        $oneShotException = $exception;
    }
    $finishedAt = now();
    $usage = is_file($usagePath)
        ? (json_decode((string) file_get_contents($usagePath), true) ?? [])
        : [];
    $inputTokens = is_numeric($usage['input_tokens'] ?? null) ? (int) $usage['input_tokens'] : null;
    $outputTokens = is_numeric($usage['output_tokens'] ?? null) ? (int) $usage['output_tokens'] : null;
    $providerLockVerified = $oneShotException === null && $process->isSuccessful()
        && (($usage['completed'] ?? false) === true || ($inputTokens !== null && $outputTokens !== null));
    $oneShotFailureReason = $providerLockVerified ? null : (
        $oneShotException instanceof Throwable
            ? 'hermes_oneshot_process_exception:'.$oneShotException::class.':'
                .mb_substr(preg_replace('/\s+/', ' ', $oneShotException->getMessage()) ?: 'unknown', 0, 600)
            : 'hermes_oneshot_failed_or_usage_missing'
    );
    $receipt = [
        'schema_version' => 'atlas.rivals2.atlas_dev_bridge_receipt.v2',
        'status' => $providerLockVerified ? 'passed' : 'failed',
        'failure_reason' => $oneShotFailureReason,
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
        fwrite(STDERR, trim($process->getErrorOutput()."\n".(string) $oneShotFailureReason)."\n");
        exit(1);
    }
    exit(0);
}

// Sink de usage: o provider_call do fast-path não carrega tokens no path
// hermes; o adaptador espelha o usage capturado aqui e nós o lemos abaixo.
$usageSink = tempnam(sys_get_temp_dir(), 'rivals-usage-sink-');
@unlink($usageSink); // só o caminho; o adaptador cria com os tokens acumulados
// Sink de patch-plans: o kernel pode rodar N ordens numa mesma resolução (ex.
// README + solution.py) e o run.provider_call só projeta a ÚLTIMA — o
// solution.py de 3.380 bytes morria invisível enquanto o README de 47 era
// aplicado (provado ao vivo 20/07). O adaptador espelha CADA patch_plan
// decodificado aqui, em ordem; o apply abaixo consome todos.
$patchSink = tempnam(sys_get_temp_dir(), 'rivals-patch-sink-');
@unlink($patchSink);
$env = array_merge(
    array_filter($_ENV, fn ($v) => is_string($v) || is_numeric($v) || is_bool($v)),
    $atlasRuntimeEnv,
    [
        'ATLAS_DEV_DETERMINISTIC_FAST_PATH_ENABLED' => 'false',
        'ATLAS_DEV_HERMES_EXECUTION_TRANSPORT' => 'cli',
        'ATLAS_DEV_PROVIDER_CONSULT_DECIDE' => 'false',
        'ATLAS_RIVALS_RUNTIME_EXECUTION' => 'true',
        'ATLAS_RIVALS_USAGE_SINK' => $usageSink,
        'ATLAS_RIVALS_PATCH_SINK' => $patchSink,
        'PATH' => $path,
    ],
);
$process = new Process($cliDevArgv, base_path(), $env);
$process->setTimeout((float) $timeout);
$processException = null;
try {
    $process->run();
} catch (Throwable $exception) {
    // A timeout/process exception must still produce a readable bridge receipt.
    // The outer native runner will persist this script's stdout/stderr as well.
    $processException = $exception;
}
$finishedAt = now();
// Decode tolerante: o artisan pode emitir warnings/log em volta do objeto, e
// json_decode estrito virava "Atlas não rodou" com o Atlas TENDO rodado e
// respondido (3/4 unidades do run 20260718_180605 — o JSON estava lá inteiro).
$rawOutput = trim($process->getOutput());
$outputPayload = json_decode($rawOutput, true);
if (! is_array($outputPayload)) {
    $first = strpos($rawOutput, '{');
    $last = strrpos($rawOutput, '}');
    if ($first !== false && $last !== false && $last > $first) {
        $outputPayload = json_decode(substr($rawOutput, $first, $last - $first + 1), true);
    }
}
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
$patchPlanPatches = data_get($providerCall, 'patch_plan.patches');
$emptyPatchPlan = is_array($patchPlanPatches) && $patchPlanPatches === [];
if ($emptyPatchPlan) {
    $providerErrors = array_values(array_filter(
        $providerErrors,
        static function (mixed $error): bool {
            $providerError = (string) $error;

            return ! (str_contains($providerError, 'candidate_preparation_blocked')
                && str_contains($providerError, 'sandbox_apply_failed'));
        },
    ));
    $providerErrors[] = 'model_empty_patch_plan';
    $providerErrors = array_values(array_unique($providerErrors));
}
$expectedProvider = $ai === 'hermes' ? 'hermes_cli' : $provider;
$inputTokens = is_numeric($providerCall['tokens_in'] ?? null)
    ? (int) $providerCall['tokens_in']
    : null;
$outputTokens = is_numeric($providerCall['tokens_out'] ?? null)
    ? (int) $providerCall['tokens_out']
    : null;
$costUsd = is_numeric($providerCall['estimated_cost_usd'] ?? null)
    ? (float) $providerCall['estimated_cost_usd']
    : null;
// Fallback: o kernel não surfou tokens no provider_call → lê o sink que o
// adaptador preencheu com o usage real capturado pelo hermes. Sem isto o
// gate de claim trava eternamente em "provider_usage_empty" com o braço OK.
if (($inputTokens === null || $outputTokens === null) && is_file($usageSink)) {
    $sink = json_decode((string) file_get_contents($usageSink), true) ?: [];
    if ($inputTokens === null && is_numeric($sink['input_tokens'] ?? null)) {
        $inputTokens = (int) $sink['input_tokens'];
    }
    if ($outputTokens === null && is_numeric($sink['output_tokens'] ?? null)) {
        $outputTokens = (int) $sink['output_tokens'];
    }
}
$providerUsageObserved = $inputTokens !== null
    && $outputTokens !== null
    && ($inputTokens + $outputTokens) > 0;
// Verboo é custo marginal de assinatura: com tokens capturados, o custo
// HONESTO é 0.0 (os adapters já tratam assim), destravando `present`.
if ($costUsd === null && $providerUsageObserved && $ai === 'hermes') {
    $costUsd = 0.0;
}
$usagePresent = $providerUsageObserved && $costUsd !== null;
@unlink($usageSink);

// DUAS PERGUNTAS DIFERENTES, E JUNTÁ-LAS APAGA A MEDIÇÃO.
//
// "O Atlas rodou?" é prova de runtime: o provider certo, o modelo certo, e uma
// chamada de provider que de fato aconteceu. "O Atlas acertou?" é o RESULTADO —
// é justamente o que a medição existe para descobrir.
//
// Isto já esteve numa variável só, exigindo `exit_code === 0` para declarar
// `real_provider`. O efeito: Atlas roda, erra a tarefa, sai exit_code=1 → a
// prova de runtime vira false → o agente HAL levanta "runtime proof missing" →
// o recibo vira environment_failure → o SkillMatrix descarta (falha de ambiente
// não é nota do modelo). Toda tarefa errada pelo Atlas era apagada, e o braço
// só conseguia registrar acerto: 100% por construção. O primeiro bug fazia a
// coluna "com Atlas" medir Hermes; este a faria medir só as vitórias.
//
// `provider_calls` conta tentativas, não garante uma RESPOSTA. Em 19/07 um
// receipt veio com calls=1 + candidate_preparation_blocked:provider_unavailable
// + tokens null e foi aceito como Atlas real. A prova agora exige usage real e
// recusa explicitamente erros pré-resposta; falhas depois de uma resposta
// continuam sendo resultado válido da tarefa.
$preResponseError = null;
foreach ($providerErrors as $providerError) {
    $providerError = (string) $providerError;
    if (str_contains($providerError, 'provider_unavailable')
        || str_contains($providerError, 'provider_driver_not_configured')
        || str_contains($providerError, 'provider_route_mismatch')) {
        $preResponseError = $providerError;
        break;
    }
}
$runtimeFailureReason = match (true) {
    $processException instanceof Throwable => 'atlas_dev_process_exception:'
        .$processException::class.':'
        .mb_substr(preg_replace('/\s+/', ' ', $processException->getMessage()) ?: 'unknown', 0, 600),
    $expectedProvider === null => 'atlas_dev_expected_provider_unresolved',
    $actualProvider !== $expectedProvider => 'atlas_dev_provider_mismatch:expected='.(string) $expectedProvider
        .':actual='.($actualProvider !== '' ? $actualProvider : 'missing'),
    $actualModel !== $model => 'atlas_dev_model_mismatch:expected='.$model
        .':actual='.($actualModel !== '' ? $actualModel : 'missing'),
    $providerCalls <= 0 => 'atlas_dev_provider_call_absent',
    $preResponseError !== null => 'atlas_dev_provider_pre_response_failure:'.$preResponseError,
    ! $usagePresent => 'atlas_dev_provider_usage_missing_after_call',
    default => null,
};
$atlasRuntimeProven = $runtimeFailureReason === null && $usagePresent;
// Resultado da tarefa: vira DADO no recibo, nunca portão. Errar é medição
// válida; não ter rodado é que não é.
$taskOk = $providerExitCode === 0 && $providerErrors === [];
$completionState = is_array($outputPayload)
    ? data_get($outputPayload, 'run.completion_state')
    : null;

// APLICAR O PATCH QUE O ATLAS GEROU no workspace, para o grader poder julgá-lo.
//
// O Atlas Dev produz o patch e o aplica num sandbox EFÊMERO; depois tenta o merge
// governado, que num benchmark descartável não tem autoridade de release e
// bloqueia (governor_authority_absent). O workspace do harness ficava intocado e
// o `git diff` do agente saía vazio — o Atlas trabalhava e a medição via nada.
//
// O que se mede é o PATCH GERADO (o grader o aplica e roda os testes), não o
// merge de produção — que não cabe aqui. O patch_plan (resposta do modelo:
// path+mode+next) é exposto pelo KernelRunExecutor só no contexto Rivals e existe
// mesmo com o merge bloqueado. Aplicá-lo no workspace é o que torna o Atlas
// mensurável, sem tocar a governança de produção.
$patchApplied = 0;
if ($atlasRuntimeProven) {
    // Preferência: o sink com TODOS os patch_plans da resolução (em ordem de
    // decodificação; patch posterior no mesmo path sobrescreve, como no merge
    // real). Fallback: o único projetado em run.provider_call (compat).
    $allPatches = [];
    foreach (is_file($patchSink) ? array_filter(explode("\n", (string) file_get_contents($patchSink))) : [] as $line) {
        $entry = json_decode($line, true);
        foreach ((array) data_get($entry, 'patch_plan.patches', []) as $patch) {
            $allPatches[] = $patch;
        }
    }
    if ($allPatches === []) {
        $allPatches = (array) data_get($providerCall, 'patch_plan.patches', []);
    }
    foreach ($allPatches as $patch) {
        if (! is_array($patch)) {
            continue;
        }
        $rel = ltrim((string) ($patch['path'] ?? ''), '/');
        // Nunca escapar do workspace — o path vem do modelo.
        if ($rel === '' || str_contains($rel, '..')) {
            continue;
        }
        $abs = $workspace.'/'.$rel;
        $mode = (string) ($patch['mode'] ?? 'modify');
        if ($mode === 'delete') {
            if (is_file($abs)) {
                @unlink($abs);
                $patchApplied++;
            }

            continue;
        }
        if (! array_key_exists('next', $patch)) {
            continue;
        }
        $dir = \dirname($abs);
        if (! is_dir($dir)) {
            @mkdir($dir, 0o755, true);
        }
        if (@file_put_contents($abs, (string) $patch['next']) !== false) {
            $patchApplied++;
        }
    }
}
$receipt = [
    'schema_version' => 'atlas.rivals2.atlas_dev_bridge_receipt.v2',
    // `status` do BRIDGE: ele conseguiu rodar o Atlas? Não é a nota da tarefa.
    'status' => $atlasRuntimeProven ? 'passed' : 'failed',
    'failure_reason' => $runtimeFailureReason,
    'real_provider' => $atlasRuntimeProven,
    'workspace' => $workspace,
    // O modelo EFETIVO, não o pedido: o hermes cai de kimi para qwen sozinho, e
    // um par medindo kimi de um lado e qwen do outro não mede o Atlas — mede a
    // troca de modelo, em silêncio.
    'model' => $actualModel !== '' ? $actualModel : $model,
    'expected_model' => $model,
    'model_matches_request' => $actualModel === $model,
    'provider' => $actualProvider !== '' ? $actualProvider : $provider,
    'ai' => $ai,
    'execution' => 'atlas_cli_dev_efficient',
    // Aqui o Atlas roda de verdade: `artisan atlas:cli:dev` com as flags de
    // fair-mode. Sem chamada de provider provada, o Atlas ter sido invocado não
    // basta — mas a tarefa ter FALHADO não desprova nada: é o resultado que a
    // medição foi buscar.
    'atlas_runtime' => $atlasRuntimeProven,
    'fair_mode' => [
        'single_provider' => $atlasRuntimeProven,
        'decide_disabled' => $atlasRuntimeProven,
        'fallback_disabled' => $atlasRuntimeProven,
        'deterministic_fast_path_disabled' => true,
    ],
    // O resultado, como DADO. Quem pontua é a suíte; isto é evidência de apoio.
    'task_ok' => $taskOk,
    // Quantos patches o bridge escreveu no workspace do harness — sem isto,
    // "patch gerado mas workspace intocado" era indiagnosticável no recibo.
    'patch_applied' => $patchApplied,
    'completion_state' => $completionState,
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
        'present' => $usagePresent,
    ],
    'stdout_sha256' => hash('sha256', $process->getOutput()),
    'stderr_sha256' => hash('sha256', $process->getErrorOutput()),
    // Cauda do erro SÓ quando o runtime não provou: morte em ~1,4s sem JSON
    // era indiagnosticável com stderr reduzido a hash (3/4 unidades do run
    // 20260718_175624). Runtime provado = sem cauda (nada a vazar).
    'stderr_tail' => $atlasRuntimeProven ? null : mb_substr(trim($process->getErrorOutput()."\n".$process->getOutput()), -600),
];
file_put_contents(
    $workspace.'/.rivals_atlas_dev_bridge.json',
    json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
);
echo $process->getOutput();
// Sai não-zero SÓ quando o Atlas não rodou — aí a unidade é falha de ambiente e
// deve mesmo sumir da medição. Tarefa que o Atlas rodou e errou sai ZERO: o
// patch (ruim ou vazio) segue para a suíte pontuar como falha, que é o dado.
// Sair 1 aqui faria o agente HAL levantar "runtime proof missing", e o erro do
// Atlas viraria "falha de ambiente" — descartado do denominador.
if (! $atlasRuntimeProven) {
    fwrite(STDERR, trim($process->getErrorOutput()."\n".(string) $runtimeFailureReason)."\n");
    exit(1);
}
exit(0);

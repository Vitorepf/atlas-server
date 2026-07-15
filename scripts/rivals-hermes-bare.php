#!/usr/bin/env php
<?php

use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\Process\Process;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$options = getopt('', ['workspace:', 'prompt-file:', 'model:', 'timeout::', 'dry-run']);
$workspace = realpath((string) ($options['workspace'] ?? ''));
$promptFile = realpath((string) ($options['prompt-file'] ?? ''));
$model = trim((string) ($options['model'] ?? ''));
$timeout = max(60, min(7200, (int) ($options['timeout'] ?? 3600)));
if ($workspace === false || $promptFile === false || $model === ''
    || ! str_starts_with($promptFile, $workspace.'/')) {
    fwrite(STDERR, "rivals_hermes_bare_invalid_arguments\n");
    exit(2);
}
$usagePath = $workspace.'/.rivals_hermes_usage.json';
// O BRAÇO DE CONTROLE ESTAVA CONTAMINADO — e contaminação no controle é pior
// que no tratamento, porque ela ESCONDE o efeito em vez de inventá-lo.
//
// Do `hermes --help`, verbatim sobre o `-z`: "Tools, memory, rules, and
// AGENTS.md in the CWD are loaded as normal". Ou seja, a coluna que o relatório
// chama de "modelo sozinho" vinha sendo: kimi + laço agêntico + MEMÓRIA do
// Hermes + RULES do operador. Memória que acumula ENTRE runs e rules escritas
// pelo operador não são o modelo: são outro wrapper, e um que deriva no tempo.
//
// O contrato da medição é "a ÚNICA diferença entre os braços é o Atlas". Com o
// controle carregando memória e regras, a diferença medida era Atlas-menos-
// Hermes-acumulado — e quanto mais o operador usasse o Hermes, MENOR o M
// aparente do Atlas. O efeito some sem ninguém ver.
//
// As TOOLS ficam de propósito: bare = kimi num harness agêntico genérico. Um
// controle sem tools (API de completion crua) inflaria o M do Atlas de graça, e
// aí o 50x viria da comparação, não do Atlas. Isto torna a barra MAIS dura, que
// é o lado certo de errar.
//
// Verificado antes de aplicar: as duas flags existem no hermes e NÃO derrubam a
// chave do verboo (a key_env sobrevive ao --ignore-user-config; testado com
// prompt real, exit 0 nos dois).
$argv = [
    'hermes',
    '--ignore-user-config',
    '--ignore-rules',
    '-z', trim((string) file_get_contents($promptFile)),
    '--provider', 'verboo',
    '-m', $model,
    '--usage-file', $usagePath,
    '--yolo',
];
if (array_key_exists('dry-run', $options)) {
    echo json_encode([
        'schema_version' => 'atlas.rivals2.hermes_bare_plan.v1',
        'model' => $model,
        'provider' => 'verboo',
        'argv' => $argv,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit(0);
}

$startedAt = now();
$process = new Process($argv, $workspace);
$process->setTimeout((float) $timeout);
$process->run();
$finishedAt = now();
$usage = is_file($usagePath)
    ? (json_decode((string) file_get_contents($usagePath), true) ?? [])
    : [];
$receipt = [
    'schema_version' => 'atlas.rivals2.hermes_bare_receipt.v1',
    'status' => ! $process->isSuccessful()
        ? 'failed'
        : (($usage['completed'] ?? false) ? 'passed' : 'incomplete'),
    'real_provider' => true,
    'runtime' => 'bare',
    'provider' => 'verboo',
    'model' => $model,
    'exit_code' => $process->getExitCode(),
    'started_at' => $startedAt->toIso8601String(),
    'finished_at' => $finishedAt->toIso8601String(),
    'wall_ms' => (int) $startedAt->diffInRealMilliseconds($finishedAt),
    'usage' => [
        'input_tokens' => $usage['input_tokens'] ?? null,
        'output_tokens' => $usage['output_tokens'] ?? null,
        // Verboo is subscription-backed for this operator: marginal run cost is zero.
        'cost_usd' => 0.0,
        'cost_source' => 'verboo_subscription_marginal',
        'present' => isset($usage['input_tokens'], $usage['output_tokens']),
    ],
    'stdout_sha256' => hash('sha256', $process->getOutput()),
    'stderr_sha256' => hash('sha256', $process->getErrorOutput()),
];
file_put_contents(
    $workspace.'/.rivals_bare_provider.json',
    json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
);
echo $process->getOutput();
if (! $process->isSuccessful()) {
    fwrite(STDERR, $process->getErrorOutput());
    exit(1);
}
exit(0);

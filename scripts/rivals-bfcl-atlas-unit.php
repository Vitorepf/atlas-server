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
// SIMETRIA DE BRAÇOS (auditoria 21/07): o braço cru usa function-calling
// NATIVO (kimi-k2.7-FC) e o harness da API empacota no formato do checker.
// Exigir do modelo "valor = string JSON-escapada do objeto de argumentos"
// dentro do patch_plan (JSON³) media escaping, não capacidade: 7 de 10 casos
// falhavam 21/21 deterministicamente enquanto o MESMO modelo fazia 30/30 cru.
// Agora o modelo entrega a chamada em JSON natural e o harness normaliza —
// o mesmo empacotamento determinístico que o braço cru ganha da API.
file_put_contents($promptFile, <<<'PROMPT'
Read task.json. Determine the exact native function call(s) that answer the user question using only the declared functions. Edit answer.json to a JSON array with one item per call. Each item must be an object with exactly one key: the declared function name; its value must be the argument object itself (plain JSON object, e.g. {"func_name": {"arg": 1}}). Do not edit task.json.
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
if (! $solver->isSuccessful() || ($proof['real_provider'] ?? false) !== true) {
    fwrite(STDERR, $solver->getErrorOutput());
    exit(1);
}
if (! is_array($answer)) {
    // Modelo escreveu lixo no answer.json (visto ao vivo: "..."): isso é
    // resposta ERRADA, não defeito de ambiente — exit 1 aqui virava
    // environment_failure e inflava a taxa que bloqueia claims. Resposta
    // vazia segue para o grader nativo reprovar como model_failure.
    $answer = [];
}
// Empacotamento determinístico no formato do checker (args como string
// JSON-encoded) — espelho do que o harness da API faz pro braço cru. Só
// FORMA muda; o conteúdo semântico (função + args) é 100% do modelo:
// argumento errado continua reprovando no grader.
$answer = array_map(static function ($item) {
    if (! is_array($item)) {
        return $item;
    }
    foreach ($item as $fn => $args) {
        if (is_array($args)) {
            $item[$fn] = json_encode((object) $args, JSON_UNESCAPED_SLASHES);
        }
    }

    return $item;
}, $answer);
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

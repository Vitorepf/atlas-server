<?php

declare(strict_types=1);

// Golden (standalone — never phpunit): the Dev CLI's hand-rolled
// build()+inject() must equal AtlasContextRuntime::compose() under the
// production default (unified retrieval OFF). With the flag ON the façade adds
// the fused retrieval core — which is exactly the capability the Dev path was
// missing by bypassing it.

$root = '/Users/vitorepf/develop/Atlas/atlas-server';
require $root.'/tests/bootstrap.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\Ai\AtlasOpenBrainContextInjectionService;
use App\Services\Ai\Context\AiContextPackBuilder;
use App\Services\Ai\Context\AtlasContextRuntime;
use App\Services\Ai\ValueObjects\AiTaskRequest;

config(['atlas.context_runtime.unified_retrieval_enabled' => false]);

$input = 'golden: descrever o fluxo de contexto do Dev';
$options = [
    'workspace' => $root,
    'surface' => 'atlas_cli_dev',
    'include_semantic_context' => true,
    'open_brain' => ['preview' => true],
    'payload' => ['domain' => 'engineering'],
];

$task = AiTaskRequest::fromInput($input, $options, [
    'agent' => 'desenvolvedor',
    'intent' => 'atlas_cli_dev_plan_preview',
]);

/** Volatile keys: ids and clocks differ per run, never per code path. */
$stabilize = static function (mixed $value) use (&$stabilize): mixed {
    if (! is_array($value)) {
        return $value;
    }
    $out = [];
    foreach ($value as $k => $v) {
        if (is_string($k) && preg_match('/(audit_id|generated_at|previewed_at|_at$|timestamp|elapsed|duration|_ms$)/', $k) === 1) {
            $out[$k] = '<volatile>';

            continue;
        }
        $out[$k] = $stabilize($v);
    }

    return $out;
};

// Path A — what AtlasCliDevCommand does by hand today.
$packA = app(AiContextPackBuilder::class)->build($input, $task, $options);
$direct = app(AtlasOpenBrainContextInjectionService::class)->inject($input, $task, $packA, $options);

// Path B — the ACOS façade the three elite executors are supposed to share.
$contract = app(AtlasContextRuntime::class)->compose($input, $task, $options);
$viaFacade = (array) ($contract->pack['open_brain_injection'] ?? []);

$out = [
    'direct' => $stabilize($direct),
    'via_facade' => $stabilize($viaFacade),
    'retrieval_core_status' => $contract->pack['retrieval_core_status'] ?? null,
    'equal' => $stabilize($direct) === $stabilize($viaFacade),
];

// Anti-vacuum: an empty or shapeless injection must never read as "equal".
if (! is_array($direct) || $direct === []) {
    fwrite(STDERR, "GOLDEN VACUOUS — direct injection is empty\n");
    exit(1);
}
foreach (['context_pack_hash', 'summary'] as $field) {
    if (! array_key_exists($field, $direct)) {
        fwrite(STDERR, "GOLDEN VACUOUS — injection missing [{$field}]\n");
        exit(1);
    }
}
if (($out['retrieval_core_status'] ?? null) !== 'legacy') {
    fwrite(STDERR, "GOLDEN PRECONDITION — expected legacy status with the flag off, got "
        .var_export($out['retrieval_core_status'], true)."\n");
    exit(1);
}

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
exit($out['equal'] ? 0 : 2);

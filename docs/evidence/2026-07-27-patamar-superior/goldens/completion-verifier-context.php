<?php

declare(strict_types=1);

// Golden (standalone) for verifierContext, duplicated byte-for-byte between the
// human gate and the closure execution pack.

$root = '/Users/vitorepf/develop/Atlas/atlas-server';
require $root.'/tests/bootstrap.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$audit = [
    'completion_audit_hash' => 'sha256:audit-golden',
    'criteria' => [
        ['id' => 'runtime_gap_matrix_all_runtime_y', 'evidence' => ['runtime_gap_matrix_hash' => 'sha256:rgm-from-criterion']],
        ['id' => 'release_dossier_green', 'evidence' => ['hash' => 'sha256:release']],
        ['id' => 'replay_diff_against_completion_snapshot_green', 'evidence' => ['diff_hash' => 'sha256:replay']],
        ['id' => 'end_to_end_real_provider_smoke_green', 'evidence' => ['smoke_hash' => 'sha256:smoke-from-criterion']],
        ['id' => 'certification_status_batch_green', 'evidence' => ['hash' => 'sha256:batch']],
    ],
    'operator_action_packet' => [
        'human_completion_receipt_template' => ['certification_status_batch_hash' => 'sha256:batch-fallback'],
    ],
];
$evidence = [
    'runtime_gap_matrix' => [
        'runtime_gap_matrix_hash' => 'sha256:rgm-from-evidence',
        'runtime_promotion_receipt' => ['receipt_hash' => 'sha256:promotion'],
    ],
    'real_provider_smoke' => ['smoke_hash' => 'sha256:smoke-from-evidence'],
];

$cases = [
    'full' => [$audit, $evidence],
    'empty_audit' => [[], $evidence],
    'empty_evidence' => [$audit, []],
    'criterion_fallback' => [$audit, ['runtime_gap_matrix' => [], 'real_provider_smoke' => []]],
    'both_empty' => [[], []],
];

$targets = [
    'human_gate' => App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionFinalCompletionHumanGateService::class,
    'closure_pack' => App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionHumanCompletionReceiptClosureExecutionPackService::class,
    'support' => App\Services\Ai\SelfConstruction\Support\CompletionVerifierContextSupport::class,
];

$out = [];
foreach ($targets as $name => $class) {
    if (! class_exists($class)) {
        continue;
    }
    $ref = new ReflectionClass($class);
    if (! $ref->hasMethod('verifierContext')) {
        continue;
    }
    $method = $ref->getMethod('verifierContext');
    $method->setAccessible(true);
    $obj = $method->isStatic() ? null : $ref->newInstanceWithoutConstructor();
    foreach ($cases as $label => $args) {
        try {
            $out[$name][$label] = $method->invokeArgs($obj, $args);
        } catch (Throwable $e) {
            $out[$name][$label] = 'ERR: '.$e->getMessage();
        }
    }
}

$owner = isset($out['support']) ? 'support' : 'human_gate';
$full = $out[$owner]['full'] ?? null;

// Anti-vacuum: the context must be a non-trivial map that reacts to its input.
if (! is_array($full) || count($full) < 2) {
    fwrite(STDERR, "GOLDEN VACUOUS — verifierContext returned nothing useful\n");
    fwrite(STDERR, json_encode($out[$owner] ?? [], JSON_PRETTY_PRINT)."\n");
    exit(1);
}
if (($out[$owner]['full'] ?? null) === ($out[$owner]['both_empty'] ?? null)) {
    fwrite(STDERR, "GOLDEN VACUOUS — output ignores its input\n");
    exit(1);
}

$out['_owner'] = $owner;
echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";

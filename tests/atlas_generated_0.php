<?php

declare(strict_types=1);

/**
 * Standalone acceptance test for AAEL certification integrationWiring() shape.
 *
 * The Atlas AAE L certification's `integrationWiring()` must expose the
 * inspected path and a non-empty missing-tokens list in evidence when the
 * integration check fails, matching fileCheck() diagnostic shape and
 * preserving existing pass/fail semantics.
 *
 * This file is executed by the obra's acceptance command:
 *     php tests/atlas_generated_0.php
 *
 * It runs WITHOUT PHPUnit — env is set up so Laravel facades (File, Schema)
 * work, then Reflection on the private `integrationWiring()` and
 * `fileCheck()` methods asserts the payload shape directly against the
 * production source. A probe temp file is used to exercise the fail path
 * through `fileCheck()` (the reference shape) because the integrationWiring()
 * `$path` is hard-coded to the real runtime service and we must not mutate
 * production files.
 */

$root = dirname(__DIR__);

// 1) Hermetic env: sqlite in-memory, testing, no external services.
$envPairs = [
    'APP_ENV' => 'testing',
    'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => ':memory:',
    'DB_URL' => '',
    'CACHE_STORE' => 'array',
    'SESSION_DRIVER' => 'array',
    'QUEUE_CONNECTION' => 'sync',
    'MAIL_MAILER' => 'array',
    'BCRYPT_ROUNDS' => '4',
    'ATLAS_TOKEN' => 'testing-atlas-token-with-enough-length',
    'BROADCAST_CONNECTION' => 'null',
    'PULSE_ENABLED' => 'false',
    'TELESCOPE_ENABLED' => 'false',
    'NIGHTWATCH_ENABLED' => 'false',
];
foreach ($envPairs as $k => $v) {
    putenv("{$k}={$v}");
    $_ENV[$k] = $v;
    $_SERVER[$k] = $v;
}

// 2) Load Composer autoloader.
$autoload = $root . '/vendor/autoload.php';
if (! is_file($autoload)) {
    fwrite(STDERR, "FAIL: composer autoload not found at {$autoload}\n");
    exit(1);
}
require $autoload;

// 3) Boot the Laravel application so facades (File, Schema, DB) work.
$bootstrap = $root . '/bootstrap/app.php';
if (! is_file($bootstrap)) {
    fwrite(STDERR, "FAIL: bootstrap/app.php not found at {$bootstrap}\n");
    exit(1);
}
$app = require $bootstrap;

// Bootstrap the HTTP kernel so all core providers (Filesystem, etc.) are
// registered and booted, and facades can resolve 'files'.
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

\Illuminate\Container\Container::setInstance($app);
\Illuminate\Support\Facades\Facade::clearResolvedInstances();
\Illuminate\Support\Facades\Facade::setFacadeApplication($app);

// 4) Sanity check: the runtime service file we expect to be present.
$expectedRuntime = $app->basePath(
    'app/Services/Ai/AutonomousEvolution/AtlasAutonomousEvolutionLoopService.php'
);
if (! is_file($expectedRuntime)) {
    fwrite(STDERR, "FAIL: expected runtime service file not found at {$expectedRuntime}\n");
    exit(1);
}

// 5) Reflection: invoke the private `integrationWiring()` and `fileCheck()`
//    methods directly on a stub instance. The certification service is
//    `final`, so we instantiate it via Reflection (no constructor args:
//    the only dep is `AtlasAutonomousEvolutionLoopService`, which we don't
//    need because we never call `certify()`).
$certClass = \App\Services\Ai\AutonomousEvolution\AtlasAutonomousEvolutionCertificationService::class;
$certReflection = new ReflectionClass($certClass);
if (! $certReflection->isFinal()) {
    fwrite(STDERR, "FAIL: certification service is expected to be final\n");
    exit(1);
}
$instance = $certReflection->newInstanceWithoutConstructor();

$integrationWiring = $certReflection->getMethod('integrationWiring');
if (! $integrationWiring->isPrivate()) {
    fwrite(STDERR, "FAIL: integrationWiring() must be private\n");
    exit(1);
}

$fileCheck = $certReflection->getMethod('fileCheck');
if (! $fileCheck->isPrivate()) {
    fwrite(STDERR, "FAIL: fileCheck() must be private\n");
    exit(1);
}

/** @var array<int,array{label:string,ok:bool,detail:string}> $results */
$results = [];

/**
 * @param  string  $label
 * @param  bool    $ok
 * @param  string  $detail
 */
$record = function (string $label, bool $ok, string $detail) use (&$results): void {
    $results[] = ['label' => $label, 'ok' => $ok, 'detail' => $detail];
};

// 6) PASS case: invoke the real `integrationWiring()` against the real
//    runtime service file (which contains both required tokens).
$passPayload = $integrationWiring->invoke($instance);

$record(
    'pass.evidence_is_list',
    is_array($passPayload['evidence'] ?? null) && array_is_list($passPayload['evidence']),
    'evidence=' . json_encode($passPayload['evidence'] ?? null)
);
$record(
    'pass.evidence_has_one_string_path',
    is_array($passPayload['evidence'] ?? null)
        && count($passPayload['evidence']) === 1
        && is_string($passPayload['evidence'][0] ?? null)
        && str_contains($passPayload['evidence'][0], 'AtlasAutonomousEvolutionLoopService.php'),
    'evidence[0]=' . ($passPayload['evidence'][0] ?? 'null')
);
$record(
    'pass.evidence_inspects_runtime_path',
    ($passPayload['evidence'][0] ?? null)
        === 'app/Services/Ai/AutonomousEvolution/AtlasAutonomousEvolutionLoopService.php',
    'evidence[0]=' . ($passPayload['evidence'][0] ?? 'null')
);
$record(
    'pass.missing_is_list',
    is_array($passPayload['missing'] ?? null) && array_is_list($passPayload['missing']),
    'missing=' . json_encode($passPayload['missing'] ?? null)
);
$record(
    'pass.missing_empty_when_tokens_present',
    ($passPayload['missing'] ?? null) === [],
    'missing=' . json_encode($passPayload['missing'] ?? null)
);
$record(
    'pass.status_is_pass',
    ($passPayload['status'] ?? null) === 'pass',
    'status=' . ($passPayload['status'] ?? 'null')
);

// 7) FAIL case: probe a controlled file that does NOT contain the required
//    tokens. The probe file lives under storage/framework/testing and is
//    created/cleaned up by the test itself.
$probeDir = $app->basePath('storage/framework/testing');
if (! is_dir($probeDir)) {
    @mkdir($probeDir, 0775, true);
}
$probeRel = 'storage/framework/testing/atlas_aael_integration_probe.php';
$probeAbs = $app->basePath($probeRel);
file_put_contents($probeAbs, "<?php // intentionally empty — no required tokens\n");

try {
    $failPayload = $fileCheck->invoke(
        $instance,
        'integration_wiring_probe',
        $probeRel,
        ['AtlasAutonomousWorkExecutionService', 'AtlasIntelligenceFactoryRuntimeService']
    );
} finally {
    @unlink($probeAbs);
}

$record(
    'fail.evidence_is_list',
    is_array($failPayload['evidence'] ?? null) && array_is_list($failPayload['evidence']),
    'evidence=' . json_encode($failPayload['evidence'] ?? null)
);
$record(
    'fail.evidence_has_one_string_path',
    is_array($failPayload['evidence'] ?? null)
        && count($failPayload['evidence']) === 1
        && is_string($failPayload['evidence'][0] ?? null),
    'evidence[0]=' . ($failPayload['evidence'][0] ?? 'null')
);
$record(
    'fail.evidence_equals_path_arg',
    ($failPayload['evidence'][0] ?? null) === $probeRel,
    'evidence[0]=' . ($failPayload['evidence'][0] ?? 'null') . ' expected=' . $probeRel
);
$record(
    'fail.missing_is_list',
    is_array($failPayload['missing'] ?? null) && array_is_list($failPayload['missing']),
    'missing=' . json_encode($failPayload['missing'] ?? null)
);
$record(
    'fail.missing_non_empty_when_tokens_absent',
    is_array($failPayload['missing'] ?? null) && count($failPayload['missing']) >= 1,
    'missing=' . json_encode($failPayload['missing'] ?? null)
);
$record(
    'fail.missing_lists_required_tokens',
    is_array($failPayload['missing'] ?? null)
        && in_array('AtlasAutonomousWorkExecutionService', $failPayload['missing'], true)
        && in_array('AtlasIntelligenceFactoryRuntimeService', $failPayload['missing'], true),
    'missing=' . json_encode($failPayload['missing'] ?? null)
);
$record(
    'fail.status_is_fail',
    ($failPayload['status'] ?? null) === 'fail',
    'status=' . ($failPayload['status'] ?? 'null')
);

// 8) SHAPE PARITY: pass (integrationWiring) and fail (fileCheck) payloads
//    share the same keys, proving integrationWiring() matches fileCheck()'s
//    diagnostic shape.
$passKeys = array_keys($passPayload);
sort($passKeys);
$failKeys = array_keys($failPayload);
sort($failKeys);
$record(
    'shape.keys_match_fileCheck',
    $passKeys === $failKeys,
    'integrationWiring keys=' . json_encode($passKeys) . ' fileCheck keys=' . json_encode($failKeys)
);

// 9) integrationWiring() payload keys are exactly {id, status, evidence, missing}.
$required = ['id', 'status', 'evidence', 'missing'];
sort($required);
$record(
    'shape.integrationWiring_has_required_keys',
    $passKeys === $required,
    'required=' . json_encode($required) . ' got=' . json_encode($passKeys)
);
$record(
    'shape.id_is_integration_wiring',
    ($passPayload['id'] ?? null) === 'integration_wiring',
    'id=' . ($passPayload['id'] ?? 'null')
);

// 10) Report.
$failed = array_values(array_filter($results, fn (array $r): bool => ! $r['ok']));
foreach ($results as $r) {
    $tag = $r['ok'] ? 'PASS' : 'FAIL';
    fwrite(STDOUT, sprintf("[%s] %s :: %s\n", $tag, $r['label'], $r['detail']));
}
if ($failed !== []) {
    fwrite(STDERR, sprintf("\nACCEPTANCE FAILED: %d / %d assertions failed\n", count($failed), count($results)));
    exit(1);
}
fwrite(STDOUT, sprintf("\nACCEPTANCE PASSED: %d / %d assertions green\n", count($results), count($results)));
exit(0);

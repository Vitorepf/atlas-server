#!/usr/bin/env php
<?php

use App\Services\Ai\Rivals\Core\ClaimTier;
use App\Services\Ai\Rivals\Core\ModelRegistry;
use App\Services\Ai\Rivals\Core\NativeExecutionManifest;
use App\Services\Ai\Rivals\Core\NativeExecutionReceipt;
use App\Services\Ai\Rivals\Core\NativeResultNormalizer;
use App\Services\Ai\Rivals\Core\VerbooEnvironment;
use App\Services\Ai\Rivals\Support\EventStream;
use App\Services\Ai\Rivals\Support\RunPaths;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$options = getopt('', [
    'manifest:',
    'cwd:',
    'execution-id::',
    'approve-provider-spend',
    'dry-run',
    'normalize-only',
]);
$manifestPath = (string) ($options['manifest'] ?? '');
$cwd = realpath((string) ($options['cwd'] ?? ''));
if ($manifestPath === '' || ! is_file($manifestPath) || $cwd === false || ! is_dir($cwd)) {
    fwrite(STDERR, "usage: php scripts/rivals-native-runner.php --manifest=<path> --cwd=<suite-clone> [--execution-id=<id>] [--approve-provider-spend] [--dry-run]\n");
    exit(2);
}

try {
    $manifest = NativeExecutionManifest::fromArray(
        json_decode((string) file_get_contents($manifestPath), true) ?? []
    );
    $tier = (string) $manifest->data['claim_tier'];
    if (! array_key_exists('dry-run', $options)
        && ! array_key_exists('normalize-only', $options)
        && in_array($tier, [ClaimTier::PRODUCTION, ClaimTier::PUBLIC], true)
        && (! ($manifest->data['provider_spend_approved'] ?? false)
            || ! array_key_exists('approve-provider-spend', $options))) {
        throw new RuntimeException('rivals_native_runner_provider_spend_not_approved');
    }

    $entries = $manifest->entries();
    $requestedExecution = (string) ($options['execution-id'] ?? '');
    if ($requestedExecution !== '') {
        $entries = array_values(array_filter(
            $entries,
            fn (array $entry): bool => $entry['execution_id'] === $requestedExecution,
        ));
        if ($entries === []) {
            throw new RuntimeException("rivals_native_runner_unknown_execution:{$requestedExecution}");
        }
    }

    $runId = (string) $manifest->data['run_id'];
    $summary = [];
    foreach ($entries as $entry) {
        $executionId = (string) $entry['execution_id'];
        $argv = array_values((array) $entry['argv']);
        if (array_key_exists('dry-run', $options)) {
            $summary[] = [
                'execution_id' => $executionId,
                'status' => 'dry_run',
                'argv' => $argv,
            ];

            continue;
        }

        $resultPath = RunPaths::runDir($runId).'/'.$entry['expected_result_path'];
        RunPaths::ensureDir(dirname($resultPath));
        $logDir = RunPaths::nativeReceiptsDir($runId).'/logs';
        RunPaths::ensureDir($logDir);
        $stdoutPath = $logDir.'/'.$executionId.'.stdout.log';
        $stderrPath = $logDir.'/'.$executionId.'.stderr.log';
        $startedAt = now();
        $startedMonotonic = hrtime(true);
        EventStream::append($runId, 'native_execution_started', [
            'execution_id' => $executionId,
            'command_hash' => $entry['command_hash'],
        ]);

        $timedOut = false;
        $exitCode = 0;
        if (! array_key_exists('normalize-only', $options)) {
            $model = (new ModelRegistry)->get((string) ($entry['model_id'] ?? ''));
            $childEnvironment = ($model['provider'] ?? null) === 'hermes'
                ? (new VerbooEnvironment)->processEnvironment()
                : null;
            if ($childEnvironment !== null) {
                $toolPaths = [$cwd.'/.atlas-venv/bin'];
                if (is_dir($cwd.'/.miniforge/bin')) {
                    $toolPaths[] = $cwd.'/.miniforge/bin';
                }
                $childEnvironment['PATH'] = implode(PATH_SEPARATOR, $toolPaths)
                    .PATH_SEPARATOR.($childEnvironment['PATH'] ?? '');
                if (! str_contains((string) $argv[0], DIRECTORY_SEPARATOR)) {
                    foreach (explode(PATH_SEPARATOR, $childEnvironment['PATH']) as $directory) {
                        $candidate = rtrim($directory, DIRECTORY_SEPARATOR)
                            .DIRECTORY_SEPARATOR.$argv[0];
                        if (is_executable($candidate)) {
                            $argv[0] = $candidate;
                            break;
                        }
                    }
                }
            }
            $process = proc_open(
                $argv,
                [
                    0 => ['file', '/dev/null', 'r'],
                    1 => ['file', $stdoutPath, 'w'],
                    2 => ['file', $stderrPath, 'w'],
                ],
                $pipes,
                $cwd,
                $childEnvironment,
            );
            if (! is_resource($process)) {
                throw new RuntimeException("rivals_native_runner_process_start_failed:{$executionId}");
            }

            $lastStatus = proc_get_status($process);
            $maxSeconds = max(1, (int) $entry['max_seconds']);
            while ($lastStatus['running']) {
                if (((hrtime(true) - $startedMonotonic) / 1_000_000_000) >= $maxSeconds) {
                    proc_terminate($process);
                    $timedOut = true;
                    break;
                }
                usleep(200_000);
                $lastStatus = proc_get_status($process);
            }
            $closeCode = proc_close($process);
            $exitCode = (int) (($lastStatus['exitcode'] ?? -1) >= 0
                ? $lastStatus['exitcode']
                : $closeCode);
        } else {
            file_put_contents($stdoutPath, '', FILE_APPEND);
            file_put_contents($stderrPath, '', FILE_APPEND);
        }
        $normalizationError = null;
        if (! $timedOut && $exitCode === 0) {
            try {
                $normalized = (new NativeResultNormalizer)->normalize(
                    (string) $manifest->data['suite_id'],
                    $entry,
                    $cwd,
                );
                file_put_contents(
                    $resultPath,
                    json_encode(
                        $normalized,
                        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION,
                    )
                );
            } catch (Throwable $e) {
                $normalizationError = $e->getMessage();
                file_put_contents(
                    $stderrPath,
                    "\n[rivals-normalizer] {$normalizationError}\n",
                    FILE_APPEND,
                );
            }
        }
        $resultPresent = is_file($resultPath);
        $status = match (true) {
            $timedOut => 'timeout',
            $exitCode === 0 && $resultPresent && $normalizationError === null => 'success',
            default => 'environment_failure',
        };
        if (! $resultPresent) {
            file_put_contents($resultPath, json_encode([
                '_rivals_runner' => [
                    'execution_id' => $executionId,
                    'status' => $status,
                    'exit_code' => $exitCode,
                    'reason' => $normalizationError ?? 'native_result_not_created',
                ],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }
        $finishedAt = now();
        $receipt = NativeExecutionReceipt::fromArray([
            'schema_version' => NativeExecutionReceipt::SCHEMA,
            'run_id' => $runId,
            'execution_id' => $executionId,
            'manifest_hash' => $manifest->hash(),
            'command_hash' => (string) $entry['command_hash'],
            'expected_result_path' => (string) $entry['expected_result_path'],
            'result_sha256' => hash_file('sha256', $resultPath),
            'status' => $status,
            'exit_code' => $exitCode,
            'started_at' => $startedAt->toIso8601String(),
            'finished_at' => $finishedAt->toIso8601String(),
            'wall_ms' => (int) $startedAt->diffInRealMilliseconds($finishedAt),
            'cost_usd' => 0.0,
            'stdout' => [
                'present' => is_file($stdoutPath),
                'sha256' => is_file($stdoutPath) ? hash_file('sha256', $stdoutPath) : null,
            ],
            'stderr' => [
                'present' => is_file($stderrPath),
                'sha256' => is_file($stderrPath) ? hash_file('sha256', $stderrPath) : null,
            ],
            'runner' => [
                'version' => 'rivals-native-runner-v1',
                'php' => PHP_VERSION,
                'cwd' => realpath($cwd) ?: $cwd,
            ],
        ]);
        $receiptPath = $receipt->persist();
        EventStream::append($runId, 'native_execution_finished', [
            'execution_id' => $executionId,
            'status' => $status,
            'exit_code' => $exitCode,
            'result_sha256' => $receipt->data['result_sha256'],
        ]);
        $summary[] = [
            'execution_id' => $executionId,
            'status' => $status,
            'exit_code' => $exitCode,
            'receipt_path' => $receiptPath,
        ];
    }

    echo json_encode([
        'schema_version' => 'atlas.rivals2.native_runner_result.v1',
        'run_id' => $manifest->data['run_id'],
        'manifest_hash' => $manifest->hash(),
        'executions' => $summary,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, json_encode([
        'status' => 'error',
        'error' => $e->getMessage(),
        'exception' => $e::class,
    ], JSON_UNESCAPED_SLASHES).PHP_EOL);
    exit(1);
}

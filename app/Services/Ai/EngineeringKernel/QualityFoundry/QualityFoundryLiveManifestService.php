<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel\QualityFoundry;

use Symfony\Component\Process\Process;

/**
 * Builds a read-only live evidence manifest by executing the canonical,
 * mode-scoped test receipts. It never mutates a workspace or authorizes a
 * release; missing runtime evidence remains an explicit blocker.
 */
final class QualityFoundryLiveManifestService
{
    private const ROLLBACK_EVIDENCE_TEST = 'tests/Feature/Ai/EngineeringKernel/CanonicalCommitActuationTest.php';

    /** @var array<string,list<string>> */
    private const MODE_TESTS = [
        'kernel' => [
            'tests/Unit/Ai/EngineeringKernel/QualityFoundryModeParityServiceTest.php',
            'tests/Unit/Ai/EngineeringKernel/ExecutionOrderModeParityTest.php',
        ],
        'dev' => [
            'tests/Unit/Ai/Programming/AtlasDev/Http/PipelineRunExecutorHermesProviderTest.php',
            'tests/Feature/Architecture/EngineeringKernelBypassRegressionTest.php',
        ],
        'forge' => [
            'tests/Unit/Ai/Programming/Forge/Execution/ForgeObraRuntimeContractTest.php',
            'tests/Feature/Architecture/EngineeringKernelBypassRegressionTest.php',
        ],
        'autonomos' => [
            'tests/Unit/Ai/SelfConstruction/AtlasTaskServingStackTest.php',
            'tests/Feature/Architecture/EngineeringKernelBypassRegressionTest.php',
        ],
    ];

    /** @var callable(array<int,string>,string):array{exit_code:int,output:string} */
    private $runner;

    public function __construct(
        private readonly ?string $basePath = null,
        ?callable $runner = null,
    ) {
        $this->runner = $runner ?? function (array $command, string $cwd): array {
            $process = new Process(
                array_merge(
                    ['/usr/bin/env', 'APP_ENV=testing', 'DB_CONNECTION=sqlite', 'DB_DATABASE=:memory:'],
                    $command,
                ),
                $cwd,
            );
            $process->setTimeout(180.0);
            $exitCode = $process->run();

            return [
                'exit_code' => $exitCode,
                'output' => trim($process->getOutput()."\n".$process->getErrorOutput()),
            ];
        };
    }

    /** @return array<string,mixed> */
    public function build(): array
    {
        $root = $this->basePath ?? base_path();
        $modes = [];

        foreach (self::MODE_TESTS as $mode => $tests) {
            $modes[$mode] = $this->runMode(
                $mode,
                array_values(array_unique([...$tests, self::ROLLBACK_EVIDENCE_TEST])),
                $root,
            );
        }

        return (new QualityFoundryModeReadinessManifestService)->build([
            'modes' => $modes,
            // A test receipt alone cannot prove cross-mode parity, exactly-once
            // mutation or a production shadow boundary.
            'mode_parity' => false,
            'idempotency' => ['provider_invocations' => 0, 'mutations' => 0],
            'shadow' => ['replay_only' => true, 'mutation_allowed' => false],
        ]);
    }

    /** @param list<string> $tests @return array<string,mixed> */
    private function runMode(string $mode, array $tests, string $root): array
    {
        $startedAt = microtime(true);
        $testRefs = [];
        foreach ($tests as $path) {
            $absolute = $root.DIRECTORY_SEPARATOR.$path;
            $testRefs[] = [
                'path' => $path,
                'sha256' => is_file($absolute) ? (string) hash_file('sha256', $absolute) : '',
            ];
        }

        $result = ($this->runner)(array_merge([PHP_BINARY, 'artisan', 'test'], $tests), $root);
        $output = (string) ($result['output'] ?? '');
        $exitCode = (int) ($result['exit_code'] ?? 1);
        $receipt = [
            'schema' => 'atlas.quality_foundry.live_test_receipt.v1',
            'mode' => $mode,
            'command' => array_merge([PHP_BINARY, 'artisan', 'test'], $tests),
            'exit_code' => $exitCode,
            'output_hash' => hash('sha256', $output),
            'test_refs' => $testRefs,
            'started_at_unix' => $startedAt,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ];

        return [
            'source' => 'live_receipt',
            'receipt_hashes' => [hash('sha256', json_encode($receipt, JSON_UNESCAPED_SLASHES) ?: '')],
            'test_refs' => $testRefs,
            'kernel_routed' => $exitCode === 0,
            'coverage_percent' => 0,
            'rollback_exercised' => in_array(self::ROLLBACK_EVIDENCE_TEST, $tests, true) && $exitCode === 0,
            'evidence' => [
                'rollback_exercised' => in_array(self::ROLLBACK_EVIDENCE_TEST, $tests, true) && $exitCode === 0,
                'outcome_writer_active' => in_array(self::ROLLBACK_EVIDENCE_TEST, $tests, true) && $exitCode === 0,
            ],
            // The canonical rollback suite asserts provisional and terminal
            // outcome events through AtlasEvidenceLedger. This is test-path
            // evidence only; it does not imply production cutover.
            'outcome_writer_active' => in_array(self::ROLLBACK_EVIDENCE_TEST, $tests, true) && $exitCode === 0,
            'command' => $receipt['command'],
            'exit_code' => $exitCode,
            'output_hash' => $receipt['output_hash'],
            'duration_ms' => $receipt['duration_ms'],
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\HardCaseBench;

use Throwable;

/**
 * One FACT-shaped record of a single bench run against the live Loop wiring. NO score, NO grade, NO ranking
 * — pétreo: "FATOS, nunca score". Co-located with the runner.
 */
final class HardCaseBenchResult
{
    public const OUTCOME_PASS = 'pass';

    public const OUTCOME_FAIL = 'fail';

    public const OUTCOME_UNKNOWN = 'unknown';

    public function __construct(
        public readonly string $caseId,
        public readonly string $ranAt,
        public readonly string $currentLoopVersion,
        public readonly string $outcome,
        public readonly ?string $observedFailureSignature,
        public readonly string $evidencePath,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'case_id' => $this->caseId,
            'ran_at' => $this->ranAt,
            'current_loop_version' => $this->currentLoopVersion,
            'outcome' => $this->outcome,
            'observed_failure_signature' => $this->observedFailureSignature,
            'evidence_path' => $this->evidencePath,
        ];
    }
}

/**
 * Replays a registered hard case against the CURRENT Loop wiring and records a FACT pass/fail. The runner
 * is sandbox-friendly: it takes the LoopRunner (and its frozen judge) duck-typed via constructor closures
 * so unit tests substitute fakes. Each run() writes one append-only result file under
 * storage/atlas/hardcase-bench/runs/.
 *
 * PASS DEFINITION (anti-Goodhart): the observed_failure_signature MUST differ from the case's
 * expected_failure_mode for a `pass` — the Loop has to genuinely handle the case differently than history
 * recorded. A reproduction of the same failure signature is a `fail`.
 */
final class AtlasLoopHardCaseBenchRunner
{
    /** @var callable(array<string,mixed> $minimalReproSeed):array{failure_signature:?string} */
    private $loopRunner;

    /** @var null|callable():string */
    private $clock;

    private ?string $storageRoot = null;

    /**
     * @param  callable(array<string,mixed>):array{failure_signature:?string}  $loopRunner
     *         The live Loop entrypoint surface (production: AtlasEvolutionLoopRunner + frozen judge).
     */
    public function __construct(
        private readonly AtlasLoopHardCaseDatasetRegistry $registry,
        callable $loopRunner,
        ?string $storageRoot = null,
        ?callable $clock = null,
    ) {
        $this->loopRunner = $loopRunner;
        $this->storageRoot = $storageRoot;
        $this->clock = $clock;
    }

    public function setStorageRootForTesting(?string $path): void
    {
        $this->storageRoot = $path === null ? null : rtrim($path, '/');
    }

    public function run(string $caseId, array $options = []): HardCaseBenchResult
    {
        $case = $this->registry->get($caseId);
        if ($case === null) {
            return $this->record(new HardCaseBenchResult(
                caseId: $caseId,
                ranAt: $this->now(),
                currentLoopVersion: (string) ($options['current_loop_version'] ?? ''),
                outcome: HardCaseBenchResult::OUTCOME_UNKNOWN,
                observedFailureSignature: null,
                evidencePath: '',
            ));
        }

        try {
            $loopResult = ($this->loopRunner)(is_array($case['minimal_repro_seed'] ?? null) ? $case['minimal_repro_seed'] : []);
        } catch (Throwable $e) {
            $loopResult = ['failure_signature' => 'runner_threw:'.$e->getMessage()];
        }
        if (! is_array($loopResult)) {
            $loopResult = ['failure_signature' => null];
        }

        $observed = isset($loopResult['failure_signature']) ? (string) $loopResult['failure_signature'] : null;
        $expected = (string) ($case['expected_failure_mode'] ?? '');

        $outcome = HardCaseBenchResult::OUTCOME_UNKNOWN;
        if ($observed !== null && $observed !== '') {
            $outcome = $observed === $expected
                ? HardCaseBenchResult::OUTCOME_FAIL          // historical failure reproduced
                : HardCaseBenchResult::OUTCOME_PASS;         // loop handled it differently — genuine pass
        }

        $ranAt = $this->now();
        $evidencePath = $this->evidencePath($caseId, $ranAt);

        return $this->record(new HardCaseBenchResult(
            caseId: $caseId,
            ranAt: $ranAt,
            currentLoopVersion: (string) ($options['current_loop_version'] ?? ''),
            outcome: $outcome,
            observedFailureSignature: $observed,
            evidencePath: $evidencePath,
        ));
    }

    /**
     * @return list<HardCaseBenchResult>
     */
    public function runAll(array $options = []): array
    {
        $out = [];
        foreach ($this->registry->all() as $case) {
            $out[] = $this->run((string) $case['case_id'], $options);
        }

        return $out;
    }

    private function record(HardCaseBenchResult $result): HardCaseBenchResult
    {
        if ($result->evidencePath === '') {
            return $result;
        }
        $dir = dirname($result->evidencePath);
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        file_put_contents($result->evidencePath, (string) json_encode($result->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);

        return $result;
    }

    private function evidencePath(string $caseId, string $ranAt): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_\-]/', '_', $caseId) ?? 'case';
        $stamp = preg_replace('/[^0-9]/', '', $ranAt) ?? 'ts';

        return $this->runsRoot().'/'.$safe.'-'.$stamp.'.json';
    }

    private function runsRoot(): string
    {
        if ($this->storageRoot !== null && $this->storageRoot !== '') {
            return $this->storageRoot;
        }
        if (function_exists('storage_path')) {
            try {
                return rtrim((string) storage_path('atlas/hardcase-bench/runs'), '/');
            } catch (Throwable) {
            }
        }

        return sys_get_temp_dir().'/atlas-hardcase-bench-runs';
    }

    private function now(): string
    {
        $clock = $this->clock;
        if (is_callable($clock)) {
            return (string) $clock();
        }

        return gmdate('Y-m-d\TH:i:s\Z');
    }
}

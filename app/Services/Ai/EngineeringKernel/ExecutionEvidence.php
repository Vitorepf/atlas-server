<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel;

/**
 * Engineering Kernel value: what a delivery claims it executed to earn a green — and the raw
 * facts the floor uses to tell a real test run from a lint-as-suite lie.
 *
 * Owns: carrying the executed command, the claimed status, and the objective counts (tests run,
 * assertions executed) plus artifacts, as immutable data.
 * Must never own: judging whether those facts constitute a real pass — that is the
 * SovereignHonestyFloor's `false_claim_blocked` invariant. This DTO stays dumb on purpose.
 */
final readonly class ExecutionEvidence
{
    /**
     * @param  list<string>  $commands       the actual commands run (e.g. ['php artisan test ...'])
     * @param  string  $claimedStatus         what the evidence claims: 'passed' | 'failed' | 'unknown'
     * @param  int  $testsRun                  number of test cases actually executed
     * @param  int  $assertionsExecuted        number of assertions actually executed
     * @param  list<string>  $selectedTests    the suite the evidence CLAIMS to represent
     * @param  list<string>  $artifacts        files produced by the run
     */
    public function __construct(
        public array $commands,
        public string $claimedStatus,
        public int $testsRun,
        public int $assertionsExecuted,
        public array $selectedTests,
        public array $artifacts,
    ) {}

    /**
     * @param  array<string,mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            commands: array_values(array_map('strval', (array) ($data['commands'] ?? []))),
            claimedStatus: (string) ($data['claimed_status'] ?? 'unknown'),
            testsRun: (int) ($data['tests_run'] ?? 0),
            assertionsExecuted: (int) ($data['assertions_executed'] ?? 0),
            selectedTests: array_values(array_map('strval', (array) ($data['selected_tests'] ?? []))),
            artifacts: array_values(array_map('strval', (array) ($data['artifacts'] ?? []))),
        );
    }
}

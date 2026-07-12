<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Compounding;

use App\Services\Ai\Compounding\CausalLearningCandidate;
use App\Services\Ai\Compounding\CausalLearningGate;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CausalLearningGateTest extends TestCase
{
    public function test_complete_reversible_candidate_is_promoted_deterministically(): void
    {
        $candidate = CausalLearningCandidate::fromArray($this->valid());
        $gate = new CausalLearningGate;

        $first = $gate->adjudicate($candidate);
        $second = $gate->adjudicate(CausalLearningCandidate::fromArray($candidate->toArray()));

        self::assertSame('promote_reversible', $first->verdict);
        self::assertSame($first->decisionHash, $second->decisionHash);
    }

    public function test_correlation_without_assignment_is_held(): void
    {
        $candidate = CausalLearningCandidate::fromArray(array_replace($this->valid(), ['assignment_hash' => str_repeat('0', 64)]));

        self::assertSame('hold', (new CausalLearningGate)->adjudicate($candidate)->verdict);
    }

    public function test_code_change_is_emitted_as_governed_task_even_with_complete_evidence(): void
    {
        $candidate = CausalLearningCandidate::fromArray(array_replace($this->valid(), ['change_class' => 'code_task']));

        self::assertSame('emit_code_task', (new CausalLearningGate)->adjudicate($candidate)->verdict);
    }

    public function test_invalid_candidate_fails_closed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        CausalLearningCandidate::fromArray(array_replace($this->valid(), ['hypothesis' => '']));
    }

    public function test_inconsistent_interval_is_rejected(): void
    {
        $candidate = CausalLearningCandidate::fromArray(array_replace($this->valid(), ['ci_low' => 0.40, 'ci_high' => 0.10]));

        self::assertSame('reject', (new CausalLearningGate)->adjudicate($candidate)->verdict);
    }

    public function test_assignment_and_execution_are_bound_to_the_same_order(): void
    {
        $candidate = CausalLearningCandidate::fromArray(array_replace($this->valid(), ['order_hash' => str_repeat('0', 64)]));

        self::assertSame('hold', (new CausalLearningGate)->adjudicate($candidate)->verdict);
    }

    /** @return array<string,mixed> */
    private function valid(): array
    {
        $hash = static fn (string $c): string => str_repeat($c, 64);

        return [
            'assignment_hash' => $hash('b'), 'experiment_hash' => $hash('c'), 'order_hash' => $hash('7'),
            'run_hash' => $hash('d'), 'release_hash' => $hash('e'), 'outcome_hash' => $hash('f'),
            'change_class' => 'routing', 'hypothesis' => 'The route improves verified outcomes',
            'baseline' => 'baseline-v1', 'metric' => 'verified_quality', 'window' => '7d',
            'effect' => 0.18, 'ci_low' => 0.06, 'ci_high' => 0.30,
            'confounders' => ['provider_drift' => 'controlled'], 'rollback' => 'route-v1',
            'reversible' => true, 'assignment_precedes_run' => true, 'real_outcome' => true,
            'authority_hash' => $hash('1'), 'scope' => 'atlas.dev.routing', 'expiry' => '2026-08-01T00:00:00Z',
        ];
    }
}

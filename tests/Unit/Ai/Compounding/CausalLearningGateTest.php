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
        $candidate = CausalLearningCandidate::fromArray(array_replace($this->valid(), [
            'assignment_hash' => str_repeat('0', 64),
            'binding_refs' => array_replace($this->valid()['binding_refs'], ['assignment' => ['hash' => str_repeat('0', 64), 'artifact_id' => 'assignment-1']]),
        ]));

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

    public function test_binding_ref_hash_mismatch_fails_closed(): void
    {
        $this->expectExceptionMessage('causal_candidate_binding_ref_mismatch_run');
        CausalLearningCandidate::fromArray(array_replace($this->valid(), [
            'binding_refs' => array_replace($this->valid()['binding_refs'], [
                'run' => ['hash' => str_repeat('0', 64), 'artifact_id' => 'run-1'],
            ]),
        ]));
    }

    public function test_every_required_candidate_field_fails_closed_when_missing(): void
    {
        foreach ([
            'assignment_hash', 'experiment_hash', 'order_hash', 'run_hash', 'release_hash', 'outcome_hash',
            'hypothesis', 'baseline', 'metric', 'window', 'effect', 'ci_low', 'ci_high', 'confounders',
            'rollback', 'reversible', 'assignment_precedes_run', 'real_outcome', 'authority_hash', 'scope',
            'expiry', 'assignment_at', 'release_at', 'run_at', 'outcome_at', 'observation_schedule', 'binding_refs',
        ] as $field) {
            try {
                CausalLearningCandidate::fromArray(array_diff_key($this->valid(), [$field => true]));
                self::fail('missing field was accepted: '.$field);
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('causal_candidate_'.$field, $exception->getMessage(), $field);
            }
        }
    }

    public function test_inconsistent_interval_is_rejected(): void
    {
        $candidate = CausalLearningCandidate::fromArray(array_replace($this->valid(), ['ci_low' => 0.40, 'ci_high' => 0.10]));

        self::assertSame('reject', (new CausalLearningGate)->adjudicate($candidate)->verdict);
    }

    public function test_assignment_and_execution_are_bound_to_the_same_order(): void
    {
        $candidate = CausalLearningCandidate::fromArray(array_replace($this->valid(), [
            'order_hash' => str_repeat('0', 64),
            'binding_refs' => array_replace($this->valid()['binding_refs'], ['order' => ['hash' => str_repeat('0', 64), 'artifact_id' => 'order-1']]),
        ]));

        self::assertSame('hold', (new CausalLearningGate)->adjudicate($candidate)->verdict);
    }

    public function test_all_execution_bindings_must_be_nonzero_before_learning_can_promote(): void
    {
        foreach (['experiment_hash', 'run_hash', 'release_hash', 'outcome_hash', 'authority_hash'] as $binding) {
            $bindingName = str_replace('_hash', '', $binding);
            $candidate = CausalLearningCandidate::fromArray(array_replace($this->valid(), [
                $binding => str_repeat('0', 64),
                'binding_refs' => array_replace($this->valid()['binding_refs'], [$bindingName => ['hash' => str_repeat('0', 64), 'artifact_id' => $bindingName.'-1']]),
            ]));

            self::assertSame('hold', (new CausalLearningGate)->adjudicate($candidate)->verdict, $binding);
            self::assertSame('causal_binding_unproven', (new CausalLearningGate)->adjudicate($candidate)->reason, $binding);
        }
    }

    public function test_known_uncontrolled_confounders_hold_learning(): void
    {
        foreach (['selection_bias', 'regression_to_mean', 'concurrent_change', 'novelty_provider_drift', 'outcome_lag'] as $key) {
            $candidate = CausalLearningCandidate::fromArray(array_replace($this->valid(), [
                'confounders' => [$key => 'uncontrolled'],
            ]));
            $verdict = (new CausalLearningGate)->adjudicate($candidate);

            self::assertSame('hold', $verdict->verdict, $key);
            self::assertSame('causal_confounder_'.$key, $verdict->reason, $key);
        }
    }

    public function test_controlled_confounder_declaration_does_not_block_learning(): void
    {
        $candidate = CausalLearningCandidate::fromArray(array_replace($this->valid(), [
            'confounders' => [
                'selection_bias' => 'controlled',
                'regression_to_mean' => 'mitigated',
                'concurrent_change' => 'absent',
                'novelty_provider_drift' => 'controlled',
                'outcome_lag' => 'bounded',
            ],
        ]));

        self::assertSame('promote_reversible', (new CausalLearningGate)->adjudicate($candidate)->verdict);
    }

    public function test_out_of_order_temporal_evidence_is_held(): void
    {
        $candidate = CausalLearningCandidate::fromArray(array_replace($this->valid(), [
            'run_at' => '2026-07-12T00:30:00Z',
            'release_at' => '2026-07-12T01:00:00Z',
        ]));

        $verdict = (new CausalLearningGate)->adjudicate($candidate);

        self::assertSame('hold', $verdict->verdict);
        self::assertSame('causal_temporal_order_unproven', $verdict->reason);
    }

    public function test_assignment_after_outcome_is_held(): void
    {
        $candidate = CausalLearningCandidate::fromArray(array_replace($this->valid(), [
            'assignment_at' => '2026-07-12T02:00:00Z',
        ]));

        $verdict = (new CausalLearningGate)->adjudicate($candidate);

        self::assertSame('hold', $verdict->verdict);
        self::assertSame('causal_temporal_order_unproven', $verdict->reason);
    }

    public function test_simulated_outcome_is_held(): void
    {
        $candidate = CausalLearningCandidate::fromArray(array_replace($this->valid(), ['real_outcome' => false]));

        $verdict = (new CausalLearningGate)->adjudicate($candidate);

        self::assertSame('hold', $verdict->verdict);
        self::assertSame('causal_binding_unproven', $verdict->reason);
    }

    public function test_non_reversible_candidate_is_held_and_cannot_promote(): void
    {
        $candidate = CausalLearningCandidate::fromArray(array_replace($this->valid(), ['reversible' => false]));
        $verdict = (new CausalLearningGate)->adjudicate($candidate);

        self::assertSame('hold', $verdict->verdict);
        self::assertSame('promotion_not_reversible', $verdict->reason);
    }

    public function test_expired_reversible_evidence_is_held(): void
    {
        $candidate = CausalLearningCandidate::fromArray(array_replace($this->valid(), [
            'expiry' => '2026-07-01T00:00:00Z',
        ]));

        $verdict = (new CausalLearningGate)->adjudicate($candidate);

        self::assertSame('hold', $verdict->verdict);
        self::assertSame('promotion_expired', $verdict->reason);
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
            'assignment_at' => '2026-07-12T00:00:00Z', 'release_at' => '2026-07-12T00:10:00Z',
            'run_at' => '2026-07-12T00:20:00Z', 'outcome_at' => '2026-07-12T01:00:00Z',
            'observation_schedule' => ['0h' => 'pending', '24h' => 'pending', '7d' => 'pending', '30d' => 'pending', '90d' => 'pending', '150d' => 'pending'],
            'binding_refs' => $this->bindingRefs($hash),
        ];
    }

    /** @param callable(string):string $hash */
    private function bindingRefs(callable $hash): array
    {
        return [
            'assignment' => ['hash' => $hash('b'), 'artifact_id' => 'assignment-1'],
            'experiment' => ['hash' => $hash('c'), 'artifact_id' => 'experiment-1'],
            'order' => ['hash' => $hash('7'), 'artifact_id' => 'order-1'],
            'run' => ['hash' => $hash('d'), 'artifact_id' => 'run-1'],
            'release' => ['hash' => $hash('e'), 'artifact_id' => 'release-1'],
            'outcome' => ['hash' => $hash('f'), 'artifact_id' => 'outcome-1'],
            'authority' => ['hash' => $hash('1'), 'artifact_id' => 'authority-1'],
        ];
    }
}

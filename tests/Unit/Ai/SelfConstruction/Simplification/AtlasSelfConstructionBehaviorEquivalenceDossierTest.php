<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Simplification;

use App\Services\Ai\SelfConstruction\Simplification\AtlasSelfConstructionBehaviorEquivalenceDossier;
use PHPUnit\Framework\TestCase;

final class AtlasSelfConstructionBehaviorEquivalenceDossierTest extends TestCase
{
    public function test_candidate_without_baseline_or_current_outputs_is_blocked(): void
    {
        $dossier = (new AtlasSelfConstructionBehaviorEquivalenceDossier)->evaluate([
            'candidate_id' => 'merge-1',
            'fixtures' => ['fixture_a'],
            'replay_checks' => ['php artisan test FooTest'],
        ]);

        self::assertSame(AtlasSelfConstructionBehaviorEquivalenceDossier::STATUS_BLOCKED, $dossier['status']);
        self::assertContains('missing_baseline_outputs', $dossier['blocked_reasons']);
        self::assertContains('missing_current_outputs', $dossier['blocked_reasons']);
        self::assertNull($dossier['dossier_hash']);
    }

    public function test_candidate_missing_replay_checks_is_blocked(): void
    {
        $dossier = (new AtlasSelfConstructionBehaviorEquivalenceDossier)->evaluate([
            'candidate_id' => 'merge-1',
            'fixtures' => ['fixture_a'],
            'baseline_outputs' => ['result' => 1],
            'current_outputs' => ['result' => 1],
        ]);

        self::assertSame(AtlasSelfConstructionBehaviorEquivalenceDossier::STATUS_BLOCKED, $dossier['status']);
        self::assertContains('missing_replay_checks', $dossier['blocked_reasons']);
    }

    public function test_diverging_outputs_are_blocked(): void
    {
        $dossier = (new AtlasSelfConstructionBehaviorEquivalenceDossier)->evaluate([
            'candidate_id' => 'merge-1',
            'fixtures' => ['fixture_a'],
            'baseline_outputs' => ['result' => 1],
            'current_outputs' => ['result' => 2],
            'replay_checks' => ['php artisan test FooTest'],
        ]);

        self::assertSame(AtlasSelfConstructionBehaviorEquivalenceDossier::STATUS_BLOCKED, $dossier['status']);
        self::assertStringContainsString('outputs_diverge:result', $dossier['blocked_reasons'][0]);
    }

    private function fullParityFields(): array
    {
        return [
            'baseline_errors' => ['error_count' => 0],
            'current_errors' => ['error_count' => 0],
            'baseline_side_effects' => ['writes' => 1],
            'current_side_effects' => ['writes' => 1],
            'baseline_command_exit' => ['exit_code' => 0],
            'current_command_exit' => ['exit_code' => 0],
            'baseline_tests' => ['passed' => 42],
            'current_tests' => ['passed' => 42],
        ];
    }

    public function test_matching_outputs_with_replay_checks_is_ready_with_stable_hash(): void
    {
        $candidate = array_merge([
            'candidate_id' => 'merge-1',
            'fixtures' => ['fixture_a'],
            'baseline_outputs' => ['result' => 1, 'count' => 5],
            'current_outputs' => ['result' => 1, 'count' => 5],
            'replay_checks' => ['php artisan test FooTest'],
        ], $this->fullParityFields());

        $dossier = new AtlasSelfConstructionBehaviorEquivalenceDossier;
        $first = $dossier->evaluate($candidate);
        $second = $dossier->evaluate($candidate);

        self::assertSame(AtlasSelfConstructionBehaviorEquivalenceDossier::STATUS_READY, $first['status']);
        self::assertTrue($first['equivalence_proven']);
        self::assertSame([], $first['blocked_reasons']);
        self::assertNotNull($first['dossier_hash']);
        self::assertSame($first['dossier_hash'], $second['dossier_hash']);
    }

    public function test_tolerated_deltas_allow_declared_keys_to_diverge(): void
    {
        $dossier = (new AtlasSelfConstructionBehaviorEquivalenceDossier)->evaluate(array_merge([
            'candidate_id' => 'merge-1',
            'fixtures' => ['fixture_a'],
            'baseline_outputs' => ['result' => 1, 'timing_ms' => 100],
            'current_outputs' => ['result' => 1, 'timing_ms' => 250],
            'tolerated_deltas' => ['timing_ms'],
            'replay_checks' => ['php artisan test FooTest'],
        ], $this->fullParityFields()));

        self::assertSame(AtlasSelfConstructionBehaviorEquivalenceDossier::STATUS_READY, $dossier['status']);
    }

    public function test_missing_candidate_id_is_blocked(): void
    {
        $dossier = (new AtlasSelfConstructionBehaviorEquivalenceDossier)->evaluate(array_merge([
            'fixtures' => ['fixture_a'],
            'baseline_outputs' => ['result' => 1],
            'current_outputs' => ['result' => 1],
            'replay_checks' => ['php artisan test FooTest'],
        ], $this->fullParityFields()));

        self::assertSame(AtlasSelfConstructionBehaviorEquivalenceDossier::STATUS_BLOCKED, $dossier['status']);
        self::assertContains('missing_candidate_id', $dossier['blocked_reasons']);
    }

    // ── AC2: matching outputs but changed error behavior is blocked ──────────

    public function test_changed_error_behavior_with_matching_outputs_is_blocked_with_error_parity_missing(): void
    {
        $dossier = (new AtlasSelfConstructionBehaviorEquivalenceDossier)->evaluate(array_merge([
            'candidate_id' => 'merge-1',
            'fixtures' => ['fixture_a'],
            'baseline_outputs' => ['result' => 1],
            'current_outputs' => ['result' => 1],
            'replay_checks' => ['php artisan test FooTest'],
        ], array_merge($this->fullParityFields(), [
            'baseline_errors' => ['error_count' => 0],
            'current_errors' => ['error_count' => 1],
        ])));

        self::assertSame(AtlasSelfConstructionBehaviorEquivalenceDossier::STATUS_BLOCKED, $dossier['status']);
        self::assertFalse($dossier['equivalence_proven']);
        $found = false;
        foreach ($dossier['blocked_reasons'] as $reason) {
            if (str_starts_with($reason, 'error_parity_missing')) {
                $found = true;
            }
        }
        self::assertTrue($found, 'Expected error_parity_missing reason');
    }

    // ── AC3: full parity across outputs/errors/side effects/commands/tests → equivalence_proven ──

    public function test_full_parity_across_all_categories_yields_equivalence_proven_true(): void
    {
        $dossier = (new AtlasSelfConstructionBehaviorEquivalenceDossier)->evaluate(array_merge([
            'candidate_id' => 'merge-1',
            'fixtures' => ['fixture_a'],
            'baseline_outputs' => ['result' => 1],
            'current_outputs' => ['result' => 1],
            'replay_checks' => ['php artisan test FooTest'],
        ], $this->fullParityFields()));

        self::assertSame(AtlasSelfConstructionBehaviorEquivalenceDossier::STATUS_READY, $dossier['status']);
        self::assertTrue($dossier['equivalence_proven']);
        self::assertSame([], $dossier['blocked_reasons']);
    }

    // ── AC4: dossier names the exact missing proof sections ──────────────────

    public function test_missing_side_effect_and_command_exit_sections_are_named_exactly(): void
    {
        $dossier = (new AtlasSelfConstructionBehaviorEquivalenceDossier)->evaluate(array_merge([
            'candidate_id' => 'merge-1',
            'fixtures' => ['fixture_a'],
            'baseline_outputs' => ['result' => 1],
            'current_outputs' => ['result' => 1],
            'replay_checks' => ['php artisan test FooTest'],
            'baseline_errors' => ['error_count' => 0],
            'current_errors' => ['error_count' => 0],
            'baseline_tests' => ['passed' => 1],
            'current_tests' => ['passed' => 1],
        ]));

        self::assertContains('missing_side_effect_receipts', $dossier['blocked_reasons']);
        self::assertContains('missing_command_exit_expectations', $dossier['blocked_reasons']);
    }
}

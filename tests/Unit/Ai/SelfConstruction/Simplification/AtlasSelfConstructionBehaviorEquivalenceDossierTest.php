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

    public function test_matching_outputs_with_replay_checks_is_ready_with_stable_hash(): void
    {
        $candidate = [
            'candidate_id' => 'merge-1',
            'fixtures' => ['fixture_a'],
            'baseline_outputs' => ['result' => 1, 'count' => 5],
            'current_outputs' => ['result' => 1, 'count' => 5],
            'replay_checks' => ['php artisan test FooTest'],
        ];

        $dossier = new AtlasSelfConstructionBehaviorEquivalenceDossier;
        $first = $dossier->evaluate($candidate);
        $second = $dossier->evaluate($candidate);

        self::assertSame(AtlasSelfConstructionBehaviorEquivalenceDossier::STATUS_READY, $first['status']);
        self::assertSame([], $first['blocked_reasons']);
        self::assertNotNull($first['dossier_hash']);
        self::assertSame($first['dossier_hash'], $second['dossier_hash']);
    }

    public function test_tolerated_deltas_allow_declared_keys_to_diverge(): void
    {
        $dossier = (new AtlasSelfConstructionBehaviorEquivalenceDossier)->evaluate([
            'candidate_id' => 'merge-1',
            'fixtures' => ['fixture_a'],
            'baseline_outputs' => ['result' => 1, 'timing_ms' => 100],
            'current_outputs' => ['result' => 1, 'timing_ms' => 250],
            'tolerated_deltas' => ['timing_ms'],
            'replay_checks' => ['php artisan test FooTest'],
        ]);

        self::assertSame(AtlasSelfConstructionBehaviorEquivalenceDossier::STATUS_READY, $dossier['status']);
    }

    public function test_missing_candidate_id_is_blocked(): void
    {
        $dossier = (new AtlasSelfConstructionBehaviorEquivalenceDossier)->evaluate([
            'fixtures' => ['fixture_a'],
            'baseline_outputs' => ['result' => 1],
            'current_outputs' => ['result' => 1],
            'replay_checks' => ['php artisan test FooTest'],
        ]);

        self::assertSame(AtlasSelfConstructionBehaviorEquivalenceDossier::STATUS_BLOCKED, $dossier['status']);
        self::assertContains('missing_candidate_id', $dossier['blocked_reasons']);
    }
}

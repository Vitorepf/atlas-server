<?php

declare(strict_types=1);

namespace Tests\Unit\Brain2;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainMuscleSkillFitRouter;
use PHPUnit\Framework\TestCase;

/**
 * Proves that scoreCandidate() feeds the Wilson score lower bound of family
 * success into the family_success fit term and the scope bonus instead of
 * the raw rate, so a candidate with one family success (1/1) no longer
 * contributes a full 0.35 and outranks a 34/40 veteran.
 *
 * BEFORE: candidate X {total:1,success:1} → familySuccessRate=1.0 → contribution 0.35
 *         candidate Y {total:40,success:34} → familySuccessRate=0.85 → contribution 0.2975
 *         X wins (higher fit_score at equal skill/reliability)
 * AFTER:  X {1,1} → Wilson LB ≈ 0.21 → contribution ~0.074
 *         Y {40,34} → Wilson LB ≈ 0.71 → contribution ~0.249
 *         Y wins (well-proven veteran beats lucky newcomer)
 */
final class AtlasExternalBrainMuscleSkillFitRouterWilsonTest extends TestCase
{
    private function route(array $overrides = []): array
    {
        return (new AtlasExternalBrainMuscleSkillFitRouter)->route(array_merge([
            'task_family' => 'refactor',
            'required_skills' => ['php'],
            'candidates' => [],
        ], $overrides));
    }

    public function test_newcomer_with_one_success_loses_to_veteran_with_strong_history(): void
    {
        // Equal skill and reliability — the only difference is family history.
        // X: 1/1 (raw 1.0 → Wilson LB ~0.21)
        // Y: 34/40 (raw 0.85 → Wilson LB ~0.71)
        // Wilson LB makes Y's family term much higher, so Y becomes primary.
        $r = $this->route([
            'candidates' => [
                [
                    'muscle_id' => 'newcomer',
                    'skills' => ['php'],
                    'history' => ['refactor' => ['total' => 1, 'success' => 1]],
                ],
                [
                    'muscle_id' => 'veteran',
                    'skills' => ['php'],
                    'history' => ['refactor' => ['total' => 40, 'success' => 34]],
                ],
            ],
        ]);

        $this->assertSame('veteran', $r['primary_muscle'],
            'veteran with Wilson LB ~0.71 must outrank newcomer with Wilson LB ~0.21');
    }

    public function test_inverse_when_newcomer_has_no_history_and_veteran_is_poor(): void
    {
        // Newcomer has NO history → neutral prior 0.5 → contribution 0.175
        // Veteran has 5/40 (poor) → Wilson LB ~0.05 → contribution ~0.018
        // Veteran loses despite having data; newcomer's neutral prior is higher.
        $r = $this->route([
            'candidates' => [
                [
                    'muscle_id' => 'newcomer',
                    'skills' => ['php'],
                    // no history → neutral
                ],
                [
                    'muscle_id' => 'poor_veteran',
                    'skills' => ['php'],
                    'history' => ['refactor' => ['total' => 40, 'success' => 5]],
                ],
            ],
        ]);

        $this->assertSame('newcomer', $r['primary_muscle'],
            'newcomer with neutral prior 0.5 must outrank poor veteran with Wilson LB ~0.05');
    }

    public function test_strong_history_still_beats_weak_history_when_both_have_enough_data(): void
    {
        // Both have enough data for Wilson to be meaningful.
        // Good: 38/40 → Wilson LB ~0.84
        // Weak: 20/40 → Wilson LB ~0.35
        $r = $this->route([
            'candidates' => [
                [
                    'muscle_id' => 'good',
                    'skills' => ['php'],
                    'history' => ['refactor' => ['total' => 40, 'success' => 38]],
                ],
                [
                    'muscle_id' => 'weak',
                    'skills' => ['php'],
                    'history' => ['refactor' => ['total' => 40, 'success' => 20]],
                ],
            ],
        ]);

        $this->assertSame('good', $r['primary_muscle'],
            'strong Wilson LB ~0.84 must outrank weak Wilson LB ~0.35');
    }
}

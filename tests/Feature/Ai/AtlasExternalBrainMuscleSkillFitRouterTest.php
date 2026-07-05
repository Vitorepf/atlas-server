<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainMuscleSkillFitRouter;
use Tests\TestCase;

final class AtlasExternalBrainMuscleSkillFitRouterTest extends TestCase
{
    private function router(): AtlasExternalBrainMuscleSkillFitRouter
    {
        return new AtlasExternalBrainMuscleSkillFitRouter;
    }

    public function test_output_has_required_keys(): void
    {
        $r = $this->router()->route([
            'task_family' => 'php_service',
            'candidates' => [
                ['muscle_id' => 'worker-1'],
            ],
        ]);

        foreach (['schema', 'ranked_muscles', 'primary_muscle', 'fallback_muscle'] as $key) {
            $this->assertArrayHasKey($key, $r);
        }
        $this->assertSame(AtlasExternalBrainMuscleSkillFitRouter::SCHEMA, $r['schema']);
    }

    public function test_ranked_muscle_entry_has_required_fields(): void
    {
        $r = $this->router()->route([
            'task_family' => 'php_service',
            'required_skills' => ['php', 'phpunit'],
            'candidates' => [
                ['muscle_id' => 'worker-1', 'skills' => ['php', 'phpunit']],
            ],
        ]);

        $entry = $r['ranked_muscles'][0];
        foreach (['muscle_id', 'fit_score', 'risk_reasons', 'expected_success_confidence', 'rank'] as $key) {
            $this->assertArrayHasKey($key, $entry);
        }
    }

    public function test_full_skill_match_outranks_partial_match(): void
    {
        $r = $this->router()->route([
            'task_family' => 'php_service',
            'required_skills' => ['php', 'phpunit', 'laravel'],
            'candidates' => [
                ['muscle_id' => 'partial', 'skills' => ['php']],
                ['muscle_id' => 'full', 'skills' => ['php', 'phpunit', 'laravel']],
            ],
        ]);

        $this->assertSame('full', $r['primary_muscle']);
        $this->assertSame('partial', $r['ranked_muscles'][1]['muscle_id']);
    }

    public function test_missing_skills_are_reported_as_risk_reason(): void
    {
        $r = $this->router()->route([
            'task_family' => 'php_service',
            'required_skills' => ['php', 'go'],
            'candidates' => [
                ['muscle_id' => 'worker-1', 'skills' => ['php']],
            ],
        ]);

        $reasons = $r['ranked_muscles'][0]['risk_reasons'];
        $this->assertNotEmpty($reasons);
        $this->assertStringContainsString('go', implode(',', $reasons));
    }

    public function test_repeated_give_back_history_disqualifies_muscle_from_primary(): void
    {
        $r = $this->router()->route([
            'task_family' => 'php_service',
            'required_skills' => ['php'],
            'candidates' => [
                [
                    'muscle_id' => 'unreliable',
                    'skills' => ['php'],
                    'history' => [
                        'php_service' => ['success' => 1, 'give_back' => 5, 'total' => 6],
                    ],
                ],
                [
                    'muscle_id' => 'reliable',
                    'skills' => ['php'],
                    'history' => [
                        'php_service' => ['success' => 4, 'give_back' => 0, 'total' => 4],
                    ],
                ],
            ],
        ]);

        $this->assertSame('reliable', $r['primary_muscle']);
        $unreliableEntry = collect($r['ranked_muscles'])->firstWhere('muscle_id', 'unreliable');
        $this->assertNotNull($unreliableEntry);
        $this->assertGreaterThan(1, $unreliableEntry['rank']);
        $this->assertNotEmpty($unreliableEntry['risk_reasons']);
    }

    public function test_repeated_scope_failure_disqualifies_muscle_from_primary(): void
    {
        $r = $this->router()->route([
            'task_family' => 'php_service',
            'candidates' => [
                [
                    'muscle_id' => 'scope-breaker',
                    'history' => [
                        'php_service' => ['success' => 3, 'scope_failure' => 1, 'total' => 4],
                    ],
                ],
                [
                    'muscle_id' => 'clean',
                    'history' => [
                        'php_service' => ['success' => 3, 'total' => 3],
                    ],
                ],
            ],
        ]);

        $this->assertSame('clean', $r['primary_muscle']);
    }

    public function test_disqualified_muscle_still_appears_in_ranked_muscles_never_silently_dropped(): void
    {
        $r = $this->router()->route([
            'task_family' => 'php_service',
            'candidates' => [
                [
                    'muscle_id' => 'unreliable',
                    'history' => ['php_service' => ['give_back' => 9, 'total' => 9]],
                ],
            ],
        ]);

        $this->assertCount(1, $r['ranked_muscles']);
        $this->assertSame('unreliable', $r['ranked_muscles'][0]['muscle_id']);
        $this->assertNull($r['primary_muscle']); // sole candidate is disqualified, never promoted
    }

    public function test_recent_give_back_rate_lowers_fit_score(): void
    {
        $r = $this->router()->route([
            'task_family' => 'php_service',
            'candidates' => [
                ['muscle_id' => 'flaky', 'recent_give_back_rate' => 0.8],
                ['muscle_id' => 'stable', 'recent_give_back_rate' => 0.0],
            ],
        ]);

        $flaky = collect($r['ranked_muscles'])->firstWhere('muscle_id', 'flaky');
        $stable = collect($r['ranked_muscles'])->firstWhere('muscle_id', 'stable');
        $this->assertLessThan($stable['fit_score'], $flaky['fit_score']);
    }

    public function test_risk_level_above_muscle_max_caps_fit_score_and_adds_reason(): void
    {
        $r = $this->router()->route([
            'task_family' => 'php_service',
            'risk_level' => 'high',
            'candidates' => [
                ['muscle_id' => 'low-risk-only', 'max_risk_level' => 'low'],
            ],
        ]);

        $entry = $r['ranked_muscles'][0];
        $this->assertLessThanOrEqual(0.30, $entry['fit_score']);
        $this->assertNotEmpty($entry['risk_reasons']);
    }

    public function test_fallback_muscle_is_second_ranked(): void
    {
        $r = $this->router()->route([
            'task_family' => 'php_service',
            'candidates' => [
                ['muscle_id' => 'a', 'recent_give_back_rate' => 0.0],
                ['muscle_id' => 'b', 'recent_give_back_rate' => 0.5],
            ],
        ]);

        $this->assertSame($r['ranked_muscles'][1]['muscle_id'], $r['fallback_muscle']);
    }

    public function test_no_candidates_returns_empty_ranked_list_and_null_muscles(): void
    {
        $r = $this->router()->route(['task_family' => 'php_service']);

        $this->assertSame([], $r['ranked_muscles']);
        $this->assertNull($r['primary_muscle']);
        $this->assertNull($r['fallback_muscle']);
    }

    public function test_route_is_deterministic(): void
    {
        $input = [
            'task_family' => 'php_service',
            'required_skills' => ['php'],
            'candidates' => [
                ['muscle_id' => 'a', 'skills' => ['php']],
                ['muscle_id' => 'b', 'skills' => []],
            ],
        ];

        $first = $this->router()->route($input);
        $second = $this->router()->route($input);

        $this->assertSame($first, $second);
    }

    // ── AC: file_scope family-tag matching prefers proven historical success ──

    public function test_file_scope_match_with_strong_history_prefers_that_muscle_over_higher_skill_match(): void
    {
        $r = $this->router()->route([
            'task_family' => 'php_service',
            'required_skills' => ['php'],
            'file_scope' => ['app/Services/Ai/Foo.php'],
            'candidates' => [
                [
                    'muscle_id' => 'scope-proven',
                    'skills' => ['php'],
                    'history' => [
                        'app/services/ai' => ['success' => 9, 'total' => 10],
                    ],
                ],
                [
                    'muscle_id' => 'no-scope-history',
                    'skills' => ['php'],
                ],
            ],
        ]);

        $proven = array_values(array_filter($r['ranked_muscles'], fn ($m) => $m['muscle_id'] === 'scope-proven'))[0];
        $noHistory = array_values(array_filter($r['ranked_muscles'], fn ($m) => $m['muscle_id'] === 'no-scope-history'))[0];

        $this->assertGreaterThan($noHistory['fit_score'], $proven['fit_score']);
        $this->assertSame('app/services/ai', $proven['scope_match']);
        $this->assertNull($noHistory['scope_match']);
    }

    public function test_file_scope_with_no_matching_history_does_not_affect_fit_score(): void
    {
        $r = $this->router()->route([
            'task_family' => 'php_service',
            'required_skills' => ['php'],
            'file_scope' => ['app/Services/Ai/Foo.php'],
            'candidates' => [
                ['muscle_id' => 'a', 'skills' => ['php']],
            ],
        ]);

        $this->assertNull($r['ranked_muscles'][0]['scope_match']);
    }

    // ── AC4: fallback is never a disqualified candidate ────────────────────────

    public function test_fallback_muscle_is_never_the_only_other_candidate_when_it_is_disqualified(): void
    {
        $r = $this->router()->route([
            'task_family' => 'php_service',
            'candidates' => [
                [
                    'muscle_id' => 'good',
                    'history' => ['php_service' => ['success' => 5, 'total' => 5]],
                ],
                [
                    'muscle_id' => 'bad',
                    'history' => ['php_service' => ['give_back' => 9, 'total' => 9]],
                ],
            ],
        ]);

        $this->assertSame('good', $r['primary_muscle']);
        $this->assertNull($r['fallback_muscle'], 'a disqualified candidate must never be promoted to fallback');
    }

    public function test_fallback_muscle_prefers_the_best_non_disqualified_second_candidate(): void
    {
        $r = $this->router()->route([
            'task_family' => 'php_service',
            'candidates' => [
                [
                    'muscle_id' => 'best',
                    'history' => ['php_service' => ['success' => 5, 'total' => 5]],
                ],
                [
                    'muscle_id' => 'disqualified',
                    'history' => ['php_service' => ['give_back' => 9, 'total' => 9]],
                ],
                [
                    'muscle_id' => 'second-best',
                    'history' => ['php_service' => ['success' => 3, 'total' => 5]],
                ],
            ],
        ]);

        $this->assertSame('best', $r['primary_muscle']);
        $this->assertSame('second-best', $r['fallback_muscle']);
    }

    // AC: scope-tag bonus requires minimum sample count
    public function test_scope_bonus_requires_minimum_sample_count(): void
    {
        $router = new AtlasExternalBrainMuscleSkillFitRouter();
        // Only 1 sample — below minimum of 3 — should NOT get scope bonus
        $r = $router->route([
            'task_family' => 'refactor',
            'required_skills' => ['php'],
            'file_scope' => ['app/Services/Foo.php'],
            'candidates' => [
                [
                    'muscle_id' => 'm1',
                    'skills' => ['php'],
                    'history' => [
                        'app/services' => ['total' => 1, 'success' => 1],
                    ],
                ],
            ],
        ]);

        $entry = $r['ranked_muscles'][0];
        // Without scope bonus, fit_score = 1.0*0.40 + 0.5*0.35 + 1.0*0.25 = 0.925
        $this->assertSame(0.825, $entry['fit_score']);
    }

    // AC: scope-tag bonus applies when sample count meets minimum
    public function test_scope_bonus_applies_when_minimum_sample_count_met(): void
    {
        $router = new AtlasExternalBrainMuscleSkillFitRouter();
        // 20 samples (well above minimum) with 100% success → Wilson LB ~0.84 > 0.5 → scope bonus applies
        $r = $router->route([
            'task_family' => 'refactor',
            'required_skills' => ['php'],
            'file_scope' => ['app/Services/Foo.php'],
            'candidates' => [
                [
                    'muscle_id' => 'm1',
                    'skills' => ['php'],
                    'history' => [
                        'app/services' => ['total' => 20, 'success' => 20],
                    ],
                ],
            ],
        ]);

        $entry = $r['ranked_muscles'][0];
        // Base fit_score = 0.40 (skill) + 0.175 (family, no history → 0.5) + 0.25 (reliability) = 0.825
        // With scope Wilson LB for 20/20 ~0.84, bonus = (~0.84 - 0.5) * 0.10 = ~0.034
        // So the scope bonus is present and measurable
        $this->assertGreaterThan(0.825, $entry['fit_score'],
            'scope bonus must increase fit_score above the no-scope baseline');
        $this->assertNotNull($entry['scope_match']);
    }

    // AC: insufficient scope samples still ranked by other factors
    public function test_insufficient_scope_samples_still_ranked_by_other_factors(): void
    {
        $router = new AtlasExternalBrainMuscleSkillFitRouter();
        $r = $router->route([
            'task_family' => 'refactor',
            'required_skills' => ['php', 'laravel'],
            'file_scope' => ['app/Services/Foo.php'],
            'candidates' => [
                [
                    'muscle_id' => 'm1',
                    'skills' => ['php'],
                    'history' => [
                        'app/services' => ['total' => 1, 'success' => 1],
                    ],
                ],
                [
                    'muscle_id' => 'm2',
                    'skills' => ['php', 'laravel'],
                    'history' => [],
                ],
            ],
        ]);

        // m2 has all required skills, m1 is missing laravel — m2 ranks higher
        $this->assertSame('m2', $r['primary_muscle']);
    }
}

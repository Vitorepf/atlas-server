<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming;

use App\Services\Ai\Programming\ForgeRivals\Corpus\AtlasForgeRivalsProviderArenaCorpusService;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Atlas Forge Rivals · Provider Arena Corpus Release v1 — end-to-end via artisan.
 *
 * Sem provider invocado. Asserts: action `cases`, filtros, run-arena
 * local_fake, safety (no provider call / no external_rivals unlock).
 */
final class AtlasForgeRivalsProviderArenaCorpusTest extends TestCase
{
    public function test_cases_action_returns_release_matrix_with_forty_entries(): void
    {
        $payload = $this->runCases(['--case-set' => 'release']);

        $this->assertSame('ok', $payload['status']);
        $this->assertSame('atlas.forge.rivals.provider_arena_corpus_cases.v1', $payload['cases_schema_version']);
        $this->assertSame('atlas.forge.rivals.provider_arena_corpus.v1', $payload['corpus_schema_version']);
        $this->assertSame(40, $payload['snapshot']['count']);
        $this->assertSame('release_v1', $payload['snapshot']['release_version']);
        $this->assertFalse($payload['external_provider_call']);
        $this->assertTrue($payload['separated_from_external_rivals_certification']);
    }

    public function test_cases_action_default_filter_returns_quick_case_set_with_three_cases(): void
    {
        $payload = $this->runCases();
        $this->assertContains('case_set=quick (default)', $payload['applied_filters']);
        $this->assertSame(3, $payload['count']);
    }

    public function test_cases_action_filter_by_case_set_frontend_returns_frontend_ui_only(): void
    {
        $payload = $this->runCases(['--case-set' => 'frontend']);
        $this->assertSame('ok', $payload['status']);
        $this->assertNotEmpty($payload['cases']);
        foreach ($payload['cases'] as $case) {
            $this->assertSame('frontend_ui', $case['category']);
        }
    }

    public function test_cases_action_filter_by_case_set_backend_returns_backend_or_integration_performance(): void
    {
        $payload = $this->runCases(['--case-set' => 'backend']);
        $this->assertSame('ok', $payload['status']);
        $this->assertNotEmpty($payload['cases']);
        foreach ($payload['cases'] as $case) {
            $this->assertContains(
                (string) $case['category'],
                ['backend_logic', 'integration_performance'],
            );
        }
    }

    public function test_cases_action_filter_by_case_set_bugfix_returns_realistic_bugfix(): void
    {
        $payload = $this->runCases(['--case-set' => 'bugfix']);
        $this->assertSame('ok', $payload['status']);
        $this->assertNotEmpty($payload['cases']);
        foreach ($payload['cases'] as $case) {
            $primary = $case['category'] === 'realistic_bugfix';
            $secondary = in_array('realistic_bugfix', (array) $case['secondary_categories'], true);
            $this->assertTrue($primary || $secondary);
        }
    }

    public function test_cases_action_filter_by_case_set_architecture_returns_architecture_or_refactor(): void
    {
        $payload = $this->runCases(['--case-set' => 'architecture']);
        $cats = array_unique(array_map(static fn (array $c): string => (string) $c['category'], $payload['cases']));
        sort($cats);
        $this->assertSame(['architecture', 'refactor'], $cats);
    }

    public function test_cases_action_unknown_case_set_blocks_with_honest_reason(): void
    {
        $payload = $this->runCases(['--case-set' => 'totally-bogus']);
        $this->assertSame('blocked', $payload['status']);
        $this->assertContains('unknown_case_set:totally-bogus', (array) $payload['blockers']);
        $this->assertFalse($payload['external_provider_call']);
    }

    public function test_cases_action_unknown_case_id_blocks_with_honest_reason(): void
    {
        $payload = $this->runCases(['--case' => ['case-does-not-exist']]);
        $this->assertSame('blocked', $payload['status']);
        $this->assertContains('unknown_case_id:case-does-not-exist', (array) $payload['blockers']);
        $this->assertFalse($payload['external_provider_call']);
    }

    public function test_cases_action_filter_by_specific_case_id_returns_single_manifest(): void
    {
        $payload = $this->runCases(['--case' => ['backend-pagination-off-by-one']]);
        $this->assertSame('ok', $payload['status']);
        $this->assertSame(1, $payload['count']);
        $this->assertSame('backend-pagination-off-by-one', $payload['cases'][0]['case_id']);
        $this->assertSame('realistic_bugfix', $payload['cases'][0]['category']);
    }

    public function test_cases_action_emits_deterministic_replay_manifest(): void
    {
        $first = $this->runCases(['--case-set' => 'quick']);
        $second = $this->runCases(['--case-set' => 'quick']);

        $this->assertArrayHasKey('replay_manifest', $first);
        $this->assertSame('atlas.forge.rivals.provider_arena_corpus_replay.v1', $first['replay_manifest']['schema_version']);
        $this->assertSame(['case_set=quick'], $first['replay_manifest']['applied_filters']);
        $this->assertCount($first['count'], $first['replay_manifest']['case_ids']);

        // plan_hash não pode depender de timestamp → duas execuções com o mesmo filter têm o mesmo hash.
        $this->assertSame(
            $first['replay_manifest']['plan_hash'],
            $second['replay_manifest']['plan_hash'],
            'plan_hash precisa ser determinístico entre execuções',
        );
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $first['replay_manifest']['plan_hash']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $first['replay_manifest']['corpus_content_hash']);
    }

    public function test_cases_action_exposes_industrial_benchmark_presets(): void
    {
        $expected = [
            'industrial-50' => 50,
            'industrial-100' => 100,
            'industrial-200' => 200,
            'ambiguous-bugs' => 50,
            'multi-day-refactors' => 50,
            'incident-response' => 50,
            'product-security-migrations' => 50,
            'statistical-repeat' => 60,
            'meta-provider-stress' => 50,
            'extreme-differentiator' => 80,
            'ceiling-360' => 120,
        ];

        foreach ($expected as $caseSet => $count) {
            $payload = $this->runCases(['--case-set' => $caseSet]);
            $this->assertSame('ok', $payload['status']);
            $this->assertSame($count, $payload['count'], "{$caseSet} deve ter {$count} casos");
            $this->assertFalse($payload['external_provider_call']);
            $this->assertFalse($payload['provider_tokens_spent']);
            $this->assertSame(
                'atlas-forge-rivals-industrial-benchmark-suite-v1',
                $payload['cases'][0]['industrial_suite'],
            );
        }
    }

    public function test_meta_provider_stress_cases_expose_human_prompts_without_provider_call(): void
    {
        $payload = $this->runCases(['--case-set' => 'meta-provider-stress']);

        $this->assertSame('ok', $payload['status']);
        $this->assertSame(50, $payload['count']);
        $this->assertFalse($payload['external_provider_call']);
        $this->assertFalse($payload['provider_tokens_spent']);
        $this->assertSame(
            'atlas.forge.rivals.meta_provider_stress_coverage.v1',
            $payload['snapshot']['meta_provider_stress_coverage']['schema_version'],
        );
        $this->assertTrue($payload['snapshot']['meta_provider_stress_coverage']['coverage_floor_met']);
        $this->assertGreaterThanOrEqual(8, $payload['snapshot']['meta_provider_stress_coverage']['domain_count']);
        $this->assertSame('meta-provider-stress', $payload['cases'][0]['industrial_case_set']);
        $this->assertStringContainsString('ticket real de engenharia', $payload['cases'][0]['human_prompt']);
        $this->assertSame('atlas.forge.rivals.meta_provider_stress.v1', $payload['cases'][0]['meta_provider_stress']['schema_version']);
        $this->assertContains('cursor_cli', $payload['cases'][0]['meta_provider_stress']['targets']);
        $this->assertContains('composer_2_5', $payload['cases'][0]['meta_provider_stress']['targets']);
        $this->assertSame(
            'atlas.forge.rivals.case_complexity_profile.v1',
            $payload['cases'][0]['meta_provider_stress']['complexity_profile']['schema_version'],
        );
        $this->assertGreaterThanOrEqual(
            4200,
            $payload['cases'][0]['meta_provider_stress']['measurement_floor']['min_context_tokens'],
        );
        $this->assertTrue($payload['cases'][0]['meta_provider_stress']['measurement_floor']['requires_replay_matrix']);
        $this->assertFalse($payload['cases'][0]['meta_provider_stress']['measurement_floor']['synthetic_claim_allowed']);
        $this->assertSame('atlas.forge.rivals.human_prompt_probe.v1', $payload['cases'][0]['human_prompt_probe']['schema_version']);
        $this->assertContains('honest_blockers', $payload['cases'][0]['human_prompt_probe']['requires_sections']);
        $this->assertContains('replay_matrix', $payload['cases'][0]['human_prompt_probe']['requires_sections']);
    }

    public function test_extreme_differentiator_cases_expose_tie_breaker_contract_without_provider_call(): void
    {
        $payload = $this->runCases(['--case-set' => 'extreme-differentiator']);

        $this->assertSame('ok', $payload['status']);
        $this->assertSame(80, $payload['count']);
        $this->assertFalse($payload['external_provider_call']);
        $this->assertFalse($payload['provider_tokens_spent']);
        $this->assertSame('extreme-differentiator', $payload['cases'][0]['industrial_case_set']);
        $case = app(AtlasForgeRivalsProviderArenaCorpusService::class)
            ->casesForCaseSet('extreme-differentiator')[0];
        $this->assertSame(
            $case['fixture_seed_path'],
            $case['setup_fixture']['seed_dir'],
        );
        $this->assertSame(
            'php storage/forge-rivals-industrial/'.$case['case_id'].'/tests/'.$case['case_id'].'Test.php',
            $case['test_command'],
        );
        $this->assertStringNotContainsString(
            'storage/forge-rivals-industrial/'.$case['industrial_variant']['source_case_id'],
            $case['test_command'],
        );
        $this->assertContains(
            'storage/forge-rivals-industrial/'.$case['case_id'].'/docs/'.$case['case_id'].'-runbook.md',
            $case['expected_changed_files'],
        );
        $this->assertSame(
            'atlas.forge.rivals.extreme_differentiator.v1',
            $payload['cases'][0]['extreme_differentiator']['schema_version'],
        );
        foreach ($payload['cases'] as $caseRow) {
            $this->assertSame('L5', $caseRow['difficulty_level']);
            $this->assertSame(5.0, (float) $caseRow['difficulty_score']);
            $this->assertSame('high', $caseRow['ambiguity_level']);
            $this->assertContains($caseRow['risk_level'], ['high', 'critical']);
            $this->assertStringContainsString('Ambiguidade percebida: high', (string) $caseRow['human_prompt']);
            $this->assertContains(
                $caseRow['difficulty_level'],
                $caseRow['extreme_differentiator']['required_signal']['allowed_difficulty_levels'],
            );
            $this->assertSame(
                'L5',
                $caseRow['context_profile']['complexity_profile']['difficulty_level'],
            );
            $this->assertSame(
                'high',
                $caseRow['human_prompt_probe']['ambiguity_level'],
            );
        }
        $this->assertContains('runner_strength_probe', $payload['cases'][0]['measurement_tags']);
        $this->assertTrue($payload['cases'][0]['extreme_differentiator']['required_signal']['requires_separation_analysis']);
        $this->assertTrue($payload['cases'][0]['extreme_differentiator']['required_signal']['tie_is_diagnostic_not_claim']);
    }

    public function test_ceiling_360_cases_push_all_required_capabilities_without_provider_call(): void
    {
        $payload = $this->runCases(['--case-set' => 'ceiling-360']);

        $this->assertSame('ok', $payload['status']);
        $this->assertSame(120, $payload['count']);
        $this->assertFalse($payload['external_provider_call']);
        $this->assertFalse($payload['provider_tokens_spent']);
        $required = [
            'long_context_retention',
            'multi_step_reasoning',
            'rollback_safety',
            'scope_boundary_discipline',
            'replayable_evidence_quality',
            'honest_blocker_behavior',
            'ambiguous_human_prompt_handling',
        ];

        foreach ($payload['cases'] as $caseRow) {
            $this->assertSame('ceiling-360', $caseRow['industrial_case_set']);
            $this->assertSame('L5', $caseRow['difficulty_level']);
            $this->assertSame('critical', $caseRow['risk_level']);
            $this->assertSame('high', $caseRow['ambiguity_level']);
            $this->assertGreaterThanOrEqual(0.70, (float) $caseRow['planning_weight']);
            $this->assertGreaterThanOrEqual(
                18000,
                (int) $caseRow['ceiling_360']['required_signal']['min_estimated_context_tokens'],
            );
            $this->assertTrue($caseRow['ceiling_360']['required_signal']['requires_all_360_capabilities']);
            $this->assertTrue($caseRow['ceiling_360']['required_signal']['requires_contradiction_resolution']);
            $this->assertTrue($caseRow['ceiling_360']['required_signal']['requires_hidden_oracle_hypotheses']);
            $this->assertTrue($caseRow['ceiling_360']['required_signal']['requires_failure_mode_matrix']);
            $this->assertTrue($caseRow['ceiling_360']['required_signal']['requires_stop_block_criteria']);
            $this->assertTrue($caseRow['ceiling_360']['required_signal']['requires_telemetry_delta']);
            $this->assertTrue($caseRow['ceiling_360']['required_signal']['requires_counterfactual_check']);
            $this->assertTrue($caseRow['ceiling_360']['required_signal']['requires_blast_radius_quantification']);
            $this->assertTrue($caseRow['ceiling_360']['required_signal']['requires_confidence_calibration']);
            $this->assertFalse($caseRow['ceiling_360']['claim_policy']['external_claim_allowed']);
            foreach ($required as $capability) {
                $this->assertContains($capability, $caseRow['measured_capabilities']);
            }
            $this->assertContains('ceiling_360', $caseRow['measurement_tags']);
            $this->assertStringContainsString('Pressao L5++', (string) $caseRow['human_prompt']);
            $this->assertStringContainsString('Failure Mode Matrix', (string) $caseRow['human_prompt']);
            $this->assertStringContainsString('Telemetry Delta', (string) $caseRow['human_prompt']);
            $this->assertStringContainsString('Counterfactual Check', (string) $caseRow['human_prompt']);
            $this->assertStringContainsString('Blast Radius', (string) $caseRow['human_prompt']);
            $this->assertStringContainsString('Confidence Calibration', (string) $caseRow['human_prompt']);
        }
    }

    public function test_industrial_suite_action_is_fail_closed_and_advisory_only(): void
    {
        $payload = $this->invoke('industrial-suite', []);

        $this->assertSame('ok', $payload['status']);
        $this->assertSame('atlas.forge.rivals.industrial_benchmark_suite.v1', $payload['schema_version']);
        $this->assertSame(50, $payload['presets']['industrial-50']['count']);
        $this->assertSame(100, $payload['presets']['industrial-100']['count']);
        $this->assertSame(200, $payload['presets']['industrial-200']['count']);
        $this->assertFalse($payload['external_provider_call']);
        $this->assertFalse($payload['provider_tokens_spent']);
        $this->assertFalse($payload['external_claim_allowed']);
        $this->assertFalse($payload['external_rivals_certification_unlocked']);
        $this->assertTrue($payload['advisory_only']);
        $this->assertSame('atlas_decide', $payload['owner_of_model_routing']);
        $this->assertSame('none', $payload['routing_effect']);
        $this->assertFalse($payload['claim_readiness']['ready_for_strong_benchmark_claim']);
    }

    public function test_run_arena_with_corpus_local_fake_emits_multi_case_dry_run_plan(): void
    {
        $payload = $this->runArena([
            '--arm-a' => 'atlas_forge',
            '--arm-a-model' => 'sonnet',
            '--arm-b' => 'claude_code',
            '--arm-b-model' => 'sonnet',
            '--mode' => 'local_fake',
            '--case-set' => 'quick',
        ]);

        $this->assertSame('ok', $payload['status']);
        $this->assertTrue((bool) $payload['corpus_dry_run']);
        $this->assertSame(3, $payload['count']);
        $this->assertFalse($payload['external_provider_call']);
        $this->assertFalse($payload['provider_tokens_spent']);
        $this->assertTrue($payload['safety_promises']['dry_run_never_invokes_provider']);
        $this->assertTrue($payload['separated_from_external_rivals_certification']);
        $this->assertNotNull($payload['replay_manifest']);
    }

    public function test_run_arena_industrial_50_dry_run_plans_without_provider_call(): void
    {
        $payload = $this->runArena([
            '--arm-a' => 'claude_code',
            '--arm-a-model' => 'sonnet',
            '--arm-b' => 'claude_code',
            '--arm-b-model' => 'opus',
            '--mode' => 'provider_arena',
            '--case-set' => 'industrial-50',
            '--dry-run' => true,
        ]);

        $this->assertSame('ok', $payload['status']);
        $this->assertSame('arena_plan_ready', $payload['verdict']);
        $this->assertSame(50, $payload['case_count']);
        $this->assertSame('industrial-50', $payload['case_set']);
        $this->assertFalse($payload['external_provider_call']);
        $this->assertFalse($payload['provider_tokens_spent']);
        $this->assertSame('industrial-001-ambiguous_bug', $payload['cases'][0]['case_id']);
    }

    public function test_run_arena_with_single_case_local_fake_resolves_manifest(): void
    {
        $payload = $this->runArena([
            '--arm-a' => 'atlas_forge',
            '--arm-a-model' => 'sonnet',
            '--arm-b' => 'claude_code',
            '--arm-b-model' => 'sonnet',
            '--mode' => 'local_fake',
            '--case' => ['frontend-execution-status-panel'],
        ]);

        $this->assertSame('ok', $payload['status']);
        $this->assertSame(1, $payload['count']);
        $this->assertSame('frontend-execution-status-panel', $payload['cases'][0]['case_id']);
    }

    public function test_run_arena_with_unknown_case_id_surfaces_blocker(): void
    {
        $payload = $this->runArena([
            '--arm-a' => 'atlas_forge',
            '--arm-a-model' => 'sonnet',
            '--arm-b' => 'claude_code',
            '--arm-b-model' => 'sonnet',
            '--mode' => 'local_fake',
            '--case' => ['arena-not-real'],
        ]);

        $this->assertSame('blocked', $payload['status']);
        $this->assertContains('unknown_case_id:arena-not-real', (array) $payload['blockers']);
        $this->assertFalse($payload['external_provider_call']);
    }

    public function test_run_arena_with_corpus_in_fair_mode_requires_provider_arena_v2_mode(): void
    {
        $payload = $this->runArena([
            '--arm-a' => 'atlas_forge',
            '--arm-a-model' => 'sonnet',
            '--arm-b' => 'claude_code',
            '--arm-b-model' => 'sonnet',
            '--mode' => 'fair',
            '--case-set' => 'quick',
            '--confirm-runbook-reviewed' => true,
            '--confirm-provider-cost' => true,
            '--confirm-real-provider-call' => true,
        ]);

        $this->assertSame('blocked', $payload['status']);
        $blockers = (array) $payload['blockers'];
        $this->assertContains('real_multi_case_requires_provider_arena_v2_mode:use_--mode=provider_arena_or_full_power_or_--dry-run', $blockers);
        $this->assertFalse($payload['external_provider_call']);
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    private function runCases(array $options = []): array
    {
        return $this->invoke('cases', $options);
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    private function runArena(array $options = []): array
    {
        return $this->invoke('run-arena', $options);
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    private function invoke(string $action, array $options): array
    {
        Artisan::call('atlas:forge:rivals', array_merge([
            'action' => $action,
            '--json' => true,
        ], $options));

        $payload = json_decode(Artisan::output(), true);
        $this->assertIsArray($payload, "{$action} --json must emit a JSON object");

        return $payload;
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming;

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

<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Atlas Forge Rivals · Provider Arena Corpus — end-to-end via artisan.
 *
 * No provider is ever invoked; every action runs in-process. Asserts the
 * `cases` action contract, the corpus filters, the run-arena corpus
 * dry-run path and the safety guarantees (no provider call, no external
 * rivals unlock).
 */
final class AtlasForgeRivalsProviderArenaCorpusTest extends TestCase
{
    public function test_cases_action_returns_corpus_with_at_least_twelve_entries(): void
    {
        $payload = $this->runCases();

        $this->assertSame('ok', $payload['status']);
        $this->assertSame('atlas.forge.rivals.provider_arena_corpus_cases.v1', $payload['cases_schema_version']);
        $this->assertSame('atlas.forge.rivals.provider_arena_corpus.v1', $payload['corpus_schema_version']);
        $this->assertGreaterThanOrEqual(12, $payload['snapshot']['count']);
        $this->assertFalse($payload['external_provider_call']);
        $this->assertTrue($payload['separated_from_external_rivals_certification']);
    }

    public function test_cases_action_default_filter_returns_quick_case_set(): void
    {
        $payload = $this->runCases();
        $this->assertContains('case_set=quick (default)', $payload['applied_filters']);
        $this->assertGreaterThanOrEqual(3, $payload['count']);
        $this->assertLessThanOrEqual(4, $payload['count']);
    }

    public function test_cases_action_filters_by_case_set_release(): void
    {
        $payload = $this->runCases(['--case-set' => 'release']);
        $this->assertSame('ok', $payload['status']);
        $this->assertSame($payload['snapshot']['count'], $payload['count']);
    }

    public function test_cases_action_filters_by_case_set_frontend(): void
    {
        $payload = $this->runCases(['--case-set' => 'frontend']);
        $this->assertSame('ok', $payload['status']);
        $this->assertNotEmpty($payload['cases']);
        foreach ($payload['cases'] as $case) {
            $this->assertSame('frontend', $case['task_category']);
        }
    }

    public function test_cases_action_filters_by_case_set_bugfix(): void
    {
        $payload = $this->runCases(['--case-set' => 'bugfix']);
        $this->assertSame('ok', $payload['status']);
        foreach ($payload['cases'] as $case) {
            $this->assertSame('bugfix', $case['task_category']);
        }
    }

    public function test_cases_action_filters_by_case_set_architecture_includes_three_categories(): void
    {
        $payload = $this->runCases(['--case-set' => 'architecture']);
        $cats = array_unique(array_map(static fn (array $c): string => (string) $c['task_category'], $payload['cases']));
        sort($cats);
        $this->assertSame(['architecture', 'docs', 'refactor'], $cats);
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
        $payload = $this->runCases(['--case' => ['arena-does-not-exist']]);
        $this->assertSame('blocked', $payload['status']);
        $this->assertContains('unknown_case_id:arena-does-not-exist', (array) $payload['blockers']);
        $this->assertFalse($payload['external_provider_call']);
    }

    public function test_cases_action_filter_by_specific_case_id_returns_single_manifest(): void
    {
        $payload = $this->runCases(['--case' => ['arena-bugfix-off-by-one-paginator']]);
        $this->assertSame('ok', $payload['status']);
        $this->assertSame(1, $payload['count']);
        $this->assertSame('arena-bugfix-off-by-one-paginator', $payload['cases'][0]['case_id']);
    }

    public function test_cases_action_emits_replay_manifest_for_every_result(): void
    {
        $payload = $this->runCases(['--case-set' => 'quick']);
        $this->assertArrayHasKey('replay_manifest', $payload);
        $this->assertSame('atlas.forge.rivals.provider_arena_corpus_replay.v1', $payload['replay_manifest']['schema_version']);
        $this->assertSame(['case_set=quick'], $payload['replay_manifest']['applied_filters']);
        $this->assertCount($payload['count'], $payload['replay_manifest']['case_ids']);
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

    public function test_run_arena_with_single_case_local_fake_resolves_manifest(): void
    {
        $payload = $this->runArena([
            '--arm-a' => 'atlas_forge',
            '--arm-a-model' => 'sonnet',
            '--arm-b' => 'claude_code',
            '--arm-b-model' => 'sonnet',
            '--mode' => 'local_fake',
            '--case' => ['arena-frontend-button-loading-state'],
        ]);

        $this->assertSame('ok', $payload['status']);
        $this->assertSame(1, $payload['count']);
        $this->assertSame('arena-frontend-button-loading-state', $payload['cases'][0]['case_id']);
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

    public function test_run_arena_with_corpus_in_real_mode_blocks_pending_implementation(): void
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
        $this->assertTrue(
            (bool) array_filter($blockers, static fn ($b): bool => str_starts_with((string) $b, 'real_multi_case_pending_implementation')),
            'fair mode + corpus deve bloquear honestamente em vez de fingir suporte; got: '.implode(', ', $blockers),
        );
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

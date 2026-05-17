<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming;

use App\Services\Ai\Kernel\Architecture\AtlasForgeRivalsProviderArenaCoreCertification;
use App\Services\Ai\Programming\ForgeRivals\Arms\AtlasForgeRivalsArmRegistryService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsActionDispatcher;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsArmCommandBuilderService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsModeRegistry;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsProviderModelRegistryService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Atlas Forge Rivals · Provider Arena Core v1 contract tests.
 *
 * Asserts the canonical arm registry, the run-arena dispatcher path,
 * task-category gating, scripted/manual safety, full_power gates and
 * the new cert read-model. Paid providers are never invoked; real-pipeline
 * tests use local stub binaries only.
 */
final class AtlasForgeRivalsProviderArenaCoreTest extends TestCase
{
    public function test_registry_lists_canonical_arms_including_atlas_dev(): void
    {
        $registry = app(AtlasForgeRivalsArmRegistryService::class);
        $arms = $registry->arms();

        $this->assertCount(8, $arms);
        $this->assertSame(
            ['atlas_forge', 'atlas_dev', 'claude_code', 'codex_cli', 'gemini_cli', 'scripted_runner', 'manual_runner', 'future_runner'],
            $arms,
        );
    }

    public function test_registry_snapshot_carries_safety_contract_for_every_arm(): void
    {
        $registry = app(AtlasForgeRivalsArmRegistryService::class);
        $snapshot = $registry->snapshot();

        $this->assertSame('atlas.forge.rivals.runner_registry.v1', $snapshot['schema_version']);
        $this->assertSame(8, $snapshot['arm_count']);
        $this->assertTrue($snapshot['separated_from_external_rivals_certification']);

        foreach ($snapshot['arms'] as $armId => $arm) {
            $this->assertSame($armId, $arm['arm_id']);
            $this->assertTrue($arm['safety_contract']['never_promotes_completion_claim']);
            $this->assertTrue($arm['safety_contract']['never_unlocks_external_rivals_certification']);
            $this->assertSame(0, $arm['safety_contract']['max_score_without_evidence']);
        }
    }

    public function test_atlas_dev_is_declared_as_lightweight_sonnet_arm(): void
    {
        $registry = app(AtlasForgeRivalsArmRegistryService::class);
        $arm = $registry->arm('atlas_dev');

        $this->assertSame('atlas_dev', $arm['arm_id']);
        $this->assertSame('atlas_dev', $arm['runner_type']);
        $this->assertSame('claude', $arm['provider']);
        $this->assertContains('sonnet', $arm['model_options']);
        $this->assertContains('claude_sonnet', $arm['model_options']);
        $this->assertSame('available', $arm['status']);
        $this->assertNull($arm['not_executable_reason']);
        $this->assertTrue($arm['safety_contract']['escalates_to_forge_on_high_risk']);
        $this->assertTrue($arm['safety_contract']['keeps_call_budget_low']);
    }

    public function test_atlas_dev_can_join_local_fake_corpus_dry_run_without_provider_spend(): void
    {
        $dispatcher = app(AtlasForgeRivalsActionDispatcher::class);
        $response = $dispatcher->dispatch('run-arena', [
            'arm_a' => 'atlas_dev',
            'arm_b' => 'claude_code',
            'arm_a_model' => 'sonnet',
            'arm_b_model' => 'sonnet',
            'case_set' => 'quick',
            'mode' => 'local_fake',
            'confirmations' => ['runbook_reviewed' => false, 'provider_cost' => false, 'real_provider_call' => false],
        ]);

        $this->assertSame('ok', $response['status']);
        $this->assertTrue($response['corpus_dry_run']);
        $this->assertSame('atlas_dev', $response['arm_a']['arm_id']);
        $this->assertSame('atlas_dev', $response['arm_a']['runner_type']);
        $this->assertFalse($response['external_provider_call']);
        $this->assertFalse($response['provider_tokens_spent']);
    }

    public function test_atlas_dev_real_run_requires_confirmations_before_provider_spend(): void
    {
        $dispatcher = app(AtlasForgeRivalsActionDispatcher::class);
        $response = $dispatcher->dispatch('run-arena', [
            'arm_a' => 'atlas_dev',
            'arm_b' => 'claude_code',
            'arm_a_model' => 'sonnet',
            'arm_b_model' => 'sonnet',
            'task_category' => 'bugfix',
            'mode' => 'provider_arena',
            'preset' => 'quick',
            'confirmations' => [
                'runbook_reviewed' => false,
                'provider_cost' => false,
                'real_provider_call' => false,
            ],
        ]);

        $this->assertSame('blocked', $response['status']);
        $this->assertContains('missing_confirmation:runbook_reviewed', $response['blockers']);
        $this->assertContains('missing_confirmation:provider_cost', $response['blockers']);
        $this->assertContains('missing_confirmation:real_provider_call', $response['blockers']);
        $this->assertFalse($response['external_provider_call']);
    }

    public function test_models_action_exposes_canonical_provider_model_registry(): void
    {
        $dispatcher = app(AtlasForgeRivalsActionDispatcher::class);
        $response = $dispatcher->dispatch('models', []);

        $this->assertSame('models', $response['action']);
        $this->assertSame('atlas.forge.rivals.provider_model_registry.v1', $response['schema_version']);
        $this->assertArrayHasKey('claude', $response['providers']);
        $this->assertArrayHasKey('codex', $response['providers']);
        $this->assertArrayHasKey('gpt-5.5', $response['providers']['codex']['models']);
        $this->assertTrue($response['advisory_only']);
        $this->assertFalse($response['should_update_provider_topology']);
        $this->assertSame('atlas_decide', $response['owner_of_model_routing']);
    }

    public function test_arena_readiness_reports_canonical_enterprise_pairs_without_provider_spend(): void
    {
        $dispatcher = app(AtlasForgeRivalsActionDispatcher::class);
        $response = $dispatcher->dispatch('arena-readiness', []);

        $this->assertSame('arena-readiness', $response['action']);
        $this->assertSame('atlas.forge.rivals.provider_arena_readiness.v1', $response['schema_version']);
        $this->assertSame(5, $response['pair_count']);
        $this->assertSame(5, $response['dry_run_ready_count']);
        $this->assertFalse($response['external_provider_call']);
        $this->assertFalse($response['provider_tokens_spent']);
        $this->assertTrue($response['advisory_only']);
        $this->assertFalse($response['should_update_provider_topology']);
        $this->assertSame('atlas_decide', $response['owner_of_model_routing']);
        $this->assertSame('none', $response['routing_effect']);

        $pairIds = array_column($response['pairs'], 'pair_id');
        $this->assertContains('atlas_dev_vs_atlas_forge', $pairIds);
        $this->assertContains('claude_opus_vs_codex_gpt55', $pairIds);
        $this->assertContains('codex_gpt55_vs_gemini_pro', $pairIds);
        $this->assertContains('claude_sonnet_vs_claude_opus', $pairIds);
        $this->assertContains('atlas_forge_full_power_vs_claude_opus', $pairIds);
    }

    public function test_arena_readiness_marks_stubbed_binaries_ready_after_confirmations(): void
    {
        $fakeProvider = $this->fakeProviderBinary();
        $oldClaudeBinary = config('atlas.ai.providers.claude_cli.binary');
        $oldCodexBinary = config('atlas.ai.providers.codex_cli.binary');
        $oldCodexArgs = config('atlas.ai.providers.codex_cli.args');
        $oldGeminiBinary = config('atlas.ai.providers.gemini_cli.binary');

        try {
            config([
                'atlas.ai.providers.claude_cli.binary' => $fakeProvider,
                'atlas.ai.providers.codex_cli.binary' => $fakeProvider,
                'atlas.ai.providers.codex_cli.args' => ['exec', '--skip-git-repo-check'],
                'atlas.ai.providers.gemini_cli.binary' => $fakeProvider,
            ]);

            $dispatcher = app(AtlasForgeRivalsActionDispatcher::class);
            $response = $dispatcher->dispatch('arena-readiness', []);

            $this->assertSame('ok', $response['status']);
            $this->assertSame(5, $response['real_run_ready_count']);
            $this->assertSame(0, $response['driver_missing_count']);
            foreach ($response['pairs'] as $pair) {
                $this->assertSame('real_run_ready_after_confirmations', $pair['status']);
                $this->assertTrue($pair['dry_run_ready']);
                $this->assertTrue($pair['real_run_ready']);
                $this->assertSame([], $pair['blockers']);
                $this->assertStringContainsString('--confirm-runbook-reviewed', $pair['next_command']);
                $this->assertStringContainsString('--confirm-provider-cost', $pair['next_command']);
                $this->assertStringContainsString('--confirm-real-provider-call', $pair['next_command']);
            }

            $codexVsGemini = collect($response['pairs'])->firstWhere('pair_id', 'codex_gpt55_vs_gemini_pro');
            $this->assertSame('gpt-5.5', data_get($codexVsGemini, 'arm_a.resolved_model_id'));
            $this->assertSame('gemini-3.1-pro-preview', data_get($codexVsGemini, 'arm_b.resolved_model_id'));
            $this->assertContains('gpt-5.5', data_get($codexVsGemini, 'command_plan.arm_a.command'));
            $this->assertContains('gemini-3.1-pro-preview', data_get($codexVsGemini, 'command_plan.arm_b.command'));
        } finally {
            config([
                'atlas.ai.providers.claude_cli.binary' => $oldClaudeBinary,
                'atlas.ai.providers.codex_cli.binary' => $oldCodexBinary,
                'atlas.ai.providers.codex_cli.args' => $oldCodexArgs,
                'atlas.ai.providers.gemini_cli.binary' => $oldGeminiBinary,
            ]);
        }
    }

    public function test_arena_readiness_blocks_missing_gemini_binary_honestly(): void
    {
        $fakeProvider = $this->fakeProviderBinary();
        $oldClaudeBinary = config('atlas.ai.providers.claude_cli.binary');
        $oldCodexBinary = config('atlas.ai.providers.codex_cli.binary');
        $oldCodexArgs = config('atlas.ai.providers.codex_cli.args');
        $oldGeminiBinary = config('atlas.ai.providers.gemini_cli.binary');

        try {
            config([
                'atlas.ai.providers.claude_cli.binary' => $fakeProvider,
                'atlas.ai.providers.codex_cli.binary' => $fakeProvider,
                'atlas.ai.providers.codex_cli.args' => ['exec', '--skip-git-repo-check'],
                'atlas.ai.providers.gemini_cli.binary' => '/tmp/atlas-rivals-gemini-missing',
            ]);

            $dispatcher = app(AtlasForgeRivalsActionDispatcher::class);
            $response = $dispatcher->dispatch('arena-readiness', []);

            $codexVsGemini = collect($response['pairs'])->firstWhere('pair_id', 'codex_gpt55_vs_gemini_pro');
            $this->assertSame('plan_ready_driver_missing', $codexVsGemini['status']);
            $this->assertTrue($codexVsGemini['dry_run_ready']);
            $this->assertFalse($codexVsGemini['real_run_ready']);
            $this->assertContains('provider_binary_not_available:arm_b:gemini', $codexVsGemini['blockers']);
            $this->assertSame(1, $response['driver_missing_count']);
        } finally {
            config([
                'atlas.ai.providers.claude_cli.binary' => $oldClaudeBinary,
                'atlas.ai.providers.codex_cli.binary' => $oldCodexBinary,
                'atlas.ai.providers.codex_cli.args' => $oldCodexArgs,
                'atlas.ai.providers.gemini_cli.binary' => $oldGeminiBinary,
            ]);
        }
    }

    public function test_provider_registry_is_single_source_for_binary_config_used_by_command_builder(): void
    {
        $oldBinary = config('atlas.ai.providers.codex_cli.binary');

        try {
            config(['atlas.ai.providers.codex_cli.binary' => '/tmp/atlas-rivals-custom-codex']);

            $registry = app(AtlasForgeRivalsProviderModelRegistryService::class);
            $binary = $registry->binaryForProvider('codex');

            $this->assertTrue($binary['ok']);
            $this->assertSame('atlas.ai.providers.codex_cli.binary', $binary['binary_config_key']);
            $this->assertSame('/tmp/atlas-rivals-custom-codex', $binary['binary']);

            $builder = app(AtlasForgeRivalsArmCommandBuilderService::class);
            $command = $builder->build([
                'arm' => ['arm_id' => 'codex_cli', 'provider' => 'codex'],
                'provider' => 'codex',
                'resolved_model' => 'gpt-5.5',
                'resolved_model_id' => 'gpt-5.5',
            ], 'prompt', '/tmp/worktree');

            $this->assertSame('/tmp/atlas-rivals-custom-codex', $command['command'][0]);
            $this->assertContains('-m', $command['command']);
            $this->assertContains('gpt-5.5', $command['command']);
        } finally {
            config(['atlas.ai.providers.codex_cli.binary' => $oldBinary]);
        }
    }

    public function test_provider_arena_modes_derive_allowed_models_from_provider_registry(): void
    {
        $registry = app(AtlasForgeRivalsProviderModelRegistryService::class);
        $modes = app(AtlasForgeRivalsModeRegistry::class);
        $canonicalModels = $registry->canonicalModels();

        $this->assertSame($canonicalModels, $modes->mode('provider_arena')['allowed_models']);
        $this->assertSame($canonicalModels, $modes->mode('provider_pure')['allowed_models']);
        $this->assertContains('gpt-5.5', $canonicalModels);
        $this->assertContains('gemini-pro', $canonicalModels);
    }

    public function test_codex_gpt_55_alias_resolves_for_codex_cli(): void
    {
        $dispatcher = app(AtlasForgeRivalsActionDispatcher::class);
        $response = $dispatcher->dispatch('run-arena', [
            'arm_a' => 'claude_code',
            'arm_b' => 'codex_cli',
            'arm_a_model' => 'opus',
            'arm_b_model' => 'gpt-5.5',
            'task_category' => 'bugfix',
            'mode' => 'provider_arena',
            'preset' => 'quick',
            'dry_run' => true,
            'confirmations' => ['runbook_reviewed' => false, 'provider_cost' => false, 'real_provider_call' => false],
        ]);

        $this->assertSame('ok', $response['status']);
        $this->assertTrue($response['dry_run']);
        $this->assertSame('claude_opus', $response['arm_a']['model']);
        $this->assertSame('gpt-5.5', $response['arm_b']['model']);
        $this->assertSame('gpt-5.5', $response['arm_b']['model_id']);
        $this->assertSame('none', $response['routing_effect']);
    }

    public function test_command_builder_passes_explicit_models_to_claude_codex_and_gemini(): void
    {
        $builder = app(AtlasForgeRivalsArmCommandBuilderService::class);

        $claude = $builder->build([
            'arm' => ['arm_id' => 'claude_code', 'provider' => 'claude'],
            'provider' => 'claude',
            'resolved_model' => 'claude_opus',
            'resolved_model_id' => 'claude-opus-4-7',
        ], 'prompt', '/tmp/worktree-a');
        $this->assertContains('--model', $claude['command']);
        $this->assertContains('claude-opus-4-7', $claude['command']);

        $codex = $builder->build([
            'arm' => ['arm_id' => 'codex_cli', 'provider' => 'codex'],
            'provider' => 'codex',
            'resolved_model' => 'gpt-5.5',
            'resolved_model_id' => 'gpt-5.5',
        ], 'prompt', '/tmp/worktree-b');
        $this->assertContains('-m', $codex['command']);
        $this->assertContains('gpt-5.5', $codex['command']);

        $gemini = $builder->build([
            'arm' => ['arm_id' => 'gemini_cli', 'provider' => 'gemini'],
            'provider' => 'gemini',
            'resolved_model' => 'gemini-pro',
            'resolved_model_id' => 'gemini-3.1-pro-preview',
        ], 'prompt', '/tmp/worktree-c');
        $this->assertContains('--model', $gemini['command']);
        $this->assertContains('gemini-3.1-pro-preview', $gemini['command']);
    }

    public function test_provider_arena_dry_run_allows_codex_vs_gemini_but_real_gemini_still_blocks(): void
    {
        config(['atlas.ai.providers.gemini_cli.binary' => '/tmp/atlas-rivals-gemini-missing']);

        $dispatcher = app(AtlasForgeRivalsActionDispatcher::class);
        $dry = $dispatcher->dispatch('run-arena', [
            'arm_a' => 'codex_cli',
            'arm_b' => 'gemini_cli',
            'arm_a_model' => 'gpt-5.5',
            'arm_b_model' => 'gemini-pro',
            'task_category' => 'architecture',
            'mode' => 'provider_arena',
            'dry_run' => true,
            'confirmations' => ['runbook_reviewed' => false, 'provider_cost' => false, 'real_provider_call' => false],
        ]);

        $this->assertSame('ok', $dry['status']);
        $this->assertSame('codex', $dry['arm_a']['provider']);
        $this->assertSame('gemini', $dry['arm_b']['provider']);

        $real = $dispatcher->dispatch('run-arena', [
            'arm_a' => 'codex_cli',
            'arm_b' => 'gemini_cli',
            'arm_a_model' => 'gpt-5.5',
            'arm_b_model' => 'gemini-pro',
            'task_category' => 'architecture',
            'mode' => 'provider_arena',
            'confirmations' => ['runbook_reviewed' => true, 'provider_cost' => true, 'real_provider_call' => true],
        ]);

        $this->assertSame('blocked', $real['status']);
        $this->assertContains('arena_provider_driver_not_configured:arm_b:gemini', $real['blockers']);
    }

    public function test_provider_arena_blocks_claude_when_programmatic_rivals_policy_disallows_it(): void
    {
        $fakeProvider = $this->fakeProviderBinary();
        $oldClaudeBinary = config('atlas.ai.providers.claude_cli.binary');
        $oldCodexBinary = config('atlas.ai.providers.codex_cli.binary');
        $oldCodexArgs = config('atlas.ai.providers.codex_cli.args');
        $oldPolicy = config('atlas_code_provider_governance.claude_programmatic_policy');

        try {
            config([
                'atlas.ai.providers.claude_cli.binary' => $fakeProvider,
                'atlas.ai.providers.codex_cli.binary' => $fakeProvider,
                'atlas.ai.providers.codex_cli.args' => ['exec', '--skip-git-repo-check'],
                'atlas_code_provider_governance.claude_programmatic_policy' => 'interactive_only',
            ]);

            $dispatcher = app(AtlasForgeRivalsActionDispatcher::class);
            $response = $dispatcher->dispatch('run-arena', [
                'arm_a' => 'claude_code',
                'arm_b' => 'codex_cli',
                'arm_a_model' => 'sonnet',
                'arm_b_model' => 'gpt-5.5',
                'task_category' => 'bugfix',
                'mode' => 'provider_arena',
                'preset' => 'quick',
                'confirmations' => [
                    'runbook_reviewed' => true,
                    'provider_cost' => true,
                    'real_provider_call' => true,
                ],
            ]);

            $this->assertSame('blocked', $response['status']);
            $this->assertContains('arena_provider_policy_blocked:arm_a:claude:policy_interactive_only', $response['blockers']);
            $this->assertFalse($response['external_provider_call']);
            $this->assertFalse($response['provider_tokens_spent']);
        } finally {
            config([
                'atlas.ai.providers.claude_cli.binary' => $oldClaudeBinary,
                'atlas.ai.providers.codex_cli.binary' => $oldCodexBinary,
                'atlas.ai.providers.codex_cli.args' => $oldCodexArgs,
                'atlas_code_provider_governance.claude_programmatic_policy' => $oldPolicy,
            ]);
        }
    }

    public function test_run_arena_multi_case_provider_arena_dry_run_does_not_fall_to_legacy(): void
    {
        $dispatcher = app(AtlasForgeRivalsActionDispatcher::class);
        $response = $dispatcher->dispatch('run-arena', [
            'arm_a' => 'claude_code',
            'arm_b' => 'codex_cli',
            'arm_a_model' => 'opus',
            'arm_b_model' => 'gpt-5.5',
            'case_set' => 'quick',
            'mode' => 'provider_arena',
            'dry_run' => true,
            'confirmations' => ['runbook_reviewed' => false, 'provider_cost' => false, 'real_provider_call' => false],
        ]);

        $this->assertSame('ok', $response['status']);
        $this->assertSame('arena_plan_ready', $response['verdict']);
        $this->assertArrayHasKey('command_plan', $response);
        $this->assertSame('gpt-5.5', $response['arm_b']['model']);
        $this->assertFalse($response['external_provider_call']);
    }

    public function test_provider_arena_real_pipeline_runs_cross_provider_with_stubbed_binaries_and_replay(): void
    {
        $fakeProvider = $this->fakeProviderBinary();

        $oldClaudeBinary = config('atlas.ai.providers.claude_cli.binary');
        $oldCodexBinary = config('atlas.ai.providers.codex_cli.binary');
        $oldCodexArgs = config('atlas.ai.providers.codex_cli.args');

        try {
            config([
                'atlas.ai.providers.claude_cli.binary' => $fakeProvider,
                'atlas.ai.providers.codex_cli.binary' => $fakeProvider,
                'atlas.ai.providers.codex_cli.args' => ['exec', '--skip-git-repo-check'],
            ]);

            $dispatcher = app(AtlasForgeRivalsActionDispatcher::class);
            $response = $dispatcher->dispatch('run-arena', [
                'arm_a' => 'claude_code',
                'arm_b' => 'codex_cli',
                'arm_a_model' => 'opus',
                'arm_b_model' => 'gpt-5.5',
                'task_category' => 'bugfix',
                'mode' => 'provider_arena',
                'preset' => 'quick',
                'run_id' => 'arena-v2-stub-'.Str::lower(Str::random(8)),
                'confirmations' => [
                    'runbook_reviewed' => true,
                    'provider_cost' => true,
                    'real_provider_call' => true,
                ],
            ]);

            $this->assertSame('ok', $response['status'], json_encode($response, JSON_PRETTY_PRINT));
            $this->assertSame('provider_arena_v2', $response['executor']);
            $this->assertSame('claude_code', $response['arm_a']['arm_id']);
            $this->assertSame('codex_cli', $response['arm_b']['arm_id']);
            $this->assertSame('claude-opus-4-7', $response['arm_a']['model_id']);
            $this->assertSame('gpt-5.5', $response['arm_b']['model_id']);
            $this->assertTrue($response['external_provider_call']);
            $this->assertTrue($response['provider_tokens_spent']);
            $this->assertTrue($response['replay_passes'] ?? false);
            $this->assertSame('atlas_decide', $response['owner_of_model_routing']);
            $this->assertSame('none', $response['routing_effect']);
            $this->assertFalse($response['should_update_provider_topology']);
            $this->assertSame('claude_code', data_get($response, 'manifest.arena_contracts.arm_a.arm_id'));
            $this->assertSame('codex_cli', data_get($response, 'manifest.arena_contracts.arm_b.arm_id'));
            $report = app(\App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsReportService::class)->render([
                'run_id' => $response['run_id'],
            ]);
            $this->assertSame('claude_code', $report['arms'][0]['id']);
            $this->assertSame('codex_cli', $report['arms'][1]['id']);
            $this->assertSame('claude', $report['arms'][0]['provider']);
            $this->assertSame('codex', $report['arms'][1]['provider']);
            $this->assertSame('claude-opus-4-7', $report['arms'][0]['model_id']);
            $this->assertSame('gpt-5.5', $report['arms'][1]['model_id']);
            $this->assertSame('claude_code:claude:claude_opus:claude-opus-4-7_vs_codex_cli:codex:gpt-5.5:gpt-5.5', $report['case_results'][0]['provider_pair']);
            $this->assertSame('claude_code', data_get($report, 'provider_performance_signal.rows.0.arm_a.id'));
            $this->assertSame('codex_cli', data_get($report, 'provider_performance_signal.rows.0.arm_b.id'));
            $this->assertStringContainsString('## Arms Medidos', (string) file_get_contents((string) $report['report_path']));

            $eventsPath = collect((array) ($response['evidence_paths'] ?? []))
                ->first(static fn (mixed $path): bool => is_string($path) && str_ends_with($path, 'events.jsonl'));
            $this->assertIsString($eventsPath);
            $events = file_get_contents($eventsPath);
            $this->assertIsString($events);
            $this->assertStringContainsString('"kind":"provider_started"', $events);
            $this->assertStringContainsString('"arm_id":"claude_code"', $events);
            $this->assertStringContainsString('"arm_id":"codex_cli"', $events);
        } finally {
            config([
                'atlas.ai.providers.claude_cli.binary' => $oldClaudeBinary,
                'atlas.ai.providers.codex_cli.binary' => $oldCodexBinary,
                'atlas.ai.providers.codex_cli.args' => $oldCodexArgs,
            ]);
        }
    }

    public function test_provider_arena_real_pipeline_runs_atlas_dev_vs_atlas_forge_with_stubbed_claude(): void
    {
        $fakeProvider = $this->fakeProviderBinary();
        $oldClaudeBinary = config('atlas.ai.providers.claude_cli.binary');

        try {
            config(['atlas.ai.providers.claude_cli.binary' => $fakeProvider]);

            $dispatcher = app(AtlasForgeRivalsActionDispatcher::class);
            $response = $dispatcher->dispatch('run-arena', [
                'arm_a' => 'atlas_dev',
                'arm_b' => 'atlas_forge',
                'arm_a_model' => 'sonnet',
                'arm_b_model' => 'sonnet',
                'task_category' => 'bugfix',
                'mode' => 'provider_arena',
                'preset' => 'quick',
                'run_id' => 'arena-dev-forge-stub-'.Str::lower(Str::random(8)),
                'confirmations' => [
                    'runbook_reviewed' => true,
                    'provider_cost' => true,
                    'real_provider_call' => true,
                ],
            ]);

            $this->assertSame('ok', $response['status'], json_encode($response, JSON_PRETTY_PRINT));
            $this->assertSame('provider_arena_v2', $response['executor']);
            $this->assertSame('atlas_dev', $response['arm_a']['arm_id']);
            $this->assertSame('atlas_forge', $response['arm_b']['arm_id']);
            $this->assertTrue($response['replay_passes'] ?? false);
            $this->assertSame('atlas_dev', data_get($response, 'manifest.arena_contracts.arm_a.arm_id'));
            $this->assertSame('atlas_forge', data_get($response, 'manifest.arena_contracts.arm_b.arm_id'));
        } finally {
            config(['atlas.ai.providers.claude_cli.binary' => $oldClaudeBinary]);
        }
    }

    public function test_full_power_real_pipeline_uses_provider_arena_v2_for_atlas_system_vs_provider_pure(): void
    {
        $fakeProvider = $this->fakeProviderBinary();
        $oldClaudeBinary = config('atlas.ai.providers.claude_cli.binary');

        try {
            config(['atlas.ai.providers.claude_cli.binary' => $fakeProvider]);

            $dispatcher = app(AtlasForgeRivalsActionDispatcher::class);
            $response = $dispatcher->dispatch('run-arena', [
                'arm_a' => 'atlas_forge',
                'arm_b' => 'claude_code',
                'arm_a_model' => 'opus',
                'arm_b_model' => 'opus',
                'task_category' => 'architecture',
                'mode' => 'full_power',
                'preset' => 'quick',
                'run_id' => 'arena-full-power-stub-'.Str::lower(Str::random(8)),
                'confirmations' => [
                    'runbook_reviewed' => true,
                    'provider_cost' => true,
                    'real_provider_call' => true,
                ],
            ]);

            $this->assertSame('ok', $response['status'], json_encode($response, JSON_PRETTY_PRINT));
            $this->assertSame('full_power', $response['mode']);
            $this->assertSame('provider_arena_v2', $response['executor']);
            $this->assertSame('atlas_forge', $response['arm_a']['arm_id']);
            $this->assertSame('claude_code', $response['arm_b']['arm_id']);
            $this->assertTrue($response['replay_passes'] ?? false);
            $this->assertFalse($response['should_update_provider_topology']);
            $this->assertSame('atlas_decide', $response['owner_of_model_routing']);
            $this->assertSame('none', $response['routing_effect']);
        } finally {
            config(['atlas.ai.providers.claude_cli.binary' => $oldClaudeBinary]);
        }
    }

    public function test_provider_arena_real_pipeline_runs_multi_case_quick_corpus_with_stubbed_binaries(): void
    {
        $fakeProvider = $this->fakeProviderBinary();

        $oldClaudeBinary = config('atlas.ai.providers.claude_cli.binary');
        $oldCodexBinary = config('atlas.ai.providers.codex_cli.binary');
        $oldCodexArgs = config('atlas.ai.providers.codex_cli.args');

        try {
            config([
                'atlas.ai.providers.claude_cli.binary' => $fakeProvider,
                'atlas.ai.providers.codex_cli.binary' => $fakeProvider,
                'atlas.ai.providers.codex_cli.args' => ['exec', '--skip-git-repo-check'],
            ]);

            $dispatcher = app(AtlasForgeRivalsActionDispatcher::class);
            $response = $dispatcher->dispatch('run-arena', [
                'arm_a' => 'claude_code',
                'arm_b' => 'codex_cli',
                'arm_a_model' => 'opus',
                'arm_b_model' => 'gpt-5.5',
                'case_set' => 'quick',
                'mode' => 'provider_arena',
                'run_id' => 'arena-multicase-stub-'.Str::lower(Str::random(8)),
                'confirmations' => [
                    'runbook_reviewed' => true,
                    'provider_cost' => true,
                    'real_provider_call' => true,
                ],
            ]);

            $this->assertSame('ok', $response['status'], json_encode($response, JSON_PRETTY_PRINT));
            $this->assertSame('provider_arena_v2', $response['executor']);
            $this->assertTrue($response['using_corpus']);
            $this->assertSame('quick', $response['case_set']);
            $this->assertTrue($response['replay_passes'] ?? false);
            $this->assertSame('claude_code', data_get($response, 'manifest.arena_contracts.arm_a.arm_id'));
            $this->assertSame('codex_cli', data_get($response, 'manifest.arena_contracts.arm_b.arm_id'));
            $this->assertIsString($response['report_path'] ?? null);
        } finally {
            config([
                'atlas.ai.providers.claude_cli.binary' => $oldClaudeBinary,
                'atlas.ai.providers.codex_cli.binary' => $oldCodexBinary,
                'atlas.ai.providers.codex_cli.args' => $oldCodexArgs,
            ]);
        }
    }

    public function test_provider_arena_real_pipeline_runs_codex_vs_gemini_when_driver_binaries_are_configured(): void
    {
        $fakeProvider = $this->fakeProviderBinary();
        $oldCodexBinary = config('atlas.ai.providers.codex_cli.binary');
        $oldCodexArgs = config('atlas.ai.providers.codex_cli.args');
        $oldGeminiBinary = config('atlas.ai.providers.gemini_cli.binary');

        try {
            config([
                'atlas.ai.providers.codex_cli.binary' => $fakeProvider,
                'atlas.ai.providers.codex_cli.args' => ['exec', '--skip-git-repo-check'],
                'atlas.ai.providers.gemini_cli.binary' => $fakeProvider,
            ]);

            $dispatcher = app(AtlasForgeRivalsActionDispatcher::class);
            $response = $dispatcher->dispatch('run-arena', [
                'arm_a' => 'codex_cli',
                'arm_b' => 'gemini_cli',
                'arm_a_model' => 'gpt-5.5',
                'arm_b_model' => 'gemini-pro',
                'task_category' => 'bugfix',
                'mode' => 'provider_arena',
                'preset' => 'quick',
                'run_id' => 'arena-codex-gemini-stub-'.Str::lower(Str::random(8)),
                'confirmations' => [
                    'runbook_reviewed' => true,
                    'provider_cost' => true,
                    'real_provider_call' => true,
                ],
            ]);

            $this->assertSame('ok', $response['status'], json_encode($response, JSON_PRETTY_PRINT));
            $this->assertSame('codex_cli', $response['arm_a']['arm_id']);
            $this->assertSame('gemini_cli', $response['arm_b']['arm_id']);
            $this->assertSame('gpt-5.5', $response['arm_a']['model_id']);
            $this->assertSame('gemini-3.1-pro-preview', $response['arm_b']['model_id']);
            $this->assertTrue($response['replay_passes'] ?? false);
            $this->assertSame('none', $response['routing_effect']);
        } finally {
            config([
                'atlas.ai.providers.codex_cli.binary' => $oldCodexBinary,
                'atlas.ai.providers.codex_cli.args' => $oldCodexArgs,
                'atlas.ai.providers.gemini_cli.binary' => $oldGeminiBinary,
            ]);
        }
    }

    public function test_atlas_forge_vs_claude_code_local_fake_does_not_call_provider(): void
    {
        $dispatcher = app(AtlasForgeRivalsActionDispatcher::class);
        $response = $dispatcher->dispatch('run-arena', [
            'arm_a' => 'atlas_forge',
            'arm_b' => 'claude_code',
            'arm_a_model' => 'sonnet',
            'arm_b_model' => 'sonnet',
            'task_category' => 'bugfix',
            'mode' => 'local_fake',
            'preset' => 'quick',
            'confirmations' => ['runbook_reviewed' => false, 'provider_cost' => false, 'real_provider_call' => false],
        ]);

        $this->assertSame('run-arena', $response['action']);
        // The arena hands off to RunBatteryService which executes the full
        // local_fake pipeline; the contract is "no provider call, no tokens".
        $this->assertFalse($response['external_provider_call']);
        $this->assertFalse($response['provider_tokens_spent']);
        $this->assertTrue($response['separated_from_external_rivals_certification']);
        $this->assertSame('atlas_forge', $response['arm_a']['arm_id']);
        $this->assertSame('claude_code', $response['arm_b']['arm_id']);
        $this->assertSame('bugfix', $response['task_category']);
    }

    public function test_claude_code_vs_codex_cli_blocks_when_codex_binary_missing(): void
    {
        if ($this->whichBinary('codex') !== '') {
            $this->markTestSkipped('codex CLI is installed; cannot exercise the honest blocker path.');
        }

        $dispatcher = app(AtlasForgeRivalsActionDispatcher::class);
        $response = $dispatcher->dispatch('run-arena', [
            'arm_a' => 'claude_code',
            'arm_b' => 'codex_cli',
            'arm_a_model' => 'sonnet',
            'arm_b_model' => 'codex',
            'task_category' => 'bugfix',
            'mode' => 'full_power',
            'preset' => 'release',
            'confirmations' => [
                'runbook_reviewed' => true,
                'provider_cost' => true,
                'real_provider_call' => true,
            ],
        ]);

        $this->assertSame('blocked', $response['status']);
        $this->assertContains('rival_driver_not_configured:codex', $response['blockers']);
        $this->assertFalse($response['external_provider_call']);
    }

    public function test_codex_cli_arm_declared_available_in_registry_when_driver_present(): void
    {
        if ($this->whichBinary('codex') === '') {
            $this->markTestSkipped('codex CLI not installed; declared-but-blocked path is exercised elsewhere.');
        }

        $registry = app(AtlasForgeRivalsArmRegistryService::class);
        $this->assertSame('available', $registry->arm('codex_cli')['status']);

        // Without confirmations the run still blocks at the confirmations gate.
        $dispatcher = app(AtlasForgeRivalsActionDispatcher::class);
        $response = $dispatcher->dispatch('run-arena', [
            'arm_a' => 'claude_code',
            'arm_b' => 'codex_cli',
            'arm_a_model' => 'sonnet',
            'arm_b_model' => 'codex',
            'task_category' => 'bugfix',
            'mode' => 'full_power',
            'preset' => 'release',
            'confirmations' => ['runbook_reviewed' => false, 'provider_cost' => false, 'real_provider_call' => false],
        ]);
        $this->assertSame('blocked', $response['status']);
        foreach (['missing_confirmation:runbook_reviewed', 'missing_confirmation:provider_cost', 'missing_confirmation:real_provider_call'] as $b) {
            $this->assertContains($b, $response['blockers']);
        }
        $this->assertFalse($response['external_provider_call']);
    }

    public function test_invalid_task_category_blocks_with_honest_code(): void
    {
        $dispatcher = app(AtlasForgeRivalsActionDispatcher::class);
        $response = $dispatcher->dispatch('run-arena', [
            'arm_a' => 'atlas_forge',
            'arm_b' => 'claude_code',
            'arm_a_model' => 'sonnet',
            'arm_b_model' => 'sonnet',
            'task_category' => 'lunchtime',
            'mode' => 'local_fake',
            'preset' => 'quick',
            'confirmations' => ['runbook_reviewed' => false, 'provider_cost' => false, 'real_provider_call' => false],
        ]);

        $this->assertSame('blocked', $response['status']);
        $this->assertContains('task_category_unknown:lunchtime', $response['blockers']);
    }

    public function test_run_battery_legacy_command_remains_compatible(): void
    {
        // Legacy run-battery path must keep working unchanged.
        $dispatcher = app(AtlasForgeRivalsActionDispatcher::class);
        $response = $dispatcher->dispatch('run-battery', [
            'mode' => 'fair',
            'atlas_model' => 'sonnet',
            'rival' => 'claude_sonnet',
            'preset' => 'quick',
            'confirmations' => ['runbook_reviewed' => false, 'provider_cost' => false, 'real_provider_call' => false],
        ]);

        $this->assertSame('run-battery', $response['action']);
        $this->assertSame('blocked', $response['status']);
        $this->assertContains('missing_confirmation:real_provider_call', $response['blockers']);
        $this->assertFalse($response['external_provider_call']);
    }

    public function test_arena_never_unlocks_external_rivals_certification(): void
    {
        $dispatcher = app(AtlasForgeRivalsActionDispatcher::class);
        $response = $dispatcher->dispatch('run-arena', [
            'arm_a' => 'atlas_forge',
            'arm_b' => 'claude_code',
            'arm_a_model' => 'sonnet',
            'arm_b_model' => 'sonnet',
            'task_category' => 'tests',
            'mode' => 'local_fake',
            'preset' => 'quick',
            'confirmations' => ['runbook_reviewed' => false, 'provider_cost' => false, 'real_provider_call' => false],
        ]);

        $this->assertTrue($response['separated_from_external_rivals_certification']);
        // The legacy operator battery cert must keep declaring external_rivals separation.
        $cert = (new \App\Services\Ai\Kernel\Architecture\AtlasForgeRivalsOperatorBatteryCertification)->evaluate();
        $this->assertSame('external_rivals_certification', $cert['separated_from']);
    }

    public function test_real_provider_arms_require_three_confirmations(): void
    {
        $dispatcher = app(AtlasForgeRivalsActionDispatcher::class);
        $response = $dispatcher->dispatch('run-arena', [
            'arm_a' => 'atlas_forge',
            'arm_b' => 'claude_code',
            'arm_a_model' => 'sonnet',
            'arm_b_model' => 'sonnet',
            'task_category' => 'bugfix',
            'mode' => 'fair',
            'preset' => 'quick',
            'confirmations' => ['runbook_reviewed' => true, 'provider_cost' => true, 'real_provider_call' => false],
        ]);

        $this->assertSame('blocked', $response['status']);
        $this->assertContains('missing_confirmation:real_provider_call', $response['blockers']);
    }

    public function test_fair_mode_refuses_cross_provider_arena(): void
    {
        $dispatcher = app(AtlasForgeRivalsActionDispatcher::class);
        $response = $dispatcher->dispatch('run-arena', [
            'arm_a' => 'claude_code',
            'arm_b' => 'codex_cli',
            'arm_a_model' => 'sonnet',
            'arm_b_model' => 'codex',
            'task_category' => 'bugfix',
            'mode' => 'fair',
            'preset' => 'quick',
            'confirmations' => ['runbook_reviewed' => true, 'provider_cost' => true, 'real_provider_call' => true],
        ]);

        $this->assertSame('blocked', $response['status']);
        $this->assertContains('fair_mode_requires_same_provider_on_both_arms:claude_vs_codex', $response['blockers']);
    }

    public function test_full_power_mode_does_not_reduce_gates(): void
    {
        $dispatcher = app(AtlasForgeRivalsActionDispatcher::class);
        $response = $dispatcher->dispatch('run-arena', [
            'arm_a' => 'atlas_forge',
            'arm_b' => 'claude_code',
            'arm_a_model' => 'opus',
            'arm_b_model' => 'opus',
            'task_category' => 'architecture',
            'mode' => 'full_power',
            'preset' => 'release',
            'confirmations' => ['runbook_reviewed' => false, 'provider_cost' => false, 'real_provider_call' => false],
        ]);

        $this->assertSame('blocked', $response['status']);
        foreach (['missing_confirmation:runbook_reviewed', 'missing_confirmation:provider_cost', 'missing_confirmation:real_provider_call'] as $b) {
            $this->assertContains($b, $response['blockers']);
        }
    }

    public function test_scripted_runner_blocks_honestly_outside_local_fake(): void
    {
        $dispatcher = app(AtlasForgeRivalsActionDispatcher::class);
        $response = $dispatcher->dispatch('run-arena', [
            'arm_a' => 'atlas_forge',
            'arm_b' => 'scripted_runner',
            'arm_a_model' => 'sonnet',
            'arm_b_model' => '',
            'task_category' => 'tests',
            'mode' => 'fair',
            'preset' => 'quick',
            'confirmations' => ['runbook_reviewed' => true, 'provider_cost' => true, 'real_provider_call' => true],
        ]);

        $this->assertSame('blocked', $response['status']);
        $this->assertContains('arm_runner_not_yet_executable:scripted_runner', $response['blockers']);
    }

    public function test_manual_runner_blocks_honestly_outside_local_fake(): void
    {
        $dispatcher = app(AtlasForgeRivalsActionDispatcher::class);
        $response = $dispatcher->dispatch('run-arena', [
            'arm_a' => 'manual_runner',
            'arm_b' => 'claude_code',
            'arm_a_model' => '',
            'arm_b_model' => 'sonnet',
            'task_category' => 'bugfix',
            'mode' => 'full_power',
            'preset' => 'quick',
            'confirmations' => ['runbook_reviewed' => true, 'provider_cost' => true, 'real_provider_call' => true],
        ]);

        $this->assertSame('blocked', $response['status']);
        $this->assertContains('arm_runner_not_yet_executable:manual_runner', $response['blockers']);
    }

    public function test_future_runner_blocks_in_every_mode(): void
    {
        $dispatcher = app(AtlasForgeRivalsActionDispatcher::class);
        $response = $dispatcher->dispatch('run-arena', [
            'arm_a' => 'future_runner',
            'arm_b' => 'claude_code',
            'arm_a_model' => '',
            'arm_b_model' => 'sonnet',
            'task_category' => 'bugfix',
            'mode' => 'local_fake',
            'preset' => 'quick',
            'confirmations' => ['runbook_reviewed' => false, 'provider_cost' => false, 'real_provider_call' => false],
        ]);

        $this->assertSame('blocked', $response['status']);
        $this->assertContains('future_runner_is_placeholder_only', $response['blockers']);
    }

    public function test_arms_action_exposes_registry_snapshot(): void
    {
        $dispatcher = app(AtlasForgeRivalsActionDispatcher::class);
        $response = $dispatcher->dispatch('arms', []);

        $this->assertSame('arms', $response['action']);
        $this->assertSame('atlas.forge.rivals.runner_registry.v1', $response['schema_version']);
        $this->assertSame(8, $response['arm_count']);
        $this->assertCount(9, $response['task_categories']);
    }

    public function test_arena_core_certification_reaches_available(): void
    {
        $cert = (new AtlasForgeRivalsProviderArenaCoreCertification)->evaluate();

        $this->assertSame(
            AtlasForgeRivalsProviderArenaCoreCertification::STATUS_AVAILABLE,
            $cert['status'],
            'Provider Arena Core cert must be available once arena layer is wired.'
        );
        $this->assertCount(18, $cert['invariants']);
        foreach ([
            'provider_model_registry_available',
            'models_action_exposes_registry',
            'arm_command_builder_centralized',
            'provider_arena_modes_declared',
            'provider_arena_real_executor_wired',
            'arena_contracts_flow_to_manifest_report_signal',
        ] as $v2Invariant) {
            $this->assertArrayHasKey($v2Invariant, $cert['invariants']);
        }
        foreach ($cert['invariants'] as $name => $row) {
            $this->assertTrue(
                (bool) $row['ok'],
                "Provider Arena Core invariant '{$name}' must be ok=true. status='".(string) $row['status']."'"
            );
        }
        $this->assertSame('external_rivals_certification', $cert['separated_from']);
    }

    public function test_audit_action_exposes_three_certifications(): void
    {
        Artisan::call('atlas:forge:rivals', [
            'action' => 'audit',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true);
        $this->assertIsArray($payload);
        $this->assertArrayHasKey('certification', $payload);
        $this->assertArrayHasKey('perfect_battery_certification', $payload);
        $this->assertArrayHasKey('provider_arena_core_certification', $payload);
        $this->assertSame(
            'atlas.forge_rivals_provider_arena_core_certification.v1',
            $payload['provider_arena_core_certification']['schema_version']
        );
        $this->assertArrayHasKey(
            'atlas_forge_rivals_provider_arena_core_certification',
            $payload['certifications']
        );
    }

    public function test_run_arena_artisan_invocation_emits_canonical_envelope(): void
    {
        Artisan::call('atlas:forge:rivals', [
            'action' => 'run-arena',
            '--arm-a' => 'atlas_forge',
            '--arm-b' => 'claude_code',
            '--arm-a-model' => 'sonnet',
            '--arm-b-model' => 'sonnet',
            '--task-category' => 'bugfix',
            '--mode' => 'local_fake',
            '--preset' => 'quick',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true);
        $this->assertIsArray($payload);
        $this->assertSame('run-arena', $payload['action']);
        $this->assertSame('atlas.forge.rivals.action_response.v1', $payload['schema_version']);
        $this->assertSame('atlas_forge', $payload['arm_a']['arm_id']);
        $this->assertSame('claude_code', $payload['arm_b']['arm_id']);
        $this->assertSame('bugfix', $payload['task_category']);
        $this->assertFalse($payload['external_provider_call']);
    }

    private function whichBinary(string $binary): string
    {
        $proc = \Symfony\Component\Process\Process::fromShellCommandline('which '.escapeshellarg($binary));
        $proc->setTimeout(5);
        $proc->run();
        if (! $proc->isSuccessful()) {
            return '';
        }

        return trim((string) $proc->getOutput());
    }

    private function fakeProviderBinary(): string
    {
        $binDir = sys_get_temp_dir().'/atlas-rivals-arena-bin-'.Str::lower(Str::random(8));
        @mkdir($binDir, 0o755, true);
        $fakeProvider = $binDir.'/fake-provider';
        file_put_contents($fakeProvider, <<<'BASH'
#!/usr/bin/env bash
set -euo pipefail
mkdir -p tests/Feature/Ai/Programming
cat > tests/Feature/Ai/Programming/ProviderArenaStubEvidenceTest.php <<'PHP'
<?php

declare(strict_types=1);

test('provider arena stub evidence exists', function (): void {
    expect(true)->toBeTrue();
});
PHP
printf '{"type":"result","subtype":"success","is_error":false,"result":"stub provider completed"}\n'
BASH);
        chmod($fakeProvider, 0o755);

        return $fakeProvider;
    }
}

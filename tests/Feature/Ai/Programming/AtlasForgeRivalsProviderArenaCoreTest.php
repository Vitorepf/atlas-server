<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming;

use App\Services\Ai\Kernel\Architecture\AtlasForgeRivalsOperatorBatteryCertification;
use App\Services\Ai\Kernel\Architecture\AtlasForgeRivalsProviderArenaCoreCertification;
use App\Services\Ai\Programming\ForgeRivals\Arms\AtlasForgeRivalsArmRegistryService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsActionDispatcher;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsArmCommandBuilderService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsModeRegistry;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsProviderModelRegistryService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsReportService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsSetupService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
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
    public function test_registry_lists_canonical_arms_including_cursor_and_composer(): void
    {
        $registry = app(AtlasForgeRivalsArmRegistryService::class);
        $arms = $registry->arms();

        $this->assertCount(10, $arms);
        $this->assertSame(
            ['atlas_forge', 'atlas_dev', 'claude_code', 'codex_cli', 'gemini_cli', 'cursor_cli', 'composer_2_5', 'scripted_runner', 'manual_runner', 'future_runner'],
            $arms,
        );
    }

    public function test_registry_snapshot_carries_safety_contract_for_every_arm(): void
    {
        $registry = app(AtlasForgeRivalsArmRegistryService::class);
        $snapshot = $registry->snapshot();

        $this->assertSame('atlas.forge.rivals.runner_registry.v1', $snapshot['schema_version']);
        $this->assertSame(10, $snapshot['arm_count']);
        $this->assertTrue($snapshot['separated_from_external_rivals_certification']);

        foreach ($snapshot['arms'] as $armId => $arm) {
            $this->assertSame($armId, $arm['arm_id']);
            $this->assertTrue($arm['safety_contract']['never_promotes_completion_claim']);
            $this->assertTrue($arm['safety_contract']['never_unlocks_external_rivals_certification']);
            $this->assertSame(0, $arm['safety_contract']['max_score_without_evidence']);
            $this->assertArrayHasKey('capabilities', $arm);
            $this->assertArrayHasKey('supports_explicit_model', $arm['capabilities']);
            $this->assertArrayHasKey('requires_human_confirmation_for_real_call', $arm['capabilities']);
        }
    }

    public function test_registry_snapshot_carries_complete_capability_contract_for_every_arm(): void
    {
        $registry = app(AtlasForgeRivalsArmRegistryService::class);
        $snapshot = $registry->snapshot();

        $requiredCapabilities = [
            'supports_explicit_model',
            'supports_non_interactive',
            'supports_json_output',
            'supports_workspace_path',
            'supports_timeout',
            'supports_resume',
            'supports_streaming_logs',
            'supports_cost_receipts',
            'supports_provider_receipts',
            'supports_evidence_pack',
            'supports_local_fake',
            'requires_human_confirmation_for_real_call',
        ];
        $enterpriseModes = [
            'fair',
            'fair-mode',
            'power-mode',
            'provider_arena',
            'provider-arena',
            'provider_pure',
            'full_power',
            'local_fake',
            'dry-run',
        ];

        foreach ($snapshot['arms'] as $armId => $arm) {
            $this->assertArrayHasKey('capabilities', $arm, "Arm {$armId} must declare capabilities.");
            $this->assertArrayHasKey('allowed_modes', $arm, "Arm {$armId} must declare allowed_modes.");

            foreach ($requiredCapabilities as $capability) {
                $this->assertArrayHasKey($capability, $arm['capabilities'], "Arm {$armId} missing {$capability}.");
                $this->assertIsBool($arm['capabilities'][$capability], "Arm {$armId} capability {$capability} must be boolean.");
            }

            if (($arm['status'] ?? null) === AtlasForgeRivalsArmRegistryService::STATUS_AVAILABLE) {
                foreach ($enterpriseModes as $mode) {
                    $this->assertContains($mode, $arm['allowed_modes'], "Executable arm {$armId} missing mode {$mode}.");
                }
                $this->assertTrue($arm['capabilities']['supports_evidence_pack'], "Executable arm {$armId} must support evidence packs.");
                $this->assertTrue($arm['capabilities']['supports_local_fake'], "Executable arm {$armId} must support local_fake planning.");
            }

            if (($arm['requires_external_provider_call'] ?? false) === true) {
                $this->assertTrue($arm['capabilities']['requires_human_confirmation_for_real_call']);
                $this->assertTrue($arm['capabilities']['supports_provider_receipts']);
                $this->assertTrue($arm['capabilities']['supports_cost_receipts']);
            } else {
                $this->assertFalse($arm['capabilities']['requires_human_confirmation_for_real_call']);
            }
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
        $this->assertArrayHasKey('cursor', $response['providers']);
        $this->assertArrayHasKey('composer', $response['providers']);
        $this->assertArrayHasKey('gpt-5.5', $response['providers']['codex']['models']);
        $this->assertArrayHasKey('default', $response['providers']['cursor']['models']);
        $this->assertArrayHasKey('composer-2.5', $response['providers']['composer']['models']);
        $this->assertTrue($response['providers']['cursor']['meta_provider']);
        $this->assertSame('meta_provider', $response['providers']['cursor']['provider_kind']);
        $this->assertSame('cursor', $response['providers']['cursor']['model_routing_owner']);
        $this->assertSame('none', $response['providers']['cursor']['atlas_routing_effect']);
        $this->assertTrue($response['providers']['composer']['meta_provider']);
        $this->assertSame('cursor', $response['providers']['composer']['meta_provider_parent']);
        $this->assertSame('stream-json', $response['providers']['composer']['tool_event_stream']);
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
        $this->assertSame('ceiling-360', $response['case_set']);
        $this->assertSame(120, $response['case_count']);
        $this->assertTrue($response['ceiling_360_matrix']);
        $this->assertSame(8, $response['pair_count']);
        $this->assertSame(8, $response['dry_run_ready_count']);
        $this->assertSame('atlas.forge.rivals.ceiling_360_execution_ladder.v1', data_get($response, 'execution_ladder.schema_version'));
        $this->assertSame('ready', data_get($response, 'execution_ladder.status'));
        $stages = collect(data_get($response, 'execution_ladder.stages'));
        $this->assertSame(['canary_8', 'floor_24', 'full_120'], $stages->pluck('stage')->all());
        $canary = $stages->firstWhere('stage', 'canary_8');
        $this->assertSame(8, $canary['case_count']);
        $this->assertSame(8, $canary['pair_count']);
        $this->assertSame(64, $canary['estimated_real_runs']);
        $this->assertSame(128, $canary['estimated_provider_invocations']);
        $this->assertCount(8, $canary['first_case_dry_run_commands']);
        $this->assertCount(8, $canary['first_case_real_commands']);
        $this->assertStringContainsString('--case=ceiling-360-001-industrial-005-incident_rollback', $canary['first_case_dry_run_commands'][0]);
        $this->assertStringContainsString('--dry-run', $canary['first_case_dry_run_commands'][0]);
        $this->assertStringContainsString('--confirm-real-provider-call', $canary['first_case_real_commands'][0]);
        $this->assertFalse($response['external_provider_call']);
        $this->assertFalse($response['provider_tokens_spent']);
        $this->assertTrue($response['advisory_only']);
        $this->assertFalse($response['should_update_provider_topology']);
        $this->assertSame('atlas_decide', $response['owner_of_model_routing']);
        $this->assertSame('none', $response['routing_effect']);

        $pairIds = array_column($response['pairs'], 'pair_id');
        $this->assertContains('atlas_forge_vs_claude_sonnet', $pairIds);
        $this->assertContains('atlas_dev_vs_atlas_forge', $pairIds);
        $this->assertContains('claude_opus_vs_codex_gpt55', $pairIds);
        $this->assertContains('codex_gpt55_vs_gemini_pro', $pairIds);
        $this->assertContains('cursor_default_vs_claude_sonnet', $pairIds);
        $this->assertContains('composer_2_5_vs_codex_gpt55', $pairIds);
        $this->assertContains('claude_sonnet_vs_claude_opus', $pairIds);
        $this->assertContains('atlas_forge_full_power_vs_claude_opus', $pairIds);
        foreach ($response['pairs'] as $pair) {
            $this->assertSame('ceiling-360', $pair['case_set']);
            $this->assertStringContainsString('--case-set=ceiling-360', $pair['dry_run_command']);
            $this->assertStringContainsString('--dry-run', $pair['dry_run_command']);
            $this->assertFalse($pair['external_provider_call']);
            $this->assertFalse($pair['provider_tokens_spent']);
            $this->assertSame('none', $pair['routing_effect']);
        }
    }

    public function test_arena_readiness_marks_stubbed_binaries_ready_after_confirmations(): void
    {
        $fakeProvider = $this->fakeProviderBinary();
        $oldClaudeBinary = config('atlas.ai.providers.claude_cli.binary');
        $oldCodexBinary = config('atlas.ai.providers.codex_cli.binary');
        $oldCodexArgs = config('atlas.ai.providers.codex_cli.args');
        $oldGeminiBinary = config('atlas.ai.providers.gemini_cli.binary');
        $oldCursorBinary = config('atlas.ai.providers.cursor_cli.binary');
        $oldCursorEnabled = config('atlas.ai.providers.cursor_cli.enabled');
        $oldEvidenceFloor = config('atlas_rivals.min_free_bytes_before_provider_evidence');

        try {
            config([
                'atlas.ai.providers.claude_cli.binary' => $fakeProvider,
                'atlas.ai.providers.codex_cli.binary' => $fakeProvider,
                'atlas.ai.providers.codex_cli.args' => ['exec', '--skip-git-repo-check'],
                'atlas.ai.providers.gemini_cli.binary' => $fakeProvider,
                'atlas_rivals.min_free_bytes_before_provider_evidence' => 0,
            ]);

            $dispatcher = app(AtlasForgeRivalsActionDispatcher::class);
            $response = $dispatcher->dispatch('arena-readiness', []);

            $this->assertSame('ok', $response['status']);
            config([
                'atlas.ai.providers.cursor_cli.binary' => $fakeProvider,
                'atlas.ai.providers.cursor_cli.enabled' => true,
            ]);

            $response = $dispatcher->dispatch('arena-readiness', []);

            $this->assertSame(8, $response['real_run_ready_count']);
            $this->assertSame(0, $response['driver_missing_count']);
            foreach ($response['pairs'] as $pair) {
                $this->assertSame('real_run_ready_after_confirmations', $pair['status']);
                $this->assertTrue($pair['dry_run_ready']);
                $this->assertTrue($pair['real_run_ready']);
                $this->assertSame([], $pair['blockers']);
                $this->assertStringContainsString('--confirm-runbook-reviewed', $pair['next_command']);
                $this->assertStringContainsString('--confirm-provider-cost', $pair['next_command']);
                $this->assertStringContainsString('--confirm-real-provider-call', $pair['next_command']);
                $this->assertStringContainsString('--case-set=ceiling-360', $pair['next_command']);
            }

            $codexVsGemini = collect($response['pairs'])->firstWhere('pair_id', 'codex_gpt55_vs_gemini_pro');
            $this->assertSame('codex_cli', data_get($codexVsGemini, 'arm_a.resolved_arm'));
            $this->assertSame('gpt-5.5', data_get($codexVsGemini, 'arm_a.model_alias'));
            $this->assertSame('gpt-5.5', data_get($codexVsGemini, 'arm_a.resolved_model_id'));
            $this->assertSame('gemini-3.1-pro-preview', data_get($codexVsGemini, 'arm_b.resolved_model_id'));
            $this->assertContains('gpt-5.5', data_get($codexVsGemini, 'command_plan.arm_a.command'));
            $this->assertContains('gemini-3.1-pro-preview', data_get($codexVsGemini, 'command_plan.arm_b.command'));
            $cursorVsClaude = collect($response['pairs'])->firstWhere('pair_id', 'cursor_default_vs_claude_sonnet');
            $this->assertSame('cursor_cli', data_get($cursorVsClaude, 'arm_a.resolved_arm'));
            $this->assertSame('default', data_get($cursorVsClaude, 'arm_a.model_alias'));
            $this->assertSame('composer-latest', data_get($cursorVsClaude, 'arm_a.resolved_model_id'));
            $this->assertSame('cursor_cli', data_get($cursorVsClaude, 'command_plan.arm_a.command_family'));
            $this->assertSame('stdin', data_get($cursorVsClaude, 'command_plan.arm_a.prompt_transport'));
            $this->assertTrue(data_get($cursorVsClaude, 'command_plan.arm_a.command_shape_summary.governed_cursor_cli_shape'));
            $this->assertTrue(data_get($cursorVsClaude, 'command_plan.arm_a.command_shape_summary.prompt_arg_absent'));
            $composerVsCodex = collect($response['pairs'])->firstWhere('pair_id', 'composer_2_5_vs_codex_gpt55');
            $this->assertSame('composer_2_5', data_get($composerVsCodex, 'arm_a.resolved_arm'));
            $this->assertSame('default', data_get($composerVsCodex, 'arm_a.model_alias'));
            $this->assertSame('composer-latest', data_get($composerVsCodex, 'arm_a.resolved_model_id'));
            $this->assertSame('composer_2_5', data_get($composerVsCodex, 'command_plan.arm_a.command_family'));
            $this->assertContains('composer-latest', data_get($composerVsCodex, 'command_plan.arm_a.command'));
            $this->assertSame('stdin', data_get($composerVsCodex, 'command_plan.arm_a.prompt_transport'));
            $this->assertTrue(data_get($composerVsCodex, 'command_plan.arm_a.command_shape_summary.governed_cursor_cli_shape'));
            $this->assertTrue(data_get($composerVsCodex, 'command_plan.arm_a.command_shape_summary.prompt_arg_absent'));
        } finally {
            config([
                'atlas.ai.providers.claude_cli.binary' => $oldClaudeBinary,
                'atlas.ai.providers.codex_cli.binary' => $oldCodexBinary,
                'atlas.ai.providers.codex_cli.args' => $oldCodexArgs,
                'atlas.ai.providers.gemini_cli.binary' => $oldGeminiBinary,
                'atlas.ai.providers.cursor_cli.binary' => $oldCursorBinary,
                'atlas.ai.providers.cursor_cli.enabled' => $oldCursorEnabled,
                'atlas_rivals.min_free_bytes_before_provider_evidence' => $oldEvidenceFloor,
            ]);
        }
    }

    public function test_arena_readiness_ladder_counts_only_valid_replay_verified_real_evidence(): void
    {
        $oldRunsRoot = config('atlas_rivals.runs_root');
        $runsRoot = sys_get_temp_dir().'/atlas-rivals-readiness-coverage-'.Str::lower(Str::random(8));

        try {
            config(['atlas_rivals.runs_root' => $runsRoot]);

            $this->writeReadinessCoverageRun(
                $runsRoot,
                'arena-z-invalid',
                verdict: 'invalid_scope_violation',
                hardFailures: ['verdict_comparable'],
            );
            $this->writeReadinessCoverageRun(
                $runsRoot,
                'arena-a-valid',
                verdict: 'comparable',
                hardFailures: [],
            );

            $response = app(AtlasForgeRivalsActionDispatcher::class)->dispatch('arena-readiness', []);
            $canary = collect(data_get($response, 'execution_ladder.stages'))->firstWhere('stage', 'canary_8');

            $this->assertSame(1, $canary['observed_real_runs']);
            $this->assertSame(1, $canary['replay_verified_runs']);
            $this->assertSame(63, $canary['missing_real_runs']);
            $this->assertSame('partial', $canary['coverage_status']);
            $this->assertSame('arena-a-valid', $canary['observed_runs'][0]['run_id']);
            $this->assertTrue($canary['observed_runs'][0]['valid_evidence']);
            $this->assertSame([], $canary['observed_runs'][0]['hard_failures']);
            $this->assertFalse($response['external_provider_call']);
            $this->assertFalse($response['provider_tokens_spent']);
            $this->assertSame('none', $response['routing_effect']);
        } finally {
            config(['atlas_rivals.runs_root' => $oldRunsRoot]);
        }
    }

    public function test_arena_readiness_blocks_real_run_when_evidence_disk_floor_is_not_met(): void
    {
        $fakeProvider = $this->fakeProviderBinary();
        $oldClaudeBinary = config('atlas.ai.providers.claude_cli.binary');
        $oldCodexBinary = config('atlas.ai.providers.codex_cli.binary');
        $oldCodexArgs = config('atlas.ai.providers.codex_cli.args');
        $oldGeminiBinary = config('atlas.ai.providers.gemini_cli.binary');
        $oldCursorBinary = config('atlas.ai.providers.cursor_cli.binary');
        $oldCursorEnabled = config('atlas.ai.providers.cursor_cli.enabled');
        $oldEvidenceFloor = config('atlas_rivals.min_free_bytes_before_provider_evidence');

        try {
            config([
                'atlas.ai.providers.claude_cli.binary' => $fakeProvider,
                'atlas.ai.providers.codex_cli.binary' => $fakeProvider,
                'atlas.ai.providers.codex_cli.args' => ['exec', '--skip-git-repo-check'],
                'atlas.ai.providers.gemini_cli.binary' => $fakeProvider,
                'atlas.ai.providers.cursor_cli.binary' => $fakeProvider,
                'atlas.ai.providers.cursor_cli.enabled' => true,
                'atlas_rivals.min_free_bytes_before_provider_evidence' => PHP_INT_MAX,
            ]);

            $dispatcher = app(AtlasForgeRivalsActionDispatcher::class);
            $response = $dispatcher->dispatch('arena-readiness', []);

            $this->assertSame('ok', $response['status']);
            $this->assertSame(8, $response['dry_run_ready_count']);
            $this->assertSame(0, $response['real_run_ready_count']);
            $this->assertSame(8, $response['evidence_disk_blocked_count']);
            $this->assertSame('blocked', $response['evidence_disk_status']['status']);
            $this->assertFalse($response['external_provider_call']);
            $this->assertFalse($response['provider_tokens_spent']);
            $this->assertTrue($response['advisory_only']);
            $this->assertFalse($response['should_update_provider_topology']);
            $this->assertSame('atlas_decide', $response['owner_of_model_routing']);
            $this->assertSame('none', $response['routing_effect']);

            foreach ($response['pairs'] as $pair) {
                $this->assertSame('plan_ready_evidence_disk_blocked', $pair['status']);
                $this->assertTrue($pair['dry_run_ready']);
                $this->assertFalse($pair['real_run_ready']);
                $this->assertSame('blocked', $pair['evidence_disk_status']['status']);
                $this->assertNotEmpty(array_filter(
                    $pair['blockers'],
                    static fn (string $blocker): bool => str_starts_with($blocker, 'provider_evidence_disk_space_insufficient:'),
                ));
            }
        } finally {
            config([
                'atlas.ai.providers.claude_cli.binary' => $oldClaudeBinary,
                'atlas.ai.providers.codex_cli.binary' => $oldCodexBinary,
                'atlas.ai.providers.codex_cli.args' => $oldCodexArgs,
                'atlas.ai.providers.gemini_cli.binary' => $oldGeminiBinary,
                'atlas.ai.providers.cursor_cli.binary' => $oldCursorBinary,
                'atlas.ai.providers.cursor_cli.enabled' => $oldCursorEnabled,
                'atlas_rivals.min_free_bytes_before_provider_evidence' => $oldEvidenceFloor,
            ]);
        }
    }

    public function test_cursor_and_composer_aliases_resolve_from_canonical_registry(): void
    {
        $registry = app(AtlasForgeRivalsProviderModelRegistryService::class);

        $cursor = $registry->resolve('cursor', 'default');
        $this->assertTrue($cursor['ok']);
        $this->assertSame('default', $cursor['canonical_model']);
        $this->assertSame('composer-latest', $cursor['model_id']);
        $this->assertSame('atlas.ai.providers.cursor_cli.model', $cursor['model_id_config_key']);
        $this->assertTrue($cursor['meta_provider']);
        $this->assertSame('meta_provider', $cursor['provider_kind']);
        $this->assertSame('cursor', $cursor['provider_metadata']['model_routing_owner']);
        $this->assertSame('none', $cursor['provider_metadata']['atlas_routing_effect']);

        $composer = $registry->resolve('composer', 'composer_2_5');
        $this->assertTrue($composer['ok']);
        $this->assertSame('composer-2.5', $composer['canonical_model']);
        $this->assertSame('composer-latest', $composer['model_id']);
        $this->assertSame('atlas.ai.providers.cursor_cli.composer_2_5_model', $composer['model_id_config_key']);
        $this->assertTrue($composer['meta_provider']);
        $this->assertSame('meta_provider_surface', $composer['provider_kind']);
        $this->assertSame('cursor', $composer['meta_provider_parent']);
        $this->assertSame('stream-json', $composer['provider_metadata']['tool_event_stream']);
    }

    public function test_configured_cursor_and_composer_aliases_resolve_through_registry_only(): void
    {
        $oldCursorAliases = config('atlas.ai.providers.cursor_cli.aliases');
        $oldComposerAliases = config('atlas.ai.providers.cursor_cli.composer_2_5_aliases');
        $oldCursorModel = config('atlas.ai.providers.cursor_cli.model');
        $oldComposerModel = config('atlas.ai.providers.cursor_cli.composer_2_5_model');

        try {
            config([
                'atlas.ai.providers.cursor_cli.aliases' => ['operator_default', 'cursor-enterprise'],
                'atlas.ai.providers.cursor_cli.composer_2_5_aliases' => 'composer_enterprise,composer-heavy',
                'atlas.ai.providers.cursor_cli.model' => 'cursor-enterprise-configured-model',
                'atlas.ai.providers.cursor_cli.composer_2_5_model' => 'composer-enterprise-configured-model',
            ]);

            $registry = app(AtlasForgeRivalsProviderModelRegistryService::class);

            $cursor = $registry->resolve('cursor', 'operator_default');
            $this->assertTrue($cursor['ok']);
            $this->assertSame('default', $cursor['canonical_model']);
            $this->assertSame('cursor-enterprise-configured-model', $cursor['model_id']);
            $this->assertSame('atlas.ai.providers.cursor_cli.model', $cursor['model_id_config_key']);

            $composerViaCursor = $registry->resolve('cursor', 'composer_enterprise');
            $this->assertTrue($composerViaCursor['ok']);
            $this->assertSame('composer-2.5', $composerViaCursor['canonical_model']);
            $this->assertSame('composer-enterprise-configured-model', $composerViaCursor['model_id']);
            $this->assertSame('atlas.ai.providers.cursor_cli.composer_2_5_model', $composerViaCursor['model_id_config_key']);

            $composer = $registry->resolve('composer', 'composer-heavy');
            $this->assertTrue($composer['ok']);
            $this->assertSame('composer-2.5', $composer['canonical_model']);
            $this->assertSame('composer-enterprise-configured-model', $composer['model_id']);
            $this->assertSame('cursor', $composer['meta_provider_parent']);
        } finally {
            config([
                'atlas.ai.providers.cursor_cli.aliases' => $oldCursorAliases,
                'atlas.ai.providers.cursor_cli.composer_2_5_aliases' => $oldComposerAliases,
                'atlas.ai.providers.cursor_cli.model' => $oldCursorModel,
                'atlas.ai.providers.cursor_cli.composer_2_5_model' => $oldComposerModel,
            ]);
        }
    }

    public function test_cursor_and_composer_block_when_configured_model_id_is_missing(): void
    {
        $oldCursorModel = config('atlas.ai.providers.cursor_cli.model');
        $oldComposerModel = config('atlas.ai.providers.cursor_cli.composer_2_5_model');

        try {
            config([
                'atlas.ai.providers.cursor_cli.model' => '',
                'atlas.ai.providers.cursor_cli.composer_2_5_model' => '',
            ]);

            $registry = app(AtlasForgeRivalsProviderModelRegistryService::class);

            $cursor = $registry->resolve('cursor', 'default');
            $this->assertFalse($cursor['ok']);
            $this->assertContains('provider_model_id_missing:cursor:default:atlas.ai.providers.cursor_cli.model', $cursor['blockers']);

            $composer = $registry->resolve('composer', 'default');
            $this->assertFalse($composer['ok']);
            $this->assertContains('provider_model_id_missing:composer:composer-2.5:atlas.ai.providers.cursor_cli.composer_2_5_model', $composer['blockers']);
        } finally {
            config([
                'atlas.ai.providers.cursor_cli.model' => $oldCursorModel,
                'atlas.ai.providers.cursor_cli.composer_2_5_model' => $oldComposerModel,
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

    public function test_setup_blocks_before_git_worktree_when_disk_space_is_below_floor(): void
    {
        $oldFloor = config('atlas_rivals.min_free_bytes_before_worktree_add');

        try {
            config(['atlas_rivals.min_free_bytes_before_worktree_add' => PHP_INT_MAX]);

            $setup = app(AtlasForgeRivalsSetupService::class)->provision([
                'run_id' => 'arena-disk-preflight-'.Str::lower(Str::random(8)),
                'source_ref' => 'HEAD',
            ]);

            $this->assertSame('blocked', $setup['status']);
            $this->assertSame([], $setup['worktrees']);
            $this->assertFalse($setup['external_provider_call']);
            $this->assertFalse($setup['provider_tokens_spent']);
            $this->assertTrue($setup['advisory_only']);
            $this->assertFalse($setup['should_update_provider_topology']);
            $this->assertTrue($setup['never_changes_atlas_decide_topology']);
            $this->assertSame('atlas_decide', $setup['owner_of_model_routing']);
            $this->assertSame('none', $setup['routing_effect']);
            $this->assertTrue(
                collect($setup['blockers'])->contains(
                    static fn (string $blocker): bool => str_starts_with($blocker, 'worktree_disk_space_insufficient:'),
                ),
                json_encode($setup, JSON_PRETTY_PRINT),
            );
        } finally {
            config(['atlas_rivals.min_free_bytes_before_worktree_add' => $oldFloor]);
        }
    }

    public function test_minimal_checkout_uses_separate_disk_floor_before_worktree_add(): void
    {
        $oldFullFloor = config('atlas_rivals.min_free_bytes_before_worktree_add');
        $oldMinimalFloor = config('atlas_rivals.min_free_bytes_before_minimal_worktree_add');

        try {
            config([
                'atlas_rivals.min_free_bytes_before_worktree_add' => 0,
                'atlas_rivals.min_free_bytes_before_minimal_worktree_add' => PHP_INT_MAX,
            ]);

            $setup = app(AtlasForgeRivalsSetupService::class)->provision([
                'run_id' => 'arena-minimal-disk-preflight-'.Str::lower(Str::random(8)),
                'source_ref' => 'HEAD',
                'checkout_strategy' => 'minimal_no_checkout',
            ]);

            $this->assertSame('blocked', $setup['status']);
            $this->assertSame([], $setup['worktrees']);
            $this->assertTrue(
                collect($setup['blockers'])->contains(
                    static fn (string $blocker): bool => str_starts_with($blocker, 'worktree_disk_space_insufficient:'),
                ),
                json_encode($setup, JSON_PRETTY_PRINT),
            );
            $this->assertFalse($setup['external_provider_call']);
            $this->assertFalse($setup['provider_tokens_spent']);
            $this->assertTrue($setup['advisory_only']);
            $this->assertSame('none', $setup['routing_effect']);
        } finally {
            config([
                'atlas_rivals.min_free_bytes_before_worktree_add' => $oldFullFloor,
                'atlas_rivals.min_free_bytes_before_minimal_worktree_add' => $oldMinimalFloor,
            ]);
        }
    }

    public function test_minimal_checkout_materializes_composer_runtime_contract_without_full_checkout(): void
    {
        $oldMinimalFloor = config('atlas_rivals.min_free_bytes_before_minimal_worktree_add');

        try {
            config(['atlas_rivals.min_free_bytes_before_minimal_worktree_add' => 0]);

            $setup = app(AtlasForgeRivalsSetupService::class)->provision([
                'run_id' => 'arena-minimal-runtime-'.Str::lower(Str::random(8)),
                'source_ref' => 'HEAD',
                'checkout_strategy' => 'minimal_no_checkout',
            ]);

            $this->assertSame('ok', $setup['status'], json_encode($setup, JSON_PRETTY_PRINT));
            foreach (['atlas', 'rival'] as $arm) {
                $worktree = $setup['worktrees'][$arm]['path'];
                $this->assertSame('minimal_no_checkout', $setup['worktrees'][$arm]['checkout_strategy']);
                $this->assertSame(['minimal_runtime_files_checked_out'], $setup['worktrees'][$arm]['minimal_runtime']['actions']);
                $this->assertFileExists($worktree.'/composer.json');
                $this->assertFileExists($worktree.'/composer.lock');
                $this->assertFileDoesNotExist($worktree.'/app');
                $this->assertTrue($setup['worktrees'][$arm]['runtime']['vendor_ready']);
            }
            $this->assertFalse($setup['external_provider_call']);
            $this->assertFalse($setup['provider_tokens_spent']);
            $this->assertTrue($setup['advisory_only']);
            $this->assertSame('none', $setup['routing_effect']);
        } finally {
            config(['atlas_rivals.min_free_bytes_before_minimal_worktree_add' => $oldMinimalFloor]);
        }
    }

    public function test_run_battery_explicit_corpus_case_uses_minimal_checkout_floor(): void
    {
        $fakeProvider = $this->fakeProviderBinary();
        $oldClaudeBinary = config('atlas.ai.providers.claude_cli.binary');
        $oldFullFloor = config('atlas_rivals.min_free_bytes_before_worktree_add');
        $oldMinimalFloor = config('atlas_rivals.min_free_bytes_before_minimal_worktree_add');

        try {
            config([
                'atlas.ai.providers.claude_cli.binary' => $fakeProvider,
                'atlas_rivals.min_free_bytes_before_worktree_add' => PHP_INT_MAX,
                'atlas_rivals.min_free_bytes_before_minimal_worktree_add' => 0,
            ]);

            Artisan::call('atlas:forge:rivals', [
                'action' => 'run-battery',
                '--case' => ['extreme-differentiator-002-industrial-005-incident_rollback'],
                '--mode' => 'fair',
                '--atlas-model' => 'sonnet',
                '--rival' => 'claude_sonnet',
                '--dry-run' => true,
                '--json' => true,
            ]);
            $payload = json_decode((string) Artisan::output(), true);

            $this->assertSame('ok', $payload['status'], json_encode($payload, JSON_PRETTY_PRINT));
            $this->assertSame('ok', $payload['phases'][1]['status'], json_encode($payload, JSON_PRETTY_PRINT));
            $this->assertFalse($payload['external_provider_call']);
            $this->assertFalse($payload['provider_tokens_spent']);
            $this->assertTrue($payload['advisory_only']);
            $this->assertSame('none', $payload['routing_effect']);
        } finally {
            config([
                'atlas.ai.providers.claude_cli.binary' => $oldClaudeBinary,
                'atlas_rivals.min_free_bytes_before_worktree_add' => $oldFullFloor,
                'atlas_rivals.min_free_bytes_before_minimal_worktree_add' => $oldMinimalFloor,
            ]);
        }
    }

    public function test_run_arena_disk_space_blocker_preserves_advisory_only_invariants(): void
    {
        $oldFloor = config('atlas_rivals.min_free_bytes_before_worktree_add');

        try {
            config(['atlas_rivals.min_free_bytes_before_worktree_add' => PHP_INT_MAX]);

            $response = app(AtlasForgeRivalsActionDispatcher::class)->dispatch('run-arena', [
                'arm_a' => 'atlas_forge',
                'arm_b' => 'claude_code',
                'arm_a_model' => 'sonnet',
                'arm_b_model' => 'sonnet',
                'task_category' => 'bugfix',
                'mode' => 'local_fake',
                'preset' => 'quick',
                'run_id' => 'arena-disk-envelope-'.Str::lower(Str::random(8)),
                'confirmations' => ['runbook_reviewed' => false, 'provider_cost' => false, 'real_provider_call' => false],
            ]);

            $this->assertSame('blocked', $response['status'], json_encode($response, JSON_PRETTY_PRINT));
            $this->assertTrue(
                collect($response['blockers'])->contains(
                    static fn (string $blocker): bool => str_starts_with($blocker, 'worktree_disk_space_insufficient:'),
                ),
                json_encode($response, JSON_PRETTY_PRINT),
            );
            $this->assertFalse($response['external_provider_call']);
            $this->assertFalse($response['provider_tokens_spent']);
            $this->assertTrue($response['advisory_only']);
            $this->assertFalse($response['should_update_provider_topology']);
            $this->assertTrue($response['never_changes_atlas_decide_topology']);
            $this->assertSame('atlas_decide', $response['owner_of_model_routing']);
            $this->assertSame('none', $response['routing_effect']);
        } finally {
            config(['atlas_rivals.min_free_bytes_before_worktree_add' => $oldFloor]);
        }
    }

    public function test_real_run_blocks_before_provider_when_evidence_disk_space_is_below_floor(): void
    {
        $fakeProvider = $this->fakeProviderBinary();
        $oldClaudeBinary = config('atlas.ai.providers.claude_cli.binary');
        $oldFullFloor = config('atlas_rivals.min_free_bytes_before_worktree_add');
        $oldMinimalFloor = config('atlas_rivals.min_free_bytes_before_minimal_worktree_add');
        $oldEvidenceFloor = config('atlas_rivals.min_free_bytes_before_provider_evidence');

        try {
            config([
                'atlas.ai.providers.claude_cli.binary' => $fakeProvider,
                'atlas_rivals.min_free_bytes_before_worktree_add' => 0,
                'atlas_rivals.min_free_bytes_before_minimal_worktree_add' => 0,
                'atlas_rivals.min_free_bytes_before_provider_evidence' => PHP_INT_MAX,
            ]);

            $response = app(AtlasForgeRivalsActionDispatcher::class)->dispatch('run-arena', [
                'arm_a' => 'atlas_forge',
                'arm_b' => 'claude_code',
                'arm_a_model' => 'sonnet',
                'arm_b_model' => 'sonnet',
                'task_category' => 'refactor',
                'mode' => 'provider_arena',
                'case' => 'extreme-differentiator-002-industrial-005-incident_rollback',
                'prompt_mode' => 'enterprise-change',
                'run_id' => 'arena-evidence-disk-guard-'.Str::lower(Str::random(8)),
                'confirmations' => ['runbook_reviewed' => true, 'provider_cost' => true, 'real_provider_call' => true],
            ]);

            $phaseNames = collect($response['phases'] ?? [])->pluck('phase')->all();

            $this->assertSame('blocked', $response['status'], json_encode($response, JSON_PRETTY_PRINT));
            $this->assertContains('evidence-disk-guard', $phaseNames, json_encode($response, JSON_PRETTY_PRINT));
            $this->assertNotContains('run-real', $phaseNames, json_encode($response, JSON_PRETTY_PRINT));
            $this->assertTrue(
                collect($response['blockers'])->contains(
                    static fn (string $blocker): bool => str_starts_with($blocker, 'provider_evidence_disk_space_insufficient:'),
                ),
                json_encode($response, JSON_PRETTY_PRINT),
            );
            $this->assertFalse($response['external_provider_call']);
            $this->assertFalse($response['provider_tokens_spent']);
            $this->assertTrue($response['advisory_only']);
            $this->assertFalse($response['should_update_provider_topology']);
            $this->assertTrue($response['never_changes_atlas_decide_topology']);
            $this->assertSame('atlas_decide', $response['owner_of_model_routing']);
            $this->assertSame('none', $response['routing_effect']);
        } finally {
            config([
                'atlas.ai.providers.claude_cli.binary' => $oldClaudeBinary,
                'atlas_rivals.min_free_bytes_before_worktree_add' => $oldFullFloor,
                'atlas_rivals.min_free_bytes_before_minimal_worktree_add' => $oldMinimalFloor,
                'atlas_rivals.min_free_bytes_before_provider_evidence' => $oldEvidenceFloor,
            ]);
        }
    }

    public function test_run_arena_explicit_industrial_case_uses_minimal_checkout_floor(): void
    {
        $fakeProvider = $this->fakeProviderBinary();
        $oldClaudeBinary = config('atlas.ai.providers.claude_cli.binary');
        $oldFullFloor = config('atlas_rivals.min_free_bytes_before_worktree_add');
        $oldMinimalFloor = config('atlas_rivals.min_free_bytes_before_minimal_worktree_add');
        $oldEvidenceFloor = config('atlas_rivals.min_free_bytes_before_provider_evidence');

        try {
            config([
                'atlas.ai.providers.claude_cli.binary' => $fakeProvider,
                'atlas_rivals.min_free_bytes_before_worktree_add' => PHP_INT_MAX,
                'atlas_rivals.min_free_bytes_before_minimal_worktree_add' => 0,
                'atlas_rivals.min_free_bytes_before_provider_evidence' => 0,
            ]);

            $response = app(AtlasForgeRivalsActionDispatcher::class)->dispatch('run-arena', [
                'arm_a' => 'atlas_forge',
                'arm_b' => 'claude_code',
                'arm_a_model' => 'sonnet',
                'arm_b_model' => 'sonnet',
                'task_category' => 'refactor',
                'mode' => 'provider_arena',
                'case' => 'ceiling-360-001-industrial-005-incident_rollback',
                'prompt_mode' => 'enterprise-change',
                'run_id' => 'arena-minimal-explicit-case-'.Str::lower(Str::random(8)),
                'confirmations' => ['runbook_reviewed' => true, 'provider_cost' => true, 'real_provider_call' => true],
            ]);

            $setup = collect($response['phases'] ?? [])->firstWhere('phase', 'setup');

            $this->assertIsArray($setup, json_encode($response, JSON_PRETTY_PRINT));
            $this->assertSame('ok', $setup['status'], json_encode($response, JSON_PRETTY_PRINT));
            $this->assertFalse(
                collect($response['blockers'] ?? [])->contains(
                    static fn (string $blocker): bool => str_starts_with($blocker, 'worktree_disk_space_insufficient:'),
                ),
                json_encode($response, JSON_PRETTY_PRINT),
            );
            $this->assertTrue(
                collect($response['phases'] ?? [])->contains(
                    static fn (array $phase): bool => ($phase['phase'] ?? '') === 'run-real' && ($phase['status'] ?? '') === 'ok',
                ),
                json_encode($response, JSON_PRETTY_PRINT),
            );
            $this->assertTrue($response['advisory_only']);
            $this->assertSame('none', $response['routing_effect']);
        } finally {
            config([
                'atlas.ai.providers.claude_cli.binary' => $oldClaudeBinary,
                'atlas_rivals.min_free_bytes_before_worktree_add' => $oldFullFloor,
                'atlas_rivals.min_free_bytes_before_minimal_worktree_add' => $oldMinimalFloor,
                'atlas_rivals.min_free_bytes_before_provider_evidence' => $oldEvidenceFloor,
            ]);
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

    public function test_provider_arena_dry_run_next_command_preserves_case_models_and_prompt_mode(): void
    {
        $dispatcher = app(AtlasForgeRivalsActionDispatcher::class);
        $response = $dispatcher->dispatch('run-arena', [
            'arm_a' => 'atlas_forge',
            'arm_b' => 'claude_code',
            'arm_a_model' => 'sonnet',
            'arm_b_model' => 'sonnet',
            'task_category' => 'frontend',
            'mode' => 'provider_arena',
            'case' => 'extreme-differentiator-004-industrial-010-product',
            'prompt_mode' => 'enterprise-change',
            'dry_run' => true,
            'confirmations' => ['runbook_reviewed' => false, 'provider_cost' => false, 'real_provider_call' => false],
        ]);

        $this->assertSame('ok', $response['status']);
        $this->assertTrue($response['dry_run']);
        $this->assertFalse($response['external_provider_call']);
        $this->assertFalse($response['provider_tokens_spent']);

        $next = (string) $response['next_command'];
        $this->assertStringContainsString('--arm-a-model=sonnet', $next);
        $this->assertStringContainsString('--arm-b-model=sonnet', $next);
        $this->assertStringContainsString('--case=extreme-differentiator-004-industrial-010-product', $next);
        $this->assertStringContainsString('--prompt-mode=enterprise-change', $next);
        $this->assertStringContainsString('--confirm-runbook-reviewed', $next);
        $this->assertStringContainsString('--confirm-provider-cost', $next);
        $this->assertStringContainsString('--confirm-real-provider-call', $next);
        $this->assertStringContainsString('--json', $next);
    }

    public function test_cursor_and_composer_dry_runs_resolve_without_provider_spend(): void
    {
        $dispatcher = app(AtlasForgeRivalsActionDispatcher::class);

        $cursor = $dispatcher->dispatch('run-arena', [
            'arm_a' => 'cursor_cli',
            'arm_b' => 'claude_code',
            'arm_a_model' => 'default',
            'arm_b_model' => 'sonnet',
            'task_category' => 'bugfix',
            'mode' => 'provider_arena',
            'dry_run' => true,
            'confirmations' => ['runbook_reviewed' => false, 'provider_cost' => false, 'real_provider_call' => false],
        ]);

        $this->assertSame('ok', $cursor['status']);
        $this->assertSame('cursor_cli', $cursor['arm_a']['arm_id']);
        $this->assertSame('cursor_cli', $cursor['arm_a']['resolved_arm']);
        $this->assertSame('cursor', $cursor['arm_a']['provider']);
        $this->assertSame('default', $cursor['arm_a']['model_alias']);
        $this->assertSame('default', $cursor['arm_a']['resolved_model']);
        $this->assertSame('composer-latest', $cursor['arm_a']['resolved_model_id']);
        $this->assertSame('default', $cursor['arm_a']['model']);
        $this->assertSame('composer-latest', $cursor['arm_a']['model_id']);
        $this->assertSame('cursor_cli', $cursor['arm_a']['command_builder']);
        $this->assertTrue($cursor['arm_a']['meta_provider']);
        $this->assertSame('meta_provider', $cursor['arm_a']['provider_kind']);
        $this->assertSame('cursor', $cursor['arm_a']['provider_metadata']['model_routing_owner']);
        $this->assertSame('stdin', $cursor['command_plan']['arm_a']['prompt_transport']);
        $this->assertContains('composer-latest', $cursor['command_plan']['arm_a']['command']);
        $this->assertNotContains('<prompt>', $cursor['command_plan']['arm_a']['command']);
        $this->assertNotEmpty($cursor['command_plan']['arm_a']['stdin_prompt_hash']);
        $this->assertTrue($cursor['command_plan']['arm_a']['command_shape_summary']['governed_cursor_cli_shape']);
        $this->assertTrue($cursor['command_plan']['arm_a']['command_shape_summary']['prompt_arg_absent']);
        $this->assertFalse($cursor['external_provider_call']);
        $this->assertFalse($cursor['provider_tokens_spent']);
        $this->assertTrue($cursor['advisory_only']);

        $composer = $dispatcher->dispatch('run-arena', [
            'arm_a' => 'composer_2_5',
            'arm_b' => 'codex_cli',
            'arm_a_model' => 'default',
            'arm_b_model' => 'gpt-5.5',
            'task_category' => 'bugfix',
            'mode' => 'provider_arena',
            'dry_run' => true,
            'confirmations' => ['runbook_reviewed' => false, 'provider_cost' => false, 'real_provider_call' => false],
        ]);

        $this->assertSame('ok', $composer['status']);
        $this->assertSame('composer_2_5', $composer['arm_a']['arm_id']);
        $this->assertSame('composer_2_5', $composer['arm_a']['resolved_arm']);
        $this->assertSame('composer', $composer['arm_a']['provider']);
        $this->assertSame('default', $composer['arm_a']['model_alias']);
        $this->assertSame('composer-2.5', $composer['arm_a']['resolved_model']);
        $this->assertSame('composer-latest', $composer['arm_a']['resolved_model_id']);
        $this->assertSame('composer-2.5', $composer['arm_a']['model']);
        $this->assertSame('composer-latest', $composer['arm_a']['model_id']);
        $this->assertSame('composer_2_5', $composer['arm_a']['command_builder']);
        $this->assertTrue($composer['arm_a']['meta_provider']);
        $this->assertSame('cursor', $composer['arm_a']['meta_provider_parent']);
        $this->assertSame('stream-json', $composer['arm_a']['provider_metadata']['tool_event_stream']);
        $this->assertSame('stdin', $composer['command_plan']['arm_a']['prompt_transport']);
        $this->assertContains('composer-latest', $composer['command_plan']['arm_a']['command']);
        $this->assertNotContains('<prompt>', $composer['command_plan']['arm_a']['command']);
        $this->assertNotEmpty($composer['command_plan']['arm_a']['stdin_prompt_hash']);
        $this->assertTrue($composer['command_plan']['arm_a']['command_shape_summary']['governed_cursor_cli_shape']);
        $this->assertTrue($composer['command_plan']['arm_a']['command_shape_summary']['prompt_arg_absent']);
        $this->assertFalse($composer['external_provider_call']);
        $this->assertFalse($composer['provider_tokens_spent']);
    }

    public function test_command_builder_passes_explicit_models_to_claude_codex_gemini_cursor_and_composer(): void
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

        $cursor = $builder->build([
            'arm' => ['arm_id' => 'cursor_cli', 'provider' => 'cursor'],
            'provider' => 'cursor',
            'resolved_model' => 'default',
            'resolved_model_id' => 'cursor-configured-model',
        ], 'human ambiguous prompt with sensitive context', '/tmp/worktree-d');
        $this->assertContains('--print', $cursor['command']);
        $this->assertContains('--output-format', $cursor['command']);
        $this->assertContains('stream-json', $cursor['command']);
        $this->assertContains('--model', $cursor['command']);
        $this->assertContains('cursor-configured-model', $cursor['command']);
        $this->assertNotContains('human ambiguous prompt with sensitive context', $cursor['command']);
        $this->assertSame('stdin', $cursor['prompt_transport']);
        $this->assertSame(hash('sha256', 'human ambiguous prompt with sensitive context'), $cursor['stdin_prompt_hash']);
        $this->assertSame('atlas.forge.rivals.cursor_command_shape_summary.v1', $cursor['command_shape_summary']['schema_version']);
        $this->assertTrue($cursor['command_shape_summary']['governed_cursor_cli_shape']);
        $this->assertTrue($cursor['command_shape_summary']['prompt_arg_absent']);
        $this->assertTrue($cursor['command_shape_summary']['force_absent']);
        $this->assertTrue($cursor['command_shape_summary']['resume_absent']);
        $this->assertNotContains('--force', $cursor['command']);
        $this->assertNotContains('--resume', $cursor['command']);

        $composer = $builder->build([
            'arm' => ['arm_id' => 'composer_2_5', 'provider' => 'composer'],
            'provider' => 'composer',
            'resolved_model' => 'composer-2.5',
            'resolved_model_id' => 'composer-configured-model',
        ], 'composer human prompt with private scope', '/tmp/worktree-e');
        $this->assertContains('--print', $composer['command']);
        $this->assertContains('--output-format', $composer['command']);
        $this->assertContains('stream-json', $composer['command']);
        $this->assertContains('--model', $composer['command']);
        $this->assertContains('composer-configured-model', $composer['command']);
        $this->assertSame('composer_2_5', $composer['command_family']);
        $this->assertNotContains('composer human prompt with private scope', $composer['command']);
        $this->assertSame('stdin', $composer['prompt_transport']);
        $this->assertSame(hash('sha256', 'composer human prompt with private scope'), $composer['stdin_prompt_hash']);
        $this->assertSame('atlas.forge.rivals.cursor_command_shape_summary.v1', $composer['command_shape_summary']['schema_version']);
        $this->assertTrue($composer['command_shape_summary']['governed_cursor_cli_shape']);
        $this->assertTrue($composer['command_shape_summary']['prompt_arg_absent']);
        $this->assertTrue($composer['command_shape_summary']['force_absent']);
        $this->assertTrue($composer['command_shape_summary']['resume_absent']);
        $this->assertNotContains('--force', $composer['command']);
        $this->assertNotContains('--resume', $composer['command']);
    }

    public function test_cursor_command_builder_blocks_unsafe_cursor_cli_flags(): void
    {
        $builder = app(AtlasForgeRivalsArmCommandBuilderService::class);
        $oldForce = config('atlas.ai.providers.cursor_cli.force');
        $oldOutputFormat = config('atlas.ai.providers.cursor_cli.output_format');

        try {
            config(['atlas.ai.providers.cursor_cli.force' => true]);
            $force = $builder->build([
                'arm' => ['arm_id' => 'cursor_cli', 'provider' => 'cursor'],
                'provider' => 'cursor',
                'resolved_model' => 'default',
                'resolved_model_id' => 'cursor-configured-model',
            ], 'prompt', '/tmp/worktree-d');
            $this->assertFalse($force['ok']);
            $this->assertSame([], $force['command']);
            $this->assertContains('cursor_cli_force_mode_forbidden', $force['blockers']);

            config([
                'atlas.ai.providers.cursor_cli.force' => false,
                'atlas.ai.providers.cursor_cli.output_format' => 'json',
            ]);
            $format = $builder->build([
                'arm' => ['arm_id' => 'composer_2_5', 'provider' => 'composer'],
                'provider' => 'composer',
                'resolved_model' => 'composer-2.5',
                'resolved_model_id' => 'composer-configured-model',
            ], 'prompt', '/tmp/worktree-e');
            $this->assertFalse($format['ok']);
            $this->assertSame([], $format['command']);
            $this->assertContains('cursor_cli_output_format_must_be_stream_json', $format['blockers']);
        } finally {
            config([
                'atlas.ai.providers.cursor_cli.force' => $oldForce,
                'atlas.ai.providers.cursor_cli.output_format' => $oldOutputFormat,
            ]);
        }
    }

    public function test_cursor_and_composer_readiness_block_when_binary_missing(): void
    {
        $fakeProvider = $this->fakeProviderBinary();
        $oldClaudeBinary = config('atlas.ai.providers.claude_cli.binary');
        $oldCodexBinary = config('atlas.ai.providers.codex_cli.binary');
        $oldCodexArgs = config('atlas.ai.providers.codex_cli.args');
        $oldGeminiBinary = config('atlas.ai.providers.gemini_cli.binary');
        $oldCursorBinary = config('atlas.ai.providers.cursor_cli.binary');
        $oldCursorEnabled = config('atlas.ai.providers.cursor_cli.enabled');

        try {
            config([
                'atlas.ai.providers.claude_cli.binary' => $fakeProvider,
                'atlas.ai.providers.codex_cli.binary' => $fakeProvider,
                'atlas.ai.providers.codex_cli.args' => ['exec', '--skip-git-repo-check'],
                'atlas.ai.providers.gemini_cli.binary' => $fakeProvider,
                'atlas.ai.providers.cursor_cli.binary' => '/tmp/atlas-rivals-cursor-missing',
                'atlas.ai.providers.cursor_cli.enabled' => true,
            ]);

            $dispatcher = app(AtlasForgeRivalsActionDispatcher::class);
            $response = $dispatcher->dispatch('arena-readiness', []);

            $cursor = collect($response['pairs'])->firstWhere('pair_id', 'cursor_default_vs_claude_sonnet');
            $composer = collect($response['pairs'])->firstWhere('pair_id', 'composer_2_5_vs_codex_gpt55');

            $this->assertSame('plan_ready_driver_missing', $cursor['status']);
            $this->assertContains('provider_binary_not_available:arm_a:cursor', $cursor['blockers']);
            $this->assertSame('plan_ready_driver_missing', $composer['status']);
            $this->assertContains('provider_binary_not_available:arm_a:composer', $composer['blockers']);
        } finally {
            config([
                'atlas.ai.providers.claude_cli.binary' => $oldClaudeBinary,
                'atlas.ai.providers.codex_cli.binary' => $oldCodexBinary,
                'atlas.ai.providers.codex_cli.args' => $oldCodexArgs,
                'atlas.ai.providers.gemini_cli.binary' => $oldGeminiBinary,
                'atlas.ai.providers.cursor_cli.binary' => $oldCursorBinary,
                'atlas.ai.providers.cursor_cli.enabled' => $oldCursorEnabled,
            ]);
        }
    }

    public function test_arena_readiness_blocks_unsafe_cursor_cli_command_config_without_provider_spend(): void
    {
        $fakeProvider = $this->fakeProviderBinary();
        $oldClaudeBinary = config('atlas.ai.providers.claude_cli.binary');
        $oldCodexBinary = config('atlas.ai.providers.codex_cli.binary');
        $oldCodexArgs = config('atlas.ai.providers.codex_cli.args');
        $oldGeminiBinary = config('atlas.ai.providers.gemini_cli.binary');
        $oldCursorBinary = config('atlas.ai.providers.cursor_cli.binary');
        $oldCursorEnabled = config('atlas.ai.providers.cursor_cli.enabled');
        $oldForce = config('atlas.ai.providers.cursor_cli.force');
        $oldOutputFormat = config('atlas.ai.providers.cursor_cli.output_format');

        try {
            config([
                'atlas.ai.providers.claude_cli.binary' => $fakeProvider,
                'atlas.ai.providers.codex_cli.binary' => $fakeProvider,
                'atlas.ai.providers.codex_cli.args' => ['exec', '--skip-git-repo-check'],
                'atlas.ai.providers.gemini_cli.binary' => $fakeProvider,
                'atlas.ai.providers.cursor_cli.binary' => $fakeProvider,
                'atlas.ai.providers.cursor_cli.enabled' => true,
                'atlas.ai.providers.cursor_cli.force' => true,
                'atlas.ai.providers.cursor_cli.output_format' => 'stream-json',
            ]);

            $dispatcher = app(AtlasForgeRivalsActionDispatcher::class);
            $response = $dispatcher->dispatch('arena-readiness', []);

            $cursor = collect($response['pairs'])->firstWhere('pair_id', 'cursor_default_vs_claude_sonnet');
            $composer = collect($response['pairs'])->firstWhere('pair_id', 'composer_2_5_vs_codex_gpt55');
            $this->assertSame('blocked', $cursor['status']);
            $this->assertSame('blocked', $composer['status']);
            $this->assertFalse($cursor['dry_run_ready']);
            $this->assertFalse($composer['dry_run_ready']);
            $this->assertContains('arm_a_command_cursor_cli_force_mode_forbidden', $cursor['blockers']);
            $this->assertContains('arm_a_command_cursor_cli_force_mode_forbidden', $composer['blockers']);
            $this->assertFalse($response['external_provider_call']);
            $this->assertFalse($response['provider_tokens_spent']);

            config([
                'atlas.ai.providers.cursor_cli.force' => false,
                'atlas.ai.providers.cursor_cli.output_format' => 'json',
            ]);

            $response = $dispatcher->dispatch('arena-readiness', []);
            $cursor = collect($response['pairs'])->firstWhere('pair_id', 'cursor_default_vs_claude_sonnet');
            $composer = collect($response['pairs'])->firstWhere('pair_id', 'composer_2_5_vs_codex_gpt55');
            $this->assertSame('blocked', $cursor['status']);
            $this->assertSame('blocked', $composer['status']);
            $this->assertContains('arm_a_command_cursor_cli_output_format_must_be_stream_json', $cursor['blockers']);
            $this->assertContains('arm_a_command_cursor_cli_output_format_must_be_stream_json', $composer['blockers']);
            $this->assertFalse($response['external_provider_call']);
            $this->assertFalse($response['provider_tokens_spent']);
        } finally {
            config([
                'atlas.ai.providers.claude_cli.binary' => $oldClaudeBinary,
                'atlas.ai.providers.codex_cli.binary' => $oldCodexBinary,
                'atlas.ai.providers.codex_cli.args' => $oldCodexArgs,
                'atlas.ai.providers.gemini_cli.binary' => $oldGeminiBinary,
                'atlas.ai.providers.cursor_cli.binary' => $oldCursorBinary,
                'atlas.ai.providers.cursor_cli.enabled' => $oldCursorEnabled,
                'atlas.ai.providers.cursor_cli.force' => $oldForce,
                'atlas.ai.providers.cursor_cli.output_format' => $oldOutputFormat,
            ]);
        }
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
        $this->skipStubbedRealPipelineWhenWorktreeDiskIsInsufficient();

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
            $report = app(AtlasForgeRivalsReportService::class)->render([
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
        $this->skipStubbedRealPipelineWhenWorktreeDiskIsInsufficient();

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
        $this->skipStubbedRealPipelineWhenWorktreeDiskIsInsufficient();

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
        $this->skipStubbedRealPipelineWhenWorktreeDiskIsInsufficient();

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
        $this->skipStubbedRealPipelineWhenWorktreeDiskIsInsufficient();

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
        $this->skipStubbedRealPipelineWhenWorktreeDiskIsInsufficient();

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
        $this->skipStubbedRealPipelineWhenWorktreeDiskIsInsufficient();

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
        $cert = (new AtlasForgeRivalsOperatorBatteryCertification)->evaluate();
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
        $this->assertSame(10, $response['arm_count']);
        $this->assertCount(9, $response['task_categories']);
    }

    public function test_runners_alias_exposes_registry_snapshot_with_advisory_only_invariants(): void
    {
        $dispatcher = app(AtlasForgeRivalsActionDispatcher::class);
        $response = $dispatcher->dispatch('runners', []);

        $this->assertSame('arms', $response['action']);
        $this->assertSame('atlas.forge.rivals.runner_registry.v1', $response['schema_version']);
        $this->assertSame(10, $response['arm_count']);
        $this->assertFalse($response['external_provider_call']);
        $this->assertFalse($response['provider_tokens_spent']);
        $this->assertTrue($response['separated_from_external_rivals_certification']);
        $this->assertTrue($response['advisory_only']);
        $this->assertFalse($response['should_update_provider_topology']);
        $this->assertTrue($response['never_changes_atlas_decide_topology']);
        $this->assertSame('atlas_decide', $response['owner_of_model_routing']);
        $this->assertSame('none', $response['routing_effect']);
    }

    public function test_unknown_action_preserves_advisory_only_invariants(): void
    {
        $dispatcher = app(AtlasForgeRivalsActionDispatcher::class);
        $response = $dispatcher->dispatch('definitely-not-a-rivals-action', []);

        $this->assertSame('error', $response['status']);
        $this->assertSame('unknown_action', $response['error_code']);
        $this->assertFalse($response['external_provider_call']);
        $this->assertFalse($response['provider_tokens_spent']);
        $this->assertTrue($response['separated_from_external_rivals_certification']);
        $this->assertTrue($response['advisory_only']);
        $this->assertFalse($response['should_update_provider_topology']);
        $this->assertTrue($response['never_changes_atlas_decide_topology']);
        $this->assertSame('atlas_decide', $response['owner_of_model_routing']);
        $this->assertSame('none', $response['routing_effect']);
    }

    public function test_arena_core_certification_reaches_available(): void
    {
        $cert = (new AtlasForgeRivalsProviderArenaCoreCertification)->evaluate();

        $this->assertSame(
            AtlasForgeRivalsProviderArenaCoreCertification::STATUS_AVAILABLE,
            $cert['status'],
            'Provider Arena Core cert must be available once arena layer is wired.'
        );
        $this->assertCount(19, $cert['invariants']);
        foreach ([
            'provider_model_registry_available',
            'models_action_exposes_registry',
            'arm_command_builder_centralized',
            'provider_arena_modes_declared',
            'provider_arena_real_executor_wired',
            'arena_contracts_flow_to_manifest_report_signal',
            'cursor_composer_meta_provider_command_shape_locked',
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
        $this->assertFalse($payload['external_provider_call']);
        $this->assertFalse($payload['provider_tokens_spent']);
        $this->assertTrue($payload['separated_from_external_rivals_certification']);
        $this->assertTrue($payload['advisory_only']);
        $this->assertFalse($payload['should_update_provider_topology']);
        $this->assertTrue($payload['never_changes_atlas_decide_topology']);
        $this->assertSame('atlas_decide', $payload['owner_of_model_routing']);
        $this->assertSame('none', $payload['routing_effect']);
    }

    public function test_run_arena_artisan_invocation_emits_canonical_envelope(): void
    {
        $this->skipStubbedRealPipelineWhenWorktreeDiskIsInsufficient();

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

    public function test_public_cli_json_surfaces_preserve_next_runner_contract_without_provider_call(): void
    {
        $commands = [
            'arms' => [
                'args' => [
                    'action' => 'arms',
                    '--json' => true,
                ],
                'assert' => function (array $payload): void {
                    $this->assertSame('ok', $payload['status']);
                    $this->assertArrayHasKey('cursor_cli', $payload['arms']);
                    $this->assertArrayHasKey('composer_2_5', $payload['arms']);
                    $this->assertSame(10, $payload['arm_count']);
                },
            ],
            'models' => [
                'args' => [
                    'action' => 'models',
                    '--json' => true,
                ],
                'assert' => function (array $payload): void {
                    $this->assertSame('ok', $payload['status']);
                    $this->assertSame('atlas.ai.providers.cursor_cli.model', data_get($payload, 'providers.cursor.models.default.model_id_config_key'));
                    $this->assertSame(
                        'atlas.ai.providers.cursor_cli.composer_2_5_model',
                        $payload['providers']['composer']['models']['composer-2.5']['model_id_config_key'] ?? null,
                    );
                    $this->assertContains(
                        'composer_2_5',
                        $payload['providers']['cursor']['models']['composer-2.5']['aliases'] ?? [],
                    );
                },
            ],
            'runners' => [
                'args' => [
                    'action' => 'runners',
                    '--json' => true,
                ],
                'assert' => function (array $payload): void {
                    $this->assertSame('ok', $payload['status']);
                    $this->assertArrayHasKey('cursor_cli', $payload['arms']);
                    $this->assertArrayHasKey('composer_2_5', $payload['arms']);
                },
            ],
            'arena-readiness' => [
                'args' => [
                    'action' => 'arena-readiness',
                    '--json' => true,
                ],
                'assert' => function (array $payload): void {
                    $this->assertSame('ok', $payload['status']);
                    $pair = collect($payload['pairs'])->firstWhere('pair_id', 'cursor_default_vs_claude_sonnet');
                    $this->assertSame('cursor_cli', data_get($pair, 'arm_a.resolved_arm'));
                    $this->assertSame('default', data_get($pair, 'arm_a.model_alias'));
                    $this->assertSame('stdin', data_get($pair, 'command_plan.arm_a.prompt_transport'));
                    $this->assertTrue(data_get($pair, 'command_plan.arm_a.command_shape_summary.governed_cursor_cli_shape'));
                },
            ],
            'run-arena-cursor-dry-run' => [
                'args' => [
                    'action' => 'run-arena',
                    '--arm-a' => 'cursor_cli',
                    '--arm-a-model' => 'default',
                    '--arm-b' => 'claude_code',
                    '--arm-b-model' => 'sonnet',
                    '--mode' => 'provider_arena',
                    '--task-category' => 'bugfix',
                    '--dry-run' => true,
                    '--json' => true,
                ],
                'assert' => function (array $payload): void {
                    $this->assertSame('ok', $payload['status']);
                    $this->assertSame('arena_plan_ready', $payload['verdict']);
                    $this->assertSame('cursor_cli', data_get($payload, 'arm_a.resolved_arm'));
                    $this->assertSame('default', data_get($payload, 'arm_a.model_alias'));
                    $this->assertSame('cursor_cli', data_get($payload, 'arm_a.command_builder'));
                    $this->assertSame('stdin', data_get($payload, 'command_plan.arm_a.prompt_transport'));
                    $this->assertTrue(data_get($payload, 'command_plan.arm_a.command_shape_summary.governed_cursor_cli_shape'));
                    $this->assertNotContains('<prompt>', data_get($payload, 'command_plan.arm_a.command'));
                },
            ],
        ];

        foreach ($commands as $name => $command) {
            Artisan::call('atlas:forge:rivals', $command['args']);
            $payload = json_decode(Artisan::output(), true);

            $this->assertIsArray($payload, "Command {$name} must emit JSON.");
            $this->assertFalse($payload['external_provider_call'] ?? true, "Command {$name} must not call providers.");
            $this->assertFalse($payload['provider_tokens_spent'] ?? true, "Command {$name} must not spend tokens.");
            $this->assertTrue($payload['advisory_only'] ?? false, "Command {$name} must stay advisory-only.");
            $this->assertFalse($payload['should_update_provider_topology'] ?? true, "Command {$name} must not update topology.");
            $this->assertTrue($payload['never_changes_atlas_decide_topology'] ?? false, "Command {$name} must never change Atlas Decide topology.");
            $this->assertSame('atlas_decide', $payload['owner_of_model_routing'] ?? null, "Command {$name} must keep Atlas Decide as routing owner.");
            $this->assertSame('none', $payload['routing_effect'] ?? null, "Command {$name} must have no routing effect.");

            $command['assert']($payload);
        }
    }

    /**
     * @param  list<string>  $hardFailures
     */
    private function writeReadinessCoverageRun(string $runsRoot, string $runId, string $verdict, array $hardFailures): void
    {
        $evidence = $runsRoot.'/'.$runId.'/evidence';
        @mkdir($evidence, 0o755, true);

        file_put_contents($evidence.'/manifest.json', json_encode([
            'run_id' => $runId,
            'mode' => 'provider_arena',
            'case_id' => 'ceiling-360-001-industrial-005-incident_rollback',
            'verdict' => $verdict,
            'external_provider_call' => true,
            'provider_tokens_spent' => true,
            'arena_contracts' => [
                'arm_a' => [
                    'arm_id' => 'atlas_forge',
                    'model_alias' => 'sonnet',
                    'requested_model' => 'sonnet',
                ],
                'arm_b' => [
                    'arm_id' => 'claude_code',
                    'model_alias' => 'sonnet',
                    'requested_model' => 'sonnet',
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        file_put_contents($evidence.'/scorecard.json', json_encode([
            'run_id' => $runId,
            'winner' => $hardFailures === [] ? 'human_review_required_tie' : null,
            'atlas_score' => $hardFailures === [] ? 80.0 : null,
            'rival_score' => $hardFailures === [] ? 80.0 : null,
            'replay_passes' => true,
            'hard_failures' => $hardFailures,
            'claim_ready' => false,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function whichBinary(string $binary): string
    {
        $proc = Process::fromShellCommandline('which '.escapeshellarg($binary));
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

    private function skipStubbedRealPipelineWhenWorktreeDiskIsInsufficient(): void
    {
        $free = @disk_free_space(sys_get_temp_dir());
        if ($free === false) {
            $this->markTestSkipped('Cannot probe free disk space for worktree-heavy Provider Arena pipeline test.');
        }

        $minimum = (int) config(
            'atlas_rivals.min_free_bytes_before_worktree_add',
            env('ATLAS_FORGE_RIVALS_MIN_FREE_BYTES_BEFORE_WORKTREE_ADD', 1073741824),
        );
        if ($minimum > 0 && $free < $minimum) {
            $this->markTestSkipped(sprintf(
                'Skipping worktree-heavy Provider Arena pipeline test: free_bytes=%d required_bytes=%d.',
                (int) $free,
                $minimum,
            ));
        }
    }
}

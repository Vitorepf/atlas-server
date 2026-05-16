<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming;

use App\Services\Ai\Kernel\Architecture\AtlasForgeRivalsProviderArenaCoreCertification;
use App\Services\Ai\Programming\ForgeRivals\Arms\AtlasForgeRivalsArmRegistryService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsActionDispatcher;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Atlas Forge Rivals · Provider Arena Core v1 contract tests.
 *
 * Asserts the canonical arm registry, the run-arena dispatcher path,
 * task-category gating, scripted/manual safety, full_power gates and
 * the new cert read-model. NO provider is ever invoked.
 */
final class AtlasForgeRivalsProviderArenaCoreTest extends TestCase
{
    public function test_registry_lists_canonical_arms_including_atlas_dev_light(): void
    {
        $registry = app(AtlasForgeRivalsArmRegistryService::class);
        $arms = $registry->arms();

        $this->assertCount(8, $arms);
        $this->assertSame(
            ['atlas_forge', 'atlas_dev_light', 'claude_code', 'codex_cli', 'gemini_cli', 'scripted_runner', 'manual_runner', 'future_runner'],
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

    public function test_atlas_dev_light_is_declared_as_lightweight_sonnet_arm_with_honest_real_run_blocker(): void
    {
        $registry = app(AtlasForgeRivalsArmRegistryService::class);
        $arm = $registry->arm('atlas_dev_light');

        $this->assertSame('atlas_dev_light', $arm['arm_id']);
        $this->assertSame('atlas_dev', $arm['runner_type']);
        $this->assertSame('claude', $arm['provider']);
        $this->assertSame(['sonnet', 'claude_sonnet'], $arm['model_options']);
        $this->assertSame('not_yet_executable', $arm['status']);
        $this->assertSame('atlas_dev_light_driver_pending', $arm['not_executable_reason']);
        $this->assertTrue($arm['safety_contract']['escalates_to_forge_on_high_risk']);
        $this->assertTrue($arm['safety_contract']['keeps_call_budget_low']);
    }

    public function test_atlas_dev_light_can_join_local_fake_corpus_dry_run_without_provider_spend(): void
    {
        $dispatcher = app(AtlasForgeRivalsActionDispatcher::class);
        $response = $dispatcher->dispatch('run-arena', [
            'arm_a' => 'atlas_dev_light',
            'arm_b' => 'claude_code',
            'arm_a_model' => 'sonnet',
            'arm_b_model' => 'sonnet',
            'case_set' => 'quick',
            'mode' => 'local_fake',
            'confirmations' => ['runbook_reviewed' => false, 'provider_cost' => false, 'real_provider_call' => false],
        ]);

        $this->assertSame('ok', $response['status']);
        $this->assertTrue($response['corpus_dry_run']);
        $this->assertSame('atlas_dev_light', $response['arm_a']['arm_id']);
        $this->assertSame('atlas_dev', $response['arm_a']['runner_type']);
        $this->assertFalse($response['external_provider_call']);
        $this->assertFalse($response['provider_tokens_spent']);
    }

    public function test_atlas_dev_light_real_run_blocks_until_driver_exists(): void
    {
        $dispatcher = app(AtlasForgeRivalsActionDispatcher::class);
        $response = $dispatcher->dispatch('run-arena', [
            'arm_a' => 'atlas_dev_light',
            'arm_b' => 'claude_code',
            'arm_a_model' => 'sonnet',
            'arm_b_model' => 'sonnet',
            'task_category' => 'bugfix',
            'mode' => 'fair',
            'preset' => 'quick',
            'confirmations' => [
                'runbook_reviewed' => true,
                'provider_cost' => true,
                'real_provider_call' => true,
            ],
        ]);

        $this->assertSame('blocked', $response['status']);
        $this->assertContains('arm_runner_not_yet_executable:atlas_dev_light', $response['blockers']);
        $this->assertFalse($response['external_provider_call']);
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
        $this->assertCount(12, $cert['invariants']);
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
}

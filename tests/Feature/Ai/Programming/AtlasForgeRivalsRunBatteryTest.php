<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming;

use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsActionDispatcher;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsAdjudicatorService;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Atlas Forge Rivals · `run-battery` single-button orchestrator contract tests.
 *
 * Focus: every gate the run-battery contract promises:
 *   - real-provider modes refuse to start without all three --confirm-* flags
 *   - codex blocker is honest when binary missing
 *   - mode aliases (power → full_power) and model aliases (sonnet/opus) work
 *   - dispatcher accepts run-battery and adjudicate
 *
 * No provider is invoked in these tests; we never pass the three
 * confirmations, so the pipeline halts before run-real for the real modes.
 */
final class AtlasForgeRivalsRunBatteryTest extends TestCase
{
    public function test_run_battery_requires_all_three_confirmations_in_fair_mode(): void
    {
        $dispatcher = app(AtlasForgeRivalsActionDispatcher::class);

        $response = $dispatcher->dispatch('run-battery', [
            'mode' => 'fair',
            'atlas_model' => 'sonnet',
            'rival' => 'claude_sonnet',
            'preset' => 'quick',
            'confirmations' => [
                'runbook_reviewed' => true,
                'provider_cost' => true,
                'real_provider_call' => false,
            ],
        ]);

        $this->assertIsArray($response);
        $this->assertSame('run-battery', $response['action']);
        $this->assertSame('blocked', $response['status']);
        $this->assertContains('missing_confirmation:real_provider_call', $response['blockers']);
        $this->assertFalse($response['external_provider_call']);
        $this->assertFalse($response['provider_tokens_spent']);
        $this->assertNull($response['winner']);
    }

    public function test_run_battery_requires_all_three_confirmations_in_full_power_mode(): void
    {
        $dispatcher = app(AtlasForgeRivalsActionDispatcher::class);

        $response = $dispatcher->dispatch('run-battery', [
            'mode' => 'full_power',
            'atlas_model' => 'opus',
            'rival' => 'claude_opus',
            'preset' => 'release',
            'confirmations' => [
                'runbook_reviewed' => false,
                'provider_cost' => false,
                'real_provider_call' => false,
            ],
        ]);

        $this->assertSame('blocked', $response['status']);
        foreach (['runbook_reviewed', 'provider_cost', 'real_provider_call'] as $required) {
            $this->assertContains('missing_confirmation:'.$required, $response['blockers']);
        }
    }

    public function test_run_battery_accepts_power_mode_alias_normalising_to_full_power(): void
    {
        $dispatcher = app(AtlasForgeRivalsActionDispatcher::class);

        $response = $dispatcher->dispatch('run-battery', [
            'mode' => 'power',
            'atlas_model' => 'sonnet',
            'rival' => 'claude_sonnet',
            'preset' => 'quick',
            'confirmations' => [
                'runbook_reviewed' => false,
                'provider_cost' => false,
                'real_provider_call' => false,
            ],
        ]);

        // `power` is accepted (no `mode_not_admissible_for_run_battery`) and
        // still demands the three confirmations.
        $this->assertSame('blocked', $response['status']);
        $this->assertSame('full_power', $response['mode']);
        $this->assertNotContains('mode_not_admissible_for_run_battery:power', $response['blockers']);
    }

    public function test_run_battery_rejects_unknown_mode_honestly(): void
    {
        $dispatcher = app(AtlasForgeRivalsActionDispatcher::class);

        $response = $dispatcher->dispatch('run-battery', [
            'mode' => 'turbo',
            'atlas_model' => 'sonnet',
            'rival' => 'claude_sonnet',
            'preset' => 'quick',
            'confirmations' => ['runbook_reviewed' => false, 'provider_cost' => false, 'real_provider_call' => false],
        ]);

        $this->assertSame('blocked', $response['status']);
        $this->assertContains('mode_not_admissible_for_run_battery:turbo', $response['blockers']);
    }

    public function test_run_battery_blocks_with_codex_driver_when_binary_missing(): void
    {
        if ($this->whichBinary('codex') !== '') {
            $this->markTestSkipped('codex CLI is installed; cannot exercise the honest blocker path.');
        }

        $dispatcher = app(AtlasForgeRivalsActionDispatcher::class);
        $response = $dispatcher->dispatch('run-battery', [
            'mode' => 'full_power',
            'atlas_model' => 'sonnet',
            'rival' => 'codex',
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

    public function test_adjudicate_action_requires_run_id(): void
    {
        $dispatcher = app(AtlasForgeRivalsActionDispatcher::class);
        $response = $dispatcher->dispatch('adjudicate', []);

        $this->assertSame('adjudicate', $response['action']);
        $this->assertSame('blocked', $response['status']);
        $this->assertContains('run_id_required', $response['blockers']);
    }

    public function test_adjudicate_action_alias_score_is_routed_to_adjudicate(): void
    {
        $dispatcher = app(AtlasForgeRivalsActionDispatcher::class);
        $response = $dispatcher->dispatch('score', []);

        $this->assertSame('adjudicate', $response['action']);
    }

    public function test_run_battery_action_alias_battery_is_routed_to_run_battery(): void
    {
        $dispatcher = app(AtlasForgeRivalsActionDispatcher::class);
        $response = $dispatcher->dispatch('battery', [
            'mode' => 'fair',
            'atlas_model' => 'sonnet',
            'rival' => 'claude_sonnet',
            'preset' => 'quick',
            'confirmations' => ['runbook_reviewed' => false, 'provider_cost' => false, 'real_provider_call' => false],
        ]);

        $this->assertSame('run-battery', $response['action']);
    }

    public function test_artisan_invocation_emits_envelope_for_run_battery_action(): void
    {
        Artisan::call('atlas:forge:rivals', [
            'action' => 'run-battery',
            '--mode' => 'fair',
            '--atlas-model' => 'sonnet',
            '--rival' => 'claude_sonnet',
            '--preset' => 'quick',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true);
        $this->assertIsArray($payload);
        $this->assertSame('run-battery', $payload['action']);
        $this->assertSame('atlas.forge.rivals.action_response.v1', $payload['schema_version']);
        $this->assertSame('blocked', $payload['status']);
        $this->assertFalse($payload['external_provider_call']);
    }

    public function test_adjudicator_winner_constants_are_canonical(): void
    {
        $this->assertSame('atlas', AtlasForgeRivalsAdjudicatorService::WINNER_ATLAS);
        $this->assertSame('rival', AtlasForgeRivalsAdjudicatorService::WINNER_RIVAL);
        $this->assertSame('human_review_required_tie', AtlasForgeRivalsAdjudicatorService::WINNER_TIE);
        $this->assertNull(AtlasForgeRivalsAdjudicatorService::WINNER_NONE);
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

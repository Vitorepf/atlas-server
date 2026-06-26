<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming;

use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsActionDispatcher;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsAdjudicatorService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
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
    /**
     * Methods that provision rivals worktrees against the LIVE repo — forbidden
     * under PHPUnit by the live-repo guard in AtlasForgeRivalsSetupService
     * (operator 2026-06-25). Skipped: rivals is a disabled/parked system.
     *
     * @var list<string>
     */
    private const LIVE_REPO_PROVISIONING_TESTS = [
        'test_run_battery_local_fake_reaches_comparable_tie_with_real_evidence',
        'test_run_real_enforces_provider_idle_timeout_without_masking_it_as_heartbeat',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        if (in_array($this->name(), self::LIVE_REPO_PROVISIONING_TESTS, true)) {
            $this->markTestSkipped('forge-rivals live-repo provisioning is forbidden under tests (rivals disabled); see live-repo guard in AtlasForgeRivalsSetupService.');
        }
    }

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

    public function test_run_battery_local_fake_reaches_comparable_tie_with_real_evidence(): void
    {
        $this->skipWorktreeHeavyBatteryTestWhenDiskIsInsufficient();

        $dispatcher = app(AtlasForgeRivalsActionDispatcher::class);

        $response = $dispatcher->dispatch('run-battery', [
            'mode' => 'local_fake',
            'atlas_model' => 'sonnet',
            'rival' => 'claude_sonnet',
            'preset' => 'quick',
            'confirmations' => ['runbook_reviewed' => false, 'provider_cost' => false, 'real_provider_call' => false],
        ]);

        $this->assertSame('ok', $response['status']);
        $this->assertSame('local_fake', $response['mode']);
        $this->assertSame(12, $response['phases_passed']);
        $this->assertSame(0, $response['phases_failed']);
        $this->assertSame(AtlasForgeRivalsAdjudicatorService::WINNER_TIE, $response['winner']);
        $this->assertSame([], $response['scorecard']['hard_failures']);
        $this->assertFalse($response['scorecard']['claim_ready']);
        $this->assertTrue($response['scorecard']['human_review_required']);
        $this->assertFalse($response['external_provider_call']);
        $this->assertFalse($response['provider_tokens_spent']);
        $this->assertFileExists($response['report_path']);
    }

    public function test_run_real_enforces_provider_idle_timeout_without_masking_it_as_heartbeat(): void
    {
        $this->skipWorktreeHeavyBatteryTestWhenDiskIsInsufficient();

        $dispatcher = app(AtlasForgeRivalsActionDispatcher::class);
        $runId = 'rivals-timeout-'.Str::lower(Str::random(8));
        $binDir = sys_get_temp_dir().'/atlas-rivals-fake-bin-'.Str::lower(Str::random(8));
        @mkdir($binDir, 0o755, true);
        $fakeClaude = $binDir.'/claude';
        file_put_contents($fakeClaude, "#!/usr/bin/env bash\nsleep 10\nexit 0\n");
        chmod($fakeClaude, 0o755);

        $oldPath = getenv('PATH') ?: '';
        $oldServerPath = $_SERVER['PATH'] ?? null;
        $oldProviderTimeout = getenv('ATLAS_FORGE_RIVALS_PROVIDER_TIMEOUT_SECONDS') ?: false;
        $oldHardKill = getenv('ATLAS_FORGE_RIVALS_HARD_KILL_SECONDS') ?: false;

        try {
            putenv('PATH='.$binDir.':'.$oldPath);
            $_SERVER['PATH'] = $binDir.':'.$oldPath;
            config(['atlas.ai.providers.claude_cli.binary' => $fakeClaude]);
            putenv('ATLAS_FORGE_RIVALS_PROVIDER_TIMEOUT_SECONDS=5');
            putenv('ATLAS_FORGE_RIVALS_HARD_KILL_SECONDS=12');

            $setup = $dispatcher->dispatch('setup', [
                'run_id' => $runId,
                'source_ref' => 'HEAD',
            ]);
            $this->assertSame('ok', $setup['status'], json_encode($setup, JSON_PRETTY_PRINT));

            $response = $dispatcher->dispatch('run-real', [
                'mode' => 'fair',
                'atlas_model' => 'claude_sonnet',
                'rival' => 'claude_sonnet',
                'preset' => 'quick',
                'run_id' => $runId,
                'confirmations' => [
                    'runbook_reviewed' => true,
                    'provider_cost' => true,
                    'real_provider_call' => true,
                ],
            ]);

            $this->assertSame('ok', $response['status']);
            $this->assertSame('invalid_provider_timeout', $response['verdict']);
            $this->assertTrue($response['atlas_receipt']['killed']);
            $this->assertSame('idle_timeout', $response['atlas_receipt']['timeout_reason']);
            $this->assertSame(-1, $response['atlas_receipt']['test_exit_code']);
            $this->assertStringContainsString('SKIPPED: provider timed out', $response['atlas_receipt']['test_log_tail']);
            $this->assertFileExists($response['paths']['events_jsonl']);
            $events = (string) file_get_contents($response['paths']['events_jsonl']);
            $this->assertStringContainsString('"kind":"provider_timeout_warning"', $events);
        } finally {
            putenv('PATH='.$oldPath);
            $_SERVER['PATH'] = $oldServerPath ?? $oldPath;
            $oldProviderTimeout === false
                ? putenv('ATLAS_FORGE_RIVALS_PROVIDER_TIMEOUT_SECONDS')
                : putenv('ATLAS_FORGE_RIVALS_PROVIDER_TIMEOUT_SECONDS='.$oldProviderTimeout);
            $oldHardKill === false
                ? putenv('ATLAS_FORGE_RIVALS_HARD_KILL_SECONDS')
                : putenv('ATLAS_FORGE_RIVALS_HARD_KILL_SECONDS='.$oldHardKill);
        }
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

    private function skipWorktreeHeavyBatteryTestWhenDiskIsInsufficient(): void
    {
        $free = @disk_free_space(sys_get_temp_dir());
        if ($free === false) {
            $this->markTestSkipped('Cannot probe free disk space for worktree-heavy Rivals battery test.');
        }

        $minimum = (int) config(
            'atlas_rivals.min_free_bytes_before_worktree_add',
            env('ATLAS_FORGE_RIVALS_MIN_FREE_BYTES_BEFORE_WORKTREE_ADD', 1073741824),
        );
        if ($minimum > 0 && $free < $minimum) {
            $this->markTestSkipped(sprintf(
                'Skipping worktree-heavy Rivals battery test: free_bytes=%d required_bytes=%d.',
                (int) $free,
                $minimum,
            ));
        }
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
}

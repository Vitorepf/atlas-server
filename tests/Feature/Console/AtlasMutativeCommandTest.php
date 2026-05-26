<?php

namespace Tests\Feature\Console;

use App\Console\Commands\AtlasMutativeCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * Atlas Cognition Operating System — Absorcao 3 (Doctor 3-Tier).
 *
 * Cobre o pattern AtlasMutativeCommand via probe class concreta.
 * Invocacao direta via BufferedOutput evita o pipeline de registro Artisan
 * (probe class vive somente em teste, nao em app/Console/Commands).
 */
class AtlasMutativeCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        MutativeProbeCommand::$applyCount = 0;
        MutativeProbeCommand::$dryRunCount = 0;
    }

    public function test_plan_mode_emits_planned_status_without_executing(): void
    {
        $env = $this->runProbe(['--mode' => 'plan', '--json' => true]);

        $this->assertSame('atlas.command.three_tier_envelope.v1', $env['schema_version']);
        $this->assertSame('atlas:probe', $env['command_name']);
        $this->assertSame('plan', $env['mode']);
        $this->assertSame('planned', $env['status']);
        $this->assertIsArray($env['actions']);
        $this->assertSame(['plan-action-1', 'plan-action-2'], $env['actions']);
        $this->assertSame('probe-rollback-uri', $env['rollback_ref']);
        $this->assertSame(0, MutativeProbeCommand::$applyCount);
    }

    public function test_dry_run_executes_and_rolls_back(): void
    {
        $env = $this->runProbe([
            '--mode' => 'dry-run',
            '--check' => 'probe-readiness',
            '--json' => true,
        ]);

        $this->assertSame('dry_run_ok', $env['status']);
        $this->assertSame(1, MutativeProbeCommand::$dryRunCount);
        $this->assertSame(0, MutativeProbeCommand::$applyCount);
    }

    public function test_dry_run_missing_check_blocks(): void
    {
        $env = $this->runProbe(['--mode' => 'dry-run', '--json' => true]);

        $this->assertSame('blocked_missing_check', $env['status']);
        $this->assertSame(['probe-readiness'], $env['available_checks']);
    }

    public function test_dry_run_invalid_check_blocks(): void
    {
        $env = $this->runProbe([
            '--mode' => 'dry-run',
            '--check' => 'unknown-code',
            '--json' => true,
        ]);

        $this->assertSame('blocked_invalid_check', $env['status']);
    }

    public function test_apply_without_confirm_blocks(): void
    {
        $env = $this->runProbe([
            '--mode' => 'apply',
            '--check' => 'probe-readiness',
            '--json' => true,
        ]);

        $this->assertSame('blocked_not_confirmed', $env['status']);
        $this->assertSame(0, MutativeProbeCommand::$applyCount);
    }

    public function test_apply_with_confirm_and_check_executes(): void
    {
        $env = $this->runProbe([
            '--mode' => 'apply',
            '--check' => 'probe-readiness',
            '--confirm' => true,
            '--json' => true,
        ]);

        $this->assertSame('applied', $env['status']);
        $this->assertSame(1, MutativeProbeCommand::$applyCount);
    }

    public function test_invalid_mode_blocks(): void
    {
        $env = $this->runProbe(['--mode' => 'destroy', '--json' => true]);

        $this->assertSame('blocked_invalid_mode', $env['status']);
    }

    public function test_envelope_hash_is_deterministic(): void
    {
        $env1 = $this->runProbe(['--mode' => 'plan', '--json' => true]);
        $env2 = $this->runProbe(['--mode' => 'plan', '--json' => true]);

        $this->assertSame($env1['envelope_hash'], $env2['envelope_hash']);
    }

    public function test_envelope_hash_changes_when_mode_changes(): void
    {
        $planEnv = $this->runProbe(['--mode' => 'plan', '--json' => true]);
        $dryEnv = $this->runProbe([
            '--mode' => 'dry-run',
            '--check' => 'probe-readiness',
            '--json' => true,
        ]);

        $this->assertNotSame($planEnv['envelope_hash'], $dryEnv['envelope_hash']);
    }

    /**
     * Invoca probe command diretamente capturando output via BufferedOutput.
     */
    private function runProbe(array $options): array
    {
        $command = new MutativeProbeCommand;
        $command->setLaravel($this->app);

        $input = new ArrayInput($options);
        $input->setInteractive(false);

        $output = new BufferedOutput;
        $command->run($input, $output);

        $raw = trim($output->fetch());
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}

/**
 * Probe concrete class — used only in test scope.
 */
class MutativeProbeCommand extends AtlasMutativeCommand
{
    public static int $applyCount = 0;
    public static int $dryRunCount = 0;

    protected $signature = 'atlas:probe
        {--mode=plan}
        {--check=}
        {--confirm}
        {--json}';

    protected $description = 'Probe command for AtlasMutativeCommand pattern tests.';

    public function handle(): int
    {
        return $this->handleMutative();
    }

    protected function mutativeName(): string
    {
        return 'atlas:probe';
    }

    protected function availableCheckCodes(): array
    {
        return ['probe-readiness'];
    }

    protected function planActions(array $context): array
    {
        return ['plan-action-1', 'plan-action-2'];
    }

    protected function dryRunActions(array $context): array
    {
        self::$dryRunCount++;

        return ['dry_run_action' => 'simulated'];
    }

    protected function applyActions(array $context): array
    {
        self::$applyCount++;

        return ['apply_action' => 'executed'];
    }

    protected function rollbackRef(array $context): ?string
    {
        return 'probe-rollback-uri';
    }
}

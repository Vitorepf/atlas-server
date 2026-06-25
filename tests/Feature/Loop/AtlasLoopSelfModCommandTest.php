<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\SelfMod\AtlasLoopSelfModInvariantSurvivalChecker;
use App\Services\Ai\AutonomousEvolution\SelfMod\AtlasLoopSelfModProofReceiptLedger;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the atlas:loop:selfmod CLI: registered; classify emits JSON with kind/evidence/target_file; verify
 * with a stub checker returning VIOLATED exits non-zero with violations[]; history with --status=REJECTED
 * filters; unknown action is usage_error.
 */
final class AtlasLoopSelfModCommandTest extends TestCase
{
    private string $ledgerPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledgerPath = sys_get_temp_dir().'/atlas_selfmod_cli_'.bin2hex(random_bytes(6)).'.jsonl';
        $this->app->instance(AtlasLoopSelfModProofReceiptLedger::class, new AtlasLoopSelfModProofReceiptLedger($this->ledgerPath));
    }

    protected function tearDown(): void
    {
        @unlink($this->ledgerPath);
        parent::tearDown();
    }

    public function test_artisan_command_is_registered(): void
    {
        $this->assertArrayHasKey('atlas:loop:selfmod', Artisan::all());
    }

    public function test_classify_returns_json_with_kind_and_target_file(): void
    {
        // Use the CLI's own file as the fixture (exists on disk and the classifier accepts before='' / after=source).
        $exit = Artisan::call('atlas:loop:selfmod', [
            'action' => 'classify',
            '--file' => base_path('app/Console/Commands/AtlasLoopSelfModCommand.php'),
            '--against' => 'HEAD',
            '--json' => true,
        ]);
        $output = trim(Artisan::output());
        $decoded = json_decode($output, true);

        $this->assertSame(0, $exit);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('kind', $decoded);
        $this->assertArrayHasKey('evidence', $decoded);
        $this->assertArrayHasKey('target_file', $decoded);
    }

    public function test_verify_with_stub_checker_returning_violated_exits_non_zero_with_violations(): void
    {
        // Bind a stub checker that returns a REJECTED report with one violation.
        $stub = new class
        {
            public function check(): array
            {
                return [
                    'proof_status' => AtlasLoopSelfModProofReceiptLedger::PROOF_REJECTED,
                    'violations' => [['class' => 'App\\Demo\\X', 'invariant' => 'foo']],
                ];
            }
        };
        $this->app->instance(AtlasLoopSelfModInvariantSurvivalChecker::class, $stub);

        $exit = Artisan::call('atlas:loop:selfmod', ['action' => 'verify', '--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertNotSame(0, $exit, 'REJECTED ⇒ fail-closed exit code');
        $this->assertSame(AtlasLoopSelfModProofReceiptLedger::PROOF_REJECTED, $decoded['status']);
        $this->assertNotEmpty($decoded['violations']);
    }

    public function test_verify_with_approved_stub_exits_zero(): void
    {
        $stub = new class
        {
            public function check(): array
            {
                return ['proof_status' => AtlasLoopSelfModProofReceiptLedger::PROOF_APPROVED, 'violations' => []];
            }
        };
        $this->app->instance(AtlasLoopSelfModInvariantSurvivalChecker::class, $stub);

        $exit = Artisan::call('atlas:loop:selfmod', ['action' => 'verify', '--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertSame(AtlasLoopSelfModProofReceiptLedger::PROOF_APPROVED, $decoded['status']);
    }

    public function test_history_status_filter_returns_only_rejected(): void
    {
        $ledger = $this->app->make(AtlasLoopSelfModProofReceiptLedger::class);
        $ledger->record([
            'target_files' => ['app/A.php'],
            'edit_classification' => [],
            'invariant_survival_report' => [],
            'proof_status' => AtlasLoopSelfModProofReceiptLedger::PROOF_APPROVED,
            'frozen_judge_decision' => [],
            'cert_diff_decision' => [],
            'commit_sha' => 'sha-a',
        ]);
        $ledger->record([
            'target_files' => ['app/B.php'],
            'edit_classification' => [],
            'invariant_survival_report' => [],
            'proof_status' => AtlasLoopSelfModProofReceiptLedger::PROOF_REJECTED,
            'frozen_judge_decision' => [],
            'cert_diff_decision' => [],
            'commit_sha' => null,
        ]);

        $exit = Artisan::call('atlas:loop:selfmod', ['action' => 'history', '--status' => 'REJECTED', '--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertCount(1, $decoded);
        $this->assertSame('REJECTED', $decoded[0]['proof_status']);
        $this->assertSame(['app/B.php'], $decoded[0]['target_files']);
    }

    public function test_unknown_action_returns_usage_error(): void
    {
        $exit = Artisan::call('atlas:loop:selfmod', ['action' => 'bogus', '--json' => true]);
        $this->assertNotSame(0, $exit);
        $decoded = json_decode(trim(Artisan::output()), true);
        $this->assertSame('usage_error', $decoded['status']);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Console\Commands\AtlasLoopNetDiffCertCommand;
use App\Services\Ai\AutonomousEvolution\AtlasLoopNetDiffCertReceiptLedger;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Tests\TestCase;

final class AtlasLoopNetDiffCertCommandTest extends TestCase
{
    private string $ledgerPath = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledgerPath = sys_get_temp_dir().'/atlas-netdiff-cli-'.bin2hex(random_bytes(6)).'.jsonl';
        // In-memory (per-test-process) ledger pinned to a temp file.
        $this->app->instance(
            AtlasLoopNetDiffCertReceiptLedger::class,
            new AtlasLoopNetDiffCertReceiptLedger($this->ledgerPath),
        );
    }

    protected function tearDown(): void
    {
        @unlink($this->ledgerPath);
        parent::tearDown();
    }

    /**
     * Pin the measurement resolver to return canned sides yielding the requested verdict.
     */
    private function pinVerdict(string $verdict): void
    {
        $this->app->instance(AtlasLoopNetDiffCertCommand::MEASUREMENT_RESOLVER_KEY, function (string $certId, string $attemptId) use ($verdict): array {
            // metric_kind=count (minimize) — improvement = baseline - candidate.
            return match ($verdict) {
                'PASS' => [
                    'baseline_side' => ['metric_kind' => 'minimize', 'value' => 10.0, 'captured_at' => '2026-06-24T12:00:00+00:00', 'source_sha' => 'base'],
                    'candidate_side' => ['metric_kind' => 'minimize', 'value' => 5.0, 'captured_at' => '2026-06-24T12:01:00+00:00', 'source_sha' => 'cand'],
                ],
                'REGRESSED' => [
                    'baseline_side' => ['metric_kind' => 'minimize', 'value' => 5.0, 'captured_at' => '2026-06-24T12:00:00+00:00', 'source_sha' => 'base'],
                    'candidate_side' => ['metric_kind' => 'minimize', 'value' => 10.0, 'captured_at' => '2026-06-24T12:01:00+00:00', 'source_sha' => 'cand'],
                ],
                'FLAT' => [
                    'baseline_side' => ['metric_kind' => 'minimize', 'value' => 5.0, 'captured_at' => '2026-06-24T12:00:00+00:00', 'source_sha' => 'base'],
                    'candidate_side' => ['metric_kind' => 'minimize', 'value' => 5.0, 'captured_at' => '2026-06-24T12:01:00+00:00', 'source_sha' => 'cand'],
                ],
                default => [
                    // ABSTAIN — return empty sides so the Collector reports unarmed.
                    'baseline_side' => [],
                    'candidate_side' => [],
                ],
            };
        });
    }

    private function runCmd(array $args): array
    {
        $kernel = $this->app->make(ConsoleKernel::class);
        $exit = $kernel->call('atlas:loop:netdiff-cert', $args);

        return [$exit, $kernel->output()];
    }

    public function test_verify_pass_returns_exit_zero(): void
    {
        $this->pinVerdict('PASS');
        [$exit, $out] = $this->runCmd(['action' => 'verify', '--cert-id' => 'c-pass', '--attempt-id' => 'a1']);
        $this->assertSame(AtlasLoopNetDiffCertCommand::EXIT_OK, $exit, $out);
        $this->assertStringContainsString('verdict=PASS', $out);
    }

    public function test_verify_regressed_returns_exit_one(): void
    {
        $this->pinVerdict('REGRESSED');
        [$exit, $out] = $this->runCmd(['action' => 'verify', '--cert-id' => 'c-reg', '--attempt-id' => 'a1']);
        $this->assertSame(AtlasLoopNetDiffCertCommand::EXIT_REGRESSED, $exit, $out);
        $this->assertStringContainsString('verdict=REGRESSED', $out);
    }

    public function test_verify_flat_returns_exit_two(): void
    {
        $this->pinVerdict('FLAT');
        [$exit, $out] = $this->runCmd(['action' => 'verify', '--cert-id' => 'c-flat', '--attempt-id' => 'a1']);
        $this->assertSame(AtlasLoopNetDiffCertCommand::EXIT_FLAT, $exit, $out);
        $this->assertStringContainsString('verdict=FLAT', $out);
    }

    public function test_verify_abstain_returns_exit_three(): void
    {
        $this->pinVerdict('ABSTAIN');
        [$exit, $out] = $this->runCmd(['action' => 'verify', '--cert-id' => 'c-abs', '--attempt-id' => 'a1']);
        $this->assertSame(AtlasLoopNetDiffCertCommand::EXIT_ABSTAIN, $exit, $out);
        $this->assertStringContainsString('verdict=ABSTAIN', $out);
    }

    public function test_unknown_action_returns_usage_exit_ten(): void
    {
        [$exit] = $this->runCmd(['action' => 'bogus']);
        $this->assertSame(AtlasLoopNetDiffCertCommand::EXIT_USAGE, $exit);
    }

    public function test_verify_missing_required_options_returns_usage_exit_ten(): void
    {
        [$exit] = $this->runCmd(['action' => 'verify']);
        $this->assertSame(AtlasLoopNetDiffCertCommand::EXIT_USAGE, $exit);
    }

    public function test_history_prints_three_seeded_receipts_in_append_order(): void
    {
        $ledger = $this->app->make(AtlasLoopNetDiffCertReceiptLedger::class);
        foreach (['a1', 'a2', 'a3'] as $attempt) {
            $ledger->append([
                'cert_id' => 'c-hist',
                'attempt_id' => $attempt,
                'verdict' => 'PASS',
                'metric_kind' => 'minimize',
                'baseline_sha' => 'b'.$attempt,
                'candidate_sha' => 'c'.$attempt,
            ]);
        }

        [$exit, $out] = $this->runCmd(['action' => 'history', '--cert-id' => 'c-hist']);

        $this->assertSame(AtlasLoopNetDiffCertCommand::EXIT_OK, $exit);
        $lines = array_values(array_filter(explode("\n", trim($out))));
        $this->assertCount(3, $lines);
        $this->assertStringContainsString('attempt=a1', $lines[0]);
        $this->assertStringContainsString('attempt=a2', $lines[1]);
        $this->assertStringContainsString('attempt=a3', $lines[2]);
        $this->assertStringContainsString('prev=genesis', $lines[0]);
        $this->assertStringContainsString('chain=', $lines[1]);
    }
}

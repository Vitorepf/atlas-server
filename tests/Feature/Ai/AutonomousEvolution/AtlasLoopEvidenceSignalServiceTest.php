<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopEvidenceSignalService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * O-2 slice (b): the Evolution Loop discovers what actually breaks. The evidence signal
 * turns the REAL failure corpus into a per-file weight — files named by recurring real
 * failures outrank structurally-pretty files with none. Fail-open when the corpus is empty.
 */
final class AtlasLoopEvidenceSignalServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Schema::dropIfExists('failure_signatures');
        parent::tearDown();
    }

    private function createCorpus(): void
    {
        Schema::dropIfExists('failure_signatures');
        Schema::create('failure_signatures', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->text('context_summary');
            $table->timestamp('recorded_at');
        });
    }

    private function insert(string $summary, string $when = 'now'): void
    {
        DB::table('failure_signatures')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'context_summary' => $summary,
            'recorded_at' => $when === 'now' ? now() : now()->subDays(90),
        ]);
    }

    public function test_no_corpus_table_fails_open_to_empty(): void
    {
        Schema::dropIfExists('failure_signatures');
        $this->assertSame([], (new AtlasLoopEvidenceSignalService())->weights(['app/Services/Ai/Foo.php']));
    }

    public function test_recurring_failures_outweigh_one_off_failures(): void
    {
        $this->createCorpus();
        // PaymentReconciler fails 3x; InvoiceFormatter once.
        $this->insert('TypeError in app/Services/Billing/PaymentReconciler.php line 42');
        $this->insert('Null deref app/Services/Billing/PaymentReconciler.php during settle');
        $this->insert('PaymentReconciler.php overflow on negative amount');
        $this->insert('format glitch in app/Services/Billing/InvoiceFormatter.php');

        $w = (new AtlasLoopEvidenceSignalService())->weights([
            'app/Services/Billing/PaymentReconciler.php',
            'app/Services/Billing/InvoiceFormatter.php',
            'app/Services/Billing/UnseenService.php',
        ]);

        $this->assertSame(1.0, $w['app/Services/Billing/PaymentReconciler.php'], 'most-failing file normalizes to 1.0');
        $this->assertEqualsWithDelta(1 / 3, $w['app/Services/Billing/InvoiceFormatter.php'], 0.001);
        $this->assertArrayNotHasKey('app/Services/Billing/UnseenService.php', $w, 'a file with no failure evidence gets no weight');
    }

    public function test_stale_failures_outside_the_window_do_not_count(): void
    {
        $this->createCorpus();
        $this->insert('app/Services/Billing/PaymentReconciler.php failed', 'stale');

        $w = (new AtlasLoopEvidenceSignalService())->weights(['app/Services/Billing/PaymentReconciler.php']);

        $this->assertSame([], $w, 'failures older than the recency window are ignored');
    }

    public function test_short_generic_basenames_do_not_create_false_evidence(): void
    {
        $this->createCorpus();
        // "Foo.php" basename is too short/generic to match on basename alone.
        $this->insert('some unrelated text mentioning Foo.php in passing');

        $w = (new AtlasLoopEvidenceSignalService())->weights(['app/Services/Deep/Nested/Foo.php']);

        $this->assertSame([], $w, 'short non-distinctive basenames must not produce false evidence');
    }

    // ── evidenceSignals(): freshness/source_trust/runtime_proof/test_proof/receipt_proof/stale_veto_hint ──

    // ── AC: fresh runtime evidence ────────────────────────────────────────────────

    public function test_fresh_runtime_evidence_has_high_freshness_and_no_veto(): void
    {
        $signals = (new AtlasLoopEvidenceSignalService())->evidenceSignals([
            'app/Foo.php' => [
                'last_verified_days_ago' => 1,
                'source_trust' => 0.9,
                'has_runtime_proof' => true,
                'has_test_proof' => true,
            ],
        ]);

        $this->assertGreaterThan(0.9, $signals['app/Foo.php']['freshness']);
        $this->assertTrue($signals['app/Foo.php']['runtime_proof']);
        $this->assertFalse($signals['app/Foo.php']['stale_veto_hint']);
    }

    // ── AC: stale evidence ────────────────────────────────────────────────────────

    public function test_stale_evidence_without_runtime_proof_raises_veto_hint(): void
    {
        $signals = (new AtlasLoopEvidenceSignalService())->evidenceSignals([
            'app/Bar.php' => [
                'last_verified_days_ago' => 90,
                'has_runtime_proof' => false,
            ],
        ]);

        $this->assertSame(0.0, $signals['app/Bar.php']['freshness']);
        $this->assertTrue($signals['app/Bar.php']['stale_veto_hint']);
    }

    public function test_stale_evidence_with_runtime_proof_does_not_raise_veto(): void
    {
        $signals = (new AtlasLoopEvidenceSignalService())->evidenceSignals([
            'app/Baz.php' => [
                'last_verified_days_ago' => 90,
                'has_runtime_proof' => true,
            ],
        ]);

        $this->assertFalse($signals['app/Baz.php']['stale_veto_hint']);
    }

    // ── AC: receipt-only evidence ─────────────────────────────────────────────────

    public function test_receipt_only_evidence_marks_receipt_proof_but_still_vetoes(): void
    {
        $signals = (new AtlasLoopEvidenceSignalService())->evidenceSignals([
            'app/Qux.php' => ['has_receipt_proof' => true],
        ]);

        $this->assertTrue($signals['app/Qux.php']['receipt_proof']);
        $this->assertFalse($signals['app/Qux.php']['runtime_proof']);
        $this->assertFalse($signals['app/Qux.php']['test_proof']);
        $this->assertTrue($signals['app/Qux.php']['stale_veto_hint'], 'receipt alone is not runtime proof — still needs reproof');
    }

    // ── AC: missing evidence ──────────────────────────────────────────────────────

    public function test_missing_evidence_degrades_to_conservative_floor(): void
    {
        $signals = (new AtlasLoopEvidenceSignalService())->evidenceSignals(['app/Empty.php' => []]);

        $this->assertSame(0.0, $signals['app/Empty.php']['freshness']);
        $this->assertSame(0.0, $signals['app/Empty.php']['source_trust']);
        $this->assertFalse($signals['app/Empty.php']['runtime_proof']);
        $this->assertFalse($signals['app/Empty.php']['test_proof']);
        $this->assertFalse($signals['app/Empty.php']['receipt_proof']);
        $this->assertTrue($signals['app/Empty.php']['stale_veto_hint']);
    }

    // ── AC: deterministic path ordering ───────────────────────────────────────────

    public function test_evidence_signals_are_sorted_deterministically_by_path(): void
    {
        $signals = (new AtlasLoopEvidenceSignalService())->evidenceSignals([
            'zeta.php' => [],
            'alpha.php' => [],
            'mu.php' => [],
        ]);

        $this->assertSame(['alpha.php', 'mu.php', 'zeta.php'], array_keys($signals));
    }
}

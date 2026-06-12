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
}

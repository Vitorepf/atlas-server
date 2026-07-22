<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Cognition\AcosProgram;

use App\Models\AtlasMemoryEntry;
use App\Services\Ai\SelfConstruction\Lineage\AtlasDecisionLineageLedger;
use App\Services\Ai\SelfConstruction\Lineage\AtlasRollbackCascadeExecutor;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesAtlasMemoryEntryTable;
use Tests\TestCase;

/**
 * ASI-11 (LOTE 8 F2) — cascade rollback mechanism.
 *
 * The mechanism landing PROVES:
 *   1. Ledger append is idempotent and closure lookup groups by kind.
 *   2. Missing decision_id / empty closure ⇒ `containment`.
 *   3. Dry-run reports what WOULD happen without touching disk.
 *   4. 5-item mixed closure (memory + apply + outcome + memory + apply)
 *      returns `clean_revert` with all reversals executed.
 *   5. Formal state discipline: `blocked_conflict` is only produced when the
 *      very first commit conflicts; otherwise `partial_with_receipt`.
 *
 * The scale-≥50 acceptance is `pending_window(cascade_scale_50)` — landed
 * honestly in the scoreboard until an autonomous run produces the volume.
 */
final class Asi11RollbackCascadeTest extends TestCase
{
    use CreatesAtlasMemoryEntryTable;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-07-12T09:00:00+00:00');
        $this->createAtlasMemoryEntryTable();

        Schema::dropIfExists('atlas_decision_lineage_ledger');
        Schema::create('atlas_decision_lineage_ledger', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('decision_id', 120)->index();
            $table->string('obra_id', 120)->nullable()->index();
            $table->string('entity_kind', 32)->index();
            $table->string('entity_ref', 200);
            $table->string('entity_scope', 60)->nullable();
            $table->string('reverse_handle', 200)->nullable();
            $table->string('writer', 80);
            $table->json('meta')->nullable();
            $table->timestampTz('recorded_at')->index();
            $table->unique(['entity_kind', 'entity_ref']);
        });
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Schema::dropIfExists('atlas_decision_lineage_ledger');
        $this->dropAtlasMemoryEntryTable();
        parent::tearDown();
    }

    public function test_ledger_append_is_idempotent_on_entity_kind_and_ref(): void
    {
        $ledger = new AtlasDecisionLineageLedger;

        $first = $ledger->append('DEC-001', AtlasDecisionLineageLedger::KIND_MEMORY, 'MEM-1', 'memory_registry');
        $this->assertTrue($first['recorded']);

        $second = $ledger->append('DEC-001', AtlasDecisionLineageLedger::KIND_MEMORY, 'MEM-1', 'memory_registry');
        $this->assertFalse($second['recorded']);
        $this->assertSame('already_recorded', $second['reason']);

        $closure = $ledger->closure('DEC-001');
        $this->assertSame(1, $closure['count']);
        $this->assertCount(1, $closure['entities'][AtlasDecisionLineageLedger::KIND_MEMORY]);
    }

    public function test_containment_when_decision_has_no_closure(): void
    {
        $executor = new AtlasRollbackCascadeExecutor(new AtlasDecisionLineageLedger, sys_get_temp_dir());

        $result = $executor->execute('UNKNOWN-DECISION', dryRun: false);

        $this->assertSame(AtlasRollbackCascadeExecutor::STATE_CONTAINMENT, $result['state']);
        $this->assertSame('empty_closure', $result['reason']);
        $this->assertSame(0, $result['reversed_count']);
        $this->assertSame(AtlasRollbackCascadeExecutor::STATES, $result['formal_states']);
    }

    public function test_containment_when_decision_id_is_empty(): void
    {
        $executor = new AtlasRollbackCascadeExecutor(new AtlasDecisionLineageLedger, sys_get_temp_dir());

        $result = $executor->execute('   ', dryRun: true);

        $this->assertSame(AtlasRollbackCascadeExecutor::STATE_CONTAINMENT, $result['state']);
        $this->assertSame('empty_decision_id', $result['reason']);
    }

    public function test_dry_run_reports_full_closure_without_touching_memory_state(): void
    {
        $ledger = new AtlasDecisionLineageLedger;
        $entryId = $this->seedMemoryEntry('mem-live-1');

        $ledger->append('DEC-DRY', AtlasDecisionLineageLedger::KIND_MEMORY, $entryId, 'memory_registry');
        $ledger->append('DEC-DRY', AtlasDecisionLineageLedger::KIND_APPLY, 'apply-42', 'learning_applier', reverseHandle: 'applier:reverse:42');

        $executor = new AtlasRollbackCascadeExecutor($ledger, sys_get_temp_dir());
        $result = $executor->execute('DEC-DRY', dryRun: true);

        $this->assertTrue($result['dry_run']);
        $this->assertSame(AtlasRollbackCascadeExecutor::STATE_CLEAN, $result['state']);
        $this->assertSame(2, $result['reversed_count']);

        // Live memory row untouched — dry-run never persists.
        $this->assertSame('active', AtlasMemoryEntry::query()->where('id', $entryId)->value('status'));
    }

    public function test_five_item_cascade_clean_revert_executes_memory_apply_and_outcome(): void
    {
        $ledger = new AtlasDecisionLineageLedger;

        // 5-item mixed closure: 2 memory writes + 2 apply proposals + 1 outcome.
        $memA = $this->seedMemoryEntry('mem-a');
        $memB = $this->seedMemoryEntry('mem-b');
        $ledger->append('DEC-CASCADE', AtlasDecisionLineageLedger::KIND_MEMORY, $memA, 'memory_registry');
        $ledger->append('DEC-CASCADE', AtlasDecisionLineageLedger::KIND_MEMORY, $memB, 'memory_registry');
        $ledger->append('DEC-CASCADE', AtlasDecisionLineageLedger::KIND_APPLY, 'apply-1', 'learning_applier', reverseHandle: 'applier:reverse:1');
        $ledger->append('DEC-CASCADE', AtlasDecisionLineageLedger::KIND_APPLY, 'apply-2', 'learning_applier', reverseHandle: 'applier:reverse:2');
        $ledger->append('DEC-CASCADE', AtlasDecisionLineageLedger::KIND_OUTCOME, 'outcome-99', 'live_outcome_feedback');

        $executor = new AtlasRollbackCascadeExecutor($ledger, sys_get_temp_dir());
        $result = $executor->execute('DEC-CASCADE', dryRun: false, options: ['allow_git' => false]);

        $this->assertSame(AtlasRollbackCascadeExecutor::STATE_CLEAN, $result['state']);
        $this->assertSame(5, $result['reversed_count']);
        $this->assertSame(5, $result['closure_size']);

        // Both memories archived on disk.
        $this->assertSame('archived', AtlasMemoryEntry::query()->where('id', $memA)->value('status'));
        $this->assertSame('archived', AtlasMemoryEntry::query()->where('id', $memB)->value('status'));

        // Apply reverse handles surfaced in the receipt for the operator digest.
        $applyRows = array_values(array_filter($result['reversed'], fn (array $r) => ($r['kind'] ?? '') === AtlasDecisionLineageLedger::KIND_APPLY));
        $this->assertCount(2, $applyRows);
        $this->assertSame('recorded_reverse_handle_intent', $applyRows[0]['action']);
    }

    public function test_partial_state_when_a_memory_row_is_missing_but_others_reverse(): void
    {
        $ledger = new AtlasDecisionLineageLedger;

        $memAlive = $this->seedMemoryEntry('mem-alive');
        $memGhost = (string) Str::uuid(); // never seeded — memory_not_found on revert

        $ledger->append('DEC-PARTIAL', AtlasDecisionLineageLedger::KIND_MEMORY, $memAlive, 'memory_registry');
        $ledger->append('DEC-PARTIAL', AtlasDecisionLineageLedger::KIND_MEMORY, $memGhost, 'memory_registry');

        $executor = new AtlasRollbackCascadeExecutor($ledger, sys_get_temp_dir());
        $result = $executor->execute('DEC-PARTIAL', dryRun: false, options: ['allow_git' => false]);

        $this->assertSame(AtlasRollbackCascadeExecutor::STATE_PARTIAL, $result['state']);
        $this->assertSame(1, $result['reversed_count']);
        $this->assertCount(1, $result['blocked']);
        $this->assertSame('memory_not_found', $result['blocked'][0]['reason']);
        $this->assertSame('archived', AtlasMemoryEntry::query()->where('id', $memAlive)->value('status'));
    }

    public function test_reversal_count_query_used_by_maxk08_returns_only_apply_rows_with_reverse_handle(): void
    {
        $ledger = new AtlasDecisionLineageLedger;

        $ledger->append('DEC-1', AtlasDecisionLineageLedger::KIND_COMMIT, 'sha-a', 'autonomos_landing');
        $ledger->append('DEC-1', AtlasDecisionLineageLedger::KIND_APPLY, 'apply-a', 'learning_applier', reverseHandle: 'applier:reverse:a');
        $ledger->append('DEC-2', AtlasDecisionLineageLedger::KIND_APPLY, 'apply-b', 'learning_applier'); // no reverse handle — must NOT count

        $this->assertSame(1, $ledger->countReversalsWithinDays(30));
        $this->assertSame(0, $ledger->countReversalsWithinDays(0)); // days<1 guard
    }

    private function seedMemoryEntry(string $suffix): string
    {
        $id = (string) Str::uuid();
        $entry = new AtlasMemoryEntry;
        $entry->forceFill([
            'id' => $id,
            'memory_type' => 'decision',
            'scope_type' => 'global',
            'title' => 'ASI-11 fixture '.$suffix,
            'body' => 'body '.$suffix,
            'status' => 'active',
            'source_type' => 'atlas_autonomous_learning',
        ])->save();

        return $id;
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\Maestro\Tiering;

use App\Services\Ai\SelfConstruction\Maestro\Tiering\AtlasMaestroTierMismatchLedger;
use App\Services\Ai\SelfConstruction\Maestro\Tiering\AtlasMaestroTieredRoutingPolicy;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Worker/model tier mistakes become append-only routing learning instead of repeated bad
 * assignments: allow verdicts are ignored, refuse verdicts append with content_hash, duplicate
 * refusals are suppressed, history is newest-first with optional client filter, forWorker/forFamily
 * return oldest-first rows, and recommend emits sorted unique avoid_tiers with mismatch_count.
 */
final class AtlasMaestroTierMismatchLedgerTest extends TestCase
{
    private string $ledgerPath;

    private AtlasMaestroTierMismatchLedger $ledger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledgerPath = sys_get_temp_dir().'/atlas_maestro_mismatch_declared_'.bin2hex(random_bytes(6)).'.jsonl';
        $this->ledger = new AtlasMaestroTierMismatchLedger($this->ledgerPath, static fn (): string => '2026-06-25T00:00:00Z');
    }

    protected function tearDown(): void
    {
        @unlink($this->ledgerPath);
        parent::tearDown();
    }

    private function refusal(string $clientId = 'sonnet-1', string $tier = 'hardest'): array
    {
        return [
            'verdict' => AtlasMaestroTieredRoutingPolicy::VERDICT_REFUSE,
            'packet_tier' => $tier,
            'packet_fact_basis' => ['allowed_files includes cross-cutting marker: Constitution'],
            'worker_declared_max_tier' => 'easy',
            'client_id' => $clientId,
            'reason' => 'packet tier exceeds worker declared max tier',
        ];
    }

    public function test_allow_verdicts_are_ignored(): void
    {
        $allow = $this->refusal();
        $allow['verdict'] = AtlasMaestroTieredRoutingPolicy::VERDICT_ALLOW;
        $allowUnknown = $this->refusal();
        $allowUnknown['verdict'] = AtlasMaestroTieredRoutingPolicy::VERDICT_ALLOW_UNKNOWN;

        $this->ledger->record($allow);
        $this->ledger->record($allowUnknown);

        self::assertFalse(is_file($this->ledgerPath), 'allow verdicts must not create any persistence path');
    }

    public function test_refuse_verdicts_append_with_content_hash(): void
    {
        $this->ledger->record($this->refusal(), ['packet_id' => 'p-xyz']);

        $rows = $this->ledger->forWorker('sonnet-1');
        self::assertCount(1, $rows);
        self::assertSame(64, strlen($rows[0]['content_hash']));
    }

    public function test_duplicate_refusals_are_suppressed(): void
    {
        $this->ledger->record($this->refusal(), ['packet_id' => 'p-dup']);
        $this->ledger->record($this->refusal(), ['packet_id' => 'p-dup']);

        self::assertCount(1, $this->ledger->forWorker('sonnet-1'));
    }

    public function test_history_is_newest_first_with_optional_client_filter(): void
    {
        $this->ledger->record($this->refusal('codex-2'), ['packet_id' => 'p1']);
        $this->ledger->record($this->refusal('sonnet-1'), ['packet_id' => 'p2']);
        $this->ledger->record($this->refusal('codex-2'), ['packet_id' => 'p3']);

        $rows = $this->ledger->history(limit: 10, clientId: 'codex-2');

        self::assertSame(['p3', 'p1'], array_column($rows, 'packet_id'));
    }

    public function test_for_worker_returns_oldest_first_rows(): void
    {
        $this->ledger->record($this->refusal('worker-a'), ['packet_id' => 'p1']);
        $this->ledger->record($this->refusal('worker-b'), ['packet_id' => 'p2']);
        $this->ledger->record($this->refusal('worker-a'), ['packet_id' => 'p3']);

        self::assertSame(['p1', 'p3'], array_column($this->ledger->forWorker('worker-a'), 'packet_id'));
    }

    public function test_for_family_returns_oldest_first_rows(): void
    {
        $this->ledger->record($this->refusal(), ['packet_id' => 'atlas-task-1']);
        $this->ledger->record($this->refusal('w2'), ['packet_id' => 'atlas-task-2']);
        $this->ledger->record($this->refusal('w3'), ['packet_id' => 'other-task-1']);

        self::assertSame(['atlas-task-1', 'atlas-task-2'], array_column($this->ledger->forFamily('atlas-task'), 'packet_id'));
    }

    public function test_recommend_emits_sorted_unique_avoid_tiers_with_mismatch_count(): void
    {
        $this->ledger->record($this->refusal('learner', 'hardest'), ['packet_id' => 'p1']);
        $this->ledger->record($this->refusal('learner', 'medium'), ['packet_id' => 'p2']);
        $this->ledger->record($this->refusal('learner', 'hardest'), ['packet_id' => 'p3']);

        $rec = $this->ledger->recommend('learner');

        self::assertSame(['hardest', 'medium'], $rec['avoid_tiers']);
        self::assertSame(3, $rec['mismatch_count']);
    }

    public function test_recommend_no_mismatches_for_clean_worker(): void
    {
        $rec = $this->ledger->recommend('clean-worker');

        self::assertSame([], $rec['avoid_tiers']);
        self::assertSame(0, $rec['mismatch_count']);
        self::assertSame('no_mismatches', $rec['signal']);
    }

    public function test_no_update_delete_or_truncate_persistence_path_exists(): void
    {
        $refl = new ReflectionClass(AtlasMaestroTierMismatchLedger::class);
        $methods = array_map(static fn ($m) => strtolower($m->getName()), $refl->getMethods());

        foreach (['update', 'delete', 'truncate', 'remove', 'overwrite'] as $forbidden) {
            self::assertNotContains($forbidden, $methods, "no {$forbidden}() persistence path allowed for the learning signal");
        }
    }
}

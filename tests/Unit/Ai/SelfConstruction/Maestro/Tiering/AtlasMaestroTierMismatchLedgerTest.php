<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Maestro\Tiering;

use App\Services\Ai\SelfConstruction\Maestro\Tiering\AtlasMaestroTierMismatchLedger;
use App\Services\Ai\SelfConstruction\Maestro\Tiering\AtlasMaestroTieredRoutingPolicy;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Proves the Maestro tier-mismatch ledger: refusal verdicts get one JSONL row each with the canonical
 * schema + both tiers + fact_basis; allow verdicts append nothing; history() returns at most $limit rows
 * filtered by client_id in newest-first order; static analysis confirms only append/read methods exist
 * (no update / delete / truncate path).
 */
final class AtlasMaestroTierMismatchLedgerTest extends TestCase
{
    private string $ledgerPath;

    private AtlasMaestroTierMismatchLedger $ledger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledgerPath = sys_get_temp_dir().'/atlas_maestro_mismatch_'.bin2hex(random_bytes(6)).'.jsonl';
        $this->ledger = new AtlasMaestroTierMismatchLedger($this->ledgerPath, static fn (): string => '2026-06-25T00:00:00Z');
    }

    protected function tearDown(): void
    {
        @unlink($this->ledgerPath);
        parent::tearDown();
    }

    private function refusal(string $clientId = 'sonnet-1'): array
    {
        return [
            'schema' => 'atlas.maestro.tier_routing.v1',
            'verdict' => AtlasMaestroTieredRoutingPolicy::VERDICT_REFUSE,
            'packet_tier' => 'hardest',
            'packet_fact_basis' => ['allowed_files includes cross-cutting marker: Constitution'],
            'worker_declared_max_tier' => 'easy',
            'client_id' => $clientId,
            'reason' => 'packet tier "hardest" exceeds worker declared max tier "easy"',
        ];
    }

    public function test_refusal_verdict_appends_one_row_with_canonical_schema_and_both_tiers(): void
    {
        $this->ledger->record($this->refusal(), ['packet_id' => 'p-xyz']);
        $lines = file($this->ledgerPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        $this->assertCount(1, $lines);
        $decoded = json_decode($lines[0], true);
        $this->assertSame(AtlasMaestroTierMismatchLedger::SCHEMA, $decoded['schema']);
        $this->assertSame('p-xyz', $decoded['packet_id']);
        $this->assertSame('sonnet-1', $decoded['client_id']);
        $this->assertSame('hardest', $decoded['inferred_tier']);
        $this->assertSame('easy', $decoded['worker_declared_tier']);
        $this->assertNotEmpty($decoded['fact_basis']);
        $this->assertSame(64, strlen($decoded['content_hash']));
    }

    public function test_allow_and_allow_unknown_verdicts_append_nothing(): void
    {
        $allow = $this->refusal();
        $allow['verdict'] = AtlasMaestroTieredRoutingPolicy::VERDICT_ALLOW;
        $allowUnknown = $this->refusal();
        $allowUnknown['verdict'] = AtlasMaestroTieredRoutingPolicy::VERDICT_ALLOW_UNKNOWN;

        $sizeBefore = is_file($this->ledgerPath) ? filesize($this->ledgerPath) : 0;
        $this->ledger->record($allow);
        $this->ledger->record($allowUnknown);
        $sizeAfter = is_file($this->ledgerPath) ? filesize($this->ledgerPath) : 0;

        $this->assertSame($sizeBefore, $sizeAfter, 'allow verdicts must not be recorded');
    }

    public function test_history_limits_and_filters_by_client_id_newest_first(): void
    {
        $this->ledger->record($this->refusal('codex-2'), ['packet_id' => 'p1']);
        $this->ledger->record($this->refusal('sonnet-1'), ['packet_id' => 'p2']);
        $this->ledger->record($this->refusal('codex-2'), ['packet_id' => 'p3']);
        $this->ledger->record($this->refusal('codex-2'), ['packet_id' => 'p4']);
        $this->ledger->record($this->refusal('codex-2'), ['packet_id' => 'p5']);
        $this->ledger->record($this->refusal('codex-2'), ['packet_id' => 'p6']);
        $this->ledger->record($this->refusal('codex-2'), ['packet_id' => 'p7']);

        $rows = $this->ledger->history(limit: 5, clientId: 'codex-2');

        $this->assertCount(5, $rows);
        foreach ($rows as $r) {
            $this->assertSame('codex-2', $r['client_id']);
        }
        // Newest-first: p7 first, then p6, p5, p4, p3.
        $this->assertSame(['p7', 'p6', 'p5', 'p4', 'p3'], array_column($rows, 'packet_id'));
    }

    public function test_class_has_no_update_or_delete_or_truncate_methods(): void
    {
        $refl = new ReflectionClass(AtlasMaestroTierMismatchLedger::class);
        $methods = array_map(static fn ($m) => strtolower($m->getName()), $refl->getMethods());

        foreach (['update', 'delete', 'truncate', 'remove', 'overwrite'] as $forbidden) {
            $this->assertNotContains($forbidden, $methods, "pétreo append-only: no {$forbidden}() method allowed");
        }
    }

    public function test_two_refusals_append_two_lines_neither_overwriting(): void
    {
        $this->ledger->record($this->refusal('a'));
        $this->ledger->record($this->refusal('b'));
        $lines = file($this->ledgerPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertCount(2, $lines);
    }

    public function test_duplicate_suppression_prevents_double_row(): void
    {
        $this->ledger->record($this->refusal(), ['packet_id' => 'p-dup']);
        $this->ledger->record($this->refusal(), ['packet_id' => 'p-dup']); // identical → same content_hash

        $lines = file($this->ledgerPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertCount(1, $lines);
    }

    public function test_for_worker_aggregates_all_rows_for_client(): void
    {
        $this->ledger->record($this->refusal('worker-a'), ['packet_id' => 'p1']);
        $this->ledger->record($this->refusal('worker-b'), ['packet_id' => 'p2']);
        $this->ledger->record($this->refusal('worker-a'), ['packet_id' => 'p3']);

        $rows = $this->ledger->forWorker('worker-a');
        $this->assertCount(2, $rows);
        foreach ($rows as $r) {
            $this->assertSame('worker-a', $r['client_id']);
        }
    }

    public function test_for_family_aggregates_by_packet_id_prefix(): void
    {
        $this->ledger->record($this->refusal(), ['packet_id' => 'atlas-task-1']);
        $this->ledger->record($this->refusal('w2'), ['packet_id' => 'atlas-task-2']);
        $this->ledger->record($this->refusal('w3'), ['packet_id' => 'other-task-1']);

        $this->assertCount(2, $this->ledger->forFamily('atlas-task'));
        $this->assertCount(1, $this->ledger->forFamily('other-task'));
        $this->assertCount(0, $this->ledger->forFamily('missing'));
    }

    public function test_recommend_returns_avoid_tiers_and_signal(): void
    {
        $this->ledger->record($this->refusal('learner'), ['packet_id' => 'p1']);
        $rec = $this->ledger->recommend('learner');

        $this->assertSame('learner', $rec['client_id']);
        $this->assertContains('hardest', $rec['avoid_tiers']);
        $this->assertSame(1, $rec['mismatch_count']);
        $this->assertStringContainsString('avoid_tiers', $rec['signal']);
    }

    public function test_recommend_no_mismatches_returns_clean_signal(): void
    {
        $rec = $this->ledger->recommend('unknown-worker');

        $this->assertSame([], $rec['avoid_tiers']);
        $this->assertSame(0, $rec['mismatch_count']);
        $this->assertSame('no_mismatches', $rec['signal']);
    }
}

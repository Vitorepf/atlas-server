<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Maestro\Adaptive;

use App\Services\Ai\SelfConstruction\Maestro\Adaptive\AtlasMaestroWorkerBehaviorLedger;
use PHPUnit\Framework\TestCase;

final class AtlasMaestroWorkerBehaviorLedgerTest extends TestCase
{
    private string $path;

    private AtlasMaestroWorkerBehaviorLedger $ledger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = sys_get_temp_dir().'/atlas-maestro-behavior-'.bin2hex(random_bytes(6)).'.jsonl';
        $this->ledger = new AtlasMaestroWorkerBehaviorLedger($this->path);
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        parent::tearDown();
    }

    // The reason this ledger exists at all: facts recorded by one process are
    // recalled by the NEXT process. Before durability, recall() across
    // instances ALWAYS returned defaults and every reader read a vacuum.
    public function test_facts_survive_across_instances(): void
    {
        $this->ledger->record(['client_id' => 'w1', 'task_family' => 'family:app/Services', 'outcome' => 'give_back', 'root_cause_family' => 'scope']);
        $this->ledger->record(['client_id' => 'w1', 'task_family' => 'family:app/Services', 'outcome' => 'success']);

        $fresh = new AtlasMaestroWorkerBehaviorLedger($this->path);
        $recall = $fresh->recall('w1', 'family:app/Services');

        $this->assertTrue($recall['seen']);
        $this->assertSame(2, $recall['total_events']);
        $this->assertSame(0.5, $recall['give_back_rate']);
        $this->assertSame(1, $fresh->topGiveBackCauses()[0]['count']);
    }

    // AC 2: success, give_back and weak_green aggregated by client+family
    public function test_events_aggregated_by_client_and_family(): void
    {
        $this->ledger->record(['client_id' => 'w1', 'task_family' => 'Brain', 'outcome' => 'success']);
        $this->ledger->record(['client_id' => 'w1', 'task_family' => 'Brain', 'outcome' => 'success']);
        $this->ledger->record(['client_id' => 'w1', 'task_family' => 'Brain', 'outcome' => 'give_back', 'root_cause_family' => 'petreo']);
        $this->ledger->record(['client_id' => 'w1', 'task_family' => 'Brain', 'outcome' => 'weak_green']);

        $recall = $this->ledger->recall('w1', 'Brain');

        $this->assertTrue($recall['seen']);
        $this->assertSame(4, $recall['total_events']);
        $this->assertSame(0.5, $recall['success_rate']);
        $this->assertSame(0.25, $recall['give_back_rate']);
        $this->assertSame(0.25, $recall['weak_green_rate']);
    }

    // AC 3: top give_back classes include root_cause_family counts
    public function test_top_give_back_includes_root_cause(): void
    {
        $this->ledger->record(['client_id' => 'w1', 'task_family' => 'Brain', 'outcome' => 'give_back', 'root_cause_family' => 'petreo']);
        $this->ledger->record(['client_id' => 'w2', 'task_family' => 'Brain', 'outcome' => 'give_back', 'root_cause_family' => 'petreo']);
        $this->ledger->record(['client_id' => 'w1', 'task_family' => 'Cortex', 'outcome' => 'give_back', 'root_cause_family' => 'duplicate']);

        $top = $this->ledger->topGiveBackCauses();

        $this->assertNotEmpty($top);
        // petreo (2) should be first
        $this->assertStringContainsString('petreo', $top[0]['root_cause_key']);
        $this->assertSame(2, $top[0]['count']);
    }

    // AC 4: recall returns conservative defaults for unseen workers
    public function test_unseen_worker_returns_conservative_defaults(): void
    {
        $recall = $this->ledger->recall('unseen-worker', 'NeverSeen');

        $this->assertFalse($recall['seen']);
        $this->assertSame(0.0, $recall['success_rate']);
        $this->assertSame(0.0, $recall['give_back_rate']);
        $this->assertSame(0, $recall['total_events']);
    }

    public function test_different_clients_tracked_separately(): void
    {
        $this->ledger->record(['client_id' => 'w1', 'task_family' => 'Brain', 'outcome' => 'success']);
        $this->ledger->record(['client_id' => 'w2', 'task_family' => 'Brain', 'outcome' => 'give_back', 'root_cause_family' => 'x']);

        $r1 = $this->ledger->recall('w1', 'Brain');
        $r2 = $this->ledger->recall('w2', 'Brain');

        $this->assertSame(1.0, $r1['success_rate']);
        $this->assertSame(1.0, $r2['give_back_rate']);
    }

    public function test_different_families_tracked_separately(): void
    {
        $this->ledger->record(['client_id' => 'w1', 'task_family' => 'Brain', 'outcome' => 'success']);
        $this->ledger->record(['client_id' => 'w1', 'task_family' => 'Cortex', 'outcome' => 'give_back', 'root_cause_family' => 'y']);

        $r1 = $this->ledger->recall('w1', 'Brain');
        $r2 = $this->ledger->recall('w1', 'Cortex');

        $this->assertSame(1.0, $r1['success_rate']);
        $this->assertSame(1.0, $r2['give_back_rate']);
    }

    public function test_empty_ledger_top_causes(): void
    {
        $this->assertSame([], $this->ledger->topGiveBackCauses());
    }
}

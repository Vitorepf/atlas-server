<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Maestro\Adaptive;

use App\Services\Ai\SelfConstruction\Maestro\Adaptive\AtlasMaestroAdaptiveDecisionReceipt;
use DomainException;
use Tests\TestCase;

final class AtlasMaestroAdaptiveDecisionReceiptTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = sys_get_temp_dir().'/atlas-maestro-decision-receipts-'.bin2hex(random_bytes(5)).'.jsonl';
        config(['atlas.maestro.adaptive.decision_receipt_enabled' => true]);
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }
        parent::tearDown();
    }

    public function test_record_appends_even_duplicate_payloads_with_monotonic_decision_ids(): void
    {
        $store = $this->store();
        $payload = $this->payload(AtlasMaestroAdaptiveDecisionReceipt::RESHAPE_APPLIED);

        $first = $store->record($payload);
        $second = $store->record($payload);

        $this->assertSame(1, $first['decision_id']);
        $this->assertSame(2, $second['decision_id']);
        $this->assertNotSame($first['decision_id'], $second['decision_id']);
        $this->assertCount(2, $store->receipts('packet-1'));
        $this->assertCount(1, $store->receiptsSince(1));
    }

    public function test_mutate_or_delete_throws_domain_exception_and_store_stays_unchanged(): void
    {
        $store = $this->store();
        $store->record($this->payload(AtlasMaestroAdaptiveDecisionReceipt::ROUTE_SELECTED));
        $before = $store->receipts('packet-1');

        try {
            $store->update(1, ['decision_kind' => AtlasMaestroAdaptiveDecisionReceipt::ROUTE_ABSTAIN]);
            $this->fail('update should throw');
        } catch (DomainException $exception) {
            $this->assertSame('atlas_maestro_adaptive_decision_receipts_are_append_only', $exception->getMessage());
        }

        try {
            $store->delete(1);
            $this->fail('delete should throw');
        } catch (DomainException $exception) {
            $this->assertSame('atlas_maestro_adaptive_decision_receipts_are_append_only', $exception->getMessage());
        }

        $this->assertSame($before, $store->receipts('packet-1'));
    }

    public function test_all_decision_kinds_round_trip_with_worker_or_abstain_fields(): void
    {
        $store = $this->store();
        $store->record($this->payload(AtlasMaestroAdaptiveDecisionReceipt::RESHAPE_APPLIED));
        $store->record($this->payload(AtlasMaestroAdaptiveDecisionReceipt::RESHAPE_PASSTHROUGH));
        $store->record($this->payload(AtlasMaestroAdaptiveDecisionReceipt::ROUTE_SELECTED, chosenWorker: 'worker-a'));
        $store->record($this->payload(AtlasMaestroAdaptiveDecisionReceipt::ROUTE_ABSTAIN, abstainReason: 'insufficient_sample'));

        $rows = $store->receipts('packet-1');

        $this->assertSame([
            AtlasMaestroAdaptiveDecisionReceipt::RESHAPE_APPLIED,
            AtlasMaestroAdaptiveDecisionReceipt::RESHAPE_PASSTHROUGH,
            AtlasMaestroAdaptiveDecisionReceipt::ROUTE_SELECTED,
            AtlasMaestroAdaptiveDecisionReceipt::ROUTE_ABSTAIN,
        ], array_column($rows, 'decision_kind'));
        $this->assertSame('worker-a', $rows[2]['chosen_worker_or_null']);
        $this->assertNull($rows[2]['abstain_reason_or_null']);
        $this->assertNull($rows[3]['chosen_worker_or_null']);
        $this->assertSame('insufficient_sample', $rows[3]['abstain_reason_or_null']);
    }

    public function test_default_flag_fallback_false_disables_record_and_reads_empty(): void
    {
        config(['atlas.maestro.adaptive.decision_receipt_enabled' => null]);
        $store = $this->store();

        $this->assertNull($store->record($this->payload(AtlasMaestroAdaptiveDecisionReceipt::ROUTE_ABSTAIN)));
        $this->assertSame([], $store->receipts('packet-1'));
        $this->assertSame([], $store->receiptsSince(0));
    }

    private function store(): AtlasMaestroAdaptiveDecisionReceipt
    {
        return new AtlasMaestroAdaptiveDecisionReceipt($this->path);
    }

    /**
     * @return array<string,mixed>
     */
    private function payload(string $kind, ?string $chosenWorker = null, ?string $abstainReason = null): array
    {
        return [
            'decision_kind' => $kind,
            'packet_id' => 'packet-1',
            'original_hash' => 'hash-original',
            'outcome_hash' => 'hash-outcome',
            'miner_facts_used' => [['shape_key' => 'scope:app', 'give_back_count' => 3, 'served_count' => 5]],
            'eligible_workers_snapshot' => [['worker' => 'worker-a', 'success_count' => 2]],
            'chosen_worker_or_null' => $chosenWorker,
            'abstain_reason_or_null' => $abstainReason,
        ];
    }
}

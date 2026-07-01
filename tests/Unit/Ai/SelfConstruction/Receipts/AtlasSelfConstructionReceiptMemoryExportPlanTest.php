<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Receipts;

use App\Services\Ai\SelfConstruction\Receipts\AtlasSelfConstructionReceiptMemoryExportPlan;
use PHPUnit\Framework\TestCase;

/**
 * Proves AtlasSelfConstructionReceiptMemoryExportPlan: bound + proven facts ⇒ export_plan row with
 * stable export_id; unbound ⇒ rejected:unbound; unverified worker_claim ⇒ rejected:unverified_claim;
 * failed gate without diagnosis ⇒ rejected:failed_gate_without_diagnosis; secret in summary ⇒
 * rejected:contains_secret; export_id deterministic across runs.
 */
final class AtlasSelfConstructionReceiptMemoryExportPlanTest extends TestCase
{
    public function test_bound_proven_fact_is_exported_with_stable_export_id(): void
    {
        $r = (new AtlasSelfConstructionReceiptMemoryExportPlan)->plan([
            ['id' => 'd-1', 'kind' => 'decision', 'bound' => true, 'verdict' => 'passed', 'fact_summary' => 'capability X delivered'],
        ]);
        $this->assertCount(1, $r['export_plan']);
        $this->assertSame(12, strlen($r['export_plan'][0]['export_id']));
    }

    public function test_unbound_is_rejected(): void
    {
        $r = (new AtlasSelfConstructionReceiptMemoryExportPlan)->plan([
            ['id' => 'x-1', 'kind' => 'decision', 'bound' => false, 'fact_summary' => 'something'],
        ]);
        $this->assertSame([], $r['export_plan']);
        $this->assertSame('rejected:unbound', $r['rejections'][0]['reason']);
    }

    public function test_unverified_worker_claim_is_rejected(): void
    {
        $r = (new AtlasSelfConstructionReceiptMemoryExportPlan)->plan([
            ['id' => 'w-1', 'kind' => 'worker_claim', 'bound' => true, 'verdict' => 'self_reported', 'fact_summary' => 'I am done'],
        ]);
        $this->assertSame('rejected:unverified_claim', $r['rejections'][0]['reason']);
    }

    public function test_failed_gate_without_diagnosis_is_rejected(): void
    {
        $r = (new AtlasSelfConstructionReceiptMemoryExportPlan)->plan([
            ['id' => 'g-1', 'kind' => 'gate', 'bound' => true, 'verdict' => 'failed', 'fact_summary' => 'tests red'],
        ]);
        $this->assertSame('rejected:failed_gate_without_diagnosis', $r['rejections'][0]['reason']);
    }

    public function test_failed_gate_with_diagnosis_is_exported(): void
    {
        $r = (new AtlasSelfConstructionReceiptMemoryExportPlan)->plan([
            ['id' => 'g-2', 'kind' => 'gate', 'bound' => true, 'verdict' => 'failed', 'fact_summary' => 'tests red because race condition (diagnosis: missing lock)'],
        ]);
        $this->assertCount(1, $r['export_plan']);
    }

    public function test_secret_in_summary_is_rejected(): void
    {
        $r = (new AtlasSelfConstructionReceiptMemoryExportPlan)->plan([
            ['id' => 's-1', 'kind' => 'decision', 'bound' => true, 'fact_summary' => 'SECRET=hunter2'],
        ]);
        $this->assertSame('rejected:contains_secret', $r['rejections'][0]['reason']);
    }

    public function test_narrative_too_broad_is_rejected(): void
    {
        $r = (new AtlasSelfConstructionReceiptMemoryExportPlan)->plan([
            ['id' => 'n-1', 'kind' => 'decision', 'bound' => true, 'fact_summary' => str_repeat('x', AtlasSelfConstructionReceiptMemoryExportPlan::NARRATIVE_MAX_CHARS + 1)],
        ]);
        $this->assertSame('rejected:narrative_too_broad', $r['rejections'][0]['reason']);
    }

    public function test_export_id_is_deterministic_across_calls(): void
    {
        $p = new AtlasSelfConstructionReceiptMemoryExportPlan;
        $candidates = [['id' => 'd-1', 'kind' => 'decision', 'bound' => true, 'fact_summary' => 'ok']];
        $a = $p->plan($candidates);
        $b = $p->plan($candidates);
        $this->assertSame($a['export_plan'][0]['export_id'], $b['export_plan'][0]['export_id']);
    }

    public function test_empty_id_is_rejected(): void
    {
        $r = (new AtlasSelfConstructionReceiptMemoryExportPlan)->plan([
            ['id' => '', 'kind' => 'decision', 'bound' => true, 'fact_summary' => 'ok'],
        ]);
        $this->assertSame([], $r['export_plan']);
        $this->assertSame('rejected:empty_id', $r['rejections'][0]['reason']);
    }

    public function test_empty_kind_is_rejected(): void
    {
        $r = (new AtlasSelfConstructionReceiptMemoryExportPlan)->plan([
            ['id' => 'e-1', 'kind' => '', 'bound' => true, 'fact_summary' => 'ok'],
        ]);
        $this->assertSame([], $r['export_plan']);
        $this->assertSame('rejected:empty_kind', $r['rejections'][0]['reason']);
    }

    public function test_empty_fact_summary_is_rejected(): void
    {
        $r = (new AtlasSelfConstructionReceiptMemoryExportPlan)->plan([
            ['id' => 'e-2', 'kind' => 'decision', 'bound' => true, 'fact_summary' => ''],
        ]);
        $this->assertSame([], $r['export_plan']);
        $this->assertSame('rejected:empty_fact_summary', $r['rejections'][0]['reason']);
    }

    public function test_secret_in_nested_raw_payload_value_is_rejected(): void
    {
        $r = (new AtlasSelfConstructionReceiptMemoryExportPlan)->plan([
            [
                'id' => 's-2', 'kind' => 'decision', 'bound' => true, 'fact_summary' => 'all good',
                'raw_payload' => ['config' => ['nested' => ['value' => 'TOKEN=abc123']]],
            ],
        ]);
        $this->assertSame([], $r['export_plan']);
        $this->assertSame('rejected:contains_secret', $r['rejections'][0]['reason']);
    }

    public function test_raw_payload_never_in_export_row(): void
    {
        $r = (new AtlasSelfConstructionReceiptMemoryExportPlan)->plan([
            ['id' => 'r-1', 'kind' => 'decision', 'bound' => true, 'fact_summary' => 'ok', 'raw_payload' => ['sensitive' => 'data']],
        ]);
        $this->assertCount(1, $r['export_plan']);
        $this->assertArrayNotHasKey('raw_payload', $r['export_plan'][0]);
    }

    // ── AC2/AC3/AC4: decisions vocabulary (export/redact/defer/reject) ─────────

    public function test_raw_transcript_is_rejected(): void
    {
        $r = (new AtlasSelfConstructionReceiptMemoryExportPlan)->plan([
            ['id' => 't-1', 'kind' => 'raw_transcript', 'bound' => true, 'fact_summary' => 'full dialogue dump'],
        ]);
        $this->assertSame([], $r['export_plan']);
        $this->assertSame('rejected:raw_transcript', $r['rejections'][0]['reason']);
        $this->assertSame(AtlasSelfConstructionReceiptMemoryExportPlan::DECISION_REJECT, $r['decisions'][0]['decision']);
    }

    public function test_status_chatter_kind_is_rejected_as_low_utility(): void
    {
        $r = (new AtlasSelfConstructionReceiptMemoryExportPlan)->plan([
            ['id' => 'c-1', 'kind' => 'status_chatter', 'bound' => true, 'fact_summary' => 'still working on it'],
        ]);
        $this->assertSame('rejected:low_utility_chatter', $r['rejections'][0]['reason']);
    }

    public function test_low_reuse_value_is_rejected_as_low_utility_regardless_of_kind(): void
    {
        $r = (new AtlasSelfConstructionReceiptMemoryExportPlan)->plan([
            ['id' => 'c-2', 'kind' => 'decision', 'bound' => true, 'fact_summary' => 'minor detail', 'reuse_value' => 0.1],
        ]);
        $this->assertSame('rejected:low_utility_chatter', $r['rejections'][0]['reason']);
    }

    public function test_high_reuse_value_is_exported(): void
    {
        $r = (new AtlasSelfConstructionReceiptMemoryExportPlan)->plan([
            ['id' => 'c-3', 'kind' => 'decision', 'bound' => true, 'fact_summary' => 'valuable pattern', 'reuse_value' => 0.9],
        ]);
        $this->assertCount(1, $r['export_plan']);
    }

    public function test_stale_hint_is_rejected(): void
    {
        $r = (new AtlasSelfConstructionReceiptMemoryExportPlan)->plan([
            ['id' => 'h-1', 'kind' => 'hint', 'bound' => true, 'fact_summary' => 'old hint', 'is_stale' => true],
        ]);
        $this->assertSame('rejected:stale_hint', $r['rejections'][0]['reason']);
    }

    public function test_fresh_hint_is_exported(): void
    {
        $r = (new AtlasSelfConstructionReceiptMemoryExportPlan)->plan([
            ['id' => 'h-2', 'kind' => 'hint', 'bound' => true, 'fact_summary' => 'fresh hint'],
        ]);
        $this->assertCount(1, $r['export_plan']);
    }

    public function test_ephemeral_evidence_is_deferred_not_rejected_or_exported(): void
    {
        $r = (new AtlasSelfConstructionReceiptMemoryExportPlan)->plan([
            ['id' => 'e-3', 'kind' => 'decision', 'bound' => true, 'fact_summary' => 'not yet durable', 'evidence_durability' => 'ephemeral'],
        ]);
        $this->assertSame([], $r['export_plan']);
        $this->assertSame([], $r['rejections']);
        $this->assertSame(AtlasSelfConstructionReceiptMemoryExportPlan::DECISION_DEFER, $r['decisions'][0]['decision']);
        $this->assertSame('deferred:ephemeral_evidence_pending_durability', $r['decisions'][0]['reason']);
    }

    public function test_durable_evidence_is_exported_by_default(): void
    {
        $r = (new AtlasSelfConstructionReceiptMemoryExportPlan)->plan([
            ['id' => 'e-4', 'kind' => 'decision', 'bound' => true, 'fact_summary' => 'durable fact'],
        ]);
        $this->assertSame(AtlasSelfConstructionReceiptMemoryExportPlan::DECISION_EXPORT, $r['decisions'][0]['decision']);
    }

    public function test_secret_in_raw_payload_is_redacted_when_explicitly_marked_redactable(): void
    {
        $r = (new AtlasSelfConstructionReceiptMemoryExportPlan)->plan([
            [
                'id' => 'p-1', 'kind' => 'decision', 'bound' => true, 'fact_summary' => 'clean summary',
                'raw_payload' => ['config' => ['TOKEN' => 'abc123']], 'raw_payload_redactable' => true,
            ],
        ]);
        $this->assertCount(1, $r['export_plan']);
        $this->assertTrue($r['export_plan'][0]['redacted']);
        $this->assertSame([], $r['rejections']);
        $this->assertSame(AtlasSelfConstructionReceiptMemoryExportPlan::DECISION_REDACT, $r['decisions'][0]['decision']);
    }

    public function test_secret_in_raw_payload_without_redactable_flag_still_hard_rejects(): void
    {
        $r = (new AtlasSelfConstructionReceiptMemoryExportPlan)->plan([
            ['id' => 'p-2', 'kind' => 'decision', 'bound' => true, 'fact_summary' => 'clean summary', 'raw_payload' => ['TOKEN' => 'abc123']],
        ]);
        $this->assertSame([], $r['export_plan']);
        $this->assertSame('rejected:contains_secret', $r['rejections'][0]['reason']);
    }

    public function test_decisions_cover_every_candidate(): void
    {
        $r = (new AtlasSelfConstructionReceiptMemoryExportPlan)->plan([
            ['id' => 'a-1', 'kind' => 'decision', 'bound' => true, 'fact_summary' => 'ok'],
            ['id' => 'a-2', 'kind' => 'decision', 'bound' => false, 'fact_summary' => 'ok'],
        ]);
        $this->assertCount(2, $r['decisions']);
    }
}

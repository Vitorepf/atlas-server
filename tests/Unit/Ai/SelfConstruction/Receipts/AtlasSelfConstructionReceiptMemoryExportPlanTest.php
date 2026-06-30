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
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasEvidenceLedgerContractService;
use Tests\TestCase;

/**
 * Pins the documented Evidence Ledger contract rules.
 *
 * @see docs/engineering-knowledge-base/system-graph/evidence-ledger.md
 */
final class AtlasEvidenceLedgerContractTest extends TestCase
{
    private AtlasEvidenceLedgerContractService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AtlasEvidenceLedgerContractService;
    }

    /**
     * The doc "Exemplos" shape: a gate event records command, status, duration,
     * output, trace + receipt.
     *
     * @return array<string,mixed>
     */
    private function gateEvent(array $overrides = []): array
    {
        return array_merge([
            'kind' => 'gate',
            'command' => 'php artisan test',
            'status' => 'ok',
            'duration' => 1.2,
            'output' => 'OK (10 tests)',
            'trace' => 'trace_1',
            'receipt' => 'dec_1',
        ], $overrides);
    }

    /** "Exemplos" — a complete factual gate event is admitted as evidence. */
    public function test_complete_gate_event_is_admitted_as_evidence(): void
    {
        $d = $this->service->admit($this->gateEvent());

        $this->assertSame('admit', $d['verdict']);
        $this->assertTrue($d['admitted']);
        $this->assertSame('factual_evidence', $d['reason']);
        $this->assertSame('gate', $d['kind']);
    }

    /** Risco "Ledger virar log solto sem schema" — an unknown kind is rejected. */
    public function test_unknown_kind_is_rejected_as_schemaless(): void
    {
        $d = $this->service->admit(['kind' => 'gossip', 'status' => 'ok', 'trace' => 't']);

        $this->assertSame('reject', $d['verdict']);
        $this->assertSame('unknown_kind', $d['reason']);
        $this->assertContains('gate', $d['detail']['allowed_kinds']);
    }

    /**
     * "Regras para IA" — "Comentario de agente nao substitui evento, teste, path,
     * run ou receipt." A payload with no factual anchor is reclassified as
     * interpretation, NOT admitted as evidence.
     */
    public function test_agent_commentary_without_anchor_is_interpretation_not_evidence(): void
    {
        $d = $this->service->admit([
            'kind' => 'decision',
            'note' => 'I think this refactor went really well and looks clean.',
        ]);

        $this->assertSame('interpretation', $d['verdict']);
        $this->assertFalse($d['admitted']);
        $this->assertSame('no_factual_anchor', $d['reason']);
    }

    /** "Exemplos" completeness — a gate missing required factual fields is rejected and names the gap. */
    public function test_incomplete_gate_event_is_rejected_with_missing_fields(): void
    {
        $event = $this->gateEvent();
        unset($event['duration'], $event['receipt']);

        $d = $this->service->admit($event);

        $this->assertSame('reject', $d['verdict']);
        $this->assertSame('incomplete_event', $d['reason']);
        $this->assertContains('duration', $d['detail']['missing_fields']);
        $this->assertContains('receipt', $d['detail']['missing_fields']);
    }

    /** "Escopo" — append is always allowed; a rewrite of recorded history without policy stops. */
    public function test_append_only_blocks_rewrite_without_policy(): void
    {
        $append = $this->service->assertWritePolicy('append');
        $this->assertSame('append', $append['verdict']);
        $this->assertTrue($append['allowed']);

        $rewrite = $this->service->assertWritePolicy('update', ['targets_existing' => true]);
        $this->assertSame('stop', $rewrite['verdict']);
        $this->assertSame('rewrite_without_policy', $rewrite['reason']);

        $authorized = $this->service->assertWritePolicy('update', [
            'targets_existing' => true,
            'policy' => 'retention.correction.v1',
        ]);
        $this->assertSame('append', $authorized['verdict']);
        $this->assertSame('rewrite_authorized_by_policy', $authorized['reason']);
    }

    /**
     * Risco "Retention destruir informacao necessaria" — a record under hold is
     * never pruned regardless of age; an old, unheld record may be pruned.
     */
    public function test_retention_never_prunes_a_held_record(): void
    {
        $held = $this->service->retentionDecision(
            ['age_days' => 9999, 'legal_hold' => true],
            retentionFloorDays: 365
        );
        $this->assertSame('stop', $held['verdict']);
        $this->assertSame('under_hold', $held['reason']);

        $tooYoung = $this->service->retentionDecision(['age_days' => 30], retentionFloorDays: 365);
        $this->assertSame('stop', $tooYoung['verdict']);
        $this->assertSame('within_retention_floor', $tooYoung['reason']);

        $prunable = $this->service->retentionDecision(['age_days' => 400], retentionFloorDays: 365);
        $this->assertSame('append', $prunable['verdict']);
        $this->assertSame('prune_authorized', $prunable['reason']);
    }

    /** Bulk admission quarantines every non-evidence candidate in input order. */
    public function test_rejected_returns_only_non_evidence_candidates(): void
    {
        $events = [
            $this->gateEvent(),                                  // admit
            ['kind' => 'decision', 'note' => 'looks good'],      // interpretation
            ['kind' => 'mystery', 'status' => 'ok', 'trace' => 't'], // reject (unknown kind)
        ];

        $rejected = $this->service->rejected($events);

        $this->assertCount(2, $rejected);
        $this->assertSame(1, $rejected[0]['index']);
        $this->assertSame('interpretation', $rejected[0]['verdict']);
        $this->assertSame(2, $rejected[1]['index']);
        $this->assertSame('reject', $rejected[1]['verdict']);
        $this->assertSame('unknown_kind', $rejected[1]['reason']);
    }
}

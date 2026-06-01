<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasEvidenceLoopService;
use Tests\TestCase;

/**
 * Pins the documented Evidence Loop rules.
 *
 * @see docs/engineering-knowledge-base/system-graph/evidence-loop.md
 */
class AtlasEvidenceLoopTest extends TestCase
{
    private function service(): AtlasEvidenceLoopService
    {
        return new AtlasEvidenceLoopService();
    }

    /**
     * THE invariant ("sinal sem evidencia nao governa decisao"): a signal with
     * no evidence-ledger reference is quarantined and may not govern.
     */
    public function test_signal_without_evidence_does_not_govern(): void
    {
        $d = $this->service()->governSignal([
            'kind' => 'cost',
            'evidence_ref' => '',
            'metric_value' => 12.0,
        ]);

        $this->assertSame(AtlasEvidenceLoopService::ACTION_QUARANTINE, $d['action']);
        $this->assertFalse($d['may_govern']);
        $this->assertFalse($d['evidence_backed']);
        $this->assertContains('missing_evidence_ref', $d['quarantine_reasons']);
        $this->assertFalse($this->service()->mayGovern(['kind' => 'cost', 'metric_value' => 12.0]));
    }

    /**
     * A complete, evidence-backed, in-bounds, gate-passed signal is cleared to
     * govern Decide/Learning.
     */
    public function test_evidence_backed_clean_signal_governs(): void
    {
        $d = $this->service()->governSignal([
            'kind' => 'latency',
            'evidence_ref' => 'ledger://trace/ap-99',
            'evidence_complete' => true,
            'gate_result' => 'pass',
            'has_outcome' => true,
            'claims_success' => true,
            'metric_value' => 850.0,
            'metric_max' => 5000.0,
        ]);

        $this->assertSame(AtlasEvidenceLoopService::ACTION_GOVERN, $d['action']);
        $this->assertTrue($d['may_govern']);
        $this->assertTrue($d['evidence_backed']);
        $this->assertSame([], $d['reject_reasons']);
        $this->assertSame([], $d['quarantine_reasons']);
    }

    /**
     * Escopo Proibido ("inferir sucesso sem gate ou outcome"): a signal that
     * claims success while no gate passed and no outcome exists is rejected, not
     * merely held — even though it carries a complete evidence ref.
     */
    public function test_success_inferred_without_gate_or_outcome_is_rejected(): void
    {
        $d = $this->service()->governSignal([
            'kind' => 'outcome',
            'evidence_ref' => 'ledger://run/42',
            'evidence_complete' => true,
            'claims_success' => true,
            'gate_result' => '',
            'has_outcome' => false,
        ]);

        $this->assertSame(AtlasEvidenceLoopService::ACTION_REJECT, $d['action']);
        $this->assertFalse($d['may_govern']);
        $this->assertContains('success_inferred_without_gate_or_outcome', $d['reject_reasons']);
    }

    /**
     * Riscos ("Metricas contaminadas reforcarem decisao ruim"): an explicitly
     * contaminated metric, or one outside its sane bounds, is rejected and can
     * never govern.
     */
    public function test_contaminated_or_out_of_bounds_metric_is_rejected(): void
    {
        $tainted = $this->service()->governSignal([
            'kind' => 'cost',
            'evidence_ref' => 'ledger://x',
            'evidence_complete' => true,
            'gate_result' => 'pass',
            'contaminated' => true,
        ]);
        $this->assertSame(AtlasEvidenceLoopService::ACTION_REJECT, $tainted['action']);
        $this->assertContains('metric_contaminated', $tainted['reject_reasons']);

        $oob = $this->service()->governSignal([
            'kind' => 'latency',
            'evidence_ref' => 'ledger://y',
            'evidence_complete' => true,
            'gate_result' => 'pass',
            'metric_value' => -3.0,
            'metric_min' => 0.0,
        ]);
        $this->assertSame(AtlasEvidenceLoopService::ACTION_REJECT, $oob['action']);
        $this->assertContains('metric_out_of_bounds', $oob['reject_reasons']);
    }

    /**
     * Riscos ("Evidence incompleta parecer sucesso"): partial evidence is
     * quarantined (withheld), not trusted.
     */
    public function test_incomplete_evidence_is_quarantined(): void
    {
        $d = $this->service()->governSignal([
            'kind' => 'gate_failure',
            'evidence_ref' => 'ledger://partial',
            'evidence_complete' => false,
            'gate_result' => 'fail',
        ]);

        $this->assertSame(AtlasEvidenceLoopService::ACTION_QUARANTINE, $d['action']);
        $this->assertFalse($d['may_govern']);
        $this->assertContains('incomplete_evidence', $d['quarantine_reasons']);
    }

    /**
     * Escopo Permitido (aggregation of signals/metrics): only clean,
     * evidence-backed signals fold into the calibrated aggregate that feeds
     * Decide; quarantined and rejected signals are counted for audit but
     * excluded from the governing numbers.
     */
    public function test_aggregate_only_counts_governing_signals(): void
    {
        $govern = [
            'kind' => 'cost', 'evidence_ref' => 'l://a', 'evidence_complete' => true,
            'gate_result' => 'pass', 'has_outcome' => true, 'metric_value' => 10.0, 'metric_max' => 1000.0,
        ];
        $governTwo = [
            'kind' => 'cost', 'evidence_ref' => 'l://b', 'evidence_complete' => true,
            'gate_result' => 'pass', 'has_outcome' => true, 'metric_value' => 30.0, 'metric_max' => 1000.0,
        ];
        $noEvidence = ['kind' => 'cost', 'metric_value' => 999.0]; // quarantined
        $tainted = [
            'kind' => 'latency', 'evidence_ref' => 'l://c', 'evidence_complete' => true,
            'contaminated' => true, 'metric_value' => 5.0,
        ]; // rejected

        $agg = $this->service()->aggregate([$govern, $governTwo, $noEvidence, $tainted]);

        $this->assertSame(4, $agg['total_signals']);
        $this->assertSame(2, $agg['governing_signals']);
        $this->assertSame(1, $agg['quarantined_signals']);
        $this->assertSame(1, $agg['rejected_signals']);
        $this->assertTrue($agg['feeds_decide']);

        // Only the two governing cost signals contribute: 10 + 30 = 40, mean 20.
        $this->assertSame(2, $agg['calibrated']['cost']['count']);
        $this->assertSame(40.0, $agg['calibrated']['cost']['sum']);
        $this->assertSame(20.0, $agg['calibrated']['cost']['mean']);
        // The tainted latency signal never reaches the calibrated metrics.
        $this->assertArrayNotHasKey('latency', $agg['calibrated']);
    }

    /**
     * A batch with no clean signal produces no governing aggregate: the loop
     * refuses to feed Decide on evidence-less / dirty input.
     */
    public function test_aggregate_with_no_clean_signal_does_not_feed_decide(): void
    {
        $agg = $this->service()->aggregate([
            ['kind' => 'cost', 'metric_value' => 5.0],            // no evidence → quarantine
            ['kind' => 'outcome', 'evidence_ref' => 'l://z', 'evidence_complete' => true, 'claims_success' => true], // inferred success → reject
        ]);

        $this->assertSame(0, $agg['governing_signals']);
        $this->assertFalse($agg['feeds_decide']);
        $this->assertSame([], $agg['calibrated']);
    }

    /** Every decision is auditable and carries the stable receipt schema. */
    public function test_decision_is_auditable_with_stable_schema(): void
    {
        $signal = $this->service()->governSignal([]);
        $this->assertSame('atlas.evidence.loop.signal.v1', $signal['schema']);
        $this->assertTrue($signal['auditable']);

        $agg = $this->service()->aggregate([]);
        $this->assertSame('atlas.evidence.loop.aggregate.v1', $agg['schema']);
        $this->assertTrue($agg['auditable']);
    }
}

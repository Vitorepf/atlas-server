<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\AcosMax;

use App\Services\Ai\Autonomy\AtlasAutonomyLadderRuntimeService;
use App\Services\Ai\Autonomy\AtlasAutonomyMetricsAuthorityPort;
use App\Services\Ai\Autonomy\SealedLedgerAutonomyMetricsAuthority;
use Tests\TestCase;

/**
 * MAXK-06 — metrics from ledger AUTHORITY, not from caller.
 *
 * The pre-MAXK-06 gate accepted `evaluatePromotion($currentLevel, $metrics,
 * $signatures)` with the metric map handed straight from the caller — the same
 * actor asking for a promotion supplied the numbers that decided it (the
 * `--signals=` payload on `atlas:autonomy:ladder`). This suite forges those
 * numbers and proves the AUTHORITATIVE path refuses them, and proves that
 * only a sealed authority entry — whose recomputed `entry_hash` matches the
 * stored one — flips the verdict. Report mode (`evaluatePromotion`) stays
 * byte-identical.
 *
 * @covers \App\Services\Ai\Autonomy\SealedLedgerAutonomyMetricsAuthority
 * @covers \App\Services\Ai\Autonomy\AtlasAutonomyLadderRuntimeService::evaluatePromotionAuthoritative
 */
final class Maxk06MetricsAuthorityTest extends TestCase
{
    private string $ledgerPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ledgerPath = tempnam(sys_get_temp_dir(), 'maxk06-authority-').'.ndjson';
        if (is_file($this->ledgerPath)) {
            @unlink($this->ledgerPath);
        }
        config()->set('atlas.ai.autonomy_ladder.metrics_authority_ledger_path', $this->ledgerPath);
    }

    protected function tearDown(): void
    {
        if (is_file($this->ledgerPath)) {
            @unlink($this->ledgerPath);
        }
        parent::tearDown();
    }

    public function test_caller_supplied_metrics_do_not_alter_the_authoritative_verdict(): void
    {
        // Adversary: hand the report calculator numbers that WOULD pass L0->L1.
        $forged = [
            'assist_sessions' => 999,
            'acceptance_rate' => 1.0,
            'severe_hallucination_count' => 0,
        ];
        $signatures = ['operator' => true];

        $service = $this->service();

        // Report mode: unchanged calculator surface accepts caller metrics.
        $report = $service->evaluatePromotion('L0', $forged, $signatures);
        $this->assertTrue($report['eligible']);
        $this->assertSame('promote', $report['decision']);

        // Authoritative gate: same calling actor, same forged numbers — the
        // gate never sees them, because it reads from the (empty) authority.
        $authoritative = $service->evaluatePromotionAuthoritative(
            'L0',
            new SealedLedgerAutonomyMetricsAuthority($this->ledgerPath),
            $signatures,
        );

        $this->assertFalse($authoritative['eligible']);
        $this->assertSame('blocked', $authoritative['decision']);
        $this->assertSame('metrics_authority_missing', $authoritative['refusal_reason']);
        $this->assertSame(
            AtlasAutonomyMetricsAuthorityPort::SOURCE_MISSING,
            $authoritative['metrics_authority']['source'],
        );
        $this->assertFalse($authoritative['metrics_authority']['verified']);
        $this->assertSame([], $authoritative['metrics']);
    }

    public function test_sealed_entry_matching_exit_criteria_promotes_and_carries_provenance(): void
    {
        $authority = new SealedLedgerAutonomyMetricsAuthority($this->ledgerPath);

        $sealed = $authority->seal(
            level: 'L1',
            metrics: [
                'assist_sessions' => 50,
                'acceptance_rate' => 0.85,
                'severe_hallucination_count' => 0,
            ],
            sourceId: 'asi05-telemetry-day-1',
        );

        $verdict = $this->service()->evaluatePromotionAuthoritative(
            'L0',
            $authority,
            ['operator' => true],
        );

        $this->assertTrue($verdict['eligible'], 'sealed metrics ≥ exit thresholds must promote');
        $this->assertSame('promote', $verdict['decision']);
        $provenance = $verdict['metrics_authority'];
        $this->assertTrue($provenance['verified']);
        $this->assertSame(SealedLedgerAutonomyMetricsAuthority::SOURCE_SEALED, $provenance['source']);
        $this->assertSame('asi05-telemetry-day-1', $provenance['source_id']);
        $this->assertSame($sealed['entry_hash'], $provenance['entry_hash']);
        $this->assertNotSame('', $provenance['sealed_at']);
    }

    public function test_post_seal_tampering_of_metric_value_is_refused_as_tampered(): void
    {
        $authority = new SealedLedgerAutonomyMetricsAuthority($this->ledgerPath);
        $authority->seal(
            level: 'L1',
            metrics: [
                'assist_sessions' => 10,
                'acceptance_rate' => 0.10,
                'severe_hallucination_count' => 5,
            ],
            sourceId: 'asi05-telemetry-day-1',
        );

        // Adversary rewrites the metrics on-disk but keeps the original
        // entry_hash — the recompute must catch the divergence.
        $rows = array_filter(explode("\n", (string) file_get_contents($this->ledgerPath)));
        $this->assertCount(1, $rows, 'seal must produce exactly one line');
        $entry = json_decode($rows[0], true);
        $this->assertIsArray($entry);
        $entry['metrics']['assist_sessions'] = 999;
        $entry['metrics']['acceptance_rate'] = 1.0;
        $entry['metrics']['severe_hallucination_count'] = 0;
        // entry_hash left ALONE — this is the forgery.
        file_put_contents($this->ledgerPath, json_encode($entry)."\n");

        $verdict = $this->service()->evaluatePromotionAuthoritative(
            'L0',
            new SealedLedgerAutonomyMetricsAuthority($this->ledgerPath),
            ['operator' => true],
        );

        $this->assertFalse($verdict['eligible']);
        $this->assertSame('blocked', $verdict['decision']);
        $this->assertSame('metrics_authority_tampered', $verdict['refusal_reason']);
        $this->assertSame(
            AtlasAutonomyMetricsAuthorityPort::SOURCE_TAMPERED,
            $verdict['metrics_authority']['source'],
        );
        $this->assertFalse($verdict['metrics_authority']['verified']);
        $this->assertSame([], $verdict['metrics'], 'tampered entry must never publish its rewritten metrics');
    }

    public function test_report_mode_evaluate_promotion_is_byte_identical_after_the_new_authoritative_path_lands(): void
    {
        $metrics = [
            'assist_sessions' => 50,
            'acceptance_rate' => 0.80,
            'severe_hallucination_count' => 0,
        ];
        $signatures = ['operator' => true];

        $baselineNoSignatures = $this->service()->evaluatePromotion('L0', $metrics, []);
        $this->assertFalse($baselineNoSignatures['eligible']);
        $this->assertSame('blocked', $baselineNoSignatures['decision']);

        $withOperator = $this->service()->evaluatePromotion('L0', $metrics, $signatures);
        $this->assertTrue($withOperator['eligible']);
        $this->assertSame('promote', $withOperator['decision']);

        // Report mode NEVER attaches a provenance envelope — that field is
        // exclusive to the authoritative gate.
        $this->assertArrayNotHasKey('metrics_authority', $withOperator);
    }

    public function test_promotion_at_ceiling_returns_ceiling_reason_via_authoritative_path(): void
    {
        $verdict = $this->service()->evaluatePromotionAuthoritative(
            'L7',
            new SealedLedgerAutonomyMetricsAuthority($this->ledgerPath),
            ['operator' => true, 'architect' => true, 'architect_human_review' => true],
        );

        $this->assertFalse($verdict['eligible']);
        $this->assertSame('at_ceiling', $verdict['decision']);
        $this->assertSame(
            AtlasAutonomyMetricsAuthorityPort::SOURCE_MISSING,
            $verdict['metrics_authority']['source'],
        );
    }

    private function service(): AtlasAutonomyLadderRuntimeService
    {
        return new AtlasAutonomyLadderRuntimeService;
    }
}

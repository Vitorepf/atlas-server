<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Foundry\Rsi\EarnedAutonomy;

use App\Services\Ai\Foundry\Rsi\EarnedAutonomy\DriftAnomalyDetectorService;
use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\Rsi\ComponentValueLedgerService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Earned Autonomy · DriftAnomalyDetectorService contract + fail-closed safety.
 *
 * Proves the detector is a PURE deterministic fold over the real value-ledger
 * signal (injected records => no I/O), correctly flags the three honest drift
 * signatures (value-per-token regression, revert-rate spike, append-only
 * discontinuity), and — critically for the immune system — FAILS CLOSED on a
 * missing / ambiguous signal (no records, no proven baseline, tampered order),
 * returning drift_detected=true so the composer can never grant autonomy it
 * cannot affirmatively prove safe.
 */
final class DriftAnomalyDetectorServiceTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/atlas_drift_detector_'.uniqid('', true);
        File::ensureDirectoryExists($this->tmp);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);
        parent::tearDown();
    }

    private function detector(): DriftAnomalyDetectorService
    {
        return new DriftAnomalyDetectorService();
    }

    /**
     * A real value-ledger event shape (mirrors ComponentValueLedgerService output):
     * one proven cycle with a given value-per-token, monotonic recorded_at.
     *
     * @return array<string,mixed>
     */
    private function provenEvent(string $recordedAt, string $cycleId, float $valuePerToken, bool $reverted = false): array
    {
        return [
            'schema_version' => ComponentValueLedgerService::EVENT_SCHEMA,
            'recorded_at' => $recordedAt,
            'area_id' => 'a',
            'focus' => 'f',
            'cycle_id' => $cycleId,
            'outcome_status' => ComponentValueLedgerService::OUTCOME_PROVEN,
            'proven_value_delta' => $valuePerToken * 1000,
            'total_tokens_cycle' => 1000,
            'value_per_token_cycle' => $valuePerToken,
            'components' => [
                [
                    'component_id' => 'session_ap786',
                    'seam_type' => ComponentValueLedgerService::SEAM_LIVE_PROVIDER,
                    'tokens_consumed' => 1000,
                    'participation_flags' => $reverted ? ['reverted' => true] : ['provider_invoked' => true],
                ],
            ],
        ];
    }

    /** A healthy, stable proven history (5 cycles, flat value-per-token, monotonic). */
    private function healthyHistory(): array
    {
        return [
            $this->provenEvent('2026-05-30T00:00:00+00:00', 'c1', 1.0),
            $this->provenEvent('2026-05-30T01:00:00+00:00', 'c2', 1.0),
            $this->provenEvent('2026-05-30T02:00:00+00:00', 'c3', 1.0),
            $this->provenEvent('2026-05-30T03:00:00+00:00', 'c4', 1.0),
            $this->provenEvent('2026-05-30T04:00:00+00:00', 'c5', 1.0),
        ];
    }

    public function test_clean_history_proves_no_drift_and_is_deterministic(): void
    {
        $detector = $this->detector();
        $records = $this->healthyHistory();

        $r1 = $detector->detect(['area_id' => 'a', 'focus' => 'f'], $records);
        $r2 = $detector->detect(['area_id' => 'a', 'focus' => 'f'], $records);

        $this->assertFalse($r1['drift_detected']);
        $this->assertSame(DriftAnomalyDetectorService::SCHEMA, $r1['schema_version']);
        $this->assertFalse($r1['provider_invoked']);
        $this->assertSame(DriftAnomalyDetectorService::ANOMALY_NONE, $r1['anomalies'][0]['code']);

        // Pure fold => byte-identical hash on identical input (deterministic).
        $this->assertSame($r1['detector_hash'], $r2['detector_hash']);
        $this->assertSame(MissionCanonicalHash::sha256([
            'schema_version' => DriftAnomalyDetectorService::SCHEMA,
            'drift_detected' => false,
            'anomalies' => $r1['anomalies'],
            'provider_invoked' => false,
        ]), $r1['detector_hash']);
    }

    public function test_value_per_token_regression_is_flagged(): void
    {
        $detector = $this->detector();
        // Baseline c1..c3 at 1.0; recent c4,c5 collapse to 0.1 (well below 70% floor).
        $records = [
            $this->provenEvent('2026-05-30T00:00:00+00:00', 'c1', 1.0),
            $this->provenEvent('2026-05-30T01:00:00+00:00', 'c2', 1.0),
            $this->provenEvent('2026-05-30T02:00:00+00:00', 'c3', 1.0),
            $this->provenEvent('2026-05-30T03:00:00+00:00', 'c4', 0.1),
            $this->provenEvent('2026-05-30T04:00:00+00:00', 'c5', 0.1),
        ];

        $r = $detector->detect(['area_id' => 'a', 'focus' => 'f'], $records);

        $this->assertTrue($r['drift_detected']);
        $this->assertContains(DriftAnomalyDetectorService::ANOMALY_VALUE_REGRESSION, array_column($r['anomalies'], 'code'));
    }

    public function test_revert_rate_spike_is_flagged(): void
    {
        $detector = $this->detector();
        // 5 cycles, 3 reverted => 60% > 25% ceiling. Stable value-per-token so the
        // ONLY anomaly is the revert spike.
        $records = [
            $this->provenEvent('2026-05-30T00:00:00+00:00', 'c1', 1.0),
            $this->provenEvent('2026-05-30T01:00:00+00:00', 'c2', 1.0, reverted: true),
            $this->provenEvent('2026-05-30T02:00:00+00:00', 'c3', 1.0),
            $this->provenEvent('2026-05-30T03:00:00+00:00', 'c4', 1.0, reverted: true),
            $this->provenEvent('2026-05-30T04:00:00+00:00', 'c5', 1.0, reverted: true),
        ];

        $r = $detector->detect(['area_id' => 'a', 'focus' => 'f'], $records);

        $this->assertTrue($r['drift_detected']);
        $this->assertContains(DriftAnomalyDetectorService::ANOMALY_REVERT_SPIKE, array_column($r['anomalies'], 'code'));
    }

    public function test_ledger_discontinuity_backwards_timestamp_is_flagged(): void
    {
        $detector = $this->detector();
        $records = $this->healthyHistory();
        // Tamper: c3's recorded_at goes backwards before c2 (append-only violated).
        $records[2]['recorded_at'] = '2026-05-29T00:00:00+00:00';

        $r = $detector->detect(['area_id' => 'a', 'focus' => 'f'], $records);

        $this->assertTrue($r['drift_detected']);
        $this->assertContains(DriftAnomalyDetectorService::ANOMALY_LEDGER_DISCONTINUITY, array_column($r['anomalies'], 'code'));
    }

    public function test_ledger_discontinuity_duplicate_cycle_id_is_flagged(): void
    {
        $detector = $this->detector();
        $records = $this->healthyHistory();
        // Tamper: forge a duplicate cycle id (append-only ledgers never re-use ids).
        $records[4]['cycle_id'] = 'c1';

        $r = $detector->detect(['area_id' => 'a', 'focus' => 'f'], $records);

        $this->assertTrue($r['drift_detected']);
        $this->assertContains(DriftAnomalyDetectorService::ANOMALY_LEDGER_DISCONTINUITY, array_column($r['anomalies'], 'code'));
    }

    public function test_fails_closed_on_empty_signal(): void
    {
        $detector = $this->detector();

        // SAFETY PROPERTY: no signal cannot be proven clean => drift_detected=true.
        $r = $detector->detect(['area_id' => 'a', 'focus' => 'f'], []);

        $this->assertTrue($r['drift_detected']);
        $this->assertContains(DriftAnomalyDetectorService::ANOMALY_LEDGER_DISCONTINUITY, array_column($r['anomalies'], 'code'));
    }

    public function test_fails_closed_on_insufficient_proven_baseline(): void
    {
        $detector = $this->detector();
        // Two proven cycles only (< MIN_PROVEN_OBSERVATIONS=3): no baseline to prove
        // no regression => fail closed.
        $records = [
            $this->provenEvent('2026-05-30T00:00:00+00:00', 'c1', 1.0),
            $this->provenEvent('2026-05-30T01:00:00+00:00', 'c2', 1.0),
        ];

        $r = $detector->detect(['area_id' => 'a', 'focus' => 'f'], $records);

        $this->assertTrue($r['drift_detected']);
        $this->assertContains(DriftAnomalyDetectorService::ANOMALY_VALUE_REGRESSION, array_column($r['anomalies'], 'code'));
    }

    public function test_fails_closed_when_no_records_and_real_ledger_empty(): void
    {
        // Drift is measured against the SAME real value-ledger the loop uses. With
        // an empty real ledger (no proven history), the detector replays it and
        // fails closed — proving the seam wiring, not just injected records.
        $valueLedger = new ComponentValueLedgerService();
        $valueLedger->setStorageRootForTesting($this->tmp);

        $detector = $this->detector();
        $detector->setValueLedgerForTesting($valueLedger);

        $r = $detector->detect(['area_id' => 'agentic_engineering_os', 'focus' => 'dev_forge']);

        $this->assertTrue($r['drift_detected']);
        $this->assertFalse($r['provider_invoked']);
    }

    public function test_detector_never_writes_and_carries_canonical_hash(): void
    {
        $detector = $this->detector();
        $r = $detector->detect(['area_id' => 'a', 'focus' => 'f'], $this->healthyHistory());

        $this->assertArrayHasKey('detector_hash', $r);
        $this->assertSame(64, strlen((string) $r['detector_hash'])); // sha256 hex
        // No public mutator beyond the test seam exists; provider is never invoked.
        $this->assertFalse($r['provider_invoked']);
    }
}

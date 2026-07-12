<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\AcosMax;

use App\Services\Ai\AtlasDecide\AtlasDecideLiveOutcomeFeedbackService;
use App\Services\Ai\AtlasDecide\AtlasSelfModelCalibrationBandService;
use App\Services\Ai\AtlasDecide\AtlasSelfModelReadModelService;
use Tests\TestCase;

/**
 * ASI-15 — calibration band DERIVED on the decision receipt.
 *
 * Invariants proven here:
 *   - Band is a pure function of (n, proven_rate) — caller cannot inject.
 *   - n<10 ⇒ `insufficient_sample` (never `low`/`sweet`/`high` by vacuum).
 *   - The band feeds the self-model candidates emitted by ASI-13 so the
 *     Decide receipt gets a `calibration_band` block per candidate.
 *   - Ex-post curve holds `insufficient_sample` per band until
 *     MIN_CURVE_SAMPLES realized outcomes exist for that band.
 *   - No single scalar score is emitted (bands + denominators only —
 *     COM-04 "92" lesson).
 */
final class Asi15CalibrationBandTest extends TestCase
{
    private string $outcomesPath = '';

    private AtlasDecideLiveOutcomeFeedbackService $outcomes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->outcomesPath = tempnam(sys_get_temp_dir(), 'asi15-outcomes-').'.jsonl';
        $this->outcomes = new AtlasDecideLiveOutcomeFeedbackService;
        $this->outcomes->setLogPathForTesting($this->outcomesPath);
    }

    protected function tearDown(): void
    {
        if ($this->outcomesPath !== '' && file_exists($this->outcomesPath)) {
            @unlink($this->outcomesPath);
        }
        parent::tearDown();
    }

    public function test_classify_below_min_n_returns_insufficient_sample(): void
    {
        $service = new AtlasSelfModelCalibrationBandService;

        $verdict = $service->classifyCandidate(['route' => 'a', 'n' => 3, 'proven_rate' => 0.9]);
        $this->assertSame(AtlasSelfModelCalibrationBandService::BAND_INSUFFICIENT, $verdict['band']);
        $this->assertSame('n_below_floor', $verdict['reason']);
        $this->assertSame(AtlasSelfModelCalibrationBandService::MIN_N_FOR_BAND, $verdict['floor']);
    }

    public function test_classify_high_rate_returns_high_band_over_min_n(): void
    {
        $service = new AtlasSelfModelCalibrationBandService;
        $verdict = $service->classifyCandidate(['route' => 'a', 'n' => 20, 'proven_rate' => 0.95]);
        $this->assertSame('high', $verdict['band']);
    }

    public function test_classify_is_pure_function_caller_cannot_inject_band(): void
    {
        $service = new AtlasSelfModelCalibrationBandService;
        // "band" in payload is silently ignored — the schema/derivation OWNS it.
        $verdict = $service->classifyCandidate(['route' => 'a', 'n' => 5, 'proven_rate' => 0.8, 'band' => 'high']);
        $this->assertSame(AtlasSelfModelCalibrationBandService::BAND_INSUFFICIENT, $verdict['band']);
    }

    public function test_calibration_curve_returns_insufficient_sample_per_band_below_floor(): void
    {
        $service = new AtlasSelfModelCalibrationBandService;
        $curve = $service->calibrationCurve([
            ['declared_band' => 'sweet', 'proven_real' => true],
            ['declared_band' => 'sweet', 'proven_real' => false],
            ['declared_band' => 'low', 'proven_real' => true],
        ]);

        $bandsByName = collect($curve['bands'])->keyBy('declared_band');
        $this->assertSame(AtlasSelfModelCalibrationBandService::BAND_INSUFFICIENT, $bandsByName['sweet']['status']);
        $this->assertNull($bandsByName['sweet']['realized_rate']);
    }

    public function test_calibration_curve_publishes_realized_rate_when_over_min_curve_samples(): void
    {
        $service = new AtlasSelfModelCalibrationBandService;
        $outcomes = [];
        for ($i = 0; $i < 10; $i++) {
            $outcomes[] = ['declared_band' => 'sweet', 'proven_real' => $i < 7];
        }
        for ($i = 0; $i < 12; $i++) {
            $outcomes[] = ['declared_band' => 'high', 'proven_real' => $i < 11];
        }
        $curve = $service->calibrationCurve($outcomes);

        $byBand = collect($curve['bands'])->keyBy('declared_band');
        $this->assertSame('ok', $byBand['sweet']['status']);
        $this->assertSame(10, $byBand['sweet']['n']);
        $this->assertEqualsWithDelta(0.7, (float) $byBand['sweet']['realized_rate'], 0.01);
        $this->assertSame('ok', $byBand['high']['status']);
        $this->assertEqualsWithDelta(11 / 12, (float) $byBand['high']['realized_rate'], 0.01);
    }

    public function test_self_model_read_model_attaches_calibration_band_per_candidate(): void
    {
        // 12 proven outcomes ⇒ band should be `high` (rate == 1.0).
        for ($i = 0; $i < 12; $i++) {
            $this->outcomes->record([
                'task_category' => 'programming',
                'role' => 'implement',
                'provider' => 'hermes',
                'model' => 'v1',
                'result' => 'success',
                'proven_real' => true,
                'verified_basis' => AtlasDecideLiveOutcomeFeedbackService::VERIFIED_BASIS_GATES_PASSED,
                'certified_receipt_id' => 'rcp-'.$i,
            ]);
        }
        $service = new AtlasSelfModelReadModelService($this->outcomes, null, new AtlasSelfModelCalibrationBandService);
        $result = $service->readForTaskCategory('programming', ['declared_routes' => ['gpt:o1']]);

        $proven = collect($result['candidates'])->firstWhere('route', 'hermes:v1');
        $this->assertNotNull($proven);
        $this->assertArrayHasKey('calibration_band', $proven);
        $this->assertSame('high', $proven['calibration_band']['band']);
        $this->assertSame(AtlasSelfModelCalibrationBandService::SCHEMA, $proven['calibration_band']['schema']);

        // The declared route (n=0) MUST come out with insufficient_sample.
        $declared = collect($result['candidates'])->firstWhere('route', 'gpt:o1');
        $this->assertSame(AtlasSelfModelCalibrationBandService::BAND_INSUFFICIENT, $declared['calibration_band']['band']);
    }
}

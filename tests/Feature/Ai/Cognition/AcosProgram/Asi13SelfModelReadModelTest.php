<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Cognition\AcosProgram;

use App\Services\Ai\AtlasDecide\AtlasDecideLiveOutcomeFeedbackService;
use App\Services\Ai\AtlasDecide\AtlasSelfModelReadModelService;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainModelCapabilityGapLedger;
use Tests\TestCase;

/**
 * ASI-13 — self-model READ MODEL consumed by the Decide layer.
 *
 * Proves the honest-basis invariants the plan writes:
 *   - `proven` basis requires >=MIN_PROVEN outcomes AND >=MIN_PROVEN with
 *     `verified_basis in {server_verified, gates_passed}` AND at least one
 *     `proven_real=true`. A route with n=8 all-success WITHOUT gates never
 *     reports `proven`.
 *   - `declared` basis: caller-supplied route with no outcome is admitted
 *     with `basis=declared, n=0` — but ranked LAST.
 *   - The read model calls `AtlasExternalBrainModelCapabilityGapLedger`
 *     (the ledger's first Decide caller — the plan's "0 callers" fix).
 *   - Rate/n are DERIVED — the caller cannot inject a fake `basis`.
 */
final class Asi13SelfModelReadModelTest extends TestCase
{
    private string $outcomesPath = '';

    private AtlasDecideLiveOutcomeFeedbackService $outcomes;

    protected function setUp(): void
    {
        parent::setUp();

        $this->outcomesPath = tempnam(sys_get_temp_dir(), 'asi13-outcomes-').'.jsonl';
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

    public function test_route_with_n_ten_all_gates_passed_and_proven_real_reports_basis_proven(): void
    {
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
                'quality_score' => 0.9,
            ]);
        }

        $service = new AtlasSelfModelReadModelService($this->outcomes, new AtlasExternalBrainModelCapabilityGapLedger);
        $result = $service->readForTaskCategory('programming');

        $this->assertNotEmpty($result['candidates']);
        $top = $result['candidates'][0];
        $this->assertSame('hermes:v1', $top['route']);
        $this->assertSame(AtlasSelfModelReadModelService::BASIS_PROVEN, $top['basis']);
        $this->assertSame(12, $top['n']);
        $this->assertGreaterThanOrEqual(10, $top['n_weighted_basis']);
    }

    public function test_route_with_n_below_min_proven_never_claims_proven_basis(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->outcomes->record([
                'task_category' => 'programming',
                'role' => 'implement',
                'provider' => 'claude',
                'model' => 'sonnet',
                'result' => 'success',
                'proven_real' => true,
                'verified_basis' => AtlasDecideLiveOutcomeFeedbackService::VERIFIED_BASIS_GATES_PASSED,
                'certified_receipt_id' => 'rcp-'.$i,
            ]);
        }

        $service = new AtlasSelfModelReadModelService($this->outcomes);
        $result = $service->readForTaskCategory('programming');

        $this->assertNotEmpty($result['candidates']);
        $top = $result['candidates'][0];
        $this->assertSame(AtlasSelfModelReadModelService::BASIS_INFERRED, $top['basis'], 'n<MIN_PROVEN must remain inferred, never proven.');
        $this->assertSame(5, $top['n']);
    }

    public function test_declared_route_admitted_with_zero_n_ranked_last(): void
    {
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

        $service = new AtlasSelfModelReadModelService($this->outcomes);
        $result = $service->readForTaskCategory('programming', [
            'declared_routes' => ['gpt:o1'],
        ]);

        $declared = array_values(array_filter($result['candidates'], fn (array $c) => $c['route'] === 'gpt:o1'));
        $this->assertCount(1, $declared);
        $this->assertSame(AtlasSelfModelReadModelService::BASIS_DECLARED, $declared[0]['basis']);
        $this->assertSame(0, $declared[0]['n']);

        // The declared route is ranked AFTER proven.
        $routes = array_column($result['candidates'], 'route');
        $this->assertSame('hermes:v1', $routes[0]);
    }

    public function test_read_model_folds_capability_gap_ledger_as_first_decide_caller(): void
    {
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

        $service = new AtlasSelfModelReadModelService($this->outcomes, new AtlasExternalBrainModelCapabilityGapLedger);
        $result = $service->readForTaskCategory('programming', [
            'capability_gaps' => [
                ['capability' => 'hermes:v1', 'evidence' => 'e2e-slow', 'task_family' => 'programming'],
            ],
        ]);

        $this->assertSame(AtlasExternalBrainModelCapabilityGapLedger::SCHEMA, $result['gaps']['schema']);
        $this->assertGreaterThanOrEqual(1, $result['gaps']['gap_count']);

        $top = $result['candidates'][0];
        $this->assertTrue($top['gap_open']);
    }

    public function test_returns_empty_candidates_when_task_category_has_no_evidence(): void
    {
        $service = new AtlasSelfModelReadModelService($this->outcomes);
        $result = $service->readForTaskCategory('nonexistent');

        $this->assertSame([], $result['candidates']);
        $this->assertSame(0, $result['summary']['proven_routes']);
    }
}

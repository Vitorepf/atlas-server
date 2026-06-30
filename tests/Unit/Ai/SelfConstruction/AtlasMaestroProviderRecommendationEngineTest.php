<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\Maestro\ProviderLearning\AtlasMaestroProviderPerformanceLedger;
use App\Services\Ai\SelfConstruction\Maestro\ProviderLearning\AtlasMaestroProviderRecommendationEngine;
use Tests\TestCase;

final class AtlasMaestroProviderRecommendationEngineTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/atlas-maestro-reco-'.bin2hex(random_bytes(6));
        @mkdir($this->root, 0o755, true);
        AtlasMaestroProviderPerformanceLedger::setRootForTesting($this->root);
    }

    protected function tearDown(): void
    {
        AtlasMaestroProviderPerformanceLedger::setRootForTesting(null);
        foreach ((array) glob($this->root.'/*') as $f) {
            @unlink($f);
        }
        @rmdir($this->root);
        parent::tearDown();
    }

    private function seedRefactorTie(): AtlasMaestroProviderPerformanceLedger
    {
        $ledger = new AtlasMaestroProviderPerformanceLedger();
        // Provider A: 8 success / 2 give_back, avg 4000ms (sample 10).
        for ($i = 0; $i < 8; $i++) {
            $ledger->recordOutcome('A', 'refactor', 'success', 4000, 1700000000 + $i);
        }
        for ($i = 0; $i < 2; $i++) {
            $ledger->recordOutcome('A', 'refactor', 'give_back', 4000, 1700000100 + $i);
        }
        // Provider B: same 8/2 success/give_back, avg 9000ms (sample 10).
        for ($i = 0; $i < 8; $i++) {
            $ledger->recordOutcome('B', 'refactor', 'success', 9000, 1700001000 + $i);
        }
        for ($i = 0; $i < 2; $i++) {
            $ledger->recordOutcome('B', 'refactor', 'give_back', 9000, 1700001100 + $i);
        }

        return $ledger;
    }

    public function test_tie_break_on_avg_duration_picks_faster_provider(): void
    {
        $engine = new AtlasMaestroProviderRecommendationEngine($this->seedRefactorTie());
        $verdict = $engine->bestProviderFor('refactor');

        $this->assertSame('ok', $verdict['status']);
        $this->assertSame('A', $verdict['provider']);
        $this->assertSame(['B'], $verdict['tied_with']);
        $this->assertSame('avg_duration_ms', $verdict['tie_reason']);
    }

    public function test_below_min_sample_size_returns_insufficient_data(): void
    {
        $ledger = new AtlasMaestroProviderPerformanceLedger();
        $ledger->recordOutcome('A', 'refactor', 'success', 1000, 1700000000);
        $ledger->recordOutcome('A', 'refactor', 'success', 1000, 1700000001);
        $engine = new AtlasMaestroProviderRecommendationEngine($ledger, minSampleSize: 5);
        $verdict = $engine->bestProviderFor('refactor');

        $this->assertSame('insufficient_data', $verdict['status']);
        $this->assertSame(5, $verdict['min_required']);
        $this->assertArrayHasKey('A', $verdict['samples_seen']);
        $this->assertArrayNotHasKey('provider', $verdict);
    }

    public function test_unknown_class_returns_insufficient_data(): void
    {
        $engine = new AtlasMaestroProviderRecommendationEngine(new AtlasMaestroProviderPerformanceLedger());
        $verdict = $engine->bestProviderFor('does_not_exist');
        $this->assertSame('insufficient_data', $verdict['status']);
    }

    public function test_stale_provider_facts_yield_stale_data_not_ok(): void
    {
        $ledger = new AtlasMaestroProviderPerformanceLedger();
        $oldTs = 1_000_000;
        for ($i = 0; $i < 5; $i++) {
            $ledger->recordOutcome('hermes', 'refactor', 'success', 1000, $oldTs + $i);
        }
        $now = $oldTs + 86400 * 30; // 30 days after last outcome
        $engine = new AtlasMaestroProviderRecommendationEngine(
            $ledger,
            minSampleSize: 5,
            freshnessWindowSeconds: 7 * 86400, // 7-day window
            clock: static fn (): int => $now,
        );

        $verdict = $engine->bestProviderFor('refactor');

        $this->assertNotSame('ok', $verdict['status'], 'stale data must not return ok');
        $this->assertContains($verdict['status'], ['stale_data', 'insufficient_data']);
    }

    public function test_atlas_native_wins_exact_metric_tie_before_alphabetical_and_engine_is_advisory(): void
    {
        $ledger = new AtlasMaestroProviderPerformanceLedger();
        // 'aaa_other' sorts before 'atlas_native' alphabetically — native must still win.
        for ($i = 0; $i < 5; $i++) {
            $ledger->recordOutcome('atlas_native', 'refactor', 'success', 1000, 1700000000 + $i);
            $ledger->recordOutcome('aaa_other', 'refactor', 'success', 1000, 1700000000 + $i);
        }
        $engine = new AtlasMaestroProviderRecommendationEngine($ledger);
        $verdict = $engine->bestProviderFor('refactor');

        $this->assertSame('ok', $verdict['status']);
        $this->assertSame('atlas_native', $verdict['provider']);
        $this->assertContains('aaa_other', $verdict['tied_with']);
        // Advisory: result must not contain routing or mutation keys.
        $this->assertArrayNotHasKey('route', $verdict);
        $this->assertArrayNotHasKey('applied', $verdict);
    }

    public function test_evidence_complete_provider_beats_evidence_missing_provider_despite_being_slower(): void
    {
        $ledger = new AtlasMaestroProviderPerformanceLedger();
        // Provider fast_no_evidence: 8/2, 1000ms, but NEVER carries required_evidence.
        for ($i = 0; $i < 8; $i++) {
            $ledger->recordOutcome('fast_no_evidence', 'refactor', 'success', 1000, 1700000000 + $i, hasRequiredEvidence: false);
        }
        for ($i = 0; $i < 2; $i++) {
            $ledger->recordOutcome('fast_no_evidence', 'refactor', 'give_back', 1000, 1700000100 + $i);
        }
        // Provider slow_with_evidence: same 8/2, slightly slower (2000ms), but ALWAYS carries required_evidence.
        for ($i = 0; $i < 8; $i++) {
            $ledger->recordOutcome('slow_with_evidence', 'refactor', 'success', 2000, 1700001000 + $i, hasRequiredEvidence: true);
        }
        for ($i = 0; $i < 2; $i++) {
            $ledger->recordOutcome('slow_with_evidence', 'refactor', 'give_back', 2000, 1700001100 + $i);
        }

        $engine = new AtlasMaestroProviderRecommendationEngine($ledger);
        $verdict = $engine->bestProviderFor('refactor');

        $this->assertSame('ok', $verdict['status']);
        $this->assertSame('slow_with_evidence', $verdict['provider']);
        $this->assertTrue($verdict['evidence_complete']);
        $this->assertSame('evidence_complete', $verdict['tie_reason']);
    }

    public function test_cost_and_duration_only_break_ties_after_success_give_back_and_evidence(): void
    {
        $ledger = new AtlasMaestroProviderPerformanceLedger();
        // cheap_but_flaky: lower give_back rate loses (more give_backs) despite being cheap/fast.
        for ($i = 0; $i < 5; $i++) {
            $ledger->recordOutcome('cheap_but_flaky', 'refactor', 'success', 500, 1700000000 + $i, tokenCostEstimate: 10, hasRequiredEvidence: true);
        }
        for ($i = 0; $i < 5; $i++) {
            $ledger->recordOutcome('cheap_but_flaky', 'refactor', 'give_back', 500, 1700000100 + $i);
        }
        // reliable_pricier: zero give_backs, more expensive/slower, but wins on success/give_back tier.
        for ($i = 0; $i < 10; $i++) {
            $ledger->recordOutcome('reliable_pricier', 'refactor', 'success', 5000, 1700001000 + $i, tokenCostEstimate: 500, hasRequiredEvidence: true);
        }

        $engine = new AtlasMaestroProviderRecommendationEngine($ledger);
        $verdict = $engine->bestProviderFor('refactor');

        $this->assertSame('ok', $verdict['status']);
        $this->assertSame('reliable_pricier', $verdict['provider'], 'give_back_rate must outrank cost/duration');
    }

    public function test_cost_breaks_tie_when_success_give_back_and_evidence_are_equal(): void
    {
        $ledger = new AtlasMaestroProviderPerformanceLedger();
        // Both equally reliable + evidence-complete; cheap wins on avg_token_cost tier.
        for ($i = 0; $i < 8; $i++) {
            $ledger->recordOutcome('cheap', 'refactor', 'success', 4000, 1700000000 + $i, tokenCostEstimate: 10, hasRequiredEvidence: true);
        }
        for ($i = 0; $i < 2; $i++) {
            $ledger->recordOutcome('cheap', 'refactor', 'give_back', 4000, 1700000100 + $i);
        }
        for ($i = 0; $i < 8; $i++) {
            $ledger->recordOutcome('pricier', 'refactor', 'success', 4000, 1700001000 + $i, tokenCostEstimate: 200, hasRequiredEvidence: true);
        }
        for ($i = 0; $i < 2; $i++) {
            $ledger->recordOutcome('pricier', 'refactor', 'give_back', 4000, 1700001100 + $i);
        }

        $engine = new AtlasMaestroProviderRecommendationEngine($ledger);
        $verdict = $engine->bestProviderFor('refactor');

        $this->assertSame('ok', $verdict['status']);
        $this->assertSame('cheap', $verdict['provider']);
        $this->assertSame('avg_token_cost', $verdict['tie_reason']);
    }

    public function test_atlas_native_wins_only_on_genuine_tie_not_when_costlier(): void
    {
        $ledger = new AtlasMaestroProviderPerformanceLedger();
        // atlas_native is MORE expensive than rival despite equal success/give_back/evidence — rival wins on cost.
        for ($i = 0; $i < 5; $i++) {
            $ledger->recordOutcome('atlas_native', 'refactor', 'success', 1000, 1700000000 + $i, tokenCostEstimate: 100, hasRequiredEvidence: true);
        }
        for ($i = 0; $i < 5; $i++) {
            $ledger->recordOutcome('rival', 'refactor', 'success', 1000, 1700000000 + $i, tokenCostEstimate: 1, hasRequiredEvidence: true);
        }

        $engine = new AtlasMaestroProviderRecommendationEngine($ledger);
        $verdict = $engine->bestProviderFor('refactor');

        $this->assertSame('ok', $verdict['status']);
        $this->assertSame('rival', $verdict['provider'], 'atlas_native must not win when genuinely costlier');
    }

    public function test_stale_or_below_min_sample_returns_non_routing_status_with_detail(): void
    {
        $ledger = new AtlasMaestroProviderPerformanceLedger();
        $ledger->recordOutcome('thin', 'refactor', 'success', 1000, 1700000000, hasRequiredEvidence: false);
        $engine = new AtlasMaestroProviderRecommendationEngine($ledger, minSampleSize: 5);
        $verdict = $engine->bestProviderFor('refactor');

        $this->assertNotSame('ok', $verdict['status']);
        $this->assertArrayNotHasKey('provider', $verdict);
        $this->assertArrayHasKey('min_required', $verdict);
        $this->assertArrayHasKey('providers_needing_samples', $verdict);
    }

    public function test_engine_source_imports_no_forbidden_classes(): void
    {
        $src = (string) file_get_contents(base_path('app/Services/Ai/SelfConstruction/Maestro/ProviderLearning/AtlasMaestroProviderRecommendationEngine.php'));
        foreach (['AiProviderManager', 'AtlasLoopRouter'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $src, "recommendation engine must NOT reference {$forbidden}");
        }
        $this->assertStringNotContainsString('namespace App\\Services\\Ai\\AutonomousEvolution', $src);
    }
}

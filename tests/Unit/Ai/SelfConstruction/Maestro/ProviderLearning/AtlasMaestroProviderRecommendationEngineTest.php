<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Maestro\ProviderLearning;

use App\Services\Ai\SelfConstruction\Maestro\ProviderLearning\AtlasMaestroProviderPerformanceLedger;
use App\Services\Ai\SelfConstruction\Maestro\ProviderLearning\AtlasMaestroProviderRecommendationEngine;
use Tests\TestCase;

final class AtlasMaestroProviderRecommendationEngineTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/atlas-maestro-reco2-'.bin2hex(random_bytes(6));
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

    private function engine(?AtlasMaestroProviderPerformanceLedger $ledger = null, int $minSampleSize = 5): AtlasMaestroProviderRecommendationEngine
    {
        return new AtlasMaestroProviderRecommendationEngine($ledger ?? new AtlasMaestroProviderPerformanceLedger(), $minSampleSize);
    }

    // ── AC: best provider — clear winner on success/give_back fit ────────────

    public function test_best_provider_is_the_one_with_highest_success_and_lowest_give_back(): void
    {
        $ledger = new AtlasMaestroProviderPerformanceLedger();
        for ($i = 0; $i < 9; $i++) {
            $ledger->recordOutcome('best', 'wiring', 'success', 1000, 1700000000 + $i, tokenCostEstimate: 50, hasRequiredEvidence: true);
        }
        $ledger->recordOutcome('best', 'wiring', 'give_back', 1000, 1700000100);
        for ($i = 0; $i < 5; $i++) {
            $ledger->recordOutcome('worse', 'wiring', 'success', 1000, 1700001000 + $i, tokenCostEstimate: 50, hasRequiredEvidence: true);
        }
        for ($i = 0; $i < 5; $i++) {
            $ledger->recordOutcome('worse', 'wiring', 'give_back', 1000, 1700001100 + $i);
        }

        $verdict = $this->engine($ledger)->bestProviderFor('wiring');

        $this->assertSame('ok', $verdict['status']);
        $this->assertSame('best', $verdict['provider']);
    }

    // ── AC: cheap provider with good fit wins over a pricier equally-reliable one ──

    public function test_cheap_provider_with_good_fit_beats_pricier_equally_reliable_provider(): void
    {
        $ledger = new AtlasMaestroProviderPerformanceLedger();
        for ($i = 0; $i < 8; $i++) {
            $ledger->recordOutcome('cheap_reliable', 'wiring', 'success', 1000, 1700000000 + $i, tokenCostEstimate: 5, hasRequiredEvidence: true);
        }
        for ($i = 0; $i < 8; $i++) {
            $ledger->recordOutcome('pricier_reliable', 'wiring', 'success', 1000, 1700001000 + $i, tokenCostEstimate: 500, hasRequiredEvidence: true);
        }

        $verdict = $this->engine($ledger)->bestProviderFor('wiring');

        $this->assertSame('ok', $verdict['status']);
        $this->assertSame('cheap_reliable', $verdict['provider']);
        $this->assertSame('avg_token_cost', $verdict['tie_reason']);
    }

    // ── AC: frontier overuse rejection ─────────────────────────────────────────

    public function test_frontier_provider_with_mediocre_give_back_rate_is_rejected_for_overuse(): void
    {
        $ledger = new AtlasMaestroProviderPerformanceLedger();
        // Frontier: 7 success / 3 give_back = 0.30 give_back rate — meets the STRICTER frontier
        // ceiling even though it would pass the normal (non-frontier) poor-fit bar.
        for ($i = 0; $i < 7; $i++) {
            $ledger->recordOutcome('frontier_model', 'architecture', 'success', 1000, 1700000000 + $i, modelTier: 'frontier');
        }
        for ($i = 0; $i < 3; $i++) {
            $ledger->recordOutcome('frontier_model', 'architecture', 'give_back', 1000, 1700000100 + $i, modelTier: 'frontier');
        }
        // A clean non-frontier alternative that is genuinely reliable.
        for ($i = 0; $i < 9; $i++) {
            $ledger->recordOutcome('small_model', 'architecture', 'success', 1000, 1700001000 + $i);
        }
        $ledger->recordOutcome('small_model', 'architecture', 'give_back', 1000, 1700001100);

        $verdict = $this->engine($ledger)->bestProviderFor('architecture');

        $this->assertSame('ok', $verdict['status']);
        $this->assertSame('small_model', $verdict['provider']);
        $this->assertArrayHasKey('frontier_model', $verdict['poor_fit_providers']);
        $this->assertSame('frontier_overuse_risk', $verdict['poor_fit_providers']['frontier_model']['reason']);
    }

    // ── AC: insufficient evidence ──────────────────────────────────────────────

    public function test_insufficient_evidence_returns_insufficient_data_status(): void
    {
        $ledger = new AtlasMaestroProviderPerformanceLedger();
        $ledger->recordOutcome('thin', 'wiring', 'success', 1000, 1700000000);
        $ledger->recordOutcome('thin', 'wiring', 'success', 1000, 1700000001);

        $verdict = $this->engine($ledger, 5)->bestProviderFor('wiring');

        $this->assertSame('insufficient_data', $verdict['status']);
        $this->assertArrayHasKey('thin', $verdict['samples_seen']);
        $this->assertArrayNotHasKey('provider', $verdict);
    }

    // ── AC: no safe provider — every candidate is poor fit ────────────────────

    public function test_no_safe_provider_when_every_candidate_is_poor_fit(): void
    {
        $ledger = new AtlasMaestroProviderPerformanceLedger();
        // Both providers meet the sample floor but have a give_back rate at/above the poor-fit ceiling.
        for ($i = 0; $i < 4; $i++) {
            $ledger->recordOutcome('flaky_a', 'wiring', 'success', 1000, 1700000000 + $i);
        }
        for ($i = 0; $i < 6; $i++) {
            $ledger->recordOutcome('flaky_a', 'wiring', 'give_back', 1000, 1700000100 + $i);
        }
        for ($i = 0; $i < 3; $i++) {
            $ledger->recordOutcome('flaky_b', 'wiring', 'success', 1000, 1700001000 + $i);
        }
        for ($i = 0; $i < 7; $i++) {
            $ledger->recordOutcome('flaky_b', 'wiring', 'give_back', 1000, 1700001100 + $i);
        }

        $verdict = $this->engine($ledger)->bestProviderFor('wiring');

        $this->assertSame('no_safe_provider', $verdict['status']);
        $this->assertSame('all_candidates_poor_fit', $verdict['reason']);
        $this->assertArrayHasKey('flaky_a', $verdict['poor_fit_providers']);
        $this->assertArrayHasKey('flaky_b', $verdict['poor_fit_providers']);
        $this->assertArrayNotHasKey('provider', $verdict);
    }

    public function test_poor_fit_below_frontier_ceiling_but_below_general_ceiling_is_not_rejected(): void
    {
        // give_back_rate 0.20 is below BOTH the frontier ceiling (0.30) and general ceiling (0.50).
        $ledger = new AtlasMaestroProviderPerformanceLedger();
        for ($i = 0; $i < 8; $i++) {
            $ledger->recordOutcome('healthy_frontier', 'wiring', 'success', 1000, 1700000000 + $i, modelTier: 'frontier');
        }
        for ($i = 0; $i < 2; $i++) {
            $ledger->recordOutcome('healthy_frontier', 'wiring', 'give_back', 1000, 1700000100 + $i, modelTier: 'frontier');
        }

        $verdict = $this->engine($ledger)->bestProviderFor('wiring');

        $this->assertSame('ok', $verdict['status']);
        $this->assertSame('healthy_frontier', $verdict['provider']);
        $this->assertSame([], $verdict['poor_fit_providers']);
    }
}

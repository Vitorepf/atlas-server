<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AcosMax;

use App\Services\Ai\AcosMax\PortfolioBudgetAllocator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * MULTK-06 — portfolio allocator acceptance (§2395-2399).
 */
final class Multk06PortfolioBudgetAllocatorTest extends TestCase
{
    #[Test]
    public function schema_and_formula_version_are_pinned(): void
    {
        $this->assertSame('atlas.decide.portfolio_allocation.v1', PortfolioBudgetAllocator::SCHEMA_VERSION);
        $this->assertSame('atlas.multk_06.portfolio_allocation.v1', PortfolioBudgetAllocator::FORMULA_VERSION);
    }

    #[Test]
    public function default_weights_produce_default_mix_and_receipt_labels_decision(): void
    {
        $out = PortfolioBudgetAllocator::derive([
            'operator_weights' => ['reactive' => 0.5, 'originated' => 0.3, 'maintenance' => 0.2],
            'default_mix' => ['reactive' => 0.5, 'originated' => 0.3, 'maintenance' => 0.2],
            'yield_by_class' => [],
            'ceiling_bands' => [],
        ]);
        $this->assertSame('portfolio_allocation', $out['decision_kind']);
        $this->assertSame('ok', $out['status']);
        $this->assertEqualsWithDelta(0.5, $out['allocation']['reactive'], 1e-6);
        $this->assertEqualsWithDelta(0.3, $out['allocation']['originated'], 1e-6);
        $this->assertEqualsWithDelta(0.2, $out['allocation']['maintenance'], 1e-6);
        $this->assertSame([], $out['reasons']);
    }

    #[Test]
    public function weight_change_without_amendment_is_refused_and_reverts_to_default(): void
    {
        $default = ['reactive' => 0.4, 'originated' => 0.4, 'maintenance' => 0.2];
        $out = PortfolioBudgetAllocator::derive([
            'operator_weights' => ['reactive' => 0.8, 'originated' => 0.1, 'maintenance' => 0.1],
            'default_mix' => $default,
            'yield_by_class' => [],
            'ceiling_bands' => [],
            'amendment_receipt_id' => null,
        ]);
        $this->assertContains('weight_change_refused_missing_amendment_receipt', $out['reasons']);
        $this->assertSame('weights_reverted_to_default', $out['status']);
        $this->assertEqualsWithDelta($default['reactive'], $out['allocation']['reactive'], 1e-6);
        $this->assertEqualsWithDelta($default['originated'], $out['allocation']['originated'], 1e-6);
        $this->assertEqualsWithDelta($default['maintenance'], $out['allocation']['maintenance'], 1e-6);
    }

    #[Test]
    public function weight_change_with_amendment_receipt_is_accepted(): void
    {
        $out = PortfolioBudgetAllocator::derive([
            'operator_weights' => ['reactive' => 0.6, 'originated' => 0.3, 'maintenance' => 0.1],
            'default_mix' => ['reactive' => 0.4, 'originated' => 0.4, 'maintenance' => 0.2],
            'yield_by_class' => [],
            'ceiling_bands' => [],
            'amendment_receipt_id' => 'maxk-07:amend-2026-07-12-001',
        ]);
        $this->assertSame('ok', $out['status']);
        $this->assertSame([], $out['reasons']);
        $this->assertSame('maxk-07:amend-2026-07-12-001', $out['amendment_receipt_id']);
        // maintenance=0.1 respected because > HARD_FLOOR_SHARE(0.05)
        $this->assertEqualsWithDelta(0.1, $out['allocation']['maintenance'], 1e-6);
    }

    #[Test]
    public function starvation_floor_prevents_zero_maintenance_share(): void
    {
        // Even with amendment, attempting to zero maintenance is refused via hard floor.
        $out = PortfolioBudgetAllocator::derive([
            'operator_weights' => ['reactive' => 0.5, 'originated' => 0.5, 'maintenance' => 0.0],
            'default_mix' => ['reactive' => 0.5, 'originated' => 0.5, 'maintenance' => 0.0],
            'yield_by_class' => [],
            'ceiling_bands' => [],
            'amendment_receipt_id' => 'maxk-07:starve',
        ]);
        $this->assertGreaterThanOrEqual(
            PortfolioBudgetAllocator::HARD_FLOOR_SHARE,
            $out['allocation']['maintenance'],
        );
    }

    #[Test]
    public function ceiling_prevents_high_yield_class_from_exceeding_operator_band(): void
    {
        // Originated has yield 10× reactive but ceiling caps at 0.4.
        $out = PortfolioBudgetAllocator::derive([
            'operator_weights' => ['reactive' => 0.3, 'originated' => 0.4, 'maintenance' => 0.3],
            'default_mix' => ['reactive' => 0.3, 'originated' => 0.4, 'maintenance' => 0.3],
            'yield_by_class' => [
                'reactive' => ['n' => 10, 'mean_proven_yield' => 0.01],
                'originated' => ['n' => 20, 'mean_proven_yield' => 0.90],
                'maintenance' => ['n' => 10, 'mean_proven_yield' => 0.02],
            ],
            'ceiling_bands' => [
                'originated' => ['min' => 0.0, 'max' => 0.4],
            ],
        ]);
        $this->assertLessThanOrEqual(0.4 + 1e-6, $out['allocation']['originated']);
    }

    #[Test]
    public function yield_below_min_n_reports_insufficient_signal_but_never_fabricates_yield(): void
    {
        $out = PortfolioBudgetAllocator::derive([
            'operator_weights' => ['reactive' => 0.4, 'originated' => 0.3, 'maintenance' => 0.3],
            'default_mix' => ['reactive' => 0.4, 'originated' => 0.3, 'maintenance' => 0.3],
            'yield_by_class' => [
                'reactive' => ['n' => 2, 'mean_proven_yield' => 0.5],
                'originated' => ['n' => 100, 'mean_proven_yield' => 0.6],
                'maintenance' => ['n' => 0],
            ],
            'ceiling_bands' => [],
        ]);
        $this->assertSame('insufficient_n', $out['yield_by_class']['reactive']['basis']);
        $this->assertNull($out['yield_by_class']['reactive']['mean_proven_yield']);
        $this->assertSame('measured', $out['yield_by_class']['originated']['basis']);
        $this->assertSame(0.6, $out['yield_by_class']['originated']['mean_proven_yield']);
        $this->assertSame('insufficient_n', $out['yield_by_class']['maintenance']['basis']);
    }

    #[Test]
    public function source_flags_encode_the_charter(): void
    {
        $out = PortfolioBudgetAllocator::derive([
            'operator_weights' => [],
            'default_mix' => [],
            'yield_by_class' => [],
            'ceiling_bands' => [],
        ]);
        $src = $out['source'];
        $this->assertTrue($src['weights_are_operator_authored']);
        $this->assertFalse($src['allocator_writes_own_weights']);
        $this->assertFalse($src['yield_recomputed_here']);
        $this->assertSame(PortfolioBudgetAllocator::HARD_FLOOR_SHARE, $src['starvation_floor_absolute']);
        $this->assertSame(PortfolioBudgetAllocator::HARD_CEILING_SHARE, $src['ceiling_absolute']);
        $this->assertTrue($src['consumer_of_maxn_04']);
        $this->assertTrue($src['consumer_of_maxk_07']);
        $this->assertSame('off', $src['flag_default']);
    }

    #[Test]
    public function empty_input_falls_back_to_equal_default_mix(): void
    {
        $out = PortfolioBudgetAllocator::derive([]);
        $this->assertEqualsWithDelta(1.0 / 3, $out['allocation']['reactive'], 1e-6);
        $this->assertEqualsWithDelta(1.0 / 3, $out['allocation']['originated'], 1e-6);
        $this->assertEqualsWithDelta(1.0 / 3, $out['allocation']['maintenance'], 1e-6);
    }
}

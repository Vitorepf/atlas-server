<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasObjectiveIntelligenceService;
use Tests\TestCase;

/**
 * Pins the documented Atlas Objective Intelligence decision rules.
 *
 * @see docs/engineering-knowledge-base/atlas-objective-intelligence.md
 */
class AtlasObjectiveIntelligenceTest extends TestCase
{
    private function service(): AtlasObjectiveIntelligenceService
    {
        return new AtlasObjectiveIntelligenceService();
    }

    /**
     * Hard gate ("Nao executar meta complexa sem objetivo e DoD"): a non-trivial
     * objective missing its Definition of Done is NOT ready to execute, and the
     * missing field is surfaced. Filling every required field flips it to ready.
     */
    public function test_non_trivial_objective_needs_dod_metric_and_constraints_to_execute(): void
    {
        $svc = $this->service();

        $incomplete = $svc->buildObjective([
            'mission_id' => 'm1',
            'objective' => 'Launch an ecommerce and sell.',
            'primary_metric' => 'first_sale_recorded',
            'constraints' => ['no external spend'],
            'definition_of_done' => [], // missing DoD
        ]);

        $this->assertFalse($incomplete['ready_to_execute']);
        $this->assertContains('definition_of_done', $incomplete['missing_fields']);
        $this->assertSame(self::EXPECTED_VERSION, $incomplete['schema_version']);

        $complete = $svc->buildObjective([
            'mission_id' => 'm1',
            'objective' => 'Launch an ecommerce and sell.',
            'primary_metric' => 'first_sale_recorded',
            'constraints' => ['no external spend'],
            'definition_of_done' => ['storefront live', 'first sale or real blocker recorded'],
        ]);

        $this->assertTrue($complete['ready_to_execute']);
        $this->assertSame([], $complete['missing_fields']);
        $this->assertTrue($complete['has_definition_of_done']);
    }

    /**
     * A real open blocker stops execution even when every field is present
     * (a blocker is a valid result, never hidden as ready), and the objective
     * record carries a stable receipt_hash and dod_hash that are deterministic
     * across runs for identical input.
     */
    public function test_open_blocker_blocks_execution_and_hashes_are_deterministic(): void
    {
        $svc = $this->service();

        $payload = [
            'mission_id' => 'm2',
            'objective' => 'Ship the catalog.',
            'primary_metric' => 'catalog_published',
            'constraints' => ['local data only'],
            'definition_of_done' => ['catalog visible'],
            'blockers' => ['payment provider credentials missing'],
        ];

        $first = $svc->buildObjective($payload);
        $second = $svc->buildObjective($payload);

        $this->assertFalse($first['ready_to_execute']);
        $this->assertSame(1, $first['open_blocker_count']);

        // Deterministic content hashes (no clock, no randomness).
        $this->assertSame($first['receipt_hash'], $second['receipt_hash']);
        $this->assertSame($first['dod_hash'], $second['dod_hash']);
        $this->assertSame(64, strlen($first['receipt_hash']));

        // A different DoD must change the dod_hash.
        $changed = $svc->buildObjective(array_merge($payload, [
            'definition_of_done' => ['catalog visible', 'payment configured'],
        ]));
        $this->assertNotSame($first['dod_hash'], $changed['dod_hash']);
    }

    /**
     * Metric selection: a vanity metric (likes) cannot be primary when the
     * business requires a result; the operational metric (revenue) is chosen
     * and the vanity one is rejected. ("Nao usar vaidade como metrica principal
     * quando negocio exige resultado.")
     */
    public function test_vanity_metric_rejected_as_primary_when_result_required(): void
    {
        $svc = $this->service();

        $chosen = $svc->selectPrimaryMetric(
            ['likes', 'revenue', 'impressions'],
            ['business_requires_result' => true],
        );

        $this->assertSame('revenue', $chosen['primary_metric']);
        $this->assertContains('likes', $chosen['rejected_vanity']);
        $this->assertContains('impressions', $chosen['rejected_vanity']);
        $this->assertFalse($chosen['needs_real_metric']);

        // If EVERY candidate is vanity and a result is required, there is no
        // valid primary and the caller must define a real metric.
        $allVanity = $svc->selectPrimaryMetric(
            ['likes', 'followers'],
            ['business_requires_result' => true],
        );
        $this->assertNull($allVanity['primary_metric']);
        $this->assertTrue($allVanity['needs_real_metric']);
    }

    /**
     * Missing-info routing ("Fluxo" step 8 + "Nao inventar budget, prazo ou
     * permissao como fato"): budget/deadline/permission are escalated for a
     * human decision regardless of risk; a low-risk researchable fact is
     * researched; a low-risk non-researchable field gets a conservative
     * assumption; high risk always escalates.
     */
    public function test_missing_info_routing_assume_research_or_ask(): void
    {
        $svc = $this->service();

        $budget = $svc->resolveMissingInfo(['field' => 'budget', 'risk' => 'low']);
        $this->assertSame(AtlasObjectiveIntelligenceService::RESOLVE_ASK, $budget['disposition']);
        $this->assertTrue($budget['requires_human_decision']);
        $this->assertTrue($budget['non_inventable']);
        $this->assertTrue($budget['is_blocker']);

        $research = $svc->resolveMissingInfo([
            'field' => 'market_average_price',
            'risk' => 'low',
            'researchable' => true,
        ]);
        $this->assertSame(AtlasObjectiveIntelligenceService::RESOLVE_RESEARCH, $research['disposition']);
        $this->assertFalse($research['requires_human_decision']);

        $assume = $svc->resolveMissingInfo([
            'field' => 'default_currency',
            'risk' => 'low',
            'researchable' => false,
        ]);
        $this->assertSame(AtlasObjectiveIntelligenceService::RESOLVE_ASSUME, $assume['disposition']);
        $this->assertTrue($assume['may_assume']);

        $highRisk = $svc->resolveMissingInfo([
            'field' => 'data_retention_policy',
            'risk' => 'high',
            'researchable' => true,
        ]);
        $this->assertSame(AtlasObjectiveIntelligenceService::RESOLVE_ASK, $highRisk['disposition']);
    }

    /**
     * Sell-intent split ("Quando a meta for 'vender', separar criar
     * infraestrutura de gerar receita"): a sell goal yields two distinct
     * sub-objectives (build infrastructure vs generate revenue); a non-sell
     * goal yields none.
     */
    public function test_sell_intent_splits_infrastructure_from_revenue(): void
    {
        $svc = $this->service();

        $sell = $svc->splitSellIntent('crie um ecommerce e realize vendas');
        $this->assertTrue($sell['is_sell_intent']);
        $this->assertCount(2, $sell['sub_objectives']);

        $keys = array_column($sell['sub_objectives'], 'key');
        $this->assertSame(['build_infrastructure', 'generate_revenue'], $keys);

        $nonSell = $svc->splitSellIntent('write the architecture document');
        $this->assertFalse($nonSell['is_sell_intent']);
        $this->assertSame([], $nonSell['sub_objectives']);
    }

    private const EXPECTED_VERSION = 'atlas.ai.objective.v1';
}

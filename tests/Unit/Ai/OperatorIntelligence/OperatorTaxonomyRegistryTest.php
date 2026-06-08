<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\OperatorIntelligence;

use App\Services\Ai\OperatorIntelligence\OperatorTaxonomyRegistry;
use Tests\TestCase;

/**
 * The registry must stay in lockstep with the canonical 170-item doc — a contract,
 * not a convenience. If the doc and the parser ever disagree, this fails loudly.
 */
class OperatorTaxonomyRegistryTest extends TestCase
{
    private function registry(): OperatorTaxonomyRegistry
    {
        return new OperatorTaxonomyRegistry();
    }

    public function test_registry_has_exactly_170_items(): void
    {
        $this->assertCount(170, $this->registry()->all());
    }

    public function test_layers_split_70_80_20(): void
    {
        $r = $this->registry();
        $this->assertCount(70, $r->ids(OperatorTaxonomyRegistry::LAYER_SYSTEM));
        $this->assertCount(80, $r->ids(OperatorTaxonomyRegistry::LAYER_OPERATOR));
        $this->assertCount(20, $r->ids(OperatorTaxonomyRegistry::LAYER_COLLABORATION));
    }

    public function test_every_id_matches_the_canonical_format(): void
    {
        foreach ($this->registry()->all() as $id => $item) {
            $this->assertMatchesRegularExpression('/^(SYS|OP|COL)-\d{3}$/', $id);
            $this->assertNotSame('', trim((string) $item['description']));
            $this->assertContains($item['layer'], [1, 2, 3]);
        }
    }

    public function test_high_stakes_and_sensitive_items_are_flagged(): void
    {
        $r = $this->registry();
        $this->assertTrue($r->isHighStakes('OP-145'), 'OP-145 (never touch) must be high-stakes');
        $this->assertSame('sensitive', $r->privacyFor('OP-141'), 'OP-141 (personal restrictions) privacy floor');
        $this->assertFalse($r->isHighStakes('OP-071'), 'a benign preference is not high-stakes');
    }

    public function test_prompt_menu_excludes_layer1_system_telemetry(): void
    {
        $menu = $this->registry()->promptMenu();
        $this->assertStringContainsString('OP-071', $menu);
        $this->assertStringContainsString('COL-170', $menu);
        $this->assertStringNotContainsString('SYS-001', $menu, 'system telemetry is never an extractor target');
    }
}

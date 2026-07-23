<?php

namespace Tests\Feature\Ai\Company\Ventures;

use App\Services\Ai\Company\Ventures\VentureBusinessRuleService;
use App\Services\Ai\Company\Ventures\VentureFoundryException;
use App\Services\Ai\Company\Ventures\VentureIdeationService;
use App\Services\Ai\Company\Ventures\VentureRegistryService;
use Tests\Concerns\CreatesStrategyRuntimeTables;
use Tests\Concerns\CreatesVentureFoundryTables;
use Tests\TestCase;

class VentureBusinessRuleServiceTest extends TestCase
{
    use CreatesStrategyRuntimeTables;
    use CreatesVentureFoundryTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createStrategyRuntimeTables();
        $this->createVentureFoundryTables();
    }

    protected function tearDown(): void
    {
        $this->dropVentureFoundryTables();
        $this->dropStrategyRuntimeTables();
        parent::tearDown();
    }

    private function makeVenture(): \App\Models\AiVenture
    {
        $idea = app(VentureIdeationService::class)->register([
            'title' => 'Empresa teste',
            'problem' => 'p', 'icp' => 'i', 'pain' => 'd',
        ]);

        return app(VentureRegistryService::class)->promoteIdea($idea);
    }

    public function test_declare_versioning_supersedes_previous_rule(): void
    {
        $venture = $this->makeVenture();
        $rules = app(VentureBusinessRuleService::class);

        $v1 = $rules->declare($venture, [
            'rule_id' => 'preco-minimo',
            'category' => 'pricing',
            'statement' => 'Nunca vender abaixo de 100 USD/mes.',
        ]);
        $this->assertSame(1, $v1->version);
        $this->assertSame(VentureBusinessRuleService::STATUS_ACTIVE, $v1->status);

        $v2 = $rules->declare($venture, [
            'rule_id' => 'preco-minimo',
            'category' => 'pricing',
            'statement' => 'Nunca vender abaixo de 150 USD/mes.',
            'rationale' => 'Reajuste apos validacao de disposicao a pagar.',
        ]);

        $this->assertSame(2, $v2->version);
        $v1->refresh();
        $this->assertSame(VentureBusinessRuleService::STATUS_SUPERSEDED, $v1->status);
        $this->assertSame($v2->id, $v1->superseded_by);

        $active = $rules->activeRules($venture);
        $this->assertCount(1, $active);
        $this->assertSame('Nunca vender abaixo de 150 USD/mes.', $active[0]->statement);
    }

    public function test_declare_rejects_invalid_category_and_retire_works(): void
    {
        $venture = $this->makeVenture();
        $rules = app(VentureBusinessRuleService::class);

        try {
            $rules->declare($venture, ['category' => 'astrologia', 'statement' => 'x']);
            $this->fail('expected invalid category exception');
        } catch (VentureFoundryException $e) {
            $this->assertStringContainsString('category', $e->getMessage());
        }

        $rule = $rules->declare($venture, ['category' => 'operations', 'statement' => 'Suporte responde em ate 4h.']);
        $retired = $rules->retire($rule);
        $this->assertSame(VentureBusinessRuleService::STATUS_RETIRED, $retired->status);
        $this->assertCount(0, $rules->activeRules($venture));
    }
}

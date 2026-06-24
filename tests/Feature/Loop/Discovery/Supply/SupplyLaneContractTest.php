<?php

declare(strict_types=1);

namespace Tests\Feature\Loop\Discovery\Supply;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopScopeComprehensionModel;
use App\Services\Ai\AutonomousEvolution\Discovery\Supply\SupplyLaneContract;
use ReflectionMethod;
use Tests\TestCase;

final class SupplyLaneContractTest extends TestCase
{
    public function test_anonymous_stub_can_implement_the_contract_and_return_empty_specs(): void
    {
        $lane = new class implements SupplyLaneContract
        {
            public function mint(AtlasLoopScopeComprehensionModel $model, string $repoRoot): array
            {
                return [];
            }
        };

        $this->assertSame([], $lane->mint($this->model(), base_path()));
    }

    public function test_contract_exposes_exactly_one_public_method_named_mint_with_typed_signature(): void
    {
        $reflection = new \ReflectionClass(SupplyLaneContract::class);
        $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);

        $this->assertCount(1, $methods);
        $this->assertSame('mint', $methods[0]->getName());
        $this->assertSame('App\Services\Ai\AutonomousEvolution\Discovery\Supply', $reflection->getNamespaceName());

        $parameters = $methods[0]->getParameters();
        $this->assertCount(2, $parameters);
        $this->assertSame(AtlasLoopScopeComprehensionModel::class, $parameters[0]->getType()?->getName());
        $this->assertSame('string', $parameters[1]->getType()?->getName());
        $this->assertSame('array', $methods[0]->getReturnType()?->getName());
    }

    private function model(): AtlasLoopScopeComprehensionModel
    {
        return new AtlasLoopScopeComprehensionModel(
            inventory: [],
            edges: [],
            orphans: [],
            cloneClusters: [],
            forbidden: [],
            docPurposes: [],
            docStatedGaps: [],
            snapshotId: 'test-snapshot',
        );
    }
}

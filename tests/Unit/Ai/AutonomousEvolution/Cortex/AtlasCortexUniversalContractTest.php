<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Cortex;

use App\Services\Ai\AutonomousEvolution\Cortex\AtlasCortexUniversalContract;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopScopeComprehensionModelBuilder;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Tests\TestCase;

/**
 * Proves the Cortex v+infinity universal contract: declared as an interface with EXACTLY two methods, no
 * parameter or return type references a Laravel / Eloquent / Atlas-internal class; the default binding
 * resolved from the container returns a payload byte-identical to the comprehension model's toArray()
 * output; and the schema id is exactly the documented placeholder 'atlas.cortex.facts.v1'.
 */
final class AtlasCortexUniversalContractTest extends TestCase
{
    public function test_contract_is_an_interface_with_exactly_two_methods(): void
    {
        $reflection = new ReflectionClass(AtlasCortexUniversalContract::class);

        $this->assertTrue($reflection->isInterface(), 'AtlasCortexUniversalContract must be an interface');
        $methods = $reflection->getMethods();
        $this->assertCount(2, $methods, 'exactly two methods in the contract');
        $names = array_map(static fn (ReflectionMethod $m): string => $m->getName(), $methods);
        sort($names);
        $this->assertSame(['comprehend', 'contractSchemaId'], $names);
    }

    public function test_signatures_only_use_scalar_and_array_types_no_laravel_or_atlas_internal(): void
    {
        $reflection = new ReflectionClass(AtlasCortexUniversalContract::class);
        $allowed = ['string', 'array', 'int', 'bool', 'float'];

        foreach ($reflection->getMethods() as $method) {
            // Parameters
            foreach ($method->getParameters() as $param) {
                $t = $param->getType();
                $this->assertInstanceOf(ReflectionNamedType::class, $t, "method {$method->getName()} param {$param->getName()} must have a named type");
                $this->assertContains($t->getName(), $allowed, "method {$method->getName()} param {$param->getName()} type must be scalar/array, got {$t->getName()}");
            }
            // Return
            $rt = $method->getReturnType();
            $this->assertInstanceOf(ReflectionNamedType::class, $rt, "method {$method->getName()} must declare a return type");
            $this->assertContains($rt->getName(), $allowed, "method {$method->getName()} return type must be scalar/array, got {$rt->getName()}");
        }
    }

    public function test_contract_schema_id_matches_documented_placeholder(): void
    {
        $contract = $this->app->make(AtlasCortexUniversalContract::class);
        $this->assertSame('atlas.cortex.facts.v1', $contract->contractSchemaId());
    }

    public function test_default_binding_delegates_to_existing_builder_byte_identical(): void
    {
        $contract = $this->app->make(AtlasCortexUniversalContract::class);
        $builder = $this->app->make(AtlasLoopScopeComprehensionModelBuilder::class);

        // Use the package own root + a narrow scope to keep the comprehension cheap; both paths use the same
        // builder call under the hood so the assertion is structural: same inputs → same JSON-encoded array.
        $repoRoot = (string) base_path();
        $scopeRoot = $repoRoot.'/app/Services/Ai/AutonomousEvolution/Cortex';

        $facts = $contract->comprehend($repoRoot, ['scope_root' => $scopeRoot, 'opts' => []]);
        $direct = $builder->build($repoRoot, $scopeRoot, [])->toArray();

        $this->assertSame(json_encode($direct), json_encode($facts), 'contract output is byte-identical to model->toArray()');
    }
}

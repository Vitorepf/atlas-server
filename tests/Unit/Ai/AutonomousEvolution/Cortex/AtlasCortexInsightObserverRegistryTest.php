<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Cortex;

use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Insights\AtlasCortexInsightObserverRegistry;
use InvalidArgumentException;
use ReflectionClass;
use Tests\TestCase;

final class AtlasCortexInsightObserverRegistryTest extends TestCase
{
    public function test_axes_are_deterministic_and_byte_identical(): void
    {
        $registry = new AtlasCortexInsightObserverRegistry;

        $first = $registry->axes();
        $second = $registry->axes();

        $this->assertSame(['api_surface', 'memory_pressure', 'orphan_spike', 'similarity_clusters', 'temporal_drift'], array_keys($first));
        $this->assertSame(
            json_encode($first, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            json_encode($second, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        );
    }

    public function test_registry_rejects_non_conforming_observer_classes(): void
    {
        $registry = new AtlasCortexInsightObserverRegistry([
            'broken_axis' => [
                'fqcn' => AtlasCortexInsightObserverRegistryNonConformingFake::class,
                'required_fact_keys' => ['scope_runtime_facts'],
                'envelope_shape' => ['facts' => 'array'],
            ],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must implement');

        $registry->axes();
    }

    public function test_registry_exposes_no_score_rank_level_or_weight_api_surface(): void
    {
        $reflection = new ReflectionClass(AtlasCortexInsightObserverRegistry::class);
        $forbidden = '/score|rank|level|weight/i';

        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $this->assertSame(0, preg_match($forbidden, $method->getName()), 'Forbidden token in public method name: '.$method->getName());

            $returnType = $method->getReturnType();
            if ($returnType !== null) {
                $this->assertSame(0, preg_match($forbidden, (string) $returnType), 'Forbidden token in return type: '.$method->getName());
            }
        }
    }
}

final class AtlasCortexInsightObserverRegistryNonConformingFake
{
    /**
     * @param  array<string,mixed>  $facts
     * @return array<string,mixed>
     */
    public function observe(array $facts): array
    {
        return $facts;
    }
}

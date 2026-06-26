<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Grinder;

use App\Services\Ai\AutonomousEvolution\Grinder\AtlasLoopGrinderCertificationCommandExtractor;
use Tests\TestCase;

class AtlasLoopGrinderCertificationCommandExtractorTest extends TestCase
{
    public function test_sealed_holdout_commands_unions_keys(): void
    {
        $payload = [
            'sealed_holdout_commands' => ['cmd1', 'cmd2'],
            'wide_holdout_commands' => ['cmd2', 'cmd3'],
        ];

        $result = AtlasLoopGrinderCertificationCommandExtractor::sealedHoldoutCommands($payload);

        self::assertSame(['cmd1', 'cmd2', 'cmd3'], $result);
    }

    public function test_sealed_holdout_commands_dedupes(): void
    {
        $payload = [
            'sealed_holdout_commands' => ['a', 'b'],
            'wide_holdout_commands' => ['b', 'c'],
            'final_holdout_commands' => ['c', 'a'],
        ];

        $result = AtlasLoopGrinderCertificationCommandExtractor::sealedHoldoutCommands($payload);

        self::assertSame(['a', 'b', 'c'], $result);
    }

    public function test_sealed_holdout_commands_empty(): void
    {
        self::assertSame([], AtlasLoopGrinderCertificationCommandExtractor::sealedHoldoutCommands([]));
    }

    public function test_semantic_refuter_commands_unions_keys(): void
    {
        $payload = [
            'semantic_refuter_commands' => ['refuter1'],
            'provider_refuter_commands' => ['refuter2'],
        ];

        $result = AtlasLoopGrinderCertificationCommandExtractor::semanticRefuterCommands($payload);

        self::assertSame(['refuter1', 'refuter2'], $result);
    }

    public function test_mutation_property_commands_unions_keys(): void
    {
        $payload = [
            'mutation_property_commands' => ['p1'],
            'property_commands' => ['p2'],
            'property_based_commands' => ['p3'],
        ];

        $result = AtlasLoopGrinderCertificationCommandExtractor::mutationPropertyCommands($payload);

        self::assertSame(['p1', 'p2', 'p3'], $result);
    }

    public function test_cross_file_consumer_commands_unions_keys(): void
    {
        $payload = [
            'cross_file_consumer_commands' => ['c1'],
            'consumer_commands' => ['c2'],
        ];

        $result = AtlasLoopGrinderCertificationCommandExtractor::crossFileConsumerCommands($payload);

        self::assertSame(['c1', 'c2'], $result);
    }

    public function test_cross_file_consumer_contracts_collects_arrays(): void
    {
        $payload = [
            'cross_file_consumer_contracts' => [['a' => 1], ['b' => 2]],
            'consumer_contracts' => [['c' => 3]],
            'code_graph_consumer_contracts' => [['d' => 4]],
        ];

        $result = AtlasLoopGrinderCertificationCommandExtractor::crossFileConsumerContracts($payload);

        self::assertCount(4, $result);
        self::assertSame(['a' => 1], $result[0]);
        self::assertSame(['d' => 4], $result[3]);
    }

    public function test_cross_file_consumer_contracts_ignores_non_arrays(): void
    {
        $payload = [
            'cross_file_consumer_contracts' => ['not_an_array', ['a' => 1], 42, null],
        ];

        $result = AtlasLoopGrinderCertificationCommandExtractor::crossFileConsumerContracts($payload);

        self::assertSame([['a' => 1]], $result);
    }

    public function test_semantic_refuters_required_uses_provider_key(): void
    {
        $payload = ['provider_refuters_required' => 5];

        self::assertSame(5, AtlasLoopGrinderCertificationCommandExtractor::semanticRefutersRequired($payload, 3));
    }

    public function test_semantic_refuters_required_uses_refuters_required_key(): void
    {
        $payload = ['refuters_required' => 7];

        self::assertSame(7, AtlasLoopGrinderCertificationCommandExtractor::semanticRefutersRequired($payload, 3));
    }

    public function test_semantic_refuters_required_uses_refuters_key(): void
    {
        $payload = ['refuters' => 9];

        self::assertSame(9, AtlasLoopGrinderCertificationCommandExtractor::semanticRefutersRequired($payload, 3));
    }

    public function test_semantic_refuters_required_falls_back_to_configured(): void
    {
        self::assertSame(3, AtlasLoopGrinderCertificationCommandExtractor::semanticRefutersRequired([], 3));
    }

    public function test_semantic_refuters_required_clamps_negative(): void
    {
        $payload = ['provider_refuters_required' => -5];

        self::assertSame(0, AtlasLoopGrinderCertificationCommandExtractor::semanticRefutersRequired($payload, 3));
    }

    public function test_semantic_refuters_required_ignores_non_numeric(): void
    {
        $payload = ['provider_refuters_required' => 'not_a_number'];

        self::assertSame(3, AtlasLoopGrinderCertificationCommandExtractor::semanticRefutersRequired($payload, 3));
    }
}
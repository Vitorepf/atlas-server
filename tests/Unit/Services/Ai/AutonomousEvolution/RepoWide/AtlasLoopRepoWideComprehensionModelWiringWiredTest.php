<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\AutonomousEvolution\RepoWide;

use App\Services\Ai\AutonomousEvolution\RepoWide\AtlasLoopRepoWideCallerResolver;
use App\Services\Ai\AutonomousEvolution\RepoWide\AtlasLoopRepoWideComprehensionModel;
use App\Services\Ai\AutonomousEvolution\RepoWide\AtlasLoopRepoWideConsumersProvider;
use Tests\TestCase;

final class AtlasLoopRepoWideComprehensionModelWiringWiredTest extends TestCase
{
    public function test_from_per_scope_models_factory_invokes_comprehension_model_and_resolves_consumers(): void
    {
        $perScopeModels = [
            [
                'inventory' => [
                    ['fqcn' => 'App\\Foo\\Producer', 'is_orphan' => false, 'path' => 'app/Foo/Producer.php'],
                    ['fqcn' => 'App\\Foo\\Consumer', 'is_orphan' => false, 'path' => 'app/Foo/Consumer.php'],
                ],
                'fqcn_by_path' => [
                    'app/Foo/Producer.php' => 'App\\Foo\\Producer',
                    'app/Foo/Consumer.php' => 'App\\Foo\\Consumer',
                ],
                'file_contents_by_path' => [
                    'app/Foo/Producer.php' => '<?php namespace App\\Foo; class Producer {}',
                    'app/Foo/Consumer.php' => '<?php namespace App\\Foo; use App\\Foo\\Producer; class Consumer { public function go(){ new Producer(); }}',
                ],
            ],
        ];

        $provider = AtlasLoopRepoWideConsumersProvider::fromPerScopeModels(
            $perScopeModels,
            new AtlasLoopRepoWideCallerResolver(),
        );
        $consumers = $provider->consumersOf('App\\Foo\\Producer');
        $this->assertContains('App\\Foo\\Consumer', $consumers, 'consumer must surface via federated repo-wide model');
    }

    public function test_factory_returns_provider_for_empty_per_scope_models(): void
    {
        $provider = AtlasLoopRepoWideConsumersProvider::fromPerScopeModels(
            [],
            new AtlasLoopRepoWideCallerResolver(),
            new AtlasLoopRepoWideComprehensionModel(),
        );
        $this->assertSame([], $provider->consumersOf('App\\Anything'));
    }
}

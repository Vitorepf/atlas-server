<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\RepoWide;

use App\Services\Ai\AutonomousEvolution\RepoWide\AtlasLoopRepoWideCallerResolver;
use App\Services\Ai\AutonomousEvolution\RepoWide\AtlasLoopRepoWideConsumersProvider;
use ReflectionClass;
use Tests\TestCase;

final class AtlasLoopRepoWideConsumersProviderTest extends TestCase
{
    public function test_consumers_of_returns_cross_domain_consumers_from_model_and_resolver(): void
    {
        $provider = new AtlasLoopRepoWideConsumersProvider($this->model(), new AtlasLoopRepoWideCallerResolver);

        $this->assertSame([
            'App\\Billing\\InvoiceConsumer',
            'App\\Fulfillment\\ShipmentConsumer',
        ], $provider->consumersOf('App\\Domain\\TargetWorker'));
    }

    public function test_as_callable_yields_the_same_list_as_consumers_of(): void
    {
        $provider = new AtlasLoopRepoWideConsumersProvider($this->model(), new AtlasLoopRepoWideCallerResolver);
        $callable = $provider->asCallable();

        $this->assertIsCallable($callable);
        $this->assertSame(
            $provider->consumersOf('App\\Domain\\TargetWorker'),
            $callable('App\\Domain\\TargetWorker'),
        );
    }

    public function test_consumers_of_is_deterministic_sorted_and_deduped(): void
    {
        $provider = new AtlasLoopRepoWideConsumersProvider($this->modelWithDuplicateConsumer(), new AtlasLoopRepoWideCallerResolver);

        $first = $provider->consumersOf('\\App\\Domain\\TargetWorker');
        $second = $provider->consumersOf('\\App\\Domain\\TargetWorker');

        $this->assertSame($first, $second);
        $this->assertSame([
            'App\\Alpha\\AConsumer',
            'App\\Zeta\\ZConsumer',
        ], $first);
    }

    public function test_source_does_not_import_or_modify_blast_radius_analyzer(): void
    {
        $source = (string) file_get_contents((new ReflectionClass(AtlasLoopRepoWideConsumersProvider::class))->getFileName());

        $this->assertStringNotContainsString('AtlasLoopBlastRadiusAnalyzer', $source);
        $this->assertStringNotContainsString('Discovery\\AtlasLoopBlastRadiusAnalyzer', $source);
    }

    /** @return array<string,mixed> */
    private function model(): array
    {
        return [
            'schema' => 'atlas.loop.repo_wide_comprehension.v1',
            'symbols' => [
                [
                    'fqcn' => 'App\\Domain\\TargetWorker',
                    'file_path' => 'app/Domain/TargetWorker.php',
                    'content' => <<<'PHP'
                        <?php
                        namespace App\Domain;
                        final class TargetWorker {}
                        PHP,
                ],
                [
                    'fqcn' => 'App\\Fulfillment\\ShipmentConsumer',
                    'file_path' => 'app/Fulfillment/ShipmentConsumer.php',
                    'content' => <<<'PHP'
                        <?php
                        namespace App\Fulfillment;
                        final class ShipmentConsumer
                        {
                            public function target(): string
                            {
                                return \App\Domain\TargetWorker::class;
                            }
                        }
                        PHP,
                ],
                [
                    'fqcn' => 'App\\Billing\\InvoiceConsumer',
                    'file_path' => 'app/Billing/InvoiceConsumer.php',
                    'content' => <<<'PHP'
                        <?php
                        namespace App\Billing;
                        use App\Domain\TargetWorker;
                        final class InvoiceConsumer
                        {
                            public function handle(TargetWorker $worker): void {}
                        }
                        PHP,
                ],
                [
                    'fqcn' => 'App\\Noise\\FalsePositive',
                    'file_path' => 'app/Noise/FalsePositive.php',
                    'content' => <<<'PHP'
                        <?php
                        namespace App\Noise;
                        use App\Domain\TargetWorkerFactory;
                        final class FalsePositive
                        {
                            public function handle(TargetWorkerFactory $factory): void {}
                        }
                        PHP,
                ],
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function modelWithDuplicateConsumer(): array
    {
        return [
            'file_contents_by_path' => [
                'app/Zeta/ZConsumer.php' => <<<'PHP'
                    <?php
                    namespace App\Zeta;
                    use App\Domain\TargetWorker;
                    final class ZConsumer { public function run(TargetWorker $worker): void {} }
                    PHP,
                'app/Alpha/AConsumer.php' => <<<'PHP'
                    <?php
                    namespace App\Alpha;
                    use App\Domain\TargetWorker;
                    final class AConsumer { public function run(TargetWorker $worker): void {} }
                    PHP,
            ],
            'symbols' => [
                ['fqcn' => 'App\\Zeta\\ZConsumer', 'path' => 'app/Zeta/ZConsumer.php'],
                ['fqcn' => 'App\\Zeta\\ZConsumer', 'path' => 'app/Zeta/ZConsumer.php'],
                ['fqcn' => 'App\\Alpha\\AConsumer', 'path' => 'app/Alpha/AConsumer.php'],
            ],
        ];
    }
}

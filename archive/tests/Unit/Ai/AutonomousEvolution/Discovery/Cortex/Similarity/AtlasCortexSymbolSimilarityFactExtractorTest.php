<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Discovery\Cortex\Similarity;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopScopeComprehensionModel;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Similarity\AtlasCortexSymbolSimilarityFactExtractor;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Similarity\AtlasCortexSymbolSimilarityPairFact;
use PHPUnit\Framework\TestCase;

final class AtlasCortexSymbolSimilarityFactExtractorTest extends TestCase
{
    public function test_it_emits_name_overlap_facts_without_scalar_verdicts(): void
    {
        $classes = $this->loadFixtureClasses([
            'OrderInvoiceProcessor' => <<<'PHP'
<?php
namespace %s;

final class OrderInvoiceProcessor
{
    public function processOrder(array $payload): array
    {
        $payload['processed'] = true;

        return $payload;
    }

    public function normalizeInvoice(array $invoice): array
    {
        $invoice['normalized'] = true;

        return $invoice;
    }
}
PHP,
            'OrderInvoiceCoordinator' => <<<'PHP'
<?php
namespace %s;

final class OrderInvoiceCoordinator
{
    public function processOrder(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if ($value === null) {
                $payload[$key] = 'missing';
            }
        }

        return $payload;
    }

    public function normalizeInvoice(array $invoice): array
    {
        foreach ($invoice as $key => $value) {
            $invoice[$key] = is_string($value) ? trim($value) : $value;
        }

        return $invoice;
    }
}
PHP,
        ]);

        $facts = (new AtlasCortexSymbolSimilarityFactExtractor)->extract($this->snapshotFor($classes));

        $this->assertCount(1, $facts);
        $fact = $facts[0];
        $this->assertInstanceOf(AtlasCortexSymbolSimilarityPairFact::class, $fact);
        $this->assertSame(1.0, $fact->methodNameOverlapRatio);
        $this->assertGreaterThanOrEqual(0.6, $fact->tokenOverlapRatio);
        $this->assertArrayNotHasKey('is_similar', $fact->toArray());
    }

    public function test_it_is_byte_identical_for_the_same_snapshot(): void
    {
        $classes = $this->loadFixtureClasses([
            'AlphaBillingBridge' => <<<'PHP'
<?php
namespace %s;

final class AlphaBillingBridge
{
    public function syncLedger(array $entries): array
    {
        return array_values($entries);
    }

    public function exportSummary(int $limit): array
    {
        return array_fill(0, $limit, 'summary');
    }
}
PHP,
            'AlphaBillingRelay' => <<<'PHP'
<?php
namespace %s;

final class AlphaBillingRelay
{
    public function syncLedger(array $entries): array
    {
        return array_reverse($entries);
    }

    public function exportSummary(int $limit): array
    {
        return array_fill(0, $limit, 'relay');
    }
}
PHP,
            'WarehouseNotifier' => <<<'PHP'
<?php
namespace %s;

final class WarehouseNotifier
{
    public function notifyWarehouse(string $message): string
    {
        return strtoupper($message);
    }
}
PHP,
        ]);

        $snapshot = $this->snapshotFor($classes);
        $extractor = new AtlasCortexSymbolSimilarityFactExtractor;

        $first = json_encode(array_map(static fn (AtlasCortexSymbolSimilarityPairFact $fact): array => $fact->toArray(), $extractor->extract($snapshot)), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $second = json_encode(array_map(static fn (AtlasCortexSymbolSimilarityPairFact $fact): array => $fact->toArray(), $extractor->extract($snapshot)), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $this->assertSame($first, $second);
    }

    public function test_it_discriminates_unrelated_pairs_without_threshold_verdicts(): void
    {
        $classes = $this->loadFixtureClasses([
            'WorkflowMatrixAssembler' => <<<'PHP'
<?php
namespace %s;

final class WorkflowMatrixAssembler
{
    public function planWorkflow(): void
    {
        if (true) {
            for ($i = 0; $i < 2; $i++) {
            }
        }
    }

    public function buildMatrix(int $size): array
    {
        return array_fill(0, $size, []);
    }

    public function trackVariance(array $values): int
    {
        $total = 0;
        foreach ($values as $value) {
            $total += $value;
        }

        return $total;
    }

    public function mergeSignals(string $left, string $right): string
    {
        return $left.$right;
    }

    public function archiveSnapshot(): bool
    {
        return true;
    }
}
PHP,
            'BeaconPingEmitter' => <<<'PHP'
<?php
namespace %s;

final class BeaconPingEmitter
{
    public function emitPing(string $channel, int $attempt): string
    {
        return $channel.'-'.$attempt;
    }
}
PHP,
        ]);

        $facts = (new AtlasCortexSymbolSimilarityFactExtractor)->extract($this->snapshotFor($classes));

        $this->assertCount(1, $facts);
        $fact = $facts[0];
        $this->assertSame(0, $fact->methodNameOverlapCount);
        $this->assertLessThanOrEqual(0.2, $fact->astShapeOverlapRatio);
    }

    /**
     * @param  array<string,string>  $classBodies
     * @return array<string,array{fqcn:string, public_methods:list<string>, rel_path:string}>
     */
    private function loadFixtureClasses(array $classBodies): array
    {
        $namespace = 'Tests\\Fixtures\\CortexSimilarity\\Run'.str_replace('.', '', uniqid('', true));
        $root = sys_get_temp_dir().'/atlas-cortex-sim-'.md5($namespace);
        if (! is_dir($root)) {
            mkdir($root, 0775, true);
        }

        $classes = [];
        foreach ($classBodies as $className => $template) {
            $path = $root.'/'.$className.'.php';
            file_put_contents($path, sprintf($template, $namespace));
            require_once $path;

            $fqcn = $namespace.'\\'.$className;
            $methods = array_map(
                static fn (\ReflectionMethod $method): string => $method->getName(),
                array_filter(
                    (new \ReflectionClass($fqcn))->getMethods(\ReflectionMethod::IS_PUBLIC),
                    static fn (\ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === $fqcn,
                ),
            );
            sort($methods, SORT_STRING);

            $classes[$fqcn] = [
                'fqcn' => $fqcn,
                'public_methods' => $methods,
                'rel_path' => $path,
            ];
        }

        ksort($classes, SORT_STRING);

        return $classes;
    }

    /**
     * @param  array<string,array{fqcn:string, public_methods:list<string>, rel_path:string}>  $classes
     */
    private function snapshotFor(array $classes): AtlasLoopScopeComprehensionModel
    {
        $inventory = [];
        foreach ($classes as $class) {
            $inventory[] = [
                'rel_path' => $class['rel_path'],
                'fqcn' => $class['fqcn'],
                'public_methods' => $class['public_methods'],
                'is_orphan' => false,
                'is_forbidden' => false,
                'clone_cluster_id' => null,
            ];
        }

        usort($inventory, static fn (array $left, array $right): int => strcmp($left['fqcn'], $right['fqcn']));

        return new AtlasLoopScopeComprehensionModel(
            inventory: $inventory,
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

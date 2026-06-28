<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\AtlasLoopContractGapScanner;
use PHPUnit\Framework\TestCase;

/**
 * CONTRACT-GAP scanner — the shared organ behind both the Mode-B command and the automated origination prompt.
 * gapsFrom is the binary membership signal (zero implementers, never a score); interfaceMethods carries the
 * grounded obligation. Both pure → frozen here.
 */
final class AtlasLoopContractGapScannerTest extends TestCase
{
    public function test_only_zero_implementer_interfaces_are_gaps(): void
    {
        $gaps = AtlasLoopContractGapScanner::gapsFrom([
            ['fqcn' => 'A\\Z', 'file' => 'a', 'methods' => [], 'implementer_count' => 0],
            ['fqcn' => 'A\\Has', 'file' => 'b', 'methods' => [], 'implementer_count' => 1],
            ['fqcn' => 'A\\A', 'file' => 'c', 'methods' => [], 'implementer_count' => 0],
        ]);
        self::assertSame(['A\\A', 'A\\Z'], array_column($gaps, 'fqcn'), 'only zero-impl, sorted by FQCN');
    }

    public function test_no_gaps_when_every_contract_is_implemented(): void
    {
        $gaps = AtlasLoopContractGapScanner::gapsFrom([
            ['fqcn' => 'A\\X', 'file' => 'a', 'methods' => [], 'implementer_count' => 3],
        ]);
        self::assertSame([], $gaps);
    }

    public function test_interface_methods_are_extracted_as_grounded_obligation(): void
    {
        $source = <<<'PHP'
        <?php
        namespace App\Demo;
        interface AtlasLoopAmbitionFacultyStore
        {
            public function current(): ?AmbitionState;
            public function save(AmbitionState $state): void;
            // a comment, not a method
        }
        PHP;
        $methods = AtlasLoopContractGapScanner::interfaceMethods($source);
        self::assertSame(
            ['function current(): ?AmbitionState', 'function save(AmbitionState $state): void'],
            $methods,
        );
    }

    public function test_interface_with_no_methods_yields_empty_obligation(): void
    {
        self::assertSame([], AtlasLoopContractGapScanner::interfaceMethods("<?php\ninterface Marker {}\n"));
    }
}

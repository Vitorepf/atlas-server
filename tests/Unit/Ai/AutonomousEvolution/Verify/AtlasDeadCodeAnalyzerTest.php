<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Verify;

use App\Services\Ai\AutonomousEvolution\Verify\AtlasDeadCodeAnalyzer;
use PHPUnit\Framework\TestCase;

/**
 * Soundness tests for the dead-code oracle: it must flag genuinely-dead private members
 * and NEVER flag a member that is used or could be reached dynamically.
 */
final class AtlasDeadCodeAnalyzerTest extends TestCase
{
    private function analyze(string $code): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'dc-test-');
        file_put_contents($tmp, $code);
        try {
            return (new AtlasDeadCodeAnalyzer)->analyzeFile($tmp);
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * @return list<string>
     */
    private function deadNames(array $report): array
    {
        return array_map(static fn (array $d): string => $d['name'], $report['dead']);
    }

    public function test_flags_unused_private_method_const_and_property(): void
    {
        $report = $this->analyze(<<<'PHP'
        <?php
        final class Subject {
            private const USED = 1;
            private const DEAD_C = 2;
            private int $usedP = 0;
            private int $deadP = 0;
            public function run(): int { return $this->used() + self::USED + $this->usedP; }
            private function used(): int { return 1; }
            private function deadM(): int { return 2; }
        }
        PHP);

        $this->assertTrue($report['parseable']);
        $names = $this->deadNames($report);
        sort($names);
        $this->assertSame(['DEAD_C', 'deadM', 'deadP'], $names);
    }

    public function test_never_flags_promoted_constructor_property(): void
    {
        $report = $this->analyze(<<<'PHP'
        <?php
        final class Subject {
            public function __construct(private readonly string $promoted = 'x') {}
        }
        PHP);

        $this->assertSame([], $report['dead']);
    }

    public function test_dynamic_dispatch_disables_method_flagging(): void
    {
        $report = $this->analyze(<<<'PHP'
        <?php
        final class Subject {
            public function __call($name, $args) { return $this->$name(...$args); }
            private function looksDead(): int { return 1; }
        }
        PHP);

        $this->assertSame([], $report['dead']);
    }

    public function test_call_user_func_with_method_string_is_a_use(): void
    {
        $report = $this->analyze(<<<'PHP'
        <?php
        final class Subject {
            public function run(): void { call_user_func([$this, 'handler']); }
            private function handler(): void {}
        }
        PHP);

        $this->assertSame([], $report['dead']);
    }

    public function test_method_referenced_only_as_string_callable_is_a_use(): void
    {
        $report = $this->analyze(<<<'PHP'
        <?php
        final class Subject {
            public function run(): array { return array_map([$this, 'mapper'], [1,2]); }
            private function mapper(int $x): int { return $x * 2; }
        }
        PHP);

        $this->assertSame([], $report['dead']);
    }

    public function test_unparseable_file_is_inconclusive_not_clean(): void
    {
        $report = $this->analyze('<?php final class { broken syntax');
        $this->assertFalse($report['parseable']);
        $this->assertSame([], $report['dead']);
    }

    public function test_oversized_file_is_skipped_fail_closed(): void
    {
        // A multi-MB (generated) file must be skipped as inconclusive, never parsed — parsing
        // an 8MB file OOMs the CLI and would crash a 24h scan.
        $big = "<?php\nfinal class Big {\n    private function dead(): int { return 1; }\n    // ".str_repeat('x', 600 * 1024)."\n}\n";
        $report = $this->analyze($big);
        $this->assertFalse($report['parseable']);
        $this->assertSame('too_large_to_parse', $report['note'] ?? null);
        $this->assertSame([], $report['dead']);
    }

    public function test_property_used_in_string_interpolation_is_a_use(): void
    {
        $report = $this->analyze(<<<'PHP'
        <?php
        final class Subject {
            private string $name = 'a';
            public function greet(): string { return "hello {$this->name}"; }
        }
        PHP);

        $this->assertSame([], $report['dead']);
    }
}

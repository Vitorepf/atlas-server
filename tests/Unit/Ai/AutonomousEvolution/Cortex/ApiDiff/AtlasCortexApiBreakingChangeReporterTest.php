<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Cortex\ApiDiff;

use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\ApiDiff\AtlasCortexApiBreakingChangeReporter;
use PHPUnit\Framework\TestCase;

final class AtlasCortexApiBreakingChangeReporterTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/atlas-cortex-breaking-'.bin2hex(random_bytes(4));
        @mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->dir.'/*') as $path) {
            @unlink((string) $path);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    public function test_it_reports_only_consumers_of_removed_or_changed_methods(): void
    {
        $fooConsumer = $this->writeFixture('FooConsumer.php', "<?php\n\$svc->foo();\n");
        $barConsumer = $this->writeFixture('BarConsumer.php', "<?php\nTargetClass::bar();\n");
        $otherConsumer = $this->writeFixture('OtherConsumer.php', "<?php\n\$svc->unrelated();\n");

        $diff = [
            ['method_name' => 'foo', 'classification' => 'removed', 'changed_fields' => []],
            ['method_name' => 'bar', 'classification' => 'changed', 'changed_fields' => []],
            ['method_name' => 'stable', 'classification' => 'unchanged', 'changed_fields' => []],
        ];

        $rows = (new AtlasCortexApiBreakingChangeReporter)->report($diff, 'TargetClass', [
            ['consumer_fqcn' => 'App\\FooConsumer', 'consumer_file' => $fooConsumer],
            ['consumer_fqcn' => 'App\\BarConsumer', 'consumer_file' => $barConsumer],
            ['consumer_fqcn' => 'App\\OtherConsumer', 'consumer_file' => $otherConsumer],
        ]);

        $this->assertSame([
            [
                'consumer_fqcn' => 'App\\BarConsumer',
                'consumer_file' => $barConsumer,
                'consumer_line' => 2,
                'target_method' => 'bar',
                'change_kind' => 'changed',
                'evidence_snippet' => 'TargetClass::bar();',
            ],
            [
                'consumer_fqcn' => 'App\\FooConsumer',
                'consumer_file' => $fooConsumer,
                'consumer_line' => 2,
                'target_method' => 'foo',
                'change_kind' => 'removed',
                'evidence_snippet' => '$svc->foo();',
            ],
        ], $rows);
    }

    public function test_output_has_only_factual_keys_and_stable_ordering(): void
    {
        $first = $this->writeFixture('AConsumer.php', "<?php\n\$svc->foo();\n");
        $second = $this->writeFixture('BConsumer.php', "<?php\nTargetClass::bar();\n");

        $diff = [
            ['method_name' => 'bar', 'classification' => 'changed', 'changed_fields' => []],
            ['method_name' => 'foo', 'classification' => 'removed', 'changed_fields' => []],
        ];

        $rows = (new AtlasCortexApiBreakingChangeReporter)->report($diff, 'TargetClass', [
            ['consumer_fqcn' => 'App\\ZedConsumer', 'consumer_file' => $second],
            ['consumer_fqcn' => 'App\\AlphaConsumer', 'consumer_file' => $first],
        ]);

        foreach ($rows as $row) {
            $this->assertSame(
                ['consumer_fqcn', 'consumer_file', 'consumer_line', 'target_method', 'change_kind', 'evidence_snippet'],
                array_keys($row)
            );
            $this->assertArrayNotHasKey('severity', $row);
            $this->assertArrayNotHasKey('breaking', $row);
            $this->assertArrayNotHasKey('risk', $row);
            $this->assertArrayNotHasKey('score', $row);
        }

        $this->assertSame('App\\AlphaConsumer', $rows[0]['consumer_fqcn']);
        $this->assertSame('App\\ZedConsumer', $rows[1]['consumer_fqcn']);
    }

    private function writeFixture(string $name, string $contents): string
    {
        $path = $this->dir.'/'.$name;
        file_put_contents($path, $contents);

        return $path;
    }
}

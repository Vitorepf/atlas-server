<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Cortex\ApiDiff;

use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\ApiDiff\AtlasCortexApiSurfaceDiffer;
use PHPUnit\Framework\TestCase;

final class AtlasCortexApiSurfaceDifferTest extends TestCase
{
    public function test_it_classifies_added_removed_and_changed_methods_without_qualitative_keys(): void
    {
        $baseline = [
            'foo' => $this->methodRecord('foo', [['name' => 'value', 'type_hint' => 'int', 'default_literal' => 'NO_DEFAULT']], '', false, 'foo-hash-a'),
            'bar' => $this->methodRecord('bar', [['name' => 'text', 'type_hint' => 'string', 'default_literal' => 'NO_DEFAULT']], '', false, 'bar-hash-a'),
        ];
        $candidate = [
            'foo' => $this->methodRecord('foo', [['name' => 'value', 'type_hint' => '?int', 'default_literal' => 'NO_DEFAULT']], '', false, 'foo-hash-b'),
            'baz' => $this->methodRecord('baz', [], '', false, 'baz-hash-b'),
        ];

        $diff = (new AtlasCortexApiSurfaceDiffer)->diff($baseline, $candidate, 'App\\Subject');

        $this->assertSame([
            [
                'fqcn' => 'App\\Subject',
                'method_name' => 'bar',
                'classification' => 'removed',
                'changed_fields' => [],
            ],
            [
                'fqcn' => 'App\\Subject',
                'method_name' => 'baz',
                'classification' => 'added',
                'changed_fields' => [],
            ],
            [
                'fqcn' => 'App\\Subject',
                'method_name' => 'foo',
                'classification' => 'changed',
                'changed_fields' => [[
                    'kind' => 'param_type_changed',
                    'parameter' => 'value',
                    'from' => 'int',
                    'to' => '?int',
                ]],
            ],
        ], $diff);

        $this->assertStringNotContainsString('severity', json_encode($diff));
        $this->assertStringNotContainsString('risk', json_encode($diff));
    }

    public function test_identical_snapshots_are_stably_reported_as_unchanged(): void
    {
        $snapshot = [
            'alpha' => $this->methodRecord('alpha', [], 'int', false, 'alpha-hash'),
            'beta' => $this->methodRecord('beta', [['name' => 'name', 'type_hint' => 'string', 'default_literal' => "'ok'"]], '?string', true, 'beta-hash'),
        ];

        $differ = new AtlasCortexApiSurfaceDiffer;
        $first = $differ->diff($snapshot, $snapshot, 'App\\Stable');
        $second = $differ->diff($snapshot, $snapshot, 'App\\Stable');

        $this->assertSame($first, $second);
        $this->assertSame([
            [
                'fqcn' => 'App\\Stable',
                'method_name' => 'alpha',
                'classification' => 'unchanged',
                'changed_fields' => [],
            ],
            [
                'fqcn' => 'App\\Stable',
                'method_name' => 'beta',
                'classification' => 'unchanged',
                'changed_fields' => [],
            ],
        ], $first);
    }

    /**
     * @param  list<array{name:string,type_hint:string,default_literal:string}>  $parameters
     * @return array<string,mixed>
     */
    private function methodRecord(string $methodName, array $parameters, string $returnType, bool $static, string $signatureHash): array
    {
        $normalizedParameters = array_map(
            static fn (array $parameter): array => $parameter + ['by_ref' => false, 'variadic' => false],
            $parameters,
        );

        return [
            'method_name' => $methodName,
            'visibility' => 'public',
            'static' => $static,
            'parameters' => $normalizedParameters,
            'return_type_hint' => $returnType,
            'declaring_file_path' => '/tmp/'.$methodName.'.php',
            'start_line' => 1,
            'end_line' => 2,
            'signature_hash' => $signatureHash,
        ];
    }
}

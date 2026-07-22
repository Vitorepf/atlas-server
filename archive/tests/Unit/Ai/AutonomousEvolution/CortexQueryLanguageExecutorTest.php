<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\QueryLanguage\AtlasCortexQueryLanguageExecutor;
use PHPUnit\Framework\TestCase;

final class CortexQueryLanguageExecutorTest extends TestCase
{
    public function test_it_executes_deterministically_with_where_group_order_and_limit(): void
    {
        $ast = [
            'SELECT' => ['classification', 'count(*)', 'max(unwired_days)'],
            'FROM' => 'cortex_temporal_orphans',
            'WHERE' => ['field' => 'change_kind', 'operator' => '=', 'value' => 'changed'],
            'GROUP BY' => ['classification'],
            'ORDER BY' => ['classification'],
            'LIMIT' => 2,
        ];

        $snapshot = [
            ['fqcn' => 'App\\A', 'classification' => 'alpha', 'change_kind' => 'changed', 'unwired_days' => 10],
            ['fqcn' => 'App\\B', 'classification' => 'alpha', 'change_kind' => 'changed', 'unwired_days' => 20],
            ['fqcn' => 'App\\C', 'classification' => 'beta', 'change_kind' => 'changed', 'unwired_days' => 5],
            ['fqcn' => 'App\\D', 'classification' => 'gamma', 'change_kind' => 'removed', 'unwired_days' => 99],
        ];

        $executor = new AtlasCortexQueryLanguageExecutor;
        $first = $executor->execute($ast, $snapshot, 'snapshot-v1');
        $second = $executor->execute($ast, $snapshot, 'snapshot-v1');

        $this->assertSame(['columns', 'rows', 'group_by', 'applied_filters', 'snapshot_version'], array_keys($first));
        $this->assertSame(['classification', 'count(*)', 'max(unwired_days)'], $first['columns']);
        $this->assertSame(['classification'], $first['group_by']);
        $this->assertSame(
            json_encode($first, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            json_encode($second, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
        );
    }

    public function test_null_group_by_value_is_echoed_not_aggregated(): void
    {
        $result = (new AtlasCortexQueryLanguageExecutor)->execute(
            [
                'SELECT' => ['classification', 'count(*)'],
                'FROM' => 'cortex_api_diff',
                'GROUP BY' => ['classification'],
            ],
            [
                ['fqcn' => 'App\\A', 'classification' => null],
                ['fqcn' => 'App\\B', 'classification' => null],
                ['fqcn' => 'App\\C', 'classification' => 'alpha'],
            ],
            'snap-v1'
        );

        $nullGroup = null;
        foreach ($result['rows'] as $row) {
            if (array_key_exists('classification', $row) && $row['classification'] === null) {
                $nullGroup = $row;
                break;
            }
        }
        $this->assertNotNull($nullGroup, 'null GROUP BY value must produce a row with classification=>null');
        $this->assertSame(2, $nullGroup['count(*)']);
    }

    public function test_it_emits_fact_only_envelope_fields(): void
    {
        $envelope = (new AtlasCortexQueryLanguageExecutor)->execute(
            [
                'SELECT' => ['fqcn', 'classification'],
                'FROM' => 'cortex_api_diff',
                'ORDER BY' => ['classification'],
            ],
            [
                ['fqcn' => 'App\\B', 'classification' => 'beta'],
                ['fqcn' => 'App\\A', 'classification' => 'alpha'],
            ],
            'snapshot-v1'
        );

        $this->assertSame(['columns', 'rows', 'group_by', 'applied_filters', 'snapshot_version'], array_keys($envelope));
        $json = json_encode($envelope, JSON_THROW_ON_ERROR);
        $this->assertIsString($json);
        $this->assertStringNotContainsString('score', $json);
        $this->assertStringNotContainsString('rank', $json);
        $this->assertStringNotContainsString('confidence', $json);
        $this->assertStringNotContainsString('narrative', $json);
        $this->assertStringNotContainsString('summary', $json);
        $this->assertStringNotContainsString('inference', $json);
    }
}

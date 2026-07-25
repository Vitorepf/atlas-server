<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\OperatorIntelligence;

use App\Services\Ai\OperatorIntelligence\OperatorTaxonomyRegistry;
use App\Services\Ai\OperatorIntelligence\Support\OperatorTaxonomyParseSupport;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class OperatorTaxonomyParseSupportTest extends TestCase
{
    #[Test]
    public function parse_markdown_table_layers_and_flags(): void
    {
        $md = <<<'MD'
| id | n | description |
| SYS-001 | 1 | system routing |
| OP-102 | 102 | high stakes preference |
| COL-151 | 151 | momentary collab |
MD;
        $items = OperatorTaxonomyParseSupport::parseMarkdownTable(
            $md,
            ['OP-102'],
            ['OP-102'],
            ['OP-102'],
            ['COL-151'],
        );

        $this->assertCount(3, $items);
        $this->assertSame(OperatorTaxonomyRegistry::LAYER_SYSTEM, $items['SYS-001']['layer']);
        $this->assertSame('system_telemetry', $items['SYS-001']['inferability']);
        $this->assertTrue($items['OP-102']['high_stakes']);
        $this->assertSame('sensitive', $items['OP-102']['privacy_default']);
        $this->assertSame('explicit_only', $items['OP-102']['inferability']);
        $this->assertSame('decaying', $items['COL-151']['validity_default']);
        $this->assertSame('chat', $items['COL-151']['extraction_route']);
    }

    #[Test]
    public function inferability_for_system_and_default(): void
    {
        $this->assertSame(
            'system_telemetry',
            OperatorTaxonomyParseSupport::inferabilityFor('SYS-001', OperatorTaxonomyRegistry::LAYER_SYSTEM, []),
        );
        $this->assertSame(
            'inferable',
            OperatorTaxonomyParseSupport::inferabilityFor('OP-071', OperatorTaxonomyRegistry::LAYER_OPERATOR, []),
        );
    }
}

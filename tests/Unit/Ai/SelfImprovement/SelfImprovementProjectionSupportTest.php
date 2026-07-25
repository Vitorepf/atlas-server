<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfImprovement;

use App\Services\Ai\SelfImprovement\Support\SelfImprovementProjectionSupport;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SelfImprovementProjectionSupportTest extends TestCase
{
    #[Test]
    public function ledger_finding_projection_counts_unique_source_types(): void
    {
        $projection = SelfImprovementProjectionSupport::ledgerFindingProjection([
            'title' => 't',
            'category' => 'c',
            'dedupe_key' => 'd',
            'confidence' => 0.9,
            'source_refs' => [
                ['type' => 'ledger'],
                ['type' => 'ledger'],
                ['type' => 'code'],
            ],
            'metadata' => [
                'schema_version' => 'v1',
                'review_signal' => ['severity' => 'high'],
            ],
        ]);

        $this->assertSame(3, $projection['source_ref_count']);
        $this->assertSame(['ledger', 'code'], $projection['source_types']);
        $this->assertSame('v1', $projection['schema_version']);
    }

    #[Test]
    public function missing_architecture_endpoints_detects_mismatch(): void
    {
        $missing = SelfImprovementProjectionSupport::missingArchitectureOperationEndpoints(
            ['op1' => ['http_path' => '/wrong']],
            ['op1' => '/expected', 'op2' => '/missing'],
            'http_path',
        );

        $this->assertArrayHasKey('op1', $missing);
        $this->assertArrayHasKey('op2', $missing);
        $this->assertSame('/expected', $missing['op1']['expected']);
    }
}

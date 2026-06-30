<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Cortex;

use App\Services\Ai\SelfConstruction\Cortex\AtlasSelfConstructionCortexFreshnessBridge;
use Tests\TestCase;

class AtlasSelfConstructionCortexFreshnessBridgeStaleBoundTest extends TestCase
{
    private function bridge(): AtlasSelfConstructionCortexFreshnessBridge
    {
        return new AtlasSelfConstructionCortexFreshnessBridge();
    }

    private function sourcesWithOneStale(int $staleAgeSeconds): array
    {
        $now = 1_000_000;
        $fresh = ['last_unix' => $now - 10, 'hash' => 'h'];
        $stale = ['last_unix' => $now - $staleAgeSeconds, 'hash' => 'h'];

        return [
            'now_unix' => $now,
            'freshness_window_seconds' => 3600,
            'sources' => [
                'docs' => $stale,
                'code_index' => $fresh,
                'queue' => $fresh,
                'receipts' => $fresh,
                'runtime_evidence' => $fresh,
                'worker_outcome' => $fresh,
                'project_lane' => $fresh,
            ],
        ];
    }

    public function test_stale_beyond_max_bound_blocks_origination_with_refresh_receipt(): void
    {
        $facts = $this->sourcesWithOneStale(7200) + ['max_stale_origin_seconds' => 5000];

        $result = $this->bridge()->adapt($facts);

        $this->assertFalse($result['safe_to_origin_tasks']);
        $blockingForDocs = array_values(array_filter(
            $result['blocking_refresh_plan'],
            static fn (array $p): bool => $p['source_id'] === 'docs',
        ));
        $this->assertNotEmpty($blockingForDocs);
        $this->assertFalse($blockingForDocs[0]['safe_to_origin_tasks']);
        $this->assertStringContainsString('receipt:docs:run_sync', $blockingForDocs[0]['required_receipt']);
    }

    public function test_stale_within_max_bound_remains_advisory_only(): void
    {
        $facts = $this->sourcesWithOneStale(4000) + ['max_stale_origin_seconds' => 5000];

        $result = $this->bridge()->adapt($facts);

        $this->assertTrue($result['stale_but_usable']);
        $advisoryForDocs = array_values(array_filter(
            $result['advisory_refresh_plan'],
            static fn (array $p): bool => $p['source_id'] === 'docs',
        ));
        $this->assertNotEmpty($advisoryForDocs);
        $this->assertTrue($advisoryForDocs[0]['safe_to_origin_tasks']);

        $blockingForDocs = array_values(array_filter(
            $result['blocking_refresh_plan'],
            static fn (array $p): bool => $p['source_id'] === 'docs',
        ));
        $this->assertEmpty($blockingForDocs);
    }

    public function test_no_max_stale_origin_seconds_preserves_legacy_advisory_only_behavior(): void
    {
        $facts = $this->sourcesWithOneStale(7200);

        $result = $this->bridge()->adapt($facts);

        $this->assertTrue($result['stale_but_usable']);
        $blockingForDocs = array_values(array_filter(
            $result['blocking_refresh_plan'],
            static fn (array $p): bool => $p['source_id'] === 'docs',
        ));
        $this->assertEmpty($blockingForDocs);
    }
}

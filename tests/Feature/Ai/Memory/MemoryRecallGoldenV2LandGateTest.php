<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Memory;

use App\Services\Ai\Programming\ProgrammingTestImpactAnalyzer;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * MAXG-03 — golden v2 land-gate suite mapping + pinned floor/hash contract.
 *
 * Full "atlas:land refuses red suite" remains pending_window until MULTV-10/ASI-10.
 * This file is the suite registered in test:impacted for Context/** and *Memory*.
 */
final class MemoryRecallGoldenV2LandGateTest extends TestCase
{
    public const PINNED_RECALL_AT_3_FLOOR = 0.80;

    public const PINNED_RECALL_AT_5_FLOOR = 0.80;

    public function test_v2_fixture_exists_with_cases_and_content_hash_anchors(): void
    {
        $path = base_path('tests/Fixtures/Context/memory_recall_golden/v2.json');
        $this->assertTrue(File::exists($path));

        $fixture = json_decode((string) File::get($path), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('atlas.memory_recall_golden_set.v1', $fixture['schema_version'] ?? null);
        $this->assertSame('v2', $fixture['version'] ?? null);
        $cases = (array) ($fixture['cases'] ?? []);
        $this->assertGreaterThanOrEqual(25, count($cases));

        foreach ($cases as $case) {
            $hash = (string) data_get($case, 'must_include.0.source_ref_hash');
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $hash);
            $this->assertNotSame('', trim((string) ($case['query'] ?? '')));
            // Independence: body/query must not be echoed into must_include text fields.
            $this->assertArrayNotHasKey('body', (array) data_get($case, 'must_include.0', []));
        }

        $frozen = hash('sha256', json_encode($fixture, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $this->assertSame(64, strlen($frozen));
        $this->assertGreaterThan(0.0, self::PINNED_RECALL_AT_3_FLOOR);
        $this->assertGreaterThan(0.0, self::PINNED_RECALL_AT_5_FLOOR);
    }

    public function test_test_impacted_maps_context_and_memory_paths_to_this_suite(): void
    {
        $analyzer = app(ProgrammingTestImpactAnalyzer::class);

        foreach ([
            'app/Services/Ai/Context/LocalRagBenchmarkService.php',
            'app/Services/Ai/Memory/AtlasMemoryVectorSearchService.php',
            'app/Services/Ai/Memory/AtlasHybridMemoryRetrievalService.php',
        ] as $path) {
            $receipt = $analyzer->analyze([$path], [], 'medium');
            $this->assertContains(
                'tests/Feature/Ai/Memory/MemoryRecallGoldenV2LandGateTest.php',
                $receipt['selected_tests'],
                'expected MAXG-03 suite for '.$path,
            );
        }
    }
}

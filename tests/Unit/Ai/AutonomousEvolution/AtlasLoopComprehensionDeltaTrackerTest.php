<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopComprehensionDeltaTracker;
use ReflectionMethod;
use Tests\TestCase;

final class AtlasLoopComprehensionDeltaTrackerTest extends TestCase
{
    public function test_identical_snapshots_produce_empty_deltas_for_every_fact_bucket(): void
    {
        $snapshot = $this->snapshot('snap-a');
        $diff = (new AtlasLoopComprehensionDeltaTracker)->diff($snapshot, $snapshot);

        $this->assertSame([], $diff['classes_added']);
        $this->assertSame([], $diff['classes_removed']);
        $this->assertSame([], $diff['orphan_wiring_added']);
        $this->assertSame([], $diff['orphan_wiring_removed']);
        $this->assertSame([], $diff['supply_seam_added']);
        $this->assertSame([], $diff['supply_seam_removed']);
        $this->assertSame([], $diff['doc_stated_gaps_added']);
        $this->assertSame([], $diff['doc_stated_gaps_removed']);
    }

    public function test_adding_one_orphan_wiring_entry_preserves_its_fqcn_and_file_path_only(): void
    {
        $old = $this->snapshot('snap-a', [
            'inventory' => [
                [
                    'fqcn' => 'App\\Services\\Ai\\AutonomousEvolution\\Discovery\\AtlasLoopNewOrphan',
                    'rel_path' => 'app/Services/Ai/AutonomousEvolution/Discovery/AtlasLoopNewOrphan.php',
                    'is_orphan' => false,
                ],
            ],
        ]);
        $new = $this->snapshot('snap-b', [
            'inventory' => [
                [
                    'fqcn' => 'App\\Services\\Ai\\AutonomousEvolution\\Discovery\\AtlasLoopNewOrphan',
                    'rel_path' => 'app/Services/Ai/AutonomousEvolution/Discovery/AtlasLoopNewOrphan.php',
                    'is_orphan' => true,
                ],
            ],
        ]);

        $diff = (new AtlasLoopComprehensionDeltaTracker)->diff($old, $new);

        $this->assertSame([
            [
                'fqcn' => 'App\\Services\\Ai\\AutonomousEvolution\\Discovery\\AtlasLoopNewOrphan',
                'rel_path' => 'app/Services/Ai/AutonomousEvolution/Discovery/AtlasLoopNewOrphan.php',
                'snapshot_id' => 'snap-b',
            ],
        ], $diff['orphan_wiring_added']);

        unset($diff['snapshot_before'], $diff['snapshot_after'], $diff['orphan_wiring_added']);
        foreach ($diff as $bucket) {
            $this->assertSame([], $bucket);
        }
    }

    public function test_tracker_is_a_pure_diff_without_db_http_or_facade_calls(): void
    {
        $source = (string) file_get_contents(app_path('Services/Ai/AutonomousEvolution/Discovery/AtlasLoopComprehensionDeltaTracker.php'));

        $this->assertStringNotContainsString('DB::', $source);
        $this->assertStringNotContainsString('Http::', $source);
        $this->assertStringNotContainsString('Storage::', $source);

        $reflection = new ReflectionMethod(AtlasLoopComprehensionDeltaTracker::class, 'diff');
        $this->assertSame('array', $reflection->getParameters()[0]->getType()?->getName());
        $this->assertSame('array', $reflection->getParameters()[1]->getType()?->getName());
        $this->assertSame('array', $reflection->getReturnType()?->getName());
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function snapshot(string $snapshotId, array $overrides = []): array
    {
        $base = [
            'snapshot_id' => $snapshotId,
            'inventory' => [],
            'doc_stated_gaps' => [],
            'supply_seams' => [],
            'hub_centrality' => [],
        ];

        foreach ($overrides as $key => $value) {
            if ($key === 'inventory') {
                $base['inventory'] = array_merge($base['inventory'], $value);

                continue;
            }

            $base[$key] = $value;
        }

        return $base;
    }
}

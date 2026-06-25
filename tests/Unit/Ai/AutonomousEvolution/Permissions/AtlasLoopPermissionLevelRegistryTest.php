<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Permissions;

use App\Services\Ai\AutonomousEvolution\Permissions\AtlasLoopPermissionLevel;
use App\Services\Ai\AutonomousEvolution\Permissions\AtlasLoopPermissionLevelRegistry;
use InvalidArgumentException;
use Tests\TestCase;

final class AtlasLoopPermissionLevelRegistryTest extends TestCase
{
    public function test_permission_level_has_strict_total_order_read_propose_write_merge(): void
    {
        $levels = [
            AtlasLoopPermissionLevel::READ,
            AtlasLoopPermissionLevel::PROPOSE,
            AtlasLoopPermissionLevel::WRITE,
            AtlasLoopPermissionLevel::MERGE,
        ];
        foreach ($levels as $i => $a) {
            foreach ($levels as $j => $b) {
                $cmp = $a->compareTo($b);
                $expected = $i <=> $j;
                $this->assertSame($expected, $cmp, "compareTo({$a->name},{$b->name}) expected {$expected}");
            }
        }
    }

    public function test_registry_maps_every_canonical_phase(): void
    {
        $registry = new AtlasLoopPermissionLevelRegistry();
        $expected = [
            'observe' => AtlasLoopPermissionLevel::READ,
            'comprehend' => AtlasLoopPermissionLevel::READ,
            'originate' => AtlasLoopPermissionLevel::PROPOSE,
            'project' => AtlasLoopPermissionLevel::PROPOSE,
            'decompose' => AtlasLoopPermissionLevel::PROPOSE,
            'implement' => AtlasLoopPermissionLevel::WRITE,
            'certify' => AtlasLoopPermissionLevel::PROPOSE,
            'merge' => AtlasLoopPermissionLevel::MERGE,
            'learn' => AtlasLoopPermissionLevel::PROPOSE,
        ];
        foreach ($expected as $phase => $level) {
            $this->assertSame($level, $registry->requiredLevelFor($phase), "phase {$phase} mapping");
        }
    }

    public function test_unknown_phase_throws_typed_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new AtlasLoopPermissionLevelRegistry())->requiredLevelFor('definitely_not_a_phase');
    }

    public function test_serialized_map_is_byte_identical_across_two_calls(): void
    {
        $registry = new AtlasLoopPermissionLevelRegistry();
        $first = json_encode($registry->serialized());
        $second = json_encode($registry->serialized());
        $this->assertSame($first, $second);
    }

    public function test_phases_method_returns_sorted_list_of_9_phases(): void
    {
        $registry = new AtlasLoopPermissionLevelRegistry();
        $phases = $registry->phases();
        $this->assertCount(9, $phases);
        $sorted = $phases;
        sort($sorted, SORT_STRING);
        $this->assertSame($sorted, $phases);
    }

    public function test_registry_source_has_no_io_or_provider_calls(): void
    {
        $src = (string) file_get_contents(base_path('app/Services/Ai/AutonomousEvolution/Permissions/AtlasLoopPermissionLevelRegistry.php'));
        foreach (['DB::', 'Http::', 'file_put_contents', 'fopen', 'fwrite', 'curl_', 'Hermes', 'MiniMax', 'Cache::'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $src, "registry must NOT contain {$forbidden}");
        }
    }
}

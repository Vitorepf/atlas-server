<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusLoopPayloadNormalizer;
use Tests\TestCase;

final class AreaFocusLoopPayloadNormalizerTest extends TestCase
{
    public function test_merge_fixture_folds_fixture_under_explicit_input(): void
    {
        $this->assertSame(
            [
                'area' => 'dev_forge',
                'status' => 'explicit',
                'extra' => true,
            ],
            AreaFocusLoopPayloadNormalizer::mergeFixture([
                'fixture' => [
                    'area' => 'dev_forge',
                    'status' => 'fixture',
                ],
                'status' => 'explicit',
                'extra' => true,
            ]),
        );
    }

    public function test_merge_fixture_returns_input_when_fixture_missing_or_empty(): void
    {
        $withoutFixture = ['status' => 'explicit'];
        $emptyFixture = ['fixture' => [], 'status' => 'explicit'];

        $this->assertSame($withoutFixture, AreaFocusLoopPayloadNormalizer::mergeFixture($withoutFixture));
        $this->assertSame($emptyFixture, AreaFocusLoopPayloadNormalizer::mergeFixture($emptyFixture));
    }

    public function test_without_volatile_report_fields_removes_only_checked_at_and_report_hash(): void
    {
        $this->assertSame(
            [
                'status' => 'healthy',
                'hash_source' => 'stable',
            ],
            AreaFocusLoopPayloadNormalizer::withoutVolatileReportFields([
                'status' => 'healthy',
                'checked_at' => '2026-06-10T00:00:00+00:00',
                'report_hash' => 'volatile',
                'hash_source' => 'stable',
            ]),
        );
    }

    public function test_without_volatile_event_fields_removes_only_recorded_at_and_event_hash(): void
    {
        $this->assertSame(
            [
                'event' => 'slice_delivered',
                'report_hash' => 'stable-for-event',
            ],
            AreaFocusLoopPayloadNormalizer::withoutVolatileEventFields([
                'event' => 'slice_delivered',
                'recorded_at' => '2026-06-10T00:00:00+00:00',
                'event_hash' => 'volatile',
                'report_hash' => 'stable-for-event',
            ]),
        );
    }

    public function test_without_fields_removes_explicit_top_level_fields_only(): void
    {
        $this->assertSame(
            [
                'stable' => true,
                'nested' => [
                    'record_hash' => 'kept',
                ],
            ],
            AreaFocusLoopPayloadNormalizer::withoutFields([
                'stable' => true,
                'record_hash' => 'removed',
                'nested' => [
                    'record_hash' => 'kept',
                ],
            ], ['record_hash', 'missing']),
        );
    }

    public function test_list_of_arrays_keeps_only_array_items_in_order(): void
    {
        $this->assertSame(
            [
                ['id' => 'A'],
                ['id' => 'B'],
            ],
            AreaFocusLoopPayloadNormalizer::listOfArrays([
                ['id' => 'A'],
                'not-array',
                ['id' => 'B'],
                42,
            ]),
        );

        $this->assertSame([], AreaFocusLoopPayloadNormalizer::listOfArrays('not-a-list'));
    }

    public function test_payload_array_count_and_non_empty_array_match_raw_payload_contract(): void
    {
        $payload = [
            'empty' => [],
            'items' => ['A', 'B'],
            'scalar' => 'A',
        ];

        $this->assertSame(2, AreaFocusLoopPayloadNormalizer::payloadArrayCount($payload, 'items'));
        $this->assertSame(0, AreaFocusLoopPayloadNormalizer::payloadArrayCount($payload, 'empty'));
        $this->assertSame(0, AreaFocusLoopPayloadNormalizer::payloadArrayCount($payload, 'scalar'));
        $this->assertTrue(AreaFocusLoopPayloadNormalizer::payloadHasNonEmptyArray($payload, 'items'));
        $this->assertFalse(AreaFocusLoopPayloadNormalizer::payloadHasNonEmptyArray($payload, 'empty'));
        $this->assertFalse(AreaFocusLoopPayloadNormalizer::payloadHasNonEmptyArray($payload, 'missing'));
    }

    public function test_merge_target_preserves_allowed_values_and_uses_explicit_fallback(): void
    {
        $this->assertSame('integration_lane', AreaFocusLoopPayloadNormalizer::mergeTarget(' integration_lane ', 'none'));
        $this->assertSame('main', AreaFocusLoopPayloadNormalizer::mergeTarget('MAIN', 'none'));
        $this->assertSame('none', AreaFocusLoopPayloadNormalizer::mergeTarget('invalid', 'none'));
        $this->assertSame('integration_lane', AreaFocusLoopPayloadNormalizer::mergeTarget('invalid', 'integration_lane'));
        $this->assertSame('none', AreaFocusLoopPayloadNormalizer::mergeTarget('invalid', 'also_invalid'));
    }

    public function test_loop_source_normalizes_known_cycle_origins(): void
    {
        $this->assertSame('canonical_backlog', AreaFocusLoopPayloadNormalizer::loopSource(''));
        $this->assertSame('canonical_backlog', AreaFocusLoopPayloadNormalizer::loopSource(' canonical_backlog '));
        $this->assertSame('self_construction_packet', AreaFocusLoopPayloadNormalizer::loopSource('salto3_self_construction_candidate'));
        $this->assertSame('scanner', AreaFocusLoopPayloadNormalizer::loopSource('SCANNER'));
        $this->assertSame('manual_ticket', AreaFocusLoopPayloadNormalizer::loopSource(' Manual_Ticket '));
    }

    public function test_repo_root_resolves_explicit_path_with_realpath_when_available(): void
    {
        $this->assertSame(
            getcwd(),
            AreaFocusLoopPayloadNormalizer::repoRoot(['repo_root' => ' . ']),
        );
    }

    public function test_repo_root_preserves_unresolved_explicit_path(): void
    {
        $missing = getcwd().'/missing-atlas-root-for-normalizer-test';

        $this->assertSame($missing, AreaFocusLoopPayloadNormalizer::repoRoot(['repo_root' => $missing]));
    }

    public function test_repo_root_or_empty_matches_integration_lane_root_contract(): void
    {
        $missing = getcwd().'/missing-atlas-root-for-integration-lane-test';

        $this->assertSame(getcwd(), AreaFocusLoopPayloadNormalizer::repoRootOrEmpty(['repo_root' => ' . ']));
        $this->assertSame($missing, AreaFocusLoopPayloadNormalizer::repoRootOrEmpty(['repo_root' => $missing]));
    }

    public function test_repo_root_raw_preserves_explicit_value_without_realpath(): void
    {
        $this->assertSame('.', AreaFocusLoopPayloadNormalizer::repoRootRaw(['repo_root' => ' . ']));

        $missing = getcwd().'/missing-atlas-root-for-raw-normalizer-test';
        $this->assertSame($missing, AreaFocusLoopPayloadNormalizer::repoRootRaw(['repo_root' => $missing]));
    }
}

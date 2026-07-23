<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainDataFlowEquivalenceChecker;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainDataFlowEquivalenceCheckerTest extends TestCase
{
    private function checker(): AtlasExternalBrainDataFlowEquivalenceChecker
    {
        return new AtlasExternalBrainDataFlowEquivalenceChecker;
    }

    private function flow(array $overrides = []): array
    {
        return array_merge([
            'input_keys' => ['task_packet_id', 'lease_id'],
            'output_keys' => ['status', 'commit_sha'],
            'transform_names' => ['normalize_paths'],
            'required_payload_paths' => ['result.status'],
        ], $overrides);
    }

    public function test_equivalent_data_flow_case(): void
    {
        $r = $this->checker()->evaluate([
            'before' => $this->flow(),
            'after' => $this->flow(),
        ]);

        $this->assertSame('equivalent', $r['verdict']);
        $this->assertSame([], $r['diff']);
    }

    public function test_payload_diff_fail_case_missing_output_key(): void
    {
        $r = $this->checker()->evaluate([
            'before' => $this->flow(),
            'after' => $this->flow(['output_keys' => ['status']]),
        ]);

        $this->assertSame('not_equivalent', $r['verdict']);
        $diffByDimension = [];
        foreach ($r['diff'] as $entry) {
            $diffByDimension[$entry['dimension']] = $entry;
        }
        $this->assertArrayHasKey('output_keys', $diffByDimension);
        $this->assertSame(['commit_sha'], $diffByDimension['output_keys']['missing']);
    }

    public function test_transform_name_changed_fails_closed(): void
    {
        $r = $this->checker()->evaluate([
            'before' => $this->flow(),
            'after' => $this->flow(['transform_names' => ['normalize_paths_v2']]),
        ]);

        $this->assertSame('not_equivalent', $r['verdict']);
        $diffByDimension = array_column($r['diff'], null, 'dimension');
        $this->assertContains('normalize_paths', $diffByDimension['transform_names']['missing']);
        $this->assertContains('normalize_paths_v2', $diffByDimension['transform_names']['added']);
    }

    public function test_unknown_required_payload_path_fails_closed(): void
    {
        $r = $this->checker()->evaluate([
            'before' => $this->flow(),
            'after' => $this->flow(['required_payload_paths' => ['result.status', 'result.unexpected_new_path']]),
        ]);

        $this->assertSame('not_equivalent', $r['verdict']);
        $diffByDimension = array_column($r['diff'], null, 'dimension');
        $this->assertContains('result.unexpected_new_path', $diffByDimension['required_payload_paths']['added']);
    }

    public function test_missing_before_snapshot_fails_closed(): void
    {
        $r = $this->checker()->evaluate(['after' => $this->flow()]);

        $this->assertSame('not_equivalent', $r['verdict']);
    }

    public function test_missing_after_snapshot_fails_closed(): void
    {
        $r = $this->checker()->evaluate(['before' => $this->flow()]);

        $this->assertSame('not_equivalent', $r['verdict']);
    }

    public function test_key_order_does_not_matter(): void
    {
        $r = $this->checker()->evaluate([
            'before' => $this->flow(['input_keys' => ['a', 'b']]),
            'after' => $this->flow(['input_keys' => ['b', 'a']]),
        ]);

        $this->assertSame('equivalent', $r['verdict']);
    }

    public function test_schema_present(): void
    {
        $r = $this->checker()->evaluate([
            'before' => $this->flow(),
            'after' => $this->flow(),
        ]);

        $this->assertSame(AtlasExternalBrainDataFlowEquivalenceChecker::SCHEMA, $r['schema']);
    }
}

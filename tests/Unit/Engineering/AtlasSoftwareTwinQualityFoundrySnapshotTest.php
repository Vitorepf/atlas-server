<?php

declare(strict_types=1);

namespace Tests\Unit\Engineering;

use App\Services\Engineering\AtlasSoftwareTwinRuntimeService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class AtlasSoftwareTwinQualityFoundrySnapshotTest extends TestCase
{
    public function test_snapshot_is_workspace_scoped_provider_safe_and_deterministic(): void
    {
        $input = $this->input();
        $first = AtlasSoftwareTwinRuntimeService::freezeQualityFoundryFacts($input);
        $second = AtlasSoftwareTwinRuntimeService::freezeQualityFoundryFacts(array_replace($input, ['facts' => array_reverse($input['facts'])]));

        self::assertSame($first['snapshot_hash'], $second['snapshot_hash']);
        self::assertSame([], $first['unknown']);
        self::assertSame(['f2'], $first['stale']);
        self::assertArrayNotHasKey('payload', $first['facts'][0]);
    }

    public function test_conflicting_duplicate_facts_are_explicit_and_not_favorably_selected(): void
    {
        $input = $this->input();
        $input['facts'][] = array_replace($input['facts'][0], ['hash' => str_repeat('9', 64)]);

        $snapshot = AtlasSoftwareTwinRuntimeService::freezeQualityFoundryFacts($input);

        self::assertContains('f1', $snapshot['conflicted']);
        self::assertSame('conflicted', $snapshot['facts'][0]['status']);
    }

    public function test_cross_workspace_fact_fails_closed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        AtlasSoftwareTwinRuntimeService::freezeQualityFoundryFacts(array_replace($this->input(), [
            'facts' => [array_replace($this->input()['facts'][0], ['workspace_id' => 'other'])],
        ]));
    }

    public function test_fact_without_temporal_provenance_fails_closed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        AtlasSoftwareTwinRuntimeService::freezeQualityFoundryFacts(array_replace($this->input(), [
            'facts' => [array_replace($this->input()['facts'][0], ['observed_at' => null])],
        ]));
    }

    public function test_unsupported_fact_type_and_missing_consumer_fail_closed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        AtlasSoftwareTwinRuntimeService::freezeQualityFoundryFacts(array_replace($this->input(), [
            'facts' => [array_replace($this->input()['facts'][0], ['type' => 'invented'])],
        ]));
    }

    public function test_missing_consumer_fails_closed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        AtlasSoftwareTwinRuntimeService::freezeQualityFoundryFacts(array_replace($this->input(), ['consumer' => '']));
    }

    public function test_all_canonical_fact_families_are_representable_without_source_payloads(): void
    {
        $families = ['code', 'contract', 'deploy_runtime', 'flag', 'incident', 'ownership', 'outcome', 'performance', 'security', 'docs', 'decision', 'concurrent_work', 'tool_provider'];
        $input = $this->input();
        $input['facts'] = array_map(static fn (string $type): array => [
            'id' => 'family-'.$type, 'type' => $type, 'workspace_id' => 'atlas-server', 'source' => 'fixture-'.$type,
            'hash' => hash('sha256', $type), 'status' => 'fresh', 'valid_from' => '2026-07-11T00:00:00Z',
            'valid_until' => null, 'observed_at' => '2026-07-12T00:30:00Z', 'payload' => ['secret' => 'must-not-escape'],
        ], $families);
        $snapshot = AtlasSoftwareTwinRuntimeService::freezeQualityFoundryFacts($input);

        self::assertCount(count($families), $snapshot['facts']);
        self::assertArrayNotHasKey('payload', $snapshot['facts'][0]);
        self::assertSame([], $snapshot['conflicted']);
    }

    public function test_snapshot_exposes_calibration_and_unresolved_prediction_refs_without_granting_claim(): void
    {
        $input = $this->input();
        $input['prediction_calibration'] = [
            'schema' => 'atlas.quality_foundry.prediction_calibration.v1',
            'status' => 'degraded', 'resolved_count' => 1, 'unresolved_count' => 1,
            'confidence' => 0.4, 'claim_eligible' => false,
        ];
        $input['unresolved_predictions'] = [['prediction_id' => 'p-1', 'reason' => 'outcome_missing']];

        $snapshot = AtlasSoftwareTwinRuntimeService::freezeQualityFoundryFacts($input);

        self::assertSame($input['prediction_calibration'], $snapshot['prediction_calibration']);
        self::assertSame($input['unresolved_predictions'], $snapshot['unresolved_predictions']);
        self::assertFalse($snapshot['claim_policy']['claim_eligible']);
    }

    /** @return array<string,mixed> */
    private function input(): array
    {
        return [
            'workspace_id' => 'atlas-server', 'base_commit' => str_repeat('a', 40), 'consumer' => 'quality-foundry',
            'as_of' => '2026-07-12T01:00:00Z', 'facts' => [
                ['id' => 'f1', 'type' => 'code', 'workspace_id' => 'atlas-server', 'source' => 'code-graph', 'hash' => str_repeat('1', 64), 'status' => 'fresh', 'valid_from' => '2026-07-11T00:00:00Z', 'valid_until' => null, 'observed_at' => '2026-07-12T00:30:00Z'],
                ['id' => 'f2', 'type' => 'outcome', 'workspace_id' => 'atlas-server', 'source' => 'ledger', 'hash' => str_repeat('2', 64), 'status' => 'stale', 'valid_from' => '2026-07-10T00:00:00Z', 'valid_until' => '2026-07-11T00:00:00Z', 'observed_at' => '2026-07-11T01:00:00Z'],
            ],
        ];
    }
}

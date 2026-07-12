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

<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\MultiProject;

use App\Services\Ai\SelfConstruction\MultiProject\AtlasProjectLaneContextFreshnessGate;
use Tests\TestCase;

final class AtlasProjectLaneContextFreshnessGateTest extends TestCase
{
    private const NOW = 2_000_000_000;

    private function manifest(array $windows = ['docs_sync' => 3600, 'code_index' => 3600, 'context_pack' => 600]): array
    {
        return ['project_id' => 'lane-x', 'freshness_window_seconds' => $windows];
    }

    public function test_all_evidence_present_and_fresh_is_conformant(): void
    {
        $verdict = (new AtlasProjectLaneContextFreshnessGate)->evaluate($this->manifest(), [
            'now_unix' => self::NOW,
            'docs_sync_last_unix' => self::NOW - 100,
            'code_index_last_unix' => self::NOW - 100,
            'context_pack_hash' => 'abc123',
            'context_pack_last_unix' => self::NOW - 100,
        ]);

        $this->assertTrue($verdict['conformant']);
        $this->assertSame([], $verdict['blockers']);
    }

    public function test_missing_docs_sync_blocks_with_named_reason(): void
    {
        $verdict = (new AtlasProjectLaneContextFreshnessGate)->evaluate($this->manifest(), [
            'now_unix' => self::NOW,
            'code_index_last_unix' => self::NOW - 100,
            'context_pack_hash' => 'abc',
            'context_pack_last_unix' => self::NOW - 100,
        ]);

        $this->assertFalse($verdict['conformant']);
        $this->assertContains('docs_sync_missing', $verdict['blockers']);
    }

    public function test_stale_code_index_blocks_with_named_reason(): void
    {
        $verdict = (new AtlasProjectLaneContextFreshnessGate)->evaluate($this->manifest(['docs_sync' => 3600, 'code_index' => 60, 'context_pack' => 60]), [
            'now_unix' => self::NOW,
            'docs_sync_last_unix' => self::NOW - 100,
            'code_index_last_unix' => self::NOW - 1_000,
            'context_pack_hash' => 'abc',
            'context_pack_last_unix' => self::NOW - 30,
        ]);

        $this->assertFalse($verdict['conformant']);
        $this->assertContains('code_index_stale', $verdict['blockers']);
    }

    public function test_missing_context_pack_hash_blocks(): void
    {
        $verdict = (new AtlasProjectLaneContextFreshnessGate)->evaluate($this->manifest(), [
            'now_unix' => self::NOW,
            'docs_sync_last_unix' => self::NOW - 100,
            'code_index_last_unix' => self::NOW - 100,
            'context_pack_last_unix' => self::NOW - 100,
        ]);

        $this->assertFalse($verdict['conformant']);
        $this->assertContains('context_pack_missing_hash', $verdict['blockers']);
    }

    public function test_per_evidence_freshness_windows_are_independent(): void
    {
        $gate = new AtlasProjectLaneContextFreshnessGate;

        // Tight 10s context_pack window: at age 30 ⇒ stale; long 3600s docs/code windows ⇒ fresh.
        $verdict = $gate->evaluate($this->manifest(['docs_sync' => 3600, 'code_index' => 3600, 'context_pack' => 10]), [
            'now_unix' => self::NOW,
            'docs_sync_last_unix' => self::NOW - 100,
            'code_index_last_unix' => self::NOW - 100,
            'context_pack_hash' => 'h',
            'context_pack_last_unix' => self::NOW - 30,
        ]);

        $this->assertFalse($verdict['conformant']);
        $this->assertSame(['context_pack_stale'], $verdict['blockers']);
    }

    public function test_verdict_carries_no_numeric_score_field(): void
    {
        $verdict = (new AtlasProjectLaneContextFreshnessGate)->evaluate($this->manifest(), ['now_unix' => self::NOW]);

        foreach (array_keys($verdict) as $key) {
            $this->assertStringNotContainsString('score', strtolower((string) $key), 'verdict must NOT carry a numeric score field');
        }
    }

    public function test_evaluation_is_deterministic_byte_identical(): void
    {
        $gate = new AtlasProjectLaneContextFreshnessGate;
        $obs = [
            'now_unix' => self::NOW,
            'docs_sync_last_unix' => self::NOW - 100,
            'code_index_last_unix' => self::NOW - 100,
            'context_pack_hash' => 'h',
            'context_pack_last_unix' => self::NOW - 100,
        ];

        $this->assertSame(json_encode($gate->evaluate($this->manifest(), $obs)), json_encode($gate->evaluate($this->manifest(), $obs)));
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\KnowledgeSync;

use App\Services\Ai\SelfConstruction\KnowledgeSync\AtlasKnowledgeSyncCodeIndexReadinessGate;
use Tests\TestCase;

final class AtlasKnowledgeSyncCodeIndexReadinessGateTest extends TestCase
{
    private const NOW = 2_000_000_000;

    private function manifest(array $overrides = []): array
    {
        return $overrides + [
            'workspace_id' => 'atlas-server',
            'required_artifacts' => ['atlas_code_symbols', 'atlas_code_files'],
            'max_age_seconds' => 600,
            'docs_only_bypass' => false,
        ];
    }

    private function obs(array $overrides = []): array
    {
        return $overrides + [
            'now_unix' => self::NOW,
            'indexed_at_unix' => self::NOW - 60,
            'index_code_status' => 'pass',
            'observed_artifacts' => ['atlas_code_symbols', 'atlas_code_files'],
            'local_schema_available' => true,
            'changed_code_hash' => 'abc',
            'index_hash' => 'abc',
        ];
    }

    public function test_fully_ready_index_returns_ready_true(): void
    {
        $verdict = (new AtlasKnowledgeSyncCodeIndexReadinessGate)->evaluate($this->manifest(), $this->obs());

        $this->assertTrue($verdict['ready']);
        $this->assertSame([], $verdict['blockers']);
        $this->assertSame('atlas-server', $verdict['workspace_id']);
    }

    public function test_missing_workspace_id_blocks(): void
    {
        $verdict = (new AtlasKnowledgeSyncCodeIndexReadinessGate)->evaluate($this->manifest(['workspace_id' => '']), $this->obs());

        $this->assertFalse($verdict['ready']);
        $this->assertContains('workspace_id_missing', $verdict['blockers']);
    }

    public function test_missing_required_tables_block_with_named_set(): void
    {
        $verdict = (new AtlasKnowledgeSyncCodeIndexReadinessGate)->evaluate(
            $this->manifest(),
            $this->obs(['observed_artifacts' => ['atlas_code_symbols']]),
        );

        $this->assertFalse($verdict['ready']);
        $this->assertContains('code_index_tables_missing:atlas_code_files', $verdict['blockers']);
    }

    public function test_stale_index_blocks_with_named_reason(): void
    {
        $verdict = (new AtlasKnowledgeSyncCodeIndexReadinessGate)->evaluate(
            $this->manifest(['max_age_seconds' => 60]),
            $this->obs(['indexed_at_unix' => self::NOW - 9999]),
        );

        $this->assertFalse($verdict['ready']);
        $this->assertContains('index_stale', $verdict['blockers']);
    }

    public function test_failed_index_code_status_blocks(): void
    {
        $verdict = (new AtlasKnowledgeSyncCodeIndexReadinessGate)->evaluate(
            $this->manifest(),
            $this->obs(['index_code_status' => 'fail']),
        );

        $this->assertFalse($verdict['ready']);
        $this->assertContains('index_code_run_failed', $verdict['blockers']);
    }

    public function test_changed_code_hash_not_represented_blocks(): void
    {
        $verdict = (new AtlasKnowledgeSyncCodeIndexReadinessGate)->evaluate(
            $this->manifest(),
            $this->obs(['changed_code_hash' => 'xyz', 'index_hash' => 'abc']),
        );

        $this->assertFalse($verdict['ready']);
        $this->assertContains('changed_code_hash_not_represented', $verdict['blockers']);
    }

    public function test_local_schema_unavailable_yields_degraded_but_blocked(): void
    {
        $verdict = (new AtlasKnowledgeSyncCodeIndexReadinessGate)->evaluate(
            $this->manifest(),
            $this->obs(['local_schema_available' => false]),
        );

        $this->assertFalse($verdict['ready'], 'degraded_but_blocked is NOT green — it is honest blocking');
        $this->assertContains('local_schema_unavailable', $verdict['degraded_but_blocked']);
        $this->assertContains('degraded_but_blocked:local_schema_unavailable', $verdict['blockers']);
    }

    public function test_docs_only_bypass_short_circuits_when_no_changed_code_hash(): void
    {
        $verdict = (new AtlasKnowledgeSyncCodeIndexReadinessGate)->evaluate(
            $this->manifest(['docs_only_bypass' => true]),
            // no changed_code_hash AND many other things missing — bypass takes priority
            ['now_unix' => self::NOW, 'local_schema_available' => true],
        );

        $this->assertTrue($verdict['ready'], 'docs-only bypass returns ready when there is no code change to reconcile');
        $this->assertTrue($verdict['bypassed_docs_only']);
    }

    public function test_evaluation_is_deterministic_byte_identical(): void
    {
        $gate = new AtlasKnowledgeSyncCodeIndexReadinessGate;
        $a = $gate->evaluate($this->manifest(), $this->obs());
        $b = $gate->evaluate($this->manifest(), $this->obs());

        $this->assertSame(json_encode($a), json_encode($b));
    }
}

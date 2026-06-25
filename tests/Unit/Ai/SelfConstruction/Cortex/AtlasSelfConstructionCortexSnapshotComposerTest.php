<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Cortex;

use App\Services\Ai\SelfConstruction\Cortex\AtlasSelfConstructionCortexSnapshotComposer;
use PHPUnit\Framework\TestCase;

/**
 * Proves AtlasSelfConstructionCortexSnapshotComposer: complete sections ⇒ status=ready + non-empty
 * snapshot + 64-char sha256 hash; missing section ⇒ status=blocked + missing_section:<name>; identical
 * input ⇒ byte-identical envelope (deterministic hash); envelope carries observation facts only.
 */
final class AtlasSelfConstructionCortexSnapshotComposerTest extends TestCase
{
    private function completeSections(): array
    {
        $out = [];
        foreach (AtlasSelfConstructionCortexSnapshotComposer::REQUIRED_SECTIONS as $name) {
            $out[$name] = ['witness' => $name, 'observed_at' => 1];
        }

        return $out;
    }

    public function test_complete_sections_yield_status_ready_with_hash(): void
    {
        $r = (new AtlasSelfConstructionCortexSnapshotComposer)->compose($this->completeSections());
        $this->assertSame(AtlasSelfConstructionCortexSnapshotComposer::STATUS_READY, $r['status']);
        $this->assertSame([], $r['blockers']);
        $this->assertSame(64, strlen($r['snapshot_hash']));
        foreach (AtlasSelfConstructionCortexSnapshotComposer::REQUIRED_SECTIONS as $name) {
            $this->assertArrayHasKey($name, $r['snapshot']);
        }
    }

    public function test_missing_section_yields_status_blocked_with_named_blocker(): void
    {
        $sections = $this->completeSections();
        unset($sections['risk_gaps'], $sections['evidence_refs']);
        $r = (new AtlasSelfConstructionCortexSnapshotComposer)->compose($sections);
        $this->assertSame(AtlasSelfConstructionCortexSnapshotComposer::STATUS_BLOCKED, $r['status']);
        $this->assertContains('missing_section:risk_gaps', $r['blockers']);
        $this->assertContains('missing_section:evidence_refs', $r['blockers']);
    }

    public function test_identical_input_yields_byte_identical_envelope(): void
    {
        $c = new AtlasSelfConstructionCortexSnapshotComposer;
        $a = json_encode($c->compose($this->completeSections()));
        $b = json_encode($c->compose($this->completeSections()));
        $this->assertSame($a, $b);
    }

    public function test_changing_any_section_changes_snapshot_hash(): void
    {
        $c = new AtlasSelfConstructionCortexSnapshotComposer;
        $base = $c->compose($this->completeSections())['snapshot_hash'];

        $modified = $this->completeSections();
        $modified['queue_state']['extra_fact'] = 'noted';
        $changed = $c->compose($modified)['snapshot_hash'];

        $this->assertNotSame($base, $changed);
    }

    public function test_envelope_carries_observation_facts_only_no_decision_keys(): void
    {
        $r = (new AtlasSelfConstructionCortexSnapshotComposer)->compose($this->completeSections());
        $json = (string) json_encode($r);
        foreach (['priority', 'schedule_at', 'merge_action', 'learning_promote'] as $forbidden) {
            $this->assertStringNotContainsString('"'.$forbidden.'"', $json);
        }
    }
}

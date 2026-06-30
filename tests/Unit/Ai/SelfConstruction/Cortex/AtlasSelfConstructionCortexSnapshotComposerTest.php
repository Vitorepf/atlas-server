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

    // ---------- freshness-awareness: fresh / stale / missing ----------

    private function sectionsWithFreshness(array $rows, bool $allFresh): array
    {
        $sections = $this->completeSections();
        $sections['freshness'] = ['all_fresh' => $allFresh, 'rows' => $rows];

        return $sections;
    }

    public function test_fresh_sources_yield_empty_stale_and_missing_lists(): void
    {
        $rows = [
            ['source_id' => 'docs', 'readiness' => 'fresh', 'reason' => 'within_window'],
            ['source_id' => 'code', 'readiness' => 'fresh', 'reason' => 'within_window'],
        ];
        $r = (new AtlasSelfConstructionCortexSnapshotComposer)->compose($this->sectionsWithFreshness($rows, true));

        $this->assertSame(AtlasSelfConstructionCortexSnapshotComposer::STATUS_READY, $r['status']);
        $this->assertSame([], $r['stale_sources'],   'no stale sources when all are fresh');
        $this->assertSame([], $r['missing_sources'], 'no missing sources when all are fresh');
    }

    public function test_stale_source_marking_surfaces_in_stale_sources_field(): void
    {
        $rows = [
            ['source_id' => 'docs',         'readiness' => 'fresh', 'reason' => 'within_window'],
            ['source_id' => 'code_index',   'readiness' => 'stale', 'reason' => 'age_90001s_exceeds_window_86400s'],
            ['source_id' => 'memory_index', 'readiness' => 'stale', 'reason' => 'age_172800s_exceeds_window_86400s'],
        ];
        $r = (new AtlasSelfConstructionCortexSnapshotComposer)->compose($this->sectionsWithFreshness($rows, false));

        $this->assertContains('code_index',   $r['stale_sources']);
        $this->assertContains('memory_index', $r['stale_sources']);
        $this->assertNotContains('docs',      $r['stale_sources']);
        $this->assertSame([], $r['missing_sources']);
    }

    public function test_missing_source_marking_surfaces_in_missing_sources_field(): void
    {
        $rows = [
            ['source_id' => 'docs',      'readiness' => 'fresh',   'reason' => 'within_window'],
            ['source_id' => 'kb_index',  'readiness' => 'missing', 'reason' => 'no_last_unix'],
        ];
        $r = (new AtlasSelfConstructionCortexSnapshotComposer)->compose($this->sectionsWithFreshness($rows, false));

        $this->assertContains('kb_index', $r['missing_sources']);
        $this->assertNotContains('docs',  $r['missing_sources']);
        $this->assertSame([], $r['stale_sources']);
    }

    public function test_queue_summary_is_extracted_from_queue_state_section(): void
    {
        $sections = $this->completeSections();
        $sections['queue_state'] = ['pending_count' => 5, 'locked_count' => 2, 'dry' => false];
        $r = (new AtlasSelfConstructionCortexSnapshotComposer)->compose($sections);

        $this->assertArrayHasKey('queue_summary', $r);
        $this->assertSame(5, $r['queue_summary']['pending']);
        $this->assertSame(2, $r['queue_summary']['locked']);
        $this->assertFalse($r['queue_summary']['dry']);
    }

    public function test_snapshot_is_bounded_to_required_sections_only(): void
    {
        $r = (new AtlasSelfConstructionCortexSnapshotComposer)->compose($this->completeSections());

        $snapshotKeys = array_keys($r['snapshot']);
        sort($snapshotKeys);
        $expected = AtlasSelfConstructionCortexSnapshotComposer::REQUIRED_SECTIONS;
        sort($expected);
        $this->assertSame($expected, $snapshotKeys, 'snapshot must contain exactly the required sections');
    }

    // ── domain_map ────────────────────────────────────────────────────────────

    private function domainMap(): array
    {
        return [
            'organs' => [
                ['name' => 'cortex', 'maturity' => 'alpha', 'risk_gaps' => ['stale_context'], 'owner_lane' => 'cognition', 'next_leverage_gap' => 'expand_risk_lens'],
            ],
        ];
    }

    public function test_domain_map_included_in_snapshot_when_provided(): void
    {
        $sections = $this->completeSections();
        $sections['domain_map'] = $this->domainMap();

        $r = (new AtlasSelfConstructionCortexSnapshotComposer)->compose($sections);

        $this->assertSame(AtlasSelfConstructionCortexSnapshotComposer::STATUS_READY, $r['status']);
        $this->assertArrayHasKey('domain_map', $r['snapshot']);
        $this->assertSame($this->domainMap(), $r['snapshot']['domain_map']);
    }

    public function test_domain_map_affects_snapshot_hash_deterministically(): void
    {
        $c = new AtlasSelfConstructionCortexSnapshotComposer;
        $without = $c->compose($this->completeSections())['snapshot_hash'];

        $sections = $this->completeSections();
        $sections['domain_map'] = $this->domainMap();
        $with = $c->compose($sections)['snapshot_hash'];

        $this->assertNotSame($without, $with, 'domain_map must change the hash');
        $this->assertSame($with, $c->compose($sections)['snapshot_hash'], 'hash must be deterministic');
    }

    public function test_missing_domain_map_surfaces_as_optional_warning_not_blocker(): void
    {
        $r = (new AtlasSelfConstructionCortexSnapshotComposer)->compose($this->completeSections());

        $this->assertSame(AtlasSelfConstructionCortexSnapshotComposer::STATUS_READY, $r['status']);
        $this->assertSame([], $r['blockers']);
        $this->assertContains('optional_domain_map_missing', $r['warnings']);
    }

    public function test_domain_map_organ_fields_preserved_in_snapshot(): void
    {
        $sections = $this->completeSections();
        $sections['domain_map'] = [
            'organs' => [
                [
                    'name' => 'merge_governor',
                    'maturity' => 'stable',
                    'risk_gaps' => ['unsafe_merge_posture'],
                    'owner_lane' => 'governance',
                    'next_leverage_gap' => 'add_rollback_path',
                ],
            ],
        ];

        $r = (new AtlasSelfConstructionCortexSnapshotComposer)->compose($sections);

        $organ = $r['snapshot']['domain_map']['organs'][0];
        $this->assertSame('merge_governor', $organ['name']);
        $this->assertSame('stable', $organ['maturity']);
        $this->assertSame(['unsafe_merge_posture'], $organ['risk_gaps']);
        $this->assertSame('governance', $organ['owner_lane']);
        $this->assertSame('add_rollback_path', $organ['next_leverage_gap']);
    }

    // ── worker_outcomes / project_lanes (optional sections) ────────────────────

    public function test_worker_outcomes_included_in_snapshot_when_provided(): void
    {
        $sections = $this->completeSections();
        $sections['worker_outcomes'] = ['by_client' => ['claude-muscle-4' => ['success' => 10, 'give_back' => 2]]];

        $r = (new AtlasSelfConstructionCortexSnapshotComposer)->compose($sections);

        $this->assertSame(AtlasSelfConstructionCortexSnapshotComposer::STATUS_READY, $r['status']);
        $this->assertArrayHasKey('worker_outcomes', $r['snapshot']);
        $this->assertSame($sections['worker_outcomes'], $r['snapshot']['worker_outcomes']);
    }

    public function test_project_lanes_included_in_snapshot_when_provided(): void
    {
        $sections = $this->completeSections();
        $sections['project_lanes'] = ['lanes' => ['loop', 'cortex', 'maestro']];

        $r = (new AtlasSelfConstructionCortexSnapshotComposer)->compose($sections);

        $this->assertSame(AtlasSelfConstructionCortexSnapshotComposer::STATUS_READY, $r['status']);
        $this->assertArrayHasKey('project_lanes', $r['snapshot']);
        $this->assertSame($sections['project_lanes'], $r['snapshot']['project_lanes']);
    }

    public function test_missing_worker_outcomes_and_project_lanes_surface_as_optional_warnings_not_blockers(): void
    {
        $r = (new AtlasSelfConstructionCortexSnapshotComposer)->compose($this->completeSections());

        $this->assertSame(AtlasSelfConstructionCortexSnapshotComposer::STATUS_READY, $r['status']);
        $this->assertSame([], $r['blockers']);
        $this->assertContains('optional_worker_outcomes_missing', $r['warnings']);
        $this->assertContains('optional_project_lanes_missing', $r['warnings']);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainStrategicThesisForge;
use Tests\TestCase;

final class AtlasExternalBrainStrategicThesisForgeTest extends TestCase
{
    private function svc(): AtlasExternalBrainStrategicThesisForge
    {
        return new AtlasExternalBrainStrategicThesisForge;
    }

    private function coherentCluster(array $overrides = []): array
    {
        return array_merge([
            'cluster_id'      => 'c1',
            'theme'           => 'memory_compression',
            'capability_delta' => 'Atlas can store 10× more episodic memory in the same token budget',
            'acceptance_path' => 'AtlasMemoryCompressionGate reports compression_ratio >= 10 in CI for 1000 episodes',
            'opportunities'   => [
                ['id' => 'o1', 'title' => 'lossless compression', 'evidence' => 'benchmark_2026_q2.json'],
                ['id' => 'o2', 'title' => 'dedup pipeline',       'evidence' => 'code_graph_duplicates.csv'],
            ],
            'urgency'      => 'high',
            'risk'         => 'medium',
            'dependencies' => ['thesis:compression_primitives'],
        ], $overrides);
    }

    // ── AC1: coherent clusters produce theses with required fields ────────────

    public function test_ac1_accepted_thesis_has_steps_field(): void
    {
        $r = $this->svc()->forge([$this->coherentCluster()]);

        $this->assertCount(1, $r['theses']);
        $thesis = $r['theses'][0];
        $this->assertArrayHasKey('steps', $thesis);
        $this->assertNotEmpty($thesis['steps']);
    }

    public function test_ac1_accepted_thesis_has_dependencies_field(): void
    {
        $r      = $this->svc()->forge([$this->coherentCluster()]);
        $thesis = $r['theses'][0];

        $this->assertArrayHasKey('dependencies', $thesis);
        $this->assertContains('thesis:compression_primitives', $thesis['dependencies']);
    }

    public function test_ac1_accepted_thesis_has_expected_compound_lift(): void
    {
        $r      = $this->svc()->forge([$this->coherentCluster()]);
        $thesis = $r['theses'][0];

        $this->assertArrayHasKey('expected_compound_lift', $thesis);
        $this->assertIsFloat($thesis['expected_compound_lift']);
        $this->assertGreaterThan(0.0, $thesis['expected_compound_lift']);
        $this->assertLessThanOrEqual(1.0, $thesis['expected_compound_lift']);
    }

    public function test_ac1_accepted_thesis_has_evidence_refs_from_opportunities(): void
    {
        $r      = $this->svc()->forge([$this->coherentCluster()]);
        $thesis = $r['theses'][0];

        $this->assertArrayHasKey('evidence_refs', $thesis);
        $this->assertContains('benchmark_2026_q2.json', $thesis['evidence_refs']);
        $this->assertContains('code_graph_duplicates.csv', $thesis['evidence_refs']);
    }

    public function test_ac1_empty_dependencies_when_not_supplied(): void
    {
        $cluster = $this->coherentCluster();
        unset($cluster['dependencies']);
        $r       = $this->svc()->forge([$cluster]);
        $thesis  = $r['theses'][0];

        $this->assertSame([], $thesis['dependencies']);
    }

    public function test_ac1_evidence_refs_empty_when_opportunities_have_no_evidence(): void
    {
        $cluster = $this->coherentCluster(['opportunities' => [['id' => 'o1', 'title' => 'misc']]]);
        $r       = $this->svc()->forge([$cluster]);

        $this->assertSame([], $r['theses'][0]['evidence_refs']);
    }

    // ── AC2: vague / duplicate / unsupported clusters are rejected ────────────

    public function test_ac2_rejects_cluster_with_missing_capability_delta(): void
    {
        $cluster = $this->coherentCluster(['capability_delta' => '']);
        $r       = $this->svc()->forge([$cluster]);

        $this->assertCount(0, $r['theses']);
        $this->assertCount(1, $r['rejected']);
        $this->assertSame('capability_delta_missing_or_empty', $r['rejected'][0]['reason']);
    }

    public function test_ac2_rejects_cluster_with_missing_acceptance_path(): void
    {
        $cluster = $this->coherentCluster(['acceptance_path' => '']);
        $r       = $this->svc()->forge([$cluster]);

        $reasons = array_column($r['rejected'], 'reason');
        $this->assertContains('acceptance_path_missing_or_empty', $reasons);
    }

    public function test_ac2_rejects_vague_acceptance_path(): void
    {
        $cluster = $this->coherentCluster(['acceptance_path' => 'This will be better over time']);
        $r       = $this->svc()->forge([$cluster]);

        $reasons = array_column($r['rejected'], 'reason');
        $this->assertContains('acceptance_path_not_falsifiable:vague_claim', $reasons);
    }

    public function test_ac2_rejects_duplicate_cluster_with_same_theme_and_capability_delta(): void
    {
        $first  = $this->coherentCluster(['cluster_id' => 'c1']);
        $second = $this->coherentCluster(['cluster_id' => 'c2']); // same theme + capability_delta

        $r = $this->svc()->forge([$first, $second]);

        $this->assertCount(1, $r['theses']);
        $this->assertSame('c1', $r['theses'][0]['cluster_id']);

        $rejectedIds = array_column($r['rejected'], 'cluster_id');
        $this->assertContains('c2', $rejectedIds);

        $reasons = array_column($r['rejected'], 'reason');
        $this->assertContains('duplicate_cluster:same_theme_and_capability_delta', $reasons);
    }

    public function test_ac2_rejects_cluster_with_incoherent_task_shapes(): void
    {
        $cluster = $this->coherentCluster([
            'task_shapes' => [
                ['shape' => 'implement_capability', 'description' => 'do the impl'],
                // missing any verify shape → incoherent
            ],
        ]);
        $r = $this->svc()->forge([$cluster]);

        $reasons = array_column($r['rejected'], 'reason');
        $this->assertNotEmpty(array_filter($reasons, static fn ($r) => str_starts_with($r, 'task_shapes_incoherent:')));
    }

    // ── AC3: chain outline — earlier steps unlock later steps ─────────────────

    public function test_ac3_steps_have_prerequisite_signals_linking_them(): void
    {
        $r     = $this->svc()->forge([$this->coherentCluster()]);
        $steps = $r['theses'][0]['steps'];

        $this->assertNotEmpty($steps);

        // Every step after the first must declare some prerequisite signal.
        foreach (array_slice($steps, 1) as $step) {
            $this->assertArrayHasKey('prerequisite_signals', $step);
            $this->assertNotEmpty($step['prerequisite_signals'],
                "Step {$step['shape']} must declare prerequisite_signals that link it to a prior step");
        }
    }

    public function test_ac3_steps_are_ordered_and_unlock_sequentially(): void
    {
        $r     = $this->svc()->forge([$this->coherentCluster()]);
        $steps = $r['theses'][0]['steps'];

        // Orders must be 1, 2, 3 …
        foreach ($steps as $i => $step) {
            $this->assertSame($i + 1, $step['order']);
        }

        // verify_acceptance must reference implement_capability_done
        $verifyStep = null;
        foreach ($steps as $s) {
            if ($s['shape'] === 'verify_acceptance') {
                $verifyStep = $s;
                break;
            }
        }
        $this->assertNotNull($verifyStep);
        $this->assertContains('implement_capability_done', $verifyStep['prerequisite_signals']);
    }

    public function test_ac3_wire_to_consumers_depends_on_verify_acceptance(): void
    {
        $r     = $this->svc()->forge([$this->coherentCluster()]);
        $steps = $r['theses'][0]['steps'];

        $wireStep = null;
        foreach ($steps as $s) {
            if ($s['shape'] === 'wire_to_consumers') {
                $wireStep = $s;
                break;
            }
        }

        if ($wireStep !== null) {
            $this->assertContains('verify_acceptance_done', $wireStep['prerequisite_signals']);
        } else {
            $this->markTestSkipped('wire_to_consumers not in default shapes for this cluster');
        }
    }

    // ── AC4: pure / deterministic / no side effects ───────────────────────────

    public function test_ac4_identical_input_yields_identical_output(): void
    {
        $clusters = [$this->coherentCluster()];

        $this->assertSame(
            json_encode($this->svc()->forge($clusters), JSON_UNESCAPED_SLASHES),
            json_encode($this->svc()->forge($clusters), JSON_UNESCAPED_SLASHES),
        );
    }

    public function test_ac4_forge_returns_only_schema_theses_rejected_keys(): void
    {
        $r = $this->svc()->forge([$this->coherentCluster()]);

        $this->assertArrayHasKey('schema',   $r);
        $this->assertArrayHasKey('theses',   $r);
        $this->assertArrayHasKey('rejected', $r);
        $this->assertSame(AtlasExternalBrainStrategicThesisForge::SCHEMA, $r['schema']);
    }
}

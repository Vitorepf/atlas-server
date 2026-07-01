<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainClosedLoopReadinessGate;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainClosedLoopReadinessGateTest extends TestCase
{
    private function gate(): AtlasExternalBrainClosedLoopReadinessGate
    {
        return new AtlasExternalBrainClosedLoopReadinessGate;
    }

    private function link(string $evidence): array
    {
        return ['evidence' => $evidence, 'hash' => hash('sha256', $evidence)];
    }

    /** @return array<string, array<string,mixed>> */
    private function wiredDossier(array $overrides = []): array
    {
        return array_merge([
            'proposal' => $this->link('proposal evidence'),
            'task_fabric' => $this->link('task_fabric evidence'),
            'queue_admission' => $this->link('queue_admission evidence'),
            'muscle_outcome' => $this->link('muscle_outcome evidence'),
            'learning_update' => $this->link('learning_update evidence'),
            'resequencing' => $this->link('resequencing evidence'),
        ], $overrides);
    }

    // ── AC: ready=false when any required link is missing ─────────────────────

    public function test_ready_false_when_a_required_link_is_entirely_missing(): void
    {
        $dossier = $this->wiredDossier();
        unset($dossier['queue_admission']);

        $r = $this->gate()->check(['dossier' => $dossier]);

        $this->assertFalse($r['ready']);
        $this->assertContains('queue_admission_missing', $r['missing_links']);
    }

    public function test_ready_false_when_link_has_no_evidence(): void
    {
        $dossier = $this->wiredDossier(['muscle_outcome' => ['evidence' => '', 'hash' => '']]);

        $r = $this->gate()->check(['dossier' => $dossier]);

        $this->assertFalse($r['ready']);
        $this->assertContains('muscle_outcome_missing_evidence', $r['missing_links']);
    }

    public function test_ready_false_when_hash_does_not_match_evidence(): void
    {
        $dossier = $this->wiredDossier(['learning_update' => ['evidence' => 'real evidence', 'hash' => 'tampered-hash']]);

        $r = $this->gate()->check(['dossier' => $dossier]);

        $this->assertFalse($r['ready']);
        $this->assertContains('learning_update_hash_unstable', $r['missing_links']);
    }

    public function test_empty_dossier_reports_all_six_links_missing(): void
    {
        $r = $this->gate()->check([]);

        $this->assertFalse($r['ready']);
        $this->assertCount(6, $r['missing_links']);
    }

    // ── AC: ready=true only when every link wired with stable hash ────────────

    public function test_ready_true_when_every_link_has_evidence_and_stable_hash(): void
    {
        $r = $this->gate()->check(['dossier' => $this->wiredDossier()]);

        $this->assertTrue($r['ready']);
        $this->assertSame([], $r['missing_links']);
        foreach (['proposal', 'task_fabric', 'queue_admission', 'muscle_outcome', 'learning_update', 'resequencing'] as $link) {
            $this->assertTrue($r[$link]['wired'], "{$link} should be wired");
        }
    }

    public function test_dossier_hashes_are_stable_across_repeat_checks(): void
    {
        $dossier = $this->wiredDossier();
        $a = $this->gate()->check(['dossier' => $dossier]);
        $b = $this->gate()->check(['dossier' => $dossier]);

        $this->assertSame($a['proposal']['dossier_hash'], $b['proposal']['dossier_hash']);
        $this->assertSame(json_encode($a), json_encode($b));
    }

    // ── AC: missing_links names gaps for next-batch wiring tasks ──────────────

    public function test_missing_links_names_every_distinct_gap(): void
    {
        $dossier = $this->wiredDossier([
            'proposal' => ['evidence' => '', 'hash' => ''],
        ]);
        unset($dossier['resequencing']);

        $r = $this->gate()->check(['dossier' => $dossier]);

        $this->assertContains('proposal_missing_evidence', $r['missing_links']);
        $this->assertContains('resequencing_missing', $r['missing_links']);
        $this->assertCount(2, $r['missing_links']);
    }

    public function test_schema_version_present(): void
    {
        $r = $this->gate()->check([]);
        $this->assertSame(AtlasExternalBrainClosedLoopReadinessGate::SCHEMA, $r['schema']);
    }
}

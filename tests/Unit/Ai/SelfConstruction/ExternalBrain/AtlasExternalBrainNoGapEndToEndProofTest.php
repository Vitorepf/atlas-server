<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainNoGapEndToEndProof;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainNoGapEndToEndProofTest extends TestCase
{
    private function proof(): AtlasExternalBrainNoGapEndToEndProof
    {
        return new AtlasExternalBrainNoGapEndToEndProof;
    }

    private function stage(string $evidence): array
    {
        return ['evidence' => $evidence, 'hash' => hash('sha256', $evidence)];
    }

    /** @return array<string, array<string,mixed>> */
    private function completeDossier(array $overrides = []): array
    {
        return array_merge([
            'discovery' => $this->stage('discovery evidence'),
            'task_spec' => $this->stage('task_spec evidence'),
            'serving' => $this->stage('serving evidence'),
            'muscle_outcome' => $this->stage('muscle_outcome evidence'),
            'learning_update' => $this->stage('learning_update evidence'),
            'next_decision' => $this->stage('next_decision evidence'),
        ], $overrides);
    }

    // ── AC: complete=false when any stage evidence is missing ─────────────────

    public function test_complete_false_when_a_stage_is_entirely_missing(): void
    {
        $dossier = $this->completeDossier();
        unset($dossier['serving']);

        $r = $this->proof()->prove(['dossier' => $dossier]);

        $this->assertFalse($r['complete']);
        $this->assertContains('serving_missing', $r['missing_stage']);
    }

    public function test_complete_false_when_stage_has_no_evidence(): void
    {
        $dossier = $this->completeDossier(['muscle_outcome' => ['evidence' => '', 'hash' => '']]);

        $r = $this->proof()->prove(['dossier' => $dossier]);

        $this->assertFalse($r['complete']);
        $this->assertContains('muscle_outcome_missing_evidence', $r['missing_stage']);
    }

    public function test_complete_false_when_stage_hash_does_not_match_evidence(): void
    {
        $dossier = $this->completeDossier(['learning_update' => ['evidence' => 'real', 'hash' => 'tampered']]);

        $r = $this->proof()->prove(['dossier' => $dossier]);

        $this->assertFalse($r['complete']);
        $this->assertContains('learning_update_hash_unstable', $r['missing_stage']);
    }

    public function test_empty_dossier_reports_all_six_stages_missing(): void
    {
        $r = $this->proof()->prove([]);

        $this->assertFalse($r['complete']);
        $this->assertCount(6, $r['missing_stage']);
    }

    // ── AC: complete=true only when every stage has evidence and stable hash ──

    public function test_complete_true_when_every_stage_has_evidence_and_stable_hash(): void
    {
        $r = $this->proof()->prove(['dossier' => $this->completeDossier()]);

        $this->assertTrue($r['complete']);
        $this->assertSame([], $r['missing_stage']);
        foreach (['discovery', 'task_spec', 'serving', 'muscle_outcome', 'learning_update', 'next_decision'] as $stage) {
            $this->assertTrue($r[$stage]['traversed'], "{$stage} should be traversed");
        }
    }

    public function test_stage_hashes_are_stable_across_repeat_proofs(): void
    {
        $dossier = $this->completeDossier();
        $a = $this->proof()->prove(['dossier' => $dossier]);
        $b = $this->proof()->prove(['dossier' => $dossier]);

        $this->assertSame($a['discovery']['dossier_hash'], $b['discovery']['dossier_hash']);
        $this->assertSame(json_encode($a), json_encode($b));
    }

    // ── AC: missing_stage exposed so a follow-up task can be created ──────────

    public function test_missing_stage_names_every_distinct_gap(): void
    {
        $dossier = $this->completeDossier(['discovery' => ['evidence' => '', 'hash' => '']]);
        unset($dossier['next_decision']);

        $r = $this->proof()->prove(['dossier' => $dossier]);

        $this->assertContains('discovery_missing_evidence', $r['missing_stage']);
        $this->assertContains('next_decision_missing', $r['missing_stage']);
        $this->assertCount(2, $r['missing_stage']);
    }

    public function test_schema_version_present(): void
    {
        $r = $this->proof()->prove([]);
        $this->assertSame(AtlasExternalBrainNoGapEndToEndProof::SCHEMA, $r['schema']);
    }
}

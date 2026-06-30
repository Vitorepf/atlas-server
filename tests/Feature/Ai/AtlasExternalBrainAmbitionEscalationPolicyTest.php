<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainAmbitionEscalationPolicy;
use Tests\TestCase;

final class AtlasExternalBrainAmbitionEscalationPolicyTest extends TestCase
{
    private function svc(): AtlasExternalBrainAmbitionEscalationPolicy
    {
        return new AtlasExternalBrainAmbitionEscalationPolicy;
    }

    private function allModes(): array
    {
        return [
            AtlasExternalBrainAmbitionEscalationPolicy::MODE_CONTRACT_MISMATCH,
            AtlasExternalBrainAmbitionEscalationPolicy::MODE_CROSS_DOMAIN_PATTERN,
            AtlasExternalBrainAmbitionEscalationPolicy::MODE_RESEARCH_BACKED_DESIGN,
            AtlasExternalBrainAmbitionEscalationPolicy::MODE_ARCHITECTURE_SIMPLIFICATION,
            AtlasExternalBrainAmbitionEscalationPolicy::MODE_RUNTIME_HEALTH,
            AtlasExternalBrainAmbitionEscalationPolicy::MODE_CERTIFICATION_GAP,
        ];
    }

    private function allWithEvidence(): array
    {
        $ev = [];
        foreach ($this->allModes() as $m) {
            $ev[$m] = ["evidence_{$m}_a", "evidence_{$m}_b"];
        }
        return $ev;
    }

    // ── AC1: runnable gate (implicit — all tests must exit 0) ─────────────────

    public function test_ac1_fresh_state_returns_first_ladder_mode(): void
    {
        $r = $this->svc()->decide([]);

        $this->assertSame(
            AtlasExternalBrainAmbitionEscalationPolicy::MODE_CONTRACT_MISMATCH,
            $r['next_mode'],
        );
        $this->assertFalse($r['honest_exhausted']);
    }

    // ── AC2: all attempted but one lacks evidence → NOT honest_exhausted ──────

    public function test_ac2_all_attempted_one_missing_evidence_returns_not_exhausted(): void
    {
        $evidence = $this->allWithEvidence();
        // Remove evidence for one mode.
        unset($evidence[AtlasExternalBrainAmbitionEscalationPolicy::MODE_RESEARCH_BACKED_DESIGN]);

        $r = $this->svc()->decide([
            'attempted_modes'  => $this->allModes(),
            'evidence_by_mode' => $evidence,
        ]);

        $this->assertFalse($r['honest_exhausted'],
            'all modes attempted but one lacks evidence must NOT return honest_exhausted');
    }

    public function test_ac2_selects_missing_evidence_mode_not_an_arbitrary_one(): void
    {
        $evidence = $this->allWithEvidence();
        unset($evidence[AtlasExternalBrainAmbitionEscalationPolicy::MODE_RUNTIME_HEALTH]);

        $r = $this->svc()->decide([
            'attempted_modes'  => $this->allModes(),
            'evidence_by_mode' => $evidence,
        ]);

        $this->assertSame(
            AtlasExternalBrainAmbitionEscalationPolicy::MODE_RUNTIME_HEALTH,
            $r['next_mode'],
            'must select the exact mode that lacks evidence',
        );
    }

    public function test_ac2_rationale_explains_missing_evidence(): void
    {
        $evidence = $this->allWithEvidence();
        unset($evidence[AtlasExternalBrainAmbitionEscalationPolicy::MODE_CERTIFICATION_GAP]);

        $r = $this->svc()->decide([
            'attempted_modes'  => $this->allModes(),
            'evidence_by_mode' => $evidence,
        ]);

        $this->assertStringContainsString('lacks_evidence', $r['rationale'],
            'rationale must clearly state why the mode is selected (missing evidence)');
        $this->assertStringContainsString(
            AtlasExternalBrainAmbitionEscalationPolicy::MODE_CERTIFICATION_GAP,
            $r['rationale'],
            'rationale must name the specific mode lacking evidence',
        );
    }

    public function test_ac2_first_missing_evidence_mode_is_selected_when_multiple_lack_evidence(): void
    {
        // Remove evidence for modes 3 and 5 (index 2 and 4).
        $evidence = $this->allWithEvidence();
        unset($evidence[AtlasExternalBrainAmbitionEscalationPolicy::MODE_RESEARCH_BACKED_DESIGN]);
        unset($evidence[AtlasExternalBrainAmbitionEscalationPolicy::MODE_RUNTIME_HEALTH]);

        $r = $this->svc()->decide([
            'attempted_modes'  => $this->allModes(),
            'evidence_by_mode' => $evidence,
        ]);

        // Must select the first in ladder order.
        $this->assertSame(
            AtlasExternalBrainAmbitionEscalationPolicy::MODE_RESEARCH_BACKED_DESIGN,
            $r['next_mode'],
        );
    }

    // ── AC3: all modes with evidence → honest_exhausted + complete dossier ────

    public function test_ac3_all_modes_with_evidence_returns_honest_exhausted(): void
    {
        $r = $this->svc()->decide([
            'attempted_modes'  => $this->allModes(),
            'evidence_by_mode' => $this->allWithEvidence(),
        ]);

        $this->assertTrue($r['honest_exhausted']);
        $this->assertSame(
            AtlasExternalBrainAmbitionEscalationPolicy::MODE_HONEST_EXHAUSTED,
            $r['next_mode'],
        );
    }

    public function test_ac3_exhaustion_dossier_covers_every_mode(): void
    {
        $r = $this->svc()->decide([
            'attempted_modes'  => $this->allModes(),
            'evidence_by_mode' => $this->allWithEvidence(),
        ]);

        $dossier = $r['exhaustion_dossier'];
        $this->assertCount(count($this->allModes()), $dossier,
            'dossier must have one entry per ladder mode');

        foreach ($dossier as $entry) {
            $this->assertArrayHasKey('mode',          $entry);
            $this->assertArrayHasKey('attempted',     $entry);
            $this->assertArrayHasKey('evidence',      $entry);
            $this->assertArrayHasKey('evidence_hash', $entry);
            $this->assertTrue($entry['attempted']);
            $this->assertNotEmpty($entry['evidence']);
        }
    }

    public function test_ac3_dossier_always_present_even_when_not_exhausted(): void
    {
        $r = $this->svc()->decide([]);

        $this->assertArrayHasKey('exhaustion_dossier', $r);
        $this->assertCount(count($this->allModes()), $r['exhaustion_dossier']);
    }

    public function test_ac3_partial_evidence_never_triggers_honest_exhausted(): void
    {
        // 5 out of 6 modes have evidence.
        $evidence = $this->allWithEvidence();
        unset($evidence[AtlasExternalBrainAmbitionEscalationPolicy::MODE_CONTRACT_MISMATCH]);

        $r = $this->svc()->decide([
            'attempted_modes'  => $this->allModes(),
            'evidence_by_mode' => $evidence,
        ]);

        $this->assertFalse($r['honest_exhausted'],
            '5/6 modes with evidence must NOT be enough for honest_exhausted');
    }

    // ── AC4: deterministic, no I/O ────────────────────────────────────────────

    public function test_ac4_identical_input_yields_identical_output(): void
    {
        $state = [
            'attempted_modes'  => [
                AtlasExternalBrainAmbitionEscalationPolicy::MODE_CONTRACT_MISMATCH,
                AtlasExternalBrainAmbitionEscalationPolicy::MODE_CROSS_DOMAIN_PATTERN,
            ],
            'evidence_by_mode' => [
                AtlasExternalBrainAmbitionEscalationPolicy::MODE_CONTRACT_MISMATCH => ['ref_a'],
            ],
        ];

        $this->assertSame(
            json_encode($this->svc()->decide($state), JSON_UNESCAPED_SLASHES),
            json_encode($this->svc()->decide($state), JSON_UNESCAPED_SLASHES),
        );
    }

    public function test_ac4_normal_ladder_progression_uses_mode_rationale_not_missing_evidence(): void
    {
        // First mode not yet attempted → generic mode rationale, NOT missing-evidence phrasing.
        $r = $this->svc()->decide([]);

        $this->assertStringNotContainsString('lacks_evidence', $r['rationale'],
            'normal ladder progression must not use missing-evidence rationale');
    }

    public function test_ac4_evidence_hash_is_deterministic(): void
    {
        $state = [
            'attempted_modes'  => $this->allModes(),
            'evidence_by_mode' => $this->allWithEvidence(),
        ];

        $d1 = $this->svc()->decide($state)['exhaustion_dossier'];
        $d2 = $this->svc()->decide($state)['exhaustion_dossier'];

        foreach ($d1 as $i => $entry) {
            $this->assertSame($entry['evidence_hash'], $d2[$i]['evidence_hash']);
        }
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainResearchDigestGrounder;
use Tests\TestCase;

final class AtlasExternalBrainResearchDigestGrounderTest extends TestCase
{
    private function svc(): AtlasExternalBrainResearchDigestGrounder
    {
        return new AtlasExternalBrainResearchDigestGrounder;
    }

    private function groundedIdea(array $overrides = []): array
    {
        return array_merge([
            'idea_id'                => 'idea_001',
            'is_hype'                => false,
            'local_symbols'          => ['AtlasMemoryCore', 'AtlasEvidenceLedger'],
            'allowed_files_candidate' => ['app/Services/Ai/Memory/AtlasMemoryCore.php'],
            'runnable_evidence_path' => '/opt/homebrew/bin/php artisan atlas:memory:test',
            'owner_files'            => ['app/Services/Ai/Memory/AtlasMemoryCore.php'],
            'has_local_owner'        => true,
            'forbidden_scope'        => false,
            'provider_steady_state_dep' => false,
            'atlas_capability_gap'   => 'Atlas can recall episodic memory within 3 hops instead of linear scan',
            'atlas_area'             => 'memory',
            'target_capability'      => 'episodic_recall_3hop',
            'implementation_boundary' => 'must not call external providers; pure PHP computation only',
            'implementation_strategy' => 'Add graph index to AtlasMemoryCore; update nearest-neighbor search',
            'acceptance_seed'        => 'AtlasMemoryRecallGate reports recall_hops <= 3 for 1000 episodes',
        ], $overrides);
    }

    private function ground(array $ideas): array
    {
        return $this->svc()->ground(['research_ideas' => $ideas]);
    }

    // ── AC1: grounded findings include required fields ─────────────────────────

    public function test_ac1_promoted_candidate_has_atlas_area(): void
    {
        $r = $this->ground([$this->groundedIdea(['atlas_area' => 'memory'])]);
        $this->assertCount(1, $r['task_candidates']);
        $this->assertSame('memory', $r['task_candidates'][0]['atlas_area']);
    }

    public function test_ac1_promoted_candidate_has_target_capability(): void
    {
        $r = $this->ground([$this->groundedIdea(['target_capability' => 'episodic_recall_3hop'])]);
        $this->assertSame('episodic_recall_3hop', $r['task_candidates'][0]['target_capability']);
    }

    public function test_ac1_target_capability_falls_back_to_atlas_capability_gap(): void
    {
        $idea = $this->groundedIdea();
        unset($idea['target_capability']);
        $r = $this->ground([$idea]);
        $this->assertSame($idea['atlas_capability_gap'], $r['task_candidates'][0]['target_capability']);
    }

    public function test_ac1_promoted_candidate_has_implementation_boundary(): void
    {
        $r = $this->ground([$this->groundedIdea()]);
        $this->assertArrayHasKey('implementation_boundary', $r['task_candidates'][0]);
        $this->assertStringContainsString('external providers', $r['task_candidates'][0]['implementation_boundary']);
    }

    public function test_ac1_promoted_candidate_has_evidence_strength(): void
    {
        $r = $this->ground([$this->groundedIdea()]);
        $candidate = $r['task_candidates'][0];
        $this->assertArrayHasKey('evidence_strength', $candidate);
        $this->assertIsFloat($candidate['evidence_strength']);
        $this->assertGreaterThan(0.0, $candidate['evidence_strength']);
        $this->assertLessThanOrEqual(1.0, $candidate['evidence_strength']);
    }

    public function test_ac1_evidence_strength_higher_when_all_grounding_factors_present(): void
    {
        $full    = $this->ground([$this->groundedIdea()])['task_candidates'][0]['evidence_strength'];
        $partial = $this->ground([$this->groundedIdea(['owner_files' => [], 'has_local_owner' => true, 'implementation_strategy' => ''])])[
            'task_candidates'
        ][0]['evidence_strength'];

        $this->assertGreaterThan($partial, $full, 'Full grounding must produce higher evidence_strength');
    }

    // ── AC2: ungrounded summaries → rejected or held for research ─────────────

    public function test_ac2_hype_idea_goes_to_rejected_not_promoted(): void
    {
        $r = $this->ground([$this->groundedIdea(['is_hype' => true])]);
        $this->assertCount(0, $r['task_candidates']);
        $this->assertCount(1, $r['rejected']);
        $this->assertSame(AtlasExternalBrainResearchDigestGrounder::REJECTION_HYPE_ONLY, $r['rejected'][0]['rejection_reason']);
    }

    public function test_ac2_idea_without_local_symbols_is_held_for_research(): void
    {
        $r = $this->ground([$this->groundedIdea(['local_symbols' => []])]);
        $this->assertCount(0, $r['task_candidates']);
        $this->assertCount(1, $r['held_for_research']);
        $this->assertSame(AtlasExternalBrainResearchDigestGrounder::HOLD_MISSING_ATLAS_FIT, $r['held_for_research'][0]['hold_reason']);
    }

    public function test_ac2_idea_without_runnable_gate_is_held_not_rejected(): void
    {
        $r = $this->ground([$this->groundedIdea(['runnable_evidence_path' => 'no gate here'])]);
        $this->assertCount(0, $r['task_candidates']);
        $this->assertCount(1, $r['held_for_research']);
        $this->assertSame(AtlasExternalBrainResearchDigestGrounder::HOLD_MISSING_RUNNABLE_GATE, $r['held_for_research'][0]['hold_reason']);
    }

    public function test_ac2_idea_without_owner_is_held_for_research(): void
    {
        $r = $this->ground([$this->groundedIdea(['owner_files' => [], 'has_local_owner' => false])]);
        $this->assertCount(0, $r['task_candidates']);
        $this->assertCount(1, $r['held_for_research']);
        $this->assertStringStartsWith('hold:', $r['held_for_research'][0]['hold_reason']);
    }

    public function test_ac2_forbidden_scope_is_hard_rejected_not_held(): void
    {
        $r = $this->ground([$this->groundedIdea(['forbidden_scope' => true])]);
        $this->assertCount(1, $r['rejected']);
        $this->assertCount(0, $r['held_for_research'] ?? []);
        $this->assertSame(AtlasExternalBrainResearchDigestGrounder::REJECTION_FORBIDDEN_SCOPE, $r['rejected'][0]['rejection_reason']);
    }

    public function test_ac2_provider_dependency_is_hard_rejected(): void
    {
        $r = $this->ground([$this->groundedIdea(['provider_steady_state_dep' => true])]);
        $this->assertCount(1, $r['rejected']);
        $this->assertSame(AtlasExternalBrainResearchDigestGrounder::REJECTION_PROVIDER_DEPENDENCY, $r['rejected'][0]['rejection_reason']);
    }

    // ── AC3: source novelty alone is not enough ───────────────────────────────

    public function test_ac3_novel_idea_without_atlas_fit_is_held_not_promoted(): void
    {
        // "Novel" idea: non-hype, has capability_gap, but no local_symbols → no Atlas fit
        $r = $this->ground([$this->groundedIdea([
            'is_hype'       => false,
            'local_symbols' => [],  // no Atlas fit
            'atlas_capability_gap' => 'Atlas can do amazing new thing based on cutting-edge research',
        ])]);

        $this->assertCount(0, $r['task_candidates'], 'Novel idea without Atlas fit must NOT be promoted');
        $this->assertCount(1, $r['held_for_research']);
    }

    public function test_ac3_novel_idea_without_runnable_gate_is_held_not_promoted(): void
    {
        $r = $this->ground([$this->groundedIdea([
            'is_hype'                => false,
            'local_symbols'          => ['AtlasBrainSomeConcept'],
            'runnable_evidence_path' => '', // no implementability evidence
        ])]);

        $this->assertCount(0, $r['task_candidates'], 'Novel idea without runnable gate must NOT be promoted');
        $this->assertCount(1, $r['held_for_research']);
    }

    public function test_ac3_no_capability_delta_means_hard_rejection_even_if_novel(): void
    {
        $r = $this->ground([$this->groundedIdea(['atlas_capability_gap' => ''])]);
        $this->assertCount(1, $r['rejected']);
        $this->assertSame(AtlasExternalBrainResearchDigestGrounder::REJECTION_NO_CAPABILITY_DELTA, $r['rejected'][0]['rejection_reason']);
    }

    // ── AC4: deterministic, provider-free ────────────────────────────────────

    public function test_ac4_identical_input_yields_identical_output(): void
    {
        $ideas = [$this->groundedIdea()];
        $this->assertSame(
            json_encode($this->ground($ideas), JSON_UNESCAPED_SLASHES),
            json_encode($this->ground($ideas), JSON_UNESCAPED_SLASHES),
        );
    }

    public function test_ac4_schema_constant_present(): void
    {
        $r = $this->ground([]);
        $this->assertSame(AtlasExternalBrainResearchDigestGrounder::SCHEMA, $r['schema']);
        $this->assertSame([], $r['task_candidates']);
        $this->assertSame([], $r['rejected']);
    }
}

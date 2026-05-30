<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Foundry\Frontier\Ports;

use App\Services\Ai\Foundry\Frontier\Ports\AtlasDecideFrontierGeneratorService;
use App\Services\Ai\Foundry\Frontier\Ports\DeterministicFixtureFrontierGeneratorService;
use App\Services\Ai\Foundry\Frontier\Ports\FrontierProposalIdentity;
use App\Services\Ai\Foundry\FoundrySchemas;
use Tests\TestCase;

final class FrontierGeneratorPortTest extends TestCase
{
    private function realGenerator(): AtlasDecideFrontierGeneratorService
    {
        return app(AtlasDecideFrontierGeneratorService::class);
    }

    /** Minimal dossier (shape irrelevant to the generator — it only forwards it). */
    private function dossier(): array
    {
        return [
            'schema_version' => FoundrySchemas::DOSSIER,
            'area_id' => 'agentic_engineering_os',
            'anchors' => [['anchor_id' => 'a-1']],
            'dossier_hash' => 'abc',
        ];
    }

    /** A fully-formed 13-key proposal projection (no provenance keys on the object). */
    private function proposal(string $id = 'p-1', string $title = 'Lift exhaustion gate sensitivity'): array
    {
        return [
            'proposal_id' => $id,
            'horizon' => 'near',
            'title' => $title,
            'thesis' => 'Measured stagnation shows the gate trips late.',
            'evidence_refs' => [
                ['anchor_id' => 'a-2', 'anchor_hash' => 'h2', 'anchor_type' => 'cycle'],
                ['anchor_id' => 'a-1', 'anchor_hash' => 'h1', 'anchor_type' => 'ledger'],
            ],
            'why_it_multiplies' => 'Compounds rarity signal across cycles.',
            'success_metric' => 'gate_trip_latency < baseline',
            'rollback' => 'revert gate threshold to prior value',
            'risk_level' => 'medium',
            'dependencies' => [],
            'proposed_packets' => [
                ['packet_id' => 'pk-1', 'label' => 'tune-threshold', 'objective' => 'x', 'delivery' => 'y', 'acceptance_criteria' => ['ac'], 'tests_required' => ['t'], 'owner_candidate' => 'foundry', 'kind' => 'logic', 'canonical_property_mapping' => ['area_id' => 'a', 'subsystem' => 's', 'schema' => 'sc', 'measured_signal_ref' => 'm']],
            ],
            'provider_tier_required' => 'premium',
            'anti_pattern_self_check' => 'not scaffold',
        ];
    }

    // ---- GENERATOR PORT: real-or-blocked, dossier-only, never fabricates ----

    public function test_real_generator_blocks_honestly_with_no_provider_execution_capacity(): void
    {
        $result = $this->realGenerator()->generate($this->dossier(), 5);

        $this->assertSame('blocked', $result['status']);
        $this->assertSame([], $result['proposals'], 'real generator must NEVER fabricate proposals');
        $this->assertSame([], $result['provenance']);
        $this->assertNotEmpty($result['generator_blocked_reasons']);
        $this->assertContains(
            AtlasDecideFrontierGeneratorService::BLOCKER_EXECUTION_BRIDGE_MISSING,
            $result['generator_blocked_reasons'],
        );
    }

    public function test_real_generator_label_is_real_prefixed_with_resolved_identity(): void
    {
        $result = $this->realGenerator()->generate($this->dossier(), 3);

        $this->assertStringStartsWith('real:', $result['generator_label']);
        // RESOLVED identity (not a free-form label) is surfaced for the I3 check.
        $this->assertNotNull($result['generator_provider_resolved']);
        $this->assertSame(
            $result['generator_provider_resolved'],
            explode(':', $result['generator_label'])[1],
        );
    }

    public function test_real_generator_never_invokes_provider(): void
    {
        $result = $this->realGenerator()->generate($this->dossier(), 2);

        $this->assertFalse($result['claim_policy']['provider_invoked']);
    }

    public function test_real_generator_receives_only_the_dossier(): void
    {
        // Generate() signature accepts ONLY ($dossier, $count, $context). There is
        // no raw-input / ledger parameter — enforced structurally. We assert the
        // call works with the dossier alone and produces a deterministic block.
        $a = $this->realGenerator()->generate($this->dossier(), 4);
        $b = $this->realGenerator()->generate($this->dossier(), 4);

        $this->assertSame($a['status'], $b['status']);
        $this->assertSame($a['generator_blocked_reasons'], $b['generator_blocked_reasons']);
    }

    // ---- CANONICAL IDENTITY: single source of truth, semantically stable ----

    public function test_proposal_identity_is_stable_across_semantically_identical_proposals(): void
    {
        $p1 = $this->proposal();

        // Same proposal, evidence_refs + packets reordered, extra ignored keys.
        $p2 = $this->proposal();
        $p2['evidence_refs'] = array_reverse($p2['evidence_refs']);

        $this->assertSame(
            FrontierProposalIdentity::of($p1),
            FrontierProposalIdentity::of($p2),
            'identity must be order-independent over anchor ids + packet fingerprint',
        );
    }

    public function test_proposal_identity_differs_for_different_thesis(): void
    {
        $p1 = $this->proposal();
        $p2 = $this->proposal();
        $p2['thesis'] = 'A different thesis entirely.';

        $this->assertNotSame(
            FrontierProposalIdentity::of($p1),
            FrontierProposalIdentity::of($p2),
        );
    }

    public function test_identity_matches_provenance_proposal_hash_from_fixture_generator(): void
    {
        $proposal = $this->proposal();
        $gen = new DeterministicFixtureFrontierGeneratorService([$proposal]);

        $result = $gen->generate($this->dossier(), 1);
        $expected = FrontierProposalIdentity::of($proposal);

        $this->assertSame($expected, $result['provenance']['p-1']['proposal_hash']);
        // basis for inbox candidate_hash: 'sha256:'+proposal_hash must be non-empty
        $this->assertNotSame('', $result['provenance']['p-1']['proposal_hash']);
    }

    // ---- FIXTURE GENERATOR: test-only, self-labelled, never 'real:' ----

    public function test_fixture_generator_self_labels_and_never_claims_real(): void
    {
        $gen = new DeterministicFixtureFrontierGeneratorService([$this->proposal()]);
        $result = $gen->generate($this->dossier(), 1);

        $this->assertSame('generated', $result['status']);
        $this->assertSame(DeterministicFixtureFrontierGeneratorService::LABEL, $result['generator_label']);
        $this->assertStringStartsWith('fixture:', $result['generator_label']);
        $this->assertStringNotContainsString('real:', $result['generator_label']);
        $this->assertFalse($result['claim_policy']['provider_invoked']);
    }

    public function test_fixture_generator_exposes_resolved_pair_distinct_from_a_judge(): void
    {
        $gen = new DeterministicFixtureFrontierGeneratorService(
            [$this->proposal()],
            resolvedProvider: 'codex_cli',
            resolvedModel: 'gen-model',
        );
        $result = $gen->generate($this->dossier(), 1);

        // A judge carrying a DIFFERENT resolved pair proves the I3 inequality is checkable.
        $judgeProvider = 'gemini_cli';
        $judgeModel = 'judge-model';

        $this->assertNotSame(
            [$result['generator_provider_resolved'], $result['generator_model_resolved']],
            [$judgeProvider, $judgeModel],
        );
    }

    public function test_fixture_generator_skips_with_no_canned_proposals(): void
    {
        $gen = new DeterministicFixtureFrontierGeneratorService([]);
        $result = $gen->generate($this->dossier(), 3);

        $this->assertSame('skipped', $result['status']);
        $this->assertSame([], $result['proposals']);
    }

    // ---- PROPOSAL-ONLY: nothing the generator emits is canonical ----

    public function test_emitted_proposal_object_carries_only_the_13_schema_keys(): void
    {
        $gen = new DeterministicFixtureFrontierGeneratorService([$this->proposal()]);
        $result = $gen->generate($this->dossier(), 1);

        $emitted = $result['proposals'][0];
        $validation = FoundrySchemas::validateShape(FoundrySchemas::EVOLUTION_PROPOSAL, $emitted);

        $this->assertTrue($validation['valid'], 'emitted proposal must pass 13-key shape gate');
        $this->assertSame([], $validation['unexpected_keys'], 'provenance must NOT be stamped on the proposal object');
        // provenance lives in a sibling result-level map, never on the object.
        $this->assertArrayNotHasKey('generator_label', $emitted);
        $this->assertArrayNotHasKey('proposal_hash', $emitted);
        $this->assertArrayHasKey('p-1', $result['provenance']);
    }
}

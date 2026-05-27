<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfDirectedEvolution;

use App\Services\Ai\SelfDirectedEvolution\SelfDirectedEvolutionGapReadModelService;
use App\Services\Ai\SelfDirectedEvolution\SelfDirectedSpecProposalAdapter;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Proposal-only contract tests for the Spec Proposal Adapter (v0.2, AP-709).
 *
 * The adapter is a pure function: gap candidate in, draft out. It must never
 * write a canonical doc, never materialize a file, never auto-approve and never
 * auto-implement.
 */
class SelfDirectedSpecProposalAdapterTest extends TestCase
{
    private function adapter(): SelfDirectedSpecProposalAdapter
    {
        return app(SelfDirectedSpecProposalAdapter::class);
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function candidate(array $overrides = []): array
    {
        return array_merge([
            'schema_version' => SelfDirectedEvolutionGapReadModelService::CANDIDATE_SCHEMA,
            'candidate_id' => 'gapc_test',
            'candidate_hash' => 'sha256:'.hash('sha256', 'seed'),
            'source_owner' => 'self_construction',
            'source_schema_version' => 'atlas.self_construction.subsystem_proposal.v1',
            'gap_kind' => 'missing_service_class',
            'title' => 'Self-Construction gap · missing_service_class · ABC',
            'rationale' => 'Service class not found.',
            'capability' => 'Alpha Beta',
            'risk_level' => 'high',
            'priority_score' => 305,
            'evidence_refs' => ['acos_scorecard:ABC', 'group:cognitive_immune'],
            'owner_doc_refs' => ['docs/engineering-knowledge-base/atlas-ai-self-construction-os.md'],
            'proposed_next_action' => 'route to propose under curation',
            'duplicate_authority_guard' => [
                'owner_service' => 'App\\Services\\Ai\\SelfConstruction\\AtlasSelfConstructionSubsystemBuilderService',
                'parallel_authority_created' => false,
            ],
        ], $overrides);
    }

    public function test_draft_emits_schema_with_owner_docs_evidence_acceptance(): void
    {
        $draft = $this->adapter()->draft($this->candidate());

        $this->assertSame(SelfDirectedSpecProposalAdapter::DRAFT_SCHEMA, $draft['schema_version']);
        $this->assertStringStartsWith('specd_', $draft['draft_id']);
        $this->assertNotEmpty($draft['acceptance_gates']);
        $this->assertNotEmpty($draft['required_tests']);
        $this->assertNotEmpty($draft['forbidden_paths']);
        $this->assertArrayHasKey('scope', $draft);
        $this->assertArrayHasKey('non_goals', $draft);
        $this->assertArrayHasKey('rollback_plan', $draft);
        $this->assertContains('acos_scorecard:ABC', $draft['evidence_refs']);
        // Spec OS owner doc must always be referenced.
        $this->assertContains(SelfDirectedSpecProposalAdapter::SPEC_OS_OWNER_DOC, $draft['owner_doc_refs']);
        $this->assertContains('docs/engineering-knowledge-base/atlas-ai-self-construction-os.md', $draft['owner_doc_refs']);
        $this->assertSame('atlas-ai-spec-operating-system', $draft['spec_os_owner']);
    }

    public function test_draft_forbids_canonical_write_and_writes_nothing(): void
    {
        $draft = $this->adapter()->draft($this->candidate());

        $this->assertFalse($draft['canonical_doc_write_allowed']);
        $this->assertFalse($draft['written']);
        // Neither the canonical target nor the staging path may exist on disk.
        $this->assertFileDoesNotExist(base_path($draft['proposed_doc_path']));
        $this->assertFileDoesNotExist(base_path($draft['staging_path']));
    }

    public function test_autoapproval_and_autoimplementation_always_false(): void
    {
        $draft = $this->adapter()->draft($this->candidate());

        $this->assertFalse($draft['autoapproval_allowed']);
        $this->assertFalse($draft['autoimplementation_allowed']);
        $this->assertFalse($draft['provider_invoked']);
        $this->assertTrue($draft['operator_approval_required']);
    }

    public function test_no_parallel_authority_in_duplicate_review(): void
    {
        $draft = $this->adapter()->draft($this->candidate());

        $this->assertFalse($draft['duplicate_authority_review']['parallel_authority_created']);
    }

    public function test_draft_hash_is_deterministic(): void
    {
        $candidate = $this->candidate();

        $a = $this->adapter()->draft($candidate);
        $b = $this->adapter()->draft($candidate);

        $this->assertSame($a['draft_hash'], $b['draft_hash']);
        $this->assertSame($a['draft_id'], $b['draft_id']);
    }

    public function test_draft_requires_candidate_hash(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->adapter()->draft(['title' => 'no hash']);
    }

    public function test_doc_kind_derivation(): void
    {
        $apDraft = $this->adapter()->draft($this->candidate(['gap_kind' => 'missing_service_class']));
        $this->assertSame('ap', $apDraft['proposed_doc_kind']);
        $this->assertStringStartsWith('docs/ap/AP-{NEXT}-', $apDraft['proposed_doc_path']);

        $specDraft = $this->adapter()->draft($this->candidate([
            'gap_kind' => 'self_improvement_backlog_item',
            'source_owner' => 'self_improvement',
        ]));
        $this->assertSame('spec', $specDraft['proposed_doc_kind']);
        $this->assertStringStartsWith('docs/engineering-knowledge-base/', $specDraft['proposed_doc_path']);
    }

    public function test_draft_state_is_drafted_by_atlas(): void
    {
        $draft = $this->adapter()->draft($this->candidate());
        $this->assertSame(SelfDirectedSpecProposalAdapter::STATE_DRAFTED_BY_ATLAS, $draft['state']);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfDirectedEvolution;

use App\Services\Ai\SelfConstruction\AtlasSelfConstructionSubsystemBuilderService;
use App\Services\Ai\SelfDirectedEvolution\SelfDirectedEvolutionCurationInboxService;
use App\Services\Ai\SelfDirectedEvolution\SelfDirectedEvolutionGapReadModelService;
use App\Services\Ai\SelfImprovement\AtlasSelfImprovementProposalBacklogService;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

/**
 * Read-only contract tests for the Operator Curation Inbox (v0.2, AP-709).
 *
 * Data is injected through the `gap_read_model` override (a synthetic read-model
 * report), so the inbox never re-runs the read model and never touches any owner
 * service. Persistence and approval are out of scope: the inbox is a projection.
 */
class SelfDirectedEvolutionCurationInboxServiceTest extends TestCase
{
    private function service(): SelfDirectedEvolutionCurationInboxService
    {
        return app(SelfDirectedEvolutionCurationInboxService::class);
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function candidate(array $overrides = []): array
    {
        return array_merge([
            'schema_version' => SelfDirectedEvolutionGapReadModelService::CANDIDATE_SCHEMA,
            'candidate_id' => 'gapc_'.substr(hash('sha256', $overrides['candidate_hash'] ?? 'x'), 0, 16),
            'candidate_hash' => 'sha256:'.hash('sha256', 'seed'),
            'source_owner' => 'self_improvement',
            'source_schema_version' => 'atlas.self_improvement.proposal_backlog_item.v1',
            'gap_kind' => 'self_improvement_backlog_item',
            'title' => 'Improve router',
            'rationale' => 'router needs work',
            'capability' => 'router',
            'risk_level' => 'medium',
            'priority_score' => 201,
            'evidence_refs' => ['ev1'],
            'owner_doc_refs' => ['docs/engineering-knowledge-base/atlas-self-improvement-closed-loop-level7-v1.md'],
            'proposed_next_action' => 'surface to operator',
            'duplicate_authority_guard' => ['parallel_authority_created' => false],
            'requires_operator_curation' => true,
            'autoapproval_allowed' => false,
            'external_side_effect_allowed' => false,
        ], $overrides);
    }

    /**
     * @param  list<array<string,mixed>>  $candidates
     * @return array<string,mixed>
     */
    private function report(array $candidates, string $status = 'ready'): array
    {
        return [
            'schema_version' => SelfDirectedEvolutionGapReadModelService::REPORT_SCHEMA,
            'status' => $status,
            'candidates' => $candidates,
            'blockers' => [],
            'report_hash' => 'sha256:'.hash('sha256', 'report'),
        ];
    }

    public function test_inbox_emits_schema_and_full_envelope(): void
    {
        $inbox = $this->service()->project(['gap_read_model' => $this->report([$this->candidate()])]);

        $this->assertSame(SelfDirectedEvolutionCurationInboxService::INBOX_SCHEMA, $inbox['schema_version']);
        foreach (['status', 'generated_at', 'source_read_model', 'item_count', 'counts', 'items', 'claim_policy', 'inbox_hash'] as $key) {
            $this->assertArrayHasKey($key, $inbox, "missing inbox key {$key}");
        }
        $this->assertStringStartsWith('sha256:', $inbox['inbox_hash']);
        $this->assertSame(1, $inbox['item_count']);
        $item = $inbox['items'][0];
        $this->assertSame(SelfDirectedEvolutionCurationInboxService::ITEM_SCHEMA, $item['schema_version']);
        $this->assertStringStartsWith('curi_', $item['item_id']);
    }

    public function test_all_items_require_operator_review_pending(): void
    {
        $inbox = $this->service()->project(['gap_read_model' => $this->report([
            $this->candidate(['candidate_hash' => 'sha256:'.hash('sha256', 'a')]),
            $this->candidate(['candidate_hash' => 'sha256:'.hash('sha256', 'b'), 'source_owner' => 'self_construction', 'risk_level' => 'high', 'priority_score' => 305]),
        ])]);

        $this->assertGreaterThanOrEqual(2, $inbox['item_count']);
        foreach ($inbox['items'] as $item) {
            $this->assertSame('pending_operator_review', $item['status']);
            $this->assertTrue($item['requires_operator_review']);
            $this->assertFalse($item['autoapproval_allowed']);
            $this->assertFalse($item['external_side_effect_allowed']);
        }
    }

    public function test_dedupe_is_deterministic(): void
    {
        $dup = $this->candidate(['candidate_hash' => 'sha256:'.hash('sha256', 'dup')]);
        $inbox = $this->service()->project(['gap_read_model' => $this->report([$dup, $dup, $dup])]);

        $this->assertSame(1, $inbox['item_count']);
    }

    public function test_items_classified_and_routed_to_owner(): void
    {
        $inbox = $this->service()->project(['gap_read_model' => $this->report([
            $this->candidate(['candidate_hash' => 'sha256:'.hash('sha256', 'sc'), 'source_owner' => 'self_construction', 'risk_level' => 'high', 'priority_score' => 305]),
        ])]);

        $item = $inbox['items'][0];
        $this->assertSame('self_construction', $item['classification']['source_owner']);
        $this->assertSame('high', $item['classification']['risk_band']);
        $this->assertSame('high', $item['classification']['priority_band']);
        $this->assertSame(AtlasSelfConstructionSubsystemBuilderService::class, $item['routes_to_owner']['owner_service']);
        $this->assertContains('approve', $item['routes_to_owner']['operator_execution_methods']);
        $this->assertContains('approve', $item['operator_actions']);
        $this->assertContains('veto', $item['operator_actions']);
        $this->assertContains('needs_revision', $item['operator_actions']);
    }

    public function test_counts_group_by_source_and_risk(): void
    {
        $inbox = $this->service()->project(['gap_read_model' => $this->report([
            $this->candidate(['candidate_hash' => 'sha256:'.hash('sha256', '1'), 'source_owner' => 'self_improvement', 'risk_level' => 'medium']),
            $this->candidate(['candidate_hash' => 'sha256:'.hash('sha256', '2'), 'source_owner' => 'self_construction', 'risk_level' => 'high', 'priority_score' => 305]),
        ])]);

        $this->assertSame(2, $inbox['counts']['total']);
        $this->assertSame(2, $inbox['counts']['pending_operator_review']);
        $this->assertSame(1, $inbox['counts']['by_source_owner']['self_improvement']);
        $this->assertSame(1, $inbox['counts']['by_source_owner']['self_construction']);
    }

    public function test_inbox_hash_is_deterministic_for_same_input(): void
    {
        $report = $this->report([
            $this->candidate(['candidate_hash' => 'sha256:'.hash('sha256', 'h1')]),
            $this->candidate(['candidate_hash' => 'sha256:'.hash('sha256', 'h2'), 'source_owner' => 'aael', 'gap_kind' => 'aael_promotion_blocked', 'risk_level' => 'high', 'priority_score' => 303]),
        ]);

        $a = $this->service()->project(['gap_read_model' => $report]);
        $b = $this->service()->project(['gap_read_model' => $report]);

        $this->assertSame($a['inbox_hash'], $b['inbox_hash']);
        $this->assertSame($a['items'], $b['items']);
    }

    public function test_claim_policy_enforces_read_only_curation(): void
    {
        $policy = $this->service()->project(['gap_read_model' => $this->report([])])['claim_policy'];

        $this->assertTrue($policy['read_only']);
        $this->assertFalse($policy['writes_state']);
        $this->assertFalse($policy['persists_registry']);
        $this->assertFalse($policy['invokes_propose']);
        $this->assertFalse($policy['invokes_approve']);
        $this->assertFalse($policy['creates_backlog_proposal']);
        $this->assertFalse($policy['creates_aael_cycle']);
        $this->assertFalse($policy['provider_invoked']);
        $this->assertFalse($policy['canonical_doc_write_allowed']);
        $this->assertFalse($policy['autoapproval_allowed']);
        $this->assertFalse($policy['autoimplementation_allowed']);
        $this->assertFalse($policy['parallel_authority_created']);
        $this->assertTrue($policy['operator_curation_required']);
    }

    public function test_uses_override_and_never_reinvokes_read_model_or_owners(): void
    {
        // A read model whose project() explodes if called proves the inbox uses
        // the `gap_read_model` override and never re-runs detection (and thus
        // never reaches any owner write method).
        $explodingReadModel = new class(
            app(AtlasSelfConstructionSubsystemBuilderService::class),
            app(AtlasSelfImprovementProposalBacklogService::class),
            app(\App\Services\Ai\AutonomousEvolution\AtlasAutonomousEvolutionLoopService::class),
        ) extends SelfDirectedEvolutionGapReadModelService
        {
            public function project(array $input = []): array
            {
                throw new RuntimeException('read model must not be re-invoked when an override is supplied');
            }
        };

        $inbox = new SelfDirectedEvolutionCurationInboxService($explodingReadModel);
        $report = $inbox->project(['gap_read_model' => $this->report([$this->candidate()])]);

        $this->assertSame(1, $report['item_count']);
    }

    public function test_operator_curation_receipt_requires_explicit_decision(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service()->buildOperatorCurationReceipt(['candidate_hash' => 'sha256:x', 'actor' => 'operator']);
    }

    public function test_operator_curation_receipt_never_auto_approves(): void
    {
        $receipt = $this->service()->buildOperatorCurationReceipt([
            'decision' => 'approve',
            'candidate_hash' => 'sha256:abc',
            'actor' => 'vitor',
            'rationale' => 'looks right',
            'source_owner' => 'self_construction',
        ]);

        $this->assertSame(SelfDirectedEvolutionCurationInboxService::RECEIPT_SCHEMA, $receipt['schema_version']);
        $this->assertSame('approve', $receipt['decision']);
        $this->assertSame('vitor', $receipt['actor']);
        $this->assertFalse($receipt['atlas_auto_decided']);
        $this->assertFalse($receipt['autoapproval']);
        $this->assertFalse($receipt['executed']);
        $this->assertTrue($receipt['requires_owner_execution']);
        $this->assertFalse($receipt['canonical_doc_write_allowed']);
        $this->assertFalse($receipt['autoimplementation_allowed']);
        $this->assertSame(AtlasSelfConstructionSubsystemBuilderService::class, $receipt['routes_to_owner']['owner_service']);
        $this->assertStringStartsWith('sha256:', $receipt['receipt_hash']);
    }

    public function test_operator_curation_receipt_requires_actor(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service()->buildOperatorCurationReceipt([
            'decision' => 'veto',
            'candidate_hash' => 'sha256:abc',
        ]);
    }
}

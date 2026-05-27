<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfDirectedEvolution;

use App\Services\Ai\Mission\MissionCanonicalHash;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Self-Directed Evolution · Spec Proposal Adapter (v0.2).
 *
 * Proposal-only (AP-707, AP-709). Turns one `atlas.evolution.gap_candidate.v1`
 * into an `atlas.self_directed_evolution.spec_proposal_draft.v1` draft for
 * operator review. It is the concrete, draft-only realization of the layer
 * doc's `atlas.evolution.spec_proposal.v1` contract.
 *
 * Hard guarantees: it never writes a canonical doc, never materializes a file,
 * never invokes Spec OS / a provider, never auto-approves and never
 * auto-implements. Spec compilation, doc publishing and execution remain with
 * their canonical owners after the operator approves.
 */
class SelfDirectedSpecProposalAdapter
{
    public const DRAFT_SCHEMA = 'atlas.self_directed_evolution.spec_proposal_draft.v1';

    public const SPEC_OS_OWNER_DOC = 'docs/engineering-knowledge-base/atlas-ai-spec-operating-system.md';

    public const STATE_DRAFTED_BY_ATLAS = 'drafted_by_atlas';

    /** Canonical roots a draft must never write to automatically. */
    private const FORBIDDEN_AUTO_WRITE_PATHS = [
        'docs/engineering-knowledge-base/** (active canonical docs)',
        'docs/ap/** (active architecture proposals)',
        'app/** (owner services)',
    ];

    /**
     * Produce a proposal-only spec/AP/doc draft from a gap candidate.
     *
     * @param  array<string,mixed>  $candidate
     * @return array<string,mixed>
     */
    public function draft(array $candidate): array
    {
        $hash = (string) ($candidate['candidate_hash'] ?? '');
        if ($hash === '') {
            throw new InvalidArgumentException('candidate_hash is required to draft a deterministic spec proposal.');
        }

        $gapKind = (string) ($candidate['gap_kind'] ?? 'unknown');
        $sourceOwner = (string) ($candidate['source_owner'] ?? 'unknown');
        $title = (string) ($candidate['title'] ?? 'Untitled gap');
        $capability = isset($candidate['capability']) && is_string($candidate['capability'])
            ? $candidate['capability']
            : null;
        $risk = (string) ($candidate['risk_level'] ?? 'medium');

        $docKind = $this->docKind($gapKind);
        $slug = $this->slug($title !== 'Untitled gap' ? $title : ($capability ?? $gapKind));
        $draftId = 'specd_'.substr(hash('sha256', 'spec_draft|'.$hash), 0, 16);

        $ownerDocRefs = array_values((array) ($candidate['owner_doc_refs'] ?? []));
        $ownerDocRefs[] = self::SPEC_OS_OWNER_DOC;

        $draft = [
            'schema_version' => self::DRAFT_SCHEMA,
            'draft_id' => $draftId,
            'state' => self::STATE_DRAFTED_BY_ATLAS,
            'candidate_hash' => $hash,
            'candidate_id' => (string) ($candidate['candidate_id'] ?? ''),
            'source_owner' => $sourceOwner,
            'gap_kind' => $gapKind,
            'proposed_doc_kind' => $docKind,
            // Canonical target IF the operator approves — NOT written here.
            'proposed_doc_path' => $this->proposedDocPath($docKind, $slug),
            // Where a receipt-gated staging draft COULD live in a future slice.
            'staging_path' => 'storage/atlas/self_directed_evolution/spec_drafts/'.$draftId.'.md',
            'written' => false,
            'title' => $title,
            'rationale' => (string) ($candidate['rationale'] ?? ''),
            'capability' => $capability,
            'risk_level' => $risk,
            'spec_os_owner' => 'atlas-ai-spec-operating-system',
            'owner_doc_refs' => array_values(array_unique($ownerDocRefs)),
            'evidence_refs' => array_values((array) ($candidate['evidence_refs'] ?? [])),
            'scope' => $this->scope($title, $capability),
            'non_goals' => $this->nonGoals(),
            'acceptance_gates' => $this->acceptanceGates($sourceOwner),
            'rollback_plan' => 'Draft is non-canonical and unwritten; vetoing the curation item discards it with zero runtime effect. No canonical doc, AP or owner service is touched.',
            'required_tests' => $this->requiredTests($sourceOwner),
            'forbidden_paths' => self::FORBIDDEN_AUTO_WRITE_PATHS,
            'duplicate_authority_review' => is_array($candidate['duplicate_authority_guard'] ?? null)
                ? $candidate['duplicate_authority_guard']
                : ['parallel_authority_created' => false],
            'routes_to_owner' => $sourceOwner,
            'operator_approval_required' => true,
            'canonical_doc_write_allowed' => false,
            'autoimplementation_allowed' => false,
            'autoapproval_allowed' => false,
            'provider_invoked' => false,
        ];
        $draft['draft_hash'] = 'sha256:'.MissionCanonicalHash::sha256($draft);
        $draft['generated_at'] = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->format(DateTimeInterface::ATOM);

        return $draft;
    }

    private function docKind(string $gapKind): string
    {
        return match ($gapKind) {
            'missing_service_class', 'partial_canon', 'pipeline_not_proven', 'coverage_drift' => 'ap',
            'self_improvement_backlog_item', 'aael_operator_review_required', 'aael_promotion_blocked' => 'spec',
            default => 'doc',
        };
    }

    private function proposedDocPath(string $docKind, string $slug): string
    {
        return match ($docKind) {
            'ap' => 'docs/ap/AP-{NEXT}-'.$slug.'.md',
            default => 'docs/engineering-knowledge-base/'.$slug.'.md',
        };
    }

    private function slug(string $value): string
    {
        $slug = strtolower(trim($value));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? $slug;
        $slug = trim($slug, '-');

        return $slug !== '' ? substr($slug, 0, 60) : 'gap-proposal';
    }

    /**
     * @return array<string,mixed>
     */
    private function scope(string $title, ?string $capability): array
    {
        return [
            'summary' => 'Operator-curated proposal to address: '.$title,
            'capability' => $capability,
            'mode' => 'proposal_only',
        ];
    }

    /**
     * @return list<string>
     */
    private function nonGoals(): array
    {
        return [
            'no_canonical_doc_write_without_operator_approval',
            'no_auto_implementation',
            'no_provider_invocation',
            'no_parallel_authority_creation',
        ];
    }

    /**
     * @return list<string>
     */
    private function acceptanceGates(string $sourceOwner): array
    {
        $gates = [
            'operator_curation_receipt_issued',
            'docs_health_passes',
            'architecture_validate_passes',
            'focused_tests_green',
            'no_parallel_authority_created',
        ];
        if ($sourceOwner === SelfDirectedEvolutionGapReadModelService::SOURCE_SELF_CONSTRUCTION) {
            $gates[] = 'subsystem_builder_propose_under_operator_curation';
        }

        return $gates;
    }

    /**
     * @return list<string>
     */
    private function requiredTests(string $sourceOwner): array
    {
        return [
            'php artisan atlas:ai:architecture-validate --json',
            'php artisan atlas:engineering:knowledge docs-health --json',
            'vendor/bin/phpunit tests/Unit/Ai/SelfDirectedEvolution',
        ];
    }
}

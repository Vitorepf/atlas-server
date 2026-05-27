<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfDirectedEvolution;

use App\Services\Ai\AutonomousEvolution\AtlasAutonomousEvolutionLoopService;
use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionSubsystemBuilderService;
use App\Services\Ai\SelfImprovement\AtlasSelfImprovementProposalBacklogService;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Throwable;

/**
 * Self-Directed Evolution · Gap Read Model (v0.1).
 *
 * Read-only composition layer (AP-707, AP-708) over three existing owners:
 *   - Self-Construction Subsystem Builder  (gap detection)
 *   - Self-Improvement Proposal Backlog    (improvement backlog)
 *   - Autonomous Evolution Loop / AAEL      (portfolio control plane)
 *
 * It NEVER writes state, NEVER invokes a provider, NEVER calls propose()/approve(),
 * NEVER creates a backlog proposal or AAEL cycle and NEVER auto-approves. It only
 * normalizes existing owner output into one operator-facing curation inbox shape
 * (`atlas.evolution.gap_candidate.v1`). Promotion of any candidate remains the
 * operator's job through the canonical owner.
 */
class SelfDirectedEvolutionGapReadModelService
{
    public const REPORT_SCHEMA = 'atlas.self_directed_evolution.gap_read_model.v1';

    public const CANDIDATE_SCHEMA = 'atlas.evolution.gap_candidate.v1';

    public const STATUS_READY = 'ready';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_BLOCKED = 'blocked';

    public const SOURCE_SELF_CONSTRUCTION = 'self_construction';

    public const SOURCE_SELF_IMPROVEMENT = 'self_improvement';

    public const SOURCE_AAEL = 'aael';

    /** @var array<string,int> */
    private const RISK_RANK = [
        'critical' => 4,
        'high' => 3,
        'medium' => 2,
        'low' => 1,
        'unknown' => 0,
    ];

    /** @var array<string,int> source tie-break weight (sub-dominant to risk) */
    private const SOURCE_WEIGHT = [
        self::SOURCE_SELF_CONSTRUCTION => 5,
        self::SOURCE_AAEL => 3,
        self::SOURCE_SELF_IMPROVEMENT => 1,
    ];

    /** Self-Improvement backlog statuses that are terminal and not surfaced as gaps. */
    private const SI_TERMINAL_STATUSES = ['archived', 'rejected', 'learned'];

    public function __construct(
        private readonly AtlasSelfConstructionSubsystemBuilderService $subsystemBuilder,
        private readonly AtlasSelfImprovementProposalBacklogService $selfImprovementBacklog,
        private readonly AtlasAutonomousEvolutionLoopService $autonomousEvolutionLoop,
    ) {}

    /**
     * Project the unified gap read model.
     *
     * Optional `$input` overrides keep the projection deterministic and
     * side-effect-free for tests:
     *   - gaps:                   list<array>  bypass detectGaps()
     *   - self_improvement_backlog: array      bypass listBacklog()
     *   - aael_control_plane:     array         bypass controlPlane()
     *   - backlog_filters:        array         passed to listBacklog()
     *   - hours:                  int           AAEL control-plane window (default 24)
     *   - limit:                  int           cap candidate count
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function project(array $input = []): array
    {
        $hours = max(1, (int) ($input['hours'] ?? 24));
        $limit = isset($input['limit']) ? max(1, (int) $input['limit']) : null;

        $candidates = [];
        $blockers = [];
        $sourceSummary = [];

        [$scCandidates, $scSummary, $scBlocker] = $this->collectSelfConstruction($input);
        $candidates = array_merge($candidates, $scCandidates);
        $sourceSummary[self::SOURCE_SELF_CONSTRUCTION] = $scSummary;
        if ($scBlocker !== null) {
            $blockers[] = $scBlocker;
        }

        [$siCandidates, $siSummary, $siBlocker] = $this->collectSelfImprovement($input);
        $candidates = array_merge($candidates, $siCandidates);
        $sourceSummary[self::SOURCE_SELF_IMPROVEMENT] = $siSummary;
        if ($siBlocker !== null) {
            $blockers[] = $siBlocker;
        }

        [$aaelCandidates, $aaelSummary, $aaelBlocker] = $this->collectAael($input, $hours);
        $candidates = array_merge($candidates, $aaelCandidates);
        $sourceSummary[self::SOURCE_AAEL] = $aaelSummary;
        if ($aaelBlocker !== null) {
            $blockers[] = $aaelBlocker;
        }

        $candidates = $this->dedupe($candidates);
        $candidates = $this->sortCandidates($candidates);
        if ($limit !== null) {
            $candidates = array_slice($candidates, 0, $limit);
        }

        $availableCount = count(array_filter(
            $sourceSummary,
            static fn (array $row): bool => ($row['available'] ?? false) === true
        ));
        $status = match (true) {
            $availableCount === 0 => self::STATUS_BLOCKED,
            $blockers !== [] => self::STATUS_PARTIAL,
            default => self::STATUS_READY,
        };

        $payload = [
            'schema_version' => self::REPORT_SCHEMA,
            'status' => $status,
            'source_summary' => $sourceSummary,
            'candidate_count' => count($candidates),
            'candidates' => $candidates,
            'blockers' => $blockers,
            'owner_reuse_matrix' => $this->ownerReuseMatrix(),
            'claim_policy' => $this->claimPolicy(),
        ];
        $payload['report_hash'] = 'sha256:'.MissionCanonicalHash::sha256($payload);
        $payload['generated_at'] = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->format(DateTimeInterface::ATOM);

        return $payload;
    }

    // ---------- owner read seams (read-only; overridable for tests) ----------

    /**
     * Read-only gap fetch from the Self-Construction Subsystem Builder.
     *
     * @return list<array<string,mixed>>
     */
    protected function fetchGaps(): array
    {
        return $this->subsystemBuilder->detectGaps();
    }

    /**
     * Read-only backlog fetch from Self-Improvement.
     *
     * @param  array<string,mixed>  $filters
     * @return array<string,mixed>
     */
    protected function fetchBacklog(array $filters): array
    {
        return $this->selfImprovementBacklog->listBacklog($filters);
    }

    /**
     * Read-only control-plane fetch from AAEL.
     *
     * @return array<string,mixed>
     */
    protected function fetchControlPlane(int $hours): array
    {
        return $this->autonomousEvolutionLoop->controlPlane($hours);
    }

    // ---------- source collectors ----------

    /**
     * @param  array<string,mixed>  $input
     * @return array{0:list<array<string,mixed>>,1:array<string,mixed>,2:array<string,mixed>|null}
     */
    private function collectSelfConstruction(array $input): array
    {
        try {
            $gaps = array_key_exists('gaps', $input) && is_array($input['gaps'])
                ? $input['gaps']
                : $this->fetchGaps();
        } catch (Throwable $e) {
            return [[], $this->unavailableSummary(), $this->sourceUnavailable(self::SOURCE_SELF_CONSTRUCTION, $e)];
        }

        $candidates = [];
        foreach ($gaps as $gap) {
            if (! is_array($gap)) {
                continue;
            }
            $candidates[] = $this->selfConstructionCandidate($gap);
        }

        return [
            $candidates,
            $this->availableSummary(count($gaps), count($candidates)),
            null,
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array{0:list<array<string,mixed>>,1:array<string,mixed>,2:array<string,mixed>|null}
     */
    private function collectSelfImprovement(array $input): array
    {
        try {
            $backlog = array_key_exists('self_improvement_backlog', $input) && is_array($input['self_improvement_backlog'])
                ? $input['self_improvement_backlog']
                : $this->fetchBacklog(
                    is_array($input['backlog_filters'] ?? null) ? $input['backlog_filters'] : []
                );
        } catch (Throwable $e) {
            return [[], $this->unavailableSummary(), $this->sourceUnavailable(self::SOURCE_SELF_IMPROVEMENT, $e)];
        }

        $proposals = is_array($backlog['proposals'] ?? null) ? $backlog['proposals'] : [];
        $candidates = [];
        foreach ($proposals as $item) {
            if (! is_array($item)) {
                continue;
            }
            $statusValue = strtolower((string) ($item['status'] ?? ''));
            if (in_array($statusValue, self::SI_TERMINAL_STATUSES, true)) {
                continue;
            }
            $candidates[] = $this->selfImprovementCandidate($item);
        }

        return [
            $candidates,
            $this->availableSummary(count($proposals), count($candidates)),
            null,
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array{0:list<array<string,mixed>>,1:array<string,mixed>,2:array<string,mixed>|null}
     */
    private function collectAael(array $input, int $hours): array
    {
        try {
            $controlPlane = array_key_exists('aael_control_plane', $input) && is_array($input['aael_control_plane'])
                ? $input['aael_control_plane']
                : $this->fetchControlPlane($hours);
        } catch (Throwable $e) {
            return [[], $this->unavailableSummary(), $this->sourceUnavailable(self::SOURCE_AAEL, $e)];
        }

        $summary = is_array($controlPlane['summary'] ?? null) ? $controlPlane['summary'] : [];
        $window = is_array($controlPlane['window'] ?? null) ? $controlPlane['window'] : ['hours' => $hours];
        $operatorReview = (int) ($summary['operator_review_required'] ?? 0);
        $blocked = (int) ($summary['blocked'] ?? 0);

        $candidates = [];
        if ($operatorReview > 0) {
            $candidates[] = $this->aaelCandidate(
                'aael_operator_review_required',
                'medium',
                "AAEL has {$operatorReview} promotion decision(s) awaiting operator review.",
                $operatorReview,
                $window,
            );
        }
        if ($blocked > 0) {
            $candidates[] = $this->aaelCandidate(
                'aael_promotion_blocked',
                'high',
                "AAEL has {$blocked} blocked promotion decision(s).",
                $blocked,
                $window,
            );
        }

        return [
            $candidates,
            $this->availableSummary(($operatorReview > 0 ? 1 : 0) + ($blocked > 0 ? 1 : 0), count($candidates)),
            null,
        ];
    }

    // ---------- candidate normalizers ----------

    /**
     * @param  array<string,mixed>  $gap
     * @return array<string,mixed>
     */
    private function selfConstructionCandidate(array $gap): array
    {
        $kind = (string) ($gap['kind'] ?? 'unknown');
        $acronym = (string) ($gap['subsystem_acronym'] ?? '');
        $name = (string) ($gap['subsystem_name'] ?? $acronym);
        $group = (string) ($gap['group'] ?? '');
        $rationale = (string) ($gap['rationale'] ?? '');

        $risk = match ($kind) {
            'missing_service_class', 'pipeline_not_proven' => 'high',
            default => 'medium',
        };

        $evidence = ['acos_scorecard:'.($acronym !== '' ? $acronym : 'unknown')];
        if ($group !== '') {
            $evidence[] = 'group:'.$group;
        }

        return $this->makeCandidate([
            'source_owner' => self::SOURCE_SELF_CONSTRUCTION,
            'source_schema_version' => AtlasSelfConstructionSubsystemBuilderService::PROPOSAL_SCHEMA,
            'source_ref' => 'self_construction:subsystem:'.($acronym !== '' ? $acronym : 'unknown').':'.$kind,
            'gap_kind' => $kind,
            'title' => 'Self-Construction gap · '.$kind.($acronym !== '' ? ' · '.$acronym : ''),
            'rationale' => $rationale !== '' ? $rationale : 'Subsystem Builder reported a gap.',
            'capability' => $name !== '' ? $name : ($acronym !== '' ? $acronym : null),
            'risk_level' => $risk,
            'evidence_refs' => $evidence,
            'owner_doc_refs' => [
                'docs/engineering-knowledge-base/atlas-ai-self-construction-os.md',
                'docs/engineering-knowledge-base/atlas-self-construction-catalog.md',
            ],
            'proposed_next_action' => 'Operator routes to AtlasSelfConstructionSubsystemBuilderService::propose() under curation; never auto-proposed here.',
            'duplicate_authority_guard' => $this->guard(
                AtlasSelfConstructionSubsystemBuilderService::class,
                ['detectGaps'],
                ['propose', 'approve'],
            ),
        ]);
    }

    /**
     * @param  array<string,mixed>  $item
     * @return array<string,mixed>
     */
    private function selfImprovementCandidate(array $item): array
    {
        $proposalId = (string) ($item['proposal_id'] ?? 'unknown');
        $title = (string) ($item['title'] ?? 'untitled proposal');
        $statusValue = (string) ($item['status'] ?? 'unknown');
        $summary = (string) ($item['summary'] ?? '');
        $risk = $this->normalizeRisk($item['risk_level'] ?? null);
        $capability = isset($item['target_capability']) && is_string($item['target_capability'])
            ? $item['target_capability']
            : null;

        $evidence = array_values(array_filter(
            (array) ($item['evidence_refs'] ?? []),
            static fn ($ref): bool => is_string($ref) && $ref !== '',
        ));
        $evidence[] = 'self_improvement_status:'.$statusValue;

        $ownerDocs = ['docs/engineering-knowledge-base/atlas-self-improvement-closed-loop-level7-v1.md'];
        foreach ((array) ($item['canonical_docs'] ?? []) as $doc) {
            if (is_string($doc) && $doc !== '') {
                $ownerDocs[] = $doc;
            }
        }

        return $this->makeCandidate([
            'source_owner' => self::SOURCE_SELF_IMPROVEMENT,
            'source_schema_version' => AtlasSelfImprovementProposalBacklogService::ITEM_SCHEMA_VERSION,
            'source_ref' => 'self_improvement:'.$proposalId,
            'gap_kind' => 'self_improvement_backlog_item',
            'title' => $title,
            'rationale' => $summary !== '' ? $summary : 'Self-Improvement backlog item in status '.$statusValue.'.',
            'capability' => $capability,
            'risk_level' => $risk,
            'evidence_refs' => array_values(array_unique($evidence)),
            'owner_doc_refs' => array_values(array_unique($ownerDocs)),
            'proposed_next_action' => 'Self-Improvement remains owner of scoring/delta; surface to Operator Curation Inbox. Do not auto-activate.',
            'duplicate_authority_guard' => $this->guard(
                AtlasSelfImprovementProposalBacklogService::class,
                ['listBacklog'],
                ['createProposal', 'evaluateProposal', 'prioritize'],
            ),
        ]);
    }

    /**
     * @param  array<string,mixed>  $window
     * @return array<string,mixed>
     */
    private function aaelCandidate(string $gapKind, string $risk, string $rationale, int $count, array $window): array
    {
        $hours = (int) ($window['hours'] ?? 24);

        return $this->makeCandidate([
            'source_owner' => self::SOURCE_AAEL,
            'source_schema_version' => AtlasAutonomousEvolutionLoopService::CONTROL_PLANE_SCHEMA,
            'source_ref' => 'aael:control_plane:'.$gapKind,
            'gap_kind' => $gapKind,
            'title' => 'AAEL · '.$gapKind,
            'rationale' => $rationale,
            'capability' => 'autonomous_evolution_portfolio',
            'risk_level' => $risk,
            'evidence_refs' => [
                'aael_control_plane:window_hours:'.$hours,
                'aael_control_plane:count:'.$count,
            ],
            'owner_doc_refs' => [
                'docs/engineering-knowledge-base/atlas-autonomous-evolution-loop.md',
            ],
            'proposed_next_action' => 'AAEL owns portfolio selection; route operator-review/blocked decisions to Operator Curation Inbox.',
            'duplicate_authority_guard' => $this->guard(
                AtlasAutonomousEvolutionLoopService::class,
                ['controlPlane'],
                ['runCycle', 'observeOpportunities'],
            ),
        ]);
    }

    /**
     * Finalize a candidate: stamp invariant flags, derive deterministic
     * id/hash and priority score.
     *
     * @param  array<string,mixed>  $base
     * @return array<string,mixed>
     */
    private function makeCandidate(array $base): array
    {
        $candidate = array_merge([
            'schema_version' => self::CANDIDATE_SCHEMA,
            'requires_operator_curation' => true,
            'autoapproval_allowed' => false,
            'external_side_effect_allowed' => false,
        ], $base);

        $candidate['priority_score'] = $this->priorityScore(
            (string) $candidate['source_owner'],
            (string) $candidate['risk_level'],
        );

        $raw = hash('sha256', implode('|', [
            (string) $candidate['source_owner'],
            (string) $candidate['source_ref'],
            (string) $candidate['gap_kind'],
            (string) ($candidate['capability'] ?? ''),
        ]));
        $candidate['candidate_id'] = 'gapc_'.substr($raw, 0, 16);
        $candidate['candidate_hash'] = 'sha256:'.$raw;

        return $candidate;
    }

    // ---------- helpers ----------

    /**
     * @param  list<array<string,mixed>>  $candidates
     * @return list<array<string,mixed>>
     */
    private function dedupe(array $candidates): array
    {
        $seen = [];
        $unique = [];
        foreach ($candidates as $candidate) {
            $key = (string) ($candidate['candidate_hash'] ?? '');
            if ($key !== '' && isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $unique[] = $candidate;
        }

        return $unique;
    }

    /**
     * Deterministic ordering: priority desc, risk desc, source asc, hash asc.
     *
     * @param  list<array<string,mixed>>  $candidates
     * @return list<array<string,mixed>>
     */
    private function sortCandidates(array $candidates): array
    {
        usort($candidates, function (array $a, array $b): int {
            return [
                $b['priority_score'] ?? 0,
                self::RISK_RANK[$b['risk_level'] ?? 'unknown'] ?? 0,
            ] <=> [
                $a['priority_score'] ?? 0,
                self::RISK_RANK[$a['risk_level'] ?? 'unknown'] ?? 0,
            ]
                ?: (((string) ($a['source_owner'] ?? '')) <=> ((string) ($b['source_owner'] ?? '')))
                ?: (((string) ($a['candidate_hash'] ?? '')) <=> ((string) ($b['candidate_hash'] ?? '')));
        });

        return array_values($candidates);
    }

    private function priorityScore(string $sourceOwner, string $riskLevel): int
    {
        $riskWeight = (self::RISK_RANK[$riskLevel] ?? 0) * 100;

        return $riskWeight + (self::SOURCE_WEIGHT[$sourceOwner] ?? 0);
    }

    private function normalizeRisk(mixed $value): string
    {
        if (! is_string($value)) {
            return 'medium';
        }
        $value = strtolower(trim($value));

        return array_key_exists($value, self::RISK_RANK) && $value !== 'unknown' ? $value : 'medium';
    }

    /**
     * @param  list<string>  $reused
     * @param  list<string>  $notInvoked
     * @return array<string,mixed>
     */
    private function guard(string $ownerService, array $reused, array $notInvoked): array
    {
        return [
            'owner_service' => $ownerService,
            'reused_methods' => $reused,
            'not_invoked_methods' => $notInvoked,
            'read_only' => true,
            'parallel_authority_created' => false,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function availableSummary(int $rawCount, int $candidateCount): array
    {
        return [
            'available' => true,
            'raw_count' => $rawCount,
            'candidate_count' => $candidateCount,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function unavailableSummary(): array
    {
        return [
            'available' => false,
            'raw_count' => 0,
            'candidate_count' => 0,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function sourceUnavailable(string $source, Throwable $e): array
    {
        return [
            'source' => $source,
            'reason' => 'source_unavailable',
            'detail' => $e->getMessage(),
        ];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function ownerReuseMatrix(): array
    {
        return [
            self::SOURCE_SELF_CONSTRUCTION => [
                'owner_service' => AtlasSelfConstructionSubsystemBuilderService::class,
                'reused_methods' => ['detectGaps'],
                'not_invoked_methods' => ['propose', 'approve'],
                'source_schema_version' => AtlasSelfConstructionSubsystemBuilderService::PROPOSAL_SCHEMA,
            ],
            self::SOURCE_SELF_IMPROVEMENT => [
                'owner_service' => AtlasSelfImprovementProposalBacklogService::class,
                'reused_methods' => ['listBacklog'],
                'not_invoked_methods' => ['createProposal', 'evaluateProposal', 'prioritize'],
                'source_schema_version' => AtlasSelfImprovementProposalBacklogService::ITEM_SCHEMA_VERSION,
            ],
            self::SOURCE_AAEL => [
                'owner_service' => AtlasAutonomousEvolutionLoopService::class,
                'reused_methods' => ['controlPlane'],
                'not_invoked_methods' => ['runCycle', 'observeOpportunities'],
                'source_schema_version' => AtlasAutonomousEvolutionLoopService::CONTROL_PLANE_SCHEMA,
            ],
        ];
    }

    /**
     * @return array<string,bool>
     */
    private function claimPolicy(): array
    {
        return [
            'read_only' => true,
            'writes_state' => false,
            'invokes_propose' => false,
            'invokes_approve' => false,
            'creates_backlog_proposal' => false,
            'creates_aael_cycle' => false,
            'provider_invoked' => false,
            'autoapproval_allowed' => false,
            'external_side_effect_allowed' => false,
            'parallel_authority_created' => false,
            'operator_curation_required' => true,
        ];
    }
}

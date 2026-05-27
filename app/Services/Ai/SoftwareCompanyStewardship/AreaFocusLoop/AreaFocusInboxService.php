<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\SelfDirectedEvolution\SelfDirectedEvolutionCurationInboxService;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Area Focus Loop · Operator Inbox (AP-718).
 *
 * Atlas Software Company Stewardship Stack é stack/capability family dentro do
 * Atlas Autonomous Software Company Runtime, não OS novo.
 *
 * Turns Area Focus Loop findings into operator-reviewable inbox items. It is a
 * read-only projection that REUSES `SelfDirectedEvolutionCurationInboxService`
 * for operator-review semantics (dedupe by finding_hash, deterministic sort,
 * pending-review status, operator actions) and `AreaFocusSpecDraftBridge` for
 * the shared finding -> candidate mapping. It persists nothing, creates NO
 * parallel proposal registry, writes no canonical doc, invokes no provider and
 * never auto-approves or auto-implements.
 *
 * Findings are supplied via `$input['findings']` (decoupled from the finding
 * engine on purpose), keeping the projection deterministic and side-effect free.
 */
class AreaFocusInboxService
{
    public const INBOX_SCHEMA = 'atlas.software_company_stewardship.area_focus_inbox.v1';

    public const ITEM_SCHEMA = 'atlas.software_company_stewardship.area_focus_inbox_item.v1';

    public const STATUS_READY = 'ready';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_BLOCKED = 'blocked';

    public function __construct(
        private readonly SelfDirectedEvolutionCurationInboxService $curationInbox,
        private readonly AreaFocusSpecDraftBridge $specBridge,
    ) {}

    /**
     * Project the Area Focus operator inbox from a list of findings.
     *
     * `$input`:
     *   - findings: list<array>  REQUIRED — Area Focus findings to triage.
     *   - area_id:  string       optional area label for the envelope.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function project(array $input = []): array
    {
        $areaId = (string) ($input['area_id'] ?? 'agentic_engineering_os');

        if (! array_key_exists('findings', $input) || ! is_array($input['findings'])) {
            return $this->blockedEnvelope($areaId, 'findings_required', [
                'detail' => 'AreaFocusInboxService::project() requires an array of Area Focus findings under input["findings"].',
            ]);
        }

        $candidates = [];
        $blockers = [];
        $findingByHash = [];

        foreach ($input['findings'] as $index => $finding) {
            if (! is_array($finding)) {
                $blockers[] = ['reason' => 'invalid_finding', 'detail' => "findings[{$index}] is not an object."];

                continue;
            }
            try {
                $candidate = $this->specBridge->findingToCandidate($finding);
            } catch (InvalidArgumentException $e) {
                $blockers[] = ['reason' => 'invalid_finding', 'detail' => $e->getMessage()];

                continue;
            }
            $findingByHash[(string) $candidate['candidate_hash']] = $finding;
            $candidates[] = $candidate;
        }

        // Reuse the Self-Directed Evolution curation inbox for operator-review
        // semantics (dedupe by candidate_hash == finding_hash, sort, status).
        $curation = $this->curationInbox->project([
            'gap_read_model' => [
                'schema_version' => 'atlas.self_directed_evolution.gap_read_model.v1',
                'status' => SelfDirectedEvolutionCurationInboxService::STATUS_PENDING_OPERATOR_REVIEW,
                'candidates' => $candidates,
                'blockers' => [],
            ],
        ]);

        $items = [];
        foreach ($curation['items'] ?? [] as $curationItem) {
            if (! is_array($curationItem)) {
                continue;
            }
            $hash = (string) ($curationItem['candidate_hash'] ?? '');
            $finding = $findingByHash[$hash] ?? [];
            $items[] = $this->inboxItem($curationItem, $finding, $areaId);
        }

        $status = match (true) {
            $candidates === [] && $blockers !== [] => self::STATUS_BLOCKED,
            $blockers !== [] => self::STATUS_PARTIAL,
            default => self::STATUS_READY,
        };

        $payload = [
            'schema_version' => self::INBOX_SCHEMA,
            'status' => $status,
            'ap_contract' => 'AP-718',
            'area_id' => $areaId,
            'stewardship_stack' => $this->stewardshipStack(),
            'source_curation_inbox' => [
                'schema_version' => SelfDirectedEvolutionCurationInboxService::INBOX_SCHEMA,
                'inbox_hash' => (string) ($curation['inbox_hash'] ?? ''),
            ],
            'item_count' => count($items),
            'counts' => $this->counts($items),
            'items' => $items,
            'blockers' => $blockers,
            'reused_owners' => $this->reusedOwners(),
            'claim_policy' => $this->claimPolicy(),
        ];
        $payload['inbox_hash'] = 'sha256:'.MissionCanonicalHash::sha256($payload);
        $payload['generated_at'] = $this->now();

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $curationItem
     * @param  array<string,mixed>  $finding
     * @return array<string,mixed>
     */
    private function inboxItem(array $curationItem, array $finding, string $areaId): array
    {
        $hash = (string) ($curationItem['candidate_hash'] ?? ($finding['finding_hash'] ?? ''));
        $risk = (string) ($curationItem['risk_level'] ?? ($finding['risk_level'] ?? 'medium'));
        $routeHint = (string) ($finding['route_hint'] ?? '');

        return [
            'schema_version' => self::ITEM_SCHEMA,
            'item_id' => 'afib_'.substr(hash('sha256', 'afib|'.$hash), 0, 16),
            'finding_hash' => $hash,
            'finding_id' => (string) ($finding['finding_id'] ?? ($curationItem['candidate_id'] ?? '')),
            'area_id' => (string) ($finding['area_id'] ?? $areaId),
            'finding_type' => (string) ($curationItem['gap_kind'] ?? ($finding['finding_type'] ?? '')),
            'title' => (string) ($curationItem['title'] ?? ($finding['title'] ?? '')),
            'rationale' => (string) ($curationItem['rationale'] ?? ($finding['detail'] ?? '')),
            'risk' => $risk,
            'risk_level' => $risk,
            'confidence' => (string) ($finding['confidence'] ?? 'medium'),
            'priority_score' => (int) ($curationItem['priority_score'] ?? ($finding['priority_score'] ?? 0)),
            'route_hint' => $routeHint,
            'spec_draftable' => $this->specBridge->isSpecDraftable($finding),
            'status' => (string) ($curationItem['status'] ?? SelfDirectedEvolutionCurationInboxService::STATUS_PENDING_OPERATOR_REVIEW),
            'operator_actions' => SelfDirectedEvolutionCurationInboxService::OPERATOR_ACTIONS,
            'operator_decision_required' => true,
            'evidence_refs' => array_values((array) ($curationItem['evidence_refs'] ?? ($finding['evidence_refs'] ?? []))),
            'recommended_action' => (string) ($finding['recommended_action'] ?? ($curationItem['proposed_next_action'] ?? 'Operator review required.')),
            'source_curation_item_id' => (string) ($curationItem['item_id'] ?? ''),
            'autoapproval_allowed' => false,
            'autoimplementation_allowed' => false,
            'external_side_effect_allowed' => false,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $items
     * @return array<string,mixed>
     */
    private function counts(array $items): array
    {
        $byRoute = [];
        $byRisk = [];
        $specDraftable = 0;
        foreach ($items as $item) {
            $route = (string) ($item['route_hint'] ?? 'unknown');
            $risk = (string) ($item['risk'] ?? 'unknown');
            $byRoute[$route] = ($byRoute[$route] ?? 0) + 1;
            $byRisk[$risk] = ($byRisk[$risk] ?? 0) + 1;
            if (($item['spec_draftable'] ?? false) === true) {
                $specDraftable++;
            }
        }
        ksort($byRoute);
        ksort($byRisk);

        return [
            'total' => count($items),
            'pending_operator_review' => count($items),
            'spec_draftable' => $specDraftable,
            'by_route_hint' => $byRoute,
            'by_risk' => $byRisk,
        ];
    }

    /**
     * @param  array<string,mixed>  $extra
     * @return array<string,mixed>
     */
    private function blockedEnvelope(string $areaId, string $reason, array $extra = []): array
    {
        $payload = [
            'schema_version' => self::INBOX_SCHEMA,
            'status' => self::STATUS_BLOCKED,
            'ap_contract' => 'AP-718',
            'area_id' => $areaId,
            'reason' => $reason,
            'stewardship_stack' => $this->stewardshipStack(),
            'item_count' => 0,
            'items' => [],
            'reused_owners' => $this->reusedOwners(),
            'claim_policy' => $this->claimPolicy(),
        ] + $extra;
        $payload['inbox_hash'] = 'sha256:'.MissionCanonicalHash::sha256($payload);
        $payload['generated_at'] = $this->now();

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    private function stewardshipStack(): array
    {
        return [
            'umbrella' => 'Atlas Software Company Stewardship Stack',
            'level_name' => 'Area Focus Loop',
            'parent_runtime' => 'Atlas Autonomous Software Company Runtime',
            'canonical_statement' => 'Atlas Software Company Stewardship Stack é stack/capability family dentro do Atlas Autonomous Software Company Runtime, não OS novo.',
            'aps' => ['AP-712', 'AP-715', 'AP-718'],
            'new_os_created' => false,
            'parallel_runtime_created' => false,
        ];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function reusedOwners(): array
    {
        return [
            'curation_inbox' => [
                'owner_service' => SelfDirectedEvolutionCurationInboxService::class,
                'reused_methods' => ['project'],
                'source_schema_version' => SelfDirectedEvolutionCurationInboxService::INBOX_SCHEMA,
            ],
            'spec_proposal_adapter' => [
                'owner_service' => AreaFocusSpecDraftBridge::class.' -> SelfDirectedSpecProposalAdapter',
                'reused_methods' => ['findingToCandidate', 'isSpecDraftable', 'draftFromFinding'],
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
            'persists_registry' => false,
            'parallel_proposal_registry_created' => false,
            'provider_invoked' => false,
            'canonical_doc_write_allowed' => false,
            'autoapproval_allowed' => false,
            'autoimplementation_allowed' => false,
            'external_side_effect_allowed' => false,
            'operator_decision_required' => true,
            'reuses_curation_inbox' => true,
            'reuses_spec_proposal_adapter' => true,
        ];
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
    }
}

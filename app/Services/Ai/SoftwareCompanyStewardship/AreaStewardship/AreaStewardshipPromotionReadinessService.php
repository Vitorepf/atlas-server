<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaStewardship;

use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipEvolutionDecisionLedgerService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipEvolutionReadModelService;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Area Stewardship · Promotion Readiness Gate (AP-732).
 *
 * Proves whether an AP-730 Area Stewardship proposal can be promoted into an
 * active implementation slice. This gate never executes the promotion; it only
 * checks ingredients and operator acceptance.
 */
class AreaStewardshipPromotionReadinessService
{
    public const REPORT_SCHEMA = 'atlas.area_stewardship.promotion_readiness.v1';

    public const STATUS_READY_FOR_OPERATOR_REVIEW = 'ready_for_operator_review';

    public const STATUS_READY_FOR_ACTIVE_HANDOFF = 'ready_for_active_handoff';

    public const STATUS_BLOCKED = 'blocked';

    public function __construct(
        private readonly StewardshipEvolutionReadModelService $evolution,
        private readonly StewardshipEvolutionDecisionLedgerService $ledger,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function assess(array $input = []): array
    {
        $areaId = $this->areaId($input['area_id'] ?? StewardshipEvolutionReadModelService::DEFAULT_AREA_ID);
        $evolution = is_array($input['evolution_report'] ?? null)
            ? $input['evolution_report']
            : $this->evolution->project(['area_id' => $areaId]);

        if (($evolution['status'] ?? '') === StewardshipEvolutionReadModelService::STATUS_BLOCKED) {
            return $this->finalize([
                'schema_version' => self::REPORT_SCHEMA,
                'status' => self::STATUS_BLOCKED,
                'reason' => 'stewardship_evolution_not_ready',
                'area_id' => $areaId,
                'blockers' => ['AP-730 evolution report is blocked for this area.'],
                'claim_policy' => $this->claimPolicy(),
            ]);
        }

        $area = is_array($evolution['area_stewardship'] ?? null) ? $evolution['area_stewardship'] : [];
        $targetId = (string) ($area['area_id'] ?? $areaId);
        $targetHash = 'sha256:'.MissionCanonicalHash::sha256($area);
        $decisionLedger = is_array($input['decision_ledger'] ?? null)
            ? $input['decision_ledger']
            : $this->ledger->listDecisions($areaId);
        $accepted = $this->acceptedDecision($decisionLedger, $targetId, $targetHash);
        $checks = $this->checks($area, $evolution);
        $blockers = $this->blockers($checks, $accepted);

        return $this->finalize([
            'schema_version' => self::REPORT_SCHEMA,
            'status' => $blockers === []
                ? self::STATUS_READY_FOR_ACTIVE_HANDOFF
                : ($this->onlyMissingDecision($blockers) ? self::STATUS_READY_FOR_OPERATOR_REVIEW : self::STATUS_BLOCKED),
            'ap_contract' => 'AP-732',
            'area_id' => $areaId,
            'target_type' => 'area_stewardship',
            'target_id' => $targetId,
            'target_hash' => $targetHash,
            'promotion_from' => 'area_focus_loop',
            'promotion_to' => 'area_stewardship_active',
            'area_stewardship' => $area,
            'operator_acceptance' => $accepted,
            'checks' => $checks,
            'blockers' => $blockers,
            'next_actions' => $this->nextActions($blockers),
            'claim_policy' => $this->claimPolicy(),
        ]);
    }

    /**
     * @param  array<string,mixed>  $ledger
     * @return array<string,mixed>
     */
    private function acceptedDecision(array $ledger, string $targetId, string $targetHash): array
    {
        $decisions = is_array($ledger['decisions'] ?? null) ? $ledger['decisions'] : [];
        foreach ($decisions as $decision) {
            if (! is_array($decision)) {
                continue;
            }
            if ((string) ($decision['target_type'] ?? '') !== 'area_stewardship') {
                continue;
            }
            if ((string) ($decision['decision'] ?? '') !== 'accept') {
                continue;
            }
            $matchesId = (string) ($decision['target_id'] ?? '') === $targetId;
            $matchesHash = (string) ($decision['target_hash'] ?? '') === $targetHash;
            if ($matchesId || $matchesHash) {
                return [
                    'status' => 'accepted',
                    'decision_id' => (string) ($decision['decision_id'] ?? ''),
                    'target_match' => $matchesHash ? 'target_hash' : 'target_id',
                    'operator_actor' => (string) ($decision['operator_actor'] ?? ''),
                    'recorded_at' => (string) ($decision['recorded_at'] ?? ''),
                ];
            }
        }

        return [
            'status' => 'missing',
            'required_decision' => 'AP-731 accept for target_type=area_stewardship',
        ];
    }

    /**
     * @param  array<string,mixed>  $area
     * @param  array<string,mixed>  $evolution
     * @return array<string,bool>
     */
    private function checks(array $area, array $evolution): array
    {
        $health = is_array($area['health_model'] ?? null) ? $area['health_model'] : [];
        $roadmap = is_array($area['roadmap_candidates'] ?? null) ? $area['roadmap_candidates'] : [];
        $devForge = is_array($area['dev_forge_policy'] ?? null) ? $area['dev_forge_policy'] : [];
        $inbox = is_array($area['operator_inbox'] ?? null) ? $area['operator_inbox'] : [];
        $areaFocus = is_array($evolution['area_focus_loop'] ?? null) ? $evolution['area_focus_loop'] : [];
        $claim = is_array($evolution['claim_policy'] ?? null) ? $evolution['claim_policy'] : [];

        return [
            'area_schema_present' => ($area['schema_version'] ?? '') === StewardshipEvolutionReadModelService::AREA_STEWARD_SCHEMA,
            'health_model_present' => $health !== [] && isset($health['score'], $health['band']),
            'roadmap_candidates_present' => count($roadmap) > 0,
            'dev_forge_policy_present' => isset($devForge['small_local_work'], $devForge['cross_system_or_long_horizon_work']),
            'operator_inbox_present' => $inbox !== [] && ($inbox['auto_approval'] ?? true) === false,
            'area_focus_ready' => ($areaFocus['status'] ?? '') === 'ready',
            'evidence_refs_present' => is_array($areaFocus['evidence_refs'] ?? null) && count($areaFocus['evidence_refs']) > 0,
            'no_mutation_claim' => ($claim['repo_mutation'] ?? true) === false
                && ($claim['merge_without_operator'] ?? true) === false
                && ($claim['deploy_without_operator'] ?? true) === false
                && ($claim['secret_access'] ?? true) === false,
        ];
    }

    /**
     * @param  array<string,bool>  $checks
     * @param  array<string,mixed>  $accepted
     * @return list<string>
     */
    private function blockers(array $checks, array $accepted): array
    {
        $blockers = [];
        foreach ($checks as $name => $passed) {
            if (! $passed) {
                $blockers[] = $name.'_missing_or_failed';
            }
        }
        if (($accepted['status'] ?? '') !== 'accepted') {
            $blockers[] = 'operator_accept_decision_missing';
        }

        return $blockers;
    }

    /**
     * @param  list<string>  $blockers
     */
    private function onlyMissingDecision(array $blockers): bool
    {
        return $blockers === ['operator_accept_decision_missing'];
    }

    /**
     * @param  list<string>  $blockers
     * @return list<string>
     */
    private function nextActions(array $blockers): array
    {
        if ($blockers === []) {
            return [
                'Open an active Area Stewardship implementation AP using this readiness report as evidence.',
                'Keep execution routed through existing Area Focus, Atlas Dev, Forge, Evidence and operator inbox owners.',
            ];
        }
        if ($this->onlyMissingDecision($blockers)) {
            return [
                'Record an AP-731 accept decision for target_type=area_stewardship before active promotion.',
            ];
        }

        return [
            'Repair failed readiness checks before asking the operator to accept Area Stewardship active promotion.',
        ];
    }

    /**
     * @return array<string,bool|string>
     */
    private function claimPolicy(): array
    {
        return [
            'read_only' => true,
            'promotion_gate_only' => true,
            'writes_local_state' => false,
            'mutates_target_repo' => false,
            'provider_invoked' => false,
            'dev_invoked' => false,
            'forge_invoked' => false,
            'opens_branch' => false,
            'merges' => false,
            'deploys' => false,
            'touches_secrets' => false,
            'autoapproval_allowed' => false,
            'autoimplementation_allowed' => false,
            'operator_review_required' => true,
            'is_new_os' => false,
            'parallel_runtime_created' => false,
        ];
    }

    private function areaId(mixed $value): string
    {
        $areaId = preg_replace('/[^a-z0-9_]+/', '_', strtolower(trim((string) $value))) ?? '';

        return $areaId !== '' ? $areaId : StewardshipEvolutionReadModelService::DEFAULT_AREA_ID;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function finalize(array $payload): array
    {
        $payload['report_hash'] = 'sha256:'.MissionCanonicalHash::sha256($this->stable($payload));
        $payload['generated_at'] = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function stable(array $payload): array
    {
        unset($payload['report_hash'], $payload['generated_at']);

        return $payload;
    }
}

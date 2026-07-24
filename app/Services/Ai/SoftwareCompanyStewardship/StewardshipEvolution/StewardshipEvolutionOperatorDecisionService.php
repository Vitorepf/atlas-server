<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution;

use App\Services\Ai\Mission\MissionCanonicalHash;
use InvalidArgumentException;

/**
 * Stewardship Evolution · Operator Decision Receipts (AP-731).
 *
 * Captures explicit operator decisions for AP-730 ladder outputs. This is the
 * review bridge for Area Stewardship, Portfolio Stewardship, Autonomous
 * Executive recommendations and Self-Expanding Software Company proposals.
 *
 * Hard guarantees: no provider calls, no branch/worktree creation, no Dev/Forge
 * dispatch, no merge/deploy/secrets/destructive changes and no auto-promotion.
 */
class StewardshipEvolutionOperatorDecisionService
{
    use StewardshipEvolutionClock;

    public const RECEIPT_SCHEMA = 'atlas.software_company_stewardship.evolution_operator_decision_receipt.v1';

    public const DECISION_ACCEPT = 'accept';

    public const DECISION_REJECT = 'reject';

    public const DECISION_DEFER = 'defer';

    public const DECISION_REQUEST_CHANGES = 'request_changes';

    /** @var list<string> */
    public const DECISIONS = [
        self::DECISION_ACCEPT,
        self::DECISION_REJECT,
        self::DECISION_DEFER,
        self::DECISION_REQUEST_CHANGES,
    ];

    /** @var list<string> */
    public const TARGET_TYPES = [
        'area_stewardship',
        'portfolio_stewardship',
        'autonomous_executive',
        'self_expanding_software_company',
        'new_area_proposal',
        'product_mode_control',
    ];

    public const BLOCK_ACTOR_REQUIRED = 'operator_actor_required';

    public const BLOCK_INVALID_DECISION = 'invalid_decision';

    public const BLOCK_INVALID_TARGET_TYPE = 'invalid_target_type';

    public const BLOCK_TARGET_WITHOUT_ANCHOR = 'target_without_anchor';

    public const BLOCK_HIGH_RISK_ACCEPT_RATIONALE = 'rationale_required_for_high_risk_accept';

    /** @var list<string> */
    private const HIGH_RISK_BANDS = ['high', 'critical'];

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function decide(array $input): array
    {
        $actor = trim((string) ($input['operator_actor'] ?? ''));
        if ($actor === '') {
            throw new InvalidArgumentException(self::BLOCK_ACTOR_REQUIRED.': operator_actor is required.');
        }

        $decision = strtolower(trim((string) ($input['decision'] ?? '')));
        if (! in_array($decision, self::DECISIONS, true)) {
            throw new InvalidArgumentException(self::BLOCK_INVALID_DECISION.': decision must be one of '.implode(', ', self::DECISIONS).'.');
        }

        $targetType = strtolower(trim((string) ($input['target_type'] ?? '')));
        if (! in_array($targetType, self::TARGET_TYPES, true)) {
            throw new InvalidArgumentException(self::BLOCK_INVALID_TARGET_TYPE.': target_type must be one of '.implode(', ', self::TARGET_TYPES).'.');
        }

        [$targetId, $targetHash] = $this->targetAnchor($input, $targetType);

        $rationale = trim((string) ($input['rationale'] ?? ''));
        $risk = $this->normalizeRisk($input['risk'] ?? ($input['risk_level'] ?? null));
        if ($decision === self::DECISION_ACCEPT && in_array($risk, self::HIGH_RISK_BANDS, true) && $rationale === '') {
            throw new InvalidArgumentException(self::BLOCK_HIGH_RISK_ACCEPT_RATIONALE.": a high-risk accept ({$risk}) requires an explicit rationale.");
        }

        $areaId = $this->normalizeAreaId($input['area_id'] ?? StewardshipEvolutionReadModelService::DEFAULT_AREA_ID);
        $portfolioId = trim((string) ($input['portfolio_id'] ?? 'atlas_software_company')) ?: 'atlas_software_company';

        $decisionId = 'seod_'.substr(hash('sha256', implode('|', [
            'stewardship_evolution_operator_decision',
            $areaId,
            $portfolioId,
            $targetType,
            $targetId,
            $targetHash,
            $decision,
            $actor,
        ])), 0, 16);

        $receipt = [
            'schema_version' => self::RECEIPT_SCHEMA,
            'ap_contract' => 'AP-731',
            'decision_id' => $decisionId,
            'area_id' => $areaId,
            'portfolio_id' => $portfolioId,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'target_hash' => $targetHash,
            'operator_actor' => $actor,
            'decision' => $decision,
            'rationale' => $rationale,
            'risk_level' => $risk,
            'next_allowed_action' => $this->nextAllowedAction($targetType, $decision),
            'routes_to_owner' => $this->routesToOwner($targetType, $decision),
            'review_boundary' => $this->reviewBoundary($targetType),
            'requires_owner_execution' => $decision === self::DECISION_ACCEPT,
            'requires_operator_followup' => in_array($decision, [self::DECISION_DEFER, self::DECISION_REQUEST_CHANGES], true),
            'executed' => false,
            'operator_owned' => true,
            'atlas_auto_decided' => false,
            'autoapproval_allowed' => false,
            'autoimplementation_allowed' => false,
            'branch_created' => false,
            'provider_invoked' => false,
            'dev_invoked' => false,
            'forge_invoked' => false,
            'mutates_target_repo' => false,
            'parallel_registry_created' => false,
            'new_os_created' => false,
            'auto_promotion' => false,
        ];
        if ($targetType === 'product_mode_control' && is_array($input['target_payload'] ?? null)) {
            $receipt['target_payload'] = $this->sanitizeProductModeControlPayload($input['target_payload']);
        }
        $receipt['decision_hash'] = 'sha256:'.MissionCanonicalHash::sha256($receipt);
        $receipt['decided_at'] = $this->now();

        return $receipt;
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array{0:string,1:string}
     */
    private function targetAnchor(array $input, string $targetType): array
    {
        $targetId = trim((string) ($input['target_id'] ?? ''));
        $targetHash = trim((string) ($input['target_hash'] ?? ''));

        if ($targetHash === '' && is_array($input['target_payload'] ?? null)) {
            $targetHash = 'sha256:'.MissionCanonicalHash::sha256($input['target_payload']);
        }

        if ($targetId === '' && is_array($input['target_payload'] ?? null)) {
            $targetId = $this->deriveTargetId($targetType, $input['target_payload']);
        }

        if ($targetId === '' && $targetHash === '') {
            throw new InvalidArgumentException(self::BLOCK_TARGET_WITHOUT_ANCHOR.': target_id, target_hash or target_payload is required.');
        }

        if ($targetHash === '') {
            $targetHash = 'sha256:'.MissionCanonicalHash::sha256([$targetType, $targetId]);
        }
        if ($targetId === '') {
            $targetId = $targetType.'_'.substr(str_replace('sha256:', '', $targetHash), 0, 16);
        }

        return [$targetId, $targetHash];
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function deriveTargetId(string $targetType, array $payload): string
    {
        $candidates = match ($targetType) {
            'autonomous_executive' => ['recommendation_id'],
            'new_area_proposal' => ['proposal_id'],
            'portfolio_stewardship' => ['portfolio_id'],
            'area_stewardship' => ['area_id'],
            'self_expanding_software_company' => ['portfolio_id'],
            'product_mode_control' => ['control_id', 'policy_id'],
            default => [],
        };

        foreach ($candidates as $key) {
            $value = trim((string) ($payload[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return $targetType.'_'.substr(MissionCanonicalHash::sha256($payload), 0, 16);
    }

    private function nextAllowedAction(string $targetType, string $decision): string
    {
        if ($decision === self::DECISION_REJECT) {
            return 'close_target_no_action';
        }
        if ($decision === self::DECISION_DEFER) {
            return 're_review_next_stewardship_cycle';
        }
        if ($decision === self::DECISION_REQUEST_CHANGES) {
            return 'return_to_stewardship_proposal_revision';
        }

        return match ($targetType) {
            'area_stewardship' => 'release_area_stewardship_to_owner_execution_under_operator_review',
            'portfolio_stewardship' => 'release_portfolio_rebalance_to_owner_execution_under_operator_review',
            'autonomous_executive' => 'release_executive_recommendation_to_owner_execution_under_operator_review',
            'self_expanding_software_company', 'new_area_proposal' => 'release_new_area_proposal_to_domain_runtime_creation_gate_under_operator_review',
            'product_mode_control' => 'apply_product_mode_control_policy_to_read_models_only',
            default => 'operator_review',
        };
    }

    /**
     * @return array<string,string|bool>
     */
    private function routesToOwner(string $targetType, string $decision): array
    {
        if ($decision !== self::DECISION_ACCEPT) {
            return [
                'owner' => 'stewardship_evolution',
                'requires_execution' => false,
                'note' => 'No downstream execution is authorized by this decision.',
            ];
        }

        return match ($targetType) {
            'new_area_proposal', 'self_expanding_software_company' => [
                'owner' => 'domain_runtime_creation_gate',
                'requires_execution' => false,
                'note' => 'Accepted expansion proposal must pass Domain Runtime Creation Gate; this receipt does not create the area.',
            ],
            'autonomous_executive' => [
                'owner' => 'operator_inbox',
                'requires_execution' => false,
                'note' => 'Accepted executive recommendation becomes operator-approved intent; execution is a separate governed slice.',
            ],
            'product_mode_control' => [
                'owner' => 'night_shift_product_mode',
                'requires_execution' => false,
                'note' => 'Accepted control receipt may configure Product Mode projections and schedulers; it never executes work or mutates repos.',
            ],
            default => [
                'owner' => 'software_company_stewardship_stack',
                'requires_execution' => false,
                'note' => 'Accepted proposal unlocks the next governed owner step; this receipt never executes it.',
            ],
        };
    }

    /**
     * @return array<string,bool|string>
     */
    private function reviewBoundary(string $targetType): array
    {
        return [
            'target_type' => $targetType,
            'proposal_only' => true,
            'operator_review_required' => true,
            'evidence_required_before_execution' => true,
            'merge_without_operator' => false,
            'deploy_without_operator' => false,
            'secret_access' => false,
            'destructive_change' => false,
            'auto_promotion' => false,
        ];
    }

    /**
     * Product Mode control receipts intentionally persist only policy-shaped
     * values. Secret-like or executor-shaped fields are discarded here even if a
     * caller passes them through target_payload.
     *
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function sanitizeProductModeControlPayload(array $payload): array
    {
        $stringKeys = [
            'schema_version',
            'control_id',
            'control_type',
            'repo',
            'repository',
            'repo_authorization_status',
            'pause_until',
            'max_risk_without_operator',
            'control_reason',
            'expires_at',
        ];
        $intKeys = [
            'autonomy_tier',
            'max_allowed_autonomy_tier',
            'cycle_budget',
            'branch_wip_limit',
            'provider_call_limit',
        ];
        $boolKeys = [
            'paused',
            'kill_switch',
            'lock_active',
            'rate_limited',
        ];
        $listKeys = [
            'authorized_repositories',
            'sensitive_domains',
            'evidence_refs',
        ];

        $out = [];
        foreach ($stringKeys as $key) {
            if (array_key_exists($key, $payload)) {
                $out[$key] = trim((string) $payload[$key]);
            }
        }
        foreach ($intKeys as $key) {
            if (array_key_exists($key, $payload)) {
                $out[$key] = (int) $payload[$key];
            }
        }
        foreach ($boolKeys as $key) {
            if (array_key_exists($key, $payload)) {
                $out[$key] = (bool) $payload[$key];
            }
        }
        foreach ($listKeys as $key) {
            if (array_key_exists($key, $payload) && is_array($payload[$key])) {
                $out[$key] = array_values(array_filter($payload[$key], 'is_string'));
            }
        }

        return $out;
    }

    private function normalizeRisk(mixed $value): string
    {
        if (! is_string($value)) {
            return 'medium';
        }
        $value = strtolower(trim($value));

        return in_array($value, ['critical', 'high', 'medium', 'low'], true) ? $value : 'medium';
    }

    private function normalizeAreaId(mixed $value): string
    {
        $areaId = preg_replace('/[^a-z0-9_]+/', '_', strtolower(trim((string) $value))) ?? '';

        return $areaId !== '' ? $areaId : StewardshipEvolutionReadModelService::DEFAULT_AREA_ID;
    }
}

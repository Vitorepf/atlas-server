<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\ProductMode;

use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipEvolutionDecisionLedgerService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipEvolutionOperatorDecisionService;
use InvalidArgumentException;

/**
 * Product Mode operational control receipts (AP-755).
 *
 * Thin adapter over AP-731. It records Product Mode controls as operator-owned,
 * append-only decision receipts and can replay accepted controls into AP-754
 * projections. It does not create a second ledger or grant execution authority.
 */
final class ProductModeOperationalControlReceiptService
{
    public const SCHEMA = 'atlas.software_company.product_mode_control_receipts.v1';

    public const RECEIPT_SCHEMA = 'atlas.software_company.product_mode_control_receipt.v1';

    public const TARGET_TYPE = 'product_mode_control';

    /** @var list<string> */
    public const CONTROL_TYPES = [
        'repo_authorization',
        'autonomy_tier',
        'budget_policy',
        'safety_control',
        'risk_policy',
        'evidence_policy',
    ];

    public const BLOCK_INVALID_CONTROL_TYPE = 'invalid_product_mode_control_type';

    public function __construct(
        private readonly StewardshipEvolutionDecisionLedgerService $ledger,
    ) {}

    public function setStorageRootForTesting(?string $dir): void
    {
        $this->ledger->setStorageRootForTesting($dir);
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function record(array $input): array
    {
        $areaId = $this->nonEmpty((string) ($input['area_id'] ?? 'agentic_engineering_os'), 'agentic_engineering_os');
        $portfolioId = $this->nonEmpty((string) ($input['portfolio_id'] ?? 'atlas_software_company'), 'atlas_software_company');
        $controlType = $this->controlType($input['control_type'] ?? 'repo_authorization');
        $payload = $this->controlPayload($areaId, $portfolioId, $controlType, $input);

        $record = $this->ledger->record([
            'area_id' => $areaId,
            'portfolio_id' => $portfolioId,
            'target_type' => self::TARGET_TYPE,
            'target_id' => (string) $payload['control_id'],
            'target_payload' => $payload,
            'operator_actor' => (string) ($input['operator_actor'] ?? $input['actor'] ?? ''),
            'decision' => (string) ($input['decision'] ?? StewardshipEvolutionOperatorDecisionService::DECISION_ACCEPT),
            'risk' => (string) ($input['risk'] ?? $input['risk_level'] ?? 'medium'),
            'rationale' => (string) ($input['rationale'] ?? ''),
        ]);

        return $this->decorateReceipt($record);
    }

    /**
     * @return array<string,mixed>
     */
    public function listReceipts(?string $areaId = 'agentic_engineering_os', ?string $portfolioId = 'atlas_software_company'): array
    {
        $list = $this->ledger->listDecisions($areaId);
        $items = [];
        foreach ((array) ($list['decisions'] ?? []) as $summary) {
            if (($summary['target_type'] ?? '') !== self::TARGET_TYPE) {
                continue;
            }
            $record = $this->ledger->replay((string) ($summary['decision_id'] ?? ''));
            if (! is_array($record)) {
                continue;
            }
            if ($portfolioId !== null && $portfolioId !== '' && (string) ($record['portfolio_id'] ?? '') !== $portfolioId) {
                continue;
            }
            $items[] = $this->receiptSummary($record);
        }

        return [
            'schema_version' => self::SCHEMA,
            'ap_contract' => 'AP-755',
            'ledger_ap_contract' => 'AP-731',
            'area_id' => $areaId,
            'portfolio_id' => $portfolioId,
            'receipt_count' => count($items),
            'receipts' => $items,
            'claim_policy' => $this->claimPolicy(),
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    public function replay(string $decisionId): ?array
    {
        $record = $this->ledger->replay($decisionId);
        if (! is_array($record) || (string) ($record['target_type'] ?? '') !== self::TARGET_TYPE) {
            return null;
        }

        return $this->decorateReceipt($record);
    }

    /**
     * @param  array<string,mixed>  $baseInput
     * @return array<string,mixed>
     */
    public function effectiveControls(string $areaId = 'agentic_engineering_os', string $portfolioId = 'atlas_software_company', array $baseInput = []): array
    {
        $policy = [];
        $sourceDecisionIds = [];
        $receipts = $this->listReceipts($areaId, $portfolioId);

        foreach ((array) ($receipts['receipts'] ?? []) as $summary) {
            if (($summary['decision'] ?? '') !== StewardshipEvolutionOperatorDecisionService::DECISION_ACCEPT) {
                continue;
            }
            $record = $this->ledger->replay((string) ($summary['decision_id'] ?? ''));
            if (! is_array($record)) {
                continue;
            }
            $payload = $this->extractControlPayload($record);
            if ($payload === []) {
                continue;
            }
            $policy = array_merge($policy, $this->projectablePolicy($payload));
            $sourceDecisionIds[] = (string) ($record['decision_id'] ?? '');
        }

        $effective = array_merge($baseInput, $policy);
        $effective['control_policy'] = [
            'schema_version' => 'atlas.software_company.product_mode_control_policy.v1',
            'source_ap_contract' => 'AP-755',
            'ledger_ap_contract' => 'AP-731',
            'applied_decision_ids' => $sourceDecisionIds,
            'applied_receipt_count' => count($sourceDecisionIds),
            'policy' => $policy,
            'policy_hash' => 'sha256:'.MissionCanonicalHash::sha256($policy),
        ];

        return $effective;
    }

    /**
     * @param  array<string,mixed>  $record
     * @return array<string,mixed>
     */
    private function decorateReceipt(array $record): array
    {
        return $record + [
            'product_mode_control_receipt_schema' => self::RECEIPT_SCHEMA,
            'product_mode_control_claim_policy' => $this->claimPolicy(),
        ];
    }

    /**
     * @param  array<string,mixed>  $record
     * @return array<string,mixed>
     */
    private function receiptSummary(array $record): array
    {
        $payload = $this->extractControlPayload($record);

        return [
            'decision_id' => (string) ($record['decision_id'] ?? ''),
            'area_id' => (string) ($record['area_id'] ?? ''),
            'portfolio_id' => (string) ($record['portfolio_id'] ?? ''),
            'control_id' => (string) ($payload['control_id'] ?? $record['target_id'] ?? ''),
            'control_type' => (string) ($payload['control_type'] ?? ''),
            'repo' => (string) ($payload['repo'] ?? $payload['repository'] ?? ''),
            'decision' => (string) ($record['decision'] ?? ''),
            'risk_level' => (string) ($record['risk_level'] ?? ''),
            'recorded_at' => (string) ($record['recorded_at'] ?? ''),
            'decision_hash' => (string) ($record['decision_hash'] ?? ''),
        ];
    }

    /**
     * @param  array<string,mixed>  $record
     * @return array<string,mixed>
     */
    private function extractControlPayload(array $record): array
    {
        $payload = $record['target_payload'] ?? [];

        return is_array($payload) ? $payload : [];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function projectablePolicy(array $payload): array
    {
        $keys = [
            'repo',
            'repository',
            'repo_authorization_status',
            'authorized_repositories',
            'autonomy_tier',
            'max_allowed_autonomy_tier',
            'cycle_budget',
            'branch_wip_limit',
            'provider_call_limit',
            'paused',
            'kill_switch',
            'lock_active',
            'rate_limited',
            'pause_until',
            'max_risk_without_operator',
            'sensitive_domains',
            'evidence_refs',
        ];

        $out = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $payload)) {
                $out[$key] = $payload[$key];
            }
        }

        return $out;
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function controlPayload(string $areaId, string $portfolioId, string $controlType, array $input): array
    {
        $repo = $this->nonEmpty((string) ($input['repo'] ?? $input['repository'] ?? 'atlas-server'), 'atlas-server');
        $payload = [
            'schema_version' => self::RECEIPT_SCHEMA,
            'control_type' => $controlType,
            'area_id' => $areaId,
            'portfolio_id' => $portfolioId,
            'repo' => $repo,
            'control_reason' => trim((string) ($input['control_reason'] ?? $input['rationale'] ?? '')),
        ];

        foreach ($this->allowedPayloadKeys($controlType) as $key) {
            if (array_key_exists($key, $input)) {
                $payload[$key] = $input[$key];
            }
        }

        if ($controlType === 'repo_authorization' && ! array_key_exists('authorized_repositories', $payload)) {
            $payload['authorized_repositories'] = [$repo];
        }

        $payload['control_id'] = 'pmctrl_'.substr(MissionCanonicalHash::sha256([
            $areaId,
            $portfolioId,
            $controlType,
            $repo,
        ]), 0, 16);

        return $payload;
    }

    /**
     * @return list<string>
     */
    private function allowedPayloadKeys(string $controlType): array
    {
        return match ($controlType) {
            'repo_authorization' => ['repo_authorization_status', 'authorized_repositories'],
            'autonomy_tier' => ['autonomy_tier', 'max_allowed_autonomy_tier'],
            'budget_policy' => ['cycle_budget', 'branch_wip_limit', 'provider_call_limit'],
            'safety_control' => ['paused', 'kill_switch', 'lock_active', 'rate_limited', 'pause_until'],
            'risk_policy' => ['max_risk_without_operator', 'sensitive_domains'],
            'evidence_policy' => ['evidence_refs'],
            default => [],
        };
    }

    private function controlType(mixed $value): string
    {
        $type = strtolower(trim((string) $value));
        if (! in_array($type, self::CONTROL_TYPES, true)) {
            throw new InvalidArgumentException(self::BLOCK_INVALID_CONTROL_TYPE.': control_type must be one of '.implode(', ', self::CONTROL_TYPES).'.');
        }

        return $type;
    }

    /**
     * @return array<string,bool|string>
     */
    private function claimPolicy(): array
    {
        return [
            'writes_local_state' => true,
            'persistence' => 'ap731_jsonl_append_only',
            'creates_parallel_ledger' => false,
            'read_only_over_repo' => true,
            'authorizes_repository_directly' => false,
            'changes_autonomy_tier_directly' => false,
            'updates_budget_directly' => false,
            'toggles_kill_switch_directly' => false,
            'mutates_target_repo' => false,
            'provider_invoked' => false,
            'dev_invoked' => false,
            'forge_invoked' => false,
            'opens_branch' => false,
            'merges' => false,
            'deploys' => false,
            'touches_secrets' => false,
            'operator_review_required' => true,
        ];
    }

    private function nonEmpty(string $value, string $fallback): string
    {
        $value = trim($value);

        return $value !== '' ? $value : $fallback;
    }
}

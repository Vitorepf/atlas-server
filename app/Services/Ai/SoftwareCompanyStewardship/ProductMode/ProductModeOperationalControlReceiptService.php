<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\ProductMode;

use App\Services\Ai\SoftwareCompanyStewardship\ProductMode\Support\ProductModeOperationalControlReceiptSupport;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipEvolutionDecisionLedgerService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipEvolutionOperatorDecisionService;

/**
 * Product Mode operational control receipts (AP-755).
 *
 * Thin adapter over AP-731. It records Product Mode controls as operator-owned,
 * append-only decision receipts and can replay accepted controls into AP-754
 * projections. It does not create a second ledger or grant execution authority.
 *
 * Pure payload/summary/policy/observability helpers live in
 * ProductModeOperationalControlReceiptSupport. This class owns ledger I/O only.
 */
final class ProductModeOperationalControlReceiptService
{
    use ProductModeStringHelper;

    public const SCHEMA = ProductModeOperationalControlReceiptSupport::SCHEMA;

    public const RECEIPT_SCHEMA = ProductModeOperationalControlReceiptSupport::RECEIPT_SCHEMA;

    public const TARGET_TYPE = ProductModeOperationalControlReceiptSupport::TARGET_TYPE;

    /** @var list<string> */
    public const CONTROL_TYPES = ProductModeOperationalControlReceiptSupport::CONTROL_TYPES;

    public const BLOCK_INVALID_CONTROL_TYPE = ProductModeOperationalControlReceiptSupport::BLOCK_INVALID_CONTROL_TYPE;

    public const CONTROLS_RECEIPTS_BACKLOG_BRIDGE_SCHEMA = ProductModeOperationalControlReceiptSupport::CONTROLS_RECEIPTS_BACKLOG_BRIDGE_SCHEMA;

    public const AP790_BACKLOG_PRODUCT_MODE_CONTROLS_RECEIPTS = ProductModeOperationalControlReceiptSupport::AP790_BACKLOG_PRODUCT_MODE_CONTROLS_RECEIPTS;

    /** Upper bound for operator-facing receipt slices (AP-790 controls backlog observability). */
    public const DEFAULT_BOUNDED_RECEIPT_WINDOW = ProductModeOperationalControlReceiptSupport::DEFAULT_BOUNDED_RECEIPT_WINDOW;

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
        $controlType = ProductModeOperationalControlReceiptSupport::controlType($input['control_type'] ?? 'repo_authorization');
        $payload = ProductModeOperationalControlReceiptSupport::controlPayload($areaId, $portfolioId, $controlType, $input);

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

        return ProductModeOperationalControlReceiptSupport::decorateReceipt($record);
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
            $items[] = ProductModeOperationalControlReceiptSupport::receiptSummary($record);
        }

        return ProductModeOperationalControlReceiptSupport::listEnvelope($areaId, $portfolioId, $items);
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

        return ProductModeOperationalControlReceiptSupport::decorateReceipt($record);
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
            $payload = ProductModeOperationalControlReceiptSupport::extractControlPayload($record);
            if ($payload === []) {
                continue;
            }
            $policy = array_merge($policy, ProductModeOperationalControlReceiptSupport::projectablePolicy($payload));
            $sourceDecisionIds[] = (string) ($record['decision_id'] ?? '');
        }

        return ProductModeOperationalControlReceiptSupport::withEffectiveControlPolicy($baseInput, $policy, $sourceDecisionIds);
    }

    /**
     * AP-790 · materialize product_mode_controls_receipts backlog into bounded observability.
     *
     * Read-only aggregate for priority-engine backlog `product_mode_controls_receipts`:
     * pause, kill-switch and autonomy tier decisions stay receipt-backed and visible
     * before longer unattended AP-790 runs.
     *
     * @return array<string,mixed>
     */
    public function productModeControlsReceiptsBacklogObservability(
        string $areaId = 'agentic_engineering_os',
        string $portfolioId = 'atlas_software_company',
        int $recentLimit = self::DEFAULT_BOUNDED_RECEIPT_WINDOW,
    ): array {
        $receipts = $this->listReceipts($areaId, $portfolioId);
        $allItems = (array) ($receipts['receipts'] ?? []);
        $acceptedPayloadsByDecisionId = [];

        foreach ($allItems as $summary) {
            if (! is_array($summary) || ($summary['decision'] ?? '') !== StewardshipEvolutionOperatorDecisionService::DECISION_ACCEPT) {
                continue;
            }
            $decisionId = (string) ($summary['decision_id'] ?? '');
            if ($decisionId === '') {
                continue;
            }
            $record = $this->ledger->replay($decisionId);
            if (! is_array($record)) {
                continue;
            }
            $acceptedPayloadsByDecisionId[$decisionId] = ProductModeOperationalControlReceiptSupport::extractControlPayload($record);
        }

        $effective = $this->effectiveControls($areaId, $portfolioId);
        $policy = is_array($effective['control_policy']['policy'] ?? null)
            ? $effective['control_policy']['policy']
            : [];

        return ProductModeOperationalControlReceiptSupport::backlogObservability(
            $areaId,
            $portfolioId,
            $allItems,
            $acceptedPayloadsByDecisionId,
            $policy,
            $recentLimit,
        );
    }
}

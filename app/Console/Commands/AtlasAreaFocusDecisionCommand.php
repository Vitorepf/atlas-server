<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusOperatorDecisionService;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Area Focus Loop · Operator Decision CLI (AP-724).
 *
 * Atlas Software Company Stewardship Stack é stack/capability family dentro do
 * Atlas Autonomous Software Company Runtime, não OS novo.
 *
 * Records an explicit operator decision (accept|reject|defer|request_changes) as
 * a deterministic receipt. No auto-approval, no auto-implementation, no
 * execution, no repo mutation.
 */
class AtlasAreaFocusDecisionCommand extends Command
{
    protected $signature = 'atlas:software-company-stewardship:area-focus-decision
        {decision : accept|reject|defer|request_changes}
        {--actor= : operator_actor (required)}
        {--finding-hash= : finding_hash anchor (required)}
        {--inbox-item-id= : AP-718 inbox item id}
        {--work-order-id= : AP-719 work order id}
        {--evidence-pack-hash= : AP-720 evidence pack hash}
        {--rationale= : rationale (required for a high-risk accept)}
        {--risk= : risk band of the item (low|medium|high|critical)}
        {--area=agentic_engineering_os : Canonical area_id}
        {--json : Emit JSON}';

    protected $description = 'Atlas Software Company Stewardship · Area Focus operator decision receipt (AP-724): accept/reject/defer/request_changes. No auto-approval, no execution, no repo mutation.';

    public function handle(AreaFocusOperatorDecisionService $service): int
    {
        try {
            $receipt = $service->decide([
                'decision' => (string) $this->argument('decision'),
                'operator_actor' => (string) $this->option('actor'),
                'finding_hash' => (string) $this->option('finding-hash'),
                'inbox_item_id' => (string) $this->option('inbox-item-id'),
                'work_order_id' => (string) $this->option('work-order-id'),
                'evidence_pack_hash' => (string) $this->option('evidence-pack-hash'),
                'rationale' => (string) $this->option('rationale'),
                'risk' => (string) $this->option('risk'),
                'area_id' => (string) $this->option('area'),
            ]);
        } catch (InvalidArgumentException $e) {
            $payload = [
                'schema_version' => 'atlas.software_company_stewardship.area_focus_operator_decision_receipt.error.v1',
                'status' => 'blocked',
                'reason' => explode(':', $e->getMessage(), 2)[0],
                'detail' => $e->getMessage(),
            ];
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::FAILURE;
        }

        if ((bool) $this->option('json')) {
            $this->line(json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('Area Focus operator decision', 'AP-724 receipt');
        $this->components->twoColumnDetail('Decision', strtoupper((string) $receipt['decision']));
        $this->components->twoColumnDetail('Actor', (string) $receipt['operator_actor']);
        $this->components->twoColumnDetail('Finding', (string) $receipt['finding_hash']);
        $this->components->twoColumnDetail('Next allowed action', (string) $receipt['next_allowed_action']);
        $this->components->twoColumnDetail('Executed', $receipt['executed'] ? 'yes' : 'no');
        $this->components->twoColumnDetail('Decision hash', (string) $receipt['decision_hash']);

        return self::SUCCESS;
    }
}
